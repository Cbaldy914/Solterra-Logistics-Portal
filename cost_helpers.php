<?php
/**
 * Helper functions for calculating and managing pallet/warehouse costs.
 * Supports multiple unit types: per_pallet, per_truck, per_sqft, flat
 */

/**
 * Calculate cost based on unit type.
 *
 * @param array $cost_item Cost item with amount, unit_type, pallets_per_truck, sqft_per_pallet
 * @param int $pallets Number of pallets
 * @param int $days_stored Days stored (for prorating monthly fees)
 * @return float Calculated cost
 */
function calculate_unit_cost($cost_item, $pallets, $days_stored = 30) {
    $amount = floatval($cost_item['amount'] ?? 0);
    $unit = $cost_item['unit_type'] ?? 'per_pallet';
    $trigger = $cost_item['trigger_event'] ?? 'other';

    if ($pallets <= 0 && $unit !== 'flat') {
        return 0;
    }

    switch ($unit) {
        case 'per_pallet':
            $cost = $amount * $pallets;
            // For monthly fees, prorate by days
            if ($trigger === 'monthly') {
                $cost = ($amount / 30) * $days_stored * $pallets;
            }
            return $cost;

        case 'per_truck':
            $per_truck = intval($cost_item['pallets_per_truck'] ?? 26);
            $trucks = $pallets > 0 ? ceil($pallets / $per_truck) : 0;
            return $amount * $trucks;

        case 'per_sqft':
            $sqft = floatval($cost_item['sqft_per_pallet'] ?? 13.33);
            $total_sqft = $pallets * $sqft;
            $cost = $amount * $total_sqft;
            // For monthly fees, prorate by days
            if ($trigger === 'monthly') {
                $cost = ($amount / 30) * $days_stored * $total_sqft;
            }
            return $cost;

        case 'flat':
            return $pallets > 0 ? $amount : 0;

        default:
            return $amount * $pallets;
    }
}

/**
 * Calculate comprehensive warehouse costs with full unit type support.
 *
 * @param int $warehouse_id Warehouse ID
 * @param int $pallets_in Number of pallets entering
 * @param int $pallets_out Number of pallets exiting
 * @param int $pallets_stored Current pallets in storage (for monthly)
 * @param int $days_stored Days stored (for prorating monthly)
 * @param mysqli $conn Database connection
 * @return array ['entry' => X, 'exit' => X, 'monthly' => X, 'customs' => X, 'drayage' => X, 'other' => X, 'total' => X, 'breakdown' => [...]]
 */
function calculate_warehouse_costs($warehouse_id, $pallets_in, $pallets_out, $pallets_stored, $days_stored, $conn) {
    $result = [
        'entry' => 0,
        'exit' => 0,
        'monthly' => 0,
        'customs' => 0,
        'drayage' => 0,
        'other' => 0,
        'total' => 0,
        'breakdown' => []
    ];

    if (!$warehouse_id) {
        return $result;
    }

    // Fetch all active cost items
    $stmt = $conn->prepare("
        SELECT id, label, trigger_event, amount, unit_type, pallets_per_truck, sqft_per_pallet
        FROM warehouse_cost_items
        WHERE warehouse_id = ? AND is_active = 1
        ORDER BY display_order, id
    ");

    if (!$stmt) {
        return $result;
    }

    $stmt->bind_param("i", $warehouse_id);
    $stmt->execute();
    $cost_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($cost_items as $item) {
        // Determine which pallet count to use based on trigger
        $pallets = 0;
        switch ($item['trigger_event']) {
            case 'entry':
                $pallets = $pallets_in;
                break;
            case 'exit':
                $pallets = $pallets_out;
                break;
            case 'monthly':
                $pallets = $pallets_stored;
                break;
            default:
                $pallets = $pallets_stored;
        }

        $cost = calculate_unit_cost($item, $pallets, $days_stored);

        // Categorize
        switch ($item['trigger_event']) {
            case 'entry':
                $result['entry'] += $cost;
                break;
            case 'exit':
                $result['exit'] += $cost;
                break;
            case 'monthly':
                $result['monthly'] += $cost;
                break;
            case 'customs_clearance':
                $result['customs'] += $cost;
                break;
            case 'drayage':
                $result['drayage'] += $cost;
                break;
            default:
                $result['other'] += $cost;
        }

        $result['breakdown'][] = [
            'id' => $item['id'],
            'label' => $item['label'],
            'trigger' => $item['trigger_event'],
            'unit' => $item['unit_type'],
            'base_amount' => floatval($item['amount']),
            'calculated_cost' => $cost
        ];
    }

    $result['total'] = $result['entry'] + $result['exit'] + $result['monthly']
                     + $result['customs'] + $result['drayage'] + $result['other'];

    return $result;
}

/**
 * Get warehouse rates for display (backward compatible).
 * Sums all fees by trigger type for legacy code compatibility.
 *
 * @param int $warehouse_id Warehouse ID
 * @param mysqli $conn Database connection
 * @return array ['in_fee' => X, 'out_fee' => X, 'monthly_storage_fee' => X, 'customs_fee' => X, 'all_items' => [...]]
 */
function get_warehouse_rates($warehouse_id, $conn) {
    $rates = [
        'in_fee' => 0,
        'out_fee' => 0,
        'monthly_storage_fee' => 0,
        'customs_fee' => 0,
        'drayage_fee' => 0,
        'all_items' => []
    ];

    if (!$warehouse_id) {
        return $rates;
    }

    $stmt = $conn->prepare("
        SELECT id, label, trigger_event, amount, unit_type, pallets_per_truck, sqft_per_pallet
        FROM warehouse_cost_items
        WHERE warehouse_id = ? AND is_active = 1
        ORDER BY display_order, id
    ");

    if (!$stmt) {
        return $rates;
    }

    $stmt->bind_param("i", $warehouse_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($items as $item) {
        $rates['all_items'][] = $item;

        // Sum by trigger for backward compatibility (sum ALL of same type)
        $amount = floatval($item['amount']);
        switch ($item['trigger_event']) {
            case 'entry':
                $rates['in_fee'] += $amount;
                break;
            case 'exit':
                $rates['out_fee'] += $amount;
                break;
            case 'monthly':
                $rates['monthly_storage_fee'] += $amount;
                break;
            case 'customs_clearance':
                $rates['customs_fee'] += $amount;
                break;
            case 'drayage':
                $rates['drayage_fee'] += $amount;
                break;
        }
    }

    return $rates;
}

/**
 * Compute the effective monthly $/pallet rate for a warehouse, collapsing all
 * monthly cost items across every unit type (per_pallet, per_sqft, per_truck, flat).
 *
 * Pass $monthly_items if you already have them cached; otherwise they're fetched.
 *
 * @param int $warehouse_id
 * @param mysqli $conn
 * @param array|null $monthly_items Pre-fetched rows with amount/unit_type/sqft_per_pallet/pallets_per_truck
 * @return float Effective $/pallet/month
 */
function get_monthly_rate_per_pallet($warehouse_id, $conn, $monthly_items = null) {
    if ($monthly_items === null) {
        if (!$warehouse_id) return 0.0;
        $stmt = $conn->prepare("
            SELECT amount, unit_type, pallets_per_truck, sqft_per_pallet
            FROM warehouse_cost_items
            WHERE warehouse_id = ? AND trigger_event = 'monthly' AND is_active = 1
        ");
        if (!$stmt) return 0.0;
        $stmt->bind_param("i", $warehouse_id);
        $stmt->execute();
        $monthly_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $rate_per_pallet = 0.0;
    foreach ($monthly_items as $item) {
        // Reuse calculate_unit_cost for 1 pallet over 30 days = effective monthly per-pallet rate
        $item_for_calc = $item;
        $item_for_calc['trigger_event'] = 'monthly';
        $rate_per_pallet += calculate_unit_cost($item_for_calc, 1, 30);
    }
    return $rate_per_pallet;
}

/**
 * Calculate running cost to date for a warehouse: actual storage accrued plus
 * entry/exit fees actually paid, across all pallets currently in and departed
 * from this warehouse. Optionally scoped to a project.
 *
 * Returns:
 *   'storage_cost' => running storage accrual (days stored × daily rate)
 *   'entry_fees'   => total entry fees paid
 *   'exit_fees'    => total exit fees paid
 *   'total'        => sum of the above
 *   'monthly_rate_per_pallet' => effective $/pallet/month
 *   'current_pallets' => pallets currently in warehouse (scoped)
 *   'monthly_run_rate' => monthly_rate_per_pallet × current_pallets
 *
 * @param int $warehouse_id
 * @param mysqli $conn
 * @param int|null $project_id
 * @return array
 */
function calculate_warehouse_running_cost($warehouse_id, $conn, $project_id = null) {
    $out = [
        'storage_cost' => 0.0,
        'entry_fees' => 0.0,
        'exit_fees' => 0.0,
        'total' => 0.0,
        'monthly_rate_per_pallet' => 0.0,
        'current_pallets' => 0,
        'monthly_run_rate' => 0.0,
    ];
    if (!$warehouse_id) return $out;

    // Fetch all cost items once, bucketed by trigger
    $stmt = $conn->prepare("
        SELECT label, trigger_event, amount, unit_type, pallets_per_truck, sqft_per_pallet
        FROM warehouse_cost_items
        WHERE warehouse_id = ? AND is_active = 1
    ");
    if (!$stmt) return $out;
    $stmt->bind_param("i", $warehouse_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $by_trigger = ['entry' => [], 'exit' => [], 'monthly' => []];
    foreach ($items as $it) {
        $trig = $it['trigger_event'] ?? '';
        if (isset($by_trigger[$trig])) $by_trigger[$trig][] = $it;
    }

    $monthly_rate_per_pallet = get_monthly_rate_per_pallet($warehouse_id, $conn, $by_trigger['monthly']);
    $daily_rate = $monthly_rate_per_pallet / 30.0;
    $out['monthly_rate_per_pallet'] = $monthly_rate_per_pallet;

    // --- Pallet days stored (current) ---
    $params = [$warehouse_id];
    $types  = "i";
    $sql_cur = "SELECT COUNT(*) AS cnt, COALESCE(SUM(GREATEST(DATEDIFF(CURDATE(), arrival_date), 0)), 0) AS total_days
                FROM inventory_pallets
                WHERE current_warehouse_id = ? AND status = 'In Warehouse'";
    if ($project_id) {
        $sql_cur .= " AND assigned_project_id = ?";
        $params[] = $project_id; $types .= "i";
    }
    $stmt = $conn->prepare($sql_cur);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $out['current_pallets'] = (int)($row['cnt'] ?? 0);
        $out['storage_cost']   += (float)($row['total_days'] ?? 0) * $daily_rate;
    }
    $out['monthly_run_rate'] = $monthly_rate_per_pallet * $out['current_pallets'];

    // --- Pallet days stored (departed) ---
    // A pallet may arrive via warehouse_id and depart via origin_type='warehouse' leg
    $params = [$warehouse_id, $warehouse_id, $warehouse_id, $warehouse_id];
    $types  = "iiii";
    $sql_dep = "
        SELECT dp.inventory_pallet_id AS pallet_id,
               MIN(CASE WHEN d.warehouse_id = ? THEN d.warehouse_arrival_date END) AS arrival_date,
               MIN(CASE WHEN d.origin_type = 'warehouse' AND d.origin_id = ? THEN d.left_warehouse_date END) AS departure_date
        FROM deliveries d
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        WHERE (d.warehouse_id = ? OR (d.origin_type = 'warehouse' AND d.origin_id = ?))
    ";
    if ($project_id) {
        $sql_dep .= " AND d.project_id = ?";
        $params[] = $project_id; $types .= "i";
    }
    $sql_dep .= " GROUP BY dp.inventory_pallet_id";
    $stmt = $conn->prepare($sql_dep);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            if (!$r['arrival_date'] || !$r['departure_date']) continue;
            $a = strtotime($r['arrival_date']); $l = strtotime($r['departure_date']);
            if ($a === false || $l === false || $l < $a) continue;
            $days = (int)ceil(($l - $a) / 86400);
            if ($days < 0) $days = 0;
            $out['storage_cost'] += $days * $daily_rate;
        }
        $stmt->close();
    }

    // --- Entry/Exit fees based on actual BOL / pallet counts ---
    // Inbound: distinct BOLs arrived + distinct pallets that arrived
    $params = [$warehouse_id]; $types = "i";
    $sql_in = "SELECT COUNT(DISTINCT d.bol_number) AS bols,
                      COUNT(DISTINCT dp.inventory_pallet_id) AS pallets
               FROM deliveries d
               LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
               WHERE d.warehouse_id = ? AND d.warehouse_arrival_date IS NOT NULL";
    if ($project_id) { $sql_in .= " AND d.project_id = ?"; $params[] = $project_id; $types .= "i"; }
    $stmt = $conn->prepare($sql_in);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $in_bols    = (int)($row['bols'] ?? 0);
        $in_pallets = (int)($row['pallets'] ?? 0);
        foreach ($by_trigger['entry'] as $fee) {
            $unit = $fee['unit_type'] ?? 'per_pallet';
            $amt  = (float)($fee['amount'] ?? 0);
            if ($unit === 'per_truck' || $unit === 'per_bol') {
                $out['entry_fees'] += $amt * $in_bols;
            } elseif ($unit === 'per_sqft') {
                $sqft = (float)($fee['sqft_per_pallet'] ?? 13.33);
                $out['entry_fees'] += $amt * $sqft * $in_pallets;
            } elseif ($unit === 'flat') {
                $out['entry_fees'] += $in_pallets > 0 ? $amt : 0;
            } else { // per_pallet
                $out['entry_fees'] += $amt * $in_pallets;
            }
        }
    }

    // Outbound: distinct BOLs departed + distinct pallets that departed
    $params = [$warehouse_id, $warehouse_id]; $types = "ii";
    $sql_out = "SELECT COUNT(DISTINCT d.bol_number) AS bols,
                       COUNT(DISTINCT dp.inventory_pallet_id) AS pallets
                FROM deliveries d
                LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
                WHERE d.left_warehouse_date IS NOT NULL
                  AND ((d.origin_type = 'warehouse' AND d.origin_id = ?)
                       OR (d.warehouse_id = ? AND d.warehouse_arrival_date IS NOT NULL
                           AND d.left_warehouse_date > d.warehouse_arrival_date))";
    if ($project_id) { $sql_out .= " AND d.project_id = ?"; $params[] = $project_id; $types .= "i"; }
    $stmt = $conn->prepare($sql_out);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $out_bols    = (int)($row['bols'] ?? 0);
        $out_pallets = (int)($row['pallets'] ?? 0);
        foreach ($by_trigger['exit'] as $fee) {
            $unit = $fee['unit_type'] ?? 'per_pallet';
            $amt  = (float)($fee['amount'] ?? 0);
            if ($unit === 'per_truck' || $unit === 'per_bol') {
                $out['exit_fees'] += $amt * $out_bols;
            } elseif ($unit === 'per_sqft') {
                $sqft = (float)($fee['sqft_per_pallet'] ?? 13.33);
                $out['exit_fees'] += $amt * $sqft * $out_pallets;
            } elseif ($unit === 'flat') {
                $out['exit_fees'] += $out_pallets > 0 ? $amt : 0;
            } else {
                $out['exit_fees'] += $amt * $out_pallets;
            }
        }
    }

    $out['total'] = $out['storage_cost'] + $out['entry_fees'] + $out['exit_fees'];
    return $out;
}

/**
 * Calculate the total warehousing cost for a pallet's stay in a warehouse.
 * Updated to support multiple fees per trigger type (sums all entry, exit, monthly fees).
 *
 * @param int $warehouse_id The ID of the warehouse.
 * @param string $arrival_date The date the pallet arrived at the warehouse (Y-m-d H:i:s).
 * @param string $departure_date The date the pallet left the warehouse (Y-m-d H:i:s).
 * @param mysqli $conn Database connection.
 * @return float The total cost (In + Out + Storage).
 */
function calculate_pallet_storage_cost($warehouse_id, $arrival_date, $departure_date, $conn) {
    if (!$warehouse_id || !$arrival_date || !$departure_date) {
        return 0.00;
    }

    // Calculate duration
    $arrival_ts = strtotime($arrival_date);
    $departure_ts = strtotime($departure_date);

    if ($departure_ts < $arrival_ts) {
        return 0.00; // Should not happen, but safety check
    }

    $days_stored = ceil(($departure_ts - $arrival_ts) / (60 * 60 * 24));
    $days_stored = max(1, $days_stored); // Minimum 1 day

    // Use the new comprehensive cost calculation
    // For a single pallet: 1 in, 1 out, 1 stored
    $costs = calculate_warehouse_costs($warehouse_id, 1, 1, 1, $days_stored, $conn);

    return $costs['total'];
}

/**
 * Calculate module cost for a project based on cost_per_watt in the modules table.
 * Returns the total module value and related metrics.
 *
 * @param int $project_id The project ID
 * @param mysqli $conn Database connection
 * @return array ['total_module_cost' => X, 'total_watts' => X, 'avg_cost_per_watt' => X, 'has_cost_data' => bool, 'breakdown' => [...]]
 */
function calculate_project_module_cost($project_id, $conn) {
    $result = [
        'total_module_cost' => 0.0,
        'total_watts' => 0.0,
        'avg_cost_per_watt' => null,
        'has_cost_data' => false,
        'breakdown' => []
    ];

    if (!$project_id || !$conn) {
        return $result;
    }

    // Query modules directly by project_id and get wattage/quantity from unassigned_module_items
    // This uses the direct modules.project_id relationship
    $sql = "
        SELECT
            m.id AS module_id,
            m.vendor_name,
            m.cost_per_watt,
            umi.wattage,
            SUM(umi.quantity) AS total_quantity,
            SUM(umi.wattage * umi.quantity) AS total_watts
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ?
        GROUP BY m.id, m.vendor_name, m.cost_per_watt, umi.wattage
        ORDER BY m.vendor_name, umi.wattage
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $result;
    }

    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $total_module_cost = 0.0;
    $total_watts_with_cost = 0.0;
    $total_watts_all = 0.0;
    $breakdown = [];

    while ($row = $res->fetch_assoc()) {
        $watts = (float)($row['total_watts'] ?? 0);
        $cost_per_watt = $row['cost_per_watt'] !== null ? (float)$row['cost_per_watt'] : null;
        $total_watts_all += $watts;

        $module_cost = null;
        if ($cost_per_watt !== null && $watts > 0) {
            $module_cost = $cost_per_watt * $watts;
            $total_module_cost += $module_cost;
            $total_watts_with_cost += $watts;
            $result['has_cost_data'] = true;
        }

        $breakdown[] = [
            'module_id' => $row['module_id'],
            'vendor_name' => $row['vendor_name'],
            'wattage' => $row['wattage'],
            'quantity' => (int)$row['total_quantity'],
            'total_watts' => $watts,
            'cost_per_watt' => $cost_per_watt,
            'module_cost' => $module_cost
        ];
    }
    $stmt->close();

    $result['total_module_cost'] = $total_module_cost;
    $result['total_watts'] = $total_watts_all;
    $result['avg_cost_per_watt'] = $total_watts_with_cost > 0 ? ($total_module_cost / $total_watts_with_cost) : null;
    $result['breakdown'] = $breakdown;

    return $result;
}

/**
 * Calculate module cost for a specific delivery based on its pallets.
 *
 * @param int $delivery_id The delivery ID
 * @param mysqli $conn Database connection
 * @return array ['module_cost' => X, 'total_watts' => X, 'has_cost_data' => bool]
 */
function calculate_delivery_module_cost($delivery_id, $conn) {
    $result = [
        'module_cost' => 0.0,
        'total_watts' => 0.0,
        'has_cost_data' => false
    ];

    if (!$delivery_id || !$conn) {
        return $result;
    }

    $sql = "
        SELECT
            m.cost_per_watt,
            ip.wattage,
            ip.quantity
        FROM delivery_pallets dp
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE dp.delivery_id = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $result;
    }

    $stmt->bind_param("i", $delivery_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $watts = (float)($row['wattage'] ?? 0) * (float)($row['quantity'] ?? 0);
        $result['total_watts'] += $watts;

        if ($row['cost_per_watt'] !== null) {
            $result['module_cost'] += (float)$row['cost_per_watt'] * $watts;
            $result['has_cost_data'] = true;
        }
    }
    $stmt->close();

    return $result;
}

/**
 * Get aggregated module cost data across all projects for an account/user.
 *
 * @param mysqli $conn Database connection
 * @param int|null $user_id User ID for access filtering (null for global admin)
 * @param string $role User role
 * @return array Aggregated module cost data
 */
function get_portfolio_module_costs($conn, $user_id, $role) {
    $result = [
        'total_module_cost' => 0.0,
        'total_watts' => 0.0,
        'avg_cost_per_watt' => null,
        'has_cost_data' => false,
        'by_vendor' => [],
        'by_project' => []
    ];

    // Build project access query based on role
    if ($role === 'global_admin') {
        $project_sql = "SELECT id, project_name FROM projects WHERE status IS NULL OR status = 'active'";
        $stmt = $conn->prepare($project_sql);
    } else {
        $project_sql = "
            SELECT p.id, p.project_name
            FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE cau.user_id = ? AND (p.status IS NULL OR p.status = 'active')
        ";
        $stmt = $conn->prepare($project_sql);
        $stmt->bind_param("i", $user_id);
    }

    if (!$stmt) {
        return $result;
    }

    $stmt->execute();
    $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $by_vendor = [];
    $total_cost = 0.0;
    $total_watts_with_cost = 0.0;
    $total_watts_all = 0.0;

    foreach ($projects as $project) {
        $project_costs = calculate_project_module_cost($project['id'], $conn);

        if ($project_costs['has_cost_data']) {
            $result['has_cost_data'] = true;
        }

        $result['by_project'][$project['id']] = [
            'project_name' => $project['project_name'],
            'module_cost' => $project_costs['total_module_cost'],
            'total_watts' => $project_costs['total_watts'],
            'has_cost_data' => $project_costs['has_cost_data']
        ];

        $total_cost += $project_costs['total_module_cost'];
        $total_watts_all += $project_costs['total_watts'];

        // Aggregate by vendor
        foreach ($project_costs['breakdown'] as $item) {
            $vendor = $item['vendor_name'] ?? 'Unknown';
            if (!isset($by_vendor[$vendor])) {
                $by_vendor[$vendor] = [
                    'total_cost' => 0.0,
                    'total_watts' => 0.0,
                    'cost_per_watt' => null
                ];
            }
            if ($item['module_cost'] !== null) {
                $by_vendor[$vendor]['total_cost'] += $item['module_cost'];
                $by_vendor[$vendor]['total_watts'] += $item['total_watts'];
                $total_watts_with_cost += $item['total_watts'];
            }
        }
    }

    // Calculate vendor averages
    foreach ($by_vendor as $vendor => &$data) {
        if ($data['total_watts'] > 0) {
            $data['cost_per_watt'] = $data['total_cost'] / $data['total_watts'];
        }
    }

    $result['total_module_cost'] = $total_cost;
    $result['total_watts'] = $total_watts_all;
    $result['avg_cost_per_watt'] = $total_watts_with_cost > 0 ? ($total_cost / $total_watts_with_cost) : null;
    $result['by_vendor'] = $by_vendor;

    return $result;
}

/**
 * Calculate total customs hold cost for a project.
 * Includes pallets linked by assigned project, current project, or module batch project.
 *
 * @param int $project_id The project ID
 * @param mysqli $conn Database connection
 * @return float Total customs hold cost
 */
function calculate_project_customs_hold_cost($project_id, $conn) {
    if (!$project_id || !$conn) {
        return 0.0;
    }

    $total_customs_hold = 0.0;
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(COALESCE(ip.customs_hold_cost, 0)), 0) AS total_customs_hold
        FROM inventory_pallets ip
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE ip.assigned_project_id = ?
           OR ip.current_project_id = ?
           OR m.project_id = ?
    ");

    if (!$stmt) {
        return 0.0;
    }

    $stmt->bind_param("iii", $project_id, $project_id, $project_id);
    $stmt->execute();
    $stmt->bind_result($total_customs_hold);
    $stmt->fetch();
    $stmt->close();

    return (float)$total_customs_hold;
}

/**
 * Calculate total warehousing cost for a project based on pallet data.
 * Uses recorded warehouse_cost when available, or calculates from warehouse cost items.
 *
 * @param int $project_id The project ID
 * @param mysqli $conn Database connection
 * @return float Total warehousing cost
 */
function calculate_project_warehousing_cost($project_id, $conn) {
    if (!$project_id || !$conn) {
        return 0.0;
    }

    $total_cost = 0.0;

    // Get all pallets for this project
    $stmt = $conn->prepare("
        SELECT ip.id, ip.warehouse_cost, ip.current_warehouse_id, ip.arrival_date, ip.status
        FROM inventory_pallets ip
        WHERE ip.assigned_project_id = ?
    ");

    if (!$stmt) {
        return 0.0;
    }

    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $pallets_result = $stmt->get_result();
    $pallets = $pallets_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Cache warehouse cost items to avoid repeated queries
    $warehouse_rates = [];

    foreach ($pallets as $pallet) {
        $pallet_cost = 0.0;
        $warehouse_id = (int)($pallet['current_warehouse_id'] ?? 0);

        // First use recorded warehouse_cost if available
        if (!empty($pallet['warehouse_cost']) && $pallet['warehouse_cost'] > 0) {
            $pallet_cost = (float)$pallet['warehouse_cost'];

            // For pallets still in warehouse, add pending storage cost
            if ($pallet['status'] === 'In Warehouse' && $warehouse_id > 0 && !empty($pallet['arrival_date'])) {
                // Get monthly rate (cached)
                if (!isset($warehouse_rates[$warehouse_id])) {
                    $warehouse_rates[$warehouse_id] = get_warehouse_rates($warehouse_id, $conn);
                }
                $monthly_fee = $warehouse_rates[$warehouse_id]['monthly_storage_fee'] ?? 0;

                if ($monthly_fee > 0) {
                    $arrival = strtotime($pallet['arrival_date']);
                    $days = max(0, floor((time() - $arrival) / 86400));
                    $estimated_storage = $days * ($monthly_fee / 30);
                    if ($estimated_storage > $pallet_cost) {
                        $pallet_cost = $estimated_storage;
                    }
                }
            }
        } else {
            // No recorded cost - calculate from warehouse rates if pallet has warehouse history
            if ($warehouse_id > 0 && !empty($pallet['arrival_date'])) {
                if (!isset($warehouse_rates[$warehouse_id])) {
                    $warehouse_rates[$warehouse_id] = get_warehouse_rates($warehouse_id, $conn);
                }
                $rates = $warehouse_rates[$warehouse_id];

                $in_fee = $rates['in_fee'] ?? 0;
                $out_fee = $rates['out_fee'] ?? 0;
                $monthly_fee = $rates['monthly_storage_fee'] ?? 0;

                $arrival = strtotime($pallet['arrival_date']);
                $days = max(1, floor((time() - $arrival) / 86400));
                $daily_fee = $monthly_fee / 30;

                // Entry fee + storage + exit fee (if delivered)
                $pallet_cost = $in_fee + ($days * $daily_fee);
                if ($pallet['status'] === 'Delivered') {
                    $pallet_cost += $out_fee;
                }
            }
        }

        $total_cost += $pallet_cost;
    }

    return $total_cost;
}
?>
