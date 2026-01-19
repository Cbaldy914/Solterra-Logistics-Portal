<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

require_once '../config.php';
require_once 'cost_helpers.php';
require_once 'milestone_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'total';
$view_mode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'dollars';
$current_year = date('Y');

// Get projects based on user role
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
$total_module_cost = 0;
$total_wattage = 0;
$projects_with_cost_data = 0;
$manufacturer_costs = [];

// Logistics cost totals
$total_freight_cost = 0;
$total_warehousing_cost = 0;
$total_accessorial_cost = 0;
$total_logistics_cost = 0;

while ($p = $projects_res->fetch_assoc()) {
    // Calculate module costs for this project
    $module_data = calculate_project_module_cost($p['id'], $conn);

    $p['module_cost'] = $module_data['total_module_cost'];
    $p['total_wattage'] = $module_data['total_watts'];
    $p['avg_cost_per_watt'] = $module_data['avg_cost_per_watt'];
    $p['has_cost_data'] = $module_data['has_cost_data'];
    $p['breakdown'] = $module_data['breakdown'];

    $total_module_cost += $module_data['total_module_cost'];
    $total_wattage += $module_data['total_watts'];

    // Calculate logistics costs for this project
    $project_freight = 0;
    $project_warehousing = 0;
    $project_accessorial = 0;

    $stmt_logistics = $conn->prepare("
        SELECT
            COALESCE(SUM(COALESCE(d.freight_cost, d.customer_cost, 0)), 0) as freight,
            COALESCE(SUM(COALESCE(d.accessorial_costs, 0)), 0) as accessorial
        FROM deliveries d
        WHERE d.project_id = ?
    ");
    $stmt_logistics->bind_param("i", $p['id']);
    $stmt_logistics->execute();
    $stmt_logistics->bind_result($project_freight, $project_accessorial);
    $stmt_logistics->fetch();
    $stmt_logistics->close();

    // Calculate warehousing costs using the helper function (includes fallback calculation)
    $project_warehousing = calculate_project_warehousing_cost($p['id'], $conn);

    $p['freight_cost'] = (float)$project_freight;
    $p['warehousing_cost'] = (float)$project_warehousing;
    $p['accessorial_cost'] = (float)$project_accessorial;
    $p['logistics_cost'] = $p['freight_cost'] + $p['warehousing_cost'] + $p['accessorial_cost'];
    $p['total_cost'] = $p['module_cost'] + $p['logistics_cost'];

    $total_freight_cost += $p['freight_cost'];
    $total_warehousing_cost += $p['warehousing_cost'];
    $total_accessorial_cost += $p['accessorial_cost'];
    $total_logistics_cost += $p['logistics_cost'];

    // Count projects with any cost data (module OR logistics)
    if ($module_data['has_cost_data'] || $p['logistics_cost'] > 0) {
        $projects_with_cost_data++;
    }

    // Aggregate manufacturer costs - breakdown is an array of items with vendor_name
    if (!empty($module_data['breakdown'])) {
        $project_manufacturers = []; // Track which manufacturers we've seen for this project
        foreach ($module_data['breakdown'] as $item) {
            $mfr = $item['vendor_name'] ?? 'Unknown';
            if (empty($mfr)) $mfr = 'Unknown';

            if (!isset($manufacturer_costs[$mfr])) {
                $manufacturer_costs[$mfr] = [
                    'total_cost' => 0,
                    'total_wattage' => 0,
                    'project_count' => 0,
                    'deliveries_total' => 0,
                    'deliveries_on_time' => 0,
                    'deliveries_late' => 0,
                    'damaged_modules' => 0
                ];
            }
            if ($item['module_cost'] !== null) {
                $manufacturer_costs[$mfr]['total_cost'] += $item['module_cost'];
            }
            $manufacturer_costs[$mfr]['total_wattage'] += $item['total_watts'] ?? 0;

            // Only count project once per manufacturer
            if (!isset($project_manufacturers[$mfr])) {
                $manufacturer_costs[$mfr]['project_count']++;
                $project_manufacturers[$mfr] = true;
            }
        }
    }

    $projects[] = $p;
}

// Calculate delivery performance per manufacturer (using distinct deliveries, filtered by accessible projects)
// Build project IDs list for filtering
$project_ids = array_column($projects, 'id');
$project_ids_str = !empty($project_ids) ? implode(',', array_map('intval', $project_ids)) : '0';

$delivery_perf_sql = "
    SELECT
        ip.manufacturer,
        COUNT(DISTINCT d.id) as total_deliveries,
        COUNT(DISTINCT CASE WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
                 AND d.actual_delivery_date <= d.anticipated_delivery_date THEN d.id END) as on_time,
        COUNT(DISTINCT CASE WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
                 AND d.actual_delivery_date > d.anticipated_delivery_date THEN d.id END) as late
    FROM deliveries d
    JOIN delivery_pallets dp ON dp.delivery_id = d.id
    JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
    WHERE ip.manufacturer IS NOT NULL AND ip.manufacturer != ''
    AND d.project_id IN ($project_ids_str)
    GROUP BY ip.manufacturer
";
$perf_result = $conn->query($delivery_perf_sql);
if ($perf_result) {
    while ($row = $perf_result->fetch_assoc()) {
        $mfr = $row['manufacturer'];
        if (isset($manufacturer_costs[$mfr])) {
            $manufacturer_costs[$mfr]['deliveries_total'] = (int)$row['total_deliveries'];
            $manufacturer_costs[$mfr]['deliveries_on_time'] = (int)$row['on_time'];
            $manufacturer_costs[$mfr]['deliveries_late'] = (int)$row['late'];
        }
    }
}

// Calculate damaged modules per manufacturer from warranty claims (sum module quantity, not pallet count)
$damage_sql = "
    SELECT
        ip.manufacturer,
        COALESCE(SUM(ip.quantity), 0) as damaged_count
    FROM warranty_claim_replacements wcr
    JOIN inventory_pallets ip ON wcr.pallet_id = ip.id
    WHERE ip.manufacturer IS NOT NULL AND ip.manufacturer != ''
    AND ip.assigned_project_id IN ($project_ids_str)
    GROUP BY ip.manufacturer
";
$damage_result = $conn->query($damage_sql);
if ($damage_result) {
    while ($row = $damage_result->fetch_assoc()) {
        $mfr = $row['manufacturer'];
        if (isset($manufacturer_costs[$mfr])) {
            $manufacturer_costs[$mfr]['damaged_modules'] = (int)$row['damaged_count'];
        }
    }
}

// Calculate averages for manufacturers
foreach ($manufacturer_costs as $mfr => &$data) {
    $data['avg_cost_per_watt'] = $data['total_wattage'] > 0 ? $data['total_cost'] / $data['total_wattage'] : 0;
}
unset($data);

// Sort manufacturers by total cost descending
uasort($manufacturer_costs, function($a, $b) {
    return $b['total_cost'] <=> $a['total_cost'];
});

$project_count = count($projects);
$avg_cost_per_watt = $total_wattage > 0 ? $total_module_cost / $total_wattage : 0;
$total_combined_cost = $total_module_cost + $total_logistics_cost;

// Get portfolio milestone summary
$account_id = null;
if ($role !== 'global_admin') {
    // Get account_id for this user
    $stmt_acc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt_acc->bind_param("i", $user_id);
    $stmt_acc->execute();
    $stmt_acc->bind_result($account_id);
    $stmt_acc->fetch();
    $stmt_acc->close();
}
$milestone_summary = get_portfolio_milestone_summary($conn, $account_id);
$has_milestone_data = $milestone_summary['total_contract_value'] > 0;

// Apply filter for display
if ($filter === 'per_project' && $project_count > 0) {
    $disp_module_cost = $total_module_cost / $project_count;
    $disp_wattage = $total_wattage / $project_count;
    $disp_cost_per_watt = $avg_cost_per_watt;
    $disp_logistics_cost = $total_logistics_cost / $project_count;
    $disp_combined_cost = $total_combined_cost / $project_count;
} else {
    $disp_module_cost = $total_module_cost;
    $disp_wattage = $total_wattage;
    $disp_cost_per_watt = $avg_cost_per_watt;
    $disp_logistics_cost = $total_logistics_cost;
    $disp_combined_cost = $total_combined_cost;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Cost Analysis</title>
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
        .page-header-content {
            display: flex;
            align-items: flex-start;
            gap: 12px;
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

        .info-tooltip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px; height: 22px;
            background: #488C9A;
            color: white;
            border-radius: 50%;
            font-weight: bold;
            cursor: pointer;
            position: relative;
            font-size: 0.7em;
            flex-shrink: 0;
            margin-top: 8px;
        }
        .info-tooltip:hover { background: #293E4C; }
        .info-tooltip .tooltip-text {
            display: none;
            width: 320px;
            background: #fff;
            color: #333;
            text-align: left;
            border-radius: 8px;
            padding: 16px;
            position: absolute;
            z-index: 1000;
            top: 30px;
            left: -150px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            font-weight: normal;
            font-size: 0.85rem;
            line-height: 1.5;
        }
        .info-tooltip:hover .tooltip-text { display: block; }

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
            font-family: 'Poppins', sans-serif;
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
            font-family: 'Poppins', sans-serif;
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
        .stat-card.primary { background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%); border: none; }
        .stat-card.primary .stat-label, .stat-card.primary .stat-value { color: #fff; }
        .stat-card-clickable { cursor: pointer; position: relative; }
        .stat-card-clickable:hover { transform: translateY(-6px); box-shadow: 0 12px 28px rgba(0,0,0,0.15); }
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
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.5em;
        }
        .stat-card.primary .stat-icon { background: rgba(255,255,255,0.2); color: #fff; }
        .stat-icon.pink { background: linear-gradient(135deg, #e8f4f6 0%, #d1e8ec 100%); color: #293E4C; }
        .stat-icon.blue { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #2563eb; }
        .stat-icon.purple { background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); color: #7c3aed; }
        .stat-icon.green { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #059669; }
        .stat-value { font-size: 1.8em; font-weight: 700; color: #293E4C; margin-bottom: 4px; }
        .stat-label { color: #6c757d; font-size: 0.85em; font-weight: 500; }

        /* Logistics Modal */
        .logistics-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }
        .logistics-modal-content {
            background: white;
            margin: 10% auto;
            padding: 0;
            width: 90%;
            max-width: 600px;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .logistics-modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            color: white;
            padding: 24px;
            border-radius: 20px 20px 0 0;
            position: relative;
        }
        .logistics-modal-header h2 {
            margin: 0;
            font-size: 1.4em;
            font-weight: 600;
        }
        .logistics-modal-close {
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
        .logistics-modal-close:hover { transform: scale(1.1); }
        .logistics-modal-body { padding: 24px; }
        .logistics-breakdown-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .logistics-breakdown-item {
            text-align: center;
            padding: 20px 16px;
            border-radius: 12px;
            transition: transform 0.2s;
        }
        .logistics-breakdown-item:hover { transform: translateY(-2px); }
        .logistics-breakdown-item.freight { background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(59, 130, 246, 0.25) 100%); }
        .logistics-breakdown-item.warehousing { background: linear-gradient(135deg, rgba(139, 92, 246, 0.15) 0%, rgba(139, 92, 246, 0.25) 100%); }
        .logistics-breakdown-item.accessorial { background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(245, 158, 11, 0.25) 100%); }
        .logistics-breakdown-item .value {
            font-size: 1.5em;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .logistics-breakdown-item.freight .value { color: #2563eb; }
        .logistics-breakdown-item.warehousing .value { color: #7c3aed; }
        .logistics-breakdown-item.accessorial .value { color: #d97706; }
        .logistics-breakdown-item .label {
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 500;
        }
        .logistics-chart-container { height: 200px; }

        /* Module Cost Modal */
        .module-breakdown-summary {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(59, 130, 246, 0.15) 100%);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .module-summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(59, 130, 246, 0.1);
        }
        .module-summary-row:last-child { border-bottom: none; }
        .module-summary-row .label { color: #6c757d; font-size: 0.9em; }
        .module-summary-row .value { font-weight: 600; color: #2563eb; font-size: 0.95em; }
        .module-manufacturer-list {
            max-height: 200px;
            overflow-y: auto;
        }
        .module-manufacturer-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 12px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .module-manufacturer-item:hover { background: #f8f9fa; }
        .mfr-name { font-weight: 500; color: #293E4C; }
        .mfr-cost { font-weight: 600; color: #488C9A; }

        /* Manufacturer Breakdown Card Styles */
        .manufacturer-breakdown-card { display: flex; flex-direction: column; }
        .manufacturer-breakdown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .manufacturer-breakdown-header h3 { margin: 0; }
        .view-all-link {
            color: #488C9A;
            font-size: 0.85em;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s;
        }
        .view-all-link:hover { color: #293E4C; }
        .manufacturer-mini-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            flex: 1;
        }
        .manufacturer-mini-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .manufacturer-mini-card:hover {
            border-color: #488C9A;
            box-shadow: 0 4px 16px rgba(72,140,154,0.15);
            transform: translateY(-2px);
        }
        .mini-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .mini-mfr-name {
            font-weight: 700;
            color: #293E4C;
            font-size: 1.05em;
            line-height: 1.3;
            flex: 1;
            margin-right: 12px;
        }
        .mini-badge {
            background: #488C9A;
            color: #fff;
            font-size: 0.75em;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 6px;
            white-space: nowrap;
            flex-shrink: 0;
            line-height: 1;
        }
        .mini-card-stats {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .mini-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .mini-stat-label {
            font-size: 0.9em;
            color: #488C9A;
            font-weight: 500;
        }
        .mini-stat-value {
            font-weight: 700;
            color: #293E4C;
            font-size: 1em;
        }
        .see-more-link {
            display: block;
            text-align: center;
            color: #488C9A;
            font-size: 0.85em;
            font-weight: 500;
            margin-top: 16px;
            text-decoration: none;
        }
        .see-more-link:hover { color: #293E4C; }
        .see-more-link:hover { text-decoration: underline; }
        .empty-mini-state {
            text-align: center;
            color: #6c757d;
            padding: 40px 20px;
            font-size: 0.9em;
        }

        /* Milestone Summary Section */
        .milestone-portfolio-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(23, 162, 184, 0.15);
        }
        .milestone-portfolio-section h2 {
            font-size: 1.4em;
            font-weight: 700;
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 24px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .milestone-portfolio-section h2 i {
            color: #17a2b8;
            -webkit-text-fill-color: #17a2b8;
        }
        .milestone-overview-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .milestone-overview-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .milestone-overview-card.primary {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
        }
        .milestone-overview-card.primary .milestone-value,
        .milestone-overview-card.primary .milestone-label {
            color: #fff;
        }
        .milestone-value {
            font-size: 1.8em;
            font-weight: 700;
            color: #293E4C;
            margin-bottom: 4px;
        }
        .milestone-label {
            color: #6c757d;
            font-size: 0.85em;
            font-weight: 500;
        }
        .milestone-progress-bar {
            height: 12px;
            background: #e9ecef;
            border-radius: 6px;
            overflow: hidden;
            margin: 16px 0;
        }
        .milestone-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #17a2b8, #28a745);
            border-radius: 6px;
            transition: width 0.3s ease;
        }
        .milestone-trigger-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        .milestone-trigger-card {
            background: #fff;
            border-radius: 10px;
            padding: 16px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .milestone-trigger-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .trigger-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 1.1em;
        }
        .trigger-icon.po { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #2563eb; }
        .trigger-icon.shipping { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #d97706; }
        .trigger-icon.customs { background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); color: #7c3aed; }
        .trigger-icon.delivery { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #059669; }
        .trigger-value {
            font-size: 1.2em;
            font-weight: 700;
            color: #293E4C;
        }
        .trigger-label {
            font-size: 0.75em;
            color: #6c757d;
            margin-top: 4px;
        }
        @media (max-width: 992px) {
            .milestone-overview-grid { grid-template-columns: 1fr; }
            .milestone-trigger-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 576px) {
            .milestone-trigger-grid { grid-template-columns: 1fr; }
        }

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

        .chart-card h3 i {
            color: #488C9A;
        }

        .chart-container {
            position: relative;
            height: 280px;
        }

        /* Manufacturer Breakdown Section */
        .manufacturer-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(236, 72, 153, 0.08);
        }

        .manufacturer-section h2 {
            font-size: 1.4em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 24px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .manufacturer-section h2 i {
            color: #488C9A;
            -webkit-text-fill-color: #488C9A;
        }

        .manufacturer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .manufacturer-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            border: 1px solid #e9ecef;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }

        .manufacturer-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .manufacturer-card-clickable {
            cursor: pointer;
        }

        .manufacturer-card-clickable:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(72,140,154,0.15);
        }

        .manufacturer-card-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(45deg, rgba(72,140,154,0.95), rgba(41,62,76,0.95));
            display: flex; align-items: center; justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            border-radius: 12px;
        }

        .manufacturer-card-clickable:hover .manufacturer-card-overlay { opacity: 1; }

        .manufacturer-card-overlay span {
            color: #fff;
            font-size: 1em;
            font-weight: 600;
        }

        .manufacturer-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f3f4;
        }

        .manufacturer-name {
            font-size: 1.1em;
            font-weight: 600;
            color: #293E4C;
        }

        .manufacturer-badge {
            background: linear-gradient(135deg, #e8f4f6 0%, #d1e8ec 100%);
            color: #293E4C;
            font-size: 0.75em;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .manufacturer-stats {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .manufacturer-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .manufacturer-stat-row .label {
            font-size: 0.85em;
            color: #6c757d;
        }

        .manufacturer-stat-row .value {
            font-size: 0.95em;
            font-weight: 600;
            color: #293E4C;
        }

        .manufacturer-stat-row .value.highlight {
            color: #488C9A;
            font-size: 1.1em;
        }

        /* Project Section */
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
        .section-title {
            font-size: 1.4em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
        }
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
            width: 100%; height: 180px;
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
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            border-radius: 10px;
            text-align: center;
        }
        .project-card-total .value { font-size: 1.2em; font-weight: 700; color: #fff; }
        .project-card-total .label { font-size: 0.75em; color: rgba(255,255,255,0.85); margin-top: 2px; }
        .no-cost-badge {
            display: inline-block;
            background: #f1f3f4;
            color: #6c757d;
            font-size: 0.8em;
            padding: 4px 10px;
            border-radius: 6px;
        }
        .project-logistics-breakdown {
            background: #f0f7f8 !important;
        }
        .project-logistics-breakdown .label small {
            color: #6c757d;
            font-size: 0.85em;
        }

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
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #fff;
            font-size: 0.8em;
            border-bottom: none;
        }
        .data-table td { padding: 14px 16px; border-bottom: 1px solid #f1f3f4; font-size: 0.9em; }
        .data-table tbody tr { cursor: pointer; transition: background 0.2s; }
        .data-table tbody tr:hover { background: #f0f7f8; }
        .data-table .project-name { font-weight: 600; color: #293E4C; }
        .data-table .cost-cell { font-weight: 600; color: #488C9A; }
        .data-table .total-cell {
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            border-radius: 6px;
            padding: 6px 12px;
            display: inline-block;
        }

        .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
        .empty-state h3 { color: #293E4C; margin-bottom: 8px; }

        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-section { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-header { padding: 24px; }
            .page-header h1 { font-size: 1.8em; }
            .stats-grid { grid-template-columns: 1fr; }
            .projects-grid { grid-template-columns: 1fr; }
            .manufacturer-grid { grid-template-columns: 1fr; }
            .table-container { overflow-x: auto; }
            .data-table { min-width: 600px; }
            .controls-row { flex-direction: column; align-items: stretch; }
            .filter-pills, .view-mode-toggle { justify-content: center; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Module Cost Analysis']); ?>

    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1>Module Cost Analysis</h1>
                <p>Analyze module costs across your portfolio based on cost per watt data</p>
            </div>
        </div>
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

    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
            <?php if ($view_mode === 'per_watt' && $total_wattage > 0): ?>
            <div class="stat-value">$<?php echo number_format($total_combined_cost / $total_wattage, 4); ?>/W</div>
            <div class="stat-label">Total Cost per Watt</div>
            <?php else: ?>
            <div class="stat-value">$<?php echo number_format($disp_combined_cost, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Total Cost' : 'Total Cost'; ?></div>
            <?php endif; ?>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openModuleCostModal()">
            <div class="stat-icon blue"><i class="fas fa-solar-panel"></i></div>
            <?php if ($view_mode === 'per_watt' && $total_wattage > 0): ?>
            <div class="stat-value">$<?php echo number_format($avg_cost_per_watt, 4); ?>/W</div>
            <div class="stat-label">Module Cost per Watt</div>
            <?php else: ?>
            <div class="stat-value">$<?php echo number_format($disp_module_cost, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Module Cost' : 'Module Cost'; ?></div>
            <?php endif; ?>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openLogisticsModal()">
            <div class="stat-icon purple"><i class="fas fa-truck"></i></div>
            <?php if ($view_mode === 'per_watt' && $total_wattage > 0): ?>
            <div class="stat-value">$<?php echo number_format($total_logistics_cost / $total_wattage, 4); ?>/W</div>
            <div class="stat-label">Logistics Cost per Watt</div>
            <?php else: ?>
            <div class="stat-value">$<?php echo number_format($disp_logistics_cost, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Logistics Cost' : 'Logistics Costs'; ?></div>
            <?php endif; ?>
        </div>
        <div class="stat-card stat-card-clickable" onclick="scrollToProjects()">
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-value"><?php echo $projects_with_cost_data; ?></div>
            <div class="stat-label">Projects with Cost Data</div>
        </div>
    </div>

    <!-- Logistics Breakdown Modal -->
    <div id="logisticsModal" class="logistics-modal">
        <div class="logistics-modal-content">
            <div class="logistics-modal-header">
                <h2><i class="fas fa-truck"></i> Logistics Cost Breakdown</h2>
                <button class="logistics-modal-close" onclick="closeLogisticsModal()">&times;</button>
            </div>
            <div class="logistics-modal-body">
                <div class="logistics-breakdown-grid">
                    <div class="logistics-breakdown-item freight">
                        <div class="value">$<?php echo number_format($total_freight_cost, 2); ?></div>
                        <div class="label"><i class="fas fa-shipping-fast"></i> Freight Costs</div>
                    </div>
                    <div class="logistics-breakdown-item warehousing">
                        <div class="value">$<?php echo number_format($total_warehousing_cost, 2); ?></div>
                        <div class="label"><i class="fas fa-warehouse"></i> Warehousing Costs</div>
                    </div>
                    <div class="logistics-breakdown-item accessorial">
                        <div class="value">$<?php echo number_format($total_accessorial_cost, 2); ?></div>
                        <div class="label"><i class="fas fa-plus-circle"></i> Accessorial Costs</div>
                    </div>
                </div>
                <div class="logistics-chart-container">
                    <canvas id="logisticsBreakdownChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Module Cost Breakdown Modal -->
    <div id="moduleCostModal" class="logistics-modal">
        <div class="logistics-modal-content">
            <div class="logistics-modal-header" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                <h2><i class="fas fa-solar-panel"></i> Module Cost Breakdown</h2>
                <button class="logistics-modal-close" onclick="closeModuleCostModal()">&times;</button>
            </div>
            <div class="logistics-modal-body">
                <div class="module-breakdown-summary">
                    <div class="module-summary-row">
                        <span class="label">Total Module Cost</span>
                        <span class="value">$<?php echo number_format($total_module_cost, 2); ?></span>
                    </div>
                    <div class="module-summary-row">
                        <span class="label">Total Wattage</span>
                        <span class="value"><?php echo number_format($total_wattage / 1000000, 2); ?> MW</span>
                    </div>
                    <div class="module-summary-row">
                        <span class="label">Average Cost per Watt</span>
                        <span class="value">$<?php echo $avg_cost_per_watt ? number_format($avg_cost_per_watt, 4) : 'N/A'; ?></span>
                    </div>
                </div>
                <h4 style="margin: 20px 0 12px; color: #293E4C; font-size: 1em;">By Manufacturer</h4>
                <div class="module-manufacturer-list">
                    <?php if (!empty($manufacturer_costs)): ?>
                        <?php foreach ($manufacturer_costs as $mfr => $data): ?>
                        <div class="module-manufacturer-item">
                            <span class="mfr-name"><?php echo htmlspecialchars($mfr); ?></span>
                            <span class="mfr-cost">$<?php echo number_format($data['total_cost'], 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #6c757d; text-align: center;">No manufacturer data available</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="chart-card">
            <h3><i class="fas fa-chart-pie"></i> Portfolio Cost Breakdown</h3>
            <div class="chart-container">
                <canvas id="costBreakdownChart"></canvas>
            </div>
        </div>
        <div class="chart-card manufacturer-breakdown-card">
            <div class="manufacturer-breakdown-header">
                <h3><i class="fas fa-industry"></i> Manufacturer Breakdown</h3>
                <a href="manufacturer_overview" class="view-all-link">View Complete Breakdown <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php if (!empty($manufacturer_costs)): ?>
            <div class="manufacturer-mini-grid">
                <?php
                $mfr_count = 0;
                foreach ($manufacturer_costs as $mfr => $data):
                    if ($mfr_count >= 4) break; // Show max 4 in mini view
                    $avg_cpw = $data['total_wattage'] > 0 ? $data['total_cost'] / $data['total_wattage'] : 0;
                ?>
                <div class="manufacturer-mini-card" onclick="window.location.href='manufacturer_details?manufacturer=<?php echo urlencode($mfr); ?>'">
                    <div class="mini-card-header">
                        <span class="mini-mfr-name"><?php echo htmlspecialchars($mfr); ?></span>
                        <span class="mini-badge"><?php echo $data['project_count']; ?></span>
                    </div>
                    <div class="mini-card-stats">
                        <div class="mini-stat-row">
                            <span class="mini-stat-label">Total Cost</span>
                            <span class="mini-stat-value">$<?php echo number_format($data['total_cost'], 2); ?></span>
                        </div>
                        <div class="mini-stat-row">
                            <span class="mini-stat-label">Total Wattage</span>
                            <span class="mini-stat-value"><?php echo number_format($data['total_wattage'] / 1000000, 2); ?> MW</span>
                        </div>
                        <div class="mini-stat-row">
                            <span class="mini-stat-label">Avg Cost/Watt</span>
                            <span class="mini-stat-value">$<?php echo number_format($avg_cpw, 4); ?></span>
                        </div>
                    </div>
                </div>
                <?php $mfr_count++; endforeach; ?>
            </div>
            <?php if (count($manufacturer_costs) > 4): ?>
            <a href="manufacturer_overview" class="see-more-link">+ <?php echo count($manufacturer_costs) - 4; ?> more manufacturers</a>
            <?php endif; ?>
            <?php else: ?>
            <div class="empty-mini-state">No manufacturer data available</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Milestones Portfolio Summary -->
    <?php if ($has_milestone_data): ?>
    <div class="milestone-portfolio-section">
        <h2><i class="fas fa-money-check-alt"></i> Portfolio Payment Milestones</h2>

        <div class="milestone-overview-grid">
            <div class="milestone-overview-card">
                <div class="milestone-value">$<?php echo number_format($milestone_summary['total_contract_value'], 0); ?></div>
                <div class="milestone-label">Total Contract Value</div>
            </div>
            <div class="milestone-overview-card primary">
                <div class="milestone-value">$<?php echo number_format($milestone_summary['total_triggered'], 0); ?></div>
                <div class="milestone-label">Total Triggered / Paid</div>
            </div>
            <div class="milestone-overview-card">
                <div class="milestone-value">$<?php echo number_format($milestone_summary['total_remaining'], 0); ?></div>
                <div class="milestone-label">Remaining to Trigger</div>
            </div>
        </div>

        <div style="text-align: center; margin-bottom: 8px;">
            <span style="font-size: 0.9em; color: #6c757d;">
                <?php echo number_format($milestone_summary['completion_percent'], 1); ?>% Complete
                (<?php echo $milestone_summary['projects_with_milestones']; ?> project<?php echo $milestone_summary['projects_with_milestones'] !== 1 ? 's' : ''; ?> with milestones)
            </span>
        </div>
        <div class="milestone-progress-bar">
            <div class="milestone-progress-fill" style="width: <?php echo min(100, $milestone_summary['completion_percent']); ?>%;"></div>
        </div>

        <?php if (!empty($milestone_summary['by_trigger_event'])): ?>
        <h4 style="margin: 24px 0 16px; color: #293E4C; font-size: 1em; font-weight: 600;">Triggered by Event Type</h4>
        <div class="milestone-trigger-grid">
            <?php
            $trigger_config = [
                'po_execution' => ['icon' => 'fas fa-file-signature', 'label' => 'PO Execution', 'class' => 'po'],
                'shipping' => ['icon' => 'fas fa-ship', 'label' => 'Shipping', 'class' => 'shipping'],
                'customs_cleared' => ['icon' => 'fas fa-stamp', 'label' => 'Customs Cleared', 'class' => 'customs'],
                'project_delivery' => ['icon' => 'fas fa-truck-loading', 'label' => 'Project Delivery', 'class' => 'delivery']
            ];
            foreach ($trigger_config as $event => $config):
                $event_data = $milestone_summary['by_trigger_event'][$event] ?? ['total' => 0, 'count' => 0];
            ?>
            <div class="milestone-trigger-card">
                <div class="trigger-icon <?php echo $config['class']; ?>">
                    <i class="<?php echo $config['icon']; ?>"></i>
                </div>
                <div class="trigger-value">$<?php echo number_format($event_data['total'], 0); ?></div>
                <div class="trigger-label"><?php echo $config['label']; ?></div>
                <div style="font-size: 0.7em; color: #adb5bd; margin-top: 2px;">
                    <?php echo $event_data['count']; ?> instance<?php echo $event_data['count'] !== 1 ? 's' : ''; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Project Section -->
    <?php if (!empty($projects)): ?>
    <div class="section-header-row">
        <div class="section-title-group">
            <div>
                <h2 class="section-title">Project Module Costs</h2>
                <p class="section-subtitle"><?php echo $project_count; ?> active project<?php echo $project_count !== 1 ? 's' : ''; ?></p>
            </div>
        </div>
        <div class="view-toggle">
            <button type="button" class="active" id="btn-grid" title="Grid View"><i class="fas fa-th-large"></i></button>
            <button type="button" id="btn-table" title="Table View"><i class="fas fa-list"></i></button>
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
                        <span class="label">Module Cost</span>
                        <span class="value">$<?php echo number_format($proj['module_cost'], 0); ?></span>
                    </div>
                    <div class="project-stat-row">
                        <span class="label">Logistics Cost</span>
                        <span class="value">$<?php echo number_format($proj['logistics_cost'], 0); ?></span>
                    </div>
                </div>
                <div class="project-card-total">
                    <?php if ($view_mode === 'per_watt' && $proj['has_cost_data']): ?>
                    <div class="value">$<?php echo number_format($proj['avg_cost_per_watt'], 4); ?>/W</div>
                    <div class="label">Module Cost per Watt</div>
                    <?php else: ?>
                    <div class="value">$<?php echo number_format($proj['total_cost'], 0); ?></div>
                    <div class="label">Total Project Cost</div>
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
                    <th>Module Cost</th>
                    <th>Freight</th>
                    <th>Warehousing</th>
                    <th>Accessorial</th>
                    <th>Logistics Total</th>
                    <th><?php echo $view_mode === 'per_watt' ? 'Cost/Watt' : 'Total Cost'; ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $proj): ?>
                <tr data-href="project_cost_details?project_id=<?php echo $proj['id']; ?>">
                    <td class="project-name"><?php echo htmlspecialchars($proj['project_name']); ?></td>
                    <td class="cost-cell">$<?php echo number_format($proj['module_cost'], 0); ?></td>
                    <td>$<?php echo number_format($proj['freight_cost'], 0); ?></td>
                    <td>$<?php echo number_format($proj['warehousing_cost'], 0); ?></td>
                    <td>$<?php echo number_format($proj['accessorial_cost'], 0); ?></td>
                    <td class="cost-cell">$<?php echo number_format($proj['logistics_cost'], 0); ?></td>
                    <?php if ($view_mode === 'per_watt' && $proj['has_cost_data']): ?>
                    <td><span class="total-cell">$<?php echo number_format($proj['avg_cost_per_watt'], 4); ?>/W</span></td>
                    <?php else: ?>
                    <td><span class="total-cell">$<?php echo number_format($proj['total_cost'], 0); ?></span></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <h3>No Projects Found</h3>
        <p>No projects with module data are available.</p>
    </div>
    <?php endif; ?>
</main>

<script>
// Logistics Modal Functions
function openLogisticsModal() {
    document.getElementById('logisticsModal').style.display = 'block';
    initLogisticsChart();
}

function closeLogisticsModal() {
    document.getElementById('logisticsModal').style.display = 'none';
}

// Module Cost Modal Functions
function openModuleCostModal() {
    document.getElementById('moduleCostModal').style.display = 'block';
}

function closeModuleCostModal() {
    document.getElementById('moduleCostModal').style.display = 'none';
}

// Scroll to projects section
function scrollToProjects() {
    var projectSection = document.querySelector('.section-header-row');
    if (projectSection) {
        projectSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    var logisticsModal = document.getElementById('logisticsModal');
    var moduleCostModal = document.getElementById('moduleCostModal');
    if (event.target === logisticsModal) {
        logisticsModal.style.display = 'none';
    }
    if (event.target === moduleCostModal) {
        moduleCostModal.style.display = 'none';
    }
}

let logisticsChartInstance = null;
function initLogisticsChart() {
    const ctx = document.getElementById('logisticsBreakdownChart');
    if (!ctx) return;

    if (logisticsChartInstance) {
        logisticsChartInstance.destroy();
    }

    const freightCost = <?php echo $total_freight_cost; ?>;
    const warehousingCost = <?php echo $total_warehousing_cost; ?>;
    const accessorialCost = <?php echo $total_accessorial_cost; ?>;

    logisticsChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Freight', 'Warehousing', 'Accessorial'],
            datasets: [{
                data: [freightCost, warehousingCost, accessorialCost],
                backgroundColor: ['#3b82f6', '#8b5cf6', '#f59e0b'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return `${context.label}: $${value.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} (${pct}%)`;
                        }
                    }
                }
            },
            cutout: '60%'
        }
    });
}

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
        localStorage.setItem('moduleAnalysisView', view);
    }

    if (btnGrid) btnGrid.addEventListener('click', function() { setView('grid'); });
    if (btnTable) btnTable.addEventListener('click', function() { setView('table'); });

    // Restore saved view
    var savedView = localStorage.getItem('moduleAnalysisView') || 'grid';
    setView(savedView);

    // Card and row clicks
    document.querySelectorAll('[data-href]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.location.href = this.getAttribute('data-href');
        });
    });

    // Initialize charts
    initCharts();
});

function initCharts() {
    // Portfolio Cost Breakdown Chart
    const costBreakdownCtx = document.getElementById('costBreakdownChart');
    if (costBreakdownCtx) {
        const moduleCost = <?php echo $total_module_cost; ?>;
        const freightCost = <?php echo $total_freight_cost; ?>;
        const warehousingCost = <?php echo $total_warehousing_cost; ?>;
        const accessorialCost = <?php echo $total_accessorial_cost; ?>;

        new Chart(costBreakdownCtx, {
            type: 'doughnut',
            data: {
                labels: ['Module Cost', 'Freight', 'Warehousing', 'Accessorial'],
                datasets: [{
                    data: [moduleCost, freightCost, warehousingCost, accessorialCost],
                    backgroundColor: ['#488C9A', '#3b82f6', '#8b5cf6', '#f59e0b'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${context.label}: $${value.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} (${pct}%)`;
                            }
                        }
                    }
                },
                cutout: '55%'
            }
        });
    }
}
</script>
</body>
</html>
