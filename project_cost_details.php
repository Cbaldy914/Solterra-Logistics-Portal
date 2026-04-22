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
require_once 'milestone_helpers.php';
require_once 'projection_helpers.php';
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

if ($role === 'admin' || $role === 'global_admin' || $role === 'customer_admin') {
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
$status_context  = $_GET['status_context'] ?? 'pallets';
if (!in_array($status_context, ['deliveries', 'pallets'], true)) {
    $status_context = 'pallets';
}
$statusConditionDeliveries = "";
$statusConditionPallets = "";

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
$filter        = 'total';
$current_year  = date('Y');
$ytdConditionDeliveries = "";
$ytdConditionPallets = "";

if ($filter === 'ytd') {
    $ytdConditionDeliveries = " AND YEAR(deliveries.created_at) = ?";
    $ytdConditionPallets = " AND YEAR(del.created_at) = ?";
    $paramTypes  .= "i";
    $params[]     = $current_year;
}

$paramTypesDeliveries = $paramTypes;
$paramsDeliveries = $params;
$paramTypesPallets = $paramTypes;
$paramsPallets = $params;

if (!empty($status_filter)) {
    if ($status_context === 'deliveries') {
        $statusConditionDeliveries = " AND status_of_delivery = ?";
        $paramTypesDeliveries .= "s";
        $paramsDeliveries[] = $status_filter;
    } else {
        $statusConditionPallets = " AND ip.status = ?";
        $paramTypesPallets .= "s";
        $paramsPallets[] = $status_filter;
    }
}

// Build final deliveries query
$sql_deliveries = "
    SELECT *
    FROM deliveries
    WHERE project_id = ?
          $ytdConditionDeliveries
          $dateCondition
          $statusConditionDeliveries
          $manufacturerCondition
          $searchCondition
    ORDER BY $filterColumn DESC
";
$stmt_deliveries = $conn->prepare($sql_deliveries);
$stmt_deliveries->bind_param($paramTypesDeliveries, ...$paramsDeliveries);
$stmt_deliveries->execute();
$deliveries_result = $stmt_deliveries->get_result();
$stmt_deliveries->close();

// We'll fetch warehouse info per delivery instead of assuming one warehouse per project

// Helper for warehousing cost - now calculates based on actual pallet data like warehouse_info.php
function calculateDeliveryWarehousingCost($delivery, $conn) {
    if (!$conn) {
        return 0;
    }

    $delivery_id = $delivery['id'] ?? null;
    $warehouse_id = $delivery['warehouse_id'] ?? null;

    // Use origin warehouse when this delivery represents pallets leaving storage
    if ((!$warehouse_id || $warehouse_id <= 0) && (($delivery['origin_type'] ?? '') === 'warehouse')) {
        $warehouse_id = $delivery['origin_id'] ?? null;
    }

    if (empty($delivery_id) || empty($warehouse_id)) {
        return 0;
    }

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
                // Use += to accumulate multiple fees of same trigger type
                switch ($cost['trigger_event']) {
                    case 'entry':
                        $warehouse_costs[$wid]['in_fee'] += (float)$cost['amount'];
                        break;
                    case 'exit':
                        $warehouse_costs[$wid]['out_fee'] += (float)$cost['amount'];
                        break;
                    case 'monthly':
                        $warehouse_costs[$wid]['monthly_storage_fee'] += (float)$cost['amount'];
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

// Calculate freight/accessorial share for a pallet using BOL-level totals (matches pallet_details.php logic)
function calculatePalletFreightAccessorialShares($pallet_id, $conn) {
    $shares = ['freight' => 0.0, 'accessorial' => 0.0];
    if (!$pallet_id || !$conn) {
        return $shares;
    }

    $bol_sql = "
        SELECT d.bol_number,
               COALESCE((SELECT SUM(COALESCE(d2.freight_cost, 0))
                         FROM deliveries d2
                         WHERE d2.bol_number = d.bol_number AND d2.bol_number IS NOT NULL AND d2.bol_number != ''), 0) AS bol_total_freight,
               COALESCE((SELECT SUM(COALESCE(d2.accessorial_costs, 0))
                         FROM deliveries d2
                         WHERE d2.bol_number = d.bol_number AND d2.bol_number IS NOT NULL AND d2.bol_number != ''), 0) AS bol_total_accessorial,
               COALESCE((SELECT COUNT(DISTINCT dp2.inventory_pallet_id)
                         FROM deliveries d3
                         JOIN delivery_pallets dp2 ON d3.id = dp2.delivery_id
                         WHERE d3.bol_number = d.bol_number AND d3.bol_number IS NOT NULL AND d3.bol_number != ''), 0) AS bol_total_pallets
        FROM deliveries d
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        WHERE dp.inventory_pallet_id = ?
          AND d.bol_number IS NOT NULL AND d.bol_number != ''
        GROUP BY d.bol_number
    ";

    $seen_bols = [];
    $stmt_bol = $conn->prepare($bol_sql);
    if ($stmt_bol) {
        $stmt_bol->bind_param("i", $pallet_id);
        $stmt_bol->execute();
        $res_bol = $stmt_bol->get_result();
        while ($row = $res_bol->fetch_assoc()) {
            $bol = $row['bol_number'];
            if (!$bol || isset($seen_bols[$bol])) {
                continue;
            }
            $seen_bols[$bol] = true;

            $pallets_in_bol = (int)$row['bol_total_pallets'];
            if ($pallets_in_bol > 0) {
                $shares['freight']     += ((float)$row['bol_total_freight']) / $pallets_in_bol;
                $shares['accessorial'] += ((float)$row['bol_total_accessorial']) / $pallets_in_bol;
            }
        }
        $stmt_bol->close();
    }

    // Fallback for deliveries without BOL numbers
    if ($shares['freight'] <= 0 && $shares['accessorial'] <= 0) {
        $del_sql = "
            SELECT d.id,
                   COALESCE(d.freight_cost, 0) AS freight_cost,
                   COALESCE(d.accessorial_costs, 0) AS accessorial_cost,
                   (SELECT COUNT(DISTINCT dp2.inventory_pallet_id)
                    FROM delivery_pallets dp2
                    WHERE dp2.delivery_id = d.id) AS pallet_count
            FROM deliveries d
            JOIN delivery_pallets dp ON d.id = dp.delivery_id
            WHERE dp.inventory_pallet_id = ?
        ";
        $stmt_del = $conn->prepare($del_sql);
        if ($stmt_del) {
            $stmt_del->bind_param("i", $pallet_id);
            $stmt_del->execute();
            $res_del = $stmt_del->get_result();
            while ($row = $res_del->fetch_assoc()) {
                $count = (int)($row['pallet_count'] ?? 0);
                if ($count > 0) {
                    $shares['freight']     += ((float)$row['freight_cost']) / $count;
                    $shares['accessorial'] += ((float)$row['accessorial_cost']) / $count;
                }
            }
            $stmt_del->close();
        }
    }

    return $shares;
}

// Normalize pallet cost figures for display/export
function enrichPalletCostData(array $row, $conn) {
    $recorded_cost    = isset($row['warehouse_cost']) ? (float)$row['warehouse_cost'] : 0.0;
    $freight_cost     = isset($row['freight_cost']) ? (float)$row['freight_cost'] : 0.0;
    $accessorial_cost = isset($row['accessorial_cost']) ? (float)$row['accessorial_cost'] : 0.0;
    $customs_hold_cost = isset($row['customs_hold_cost']) ? (float)$row['customs_hold_cost'] : 0.0;
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

    // Prefer BOL-based freight/accessorial shares (matches pallet_details.php)
    $computed_shares = calculatePalletFreightAccessorialShares((int)($row['id'] ?? 0), $conn);
    if ($computed_shares['freight'] > 0 || $computed_shares['accessorial'] > 0) {
        $freight_cost     = $computed_shares['freight'];
        $accessorial_cost = $computed_shares['accessorial'];
    } else {
        if ($freight_cost <= 0 && $fallback_freight > 0) {
            $freight_cost = $fallback_freight;
        }
        if ($accessorial_cost <= 0 && $fallback_accessorial > 0) {
            $accessorial_cost = $fallback_accessorial;
        }
    }

    $row['pending_warehouse_cost']  = $pending_cost;
    $row['calculated_warehouse_cost'] = $recorded_cost;
    $row['display_warehouse_cost']  = $recorded_cost + $pending_cost;
    $row['freight_cost']            = $freight_cost;
    $row['customs_hold_cost']       = $customs_hold_cost;
    $row['accessorial_cost']        = $accessorial_cost + $customs_hold_cost;
    $module_cost = null;
    if (array_key_exists('module_cost', $row)) {
        $module_cost = $row['module_cost'] !== null ? (float)$row['module_cost'] : null;
    }

    $row['module_cost']             = $module_cost;
    $row['total_cost']              = $row['display_warehouse_cost'] + $freight_cost + $row['accessorial_cost'] + ($module_cost ?? 0);

    return $row;
}

// Initialize totals - ensure they are never null
$total_customer_cost     = 0.0; // Freight total
$total_accessorial_costs = 0.0;
$total_warehousing_cost  = 0.0;
$total_solterra_fee      = 0.0;
$total_logistics_cost    = 0.0;

$total_quantity         = 0;
$total_wattage_quantity = 0.0;
$total_pallets_count    = 0;
$filtered_module_cost   = 0.0;
$filtered_has_module_cost = false;

$project_milestone_status = get_milestone_completion_status($project_id, $conn);
$project_has_milestones = $project_milestone_status['total_contract_value'] > 0;

$module_milestone_breakdown = [
    'po_execution' => 0.0,
    'shipping' => 0.0,
    'customs_cleared' => 0.0,
    'project_delivery' => 0.0
];

if ($project_has_milestones) {
    $stmt_breakdown = $conn->prepare("
        SELECT
            mbm.trigger_event,
            COALESCE(SUM(
                CASE
                    WHEN mbm.trigger_event = 'shipping' AND d.origin_type = 'warehouse' THEN 0
                    ELSE dmi.payment_amount
                END
            ), 0) as total_amount
        FROM delivery_milestone_instances dmi
        JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
        JOIN modules m ON mbm.module_id = m.id
        LEFT JOIN deliveries d ON dmi.delivery_id = d.id
        WHERE m.project_id = ?
        GROUP BY mbm.trigger_event
    ");
    if ($stmt_breakdown) {
        $stmt_breakdown->bind_param('i', $project_id);
        $stmt_breakdown->execute();
        $breakdown_result = $stmt_breakdown->get_result();
        while ($row = $breakdown_result->fetch_assoc()) {
            $trigger = $row['trigger_event'] ?? '';
            if (isset($module_milestone_breakdown[$trigger])) {
                $module_milestone_breakdown[$trigger] = (float)$row['total_amount'];
            }
        }
        $stmt_breakdown->close();
    }
}

function calculateProjectWarehousingCostAccrued($project_id, $conn) {
    if (!$project_id || !$conn) {
        return 0.0;
    }

    $stmt = $conn->prepare("
        SELECT DISTINCT ip.id, ip.warehouse_cost, ip.current_warehouse_id, ip.arrival_date, ip.status
        FROM inventory_pallets ip
        JOIN delivery_pallets dp ON dp.inventory_pallet_id = ip.id
        JOIN deliveries d ON dp.delivery_id = d.id
        WHERE d.project_id = ?
    ");

    if (!$stmt) {
        return 0.0;
    }

    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $total_cost = 0.0;
    while ($row = $result->fetch_assoc()) {
        $recorded_cost = isset($row['warehouse_cost']) ? (float)$row['warehouse_cost'] : 0.0;
        $pending_cost = 0.0;

        if ($recorded_cost > 0 && ($row['status'] ?? '') === 'In Warehouse'
            && !empty($row['current_warehouse_id']) && !empty($row['arrival_date'])) {
            $estimated_total = calculate_pallet_storage_cost(
                $row['current_warehouse_id'],
                $row['arrival_date'],
                date('Y-m-d H:i:s'),
                $conn
            );
            $pending_cost = max(0, $estimated_total - $recorded_cost);
        }

        if ($recorded_cost <= 0) {
            $recorded_cost = calculatePalletWarehousingCostFallback((int)$row['id'], $conn);
        }

        $total_cost += ($recorded_cost + $pending_cost);
    }
    $stmt->close();

    return $total_cost;
}

$module_trigger_labels = [
    'po_execution' => 'PO Execution',
    'shipping' => 'Shipping',
    'customs_cleared' => 'Customs Cleared',
    'project_delivery' => 'Project Delivery'
];

$module_contract_value = (float)($project_milestone_status['total_contract_value'] ?? 0.0);

$module_milestone_targets = [];
$stmt_targets = $conn->prepare("
    SELECT mbm.trigger_event,
           COALESCE(SUM(batch.batch_value * (mbm.percentage / 100)), 0) as target_amount
    FROM module_batch_milestones mbm
    JOIN (
        SELECT m.id as module_id,
               m.cost_per_watt,
               SUM(umi.wattage * umi.quantity) as total_watts,
               (m.cost_per_watt * SUM(umi.wattage * umi.quantity)) as batch_value
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ? AND m.cost_per_watt IS NOT NULL
        GROUP BY m.id
    ) batch ON batch.module_id = mbm.module_id
    WHERE mbm.is_active = 1
    GROUP BY mbm.trigger_event
");
if ($stmt_targets) {
    $stmt_targets->bind_param('i', $project_id);
    $stmt_targets->execute();
    $targets_result = $stmt_targets->get_result();
    while ($row = $targets_result->fetch_assoc()) {
        $trigger = $row['trigger_event'] ?? '';
        if ($trigger !== '') {
            $module_milestone_targets[$trigger] = (float)$row['target_amount'];
        }
    }
    $stmt_targets->close();
}

$module_configured_triggers = [];
$stmt_configured = $conn->prepare("
    SELECT DISTINCT mbm.trigger_event
    FROM module_batch_milestones mbm
    JOIN modules m ON mbm.module_id = m.id
    WHERE m.project_id = ? AND mbm.is_active = 1
");
if ($stmt_configured) {
    $stmt_configured->bind_param('i', $project_id);
    $stmt_configured->execute();
    $configured_result = $stmt_configured->get_result();
    while ($row = $configured_result->fetch_assoc()) {
        $trigger = $row['trigger_event'] ?? '';
        if ($trigger !== '') {
            $module_configured_triggers[$trigger] = true;
        }
    }
    $stmt_configured->close();
}

$module_milestone_rows = [];
foreach ($module_trigger_labels as $trigger => $label) {
    if (!empty($module_configured_triggers[$trigger])) {
        $target_amount = $module_milestone_targets[$trigger] ?? 0.0;
        $percentage = $module_contract_value > 0
            ? ($target_amount / $module_contract_value) * 100
            : null;
        $module_milestone_rows[] = [
            'trigger' => $trigger,
            'label' => $label,
            'amount' => $module_milestone_breakdown[$trigger] ?? 0.0,
            'target_amount' => $target_amount,
            'percentage' => $percentage
        ];
    }
}

$module_batch_has_milestones = [];
$module_batch_watts = [];
$module_batch_triggered_totals = [];
$delivery_milestone_totals = [];

$stmt_milestone_batches = $conn->prepare("
    SELECT DISTINCT mbm.module_id
    FROM module_batch_milestones mbm
    JOIN modules m ON mbm.module_id = m.id
    WHERE m.project_id = ? AND mbm.is_active = 1
");
if ($stmt_milestone_batches) {
    $stmt_milestone_batches->bind_param('i', $project_id);
    $stmt_milestone_batches->execute();
    $batch_result = $stmt_milestone_batches->get_result();
    while ($row = $batch_result->fetch_assoc()) {
        $module_batch_has_milestones[(int)$row['module_id']] = true;
    }
    $stmt_milestone_batches->close();
}

$stmt_batch_watts = $conn->prepare("
    SELECT m.id as module_id, COALESCE(SUM(umi.wattage * umi.quantity), 0) as total_watts
    FROM modules m
    JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
    WHERE m.project_id = ?
    GROUP BY m.id
");
if ($stmt_batch_watts) {
    $stmt_batch_watts->bind_param('i', $project_id);
    $stmt_batch_watts->execute();
    $watts_result = $stmt_batch_watts->get_result();
    while ($row = $watts_result->fetch_assoc()) {
        $module_batch_watts[(int)$row['module_id']] = (float)$row['total_watts'];
    }
    $stmt_batch_watts->close();
}

$stmt_batch_triggered = $conn->prepare("
    SELECT dmi.module_batch_id, mbm.trigger_event, COALESCE(SUM(dmi.payment_amount), 0) as total_amount
    FROM delivery_milestone_instances dmi
    JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
    JOIN modules m ON mbm.module_id = m.id
    WHERE m.project_id = ? AND dmi.module_batch_id IS NOT NULL AND dmi.delivery_id IS NULL
    GROUP BY dmi.module_batch_id, mbm.trigger_event
");
if ($stmt_batch_triggered) {
    $stmt_batch_triggered->bind_param('i', $project_id);
    $stmt_batch_triggered->execute();
    $triggered_result = $stmt_batch_triggered->get_result();
    while ($row = $triggered_result->fetch_assoc()) {
        $batch_id = (int)$row['module_batch_id'];
        $trigger = $row['trigger_event'] ?? '';
        if (!isset($module_batch_triggered_totals[$batch_id])) {
            $module_batch_triggered_totals[$batch_id] = [];
        }
        $module_batch_triggered_totals[$batch_id][$trigger] = (float)$row['total_amount'];
    }
    $stmt_batch_triggered->close();
}

$stmt_delivery_triggered = $conn->prepare("
    SELECT
        dmi.delivery_id,
        COALESCE(SUM(
            CASE
                WHEN mbm.trigger_event = 'shipping' AND d.origin_type = 'warehouse' THEN 0
                ELSE dmi.payment_amount
            END
        ), 0) as total_amount
    FROM delivery_milestone_instances dmi
    JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
    JOIN deliveries d ON dmi.delivery_id = d.id
    WHERE d.project_id = ?
    GROUP BY dmi.delivery_id
");
if ($stmt_delivery_triggered) {
    $stmt_delivery_triggered->bind_param('i', $project_id);
    $stmt_delivery_triggered->execute();
    $delivery_result = $stmt_delivery_triggered->get_result();
    while ($row = $delivery_result->fetch_assoc()) {
        $delivery_milestone_totals[(int)$row['delivery_id']] = (float)$row['total_amount'];
    }
    $stmt_delivery_triggered->close();
}

$pallet_accrued_module_costs = [];
$pallet_watts_map = [];
$pallet_batch_map = [];
$pallet_has_milestones_map = [];

$deliveries = [];

// Prepare statement for fetching pallets for efficiency
$stmtPallets = $conn->prepare("SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status, ip.current_warehouse_id, ip.arrival_date, ip.warehouse_cost, ip.freight_cost, ip.accessorial_cost, ip.customs_hold_cost, m.id AS module_batch_id, m.cost_per_watt, CASE WHEN m.cost_per_watt IS NOT NULL THEN (m.cost_per_watt * ip.wattage * ip.quantity) ELSE NULL END AS module_cost FROM delivery_pallets dp JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id LEFT JOIN modules m ON umi.unassigned_module_id = m.id WHERE dp.delivery_id = ? ORDER BY ip.id");

while ($delivery = $deliveries_result->fetch_assoc()) {
    $quantity          = (int)($delivery['quantity'] ?? 0);
    $wattage           = (float)($delivery['wattage'] ?? 0);
    $total_quantity         += $quantity;
    $total_wattage_quantity += ($quantity * $wattage);

    if (!empty($delivery['warehouse_id'])) {
        $warehouse_ids_for_lookup[(int)$delivery['warehouse_id']] = true;
    }
    if (!empty($delivery['origin_type']) && $delivery['origin_type'] === 'warehouse' && !empty($delivery['origin_id'])) {
        $warehouse_ids_for_lookup[(int)$delivery['origin_id']] = true;
    }

    // Base costs straight from deliveries table
    $delivery_freight_cost     = (float)($delivery['freight_cost'] ?? $delivery['customer_cost'] ?? 0);
    $delivery_accessorial_cost = (float)($delivery['accessorial_costs'] ?? 0);
    $delivery_warehousing_cost = 0.0;

    // Fetch associated pallets for this delivery and build cost rollups
    $associatedPallets = [];
    $palletCount = 0;
    $warehouse_for_costs = (int)($delivery['warehouse_id'] ?? 0);
    if (!$warehouse_for_costs && (($delivery['origin_type'] ?? '') === 'warehouse')) {
        $warehouse_for_costs = (int)($delivery['origin_id'] ?? 0);
    }

    $rates = getWarehouseCostRates($warehouse_for_costs, $conn);
    $is_inbound  = !empty($delivery['warehouse_arrival_date']) && (empty($delivery['origin_type']) || $delivery['origin_type'] !== 'warehouse');
    $is_outbound = !empty($delivery['left_warehouse_date']) && (!empty($delivery['origin_type']) && $delivery['origin_type'] === 'warehouse');

    $fallback_freight_share = 0.0;
    $fallback_accessorial_share = 0.0;
    $delivery_module_cost = 0.0;
    $delivery_has_module_cost = false;
    $delivery_full_milestone_cost = 0.0;
    $pallet_full_module_costs = [];
    $pallet_has_milestones = [];

    if ($stmtPallets) {
        $stmtPallets->bind_param("i", $delivery['id']);
        $stmtPallets->execute();
        $palletsResult = $stmtPallets->get_result();
        $seenPallets = [];
        while ($palletRow = $palletsResult->fetch_assoc()) {
            $palletId = $palletRow['id'] ?? null;
            if ($palletId && isset($seenPallets[$palletId])) {
                continue;
            }
            if ($palletId) {
                $seenPallets[$palletId] = true;
                if (!empty($palletRow['current_warehouse_id'])) {
                    $warehouse_ids_for_lookup[(int)$palletRow['current_warehouse_id']] = true;
                }
            }

            $associatedPallets[] = $palletRow;
            $enriched = enrichPalletCostData($palletRow, $conn);
            $module_batch_id = $palletRow['module_batch_id'] ?? null;
            $batch_has_milestones = $module_batch_id && isset($module_batch_has_milestones[(int)$module_batch_id]);

            if ($palletId && !isset($pallet_batch_map[$palletId])) {
                $pallet_batch_map[$palletId] = $module_batch_id ? (int)$module_batch_id : null;
                $pallet_watts_map[$palletId] = (float)($palletRow['wattage'] ?? 0) * (float)($palletRow['quantity'] ?? 0);
            }

            if ($palletId && !isset($pallet_has_milestones_map[$palletId])) {
                $pallet_has_milestones_map[$palletId] = $batch_has_milestones;
            }

            // Warehousing split: outbound shows storage+exit, inbound shows entry, active storage shows accrual
            $pallet_wh_cost = 0.0;
            $pallet_status = strtolower($palletRow['status'] ?? '');
            $is_currently_stored = ($pallet_status === 'in warehouse');

            if ($is_outbound) {
                if (!empty($palletRow['arrival_date']) && !empty($delivery['left_warehouse_date']) && $warehouse_for_costs) {
                    $total_cost = calculate_pallet_storage_cost($warehouse_for_costs, $palletRow['arrival_date'], $delivery['left_warehouse_date'], $conn);
                    $pallet_wh_cost = max(0, $total_cost - $rates['in_fee']); // remove entry already counted on inbound
                } else {
                    $pallet_wh_cost = $rates['out_fee'];
                }
            } elseif ($is_currently_stored) {
                $pallet_wh_cost = $enriched['display_warehouse_cost'] ?? $rates['in_fee'];
            } elseif ($is_inbound) {
                $pallet_wh_cost = $rates['in_fee'];
            } else {
                $pallet_wh_cost = $enriched['display_warehouse_cost'] ?? 0;
            }

            $delivery_warehousing_cost += $pallet_wh_cost;

            if ($enriched['module_cost'] !== null) {
                $delivery_has_module_cost = true;
                $pallet_full_module_costs[$palletId] = (float)$enriched['module_cost'];
                $pallet_has_milestones[$palletId] = $batch_has_milestones;
                if ($batch_has_milestones) {
                    $delivery_full_milestone_cost += (float)$enriched['module_cost'];
                }
            }

            // Track fallback shares only if base costs are missing
            if ($delivery_freight_cost <= 0) {
                $fallback_freight_share += $enriched['freight_cost'] ?? 0;
            }
            if ($delivery_accessorial_cost <= 0) {
                $fallback_accessorial_share += $enriched['accessorial_cost'] ?? 0;
            }
        }
        $palletCount = count($associatedPallets);
        $total_pallets_count += $palletCount;
    }

    $delivery_triggered_total = $delivery_milestone_totals[(int)$delivery['id']] ?? 0.0;
    $delivery_ratio = $delivery_full_milestone_cost > 0
        ? min(1.0, max(0.0, $delivery_triggered_total / $delivery_full_milestone_cost))
        : 0.0;

    foreach ($pallet_full_module_costs as $palletId => $full_cost) {
        $uses_milestones = $pallet_has_milestones[$palletId] ?? false;
        $accrued_cost = $uses_milestones ? ($full_cost * $delivery_ratio) : $full_cost;
        if ($full_cost !== null) {
            $pallet_accrued_module_costs[$palletId] = ($pallet_accrued_module_costs[$palletId] ?? 0.0) + $accrued_cost;
            $delivery_module_cost += $accrued_cost;
        }
    }

    // Fallbacks when delivery record lacks costs
    if ($delivery_freight_cost <= 0 && $palletCount > 0) {
        $delivery_freight_cost = $fallback_freight_share;
    }
    if ($delivery_accessorial_cost <= 0 && $palletCount > 0) {
        $delivery_accessorial_cost = $fallback_accessorial_share;
    }
    if ($delivery_warehousing_cost <= 0 && $palletCount > 0) {
        $delivery_warehousing_cost = calculateDeliveryWarehousingCost($delivery, $conn);
    }

    $delivery['module_cost'] = $delivery_has_module_cost ? $delivery_module_cost : null;
    $total_warehousing_cost += $delivery_warehousing_cost;

    // Solterra fee only if actual_delivery_date
    if (!empty($delivery['actual_delivery_date'])) {
        $solterraFeeForThisDelivery = $solterra_fee * ($wattage * $quantity);
    } else {
        $solterraFeeForThisDelivery = 0;
    }
    $total_solterra_fee += $solterraFeeForThisDelivery;

    $line_total = $delivery_freight_cost + $delivery_accessorial_cost + $delivery_warehousing_cost + $solterraFeeForThisDelivery;
    $display_total_freight_only = $delivery_freight_cost + $delivery_accessorial_cost;

    // Calculate cost per pallet for this delivery
    $cost_per_pallet = ($palletCount > 0) ? ($line_total / $palletCount) : 0;

    // Totals rollup (freight/accessorial direct from deliveries table when present)
    $total_customer_cost     += $delivery_freight_cost;
    $total_accessorial_costs += $delivery_accessorial_cost;
    // Warehousing total tracked from delivery rollup

    // For display
    $delivery['warehousing_cost']     = $delivery_warehousing_cost;
    $delivery['customer_cost']        = $delivery_freight_cost;
    $delivery['accessorial_costs']    = $delivery_accessorial_cost;
    $delivery['solterra_fee']         = $solterraFeeForThisDelivery;
    $delivery['total_logistics_cost'] = $line_total;
    $delivery['display_total_freight_only'] = $display_total_freight_only;
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

foreach ($pallet_batch_map as $pallet_id => $batch_id) {
    if (!$batch_id || empty($module_batch_triggered_totals[$batch_id])) {
        continue;
    }
    $batch_total_triggered = array_sum($module_batch_triggered_totals[$batch_id]);
    if ($batch_total_triggered <= 0) {
        continue;
    }
    $batch_total_watts = $module_batch_watts[$batch_id] ?? 0.0;
    $pallet_watts = $pallet_watts_map[$pallet_id] ?? 0.0;
    if ($batch_total_watts <= 0 || $pallet_watts <= 0) {
        continue;
    }
    $pallet_share = ($pallet_watts / $batch_total_watts) * $batch_total_triggered;
    $pallet_accrued_module_costs[$pallet_id] = ($pallet_accrued_module_costs[$pallet_id] ?? 0.0) + $pallet_share;
}

$filtered_module_cost = 0.0;
$filtered_has_module_cost = false;
foreach ($pallet_accrued_module_costs as $accrued_cost) {
    if ($accrued_cost !== null) {
        $filtered_module_cost += $accrued_cost;
        $filtered_has_module_cost = true;
    }
}

$pallet_statuses = [];
$status_sql = "
    SELECT DISTINCT ip.status
    FROM inventory_pallets ip
    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
    JOIN deliveries d ON dp.delivery_id = d.id
    WHERE d.project_id = ?
      AND ip.status IS NOT NULL
      AND ip.status != ''
    ORDER BY ip.status
";
$stmt_status = $conn->prepare($status_sql);
if ($stmt_status) {
    $stmt_status->bind_param('i', $project_id);
    $stmt_status->execute();
    $status_result = $stmt_status->get_result();
    while ($status_row = $status_result->fetch_assoc()) {
        $pallet_statuses[] = $status_row['status'];
    }
    $stmt_status->close();
}

$total_logistics_cost = $total_customer_cost + $total_accessorial_costs + $total_warehousing_cost + $total_solterra_fee;
$filtered_total_cost = $total_logistics_cost + $filtered_module_cost;
$filtered_logistics_per_watt = $total_wattage_quantity > 0 ? ($total_logistics_cost / $total_wattage_quantity) : null;
$filtered_total_cost_per_watt = $total_wattage_quantity > 0 ? ($filtered_total_cost / $total_wattage_quantity) : null;
$filtered_module_cost_per_watt = ($filtered_has_module_cost && $total_wattage_quantity > 0)
    ? ($filtered_module_cost / $total_wattage_quantity)
    : null;
$filtered_freight_per_watt = $total_wattage_quantity > 0 ? ($total_customer_cost / $total_wattage_quantity) : null;
$filtered_accessorial_per_watt = $total_wattage_quantity > 0 ? ($total_accessorial_costs / $total_wattage_quantity) : null;
$filtered_warehousing_per_watt = $total_wattage_quantity > 0 ? ($total_warehousing_cost / $total_wattage_quantity) : null;
$filtered_solterra_per_watt = $total_wattage_quantity > 0 ? ($total_solterra_fee / $total_wattage_quantity) : null;

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

// Track warehouse IDs for pretty status labels
$warehouse_ids_for_lookup = [];

// Build Pallet Query
// We want pallets associated with the *filtered* deliveries to match the "breakdown" concept.
// We reuse the same parameters as the delivery query, but we need to adjust the query structure.
$pallet_base_sql = "
    SELECT 
        ip.id, ip.pallet_identifier, ip.status, ip.manufacturer, ip.quantity, ip.wattage,
        ip.warehouse_cost, ip.freight_cost, ip.accessorial_cost, ip.customs_hold_cost,
        m.id AS module_batch_id,
        m.cost_per_watt,
        CASE
            WHEN m.cost_per_watt IS NOT NULL THEN (m.cost_per_watt * ip.wattage * ip.quantity)
            ELSE NULL
        END AS module_cost,
        ip.current_warehouse_id, ip.arrival_date,
        AVG(del.customer_cost / NULLIF(dc.pallet_count, 0)) AS fallback_freight_share,
        AVG(del.accessorial_costs / NULLIF(dc.pallet_count, 0)) AS fallback_accessorial_share
    FROM inventory_pallets ip
    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
    JOIN deliveries del ON dp.delivery_id = del.id
    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
    JOIN (
        SELECT delivery_id, COUNT(DISTINCT inventory_pallet_id) AS pallet_count
        FROM delivery_pallets
        GROUP BY delivery_id
    ) dc ON dc.delivery_id = del.id
    WHERE del.project_id = ?
          $ytdConditionPallets
          $dateCondition
          $statusConditionPallets
          $manufacturerCondition
          $searchCondition
    GROUP BY ip.id
";

$pallet_params = $paramsPallets;
$pallet_params[] = $pallet_offset;
$pallet_params[] = $pallet_limit;
$pallet_types = $paramTypesPallets . "ii";

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
        $pallet_id = (int)($p_row['id'] ?? 0);
        if ($pallet_id && array_key_exists($pallet_id, $pallet_accrued_module_costs)) {
            $p_row['module_cost'] = $pallet_accrued_module_costs[$pallet_id];
        } elseif ($p_row['module_cost'] !== null && !empty($p_row['module_batch_id'])
            && isset($module_batch_has_milestones[(int)$p_row['module_batch_id']])) {
            $p_row['module_cost'] = 0.0;
        }

        $pallets_data[] = enrichPalletCostData($p_row, $conn);
        if (!empty($p_row['current_warehouse_id'])) {
            $warehouse_ids_for_lookup[(int)$p_row['current_warehouse_id']] = true;
        }
    }
    $stmt_pallets_page->close();
    
    // Get total count using explicit COUNT to ensure accuracy with GROUP BY
    $count_sql = "SELECT COUNT(DISTINCT ip.id) AS total
                  FROM inventory_pallets ip
                  JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                  JOIN deliveries del ON dp.delivery_id = del.id
                  WHERE del.project_id = ?
                        $ytdConditionPallets
                        $dateCondition
                        $statusConditionPallets
                        $manufacturerCondition
                        $searchCondition";
    $stmt_count = $conn->prepare($count_sql);
    if ($stmt_count) {
        $stmt_count->bind_param($paramTypesPallets, ...$paramsPallets);
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

$displayed_pallet_watts = 0.0;
$displayed_freight_cost = 0.0;
$displayed_accessorial_cost = 0.0;
$displayed_warehousing_cost = 0.0;
$displayed_module_cost = 0.0;
$displayed_has_module_cost = false;

foreach ($pallets_data as $p_row) {
    $pallet_watts = (float)($p_row['wattage'] ?? 0) * (float)($p_row['quantity'] ?? 0);
    $displayed_pallet_watts += $pallet_watts;
    $displayed_freight_cost += (float)($p_row['freight_cost'] ?? 0);
    $displayed_accessorial_cost += (float)($p_row['accessorial_cost'] ?? 0);
    $displayed_warehousing_cost += (float)($p_row['display_warehouse_cost'] ?? 0);
    if ($p_row['module_cost'] !== null) {
        $displayed_module_cost += (float)$p_row['module_cost'];
        $displayed_has_module_cost = true;
    }
}

$displayed_total_cost = $displayed_freight_cost + $displayed_accessorial_cost + $displayed_warehousing_cost + $displayed_module_cost;
$displayed_freight_per_watt = $displayed_pallet_watts > 0 ? ($displayed_freight_cost / $displayed_pallet_watts) : null;
$displayed_accessorial_per_watt = $displayed_pallet_watts > 0 ? ($displayed_accessorial_cost / $displayed_pallet_watts) : null;
$displayed_warehousing_per_watt = $displayed_pallet_watts > 0 ? ($displayed_warehousing_cost / $displayed_pallet_watts) : null;
$displayed_module_per_watt = ($displayed_has_module_cost && $displayed_pallet_watts > 0)
    ? ($displayed_module_cost / $displayed_pallet_watts)
    : null;
$displayed_total_per_watt = $displayed_pallet_watts > 0 ? ($displayed_total_cost / $displayed_pallet_watts) : null;

// Calculate module costs from cost_per_watt
$module_cost_data = calculate_project_module_cost($project_id, $conn);
$total_module_cost = $module_cost_data['total_module_cost'];
$module_cost_per_watt = $module_cost_data['avg_cost_per_watt'];
$has_module_cost_data = $module_cost_data['has_cost_data'];
$module_cost_breakdown = $module_cost_data['breakdown'];

$module_cost_per_watt_display = $module_cost_per_watt !== null
    ? (float)$module_cost_per_watt
    : null;

// Project totals for header (ignore filters)
$project_total_freight_cost = 0.0;
$project_total_accessorial_costs = 0.0;
$project_total_customs_hold_cost = calculate_project_customs_hold_cost($project_id, $conn);
$project_total_watts = 0.0;
$project_total_delivered_watts = 0.0;

$project_totals_sql = "
    SELECT
        COALESCE(SUM(COALESCE(freight_cost, customer_cost, 0)), 0) as freight_total,
        COALESCE(SUM(accessorial_costs), 0) as accessorial_total,
        COALESCE(SUM(wattage * quantity), 0) as total_watts,
        COALESCE(SUM(CASE WHEN actual_delivery_date IS NOT NULL THEN (wattage * quantity) ELSE 0 END), 0) as delivered_watts
    FROM deliveries
    WHERE project_id = ?
";
$stmt_project_totals = $conn->prepare($project_totals_sql);
if ($stmt_project_totals) {
    $stmt_project_totals->bind_param('i', $project_id);
    $stmt_project_totals->execute();
    $stmt_project_totals->bind_result(
        $project_total_freight_cost,
        $project_total_accessorial_costs,
        $project_total_watts,
        $project_total_delivered_watts
    );
    $stmt_project_totals->fetch();
    $stmt_project_totals->close();
}

$project_total_accessorial_costs += $project_total_customs_hold_cost;

$project_total_warehousing_cost = calculateProjectWarehousingCostAccrued($project_id, $conn);
$project_total_module_cost_full = 0.0;
$project_total_module_watts = 0.0;
$project_has_module_cost = false;

$project_module_sql = "
    SELECT
        COALESCE(SUM(CASE WHEN m.cost_per_watt IS NOT NULL THEN (m.cost_per_watt * ip.wattage * ip.quantity) ELSE 0 END), 0) as module_cost,
        COALESCE(SUM(CASE WHEN m.cost_per_watt IS NOT NULL THEN (ip.wattage * ip.quantity) ELSE 0 END), 0) as module_watts
    FROM deliveries d
    JOIN delivery_pallets dp ON d.id = dp.delivery_id
    JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
    JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
    JOIN modules m ON umi.unassigned_module_id = m.id
    WHERE d.project_id = ?
";
$stmt_project_module = $conn->prepare($project_module_sql);
if ($stmt_project_module) {
    $stmt_project_module->bind_param('i', $project_id);
    $stmt_project_module->execute();
    $stmt_project_module->bind_result($project_total_module_cost_full, $project_total_module_watts);
    $stmt_project_module->fetch();
    $stmt_project_module->close();
}
$project_has_module_cost = $project_total_module_cost_full > 0;
$project_total_module_cost = $project_has_milestones
    ? (float)($project_milestone_status['total_triggered'] ?? 0.0)
    : $project_total_module_cost_full;
$project_module_cost_per_watt = $project_total_module_watts > 0
    ? ($project_total_module_cost / $project_total_module_watts)
    : null;
$project_total_solterra_fee = $project_total_delivered_watts > 0
    ? ($project_total_delivered_watts * $solterra_fee)
    : 0.0;
$project_total_logistics_cost = $project_total_freight_cost + $project_total_accessorial_costs + $project_total_warehousing_cost + $project_total_solterra_fee;
$project_total_cost = $project_total_logistics_cost + $project_total_module_cost;
$project_module_contract_value = $project_has_milestones
    ? (float)($project_milestone_status['total_contract_value'] ?? 0.0)
    : $project_total_module_cost_full;

$project_logistics_cost_per_watt = $project_total_watts > 0
    ? ($project_total_logistics_cost / $project_total_watts)
    : null;
$project_total_cost_per_watt = $project_total_watts > 0
    ? ($project_total_cost / $project_total_watts)
    : null;
$project_freight_per_watt = $project_total_watts > 0
    ? ($project_total_freight_cost / $project_total_watts)
    : null;
$project_accessorial_per_watt = $project_total_watts > 0
    ? ($project_total_accessorial_costs / $project_total_watts)
    : null;
$project_warehousing_per_watt = $project_total_watts > 0
    ? ($project_total_warehousing_cost / $project_total_watts)
    : null;
$project_solterra_per_watt = $project_total_watts > 0
    ? ($project_total_solterra_fee / $project_total_watts)
    : null;

$module_breakdown_per_watt = [];
foreach ($module_milestone_breakdown as $event => $amount) {
    $module_breakdown_per_watt[$event] = $project_total_watts > 0
        ? ($amount / $project_total_watts)
        : null;
}

$primary_projection = get_primary_projection($conn, $project_id);
$primary_projection_id = (int)($primary_projection['id'] ?? 0);
$projection_allocations = is_array($primary_projection['module_allocations'] ?? null) ? $primary_projection['module_allocations'] : [];
$projection_stops = is_array($primary_projection['stops'] ?? null) ? $primary_projection['stops'] : [];
$projection_legs = is_array($primary_projection['legs'] ?? null) ? $primary_projection['legs'] : [];

$to_date = static function ($value): ?DateTimeImmutable {
    if ($value === null || $value === '') {
        return null;
    }

    $raw_value = trim((string)$value);
    if ($raw_value === '' || $raw_value === '0000-00-00' || $raw_value === '0000-00-00 00:00:00') {
        return null;
    }

    $ts = strtotime($raw_value);
    if ($ts === false || $ts <= 0) {
        return null;
    }

    $year = (int)date('Y', $ts);
    if ($year < 2000) {
        return null;
    }

    return (new DateTimeImmutable('@' . $ts))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->setTime(0, 0, 0);
};

$get_week_start_key = static function ($value) use ($to_date): ?string {
    $date = $to_date($value);
    if (!$date) {
        return null;
    }
    $day_of_week = (int)$date->format('w'); // Sunday = 0
    return $date->modify("-{$day_of_week} days")->format('Y-m-d');
};

$ensure_bucket = static function (&$buckets, string $week_key): void {
    if (!isset($buckets[$week_key])) {
        $buckets[$week_key] = ['freight' => 0.0, 'warehousing' => 0.0, 'milestones' => 0.0];
    }
};

$get_leg_weekly_trucks = static function (array $leg) use ($to_date, $get_week_start_key): array {
    $schedule = [];
    $start_date = $to_date($leg['start_date'] ?? $leg['end_date'] ?? null);
    if (!$start_date) {
        return $schedule;
    }

    $total_trucks = max(0.0, (float)($leg['trucks_required'] ?? 0));
    if ($total_trucks <= 0) {
        return $schedule;
    }

    $rate = (float)($leg['delivery_rate'] ?? 0);
    $rate_unit = (string)($leg['delivery_rate_unit'] ?? 'per_week');

    $add_to_week = static function (&$weekly, DateTimeImmutable $date, float $trucks) use ($get_week_start_key): void {
        if ($trucks <= 0) {
            return;
        }
        $key = $get_week_start_key($date->format('Y-m-d'));
        if (!$key) {
            return;
        }
        $weekly[$key] = ($weekly[$key] ?? 0.0) + $trucks;
    };

    if ($rate <= 0) {
        $add_to_week($schedule, $start_date, $total_trucks);
        return $schedule;
    }

    $remaining = $total_trucks;
    $cursor = $start_date;

    while ($remaining > 0) {
        $deliveries = min($remaining, $rate);
        $add_to_week($schedule, $cursor, $deliveries);
        $remaining -= $deliveries;

        if ($remaining <= 0) {
            break;
        }

        if ($rate_unit === 'per_day') {
            $cursor = $cursor->modify('+1 day');
        } elseif ($rate_unit === 'per_month') {
            $cursor = $cursor->modify('first day of next month');
        } else {
            $cursor = $cursor->modify('+1 week');
        }
    }

    return $schedule;
};

$get_leg_cost_per_truck = static function (array $leg): float {
    $freight_per_truck = (float)($leg['freight_cost_per_truck'] ?? 0);
    $accessorial_per_truck = (float)($leg['accessorial_cost_per_truck'] ?? 0);
    if ($freight_per_truck > 0 || $accessorial_per_truck > 0) {
        return $freight_per_truck + $accessorial_per_truck;
    }

    $trucks = max(0.0, (float)($leg['trucks_required'] ?? 0));
    $total = (float)($leg['total_freight_cost'] ?? 0);
    return $trucks > 0 ? ($total / $trucks) : 0.0;
};

$forecast_weekly_buckets = [];

foreach ($projection_legs as $leg) {
    $trucks_required = max(0.0, (float)($leg['trucks_required'] ?? 0));
    if ($trucks_required <= 0) {
        continue;
    }
    $per_truck_cost = $get_leg_cost_per_truck($leg);
    if ($per_truck_cost <= 0) {
        continue;
    }

    $weekly_trucks = $get_leg_weekly_trucks($leg);
    foreach ($weekly_trucks as $week_key => $trucks) {
        $ensure_bucket($forecast_weekly_buckets, $week_key);
        $forecast_weekly_buckets[$week_key]['freight'] += $trucks * $per_truck_cost;
    }
}

$milestone_events = [];
foreach ($projection_allocations as $alloc) {
    $contract_value = (float)($alloc['contract_value'] ?? 0);
    if ($contract_value <= 0) {
        continue;
    }

    $milestones = is_array($alloc['milestones'] ?? null) ? $alloc['milestones'] : [];
    $has_milestones = false;
    foreach ($milestones as $milestone) {
        $trigger_event = (string)($milestone['trigger_event'] ?? '');
        $percentage = (float)($milestone['percentage'] ?? 0);
        if ($trigger_event !== '' && $percentage > 0) {
            $has_milestones = true;
            break;
        }
    }

    if (!$has_milestones) {
        $milestone_events[] = ['trigger' => 'project_delivery', 'amount' => $contract_value, 'date' => null];
        continue;
    }

    foreach ($milestones as $milestone) {
        $trigger_event = (string)($milestone['trigger_event'] ?? '');
        $percentage = (float)($milestone['percentage'] ?? 0);
        if ($trigger_event === '' || $percentage <= 0) {
            continue;
        }

        $po_date = $alloc['po_execution_date'] ?? $alloc['poExecutionDate'] ?? null;
        $milestone_events[] = [
            'trigger' => $trigger_event,
            'amount' => $contract_value * ($percentage / 100),
            'date' => $trigger_event === 'po_execution' ? $po_date : null
        ];
    }
}

$milestone_totals = [];
foreach ($milestone_events as $event) {
    $trigger = (string)($event['trigger'] ?? '');
    if ($trigger === '') {
        continue;
    }
    $milestone_totals[$trigger] = ($milestone_totals[$trigger] ?? 0.0) + (float)($event['amount'] ?? 0);
}

$stop_lookup = [];
foreach ($projection_stops as $stop) {
    $stop_lookup[(string)($stop['id'] ?? '')] = $stop;
}
$get_stop = static function ($stop_id) use (&$stop_lookup) {
    return $stop_lookup[(string)$stop_id] ?? null;
};

$origin_legs = [];
$destination_legs = [];
$customs_legs = [];
foreach ($projection_legs as $leg) {
    $from_stop = $get_stop($leg['from_stop_id'] ?? null);
    $to_stop = $get_stop($leg['to_stop_id'] ?? null);
    if (($from_stop['stop_type'] ?? '') === 'origin') {
        $origin_legs[] = $leg;
    }
    if (($to_stop['stop_type'] ?? '') === 'destination') {
        $destination_legs[] = $leg;
    }
    if (!empty($to_stop['is_customs_clearance']) || (($to_stop['stop_type'] ?? '') === 'customs')) {
        $customs_legs[] = $leg;
    }
}

$resolve_milestone_legs = static function (string $trigger) use ($origin_legs, $destination_legs, $customs_legs, $projection_legs): array {
    $matching_legs = array_values(array_filter($projection_legs, static function ($leg) use ($trigger) {
        return (string)($leg['triggers_milestone'] ?? '') === $trigger;
    }));

    if ($trigger === 'shipping') {
        if (!empty($origin_legs)) {
            return $origin_legs;
        }
        if (!empty($matching_legs)) {
            return $matching_legs;
        }
        return !empty($projection_legs) ? [$projection_legs[0]] : [];
    }

    if ($trigger === 'project_delivery') {
        if (!empty($destination_legs)) {
            return $destination_legs;
        }
        if (!empty($matching_legs)) {
            return $matching_legs;
        }
        return !empty($projection_legs) ? [end($projection_legs)] : [];
    }

    if ($trigger === 'customs_cleared') {
        return !empty($customs_legs) ? $customs_legs : $matching_legs;
    }

    return $matching_legs;
};

$allocate_milestone = function (string $trigger, float $amount) use (&$forecast_weekly_buckets, $ensure_bucket, $get_leg_weekly_trucks, $resolve_milestone_legs): void {
    if ($amount <= 0) {
        return;
    }
    $legs = $resolve_milestone_legs($trigger);
    if (empty($legs)) {
        return;
    }

    $weekly_trucks = [];
    $total_trucks = 0.0;
    foreach ($legs as $leg) {
        $schedule = $get_leg_weekly_trucks($leg);
        foreach ($schedule as $week_key => $trucks) {
            if ($trucks <= 0) {
                continue;
            }
            $weekly_trucks[$week_key] = ($weekly_trucks[$week_key] ?? 0.0) + $trucks;
            $total_trucks += $trucks;
        }
    }

    if ($total_trucks <= 0) {
        return;
    }

    $amount_per_truck = $amount / $total_trucks;
    foreach ($weekly_trucks as $week_key => $trucks) {
        $ensure_bucket($forecast_weekly_buckets, $week_key);
        $forecast_weekly_buckets[$week_key]['milestones'] += $amount_per_truck * $trucks;
    }
};

foreach ($milestone_totals as $trigger => $amount) {
    if ($trigger === 'po_execution') {
        continue;
    }
    $allocate_milestone($trigger, (float)$amount);
}

foreach ($milestone_events as $event) {
    if (($event['trigger'] ?? '') !== 'po_execution') {
        continue;
    }
    $event_date = !empty($event['date']) ? $event['date'] : date('Y-m-d');
    $week_key = $get_week_start_key($event_date);
    if (!$week_key) {
        continue;
    }
    $ensure_bucket($forecast_weekly_buckets, $week_key);
    $forecast_weekly_buckets[$week_key]['milestones'] += (float)($event['amount'] ?? 0);
}

$incoming_legs_by_stop = [];
$outgoing_legs_by_stop = [];
foreach ($projection_legs as $leg) {
    $from_stop_id = (string)($leg['from_stop_id'] ?? '');
    $to_stop_id = (string)($leg['to_stop_id'] ?? '');
    if ($from_stop_id !== '') {
        $outgoing_legs_by_stop[$from_stop_id][] = $leg;
    }
    if ($to_stop_id !== '') {
        $incoming_legs_by_stop[$to_stop_id][] = $leg;
    }
}

$calculate_leg_end_date = static function (array $leg) use ($to_date): ?DateTimeImmutable {
    $explicit_end = $to_date($leg['end_date'] ?? null);
    if ($explicit_end) {
        return $explicit_end;
    }

    $start_date = $to_date($leg['start_date'] ?? null);
    if (!$start_date) {
        return null;
    }

    $rate = (float)($leg['delivery_rate'] ?? 0);
    if ($rate <= 0) {
        return $start_date;
    }

    $rate_unit = (string)($leg['delivery_rate_unit'] ?? 'per_week');
    $total_trucks = (int)($leg['trucks_required'] ?? 0);
    if ($total_trucks <= 0) {
        $total_trucks = 1;
    }

    if ($rate_unit === 'per_month') {
        $days_per_delivery = 30 / $rate;
    } elseif ($rate_unit === 'per_day') {
        $days_per_delivery = 1 / $rate;
    } else {
        $days_per_delivery = 7 / $rate;
    }

    $total_days = (int)ceil($total_trucks * $days_per_delivery);
    return $start_date->modify('+' . $total_days . ' days');
};

$resolve_stop_arrival_date = static function (array $stop) use ($to_date, $incoming_legs_by_stop, $calculate_leg_end_date): ?DateTimeImmutable {
    $arrival = $to_date($stop['estimated_arrival_date'] ?? null);
    if ($arrival) {
        return $arrival;
    }

    $stop_id = (string)($stop['id'] ?? '');
    if ($stop_id === '' || empty($incoming_legs_by_stop[$stop_id])) {
        return null;
    }

    foreach ($incoming_legs_by_stop[$stop_id] as $incoming_leg) {
        $leg_arrival = $calculate_leg_end_date($incoming_leg);
        if ($leg_arrival) {
            return $leg_arrival;
        }

        $fallback_start = $to_date($incoming_leg['start_date'] ?? null);
        if ($fallback_start) {
            return $fallback_start;
        }
    }

    return null;
};

$resolve_stop_departure_date = static function (array $stop) use ($to_date, $outgoing_legs_by_stop): ?DateTimeImmutable {
    $departure = $to_date($stop['estimated_departure_date'] ?? null);
    if ($departure) {
        return $departure;
    }

    $stop_id = (string)($stop['id'] ?? '');
    if ($stop_id === '' || empty($outgoing_legs_by_stop[$stop_id])) {
        return null;
    }

    foreach ($outgoing_legs_by_stop[$stop_id] as $outgoing_leg) {
        $start = $to_date($outgoing_leg['start_date'] ?? null);
        if ($start) {
            return $start;
        }
    }

    return null;
};

foreach ($projection_stops as $stop_index => $stop) {
    $arrival_date = $resolve_stop_arrival_date($stop);
    $fees = is_array($stop['fees'] ?? null) ? $stop['fees'] : [];
    if (!$arrival_date || empty($fees)) {
        continue;
    }

    foreach ($fees as $fee) {
        $cost = (float)($fee['estimated_cost'] ?? 0);
        if ($cost <= 0) {
            continue;
        }

        $fee_type = strtolower(trim((string)($fee['fee_type'] ?? '')));
        $fee_trigger = strtolower(trim((string)($fee['trigger'] ?? '')));
        if ($fee_type === 'storage' || $fee_trigger === 'monthly') {
            $planned_departure = $resolve_stop_departure_date($stop);
            $next_stop = $projection_stops[$stop_index + 1] ?? null;
            $next_arrival = $next_stop ? $resolve_stop_arrival_date($next_stop) : null;

            if ($planned_departure && $planned_departure > $arrival_date) {
                $departure_date = $planned_departure;
            } elseif ($next_arrival && $next_arrival > $arrival_date) {
                $departure_date = $next_arrival;
            } else {
                $departure_date = $arrival_date->modify('+90 days');
            }

            $months_in_storage = max(1, (int)ceil(($departure_date->getTimestamp() - $arrival_date->getTimestamp()) / (30 * 24 * 60 * 60)));
            $monthly_fee = $cost / $months_in_storage;
            $month_cursor = $arrival_date->modify('first day of this month');

            for ($i = 0; $i < $months_in_storage; $i++) {
                $month_first = $month_cursor->modify("+{$i} month");
                $week_key = $get_week_start_key($month_first->format('Y-m-d'));
                if (!$week_key) {
                    continue;
                }
                $ensure_bucket($forecast_weekly_buckets, $week_key);
                $forecast_weekly_buckets[$week_key]['warehousing'] += $monthly_fee;
            }
            continue;
        }

        $fee_date = $arrival_date;
        if ($fee_type === 'outbound' || $fee_type === 'out') {
            $departure_date = $resolve_stop_departure_date($stop);
            if (!$departure_date) {
                $next_stop = $projection_stops[$stop_index + 1] ?? null;
                $departure_date = $next_stop ? $resolve_stop_arrival_date($next_stop) : null;
            }
            if ($departure_date && $departure_date > $arrival_date) {
                $fee_date = $departure_date;
            }
        }

        $week_key = $get_week_start_key($fee_date->format('Y-m-d'));
        if (!$week_key) {
            continue;
        }
        $ensure_bucket($forecast_weekly_buckets, $week_key);
        $forecast_weekly_buckets[$week_key]['warehousing'] += $cost;
    }
}

ksort($forecast_weekly_buckets);

$weekly_projection_rows = [];
$weekly_projection_totals = ['freight' => 0.0, 'warehousing' => 0.0, 'milestones' => 0.0, 'weekly_total' => 0.0];
$weekly_projection_total_range = '-';
$forecast_weekly_totals = [];
$week_index = 0;
$running_forecast = 0.0;
$first_week_key = null;
$last_week_key = null;

foreach ($forecast_weekly_buckets as $week_key => $bucket) {
    $week_start = new DateTimeImmutable($week_key);
    $week_end = $week_start->modify('+6 days');
    $weekly_total = (float)$bucket['freight'] + (float)$bucket['warehousing'] + (float)$bucket['milestones'];
    $running_forecast += $weekly_total;
    $week_index++;

    $first_week_key = $first_week_key ?? $week_key;
    $last_week_key = $week_key;
    $forecast_weekly_totals[$week_key] = $weekly_total;

    $weekly_projection_rows[] = [
        'week_label' => 'Week ' . $week_index,
        'date_range' => $week_start->format('M j') . ' - ' . $week_end->format('M j, Y'),
        'freight' => round((float)$bucket['freight'], 2),
        'warehousing' => round((float)$bucket['warehousing'], 2),
        'milestones' => round((float)$bucket['milestones'], 2),
        'weekly_total' => round($weekly_total, 2),
        'cumulative' => round($running_forecast, 2)
    ];

    $weekly_projection_totals['freight'] += (float)$bucket['freight'];
    $weekly_projection_totals['warehousing'] += (float)$bucket['warehousing'];
    $weekly_projection_totals['milestones'] += (float)$bucket['milestones'];
    $weekly_projection_totals['weekly_total'] += $weekly_total;
}

if ($first_week_key !== null && $last_week_key !== null) {
    $range_start = new DateTimeImmutable($first_week_key);
    $range_end = (new DateTimeImmutable($last_week_key))->modify('+6 days');
    $weekly_projection_total_range = $range_start->format('M j, Y') . ' - ' . $range_end->format('M j, Y');
}

$actual_weekly_totals = [];
foreach ($deliveries as $delivery_for_chart) {
    $actual_week_key = $get_week_start_key($delivery_for_chart['actual_delivery_date'] ?? null);
    if (!$actual_week_key) {
        continue;
    }
    $actual_total = max(0.0,
        (float)($delivery_for_chart['customer_cost'] ?? 0)
        + (float)($delivery_for_chart['accessorial_costs'] ?? 0)
        + (float)($delivery_for_chart['warehousing_cost'] ?? 0)
        + (float)($delivery_for_chart['module_cost'] ?? 0)
    );
    $actual_weekly_totals[$actual_week_key] = ($actual_weekly_totals[$actual_week_key] ?? 0.0) + $actual_total;
}
ksort($actual_weekly_totals);

$all_week_keys = array_values(array_unique(array_merge(array_keys($forecast_weekly_totals), array_keys($actual_weekly_totals))));
sort($all_week_keys);

if (!empty($all_week_keys)) {
    $first_week = new DateTimeImmutable($all_week_keys[0]);
    $last_week = new DateTimeImmutable($all_week_keys[count($all_week_keys) - 1]);
    $filled_week_keys = [];
    $cursor = $first_week;
    while ($cursor <= $last_week) {
        $filled_week_keys[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 week');
    }
    $all_week_keys = $filled_week_keys;
}

$forecast_actual_labels = [];
$forecast_actual_forecast_cumulative = [];
$forecast_actual_actual_cumulative = [];
$forecast_actual_cadence_label = 'Week';

$forecast_actual_cadence = 'weekly';
if (count($all_week_keys) > 24) {
    $forecast_actual_cadence = 'monthly';
} elseif (count($all_week_keys) > 8) {
    $forecast_actual_cadence = 'biweekly';
}

$forecast_actual_buckets = [];

if ($forecast_actual_cadence === 'monthly') {
    $forecast_actual_cadence_label = 'Month';
    foreach ($all_week_keys as $week_key) {
        $month_key = substr($week_key, 0, 7);
        if (!isset($forecast_actual_buckets[$month_key])) {
            $month_start = new DateTimeImmutable($month_key . '-01');
            $forecast_actual_buckets[$month_key] = [
                'label' => $month_start->format('M Y'),
                'forecast_increment' => 0.0,
                'actual_increment' => 0.0
            ];
        }

        $forecast_actual_buckets[$month_key]['forecast_increment'] += (float)($forecast_weekly_totals[$week_key] ?? 0.0);
        $forecast_actual_buckets[$month_key]['actual_increment'] += (float)($actual_weekly_totals[$week_key] ?? 0.0);
    }
    $forecast_actual_buckets = array_values($forecast_actual_buckets);
} elseif ($forecast_actual_cadence === 'biweekly') {
    $forecast_actual_cadence_label = 'Bi-Weekly';
    $bucket_count = count($all_week_keys);
    for ($i = 0; $i < $bucket_count; $i += 2) {
        $slice = array_slice($all_week_keys, $i, 2);
        if (empty($slice)) {
            continue;
        }

        $first_week = new DateTimeImmutable($slice[0]);
        $last_week = new DateTimeImmutable($slice[count($slice) - 1]);
        $range_end = $last_week->modify('+6 days');

        $forecast_increment = 0.0;
        $actual_increment = 0.0;
        foreach ($slice as $week_key) {
            $forecast_increment += (float)($forecast_weekly_totals[$week_key] ?? 0.0);
            $actual_increment += (float)($actual_weekly_totals[$week_key] ?? 0.0);
        }

        $forecast_actual_buckets[] = [
            'label' => $first_week->format('M j') . ' - ' . $range_end->format('M j, Y'),
            'forecast_increment' => $forecast_increment,
            'actual_increment' => $actual_increment
        ];
    }
} else {
    foreach ($all_week_keys as $week_key) {
        $week_start = new DateTimeImmutable($week_key);
        $week_end = $week_start->modify('+6 days');
        $forecast_actual_buckets[] = [
            'label' => $week_start->format('M j') . ' - ' . $week_end->format('M j, Y'),
            'forecast_increment' => (float)($forecast_weekly_totals[$week_key] ?? 0.0),
            'actual_increment' => (float)($actual_weekly_totals[$week_key] ?? 0.0)
        ];
    }
}

$running_forecast = 0.0;
$running_actual = 0.0;
foreach ($forecast_actual_buckets as $bucket) {
    $running_forecast += (float)($bucket['forecast_increment'] ?? 0.0);
    $running_actual += (float)($bucket['actual_increment'] ?? 0.0);

    $forecast_actual_labels[] = (string)($bucket['label'] ?? '');
    $forecast_actual_forecast_cumulative[] = round($running_forecast, 2);
    $forecast_actual_actual_cumulative[] = round($running_actual, 2);
}

$weekly_projection_preview = array_slice($weekly_projection_rows, 0, 5);
$has_more_weekly_rows = count($weekly_projection_rows) > count($weekly_projection_preview);
$weekly_projection_view_all_url = 'anticipated_deliveries.php?project_id=' . (int)$project_id . '&view=weekly-projections';
if ($primary_projection_id > 0) {
    $weekly_projection_view_all_url .= '&projection_id=' . $primary_projection_id;
}
$has_forecast_actual_chart_data = !empty($forecast_actual_labels);

// Warehouse name lookup for status display
$warehouse_name_map = [];
if (!empty($warehouse_ids_for_lookup)) {
    $ids_list = implode(',', array_keys($warehouse_ids_for_lookup));
    $sql_wh = "SELECT id, name FROM warehouses WHERE id IN ($ids_list)";
    $res_wh = $conn->query($sql_wh);
    if ($res_wh) {
        while ($row = $res_wh->fetch_assoc()) {
            $warehouse_name_map[(int)$row['id']] = $row['name'];
        }
    }
}

// Calculate overall cost per pallet and price metrics with updated totals
$overall_cost_per_pallet = ($total_pallets_count > 0) ? ($total_logistics_cost / $total_pallets_count) : 0;

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
        'Module Cost',
        'Total Cost'
    ]);

    $sql_pallets_export = $pallet_base_sql . " ORDER BY ip.id DESC";
    $stmt_export = $conn->prepare($sql_pallets_export);
    if ($stmt_export) {
        $stmt_export->bind_param($paramTypesPallets, ...$paramsPallets);
        $stmt_export->execute();
        $result_export = $stmt_export->get_result();
        while ($row = $result_export->fetch_assoc()) {
            $pallet_id = (int)($row['id'] ?? 0);
            if ($pallet_id && array_key_exists($pallet_id, $pallet_accrued_module_costs)) {
                $row['module_cost'] = $pallet_accrued_module_costs[$pallet_id];
            } elseif ($row['module_cost'] !== null && !empty($row['module_batch_id'])
                && isset($module_batch_has_milestones[(int)$row['module_batch_id']])) {
                $row['module_cost'] = 0.0;
            }

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
                $row['module_cost'] !== null ? number_format($row['module_cost'], 2) : '',
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
        'Freight Cost',
        'Accessorial Cost',
        'Total Freight Cost',
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
            number_format($d['customer_cost'] ?? 0, 2),
            number_format($d['accessorial_costs'] ?? 0, 2),
            number_format($d['display_total_freight_only'] ?? (($d['customer_cost'] ?? 0) + ($d['accessorial_costs'] ?? 0)), 2),
            number_format($d['solterra_fee'] ?? 0, 2),
            number_format(($d['customer_cost'] ?? 0) + ($d['accessorial_costs'] ?? 0) + ($d['warehousing_cost'] ?? 0) + ($d['solterra_fee'] ?? 0), 2)
        ]);
    }
    fclose($output);
    exit();
}

// Note: $conn->close() moved to after milestone component to avoid "mysqli already closed" error
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            background: linear-gradient(135deg, rgba(41, 62, 76, 0.15) 0%, rgba(41, 62, 76, 0.2) 100%);
        }

        .stat-item-total .stat-number {
            color: #293E4C;
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

        /* Clickable stat item for logistics breakdown */
        .stat-item-clickable {
            cursor: pointer;
            position: relative;
        }

        .stat-item-clickable:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(72, 140, 154, 0.3);
        }

        .stat-item-clickable::after {
            content: 'Click for breakdown';
            position: absolute;
            bottom: -20px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.7em;
            color: #488C9A;
            opacity: 0;
            transition: opacity 0.2s;
            white-space: nowrap;
        }

        .stat-item-clickable:hover::after {
            opacity: 1;
        }

        .stat-item-module {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(72, 140, 154, 0.25) 100%);
        }

        .stat-item-module .stat-number {
            color: #488C9A;
        }

        /* Filter Pills */
        .filter-pills-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
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

        .filter-pill:hover {
            background: rgba(255,255,255,0.5);
            color: #293E4C;
        }

        .filter-pill.active {
            background: #fff;
            color: #293E4C;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

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

        .view-mode-btn:hover {
            background: rgba(255,255,255,0.5);
            color: #293E4C;
        }

        .view-mode-btn.active {
            background: #fff;
            color: #488C9A;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        /* Logistics Breakdown Modal */
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
            margin: 5% auto;
            padding: 0;
            width: 90%;
            max-width: 700px;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .logistics-modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 24px;
            border-radius: 20px 20px 0 0;
            position: relative;
        }

        .logistics-modal-header h2 {
            margin: 0;
            font-size: 1.5em;
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

        .logistics-modal-close:hover {
            transform: scale(1.1);
        }

        .logistics-modal-body {
            padding: 24px;
        }

        .logistics-breakdown-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .logistics-breakdown-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }

        .module-modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
        }

        .module-breakdown-item {
            background: rgba(72, 140, 154, 0.08);
        }

        .module-breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 0.95em;
        }

        .module-breakdown-table th,
        .module-breakdown-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(72, 140, 154, 0.15);
            text-align: left;
        }

        .module-breakdown-table th {
            background: #ffffff;
            text-align: left;
            color: #293E4C;
            font-weight: 600;
        }

        .module-breakdown-table .text-right {
            text-align: right;
        }

        .module-breakdown-summary {
            border-top: 1px solid rgba(72, 140, 154, 0.15);
            padding-top: 16px;
            margin-top: 8px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-weight: 600;
            color: #293E4C;
        }

        .summary-label {
            font-size: 0.9em;
            color: #6c757d;
            font-weight: 500;
        }

        .summary-value {
            font-size: 1.1em;
        }

        .module-breakdown-note {
            font-size: 0.85em;
            color: #6c757d;
        }

        .logistics-breakdown-item .value {
            font-size: 1.5em;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .logistics-breakdown-item .label {
            font-size: 0.9em;
            color: #6c757d;
        }

        .logistics-breakdown-item.freight .value { color: #2563eb; }
        .logistics-breakdown-item.warehousing .value { color: #7c3aed; }
        .logistics-breakdown-item.accessorial .value { color: #d97706; }
        .logistics-breakdown-item.solterra .value { color: #16a34a; }

        .logistics-chart-container {
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
        }

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
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

        .cashflow-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .cashflow-header h3 {
            margin: 0;
        }

        .view-all-link {
            font-size: 0.85em;
            color: #488C9A;
            text-decoration: none;
            font-weight: 600;
            white-space: nowrap;
        }

        .view-all-link:hover {
            color: #2f6673;
            text-decoration: underline;
        }

        .chart-container {
            position: relative;
            height: 250px;
        }

        .chart-subtitle {
            margin: -10px 0 16px 0;
            font-size: 0.85em;
            color: #6c757d;
        }

        .forecast-chart-container {
            position: relative;
            height: 300px;
        }

        .chart-empty-state {
            min-height: 140px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #6c757d;
            font-size: 0.9em;
            border: 1px dashed rgba(72, 140, 154, 0.3);
            border-radius: 12px;
            background: rgba(72, 140, 154, 0.03);
            padding: 18px;
        }

        .summary-layout {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* Hero Cost Breakdown card */
        .cost-summary-hero {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 26px 32px 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .cost-summary-hero-header {
            margin-bottom: 18px;
        }

        .cost-summary-hero-header h3 {
            font-size: 1.25em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cost-summary-hero-header h3 i {
            color: #488C9A;
        }

        .cost-summary-hero-body {
            display: grid;
            grid-template-columns: minmax(240px, 340px) 1fr;
            gap: 40px;
            align-items: center;
        }

        .cost-summary-hero-chart {
            position: relative;
            height: 260px;
        }

        .cost-breakdown-list {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .breakdown-row {
            display: grid;
            grid-template-columns: 14px 1fr auto;
            gap: 14px;
            align-items: center;
            padding: 12px 2px;
            border-bottom: 1px solid #edf1f3;
        }

        .breakdown-row:last-child {
            border-bottom: none;
        }

        .breakdown-swatch {
            width: 12px;
            height: 12px;
            border-radius: 3px;
        }

        .breakdown-label {
            color: #435160;
            font-size: 0.95em;
            min-width: 0;
        }

        .breakdown-value {
            font-weight: 600;
            color: #293E4C;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .breakdown-row-total {
            margin-top: 6px;
            padding-top: 16px;
            border-top: 2px solid #e2e8ed;
            border-bottom: none !important;
        }

        .breakdown-row-total .breakdown-label {
            color: #293E4C;
            font-weight: 600;
            font-size: 1em;
        }

        .breakdown-row-total .breakdown-value {
            color: #488C9A;
            font-size: 1.15em;
        }

        /* Supplement cards grid (milestones, forecast) */
        .summary-supplements {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(440px, 1fr));
            gap: 20px;
            align-items: start;
        }

        .summary-card {
            height: 430px;
            display: flex;
            flex-direction: column;
        }

        .summary-card .chart-container,
        .summary-card .forecast-chart-container {
            flex: 1;
            min-height: 0;
        }

        /* Weekly projections card always takes its own row (7 columns need the width) */
        .summary-weekly-card {
            height: auto;
            max-height: 520px;
        }

        .summary-milestones-wrap {
            padding: 0;
            overflow: hidden;
        }

        .summary-milestones-wrap .milestone-detail-section {
            margin: 0 !important;
            height: 100%;
        }

        .summary-milestones-wrap .milestone-detail-section > div {
            height: 100%;
            display: flex;
            flex-direction: column;
            border-radius: 20px !important;
            border: 1px solid rgba(72, 140, 154, 0.08) !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }

        .summary-milestones-wrap .milestone-detail-section > div > div:first-child {
            background: transparent !important;
            color: #293E4C !important;
            border-bottom: 1px solid #e8ecef;
            padding: 16px 20px 12px 20px !important;
        }

        .summary-milestones-wrap .milestone-detail-section > div > div:first-child h4 {
            color: #293E4C !important;
            font-size: 1.2em !important;
            font-weight: 600 !important;
        }

        .summary-milestones-wrap .milestone-detail-section > div > div:first-child h4 span:first-child {
            display: none;
        }

        .summary-milestones-wrap .milestone-detail-section > div > div:first-child > div > span {
            color: #488C9A !important;
            font-weight: 600 !important;
        }

        .summary-milestones-wrap #milestone-detail-body {
            flex: 1;
            min-height: 0;
            overflow: auto;
            padding: 14px 18px !important;
        }

        .weekly-table-wrapper {
            overflow: auto;
            border: 1px solid rgba(72, 140, 154, 0.14);
            border-radius: 12px;
            flex: 1;
            min-height: 0;
        }

        .weekly-projections-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
            font-size: 0.85em;
        }

        .weekly-projections-table th {
            background: #293E4C;
            color: #ffffff;
            text-align: left;
            padding: 10px 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .weekly-projections-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #edf1f3;
            color: #435160;
            white-space: nowrap;
        }

        .weekly-projections-table tbody tr:nth-child(even) {
            background: rgba(72, 140, 154, 0.03);
        }

        .weekly-projections-table .weekly-totals-row td {
            background: #f3f7f9;
            border-top: 1px solid #dde7ec;
            border-bottom: none;
            font-weight: 600;
        }

        .weekly-preview-note {
            margin: 10px 0 0 0;
            font-size: 0.82em;
            color: #5e6c77;
        }

        @media (max-width: 992px) {
            .charts-section {
                grid-template-columns: 1fr;
            }
            .logistics-breakdown-grid {
                grid-template-columns: 1fr;
            }
            .cost-summary-hero-body {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .cost-summary-hero-chart {
                height: 220px;
            }
            .summary-supplements {
                grid-template-columns: 1fr;
            }
            .summary-card {
                height: auto;
                min-height: 380px;
            }
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

        .table-header-center {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filtered-summary-header {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.85em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .filtered-summary-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filtered-summary-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #fff;
            font-size: 0.8em;
        }

        .filtered-summary-chip .label {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.65em;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 600;
        }

        .filtered-summary-chip .value {
            font-weight: 700;
            color: #fff;
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

        .status-cell {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
        }

        .status-subtext {
            font-size: 0.85em;
            color: #6c757d;
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
                <p class="header-subtitle">Project totals across all deliveries</p>
            </div>
            <div class="header-stats">
                <div class="stat-item stat-item-freight stat-item-clickable" onclick="openLogisticsModal()">
                    <p class="stat-number">
                        <span class="cost-display" data-cost="<?php echo $project_total_logistics_cost; ?>" data-per-watt="<?php echo $project_logistics_cost_per_watt !== null ? $project_logistics_cost_per_watt : ''; ?>">
                            $<?php echo number_format($project_total_logistics_cost, 2); ?>
                        </span>
                    </p>
                    <p class="stat-label"><i class="fas fa-truck"></i> Logistics Costs</p>
                </div>
                <?php if ($project_has_module_cost): ?>
                <div class="stat-item stat-item-module stat-item-clickable" onclick="openModuleModal()">
                    <p class="stat-number">
                        <span class="cost-display" data-cost="<?php echo $project_total_module_cost; ?>" data-per-watt="<?php echo $project_module_cost_per_watt !== null ? $project_module_cost_per_watt : ''; ?>">
                            $<?php echo number_format($project_total_module_cost, 2); ?>
                        </span>
                    </p>
                    <p class="stat-label"><i class="fas fa-solar-panel"></i> Module Costs (Accrued)</p>
                </div>
                <?php endif; ?>
                <div class="stat-item stat-item-total">
                    <p class="stat-number">
                        <span class="cost-display" data-cost="<?php echo $project_total_cost; ?>" data-per-watt="<?php echo $project_total_cost_per_watt !== null ? $project_total_cost_per_watt : ''; ?>">
                            $<?php echo number_format($project_total_cost, 2); ?>
                        </span>
                    </p>
                    <p class="stat-label"><i class="fas fa-calculator"></i> Total Costs</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="tabs-nav">
        <button class="tab-btn active" onclick="switchTab('summary')">Summary</button>
        <button class="tab-btn" onclick="switchTab('pallets')">Pallet Costs</button>
    </div>

    <?php
    // Buffer the milestone component so we can skip its card entirely when there's no content
    ob_start();
    $section_title = 'Module Payment Milestones';
    $collapsible = false;
    $compact_batch_header = true;
    include 'components/milestone_detail_table.php';
    $milestone_detail_html = trim(ob_get_clean());

    // Close DB connection before rendering the rest of the page
    $conn->close();

    $show_milestones = $milestone_detail_html !== '';
    $show_forecast   = $has_forecast_actual_chart_data;
    $show_weekly     = !empty($weekly_projection_preview);
    ?>
    <div id="tab-summary" class="tab-content active">
        <div class="summary-layout">
            <!-- Hero: Cost Breakdown -->
            <div class="cost-summary-hero">
                <div class="cost-summary-hero-header">
                    <h3><i class="fas fa-chart-pie"></i> Cost Breakdown</h3>
                </div>
                <div class="cost-summary-hero-body">
                    <div class="cost-summary-hero-chart">
                        <canvas id="costBreakdownChart"></canvas>
                    </div>
                    <div class="cost-breakdown-list">
                        <?php if ($project_has_module_cost): ?>
                        <div class="breakdown-row">
                            <span class="breakdown-swatch" style="background:#488C9A"></span>
                            <span class="breakdown-label">Module Cost (Accrued)</span>
                            <span class="breakdown-value cost-display"
                                  data-cost="<?php echo $project_total_module_cost; ?>"
                                  data-per-watt="<?php echo $project_module_cost_per_watt !== null ? $project_module_cost_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_module_cost, 2); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="breakdown-row">
                            <span class="breakdown-swatch" style="background:#3b82f6"></span>
                            <span class="breakdown-label">Freight</span>
                            <span class="breakdown-value cost-display"
                                  data-cost="<?php echo $project_total_freight_cost; ?>"
                                  data-per-watt="<?php echo $project_freight_per_watt !== null ? $project_freight_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_freight_cost, 2); ?>
                            </span>
                        </div>
                        <div class="breakdown-row">
                            <span class="breakdown-swatch" style="background:#8b5cf6"></span>
                            <span class="breakdown-label">Warehousing</span>
                            <span class="breakdown-value cost-display"
                                  data-cost="<?php echo $project_total_warehousing_cost; ?>"
                                  data-per-watt="<?php echo $project_warehousing_per_watt !== null ? $project_warehousing_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_warehousing_cost, 2); ?>
                            </span>
                        </div>
                        <div class="breakdown-row">
                            <span class="breakdown-swatch" style="background:#f59e0b"></span>
                            <span class="breakdown-label">Accessorial</span>
                            <span class="breakdown-value cost-display"
                                  data-cost="<?php echo $project_total_accessorial_costs; ?>"
                                  data-per-watt="<?php echo $project_accessorial_per_watt !== null ? $project_accessorial_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_accessorial_costs, 2); ?>
                            </span>
                        </div>
                        <?php if ($project_total_solterra_fee > 0): ?>
                        <div class="breakdown-row">
                            <span class="breakdown-swatch" style="background:#5ba3b1"></span>
                            <span class="breakdown-label">Solterra Fee</span>
                            <span class="breakdown-value cost-display"
                                  data-cost="<?php echo $project_total_solterra_fee; ?>"
                                  data-per-watt="<?php echo $project_solterra_per_watt !== null ? $project_solterra_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_solterra_fee, 2); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="breakdown-row breakdown-row-total">
                            <span></span>
                            <span class="breakdown-label">Total Costs</span>
                            <span class="breakdown-value cost-display"
                                  data-cost="<?php echo $project_total_cost; ?>"
                                  data-per-watt="<?php echo $project_total_cost_per_watt !== null ? $project_total_cost_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_cost, 2); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($show_milestones || $show_forecast): ?>
            <div class="summary-supplements">
                <?php if ($show_milestones): ?>
                <div class="summary-milestones-wrap summary-card">
                    <?php echo $milestone_detail_html; ?>
                </div>
                <?php endif; ?>

                <?php if ($show_forecast): ?>
                <div class="chart-card summary-card">
                    <div class="cashflow-header">
                        <h3><i class="fas fa-chart-line"></i> Forecasted vs Actual Cost</h3>
                        <a href="<?php echo htmlspecialchars($weekly_projection_view_all_url); ?>" class="view-all-link">View All</a>
                    </div>
                    <p class="chart-subtitle">Cumulative projected cost trend from planned delivery dates vs actual delivery dates.</p>
                    <div class="forecast-chart-container">
                        <canvas id="forecastActualCostChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($show_weekly): ?>
            <div class="chart-card summary-card summary-weekly-card">
                <div class="cashflow-header">
                    <h3><i class="fas fa-calendar-week"></i> Weekly Cost Projections</h3>
                    <a href="<?php echo htmlspecialchars($weekly_projection_view_all_url); ?>" class="view-all-link">View All</a>
                </div>
                <div class="weekly-table-wrapper">
                    <table class="weekly-projections-table">
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Date Range</th>
                                <th>Freight</th>
                                <th>Warehousing</th>
                                <th>Milestones</th>
                                <th>Weekly Total</th>
                                <th>Cumulative</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($weekly_projection_preview as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['week_label']); ?></td>
                                <td><?php echo htmlspecialchars($row['date_range']); ?></td>
                                <td>$<?php echo number_format((float)$row['freight'], 2); ?></td>
                                <td>$<?php echo number_format((float)$row['warehousing'], 2); ?></td>
                                <td>$<?php echo number_format((float)$row['milestones'], 2); ?></td>
                                <td style="font-weight: 600;">$<?php echo number_format((float)$row['weekly_total'], 2); ?></td>
                                <td style="font-weight: 700; color: #488C9A;">$<?php echo number_format((float)$row['cumulative'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="weekly-totals-row">
                                <td><strong>Total</strong></td>
                                <td><?php echo htmlspecialchars($weekly_projection_total_range); ?></td>
                                <td><strong>$<?php echo number_format((float)$weekly_projection_totals['freight'], 2); ?></strong></td>
                                <td><strong>$<?php echo number_format((float)$weekly_projection_totals['warehousing'], 2); ?></strong></td>
                                <td><strong>$<?php echo number_format((float)$weekly_projection_totals['milestones'], 2); ?></strong></td>
                                <td><strong>$<?php echo number_format((float)$weekly_projection_totals['weekly_total'], 2); ?></strong></td>
                                <td><strong>$<?php echo number_format((float)$weekly_projection_totals['weekly_total'], 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php if ($has_more_weekly_rows): ?>
                <p class="weekly-preview-note">Showing first 5 weeks. Select <strong>View All</strong> for the full weekly projection table.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

    <!-- Logistics Breakdown Modal -->
    <div id="logisticsModal" class="logistics-modal">
        <div class="logistics-modal-content">
            <div class="logistics-modal-header">
                <h2><i class="fas fa-truck-loading"></i> Logistics Cost Breakdown</h2>
                <button class="logistics-modal-close" onclick="closeLogisticsModal()">&times;</button>
            </div>
            <div class="logistics-modal-body">
                <div class="logistics-breakdown-grid">
                    <div class="logistics-breakdown-item freight">
                        <div class="value">
                            <span class="cost-display" data-cost="<?php echo $project_total_freight_cost; ?>" data-per-watt="<?php echo $project_freight_per_watt !== null ? $project_freight_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_freight_cost, 2); ?>
                            </span>
                        </div>
                        <div class="label"><i class="fas fa-shipping-fast"></i> Freight Costs</div>
                    </div>
                    <div class="logistics-breakdown-item warehousing">
                        <div class="value">
                            <span class="cost-display" data-cost="<?php echo $project_total_warehousing_cost; ?>" data-per-watt="<?php echo $project_warehousing_per_watt !== null ? $project_warehousing_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_warehousing_cost, 2); ?>
                            </span>
                        </div>
                        <div class="label"><i class="fas fa-warehouse"></i> Warehousing Costs</div>
                    </div>
                    <div class="logistics-breakdown-item accessorial">
                        <div class="value">
                            <span class="cost-display" data-cost="<?php echo $project_total_accessorial_costs; ?>" data-per-watt="<?php echo $project_accessorial_per_watt !== null ? $project_accessorial_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_accessorial_costs, 2); ?>
                            </span>
                        </div>
                        <div class="label"><i class="fas fa-plus-circle"></i> Accessorial Costs</div>
                    </div>
                    <?php if ($project_total_solterra_fee > 0): ?>
                    <div class="logistics-breakdown-item solterra">
                        <div class="value">
                            <span class="cost-display" data-cost="<?php echo $project_total_solterra_fee; ?>" data-per-watt="<?php echo $project_solterra_per_watt !== null ? $project_solterra_per_watt : ''; ?>">
                                $<?php echo number_format($project_total_solterra_fee, 2); ?>
                            </span>
                        </div>
                        <div class="label"><i class="fas fa-hand-holding-usd"></i> Solterra Fee</div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="logistics-chart-container">
                    <canvas id="logisticsBreakdownChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <?php if ($project_has_module_cost): ?>
    <!-- Module Cost Breakdown Modal -->
    <div id="moduleModal" class="logistics-modal">
        <div class="logistics-modal-content">
            <div class="logistics-modal-header module-modal-header">
                <h2><i class="fas fa-solar-panel"></i> Module Cost Breakdown</h2>
                <button class="logistics-modal-close" onclick="closeModuleModal()">&times;</button>
            </div>
            <div class="logistics-modal-body">
                <?php if (!empty($module_milestone_rows)): ?>
                    <table class="module-breakdown-table">
                        <thead>
                            <tr>
                                <th>Milestone</th>
                                <th class="text-right">Percent</th>
                                <th class="text-right">Completion Amount</th>
                                <th class="text-right">Accrued</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($module_milestone_rows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['label']); ?></td>
                                    <td class="text-right">
                                        <?php echo $row['percentage'] !== null ? number_format($row['percentage'], 1) . '%' : '—'; ?>
                                    </td>
                                    <td class="text-right">
                                        $<?php echo number_format($row['target_amount'], 2); ?>
                                    </td>
                                    <td class="text-right">
                                        <span class="cost-display" data-cost="<?php echo $row['amount']; ?>" data-per-watt="<?php echo $module_breakdown_per_watt[$row['trigger']] ?? ''; ?>">
                                            $<?php echo number_format($row['amount'], 2); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="module-breakdown-note">No milestones configured. Showing full module value.</div>
                <?php endif; ?>
                <div class="module-breakdown-summary">
                    <div class="summary-row">
                        <span class="summary-label">Accrued Module Cost</span>
                        <span class="summary-value cost-display" data-cost="<?php echo $project_total_module_cost; ?>" data-per-watt="<?php echo $project_module_cost_per_watt !== null ? $project_module_cost_per_watt : ''; ?>">
                            $<?php echo number_format($project_total_module_cost, 2); ?>
                        </span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Full Module Value</span>
                        <span class="summary-value">
                            $<?php echo number_format($project_module_contract_value, 2); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
                    <select name="status_filter" id="statusFilter" class="filter-select" data-selected="<?php echo htmlspecialchars($status_filter); ?>">
                        <option value="">All Statuses</option>
                    </select>
                    <input type="hidden" name="status_context" id="statusContext" value="<?php echo htmlspecialchars($status_context); ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="searchFilter">Search</label>
                    <input type="text" name="search" id="searchFilter" class="filter-input" placeholder="Search BOL, manufacturer..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
            </div>
        </form>
    </div>

    <!-- Delivery Breakdown (Summary Tab) -->
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

        <!-- Deliveries Table -->
        <div class="table-container">
            <div class="table-header">
                <h3 class="table-title">
                    <i class="fas fa-dollar-sign"></i>
                    Delivery Cost Breakdown
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
                            <label><input type="checkbox" class="column-toggle" data-column="col-freight-cost" checked> Freight Cost</label>
                        </div>
                        <div class="column-item">
                            <label><input type="checkbox" class="column-toggle" data-column="col-accessorial-cost" checked> Accessorial Cost</label>
                        </div>
                        <div class="column-item">
                            <label><input type="checkbox" class="column-toggle" data-column="col-total-cost" checked> Total Freight Cost</label>
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
                        <th class="col-freight-cost">Freight Cost</th>
                        <th class="col-accessorial-cost">Accessorial Cost</th>
                        <th class="col-total-cost">Total Freight Cost</th>
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
                            <?php
                                $status_text = htmlspecialchars($d['status_of_delivery'] ?? '');
                                $status_lower = strtolower($d['status_of_delivery'] ?? '');
                                $wh_label = '';
                                if (in_array($status_lower, ['in warehouse', 'delivered to warehouse'])) {
                                    $wh_id = 0;
                                    if (!empty($d['warehouse_id'])) {
                                        $wh_id = (int)$d['warehouse_id'];
                                    } elseif (!empty($d['origin_type']) && $d['origin_type'] === 'warehouse' && !empty($d['origin_id'])) {
                                        $wh_id = (int)$d['origin_id'];
                                    }
                                    if ($wh_id && isset($warehouse_name_map[$wh_id])) {
                                        $wh_label = $warehouse_name_map[$wh_id];
                                    }
                                }
                            ?>
                            <td class="col-status">
                                <div class="status-cell">
                                    <span class="status-text"><?php echo $status_text; ?></span>
                                    <?php if (!empty($wh_label)): ?>
                                        <span class="status-subtext">(<?php echo htmlspecialchars($wh_label); ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="col-delivery-date"><?php echo $d['actual_delivery_date_formatted']; ?></td>
                            <?php
                                $delivery_watts = (float)($d['wattage'] ?? 0) * (float)($d['quantity'] ?? 0);
                                $delivery_freight = (float)($d['customer_cost'] ?? 0);
                                $delivery_accessorial = (float)($d['accessorial_costs'] ?? 0);
                                $delivery_total_cost = (float)($d['display_total_freight_only'] ?? ($delivery_freight + $delivery_accessorial));
                                $delivery_freight_per_watt = $delivery_watts > 0 ? ($delivery_freight / $delivery_watts) : null;
                                $delivery_accessorial_per_watt = $delivery_watts > 0 ? ($delivery_accessorial / $delivery_watts) : null;
                                $delivery_total_per_watt = $delivery_watts > 0 ? ($delivery_total_cost / $delivery_watts) : null;
                            ?>
                            <td class="col-freight-cost" style="text-align: right;">
                                <span class="cost-display" data-cost="<?php echo $delivery_freight; ?>" data-per-watt="<?php echo $delivery_freight_per_watt !== null ? $delivery_freight_per_watt : ''; ?>">
                                    $<?php echo number_format($delivery_freight, 2); ?>
                                </span>
                            </td>
                            <td class="col-accessorial-cost" style="text-align: right;">
                                <span class="cost-display" data-cost="<?php echo $delivery_accessorial; ?>" data-per-watt="<?php echo $delivery_accessorial_per_watt !== null ? $delivery_accessorial_per_watt : ''; ?>">
                                    $<?php echo number_format($delivery_accessorial, 2); ?>
                                </span>
                            </td>
                            <td class="col-total-cost" style="text-align: right; font-weight: bold; background-color: #f8f9fa;">
                                <span class="cost-display" data-cost="<?php echo $delivery_total_cost; ?>" data-per-watt="<?php echo $delivery_total_per_watt !== null ? $delivery_total_per_watt : ''; ?>">
                                    $<?php echo number_format($delivery_total_cost, 2); ?>
                                </span>
                            </td>
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
        <?php endif; ?>
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
                <div class="table-header-center">
                <div class="filtered-summary-header">Totals:</div>
                    <div class="filtered-summary-chips">
                        <div class="filtered-summary-chip">
                            <span class="label">Freight</span>
                            <span class="value">
                                <span class="cost-display" data-cost="<?php echo $displayed_freight_cost; ?>" data-per-watt="<?php echo $displayed_freight_per_watt !== null ? $displayed_freight_per_watt : ''; ?>">
                                    $<?php echo number_format($displayed_freight_cost, 2); ?>
                                </span>
                            </span>
                        </div>
                        <div class="filtered-summary-chip">
                            <span class="label">Warehousing</span>
                            <span class="value">
                                <span class="cost-display" data-cost="<?php echo $displayed_warehousing_cost; ?>" data-per-watt="<?php echo $displayed_warehousing_per_watt !== null ? $displayed_warehousing_per_watt : ''; ?>">
                                    $<?php echo number_format($displayed_warehousing_cost, 2); ?>
                                </span>
                            </span>
                        </div>
                        <div class="filtered-summary-chip">
                            <span class="label">Accessorial</span>
                            <span class="value">
                                <span class="cost-display" data-cost="<?php echo $displayed_accessorial_cost; ?>" data-per-watt="<?php echo $displayed_accessorial_per_watt !== null ? $displayed_accessorial_per_watt : ''; ?>">
                                    $<?php echo number_format($displayed_accessorial_cost, 2); ?>
                                </span>
                            </span>
                        </div>
                        <?php if ($displayed_has_module_cost): ?>
                        <div class="filtered-summary-chip">
                            <span class="label">Module</span>
                            <span class="value">
                                <span class="cost-display" data-cost="<?php echo $displayed_module_cost; ?>" data-per-watt="<?php echo $displayed_module_per_watt !== null ? $displayed_module_per_watt : ''; ?>">
                                    $<?php echo number_format($displayed_module_cost, 2); ?>
                                </span>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="filtered-summary-chip">
                            <span class="label">Total</span>
                            <span class="value">
                                <span class="cost-display" data-cost="<?php echo $displayed_total_cost; ?>" data-per-watt="<?php echo $displayed_total_per_watt !== null ? $displayed_total_per_watt : ''; ?>">
                                    $<?php echo number_format($displayed_total_cost, 2); ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
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
                            <th>Module Cost (Accrued)</th>
                            <th>Total Cost</th>
                            <th>Actions</th>
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
                                        <?php
                                            $p_status = $p['status'] ?? '';
                                            $p_status_lower = strtolower($p_status);
                                            $p_wh_label = '';
                                            if (in_array($p_status_lower, ['in warehouse', 'delivered to warehouse']) && !empty($p['current_warehouse_id'])) {
                                                $pid = (int)$p['current_warehouse_id'];
                                                if (isset($warehouse_name_map[$pid])) {
                                                    $p_wh_label = $warehouse_name_map[$pid];
                                                }
                                            }
                                        ?>
                                        <div class="status-cell">
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $p_status)); ?>">
                                                <?php echo htmlspecialchars($p_status); ?>
                                            </span>
                                            <?php if (!empty($p_wh_label)): ?>
                                                <span class="status-subtext">(<?php echo htmlspecialchars($p_wh_label); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php
                                        $pallet_watts = (float)($p['wattage'] ?? 0) * (float)($p['quantity'] ?? 0);
                                        $warehouse_cost = (float)($p['display_warehouse_cost'] ?? 0);
                                        $freight_cost = (float)($p['freight_cost'] ?? 0);
                                        $accessorial_cost = (float)($p['accessorial_cost'] ?? 0);
                                        $module_cost = $p['module_cost'] !== null ? (float)$p['module_cost'] : null;
                                        $total_cost = (float)($p['total_cost'] ?? 0);
                                        $warehouse_per_watt = $pallet_watts > 0 ? ($warehouse_cost / $pallet_watts) : null;
                                        $freight_per_watt = $pallet_watts > 0 ? ($freight_cost / $pallet_watts) : null;
                                        $accessorial_per_watt = $pallet_watts > 0 ? ($accessorial_cost / $pallet_watts) : null;
                                        $module_per_watt = ($pallet_watts > 0 && $module_cost !== null) ? ($module_cost / $pallet_watts) : null;
                                        $total_per_watt = $pallet_watts > 0 ? ($total_cost / $pallet_watts) : null;
                                    ?>
                                    <td>
                                        <span class="cost-display" data-cost="<?php echo $warehouse_cost; ?>" data-per-watt="<?php echo $warehouse_per_watt !== null ? $warehouse_per_watt : ''; ?>">
                                            $<?php echo number_format($warehouse_cost, 2); ?>
                                        </span>
                                        <?php if (!empty($p['pending_warehouse_cost'])): ?>
                                            <span style="font-size:0.8em; color:#d97706;" title="Pending/Accruing cost included">
                                                incl. $<?php echo number_format($p['pending_warehouse_cost'], 2); ?> pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="cost-display" data-cost="<?php echo $freight_cost; ?>" data-per-watt="<?php echo $freight_per_watt !== null ? $freight_per_watt : ''; ?>">
                                            $<?php echo number_format($freight_cost, 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="cost-display" data-cost="<?php echo $accessorial_cost; ?>" data-per-watt="<?php echo $accessorial_per_watt !== null ? $accessorial_per_watt : ''; ?>">
                                            $<?php echo number_format($accessorial_cost, 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($module_cost !== null): ?>
                                            <span class="cost-display" data-cost="<?php echo $module_cost; ?>" data-per-watt="<?php echo $module_per_watt !== null ? $module_per_watt : ''; ?>">
                                                $<?php echo number_format($module_cost, 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#6c757d;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight: 600; color: #488C9A;">
                                        <span class="cost-display" data-cost="<?php echo $total_cost; ?>" data-per-watt="<?php echo $total_per_watt !== null ? $total_per_watt : ''; ?>">
                                            $<?php echo number_format($total_cost, 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a class="action-btn action-btn-primary" href="pallet_details.php?pallet_id=<?php echo (int)$p['id']; ?>&project_id=<?php echo (int)$project_id; ?>&from=cost_details">
                                            <i class="fas fa-eye"></i>
                                            Pallet Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 40px; color: #6c757d;">
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
    const projectIdForLinks = <?php echo (int)$project_id; ?>;
    const deliveryStatusOptions = [
        'On Water',
        'Customs Hold',
        'Cleared Customs',
        'In Transit to Warehouse',
        'Delivered to Warehouse',
        'In Transit to Project',
        'Delivered to Project',
        'Canceled'
    ];
    const palletStatusOptions = <?php echo json_encode($pallet_statuses); ?>;

    function showPalletModal(button) {
        const modal = document.getElementById('associatedPalletsModal');
        const list = document.getElementById('palletList');

        if (!modal || !list || !button) return;

        let pallets = [];
        try {
            pallets = JSON.parse(button.getAttribute('data-pallets') || '[]');
        } catch (e) {
            pallets = [];
        }

        if (!Array.isArray(pallets) || pallets.length === 0) {
            list.innerHTML = '<p style="text-align: center; color: #6c757d; margin: 0;">No pallets found.</p>';
        } else {
            const table = document.createElement('table');
            table.className = 'modal-table';

            const thead = table.createTHead();
            const hr = thead.insertRow();
            ['Identifier', 'Wattage', 'Quantity', 'Actions'].forEach(h => {
                const th = document.createElement('th');
                th.textContent = h;
                hr.appendChild(th);
            });

            const tbody = table.createTBody();
            pallets.forEach(p => {
                const r = tbody.insertRow();
                const idCell = r.insertCell();
                idCell.textContent = p.pallet_identifier || `ID: ${p.id}`;

                const wattCell = r.insertCell();
                wattCell.textContent = p.wattage ? `${p.wattage}W` : '—';

                const qtyCell = r.insertCell();
                qtyCell.textContent = p.quantity ?? '—';

                const actCell = r.insertCell();
                const link = document.createElement('a');
                link.href = `pallet_details.php?pallet_id=${p.id}&project_id=${projectIdForLinks}&from=cost_details`;
                link.className = 'action-btn action-btn-primary';
                link.innerHTML = '<i class="fas fa-eye"></i> View Details';
                actCell.appendChild(link);
            });

            list.innerHTML = '';
            list.appendChild(table);
        }

        modal.style.display = 'block';
    }

    function closeAssociatedPalletModal() {
        const modal = document.getElementById('associatedPalletsModal');
        const list = document.getElementById('palletList');
        if (modal) {
            modal.style.display = 'none';
        }
        if (list) {
            list.innerHTML = '';
        }
    }

    window.addEventListener('click', function(event) {
        const modal = document.getElementById('associatedPalletsModal');
        if (modal && event.target === modal) {
            closeAssociatedPalletModal();
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeAssociatedPalletModal();
        }
    });

    function populateStatusOptions(context) {
        const select = document.getElementById('statusFilter');
        if (!select) return;
        const currentValue = select.value || select.dataset.selected || '';
        const statuses = context === 'deliveries' ? deliveryStatusOptions : palletStatusOptions;
        select.innerHTML = '<option value="">All Statuses</option>';
        statuses.forEach(status => {
            if (!status) return;
            const opt = document.createElement('option');
            opt.value = status;
            opt.textContent = status;
            select.appendChild(opt);
        });
        if (currentValue && statuses.includes(currentValue)) {
            select.value = currentValue;
        } else {
            select.value = '';
        }
    }

    function setStatusContext(context) {
        const input = document.getElementById('statusContext');
        if (input) {
            input.value = context;
        }
        populateStatusOptions(context);
    }

    function switchTab(tabName) {
        let normalizedTab = tabName;
        if (normalizedTab === 'deliveries') {
            normalizedTab = 'summary';
        }
        if (!document.getElementById('tab-' + normalizedTab)) {
            normalizedTab = 'summary';
        }

        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        
        // Show selected
        document.getElementById('tab-' + normalizedTab).classList.add('active');
        
        // Activate button
        const btns = document.querySelectorAll('.tab-btn');
        btns.forEach(btn => {
            if (btn.getAttribute('onclick').includes(normalizedTab)) {
                btn.classList.add('active');
            }
        });
        
        // Store preference
        localStorage.setItem('cost_details_tab', normalizedTab);

        const statusContext = normalizedTab === 'pallets' ? 'pallets' : 'deliveries';
        setStatusContext(statusContext);
    }
    
    // Restore tab on load
    document.addEventListener('DOMContentLoaded', () => {
        const savedTabRaw = localStorage.getItem('cost_details_tab');
        const savedTab = savedTabRaw === 'deliveries' ? 'summary' : savedTabRaw;
        switchTab(savedTab || 'summary');
        setViewMode('dollars');
    });

    // Logistics Modal Functions
    function openLogisticsModal() {
        const modal = document.getElementById('logisticsModal');
        if (modal) {
            modal.style.display = 'block';
            initLogisticsChart('dollars');
        }
    }

    function closeLogisticsModal() {
        const modal = document.getElementById('logisticsModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    function openModuleModal() {
        const modal = document.getElementById('moduleModal');
        if (modal) {
            modal.style.display = 'block';
        }
    }

    function closeModuleModal() {
        const modal = document.getElementById('moduleModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const logisticsModal = document.getElementById('logisticsModal');
        if (logisticsModal && event.target === logisticsModal) {
            closeLogisticsModal();
        }
        const moduleModal = document.getElementById('moduleModal');
        if (moduleModal && event.target === moduleModal) {
            closeModuleModal();
        }
    });

    // Filter functions
    function setFilter(filterValue) {
        const url = new URL(window.location.href);
        url.searchParams.set('filter', filterValue);
        window.location.href = url.toString();
    }

    function formatCurrency(value) {
        return '$' + value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatPerWatt(value) {
        return '$' + value.toFixed(4) + '/W';
    }

    function applyViewMode(mode) {
        const isPerWatt = mode === 'per_watt';
        document.querySelectorAll('.cost-display').forEach(el => {
            const rawCost = parseFloat(el.dataset.cost || '0');
            const rawPerWatt = el.dataset.perWatt;
            const perWattVal = rawPerWatt !== undefined && rawPerWatt !== '' ? parseFloat(rawPerWatt) : null;
            if (isPerWatt) {
                if (perWattVal === null || Number.isNaN(perWattVal)) {
                    el.textContent = '—';
                } else {
                    el.textContent = formatPerWatt(perWattVal);
                }
            } else {
                el.textContent = formatCurrency(rawCost);
            }
        });
    }

    function setViewMode(mode) {
        const buttons = document.querySelectorAll('.view-mode-btn');
        buttons.forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.mode === mode) {
                btn.classList.add('active');
            }
        });
        localStorage.setItem('cost_view_mode', mode);
        applyViewMode(mode);
        renderCharts(mode);
        const logisticsModal = document.getElementById('logisticsModal');
        if (logisticsModal && logisticsModal.style.display === 'block') {
            initLogisticsChart(mode);
        }
    }

    // Chart data from PHP
    const chartData = {
        freight: <?php echo $project_total_freight_cost; ?>,
        warehousing: <?php echo $project_total_warehousing_cost; ?>,
        accessorial: <?php echo $project_total_accessorial_costs; ?>,
        solterra: <?php echo $project_total_solterra_fee; ?>,
        module: <?php echo $project_total_module_cost; ?>,
        hasModuleCost: <?php echo $project_has_module_cost ? 'true' : 'false'; ?>,
        freightPerWatt: <?php echo $project_freight_per_watt !== null ? $project_freight_per_watt : 'null'; ?>,
        warehousingPerWatt: <?php echo $project_warehousing_per_watt !== null ? $project_warehousing_per_watt : 'null'; ?>,
        accessorialPerWatt: <?php echo $project_accessorial_per_watt !== null ? $project_accessorial_per_watt : 'null'; ?>,
        solterraPerWatt: <?php echo $project_solterra_per_watt !== null ? $project_solterra_per_watt : 'null'; ?>,
        modulePerWatt: <?php echo $project_module_cost_per_watt !== null ? $project_module_cost_per_watt : 'null'; ?>,
        hasWattage: <?php echo $project_total_watts > 0 ? 'true' : 'false'; ?>
    };

    const forecastActualData = {
        labels: <?php echo json_encode($forecast_actual_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS); ?>,
        forecast: <?php echo json_encode($forecast_actual_forecast_cumulative, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS); ?>,
        actual: <?php echo json_encode($forecast_actual_actual_cumulative, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS); ?>,
        totalWatts: <?php echo (float)$project_total_watts; ?>,
        xAxisTitle: <?php echo json_encode($forecast_actual_cadence_label, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS); ?>,
        hasData: <?php echo $has_forecast_actual_chart_data ? 'true' : 'false'; ?>
    };

    let costBreakdownChart = null;
    let logisticsChart = null;
    let forecastActualChart = null;

    function buildBreakdownData(source, isPerWatt) {
        const labels = ['Freight', 'Warehousing', 'Accessorial'];
        const colors = ['#488C9A', '#293E4C', '#fbb040'];
        const data = [
            isPerWatt ? source.freightPerWatt : source.freight,
            isPerWatt ? source.warehousingPerWatt : source.warehousing,
            isPerWatt ? source.accessorialPerWatt : source.accessorial
        ].map(v => (v === null || Number.isNaN(v) ? 0 : v));

        if ((source.solterra ?? 0) > 0) {
            labels.push('Solterra Fee');
            data.push(isPerWatt ? (source.solterraPerWatt ?? 0) : source.solterra);
            colors.push('#5ba3b1');
        }

        if (source.hasModuleCost && source.module > 0) {
            labels.push('Module Costs (Accrued)');
            data.push(isPerWatt ? (source.modulePerWatt ?? 0) : source.module);
            colors.push('#E4572E');
        }

        return { labels, data, colors };
    }

    function renderCharts(mode) {
        if (costBreakdownChart) {
            costBreakdownChart.destroy();
        }

        const breakdownCtx = document.getElementById('costBreakdownChart');
        if (breakdownCtx) {
            const isPerWatt = mode === 'per_watt' && chartData.hasWattage;
            const labels = [];
            const data = [];
            const colors = [];

            const pick = (total, perWatt) => isPerWatt ? (perWatt ?? 0) : (total ?? 0);

            if (chartData.hasModuleCost && (chartData.module ?? 0) > 0) {
                labels.push('Module Cost (Accrued)');
                data.push(pick(chartData.module, chartData.modulePerWatt));
                colors.push('#488C9A');
            }
            labels.push('Freight', 'Warehousing', 'Accessorial');
            data.push(
                pick(chartData.freight, chartData.freightPerWatt),
                pick(chartData.warehousing, chartData.warehousingPerWatt),
                pick(chartData.accessorial, chartData.accessorialPerWatt)
            );
            colors.push('#3b82f6', '#8b5cf6', '#f59e0b');

            if ((chartData.solterra ?? 0) > 0) {
                labels.push('Solterra Fee');
                data.push(pick(chartData.solterra, chartData.solterraPerWatt));
                colors.push('#5ba3b1');
            }

            costBreakdownChart = new Chart(breakdownCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors,
                        borderWidth: 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    const formatted = isPerWatt ? formatPerWatt(value) : formatCurrency(value);
                                    return `${context.label}: ${formatted} (${pct}%)`;
                                }
                            }
                        }
                    }
                },
                cutout: '62%'
            });
        }
        renderForecastActualChart(mode);
    }

    function renderForecastActualChart(mode) {
        const canvas = document.getElementById('forecastActualCostChart');
        if (!canvas || !forecastActualData.hasData) return;

        if (forecastActualChart) {
            forecastActualChart.destroy();
        }

        const isPerWatt = mode === 'per_watt' && forecastActualData.totalWatts > 0;
        const divisor = isPerWatt ? forecastActualData.totalWatts : 1;
        const forecastSeries = forecastActualData.forecast.map((value) => value / divisor);
        const actualSeries = forecastActualData.actual.map((value) => value / divisor);

        forecastActualChart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: forecastActualData.labels,
                datasets: [
                    {
                        label: isPerWatt ? 'Forecasted ($/W)' : 'Forecasted',
                        data: forecastSeries,
                        borderColor: '#488C9A',
                        backgroundColor: 'rgba(72, 140, 154, 0.08)',
                        borderWidth: 2,
                        borderDash: [6, 4],
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        tension: 0.2
                    },
                    {
                        label: isPerWatt ? 'Actual ($/W)' : 'Actual',
                        data: actualSeries,
                        borderColor: '#293E4C',
                        backgroundColor: 'rgba(41, 62, 76, 0.08)',
                        borderWidth: 2,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        tension: 0.2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 14 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.parsed.y || 0;
                                return isPerWatt
                                    ? `${context.dataset.label}: ${formatPerWatt(value)}`
                                    : `${context.dataset.label}: ${formatCurrency(value)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return isPerWatt ? formatPerWatt(Number(value)) : formatCurrency(Number(value));
                            }
                        },
                        title: {
                            display: true,
                            text: isPerWatt ? 'Cumulative Cost ($/W)' : 'Cumulative Cost ($)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: forecastActualData.xAxisTitle || 'Month'
                        }
                    }
                }
            }
        });
    }

    function initLogisticsChart(mode) {
        const ctx = document.getElementById('logisticsBreakdownChart');
        if (!ctx) return;

        // Destroy existing chart if any
        if (logisticsChart) {
            logisticsChart.destroy();
        }
        const isPerWatt = mode === 'per_watt';
        const breakdown = buildBreakdownData(chartData, isPerWatt);

        logisticsChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: breakdown.labels,
                datasets: [{
                    data: breakdown.data,
                    backgroundColor: breakdown.colors,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                if (isPerWatt) {
                                if (!chartData.hasWattage) {
                                    return `${context.label}: N/A`;
                                }
                                    return `${context.label}: ${formatPerWatt(value)}`;
                                }
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${context.label}: ${formatCurrency(value)} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
</script>
</body>
</html>
