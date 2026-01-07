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

/**
 * Calculate warehousing cost for a project (TOTAL).
 */
function calculateProjectWarehousingCost($conn, $project_id) {
    $stmt = $conn->prepare("SELECT w.id FROM warehouses w JOIN projects p ON p.warehouse_id=w.id WHERE p.id=?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $resWarehouse = $stmt->get_result();
    $stmt->close();

    if ($resWarehouse->num_rows < 1) return 0;
    $warehouse_id = $resWarehouse->fetch_assoc()['id'];

    $cost_stmt = $conn->prepare("SELECT trigger_event, amount FROM warehouse_cost_items WHERE warehouse_id = ? AND is_active = 1");
    $cost_stmt->bind_param("i", $warehouse_id);
    $cost_stmt->execute();
    $cost_result = $cost_stmt->get_result();

    $warehouse = ['in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];
    while ($cost = $cost_result->fetch_assoc()) {
        switch ($cost['trigger_event']) {
            case 'entry': $warehouse['in_fee'] = $cost['amount']; break;
            case 'exit': $warehouse['out_fee'] = $cost['amount']; break;
            case 'monthly': $warehouse['monthly_storage_fee'] = $cost['amount']; break;
        }
    }
    $cost_stmt->close();

    $stmt2 = $conn->prepare("SELECT COUNT(*) FROM deliveries WHERE project_id=? AND warehouse_arrival_date IS NOT NULL");
    $stmt2->bind_param("i", $project_id);
    $stmt2->execute();
    $stmt2->bind_result($total_in);
    $stmt2->fetch();
    $stmt2->close();

    $stmt3 = $conn->prepare("SELECT COUNT(*) FROM deliveries WHERE project_id=? AND warehouse_arrival_date IS NOT NULL AND left_warehouse_date IS NOT NULL");
    $stmt3->bind_param("i", $project_id);
    $stmt3->execute();
    $stmt3->bind_result($total_out);
    $stmt3->fetch();
    $stmt3->close();

    $stmt4 = $conn->prepare("SELECT warehouse_arrival_date, left_warehouse_date FROM deliveries WHERE project_id=? AND warehouse_arrival_date IS NOT NULL");
    $stmt4->bind_param("i", $project_id);
    $stmt4->execute();
    $res4 = $stmt4->get_result();
    $stmt4->close();

    $storage_cost_total = 0;
    while ($d = $res4->fetch_assoc()) {
        if (empty($d['warehouse_arrival_date'])) continue;
        $start = new DateTime($d['warehouse_arrival_date']);
        $end = new DateTime(!empty($d['left_warehouse_date']) ? $d['left_warehouse_date'] : date('Y-m-d'));
        $days = $start->diff($end)->days + 1;
        $storage_cost_total += $days * ($warehouse['monthly_storage_fee'] / 30.0);
    }

    return ($warehouse['in_fee'] * $total_in) + ($warehouse['out_fee'] * $total_out) + $storage_cost_total;
}

/**
 * Calculate YTD warehousing cost for a project.
 */
function calculateProjectYTDWarehousingCost($conn, $project_id, $current_year) {
    $stmt = $conn->prepare("SELECT w.id FROM warehouses w JOIN projects p ON p.warehouse_id=w.id WHERE p.id=?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $warehouse_res = $stmt->get_result();
    $stmt->close();

    if ($warehouse_res->num_rows < 1) return 0;
    $warehouse_id = $warehouse_res->fetch_assoc()['id'];

    $cost_stmt = $conn->prepare("SELECT trigger_event, amount FROM warehouse_cost_items WHERE warehouse_id = ? AND is_active = 1");
    $cost_stmt->bind_param("i", $warehouse_id);
    $cost_stmt->execute();
    $cost_result = $cost_stmt->get_result();

    $warehouse = ['in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];
    while ($cost = $cost_result->fetch_assoc()) {
        switch ($cost['trigger_event']) {
            case 'entry': $warehouse['in_fee'] = $cost['amount']; break;
            case 'exit': $warehouse['out_fee'] = $cost['amount']; break;
            case 'monthly': $warehouse['monthly_storage_fee'] = $cost['amount']; break;
        }
    }
    $cost_stmt->close();

    $stmt2 = $conn->prepare("SELECT COUNT(*) FROM deliveries WHERE project_id=? AND warehouse_arrival_date IS NOT NULL AND YEAR(warehouse_arrival_date)=?");
    $stmt2->bind_param("ii", $project_id, $current_year);
    $stmt2->execute();
    $stmt2->bind_result($total_in);
    $stmt2->fetch();
    $stmt2->close();

    $stmt3 = $conn->prepare("SELECT COUNT(*) FROM deliveries WHERE project_id=? AND warehouse_arrival_date IS NOT NULL AND left_warehouse_date IS NOT NULL AND YEAR(left_warehouse_date)=?");
    $stmt3->bind_param("ii", $project_id, $current_year);
    $stmt3->execute();
    $stmt3->bind_result($total_out);
    $stmt3->fetch();
    $stmt3->close();

    $stmt4 = $conn->prepare("SELECT warehouse_arrival_date, left_warehouse_date FROM deliveries WHERE project_id=? AND warehouse_arrival_date IS NOT NULL");
    $stmt4->bind_param("i", $project_id);
    $stmt4->execute();
    $res4 = $stmt4->get_result();
    $stmt4->close();

    $yr_start = new DateTime("$current_year-01-01");
    $yr_end = new DateTime("$current_year-12-31");
    $storage_cost_total = 0;

    while ($d = $res4->fetch_assoc()) {
        if (empty($d['warehouse_arrival_date'])) continue;
        $start = new DateTime($d['warehouse_arrival_date']);
        $end = new DateTime(!empty($d['left_warehouse_date']) ? $d['left_warehouse_date'] : date('Y-m-d'));

        if ($start > $yr_end || $end < $yr_start) continue;
        if ($start < $yr_start) $start = clone $yr_start;
        if ($end > $yr_end) $end = clone $yr_end;

        $days = $start->diff($end)->days + 1;
        $storage_cost_total += $days * ($warehouse['monthly_storage_fee'] / 30.0);
    }

    return ($warehouse['in_fee'] * $total_in) + ($warehouse['out_fee'] * $total_out) + $storage_cost_total;
}

/**
 * Calculate total logistics cost and watts for a project.
 */
function calculateProjectTotalLogisticsCost($conn, $project_id, $filter) {
    $current_year = date('Y');

    $sql_deliv = "SELECT freight_cost, accessorial_costs, wattage, quantity FROM deliveries WHERE project_id=?";
    if ($filter === 'ytd') $sql_deliv .= " AND YEAR(created_at)=?";

    $stmt = $conn->prepare($sql_deliv);
    if ($filter === 'ytd') {
        $stmt->bind_param("ii", $project_id, $current_year);
    } else {
        $stmt->bind_param("i", $project_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $freight_cost = 0;
    $accessorial_costs = 0;
    $total_watts = 0;

    while ($r = $res->fetch_assoc()) {
        $freight_cost += (float)$r['freight_cost'];
        $accessorial_costs += (float)$r['accessorial_costs'];
        $total_watts += (float)$r['wattage'] * (float)$r['quantity'];
    }

    $warehousing_cost = ($filter === 'ytd')
        ? calculateProjectYTDWarehousingCost($conn, $project_id, $current_year)
        : calculateProjectWarehousingCost($conn, $project_id);

    return [
        'freight_cost' => $freight_cost,
        'accessorial_costs' => $accessorial_costs,
        'warehousing_cost' => $warehousing_cost,
        'total_logistics_cost' => $freight_cost + $accessorial_costs + $warehousing_cost,
        'total_watts' => $total_watts
    ];
}

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'total';
$view_mode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'dollars';
$current_year = date('Y');

$total_freight = 0;
$total_accessorial = 0;
$total_warehousing = 0;
$total_logistics_cost = 0;
$total_watts = 0;

if ($role === 'global_admin') {
    $sql_proj = "SELECT p.id, p.project_name, p.image_url, p.project_address FROM projects p WHERE status IS NULL OR status = 'active'";
    $paramTypes = "";
    $params = [];
} else {
    $sql_proj = "SELECT p.id, p.project_name, p.image_url, p.project_address FROM projects p JOIN customer_account_users cau ON p.account_id = cau.account_id WHERE cau.user_id=? AND (p.status IS NULL OR p.status = 'active')";
    $paramTypes = "i";
    $params = [$user_id];
}

$stmtProj = $conn->prepare($sql_proj);
if (!empty($paramTypes)) {
    $stmtProj->bind_param($paramTypes, ...$params);
}
$stmtProj->execute();
$projects_res = $stmtProj->get_result();
$stmtProj->close();

$projects = [];
while ($p = $projects_res->fetch_assoc()) {
    $calc = calculateProjectTotalLogisticsCost($conn, $p['id'], $filter);

    $total_freight += $calc['freight_cost'];
    $total_accessorial += $calc['accessorial_costs'];
    $total_warehousing += $calc['warehousing_cost'];
    $total_logistics_cost += $calc['total_logistics_cost'];
    $total_watts += $calc['total_watts'];

    $p['freight_cost'] = $calc['freight_cost'];
    $p['accessorial_costs'] = $calc['accessorial_costs'];
    $p['warehousing_cost'] = $calc['warehousing_cost'];
    $p['total_logistics_cost'] = $calc['total_logistics_cost'];
    $p['total_watts'] = $calc['total_watts'];
    $p['cost_per_watt'] = $calc['total_watts'] > 0 ? $calc['total_logistics_cost'] / $calc['total_watts'] : 0;

    $projects[] = $p;
}

$project_count = count($projects);

if ($filter === 'per_project' && $project_count > 0) {
    $disp_freight = $total_freight / $project_count;
    $disp_accessorial = $total_accessorial / $project_count;
    $disp_warehousing = $total_warehousing / $project_count;
    $disp_total = $total_logistics_cost / $project_count;
    $disp_watts = $total_watts / $project_count;
} else {
    $disp_freight = $total_freight;
    $disp_accessorial = $total_accessorial;
    $disp_warehousing = $total_warehousing;
    $disp_total = $total_logistics_cost;
    $disp_watts = $total_watts;
}

$disp_cost_per_watt = $disp_watts > 0 ? $disp_total / $disp_watts : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Overview</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .page-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
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

        .controls-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
        }
        .filter-pills {
            display: inline-flex;
            background: #f1f3f4;
            border-radius: 10px;
            padding: 4px;
            gap: 4px;
        }
        .filter-pill {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            background: transparent;
            color: #6c757d;
            font-weight: 600;
            font-size: 0.9em;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-pill:hover { background: rgba(255,255,255,0.5); color: #293E4C; }
        .filter-pill.active { background: #fff; color: #293E4C; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }

        .view-mode-toggle {
            display: inline-flex;
            background: #f1f3f4;
            border-radius: 10px;
            padding: 4px;
            gap: 4px;
        }
        .view-mode-btn {
            padding: 10px 16px;
            border-radius: 8px;
            border: none;
            background: transparent;
            color: #6c757d;
            font-weight: 600;
            font-size: 0.85em;
            cursor: pointer;
            transition: all 0.2s;
        }
        .view-mode-btn:hover { background: rgba(255,255,255,0.5); color: #293E4C; }
        .view-mode-btn.active { background: #fff; color: #488C9A; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
        .stat-card.primary { background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); border: none; }
        .stat-card.primary .stat-label, .stat-card.primary .stat-value { color: #fff; }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.5em;
            background: linear-gradient(135deg, #e8f4f7 0%, #d4eef3 100%);
        }
        .stat-card.primary .stat-icon { background: rgba(255,255,255,0.2); }
        .stat-value { font-size: 1.8em; font-weight: 700; color: #293E4C; margin-bottom: 4px; }
        .stat-label { color: #6c757d; font-size: 0.85em; font-weight: 500; }

        .section-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 16px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .section-title-group { display: flex; align-items: center; gap: 16px; }
        .section-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3em;
        }
        .section-title { font-size: 1.2em; font-weight: 600; color: #293E4C; margin: 0; }
        .section-subtitle { font-size: 0.85em; color: #6c757d; margin: 4px 0 0; }
        .view-toggle {
            display: inline-flex;
            background: #f1f3f4;
            border-radius: 8px;
            padding: 3px;
            gap: 3px;
        }
        .view-toggle button {
            padding: 8px 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 1em;
            border: none;
            cursor: pointer;
            color: #6c757d;
            background: transparent;
            transition: all 0.2s;
        }
        .view-toggle button:hover { color: #293E4C; background: rgba(255,255,255,0.5); }
        .view-toggle button.active { background: #fff; color: #293E4C; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
            max-width: 1400px;
        }
        .projects-grid:not(.active) { display: none; }
        @media (min-width: 1400px) { .projects-grid { grid-template-columns: repeat(4, 1fr); } }

        .project-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.2s;
            cursor: pointer;
        }
        .project-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,0.12); }
        .project-card-image {
            width: 100%; height: 120px;
            background: linear-gradient(135deg, #e8f4f7 0%, #d4eef3 100%);
            position: relative;
            overflow: hidden;
        }
        .project-card-image img { width: 100%; height: 100%; object-fit: cover; }
        .project-card-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(45deg, rgba(72,140,154,0.9), rgba(58,110,127,0.9));
            display: flex; align-items: center; justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .project-card:hover .project-card-overlay { opacity: 1; }
        .project-card-overlay span { color: #fff; font-size: 0.95em; font-weight: 600; }
        .project-card-content { padding: 16px; }
        .project-card-title { margin: 0 0 16px; font-size: 1em; color: #293E4C; font-weight: 600; line-height: 1.3; }
        .project-card-stats { display: flex; flex-direction: column; gap: 8px; }
        .project-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .project-stat-row .label { font-size: 0.8em; color: #6c757d; }
        .project-stat-row .value { font-size: 0.9em; font-weight: 700; color: #488C9A; }
        .project-card-total {
            margin-top: 12px;
            padding: 14px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 10px;
            text-align: center;
        }
        .project-card-total .value { font-size: 1.2em; font-weight: 700; color: #fff; }
        .project-card-total .label { font-size: 0.75em; color: rgba(255,255,255,0.85); margin-top: 2px; }

        .table-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            overflow: hidden;
            display: none;
        }
        .table-container.active { display: block; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: #f8f9fa;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #293E4C;
            font-size: 0.8em;
            border-bottom: 2px solid #e9ecef;
        }
        .data-table td { padding: 14px 16px; border-bottom: 1px solid #f1f3f4; font-size: 0.9em; }
        .data-table tbody tr { cursor: pointer; transition: background 0.2s; }
        .data-table tbody tr:hover { background: #f8f9fa; }
        .data-table .project-name { font-weight: 600; color: #293E4C; }
        .data-table .cost-cell { font-weight: 600; color: #488C9A; }
        .data-table .total-cell {
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 6px;
            padding: 6px 12px;
            display: inline-block;
        }

        .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
        .empty-state h3 { color: #293E4C; margin-bottom: 8px; }

        @media (max-width: 992px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .page-header { padding: 24px; }
            .page-header h1 { font-size: 1.8em; }
            .stats-grid { grid-template-columns: 1fr; }
            .projects-grid { grid-template-columns: 1fr; }
            .table-container { overflow-x: auto; }
            .data-table { min-width: 700px; }
            .controls-row { flex-direction: column; align-items: stretch; }
            .filter-pills, .view-mode-toggle { justify-content: center; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Cost Overview']); ?>

    <div class="page-header">
        <h1>Cost Overview</h1>
        <p>Comprehensive logistics cost analysis across all your projects</p>
    </div>

    <div class="controls-row">
        <div class="filter-pills">
            <button type="button" class="filter-pill <?php echo $filter === 'total' ? 'active' : ''; ?>" data-filter="total">All Time</button>
            <button type="button" class="filter-pill <?php echo $filter === 'ytd' ? 'active' : ''; ?>" data-filter="ytd">Year to Date</button>
            <button type="button" class="filter-pill <?php echo $filter === 'per_project' ? 'active' : ''; ?>" data-filter="per_project">Avg per Project</button>
        </div>
        <div class="view-mode-toggle">
            <button type="button" class="view-mode-btn <?php echo $view_mode === 'dollars' ? 'active' : ''; ?>" data-mode="dollars">$ Dollars</button>
            <button type="button" class="view-mode-btn <?php echo $view_mode === 'per_watt' ? 'active' : ''; ?>" data-mode="per_watt">$/Watt</button>
        </div>
    </div>

    <?php if ($view_mode === 'dollars'): ?>
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon">$</div>
            <div class="stat-value">$<?php echo number_format($disp_total, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Total Cost' : ($filter === 'ytd' ? 'Total Cost (YTD)' : 'Total Logistics Cost'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🚛</div>
            <div class="stat-value">$<?php echo number_format($disp_freight, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Freight' : 'Freight Cost'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📋</div>
            <div class="stat-value">$<?php echo number_format($disp_accessorial, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Accessorial' : 'Accessorial Cost'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🏢</div>
            <div class="stat-value">$<?php echo number_format($disp_warehousing, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Warehousing' : 'Warehousing Cost'; ?></div>
        </div>
    </div>
    <?php else: ?>
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon">⚡</div>
            <div class="stat-value">$<?php echo number_format($disp_cost_per_watt, 4); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Cost per Watt' : 'Cost per Watt'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">$</div>
            <div class="stat-value">$<?php echo number_format($disp_total, 0); ?></div>
            <div class="stat-label">Total Cost</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🔋</div>
            <div class="stat-value"><?php echo number_format($disp_watts / 1000000, 2); ?> MW</div>
            <div class="stat-label">Total Watts</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-value"><?php echo $project_count; ?></div>
            <div class="stat-label">Projects</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($projects)): ?>
    <div class="section-header-row">
        <div class="section-title-group">
            <div class="section-icon">💼</div>
            <div>
                <h2 class="section-title">Project Cost Breakdown</h2>
                <p class="section-subtitle"><?php echo $project_count; ?> active project<?php echo $project_count !== 1 ? 's' : ''; ?> with logistics data</p>
            </div>
        </div>
        <div class="view-toggle">
            <button type="button" class="active" id="btn-grid" title="Grid View">▦</button>
            <button type="button" id="btn-table" title="Table View">☰</button>
        </div>
    </div>

    <div class="projects-grid active" id="projects-grid">
        <?php foreach ($projects as $proj): ?>
        <div class="project-card" data-href="project_cost_details?project_id=<?php echo $proj['id']; ?>">
            <div class="project-card-image">
                <?php if (!empty($proj['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($proj['image_url']); ?>" alt="<?php echo htmlspecialchars($proj['project_name']); ?>">
                <?php endif; ?>
                <div class="project-card-overlay"><span>View Details</span></div>
            </div>
            <div class="project-card-content">
                <h3 class="project-card-title"><?php echo htmlspecialchars($proj['project_name']); ?></h3>
                <div class="project-card-stats">
                    <div class="project-stat-row">
                        <span class="label">Freight</span>
                        <span class="value">$<?php echo number_format($proj['freight_cost'], 0); ?></span>
                    </div>
                    <div class="project-stat-row">
                        <span class="label">Accessorial</span>
                        <span class="value">$<?php echo number_format($proj['accessorial_costs'], 0); ?></span>
                    </div>
                    <div class="project-stat-row">
                        <span class="label">Warehousing</span>
                        <span class="value">$<?php echo number_format($proj['warehousing_cost'], 0); ?></span>
                    </div>
                </div>
                <div class="project-card-total">
                    <?php if ($view_mode === 'per_watt'): ?>
                    <div class="value">$<?php echo number_format($proj['cost_per_watt'], 4); ?>/W</div>
                    <div class="label">Cost per Watt</div>
                    <?php else: ?>
                    <div class="value">$<?php echo number_format($proj['total_logistics_cost'], 2); ?></div>
                    <div class="label">Total Logistics Cost</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="table-container" id="projects-table">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Freight</th>
                    <th>Accessorial</th>
                    <th>Warehousing</th>
                    <?php if ($view_mode === 'per_watt'): ?>
                    <th>Cost/Watt</th>
                    <?php else: ?>
                    <th>Total Cost</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $proj): ?>
                <tr data-href="project_cost_details?project_id=<?php echo $proj['id']; ?>">
                    <td class="project-name"><?php echo htmlspecialchars($proj['project_name']); ?></td>
                    <td class="cost-cell">$<?php echo number_format($proj['freight_cost'], 2); ?></td>
                    <td class="cost-cell">$<?php echo number_format($proj['accessorial_costs'], 2); ?></td>
                    <td class="cost-cell">$<?php echo number_format($proj['warehousing_cost'], 2); ?></td>
                    <?php if ($view_mode === 'per_watt'): ?>
                    <td><span class="total-cell">$<?php echo number_format($proj['cost_per_watt'], 4); ?>/W</span></td>
                    <?php else: ?>
                    <td><span class="total-cell">$<?php echo number_format($proj['total_logistics_cost'], 2); ?></span></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <h3>No Projects Found</h3>
        <p>No projects with logistics data are available.</p>
    </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Filter pills
    document.querySelectorAll('.filter-pill').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var filter = this.getAttribute('data-filter');
            var url = new URL(window.location.href);
            url.searchParams.set('filter', filter);
            window.location.href = url.toString();
        });
    });

    // View mode toggle
    document.querySelectorAll('.view-mode-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var mode = this.getAttribute('data-mode');
            var url = new URL(window.location.href);
            url.searchParams.set('view_mode', mode);
            window.location.href = url.toString();
        });
    });

    // Grid/Table view toggle
    var btnGrid = document.getElementById('btn-grid');
    var btnTable = document.getElementById('btn-table');
    var gridView = document.getElementById('projects-grid');
    var tableView = document.getElementById('projects-table');

    function setView(view) {
        if (gridView) gridView.classList.toggle('active', view === 'grid');
        if (tableView) tableView.classList.toggle('active', view === 'table');
        if (btnGrid) btnGrid.classList.toggle('active', view === 'grid');
        if (btnTable) btnTable.classList.toggle('active', view === 'table');
        localStorage.setItem('costOverviewView', view);
    }

    if (btnGrid) btnGrid.addEventListener('click', function() { setView('grid'); });
    if (btnTable) btnTable.addEventListener('click', function() { setView('table'); });

    // Restore saved view
    var savedView = localStorage.getItem('costOverviewView') || 'grid';
    setView(savedView);

    // Card and row clicks
    document.querySelectorAll('[data-href]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.location.href = this.getAttribute('data-href');
        });
    });
});
</script>
</body>
</html>
