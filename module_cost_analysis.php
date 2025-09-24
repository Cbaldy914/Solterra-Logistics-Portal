<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Current user info
$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'user';

/**
 * Calculate warehousing cost for a project (TOTAL).
 */
function calculateProjectWarehousingCost($conn, $project_id) {
    // Fetch warehouse info
    $stmt = $conn->prepare("
        SELECT w.id
        FROM warehouses w
        JOIN projects p ON p.warehouse_id=w.id
        WHERE p.id=?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $resWarehouse = $stmt->get_result();
    $stmt->close();

    if ($resWarehouse->num_rows < 1) {
        return 0;
    }
    $warehouse_basic = $resWarehouse->fetch_assoc();
    $warehouse_id = $warehouse_basic['id'];
    
    // Fetch cost items for this warehouse
    $cost_stmt = $conn->prepare("
        SELECT trigger_event, amount 
        FROM warehouse_cost_items 
        WHERE warehouse_id = ? AND is_active = 1
    ");
    $cost_stmt->bind_param("i", $warehouse_id);
    $cost_stmt->execute();
    $cost_result = $cost_stmt->get_result();
    
    $warehouse = ['id' => $warehouse_id, 'in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];
    while ($cost = $cost_result->fetch_assoc()) {
        switch ($cost['trigger_event']) {
            case 'entry':
                $warehouse['in_fee'] = $cost['amount'];
                break;
            case 'exit':
                $warehouse['out_fee'] = $cost['amount'];
                break;
            case 'monthly':
                $warehouse['monthly_storage_fee'] = $cost['amount'];
                break;
        }
    }
    $cost_stmt->close();

    // Count inbound deliveries
    $stmt2 = $conn->prepare("
        SELECT COUNT(*) AS total_in
        FROM deliveries
        WHERE project_id=?
          AND warehouse_arrival_date IS NOT NULL
    ");
    $stmt2->bind_param("i", $project_id);
    $stmt2->execute();
    $stmt2->bind_result($total_in);
    $stmt2->fetch();
    $stmt2->close();

    // Count outbound deliveries
    $stmt3 = $conn->prepare("
        SELECT COUNT(*) AS total_out
        FROM deliveries
        WHERE project_id=?
          AND warehouse_arrival_date IS NOT NULL
          AND left_warehouse_date IS NOT NULL
    ");
    $stmt3->bind_param("i", $project_id);
    $stmt3->execute();
    $stmt3->bind_result($total_out);
    $stmt3->fetch();
    $stmt3->close();

    $in_fee_cost  = $warehouse['in_fee']  * $total_in;
    $out_fee_cost = $warehouse['out_fee'] * $total_out;

    // Storage cost for every delivery that arrived
    $stmt4 = $conn->prepare("
        SELECT warehouse_arrival_date, left_warehouse_date
        FROM deliveries
        WHERE project_id=?
          AND warehouse_arrival_date IS NOT NULL
    ");
    $stmt4->bind_param("i", $project_id);
    $stmt4->execute();
    $res4 = $stmt4->get_result();
    $stmt4->close();

    $storage_cost_total = 0;
    while ($d = $res4->fetch_assoc()) {
        $sd = $d['warehouse_arrival_date'];
        if (empty($sd)) {
            continue;
        }
        $ed = (!empty($d['left_warehouse_date']))
                ? $d['left_warehouse_date']
                : date('Y-m-d');

        $start = new DateTime($sd);
        $end   = new DateTime($ed);

        $diff = $start->diff($end);
        $days = $diff->days + 1;

        $daily_storage_fee = ($warehouse['monthly_storage_fee'] / 30.0);
        $delivery_storage  = $days * $daily_storage_fee;

        $storage_cost_total += $delivery_storage;
    }

    return $in_fee_cost + $out_fee_cost + $storage_cost_total;
}

/**
 * Calculate YTD warehousing cost for a project (this year).
 */
function calculateProjectYTDWarehousingCost($conn, $project_id, $current_year) {
    // Same approach, but restricted to year-based logic
    $stmt = $conn->prepare("
        SELECT w.id
        FROM warehouses w
        JOIN projects p ON p.warehouse_id=w.id
        WHERE p.id=?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $warehouse_res = $stmt->get_result();
    $stmt->close();

    if ($warehouse_res->num_rows<1) {
        return 0;
    }
    $warehouse_basic = $warehouse_res->fetch_assoc();
    $warehouse_id = $warehouse_basic['id'];
    
    // Fetch cost items for this warehouse
    $cost_stmt = $conn->prepare("
        SELECT trigger_event, amount 
        FROM warehouse_cost_items 
        WHERE warehouse_id = ? AND is_active = 1
    ");
    $cost_stmt->bind_param("i", $warehouse_id);
    $cost_stmt->execute();
    $cost_result = $cost_stmt->get_result();
    
    $warehouse = ['id' => $warehouse_id, 'in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];
    while ($cost = $cost_result->fetch_assoc()) {
        switch ($cost['trigger_event']) {
            case 'entry':
                $warehouse['in_fee'] = $cost['amount'];
                break;
            case 'exit':
                $warehouse['out_fee'] = $cost['amount'];
                break;
            case 'monthly':
                $warehouse['monthly_storage_fee'] = $cost['amount'];
                break;
        }
    }
    $cost_stmt->close();

    // Count inbound deliveries (arrived this year)
    $stmt2 = $conn->prepare("
        SELECT COUNT(*) 
        FROM deliveries
        WHERE project_id=?
          AND warehouse_arrival_date IS NOT NULL
          AND YEAR(warehouse_arrival_date)=?
    ");
    $stmt2->bind_param("ii", $project_id, $current_year);
    $stmt2->execute();
    $stmt2->bind_result($total_in);
    $stmt2->fetch();
    $stmt2->close();

    // Count outbound deliveries (left this year)
    $stmt3 = $conn->prepare("
        SELECT COUNT(*)
        FROM deliveries
        WHERE project_id=?
          AND warehouse_arrival_date IS NOT NULL
          AND left_warehouse_date IS NOT NULL
          AND YEAR(left_warehouse_date)=?
    ");
    $stmt3->bind_param("ii", $project_id, $current_year);
    $stmt3->execute();
    $stmt3->bind_result($total_out);
    $stmt3->fetch();
    $stmt3->close();

    $in_fee_cost  = $warehouse['in_fee']  * $total_in;
    $out_fee_cost = $warehouse['out_fee'] * $total_out;

    // Partial-year storage cost
    $stmt4 = $conn->prepare("
        SELECT warehouse_arrival_date, left_warehouse_date
        FROM deliveries
        WHERE project_id=?
          AND warehouse_arrival_date IS NOT NULL
    ");
    $stmt4->bind_param("i", $project_id);
    $stmt4->execute();
    $res4 = $stmt4->get_result();
    $stmt4->close();

    $yr_start = new DateTime("$current_year-01-01");
    $yr_end   = new DateTime("$current_year-12-31");
    $storage_cost_total=0;

    while($d=$res4->fetch_assoc()){
        $sd = $d['warehouse_arrival_date'];
        if (empty($sd)) continue;
        $ed = (!empty($d['left_warehouse_date']))
                ? $d['left_warehouse_date']
                : date('Y-m-d');

        $start = new DateTime($sd);
        $end   = new DateTime($ed);

        // If no overlap with the year, skip
        if ($start>$yr_end || $end<$yr_start) {
            continue;
        }
        if ($start<$yr_start) $start=clone $yr_start;
        if ($end>$yr_end)     $end=clone $yr_end;

        $diff = $start->diff($end);
        $days = $diff->days+1;
        $daily_storage_fee = ($warehouse['monthly_storage_fee']/30.0);
        $storage_cost_total += ($days*$daily_storage_fee);
    }

    return $in_fee_cost + $out_fee_cost + $storage_cost_total;
}

/**
 * Calculate total (or YTD) freight + accessorial + warehousing + Solterra fee for a project.
 */
function calculateProjectTotalLogisticsCost($conn, $project_id, $filter) {
    // 1) fetch the project's solterra_fee
    $stmt = $conn->prepare("SELECT solterra_fee FROM projects WHERE id=?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($solterra_fee_db);
    $stmt->fetch();
    $stmt->close();

    $solterra_fee = floatval($solterra_fee_db ?? 0);
    $current_year = date('Y');

    // 2) gather freight & accessorial
    $sql_deliv = "SELECT freight_cost, accessorial_costs, wattage, quantity, actual_delivery_date 
                  FROM deliveries
                  WHERE project_id=?";
    if ($filter==='ytd') {
        $sql_deliv .= " AND YEAR(created_at)=?";
    }

    $stmt2 = $conn->prepare($sql_deliv);
    if ($filter==='ytd') {
        $stmt2->bind_param("ii", $project_id, $current_year);
    } else {
        $stmt2->bind_param("i", $project_id);
    }
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $stmt2->close();

    $proj_freight_cost      = 0;
    $proj_accessorial_costs = 0;
    $proj_solterra_fee      = 0;

    while($r=$res2->fetch_assoc()){
        $proj_freight_cost      += (float)$r['freight_cost'];
        $proj_accessorial_costs += (float)$r['accessorial_costs'];

        if (!empty($r['actual_delivery_date'])) {
            $wattage  = (float)$r['wattage'];
            $quantity = (float)$r['quantity'];
            $proj_solterra_fee += ($solterra_fee * ($wattage*$quantity));
        }
    }

    // 3) gather warehousing
    if ($filter==='ytd') {
        $proj_warehousing = calculateProjectYTDWarehousingCost($conn, $project_id, $current_year);
    } else {
        $proj_warehousing = calculateProjectWarehousingCost($conn, $project_id);
    }

    // 4) total
    $proj_total = $proj_freight_cost + $proj_accessorial_costs + $proj_warehousing + $proj_solterra_fee;

    return [
        'freight_cost'      => $proj_freight_cost,
        'accessorial_costs' => $proj_accessorial_costs,
        'warehousing_cost'  => $proj_warehousing,
        'solterra_fee'      => $proj_solterra_fee,
        'total_logistics_cost' => $proj_total
    ];
}

// Chosen filter
$filter = $_GET['filter'] ?? 'total';

// We'll sum over all relevant projects
$total_freight           = 0;
$total_accessorial       = 0;
$total_warehousing       = 0;
$total_solterra_fee      = 0;
$total_logistics_cost    = 0;

// Step: fetch user's projects differently if admin/global_admin or normal user
if ($role === 'admin' || $role === 'global_admin') {
    // All projects
    $sql_proj = "SELECT p.id, p.project_name, p.image_url FROM projects p";
    $paramTypes = "";
    $params     = [];
} else {
    // Only projects from their account
    $sql_proj = "
        SELECT p.id, p.project_name, p.image_url
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE cau.user_id=?
    ";
    $paramTypes = "i";
    $params     = [$user_id];
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
    $pid = $p['id'];
    $calc = calculateProjectTotalLogisticsCost($conn, $pid, $filter);

    $total_freight      += $calc['freight_cost'];
    $total_accessorial  += $calc['accessorial_costs'];
    $total_warehousing  += $calc['warehousing_cost'];
    $total_solterra_fee += $calc['solterra_fee'];
    $total_logistics_cost += $calc['total_logistics_cost'];

    $p['freight_cost']      = $calc['freight_cost'];
    $p['accessorial_costs'] = $calc['accessorial_costs'];
    $p['warehousing_cost']  = $calc['warehousing_cost'];
    $p['solterra_fee']      = $calc['solterra_fee'];
    $p['total_logistics_cost'] = $calc['total_logistics_cost'];

    $projects[] = $p;
}

$project_count = count($projects);

if ($filter==='per_project' && $project_count>0) {
    $disp_freight      = $total_freight/$project_count;
    $disp_accessorial  = $total_accessorial/$project_count;
    $disp_warehousing  = $total_warehousing/$project_count;
    $disp_solterra_fee = $total_solterra_fee/$project_count;
    $disp_total_log    = $total_logistics_cost/$project_count;
} else {
    $disp_freight      = $total_freight;
    $disp_accessorial  = $total_accessorial;
    $disp_warehousing  = $total_warehousing;
    $disp_solterra_fee = $total_solterra_fee;
    $disp_total_log    = $total_logistics_cost;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Module Cost Analysis</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        h2 {
            margin-top: 50px;
            margin-bottom: 20px;
            color: #293E4C;
            font-size: 1.8em;
            font-weight: 600;
            padding-bottom: 10px;
            border-bottom: 3px solid #488C9A;
            position: relative;
        }
        h2::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            width: 60px;
            height: 3px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 2px;
        }
        .cost-overview {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            margin-bottom: 50px;
        }
        .cost-row {
            display: flex;
            width: 100%;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .cost-metric {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            margin: 8px;
            border-radius: 12px;
            text-align: center;
            min-width: 200px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            border: 1px solid #dee2e6;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .cost-metric:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }
        .cost-metric h3 {
            margin: 0 0 10px 0;
            font-weight: 600;
            color: #293E4C;
            font-size: 1rem;
        }
        .cost-metric p {
            margin: 0;
            font-size: 1.4rem;
            font-weight: bold;
            color: #488C9A;
        }
        .cost-metric--total {
            max-width: 400px;
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            color: white;
        }
        .cost-metric--total h3,
        .cost-metric--total p {
            color: white;
        }
        .filter-form {
            margin: 15px 0 25px 0;
            padding: 15px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 1px solid #dee2e6;
            width: auto !important;
            max-width: fit-content;
            display: inline-block;
        }
        .filter-form label {
            margin-right: 15px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 6px;
            transition: background-color 0.2s ease;
            font-size: 0.9em;
        }
        .filter-form label:hover {
            background-color: #f8f9fa;
        }
        .filter-form input[type="radio"] {
            margin: 0;
        }
        .projects-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
            padding: 0;
        }
        .project-item {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .project-item:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.15);
        }
        .project-image {
            width: 100%;
            height: 200px;
            overflow: hidden;
            position: relative;
        }
        .project-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        .project-item:hover .project-image img {
            transform: scale(1.05);
        }
        .project-title {
            padding: 20px 20px 15px 20px;
            background: #ffffff;
            border-bottom: 1px solid #f1f3f4;
        }
        .project-title h3 {
            margin: 0;
            font-size: 1.4em;
            color: #293E4C;
            font-weight: 600;
            text-align: center;
        }
        .project-title h3 a {
            text-decoration: none;
            color: inherit;
        }
        .project-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(72, 140, 154, 0.9), rgba(58, 110, 127, 0.9));
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 3;
        }
        .project-item:hover .project-overlay {
            opacity: 1;
        }
        .project-overlay-text {
            color: white;
            font-size: 1.2em;
            font-weight: 600;
            text-align: center;
        }
        .project-content {
            background: #fafbfc;
            width: 85%
        }
        .project-details {
            background: #ffffff;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #f1f3f4;
        }
        .project-details p {
            margin: 12px 0;
            color: #495057;
            font-size: 0.95em;
            line-height: 1.6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 24px;
        }
        .project-details strong {
            color: #293E4C;
            font-weight: 600;
        }
        .cost-value {
            font-weight: 700;
            color: #488C9A;
            font-size: 1.1em;
        }
        .cost-efficiency-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 10px;
        }
        .efficiency-excellent {
            background: #d4edda;
            color: #155724;
        }
        .efficiency-good {
            background: #fff3cd;
            color: #856404;
        }
        .efficiency-average {
            background: #f8d7da;
            color: #721c24;
        }
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
            margin-top: 10px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
        @media (max-width: 768px) {
            .projects-container {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            .cost-row {
                flex-direction: column;
                align-items: center;
            }
            .cost-metric {
                min-width: 250px;
                margin: 5px 0;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Cost Overview']); ?>
    <h1>Cost Overview</h1>
    <form method="GET" id="filter-form" class="filter-form">
        <label>
            <input type="radio" name="filter" value="total" onchange="this.form.submit();"
                   <?php if ($filter==='total') echo 'checked'; ?>>
            📊 Total Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="ytd" onchange="this.form.submit();"
                   <?php if ($filter==='ytd') echo 'checked'; ?>>
            📅 Year-to-Date Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="per_project" onchange="this.form.submit();"
                   <?php if ($filter==='per_project') echo 'checked'; ?>>
            📈 Average per Project
        </label>
    </form>

    <!-- Enhanced cost-overview -->
    <div class="cost-overview">
        <?php if ($filter==='per_project'): ?>
            <!-- Row 1: single average total cost per project -->
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3>📊 Average Logistics Cost per Project</h3>
                    <p>$<?php echo number_format($disp_total_log,2); ?></p>
                </div>
            </div>
            <div class="cost-row">
                <div class="cost-metric">
                    <h3>🚛 Average Freight Cost</h3>
                    <p>$<?php echo number_format($disp_freight,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>📋 Average Accessorial Cost</h3>
                    <p>$<?php echo number_format($disp_accessorial,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>🏢 Average Warehousing Cost</h3>
                    <p>$<?php echo number_format($disp_warehousing,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>⚡ Average Solterra Fee</h3>
                    <p>$<?php echo number_format($disp_solterra_fee,2); ?></p>
                </div>
            </div>
        <?php else: ?>
            <!-- Row 1: single total cost -->
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3><?php echo ($filter==='ytd') 
                            ? '💸 Total Logistics Cost (YTD)'
                            : '💸 Total Logistics Cost'; ?></h3>
                    <p>$<?php echo number_format($disp_total_log,2); ?></p>
                </div>
            </div>
            <!-- Row 2 -->
            <div class="cost-row">
                <div class="cost-metric">
                    <h3><?php echo ($filter==='ytd')
                            ? '🚛 Freight Cost (YTD)'
                            : '🚛 Freight Cost'; ?></h3>
                    <p>$<?php echo number_format($disp_freight,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3><?php echo ($filter==='ytd')
                            ? '📋 Accessorial Cost (YTD)'
                            : '📋 Accessorial Cost'; ?></h3>
                    <p>$<?php echo number_format($disp_accessorial,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3><?php echo ($filter==='ytd')
                            ? '🏢 Warehousing Cost (YTD)'
                            : '🏢 Warehousing Cost'; ?></h3>
                    <p>$<?php echo number_format($disp_warehousing,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3><?php echo ($filter==='ytd')
                            ? '⚡ Solterra Fee (YTD)'
                            : '⚡ Solterra Fee'; ?></h3>
                    <p>$<?php echo number_format($disp_solterra_fee,2); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <h2>💼 Logistics Costs per Project</h2>
    <div class="projects-container">
        <?php if (!empty($projects)): ?>
            <?php foreach ($projects as $proj): 
                // Calculate cost efficiency indicator
                $avg_cost_per_project = $total_logistics_cost / count($projects);
                $efficiency_class = 'efficiency-average';
                $efficiency_text = 'Average';
                
                if ($proj['total_logistics_cost'] < $avg_cost_per_project * 0.8) {
                    $efficiency_class = 'efficiency-excellent';
                    $efficiency_text = 'Cost Efficient';
                } elseif ($proj['total_logistics_cost'] < $avg_cost_per_project * 1.2) {
                    $efficiency_class = 'efficiency-good';
                    $efficiency_text = 'Good Value';
                }
            ?>
                <div class="project-item" onclick="window.location.href='project_cost_details?project_id=<?php echo $proj['id']; ?>'">
                    <div class="project-title">
                        <h3>
                            <a href="project_cost_details?project_id=<?php echo $proj['id']; ?>">
                                <?php echo htmlspecialchars($proj['project_name']); ?>
                            </a>
                        </h3>
                    </div>
                    <div class="project-image">
                        <img src="<?php echo htmlspecialchars($proj['image_url']); ?>" alt="<?php echo htmlspecialchars($proj['project_name']); ?>">
                        <div class="project-overlay">
                            <div class="project-overlay-text">View Cost Details</div>
                        </div>
                    </div>
                    <div class="project-content">
                        <div class="project-details">
                            <p>
                                <strong>💸 <?php echo ($filter==='ytd') ? 'Total Cost (YTD)' : 'Total Logistics Cost'; ?></strong>
                                <span class="cost-value">$<?php echo number_format($proj['total_logistics_cost'],2); ?></span>
                            </p>
                            <p>
                                <strong>🚛 <?php echo ($filter==='ytd') ? 'Freight (YTD)' : 'Freight Cost'; ?></strong>
                                <span class="cost-value">$<?php echo number_format($proj['freight_cost'],2); ?></span>
                            </p>
                            <p>
                                <strong>📋 <?php echo ($filter==='ytd') ? 'Accessorial (YTD)' : 'Accessorial Cost'; ?></strong>
                                <span class="cost-value">$<?php echo number_format($proj['accessorial_costs'],2); ?></span>
                            </p>
                            <p>
                                <strong>🏢 <?php echo ($filter==='ytd') ? 'Warehousing (YTD)' : 'Warehousing Cost'; ?></strong>
                                <span class="cost-value">$<?php echo number_format($proj['warehousing_cost'],2); ?></span>
                            </p>
                            <p>
                                <strong>⚡ <?php echo ($filter==='ytd') ? 'Solterra Fee (YTD)' : 'Solterra Fee'; ?></strong>
                                <span class="cost-value">$<?php echo number_format($proj['solterra_fee'],2); ?></span>
                            </p>
                        </div>
                        <?php if (count($projects) > 1): ?>
                            <div class="cost-efficiency-badge <?php echo $efficiency_class; ?>">
                                <?php echo $efficiency_text; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #6c757d; grid-column: 1/-1;">
                <h3>📊 No Projects Found</h3>
                <p>No projects with logistics data are available for the selected filter.</p>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
