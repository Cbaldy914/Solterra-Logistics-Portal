<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

require_once '../config.php';
require_once 'cost_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$manufacturer = isset($_GET['manufacturer']) ? trim($_GET['manufacturer']) : '';

// If no manufacturer specified, redirect to overview
if (empty($manufacturer)) {
    header("Location: manufacturer_overview");
    exit();
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

// Get manufacturer data
$mfr_sql = "
    SELECT
        m.vendor_name,
        SUM(umi.wattage * umi.quantity) as total_watts,
        SUM(CASE WHEN m.cost_per_watt IS NOT NULL THEN m.cost_per_watt * umi.wattage * umi.quantity ELSE 0 END) as total_cost,
        COUNT(DISTINCT m.project_id) as project_count
    FROM modules m
    JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
    WHERE m.vendor_name = ? AND m.project_id IN ($project_access_sql)
    GROUP BY m.vendor_name
";

$stmt = $conn->prepare($mfr_sql);
if (!empty($access_types)) {
    $stmt->bind_param("s" . $access_types, $manufacturer, ...$access_params);
} else {
    $stmt->bind_param("s", $manufacturer);
}
$stmt->execute();
$mfr_result = $stmt->get_result();
$manufacturer_data = $mfr_result->fetch_assoc();
$stmt->close();

if (!$manufacturer_data) {
    $manufacturer_data = [
        'vendor_name' => $manufacturer,
        'total_watts' => 0,
        'total_cost' => 0,
        'project_count' => 0
    ];
}

$manufacturer_data['avg_cost_per_watt'] = $manufacturer_data['total_watts'] > 0
    ? $manufacturer_data['total_cost'] / $manufacturer_data['total_watts'] : 0;

// Get damaged modules from warranty claims (sum reported damaged quantity)
$manufacturer_case_sql = "TRIM(CASE WHEN d.supplier LIKE '%-%' THEN SUBSTRING_INDEX(d.supplier, '-', 1) ELSE d.supplier END)";
$damage_sql = "
    SELECT COALESCE(SUM(COALESCE(w.damaged_quantity, 0)), 0) as damaged_count
    FROM warranty_claims w
    JOIN site_scheduling ss ON ss.id = w.scheduling_id
    JOIN deliveries d ON d.id = ss.delivery_id
    WHERE $manufacturer_case_sql = ?
    AND w.issue_type IN ('damaged', 'both')
    AND ss.project_id IN ($project_access_sql)
";
$stmt_damage = $conn->prepare($damage_sql);
if (!empty($access_types)) {
    $stmt_damage->bind_param("s" . $access_types, $manufacturer, ...$access_params);
} else {
    $stmt_damage->bind_param("s", $manufacturer);
}
$stmt_damage->execute();
$damage_result = $stmt_damage->get_result()->fetch_assoc();
$stmt_damage->close();
$damaged_modules = (int)($damage_result['damaged_count'] ?? 0);

$damaged_claims = [];
$damaged_claims_sql = "
    SELECT
        w.id,
        w.status,
        w.issue_type,
        w.damaged_quantity,
        w.created_at,
        p.project_name,
        d.bol_number,
        d.actual_delivery_date
    FROM warranty_claims w
    JOIN site_scheduling ss ON ss.id = w.scheduling_id
    JOIN deliveries d ON d.id = ss.delivery_id
    JOIN projects p ON p.id = ss.project_id
    WHERE $manufacturer_case_sql = ?
    AND w.issue_type IN ('damaged', 'both')
    AND COALESCE(w.damaged_quantity, 0) > 0
    AND ss.project_id IN ($project_access_sql)
    ORDER BY w.created_at DESC
";
$stmt_claims = $conn->prepare($damaged_claims_sql);
if (!empty($access_types)) {
    $stmt_claims->bind_param("s" . $access_types, $manufacturer, ...$access_params);
} else {
    $stmt_claims->bind_param("s", $manufacturer);
}
$stmt_claims->execute();
$claims_result = $stmt_claims->get_result();
while ($claim = $claims_result->fetch_assoc()) {
    $damaged_claims[] = $claim;
}
$stmt_claims->close();

// Get projects for this manufacturer
$projects_for_manufacturer = [];
$proj_sql = "
    SELECT DISTINCT
        p.id,
        p.project_name,
        p.image_url,
        p.project_address,
        SUM(umi.wattage * umi.quantity) as project_watts,
        SUM(CASE WHEN m.cost_per_watt IS NOT NULL THEN m.cost_per_watt * umi.wattage * umi.quantity ELSE 0 END) as project_cost
    FROM projects p
    JOIN modules m ON m.project_id = p.id
    JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
    WHERE m.vendor_name = ?
      AND p.id IN ($project_access_sql)
    GROUP BY p.id, p.project_name, p.image_url, p.project_address
    ORDER BY project_cost DESC
";

$stmt_proj = $conn->prepare($proj_sql);
if (!empty($access_types)) {
    $stmt_proj->bind_param("s" . $access_types, $manufacturer, ...$access_params);
} else {
    $stmt_proj->bind_param("s", $manufacturer);
}
$stmt_proj->execute();
$proj_result = $stmt_proj->get_result();

while ($proj = $proj_result->fetch_assoc()) {
    $projects_for_manufacturer[] = $proj;
}
$stmt_proj->close();

// Get module batches for this manufacturer
$module_batches = [];
$batch_sql = "
    SELECT
        m.id as module_id,
        m.vendor_name,
        m.cost_per_watt,
        p.project_name,
        p.id as project_id,
        umi.wattage,
        SUM(umi.quantity) as total_quantity,
        SUM(umi.wattage * umi.quantity) as total_watts
    FROM modules m
    JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
    JOIN projects p ON m.project_id = p.id
    WHERE m.vendor_name = ?
      AND p.id IN ($project_access_sql)
    GROUP BY m.id, m.vendor_name, m.cost_per_watt, p.project_name, p.id, umi.wattage
    ORDER BY p.project_name, umi.wattage DESC
";

$stmt_batch = $conn->prepare($batch_sql);
if (!empty($access_types)) {
    $stmt_batch->bind_param("s" . $access_types, $manufacturer, ...$access_params);
} else {
    $stmt_batch->bind_param("s", $manufacturer);
}
$stmt_batch->execute();
$batch_result = $stmt_batch->get_result();

while ($batch = $batch_result->fetch_assoc()) {
    $module_batches[] = $batch;
}
$stmt_batch->close();

// Get project completion data - compare last delivery vs project estimated completion date
$project_completion = [];
$completion_sql = "
    SELECT
        p.id,
        p.project_name,
        p.estimated_completion_date as project_end_date,
        MAX(d.actual_delivery_date) as last_delivery_date,
        COUNT(DISTINCT d.id) as total_deliveries,
        COUNT(DISTINCT CASE WHEN d.actual_delivery_date IS NOT NULL THEN d.id END) as completed_deliveries,
        COUNT(DISTINCT CASE WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
                           AND d.actual_delivery_date > d.anticipated_delivery_date THEN d.id END) as late_deliveries,
        AVG(CASE WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
                 AND d.actual_delivery_date > d.anticipated_delivery_date
            THEN DATEDIFF(d.actual_delivery_date, d.anticipated_delivery_date) END) as avg_days_late
    FROM projects p
    JOIN modules m ON m.project_id = p.id
    JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
    JOIN inventory_pallets ip ON ip.unassigned_module_item_id = umi.id
    JOIN delivery_pallets dp ON dp.inventory_pallet_id = ip.id
    JOIN deliveries d ON d.id = dp.delivery_id
    WHERE m.vendor_name = ?
      AND p.id IN ($project_access_sql)
    GROUP BY p.id, p.project_name, p.estimated_completion_date
    ORDER BY p.project_name
";

$stmt_completion = $conn->prepare($completion_sql);
if (!empty($access_types)) {
    $stmt_completion->bind_param("s" . $access_types, $manufacturer, ...$access_params);
} else {
    $stmt_completion->bind_param("s", $manufacturer);
}
$stmt_completion->execute();
$completion_result = $stmt_completion->get_result();

$projects_on_time = 0;
$projects_late = 0;
$total_days_late = 0;
$late_count = 0;

while ($row = $completion_result->fetch_assoc()) {
    $row['status'] = 'pending';
    $row['days_difference'] = null;

    if ($row['completed_deliveries'] == $row['total_deliveries'] && $row['total_deliveries'] > 0) {
        // All deliveries completed
        if (!empty($row['project_end_date']) && !empty($row['last_delivery_date'])) {
            $days_diff = (strtotime($row['last_delivery_date']) - strtotime($row['project_end_date'])) / 86400;
            $row['days_difference'] = round($days_diff);

            if ($days_diff <= 0) {
                $row['status'] = 'on_time';
                $projects_on_time++;
            } else {
                $row['status'] = 'late';
                $projects_late++;
                $total_days_late += $days_diff;
                $late_count++;
            }
        } elseif ($row['late_deliveries'] == 0) {
            $row['status'] = 'on_time';
            $projects_on_time++;
        } else {
            $row['status'] = 'late';
            $projects_late++;
        }
    }

    $project_completion[] = $row;
}
$stmt_completion->close();

$avg_days_late_overall = $late_count > 0 ? round($total_days_late / $late_count, 1) : 0;
$total_projects = count($project_completion);
$completed_projects = $projects_on_time + $projects_late;
$projects_pending = max($total_projects - $completed_projects, 0);
$project_on_time_rate = $completed_projects > 0
    ? round(($projects_on_time / $completed_projects) * 100) : null;

if ($completed_projects === 0) {
    $completion_rate_label = 'In Progress';
    $completion_rate_class = 'neutral';
} else {
    $completion_rate_label = $project_on_time_rate . '%';
    if ($project_on_time_rate >= 90) {
        $completion_rate_class = 'good';
    } elseif ($project_on_time_rate < 70) {
        $completion_rate_class = 'poor';
    } else {
        $completion_rate_class = 'neutral';
    }
}

// Get cumulative delivery data by week for the chart
$delivery_timeline = [];
$timeline_sql = "
    SELECT
        DATE_FORMAT(DATE_SUB(d.anticipated_delivery_date, INTERVAL (DAYOFWEEK(d.anticipated_delivery_date) - 1) DAY) + INTERVAL 6 DAY, '%Y-%m-%d') as week_ending,
        'anticipated' as type,
        COUNT(DISTINCT d.id) as delivery_count
    FROM deliveries d
    JOIN delivery_pallets dp ON dp.delivery_id = d.id
    JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
    WHERE ip.manufacturer = ?
    AND d.anticipated_delivery_date IS NOT NULL
    AND d.project_id IN ($project_access_sql)
    GROUP BY week_ending
    UNION ALL
    SELECT
        DATE_FORMAT(DATE_SUB(d.actual_delivery_date, INTERVAL (DAYOFWEEK(d.actual_delivery_date) - 1) DAY) + INTERVAL 6 DAY, '%Y-%m-%d') as week_ending,
        'actual' as type,
        COUNT(DISTINCT d.id) as delivery_count
    FROM deliveries d
    JOIN delivery_pallets dp ON dp.delivery_id = d.id
    JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
    WHERE ip.manufacturer = ?
    AND d.actual_delivery_date IS NOT NULL
    AND d.project_id IN ($project_access_sql)
    GROUP BY week_ending
    ORDER BY week_ending, type
";
$stmt_timeline = $conn->prepare($timeline_sql);
if (!empty($access_types)) {
    // Build params array for the UNION query (manufacturer + access_params twice)
    $timeline_types = "s" . $access_types . "s" . $access_types;
    $timeline_params = array_merge([$manufacturer], $access_params, [$manufacturer], $access_params);
    $stmt_timeline->bind_param($timeline_types, ...$timeline_params);
} else {
    $stmt_timeline->bind_param("ss", $manufacturer, $manufacturer);
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
    // Only show actuals up to current week
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

// Get delivery data for each project
$project_delivery_data = [];
$proj_delivery_sql = "
    SELECT
        p.id,
        COUNT(DISTINCT d.id) as total_deliveries,
        COUNT(DISTINCT CASE WHEN d.actual_delivery_date IS NOT NULL THEN d.id END) as completed_deliveries,
        COUNT(DISTINCT CASE WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
                 AND d.actual_delivery_date <= d.anticipated_delivery_date THEN d.id END) as on_time
    FROM projects p
    JOIN modules m ON m.project_id = p.id
    JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
    JOIN inventory_pallets ip ON ip.unassigned_module_item_id = umi.id
    JOIN delivery_pallets dp ON dp.inventory_pallet_id = ip.id
    JOIN deliveries d ON d.id = dp.delivery_id
    WHERE m.vendor_name = ?
      AND p.id IN ($project_access_sql)
    GROUP BY p.id
";
$stmt_proj_del = $conn->prepare($proj_delivery_sql);
if (!empty($access_types)) {
    $stmt_proj_del->bind_param("s" . $access_types, $manufacturer, ...$access_params);
} else {
    $stmt_proj_del->bind_param("s", $manufacturer);
}
$stmt_proj_del->execute();
$proj_del_result = $stmt_proj_del->get_result();
while ($row = $proj_del_result->fetch_assoc()) {
    $project_delivery_data[$row['id']] = $row;
}
$stmt_proj_del->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($manufacturer); ?> - Manufacturer Details</title>
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
        }
        .page-header p { color: #6c757d; font-size: 1.1em; margin: 0; }

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

        /* Projects Grid */
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

        /* Table */
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

        /* Modal Styles */
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
        .breakdown-item-name {
            font-weight: 500;
            color: #293E4C;
        }
        .breakdown-item-value {
            font-weight: 700;
            color: #488C9A;
        }
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
        .breakdown-total-label {
            font-weight: 600;
            color: #293E4C;
        }
        .breakdown-total-value {
            font-size: 1.3em;
            font-weight: 700;
            color: #488C9A;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state h3 { color: #293E4C; margin-bottom: 8px; }

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

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-section { grid-template-columns: 1fr; }
            .delivery-story-grid { grid-template-columns: 1fr; }
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
        'current_label' => htmlspecialchars($manufacturer),
        'extra' => [
            ['label' => 'Manufacturer Overview', 'url' => 'manufacturer_overview']
        ]
    ]);
    ?>

    <div class="page-header">
        <h1><?php echo htmlspecialchars($manufacturer); ?></h1>
        <p>Module manufacturer details with completion reliability and delivery cadence</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card primary stat-card-clickable" onclick="openCostModal()">
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-value">$<?php echo number_format($manufacturer_data['total_cost'], 0); ?></div>
            <div class="stat-label">Total Module Cost</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openWattageModal()">
            <div class="stat-icon blue"><i class="fas fa-bolt"></i></div>
            <div class="stat-value"><?php echo number_format($manufacturer_data['total_watts'] / 1000000, 2); ?> MW</div>
            <div class="stat-label">Total Wattage</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="scrollToProjects()">
            <div class="stat-icon purple"><i class="fas fa-project-diagram"></i></div>
            <div class="stat-value"><?php echo $manufacturer_data['project_count']; ?></div>
            <div class="stat-label">Projects</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="scrollToDelivery()">
            <div class="stat-icon <?php echo $project_on_time_rate === null ? 'orange' : ($project_on_time_rate >= 90 ? 'green' : ($project_on_time_rate >= 70 ? 'orange' : 'red')); ?>"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-value <?php echo $project_on_time_rate === null ? '' : ($project_on_time_rate >= 90 ? 'good' : ($project_on_time_rate >= 70 ? 'warning' : 'poor')); ?>">
                <?php echo $project_on_time_rate !== null ? $project_on_time_rate . '%' : 'N/A'; ?>
            </div>
            <div class="stat-label">Projects On-Time</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openDamagedModal()">
            <div class="stat-icon <?php echo $damaged_modules === 0 ? 'green' : 'red'; ?>"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-value <?php echo $damaged_modules === 0 ? 'good' : 'poor'; ?>"><?php echo number_format($damaged_modules); ?></div>
            <div class="stat-label">Damaged Modules</div>
        </div>
    </div>

    <!-- Cost Breakdown Modal -->
    <div id="costModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header">
                <h2><i class="fas fa-dollar-sign"></i> Module Cost by Project</h2>
                <button class="breakdown-modal-close" onclick="closeCostModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <div class="breakdown-list">
                    <?php foreach ($projects_for_manufacturer as $proj): ?>
                    <div class="breakdown-item" onclick="window.location.href='project_cost_details?project_id=<?php echo $proj['id']; ?>'">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($proj['project_name']); ?></span>
                        <span class="breakdown-item-value">$<?php echo number_format($proj['project_cost'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Module Cost</span>
                    <span class="breakdown-total-value">$<?php echo number_format($manufacturer_data['total_cost'], 2); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Wattage Breakdown Modal -->
    <div id="wattageModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);">
                <h2><i class="fas fa-bolt"></i> Wattage by Project</h2>
                <button class="breakdown-modal-close" onclick="closeWattageModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <div class="breakdown-list">
                    <?php foreach ($projects_for_manufacturer as $proj): ?>
                    <div class="breakdown-item" onclick="window.location.href='project_cost_details?project_id=<?php echo $proj['id']; ?>'">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($proj['project_name']); ?></span>
                        <span class="breakdown-item-value"><?php echo number_format($proj['project_watts'] / 1000000, 2); ?> MW</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Wattage</span>
                    <span class="breakdown-total-value"><?php echo number_format($manufacturer_data['total_watts'] / 1000000, 2); ?> MW</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Damaged Modules Modal -->
    <div id="damagedModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header" style="background: linear-gradient(135deg, #E4572E 0%, #dc2626 100%);">
                <h2><i class="fas fa-exclamation-triangle"></i> Damaged Module Details</h2>
                <button class="breakdown-modal-close" onclick="closeDamagedModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <?php if ($damaged_modules > 0): ?>
                <p style="color:#6c757d; margin-bottom:16px;">Reported damaged modules from warranty tickets. Click a ticket to view details.</p>
                <?php if (!empty($damaged_claims)): ?>
                <div class="breakdown-list">
                    <?php foreach ($damaged_claims as $claim): ?>
                    <div class="breakdown-item" onclick="window.location.href='warranty_detail.php?id=<?php echo (int)$claim['id']; ?>'">
                        <span class="breakdown-item-name">
                            Ticket #<?php echo (int)$claim['id']; ?> • <?php echo htmlspecialchars($claim['project_name']); ?>
                            <small style="color:#6c757d;font-weight:400;">(<?php echo htmlspecialchars($claim['status']); ?>)</small>
                        </span>
                        <span class="breakdown-item-value"><?php echo number_format((int)$claim['damaged_quantity']); ?> modules</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="color:#fbb040; font-size:0.9em; margin-bottom:16px;">No matching warranty tickets found for this manufacturer.</p>
                <?php endif; ?>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Damaged Modules</span>
                    <span class="breakdown-total-value" style="color:#E4572E;"><?php echo number_format($damaged_modules); ?></span>
                </div>
                <?php else: ?>
                <div style="text-align:center; padding:40px 20px;">
                    <i class="fas fa-check-circle" style="font-size:3em; color:#059669; margin-bottom:16px;"></i>
                    <p style="font-size:1.1em; color:#293E4C; font-weight:600;">No Damaged Modules</p>
                    <p style="color:#6c757d;">This manufacturer has no warranty claims or damaged module reports.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="charts-section">
        <div class="chart-card">
            <h3><i class="fas fa-chart-pie"></i> Cost by Project</h3>
            <div class="chart-container">
                <canvas id="projectCostChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3><i class="fas fa-chart-bar"></i> Wattage by Project</h3>
            <div class="chart-container">
                <canvas id="projectWattageChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Delivery Story Section -->
    <?php if (!empty($project_completion) || !empty($all_weeks)): ?>
    <div class="section-card" id="delivery-section">
        <h2><i class="fas fa-truck"></i> Delivery Story</h2>

        <div class="delivery-story-grid">
            <div class="delivery-story-card">
                <div class="story-label">Completion Reliability</div>
                <div class="story-value <?php echo $completion_rate_class; ?>"><?php echo $completion_rate_label; ?></div>
                <div class="story-subtitle">Completion reliability checks whether every project finishes all deliveries by its estimated completion date.</div>
                <div class="story-subtitle">
                    <?php if ($completed_projects > 0): ?>
                        <?php echo $projects_on_time; ?> of <?php echo $completed_projects; ?> completed projects delivered by the estimated completion date.
                    <?php else: ?>
                        No completed projects yet.
                    <?php endif; ?>
                </div>
                
                <div class="story-details">
                    <div class="story-detail">
                        <div class="label">On Time</div>
                        <div class="value"><?php echo $projects_on_time; ?></div>
                    </div>
                    <div class="story-detail">
                        <div class="label">Late</div>
                        <div class="value"><?php echo $projects_late; ?></div>
                    </div>
                    <div class="story-detail">
                        <div class="label">In Progress</div>
                        <div class="value"><?php echo $projects_pending; ?></div>
                    </div>
                </div>

                <?php if ($avg_days_late_overall > 0): ?>
                <div class="story-note">
                    <i class="fas fa-exclamation-circle"></i>
                    Average <strong><?php echo $avg_days_late_overall; ?> days</strong> late when deadlines are missed.
                </div>
                <?php endif; ?>
            </div>

            <div class="delivery-story-card">
                <div class="story-label">Delivery Cadence</div>
                <div class="story-subtitle">Anticipated vs actual cumulative deliveries highlight pacing and spikes across active projects.</div>

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

    <!-- Projects Section -->
    <?php if (!empty($projects_for_manufacturer)): ?>
    <div class="section-card" id="projects-section">
        <h2><i class="fas fa-folder-open"></i> Projects Using <?php echo htmlspecialchars($manufacturer); ?></h2>
        <div class="projects-grid">
            <?php foreach ($projects_for_manufacturer as $proj):
                $proj_del = $project_delivery_data[$proj['id']] ?? null;
                $proj_total_del = $proj_del['total_deliveries'] ?? 0;
                $proj_completed_del = $proj_del['completed_deliveries'] ?? 0;
                $proj_on_time = $proj_del['on_time'] ?? 0;
                $proj_on_time_pct = $proj_completed_del > 0 ? round(($proj_on_time / $proj_completed_del) * 100) : null;
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
                            <span class="label">Module Cost</span>
                            <span class="value">$<?php echo number_format($proj['project_cost'], 0); ?></span>
                        </div>
                        <div class="project-stat-row">
                            <span class="label">Wattage</span>
                            <span class="value"><?php echo number_format($proj['project_watts'] / 1000000, 2); ?> MW</span>
                        </div>
                        <?php if ($proj_total_del > 0): ?>
                        <div class="project-stat-row">
                            <span class="label">Deliveries</span>
                            <span class="value"><?php echo $proj_completed_del; ?>/<?php echo $proj_total_del; ?></span>
                        </div>
                        <div class="project-stat-row">
                            <span class="label">On-Time</span>
                            <span class="value <?php echo $proj_on_time_pct === null ? '' : ($proj_on_time_pct >= 90 ? 'good' : ($proj_on_time_pct < 70 ? 'poor' : '')); ?>">
                                <?php echo $proj_on_time_pct !== null ? $proj_on_time_pct . '%' : 'N/A'; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Module Batches Table -->
    <?php if (!empty($module_batches)): ?>
    <div class="section-card">
        <h2><i class="fas fa-boxes"></i> Module Batches</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Wattage</th>
                    <th>Quantity</th>
                    <th>Total MW</th>
                    <th>Cost/Watt</th>
                    <th>Total Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($module_batches as $batch):
                    $batch_value = $batch['cost_per_watt'] ? $batch['cost_per_watt'] * $batch['total_watts'] : 0;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($batch['project_name']); ?></td>
                    <td><?php echo number_format($batch['wattage']); ?>W</td>
                    <td><?php echo number_format($batch['total_quantity']); ?></td>
                    <td><?php echo number_format($batch['total_watts'] / 1000000, 3); ?> MW</td>
                    <td><?php echo $batch['cost_per_watt'] ? '$' . number_format($batch['cost_per_watt'], 4) : 'N/A'; ?></td>
                    <td class="cost-highlight">$<?php echo number_format($batch_value, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<script>
// Modal Functions
function openCostModal() { document.getElementById('costModal').style.display = 'block'; }
function closeCostModal() { document.getElementById('costModal').style.display = 'none'; }
function openWattageModal() { document.getElementById('wattageModal').style.display = 'block'; }
function closeWattageModal() { document.getElementById('wattageModal').style.display = 'none'; }
function openDamagedModal() { document.getElementById('damagedModal').style.display = 'block'; }
function closeDamagedModal() { document.getElementById('damagedModal').style.display = 'none'; }

function scrollToProjects() {
    var projectsSection = document.querySelector('.section-card h2 i.fa-folder-open');
    if (projectsSection) {
        projectsSection.closest('.section-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function scrollToDelivery() {
    var deliverySection = document.querySelector('.section-card h2 i.fa-truck');
    if (deliverySection) {
        deliverySection.closest('.section-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = ['costModal', 'wattageModal', 'damagedModal'];
    modals.forEach(function(id) {
        const modal = document.getElementById(id);
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($projects_for_manufacturer)): ?>
    const projectsData = <?php echo json_encode($projects_for_manufacturer); ?>;
    const projectNames = projectsData.map(p => p.project_name);
    const projectCosts = projectsData.map(p => parseFloat(p.project_cost) || 0);
    const projectWatts = projectsData.map(p => (parseFloat(p.project_watts) || 0) / 1000000);

    const colors = [
        '#488C9A', '#293E4C', '#fbb040', '#059669', '#E4572E',
        '#3b82f6', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316'
    ];

    // Project Cost Pie Chart
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

    // Project Wattage Bar Chart
    const wattCtx = document.getElementById('projectWattageChart');
    if (wattCtx) {
        new Chart(wattCtx, {
            type: 'bar',
            data: {
                labels: projectNames,
                datasets: [{
                    label: 'MW',
                    data: projectWatts,
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
                            label: function(ctx) { return `${ctx.raw.toFixed(2)} MW`; }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => v + ' MW' } },
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

        // Format week labels for display
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
