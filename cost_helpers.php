<?php
/**
 * Helper functions for calculating and managing pallet costs.
 */

/**
 * Calculate the total warehousing cost for a pallet's stay in a warehouse.
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

    // Get warehouse cost items
    $stmt = $conn->prepare("
        SELECT label, trigger_event, amount, unit_type
        FROM warehouse_cost_items 
        WHERE warehouse_id = ? AND is_active = 1
    ");
    if (!$stmt) {
        return 0.00;
    }

    $stmt->bind_param("i", $warehouse_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $in_fee = 0;
    $out_fee = 0;
    $monthly_storage_fee = 0;

    while ($cost_item = $result->fetch_assoc()) {
        $label = strtolower($cost_item['label']);
        $amount = floatval($cost_item['amount'] ?? 0);
        
        // Determine fee type based on label or trigger_event
        // Note: Logic matches warehouse_info.php and project_cost_details.php
        if (strpos($label, 'entry') !== false || strpos($label, 'in') !== false || $cost_item['trigger_event'] === 'entry') {
            $in_fee = $amount;
        } elseif (strpos($label, 'exit') !== false || strpos($label, 'out') !== false || $cost_item['trigger_event'] === 'exit') {
            $out_fee = $amount;
        } elseif (strpos($label, 'monthly') !== false || strpos($label, 'storage') !== false || $cost_item['trigger_event'] === 'monthly') {
            $monthly_storage_fee = $amount;
        }
    }
    $stmt->close();

    // Calculate duration
    $arrival_ts = strtotime($arrival_date);
    $departure_ts = strtotime($departure_date);
    
    if ($departure_ts < $arrival_ts) {
        return 0.00; // Should not happen, but safety check
    }

    $days_stored = ceil(($departure_ts - $arrival_ts) / (60 * 60 * 24));
    $days_stored = max(0, $days_stored);

    // Calculate storage cost
    // Daily rate = Monthly rate / 30
    $daily_storage_rate = $monthly_storage_fee > 0 ? ($monthly_storage_fee / 30) : 0;
    $storage_cost = $days_stored * $daily_storage_rate;

    $total_cost = $in_fee + $out_fee + $storage_cost;

    return $total_cost;
}
?>
