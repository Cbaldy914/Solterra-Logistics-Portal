<?php
session_name("logistics_session");
session_start();



// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Grab the user's role from the session
$role = $_SESSION['role'] ?? 'user';

if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);

// Database connection
require_once '../config.php';
require_once 'anticipated_schedule_helpers.php';
require_once 'cost_helpers.php';
require_once 'milestone_helpers.php';
require_once 'projection_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Inline update of module batch info (admin/global_admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_module_batch' && in_array($role, ['admin','global_admin','customer_admin'])) {
    $upd_batch_id = intval($_POST['batch_id'] ?? 0);
    if ($upd_batch_id > 0) {
        $fields = [
            'modules_per_pallet' => FILTER_VALIDATE_INT,
            'pallets_per_truck' => FILTER_VALIDATE_INT,
            'modules_per_truck' => FILTER_VALIDATE_INT,
            'pallet_length_mm' => FILTER_VALIDATE_INT,
            'pallet_depth_mm' => FILTER_VALIDATE_INT,
            'pallet_double_stacked_height_mm' => FILTER_VALIDATE_INT,
            'pallet_total_weight_kg' => FILTER_VALIDATE_INT,
            'forklift_truck_long_side_mm' => FILTER_VALIDATE_INT,
            'forklift_truck_short_side_mm' => FILTER_VALIDATE_INT,
            'pallet_jack_long_side_mm' => FILTER_VALIDATE_INT,
            'pallet_jack_short_side_mm' => FILTER_VALIDATE_INT
        ];
        $ints = [];
        foreach ($fields as $k => $f) { $ints[$k] = ($_POST[$k] ?? '') !== '' ? intval($_POST[$k]) : null; }
        $module_notes = trim($_POST['module_notes'] ?? '');

        // Build dynamic update
        $sets = [
            'modules_per_pallet = ?', 'pallets_per_truck = ?', 'modules_per_truck = ?',
            'pallet_length_mm = ?', 'pallet_depth_mm = ?', 'pallet_double_stacked_height_mm = ?',
            'pallet_total_weight_kg = ?', 'forklift_truck_long_side_mm = ?', 'forklift_truck_short_side_mm = ?',
            'pallet_jack_long_side_mm = ?', 'pallet_jack_short_side_mm = ?', 'module_notes = ?'
        ];
        $sql = 'UPDATE modules SET ' . implode(', ', $sets) . ', last_updated_at = NOW() WHERE id = ?';
        $stmtU = $conn->prepare($sql);
        if ($stmtU) {
            $stmtU->bind_param(
                'iiiiiiiiiiisi',
                $ints['modules_per_pallet'], $ints['pallets_per_truck'], $ints['modules_per_truck'],
                $ints['pallet_length_mm'], $ints['pallet_depth_mm'], $ints['pallet_double_stacked_height_mm'],
                $ints['pallet_total_weight_kg'], $ints['forklift_truck_long_side_mm'], $ints['forklift_truck_short_side_mm'],
                $ints['pallet_jack_long_side_mm'], $ints['pallet_jack_short_side_mm'], $module_notes, $upd_batch_id
            );
            $stmtU->execute();
            $stmtU->close();
        }
        $_SESSION['project_overview_message'] = 'Module batch updated successfully.';
    }
    header('Location: project_overview.php?project_id=' . $project_id . '&view_mode=' . urlencode($view_mode));
    exit();
}

// Manual project health override (admin/global_admin/customer_admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_project_health' && in_array($role, ['admin','global_admin','customer_admin'])) {
    $health_status = $_POST['health_status'] ?? '';
    $health_reason = trim($_POST['health_reason'] ?? '');
    $user_id = $_SESSION['user_id'];

    if ($health_status === 'auto') {
        // Clear manual override - revert to auto-calculated
        $stmt_health = $conn->prepare("UPDATE projects SET manual_health_status = NULL, manual_health_reason = NULL, manual_health_set_by = NULL, manual_health_set_at = NULL WHERE id = ?");
        $stmt_health->bind_param("i", $project_id);
        $stmt_health->execute();
        $stmt_health->close();
        $_SESSION['project_overview_message'] = 'Project health reverted to auto-calculated status.';
    } elseif (in_array($health_status, ['on_track', 'at_risk', 'behind'])) {
        // Validate that reason is provided for at_risk or behind status
        if (($health_status === 'at_risk' || $health_status === 'behind') && empty($health_reason)) {
            $_SESSION['project_overview_error'] = 'Please provide a reason when setting the project to At Risk or Behind Schedule.';
        } else {
            $stmt_health = $conn->prepare("UPDATE projects SET manual_health_status = ?, manual_health_reason = ?, manual_health_set_by = ?, manual_health_set_at = NOW() WHERE id = ?");
            $stmt_health->bind_param("ssii", $health_status, $health_reason, $user_id, $project_id);
            $stmt_health->execute();
            $stmt_health->close();
            $_SESSION['project_overview_message'] = 'Project health status updated successfully.';
        }
    }
    header('Location: project_overview.php?project_id=' . $project_id);
    exit();
}

$view_mode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'mw';

/**
 * Calculate a quantity based on the user's chosen view mode:
 *   - modules => raw quantity
 *   - mw => (quantity * wattage) / 1,000,000
 */
function calculateQuantity($quantity, $wattage, $view_mode) {
    if ($view_mode == 'modules') {
        return $quantity;
    } elseif ($view_mode == 'mw') {
        return ($quantity * $wattage) / 1000000;
    } else {
        return $quantity; // default
    }
}

// Fetch project
$stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    die("Project not found.");
}
$project = $result->fetch_assoc();
$stmt->close();

// Grab the Solterra fee if any
$solterra_fee = isset($project['solterra_fee']) ? (float)$project['solterra_fee'] : 0.0;

// Parse forecasted costs if available
$forecasted_costs = [];
if (!empty($project['forecasted_costs'])) {
    $forecasted_costs = json_decode($project['forecasted_costs'], true);
    if (!is_array($forecasted_costs)) {
        $forecasted_costs = [];
    }
}
$forecasted_freight     = $forecasted_costs['freight']     ?? 0;
$forecasted_warehousing = $forecasted_costs['warehousing'] ?? 0; 
$forecasted_accessorial = $forecasted_costs['accessorial'] ?? 0;

// Ensure project_wattage_orders reflects all module batches assigned to this project
// We compare against actual batch items, and if mismatched, we rebuild the totals.
try {
    $actual_totals = [];
    if ($stmtA = $conn->prepare("\n        SELECT umi.wattage, SUM(umi.quantity) AS total_qty\n        FROM unassigned_module_items umi\n        JOIN modules m ON umi.unassigned_module_id = m.id\n        WHERE m.project_id = ?\n          AND NOT EXISTS (\n              SELECT 1\n              FROM inventory_pallets ip\n              JOIN warranty_claim_replacements wcr ON wcr.pallet_id = ip.id\n              WHERE ip.unassigned_module_item_id = umi.id\n          )\n        GROUP BY umi.wattage\n    ")) {
        $stmtA->bind_param("i", $project_id);
        $stmtA->execute();
        $resA = $stmtA->get_result();
        while ($row = $resA->fetch_assoc()) {
            $w = (int)$row['wattage'];
            $q = (int)$row['total_qty'];
            if ($w > 0 && $q > 0) { $actual_totals[$w] = $q; }
        }
        $stmtA->close();
    }

    $pwo_totals = [];
    if ($stmtP = $conn->prepare("SELECT wattage, total_order FROM project_wattage_orders WHERE project_id = ?")) {
        $stmtP->bind_param("i", $project_id);
        $stmtP->execute();
        $resP = $stmtP->get_result();
        while ($row = $resP->fetch_assoc()) {
            $pwo_totals[(int)$row['wattage']] = (int)$row['total_order'];
        }
        $stmtP->close();
    }

    $needs_sync = false;
    if (count($actual_totals) !== count($pwo_totals)) {
        $needs_sync = true;
    } else {
        foreach ($actual_totals as $w => $q) {
            if (!isset($pwo_totals[$w]) || $pwo_totals[$w] !== $q) { $needs_sync = true; break; }
        }
    }

    if ($needs_sync) {
        $conn->begin_transaction();
        if ($stmtDel = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ?")) {
            $stmtDel->bind_param("i", $project_id);
            $stmtDel->execute();
            $stmtDel->close();
        }
        if (!empty($actual_totals)) {
            if ($stmtIns = $conn->prepare("INSERT INTO project_wattage_orders (project_id, wattage, total_order) VALUES (?, ?, ?)")) {
                foreach ($actual_totals as $w => $q) {
                    $stmtIns->bind_param("iii", $project_id, $w, $q);
                    $stmtIns->execute();
                }
                $stmtIns->close();
            }
        }
        $conn->commit();
    }
} catch (Exception $e) {
    // If anything fails, do not block page rendering; leave existing data in place
}

// Fetch total orders
$stmt = $conn->prepare("
    SELECT wattage, total_order
    FROM project_wattage_orders
    WHERE project_id = ?
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$total_orders_result = $stmt->get_result();

$total_orders = [];
$project_size_mw = isset($project['project_size']) ? (float)$project['project_size'] : 0;
$wattages = [];

while ($row = $total_orders_result->fetch_assoc()) {
    $w = (float)$row['wattage'];
    $t = (int)$row['total_order'];
    $wattages[] = $w;

    $label = $w . 'W';
    $total_orders[$label] = [
        'wattage'     => $w,
        'total_order' => calculateQuantity($t, $w, $view_mode),
        'raw_quantity'=> $t,
    ];
}
$stmt->close();

// Count total raw modules for forecast
$total_raw_modules = 0;
foreach($total_orders as $lbl => $info) {
    $total_raw_modules += $info['raw_quantity'];
}

// Create combined wattage label
$non_zero_watts = array_filter($wattages, fn($v)=>$v>0);
if (count($non_zero_watts) > 0) {
    $min_w = min($non_zero_watts);
    $max_w = max($non_zero_watts);
    $module_type_combined = ($min_w == $max_w)
        ? ($min_w . 'W')
        : ($min_w . 'W-' . $max_w . 'W');
    // Calculate average wattage for conversions
    $avg_wattage = array_sum($non_zero_watts) / count($non_zero_watts);
} else {
    $module_type_combined = "N/A";
    $avg_wattage = 585; // Default wattage if none specified
}

/**
 * For grouping deliveries by week, we define a helper
 * that returns the Sunday of that week.
 */
function getWeekEndingSunday($dateStr) {
    $dt = new DateTime($dateStr);
    if ($dt->format('w') != 0) {
        $dt->modify('next Sunday');
    }
    return $dt->format('Y-m-d');
}

/**
 * Get the Sunday that starts the week containing this date (Sun-Sat weeks).
 * Matches the JS getWeekKey() logic.
 */
function getWeekStartingSunday($dateStr) {
    $dt = new DateTime($dateStr);
    $dayOfWeek = (int)$dt->format('w'); // 0=Sunday, 1=Monday, ...
    if ($dayOfWeek > 0) {
        $dt->modify("-{$dayOfWeek} days");
    }
    return $dt->format('Y-m-d');
}

// --------------- Anticipated vs Actual Deliveries (line chart) ---------------
function fetchDeliveriesByDate($conn, $project_id, $date_field, $status_filter = null) {
    // Build dynamic SQL allowing optional status filtering
    $sql = "SELECT wattage, $date_field AS delivery_date, SUM(quantity) AS quantity
            FROM deliveries
            WHERE project_id = ? AND $date_field IS NOT NULL";
    if ($status_filter !== null) {
        $sql .= " AND status_of_delivery = ?";
    }
    $sql .= " GROUP BY wattage, $date_field ORDER BY $date_field ASC";

    $stmt = $conn->prepare($sql);
    if ($status_filter !== null) {
        $stmt->bind_param("is", $project_id, $status_filter);
    } else {
        $stmt->bind_param("i", $project_id);
    }
    $stmt->execute();
    return $stmt->get_result();
}

// NEW: Check if anticipated delivery schedule exists - use it if available
$schedule_data = generateAnticipatedDeliveriesFromSchedule($conn, $project_id);

// Load primary delivery projection for financial forecasting
$primary_projection = get_primary_projection($conn, $project_id);
$anticipated_deliveries = [];
$date_labels = [];

if ($schedule_data && !empty($schedule_data['dates'])) {
    // Use schedule data - convert cumulative MW to non-cumulative (delta) values
    $prev_cumulative = 0;
    foreach ($schedule_data['dates'] as $idx => $date) {
        $cumulative_mw = $schedule_data['cumulative_mw'][$idx];
        $delta_mw = $cumulative_mw - $prev_cumulative;
        
        // Convert delta MW to view mode
        if ($view_mode === 'modules') {
            // Convert MW to modules using average wattage
            $avg_wattage = getProjectAverageWattage($conn, $project_id);
            $anticipated_deliveries[$date] = ($delta_mw * 1000000) / $avg_wattage;
        } else {
            // Keep as MW
            $anticipated_deliveries[$date] = $delta_mw;
        }
        
        if (!in_array($date, $date_labels)) {
            $date_labels[] = $date;
        }
        
        $prev_cumulative = $cumulative_mw;
    }
} else {
    // Fallback to old method - fetch from deliveries table
    $anticipated_res = fetchDeliveriesByDate($conn, $project_id, 'anticipated_delivery_date');
    
    while ($r = $anticipated_res->fetch_assoc()) {
        $w = (float)$r['wattage'];
        $dOriginal = $r['delivery_date'];
        $d = getWeekEndingSunday($dOriginal);
        $q_raw = (int)$r['quantity'];
        $q_calc = calculateQuantity($q_raw, $w, $view_mode);

        if (!isset($anticipated_deliveries[$d])) {
            $anticipated_deliveries[$d] = 0;
        }
        $anticipated_deliveries[$d] += $q_calc;
        if (!in_array($d, $date_labels)) {
            $date_labels[] = $d;
        }
    }
}

// Fetch actual deliveries: include all with status Delivered to Project (even if future-dated)
$actual_res = fetchDeliveriesByDate($conn, $project_id, 'actual_delivery_date', 'Delivered to Project');
$actual_deliveries = [];
while ($r = $actual_res->fetch_assoc()) {
    $w = (float)$r['wattage'];
    $dOriginal = $r['delivery_date'];
    $d = getWeekEndingSunday($dOriginal);

    $q_raw = (int)$r['quantity'];
    $q_calc = calculateQuantity($q_raw, $w, $view_mode);

    if (!isset($actual_deliveries[$d])) {
        $actual_deliveries[$d] = 0;
    }
    $actual_deliveries[$d] += $q_calc;
    if (!in_array($d, $date_labels)) {
        $date_labels[] = $d;
    }
}
sort($date_labels);

$today = new DateTime();
$today_str = $today->format('Y-m-d');
$current_week_ending = getWeekEndingSunday($today_str);

// Check if there are any actual deliveries in the future
$has_future_actual_deliveries = false;
foreach ($actual_deliveries as $delivery_date => $qty) {
    if ($delivery_date > $current_week_ending && $qty > 0) {
        $has_future_actual_deliveries = true;
        break;
    }
}

$cumulative_ant = 0;
$cumulative_act = 0;
$lineChartData_anticipated = [];
$lineChartData_actual      = [];

foreach ($date_labels as $dt) {
    $val_ant = $anticipated_deliveries[$dt] ?? 0;
    $cumulative_ant += $val_ant;
    $lineChartData_anticipated[] = $cumulative_ant;

    // For actuals: if there are future deliveries marked as Delivered to Project, show them
    // Otherwise, stop the line at the current week (use null for future data points)
    $val_act = $actual_deliveries[$dt] ?? 0;
    $cumulative_act += $val_act;

    if ($has_future_actual_deliveries) {
        // Show all actuals including future ones (demo mode)
        $lineChartData_actual[] = $cumulative_act;
    } else {
        // Only show actuals up to the current week
        if ($dt <= $current_week_ending) {
            $lineChartData_actual[] = $cumulative_act;
        } else {
            // Use null to stop the line (Chart.js will not draw a line to null points)
            $lineChartData_actual[] = null;
        }
    }
}

$lineChartData = [
    'anticipated' => $lineChartData_anticipated,
    'actual'      => $lineChartData_actual,
];
$dateLabelsJSON    = json_encode($date_labels);
$lineChartDataJSON = json_encode($lineChartData);

// --------------- Delivery Status Table ---------------
// Gather pallet statuses directly from inventory_pallets

// First get detailed breakdown from inventory_pallets
$status_totals = [
    'At Manufacturer' => ['pallets' => 0, 'modules' => 0],
    'On Water' => ['pallets' => 0, 'modules' => 0],
    'Delivered to Project' => ['pallets' => 0, 'modules' => 0],
    'Cleared Customs' => ['pallets' => 0, 'modules' => 0],
    'In Transit to Warehouse' => ['pallets' => 0, 'modules' => 0],
    'In Warehouse' => ['pallets' => 0, 'modules' => 0],
    'In Transit to Project' => ['pallets' => 0, 'modules' => 0],
    'Damaged' => ['pallets' => 0, 'modules' => 0]
];
$detailed_breakdown = [];

$stmt_status = $conn->prepare(
    "SELECT ip.status, ip.wattage, ip.quantity, ip.current_warehouse_id, w.name AS warehouse_name,
            ip.current_project_id, p.project_name
       FROM inventory_pallets ip
       LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
       LEFT JOIN modules m ON umi.unassigned_module_id = m.id
       LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
       LEFT JOIN projects p ON ip.current_project_id = p.id
       WHERE (m.project_id = ? OR ip.assigned_project_id = ? OR ip.current_project_id = ?)"
);
$stmt_status->bind_param('iii', $project_id, $project_id, $project_id);
$stmt_status->execute();
$res_status = $stmt_status->get_result();

// Also fetch damaged pallets from warranty claims JSON
$warranty_damaged_pallets = [];
$stmt_warranty = $conn->prepare(
    "SELECT wc.notes, s.project_id 
     FROM warranty_claims wc 
     LEFT JOIN site_scheduling s ON wc.scheduling_id = s.id 
     WHERE s.project_id = ? AND wc.notes IS NOT NULL AND wc.notes != ''"
);
$stmt_warranty->bind_param('i', $project_id);
$stmt_warranty->execute();
$res_warranty = $stmt_warranty->get_result();

while ($warranty_row = $res_warranty->fetch_assoc()) {
    $notes = $warranty_row['notes'];
    if ($notes) {
        $decoded = json_decode($notes, true);
        if (isset($decoded['pallets']) && is_array($decoded['pallets'])) {
            foreach ($decoded['pallets'] as $pallet_info) {
                if (isset($pallet_info['pallet_id']) && $pallet_info['damaged'] > 0) {
                    $warranty_damaged_pallets[] = [
                        'pallet_id' => $pallet_info['pallet_id'],
                        'wattage' => $pallet_info['wattage'],
                        'damaged' => $pallet_info['damaged'],
                        'actual' => $pallet_info['actual']
                    ];
                }
            }
        }
    }
}
$stmt_warranty->close();

// Build delivery_totals structure to match expected format
$delivery_totals = [];
$delivered_raw_total = 0; // Raw module count for timeline calculations
$delivered_damaged_total = 0; // Count of damaged modules that were delivered to project

while ($row = $res_status->fetch_assoc()) {
    $status  = $row['status'];
    $wattage = (int)$row['wattage'];
    $qty     = (int)$row['quantity'];
    $wh_id   = $row['current_warehouse_id'];
    $wh_name = $row['warehouse_name'];
    $proj_id = $row['current_project_id'];
    $proj_name = $row['project_name'];

    // Build delivery_totals by wattage for the main table
    $w = (float)$wattage;
    $lbl = $w . 'W';
    $q_calc = calculateQuantity($qty, $w, $view_mode);

    if (!isset($delivery_totals[$lbl])) {
        $delivery_totals[$lbl] = [
            'At Manufacturer'        => 0,
            'On Water'               => 0,
            'Cleared Customs'        => 0,
            'In Transit to Warehouse'=> 0,
            'In Warehouse'           => 0,
            'In Transit to Project'  => 0,
            'Delivered to Project'   => 0,
        ];
    }
    
    // Only count healthy pallets toward delivery totals
    if ($status !== 'Damaged') {
        $delivery_totals[$lbl][$status] += $q_calc;
        if ($status === 'Delivered to Project') {
            $delivered_raw_total += $qty;
        }
    }

    // Build status_totals for overall tracking (include all pallets for status counts)
    if (!isset($status_totals[$status])) {
        $status_totals[$status] = ['pallets' => 0, 'modules' => 0];
    }
    $status_totals[$status]['pallets'] += 1;
    $status_totals[$status]['modules'] += $qty;

    // Build detailed_breakdown for the overview section
    // Only include non-damaged pallets to match delivery_totals (sub rows)
    if ($status === 'Damaged') {
        continue; // Skip damaged pallets for detailed_breakdown to match sub row calculations
    }

    if ($status === 'In Warehouse' && $wh_name) {
        $key = 'In Warehouse - ' . $wh_name;
    } elseif ($status === 'In Transit to Warehouse' && $wh_name) {
        $key = 'In Transit to Warehouse - ' . $wh_name;
    } elseif ($status === 'In Transit to Project' && $proj_name) {
        $key = 'In Transit to Project - ' . $proj_name;
    } elseif ($status === 'At Manufacturer') {
        $key = 'At Manufacturer';
    } else {
        $key = $status;
    }

    if (!isset($detailed_breakdown[$key])) {
        $detailed_breakdown[$key] = [
            'pallet_count' => 0,
            'total_modules' => 0,
            'wattage_breakdown' => [],
            'warehouse_id' => $wh_id,
            'project_id' => $proj_id
        ];
    }
    $detailed_breakdown[$key]['pallet_count']++;
    $detailed_breakdown[$key]['total_modules'] += $qty;
    if (!isset($detailed_breakdown[$key]['wattage_breakdown'][$wattage])) {
        $detailed_breakdown[$key]['wattage_breakdown'][$wattage] = ['pallets' => 0, 'modules' => 0];
    }
    $detailed_breakdown[$key]['wattage_breakdown'][$wattage]['pallets']++;
    $detailed_breakdown[$key]['wattage_breakdown'][$wattage]['modules'] += $qty;
}
$stmt_status->close();

// Process warranty damaged pallets for delivered damaged total calculation only
// Note: Don't add to status_totals as damaged pallets are already counted with status='Damaged' in main loop
foreach ($warranty_damaged_pallets as $damaged_pallet) {
    $damaged_qty = $damaged_pallet['damaged'];
    $pallet_id = $damaged_pallet['pallet_id'];
    
    // Check if this damaged pallet was delivered to project site
    $stmt_check_delivery = $conn->prepare(
        "SELECT COUNT(*) as is_delivered FROM deliveries d 
         LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id 
         WHERE dp.inventory_pallet_id = ? AND d.status_of_delivery = 'Delivered to Project'"
    );
    $stmt_check_delivery->bind_param('i', $pallet_id);
    $stmt_check_delivery->execute();
    $delivery_result = $stmt_check_delivery->get_result();
    $delivery_row = $delivery_result->fetch_assoc();
    $is_delivered_to_project = ($delivery_row['is_delivered'] > 0);
    $stmt_check_delivery->close();
    
    // If this damaged pallet was delivered to project, add to delivered damaged total
    if ($is_delivered_to_project) {
        $delivered_damaged_total += $damaged_qty;
    }
}

// Calculate combined totals - use status_totals for status data, total_orders for total_order
$total_order_combined             = 0;
$at_manufacturer_combined         = 0;
$on_water_combined                = 0;
$cleared_customs_combined         = 0;
$in_transit_to_warehouse_combined = 0;
$in_warehouse_combined            = 0;
$in_transit_to_project_combined   = 0;
$delivered_combined               = 0;
$exceptions_combined              = 0;


// Calculate exceptions (damaged) from detailed_breakdown since they're not in delivery_totals
// Other combined values will be calculated by summing sub rows below
foreach ($status_totals as $status => $data) {
    if ($status === 'Damaged' && ($data['modules'] ?? 0) > 0) {
        foreach ($detailed_breakdown as $key => $breakdown) {
            if ($key === 'Damaged') {
                foreach ($breakdown['wattage_breakdown'] as $wattage => $watt_data) {
                    $watt_modules = $watt_data['modules'];
                    $mw_for_this_wattage = calculateQuantity($watt_modules, $wattage, $view_mode);
                    $exceptions_combined += $mw_for_this_wattage;
                }
            }
        }
    }
}

$pieChartData = [
    'Delivered to Project'    => 0,
    'At Manufacturer'         => 0,
    'On Water'                => 0,
    'Cleared Customs'         => 0,
    'In Transit to Warehouse' => 0,
    'In Transit to Project'   => 0,
    'In Warehouse'            => 0,
    'Exceptions'              => 0,
];

$sub_rows        = [];
$sub_rows_status = [];

// First, create a comprehensive list of all wattages (both from orders and from actual inventory)
$all_wattages = [];
foreach ($total_orders as $lbl => $info) {
    $all_wattages[$lbl] = $info;
}
// Add any wattages that exist in inventory but not in orders
foreach ($delivery_totals as $lbl => $statuses) {
    if (!isset($all_wattages[$lbl])) {
        // Extract wattage from label (e.g., "555W" -> 555)
        $w = (float)str_replace('W', '', $lbl);
        $all_wattages[$lbl] = [
            'wattage' => $w,
            'total_order' => 0, // No formal order, but inventory exists
            'raw_quantity' => 0,
        ];
    }
}

foreach ($all_wattages as $lbl => $info) {
    $w  = (float)$info['wattage'];
    $to = (float)$info['total_order'];

    $atman = $delivery_totals[$lbl]['At Manufacturer'] ?? 0;
    $onw   = $delivery_totals[$lbl]['On Water'] ?? 0;
    $clr   = $delivery_totals[$lbl]['Cleared Customs'] ?? 0;
    $itw   = $delivery_totals[$lbl]['In Transit to Warehouse'] ?? 0;
    $inw   = $delivery_totals[$lbl]['In Warehouse'] ?? 0;
    $itp   = $delivery_totals[$lbl]['In Transit to Project'] ?? 0;
    $del   = $delivery_totals[$lbl]['Delivered to Project'] ?? 0;

    // Next 5 Weeks
    $sub_rows[$lbl] = [
        'wattage_label'         => $lbl,
        'total_order'           => $to,
        'delivered'             => $del,
        'anticipated_quantities'=> [],
    ];
    // Delivery Status
    $sub_rows_status[$lbl] = [
        'wattage_label'          => $lbl,
        'total_order'            => $to,
        'at_manufacturer'        => $atman,
        'on_water'               => $onw,
        'cleared_customs'        => $clr,
        'in_transit_to_warehouse'=> $itw,
        'in_warehouse'           => $inw,
        'in_transit_to_project'  => $itp,
        'delivered'              => $del,
    ];

    $total_order_combined             += $to;
}

// Calculate combined values by explicitly summing from the sub_rows arrays
// This guarantees the main row exactly matches the sum of sub rows
$delivered_combined = 0;
$at_manufacturer_combined = 0;
$on_water_combined = 0;
$cleared_customs_combined = 0;
$in_transit_to_warehouse_combined = 0;
$in_warehouse_combined = 0;
$in_transit_to_project_combined = 0;

foreach ($sub_rows as $sr) {
    $delivered_combined += $sr['delivered'];
}
foreach ($sub_rows_status as $srs) {
    $at_manufacturer_combined += ($srs['at_manufacturer'] ?? 0);
    $on_water_combined += ($srs['on_water'] ?? 0);
    $cleared_customs_combined += ($srs['cleared_customs'] ?? 0);
    $in_transit_to_warehouse_combined += ($srs['in_transit_to_warehouse'] ?? 0);
    $in_warehouse_combined += ($srs['in_warehouse'] ?? 0);
    $in_transit_to_project_combined += ($srs['in_transit_to_project'] ?? 0);
}

// Calculate pieChartData using the correct combined values
$pieChartData['Delivered to Project']    = $delivered_combined;
$pieChartData['At Manufacturer']         = $at_manufacturer_combined;
$pieChartData['On Water']                = $on_water_combined;
$pieChartData['Cleared Customs']         = $cleared_customs_combined;
$pieChartData['In Transit to Warehouse'] = $in_transit_to_warehouse_combined;
$pieChartData['In Transit to Project']   = $in_transit_to_project_combined;
$pieChartData['In Warehouse']            = $in_warehouse_combined;
$pieChartData['Exceptions']              = $exceptions_combined;

// Filter out statuses with 0 values (except always-visible ones)
$filteredPieChartData = [];
$alwaysVisible = ['At Manufacturer', 'Delivered to Project'];
foreach ($pieChartData as $k => $v) {
    if ($v > 0 || in_array($k, $alwaysVisible)) {
        $filteredPieChartData[$k] = $v;
    }
}

$total_pie = array_sum($filteredPieChartData);
$pieChartPercentages = [];
foreach ($filteredPieChartData as $k => $v) {
    $perc = ($total_pie>0)?(($v/$total_pie)*100):0;
    $pieChartPercentages[$k] = $perc;
}

// Next 5 weeks
$today2 = new DateTime();
$weeks  = [];
$weekEnding = clone $today2;
if ($weekEnding->format('w') != 0) {
    $weekEnding->modify('next Sunday');
}
for ($i=0; $i<5; $i++) {
    $start= clone $weekEnding;
    $start->modify('-6 days');
    $weeks[] = ['start'=>$start,'end'=>clone $weekEnding];
    $weekEnding->modify('+1 week');
}

// Fill the sub_rows for Next 5 Weeks
$anticipated_deliveries_by_lbl = [];
foreach ($total_orders as $lbl => $info) {
    $anticipated_deliveries_by_lbl[$lbl] = [];
}
$stmt = $conn->prepare("
    SELECT wattage, anticipated_delivery_date AS ddate, SUM(quantity) AS q
    FROM deliveries
    WHERE project_id=? AND anticipated_delivery_date IS NOT NULL
    GROUP BY wattage, ddate
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$res2 = $stmt->get_result();
while ($r2 = $res2->fetch_assoc()) {
    $w   = (float)$r2['wattage'];
    $lbl = $w . 'W';
    $d   = $r2['ddate'];
    $raw = (int)$r2['q'];
    $qcalc = calculateQuantity($raw, $w, $view_mode);

    if (!isset($anticipated_deliveries_by_lbl[$lbl][$d])) {
        $anticipated_deliveries_by_lbl[$lbl][$d] = 0;
    }
    $anticipated_deliveries_by_lbl[$lbl][$d] += $qcalc;
}
$stmt->close();

// Populate
foreach ($sub_rows as &$sr) {
    $wl = $sr['wattage_label'];
    $sr['anticipated_quantities'] = array_fill(0, count($weeks), 0);
    if (isset($anticipated_deliveries_by_lbl[$wl])) {
        foreach ($weeks as $ix => $wk) {
            $sumwk = 0;
            foreach ($anticipated_deliveries_by_lbl[$wl] as $dt => $quan) {
                $dtemp = new DateTime($dt);
                if ($dtemp >= $wk['start'] && $dtemp <= $wk['end']) {
                    $sumwk += $quan;
                }
            }
            $sr['anticipated_quantities'][$ix] = $sumwk;
        }
    }
}
unset($sr);

$anticipated_quantities_combined = array_fill(0, count($weeks), 0);
foreach ($weeks as $ix => $wobj) {
    $sumwk = 0;
    foreach ($anticipated_deliveries as $d3 => $amt) {
        $tmpdt = new DateTime($d3);
        if ($tmpdt >= $wobj['start'] && $tmpdt <= $wobj['end']) {
            $sumwk += $amt;
        }
    }
    $anticipated_quantities_combined[$ix] = $sumwk;
}

// -------------- Financial View --------------
$deliveries = [];
$stmt = $conn->prepare("SELECT * FROM deliveries WHERE project_id=?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$dres = $stmt->get_result();
$stmt->close();

// Totals
$total_freight_cost      = 0;
$total_accessorial_costs = 0;
$total_warehousing_cost  = 0;
$total_solterra_fee      = 0;
$total_logistics_cost    = 0;

// For cost-per-unit
$costs_by_key    = [];
$quantity_by_key = [];
$keys_list_fin   = [];

// Calculate warehousing cost for a single pallet by looking at its delivery history
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

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        return 0.0;
    }

    $warehouse_rows = [];
    $warehouse_ids = [];
    while ($row = $result->fetch_assoc()) {
        $warehouse_rows[] = $row;
        $warehouse_ids[] = (int)$row['warehouse_id'];
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
                    case 'entry': $warehouse_costs[$wid]['in_fee'] = (float)$cost['amount']; break;
                    case 'exit': $warehouse_costs[$wid]['out_fee'] = (float)$cost['amount']; break;
                    case 'monthly': $warehouse_costs[$wid]['monthly_storage_fee'] = (float)$cost['amount']; break;
                }
            }
        }
    }

    $total_warehouse_cost = 0.0;
    foreach ($warehouse_rows as $row) {
        $wid = (int)$row['warehouse_id'];
        $costs = $warehouse_costs[$wid] ?? ['in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];

        $in_fee_cost = $costs['in_fee'] * (int)($row['inbound_deliveries'] ?? 0);
        $out_fee_cost = $costs['out_fee'] * (int)($row['outbound_deliveries'] ?? 0);

        $storage_cost = 0.0;
        if (!empty($row['arrival_date'])) {
            $arrival = new DateTime($row['arrival_date']);
            $departure = !empty($row['departure_date']) ? new DateTime($row['departure_date']) : new DateTime();
            $days = max(0, $arrival->diff($departure)->days);
            $daily_fee = ($costs['monthly_storage_fee'] ?? 0) / 30;
            $storage_cost = $days * $daily_fee;
        }

        $total_warehouse_cost += $in_fee_cost + $out_fee_cost + $storage_cost;
    }

    return $total_warehouse_cost;
}

// Calculate warehousing cost using pallet-level data (matches project_cost_details.php approach)
function calculateProjectWarehousingCostFromPallets($conn, $project_id) {
    $total_cost = 0;

    // Get all pallets for this project
    $stmt = $conn->prepare("
        SELECT ip.id, ip.warehouse_cost, ip.current_warehouse_id, ip.arrival_date, ip.status
        FROM inventory_pallets ip
        WHERE ip.assigned_project_id = ?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $pallets_result = $stmt->get_result();
    $stmt->close();

    while ($pallet = $pallets_result->fetch_assoc()) {
        $pallet_cost = 0.0;

        // First use recorded warehouse_cost if available
        if (!empty($pallet['warehouse_cost']) && $pallet['warehouse_cost'] > 0) {
            $pallet_cost = (float)$pallet['warehouse_cost'];

            // For pallets still in warehouse, add pending storage cost
            if ($pallet['status'] === 'In Warehouse' && !empty($pallet['current_warehouse_id']) && !empty($pallet['arrival_date'])) {
                $wh_stmt = $conn->prepare("SELECT amount FROM warehouse_cost_items WHERE warehouse_id = ? AND trigger_event = 'monthly' AND is_active = 1");
                if ($wh_stmt) {
                    $wh_stmt->bind_param("i", $pallet['current_warehouse_id']);
                    $wh_stmt->execute();
                    $wh_stmt->bind_result($monthly_fee);
                    if ($wh_stmt->fetch() && $monthly_fee > 0) {
                        $arrival = new DateTime($pallet['arrival_date']);
                        $now = new DateTime();
                        $days = max(0, $arrival->diff($now)->days);
                        $estimated_storage = $days * ($monthly_fee / 30);
                        if ($estimated_storage > $pallet_cost) {
                            $pallet_cost = $estimated_storage;
                        }
                    }
                    $wh_stmt->close();
                }
            }
        } else {
            // No recorded cost - calculate from delivery history
            $pallet_cost = calculatePalletWarehousingCostFallback((int)$pallet['id'], $conn);
        }

        $total_cost += $pallet_cost;
    }

    return $total_cost;
}

// Calculate warehousing cost from pallets (replaces old delivery-based calculation)
$total_warehousing_cost = calculateProjectWarehousingCostFromPallets($conn, $project_id);

// Build actual total cost from deliveries (warehousing already calculated from pallets above)
while ($dv = $dres->fetch_assoc()) {
    $stat = $dv['status_of_delivery'];
    $watt= (float)$dv['wattage'];
    $fc  = (float)$dv['freight_cost'];  // Use freight_cost instead of customer_cost
    $a   = (float)$dv['accessorial_costs'];
    $q   = (int)$dv['quantity'];

    if (!empty($dv['actual_delivery_date'])) {
        $soltFeeForThisDelivery = $solterra_fee * ($watt * $q);
    } else {
        $soltFeeForThisDelivery = 0;
    }

    $tc = $fc + $a + $soltFeeForThisDelivery;

    $total_freight_cost      += $fc;
    $total_accessorial_costs += $a;
    $total_solterra_fee      += $soltFeeForThisDelivery;

    if ($stat==='Canceled') {
        $thisKey = 'canceled';
    } else {
        if($watt>0) {
            $thisKey = (string)$watt;
        } else {
            continue;
        }
    }
    if(!isset($costs_by_key[$thisKey])) {
        $costs_by_key[$thisKey] = 0;
        $quantity_by_key[$thisKey] = 0;
        $keys_list_fin[] = $thisKey;
    }
    $costs_by_key[$thisKey]    += $tc;
    $quantity_by_key[$thisKey] += $q;
}

// Calculate total logistics cost (includes warehousing from pallets)
$total_logistics_cost = $total_freight_cost + $total_accessorial_costs + $total_warehousing_cost + $total_solterra_fee;

// Calculate module costs from cost_per_watt
$module_cost_data = calculate_project_module_cost($project_id, $conn);
$total_module_cost = $module_cost_data['total_module_cost'];
$module_cost_per_watt = $module_cost_data['avg_cost_per_watt'];
$has_module_cost_data = $module_cost_data['has_cost_data'];

// Calculate logistics as percentage of module cost (if module cost data available)
$logistics_percentage_of_module = ($total_module_cost > 0) ? ($total_logistics_cost / $total_module_cost) * 100 : null;

// Total project cost (will be recalculated after milestone data is loaded)
$total_project_cost = $total_module_cost + $total_logistics_cost;

// Get milestone payment data
$milestone_completion = get_milestone_completion_status($project_id, $conn);
$milestone_timeline = get_milestone_payment_timeline($project_id, $conn);
$has_milestone_data = $milestone_completion['total_contract_value'] > 0;

// Calculate accrued module cost (triggered milestones) for cost summary
$accrued_module_cost = $milestone_completion['total_triggered'];
$module_contract_value = $milestone_completion['total_contract_value'];

// Build milestone breakdown data for the modal
$module_milestone_rows = [];
$milestone_trigger_names = [
    'po_execution' => 'PO Execution',
    'shipping' => 'Shipping',
    'customs_cleared' => 'Customs Clearance',
    'project_delivery' => 'Project Delivery'
];
$milestone_trigger_order = ['po_execution', 'shipping', 'customs_cleared', 'project_delivery'];

// Get triggered amounts by event for this project
$milestone_by_event = [];
$stmt_ms_events = $conn->prepare("
    SELECT mbm.trigger_event, mbm.percentage,
           COALESCE(SUM(
               CASE WHEN mbm.trigger_event = 'shipping' AND d.origin_type = 'warehouse' THEN 0
                    ELSE dmi.payment_amount END
           ), 0) as triggered_amount
    FROM module_batch_milestones mbm
    JOIN modules m ON mbm.module_id = m.id
    LEFT JOIN delivery_milestone_instances dmi ON dmi.milestone_id = mbm.id
    LEFT JOIN deliveries d ON dmi.delivery_id = d.id
    WHERE m.project_id = ? AND mbm.is_active = 1
    GROUP BY mbm.trigger_event, mbm.percentage
");
if ($stmt_ms_events) {
    $stmt_ms_events->bind_param("i", $project_id);
    $stmt_ms_events->execute();
    $ms_events_result = $stmt_ms_events->get_result();
    while ($ms_row = $ms_events_result->fetch_assoc()) {
        $trigger = $ms_row['trigger_event'];
        if (!isset($milestone_by_event[$trigger])) {
            $milestone_by_event[$trigger] = ['triggered' => 0, 'percentage' => $ms_row['percentage']];
        }
        $milestone_by_event[$trigger]['triggered'] += floatval($ms_row['triggered_amount']);
    }
    $stmt_ms_events->close();
}

// Build rows for the modal table
foreach ($milestone_trigger_order as $trigger) {
    if (isset($milestone_by_event[$trigger]) || $module_contract_value > 0) {
        $pct = $milestone_by_event[$trigger]['percentage'] ?? 0;
        $target_amount = $module_contract_value * ($pct / 100);
        $triggered_amount = $milestone_by_event[$trigger]['triggered'] ?? 0;
        $module_milestone_rows[] = [
            'name' => $milestone_trigger_names[$trigger] ?? $trigger,
            'trigger' => $trigger,
            'percentage' => $pct,
            'target_amount' => round($target_amount, 2),
            'accrued_amount' => round($triggered_amount, 2),
            'is_complete' => ($target_amount > 0 && $triggered_amount >= $target_amount * 0.99)
        ];
    }
}

// Recalculate total project cost using accrued milestone amount
if ($has_milestone_data) {
    $total_project_cost = $accrued_module_cost + $total_logistics_cost;
}

// Build cost_data
$cost_data = [];
$combined_total_costs = 0;
$combined_qty = 0;

// First pass: get total quantity for proportional warehousing distribution
$total_qty_for_warehousing = 0;
foreach ($keys_list_fin as $k) {
    $total_qty_for_warehousing += $quantity_by_key[$k];
}

foreach ($keys_list_fin as $k) {
    $tc = $costs_by_key[$k];
    $qt = $quantity_by_key[$k];

    // Add proportional share of warehousing cost based on quantity
    $warehousing_share = ($total_qty_for_warehousing > 0) ? ($total_warehousing_cost * ($qt / $total_qty_for_warehousing)) : 0;
    $tc += $warehousing_share;

    $lbl = ($k==='canceled') ? 'Canceled' : ($k.'W');

    $pallets = $qt/30;
    $ppp = ($pallets>0) ? ($tc/$pallets) : 0;       // price per pallet
    $ppm = ($qt>0) ? ($tc/$qt) : 0;                // price per module
    $ppw = 0;
    if($k!=='canceled') {
        $numW = floatval($k);
        if($qt*$numW>0) {
            $ppw = $tc/($qt*$numW);
        }
    }
    $cost_data[$k] = [
        'module_type'      => $lbl,
        'total_costs'      => $tc,
        'price_per_pallet' => $ppp,
        'price_per_module' => $ppm,
        'price_per_watt'   => $ppw,
    ];

    $combined_total_costs += $tc;
    $combined_qty         += $qt;
}

$non_zero_wattage_list_fin=[];
foreach ($keys_list_fin as $kk) {
    if($kk!=='canceled') {
        $valW = floatval($kk);
        if($valW>0) {
            $non_zero_wattage_list_fin[] = $valW;
        }
    }
}
$minf = (count($non_zero_wattage_list_fin)>0)? min($non_zero_wattage_list_fin) : 0;
$maxf = (count($non_zero_wattage_list_fin)>0)? max($non_zero_wattage_list_fin) : 0;
if(count($non_zero_wattage_list_fin)==0) {
    $combined_label="N/A";
} else {
    $combined_label= ($minf==$maxf)?($minf.'W'):($minf.'W-'.$maxf.'W');
}

$combined_pallets = $combined_qty/30;
$combined_ppp = ($combined_pallets>0)?($combined_total_costs/$combined_pallets):0;
$combined_ppm = ($combined_qty>0)?($combined_total_costs/$combined_qty):0;
$sum_watts=0;
foreach($non_zero_wattage_list_fin as $ww) {
    $sum_watts += ($quantity_by_key[strval($ww)] * $ww);
}
$combined_ppw = ($sum_watts>0)?($combined_total_costs/$sum_watts):0;

// Cost Breakdown Pie
$pieChartDataFinancial = [
    'Freight Cost'  => $total_freight_cost,
    'Warehousing'   => $total_warehousing_cost,
    'Accessorial'   => $total_accessorial_costs,
];

// Next 5 weeks for Invoices/Cashflow (Sunday-Saturday weeks)
$weeks_financial = [];
$weekStartFin = new DateTime();
if ($weekStartFin->format('w') != 0) {
    $weekStartFin->modify('last Sunday');
}
for ($i = 0; $i < 5; $i++) {
    $startFin = clone $weekStartFin;
    $endFin = clone $weekStartFin;
    $endFin->modify('+6 days');
    $weeks_financial[] = ['start' => $startFin, 'end' => $endFin];
    $weekStartFin->modify('+1 week');
}

// Forecast from primary projection (or zeros if no projection)
$anticipated_deliveries_financial = array_fill(0, count($weeks_financial), 0);
$all_projection_weeks = []; // All weeks with costs (for modal)

if ($primary_projection) {
    $today_str = (new DateTime())->format('Y-m-d');
    $projection_weekly_costs = []; // week-starting-Sunday => ['freight' => X, 'warehousing' => X, 'milestones' => X]
    $projection_monthly_costs = []; // YYYY-MM => ['freight' => X, 'warehousing' => X, 'milestones' => X]

    // Calculate total milestone amounts per trigger event from module allocations
    $projection_milestone_amounts = []; // trigger_event => total_amount
    $projection_total_contract_value = 0;
    if (!empty($primary_projection['module_allocations'])) {
        foreach ($primary_projection['module_allocations'] as $alloc) {
            $contract_val = $alloc['contract_value'] ?? 0;
            $projection_total_contract_value += $contract_val;
            if (!empty($alloc['milestones'])) {
                foreach ($alloc['milestones'] as $ms) {
                    $trigger = $ms['trigger_event'];
                    $ms_amount = $contract_val * ($ms['percentage'] / 100);
                    if (!isset($projection_milestone_amounts[$trigger])) {
                        $projection_milestone_amounts[$trigger] = 0;
                    }
                    $projection_milestone_amounts[$trigger] += $ms_amount;
                }
            }

            // PO execution milestone goes to po_execution_date week (or today if missing)
            $alloc_po_amount = 0;
            if (!empty($alloc['milestones'])) {
                foreach ($alloc['milestones'] as $ms) {
                    if (($ms['trigger_event'] ?? '') === 'po_execution') {
                        $alloc_po_amount += $contract_val * ($ms['percentage'] / 100);
                    }
                }
            }

            if ($alloc_po_amount > 0) {
                $po_date = !empty($alloc['po_execution_date']) ? $alloc['po_execution_date'] : $today_str;
                $po_week = getWeekStartingSunday($po_date);
                if (!isset($projection_weekly_costs[$po_week])) {
                    $projection_weekly_costs[$po_week] = ['freight' => 0, 'warehousing' => 0, 'milestones' => 0];
                }
                $projection_weekly_costs[$po_week]['milestones'] += $alloc_po_amount;

                $po_month = substr($po_date, 0, 7);
                if (!isset($projection_monthly_costs[$po_month])) {
                    $projection_monthly_costs[$po_month] = ['freight' => 0, 'warehousing' => 0, 'milestones' => 0];
                }
                $projection_monthly_costs[$po_month]['milestones'] += $alloc_po_amount;
            }
        }
    }

    // Iterate legs: assign freight cost to start_date week, milestones to triggered week
    $assigned_milestone_events = []; // Prevent double-counting if multiple legs trigger same event
    if (!empty($primary_projection['legs'])) {
        foreach ($primary_projection['legs'] as $leg) {
            if (!empty($leg['start_date'])) {
                $leg_week = getWeekStartingSunday($leg['start_date']);
                if (!isset($projection_weekly_costs[$leg_week])) {
                    $projection_weekly_costs[$leg_week] = ['freight' => 0, 'warehousing' => 0, 'milestones' => 0];
                }
                $projection_weekly_costs[$leg_week]['freight'] += floatval($leg['total_freight_cost'] ?? 0);

                $leg_month = substr($leg['start_date'], 0, 7);
                if (!isset($projection_monthly_costs[$leg_month])) {
                    $projection_monthly_costs[$leg_month] = ['freight' => 0, 'warehousing' => 0, 'milestones' => 0];
                }
                $projection_monthly_costs[$leg_month]['freight'] += floatval($leg['total_freight_cost'] ?? 0);

                // If this leg triggers a milestone, add milestone amount to this week
                if (!empty($leg['triggers_milestone']) && $leg['triggers_milestone'] !== 'po_execution') {
                    $trigger = $leg['triggers_milestone'];
                    if (isset($projection_milestone_amounts[$trigger]) && !in_array($trigger, $assigned_milestone_events)) {
                        $projection_weekly_costs[$leg_week]['milestones'] += $projection_milestone_amounts[$trigger];
                        $projection_monthly_costs[$leg_month]['milestones'] += $projection_milestone_amounts[$trigger];
                        $assigned_milestone_events[] = $trigger;
                    }
                }
            }
        }
    }

    // Build stop_id -> outgoing leg dates for outbound fee placement
    $outgoing_leg_dates = [];
    if (!empty($primary_projection['legs'])) {
        foreach ($primary_projection['legs'] as $leg) {
            if (empty($leg['start_date']) || strtotime($leg['start_date']) <= 0) {
                continue;
            }
            if (!empty($leg['from_stop_id'])) {
                $outgoing_leg_dates[$leg['from_stop_id']] = $leg['start_date'];
            }
        }
    }

    // Distribute stop fees: monthly storage to 1st of each month, in/out at actual dates
    if (!empty($primary_projection['stops'])) {
        foreach ($primary_projection['stops'] as $stop_idx => $stop) {
            if (empty($stop['fees']) || empty($stop['estimated_arrival_date'])) {
                continue;
            }

            $arrival = $stop['estimated_arrival_date'];
            $arrival_ts = strtotime($arrival);
            if ($arrival_ts === false || $arrival_ts <= 0) {
                continue;
            }

            $arrival_dt = new DateTime($arrival);
            $departure = $stop['estimated_departure_date'] ?? null;
            $departure_ts = ($departure && strtotime($departure) !== false) ? strtotime($departure) : null;
            $valid_departure = ($departure_ts && $departure_ts > $arrival_ts);

            foreach ($stop['fees'] as $fee) {
                $fee_cost = floatval($fee['estimated_cost'] ?? 0);
                if ($fee_cost <= 0) continue;

                $fee_type = strtolower($fee['fee_type'] ?? 'other');
                $fee_trigger = strtolower((string)($fee['trigger'] ?? ''));
                $is_storage_fee = ($fee_type === 'storage' || $fee_trigger === 'monthly');

                if ($is_storage_fee) {
                    // Monthly storage fees: place on 1st of each month during storage period
                    if ($valid_departure) {
                        $dep_dt = new DateTime($departure);
                    } else {
                        $next_stop = $primary_projection['stops'][$stop_idx + 1] ?? null;
                        $next_arrival = $next_stop['estimated_arrival_date'] ?? null;
                        if ($next_arrival && strtotime($next_arrival) > $arrival_ts) {
                            $dep_dt = new DateTime($next_arrival);
                        } else {
                            $dep_dt = clone $arrival_dt;
                            $dep_dt->modify('+90 days');
                        }
                    }

                    $months_in_storage = max(1, (int)ceil(($dep_dt->getTimestamp() - $arrival_dt->getTimestamp()) / (30 * 86400)));
                    $monthly_fee = $fee_cost / $months_in_storage;

                    $current_month = new DateTime($arrival_dt->format('Y-m-01'));
                    for ($m = 0; $m < $months_in_storage; $m++) {
                        $month_first = clone $current_month;
                        $month_first->modify("+{$m} months");
                        $month_first_str = $month_first->format('Y-m-d');
                        $month_key = $month_first->format('Y-m');

                        if (!isset($projection_monthly_costs[$month_key])) {
                            $projection_monthly_costs[$month_key] = ['freight' => 0, 'warehousing' => 0, 'milestones' => 0];
                        }
                        $projection_monthly_costs[$month_key]['warehousing'] += $monthly_fee;

                        $w_key = getWeekStartingSunday($month_first_str);
                        if (!isset($projection_weekly_costs[$w_key])) {
                            $projection_weekly_costs[$w_key] = ['freight' => 0, 'warehousing' => 0, 'milestones' => 0];
                        }
                        $projection_weekly_costs[$w_key]['warehousing'] += $monthly_fee;
                    }
                } else {
                    // In/out/handling/other fees: place at arrival week (in) or departure week (out/outbound)
                    $fee_date = $arrival;
                    if (in_array($fee_type, ['outbound', 'out'], true)) {
                        $departure_candidate = null;
                        if ($valid_departure) {
                            $departure_candidate = $departure;
                        }

                        if (!$departure_candidate) {
                            $stop_id = $stop['id'] ?? null;
                            if ($stop_id && !empty($outgoing_leg_dates[$stop_id])) {
                                $departure_candidate = $outgoing_leg_dates[$stop_id];
                            }
                        }

                        if (!$departure_candidate) {
                            $next_stop = $primary_projection['stops'][$stop_idx + 1] ?? null;
                            $next_arrival = $next_stop['estimated_arrival_date'] ?? null;
                            if ($next_arrival && strtotime($next_arrival) > 0) {
                                $departure_candidate = $next_arrival;
                            }
                        }

                        if ($departure_candidate) {
                            $departure_candidate_ts = strtotime($departure_candidate);
                            if ($departure_candidate_ts && $departure_candidate_ts > $arrival_ts) {
                                $fee_date = $departure_candidate;
                            }
                        }
                    }

                    $w_key = getWeekStartingSunday($fee_date);
                    if (!isset($projection_weekly_costs[$w_key])) {
                        $projection_weekly_costs[$w_key] = ['freight' => 0, 'warehousing' => 0, 'milestones' => 0];
                    }
                    $projection_weekly_costs[$w_key]['warehousing'] += $fee_cost;

                    $fee_month = substr($fee_date, 0, 7);
                    if (!isset($projection_monthly_costs[$fee_month])) {
                        $projection_monthly_costs[$fee_month] = ['freight' => 0, 'warehousing' => 0, 'milestones' => 0];
                    }
                    $projection_monthly_costs[$fee_month]['warehousing'] += $fee_cost;
                }
            }
        }
    }

    // Sort weeks and build all_projection_weeks for modal (using week-starting-Sunday to match JS)
    ksort($projection_weekly_costs);
    $cumulative_projection = 0;
    foreach ($projection_weekly_costs as $week_start => $costs) {
        $week_total = $costs['freight'] + $costs['warehousing'] + $costs['milestones'];
        $cumulative_projection += $week_total;
        $all_projection_weeks[] = [
            'week_start' => $week_start,
            'freight' => round($costs['freight'], 2),
            'warehousing' => round($costs['warehousing'], 2),
            'milestones' => round($costs['milestones'], 2),
            'total' => round($week_total, 2),
            'cumulative' => round($cumulative_projection, 2)
        ];
    }

    // Also sort projection_weekly_costs for the 5-week financial chart
    ksort($projection_weekly_costs);

    // Fill the 5-week financial forecast from projection data
    foreach ($weeks_financial as $ix => $wf) {
        $week_key = $wf['start']->format('Y-m-d');
        if (isset($projection_weekly_costs[$week_key])) {
            $costs = $projection_weekly_costs[$week_key];
            $anticipated_deliveries_financial[$ix] = $costs['freight'] + $costs['warehousing'] + $costs['milestones'];
        }
    }
}

if (!isset($projection_weekly_costs)) {
    $projection_weekly_costs = [];
}
if (!isset($projection_monthly_costs)) {
    $projection_monthly_costs = [];
}

// open invoices
$stmt = $conn->prepare("
    SELECT SUM(amount) as open_invoices_total
    FROM project_invoices
    WHERE project_id=? AND status='Open'
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($open_invoices_total);
$stmt->fetch();
$stmt->close();
$open_invoices_total=$open_invoices_total?:0;

// For Forecasted vs Actual Cost line chart
// Actual costs from real deliveries - track by category for breakdown
$deliveries_by_month_actual_cost = [];
$actual_cost_breakdown_by_month = []; // Track freight, milestones, warehousing per month
$stmt = $conn->prepare("
    SELECT *
    FROM deliveries
    WHERE project_id=?
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$allDel = $stmt->get_result();
$stmt->close();

while($dv = $allDel->fetch_assoc()) {
    $adate = $dv['actual_delivery_date'];
    $ddate = $dv['anticipated_delivery_date'];
    $stat  = $dv['status_of_delivery'];
    $watt  = (float)$dv['wattage'];
    $qty   = (int)$dv['quantity'];

    // Use actual_delivery_date, fall back to anticipated_delivery_date, then today
    $has_actual_date = !empty($adate);
    if (empty($adate)) {
        $adate = !empty($ddate) ? $ddate : date('Y-m-d');
    }

    $fc   = (float)$dv['freight_cost'];
    $ac   = (float)$dv['accessorial_costs'];
    $fee  = $has_actual_date ? $solterra_fee * ($watt * $qty) : 0;
    $actual_tc = $fc + $ac + $fee;

    if ($actual_tc > 0 && !empty($adate)) {
        $monthKey = substr($adate, 0, 7);
        if (!isset($deliveries_by_month_actual_cost[$monthKey])) {
            $deliveries_by_month_actual_cost[$monthKey] = 0;
        }
        $deliveries_by_month_actual_cost[$monthKey] += $actual_tc;

        // Track breakdown by category
        if (!isset($actual_cost_breakdown_by_month[$monthKey])) {
            $actual_cost_breakdown_by_month[$monthKey] = ['freight' => 0, 'milestones' => 0, 'warehousing' => 0];
        }
        $actual_cost_breakdown_by_month[$monthKey]['freight'] += ($fc + $ac + $fee);
    }
}

// Add actual milestone payments to actual costs (module costs accrued by date)
// For po_execution milestones, prefer the module's po_execution_date over triggered_at
$module_po_dates_cache = [];
if (!empty($milestone_timeline)) {
    foreach ($milestone_timeline as $mt_entry) {
        if (floatval($mt_entry['amount']) <= 0) continue;

        $mt_date = null;
        // For PO execution milestones, use the module's po_execution_date if available
        if (($mt_entry['trigger_event'] ?? '') === 'po_execution' && !empty($mt_entry['module_batch_id'])) {
            $batch_id_for_date = (int)$mt_entry['module_batch_id'];
            if (!isset($module_po_dates_cache[$batch_id_for_date])) {
                $po_stmt = $conn->prepare("SELECT po_execution_date FROM modules WHERE id = ?");
                $po_stmt->bind_param("i", $batch_id_for_date);
                $po_stmt->execute();
                $po_row = $po_stmt->get_result()->fetch_assoc();
                $po_stmt->close();
                $module_po_dates_cache[$batch_id_for_date] = $po_row['po_execution_date'] ?? null;
            }
            if (!empty($module_po_dates_cache[$batch_id_for_date])) {
                $mt_date = $module_po_dates_cache[$batch_id_for_date];
            }
        }

        // Fallback to triggered_at date
        if (!$mt_date && !empty($mt_entry['date'])) {
            $mt_date = is_string($mt_entry['date']) ? substr($mt_entry['date'], 0, 10) : $mt_entry['date'];
        }

        if ($mt_date && strtotime($mt_date) > 0) {
            $mt_month = substr($mt_date, 0, 7);
            if (!isset($deliveries_by_month_actual_cost[$mt_month])) {
                $deliveries_by_month_actual_cost[$mt_month] = 0;
            }
            $deliveries_by_month_actual_cost[$mt_month] += floatval($mt_entry['amount']);

            // Track milestone breakdown
            if (!isset($actual_cost_breakdown_by_month[$mt_month])) {
                $actual_cost_breakdown_by_month[$mt_month] = ['freight' => 0, 'milestones' => 0, 'warehousing' => 0];
            }
            $actual_cost_breakdown_by_month[$mt_month]['milestones'] += floatval($mt_entry['amount']);
        }
    }
}

// Add actual warehousing cost (from pallets in warehouse) at today's month
if (($total_warehousing_cost ?? 0) > 0) {
    $wh_month = date('Y-m');
    if (!isset($deliveries_by_month_actual_cost[$wh_month])) {
        $deliveries_by_month_actual_cost[$wh_month] = 0;
    }
    $deliveries_by_month_actual_cost[$wh_month] += $total_warehousing_cost;

    // Track warehousing breakdown
    if (!isset($actual_cost_breakdown_by_month[$wh_month])) {
        $actual_cost_breakdown_by_month[$wh_month] = ['freight' => 0, 'milestones' => 0, 'warehousing' => 0];
    }
    $actual_cost_breakdown_by_month[$wh_month]['warehousing'] += $total_warehousing_cost;
}

// Anticipated costs from projection (or fallback to old method)
$deliveries_by_month_anticipated = [];
if ($primary_projection && !empty($projection_monthly_costs)) {
    foreach ($projection_monthly_costs as $month_key => $costs) {
        $deliveries_by_month_anticipated[$month_key] = $costs['freight'] + $costs['warehousing'] + $costs['milestones'];
    }
} else {
    // Fallback: use forecasted costs from deliveries table
    $stmt = $conn->prepare("SELECT * FROM deliveries WHERE project_id=?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $allDelForecast = $stmt->get_result();
    $stmt->close();

    while($dv = $allDelForecast->fetch_assoc()) {
        $ddate = $dv['anticipated_delivery_date'];
        $stat  = $dv['status_of_delivery'];
        $watt  = (float)$dv['wattage'];
        $qty   = (int)$dv['quantity'];

        $cost_date = $dv['actual_delivery_date'];
        if (empty($cost_date) && $stat === 'Canceled') {
            $cost_date = (!empty($ddate)) ? $ddate : date('Y-m-d');
        } else if (empty($cost_date)) {
            $cost_date = $ddate;
        }
        if (!empty($cost_date)) {
            $monthKey = substr($cost_date, 0, 7);
            $pmFreight     = ($total_raw_modules > 0) ? ($forecasted_freight / $total_raw_modules) : 0;
            $pmAccessorial = ($total_raw_modules > 0) ? ($forecasted_accessorial / $total_raw_modules) : 0;
            $forecast_tc   = ($pmFreight + $pmAccessorial) * $qty + ($solterra_fee * ($watt * $qty));
            if (!isset($deliveries_by_month_anticipated[$monthKey])) {
                $deliveries_by_month_anticipated[$monthKey] = 0;
            }
            $deliveries_by_month_anticipated[$monthKey] += $forecast_tc;
        }
    }
}

$all_months_cost = array_unique(array_merge(
    array_keys($deliveries_by_month_actual_cost),
    array_keys($deliveries_by_month_anticipated)
));
sort($all_months_cost);

$budgetLine_anticipated = [];
$budgetLine_actual = [];
$budgetLine_forecast_breakdown = []; // Per-point breakdown for forecasted
$budgetLine_actual_breakdown = [];   // Per-point breakdown for actual
$acc_ant=0;
$acc_act=0;
$acc_forecast_freight = 0;
$acc_forecast_warehousing = 0;
$acc_forecast_milestones = 0;
$acc_actual_freight = 0;
$acc_actual_warehousing = 0;
$acc_actual_milestones = 0;
$current_month = date('Y-m');

foreach ($all_months_cost as $month_key) {
    $acc_ant += ($deliveries_by_month_anticipated[$month_key] ?? 0);

    // Get forecasted breakdown from projection_monthly_costs if available
    if (isset($projection_monthly_costs[$month_key])) {
        $acc_forecast_freight += $projection_monthly_costs[$month_key]['freight'] ?? 0;
        $acc_forecast_warehousing += $projection_monthly_costs[$month_key]['warehousing'] ?? 0;
        $acc_forecast_milestones += $projection_monthly_costs[$month_key]['milestones'] ?? 0;
    }
    $budgetLine_forecast_breakdown[] = [
        'freight' => $acc_forecast_freight,
        'warehousing' => $acc_forecast_warehousing,
        'milestones' => $acc_forecast_milestones
    ];

    if ($month_key <= $current_month) {
        $acc_act += ($deliveries_by_month_actual_cost[$month_key] ?? 0);
        $budgetLine_actual[] = $acc_act;

        if (isset($actual_cost_breakdown_by_month[$month_key])) {
            $acc_actual_freight += $actual_cost_breakdown_by_month[$month_key]['freight'] ?? 0;
            $acc_actual_warehousing += $actual_cost_breakdown_by_month[$month_key]['warehousing'] ?? 0;
            $acc_actual_milestones += $actual_cost_breakdown_by_month[$month_key]['milestones'] ?? 0;
        }
        $budgetLine_actual_breakdown[] = [
            'freight' => $acc_actual_freight,
            'warehousing' => $acc_actual_warehousing,
            'milestones' => $acc_actual_milestones
        ];
    } else {
        $budgetLine_actual[] = null;
        $budgetLine_actual_breakdown[] = null;
    }

    $budgetLine_anticipated[] = $acc_ant;
}

$budgetLineChartData = [
    'anticipated_cost'=> $budgetLine_anticipated,
    'actual_cost'     => $budgetLine_actual,
    'forecast_breakdown' => $budgetLine_forecast_breakdown,
    'actual_breakdown' => $budgetLine_actual_breakdown,
];
$budgetLineChartDataJSON = json_encode($budgetLineChartData);
$budgetDateLabels = array_map(function($month_key) {
    return $month_key . '-01';
}, $all_months_cost);
$dateLabelsForBudget = json_encode($budgetDateLabels);
$allProjectionWeeksJSON = json_encode($all_projection_weeks);

// For Admin Warehousing functionality - check if project has pallets in multiple warehouses
$warehouses_with_inventory = [];
$stmt_warehouses = $conn->prepare("
    SELECT DISTINCT 
        w.id, 
        w.name,
        w.address, 
        w.image_url,
        COUNT(ip.id) as pallets_in_warehouse,
        SUM(ip.quantity) as modules_in_warehouse,
        COUNT(DISTINCT d_transit.id) as pallets_in_transit_to_wh,
        SUM(CASE WHEN d_transit.status_of_delivery LIKE 'In Transit%' THEN d_transit.quantity ELSE 0 END) as modules_in_transit_to_wh
    FROM warehouses w
    LEFT JOIN inventory_pallets ip ON w.id = ip.current_warehouse_id 
        AND ip.status = 'In Warehouse' 
        AND (ip.assigned_project_id = ? OR ip.current_project_id = ?)
    LEFT JOIN deliveries d_transit ON w.id = d_transit.warehouse_id
        AND d_transit.project_id = ?
        AND d_transit.status_of_delivery LIKE 'In Transit%'
        AND d_transit.warehouse_arrival_date IS NULL
    WHERE
        EXISTS (
            SELECT 1 FROM inventory_pallets ip_check
            WHERE ip_check.current_warehouse_id = w.id
                AND ip_check.status = 'In Warehouse'
                AND (ip_check.assigned_project_id = ? OR ip_check.current_project_id = ?)
        )
        OR EXISTS (
            SELECT 1 FROM deliveries d_check
            WHERE d_check.warehouse_id = w.id
                AND d_check.project_id = ?
                AND d_check.status_of_delivery LIKE 'In Transit%'
                AND d_check.warehouse_arrival_date IS NULL
        )
        OR EXISTS (
            SELECT 1 FROM deliveries d_hist
            WHERE d_hist.warehouse_id = w.id
                AND d_hist.project_id = ?
        )
    GROUP BY w.id, w.name, w.address, w.image_url
    ORDER BY w.name ASC
");
$stmt_warehouses->bind_param("iiiiiii", $project_id, $project_id, $project_id, $project_id, $project_id, $project_id, $project_id);
$stmt_warehouses->execute();
$result_warehouses = $stmt_warehouses->get_result();
while ($wh = $result_warehouses->fetch_assoc()) {
    $warehouses_with_inventory[] = $wh;
}
$stmt_warehouses->close();

// --- Shipping Status Breakdown ---
// Data already calculated above in the delivery status section

// Move all remaining database queries here before HTML output
// Better calculation for palletized modules - count actual pallets in inventory_pallets
$stmt_palletized = $conn->prepare("
    SELECT COUNT(*) as palletized_count
    FROM inventory_pallets ip
    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
    WHERE (m.project_id = ? OR ip.assigned_project_id = ? OR ip.current_project_id = ?)
");
$stmt_palletized->bind_param("iii", $project_id, $project_id, $project_id);
$stmt_palletized->execute();
$stmt_palletized->bind_result($actual_palletized_count);
$stmt_palletized->fetch();
$stmt_palletized->close();

// Calculate total EXPECTED pallets based on total modules / modules per pallet
// This shows what SHOULD be palletized, not what IS palletized
$modules_per_pallet = 30; // Default

// Try to get project-specific modules_per_pallet if column exists
if (isset($project['modules_per_pallet']) && $project['modules_per_pallet'] > 0) {
    $modules_per_pallet = (int)$project['modules_per_pallet'];
}

$expected_pallets = 0;
if ($total_raw_modules > 0) {
    $expected_pallets = ceil($total_raw_modules / $modules_per_pallet);
}

// Also get the actual count of existing pallets (for comparison)
$stmt_existing_pallets = $conn->prepare("
    SELECT COUNT(*) as existing
    FROM inventory_pallets ip
    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
    WHERE (m.project_id = ? OR ip.assigned_project_id = ? OR ip.current_project_id = ?)
");
$stmt_existing_pallets->bind_param("iii", $project_id, $project_id, $project_id);
$stmt_existing_pallets->execute();
$stmt_existing_pallets->bind_result($existing_pallet_count);
$stmt_existing_pallets->fetch();
$stmt_existing_pallets->close();

// Get delivered modules breakdown for JavaScript (using inventory_pallets for accuracy)
$delivered_by_wattage = [];
$delivered_damaged_by_wattage = [];
$stmt_delivered = $conn->prepare("
    SELECT ip.wattage, 
           COUNT(*) as pallet_count, 
           SUM(ip.quantity) as total_modules,
           SUM(CASE WHEN ip.status = 'Damaged' THEN 1 ELSE 0 END) as damaged_pallets,
           SUM(CASE WHEN ip.status = 'Damaged' THEN ip.quantity ELSE 0 END) as damaged_modules
    FROM inventory_pallets ip
    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
    WHERE (m.project_id = ? OR ip.assigned_project_id = ? OR ip.current_project_id = ?)
      AND (ip.status = 'Delivered to Project' OR ip.status = 'Damaged')
    GROUP BY ip.wattage
");
$stmt_delivered->bind_param("iii", $project_id, $project_id, $project_id);
$stmt_delivered->execute();
$delivered_result = $stmt_delivered->get_result();
while ($row = $delivered_result->fetch_assoc()) {
    $wattage = (float)$row['wattage'];
    $total_pallets = (int)$row['pallet_count'];
    $total_modules = (int)$row['total_modules'];
    $damaged_pallets = (int)$row['damaged_pallets'];
    $damaged_modules = (int)$row['damaged_modules'];
    
    // Only include if there are delivered (non-damaged) modules
    if ($total_pallets > $damaged_pallets) {
        $delivered_by_wattage[] = [
            'wattage' => $wattage,
            'pallets' => $total_pallets - $damaged_pallets,
            'modules' => $total_modules - $damaged_modules,
            'damaged_pallets' => $damaged_pallets,
            'damaged_modules' => $damaged_modules
        ];
    }
}
$stmt_delivered->close();

// Determine current step logic
$step1_completed = true; // Project always created
$step2_completed = $total_raw_modules > 0;
$step3_completed = $actual_palletized_count >= $expected_pallets && $total_raw_modules > 0;

// Step 4 (Shipping) is completed when:
// 1. Some shipping has actually occurred (deliveries exist for this project)
// 2. AND no pallets are currently in shipping statuses
$has_shipping_started = false;
$stmt_shipping_check = $conn->prepare("SELECT COUNT(*) as delivery_count FROM deliveries WHERE project_id = ?");
$stmt_shipping_check->bind_param("i", $project_id);
$stmt_shipping_check->execute();
$stmt_shipping_check->bind_result($delivery_count);
$stmt_shipping_check->fetch();
$stmt_shipping_check->close();
$has_shipping_started = $delivery_count > 0;

$step4_completed = $has_shipping_started && 
                  ($status_totals['At Manufacturer']['pallets'] ?? 0) == 0 && 
                  ($status_totals['On Water']['pallets'] ?? 0) == 0 && 
                  ($status_totals['Cleared Customs']['pallets'] ?? 0) == 0 && 
                  ($status_totals['In Transit to Warehouse']['pallets'] ?? 0) == 0 && 
                  ($status_totals['In Warehouse']['pallets'] ?? 0) == 0 && 
                  ($status_totals['In Transit to Project']['pallets'] ?? 0) == 0;

$step5_completed = $delivered_raw_total >= $total_raw_modules && $total_raw_modules > 0;

// Determine current step (the next step that needs to be completed)
$current_step = 1;
if ($step1_completed && !$step2_completed) $current_step = 2;
elseif ($step2_completed && !$step3_completed) $current_step = 3;
elseif ($step3_completed && !$step4_completed) $current_step = 4;
elseif ($step4_completed && !$step5_completed) $current_step = 5;
elseif ($step5_completed) $current_step = 6; // All completed

// Calculate progress percentage for timeline - only go to current step, not beyond
$progress_percentage = 0;
if ($current_step >= 2 && $step1_completed) $progress_percentage = 20;
if ($current_step >= 3 && $step2_completed) $progress_percentage = 40;
if ($current_step >= 4 && $step3_completed) $progress_percentage = 60;
if ($current_step >= 5 && $step4_completed) $progress_percentage = 80;
if ($step5_completed) $progress_percentage = 100;

// Calculate ordered MW (total MW of modules added to the project)
$ordered_mw = 0;
foreach ($total_orders as $lbl => $info) {
    $ordered_mw += ($info['raw_quantity'] * $info['wattage']) / 1000000;
}

// Calculate delivered MW (based on delivered modules with their wattages)
// We need to get delivered quantities by wattage to calculate accurate MW
$delivered_mw = 0;
$delivered_by_wattage_query = $conn->prepare("
    SELECT ip.wattage, SUM(ip.quantity) as qty
    FROM inventory_pallets ip
    WHERE ip.assigned_project_id = ? AND ip.status = 'Delivered to Project'
    GROUP BY ip.wattage
");
$delivered_by_wattage_query->bind_param("i", $project_id);
$delivered_by_wattage_query->execute();
$delivered_wattage_result = $delivered_by_wattage_query->get_result();
while ($dw_row = $delivered_wattage_result->fetch_assoc()) {
    $delivered_mw += ($dw_row['qty'] * $dw_row['wattage']) / 1000000;
}
$delivered_by_wattage_query->close();

// Delivered-based completion percentage for the circular indicator (of ordered modules)
$delivered_percentage = 0;
if ($total_raw_modules > 0) {
    $delivered_percentage = max(0, min(100, (int)round(($delivered_raw_total / $total_raw_modules) * 100)));
}

// Project target completion percentage (based on project_size_mw)
$project_completion_percentage = 0;
if ($project_size_mw > 0) {
    $project_completion_percentage = max(0, min(100, (int)round(($delivered_mw / $project_size_mw) * 100)));
}

// Ordered vs target percentage (how much of the project target has been ordered)
$ordered_vs_target_percentage = 0;
if ($project_size_mw > 0) {
    $ordered_vs_target_percentage = max(0, min(100, (int)round(($ordered_mw / $project_size_mw) * 100)));
}

// Calculate project health status (for at-risk indicator)
$project_health = 'on_track';
$project_health_text = 'On Track';
$project_health_reason = '';
$days_remaining = null;
$est_completion_date_formatted = null;
$is_manual_health = false;
$manual_health_set_by_name = null;
$manual_health_set_at_formatted = null;

// Check for manual health override first
if (!empty($project['manual_health_status'])) {
    $is_manual_health = true;
    $project_health = $project['manual_health_status'];
    $project_health_reason = $project['manual_health_reason'] ?? '';

    // Format the manual health text
    switch ($project_health) {
        case 'at_risk':
            $project_health_text = 'At Risk';
            break;
        case 'behind':
            $project_health_text = 'Behind Schedule';
            break;
        case 'on_track':
        default:
            $project_health_text = 'On Track';
            break;
    }

    // Get the name of who set the manual health
    if (!empty($project['manual_health_set_by'])) {
        $conn_temp = getDBConnection();
        $stmt_user = $conn_temp->prepare("SELECT first_name, last_name, username FROM users WHERE id = ?");
        $stmt_user->bind_param("i", $project['manual_health_set_by']);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $manual_health_set_by_name = trim($user_row['first_name'] . ' ' . $user_row['last_name']);
            if (empty($manual_health_set_by_name)) {
                $manual_health_set_by_name = $user_row['username'];
            }
        }
        $stmt_user->close();
        $conn_temp->close();
    }

    // Format when it was set
    if (!empty($project['manual_health_set_at'])) {
        $set_at = new DateTime($project['manual_health_set_at']);
        $manual_health_set_at_formatted = $set_at->format('M j, Y g:i A');
    }
} elseif ($project_completion_percentage >= 100) {
    // Auto-calculated: Completed
    $project_health = 'completed';
    $project_health_text = 'Completed';
    $project_health_reason = 'All modules have been delivered to the project site';
} elseif (!empty($project['estimated_completion_date'])) {
    // Auto-calculated based on estimated completion date
    $today = new DateTime();
    $est_date = new DateTime($project['estimated_completion_date']);
    $est_completion_date_formatted = $est_date->format('M j, Y');
    $diff = $today->diff($est_date);
    $days_remaining = $diff->days;
    $is_past = $est_date < $today;

    if ($is_past) {
        $days_remaining = -$days_remaining; // Negative to indicate overdue
    }

    if ($is_past && $project_completion_percentage < 100) {
        $project_health = 'behind';
        $project_health_text = 'Behind Schedule';
        $project_health_reason = 'Project is ' . abs($days_remaining) . ' days past the target completion date (' . $est_completion_date_formatted . ') with ' . $project_completion_percentage . '% delivered';
    } elseif (!$is_past && $days_remaining <= 30 && $project_completion_percentage < 80) {
        $project_health = 'at_risk';
        $project_health_text = 'At Risk';
        $project_health_reason = 'Only ' . $days_remaining . ' days until target date (' . $est_completion_date_formatted . ') with ' . $project_completion_percentage . '% delivered';
    } else {
        $project_health_reason = $days_remaining . ' days until target completion (' . $est_completion_date_formatted . ')';
    }
}

// Fetch module batches for this project with wattage information
$module_batches = [];
$stmt_modules = $conn->prepare("
    SELECT m.*, c.name as account_name,
        EXISTS (
            SELECT 1
            FROM unassigned_module_items umi2
            JOIN inventory_pallets ip2 ON ip2.unassigned_module_item_id = umi2.id
            JOIN warranty_claim_replacements wcr2 ON wcr2.pallet_id = ip2.id
            WHERE umi2.unassigned_module_id = m.id
        ) AS is_replacement_batch
    FROM modules m 
    JOIN customer_accounts c ON m.account_id = c.id
    WHERE m.project_id = ?
    ORDER BY m.vendor_name, m.created_at
");
$stmt_modules->bind_param("i", $project_id);
$stmt_modules->execute();
$modules_result = $stmt_modules->get_result();
while ($module = $modules_result->fetch_assoc()) {
    // Get wattage information for this module batch
    $stmt_wattages = $conn->prepare("
        SELECT wattage, quantity 
        FROM unassigned_module_items 
        WHERE unassigned_module_id = ? AND wattage > 0 AND quantity > 0
        ORDER BY wattage ASC
    ");
    $stmt_wattages->bind_param("i", $module['id']);
    $stmt_wattages->execute();
    $wattages_result = $stmt_wattages->get_result();
    
    $module['wattages'] = [];
    while ($wattage_row = $wattages_result->fetch_assoc()) {
        $module['wattages'][] = $wattage_row;
    }
    $stmt_wattages->close();
    
    $module_batches[] = $module;
}
$stmt_modules->close();

// Calculate averages for conversion settings from module batches
$pallets_per_truck_values = [];
$modules_per_truck_values = [];
$total_modules_for_ppt = 0;
$weighted_ppt_sum = 0;
$total_modules_for_mpt = 0;
$weighted_mpt_sum = 0;

// For modules per pallet (project-level and by wattage)
$has_modules_per_pallet_data = false;
$total_modules_for_mpp = 0;
$weighted_mpp_sum = 0;
$mpp_by_wattage = [];
$modules_by_wattage_for_mpp = [];

foreach ($module_batches as $batch) {
    // Weighted average pallets per truck
    $batch_modules_total = 0;
    foreach ($batch['wattages'] as $wattage_info) {
        $batch_modules_total += (int)$wattage_info['quantity'];
    }
    if (!empty($batch['pallets_per_truck']) && $batch['pallets_per_truck'] > 0) {
        $ppt = (int)$batch['pallets_per_truck'];
        $pallets_per_truck_values[] = $ppt;
        $weighted_ppt_sum += ($ppt * $batch_modules_total);
        $total_modules_for_ppt += $batch_modules_total;
    }
    // Weighted average modules per truck
    if (!empty($batch['modules_per_truck']) && $batch['modules_per_truck'] > 0) {
        $mpt = (int)$batch['modules_per_truck'];
        $modules_per_truck_values[] = $mpt;
        $weighted_mpt_sum += ($mpt * $batch_modules_total);
        $total_modules_for_mpt += $batch_modules_total;
    }
    // Modules per pallet (project-level and by wattage)
    if (!empty($batch['modules_per_pallet']) && $batch['modules_per_pallet'] > 0) {
        $has_modules_per_pallet_data = true;
        $mpp = (int)$batch['modules_per_pallet'];
        $weighted_mpp_sum += ($mpp * $batch_modules_total);
        $total_modules_for_mpp += $batch_modules_total;
        foreach ($batch['wattages'] as $wattage_info) {
            $w = (int)$wattage_info['wattage'];
            $qty = (int)$wattage_info['quantity'];
            if (!isset($mpp_by_wattage[$w])) {
                $mpp_by_wattage[$w] = 0;
                $modules_by_wattage_for_mpp[$w] = 0;
            }
            $mpp_by_wattage[$w] += ($mpp * $qty);
            $modules_by_wattage_for_mpp[$w] += $qty;
        }
    }
}

$has_pallets_per_truck_data = ($total_modules_for_ppt > 0 && $weighted_ppt_sum > 0) || !empty($pallets_per_truck_values);
$has_modules_per_truck_data = ($total_modules_for_mpt > 0 && $weighted_mpt_sum > 0) || !empty($modules_per_truck_values);

if ($total_modules_for_ppt > 0 && $weighted_ppt_sum > 0) {
    $average_pallets_per_truck = round($weighted_ppt_sum / $total_modules_for_ppt);
} elseif (!empty($pallets_per_truck_values)) {
    $average_pallets_per_truck = round(array_sum($pallets_per_truck_values) / count($pallets_per_truck_values));
} else {
    // No data present; leave undefined for N/A behavior in UI
    $average_pallets_per_truck = null;
}

if ($total_modules_for_mpt > 0 && $weighted_mpt_sum > 0) {
    $average_modules_per_truck = round($weighted_mpt_sum / $total_modules_for_mpt);
} elseif (!empty($modules_per_truck_values)) {
    $average_modules_per_truck = round(array_sum($modules_per_truck_values) / count($modules_per_truck_values));
} else {
    $average_modules_per_truck = null;
}
$weighted_avg_modules_per_truck = $average_modules_per_truck;

$average_modules_per_pallet = null;
if ($has_modules_per_pallet_data && $total_modules_for_mpp > 0) {
    $average_modules_per_pallet = round($weighted_mpp_sum / $total_modules_for_mpp);
}

// Compute project-level pallets per status (actuals) for Module Delivery Status table
$statuses_of_interest = [
    'At Manufacturer',
    'On Water',
    'Cleared Customs',
    'In Transit to Warehouse',
    'In Warehouse',
    'In Transit to Project',
    'Delivered to Project',
];

$pallets_status_main = [
    'total_order' => 0,
    'delivered' => 0,
    'at_manufacturer' => 0,
    'on_water' => 0,
    'cleared_customs' => 0,
    'in_transit_to_warehouse' => 0,
    'in_warehouse' => 0,
    'in_transit_to_project' => 0,
];
foreach ($statuses_of_interest as $s) {
    $count = (int)($status_totals[$s]['pallets'] ?? 0);
    switch ($s) {
        case 'At Manufacturer': $pallets_status_main['at_manufacturer'] = $count; break;
        case 'On Water': $pallets_status_main['on_water'] = $count; break;
        case 'Cleared Customs': $pallets_status_main['cleared_customs'] = $count; break;
        case 'In Transit to Warehouse': $pallets_status_main['in_transit_to_warehouse'] = $count; break;
        case 'In Warehouse': $pallets_status_main['in_warehouse'] = $count; break;
        case 'In Transit to Project': $pallets_status_main['in_transit_to_project'] = $count; break;
        case 'Delivered to Project': $pallets_status_main['delivered'] = $count; break;
    }
    $pallets_status_main['total_order'] += $count;
}

// Build per-wattage pallets for sub rows using detailed_breakdown actuals
$pallets_sub_rows_status = [];
foreach ($statuses_of_interest as $s) {
    foreach ($detailed_breakdown as $key => $breakdown) {
        // Normalize key to base status (strip location suffixes like " - <name>")
        $base = explode(' - ', $key)[0];
        if ($base !== $s) continue;
        foreach ($breakdown['wattage_breakdown'] as $w => $wd) {
            $label = $w . 'W';
            if (!isset($pallets_sub_rows_status[$label])) {
                $pallets_sub_rows_status[$label] = [
                    'total_order' => 0,
                    'delivered' => 0,
                    'at_manufacturer' => 0,
                    'on_water' => 0,
                    'cleared_customs' => 0,
                    'in_transit_to_warehouse' => 0,
                    'in_warehouse' => 0,
                    'in_transit_to_project' => 0,
                ];
            }
            $pal = (int)($wd['pallets'] ?? 0);
            switch ($s) {
                case 'At Manufacturer': $pallets_sub_rows_status[$label]['at_manufacturer'] += $pal; break;
                case 'On Water': $pallets_sub_rows_status[$label]['on_water'] += $pal; break;
                case 'Cleared Customs': $pallets_sub_rows_status[$label]['cleared_customs'] += $pal; break;
                case 'In Transit to Warehouse': $pallets_sub_rows_status[$label]['in_transit_to_warehouse'] += $pal; break;
                case 'In Warehouse': $pallets_sub_rows_status[$label]['in_warehouse'] += $pal; break;
                case 'In Transit to Project': $pallets_sub_rows_status[$label]['in_transit_to_project'] += $pal; break;
                case 'Delivered to Project': $pallets_sub_rows_status[$label]['delivered'] += $pal; break;
            }
            $pallets_sub_rows_status[$label]['total_order'] += $pal;
        }
    }
}

// Fetch manufacturers for modal dropdown
$manufacturers = [];
$stmt_manufacturers = $conn->prepare("SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC");
$stmt_manufacturers->execute();
$manufacturers_result = $stmt_manufacturers->get_result();
while ($manufacturer = $manufacturers_result->fetch_assoc()) {
    $manufacturers[] = $manufacturer;
}
$stmt_manufacturers->close();

// Fetch site operating hours for this project
$site_operating_hours = [];
$stmt_hours = $conn->prepare("SELECT day_of_week, start_time, end_time FROM site_operating_hours WHERE project_id = ? ORDER BY day_of_week");
if ($stmt_hours) {
    $stmt_hours->bind_param("i", $project_id);
    $stmt_hours->execute();
    $hours_result = $stmt_hours->get_result();
    while ($row = $hours_result->fetch_assoc()) {
        $site_operating_hours[$row['day_of_week']] = [
            'start' => $row['start_time'],
            'end' => $row['end_time']
        ];
    }
    $stmt_hours->close();
}

// Close database connection
$conn->close();

// Determine the correct link for the "Deliveries" button
// If user is "admin", go to manage_deliveries.php; else "view_project.php"
$deliveriesLink = in_array($role, ['admin', 'global_admin', 'customer_admin'])
    ? "manage_deliveries?project_id={$project_id}"
    : "view_project?project_id={$project_id}";

// Role helper variables for unified view
$isAdmin = in_array($role, ['admin', 'global_admin', 'customer_admin']);
$isGlobalAdmin = in_array($role, ['admin', 'global_admin', 'customer_admin']);
