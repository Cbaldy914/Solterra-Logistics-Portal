<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Validate project ID
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);
$user_id    = $_SESSION['user_id'];
$role       = $_SESSION['role'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch project details and ensure the user has access
$project_name = "";
if ($role == 'admin') {
    // If admin, just check if project exists
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($project_name);
    $stmt->fetch();
    $stmt->close();

    if (!$project_name) {
        die("Project not found.");
    }
} else {
    // If regular user, check if they own this project (or account-based logic)
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($project_name);
    $stmt->fetch();
    $stmt->close();

    if (!$project_name) {
        die("You do not have access to this project.");
    }
}

// Fetch the Solterra Fee from the projects table
$stmt2 = $conn->prepare("SELECT solterra_fee FROM projects WHERE id=?");
$stmt2->bind_param("i", $project_id);
$stmt2->execute();
$stmt2->bind_result($solterra_fee_from_db);
$stmt2->fetch();
$stmt2->close();

$solterra_fee = floatval($solterra_fee_from_db ?? 0);

// TIME FILTER LOGIC
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";
$time_filter  = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date     = isset($_GET['ref_date']) ? $_GET['ref_date'] : date('Y-m-d');

$dateCondition = "";
$paramTypes    = "i"; // for project_id
$params        = [$project_id];

$dateLabel = "All Deliveries";
$prev_date = "";
$next_date = "";

if ($time_filter === 'day') {
    $dateCondition = " AND DATE($filterColumn) = ?";
    $paramTypes   .= "s";
    $params[]      = $ref_date;

    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($ref_date . " -1 day"));
    $next_date = date('Y-m-d', strtotime($ref_date . " +1 day"));

} elseif ($time_filter === 'week') {
    $timestamp   = strtotime($ref_date);
    $dayOfWeek   = date('w', $timestamp);
    $startOfWeek = date('Y-m-d', strtotime("-{$dayOfWeek} days", $timestamp));
    $endOfWeek   = date('Y-m-d', strtotime("+" . (6 - $dayOfWeek) . " days", $timestamp));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfWeek;
    $params[]      = $endOfWeek;

    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime($startOfWeek . " -7 days"));
    $next_date = date('Y-m-d', strtotime($startOfWeek . " +7 days"));

} elseif ($time_filter === 'month') {
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth   = date('Y-m-t', strtotime($ref_date));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfMonth;
    $params[]      = $endOfMonth;

    $dateLabel = date('F Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($startOfMonth . " -1 month"));
    $next_date = date('Y-m-d', strtotime($startOfMonth . " +1 month"));
}

// STATUS FILTER
$status_filter   = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$statusCondition = "";
if (!empty($status_filter)) {
    $statusCondition = " AND status_of_delivery = ?";
    $paramTypes     .= "s";
    $params[]        = $status_filter;
}

// Additional "filter" logic
$filter        = $_GET['filter'] ?? 'total';
$current_year  = date('Y');
$ytdCondition  = "";

if ($filter == 'ytd') {
    $ytdCondition = " AND YEAR(created_at) = ?";
    $paramTypes  .= "i";
    $params[]     = $current_year;
}

// Build final deliveries query
$sql_deliveries = "
    SELECT *
    FROM deliveries
    WHERE project_id = ?
          $ytdCondition
          $dateCondition
          $statusCondition
    ORDER BY $filterColumn DESC
";

$stmt_deliveries = $conn->prepare($sql_deliveries);
$stmt_deliveries->bind_param($paramTypes, ...$params);
$stmt_deliveries->execute();
$deliveries_result = $stmt_deliveries->get_result();
$stmt_deliveries->close();

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

$warehouse = $warehouse_result->num_rows > 0
    ? $warehouse_result->fetch_assoc()
    : null;

// Helper function for warehousing cost
function calculateDeliveryWarehousingCost($delivery, $warehouse) {
    if (!$warehouse || empty($delivery['warehouse_arrival_date'])) {
        return 0;
    }
    $in_fee  = $warehouse['in_fee'];
    $out_fee = (!empty($delivery['left_warehouse_date'])) ? $warehouse['out_fee'] : 0;

    $start_date = new DateTime($delivery['warehouse_arrival_date']);
    $end_date   = !empty($delivery['left_warehouse_date'])
                    ? new DateTime($delivery['left_warehouse_date'])
                    : new DateTime();

    $interval = $start_date->diff($end_date);
    $days_in_storage = $interval->days + 1;
    $daily_storage_fee= $warehouse['monthly_storage_fee'] / 30;

    return $in_fee + ($daily_storage_fee * $days_in_storage) + $out_fee;
}

// Calculate Totals
$total_freight_cost      = 0;
$total_accessorial_costs = 0;
$total_warehousing_cost  = 0;
$total_solterra_fee      = 0;
$total_logistics_cost    = 0;

$total_quantity         = 0;
$total_wattage_quantity = 0;

$deliveries = [];

while ($delivery = $deliveries_result->fetch_assoc()) {
    $freight_cost      = (float)$delivery['freight_cost'];
    $accessorial_costs = (float)$delivery['accessorial_costs'];
    $quantity          = (int)$delivery['quantity'];
    $wattage           = (float)$delivery['wattage'];

    $total_freight_cost      += $freight_cost;
    $total_accessorial_costs += $accessorial_costs;

    $total_quantity         += $quantity;
    $total_wattage_quantity += ($quantity * $wattage);

    // Warehousing cost
    $warehousing_cost = calculateDeliveryWarehousingCost($delivery, $warehouse);
    $total_warehousing_cost += $warehousing_cost;

    // Solterra fee if actually delivered
    if (!empty($delivery['actual_delivery_date'])) {
        $solterraFeeForThisDelivery = $solterra_fee * ($wattage * $quantity);
    } else {
        $solterraFeeForThisDelivery = 0;
    }
    $total_solterra_fee += $solterraFeeForThisDelivery;

    // Summation
    $line_total = $freight_cost + $accessorial_costs + $warehousing_cost + $solterraFeeForThisDelivery;
    $total_logistics_cost += $line_total;

    // Store line-item details
    $delivery['warehousing_cost']     = $warehousing_cost;
    $delivery['solterra_fee']         = $solterraFeeForThisDelivery;
    $delivery['total_logistics_cost'] = $line_total;

    // Format date fields
    $delivery['warehouse_arrival_date_formatted'] = !empty($delivery['warehouse_arrival_date'])
        ? htmlspecialchars($delivery['warehouse_arrival_date'])
        : 'N/A';
    $delivery['actual_delivery_date_formatted'] = !empty($delivery['actual_delivery_date'])
        ? htmlspecialchars($delivery['actual_delivery_date'])
        : 'N/A';

    $deliveries[] = $delivery;
}

// Price per Watt / Price per Module
if ($filter == 'price_per_watt') {
    if ($total_wattage_quantity > 0) {
        $price_per_watt = $total_logistics_cost / $total_wattage_quantity;
    } else {
        $price_per_watt = 0;
    }
} elseif ($filter == 'price_per_module') {
    if ($total_quantity > 0) {
        $price_per_module = $total_logistics_cost / $total_quantity;
    } else {
        $price_per_module = 0;
    }
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] == 1) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=cost_details.csv');
    $output = fopen('php://output', 'w');

    // CSV headers (Supplier -> removed, BOL# -> added)
    fputcsv($output, array(
        'BOL#',
        'Wattage',
        'Quantity',
        'Status of Delivery',
        'Warehouse Arrival Date',
        'Delivered to Site Date',
        'Warehousing Cost',
        'Freight Cost',
        'Accessorial Cost',
        'Solterra Fee'
    ));

    // Rows
    foreach ($deliveries as $d) {
        fputcsv($output, array(
            $d['bol_number'] ?? '',                     // BOL# instead of Supplier
            $d['wattage'] ?? '',
            $d['quantity'] ?? '',
            $d['status_of_delivery'] ?? '',
            $d['warehouse_arrival_date_formatted'],
            $d['actual_delivery_date_formatted'],
            number_format($d['warehousing_cost'], 2),
            number_format($d['freight_cost'], 2),
            number_format($d['accessorial_costs'], 2),
            number_format($d['solterra_fee'], 2)
        ));
    }
    fclose($output);
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Details for <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
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
            background: #f9f9f9;
            padding: 15px;
            margin: 5px;
            border-radius: 8px;
            text-align: center;
            min-width: 180px;
        }
        .cost-metric h3 { margin: 0; font-weight: bold; }
        .cost-metric p { margin: 0; font-size: 1.2rem; }
        .cost-metric--total { max-width: 400px; }

        .time-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 30px 20px 10px 20px;
            flex-wrap: wrap;
        }
        .time-filters {
            display: flex;
            gap: 10px;
        }
        .time-filters a {
            text-decoration: none;
            padding: 6px 12px;
            background: #eee;
            border-radius: 4px;
            color: #333;
        }
        .time-filters a.active {
            background: #488C9A;
            color: #fff;
        }
        .date-navigation {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .nav-arrow {
            font-weight: bold;
            cursor: pointer;
            background: #eee;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
        }
        .nav-arrow:hover { background: #ccc; }
        .date-label { font-weight: bold; font-size: 1.1em; }
        .right-filters {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }
        @media screen and (max-width: 768px) {
            .mobile-hide {
                display: none !important;
            }
        }

        .table-container {
            overflow-x: auto;
            width: 100%;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table, th, td {
            border: 1px solid #ccc;
        }
        th, td {
            padding: 8px;
            white-space: nowrap;
        }
        tr:hover { background: #f1f1f1; }

        .legacy-filter-form {
            margin-left: 20px;
            margin-bottom: 10px;
        }

        .back-icon {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            margin: 10px;
            color: #333;
        }
        .back-icon svg {
            width: 24px;
            height: 24px;
            margin-right: 5px;
        }
    </style>
    <script>
        (function() {
            var referrer = document.referrer;
            if (!referrer) return;
            var refAnchor = document.createElement('a');
            refAnchor.href = referrer;

            var curAnchor = document.createElement('a');
            curAnchor.href = window.location.href;

            var refPath = refAnchor.protocol + '//' + refAnchor.host + refAnchor.pathname;
            var curPath = curAnchor.protocol + '//' + curAnchor.host + curAnchor.pathname;
            if (refPath !== curPath) {
                sessionStorage.setItem('backButtonURL', referrer);
            }
        })();
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Simple 'Back' link -->
    <a href="#" onclick="goBack()" class="back-icon">
        <!-- Simple Back Arrow SVG -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>

    <h1>Cost Details for <?php echo htmlspecialchars($project_name); ?></h1>

    <!-- Legacy filter form (Total/YTD/Price per Watt/Price per Module) -->
    <form method="GET" class="legacy-filter-form">
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
        <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
        <input type="hidden" name="ref_date" value="<?php echo $ref_date; ?>">
        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">

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
            <input type="radio" name="filter" value="price_per_watt"
                   onchange="this.form.submit();"
                   <?php if ($filter == 'price_per_watt') echo 'checked'; ?>>
            Price Per Watt
        </label>
        <label>
            <input type="radio" name="filter" value="price_per_module"
                   onchange="this.form.submit();"
                   <?php if ($filter == 'price_per_module') echo 'checked'; ?>>
            Price Per Module
        </label>
    </form>

    <!-- COST OVERVIEW -->
    <div class="cost-overview">
        <?php if ($filter == 'price_per_watt'): ?>
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3>Price Per Watt</h3>
                    <p>$<?php echo number_format($price_per_watt ?? 0, 4); ?></p>
                </div>
            </div>
        <?php elseif ($filter == 'price_per_module'): ?>
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3>Price Per Module</h3>
                    <p>$<?php echo number_format($price_per_module ?? 0, 2); ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3>Total Logistics Cost<?php echo ($filter == 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_logistics_cost, 2); ?></p>
                </div>
            </div>
            <div class="cost-row">
                <div class="cost-metric">
                    <h3>Freight Cost<?php echo ($filter == 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_freight_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>Accessorial Cost<?php echo ($filter == 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_accessorial_costs, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>Warehousing Cost<?php echo ($filter == 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_warehousing_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>Solterra Fee<?php echo ($filter == 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_solterra_fee, 2); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- TIME FILTER HEADER -->
    <div class="time-filter-header">
        <div class="time-filters">
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=all&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter === 'all') ? 'active' : ''; ?>">
                All
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=day&ref_date=<?php echo $ref_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter === 'day') ? 'active' : ''; ?>">
                Day
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=week&ref_date=<?php echo $ref_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter === 'week') ? 'active' : ''; ?>">
                Week
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=month&ref_date=<?php echo $ref_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter === 'month') ? 'active' : ''; ?>">
                Month
            </a>
        </div>

        <div class="date-navigation">
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>'">
                    &larr;
                </button>
            <?php endif; ?>
            <span class="date-label"><?php echo $dateLabel; ?></span>
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>'">
                    &rarr;
                </button>
            <?php endif; ?>
        </div>

        <div class="right-filters">
            <div style="display: flex; gap: 10px;" class="mobile-hide">
                <label for="searchInput" style="align-self: center;">Search in Table:</label>
                <input type="text" id="searchInput" placeholder="Type to filter..." onkeyup="searchTable()">
            </div>
            <form method="get" action="" style="display: flex; gap: 10px;">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
                <input type="hidden" name="ref_date" value="<?php echo $ref_date; ?>">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">

                <label for="status_filter" style="align-self: center;">Filter by Status:</label>
                <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="Pending"     <?php if ($status_filter === 'Pending') echo 'selected'; ?>>Pending</option>
                    <option value="In Transit"  <?php if ($status_filter === 'In Transit') echo 'selected'; ?>>In Transit</option>
                    <option value="Delivered"   <?php if ($status_filter === 'Delivered') echo 'selected'; ?>>Delivered</option>
                    <option value="Complete"    <?php if ($status_filter === 'Complete') echo 'selected'; ?>>Complete</option>
                    <!-- Add others as needed -->
                </select>

                <span class="mobile-hide">
                    <button type="submit" name="export" value="1">Export to CSV</button>
                </span>
            </form>
        </div>
    </div>

    <!-- TABLE of Deliveries -->
    <div class="table-container">
        <table id="deliveriesTable">
            <thead>
            <tr>
                <!-- (1) Removed Supplier, (2) Added BOL# -->
                <th>BOL#</th>
                <th>Wattage</th>
                <th>Quantity</th>
                <th>Status of Delivery</th>
                <th>Warehouse Arrival Date</th>
                <th>Delivered to Site Date</th>
                <th>Warehousing Cost</th>
                <th>Freight Cost</th>
                <th>Accessorial Cost</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!empty($deliveries)): ?>
                <?php foreach ($deliveries as $d): ?>
                    <tr>
                        <!-- BOL# -->
                        <td><?php echo htmlspecialchars($d['bol_number'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($d['wattage'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($d['quantity'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($d['status_of_delivery'] ?? ''); ?></td>
                        <td><?php echo $d['warehouse_arrival_date_formatted']; ?></td>
                        <td><?php echo $d['actual_delivery_date_formatted']; ?></td>
                        <td>$<?php echo number_format($d['warehousing_cost'], 2); ?></td>
                        <td>$<?php echo number_format($d['freight_cost'], 2); ?></td>
                        <td>$<?php echo number_format($d['accessorial_costs'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9">No deliveries found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<script>
    function searchTable() {
        var input = document.getElementById("searchInput");
        if (!input) return;
        var filter = input.value.toLowerCase();
        var table  = document.getElementById("deliveriesTable");
        var trs    = table.getElementsByTagName("tr");
        for (var i = 1; i < trs.length; i++) {
            var tds = trs[i].getElementsByTagName("td");
            var show = false;
            for (var j = 0; j < tds.length; j++) {
                var txtValue = tds[j].textContent || tds[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    show = true;
                    break;
                }
            }
            trs[i].style.display = show ? "" : "none";
        }
    }

    function goBack() {
        var backURL = sessionStorage.getItem('backButtonURL');
        if (backURL && backURL !== window.location.href) {
            window.location.href = backURL;
        } else {
            window.history.back();
        }
    }
</script>
</body>
</html>
