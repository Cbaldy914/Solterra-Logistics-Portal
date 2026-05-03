<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

require_once '../config.php';
require_once __DIR__ . '/carrier_helpers.php';
require_once __DIR__ . '/document_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$carrier_id = isset($_GET['carrier_id']) ? (int)$_GET['carrier_id'] : 0;
$isAdmin = is_admin_role($role);
$isCustomerRole = is_customer_role($role);

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

// Determine if this is the Solterra carrier being viewed by a customer role
$solterra_carrier_id = get_solterra_carrier_id($conn);
$is_solterra_view = ($isCustomerRole && (int)$carrier_id === (int)$solterra_carrier_id);

// For customer roles viewing a Solterra sub-carrier directly, redirect to the Solterra carrier page
if ($isCustomerRole && $carrier_id !== $solterra_carrier_id) {
    // Check if the requested carrier is solterra-managed
    $check_stmt = $conn->prepare("SELECT is_solterra_managed FROM carriers WHERE id = ?");
    $check_stmt->bind_param("i", $carrier_id);
    $check_stmt->execute();
    $check_stmt->bind_result($is_managed);
    $check_stmt->fetch();
    $check_stmt->close();
    if ($is_managed && $solterra_carrier_id > 0) {
        header("Location: carrier_details.php?carrier_id=" . $solterra_carrier_id);
        exit();
    }
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

// Build carrier_id filter for queries: for Solterra view by customers, aggregate all sub-carriers
$carrier_id_filter = "d.carrier_id = ?";
$carrier_bind_ids = [$carrier_id];
$carrier_bind_types = "i";

if ($is_solterra_view) {
    $carrier_id_filter = "d.carrier_id IN (SELECT id FROM carriers WHERE is_solterra_managed = 1)";
    $carrier_bind_ids = [];
    $carrier_bind_types = "";
}

// Helper to build bind params with carrier filter + access params
function build_params($carrier_types, $carrier_ids, $access_types, $access_params) {
    $types = $carrier_types . $access_types;
    $params = array_merge($carrier_ids, $access_params);
    return [$types, $params];
}

$delivered_date_expr = "COALESCE(d.actual_delivery_date, d.warehouse_arrival_date)";

// Get carrier aggregate stats
$agg_sql = "
    SELECT
        COUNT(DISTINCT d.id) as total_deliveries,
        COALESCE(SUM(d.freight_cost), 0) as total_freight_cost,
        COALESCE(SUM(d.miles), 0) as total_miles,
        COUNT(DISTINCT d.project_id) as project_count
    FROM deliveries d
    WHERE $carrier_id_filter AND d.project_id IN ($project_access_sql)
";
$stmt_agg = $conn->prepare($agg_sql);
list($bind_types, $bind_params) = build_params($carrier_bind_types, $carrier_bind_ids, $access_types, $access_params);
if (!empty($bind_types)) {
    $stmt_agg->bind_param($bind_types, ...$bind_params);
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
            WHEN $delivered_date_expr IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
            AND $delivered_date_expr <= d.anticipated_delivery_date THEN d.id END) as on_time_count,
        COUNT(DISTINCT CASE
            WHEN $delivered_date_expr IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
            AND $delivered_date_expr > d.anticipated_delivery_date THEN d.id END) as late_count,
        AVG(CASE
            WHEN $delivered_date_expr IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
            AND $delivered_date_expr > d.anticipated_delivery_date
            THEN DATEDIFF($delivered_date_expr, d.anticipated_delivery_date) END) as avg_days_late
    FROM deliveries d
    WHERE $carrier_id_filter
    AND $delivered_date_expr IS NOT NULL
    AND d.project_id IN ($project_access_sql)
";
$stmt_ontime = $conn->prepare($ontime_sql);
list($bind_types, $bind_params) = build_params($carrier_bind_types, $carrier_bind_ids, $access_types, $access_params);
if (!empty($bind_types)) {
    $stmt_ontime->bind_param($bind_types, ...$bind_params);
}
$stmt_ontime->execute();
$ontime = $stmt_ontime->get_result()->fetch_assoc();
$stmt_ontime->close();

$deliveries_on_time = (int)($ontime['on_time_count'] ?? 0);
$deliveries_late = (int)($ontime['late_count'] ?? 0);
$completed_deliveries = (int)($ontime['total_completed'] ?? 0);
$date_tracked_completed_deliveries = $deliveries_on_time + $deliveries_late;
$avg_days_late = round((float)($ontime['avg_days_late'] ?? 0), 1);
$on_time_rate = $date_tracked_completed_deliveries > 0 ? round(($deliveries_on_time / $date_tracked_completed_deliveries) * 100) : null;

if ($date_tracked_completed_deliveries === 0) {
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
    WHERE $carrier_id_filter
    AND d.project_id IN ($project_access_sql)
";
$stmt_safety = $conn->prepare($safety_sql);
list($bind_types, $bind_params) = build_params($carrier_bind_types, $carrier_bind_ids, $access_types, $access_params);
if (!empty($bind_types)) {
    $stmt_safety->bind_param($bind_types, ...$bind_params);
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
    WHERE $carrier_id_filter
    AND d.project_id IN ($project_access_sql)
    ORDER BY ss_safety.created_at DESC
";
$stmt_sd = $conn->prepare($safety_detail_sql);
list($bind_types, $bind_params) = build_params($carrier_bind_types, $carrier_bind_ids, $access_types, $access_params);
if (!empty($bind_types)) {
    $stmt_sd->bind_param($bind_types, ...$bind_params);
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
        COUNT(DISTINCT CASE WHEN $delivered_date_expr IS NOT NULL THEN d.id END) as completed_deliveries,
        COUNT(DISTINCT CASE WHEN $delivered_date_expr IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL THEN d.id END) as date_tracked_deliveries,
        COUNT(DISTINCT CASE WHEN $delivered_date_expr IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
                 AND $delivered_date_expr <= d.anticipated_delivery_date THEN d.id END) as on_time
    FROM projects p
    JOIN deliveries d ON d.project_id = p.id
    WHERE $carrier_id_filter
      AND p.id IN ($project_access_sql)
    GROUP BY p.id, p.project_name, p.image_url, p.project_address
    ORDER BY project_freight_cost DESC
";
$stmt_proj = $conn->prepare($proj_sql);
list($bind_types, $bind_params) = build_params($carrier_bind_types, $carrier_bind_ids, $access_types, $access_params);
if (!empty($bind_types)) {
    $stmt_proj->bind_param($bind_types, ...$bind_params);
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
    WHERE $carrier_id_filter
    AND d.anticipated_delivery_date IS NOT NULL
    AND d.project_id IN ($project_access_sql)
    GROUP BY week_ending
    UNION ALL
    SELECT
        DATE_FORMAT(DATE_SUB($delivered_date_expr, INTERVAL (DAYOFWEEK($delivered_date_expr) - 1) DAY) + INTERVAL 6 DAY, '%Y-%m-%d') as week_ending,
        'actual' as type,
        COUNT(DISTINCT d.id) as delivery_count
    FROM deliveries d
    WHERE $carrier_id_filter
    AND $delivered_date_expr IS NOT NULL
    AND d.project_id IN ($project_access_sql)
    GROUP BY week_ending
    ORDER BY week_ending, type
";
$stmt_timeline = $conn->prepare($timeline_sql);
// For timeline we need carrier params twice (once per UNION)
if ($is_solterra_view) {
    if (!empty($access_types)) {
        $tl_types = $access_types . $access_types;
        $tl_params = array_merge($access_params, $access_params);
        $stmt_timeline->bind_param($tl_types, ...$tl_params);
    }
} else {
    if (!empty($access_types)) {
        $tl_types = "i" . $access_types . "i" . $access_types;
        $tl_params = array_merge([$carrier_id], $access_params, [$carrier_id], $access_params);
        $stmt_timeline->bind_param($tl_types, ...$tl_params);
    } else {
        $stmt_timeline->bind_param("ii", $carrier_id, $carrier_id);
    }
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

// All deliveries for this carrier (full list for Performance tab charts, limited for Deliveries tab)
$all_deliveries = [];
$del_sql = "
    SELECT
        d.id,
        d.bol_number,
        d.anticipated_delivery_date,
        d.actual_delivery_date,
        d.warehouse_arrival_date,
        $delivered_date_expr AS delivered_date,
        d.freight_cost,
        d.miles,
        d.carrier_reference_number,
        d.status_of_delivery,
        p.project_name,
        p.id as project_id
    FROM deliveries d
    LEFT JOIN projects p ON p.id = d.project_id
    WHERE $carrier_id_filter
    AND d.project_id IN ($project_access_sql)
    ORDER BY COALESCE($delivered_date_expr, d.anticipated_delivery_date) DESC
";
$stmt_del = $conn->prepare($del_sql);
list($bind_types, $bind_params) = build_params($carrier_bind_types, $carrier_bind_ids, $access_types, $access_params);
if (!empty($bind_types)) {
    $stmt_del->bind_param($bind_types, ...$bind_params);
}
$stmt_del->execute();
$del_result = $stmt_del->get_result();
while ($row = $del_result->fetch_assoc()) {
    $all_deliveries[] = $row;
}
$stmt_del->close();

// Deliveries for the Deliveries tab (first 10)
$tab_deliveries = array_slice($all_deliveries, 0, 10);
$has_more_deliveries = count($all_deliveries) > 10;

// Carrier documents
$carrier_documents = [];
if (!$is_solterra_view || $isAdmin) {
    $carrier_documents = getProjectDocuments($conn, [
        'carrier_id' => $carrier_id,
        'document_type' => 'carrier_documents'
    ]);
}

// Handle document upload (POST)
$doc_success = '';
$doc_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_carrier_doc'])) {
    try {
        if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
            throw new Exception('You do not have permission to upload documents.');
        }
        if (!isset($_FILES['carrier_doc_file']) || $_FILES['carrier_doc_file']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception('Please select a file to upload.');
        }

        $processed = processDocumentUpload($_FILES['carrier_doc_file'], 'carrier_documents');
        $doc_sub_type = $_POST['carrier_doc_type'] ?? 'other';
        $valid_sub_types = ['coi', 'insurance_cert', 'carrier_contract', 'other'];
        if (!in_array($doc_sub_type, $valid_sub_types, true)) {
            $doc_sub_type = 'other';
        }

        $sub_type_label = determineDocumentSubType('carrier_documents', ['carrier_doc_type' => $doc_sub_type]);

        $doc_data = [
            'project_id' => null,
            'document_type' => 'carrier_documents',
            'document_sub_type' => $sub_type_label,
            'carrier_id' => $carrier_id,
            'original_name' => $processed['original_name'],
            'file_size' => $processed['size'],
            'mime_type' => $processed['mime_type'],
            'uploaded_by' => $user_id,
            'tmp_name' => $processed['tmp_name'],
            'entity_context' => "Carrier document for carrier ID: $carrier_id",
            'description' => trim($_POST['doc_description'] ?? '')
        ];

        saveDocumentToProjectDocuments($conn, $doc_data);
        $doc_success = 'Document uploaded successfully.';

        // Refresh documents list
        $carrier_documents = getProjectDocuments($conn, [
            'carrier_id' => $carrier_id,
            'document_type' => 'carrier_documents'
        ]);
    } catch (Exception $e) {
        $doc_error = $e->getMessage();
    }
}

// Handle document delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_carrier_doc'])) {
    try {
        if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
            throw new Exception('You do not have permission to delete documents.');
        }
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        if ($doc_id > 0) {
            deleteProjectDocument($conn, $doc_id, $user_id);
            $doc_success = 'Document deleted successfully.';
            $carrier_documents = getProjectDocuments($conn, [
                'carrier_id' => $carrier_id,
                'document_type' => 'carrier_documents'
            ]);
        }
    } catch (Exception $e) {
        $doc_error = $e->getMessage();
    }
}

$conn->close();

$type_labels = ['ftl' => 'FTL', 'ltl' => 'LTL', 'drayage' => 'Drayage', 'intermodal' => 'Intermodal', 'ocean' => 'Ocean', 'other' => 'Other'];
$carrier_display_name = htmlspecialchars(get_carrier_display_name($carrier, $role));
$carrier_type_label = $type_labels[$carrier['carrier_type']] ?? ucfirst($carrier['carrier_type']);
$compliance_status = get_carrier_compliance_status($carrier);
$compliance_badge = get_compliance_badge_html($carrier);
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
        body { background: #f8f9fa; font-family: 'Poppins', sans-serif; }

        .page-header {
            border-radius: 24px; padding: 32px; margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.06); border: 1px solid rgba(72,140,154,0.08);
            position: relative; overflow: hidden;
        }
        .page-header::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header h1 {
            font-size: 2.2em; font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
            margin: 0 0 8px 0; display: flex; align-items: center; gap: 16px;
        }
        .page-header p { color: #6c757d; font-size: 1.1em; margin: 0; }
        .header-badges { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .header-badge {
            font-size: 0.8em; font-weight: 600; padding: 6px 14px; border-radius: 20px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .header-badge.solterra { background: linear-gradient(135deg, #488C9A, #293E4C); color: #fff; }
        .header-badge.type { background: #e8f4f6; color: #293E4C; }
        .header-badge.mc { background: #f0f0ff; color: #4338ca; }
        .header-badge.dot { background: #fef3c7; color: #92400e; }
        .carrier-contact-info {
            display: flex; flex-wrap: wrap; gap: 20px; margin-top: 16px;
            font-size: 0.9em; color: #6c757d;
        }
        .carrier-contact-info span { display: flex; align-items: center; gap: 6px; }
        .carrier-contact-info i { color: #488C9A; }

        /* Tab Navigation */
        .carrier-tabs {
            display: flex; gap: 4px; margin-bottom: 24px;
            background: #fff; border-radius: 16px; padding: 6px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef;
        }
        .tab-btn {
            flex: 1; padding: 14px 20px; border: none; background: transparent;
            border-radius: 12px; cursor: pointer; font-family: inherit;
            font-size: 0.95em; font-weight: 600; color: #6c757d;
            transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .tab-btn:hover { color: #488C9A; background: rgba(72,140,154,0.06); }
        .tab-btn.active {
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            color: #fff; box-shadow: 0 4px 12px rgba(72,140,154,0.3);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Stat cards */
        .stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card {
            background: #fff; border-radius: 16px; padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef;
            text-align: center; transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
        .stat-card.primary { background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%); border: none; }
        .stat-card.primary .stat-label, .stat-card.primary .stat-value { color: #fff; }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px; font-size: 1.5em;
        }
        .stat-card.primary .stat-icon { background: rgba(255,255,255,0.2); color: #fff; }
        .stat-icon.blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #2563eb; }
        .stat-icon.purple { background: linear-gradient(135deg, #ede9fe, #ddd6fe); color: #7c3aed; }
        .stat-icon.green { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
        .stat-icon.orange { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #fbb040; }
        .stat-icon.red { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #E4572E; }
        .stat-value { font-size: 1.8em; font-weight: 700; color: #293E4C; margin-bottom: 4px; }
        .stat-label { color: #6c757d; font-size: 0.85em; font-weight: 500; }
        .stat-value.good { color: #059669; }
        .stat-value.warning { color: #fbb040; }
        .stat-value.poor { color: #E4572E; }

        /* Section Card */
        .section-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px; padding: 24px; margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.06); border: 1px solid rgba(72,140,154,0.08);
        }
        .section-card h2 {
            font-size: 1.4em; font-weight: 700; color: #293E4C; margin: 0 0 24px 0;
            display: flex; align-items: center; gap: 12px;
        }
        .section-card h2 i { color: #488C9A; }

        /* Compliance Card */
        .compliance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
        .compliance-item {
            background: #fff; border-radius: 12px; padding: 16px; border: 1px solid #e9ecef; text-align: center;
        }
        .compliance-item .label { font-size: 0.8em; color: #6c757d; font-weight: 500; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; }
        .compliance-item .value { font-size: 1.1em; font-weight: 700; color: #293E4C; }
        .compliance-item .value.good { color: #059669; }
        .compliance-item .value.warning { color: #fbb040; }
        .compliance-item .value.poor { color: #dc2626; }

        /* Table card (for deliveries) */
        .table-card {
            background: #fff; border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06); overflow: hidden;
            border: 1px solid #e9ecef; margin-bottom: 24px;
        }
        .table-card-header {
            display: flex; align-items: center; gap: 12px; padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e9ecef;
        }
        .table-card-header .icon-badge {
            background: linear-gradient(135deg, #488C9A, #3a7a87); color: #fff;
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 1rem;
        }
        .table-card-header h3 { margin: 0; font-size: 1.15rem; font-weight: 600; color: #293E4C; }

        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            padding: 14px 16px; text-align: left; font-weight: 600; color: #fff; font-size: 0.85em;
        }
        .data-table td { padding: 14px 16px; border-bottom: 1px solid #f1f3f4; font-size: 0.9em; }
        .data-table tbody tr { transition: all 0.2s; border-left: 3px solid transparent; }
        .data-table tbody tr:hover { background: #f0f7f8; border-left-color: #488C9A; }
        .cost-highlight { font-weight: 600; color: #488C9A; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 0.75em; font-weight: 600; }
        .status-badge.status-delivered, .status-badge.on-time { background: #d1fae5; color: #059669; }
        .status-badge.status-transit { background: #dbeafe; color: #2563eb; }
        .status-badge.status-pending, .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.late { background: #fee2e2; color: #E4572E; }

        .view-all-link {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 16px; color: #488C9A; font-weight: 600; text-decoration: none;
            border-top: 1px solid #e9ecef; transition: background 0.2s;
        }
        .view-all-link:hover { background: rgba(72,140,154,0.06); }

        /* Charts */
        .charts-section { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .chart-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px; padding: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.06); border: 1px solid rgba(72,140,154,0.08);
        }
        .chart-card h3 { font-size: 1.2em; font-weight: 600; color: #293E4C; margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px; }
        .chart-card h3 i { color: #488C9A; }
        .chart-container { position: relative; height: 280px; }

        /* Delivery Story */
        .delivery-story-grid { display: grid; grid-template-columns: 1fr 1.6fr; gap: 24px; }
        .delivery-story-card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; }
        .story-label { font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.08em; color: #488C9A; font-weight: 600; }
        .story-value { font-size: 2.4em; font-weight: 700; color: #293E4C; margin: 8px 0 6px; }
        .story-value.good { color: #059669; } .story-value.poor { color: #E4572E; } .story-value.neutral { color: #fbb040; }
        .story-subtitle { font-size: 0.95em; color: #6c757d; line-height: 1.4; }
        .story-subtitle + .story-subtitle { margin-top: 6px; }
        .story-details { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 18px; }
        .story-detail { background: #f8f9fa; border-radius: 12px; padding: 12px; text-align: center; }
        .story-detail .label { font-size: 0.7em; text-transform: uppercase; letter-spacing: 0.06em; color: #6c757d; font-weight: 600; }
        .story-detail .value { font-size: 1.1em; font-weight: 700; color: #293E4C; margin-top: 4px; }
        .story-note { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #fff3cd; border-radius: 10px; color: #856404; font-size: 0.85em; margin-top: 14px; }
        .story-note i { color: #E4572E; }
        .story-chart-container { position: relative; height: 300px; margin-top: 14px; }
        .story-chart-empty { height: 100%; display: flex; align-items: center; justify-content: center; background: #f8f9fa; border-radius: 12px; color: #6c757d; font-size: 0.9em; }
        .story-callouts { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .story-callout { background: #f8f9fa; border-radius: 10px; padding: 10px 12px; font-size: 0.85em; color: #6c757d; }
        .story-callout strong { color: #293E4C; font-weight: 600; }

        /* Safety */
        .safety-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
        .safety-stat { background: #fff; border-radius: 12px; padding: 20px; text-align: center; border: 1px solid #e9ecef; }
        .safety-stat .value { font-size: 1.8em; font-weight: 700; color: #293E4C; }
        .safety-stat .value.good { color: #059669; } .safety-stat .value.poor { color: #E4572E; }
        .safety-stat .label { font-size: 0.85em; color: #6c757d; margin-top: 4px; }
        .driver-reported-badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 0.72em; font-weight: 600; background: #fee2e2; color: #E4572E; }
        .table-container { overflow-x: auto; border-radius: 12px; }

        /* Projects Grid */
        .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .project-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.04); border: 1px solid #e9ecef; overflow: hidden; transition: all 0.2s; cursor: pointer; }
        .project-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        .project-card-image { width: 100%; height: 120px; background: linear-gradient(135deg, #e8f4f6, #d1e8ec); position: relative; overflow: hidden; }
        .project-card-image img { width: 100%; height: 100%; object-fit: cover; }
        .project-card-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(45deg, rgba(72,140,154,0.9), rgba(41,62,76,0.9)); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
        .project-card:hover .project-card-overlay { opacity: 1; }
        .project-card-overlay span { color: #fff; font-size: 0.9em; font-weight: 600; }
        .project-card-content { padding: 16px; }
        .project-card-title { margin: 0 0 12px; font-size: 1em; color: #293E4C; font-weight: 600; }
        .project-card-stats { display: flex; flex-direction: column; gap: 6px; }
        .project-stat-row { display: flex; justify-content: space-between; font-size: 0.85em; }
        .project-stat-row .label { color: #6c757d; }
        .project-stat-row .value { font-weight: 600; color: #488C9A; }
        .project-stat-row .value.good { color: #059669; }
        .project-stat-row .value.poor { color: #E4572E; }

        /* Documents tab */
        .doc-upload-form {
            background: #fff; border-radius: 16px; padding: 24px; border: 2px dashed #e9ecef;
            margin-bottom: 24px; transition: border-color 0.2s;
        }
        .doc-upload-form:hover { border-color: #488C9A; }
        .doc-list { display: flex; flex-direction: column; gap: 12px; }
        .doc-item {
            display: flex; align-items: center; gap: 16px; padding: 16px 20px;
            background: #fff; border-radius: 12px; border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .doc-item:hover { border-color: #488C9A; box-shadow: 0 4px 12px rgba(72,140,154,0.1); }
        .doc-icon {
            width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            font-size: 1.2em; flex-shrink: 0;
            background: linear-gradient(135deg, #e8f4f6, #d1e8ec); color: #488C9A;
        }
        .doc-info { flex: 1; min-width: 0; }
        .doc-name { font-weight: 600; color: #293E4C; font-size: 0.95em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .doc-meta { font-size: 0.8em; color: #6c757d; margin-top: 2px; }
        .doc-actions { display: flex; gap: 8px; flex-shrink: 0; }
        .doc-btn {
            padding: 8px 14px; border-radius: 8px; border: none; cursor: pointer;
            font-size: 0.85em; font-weight: 600; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
        }
        .doc-btn.download { background: #e8f4f6; color: #488C9A; }
        .doc-btn.download:hover { background: #488C9A; color: #fff; }
        .doc-btn.delete { background: #fee2e2; color: #dc2626; }
        .doc-btn.delete:hover { background: #dc2626; color: #fff; }

        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: 500; color: #333; margin-bottom: 6px; font-size: 0.9em; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px 14px; border: 2px solid #e9ecef; border-radius: 10px;
            font-size: 0.95em; background: #fafafa; font-family: inherit; width: 100%; box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #488C9A; background: #fff; box-shadow: 0 0 0 3px rgba(72,140,154,0.1);
        }
        .btn-upload {
            background: linear-gradient(135deg, #488C9A, #3a7a87); color: #fff; border: none;
            padding: 12px 24px; border-radius: 12px; font-weight: 600; cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(72,140,154,0.3);
        }
        .btn-upload:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(72,140,154,0.4); }

        .alert-success { padding: 12px 16px; border-radius: 10px; background: #d1fae5; color: #059669; font-weight: 500; margin-bottom: 16px; }
        .alert-error { padding: 12px 16px; border-radius: 10px; background: #fee2e2; color: #dc2626; font-weight: 500; margin-bottom: 16px; }

        /* Breakdown Modal */
        .breakdown-modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background: rgba(0,0,0,0.5); backdrop-filter: blur(5px); }
        .breakdown-modal-content { background: white; margin: 8% auto; padding: 0; width: 90%; max-width: 600px; border-radius: 20px; box-shadow: 0 25px 50px rgba(0,0,0,0.25); animation: modalSlideIn 0.3s ease; max-height: 80vh; display: flex; flex-direction: column; }
        @keyframes modalSlideIn { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .breakdown-modal-header { background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%); color: white; padding: 24px; border-radius: 20px 20px 0 0; position: relative; flex-shrink: 0; }
        .breakdown-modal-header h2 { margin: 0; font-size: 1.4em; font-weight: 600; display: flex; align-items: center; gap: 12px; }
        .breakdown-modal-close { position: absolute; top: 20px; right: 24px; font-size: 28px; font-weight: bold; color: white; cursor: pointer; border: none; background: transparent; transition: transform 0.2s; }
        .breakdown-modal-close:hover { transform: scale(1.1); }
        .breakdown-modal-body { padding: 24px; overflow-y: auto; }
        .breakdown-list { display: flex; flex-direction: column; gap: 8px; }
        .breakdown-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f8f9fa; border-radius: 10px; transition: background 0.2s; cursor: pointer; }
        .breakdown-item:hover { background: #e9ecef; }
        .breakdown-item-name { font-weight: 500; color: #293E4C; }
        .breakdown-item-value { font-weight: 700; color: #488C9A; }
        .breakdown-total { display: flex; justify-content: space-between; align-items: center; padding: 16px; background: linear-gradient(135deg, #e8f4f6, #d1e8ec); border-radius: 12px; margin-top: 16px; border: 1px solid rgba(72,140,154,0.2); }
        .breakdown-total-label { font-weight: 600; color: #293E4C; }
        .breakdown-total-value { font-size: 1.3em; font-weight: 700; color: #488C9A; }

        .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
        .empty-state h3 { color: #293E4C; margin-bottom: 8px; }

        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-section { grid-template-columns: 1fr; }
            .delivery-story-grid { grid-template-columns: 1fr; }
            .safety-grid { grid-template-columns: 1fr; }
            .carrier-tabs { flex-wrap: wrap; }
        }
        @media (max-width: 768px) {
            .page-header { padding: 24px; }
            .page-header h1 { font-size: 1.8em; }
            .stats-grid { grid-template-columns: 1fr; }
            .projects-grid { grid-template-columns: 1fr; }
            .tab-btn { padding: 10px 12px; font-size: 0.85em; }
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
            ['label' => 'Carrier Overview', 'url' => 'carrier_overview.php']
        ]
    ]);
    ?>

    <!-- Page Header (above tabs) -->
    <div class="page-header">
        <h1><?php echo $carrier_display_name; ?></h1>
        <p>Carrier performance details with delivery reliability, cost analysis, and safety metrics</p>
        <div class="header-badges">
            <?php if ($isAdmin && $carrier['is_solterra_managed']): ?>
                <span class="header-badge solterra"><i class="fas fa-shield-alt"></i> Solterra Managed</span>
            <?php endif; ?>
            <span class="header-badge type"><i class="fas fa-truck"></i> <?php echo $carrier_type_label; ?></span>
            <?php if ($compliance_badge): ?>
                <?php echo $compliance_badge; ?>
            <?php endif; ?>
            <?php if (!$is_solterra_view || $isAdmin): ?>
                <?php if (!empty($carrier['mc_number'])): ?>
                    <span class="header-badge mc"><i class="fas fa-id-card"></i> MC# <?php echo htmlspecialchars($carrier['mc_number']); ?></span>
                <?php endif; ?>
                <?php if (!empty($carrier['dot_number'])): ?>
                    <span class="header-badge dot"><i class="fas fa-hashtag"></i> DOT# <?php echo htmlspecialchars($carrier['dot_number']); ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if (!$is_solterra_view || $isAdmin): ?>
        <?php if (!empty($carrier['contact_person']) || !empty($carrier['phone']) || !empty($carrier['email'])): ?>
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
            <?php if (!empty($carrier['website'])): ?>
                <span><i class="fas fa-globe"></i> <a href="<?php echo htmlspecialchars($carrier['website']); ?>" target="_blank" style="color:#488C9A;"><?php echo htmlspecialchars($carrier['website']); ?></a></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Tab Navigation -->
    <div class="carrier-tabs">
        <button class="tab-btn active" data-tab="tab-overview"><i class="fas fa-th-large"></i> Overview</button>
        <button class="tab-btn" data-tab="tab-deliveries"><i class="fas fa-clipboard-list"></i> Deliveries</button>
        <button class="tab-btn" data-tab="tab-performance"><i class="fas fa-chart-line"></i> Performance</button>
        <?php if (!$is_solterra_view || $isAdmin): ?>
        <button class="tab-btn" data-tab="tab-documents"><i class="fas fa-file-alt"></i> Documents</button>
        <?php endif; ?>
    </div>

    <!-- ==================== OVERVIEW TAB ==================== -->
    <div id="tab-overview" class="tab-content active">
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-value">$<?php echo number_format($total_freight_cost, 0); ?></div>
                <div class="stat-label">Total Freight Cost</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-truck"></i></div>
                <div class="stat-value"><?php echo number_format($total_deliveries); ?></div>
                <div class="stat-label">Total Deliveries</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon <?php echo $on_time_rate === null ? 'orange' : ($on_time_rate >= 90 ? 'green' : ($on_time_rate >= 70 ? 'orange' : 'red')); ?>"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-value <?php echo $on_time_class; ?>"><?php echo $on_time_label; ?></div>
                <div class="stat-label">On-Time Rate</div>
            </div>
            <div class="stat-card">
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

        <!-- Compliance Card -->
        <?php if ($isAdmin || (!$is_solterra_view)): ?>
        <div class="section-card">
            <h2><i class="fas fa-shield-alt"></i> Compliance</h2>
            <div class="compliance-grid">
                <div class="compliance-item">
                    <div class="label">COI on File</div>
                    <div class="value <?php echo !empty($carrier['coi_on_file']) ? 'good' : ''; ?>">
                        <?php echo !empty($carrier['coi_on_file']) ? 'Yes' : 'No'; ?>
                    </div>
                </div>
                <div class="compliance-item">
                    <div class="label">COI Expiration</div>
                    <div class="value <?php
                        if (!empty($carrier['coi_expiration_date'])) {
                            $days_left = (strtotime($carrier['coi_expiration_date']) - time()) / 86400;
                            echo $days_left < 0 ? 'poor' : ($days_left <= 30 ? 'warning' : 'good');
                        }
                    ?>">
                        <?php echo !empty($carrier['coi_expiration_date']) ? date('M j, Y', strtotime($carrier['coi_expiration_date'])) : 'N/A'; ?>
                    </div>
                </div>
                <div class="compliance-item">
                    <div class="label">Insurance Minimum</div>
                    <div class="value <?php echo !empty($carrier['insurance_minimum_met']) ? 'good' : ''; ?>">
                        <?php echo !empty($carrier['insurance_minimum_met']) ? 'Met' : 'Not Met'; ?>
                    </div>
                </div>
                <div class="compliance-item">
                    <div class="label">Authority Status</div>
                    <div class="value <?php
                        $auth = $carrier['authority_status'] ?? null;
                        echo $auth === 'active' ? 'good' : ($auth === 'revoked' ? 'poor' : ($auth === 'inactive' ? 'warning' : ''));
                    ?>">
                        <?php echo $auth ? ucfirst($auth) : 'N/A'; ?>
                    </div>
                </div>
                <div class="compliance-item">
                    <div class="label">FMCSA Rating</div>
                    <div class="value <?php
                        $rating = $carrier['fmcsa_safety_rating'] ?? null;
                        echo $rating === 'satisfactory' ? 'good' : ($rating === 'unsatisfactory' ? 'poor' : ($rating === 'conditional' ? 'warning' : ''));
                    ?>">
                        <?php echo $rating ? ucfirst(str_replace('_', ' ', $rating)) : 'N/A'; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Projects Grid -->
        <?php if (!empty($projects_for_carrier)): ?>
        <div class="section-card">
            <h2><i class="fas fa-folder-open"></i> Projects</h2>
            <div class="projects-grid">
                <?php foreach ($projects_for_carrier as $proj):
                    $proj_total_del = (int)$proj['total_deliveries'];
                    $proj_completed_del = (int)$proj['completed_deliveries'];
                    $proj_date_tracked_del = (int)($proj['date_tracked_deliveries'] ?? 0);
                    $proj_on_time = (int)$proj['on_time'];
                    $proj_on_time_pct = $proj_date_tracked_del > 0 ? round(($proj_on_time / $proj_date_tracked_del) * 100) : null;
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
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notes -->
        <?php if (!empty($carrier['notes']) && ($isAdmin || !$is_solterra_view)): ?>
        <div class="section-card">
            <h2><i class="fas fa-sticky-note"></i> Notes</h2>
            <p style="color:#6c757d; line-height:1.6;"><?php echo nl2br(htmlspecialchars($carrier['notes'])); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ==================== DELIVERIES TAB ==================== -->
    <div id="tab-deliveries" class="tab-content">
        <?php if (!empty($tab_deliveries)): ?>
        <div class="table-card">
            <div class="table-card-header">
                <div class="icon-badge"><i class="fas fa-clipboard-list"></i></div>
                <h3>Recent Deliveries</h3>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>BOL #</th>
                            <th>Project</th>
                            <th>Status</th>
                            <th>Anticipated Date</th>
                            <th>Actual / Arrival Date</th>
                            <th>Freight Cost</th>
                            <th>Miles</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tab_deliveries as $del):
                            $del_status = 'pending';
                            $del_status_label = 'Pending';
                            $del_status_class = 'status-pending';
                            $delivered_date = $del['delivered_date'] ?? null;
                            if (!empty($delivered_date) && !empty($del['anticipated_delivery_date'])) {
                                if ($delivered_date <= $del['anticipated_delivery_date']) {
                                    $del_status = 'on-time';
                                    $del_status_label = 'On Time';
                                    $del_status_class = 'status-delivered';
                                } else {
                                    $del_status = 'late';
                                    $days_late = (strtotime($delivered_date) - strtotime($del['anticipated_delivery_date'])) / 86400;
                                    $del_status_label = round($days_late) . 'd Late';
                                    $del_status_class = 'late';
                                }
                            } elseif (!empty($delivered_date)) {
                                $del_status_label = 'Delivered';
                                $del_status_class = 'status-delivered';
                            } elseif (!empty($del['status_of_delivery']) && stripos($del['status_of_delivery'], 'transit') !== false) {
                                $del_status_label = 'In Transit';
                                $del_status_class = 'status-transit';
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo !empty($del['bol_number']) ? htmlspecialchars($del['bol_number']) : '—'; ?></strong></td>
                            <td>
                                <?php if (!empty($del['project_name'])): ?>
                                    <a href="project_overview?project_id=<?php echo (int)$del['project_id']; ?>" style="color:#488C9A; text-decoration:none; font-weight:500;"><?php echo htmlspecialchars($del['project_name']); ?></a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><span class="status-badge <?php echo $del_status_class; ?>"><?php echo $del_status_label; ?></span></td>
                            <td><?php echo !empty($del['anticipated_delivery_date']) ? date('M j, Y', strtotime($del['anticipated_delivery_date'])) : '—'; ?></td>
                            <td><?php echo !empty($delivered_date) ? date('M j, Y', strtotime($delivered_date)) : '—'; ?></td>
                            <td class="cost-highlight"><?php echo $del['freight_cost'] > 0 ? '$' . number_format($del['freight_cost'], 2) : '—'; ?></td>
                            <td><?php echo $del['miles'] > 0 ? number_format($del['miles'], 0) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($has_more_deliveries): ?>
            <a href="#" class="view-all-link" onclick="activateTab('tab-performance'); return false;">
                <i class="fas fa-arrow-right"></i> View All <?php echo number_format(count($all_deliveries)); ?> Deliveries
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-truck" style="font-size:3em; color:#488C9A; margin-bottom:16px;"></i>
            <h3>No Deliveries Yet</h3>
            <p>No delivery records found for this carrier.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ==================== PERFORMANCE TAB ==================== -->
    <div id="tab-performance" class="tab-content">
        <!-- Charts -->
        <?php if (!empty($projects_for_carrier)): ?>
        <div class="charts-section">
            <div class="chart-card">
                <h3><i class="fas fa-chart-pie"></i> Freight Cost by Project</h3>
                <div class="chart-container"><canvas id="projectCostChart"></canvas></div>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-chart-bar"></i> Deliveries by Project</h3>
                <div class="chart-container"><canvas id="projectDeliveriesChart"></canvas></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Delivery Story -->
        <?php if ($completed_deliveries > 0 || !empty($all_weeks)): ?>
        <div class="section-card" id="delivery-story-section">
            <h2><i class="fas fa-truck"></i> Delivery Story</h2>
            <div class="delivery-story-grid">
                <div class="delivery-story-card">
                    <div class="story-label">Delivery Reliability</div>
                    <div class="story-value <?php echo $on_time_class; ?>"><?php echo $on_time_label; ?></div>
                    <div class="story-subtitle">Percentage of deliveries that arrived on or before the anticipated delivery date.</div>
                    <div class="story-subtitle">
                        <?php if ($date_tracked_completed_deliveries > 0): ?>
                            <?php echo $deliveries_on_time; ?> of <?php echo $date_tracked_completed_deliveries; ?> date-tracked deliveries arrived on time.
                        <?php else: ?>
                            No completed deliveries with date tracking yet.
                        <?php endif; ?>
                    </div>
                    <div class="story-details">
                        <div class="story-detail"><div class="label">On Time</div><div class="value"><?php echo $deliveries_on_time; ?></div></div>
                        <div class="story-detail"><div class="label">Late</div><div class="value"><?php echo $deliveries_late; ?></div></div>
                        <div class="story-detail"><div class="label">Pending</div><div class="value"><?php echo max($total_deliveries - $completed_deliveries, 0); ?></div></div>
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
                    <div class="story-subtitle">Anticipated vs actual cumulative deliveries highlight pacing and spikes.</div>
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
                        <tr><th>Date</th><th>Project</th><th>BOL</th><th>Driver Reported</th><th>Notes</th></tr>
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
    </div>

    <!-- ==================== DOCUMENTS TAB ==================== -->
    <div id="tab-documents" class="tab-content">
        <?php if (!empty($doc_success)): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($doc_success); ?></div>
        <?php endif; ?>
        <?php if (!empty($doc_error)): ?>
            <div class="alert-error"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($doc_error); ?></div>
        <?php endif; ?>

        <!-- Upload Form (admin/customer_admin only) -->
        <?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'], true) && (!$is_solterra_view || $isAdmin)): ?>
        <form method="POST" enctype="multipart/form-data" class="doc-upload-form">
            <h3 style="margin:0 0 16px; color:#293E4C; font-weight:600;"><i class="fas fa-cloud-upload-alt" style="color:#488C9A;"></i> Upload Document</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="carrier_doc_type">Document Type</label>
                    <select id="carrier_doc_type" name="carrier_doc_type">
                        <option value="coi">Certificate of Insurance (COI)</option>
                        <option value="insurance_cert">Insurance Certificate</option>
                        <option value="carrier_contract">Carrier Contract</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="carrier_doc_file">File</label>
                    <input type="file" id="carrier_doc_file" name="carrier_doc_file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt,.csv">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="flex:1;">
                    <label for="doc_description">Description (optional)</label>
                    <input type="text" id="doc_description" name="doc_description" placeholder="Brief description of the document">
                </div>
            </div>
            <button type="submit" name="upload_carrier_doc" value="1" class="btn-upload"><i class="fas fa-upload"></i> Upload</button>
        </form>
        <?php endif; ?>

        <!-- Document List -->
        <?php if (!empty($carrier_documents)): ?>
        <div class="section-card">
            <h2><i class="fas fa-folder-open"></i> Carrier Documents</h2>
            <div class="doc-list">
                <?php foreach ($carrier_documents as $doc):
                    $ext = strtolower(pathinfo($doc['original_file_name'], PATHINFO_EXTENSION));
                    $icon_map = ['pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word', 'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel', 'jpg' => 'fa-file-image', 'jpeg' => 'fa-file-image', 'png' => 'fa-file-image'];
                    $icon_class = $icon_map[$ext] ?? 'fa-file';
                ?>
                <div class="doc-item">
                    <div class="doc-icon"><i class="fas <?php echo $icon_class; ?>"></i></div>
                    <div class="doc-info">
                        <div class="doc-name"><?php echo htmlspecialchars($doc['original_file_name']); ?></div>
                        <div class="doc-meta">
                            <?php echo htmlspecialchars($doc['document_sub_type'] ?? 'Document'); ?>
                            &middot; <?php echo $doc['formatted_size']; ?>
                            &middot; <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?>
                            <?php if (!empty($doc['uploaded_by_name'])): ?>
                                &middot; by <?php echo htmlspecialchars($doc['uploaded_by_name']); ?>
                            <?php endif; ?>
                            <?php if (!empty($doc['description'])): ?>
                                <br><?php echo htmlspecialchars($doc['description']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="doc-actions">
                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" download class="doc-btn download"><i class="fas fa-download"></i> Download</a>
                        <?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'], true)): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this document?');">
                            <input type="hidden" name="doc_id" value="<?php echo (int)$doc['id']; ?>">
                            <button type="submit" name="delete_carrier_doc" value="1" class="doc-btn delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-file-alt" style="font-size:3em; color:#488C9A; margin-bottom:16px;"></i>
            <h3>No Documents</h3>
            <p>No documents have been uploaded for this carrier yet.</p>
        </div>
        <?php endif; ?>
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
</main>

<script>
// Tab System (hash-based routing)
function activateTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

    const btn = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
    const content = document.getElementById(tabId);
    if (btn) btn.classList.add('active');
    if (content) content.classList.add('active');

    history.replaceState(null, '', '#' + tabId);

    // Initialize charts when Performance tab is first shown
    if (tabId === 'tab-performance' && !window._chartsInitialized) {
        window._chartsInitialized = true;
        initCharts();
    }
}

function setActiveTabFromHash() {
    const hash = window.location.hash.replace('#', '');
    const validTabs = ['tab-overview', 'tab-deliveries', 'tab-performance', 'tab-documents'];
    if (validTabs.includes(hash)) {
        activateTab(hash);
    }
}

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => activateTab(btn.dataset.tab));
});

window.addEventListener('hashchange', setActiveTabFromHash);

// On load
document.addEventListener('DOMContentLoaded', function() {
    setActiveTabFromHash();
});

// Modal
function openCostModal() { document.getElementById('costModal').style.display = 'block'; }
function closeCostModal() { document.getElementById('costModal').style.display = 'none'; }
window.onclick = function(event) {
    if (event.target.classList.contains('breakdown-modal')) {
        event.target.style.display = 'none';
    }
}

// Charts (initialized lazily when Performance tab is shown)
function initCharts() {
    <?php if (!empty($projects_for_carrier)): ?>
    const projectsData = <?php echo json_encode($projects_for_carrier); ?>;
    const projectNames = projectsData.map(p => p.project_name);
    const projectCosts = projectsData.map(p => parseFloat(p.project_freight_cost) || 0);
    const projectDeliveries = projectsData.map(p => parseInt(p.total_deliveries) || 0);

    const colors = ['#488C9A', '#293E4C', '#fbb040', '#059669', '#E4572E', '#3b82f6', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316'];

    const costCtx = document.getElementById('projectCostChart');
    if (costCtx) {
        new Chart(costCtx, {
            type: 'doughnut',
            data: { labels: projectNames, datasets: [{ data: projectCosts, backgroundColor: colors.slice(0, projectNames.length), borderWidth: 0 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { padding: 15, usePointStyle: true } },
                    tooltip: { callbacks: { label: function(ctx) { const val = ctx.raw; const total = ctx.dataset.data.reduce((a,b)=>a+b,0); const pct = total>0?((val/total)*100).toFixed(1):0; return `${ctx.label}: $${val.toLocaleString()} (${pct}%)`; } } }
                },
                cutout: '55%'
            }
        });
    }

    const delCtx = document.getElementById('projectDeliveriesChart');
    if (delCtx) {
        new Chart(delCtx, {
            type: 'bar',
            data: { labels: projectNames, datasets: [{ label: 'Deliveries', data: projectDeliveries, backgroundColor: colors.slice(0, projectNames.length), borderRadius: 6 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return `${ctx.raw} deliveries`; } } } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { grid: { display: false } } }
            }
        });
    }
    <?php endif; ?>

    // Cumulative line chart
    const cumulativeCtx = document.getElementById('cumulativeDeliveryChart');
    if (cumulativeCtx) {
        const weekLabels = <?php echo json_encode($all_weeks); ?>;
        const anticipatedData = <?php echo json_encode($cumulative_anticipated); ?>;
        const actualData = <?php echo json_encode($cumulative_actual); ?>;
        const formattedLabels = weekLabels.map(w => { const d = new Date(w); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); });

        new Chart(cumulativeCtx, {
            type: 'line',
            data: {
                labels: formattedLabels,
                datasets: [
                    { label: 'Anticipated', data: anticipatedData, borderColor: '#488C9A', backgroundColor: 'rgba(72,140,154,0.1)', borderWidth: 2, fill: true, tension: 0.3, pointRadius: 3, pointBackgroundColor: '#488C9A' },
                    { label: 'Actual', data: actualData, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.1)', borderWidth: 3, fill: false, tension: 0.3, pointRadius: 4, pointBackgroundColor: '#059669', spanGaps: false }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, padding: 20 } },
                    tooltip: { callbacks: { label: function(ctx) { if (ctx.raw === null) return null; return `${ctx.dataset.label}: ${ctx.raw} deliveries`; } } }
                },
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Cumulative Deliveries' }, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { title: { display: true, text: 'Week Ending' }, grid: { display: false } }
                }
            }
        });
    }
}
</script>
</body>
</html>
