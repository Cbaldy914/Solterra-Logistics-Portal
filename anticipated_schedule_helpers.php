<?php
/**
 * Anticipated Delivery Schedule Helper Functions
 * Shared utility functions for schedule calculations and conversions
 */

/**
 * Get week ending Sunday for any date
 * @param string $dateStr Date in Y-m-d format
 * @return string Sunday date in Y-m-d format
 */
if (!function_exists('getWeekEndingSunday')) {
    function getWeekEndingSunday($dateStr) {
        $dt = new DateTime($dateStr);
        if ($dt->format('w') != 0) { // If not Sunday
            $dt->modify('next Sunday');
        }
        return $dt->format('Y-m-d');
    }
}

/**
 * Convert between different delivery units
 * @param float $amount Amount to convert
 * @param string $fromUnit Source unit (mw, modules, pallets, trucks)
 * @param string $toUnit Target unit (mw, modules, pallets, trucks)
 * @param float $avgWattage Average wattage for MW calculations
 * @param int $modulesPerPallet Modules per pallet
 * @param int $palletsPerTruck Pallets per truck
 * @return float Converted amount
 */
function convertDeliveryUnits($amount, $fromUnit, $toUnit, $avgWattage, $modulesPerPallet = 30, $palletsPerTruck = 20) {
    // First convert to modules (base unit)
    $modules = 0;
    
    switch ($fromUnit) {
        case 'mw':
            $modules = ($amount * 1000000) / $avgWattage;
            break;
        case 'modules':
            $modules = $amount;
            break;
        case 'pallets':
            $modules = $amount * $modulesPerPallet;
            break;
        case 'trucks':
            $modules = $amount * $palletsPerTruck * $modulesPerPallet;
            break;
    }
    
    // Then convert from modules to target unit
    switch ($toUnit) {
        case 'mw':
            return ($modules * $avgWattage) / 1000000;
        case 'modules':
            return $modules;
        case 'pallets':
            return $modules / $modulesPerPallet;
        case 'trucks':
            return $modules / ($palletsPerTruck * $modulesPerPallet);
        default:
            return $modules;
    }
}

/**
 * Calculate weighted average wattage for a project
 * @param mysqli $conn Database connection
 * @param int $project_id Project ID
 * @return float Average wattage
 */
function getProjectAverageWattage($conn, $project_id) {
    $stmt = $conn->prepare("
        SELECT SUM(wattage * total_order) / SUM(total_order) AS avg_wattage
        FROM project_wattage_orders
        WHERE project_id = ?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['avg_wattage'] ? (float)$row['avg_wattage'] : 560; // Default to 560W if no data
}

/**
 * Get project size in different units
 * @param mysqli $conn Database connection
 * @param int $project_id Project ID
 * @return array ['mw' => X, 'modules' => Y, 'pallets' => Z, 'trucks' => W, 'avg_wattage' => A]
 */
function getProjectSizeSummary($conn, $project_id) {
    // Get wattage breakdown
    $stmt = $conn->prepare("
        SELECT wattage, total_order
        FROM project_wattage_orders
        WHERE project_id = ?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $total_mw = 0;
    $total_modules = 0;
    $wattage_breakdown = [];
    
    while ($row = $result->fetch_assoc()) {
        $w = (float)$row['wattage'];
        $qty = (int)$row['total_order'];
        
        $total_mw += ($qty * $w) / 1000000;
        $total_modules += $qty;
        $wattage_breakdown[] = [
            'wattage' => $w,
            'quantity' => $qty
        ];
    }
    $stmt->close();
    
    // Get project configuration from modules table
    // Use default values if no module data exists
    $modules_per_pallet = 30;
    $pallets_per_truck = 20;
    
    $avg_wattage = getProjectAverageWattage($conn, $project_id);
    
    // Get modules_per_pallet and pallets_per_truck from modules table (weighted average)
    $stmt = $conn->prepare("
        SELECT modules_per_pallet, pallets_per_truck
        FROM modules
        WHERE project_id = ? AND modules_per_pallet IS NOT NULL AND pallets_per_truck IS NOT NULL
        LIMIT 1
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $module_result = $stmt->get_result();
    
    if ($module_result->num_rows > 0) {
        $module_data = $module_result->fetch_assoc();
        $modules_per_pallet = (int)$module_data['modules_per_pallet'];
        $pallets_per_truck = (int)$module_data['pallets_per_truck'];
    }
    $stmt->close();
    
    return [
        'mw' => round($total_mw, 3),
        'modules' => $total_modules,
        'pallets' => round($total_modules / $modules_per_pallet, 2),
        'trucks' => round($total_modules / ($modules_per_pallet * $pallets_per_truck), 2),
        'avg_wattage' => $avg_wattage,
        'modules_per_pallet' => $modules_per_pallet,
        'pallets_per_truck' => $pallets_per_truck,
        'wattage_breakdown' => $wattage_breakdown
    ];
}

/**
 * Generate anticipated delivery data from schedule for graphing
 * @param mysqli $conn Database connection
 * @param int $project_id Project ID
 * @return array ['dates' => [], 'cumulative_mw' => []]
 */
function generateAnticipatedDeliveriesFromSchedule($conn, $project_id) {
    // Check if active schedule exists
    $stmt = $conn->prepare("
        SELECT id, schedule_type, delivery_start_date, quick_rate_value, 
               quick_rate_unit, quick_rate_frequency
        FROM anticipated_delivery_schedule
        WHERE project_id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return null; // No schedule found
    }
    
    $schedule = $result->fetch_assoc();
    $schedule_id = $schedule['id'];
    $stmt->close();
    
    $project_summary = getProjectSizeSummary($conn, $project_id);
    $dates = [];
    $cumulative_mw = [];
    
    if ($schedule['schedule_type'] === 'quick') {
        // Generate from quick schedule
        $start_date = new DateTime($schedule['delivery_start_date']);
        $rate_value = (float)$schedule['quick_rate_value'];
        $rate_unit = $schedule['quick_rate_unit'];
        $frequency = $schedule['quick_rate_frequency'];
        
        // Convert rate to MW per week
        $mw_per_week = convertDeliveryUnits(
            $rate_value,
            $rate_unit,
            'mw',
            $project_summary['avg_wattage'],
            $project_summary['modules_per_pallet'],
            $project_summary['pallets_per_truck']
        );
        
        if ($frequency === 'per_month') {
            $mw_per_week = $mw_per_week / 4.33; // Convert monthly to weekly
        }
        
        $cumulative = 0;
        $current_date = clone $start_date;
        $target_mw = $project_summary['mw'];
        
        while ($cumulative < $target_mw) {
            $week_ending = getWeekEndingSunday($current_date->format('Y-m-d'));
            $dates[] = $week_ending;
            
            $cumulative += $mw_per_week;
            if ($cumulative > $target_mw) {
                $cumulative = $target_mw; // Cap at project size
            }
            
            $cumulative_mw[] = round($cumulative, 3);
            $current_date->modify('+7 days');
            
            // Safety limit: max 520 weeks (10 years)
            if (count($dates) > 520) break;
        }
        
    } else {
        // Generate from detailed schedule
        $stmt = $conn->prepare("
            SELECT delivery_week_ending, delivery_amount, delivery_unit
            FROM anticipated_delivery_schedule_details
            WHERE schedule_id = ?
            ORDER BY delivery_week_ending ASC
        ");
        $stmt->bind_param("i", $schedule_id);
        $stmt->execute();
        $details = $stmt->get_result();
        
        $cumulative = 0;
        while ($row = $details->fetch_assoc()) {
            $dates[] = $row['delivery_week_ending'];
            
            // Convert to MW
            $mw_amount = convertDeliveryUnits(
                (float)$row['delivery_amount'],
                $row['delivery_unit'],
                'mw',
                $project_summary['avg_wattage'],
                $project_summary['modules_per_pallet'],
                $project_summary['pallets_per_truck']
            );
            
            $cumulative += $mw_amount;
            $cumulative_mw[] = round($cumulative, 3);
        }
        $stmt->close();
    }
    
    return [
        'dates' => $dates,
        'cumulative_mw' => $cumulative_mw
    ];
}

/**
 * Get existing schedule for a project
 * @param mysqli $conn Database connection
 * @param int $project_id Project ID
 * @return array|null Schedule data or null if none exists
 */
function getActiveSchedule($conn, $project_id) {
    $stmt = $conn->prepare("
        SELECT s.*, u.username as created_by_name
        FROM anticipated_delivery_schedule s
        JOIN users u ON s.created_by = u.id
        WHERE s.project_id = ? AND s.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return null;
    }
    
    $schedule = $result->fetch_assoc();
    $schedule_id = $schedule['id'];
    $stmt->close();
    
    // If detailed, fetch details
    if ($schedule['schedule_type'] === 'detailed') {
        $stmt = $conn->prepare("
            SELECT *
            FROM anticipated_delivery_schedule_details
            WHERE schedule_id = ?
            ORDER BY delivery_week_ending ASC
        ");
        $stmt->bind_param("i", $schedule_id);
        $stmt->execute();
        $details_result = $stmt->get_result();
        
        $schedule['details'] = [];
        while ($row = $details_result->fetch_assoc()) {
            $schedule['details'][] = $row;
        }
        $stmt->close();
    }
    
    return $schedule;
}

