<?php
// Initialize session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) { die("Connection failed"); }

$user_id = $_SESSION['user_id'];
$accountIds = [];

$displayName = $_SESSION['username'];
$stmtUser = $conn->prepare("SELECT first_name FROM users WHERE id = ?");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$stmtUser->bind_result($firstName);
$stmtUser->fetch();
$stmtUser->close();
if (!empty($firstName)) { $displayName = $firstName; }

if ($role === 'global_admin') {
    $resultAccts = $conn->query("SELECT id FROM customer_accounts");
    if ($resultAccts) { while ($row = $resultAccts->fetch_assoc()) { $accountIds[] = (int)$row['id']; } }
} else {
    $stmtAccts = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ?");
    $stmtAccts->bind_param("i", $user_id);
    $stmtAccts->execute();
    $resultAccts = $stmtAccts->get_result();
    while ($row = $resultAccts->fetch_assoc()) { $accountIds[] = (int)$row['account_id']; }
    $stmtAccts->close();
}

$unassigned_modules_count = 0;
if (count($accountIds) > 0) {
    $placeholders_unassigned = implode(',', array_fill(0, count($accountIds), '?'));
    $sqlUnassigned = "SELECT COUNT(DISTINCT umi.id) as unassigned_count FROM unassigned_module_items umi JOIN modules m ON umi.unassigned_module_id = m.id LEFT JOIN projects p ON p.account_id IN ($placeholders_unassigned) WHERE umi.id NOT IN (SELECT ip.unassigned_module_item_id FROM inventory_pallets ip WHERE ip.assigned_project_id IS NOT NULL AND ip.unassigned_module_item_id IS NOT NULL)";
    $stmtUnassigned = $conn->prepare($sqlUnassigned);
    $stmtUnassigned->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmtUnassigned->execute();
    $stmtUnassigned->bind_result($unassigned_modules_count);
    $stmtUnassigned->fetch();
    $stmtUnassigned->close();
    $unassigned_modules_count = $unassigned_modules_count ?: 0;
}

$projects = [];
$dashboard_totals = [
    'total_modules' => 0, 'total_delivered' => 0, 'total_in_storage' => 0,
    'total_project_size_mw' => 0, 'total_ordered_mw' => 0, 'delivered_mw' => 0, 'storage_mw' => 0,
    'health_counts' => ['on_track' => 0, 'at_risk' => 0, 'behind' => 0, 'completed' => 0]
];

$archived_count = 0;
if (count($accountIds) > 0) {
    $placeholders_archived = implode(',', array_fill(0, count($accountIds), '?'));
    $stmtArchived = $conn->prepare("SELECT COUNT(*) FROM archived_projects WHERE account_id IN ($placeholders_archived)");
    $stmtArchived->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmtArchived->execute();
    $stmtArchived->bind_result($archived_count);
    $stmtArchived->fetch();
    $stmtArchived->close();
}

if (count($accountIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $stmt = $conn->prepare("SELECT id, project_name, project_address, image_url, estimated_completion_date, project_size FROM projects WHERE account_id IN ($placeholders) AND (status IS NULL OR status = 'active') ORDER BY id ASC");
    $stmt->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $project_id = $row['id'];
        $project = $row;

        $stmt_wattage = $conn->prepare("SELECT wattage, total_order FROM project_wattage_orders WHERE project_id = ? ORDER BY wattage ASC");
        $stmt_wattage->bind_param("i", $project_id);
        $stmt_wattage->execute();
        $wattage_result = $stmt_wattage->get_result();
        $stmt_wattage->close();

        $total_order_quantity = 0;
        $weighted_wattage_sum = 0;
        $wattage_breakdown = [];
        while ($wattage_row = $wattage_result->fetch_assoc()) {
            $qty = (int)$wattage_row['total_order'];
            $watt = (float)$wattage_row['wattage'];
            $total_order_quantity += $qty;
            $weighted_wattage_sum += ($qty * $watt);
            if ($qty > 0) { $wattage_breakdown[] = ['wattage' => $watt, 'quantity' => $qty, 'mw' => ($qty * $watt) / 1000000]; }
        }

        $avg_wattage = $total_order_quantity > 0 ? $weighted_wattage_sum / $total_order_quantity : 0;
        $project['project_size'] = isset($project['project_size']) ? (float)$project['project_size'] : 0;
        $project['total_modules'] = $total_order_quantity;
        $project['wattage_breakdown'] = $wattage_breakdown;
        $project['ordered_mw'] = ($total_order_quantity * $avg_wattage) / 1000000;
        $project['order_progress'] = $project['project_size'] > 0 ? min(100, ($project['ordered_mw'] / $project['project_size']) * 100) : 0;

        $stmt_delivered = $conn->prepare("SELECT d.wattage, SUM(d.quantity) as delivered_qty FROM deliveries d WHERE d.project_id = ? AND d.status_of_delivery = 'Delivered to Project' GROUP BY d.wattage");
        $stmt_delivered->bind_param("i", $project_id);
        $stmt_delivered->execute();
        $delivered_result = $stmt_delivered->get_result();
        $stmt_delivered->close();

        $total_delivered = 0;
        $delivered_mw = 0;
        $delivered_breakdown = [];
        while ($del_row = $delivered_result->fetch_assoc()) {
            $del_qty = (int)$del_row['delivered_qty'];
            $del_watt = (float)$del_row['wattage'];
            $total_delivered += $del_qty;
            $del_mw = ($del_qty * $del_watt) / 1000000;
            $delivered_mw += $del_mw;
            if ($del_qty > 0) { $delivered_breakdown[] = ['wattage' => $del_watt, 'quantity' => $del_qty, 'mw' => $del_mw]; }
        }
        $project['delivered_modules'] = $total_delivered;
        $project['delivered_mw'] = $delivered_mw;
        $project['delivered_breakdown'] = $delivered_breakdown;
        $project['delivery_progress'] = $project['project_size'] > 0 ? min(100, ($delivered_mw / $project['project_size']) * 100) : 0;

        $stmt_storage = $conn->prepare("SELECT SUM(ip.quantity) AS total_in_storage FROM inventory_pallets ip WHERE ip.assigned_project_id = ? AND ip.status = 'In Warehouse'");
        $stmt_storage->bind_param("i", $project_id);
        $stmt_storage->execute();
        $stmt_storage->bind_result($total_in_storage);
        $stmt_storage->fetch();
        $stmt_storage->close();
        $total_in_storage = $total_in_storage ?: 0;
        $project['storage_modules'] = $total_in_storage;
        $project['storage_mw'] = ($total_in_storage * $avg_wattage) / 1000000;

        $stmt_pallets = $conn->prepare("SELECT COUNT(*) FROM inventory_pallets WHERE assigned_project_id = ?");
        $stmt_pallets->bind_param("i", $project_id);
        $stmt_pallets->execute();
        $stmt_pallets->bind_result($pallet_count);
        $stmt_pallets->fetch();
        $stmt_pallets->close();

        $stmt_transit = $conn->prepare("SELECT SUM(quantity) FROM deliveries WHERE project_id = ? AND status_of_delivery IN ('On Water', 'In Transit to Warehouse', 'In Transit to Project')");
        $stmt_transit->bind_param("i", $project_id);
        $stmt_transit->execute();
        $stmt_transit->bind_result($in_transit);
        $stmt_transit->fetch();
        $stmt_transit->close();
        $project['in_transit'] = $in_transit ?: 0;

        $timeline_step = 1;
        if ($total_order_quantity > 0) $timeline_step = 2;
        if ($pallet_count > 0) $timeline_step = 3;
        if ($total_delivered > 0 || ($in_transit ?: 0) > 0) $timeline_step = 4;
        if ($project['delivery_progress'] >= 100) $timeline_step = 5;
        $project['timeline_step'] = $timeline_step;
        $timeline_labels = [1 => 'Planning', 2 => 'Ordered', 3 => 'Palletized', 4 => 'Shipping', 5 => 'Completed'];
        $project['timeline_label'] = $timeline_labels[$timeline_step];

        $today = new DateTime();
        $health = 'on_track';
        $health_text = 'On Track';
        $health_reason = 'Project is progressing normally';

        if ($project['delivery_progress'] >= 100) {
            $health = 'completed';
            $health_text = 'Completed';
            $health_reason = 'All modules have been delivered to the project site';
        } elseif (!empty($project['estimated_completion_date'])) {
            $est_date = new DateTime($project['estimated_completion_date']);
            $diff = $today->diff($est_date);
            $days_remaining = $diff->days;
            $is_past = $est_date < $today;

            if ($is_past && $project['delivery_progress'] < 100) {
                $health = 'behind';
                $health_text = 'Behind';
                $health_reason = 'Past completion date (' . $est_date->format('M j, Y') . ') with ' . round($project['delivery_progress']) . '% delivered';
            } elseif (!$is_past && $days_remaining <= 30 && $project['delivery_progress'] < 80) {
                $health = 'at_risk';
                $health_text = 'At Risk';
                $health_reason = $days_remaining . ' days until deadline with only ' . round($project['delivery_progress']) . '% delivered';
            } else {
                $health_reason = $is_past ? 'Completed on schedule' : $days_remaining . ' days remaining with ' . round($project['delivery_progress']) . '% delivered';
            }
        }
        $project['health'] = $health;
        $project['health_text'] = $health_text;
        $project['health_reason'] = $health_reason;
        $dashboard_totals['health_counts'][$health]++;

        $dashboard_totals['total_modules'] += $total_order_quantity;
        $dashboard_totals['total_delivered'] += $total_delivered;
        $dashboard_totals['total_in_storage'] += $total_in_storage;
        $dashboard_totals['total_project_size_mw'] += $project['project_size'];
        $dashboard_totals['total_ordered_mw'] += $project['ordered_mw'];
        $dashboard_totals['delivered_mw'] += $delivered_mw;
        $dashboard_totals['storage_mw'] += $project['storage_mw'];

        $projects[] = $project;
    }
    $stmt->close();
}

$mw_gap = $dashboard_totals['total_project_size_mw'] - $dashboard_totals['total_ordered_mw'];

// Get open warranty claims count per project
$warranty_counts = [];
$total_open_claims = 0;
if (count($accountIds) > 0) {
    $project_ids = array_column($projects, 'id');
    if (!empty($project_ids)) {
        $placeholders_warranty = implode(',', array_fill(0, count($project_ids), '?'));
        $sqlWarranty = "SELECT ss.project_id, COUNT(*) as open_claims
                        FROM warranty_claims w
                        JOIN site_scheduling ss ON ss.id = w.scheduling_id
                        WHERE ss.project_id IN ($placeholders_warranty) AND w.status <> 'Closed'
                        GROUP BY ss.project_id";
        $stmtWarranty = $conn->prepare($sqlWarranty);
        $stmtWarranty->bind_param(str_repeat('i', count($project_ids)), ...$project_ids);
        $stmtWarranty->execute();
        $resultWarranty = $stmtWarranty->get_result();
        while ($row = $resultWarranty->fetch_assoc()) {
            $warranty_counts[(int)$row['project_id']] = (int)$row['open_claims'];
            $total_open_claims += (int)$row['open_claims'];
        }
        $stmtWarranty->close();
    }
}

// Add warranty counts to projects array
foreach ($projects as &$project) {
    $project['open_claims'] = $warranty_counts[$project['id']] ?? 0;
}
unset($project);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-header {
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .dashboard-header h1 {
            margin: 0;
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .dashboard-header p { margin: 8px 0 0; font-size: 1.1em; color: #6c757d; }
        .unit-toggle { display: inline-flex; background: #f1f3f4; border-radius: 8px; padding: 3px; gap: 3px; }
        .unit-toggle button { padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 0.85em; border: none; cursor: pointer; color: #6c757d; background: transparent; transition: all 0.2s; }
        .unit-toggle button:hover { color: #293E4C; background: rgba(255,255,255,0.5); }
        .unit-toggle button.active { background: #fff; color: #293E4C; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }

        .stats-charts-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; align-items: stretch; }
        .stats-section { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .stat-card { background: #fff; padding: 12px; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; text-align: center; transition: transform 0.2s, box-shadow 0.2s; text-decoration: none; color: inherit; display: block; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); text-decoration: none; color: inherit; }
        .stat-card.clickable { cursor: pointer; }
        .stat-icon { font-size: 1.5em; margin-bottom: 4px; opacity: 0.8; }
        .stat-number { font-size: 1.5em; font-weight: 700; color: #488C9A; margin: 0; }
        .stat-label { font-size: 0.8em; color: #6c757d; margin: 2px 0 0; font-weight: 500; }

        .charts-section { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .chart-card { background: #fff; padding: 16px; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; }
        .chart-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
        .chart-card h3 { margin: 0 0 12px; color: #293E4C; font-size: 1em; font-weight: 600; }
        .chart-content { display: flex; align-items: center; gap: 16px; flex: 1; }
        .chart-container { position: relative; width: 110px; height: 110px; flex-shrink: 0; }
        .chart-legend { flex: 1; font-size: 0.9em; }
        .legend-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f1f3f4; }
        .legend-item:last-child { border-bottom: none; }
        .legend-item.clickable-filter:hover { background: #f0f8fa !important; }
        .legend-label { display: flex; align-items: center; gap: 8px; color: #495057; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; }
        .legend-value { font-weight: 600; color: #293E4C; }
        .coverage-summary { padding: 10px; background: #f8f9fa; border-radius: 6px; font-size: 0.9em; }
        .coverage-row { display: flex; justify-content: space-between; padding: 5px 0; }
        .coverage-row span:first-child { color: #6c757d; }
        .coverage-row span:last-child { font-weight: 600; color: #293E4C; }
        .coverage-row.highlight span:last-child { color: #28a745; }
        .coverage-row.warning span:last-child { color: #dc3545; }

        .section-header-row { display: flex; justify-content: space-between; align-items: center; margin: 20px 0 15px; flex-wrap: wrap; gap: 12px; padding: 20px 24px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 16px; border: 1px solid #e9ecef; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .section-header { font-size: 1.4em; font-weight: 700; background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0; letter-spacing: -0.3px; }
        .section-controls { display: flex; align-items: center; gap: 12px; }
        .section-title-row { display: flex; align-items: center; gap: 12px; }
        .view-archived-link { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 20px; color: #6c757d; font-size: 0.8em; font-weight: 500; text-decoration: none; transition: all 0.2s; }
        .view-archived-link:hover { background: #e8f4f7; border-color: #488C9A; color: #488C9A; }
        .view-archived-link .archived-count { background: #488C9A; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 0.9em; font-weight: 600; }
        .active-filter-banner { display: none; align-items: center; gap: 12px; padding: 12px 16px; background: linear-gradient(135deg, #fff3cd 0%, #fff9e6 100%); border: 1px solid #ffc107; border-radius: 10px; margin-bottom: 16px; }
        .active-filter-banner.visible { display: flex; }
        .active-filter-banner .filter-text { flex: 1; font-size: 0.9em; color: #856404; font-weight: 500; }
        .active-filter-banner .filter-status { font-weight: 700; }
        .active-filter-banner .clear-filter-btn { padding: 6px 12px; background: #fff; border: 1px solid #ffc107; border-radius: 6px; color: #856404; font-size: 0.8em; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .active-filter-banner .clear-filter-btn:hover { background: #ffc107; color: #fff; }
        .view-toggle { display: inline-flex; background: #f1f3f4; border-radius: 8px; padding: 3px; gap: 3px; }
        .view-toggle button { padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 1em; border: none; cursor: pointer; color: #6c757d; background: transparent; transition: all 0.2s; }
        .view-toggle button:hover { color: #293E4C; background: rgba(255,255,255,0.5); }
        .view-toggle button.active { background: #fff; color: #293E4C; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }

        .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 28px; justify-content: start; margin-top: 20px; }
        @media (min-width: 1400px) { .projects-grid { grid-template-columns: repeat(5, 1fr); } }
        .project-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; overflow: hidden; transition: all 0.2s; cursor: pointer; }
        .project-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,0.12); }
        .project-card-image { width: 100%; height: 160px; background: #f0f2f4; position: relative; overflow: hidden; }
        .project-card-image img { width: 100%; height: 100%; object-fit: cover; }
        .project-card-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(45deg, rgba(72,140,154,0.9), rgba(58,110,127,0.9)); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
        .project-card:hover .project-card-overlay { opacity: 1; }
        .project-card-overlay span { color: #fff; font-size: 1em; font-weight: 600; }
        .project-card-content { padding: 14px; }
        .project-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; gap: 8px; }
        .project-card-title { margin: 0; font-size: 1.05em; color: #293E4C; font-weight: 600; flex: 1; line-height: 1.3; }
        .health-badge { padding: 3px 8px; border-radius: 10px; font-size: 0.65em; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; cursor: help; position: relative; }
        .health-badge:hover .health-tooltip { opacity: 1; visibility: visible; }
        .health-tooltip { position: absolute; bottom: calc(100% + 6px); right: 0; background: #293E4C; color: #fff; padding: 8px 10px; border-radius: 6px; font-size: 10px; font-weight: 400; text-transform: none; letter-spacing: 0; white-space: normal; width: 180px; text-align: left; opacity: 0; visibility: hidden; transition: all 0.2s; z-index: 100; line-height: 1.4; }
        .health-tooltip::after { content: ''; position: absolute; top: 100%; right: 10px; border: 5px solid transparent; border-top-color: #293E4C; }
        .health-on_track { background: #d4edda; color: #155724; }
        .health-at_risk { background: #fff3cd; color: #856404; }
        .health-behind { background: #f8d7da; color: #721c24; }
        .health-completed { background: #cce7ff; color: #004085; }
        .project-card-location { color: #6c757d; font-size: 0.75em; margin-bottom: 12px; }

        .progress-rings-row { display: flex; justify-content: center; gap: 24px; margin-bottom: 12px; }
        .progress-ring-item { text-align: center; cursor: pointer; padding: 6px; border-radius: 8px; transition: background 0.2s; }
        .progress-ring-item:hover { background: #f8f9fa; }
        .progress-ring { width: 64px; height: 64px; position: relative; margin: 0 auto 4px; }
        .progress-ring svg { transform: rotate(-90deg); }
        .progress-ring-bg { fill: none; stroke: #e9ecef; stroke-width: 5; }
        .progress-ring-fill { fill: none; stroke-width: 5; stroke-linecap: round; transition: stroke-dashoffset 0.8s; }
        .progress-ring-fill.order { stroke: #488C9A; }
        .progress-ring-fill.delivery { stroke: #28a745; }
        .progress-ring-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
        .progress-ring-value { font-size: 0.95em; font-weight: 700; color: #293E4C; line-height: 1; }
        .progress-ring-label { font-size: 0.55em; color: #6c757d; }
        .ring-title { font-size: 0.7em; color: #495057; font-weight: 500; }
        .ring-subtitle { font-size: 0.65em; color: #999; }

        .project-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 10px; }
        .info-item { padding: 6px 8px; background: #f8f9fa; border-radius: 6px; }
        .info-label { font-size: 0.65em; color: #6c757d; }
        .info-value { font-size: 0.85em; font-weight: 600; color: #293E4C; }

        .storage-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #fff3cd; border-radius: 6px; font-size: 0.7em; color: #856404; margin-top: 8px; }
        .storage-badge strong { color: #664d03; }
        .warranty-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #f8d7da; border-radius: 6px; font-size: 0.7em; color: #721c24; margin-top: 8px; margin-left: 6px; }
        .warranty-badge strong { color: #491217; }
        .project-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 0; }

        .add-project-card { display: flex; align-items: center; justify-content: center; flex-direction: column; border: 2px dashed #d0d0d0; background: #f9f9f9; color: #6c757d; min-height: 360px; border-radius: 12px; cursor: pointer; transition: all 0.2s; }
        .add-project-card:hover { border-color: #488C9A; background: #f0f8fa; color: #488C9A; transform: translateY(-4px); }
        .add-project-icon { font-size: 3em; margin-bottom: 10px; }
        .add-project-text { font-size: 1.1em; font-weight: 600; }

        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; visibility: hidden; transition: all 0.3s; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-content { background: #fff; border-radius: 16px; padding: 24px; max-width: 420px; width: 90%; max-height: 80vh; overflow-y: auto; transform: scale(0.9); transition: transform 0.3s; }
        .modal-overlay.active .modal-content { transform: scale(1); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-header h3 { margin: 0; color: #293E4C; font-size: 1.1em; }
        .modal-close { background: none; border: none; font-size: 1.5em; cursor: pointer; color: #6c757d; padding: 0; line-height: 1; }
        .modal-close:hover { color: #293E4C; }
        .modal-summary { display: flex; align-items: center; gap: 14px; padding: 14px; background: #f8f9fa; border-radius: 10px; margin-bottom: 16px; }
        .modal-ring { width: 70px; height: 70px; position: relative; flex-shrink: 0; }
        .modal-ring svg { transform: rotate(-90deg); }
        .modal-ring-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
        .modal-ring-value { font-size: 1.2em; font-weight: 700; color: #293E4C; }
        .modal-ring-label { font-size: 0.65em; color: #6c757d; }
        .modal-summary-info { flex: 1; }
        .modal-summary-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 0.85em; }
        .modal-summary-row span:first-child { color: #6c757d; }
        .modal-summary-row span:last-child { font-weight: 600; color: #293E4C; }
        .modal-breakdown h4 { margin: 0 0 10px; font-size: 0.9em; color: #293E4C; }
        .breakdown-table { width: 100%; border-collapse: collapse; }
        .breakdown-table th { text-align: left; padding: 8px 10px; background: #f8f9fa; font-size: 0.75em; font-weight: 600; color: #6c757d; border-bottom: 2px solid #e9ecef; }
        .breakdown-table td { padding: 8px 10px; border-bottom: 1px solid #f1f3f4; font-size: 0.85em; }
        .breakdown-table tr:last-child td { border-bottom: none; }

        .projects-table-container { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; overflow: hidden; display: none; }
        .projects-table-container.active { display: block; }
        .projects-grid.active { display: grid; }
        .projects-grid:not(.active) { display: none; }
        .projects-table { width: 100%; border-collapse: collapse; }
        .projects-table th { background: #488C9A; padding: 10px 12px; text-align: left; font-weight: 600; color: #fff; font-size: 0.75em; border-bottom: none; cursor: pointer; white-space: nowrap; }
        .projects-table th:hover { background: #3A6E7F; }
        .projects-table th .sort-icon { margin-left: 4px; opacity: 0.5; }
        .projects-table td { padding: 10px 12px; border-bottom: 1px solid #f1f3f4; color: #495057; font-size: 0.8em; vertical-align: middle; }
        .projects-table tr { cursor: pointer; transition: background 0.2s; }
        .projects-table tbody tr:hover { background: #f8f9fa; }
        .table-project-name { font-weight: 600; color: #293E4C; }
        .table-progress { display: flex; align-items: center; gap: 6px; }
        .table-progress-bar { flex: 1; height: 5px; background: #e9ecef; border-radius: 3px; overflow: hidden; min-width: 50px; }
        .table-progress-fill { height: 100%; border-radius: 3px; }
        .table-progress-fill.order { background: linear-gradient(90deg, #488C9A, #5AA8B7); }
        .table-progress-fill.delivery { background: linear-gradient(90deg, #28a745, #34ce57); }
        .table-progress-text { font-weight: 600; min-width: 35px; font-size: 0.9em; }
        .table-progress-text.order { color: #488C9A; }
        .table-progress-text.delivery { color: #28a745; }
        .table-wattages { display: flex; flex-direction: column; gap: 1px; }
        .table-wattage-row { font-size: 0.9em; color: #495057; }
        .table-wattage-row strong { color: #293E4C; }

        .no-projects { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; text-align: center; }
        .no-projects h2 { color: #293E4C; margin-bottom: 8px; }
        .no-projects p { color: #6c757d; }

        @media (max-width: 992px) { .stats-charts-row { grid-template-columns: 1fr; } .charts-section { grid-template-columns: 1fr 1fr; } .chart-content { flex-direction: row; } .chart-container { width: 100px; height: 100px; } }
        @media (max-width: 768px) { .dashboard-header { flex-direction: column; align-items: flex-start; } .dashboard-header h1 { font-size: 1.5em; } .stats-section { grid-template-columns: repeat(2, 1fr); } .stat-number { font-size: 1.3em; } .charts-section { grid-template-columns: 1fr; } .chart-container { width: 110px; height: 110px; } .projects-grid { grid-template-columns: 1fr; } .projects-table-container { overflow-x: auto; } .projects-table { min-width: 980px; } }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="dashboard-header">
        <div>
            <h1>Welcome back, <?php echo htmlspecialchars($displayName); ?>!</h1>
            <p>Here's an overview of your projects and modules</p>
        </div>
        <div class="unit-toggle">
            <button class="active" onclick="setUnit('modules')" id="btn-modules">Modules</button>
            <button onclick="setUnit('mw')" id="btn-mw">MW</button>
        </div>
    </div>

    <div class="stats-charts-row">
        <div class="stats-section">
            <div class="stat-card clickable" onclick="document.getElementById('projects-section').scrollIntoView({behavior:'smooth'})">
                <div class="stat-icon">📊</div>
                <h3 class="stat-number"><?php echo count($projects); ?></h3>
                <p class="stat-label">Active Projects</p>
            </div>
            <a href="warehousing_overview.php" class="stat-card clickable">
                <div class="stat-icon">🏭</div>
                <h3 class="stat-number">
                    <span class="unit-modules"><?php echo number_format($dashboard_totals['total_in_storage']); ?></span>
                    <span class="unit-mw" style="display:none"><?php echo number_format($dashboard_totals['storage_mw'], 2); ?></span>
                </h3>
                <p class="stat-label">In Storage</p>
            </a>
            <a href="modules.php" class="stat-card clickable">
                <div class="stat-icon">📦</div>
                <h3 class="stat-number">
                    <span class="unit-modules"><?php echo number_format($dashboard_totals['total_modules']); ?></span>
                    <span class="unit-mw" style="display:none"><?php echo number_format($dashboard_totals['total_ordered_mw'], 2); ?></span>
                </h3>
                <p class="stat-label">Total <span class="unit-label-modules">Modules</span><span class="unit-label-mw" style="display:none">MW</span></p>
            </a>
            <div class="stat-card">
                <div class="stat-icon">🚚</div>
                <h3 class="stat-number">
                    <span class="unit-modules"><?php echo number_format($dashboard_totals['total_delivered']); ?></span>
                    <span class="unit-mw" style="display:none"><?php echo number_format($dashboard_totals['delivered_mw'], 2); ?></span>
                </h3>
                <p class="stat-label">Delivered</p>
            </div>
        </div>
        <div class="charts-section">
            <div class="chart-card" style="cursor:default">
                <h3>Project Pipeline <span style="font-size:0.7em;font-weight:400;color:#6c757d">(click to filter)</span> <span onclick="event.stopPropagation();openChartModal('pipeline')" style="cursor:pointer;font-size:0.8em;color:#6c757d;margin-left:4px" title="What do these statuses mean?">ⓘ</span></h3>
                <div class="chart-content">
                    <div class="chart-container"><canvas id="pipelineChart"></canvas></div>
                    <div class="chart-legend">
                        <div class="legend-item clickable-filter" data-health="on_track" onclick="filterByHealth('on_track')" style="cursor:pointer;border-radius:6px;padding:6px 8px;margin:-2px -4px;transition:background 0.2s"><span class="legend-label"><span class="legend-dot" style="background:#28a745"></span>On Track</span><span class="legend-value"><?php echo $dashboard_totals['health_counts']['on_track']; ?></span></div>
                        <div class="legend-item clickable-filter" data-health="at_risk" onclick="filterByHealth('at_risk')" style="cursor:pointer;border-radius:6px;padding:6px 8px;margin:-2px -4px;transition:background 0.2s"><span class="legend-label"><span class="legend-dot" style="background:#ffc107"></span>At Risk</span><span class="legend-value"><?php echo $dashboard_totals['health_counts']['at_risk']; ?></span></div>
                        <div class="legend-item clickable-filter" data-health="behind" onclick="filterByHealth('behind')" style="cursor:pointer;border-radius:6px;padding:6px 8px;margin:-2px -4px;transition:background 0.2s"><span class="legend-label"><span class="legend-dot" style="background:#dc3545"></span>Behind</span><span class="legend-value"><?php echo $dashboard_totals['health_counts']['behind']; ?></span></div>
                        <div class="legend-item clickable-filter" data-health="completed" onclick="filterByHealth('completed')" style="cursor:pointer;border-radius:6px;padding:6px 8px;margin:-2px -4px;transition:background 0.2s"><span class="legend-label"><span class="legend-dot" style="background:#488C9A"></span>Done</span><span class="legend-value"><?php echo $dashboard_totals['health_counts']['completed']; ?></span></div>
                    </div>
                </div>
            </div>
            <div class="chart-card" onclick="openChartModal('distribution')">
                <h3>Module Distribution</h3>
                <div class="chart-content">
                    <div class="chart-container"><canvas id="coverageChart"></canvas></div>
                    <div class="chart-legend">
                        <div class="coverage-summary">
                            <div class="coverage-row"><span>Project Needs</span><span><?php echo number_format($dashboard_totals['total_project_size_mw'], 1); ?> MW</span></div>
                            <div class="coverage-row"><span>MW Ordered</span><span><?php echo number_format($dashboard_totals['total_ordered_mw'], 1); ?> MW</span></div>
                            <div class="coverage-row <?php echo $mw_gap <= 0 ? 'highlight' : 'warning'; ?>"><span><?php echo $mw_gap <= 0 ? 'Surplus' : 'Gap'; ?></span><span><?php echo number_format(abs($mw_gap), 1); ?> MW</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($projects) || in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
    <div class="section-header-row" id="projects-section">
        <div class="section-title-row">
            <h2 class="section-header">Your Active Projects</h2>
            <a href="archived_projects.php" class="view-archived-link">
                Archived
                <span class="archived-count"><?php echo $archived_count; ?></span>
            </a>
        </div>
        <div class="section-controls">
            <div class="view-toggle">
                <button class="active" onclick="setView('grid')" id="btn-grid" title="Grid View">▦</button>
                <button onclick="setView('table')" id="btn-table" title="Table View">☰</button>
            </div>
        </div>
    </div>

    <div class="active-filter-banner" id="filter-banner">
        <span class="filter-text">Showing <span class="filter-status" id="filter-status-text"></span> projects</span>
        <button class="clear-filter-btn" onclick="clearHealthFilter()">Show All Projects</button>
    </div>

    <div class="projects-grid active" id="projects-grid">
        <?php
        $target_page = ($role === 'DDPm') ? 'DDPm_overview' : 'project_overview';
        foreach ($projects as $idx => $project):
            $est_date_display = !empty($project['estimated_completion_date']) ? (new DateTime($project['estimated_completion_date']))->format('M j, Y') : 'N/A';
            $circ = 2 * 3.14159 * 26;
            $order_offset = $circ - ($project['order_progress'] / 100) * $circ;
            $delivery_offset = $circ - ($project['delivery_progress'] / 100) * $circ;
        ?>
        <div class="project-card" data-health="<?php echo $project['health']; ?>" onclick="window.location.href='<?php echo $target_page; ?>.php?project_id=<?php echo $project['id']; ?>'">
            <div class="project-card-image">
                <img src="<?php echo htmlspecialchars(!empty($project['image_url']) ? $project['image_url'] : 'pictures/project_default.png'); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>" onerror="this.src='pictures/project_default.png'">
                <div class="project-card-overlay"><span>View Details</span></div>
            </div>
            <div class="project-card-content">
                <div class="project-card-header">
                    <h3 class="project-card-title"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                    <?php
                    $health_definitions = [
                        'on_track' => 'On schedule relative to completion date.',
                        'at_risk' => 'Less than 30 days to deadline with <80% delivered.',
                        'behind' => 'Past completion date, not fully delivered.',
                        'completed' => 'All modules delivered to site.'
                    ];
                    ?>
                    <span class="health-badge health-<?php echo $project['health']; ?>" onclick="event.stopPropagation()">
                        <?php echo $project['health_text']; ?>
                        <span class="health-tooltip"><strong><?php echo $health_definitions[$project['health']]; ?></strong><br><br><?php echo htmlspecialchars($project['health_reason']); ?></span>
                    </span>
                </div>
                <div class="project-card-location">📍 <?php echo htmlspecialchars($project['project_address']); ?></div>

                <div class="progress-rings-row">
                    <div class="progress-ring-item" onclick="event.stopPropagation();openModal('order',<?php echo $idx; ?>)">
                        <div class="progress-ring">
                            <svg width="64" height="64"><circle class="progress-ring-bg" cx="32" cy="32" r="26"></circle><circle class="progress-ring-fill order" cx="32" cy="32" r="26" stroke-dasharray="<?php echo $circ; ?>" stroke-dashoffset="<?php echo $order_offset; ?>"></circle></svg>
                            <div class="progress-ring-center"><div class="progress-ring-value"><?php echo round($project['order_progress']); ?>%</div><div class="progress-ring-label">ordered</div></div>
                        </div>
                        <div class="ring-title">Order Progress</div>
                        <div class="ring-subtitle"><?php echo number_format($project['ordered_mw'], 2); ?>/<?php echo number_format($project['project_size'], 2); ?> MW</div>
                    </div>
                    <div class="progress-ring-item" onclick="event.stopPropagation();openModal('delivery',<?php echo $idx; ?>)">
                        <div class="progress-ring">
                            <svg width="64" height="64"><circle class="progress-ring-bg" cx="32" cy="32" r="26"></circle><circle class="progress-ring-fill delivery" cx="32" cy="32" r="26" stroke-dasharray="<?php echo $circ; ?>" stroke-dashoffset="<?php echo $delivery_offset; ?>"></circle></svg>
                            <div class="progress-ring-center"><div class="progress-ring-value"><?php echo round($project['delivery_progress']); ?>%</div><div class="progress-ring-label">delivered</div></div>
                        </div>
                        <div class="ring-title">Delivery Progress</div>
                        <div class="ring-subtitle"><?php echo number_format($project['delivered_mw'], 2); ?>/<?php echo number_format($project['project_size'], 2); ?> MW</div>
                    </div>
                </div>

                <div class="project-info-grid">
                    <div class="info-item"><div class="info-label">Project Size</div><div class="info-value"><?php echo number_format($project['project_size'], 2); ?> MW</div></div>
                    <div class="info-item"><div class="info-label">Est. Completion</div><div class="info-value"><?php echo $est_date_display; ?></div></div>
                </div>

                <?php if ($project['storage_modules'] > 0 || $project['open_claims'] > 0): ?>
                <div class="project-badges">
                    <?php if ($project['storage_modules'] > 0): ?>
                    <div class="storage-badge">🏭 <strong><?php echo number_format($project['storage_modules']); ?></strong> in storage</div>
                    <?php endif; ?>
                    <?php if ($project['open_claims'] > 0): ?>
                    <div class="warranty-badge">⚠️ <strong><?php echo $project['open_claims']; ?></strong> open claim<?php echo $project['open_claims'] > 1 ? 's' : ''; ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
        <div class="add-project-card" onclick="window.location.href='add_project.php'"><div class="add-project-icon">+</div><div class="add-project-text">Add New Project</div></div>
        <?php endif; ?>
    </div>

    <div class="projects-table-container" id="projects-table">
        <table class="projects-table">
            <thead><tr>
                <th onclick="sortTable(0)">Project <span class="sort-icon">↕</span></th>
                <th onclick="sortTable(1)">Size <span class="sort-icon">↕</span></th>
                <th>Wattages</th>
                <th onclick="sortTable(3)">Ordered <span class="sort-icon">↕</span></th>
                <th onclick="sortTable(4)">Delivered <span class="sort-icon">↕</span></th>
                <th>Stage</th>
                <th>Health</th>
                <th onclick="sortTable(7)">Claims <span class="sort-icon">↕</span></th>
                <th onclick="sortTable(8)">Est. Complete <span class="sort-icon">↕</span></th>
            </tr></thead>
            <tbody>
            <?php
            $health_definitions = [
                'on_track' => 'On schedule relative to completion date.',
                'at_risk' => 'Less than 30 days to deadline with <80% delivered.',
                'behind' => 'Past completion date, not fully delivered.',
                'completed' => 'All modules delivered to site.'
            ];
            foreach ($projects as $project):
                $est_date_display = !empty($project['estimated_completion_date']) ? (new DateTime($project['estimated_completion_date']))->format('M j, Y') : 'N/A';
            ?>
            <tr data-health="<?php echo $project['health']; ?>" onclick="window.location.href='<?php echo $target_page; ?>.php?project_id=<?php echo $project['id']; ?>'">
                <td class="table-project-name"><?php echo htmlspecialchars($project['project_name']); ?></td>
                <td><?php echo number_format($project['project_size'], 2); ?> MW</td>
                <td><div class="table-wattages"><?php if (!empty($project['wattage_breakdown'])): foreach ($project['wattage_breakdown'] as $wb): ?><div class="table-wattage-row"><strong><?php echo number_format($wb['quantity']); ?></strong> × <?php echo (int)$wb['wattage']; ?>W</div><?php endforeach; else: ?><span style="color:#999">—</span><?php endif; ?></div></td>
                <td><div class="table-progress"><div class="table-progress-bar"><div class="table-progress-fill order" style="width:<?php echo min($project['order_progress'], 100); ?>%"></div></div><span class="table-progress-text order"><?php echo round($project['order_progress']); ?>%</span></div></td>
                <td><div class="table-progress"><div class="table-progress-bar"><div class="table-progress-fill delivery" style="width:<?php echo min($project['delivery_progress'], 100); ?>%"></div></div><span class="table-progress-text delivery"><?php echo round($project['delivery_progress']); ?>%</span></div></td>
                <td><?php echo $project['timeline_label']; ?></td>
                <td><span class="health-badge health-<?php echo $project['health']; ?>" title="<?php echo $health_definitions[$project['health']] . ' — ' . htmlspecialchars($project['health_reason']); ?>"><?php echo $project['health_text']; ?></span></td>
                <td><?php if ($project['open_claims'] > 0): ?><span style="color:#dc3545;font-weight:600"><?php echo $project['open_claims']; ?></span><?php else: ?><span style="color:#999">—</span><?php endif; ?></td>
                <td><?php echo $est_date_display; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <div class="no-projects"><h2>No Active Projects</h2><p>Contact your administrator to get started.</p></div>
    <?php endif; ?>
</main>

<div class="modal-overlay" id="modal-overlay" onclick="closeModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
        <div class="modal-header"><h3 id="modal-title">Breakdown</h3><button class="modal-close" onclick="closeModal()">&times;</button></div>
        <div class="modal-summary" id="modal-summary"></div>
        <div class="modal-breakdown"><h4 id="breakdown-title">Wattage Breakdown</h4><table class="breakdown-table"><thead><tr><th>Wattage</th><th>Quantity</th><th>MW</th></tr></thead><tbody id="breakdown-body"></tbody></table></div>
    </div>
</div>

<div class="modal-overlay" id="chart-modal-overlay" onclick="closeChartModal()">
    <div class="modal-content" onclick="event.stopPropagation()" style="max-width:500px">
        <div class="modal-header"><h3 id="chart-modal-title">Chart Details</h3><button class="modal-close" onclick="closeChartModal()">&times;</button></div>
        <div id="chart-modal-body"></div>
    </div>
</div>

<script>
const projectsData = <?php echo json_encode(array_map(function($p) {
    return ['name'=>$p['project_name'],'project_size'=>$p['project_size'],'ordered_mw'=>$p['ordered_mw'],'order_progress'=>$p['order_progress'],'delivered_mw'=>$p['delivered_mw'],'delivery_progress'=>$p['delivery_progress'],'total_modules'=>$p['total_modules'],'delivered_modules'=>$p['delivered_modules'],'wattage_breakdown'=>$p['wattage_breakdown'],'delivered_breakdown'=>$p['delivered_breakdown']];
}, $projects)); ?>;

function openModal(type, idx) {
    const p = projectsData[idx], modal = document.getElementById('modal-overlay'), circ = 2 * 3.14159 * 28;
    document.getElementById('modal-title').textContent = (type === 'order' ? 'Order Progress - ' : 'Delivery Progress - ') + p.name;
    document.getElementById('breakdown-title').textContent = type === 'order' ? 'Ordered by Wattage' : 'Delivered by Wattage';
    const progress = type === 'order' ? p.order_progress : p.delivery_progress;
    const mw = type === 'order' ? p.ordered_mw : p.delivered_mw;
    const modules = type === 'order' ? p.total_modules : p.delivered_modules;
    const breakdown = type === 'order' ? p.wattage_breakdown : p.delivered_breakdown;
    const colorClass = type === 'order' ? 'order' : 'delivery';
    const dashoffset = circ - (progress / 100) * circ;
    document.getElementById('modal-summary').innerHTML = `<div class="modal-ring"><svg width="70" height="70"><circle class="progress-ring-bg" cx="35" cy="35" r="28" style="stroke-width:6"></circle><circle class="progress-ring-fill ${colorClass}" cx="35" cy="35" r="28" style="stroke-width:6;stroke-dasharray:${circ};stroke-dashoffset:${dashoffset}"></circle></svg><div class="modal-ring-center"><div class="modal-ring-value">${Math.round(progress)}%</div><div class="modal-ring-label">${type === 'order' ? 'Ordered' : 'Delivered'}</div></div></div><div class="modal-summary-info"><div class="modal-summary-row"><span>Project Size</span><span>${p.project_size.toFixed(2)} MW</span></div><div class="modal-summary-row"><span>MW ${type === 'order' ? 'Ordered' : 'Delivered'}</span><span>${mw.toFixed(2)} MW</span></div><div class="modal-summary-row"><span>Modules</span><span>${modules.toLocaleString()}</span></div></div>`;
    document.getElementById('breakdown-body').innerHTML = breakdown.length ? breakdown.map(w => `<tr><td><strong>${Math.round(w.wattage)}W</strong></td><td>${w.quantity.toLocaleString()}</td><td>${w.mw.toFixed(3)} MW</td></tr>`).join('') : '<tr><td colspan="3" style="text-align:center;color:#999">No data</td></tr>';
    modal.classList.add('active');
}
function closeModal() { document.getElementById('modal-overlay').classList.remove('active'); }

function openChartModal(type) {
    const modal = document.getElementById('chart-modal-overlay');
    const title = document.getElementById('chart-modal-title');
    const body = document.getElementById('chart-modal-body');

    if (type === 'pipeline') {
        title.textContent = 'Project Pipeline - Health Status Guide';
        body.innerHTML = `
            <div style="margin-bottom:16px;padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #28a745">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#28a745"></span>
                    <strong style="color:#155724">On Track</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057">Project is progressing normally. Delivery progress is on schedule relative to the estimated completion date.</p>
            </div>
            <div style="margin-bottom:16px;padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #ffc107">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#ffc107"></span>
                    <strong style="color:#856404">At Risk</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057">Project has less than 30 days until the deadline AND delivery progress is below 80%. Immediate attention recommended.</p>
            </div>
            <div style="margin-bottom:16px;padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #dc3545">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#dc3545"></span>
                    <strong style="color:#721c24">Behind</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057">Project has passed its estimated completion date and delivery is not yet 100% complete. Requires urgent action.</p>
            </div>
            <div style="padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #488C9A">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#488C9A"></span>
                    <strong style="color:#004085">Completed</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057">All modules have been delivered to the project site (100% delivery progress).</p>
            </div>
        `;
    } else if (type === 'distribution') {
        title.textContent = 'Module Distribution - Coverage Guide';
        body.innerHTML = `
            <div style="margin-bottom:16px;padding:14px;background:#f8f9fa;border-radius:10px">
                <h4 style="margin:0 0 8px;color:#293E4C;font-size:0.95em">What This Chart Shows</h4>
                <p style="margin:0;font-size:0.85em;color:#495057">This chart compares your total ordered MW against your total project needs (combined project sizes) across all active projects.</p>
            </div>
            <div style="margin-bottom:16px;padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #488C9A">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#488C9A"></span>
                    <strong style="color:#293E4C">Ordered (MW)</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057">Total megawatts of modules that have been ordered across all projects, calculated from module quantities and their wattages.</p>
            </div>
            <div style="padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #e9ecef">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#e9ecef;border:1px solid #dee2e6"></span>
                    <strong style="color:#293E4C">Gap / Surplus</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057"><strong style="color:#dc3545">Gap:</strong> You need to order more modules to meet project requirements.<br><strong style="color:#28a745">Surplus:</strong> You have ordered more than your current project needs.</p>
            </div>
        `;
    }

    modal.classList.add('active');
}

function closeChartModal() { document.getElementById('chart-modal-overlay').classList.remove('active'); }

document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); closeChartModal(); } });

function setUnit(unit) {
    document.querySelectorAll('.unit-modules,.unit-label-modules').forEach(el => el.style.display = unit === 'modules' ? '' : 'none');
    document.querySelectorAll('.unit-mw,.unit-label-mw').forEach(el => el.style.display = unit === 'mw' ? '' : 'none');
    document.getElementById('btn-modules').classList.toggle('active', unit === 'modules');
    document.getElementById('btn-mw').classList.toggle('active', unit === 'mw');
    localStorage.setItem('dashboardUnit', unit);
}
function setView(view) {
    document.getElementById('projects-grid').classList.toggle('active', view === 'grid');
    document.getElementById('projects-table').classList.toggle('active', view === 'table');
    document.getElementById('btn-grid').classList.toggle('active', view === 'grid');
    document.getElementById('btn-table').classList.toggle('active', view === 'table');
    localStorage.setItem('dashboardView', view);
}

let currentHealthFilter = null;
const healthLabels = { on_track: 'On Track', at_risk: 'At Risk', behind: 'Behind', completed: 'Completed' };

function filterByHealth(health) {
    currentHealthFilter = health;

    // Update filter banner
    document.getElementById('filter-banner').classList.add('visible');
    document.getElementById('filter-status-text').textContent = healthLabels[health];

    // Filter project cards
    document.querySelectorAll('.project-card[data-health]').forEach(card => {
        card.style.display = card.dataset.health === health ? '' : 'none';
    });

    // Filter table rows
    document.querySelectorAll('.projects-table tbody tr[data-health]').forEach(row => {
        row.style.display = row.dataset.health === health ? '' : 'none';
    });

    // Highlight active filter in legend
    document.querySelectorAll('.clickable-filter').forEach(item => {
        item.style.background = item.dataset.health === health ? '#e8f4f7' : '';
    });

    // Scroll to projects section
    document.getElementById('projects-section').scrollIntoView({ behavior: 'smooth' });
}

function clearHealthFilter() {
    currentHealthFilter = null;

    // Hide filter banner
    document.getElementById('filter-banner').classList.remove('visible');

    // Show all project cards
    document.querySelectorAll('.project-card[data-health]').forEach(card => {
        card.style.display = '';
    });

    // Show all table rows
    document.querySelectorAll('.projects-table tbody tr[data-health]').forEach(row => {
        row.style.display = '';
    });

    // Remove highlight from legend
    document.querySelectorAll('.clickable-filter').forEach(item => {
        item.style.background = '';
    });
}

let sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('.projects-table tbody'), rows = Array.from(tbody.querySelectorAll('tr'));
    sortDir[col] = !sortDir[col];
    const dir = sortDir[col] ? 1 : -1;
    rows.sort((a, b) => {
        let av = a.cells[col].textContent.trim(), bv = b.cells[col].textContent.trim();
        if ([1,3,4].includes(col)) { av = parseFloat(av.replace(/[^0-9.-]/g,''))||0; bv = parseFloat(bv.replace(/[^0-9.-]/g,''))||0; return (av-bv)*dir; }
        return av.localeCompare(bv)*dir;
    });
    rows.forEach(r => tbody.appendChild(r));
}

const pipelineChart = new Chart(document.getElementById('pipelineChart'), {
    type: 'doughnut',
    data: { labels: ['On Track','At Risk','Behind','Completed'], datasets: [{ data: [<?php echo $dashboard_totals['health_counts']['on_track'].','.$dashboard_totals['health_counts']['at_risk'].','.$dashboard_totals['health_counts']['behind'].','.$dashboard_totals['health_counts']['completed']; ?>], backgroundColor: ['#28a745','#ffc107','#dc3545','#488C9A'], borderWidth: 0 }] },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        cutout: '60%',
        onClick: (evt, elements) => {
            if (elements.length > 0) {
                const healthMap = ['on_track', 'at_risk', 'behind', 'completed'];
                filterByHealth(healthMap[elements[0].index]);
            }
        }
    }
});
document.getElementById('pipelineChart').style.cursor = 'pointer';
new Chart(document.getElementById('coverageChart'), {
    type: 'doughnut',
    data: { labels: ['Ordered','Gap'], datasets: [{ data: [<?php echo min($dashboard_totals['total_ordered_mw'], $dashboard_totals['total_project_size_mw']).','.max(0, $dashboard_totals['total_project_size_mw'] - $dashboard_totals['total_ordered_mw']); ?>], backgroundColor: ['#488C9A','#e9ecef'], borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '60%' }
});

document.addEventListener('DOMContentLoaded', () => {
    setUnit(localStorage.getItem('dashboardUnit')||'modules');
    setView(localStorage.getItem('dashboardView')||'grid');
});
</script>
</body>
</html>
