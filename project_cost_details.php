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
require_once 'cost_helpers.php';
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

// Backup warehousing cost calculation for pallets (mirrors warehouse_info logic)
function calculatePalletWarehousingCostFallback($pallet_id, $conn) {
    if (!$conn || !$pallet_id) {
        return 0.0;
    }

    $sql = "SELECT DISTINCT
                w.id AS warehouse_id,
                (SELECT MIN(d_arr.warehouse_arrival_date)
                 FROM deliveries d_arr
                 JOIN delivery_pallets dp_arr ON d_arr.id = dp_arr.delivery_id
                 WHERE dp_arr.inventory_pallet_id = ?
                   AND d_arr.warehouse_id = w.id
                   AND d_arr.warehouse_arrival_date IS NOT NULL) AS arrival_date,
                (SELECT MIN(d_dep.left_warehouse_date)
                 FROM deliveries d_dep
                 JOIN delivery_pallets dp_dep ON d_dep.id = dp_dep.delivery_id
                 WHERE dp_dep.inventory_pallet_id = ?
                   AND d_dep.origin_type = 'warehouse'
                   AND d_dep.origin_id = w.id
                   AND d_dep.left_warehouse_date IS NOT NULL) AS departure_date,
                (SELECT COUNT(DISTINCT d_in.id)
                 FROM deliveries d_in
                 JOIN delivery_pallets dp_in ON d_in.id = dp_in.delivery_id
                 WHERE dp_in.inventory_pallet_id = ?
                   AND d_in.warehouse_id = w.id
                   AND d_in.warehouse_arrival_date IS NOT NULL) AS inbound_deliveries,
                (SELECT COUNT(DISTINCT d_out.id)
                 FROM deliveries d_out
                 JOIN delivery_pallets dp_out ON d_out.id = dp_out.delivery_id
                 WHERE dp_out.inventory_pallet_id = ?
                   AND d_out.origin_type = 'warehouse'
                   AND d_out.origin_id = w.id
                   AND d_out.left_warehouse_date IS NOT NULL) AS outbound_deliveries
            FROM warehouses w
            WHERE w.id IN (
                SELECT DISTINCT COALESCE(d.warehouse_id, d.origin_id)
                FROM deliveries d
                JOIN delivery_pallets dp ON d.id = dp.delivery_id
                WHERE dp.inventory_pallet_id = ?
                  AND (d.warehouse_id IS NOT NULL OR (d.origin_type = 'warehouse' AND d.origin_id IS NOT NULL))
            )
            ORDER BY arrival_date ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0.0;
    }

    $stmt->bind_param("iiiii", $pallet_id, $pallet_id, $pallet_id, $pallet_id, $pallet_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        return 0.0;
    }

    $warehouse_rows = [];
    $warehouse_ids  = [];
    while ($row = $result->fetch_assoc()) {
        $warehouse_rows[] = $row;
        $warehouse_ids[]  = (int)$row['warehouse_id'];
    }
    $stmt->close();

    $warehouse_costs = [];
    if (!empty($warehouse_ids)) {
        $warehouse_ids_str = implode(',', array_map('intval', $warehouse_ids));
        $cost_sql = "SELECT warehouse_id, trigger_event, amount
                     FROM warehouse_cost_items
                     WHERE warehouse_id IN ({$warehouse_ids_str}) AND is_active = 1";
        $cost_result = $conn->query($cost_sql);
        if ($cost_result) {
            while ($cost = $cost_result->fetch_assoc()) {
                $wid = (int)$cost['warehouse_id'];
                if (!isset($warehouse_costs[$wid])) {
                    $warehouse_costs[$wid] = ['in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];
                }
                switch ($cost['trigger_event']) {
                    case 'entry':
                        $warehouse_costs[$wid]['in_fee'] = (float)$cost['amount'];
                        break;
                    case 'exit':
                        $warehouse_costs[$wid]['out_fee'] = (float)$cost['amount'];
                        break;
                    case 'monthly':
                        $warehouse_costs[$wid]['monthly_storage_fee'] = (float)$cost['amount'];
                        break;
                }
            }
        }
    }

    $total_warehouse_cost = 0.0;
    foreach ($warehouse_rows as $row) {
        $wid   = (int)$row['warehouse_id'];
        $costs = $warehouse_costs[$wid] ?? ['in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];

        $in_fee_cost  = $costs['in_fee'] * (int)($row['inbound_deliveries'] ?? 0);
        $out_fee_cost = $costs['out_fee'] * (int)($row['outbound_deliveries'] ?? 0);

        $storage_cost = 0.0;
        if (!empty($row['arrival_date'])) {
            $arrival   = new DateTime($row['arrival_date']);
            $departure = !empty($row['departure_date']) ? new DateTime($row['departure_date']) : new DateTime();
            $days      = max(0, $arrival->diff($departure)->days);
            $daily_fee = ($costs['monthly_storage_fee'] ?? 0) / 30;
            $storage_cost = $days * $daily_fee;
        }

        $total_warehouse_cost += $in_fee_cost + $out_fee_cost + $storage_cost;
    }

    return $total_warehouse_cost;
}

function getWarehouseCostRates($warehouse_id, $conn) {
    $rates = ['in_fee' => 0.0, 'out_fee' => 0.0, 'monthly_storage_fee' => 0.0];
    if (!$warehouse_id || !$conn) {
        return $rates;
    }

    $stmt = $conn->prepare("SELECT trigger_event, amount FROM warehouse_cost_items WHERE warehouse_id = ? AND is_active = 1");
    if ($stmt) {
        $stmt->bind_param("i", $warehouse_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            switch ($row['trigger_event']) {
                case 'entry':
                    $rates['in_fee'] = (float)$row['amount'];
                    break;
                case 'exit':
                    $rates['out_fee'] = (float)$row['amount'];
                    break;
                case 'monthly':
                    $rates['monthly_storage_fee'] = (float)$row['amount'];
                    break;
            }
        }
        $stmt->close();
    }
    return $rates;
}

// Normalize pallet cost figures for display/export
function enrichPalletCostData(array $row, $conn) {
    $recorded_cost    = isset($row['warehouse_cost']) ? (float)$row['warehouse_cost'] : 0.0;
    $freight_cost     = isset($row['freight_cost']) ? (float)$row['freight_cost'] : 0.0;
    $accessorial_cost = isset($row['accessorial_cost']) ? (float)$row['accessorial_cost'] : 0.0;
    $fallback_freight = isset($row['fallback_freight_share']) ? (float)$row['fallback_freight_share'] : 0.0;
    $fallback_accessorial = isset($row['fallback_accessorial_share']) ? (float)$row['fallback_accessorial_share'] : 0.0;

    $pending_cost = 0.0;
    if ($recorded_cost > 0 && ($row['status'] ?? '') === 'In Warehouse' && !empty($row['current_warehouse_id']) && !empty($row['arrival_date'])) {
        $estimated_total = calculate_pallet_storage_cost($row['current_warehouse_id'], $row['arrival_date'], date('Y-m-d H:i:s'), $conn);
        $pending_cost    = max(0, $estimated_total - $recorded_cost);
    }

    if ($recorded_cost <= 0) {
        $recorded_cost = calculatePalletWarehousingCostFallback((int)$row['id'], $conn);
    }

    if ($freight_cost <= 0 && $fallback_freight > 0) {
        $freight_cost = $fallback_freight;
    }

    if ($accessorial_cost <= 0 && $fallback_accessorial > 0) {
        $accessorial_cost = $fallback_accessorial;
    }

    $row['pending_warehouse_cost']  = $pending_cost;
    $row['calculated_warehouse_cost'] = $recorded_cost;
    $row['display_warehouse_cost']  = $recorded_cost + $pending_cost;
    $row['freight_cost']            = $freight_cost;
    $row['accessorial_cost']        = $accessorial_cost;
    $row['total_cost']              = $row['display_warehouse_cost'] + $freight_cost + $accessorial_cost;

    return $row;
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
$stmtPallets = $conn->prepare("SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status, ip.current_warehouse_id, ip.arrival_date, ip.warehouse_cost, ip.freight_cost, ip.accessorial_cost FROM delivery_pallets dp JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id WHERE dp.delivery_id = ? ORDER BY ip.id");

while ($delivery = $deliveries_result->fetch_assoc()) {
    $customer_cost     = (float)$delivery['customer_cost'];
    $accessorial_costs = (float)$delivery['accessorial_costs'];
    $quantity          = (int)($delivery['quantity'] ?? 0);
    $wattage           = (float)($delivery['wattage'] ?? 0);

    $total_customer_cost     += $customer_cost;
    $total_accessorial_costs += $accessorial_costs;

    $total_quantity         += $quantity;
    $total_wattage_quantity += ($quantity * $wattage);

    // Fetch associated pallets for this delivery and build cost rollups
    $associatedPallets = [];
    $palletCount = 0;
    $delivery_freight_cost = 0.0;
    $delivery_accessorial_cost = 0.0;
    $delivery_warehousing_cost = 0.0;
    $rates = getWarehouseCostRates((int)($delivery['warehouse_id'] ?? 0), $conn);
    $is_inbound  = !empty($delivery['warehouse_arrival_date']) && (empty($delivery['origin_type']) || $delivery['origin_type'] !== 'warehouse');
    $is_outbound = !empty($delivery['left_warehouse_date']) && (!empty($delivery['origin_type']) && $delivery['origin_type'] === 'warehouse');

    if ($stmtPallets) {
        $stmtPallets->bind_param("i", $delivery['id']);
        $stmtPallets->execute();
        $palletsResult = $stmtPallets->get_result();
        while ($palletRow = $palletsResult->fetch_assoc()) {
            $associatedPallets[] = $palletRow;
            $enriched = enrichPalletCostData($palletRow, $conn);

            // Warehousing split: inbound gets entry fee, outbound gets storage+exit, fallback otherwise
            $pallet_wh_cost = 0.0;
            if ($is_inbound) {
                $pallet_wh_cost = $rates['in_fee'];
            } elseif ($is_outbound) {
                if (!empty($palletRow['arrival_date']) && !empty($delivery['left_warehouse_date'])) {
                    $total_cost = calculate_pallet_storage_cost((int)$delivery['warehouse_id'], $palletRow['arrival_date'], $delivery['left_warehouse_date'], $conn);
                    $pallet_wh_cost = max(0, $total_cost - $rates['in_fee']); // remove entry already counted on inbound
                } else {
                    $pallet_wh_cost = $rates['out_fee'];
                }
            } else {
                $pallet_wh_cost = $enriched['display_warehouse_cost'] ?? 0;
            }

            $delivery_warehousing_cost += $pallet_wh_cost;
            $delivery_freight_cost     += $enriched['freight_cost'] ?? 0;
            $delivery_accessorial_cost += $enriched['accessorial_cost'] ?? 0;
        }
        $palletCount = count($associatedPallets);
        $total_pallets_count += $palletCount;
    }

    // Fallbacks for older projects
    if ($delivery_freight_cost <= 0 && $palletCount > 0) {
        $delivery_freight_cost = $customer_cost; // total truck cost fallback
    }
    if ($delivery_accessorial_cost <= 0 && $palletCount > 0) {
        $delivery_accessorial_cost = $accessorial_costs;
    }
    if ($delivery_warehousing_cost <= 0 && $palletCount > 0) {
        $delivery_warehousing_cost = calculateDeliveryWarehousingCost($delivery, $conn);
    }

    // Solterra fee only if actual_delivery_date
    if (!empty($delivery['actual_delivery_date'])) {
        $solterraFeeForThisDelivery = $solterra_fee * ($wattage * $quantity);
    } else {
        $solterraFeeForThisDelivery = 0;
    }
    $total_solterra_fee += $solterraFeeForThisDelivery;

    $line_total = $delivery_freight_cost + $delivery_accessorial_cost + $delivery_warehousing_cost + $solterraFeeForThisDelivery;
    $total_logistics_cost += $line_total;

    // Calculate cost per pallet for this delivery
    $cost_per_pallet = ($palletCount > 0) ? ($line_total / $palletCount) : 0;

    // Totals rollup
    $total_customer_cost     += $delivery_freight_cost;
    $total_accessorial_costs += $delivery_accessorial_cost;
    $total_warehousing_cost  += $delivery_warehousing_cost;

    // For display
    $delivery['warehousing_cost']     = $delivery_warehousing_cost;
    $delivery['customer_cost']        = $delivery_freight_cost;
    $delivery['accessorial_costs']    = $delivery_accessorial_cost;
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

// --- Delivery Pagination (server-side slice to preserve totals) ---
$delivery_page  = isset($_GET['delivery_page']) ? max(1, intval($_GET['delivery_page'])) : 1;
$delivery_limit = isset($_GET['delivery_limit']) ? max(1, min(500, intval($_GET['delivery_limit']))) : 25;
$delivery_total = count($deliveries);
$delivery_total_pages = $delivery_total > 0 ? ceil($delivery_total / $delivery_limit) : 1;
$delivery_offset = ($delivery_page - 1) * $delivery_limit;
if ($delivery_offset >= $delivery_total) {
    $delivery_page = 1;
    $delivery_offset = 0;
}
$deliveries_paginated = array_slice($deliveries, $delivery_offset, $delivery_limit);

// --- Pallet Pagination Logic ---
$pallet_page  = isset($_GET['pallet_page']) ? max(1, intval($_GET['pallet_page'])) : 1;
$pallet_limit = isset($_GET['pallet_limit']) ? max(1, min(500, intval($_GET['pallet_limit']))) : 50;
$pallet_offset = ($pallet_page - 1) * $pallet_limit;

// Build Pallet Query
// We want pallets associated with the *filtered* deliveries to match the "breakdown" concept.
// We reuse the same parameters as the delivery query, but we need to adjust the query structure.
$pallet_base_sql = "
    SELECT 
        ip.id, ip.pallet_identifier, ip.status, ip.manufacturer, ip.quantity, ip.wattage,
        ip.warehouse_cost, ip.freight_cost, ip.accessorial_cost,
        ip.current_warehouse_id, ip.arrival_date,
        AVG(del.customer_cost / NULLIF(dc.pallet_count, 0)) AS fallback_freight_share,
        AVG(del.accessorial_costs / NULLIF(dc.pallet_count, 0)) AS fallback_accessorial_share
    FROM inventory_pallets ip
    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
    JOIN deliveries del ON dp.delivery_id = del.id
    JOIN (
        SELECT delivery_id, COUNT(DISTINCT inventory_pallet_id) AS pallet_count
        FROM delivery_pallets
        GROUP BY delivery_id
    ) dc ON dc.delivery_id = del.id
    WHERE del.project_id = ?
          $ytdCondition
          $dateCondition
          $statusCondition
          $manufacturerCondition
          $searchCondition
    GROUP BY ip.id
";

$pallet_params = $params;
$pallet_params[] = $pallet_offset;
$pallet_params[] = $pallet_limit;
$pallet_types = $paramTypes . "ii";

$pallet_paginated_sql = preg_replace('/^\s*SELECT/i', 'SELECT SQL_CALC_FOUND_ROWS', $pallet_base_sql, 1);

$sql_pallets_paginated = $pallet_paginated_sql . "
    ORDER BY ip.id DESC
    LIMIT ?, ?
";

$stmt_pallets_page = $conn->prepare($sql_pallets_paginated);
if ($stmt_pallets_page) {
    $stmt_pallets_page->bind_param($pallet_types, ...$pallet_params);
    $stmt_pallets_page->execute();
    $result_pallets_page = $stmt_pallets_page->get_result();
    
    $pallets_data = [];
    while ($p_row = $result_pallets_page->fetch_assoc()) {
        $pallets_data[] = enrichPalletCostData($p_row, $conn);
    }
    $stmt_pallets_page->close();
    
    // Get total count using explicit COUNT to ensure accuracy with GROUP BY
    $count_sql = "SELECT COUNT(DISTINCT ip.id) AS total
                  FROM inventory_pallets ip
                  JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                  JOIN deliveries del ON dp.delivery_id = del.id
                  WHERE del.project_id = ?
                        $ytdCondition
                        $dateCondition
                        $statusCondition
                        $manufacturerCondition
                        $searchCondition";
    $stmt_count = $conn->prepare($count_sql);
    if ($stmt_count) {
        $stmt_count->bind_param($paramTypes, ...$params);
        $stmt_count->execute();
        $stmt_count->bind_result($total_pallets_found);
        $stmt_count->fetch();
        $stmt_count->close();
    } else {
        $total_pallets_found = count($pallets_data);
    }
    $total_pallet_pages = $total_pallets_found > 0 ? ceil($total_pallets_found / $pallet_limit) : 1;
    if ($total_pallet_pages < 1 && $total_pallets_found > 0) {
        $total_pallet_pages = 1;
    }
} else {
    // Fallback or error
    $pallets_data = [];
    $total_pallets_found = 0;
    $total_pallet_pages = 0;
    error_log("Pallet query failed: " . $conn->error);
}

// Pallet CSV Export
if (isset($_GET['export_pallets']) && $_GET['export_pallets'] == 1) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pallet_cost_details.csv');
    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Pallet ID',
        'Manufacturer',
        'Wattage',
        'Quantity',
        'Status',
        'Warehousing Cost',
        'Freight Cost',
        'Accessorial Cost',
        'Total Cost'
    ]);

    $sql_pallets_export = $pallet_base_sql . " ORDER BY ip.id DESC";
    $stmt_export = $conn->prepare($sql_pallets_export);
    if ($stmt_export) {
        $stmt_export->bind_param($paramTypes, ...$params);
        $stmt_export->execute();
        $result_export = $stmt_export->get_result();
        while ($row = $result_export->fetch_assoc()) {
            $row = enrichPalletCostData($row, $conn);
            fputcsv($output, [
                $row['pallet_identifier'] ?? '',
                $row['manufacturer'] ?? '',
                $row['wattage'] ?? '',
                $row['quantity'] ?? '',
                $row['status'] ?? '',
                number_format($row['display_warehouse_cost'] ?? 0, 2),
                number_format($row['freight_cost'] ?? 0, 2),
                number_format($row['accessorial_cost'] ?? 0, 2),
                number_format($row['total_cost'] ?? 0, 2)
            ]);
        }
        $stmt_export->close();
    }
    fclose($output);
    exit();
}

// CSV Export (Deliveries)
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

        /* Tabs */
        .tabs-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid rgba(72, 140, 154, 0.1);
            padding-bottom: 2px;
        }
        
        .tab-btn {
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            font-family: 'Poppins', sans-serif;
            font-size: 1em;
            font-weight: 600;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab-btn:hover {
            color: #488C9A;
            background: rgba(72, 140, 154, 0.05);
            border-radius: 8px 8px 0 0;
        }
        
        .tab-btn.active {
            color: #488C9A;
            border-bottom-color: #488C9A;
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
            padding: 20px;
        }
        
        .page-link {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            color: #488C9A;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .page-link:hover, .page-link.active {
            background: #488C9A;
            color: white;
            border-color: #488C9A;
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

        /* Pagination styles - matching manage_pallets.php */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .pagination-info {
            font-size: 0.9em;
            color: #6c757d;
            font-weight: 500;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pagination-controls label {
            font-size: 0.9em;
            margin-right: 5px;
            color: #293E4C;
            font-weight: 500;
        }

        .pagination-controls input,
        .pagination-controls select {
            padding: 8px 12px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
        }

        .pagination-controls input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }

        .pagination-controls button {
            padding: 8px 16px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }

        .pagination-controls button:hover:not(:disabled) {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-1px);
        }

        .pagination-controls button:disabled {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
            transform: none;
        }

        #pageInfo {
            color: #293E4C;
            font-weight: 600;
            padding: 0 8px;
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

    <!-- Tabs Navigation -->
    <div class="tabs-nav">
        <button class="tab-btn active" onclick="switchTab('deliveries')">Delivery Breakdown</button>
        <button class="tab-btn" onclick="switchTab('pallets')">Pallet Details</button>
    </div>

    <!-- Deliveries Tab -->
    <div id="tab-deliveries" class="tab-content active">
        <!-- Pagination Controls (Deliveries) -->
        <?php if (!empty($deliveries)): ?>
            <?php
                $delivery_start = $delivery_offset + 1;
                $delivery_end = min($delivery_offset + count($deliveries_paginated), $delivery_total);
                $delivery_query_params = $_GET;
                unset($delivery_query_params['delivery_page'], $delivery_query_params['export'], $delivery_query_params['export_pallets']);
                $delivery_query_params['delivery_limit'] = $delivery_limit;
                $delivery_query_string = http_build_query($delivery_query_params);
                $delivery_prev_url = '?' . $delivery_query_string . '&delivery_page=' . max(1, $delivery_page - 1);
                $delivery_next_url = '?' . $delivery_query_string . '&delivery_page=' . ($delivery_page + 1);
            ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    <span>Showing <?php echo $delivery_start; ?> - <?php echo $delivery_end; ?> of <?php echo $delivery_total; ?> deliveries</span>
                </div>
                <div class="pagination-controls">
                    <form id="deliveryLimitForm" method="get" style="display: flex; align-items: center; gap: 8px; margin: 0;">
                        <label for="itemsPerPage">Show:</label>
                        <input type="number" name="delivery_limit" id="itemsPerPage" value="<?php echo $delivery_limit; ?>" min="1" max="500" style="width: 80px;" onchange="document.getElementById('deliveryLimitForm').submit();">
                        <label>per page</label>
                        <input type="hidden" name="delivery_page" value="1">
                        <?php foreach ($delivery_query_params as $key => $value): ?>
                            <?php if (in_array($key, ['delivery_limit'])) continue; ?>
                            <input type="hidden" name="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endforeach; ?>
                    </form>
                    <button type="button" onclick="window.location.href='<?php echo htmlspecialchars($delivery_prev_url, ENT_QUOTES, 'UTF-8'); ?>';" <?php echo $delivery_page <= 1 ? 'disabled' : ''; ?>>Previous</button>
                    <span id="pageInfo">Page <?php echo $delivery_page; ?> of <?php echo $delivery_total_pages; ?></span>
                    <button type="button" onclick="window.location.href='<?php echo htmlspecialchars($delivery_next_url, ENT_QUOTES, 'UTF-8'); ?>';" <?php echo ($delivery_page >= $delivery_total_pages) ? 'disabled' : ''; ?>>Next</button>
                </div>
            </div>
        <?php endif; ?>

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
                <?php if (!empty($deliveries_paginated)): ?>
                    <?php foreach ($deliveries_paginated as $d): ?>
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
    </div>

    <!-- Pallets Tab -->
    <div id="tab-pallets" class="tab-content">
        <?php if (!empty($pallets_data)): ?>
            <?php
                $pallet_start = $total_pallets_found > 0 ? ($pallet_offset + 1) : 0;
                $pallet_end = $total_pallets_found > 0 ? min($pallet_offset + count($pallets_data), $total_pallets_found) : 0;
                $pallet_query_params = $_GET;
                unset($pallet_query_params['pallet_page'], $pallet_query_params['export'], $pallet_query_params['export_pallets']);
                $pallet_query_params['pallet_limit'] = $pallet_limit;
                $pallet_query_string = http_build_query($pallet_query_params);
                $pallet_total_pages = max(1, $total_pallet_pages);
                $prev_page_url = '?' . $pallet_query_string . '&pallet_page=' . max(1, $pallet_page - 1);
                $next_page_url = '?' . $pallet_query_string . '&pallet_page=' . ($pallet_page + 1);
            ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    <span>Showing <?php echo $pallet_start; ?> - <?php echo $pallet_end; ?> of <?php echo $total_pallets_found; ?> pallets</span>
                </div>
                <div class="pagination-controls">
                    <form id="palletLimitForm" method="get" style="display: flex; align-items: center; gap: 8px; margin: 0;">
                        <label for="palletsPerPage">Show:</label>
                        <input type="number" name="pallet_limit" id="palletsPerPage" value="<?php echo $pallet_limit; ?>" min="1" max="500" style="width: 80px;" onchange="document.getElementById('palletLimitForm').submit();">
                        <label>per page</label>
                        <input type="hidden" name="pallet_page" value="1">
                        <?php foreach ($pallet_query_params as $key => $value): ?>
                            <?php if (in_array($key, ['pallet_limit'])) continue; ?>
                            <input type="hidden" name="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endforeach; ?>
                    </form>
                    <button type="button" onclick="window.location.href='<?php echo htmlspecialchars($prev_page_url, ENT_QUOTES, 'UTF-8'); ?>';" <?php echo $pallet_page <= 1 ? 'disabled' : ''; ?>>Previous</button>
                    <span id="palletPageInfo">Page <?php echo $pallet_page; ?> of <?php echo $pallet_total_pages; ?></span>
                    <button type="button" onclick="window.location.href='<?php echo htmlspecialchars($next_page_url, ENT_QUOTES, 'UTF-8'); ?>';" <?php echo ($pallet_page >= $total_pallet_pages) ? 'disabled' : ''; ?>>Next</button>
                </div>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <div class="table-header">
                <h2 class="table-title">
                    <i class="fas fa-pallet"></i>
                    Individual Pallet Costs
                </h2>
                <div class="table-header-actions">
                    <button type="submit" form="filterForm" name="export_pallets" value="1" class="btn-export-header">
                        <i class="fas fa-download"></i>
                        Export CSV
                    </button>
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Pallet ID</th>
                            <th>Manufacturer</th>
                            <th>Wattage</th>
                            <th>Quantity</th>
                            <th>Status</th>
                            <th>Warehouse Cost</th>
                            <th>Freight Cost</th>
                            <th>Accessorial Cost</th>
                            <th>Total Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($pallets_data)): ?>
                            <?php foreach ($pallets_data as $p): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($p['pallet_identifier']); ?></td>
                                    <td><?php echo htmlspecialchars($p['manufacturer'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($p['wattage']); ?>W</td>
                                    <td><?php echo number_format($p['quantity']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $p['status'])); ?>">
                                            <?php echo htmlspecialchars($p['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        $<?php echo number_format($p['display_warehouse_cost'] ?? 0, 2); ?>
                                        <?php if (!empty($p['pending_warehouse_cost'])): ?>
                                            <span style="font-size:0.8em; color:#d97706;" title="Pending/Accruing cost included">
                                                incl. $<?php echo number_format($p['pending_warehouse_cost'], 2); ?> pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>$<?php echo number_format($p['freight_cost'] ?? 0, 2); ?></td>
                                    <td>$<?php echo number_format($p['accessorial_cost'] ?? 0, 2); ?></td>
                                    <td style="font-weight: 600; color: #488C9A;">$<?php echo number_format($p['total_cost'] ?? 0, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px; color: #6c757d;">
                                    <p>No pallets found matching criteria.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
    function switchTab(tabName) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        
        // Show selected
        document.getElementById('tab-' + tabName).classList.add('active');
        
        // Activate button
        const btns = document.querySelectorAll('.tab-btn');
        btns.forEach(btn => {
            if (btn.getAttribute('onclick').includes(tabName)) {
                btn.classList.add('active');
            }
        });
        
        // Store preference
        localStorage.setItem('cost_details_tab', tabName);
    }
    
    // Restore tab on load
    document.addEventListener('DOMContentLoaded', () => {
        const savedTab = localStorage.getItem('cost_details_tab');
        if (savedTab) {
            switchTab(savedTab);
        }
    });
</script>
</body>
</html>
