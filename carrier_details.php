<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$carrier_id = isset($_GET['carrier_id']) ? (int)$_GET['carrier_id'] : 0;

if ($carrier_id <= 0) {
    header("Location: carrier_overview");
    exit();
}

// Account-scoped access
$account_id = null;
if ($role !== 'global_admin') {
    $stmtAcc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    if ($stmtAcc) {
        $stmtAcc->bind_param("i", $user_id);
        $stmtAcc->execute();
        $stmtAcc->bind_result($account_id);
        $stmtAcc->fetch();
        $stmtAcc->close();
    }
}

// Get projects based on user role
if ($role === 'global_admin') {
    $project_access_sql = "SELECT id FROM projects WHERE status IS NULL OR status = 'active'";
    $access_params = [];
    $access_types = "";
} else {
    $project_access_sql = "SELECT p.id FROM projects p JOIN customer_account_users cau ON p.account_id = cau.account_id WHERE cau.user_id = ? AND (p.status IS NULL OR p.status = 'active')";
    $access_params = [$user_id];
    $access_types = "i";
}

// Get carrier info (with account access check)
if ($role === 'global_admin') {
    $carrier_sql = "SELECT * FROM carriers WHERE id = ?";
    $stmt = $conn->prepare($carrier_sql);
    $stmt->bind_param("i", $carrier_id);
} else {
    $carrier_sql = "SELECT * FROM carriers WHERE id = ? AND (account_id IS NULL OR account_id = ?)";
    $stmt = $conn->prepare($carrier_sql);
    $stmt->bind_param("ii", $carrier_id, $account_id);
}
$stmt->execute();
$carrier = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$carrier) {
    header("Location: carrier_overview");
    exit();
}

// Get carrier aggregate stats
$agg_sql = "
    SELECT
        COUNT(DISTINCT d.id) as total_deliveries,
        COALESCE(SUM(d.freight_cost), 0) as total_freight_cost,
        COALESCE(SUM(d.miles), 0) as total_miles,
        COUNT(DISTINCT d.project_id) as project_count
    FROM deliveries d
    WHERE d.carrier_id = ? AND d.project_id IN ($project_access_sql)
";
$stmt_agg = $conn->prepare($agg_sql);
if (!empty($access_types)) {
    $stmt_agg->bind_param("i" . $access_types, $carrier_id, ...$access_params);
} else {
    $stmt_agg->bind_param("i", $carrier_id);
}
$stmt_agg->execute();
$agg = $stmt_agg->get_result()->fetch_assoc();
$stmt_agg->close();

$total_deliveries = (int)($agg['total_deliveries'] ?? 0);
$total_freight_cost = (float)($agg['total_freight_cost'] ?? 0);
$total_miles = (float)($agg['total_miles'] ?? 0);
$project_count = (int)($agg['project_count'] ?? 0);
$avg_cost_per_mile = $total_miles > 0 ? $total_freight_cost / $total_miles : 0;

// On-time performance
$ontime_sql = "
    SELECT
        COUNT(DISTINCT d.id) as total_completed,
        COUNT(DISTINCT CASE
            WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
            AND d.actual_delivery_date <= d.anticipated_delivery_date THEN d.id END) as on_time_count,
        COUNT(DISTINCT CASE
            WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
            AND d.actual_delivery_date > d.anticipated_delivery_date THEN d.id END) as late_count,
        AVG(CASE
            WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
            AND d.actual_delivery_date > d.anticipated_delivery_date
            THEN DATEDIFF(d.actual_delivery_date, d.anticipated_delivery_date) END) as avg_days_late
    FROM deliveries d
    WHERE d.carrier_id = ?
    AND d.actual_delivery_date IS NOT NULL
    AND d.project_id IN ($project_access_sql)
";
$stmt_ontime = $conn->prepare($ontime_sql);
if (!empty($access_types)) {
    $stmt_ontime->bind_param("i" . $access_types, $carrier_id, ...$access_params);
} else {
    $stmt_ontime->bind_param("i", $carrier_id);
}
$stmt_ontime->execute();
$ontime = $stmt_ontime->get_result()->fetch_assoc();
$stmt_ontime->close();

$deliveries_on_time = (int)($ontime['on_time_count'] ?? 0);
$deliveries_late = (int)($ontime['late_count'] ?? 0);
$completed_deliveries = $deliveries_on_time + $deliveries_late;
$avg_days_late = round((float)($ontime['avg_days_late'] ?? 0), 1);
$on_time_rate = $completed_deliveries > 0 ? round(($deliveries_on_time / $completed_deliveries) * 100) : null;

if ($completed_deliveries === 0) {
    $on_time_label = 'N/A';
    $on_time_class = '';
} else {
    $on_time_label = $on_time_rate . '%';
    $on_time_class = $on_time_rate >= 90 ? 'good' : ($on_time_rate >= 70 ? 'warning' : 'poor');
}

// Safety incidents
$safety_sql = "
    SELECT
        COUNT(DISTINCT ss_safety.id) as total_incidents,
        COUNT(DISTINCT CASE WHEN ss_safety.report_driver = 'Yes' THEN ss_safety.id END) as drivers_reported
    FROM deliveries d
    JOIN site_scheduling sched ON sched.delivery_id = d.id
    JOIN site_safety ss_safety ON ss_safety.scheduling_id = sched.id
    WHERE d.carrier_id = ?
    AND d.project_id IN ($project_access_sql)
";
$stmt_safety = $conn->prepare($safety_sql);
if (!empty($access_types)) {
    $stmt_safety->bind_param("i" . $access_types, $carrier_id, ...$access_params);
} else {
    $stmt_safety->bind_param("i", $carrier_id);
}
$stmt_safety->execute();
$safety = $stmt_safety->get_result()->fetch_assoc();
$stmt_safety->close();

$safety_incidents = (int)($safety['total_incidents'] ?? 0);
$drivers_reported = (int)($safety['drivers_reported'] ?? 0);

// Safety incident details
$safety_details = [];
$safety_detail_sql = "
    SELECT
        ss_safety.id,
        ss_safety.notes,
        ss_safety.report_driver,
        ss_safety.created_at,
        d.bol_number,
        d.actual_delivery_date,
        p.project_name
    FROM site_safety ss_safety
    JOIN site_scheduling sched ON sched.id = ss_safety.scheduling_id
    JOIN deliveries d ON d.id = sched.delivery_id
    JOIN projects p ON p.id = sched.project_id
    WHERE d.carrier_id = ?
    AND d.project_id IN ($project_access_sql)
    ORDER BY ss_safety.created_at DESC
";
$stmt_sd = $conn->prepare($safety_detail_sql);
if (!empty($access_types)) {
    $stmt_sd->bind_param("i" . $access_types, $carrier_id, ...$access_params);
} else {
    $stmt_sd->bind_param("i", $carrier_id);
}
$stmt_sd->execute();
$sd_result = $stmt_sd->get_result();
while ($row = $sd_result->fetch_assoc()) {
    $safety_details[] = $row;
}
$stmt_sd->close();

// Projects for this carrier
$projects_for_carrier = [];
$proj_sql = "
    SELECT
        p.id,
        p.project_name,
        p.image_url,
        p.project_address,
        COUNT(DISTINCT d.id) as total_deliveries,
        COALESCE(SUM(d.freight_cost), 0) as project_freight_cost,
        COALESCE(SUM(d.miles), 0) as project_miles,
        COUNT(DISTINCT CASE WHEN d.actual_delivery_date IS NOT NULL THEN d.id END) as completed_deliveries,
        COUNT(DISTINCT CASE WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
                 AND d.actual_delivery_date <= d.anticipated_delivery_date THEN d.id END) as on_time
    FROM projects p
    JOIN deliveries d ON d.project_id = p.id
    WHERE d.carrier_id = ?
      AND p.id IN ($project_access_sql)
    GROUP BY p.id, p.project_name, p.image_url, p.project_address
    ORDER BY project_freight_cost DESC
";
$stmt_proj = $conn->prepare($proj_sql);
if (!empty($access_types)) {
    $stmt_proj->bind_param("i" . $access_types, $carrier_id, ...$access_params);
} else {
    $stmt_proj->bind_param("i", $carrier_id);
}
$stmt_proj->execute();
$proj_result = $stmt_proj->get_result();
while ($proj = $proj_result->fetch_assoc()) {
    $projects_for_carrier[] = $proj;
}
$stmt_proj->close();

// Cumulative delivery timeline (anticipated vs actual by week)
$delivery_timeline = [];
$timeline_sql = "
    SELECT
        DATE_FORMAT(DATE_SUB(d.anticipated_delivery_date, INTERVAL (DAYOFWEEK(d.anticipated_delivery_date) - 1) DAY) + INTERVAL 6 DAY, '%Y-%m-%d') as week_ending,
        'anticipated' as type,
        COUNT(DISTINCT d.id) as delivery_count
    FROM deliveries d
    WHERE d.carrier_id = ?
    AND d.anticipated_delivery_date IS NOT NULL
    AND d.project_id IN ($project_access_sql)
    GROUP BY week_ending
    UNION ALL
    SELECT
        DATE_FORMAT(DATE_SUB(d.actual_delivery_date, INTERVAL (DAYOFWEEK(d.actual_delivery_date) - 1) DAY) + INTERVAL 6 DAY, '%Y-%m-%d') as week_ending,
        'actual' as type,
        COUNT(DISTINCT d.id) as delivery_count
    FROM deliveries d
    WHERE d.carrier_id = ?
    AND d.actual_delivery_date IS NOT NULL
    AND d.project_id IN ($project_access_sql)
    GROUP BY week_ending
    ORDER BY week_ending, type
";
$stmt_timeline = $conn->prepare($timeline_sql);
if (!empty($access_types)) {
    $timeline_types = "i" . $access_types . "i" . $access_types;
    $timeline_params = array_merge([$carrier_id], $access_params, [$carrier_id], $access_params);
    $stmt_timeline->bind_param($timeline_types, ...$timeline_params);
} else {
    $stmt_timeline->bind_param("ii", $carrier_id, $carrier_id);
}
$stmt_timeline->execute();
$timeline_result = $stmt_timeline->get_result();

$anticipated_by_week = [];
$actual_by_week = [];
$all_weeks = [];

while ($row = $timeline_result->fetch_assoc()) {
    $week = $row['week_ending'];
    if (!in_array($week, $all_weeks)) {
        $all_weeks[] = $week;
    }
    if ($row['type'] === 'anticipated') {
        $anticipated_by_week[$week] = (int)$row['delivery_count'];
    } else {
        $actual_by_week[$week] = (int)$row['delivery_count'];
    }
}
$stmt_timeline->close();

sort($all_weeks);

// Build cumulative data
$cumulative_anticipated = [];
$cumulative_actual = [];
$running_ant = 0;
$running_act = 0;
$today = date('Y-m-d');

foreach ($all_weeks as $week) {
    $running_ant += $anticipated_by_week[$week] ?? 0;
    $running_act += $actual_by_week[$week] ?? 0;
    $cumulative_anticipated[] = $running_ant;
    if ($week <= $today) {
        $cumulative_actual[] = $running_act;
    } else {
        $cumulative_actual[] = null;
    }
}

$alignment_week = null;
$alignment_delta = null;
for ($i = count($all_weeks) - 1; $i >= 0; $i--) {
    if ($cumulative_actual[$i] !== null) {
        $alignment_week = $all_weeks[$i];
        $alignment_delta = $cumulative_actual[$i] - $cumulative_anticipated[$i];
        break;
    }
}
$alignment_week_label = $alignment_week ? date('M j, Y', strtotime($alignment_week)) : null;

$peak_week = null;
$peak_week_count = 0;
foreach ($actual_by_week as $week => $count) {
    if ($count > $peak_week_count) {
        $peak_week = $week;
        $peak_week_count = $count;
    }
}
$total_actual_deliveries = array_sum($actual_by_week);
$peak_week_share = $total_actual_deliveries > 0
    ? round(($peak_week_count / $total_actual_deliveries) * 100) : null;
$peak_week_label = $peak_week ? date('M j, Y', strtotime($peak_week)) : null;

// All deliveries for this carrier (table)
$all_deliveries = [];
$del_sql = "
    SELECT
        d.id,
        d.bol_number,
        d.anticipated_delivery_date,
        d.actual_delivery_date,
        d.freight_cost,
        d.miles,
        d.carrier_reference_number,
        d.status_of_delivery,
        p.project_name,
        p.id as project_id
    FROM deliveries d
    LEFT JOIN projects p ON p.id = d.project_id
    WHERE d.carrier_id = ?
    AND d.project_id IN ($project_access_sql)
    ORDER BY COALESCE(d.actual_delivery_date, d.anticipated_delivery_date) DESC
";
$stmt_del = $conn->prepare($del_sql);
if (!empty($access_types)) {
    $stmt_del->bind_param("i" . $access_types, $carrier_id, ...$access_params);
} else {
    $stmt_del->bind_param("i", $carrier_id);
}
$stmt_del->execute();
$del_result = $stmt_del->get_result();
while ($row = $del_result->fetch_assoc()) {
    $all_deliveries[] = $row;
}
$stmt_del->close();

$conn->close();

$type_labels = ['ftl' => 'FTL', 'ltl' => 'LTL', 'drayage' => 'Drayage', 'intermodal' => 'Intermodal', 'ocean' => 'Ocean', 'other' => 'Other'];
$carrier_display_name = htmlspecialchars($carrier['name']);
$carrier_type_label = $type_labels[$carrier['carrier_type']] ?? ucfirst($carrier['carrier_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $carrier_display_name; ?> - Carrier Details</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }

        .page-header {
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .page-header p { color: #6c757d; font-size: 1.1em; margin: 0; }
        .header-badges {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .header-badge {
            font-size: 0.8em;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .header-badge.solterra {
            background: linear-gradient(135deg, #488C9A, #293E4C);
            color: #fff;
        }
        .header-badge.type {
            background: #e8f4f6;
            color: #293E4C;
        }
        .header-badge.mc {
            background: #f0f0ff;
            color: #4338ca;
        }
        .header-badge.dot {
            background: #fef3c7;
            color: #92400e;
        }
        .carrier-contact-info {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 16px;
            font-size: 0.9em;
            color: #6c757d;
        }
        .carrier-contact-info span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .carrier-contact-info i { color: #488C9A; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
        .stat-card.primary { background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%); border: none; }
        .stat-card.primary .stat-label, .stat-card.primary .stat-value { color: #fff; }
        .stat-card-clickable { cursor: pointer; position: relative; }
        .stat-card-clickable::after {
            content: 'Click for breakdown';
            position: absolute;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.65em;
            color: #488C9A;
            opacity: 0;
            transition: opacity 0.2s;
            white-space: nowrap;
        }
        .stat-card-clickable:hover::after { opacity: 1; }
        .stat-card.primary.stat-card-clickable::after { color: rgba(255,255,255,0.8); }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.5em;
        }
        .stat-card.primary .stat-icon { background: rgba(255,255,255,0.2); color: #fff; }
        .stat-icon.blue { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #2563eb; }
        .stat-icon.purple { background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); color: #7c3aed; }
        .stat-icon.green { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #059669; }
        .stat-icon.orange { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #fbb040; }
        .stat-icon.red { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #E4572E; }
        .stat-icon.red-orange { background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%); color: #ea580c; }
        .stat-value { font-size: 1.8em; font-weight: 700; color: #293E4C; margin-bottom: 4px; }
        .stat-label { color: #6c757d; font-size: 0.85em; font-weight: 500; }
        .stat-value.good { color: #059669; }
        .stat-value.warning { color: #fbb040; }
        .stat-value.poor { color: #E4572E; }

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 40px;
        }
        .chart-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }
        .chart-card h3 {
            font-size: 1.2em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chart-card h3 i { color: #488C9A; }
        .chart-container {
            position: relative;
            height: 280px;
        }

        /* Section Card */
        .section-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }
        .section-card h2 {
            font-size: 1.4em;
            font-weight: 700;
            color: #293E4C;
            margin: 0 0 24px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section-card h2 i { color: #488C9A; }

        /* Delivery Story Styles */
        .delivery-story-grid {
            display: grid;
            grid-template-columns: 1fr 1.6fr;
            gap: 24px;
        }
        .delivery-story-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
        }
        .story-label {
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #488C9A;
            font-weight: 600;
        }
        .story-value {
            font-size: 2.4em;
            font-weight: 700;
            color: #293E4C;
            margin: 8px 0 6px;
        }
        .story-value.good { color: #059669; }
        .story-value.poor { color: #E4572E; }
        .story-value.neutral { color: #fbb040; }
        .story-subtitle {
            font-size: 0.95em;
            color: #6c757d;
            line-height: 1.4;
        }
        .story-subtitle + .story-subtitle { margin-top: 6px; }
        .story-details {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 18px;
        }
        .story-detail {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 12px;
            text-align: center;
        }
        .story-detail .label {
            font-size: 0.7em;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #6c757d;
            font-weight: 600;
        }
        .story-detail .value {
            font-size: 1.1em;
            font-weight: 700;
            color: #293E4C;
            margin-top: 4px;
        }
        .story-note {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            background: #fff3cd;
            border-radius: 10px;
            color: #856404;
            font-size: 0.85em;
            margin-top: 14px;
        }
        .story-note i { color: #E4572E; }
        .story-chart-container {
            position: relative;
            height: 300px;
            margin-top: 14px;
        }
        .story-chart-empty {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: 12px;
            color: #6c757d;
            font-size: 0.9em;
        }
        .story-callouts {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }
        .story-callout {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.85em;
            color: #6c757d;
        }
        .story-callout strong { color: #293E4C; font-weight: 600; }

        /* Projects Grid */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .project-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.2s;
            cursor: pointer;
        }
        .project-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }
        .project-card-image {
            width: 100%; height: 120px;
            background: linear-gradient(135deg, #e8f4f6 0%, #d1e8ec 100%);
            position: relative;
            overflow: hidden;
        }
        .project-card-image img { width: 100%; height: 100%; object-fit: cover; }
        .project-card-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(45deg, rgba(72,140,154,0.9), rgba(41,62,76,0.9));
            display: flex; align-items: center; justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .project-card:hover .project-card-overlay { opacity: 1; }
        .project-card-overlay span { color: #fff; font-size: 0.9em; font-weight: 600; }
        .project-card-content { padding: 16px; }
        .project-card-title { margin: 0 0 12px; font-size: 1em; color: #293E4C; font-weight: 600; }
        .project-card-stats {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .project-stat-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.85em;
        }
        .project-stat-row .label { color: #6c757d; }
        .project-stat-row .value { font-weight: 600; color: #488C9A; }
        .project-stat-row .value.good { color: #059669; }
        .project-stat-row .value.poor { color: #E4572E; }

        /* Safety Table */
        .safety-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .safety-stat {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
        }
        .safety-stat .value {
            font-size: 1.8em;
            font-weight: 700;
            color: #293E4C;
        }
        .safety-stat .value.good { color: #059669; }
        .safety-stat .value.poor { color: #E4572E; }
        .safety-stat .label {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 4px;
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th {
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #fff;
            font-size: 0.85em;
        }
        .data-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f3f4;
            font-size: 0.9em;
        }
        .data-table tbody tr {
            transition: background 0.2s;
        }
        .data-table tbody tr:hover {
            background: #f0f7f8;
        }
        .cost-highlight {
            font-weight: 600;
            color: #488C9A;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
        }
        .status-badge.on-time {
            background: #d1fae5;
            color: #059669;
        }
        .status-badge.late {
            background: #fee2e2;
            color: #E4572E;
        }
        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }
        .driver-reported-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 0.72em;
            font-weight: 600;
            background: #fee2e2;
            color: #E4572E;
        }

        /* Breakdown Modal */
        .breakdown-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            overflow: auto;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }
        .breakdown-modal-content {
            background: white;
            margin: 8% auto;
            padding: 0;
            width: 90%;
            max-width: 600px;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            animation: modalSlideIn 0.3s ease;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .breakdown-modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            color: white;
            padding: 24px;
            border-radius: 20px 20px 0 0;
            position: relative;
            flex-shrink: 0;
        }
        .breakdown-modal-header h2 {
            margin: 0;
            font-size: 1.4em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .breakdown-modal-close {
            position: absolute;
            top: 20px;
            right: 24px;
            font-size: 28px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            transition: transform 0.2s ease;
            border: none;
            background: transparent;
        }
        .breakdown-modal-close:hover { transform: scale(1.1); }
        .breakdown-modal-body {
            padding: 24px;
            overflow-y: auto;
        }
        .breakdown-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: background 0.2s;
            cursor: pointer;
        }
        .breakdown-item:hover { background: #e9ecef; }
        .breakdown-item-name { font-weight: 500; color: #293E4C; }
        .breakdown-item-value { font-weight: 700; color: #488C9A; }
        .breakdown-item-value.good { color: #059669; }
        .breakdown-item-value.poor { color: #E4572E; }
        .breakdown-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: linear-gradient(135deg, #e8f4f6 0%, #d1e8ec 100%);
            border-radius: 12px;
            margin-top: 16px;
            border: 1px solid rgba(72, 140, 154, 0.2);
        }
        .breakdown-total-label { font-weight: 600; color: #293E4C; }
        .breakdown-total-value { font-size: 1.3em; font-weight: 700; color: #488C9A; }

        .table-container {
            overflow-x: auto;
            border-radius: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state h3 { color: #293E4C; margin-bottom: 8px; }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-section { grid-template-columns: 1fr; }
            .delivery-story-grid { grid-template-columns: 1fr; }
            .safety-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-header { padding: 24px; }
            .page-header h1 { font-size: 1.8em; }
            .stats-grid { grid-template-columns: 1fr; }
            .projects-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
    require_once 'components/breadcrumbs.php';
    echo slp_render_breadcrumbs([
        'current_label' => $carrier_display_name,
        'extra' => [
            ['label' => 'Carrier Overview', 'url' => 'carrier_overview']
        ]
    ]);
    ?>

    <div class="page-header">
        <h1><?php echo $carrier_display_name; ?></h1>
        <p>Carrier performance details with delivery reliability, cost analysis, and safety metrics</p>
        <div class="header-badges">
            <?php if ($carrier['is_solterra_managed']): ?>
                <span class="header-badge solterra"><i class="fas fa-shield-alt"></i> Solterra Managed</span>
            <?php endif; ?>
            <span class="header-badge type"><i class="fas fa-truck"></i> <?php echo $carrier_type_label; ?></span>
            <?php if (!empty($carrier['mc_number'])): ?>
                <span class="header-badge mc"><i class="fas fa-id-card"></i> MC# <?php echo htmlspecialchars($carrier['mc_number']); ?></span>
            <?php endif; ?>
            <?php if (!empty($carrier['dot_number'])): ?>
                <span class="header-badge dot"><i class="fas fa-hashtag"></i> DOT# <?php echo htmlspecialchars($carrier['dot_number']); ?></span>
            <?php endif; ?>
        </div>
        <?php if (!empty($carrier['contact_person']) || !empty($carrier['phone']) || !empty($carrier['email']) || !empty($carrier['address'])): ?>
        <div class="carrier-contact-info">
            <?php if (!empty($carrier['contact_person'])): ?>
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($carrier['contact_person']); ?></span>
            <?php endif; ?>
            <?php if (!empty($carrier['phone'])): ?>
                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($carrier['phone']); ?></span>
            <?php endif; ?>
            <?php if (!empty($carrier['email'])): ?>
                <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($carrier['email']); ?></span>
            <?php endif; ?>
            <?php if (!empty($carrier['address'])): ?>
                <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($carrier['address']); ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card primary stat-card-clickable" onclick="openCostModal()">
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-value">$<?php echo number_format($total_freight_cost, 0); ?></div>
            <div class="stat-label">Total Freight Cost</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="scrollToDeliveries()">
            <div class="stat-icon blue"><i class="fas fa-truck"></i></div>
            <div class="stat-value"><?php echo number_format($total_deliveries); ?></div>
            <div class="stat-label">Total Deliveries</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="scrollToDeliveryStory()">
            <div class="stat-icon <?php echo $on_time_rate === null ? 'orange' : ($on_time_rate >= 90 ? 'green' : ($on_time_rate >= 70 ? 'orange' : 'red')); ?>"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-value <?php echo $on_time_class; ?>">
                <?php echo $on_time_label; ?>
            </div>
            <div class="stat-label">On-Time Rate</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="scrollToSafety()">
            <div class="stat-icon <?php echo $safety_incidents === 0 ? 'green' : 'red'; ?>"><i class="fas fa-hard-hat"></i></div>
            <div class="stat-value <?php echo $safety_incidents === 0 ? 'good' : 'poor'; ?>"><?php echo number_format($safety_incidents); ?></div>
            <div class="stat-label">Safety Incidents</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-route"></i></div>
            <div class="stat-value">$<?php echo $avg_cost_per_mile > 0 ? number_format($avg_cost_per_mile, 2) : '0.00'; ?></div>
            <div class="stat-label">Avg Cost / Mile</div>
        </div>
    </div>

    <!-- Cost Breakdown Modal -->
    <div id="costModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header">
                <h2><i class="fas fa-dollar-sign"></i> Freight Cost by Project</h2>
                <button class="breakdown-modal-close" onclick="closeCostModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <div class="breakdown-list">
                    <?php foreach ($projects_for_carrier as $proj): ?>
                    <div class="breakdown-item" onclick="window.location.href='project_overview?project_id=<?php echo $proj['id']; ?>'">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($proj['project_name']); ?></span>
                        <span class="breakdown-item-value">$<?php echo number_format($proj['project_freight_cost'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Freight Cost</span>
                    <span class="breakdown-total-value">$<?php echo number_format($total_freight_cost, 2); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <?php if (!empty($projects_for_carrier)): ?>
    <div class="charts-section">
        <div class="chart-card">
            <h3><i class="fas fa-chart-pie"></i> Freight Cost by Project</h3>
            <div class="chart-container">
                <canvas id="projectCostChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3><i class="fas fa-chart-bar"></i> Deliveries by Project</h3>
            <div class="chart-container">
                <canvas id="projectDeliveriesChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delivery Story Section -->
    <?php if ($completed_deliveries > 0 || !empty($all_weeks)): ?>
    <div class="section-card" id="delivery-story-section">
        <h2><i class="fas fa-truck"></i> Delivery Story</h2>

        <div class="delivery-story-grid">
            <div class="delivery-story-card">
                <div class="story-label">Delivery Reliability</div>
                <div class="story-value <?php echo $on_time_class; ?>"><?php echo $on_time_label; ?></div>
                <div class="story-subtitle">Delivery reliability measures the percentage of deliveries that arrived on or before the anticipated delivery date.</div>
                <div class="story-subtitle">
                    <?php if ($completed_deliveries > 0): ?>
                        <?php echo $deliveries_on_time; ?> of <?php echo $completed_deliveries; ?> deliveries arrived on time.
                    <?php else: ?>
                        No completed deliveries with date tracking yet.
                    <?php endif; ?>
                </div>

                <div class="story-details">
                    <div class="story-detail">
                        <div class="label">On Time</div>
                        <div class="value"><?php echo $deliveries_on_time; ?></div>
                    </div>
                    <div class="story-detail">
                        <div class="label">Late</div>
                        <div class="value"><?php echo $deliveries_late; ?></div>
                    </div>
                    <div class="story-detail">
                        <div class="label">Pending</div>
                        <div class="value"><?php echo max($total_deliveries - $completed_deliveries, 0); ?></div>
                    </div>
                </div>

                <?php if ($avg_days_late > 0): ?>
                <div class="story-note">
                    <i class="fas fa-exclamation-circle"></i>
                    Average <strong><?php echo $avg_days_late; ?> days</strong> late when deadlines are missed.
                </div>
                <?php endif; ?>
            </div>

            <div class="delivery-story-card">
                <div class="story-label">Delivery Cadence</div>
                <div class="story-subtitle">Anticipated vs actual cumulative deliveries highlight pacing and spikes across all projects for this carrier.</div>

                <div class="story-chart-container">
                    <?php if (!empty($all_weeks)): ?>
                        <canvas id="cumulativeDeliveryChart"></canvas>
                    <?php else: ?>
                        <div class="story-chart-empty">No delivery timeline data yet.</div>
                    <?php endif; ?>
                </div>

                <?php if ($alignment_delta !== null || $peak_week_label): ?>
                <div class="story-callouts">
                    <?php if ($alignment_delta !== null): ?>
                    <div class="story-callout">
                        <?php if ($alignment_delta > 0): ?>
                            <strong><?php echo $alignment_delta; ?> deliveries ahead</strong> of forecast as of <?php echo $alignment_week_label; ?>.
                        <?php elseif ($alignment_delta < 0): ?>
                            <strong><?php echo abs($alignment_delta); ?> deliveries behind</strong> forecast as of <?php echo $alignment_week_label; ?>.
                        <?php else: ?>
                            <strong>On forecast</strong> as of <?php echo $alignment_week_label; ?>.
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($peak_week_label): ?>
                    <div class="story-callout">
                        <strong>Peak week:</strong> <?php echo $peak_week_label; ?> (<?php echo $peak_week_count; ?> deliveries<?php echo $peak_week_share !== null ? ', ' . $peak_week_share . '% of total' : ''; ?>).
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Safety Section -->
    <div class="section-card" id="safety-section">
        <h2><i class="fas fa-hard-hat"></i> Safety Record</h2>

        <div class="safety-grid">
            <div class="safety-stat">
                <div class="value <?php echo $safety_incidents === 0 ? 'good' : 'poor'; ?>"><?php echo number_format($safety_incidents); ?></div>
                <div class="label">Total Safety Incidents</div>
            </div>
            <div class="safety-stat">
                <div class="value <?php echo $drivers_reported === 0 ? 'good' : 'poor'; ?>"><?php echo number_format($drivers_reported); ?></div>
                <div class="label">Drivers Reported</div>
            </div>
            <div class="safety-stat">
                <div class="value"><?php echo $total_deliveries > 0 ? number_format(($safety_incidents / $total_deliveries) * 100, 1) : '0.0'; ?>%</div>
                <div class="label">Incident Rate</div>
            </div>
        </div>

        <?php if (!empty($safety_details)): ?>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Project</th>
                        <th>BOL</th>
                        <th>Driver Reported</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($safety_details as $incident): ?>
                    <tr>
                        <td><?php echo !empty($incident['created_at']) ? date('M j, Y', strtotime($incident['created_at'])) : '—'; ?></td>
                        <td><?php echo htmlspecialchars($incident['project_name']); ?></td>
                        <td><?php echo !empty($incident['bol_number']) ? htmlspecialchars($incident['bol_number']) : '—'; ?></td>
                        <td>
                            <?php if ($incident['report_driver'] === 'Yes'): ?>
                                <span class="driver-reported-badge">Reported</span>
                            <?php else: ?>
                                <span style="color:#059669; font-weight:600; font-size:0.85em;">No</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo !empty($incident['notes']) ? htmlspecialchars(mb_strimwidth($incident['notes'], 0, 80, '...')) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="text-align:center; padding:40px 20px;">
            <i class="fas fa-check-circle" style="font-size:3em; color:#059669; margin-bottom:16px;"></i>
            <p style="font-size:1.1em; color:#293E4C; font-weight:600;">Clean Safety Record</p>
            <p style="color:#6c757d;">No safety incidents have been reported for this carrier.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Projects Section -->
    <?php if (!empty($projects_for_carrier)): ?>
    <div class="section-card" id="projects-section">
        <h2><i class="fas fa-folder-open"></i> Projects</h2>
        <div class="projects-grid">
            <?php foreach ($projects_for_carrier as $proj):
                $proj_total_del = (int)$proj['total_deliveries'];
                $proj_completed_del = (int)$proj['completed_deliveries'];
                $proj_on_time = (int)$proj['on_time'];
                $proj_on_time_pct = $proj_completed_del > 0 ? round(($proj_on_time / $proj_completed_del) * 100) : null;
                $proj_avg_cpm = $proj['project_miles'] > 0 ? $proj['project_freight_cost'] / $proj['project_miles'] : 0;
            ?>
            <div class="project-card" onclick="window.location.href='project_overview?project_id=<?php echo $proj['id']; ?>'">
                <div class="project-card-image">
                    <?php if (!empty($proj['image_url'])): ?>
                    <img src="<?php echo htmlspecialchars($proj['image_url']); ?>" alt="<?php echo htmlspecialchars($proj['project_name']); ?>">
                    <?php endif; ?>
                    <div class="project-card-overlay"><span>View Project</span></div>
                </div>
                <div class="project-card-content">
                    <h3 class="project-card-title"><?php echo htmlspecialchars($proj['project_name']); ?></h3>
                    <div class="project-card-stats">
                        <div class="project-stat-row">
                            <span class="label">Freight Cost</span>
                            <span class="value">$<?php echo number_format($proj['project_freight_cost'], 0); ?></span>
                        </div>
                        <div class="project-stat-row">
                            <span class="label">Deliveries</span>
                            <span class="value"><?php echo $proj_completed_del; ?>/<?php echo $proj_total_del; ?></span>
                        </div>
                        <?php if ($proj_on_time_pct !== null): ?>
                        <div class="project-stat-row">
                            <span class="label">On-Time</span>
                            <span class="value <?php echo $proj_on_time_pct >= 90 ? 'good' : ($proj_on_time_pct < 70 ? 'poor' : ''); ?>">
                                <?php echo $proj_on_time_pct; ?>%
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if ($proj_avg_cpm > 0): ?>
                        <div class="project-stat-row">
                            <span class="label">Avg $/Mile</span>
                            <span class="value">$<?php echo number_format($proj_avg_cpm, 2); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- All Deliveries Table -->
    <?php if (!empty($all_deliveries)): ?>
    <div class="section-card" id="deliveries-section">
        <h2><i class="fas fa-clipboard-list"></i> All Deliveries</h2>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>BOL #</th>
                        <th>Project</th>
                        <th>Ref #</th>
                        <th>Anticipated</th>
                        <th>Actual</th>
                        <th>Status</th>
                        <th>Freight Cost</th>
                        <th>Miles</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_deliveries as $del):
                        $del_status = 'pending';
                        $del_status_label = 'Pending';
                        if (!empty($del['actual_delivery_date']) && !empty($del['anticipated_delivery_date'])) {
                            if ($del['actual_delivery_date'] <= $del['anticipated_delivery_date']) {
                                $del_status = 'on-time';
                                $del_status_label = 'On Time';
                            } else {
                                $del_status = 'late';
                                $days_late = (strtotime($del['actual_delivery_date']) - strtotime($del['anticipated_delivery_date'])) / 86400;
                                $del_status_label = round($days_late) . 'd Late';
                            }
                        } elseif (!empty($del['actual_delivery_date'])) {
                            $del_status = 'on-time';
                            $del_status_label = 'Delivered';
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo !empty($del['bol_number']) ? htmlspecialchars($del['bol_number']) : '—'; ?></strong></td>
                        <td>
                            <?php if (!empty($del['project_name'])): ?>
                                <a href="project_overview?project_id=<?php echo (int)$del['project_id']; ?>" style="color:#488C9A; text-decoration:none; font-weight:500;"><?php echo htmlspecialchars($del['project_name']); ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?php echo !empty($del['carrier_reference_number']) ? htmlspecialchars($del['carrier_reference_number']) : '—'; ?></td>
                        <td><?php echo !empty($del['anticipated_delivery_date']) ? date('M j, Y', strtotime($del['anticipated_delivery_date'])) : '—'; ?></td>
                        <td><?php echo !empty($del['actual_delivery_date']) ? date('M j, Y', strtotime($del['actual_delivery_date'])) : '—'; ?></td>
                        <td><span class="status-badge <?php echo $del_status; ?>"><?php echo $del_status_label; ?></span></td>
                        <td class="cost-highlight"><?php echo $del['freight_cost'] > 0 ? '$' . number_format($del['freight_cost'], 2) : '—'; ?></td>
                        <td><?php echo $del['miles'] > 0 ? number_format($del['miles'], 0) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
// Modal Functions
function openCostModal() { document.getElementById('costModal').style.display = 'block'; }
function closeCostModal() { document.getElementById('costModal').style.display = 'none'; }

function scrollToDeliveries() {
    var section = document.getElementById('deliveries-section');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function scrollToDeliveryStory() {
    var section = document.getElementById('delivery-story-section');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function scrollToSafety() {
    var section = document.getElementById('safety-section');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

window.onclick = function(event) {
    const modals = ['costModal'];
    modals.forEach(function(id) {
        const modal = document.getElementById(id);
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($projects_for_carrier)): ?>
    const projectsData = <?php echo json_encode($projects_for_carrier); ?>;
    const projectNames = projectsData.map(p => p.project_name);
    const projectCosts = projectsData.map(p => parseFloat(p.project_freight_cost) || 0);
    const projectDeliveries = projectsData.map(p => parseInt(p.total_deliveries) || 0);

    const colors = [
        '#488C9A', '#293E4C', '#fbb040', '#059669', '#E4572E',
        '#3b82f6', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316'
    ];

    // Freight Cost by Project (Doughnut)
    const costCtx = document.getElementById('projectCostChart');
    if (costCtx) {
        new Chart(costCtx, {
            type: 'doughnut',
            data: {
                labels: projectNames,
                datasets: [{
                    data: projectCosts,
                    backgroundColor: colors.slice(0, projectNames.length),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { padding: 15, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.raw;
                                const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                                const pct = total > 0 ? ((val/total)*100).toFixed(1) : 0;
                                return `${ctx.label}: $${val.toLocaleString()} (${pct}%)`;
                            }
                        }
                    }
                },
                cutout: '55%'
            }
        });
    }

    // Deliveries by Project (Bar)
    const delCtx = document.getElementById('projectDeliveriesChart');
    if (delCtx) {
        new Chart(delCtx, {
            type: 'bar',
            data: {
                labels: projectNames,
                datasets: [{
                    label: 'Deliveries',
                    data: projectDeliveries,
                    backgroundColor: colors.slice(0, projectNames.length),
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return `${ctx.raw} deliveries`; }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
    <?php endif; ?>

    // Cumulative Delivery Line Chart
    const cumulativeCtx = document.getElementById('cumulativeDeliveryChart');
    if (cumulativeCtx) {
        const weekLabels = <?php echo json_encode($all_weeks); ?>;
        const anticipatedData = <?php echo json_encode($cumulative_anticipated); ?>;
        const actualData = <?php echo json_encode($cumulative_actual); ?>;

        const formattedLabels = weekLabels.map(w => {
            const d = new Date(w);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });

        new Chart(cumulativeCtx, {
            type: 'line',
            data: {
                labels: formattedLabels,
                datasets: [
                    {
                        label: 'Anticipated',
                        data: anticipatedData,
                        borderColor: '#488C9A',
                        backgroundColor: 'rgba(72, 140, 154, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: '#488C9A'
                    },
                    {
                        label: 'Actual',
                        data: actualData,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.1)',
                        borderWidth: 3,
                        fill: false,
                        tension: 0.3,
                        pointRadius: 4,
                        pointBackgroundColor: '#059669',
                        spanGaps: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, padding: 20 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                if (ctx.raw === null) return null;
                                return `${ctx.dataset.label}: ${ctx.raw} deliveries`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Cumulative Deliveries' },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        title: { display: true, text: 'Week Ending' },
                        grid: { display: false }
                    }
                }
            }
        });
    }
});
</script>
</body>
</html>
