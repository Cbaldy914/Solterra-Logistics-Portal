<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'user';

// Validate project ID
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}
$project_id = intval($_GET['project_id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

/**
 * Account-based access check:
 * If role = admin or global_admin => can see any project
 * Else => must join projects.account_id to customer_account_users.account_id 
 */
$project_name = '';

if ($role === 'admin' || $role === 'global_admin') {
    // Admin or global_admin => check project exists
    $stmt = $conn->prepare("SELECT project_name, solterra_fee FROM projects WHERE id=?");
    $stmt->bind_param("i", $project_id);
} else {
    // Regular user => check if user belongs to project's account
    $stmt = $conn->prepare("
        SELECT p.project_name, p.solterra_fee
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id=?
          AND cau.user_id=?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
}

$stmt->execute();
$stmt->bind_result($project_name, $solterra_fee_from_db);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    die("You do not have access to this project or it does not exist.");
}

// Convert solterra_fee to float if not null
$solterra_fee = floatval($solterra_fee_from_db ?? 0);

// TIME FILTER LOGIC
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";
$time_filter  = $_GET['time_filter'] ?? 'all';
$ref_date     = $_GET['ref_date']    ?? date('Y-m-d');

$dateCondition = "";
$paramTypes    = "i";
$params        = [$project_id];

$dateLabel = "All Deliveries";
$prev_date = "";
$next_date = "";

// Day / Week / Month filters
if ($time_filter === 'day') {
    $dateCondition = " AND DATE($filterColumn) = ?";
    $paramTypes   .= "s";
    $params[]      = $ref_date;

    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime("$ref_date -1 day"));
    $next_date = date('Y-m-d', strtotime("$ref_date +1 day"));

} elseif ($time_filter === 'week') {
    $timestamp   = strtotime($ref_date);
    $dayOfWeek   = date('w', $timestamp); // Sunday=0
    $startOfWeek = date('Y-m-d', strtotime("-{$dayOfWeek} days", $timestamp));
    $endOfWeek   = date('Y-m-d', strtotime("+" . (6 - $dayOfWeek) . " days", $timestamp));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfWeek;
    $params[]      = $endOfWeek;

    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime("$startOfWeek -7 days"));
    $next_date = date('Y-m-d', strtotime("$startOfWeek +7 days"));

} elseif ($time_filter === 'month') {
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth   = date('Y-m-t',  strtotime($ref_date));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfMonth;
    $params[]      = $endOfMonth;

    $dateLabel = date('F Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime("$startOfMonth -1 month"));
    $next_date = date('Y-m-d', strtotime("$startOfMonth +1 month"));
}

// STATUS FILTER
$status_filter   = $_GET['status_filter'] ?? '';
$statusCondition = "";
if (!empty($status_filter)) {
    $statusCondition = " AND status_of_delivery = ?";
    $paramTypes     .= "s";
    $params[]        = $status_filter;
}

// Additional "filter" logic (price_per_watt, ytd, etc.)
$filter        = $_GET['filter'] ?? 'total';
$current_year  = date('Y');
$ytdCondition  = "";

if ($filter === 'ytd') {
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

// We'll fetch warehouse info per delivery instead of assuming one warehouse per project

// Helper for warehousing cost - now calculates based on actual pallet data like warehouse_info.php
function calculateDeliveryWarehousingCost($delivery, $conn) {
    if (!$conn || empty($delivery['warehouse_id'])) {
        return 0;
    }
    
    $delivery_id = $delivery['id'];
    $warehouse_id = $delivery['warehouse_id'];
    
    // Get warehouse cost items for this specific delivery
    $stmt_warehouse = $conn->prepare("
        SELECT label, trigger_event, amount, unit_type
        FROM warehouse_cost_items 
        WHERE warehouse_id = ? AND is_active = 1
    ");
    if (!$stmt_warehouse) {
        return 0;
    }
    
    $stmt_warehouse->bind_param("i", $warehouse_id);
    $stmt_warehouse->execute();
    $warehouse_result = $stmt_warehouse->get_result();
    
    // Parse cost items into usable structure
    $in_fee = 0;
    $out_fee = 0;
    $monthly_storage_fee = 0;
    
    while ($cost_item = $warehouse_result->fetch_assoc()) {
        $label = strtolower($cost_item['label']);
        $amount = floatval($cost_item['amount'] ?? 0);
        
        if (strpos($label, 'entry') !== false || strpos($label, 'in') !== false) {
            $in_fee = $amount;
        } elseif (strpos($label, 'exit') !== false || strpos($label, 'out') !== false) {
            $out_fee = $amount;
        } elseif (strpos($label, 'monthly') !== false || strpos($label, 'storage') !== false) {
            $monthly_storage_fee = $amount;
        }
    }
    $stmt_warehouse->close();
    
    // Get all pallets associated with this delivery
    $stmt_pallets = $conn->prepare("
        SELECT ip.id, ip.arrival_date, ip.current_warehouse_id, ip.status,
               CASE 
                   WHEN ip.status = 'In Warehouse' THEN DATEDIFF(CURDATE(), ip.arrival_date)
                   WHEN ip.status = 'Delivered' THEN DATEDIFF(CURDATE(), ip.arrival_date)
                   ELSE 0
               END as days_stored,
               CASE 
                   WHEN ip.status = 'Delivered' THEN 1
                   ELSE 0
               END as has_left_warehouse
        FROM delivery_pallets dp
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        WHERE dp.delivery_id = ?
    ");
    
    if (!$stmt_pallets) {
        return 0;
    }
    
    $stmt_pallets->bind_param("i", $delivery_id);
    $stmt_pallets->execute();
    $result_pallets = $stmt_pallets->get_result();
    
    $pallet_count = 0;
    $pallets_that_left = 0;
    $total_storage_days = 0;
    
    while ($pallet = $result_pallets->fetch_assoc()) {
        $pallet_count++;
        $days_stored = max(0, intval($pallet['days_stored']));
        $total_storage_days += $days_stored;
        
        if ($pallet['has_left_warehouse']) {
            $pallets_that_left++;
        }
    }
    $stmt_pallets->close();
    
    if ($pallet_count === 0) {
        return 0;
    }
    
    // Calculate costs using warehouse_cost_items
    $in_fee_cost = $in_fee * $pallet_count; // All pallets that arrived
    $out_fee_cost = $out_fee * $pallets_that_left; // Only pallets that left
    
    // Storage cost based on actual days stored
    $daily_storage_rate = $monthly_storage_fee > 0 ? ($monthly_storage_fee / 30) : 0;
    $storage_cost = $total_storage_days * $daily_storage_rate;
    
    $total_warehousing_cost = $in_fee_cost + $out_fee_cost + $storage_cost;
    
    return $total_warehousing_cost;
}

// Initialize totals - ensure they are never null
$total_customer_cost     = 0.0;
$total_accessorial_costs = 0.0;
$total_warehousing_cost  = 0.0;
$total_solterra_fee      = 0.0;
$total_logistics_cost    = 0.0;

$total_quantity         = 0;
$total_wattage_quantity = 0.0;
$total_pallets_count    = 0;

$deliveries = [];

// Prepare statement for fetching pallets for efficiency
$stmtPallets = $conn->prepare("SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity FROM delivery_pallets dp JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id WHERE dp.delivery_id = ? ORDER BY ip.id");

while ($delivery = $deliveries_result->fetch_assoc()) {
    $customer_cost     = (float)$delivery['customer_cost'];
    $accessorial_costs = (float)$delivery['accessorial_costs'];
    $quantity          = (int)($delivery['quantity'] ?? 0);
    $wattage           = (float)($delivery['wattage'] ?? 0);

    $total_customer_cost     += $customer_cost;
    $total_accessorial_costs += $accessorial_costs;

    $total_quantity         += $quantity;
    $total_wattage_quantity += ($quantity * $wattage);

    // Fetch associated pallets for this delivery
    $associatedPallets = [];
    $palletCount = 0;
    if ($stmtPallets) {
        $stmtPallets->bind_param("i", $delivery['id']);
        $stmtPallets->execute();
        $palletsResult = $stmtPallets->get_result();
        while ($palletRow = $palletsResult->fetch_assoc()) {
            $associatedPallets[] = $palletRow;
        }
        $palletCount = count($associatedPallets);
        $total_pallets_count += $palletCount;
    }

    // Warehousing cost
    $warehousing_cost = calculateDeliveryWarehousingCost($delivery, $conn);
    $total_warehousing_cost += $warehousing_cost;

    // Solterra fee only if actual_delivery_date
    if (!empty($delivery['actual_delivery_date'])) {
        $solterraFeeForThisDelivery = $solterra_fee * ($wattage * $quantity);
    } else {
        $solterraFeeForThisDelivery = 0;
    }
    $total_solterra_fee += $solterraFeeForThisDelivery;

    $line_total = $customer_cost + $accessorial_costs + $warehousing_cost + $solterraFeeForThisDelivery;
    $total_logistics_cost += $line_total;

    // Calculate cost per pallet for this delivery
    $cost_per_pallet = ($palletCount > 0) ? ($line_total / $palletCount) : 0;

    // For display
    $delivery['warehousing_cost']     = $warehousing_cost;
    $delivery['solterra_fee']         = $solterraFeeForThisDelivery;
    $delivery['total_logistics_cost'] = $line_total;
    $delivery['cost_per_pallet']      = $cost_per_pallet;
    $delivery['pallet_count']         = $palletCount;
    $delivery['associated_pallets']   = $associatedPallets;

    // Format date fields
    $delivery['warehouse_arrival_date_formatted'] = !empty($delivery['warehouse_arrival_date'])
        ? htmlspecialchars($delivery['warehouse_arrival_date'])
        : 'N/A';
    $delivery['actual_delivery_date_formatted'] = !empty($delivery['actual_delivery_date'])
        ? htmlspecialchars($delivery['actual_delivery_date'])
        : 'N/A';

    $deliveries[] = $delivery;
}

if ($stmtPallets) $stmtPallets->close();

// Calculate overall cost per pallet
$overall_cost_per_pallet = ($total_pallets_count > 0) ? ($total_logistics_cost / $total_pallets_count) : 0;

// Price per watt / module - ensure safe division
$price_per_watt = 0.0;
$price_per_module = 0.0;

if ($filter === 'price_per_watt') {
    if ($total_wattage_quantity > 0 && $total_logistics_cost > 0) {
        $price_per_watt = $total_logistics_cost / $total_wattage_quantity;
    }
} elseif ($filter === 'price_per_module') {
    if ($total_quantity > 0 && $total_logistics_cost > 0) {
        $price_per_module = $total_logistics_cost / $total_quantity;
    }
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] == 1) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=cost_details.csv');
    $output = fopen('php://output', 'w');

    // CSV headers
    fputcsv($output, [
        'BOL#',
        'Manufacturer',
        'Wattage',
        'Quantity',
        'Pallet Count',
        'Status of Delivery',
        'Delivered to Site Date',
        'Warehousing Cost',
        'Freight Cost',
        'Accessorial Cost',
        'Solterra Fee',
        'Total Cost'
    ]);

    foreach ($deliveries as $d) {
        fputcsv($output, [
            $d['bol_number'] ?? '',
            $d['supplier'] ?? '',
            $d['wattage'] ?? '',
            $d['quantity'] ?? '',
            $d['pallet_count'] ?? 0,
            $d['status_of_delivery'] ?? '',
            $d['actual_delivery_date_formatted'] ?? '',
            number_format($d['warehousing_cost'] ?? 0, 2),
            number_format($d['customer_cost'] ?? 0, 2),
            number_format($d['accessorial_costs'] ?? 0, 2),
            number_format($d['solterra_fee'] ?? 0, 2),
            number_format($d['total_logistics_cost'] ?? 0, 2)
        ]);
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
        .cost-metric--efficiency {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border-color: #b8dabd;
        }
        .cost-metric--efficiency h3 {
            color: #155724;
        }
        .cost-metric--efficiency p {
            color: #28a745;
        }
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
        .nav-arrow:hover {
            background: #ccc;
        }
        .date-label {
            font-weight: bold;
            font-size: 1.1em;
        }
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
            min-width: 650px;
        }
        table, th, td {
            border: 1px solid #ccc;
        }
        th, td {
            padding: 8px;
            white-space: nowrap;
        }
        tr:hover {
            background: #f1f1f1;
        }
        .legacy-filter-form {
            margin: 15px 20px 20px 20px;
            padding: 15px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 1px solid #dee2e6;
            width: auto !important;
            max-width: fit-content;
            display: inline-block;
        }
        .legacy-filter-form label {
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
        .legacy-filter-form label:hover {
            background-color: #f8f9fa;
        }
        .legacy-filter-form input[type="radio"] {
            margin: 0;
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
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
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
    <script>

    function searchTable() {
        var input = document.getElementById("searchInput");
        if (!input) return;
        var filter = input.value.toLowerCase();
        var table = document.getElementById("deliveriesTable");
        var trs = table.getElementsByTagName("tr");

        for (var i=1; i<trs.length; i++) {
            var tds = trs[i].getElementsByTagName("td");
            var show = false;
            for (var j=0; j<tds.length; j++) {
                var txtValue = tds[j].textContent || tds[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    show = true;
                    break;
                }
            }
            trs[i].style.display = show ? "" : "none";
        }
    }

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
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="project_overview.php?project_id=<?php echo $project_id; ?>">Project Overview</a>
        <span class="separator">&raquo;</span>
        <span>Cost Details</span>
    </div>

    <h1>Cost Details for <?php echo htmlspecialchars($project_name); ?></h1>

    <!-- Legacy filter form (Total/YTD/Price per Watt/Price per Module) -->
    <form method="GET" class="legacy-filter-form">
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
        <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter); ?>">
        <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">

        <label>
            <input type="radio" name="filter" value="total"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'total') echo 'checked'; ?>>
            📊 Total Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="ytd"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'ytd') echo 'checked'; ?>>
            📅 Year-to-Date Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="price_per_watt"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'price_per_watt') echo 'checked'; ?>>
            ⚡ Price Per Watt
        </label>
        <label>
            <input type="radio" name="filter" value="price_per_module"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'price_per_module') echo 'checked'; ?>>
            📦 Price Per Module
        </label>
    </form>

    <!-- COST OVERVIEW -->
    <div class="cost-overview">
        <?php if ($filter === 'price_per_watt'): ?>
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3>💰 Price Per Watt</h3>
                    <p>$<?php echo number_format($price_per_watt ?? 0, 4); ?></p>
                </div>
            </div>
        <?php elseif ($filter === 'price_per_module'): ?>
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3>📦 Price Per Module</h3>
                    <p>$<?php echo number_format($price_per_module ?? 0, 2); ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="cost-row">
                <div class="cost-metric cost-metric--total">
                    <h3>💸 Total Logistics Cost<?php echo ($filter === 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_logistics_cost, 2); ?></p>
                </div>
            </div>
            <div class="cost-row">
                <div class="cost-metric">
                    <h3>🚛 Freight Cost<?php echo ($filter === 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_customer_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>📋 Accessorial Cost<?php echo ($filter === 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_accessorial_costs, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>🏢 Warehousing Cost<?php echo ($filter === 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_warehousing_cost, 2); ?></p>
                </div>
                <div class="cost-metric">
                    <h3>⚡ Solterra Fee<?php echo ($filter === 'ytd') ? ' (YTD)' : ''; ?></h3>
                    <p>$<?php echo number_format($total_solterra_fee, 2); ?></p>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- TIME FILTER HEADER -->
    <div class="time-filter-header">
        <div class="time-filters">
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=all&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='all')?'active':''; ?>">
               All
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=day&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='day')?'active':''; ?>">
               Day
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=week&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='week')?'active':''; ?>">
               Week
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=month&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='month')?'active':''; ?>">
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
                <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter); ?>">
                <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">

                <label for="status_filter" style="align-self: center;">Filter by Status:</label>
                <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="Pending"     <?php if($status_filter==='Pending')    echo 'selected'; ?>>Pending</option>
                    <option value="In Transit"  <?php if($status_filter==='In Transit') echo 'selected'; ?>>In Transit</option>
                    <option value="Delivered"   <?php if($status_filter==='Delivered')  echo 'selected'; ?>>Delivered</option>
                    <option value="Complete"    <?php if($status_filter==='Complete')   echo 'selected'; ?>>Complete</option>
                </select>

                <span class="mobile-hide">
                    <button type="submit" name="export" value="1">Export to CSV</button>
                </span>
            </form>
        </div>
    </div>

    <!-- Deliveries Table -->
    <div class="table-container">
        <table id="deliveriesTable">
            <thead style="background-color: #293E4C; color: white;">
                <tr>
                    <th>BOL#</th>
                    <th>Manufacturer</th>
                    <th>Wattage</th>
                    <th>Quantity</th>
                    <th>Associated Pallets</th>
                    <th>Status of Delivery</th>
                    <th>Delivered to Site Date</th>
                    <th>Warehousing Cost</th>
                    <th>Freight Cost</th>
                    <th>Accessorial Cost</th>
                    <th>Total Cost</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($deliveries)): ?>
                <?php foreach ($deliveries as $d): ?>
                    <tr style="border-bottom: 1px solid #dee2e6;">
                        <td><?php echo htmlspecialchars($d['bol_number'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($d['supplier'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($d['wattage'] ?? ''); ?>W</td>
                        <td><?php echo number_format($d['quantity'] ?? 0); ?></td>
                        <td style="text-align: center;">
                            <?php if ($d['pallet_count'] > 0): ?>
                                <button type="button" class="action-buttons" 
                                        onclick="showPalletModal(this)" 
                                        data-pallets='<?php echo htmlspecialchars(json_encode($d['associated_pallets']), ENT_QUOTES, 'UTF-8'); ?>'
                                        style="background-color: #488C9A; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    View Pallets (<?php echo $d['pallet_count']; ?>)
                                </button>
                            <?php else: ?>
                                <span style="color: #6c757d;">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($d['status_of_delivery'] ?? ''); ?></td>
                        <td><?php echo $d['actual_delivery_date_formatted']; ?></td>
                        <td style="text-align: right;">$<?php echo number_format($d['warehousing_cost'] ?? 0, 2); ?></td>
                        <td style="text-align: right;">$<?php echo number_format($d['customer_cost'] ?? 0, 2); ?></td>
                        <td style="text-align: right;">$<?php echo number_format($d['accessorial_costs'] ?? 0, 2); ?></td>
                        <td style="text-align: right; font-weight: bold; background-color: #f8f9fa;">$<?php echo number_format($d['total_logistics_cost'] ?? 0, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="11" style="text-align: center; padding: 40px; color: #6c757d;">
                    <div style="font-size: 1.1em; margin-bottom: 10px;">📋 No deliveries found</div>
                    <div style="font-size: 0.9em; line-height: 1.4;">
                        <?php if (!empty($status_filter) || $time_filter !== 'all'): ?>
                            No deliveries match your current filters. Try adjusting your time period or status filter.
                        <?php else: ?>
                            No deliveries have been recorded for this project yet.<br>
                            Cost details will appear here once deliveries are added to the system.
                        <?php endif; ?>
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

    <!-- Associated Pallets Modal -->
    <div id="associatedPalletsModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.5);">
        <div style="background-color: #fff; margin: 5% auto; padding: 20px; width: 500px; max-width: 90%; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); position: relative;">
            <span onclick="closeAssociatedPalletModal()" style="position: absolute; top: 15px; right: 20px; font-size: 24px; font-weight: bold; color: #aaa; cursor: pointer; border: none; background: transparent;">&times;</span>
            <h2>Associated Pallets</h2>
            <div id="palletList" style="max-height: 300px; overflow-y: auto; border: 1px solid #eee;"> 
                <!-- Pallet details will be loaded here by JS -->
            </div>
        </div>
    </div>

    <script>
    function searchTable() {
        var input = document.getElementById("searchInput");
        if (!input) return;
        var filter = input.value.toLowerCase();
        var table = document.getElementById("deliveriesTable");
        var trs = table.getElementsByTagName("tr");

        for (var i=1; i<trs.length; i++) {
            var tds = trs[i].getElementsByTagName("td");
            var show = false;
            for (var j=0; j<tds.length; j++) {
                var txtValue = tds[j].textContent || tds[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    show = true;
                    break;
                }
            }
            trs[i].style.display = show ? "" : "none";
        }
    }

    // --- Associated Pallets Modal --- 
    var associatedPalletsModal = document.getElementById('associatedPalletsModal');
    var palletListDiv = document.getElementById('palletList');

    function showPalletModal(buttonElement) {
        var palletsJson = buttonElement.getAttribute('data-pallets');
        try {
            var pallets = JSON.parse(palletsJson);
            palletListDiv.innerHTML = ''; // Clear previous content

            if (pallets.length > 0) {
                var table = document.createElement('table');
                table.style.width = '100%';
                table.style.borderCollapse = 'collapse';

                var thead = table.createTHead();
                var headerRow = thead.insertRow();
                var headers = ['Identifier', 'Wattage', 'Quantity', 'Actions'];
                headers.forEach(function(headerText) {
                    var th = document.createElement('th');
                    th.textContent = headerText;
                    th.style.border = '1px solid #ddd';
                    th.style.padding = '8px';
                    th.style.textAlign = 'center';
                    th.style.backgroundColor = '#293E4C';
                    th.style.color = 'white';
                    headerRow.appendChild(th);
                });

                var tbody = table.createTBody();
                pallets.forEach(function(pallet) {
                    var row = tbody.insertRow();
                    
                    var cellIdentifier = row.insertCell();
                    cellIdentifier.textContent = pallet.pallet_identifier ? pallet.pallet_identifier : `ID: ${pallet.id}`;
                    cellIdentifier.style.border = '1px solid #ddd';
                    cellIdentifier.style.padding = '8px';

                    var cellWattage = row.insertCell();
                    cellWattage.textContent = pallet.wattage ? `${pallet.wattage}W` : 'N/A';
                    cellWattage.style.border = '1px solid #ddd';
                    cellWattage.style.padding = '8px';

                    var cellQuantity = row.insertCell();
                    cellQuantity.textContent = pallet.quantity ? pallet.quantity : 'N/A';
                    cellQuantity.style.border = '1px solid #ddd';
                    cellQuantity.style.padding = '8px';

                    var cellActions = row.insertCell();
                    cellActions.style.border = '1px solid #ddd';
                    cellActions.style.padding = '8px';
                    cellActions.style.textAlign = 'center';

                    var viewDetailsBtn = document.createElement('a');
                    viewDetailsBtn.href = `pallet_details.php?pallet_id=${pallet.id}`;
                    viewDetailsBtn.textContent = 'View Details';
                    viewDetailsBtn.style.color = '#488C9A';
                    viewDetailsBtn.style.textDecoration = 'none';
                    cellActions.appendChild(viewDetailsBtn);
                });

                palletListDiv.appendChild(table);
            } else {
                 var p = document.createElement('p');
                 p.textContent = 'No pallets found.';
                 palletListDiv.appendChild(p);
            }

            associatedPalletsModal.style.display = 'block';
        } catch (e) {
            console.error("Error parsing pallet data or creating table:", e);
            palletListDiv.innerHTML = '<p>Error loading pallet data.</p>';
            associatedPalletsModal.style.display = 'block';
        }
    }

    function closeAssociatedPalletModal() {
         associatedPalletsModal.style.display = 'none';
         palletListDiv.innerHTML = ''; // Clear list on close
    }

    // Add event listener for clicks outside the associated pallets modal
    window.addEventListener('click', function(event) {
        if (event.target == associatedPalletsModal) {
            closeAssociatedPalletModal();
        }
    });
    </script>
</body>
</html>
