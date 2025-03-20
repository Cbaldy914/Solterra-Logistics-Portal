<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Database connection
$servername   = "localhost";
$db_username  = "SolterraSolutions"; // Replace with your database username
$db_password  = "CompanyAdmin!";     // Replace with your database password
$dbname       = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch user role and ID
$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

/**
 * Calculate warehousing cost for a project (TOTAL or YTD).
 */
function calculateProjectWarehousingCost($conn, $project_id) {
    // Fetch warehouse info
    $stmt = $conn->prepare("
        SELECT w.id AS warehouse_id, w.in_fee, w.out_fee, w.monthly_storage_fee
        FROM warehouses w
        INNER JOIN projects p ON p.warehouse_id = w.id
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $warehouse_result = $stmt->get_result();
    $stmt->close();

    if ($warehouse_result->num_rows < 1) {
        return 0; // no warehouse
    }
    $warehouse = $warehouse_result->fetch_assoc();

    // Count deliveries that arrived at the warehouse
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_deliveries
        FROM deliveries
        WHERE project_id = ? AND warehouse_arrival_date IS NOT NULL
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($total_deliveries);
    $stmt->fetch();
    $stmt->close();

    // Count deliveries that actually left the warehouse
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_deliveries_out
        FROM deliveries
        WHERE project_id = ? 
          AND left_warehouse_date IS NOT NULL
          AND warehouse_arrival_date IS NOT NULL
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($total_deliveries_out);
    $stmt->fetch();
    $stmt->close();

    $in_fee_cost  = $warehouse['in_fee']  * $total_deliveries;
    $out_fee_cost = $warehouse['out_fee'] * $total_deliveries_out;

    // Sum up storage cost for all deliveries that have been to warehouse
    $stmt = $conn->prepare("
        SELECT warehouse_arrival_date, left_warehouse_date
        FROM deliveries
        WHERE project_id = ? AND warehouse_arrival_date IS NOT NULL
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $all_deliveries_result = $stmt->get_result();
    $stmt->close();

    $total_storage_cost = 0;
    while ($delivery = $all_deliveries_result->fetch_assoc()) {
        $start_date = $delivery['warehouse_arrival_date'];
        if (empty($start_date)) {
            continue;
        }
        $end_date   = (!empty($delivery['left_warehouse_date'])) 
                        ? $delivery['left_warehouse_date']
                        : date('Y-m-d');
        $sd = new DateTime($start_date);
        $ed = new DateTime($end_date);

        $interval        = $sd->diff($ed);
        $days_in_storage = $interval->days + 1;

        $daily_storage_fee = $warehouse['monthly_storage_fee'] / 30.0;
        $storage_cost      = $daily_storage_fee * $days_in_storage;
        $total_storage_cost += $storage_cost;
    }

    return $in_fee_cost + $out_fee_cost + $total_storage_cost;
}

/**
 * Calculate YTD warehousing cost for a project.
 */
function calculateProjectYTDWarehousingCost($conn, $project_id, $current_year) {
    // Fetch warehouse info
    $stmt = $conn->prepare("
        SELECT w.id AS warehouse_id, w.in_fee, w.out_fee, w.monthly_storage_fee
        FROM warehouses w
        INNER JOIN projects p ON p.warehouse_id = w.id
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows < 1) {
        return 0;
    }
    $warehouse = $res->fetch_assoc();

    // Count deliveries that arrived in the warehouse this year
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_deliveries
        FROM deliveries
        WHERE project_id = ?
          AND warehouse_arrival_date IS NOT NULL
          AND YEAR(warehouse_arrival_date)=?
    ");
    $stmt->bind_param("ii", $project_id, $current_year);
    $stmt->execute();
    $stmt->bind_result($total_deliveries);
    $stmt->fetch();
    $stmt->close();

    // Count deliveries that left warehouse this year
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_deliveries_out
        FROM deliveries
        WHERE project_id = ?
          AND left_warehouse_date IS NOT NULL
          AND YEAR(left_warehouse_date)=?
          AND warehouse_arrival_date IS NOT NULL
    ");
    $stmt->bind_param("ii", $project_id, $current_year);
    $stmt->execute();
    $stmt->bind_result($total_deliveries_out);
    $stmt->fetch();
    $stmt->close();

    $in_fee_cost  = $warehouse['in_fee']  * $total_deliveries;
    $out_fee_cost = $warehouse['out_fee'] * $total_deliveries_out;

    // Calculate partial-year storage costs
    $stmt = $conn->prepare("
        SELECT warehouse_arrival_date, left_warehouse_date
        FROM deliveries
        WHERE project_id = ?
          AND warehouse_arrival_date IS NOT NULL
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $resDel = $stmt->get_result();
    $stmt->close();

    $year_start = new DateTime("$current_year-01-01");
    $year_end   = new DateTime("$current_year-12-31");
    $total_storage_cost = 0;
    while ($d = $resDel->fetch_assoc()) {
        if (empty($d['warehouse_arrival_date'])) continue;
        $sd = new DateTime($d['warehouse_arrival_date']);
        $ed = (!empty($d['left_warehouse_date']))
                ? new DateTime($d['left_warehouse_date'])
                : new DateTime();

        // If no overlap with current year, skip
        if ($sd > $year_end || $ed < $year_start) {
            continue;
        }
        if ($sd < $year_start) $sd = clone $year_start;
        if ($ed > $year_end)   $ed = clone $year_end;

        $interval        = $sd->diff($ed);
        $days_in_storage = $interval->days + 1;

        $daily_storage_fee = $warehouse['monthly_storage_fee']/30.0;
        $storage_cost      = $daily_storage_fee * $days_in_storage;
        $total_storage_cost += $storage_cost;
    }

    return $in_fee_cost + $out_fee_cost + $total_storage_cost;
}

/**
 * Calculate total (or YTD) freight + accessorial + warehousing + SOLTERRA FEE for a project.
 */
function calculateProjectTotalLogisticsCost($conn, $project_id, $filter) {
    // Step 1: get the project's solterra_fee from DB
    $stmt = $conn->prepare("SELECT solterra_fee FROM projects WHERE id=?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($solterra_fee_db);
    $stmt->fetch();
    $stmt->close();
    $solterra_fee = floatval($solterra_fee_db ?? 0);

    // Step 2: gather freight & accessorial (TOTAL or YTD)
    $current_year = date('Y');
    $project_freight_cost     = 0;
    $project_accessorial_cost = 0;

    $sql_deliveries = "SELECT freight_cost, accessorial_costs, wattage, quantity 
                       FROM deliveries
                       WHERE project_id=?";

    if ($filter == 'ytd') {
        $sql_deliveries .= " AND YEAR(created_at)=?";
    }

    $stmt2 = $conn->prepare($sql_deliveries);
    if ($filter == 'ytd') {
        $stmt2->bind_param("ii", $project_id, $current_year);
    } else {
        $stmt2->bind_param("i", $project_id);
    }
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $stmt2->close();

    $project_solterra_fee = 0; // sum over deliveries
    while ($row = $res2->fetch_assoc()) {
        $project_freight_cost     += (float)$row['freight_cost'];
        $project_accessorial_cost += (float)$row['accessorial_costs'];

        // compute solterra fee for this delivery
        $wattage  = (float)$row['wattage'];
        $quantity = (float)$row['quantity'];
        $deliveryFee = $solterra_fee * ($wattage * $quantity);
        $project_solterra_fee += $deliveryFee;
    }

    // Step 3: gather warehousing cost
    if ($filter == 'ytd') {
        $project_warehousing_cost = calculateProjectYTDWarehousingCost($conn, $project_id, $current_year);
    } else {
        $project_warehousing_cost = calculateProjectWarehousingCost($conn, $project_id);
    }

    // Step 4: total cost
    $project_total_logistics_cost = 
          $project_freight_cost 
        + $project_accessorial_cost 
        + $project_warehousing_cost
        + $project_solterra_fee;

    return [
        'freight_cost'       => $project_freight_cost,
        'accessorial_costs'  => $project_accessorial_cost,
        'warehousing_cost'   => $project_warehousing_cost,
        'solterra_fee'       => $project_solterra_fee,   // new
        'total_logistics_cost' => $project_total_logistics_cost
    ];
}

// Determine user’s chosen filter
$filter = $_GET['filter'] ?? 'total';

// For summation across all user’s projects
$total_freight_cost       = 0;
$total_accessorial_costs  = 0;
$total_warehousing_cost   = 0;
$total_solterra_fee       = 0;  // new aggregator
$total_logistics_costs    = 0;

// Fetch projects for the logged-in user
$sql_projects = "
    SELECT p.id, p.project_name, p.image_url
    FROM projects p
    WHERE p.user_id = ?
";
$stmt = $conn->prepare($sql_projects);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result_projects = $stmt->get_result();
$stmt->close();

$project_count = $result_projects->num_rows;

$projects = [];
while ($pr = $result_projects->fetch_assoc()) {
    $pid = $pr['id'];
    // Calc cost
    $c = calculateProjectTotalLogisticsCost($conn, $pid, $filter);

    // accumulate
    $total_freight_cost      += $c['freight_cost'];
    $total_accessorial_costs += $c['accessorial_costs'];
    $total_warehousing_cost  += $c['warehousing_cost'];
    $total_solterra_fee      += $c['solterra_fee'];
    $total_logistics_costs   += $c['total_logistics_cost'];

    // store in project array
    $pr['freight_cost']      = $c['freight_cost'];
    $pr['accessorial_costs'] = $c['accessorial_costs'];
    $pr['warehousing_cost']  = $c['warehousing_cost'];
    $pr['solterra_fee']      = $c['solterra_fee'];
    $pr['total_logistics_cost'] = $c['total_logistics_cost'];

    $projects[] = $pr;
}

// Decide how to present the top-level cost overview
if ($filter == 'per_project' && $project_count > 0) {
    // average per project
    $display_freight_cost     = $total_freight_cost     / $project_count;
    $display_accessorial_cost = $total_accessorial_costs / $project_count;
    $display_warehousing_cost = $total_warehousing_cost  / $project_count;
    $display_solterra_fee     = $total_solterra_fee      / $project_count;
    $display_total_logistics  = $total_logistics_costs   / $project_count;
} else {
    // show totals
    $display_freight_cost     = $total_freight_cost;
    $display_accessorial_cost = $total_accessorial_costs;
    $display_warehousing_cost = $total_warehousing_cost;
    $display_solterra_fee     = $total_solterra_fee;
    $display_total_logistics  = $total_logistics_costs;
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
        /* Single "big" block for total */
        .cost-metric--total {
            max-width: 400px;
        }

    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Cost Overview</h1>
    <form method="GET" id="filter-form">
        <label>
            <input type="radio" name="filter" value="total"
                   onchange="this.form.submit();"
                   <?php if ($filter == 'total') echo 'checked'; ?>>
            Total Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="ytd"
                   onchange="this.form.submit();"
                   <?php if ($filter == 'ytd') echo 'checked'; ?>>
            Year-to-Date Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="per_project"
                   onchange="this.form.submit();"
                   <?php if ($filter == 'per_project') echo 'checked'; ?>>
            Average per Project
        </label>
    </form>

    <!-- Two-row cost-overview -->
    <div class="cost-overview">
        <?php if ($filter == 'per_project'): ?>
            <!-- Row 1: single average total cost per project -->
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3>Average Logistics Cost per Project</h3>
                    <p>$<?php echo number_format($display_total_logistics, 2); ?></p>
                </div>
            </div>
            <!-- Row 2: Freed up for the other 4 metrics -->
            <div class="cost-row">
                <div class="cost-metric">
                    <h3>Average Freight Cost per Project</h3>
                    <p>$<?php echo number_format($display_freight_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>Average Accessorial Cost per Project</h3>
                    <p>$<?php echo number_format($display_accessorial_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>Average Warehousing Cost per Project</h3>
                    <p>$<?php echo number_format($display_warehousing_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>Average Solterra Fee per Project</h3>
                    <p>$<?php echo number_format($display_solterra_fee, 2); ?></p>
                </div>
            </div>

        <?php else: ?>
            <!-- Row 1: single total cost -->
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3><?php echo ($filter == 'ytd') 
                            ? 'Total Logistics Cost (YTD)' 
                            : 'Total Logistics Cost'; ?>
                    </h3>
                    <p>$<?php echo number_format($display_total_logistics, 2); ?></p>
                </div>
            </div>
            <!-- Row 2: Freed up for the other 4 metrics -->
            <div class="cost-row">
                <div class="cost-metric">
                    <h3><?php echo ($filter == 'ytd')
                            ? 'Freight Cost (YTD)'
                            : 'Freight Cost'; ?>
                    </h3>
                    <p>$<?php echo number_format($display_freight_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3><?php echo ($filter == 'ytd')
                            ? 'Accessorial Cost (YTD)'
                            : 'Accessorial Cost'; ?>
                    </h3>
                    <p>$<?php echo number_format($display_accessorial_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3><?php echo ($filter == 'ytd')
                            ? 'Warehousing Cost (YTD)'
                            : 'Warehousing Cost'; ?>
                    </h3>
                    <p>$<?php echo number_format($display_warehousing_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3><?php echo ($filter == 'ytd')
                            ? 'Solterra Fee (YTD)'
                            : 'Solterra Fee'; ?>
                    </h3>
                    <p>$<?php echo number_format($display_solterra_fee, 2); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <h2>Logistics Costs per Project:</h2>
    <div class="projects-container">
        <?php if (count($projects) > 0): ?>
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
                        <!-- Show all 5 cost fields including Solterra Fee -->
                        <p>
                            <strong>
                                <?php echo ($filter == 'ytd') 
                                        ? 'Total Logistics Cost (YTD)' 
                                        : 'Total Logistics Cost'; ?>:
                            </strong> 
                            $<?php echo number_format($proj['total_logistics_cost'], 2); ?>
                        </p>
                        <p>
                            <strong>
                                <?php echo ($filter == 'ytd') 
                                        ? 'Freight Cost (YTD)' 
                                        : 'Freight Cost'; ?>:
                            </strong> 
                            $<?php echo number_format($proj['freight_cost'], 2); ?>
                        </p>
                        <p>
                            <strong>
                                <?php echo ($filter == 'ytd') 
                                        ? 'Accessorial Cost (YTD)' 
                                        : 'Accessorial Cost'; ?>:
                            </strong> 
                            $<?php echo number_format($proj['accessorial_costs'], 2); ?>
                        </p>
                        <p>
                            <strong>
                                <?php echo ($filter == 'ytd') 
                                        ? 'Warehousing Cost (YTD)' 
                                        : 'Warehousing Cost'; ?>:
                            </strong> 
                            $<?php echo number_format($proj['warehousing_cost'], 2); ?>
                        </p>
                        <p>
                            <strong>
                                <?php echo ($filter == 'ytd') 
                                        ? 'Solterra Fee (YTD)' 
                                        : 'Solterra Fee'; ?>:
                            </strong>
                            $<?php echo number_format($proj['solterra_fee'], 2); ?>
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
