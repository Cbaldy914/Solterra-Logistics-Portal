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
        SELECT w.id, w.in_fee, w.out_fee, w.monthly_storage_fee
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
    $warehouse = $resWarehouse->fetch_assoc();

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
        SELECT w.id, w.in_fee, w.out_fee, w.monthly_storage_fee
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
    $warehouse = $warehouse_res->fetch_assoc();

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
            margin-bottom: 0px;
        }
        .cost-overview {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            margin-bottom: 30px;
        }
        .cost-row {
            display: flex;
            width: 100%;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .cost-metric {
            background: #f9f9f9;
            padding: 15px;
            margin: 5px;
            border-radius: 8px;
            text-align: center;
            min-width: 180px;
        }
        .cost-metric h3 {
            margin: 0;
            font-weight: bold;
        }
        .cost-metric p {
            margin: 0;
            font-size: 1.2rem;
        }
        .cost-metric--total {
            max-width: 400px;
        }
        .projects-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .project-item {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 15px;
        }
        .project-item h3 {
            margin-top: 0;
        }
        .project-image img {
            max-width: 100%;
            border-radius: 4px;
        }
        .project-details p {
            margin: 4px 0;
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
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb">
        <a href="<?php echo isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'global_admin') ? 'admin_dashboard.php' : 'dashboard.php'; ?>">Dashboard</a>
        <span class="separator">&raquo;</span>
        <span>Cost Overview</span>
    </div>
    <h1>Cost Overview</h1>
    <form method="GET" id="filter-form">
        <label>
            <input type="radio" name="filter" value="total" onchange="this.form.submit();"
                   <?php if ($filter==='total') echo 'checked'; ?>>
            Total Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="ytd" onchange="this.form.submit();"
                   <?php if ($filter==='ytd') echo 'checked'; ?>>
            Year-to-Date Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="per_project" onchange="this.form.submit();"
                   <?php if ($filter==='per_project') echo 'checked'; ?>>
            Average per Project
        </label>
    </form>

    <!-- Two-row cost-overview -->
    <div class="cost-overview">
        <?php if ($filter==='per_project'): ?>
            <!-- Row 1: single average total cost per project -->
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3>Average Logistics Cost per Project</h3>
                    <p>$<?php echo number_format($disp_total_log,2); ?></p>
                </div>
            </div>
            <div class="cost-row">
                <div class="cost-metric">
                    <h3>Average Freight Cost</h3>
                    <p>$<?php echo number_format($disp_freight,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>Average Accessorial Cost</h3>
                    <p>$<?php echo number_format($disp_accessorial,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>Average Warehousing Cost</h3>
                    <p>$<?php echo number_format($disp_warehousing,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>Average Solterra Fee</h3>
                    <p>$<?php echo number_format($disp_solterra_fee,2); ?></p>
                </div>
            </div>
        <?php else: ?>
            <!-- Row 1: single total cost -->
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3><?php echo ($filter==='ytd') 
                            ? 'Total Logistics Cost (YTD)'
                            : 'Total Logistics Cost'; ?></h3>
                    <p>$<?php echo number_format($disp_total_log,2); ?></p>
                </div>
            </div>
            <!-- Row 2 -->
            <div class="cost-row">
                <div class="cost-metric">
                    <h3><?php echo ($filter==='ytd')
                            ? 'Freight Cost (YTD)'
                            : 'Freight Cost'; ?></h3>
                    <p>$<?php echo number_format($disp_freight,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3><?php echo ($filter==='ytd')
                            ? 'Accessorial Cost (YTD)'
                            : 'Accessorial Cost'; ?></h3>
                    <p>$<?php echo number_format($disp_accessorial,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3><?php echo ($filter==='ytd')
                            ? 'Warehousing Cost (YTD)'
                            : 'Warehousing Cost'; ?></h3>
                    <p>$<?php echo number_format($disp_warehousing,2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3><?php echo ($filter==='ytd')
                            ? 'Solterra Fee (YTD)'
                            : 'Solterra Fee'; ?></h3>
                    <p>$<?php echo number_format($disp_solterra_fee,2); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <h2>Logistics Costs per Project:</h2>
    <div class="projects-container">
        <?php if (!empty($projects)): ?>
            <?php foreach ($projects as $proj): ?>
                <div class="project-item">
                    <h3>
                        <a href="project_cost_details?project_id=<?php echo $proj['id']; ?>">
                            <?php echo htmlspecialchars($proj['project_name']); ?>
                        </a>
                    </h3>
                    <div class="project-image">
                        <a href="project_cost_details?project_id=<?php echo $proj['id']; ?>">
                            <img src="<?php echo htmlspecialchars($proj['image_url']); ?>" alt="Project Image">
                        </a>
                    </div>
                    <div class="project-details">
                        <p>
                            <strong>
                                <?php echo ($filter==='ytd')
                                        ? 'Total Logistics Cost (YTD)'
                                        : 'Total Logistics Cost'; ?>:
                            </strong>
                            $<?php echo number_format($proj['total_logistics_cost'],2); ?>
                        </p>
                        <p>
                            <strong>
                                <?php echo ($filter==='ytd')
                                        ? 'Freight Cost (YTD)'
                                        : 'Freight Cost'; ?>:
                            </strong>
                            $<?php echo number_format($proj['freight_cost'],2); ?>
                        </p>
                        <p>
                            <strong>
                                <?php echo ($filter==='ytd')
                                        ? 'Accessorial Cost (YTD)'
                                        : 'Accessorial Cost'; ?>:
                            </strong>
                            $<?php echo number_format($proj['accessorial_costs'],2); ?>
                        </p>
                        <p>
                            <strong>
                                <?php echo ($filter==='ytd')
                                        ? 'Warehousing Cost (YTD)'
                                        : 'Warehousing Cost'; ?>:
                            </strong>
                            $<?php echo number_format($proj['warehousing_cost'],2); ?>
                        </p>
                        <p>
                            <strong>
                                <?php echo ($filter==='ytd')
                                        ? 'Solterra Fee (YTD)'
                                        : 'Solterra Fee'; ?>:
                            </strong>
                            $<?php echo number_format($proj['solterra_fee'],2); ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No projects found.</p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
