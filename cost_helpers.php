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
?>
