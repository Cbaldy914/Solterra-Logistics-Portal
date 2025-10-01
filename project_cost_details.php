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

// NEW FILTER PARAMETERS
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search_query = $_GET['search'] ?? '';
$manufacturer_filter = $_GET['manufacturer'] ?? '';

// Date range filter
if ($start_date && $end_date) {
    $dateCondition .= " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    array_push($params, $start_date, $end_date);
} elseif ($start_date) {
    $dateCondition .= " AND DATE($filterColumn) >= ?";
    $paramTypes .= "s";
    $params[] = $start_date;
} elseif ($end_date) {
    $dateCondition .= " AND DATE($filterColumn) <= ?";
    $paramTypes .= "s";
    $params[] = $end_date;
}

// Manufacturer filter
$manufacturerCondition = "";
if ($manufacturer_filter !== '') {
    $manufacturerCondition = " AND supplier = ?";
    $paramTypes .= "s";
    $params[] = $manufacturer_filter;
}

// Search filter
if ($search_query !== '') {
    $searchCondition = " AND (bol_number LIKE ? OR supplier LIKE ?)";
    $paramTypes .= "ss";
    $search_param = "%$search_query%";
    array_push($params, $search_param, $search_param);
} else {
    $searchCondition = "";
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
          $manufacturerCondition
          $searchCondition
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }

        /* Header Section */
        .cost-tracker-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .cost-tracker-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        .header-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            background: rgba(72, 140, 154, 0.08);
            padding: 16px 20px;
            border-radius: 16px;
            min-width: 140px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.2);
        }

        .stat-item-total {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(72, 140, 154, 0.2) 100%);
        }

        .stat-item-freight {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(59, 130, 246, 0.2) 100%);
        }

        .stat-item-freight .stat-number {
            color: #2563eb;
        }

        .stat-item-warehousing {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.15) 0%, rgba(139, 92, 246, 0.2) 100%);
        }

        .stat-item-warehousing .stat-number {
            color: #7c3aed;
        }

        .stat-item-accessorial {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(245, 158, 11, 0.2) 100%);
        }

        .stat-item-accessorial .stat-number {
            color: #d97706;
        }

        .stat-item-solterra {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15) 0%, rgba(34, 197, 94, 0.2) 100%);
        }

        .stat-item-solterra .stat-number {
            color: #16a34a;
        }

        .stat-number {
            font-size: 1.8em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.85em;
            color: #6c757d;
            margin: 4px 0 0 0;
            font-weight: 500;
        }
        /* Filter Section */
        .filter-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .filter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .filter-title {
            font-size: 1.4em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .filter-title i {
            color: #488C9A;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: start;
        }

        .filter-group {
            position: relative;
            display: flex;
            flex-direction: column;
        }

        /* Make date range span two columns to prevent overlap */
        .filter-group:has(.date-range-group) {
            grid-column: span 2;
        }

        .filter-label {
            font-weight: 600;
            color: #293E4C;
            font-size: 0.95em;
            margin-bottom: 8px;
        }

        .filter-select, .filter-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 12px;
            background: white;
            font-size: 0.95em;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }

        .date-range-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-clear, .btn-apply {
            padding: 10px 18px;
            border-radius: 12px;
            font-size: 0.9em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
        }

        .btn-clear {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.15) 100%);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .btn-clear:hover {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(220, 38, 38, 0.2) 100%);
            transform: translateY(-1px);
        }

        .btn-apply {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.3);
        }

        .btn-apply:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }


        /* Table Container */
        .table-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .table-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .table-title {
            font-size: 1.3em;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
        }

        .table-header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        table thead {
            background: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid rgba(72, 140, 154, 0.1);
            border: none;
            background: white;
        }

        table td {
            padding: 16px;
            border-bottom: 1px solid rgba(72, 140, 154, 0.08);
            vertical-align: middle;
            border: none;
        }

        table tbody tr {
            transition: all 0.3s ease;
        }

        table tbody tr:hover {
            background: rgba(72, 140, 154, 0.05);
            transform: translateX(4px);
        }
        /* Action Buttons */
        .btn-export-header, .btn-filter-header, .btn-columns-header {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.85em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }

        .btn-export-header {
            background: rgba(255, 255, 255, 0.95);
            color: #16a34a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-export-header:hover {
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btn-filter-header {
            background: rgba(255, 255, 255, 0.95);
            color: #488C9A;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-filter-header:hover {
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btn-columns-header {
            background: rgba(255, 255, 255, 0.95);
            color: #d97706;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-columns-header:hover {
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.85em;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }

        .action-btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.25);
        }

        .action-btn-primary:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.35);
            color: white;
        }

        /* Dropdown filters */
        .filters-dropdown, .column-chooser {
            position: relative;
            display: inline-block;
        }

        .filter-dropdown-content, .column-chooser-content {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: white;
            border: 1px solid rgba(72, 140, 154, 0.2);
            border-radius: 12px;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            min-width: 250px;
            max-height: 400px;
            overflow-y: auto;
        }

        .filter-dropdown-content.show, .column-chooser-content.show {
            display: block;
        }

        .filter-dropdown-header, .column-chooser-header {
            padding: 16px;
            background: #f8f9fa;
            border-bottom: 1px solid rgba(72, 140, 154, 0.2);
            font-weight: 600;
            color: #293E4C;
        }

        .filter-dropdown-options, .column-chooser-options {
            padding: 8px 0;
        }

        .filter-option, .column-option, .column-item {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .filter-option:hover, .column-option:hover, .column-item:hover {
            background-color: rgba(72, 140, 154, 0.08);
        }

        .filter-option input[type=checkbox], .column-option input[type=checkbox], .column-item input[type=checkbox] {
            margin-right: 10px;
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
        }

        .column-item label {
            display: flex;
            align-items: center;
            cursor: pointer;
            color: #293E4C;
            font-size: 0.9em;
            width: 100%;
        }

        .column-toggle {
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
        }

        #searchInput, #status_filter {
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 12px;
            padding: 10px 16px;
            background: white;
            transition: all 0.3s ease;
            font-size: 0.95em;
            width: 100%;
            font-family: 'Poppins', sans-serif;
        }

        #searchInput:focus, #status_filter:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }

        .filter-item {
            margin-bottom: 16px;
        }

        .filter-item label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #293E4C;
            font-size: 0.9em;
        }

        .column-hidden {
            display: none !important;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            width: 90%;
            max-width: 600px;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 24px;
            position: relative;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 600;
            color: white;
        }

        .modal-close {
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

        .modal-close:hover {
            transform: scale(1.1);
        }

        .modal-body {
            padding: 0;
            max-height: 400px;
            overflow-y: auto;
            position: relative;
        }

        .pallet-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .pallet-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f8f9fa;
        }

        .pallet-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #293E4C;
            border-bottom: 2px solid rgba(72, 140, 154, 0.2);
        }

        .pallet-table td {
            padding: 12px;
            border-bottom: 1px solid rgba(72, 140, 154, 0.1);
        }

        .pallet-table tr:hover {
            background: rgba(72, 140, 154, 0.05);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 4em;
            color: #d1d5db;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 1.5em;
            color: #6c757d;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .empty-state p {
            color: #9ca3af;
            margin: 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .cost-tracker-header {
                padding: 20px;
            }

            .header-content {
                flex-direction: column;
                align-items: stretch;
            }

            .header-stats {
                justify-content: space-between;
            }

            .stat-item {
                flex: 1;
                min-width: 100px;
            }

            .filter-section {
                padding: 20px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            /* Reset date range span on mobile */
            .filter-group:has(.date-range-group) {
                grid-column: span 1;
            }

            .date-range-group {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                width: 100%;
                flex-direction: column;
            }

            .filter-actions button {
                width: 100%;
                justify-content: center;
            }

            .table-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .table-header-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .btn-export-header, .btn-columns-header {
                flex: 1;
                justify-content: center;
            }

            table {
                font-size: 0.9em;
            }

            table th, table td {
                padding: 12px 8px;
            }
        }

        @media (max-width: 480px) {
            .header-info h1 {
                font-size: 1.8em;
            }

            .stat-number {
                font-size: 1.5em;
            }

            .filter-section {
                padding: 16px;
            }
        }
    </style>
    <script>
    // Clear filters
    function clearFilters() {
        document.getElementById('filterForm').reset();
        window.location.href = '?project_id=<?php echo $project_id; ?>';
    }

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

    // Toggle column chooser
    function toggleColumnChooser() {
        const dropdown = document.getElementById('columnChooser');
        dropdown.style.display = dropdown.style.display === 'none' || dropdown.style.display === '' ? 'block' : 'none';
    }

    // Column visibility toggle
    function toggleColumn(columnClass, isVisible) {
        const elements = document.querySelectorAll('.' + columnClass);
        elements.forEach(element => {
            if (isVisible) {
                element.classList.remove('column-hidden');
            } else {
                element.classList.add('column-hidden');
            }
        });
    }

    // Click outside handler
    document.addEventListener('click', function(e) {
        // Close column chooser
        const columnChooser = document.getElementById('columnChooser');
        if (columnChooser && !e.target.closest('.btn-columns-header') && !columnChooser.contains(e.target)) {
            columnChooser.style.display = 'none';
        }
    });

    // Initialize column chooser functionality
    document.addEventListener('DOMContentLoaded', function() {
        const columnToggles = document.querySelectorAll('.column-toggle');
        columnToggles.forEach(toggle => {
            toggle.addEventListener('change', function() {
                const columnClass = this.dataset.column;
                const isVisible = this.checked;
                toggleColumn(columnClass, isVisible);
            });
        });
    });

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
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Cost Details',
            'project_id' => (int)$project_id
        ]);
    ?>

    <!-- Header Section -->
    <div class="cost-tracker-header">
        <div class="header-content">
            <div class="header-info">
                <h1>Cost Details: <?php echo htmlspecialchars($project_name); ?></h1>
                <p class="header-subtitle">Comprehensive cost breakdown and logistics analysis</p>
            </div>
            <div class="header-stats">
                <div class="stat-item stat-item-total">
                    <p class="stat-number">$<?php echo number_format($total_logistics_cost, 2); ?></p>
                    <p class="stat-label">Total Cost</p>
                </div>
                <div class="stat-item stat-item-freight">
                    <p class="stat-number">$<?php echo number_format($total_customer_cost, 2); ?></p>
                    <p class="stat-label">Freight</p>
                </div>
                <div class="stat-item stat-item-accessorial">
                    <p class="stat-number">$<?php echo number_format($total_accessorial_costs, 2); ?></p>
                    <p class="stat-label">Accessorial</p>
                </div>
                <div class="stat-item stat-item-warehousing">
                    <p class="stat-number">$<?php echo number_format($total_warehousing_cost, 2); ?></p>
                    <p class="stat-label">Warehousing</p>
                </div>
                <div class="stat-item stat-item-solterra">
                    <p class="stat-number">$<?php echo number_format($total_solterra_fee, 2); ?></p>
                    <p class="stat-label">Solterra Fee</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form id="filterForm" method="get">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            
            <div class="filter-header">
                <h2 class="filter-title">
                    <i class="fas fa-filter"></i>
                    Filter Costs
                </h2>
                <div class="filter-actions">
                    <button type="button" class="btn-clear" onclick="clearFilters()">
                        <i class="fas fa-times"></i>
                        Clear
                    </button>
                    <button type="submit" class="btn-apply">
                        <i class="fas fa-search"></i>
                        Apply Filters
                    </button>
                </div>
            </div>

            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Delivery Date Range</label>
                    <div class="date-range-group">
                        <input type="date" name="start_date" class="filter-input" placeholder="Start Date" value="<?php echo htmlspecialchars($start_date); ?>">
                        <input type="date" name="end_date" class="filter-input" placeholder="End Date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="manufacturerFilter">Manufacturer</label>
                    <select name="manufacturer" id="manufacturerFilter" class="filter-select">
                        <option value="">All Manufacturers</option>
                        <?php
                        // Get unique manufacturers from deliveries
                        $unique_manufacturers = array_unique(array_column($deliveries, 'supplier'));
                        sort($unique_manufacturers);
                        $manufacturer_filter = $_GET['manufacturer'] ?? '';
                        foreach ($unique_manufacturers as $manufacturer):
                            if (!empty($manufacturer)):
                        ?>
                            <option value="<?php echo htmlspecialchars($manufacturer); ?>" <?php echo $manufacturer_filter == $manufacturer ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($manufacturer); ?>
                            </option>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="statusFilter">Status</label>
                    <select name="status_filter" id="statusFilter" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="On Water" <?php echo $status_filter == 'On Water' ? 'selected' : ''; ?>>On Water</option>
                        <option value="Cleared Customs" <?php echo $status_filter == 'Cleared Customs' ? 'selected' : ''; ?>>Cleared Customs</option>
                        <option value="In Transit to Warehouse" <?php echo $status_filter == 'In Transit to Warehouse' ? 'selected' : ''; ?>>In Transit to Warehouse</option>
                        <option value="Delivered to Warehouse" <?php echo $status_filter == 'Delivered to Warehouse' ? 'selected' : ''; ?>>Delivered to Warehouse</option>
                        <option value="In Transit to Project" <?php echo $status_filter == 'In Transit to Project' ? 'selected' : ''; ?>>In Transit to Project</option>
                        <option value="Delivered to Project" <?php echo $status_filter == 'Delivered to Project' ? 'selected' : ''; ?>>Delivered to Project</option>
                        <option value="Canceled" <?php echo $status_filter == 'Canceled' ? 'selected' : ''; ?>>Canceled</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="searchFilter">Search</label>
                    <input type="text" name="search" id="searchFilter" class="filter-input" placeholder="Search BOL, manufacturer..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
            </div>
        </form>
    </div>

    <!-- Deliveries Table -->
    <div class="table-container">
        <div class="table-header">
            <h3 class="table-title">
                <i class="fas fa-dollar-sign"></i>
                Cost Breakdown
            </h3>
            <div class="table-header-actions">
                <button type="submit" form="filterForm" name="export" value="1" class="btn-export-header">
                    <i class="fas fa-download"></i>
                    Export CSV
                </button>
                <div style="position: relative;">
                    <button type="button" class="btn-columns-header" onclick="toggleColumnChooser()">
                        <i class="fas fa-columns"></i>
                        Columns
                    </button>
                    <div class="column-chooser-content" id="columnChooser">
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-bol" checked> BOL#</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-manufacturer" checked> Manufacturer</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-wattage" checked> Wattage</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-quantity" checked> Quantity</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-pallets" checked> Associated Pallets</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-status" checked> Status of Delivery</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-delivery-date" checked> Delivered to Site Date</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-warehousing-cost" checked> Warehousing Cost</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-freight-cost" checked> Freight Cost</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-accessorial-cost" checked> Accessorial Cost</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-total-cost" checked> Total Cost</label>
                    </div>
                </div>
                </div>
            </div>
        </div>

        <table id="deliveriesTable">
            <thead>
                <tr>
                    <th class="col-bol">BOL#</th>
                    <th class="col-manufacturer">Manufacturer</th>
                    <th class="col-wattage">Wattage</th>
                    <th class="col-quantity">Quantity</th>
                    <th class="col-pallets">Associated Pallets</th>
                    <th class="col-status">Status of Delivery</th>
                    <th class="col-delivery-date">Delivered to Site Date</th>
                    <th class="col-warehousing-cost">Warehousing Cost</th>
                    <th class="col-freight-cost">Freight Cost</th>
                    <th class="col-accessorial-cost">Accessorial Cost</th>
                    <th class="col-total-cost">Total Cost</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($deliveries)): ?>
                <?php foreach ($deliveries as $d): ?>
                    <tr style="border-bottom: 1px solid #dee2e6;">
                        <td class="col-bol"><?php echo htmlspecialchars($d['bol_number'] ?? ''); ?></td>
                        <td class="col-manufacturer"><?php echo htmlspecialchars($d['supplier'] ?? ''); ?></td>
                        <td class="col-wattage"><?php echo htmlspecialchars($d['wattage'] ?? ''); ?>W</td>
                        <td class="col-quantity"><?php echo number_format($d['quantity'] ?? 0); ?></td>
                        <td class="col-pallets" style="text-align: center;">
                            <?php if ($d['pallet_count'] > 0): ?>
                                <button type="button" class="action-btn action-btn-primary" 
                                        onclick="showPalletModal(this)" 
                                        data-pallets='<?php echo htmlspecialchars(json_encode($d['associated_pallets']), ENT_QUOTES, 'UTF-8'); ?>'>
                                    <i class="fas fa-boxes"></i>
                                    View Pallets (<?php echo $d['pallet_count']; ?>)
                                </button>
                            <?php else: ?>
                                <span style="color: #6c757d;">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-status"><?php echo htmlspecialchars($d['status_of_delivery'] ?? ''); ?></td>
                        <td class="col-delivery-date"><?php echo $d['actual_delivery_date_formatted']; ?></td>
                        <td class="col-warehousing-cost" style="text-align: right;">$<?php echo number_format($d['warehousing_cost'] ?? 0, 2); ?></td>
                        <td class="col-freight-cost" style="text-align: right;">$<?php echo number_format($d['customer_cost'] ?? 0, 2); ?></td>
                        <td class="col-accessorial-cost" style="text-align: right;">$<?php echo number_format($d['accessorial_costs'] ?? 0, 2); ?></td>
                        <td class="col-total-cost" style="text-align: right; font-weight: bold; background-color: #f8f9fa;">$<?php echo number_format($d['total_logistics_cost'] ?? 0, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11">
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No Deliveries Found</h3>
                            <p>
                                <?php if (!empty($status_filter) || $time_filter !== 'all'): ?>
                                    No deliveries match your current filters. Try adjusting your time period or status filter.
                                <?php else: ?>
                                    No deliveries have been recorded for this project yet.<br>
                                    Cost details will appear here once deliveries are added to the system.
                                <?php endif; ?>
                            </p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

    <!-- Associated Pallets Modal -->
    <div id="associatedPalletsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Associated Pallets</h2>
                <span class="modal-close" onclick="closeAssociatedPalletModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="palletList"></div>
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
            palletListDiv.innerHTML = '';

            if (pallets.length > 0) {
                var table = document.createElement('table');
                table.className = 'pallet-table';

                var thead = table.createTHead();
                var headerRow = thead.insertRow();
                var headers = ['Identifier', 'Wattage', 'Quantity', 'Actions'];
                headers.forEach(function(headerText) {
                    var th = document.createElement('th');
                    th.textContent = headerText;
                    headerRow.appendChild(th);
                });

                var tbody = table.createTBody();
                pallets.forEach(function(pallet) {
                    var row = tbody.insertRow();
                    
                    var cellIdentifier = row.insertCell();
                    cellIdentifier.textContent = pallet.pallet_identifier ? pallet.pallet_identifier : `ID: ${pallet.id}`;

                    var cellWattage = row.insertCell();
                    cellWattage.textContent = pallet.wattage ? `${pallet.wattage}W` : '—';

                    var cellQuantity = row.insertCell();
                    cellQuantity.textContent = pallet.quantity ? pallet.quantity : '—';

                    var cellActions = row.insertCell();
                    cellActions.style.textAlign = 'center';

                    var viewDetailsBtn = document.createElement('a');
                    viewDetailsBtn.href = `pallet_details.php?pallet_id=${pallet.id}`;
                    viewDetailsBtn.className = 'action-btn action-btn-primary';
                    viewDetailsBtn.innerHTML = '<i class="fas fa-eye"></i> View Details';
                    cellActions.appendChild(viewDetailsBtn);
                });

                palletListDiv.appendChild(table);
            } else {
                var p = document.createElement('p');
                p.style.textAlign = 'center';
                p.style.color = '#6c757d';
                p.textContent = 'No pallets found.';
                palletListDiv.appendChild(p);
            }

            associatedPalletsModal.style.display = 'block';
        } catch (e) {
            console.error("Error parsing pallet data or creating table:", e);
            palletListDiv.innerHTML = '<p style="text-align: center; color: #dc2626;">Error loading pallet data.</p>';
            associatedPalletsModal.style.display = 'block';
        }
    }

    function closeAssociatedPalletModal() {
         associatedPalletsModal.style.display = 'none';
         palletListDiv.innerHTML = ''; // Clear list on close
    }

    // Close modal on outside click
    window.addEventListener('click', function(event) {
        if (event.target === associatedPalletsModal) {
            closeAssociatedPalletModal();
        }
    });
    </script>
</body>
</html>
