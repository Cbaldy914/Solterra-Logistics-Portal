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
} else {
    $module_type_combined = "N/A";
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
$total_customer_cost     = 0;
$total_accessorial_costs = 0;
$total_warehousing_cost  = 0;
$total_solterra_fee      = 0;
$total_logistics_cost    = 0;

// For cost-per-unit
$costs_by_key    = [];
$quantity_by_key = [];
$keys_list_fin   = [];

function calcWarehousingCost($dv, $warehouse) {
    if (!$warehouse) return 0;
    $res = 0;
    if (!empty($dv['warehouse_arrival_date'])) {
        $in_fee  = $warehouse['in_fee'];
        $out_fee = (!empty($dv['left_warehouse_date'])) ? $warehouse['out_fee'] : 0;
        $sd=new DateTime($dv['warehouse_arrival_date']);
        $ed=(!empty($dv['left_warehouse_date']))?new DateTime($dv['left_warehouse_date']):new DateTime();
        $diff=$sd->diff($ed);
        $days=$diff->days+1;
        $daily= $warehouse['monthly_storage_fee']/30;
        $store=$days*$daily;
        $res=$in_fee + $store + $out_fee;
    }
    return $res;
}

// fetch warehouse if any
$stmt = $conn->prepare("
    SELECT w.id
    FROM warehouses w
    INNER JOIN projects p ON p.warehouse_id = w.id
    WHERE p.id=?
");
$stmt->bind_param("i",$project_id);
$stmt->execute();
$whres = $stmt->get_result();
$stmt->close();
$warehouse = null;
if ($whres->num_rows > 0) {
    $warehouse_basic = $whres->fetch_assoc();
    $warehouse_id = $warehouse_basic['id'];
    
    // Fetch cost items for this warehouse
    $cost_stmt = $conn->prepare("
        SELECT trigger_event, amount 
        FROM warehouse_cost_items 
        WHERE warehouse_id = ? AND is_active = 1
    ");
    $cost_stmt->bind_param("i", $warehouse_id);
    $cost_stmt->execute();
    $cost_result = $cost_stmt->get_result();
    
    $warehouse = ['id' => $warehouse_id];
    $warehouse['in_fee'] = 0;
    $warehouse['out_fee'] = 0;
    $warehouse['monthly_storage_fee'] = 0;
    
    while ($cost = $cost_result->fetch_assoc()) {
        switch ($cost['trigger_event']) {
            case 'entry':
                $warehouse['in_fee'] = $cost['amount'];
                break;
            case 'exit':
                $warehouse['out_fee'] = $cost['amount'];
                break;
            case 'monthly':
                $warehouse['monthly_storage_fee'] = $cost['amount'];
                break;
        }
    }
    $cost_stmt->close();
}

// Build actual total cost from deliveries
while ($dv = $dres->fetch_assoc()) {
    $stat = $dv['status_of_delivery'];
    $watt= (float)$dv['wattage'];
    $c   = (float)$dv['customer_cost'];
    $a   = (float)$dv['accessorial_costs'];
    $q   = (int)$dv['quantity'];

    $wcost = calcWarehousingCost($dv, $warehouse);

    if (!empty($dv['actual_delivery_date'])) {
        $soltFeeForThisDelivery = $solterra_fee * ($watt * $q);
    } else {
        $soltFeeForThisDelivery = 0;
    }

    $tc = $c + $a + $wcost + $soltFeeForThisDelivery;

    $total_customer_cost     += $c;
    $total_accessorial_costs += $a;
    $total_warehousing_cost  += $wcost;
    $total_solterra_fee      += $soltFeeForThisDelivery;
    $total_logistics_cost    += $tc;

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

// Build cost_data
$cost_data = [];
$combined_total_costs = 0;
$combined_qty = 0;

foreach ($keys_list_fin as $k) {
    $tc = $costs_by_key[$k];
    $qt = $quantity_by_key[$k];

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
    'Freight Cost' => $total_customer_cost,
    'Warehousing'   => $total_warehousing_cost,
    'Accessorial'   => $total_accessorial_costs,
    'Solterra Fee'  => $total_solterra_fee,
];

// Next 5 weeks for Invoices/Cashflow
$weeks_financial = [];
$weekEndingFin = new DateTime();
if ($weekEndingFin->format('w') != 0) {
    $weekEndingFin->modify('next Sunday');
}
for($i=0;$i<5;$i++){
    $startFin = clone $weekEndingFin;
    $startFin->modify('-6 days');
    $weeks_financial[] = ['start'=>$startFin,'end'=>clone $weekEndingFin];
    $weekEndingFin->modify('+1 week');
}

// Forecast next 5 weeks
$anticipated_deliveries_financial = [];
foreach($weeks_financial as $ix=>$wf) {
    $anticipated_deliveries_financial[$ix] = 0;
}
$stmt = $conn->prepare("
    SELECT anticipated_delivery_date AS dd, quantity, wattage
    FROM deliveries
    WHERE project_id=? AND anticipated_delivery_date IS NOT NULL
    ORDER BY anticipated_delivery_date ASC
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$res_fin = $stmt->get_result();
while($rf=$res_fin->fetch_assoc()) {
    $dd   = new DateTime($rf['dd']);
    $w    = (float)$rf['wattage'];
    $q    = (int)$rf['quantity'];

    $perModFreight     = ($total_raw_modules>0)?($forecasted_freight / $total_raw_modules):0;
    $perModAccessorial = ($total_raw_modules>0)?($forecasted_accessorial / $total_raw_modules):0;
    $forecastVal = ($perModFreight + $perModAccessorial)*$q + ($solterra_fee*($w*$q));

    foreach($weeks_financial as $ix=>$wk) {
        if($dd>=$wk['start'] && $dd<=$wk['end']) {
            $anticipated_deliveries_financial[$ix] += $forecastVal;
            break;
        }
    }
}
$stmt->close();

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
$deliveries_by_date_actual_cost  = [];
$deliveries_by_date_anticipated = [];
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

    // Actual cost
    if(empty($adate) && $stat==='Canceled') {
        $adate = (!empty($ddate)) ? $ddate : date('Y-m-d');
    }
    if(!empty($adate)){
        $weekKey = getWeekEndingSunday($adate);
        $wh   = calcWarehousingCost($dv, $warehouse);
        $cc   = (float)$dv['customer_cost'];
        $ac   = (float)$dv['accessorial_costs'];
        $fee  = $solterra_fee*($watt*$qty);
        $actual_tc = $cc + $ac + $wh + $fee;
        if(!isset($deliveries_by_date_actual_cost[$weekKey])) {
            $deliveries_by_date_actual_cost[$weekKey] = 0;
        }
        $deliveries_by_date_actual_cost[$weekKey] += $actual_tc;
    }

    // Anticipated cost
    $cost_date = $dv['actual_delivery_date'];
    if(empty($cost_date) && $stat==='Canceled'){
        $cost_date = (!empty($ddate))? $ddate : date('Y-m-d');
    } else if(empty($cost_date)){
        $cost_date = $ddate;
    }
    if(!empty($cost_date)) {
        $weekKey = getWeekEndingSunday($cost_date);
        $pmFreight     = ($total_raw_modules>0)?($forecasted_freight / $total_raw_modules):0;
        $pmAccessorial = ($total_raw_modules>0)?($forecasted_accessorial / $total_raw_modules):0;
        $forecast_tc   = ($pmFreight + $pmAccessorial)*$qty + ($solterra_fee*($watt*$qty));
        if(!isset($deliveries_by_date_anticipated[$weekKey])) {
            $deliveries_by_date_anticipated[$weekKey] = 0;
        }
        $deliveries_by_date_anticipated[$weekKey] += $forecast_tc;
    }
}

$all_dates_cost = array_unique(array_merge(
    array_keys($deliveries_by_date_actual_cost),
    array_keys($deliveries_by_date_anticipated)
));
sort($all_dates_cost);

$budgetLine_anticipated = [];
$budgetLine_actual = [];
$acc_ant=0;
$acc_act=0;
$today_str=(new DateTime())->format('Y-m-d');

foreach($all_dates_cost as $d) {
    $acc_ant += ($deliveries_by_date_anticipated[$d] ?? 0);
    if($d<=$today_str) {
        $acc_act += ($deliveries_by_date_actual_cost[$d] ?? 0);
        $budgetLine_actual[]=$acc_act;
    } else {
        $budgetLine_actual[]=null;
    }
    $budgetLine_anticipated[]=$acc_ant;
}

$budgetLineChartData = [
    'anticipated_cost'=> $budgetLine_anticipated,
    'actual_cost'     => $budgetLine_actual,
];
$budgetLineChartDataJSON=json_encode($budgetLineChartData);
$dateLabelsForBudget=json_encode($all_dates_cost);

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

// Close database connection
$conn->close();

// Determine the correct link for the "Deliveries" button
// If user is "admin", go to manage_deliveries.php; else "view_project.php"
$deliveriesLink = in_array($role, ['admin', 'global_admin', 'customer_admin'])
    ? "manage_deliveries?project_id={$project_id}"
    : "view_project?project_id={$project_id}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Project Overview - <?php echo htmlspecialchars($project['project_name']); ?></title>
<link rel="stylesheet" href="portal.css">
<link rel="stylesheet" href="components/project_overview/project_overview.css?v=<?php echo time(); ?>">
<link rel="icon" href="pictures/favicon.png" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
</head>
<body>
<script>
// Keep unit filter buttons on one line on mobile by shortening Truckloads to Trucks
document.addEventListener('DOMContentLoaded', function() {
  function updateTruckloadsLabel() {
    const isMobile = window.innerWidth <= 768;
    document.querySelectorAll('.unit-filter-btn[data-unit="truckloads"]').forEach(btn => {
      const desired = isMobile ? 'Trucks' : 'Truckloads';
      if (btn.textContent.trim() !== desired) btn.textContent = desired;
    });
  }
  updateTruckloadsLabel();
  window.addEventListener('resize', updateTruckloadsLabel);
});

// Tap-to-reveal full table header on mobile
document.addEventListener('DOMContentLoaded', function() {
  if (window.innerWidth <= 768) {
    document.querySelectorAll('.table-responsive th[data-full]').forEach(function(th) {
      th.setAttribute('title', th.getAttribute('data-full'));
      th.style.cursor = 'pointer';
      th.addEventListener('click', function() {
        // Show full name above header row temporarily
        const label = document.createElement('div');
        label.textContent = th.getAttribute('data-full');
        label.style.position = 'absolute';
        label.style.top = '-24px';
        label.style.left = '0';
        label.style.right = '0';
        label.style.textAlign = 'center';
        label.style.fontSize = '12px';
        label.style.fontWeight = '600';
        label.style.color = '#293E4C';
        label.style.background = 'rgba(255,255,255,0.9)';
        label.style.padding = '2px 4px';
        label.style.borderRadius = '4px';
        label.style.boxShadow = '0 2px 6px rgba(0,0,0,0.1)';
        th.style.position = 'relative';
        th.appendChild(label);
        setTimeout(function(){ label.remove(); }, 1500);
      });
    });
  }
});
</script>
<?php include 'header.php'; ?>
<main>
    <?php
    $backLink = 'dashboard.php';
    ?>
    <?php
        require_once 'components/breadcrumbs.php';
        // On project overview, show only: Dashboard » <Project Name>
        echo slp_render_breadcrumbs([
            'project_id'  => (int)$project_id,
            'omit_current'=> true,
        ]);
    ?>



    <div class="project-overview-container">
        <!-- Mobile Project Name -->
        <h1 class="project-name-mobile"><?php echo htmlspecialchars($project['project_name']); ?></h1>
        
        <div class="project-overview-image">
            <?php $projectImage = !empty($project['image_url']) ? $project['image_url'] : 'pictures/project_default.png'; ?>
            <img src="<?php echo htmlspecialchars($projectImage); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>" onerror="this.src='pictures/project_default.png'">
        </div>
        
        <div class="project-info">
            <!-- Desktop Project Name -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1;">
                    <h1 class="project-name-desktop"><?php echo htmlspecialchars($project['project_name']); ?></h1>
                    <p><strong>Project Address:</strong> <?php echo htmlspecialchars($project['project_address']); ?></p>

                    <!-- Project Capacity Overview -->
                    <?php
                    $remaining_mw = $project_size_mw - $ordered_mw;
                    $order_progress_pct = $project_size_mw > 0 ? min(100, ($ordered_mw / $project_size_mw) * 100) : 0;
                    $can_add_modules = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin']);
                    ?>
                    <div class="capacity-wrapper" style="max-width: 60%; margin-top: 12px;">
                        <div class="capacity-overview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                            <!-- Project Size Box -->
                            <div class="capacity-box" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 10px; padding: 10px 14px; border: 1px solid #dee2e6;">
                                <div style="font-size: 10px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Project Size</div>
                                <div style="font-size: 20px; font-weight: 700; color: #293E4C;"><?php echo number_format($project_size_mw, 2); ?> <span style="font-size: 13px; font-weight: 500;">MW</span></div>
                            </div>

                            <!-- Modules Ordered Box -->
                            <div class="capacity-box modules-ordered-box" style="background: linear-gradient(135deg, #f0f7f8 0%, #e3eff1 100%); border-radius: 10px; padding: 10px 14px; border: 1px solid rgba(72, 140, 154, 0.2); position: relative; <?php echo $can_add_modules ? 'cursor: pointer;' : ''; ?>" <?php echo $can_add_modules ? 'onclick="toggleWattageBreakdown(event)"' : ''; ?>>
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <div>
                                        <div style="font-size: 10px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Modules Ordered</div>
                                        <div style="display: flex; align-items: baseline; gap: 6px;">
                                            <span style="font-size: 20px; font-weight: 700; color: #488C9A;"><?php echo number_format($total_raw_modules); ?></span>
                                            <span style="font-size: 12px; color: #495057;">(<?php echo number_format($ordered_mw, 2); ?> MW)</span>
                                        </div>
                                    </div>
                                    <?php if (!empty($total_orders) && count($total_orders) > 0): ?>
                                    <span class="wattage-toggle-icon" style="font-size: 10px; color: #488C9A; transition: transform 0.2s;">▼</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($total_orders) && count($total_orders) > 0): ?>
                                <!-- Wattage Breakdown Dropdown -->
                                <div class="wattage-breakdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid rgba(72, 140, 154, 0.25); border-radius: 8px; padding: 10px 12px; margin-top: 4px; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                                    <div style="font-size: 10px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Wattage Breakdown</div>
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                        <?php foreach ($total_orders as $label => $info): ?>
                                        <span style="font-size: 11px; padding: 3px 8px; background: rgba(72, 140, 154, 0.1); border-radius: 4px; color: #3A6E7F;">
                                            <?php echo number_format($info['raw_quantity']); ?> × <?php echo $label; ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($can_add_modules): ?>
                                    <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" style="display: block; margin-top: 10px; padding: 8px 12px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color: #fff; text-decoration: none; border-radius: 6px; font-size: 12px; font-weight: 500; text-align: center; transition: opacity 0.2s;" onclick="event.stopPropagation();" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                        + Add More Modules
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Remaining/Status Box -->
                            <div class="capacity-box remaining-box" style="border-radius: 10px; padding: 10px 14px; border: 1px solid <?php echo $remaining_mw > 0 ? 'rgba(255, 193, 7, 0.3)' : 'rgba(40, 167, 69, 0.3)'; ?>; background: <?php echo $remaining_mw > 0 ? 'linear-gradient(135deg, #fffbf0 0%, #fff3cd 100%)' : 'linear-gradient(135deg, #f0fff4 0%, #d4edda 100%)'; ?>; display: flex; justify-content: space-between; align-items: center;">
                                <?php if ($remaining_mw > 0): ?>
                                <div>
                                    <div style="font-size: 10px; color: #856404; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Remaining</div>
                                    <div style="font-size: 20px; font-weight: 700; color: #856404;"><?php echo number_format($remaining_mw, 2); ?> <span style="font-size: 13px; font-weight: 500;">MW</span></div>
                                </div>
                                <?php if ($can_add_modules): ?>
                                <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" style="font-size: 18px; color: #856404; text-decoration: none; opacity: 0.6; line-height: 1;" title="Add Modules" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'">+</a>
                                <?php endif; ?>
                                <?php elseif ($remaining_mw < 0): ?>
                                <div>
                                    <div style="font-size: 10px; color: #155724; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Over Target</div>
                                    <div style="font-size: 20px; font-weight: 700; color: #155724;">+<?php echo number_format(abs($remaining_mw), 2); ?> <span style="font-size: 13px; font-weight: 500;">MW</span></div>
                                </div>
                                <?php else: ?>
                                <div>
                                    <div style="font-size: 10px; color: #155724; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Status</div>
                                    <div style="font-size: 16px; font-weight: 700; color: #155724;">Target Reached</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <?php if ($project_size_mw > 0): ?>
                        <div class="capacity-progress" style="margin-top: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px;">
                                <span style="font-size: 10px; color: #6c757d;">Order Progress</span>
                                <span style="font-size: 10px; font-weight: 600; color: <?php echo $order_progress_pct >= 100 ? '#155724' : '#488C9A'; ?>;"><?php echo number_format($order_progress_pct, 1); ?>%</span>
                            </div>
                            <div style="height: 5px; background: #e9ecef; border-radius: 3px; overflow: hidden;">
                                <div style="height: 100%; width: <?php echo min(100, $order_progress_pct); ?>%; background: <?php echo $order_progress_pct >= 100 ? 'linear-gradient(90deg, #28a745 0%, #20c997 100%)' : 'linear-gradient(90deg, #488C9A 0%, #5ba3b1 100%)'; ?>; border-radius: 3px; transition: width 0.5s ease;"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                <div class="project-actions-dropdown">
                    <button class="project-actions-btn" onclick="toggleProjectActions()">
                        <span style="font-size: 18px;">⚙️</span>
                    </button>
                    <div class="project-actions-content" id="projectActionsDropdown">
                        <a href="edit_project.php?project_id=<?php echo $project_id; ?>">✏️ Edit Project</a>
                        <a href="#" onclick="confirmDeleteProject(<?php echo $project_id; ?>, '<?php echo htmlspecialchars($project['project_name'], ENT_QUOTES); ?>')">🗑️ Delete Project</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php include 'components/project_overview/views.php'; ?>
</main>

<script>
<?php if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
// Delivery View line chart (for regular users)
var dateLabels = <?php echo $dateLabelsJSON; ?>;
var lineData   = <?php echo $lineChartDataJSON; ?>;
var ctxLineEl  = document.getElementById('lineChart');
if(ctxLineEl){
var ctxLine    = ctxLineEl.getContext('2d');
// Force a layout refresh to ensure tick rotation takes effect in some browsers
setTimeout(function(){ try { window.dispatchEvent(new Event('resize')); } catch(e){} }, 0);
var lineChart = new Chart(ctxLine, {
    type: 'line',
    data: {
        labels: dateLabels,
        datasets: [
            {
                label: 'Anticipated',
                data: lineData.anticipated,
                borderColor: '#488C9A',
                borderDash: [5,5],
                borderWidth: 2,
                fill: false,
                pointRadius: 0
            },
            {
                label: 'Actual',
                data: lineData.actual,
                borderColor: '#293E4C',
                borderWidth: 2,
                fill: false,
                pointRadius: 0,
                spanGaps: false
            }
        ]
    },
    options: {
        interaction: { mode: 'index', intersect: false },
        responsive: true,
        animation: false,
        scales: {
            x: {
                type: 'time',
                time: {
                    parser: 'yyyy-MM-dd',
                    tooltipFormat: 'PP',
                    unit: 'week',
                    displayFormats: { week: 'MMM d' }
                },
                title: { display: true, text: 'Date' },
                ticks: {
                    maxRotation: 70,
                    minRotation: 70,
                    autoSkip: true,
                    autoSkipPadding: 12
                }
            },
            y: {
                beginAtZero: true,
                ticks: { precision: 0 },
                title: {
                    display: true,
                    text: '<?php echo ($view_mode=="mw") ? "MWs" : "Number of Modules";?>'
                }
            }
        },
        plugins: {
            tooltip: { mode: 'index', intersect: false },
            legend: { display: true, position: 'top' }
        }
    }
});
}

// Delivery Overview pie (for regular users)
var pieChartData   = <?php echo json_encode(array_values($pieChartPercentages ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]';?>;
var pieChartLabels = <?php echo json_encode(array_keys($pieChartPercentages ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]';?>;

// Create dynamic color mapping based on actual labels
var colorMap = {
    'Delivered to Project': '#488C9A',
    'At Manufacturer': '#293E4C', 
    'On Water': '#66B2FF',
    'Cleared Customs': '#32CD32',
    'In Transit to Warehouse': '#9370DB',
    'In Transit to Project': '#C0C0C0',
    'In Warehouse': '#FF6B6B',
    'Exceptions': '#f57c00'
};

var dynamicColors = pieChartLabels.map(function(label) {
    return colorMap[label] || '#cccccc'; // fallback color
});

var ctxPieEl       = document.getElementById('pieChart');
if(ctxPieEl){
// Add pointer cursor since pie chart is now clickable
ctxPieEl.style.cursor = 'pointer';
var ctxPie         = ctxPieEl.getContext('2d');
var pieChart = new Chart(ctxPie,{
    type:'pie',
    data:{
        labels: pieChartLabels,
        datasets:[{
            data: pieChartData,
            backgroundColor: dynamicColors
        }]
    },
    options:{
        plugins:{
            tooltip:{
                callbacks:{
                    label:function(context){
                        var lab=context.label||'';
                        var val=context.parsed||0;
                        let tooltipText = lab+': '+ val.toFixed(2)+'%';
                        
                        // Add damaged count for Delivered to Project
                        if (lab === 'Delivered to Project') {
                            const deliveredDamagedTotal = <?php echo (int)($delivered_damaged_total ?? 0); ?>;
                            if (deliveredDamagedTotal > 0) {
                                tooltipText += ` (${deliveredDamagedTotal} modules damaged)`;
                            }
                        }
                        
                        return tooltipText;
                    }
                }
            }
        },
        onClick: function(event, elements) {
            if (elements.length > 0) {
                const elementIndex = elements[0].index;
                const label = pieChartLabels[elementIndex];
                
                // Map pie chart labels to modal status names
                let modalStatus = label;
                if (label === 'Delivered to Project') {
                    modalStatus = 'Delivered';
                } else if (label === 'Exceptions') {
                    modalStatus = 'Exceptions';
                }
                
                // Open the same modal as the shipping status boxes
                showCustomerShippingModal(modalStatus);
            }
        }
    }
});
}
<?php endif; ?>

function showView(viewId) {
    <?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
    // Admin view logic
    ['progress-info','site-info','module-info'].forEach(function(id){
        var sec = document.getElementById(id);
        var btn = document.getElementById(id+'-btn');
        if(sec) sec.style.display = (id===viewId)?'block':'none';
        if(btn){
            if(id===viewId) btn.classList.add('active');
            else btn.classList.remove('active');
        }
    });
    
    // Load content for site-info and module-info when they're selected
    if(viewId === 'site-info') {
        loadSiteInfo();
    } else if(viewId === 'module-info') {
        loadModuleInfo();
    }
    <?php else: ?>
    // Regular user view logic
    document.getElementById('delivery-info').style.display='none';
    document.getElementById('financial-info').style.display='none';

    document.getElementById('delivery-info-btn').classList.remove('active');
    document.getElementById('financial-info-btn').classList.remove('active');

    if(viewId==='delivery-info'){
        document.getElementById('delivery-info').style.display='block';
        document.getElementById('delivery-info-btn').classList.add('active');
    } else {
        document.getElementById('financial-info').style.display='block';
        document.getElementById('financial-info-btn').classList.add('active');
        initializeFinancialCharts();
    }
    <?php endif; ?>
}

<?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
function loadSiteInfo() {
    var siteSection = document.getElementById('site-info');
    if(siteSection && siteSection.innerHTML.trim() === '') {
        siteSection.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #6c757d;">
                <h3 style="color: #293E4C; margin-bottom: 15px;">Site Information</h3>
                <p>This section will contain project site details, location information, and site-specific requirements.</p>
                <p style="font-style: italic; margin-top: 20px;">Content coming soon...</p>
            </div>
        `;
    }
}

function loadModuleInfo() {
    var moduleSection = document.getElementById('module-info');
    if(moduleSection && moduleSection.innerHTML.trim() === '') {
        moduleSection.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #6c757d;">
                <h3 style="color: #293E4C; margin-bottom: 15px;">Module Information</h3>
                <p>This section will contain detailed module specifications, performance data, and technical documentation.</p>
                <p style="font-style: italic; margin-top: 20px;">Content coming soon...</p>
            </div>
        `;
    }
}

// Shipping Breakdown modal
const shippingBreakdown = <?php echo json_encode($detailed_breakdown ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>;
function showShippingBreakdown(type){
    const modal = document.getElementById('shippingModal');
    const title = document.getElementById('shippingModalTitle');
    const content = document.getElementById('shippingModalContent');
    title.textContent = type + ' - Detailed Breakdown';
    content.innerHTML = generateShippingContent(type);
    modal.style.display = 'block';
}
function closeShippingModal(){
    const modal = document.getElementById('shippingModal');
    if(modal) modal.style.display = 'none';
}
function generateShippingContent(filter){
    let html='<div>';
    let has=false;
    
    // Handle special case for "Delivered" status
    if(filter === 'Delivered') {
        has = true;
        const totalDeliveredRaw = <?php echo (int)($delivered_raw_total ?? 0); ?>;
        const totalDeliveredMW = <?php echo $delivered_combined; ?>;
        const totalPallets = Math.round(totalDeliveredRaw / 30);
        
        html += `<div style="margin-bottom:20px;padding:20px;background:#e8f5e8;border-radius:12px;border-left:4px solid #28a745;">` +
               `<h4 style="margin-top:0;color:#28a745;">🎉 Delivered to Project</h4>` +
               `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin:15px 0;">` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${totalPallets}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Pallets</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${totalDeliveredRaw.toLocaleString()}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Modules</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${totalDeliveredMW.toFixed(2)}</div>` +
               `<div style="font-size:0.9rem;color:#666;">MWs</div></div>` +
               `</div>`;
        
        // Show wattage breakdown for delivered modules        
        const deliveredBreakdown = <?php echo json_encode($delivered_by_wattage ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
        if(deliveredBreakdown.length > 0) {
            html += '<div style="margin-top:20px;"><h5 style="color:#28a745;">Wattage Breakdown:</h5><ul style="list-style:none;padding:0;">';
            deliveredBreakdown.forEach(function(item) {
                const mws = ((item.modules * item.wattage) / 1000000).toFixed(2);
                let breakdownText = `${item.wattage}W: ${item.pallets} pallets • ${item.modules.toLocaleString()} modules • ${mws} MWs`;
                if(item.damaged_pallets > 0) {
                    breakdownText += ` (${item.damaged_pallets} damaged pallets, ${item.damaged_modules.toLocaleString()} damaged modules)`;
                }
                html += `<li style="padding:8px 0;border-bottom:1px solid #eee;">${breakdownText}</li>`;
            });
            html += '</ul></div>';
        }
        
        html += `<div style="text-align:center;margin-top:20px;">` +
               `<a href="manage_deliveries?project_id=<?php echo $project_id; ?>" class="customer-modal-btn">View Deliveries</a>` +
               `</div>`;
        html += '</div>';
    } else if (filter === 'Exceptions') {
        // Handle Exceptions (Damaged Pallets) for admin
        has = true;
        const exceptionsData = {
            damaged: <?php echo ($status_totals['Damaged']['pallets'] ?? 0); ?>,
            damaged_modules: <?php echo ($status_totals['Damaged']['modules'] ?? 0); ?>
        };
        
        const totalExceptionPallets = exceptionsData.damaged;
        const totalExceptionModules = exceptionsData.damaged_modules;
        
        html += `<div style="margin-bottom:20px;padding:15px;background:#fff3e0;border-radius:8px;border-left:4px solid #f57c00;">` +
               `<h4 style="margin-top:0;color:#e65100;">⚠️ Module Exceptions</h4>` +
               `<p><strong>Total:</strong> ${totalExceptionPallets} pallets, ${totalExceptionModules.toLocaleString()} modules</p>`;
        
        // Show exception type breakdown
        if (exceptionsData.damaged > 0) {
            html += '<p><strong>Exception Breakdown:</strong></p><ul>';
            html += `<li style="color:#d32f2f;"><strong>Damaged:</strong> ${exceptionsData.damaged} pallets (${exceptionsData.damaged_modules.toLocaleString()} modules)</li>`;
            html += '</ul>';
        }
        
        html += `<div style="text-align:center;margin-top:15px;">` +
               `<a href="warranty.php?project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#f57c00;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">View Exceptions</a>` +
               `</div>`;
        html += '</div>';
    } else {
        // Handle other shipping statuses
        for(const key in shippingBreakdown){
            if(key.includes(filter)){
                has=true;
                const data=shippingBreakdown[key];
                html+=`<div style="margin-bottom:20px;padding:15px;background:#f8f9fa;border-radius:8px;border-left:4px solid #488C9A;">`+
                     `<h4 style="margin-top:0;">${key}</h4>`+
                     `<p><strong>Total:</strong> ${data.pallet_count} pallets, ${data.total_modules.toLocaleString()} modules</p>`;
                if(data.wattage_breakdown && Object.keys(data.wattage_breakdown).length>0){
                    html+='<p><strong>Wattage Breakdown:</strong></p><ul>';
                    for(const w in data.wattage_breakdown){
                        const d=data.wattage_breakdown[w];
                        html+=`<li>${w}W: ${d.pallets} pallets (${d.modules.toLocaleString()} modules)</li>`;
                    }
                    html+='</ul>';
                }
                if(filter==='In Transit to Warehouse' && data.warehouse_id){
                    html+=`<a href="manage_warehouse_inventory.php?warehouse_id=${data.warehouse_id}&project_id=<?php echo $project_id; ?>" class="modal-action" style="display:inline-block;margin-top:8px;background:#488C9A;color:#fff;padding:6px 10px;border-radius:4px;text-decoration:none;">Receive into Warehouse</a>`;
                }
                html+='</div>';
            }
        }
        
        if(!has){html+='<p style="text-align:center;color:#666;font-style:italic;">No data.</p>';}
        
        // Add action buttons for different statuses
        if(filter==='At Manufacturer'){
            html+=`<div style="text-align:center;margin-top:15px;"><a href="create_shipment.php?project_id=<?php echo $project_id; ?>&status_filter=At%20Manufacturer" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Create Shipment</a></div>`;
        }else if(filter==='On Water'){
            // For On Water status, link to the appropriate port warehouse for receiving
            html+=`<div style="text-align:center;margin-top:15px;">`;
            html+=`<p style="color:#666;margin-bottom:10px;">Pallets are in ocean transit. When they arrive at port, they will be available for receiving.</p>`;
            // Find the port warehouse for this project's overseas pallets
            for(const key in shippingBreakdown){
                if(key.includes('On Water') && shippingBreakdown[key].warehouse_id){
                    html+=`<a href="manage_warehouse_inventory.php?warehouse_id=${shippingBreakdown[key].warehouse_id}&project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;margin:5px;">Receive at Port</a>`;
                    break;
                }
            }
            html+=`</div>`;
        }else if(filter==='Cleared Customs'){
            // For Cleared Customs status, link to warehouse inventory for drayage shipment
            html+=`<div style="text-align:center;margin-top:15px;">`;
            html+=`<p style="color:#666;margin-bottom:10px;">Pallets have cleared customs. Create a drayage shipment to move them to their destination.</p>`;
            // Find the port warehouse for this project's cleared customs pallets
            for(const key in shippingBreakdown){
                if(key.includes('Cleared Customs') && shippingBreakdown[key].warehouse_id){
                    html+=`<a href="manage_warehouse_inventory.php?warehouse_id=${shippingBreakdown[key].warehouse_id}&project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;margin:5px;">Create Drayage Shipment</a>`;
                    break;
                }
            }
            html+=`</div>`;
        }else if(filter==='In Warehouse'){
            html+=`<div style="text-align:center;margin-top:15px;"><a href="create_shipment.php?project_id=<?php echo $project_id; ?>&status_filter=In%20Warehouse" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Create Shipment</a></div>`;
        }else if(filter==='In Transit to Project'){
            html+=`<div style="text-align:center;margin-top:15px;"><a href="scheduling.php?project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Schedule/Receive Deliveries</a></div>`;
        }
    }
    
    html+='</div>';
    return html;
}

// Admin warehousing functionality
const warehousesWithInventory = <?php echo json_encode($warehouses_with_inventory ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
function handleAdminWarehousing() {
    const projectId = <?php echo $project_id; ?>;
    
    if (warehousesWithInventory.length === 0) {
        alert('No inventory found for this project in any warehouse.');
        return;
    } else if (warehousesWithInventory.length === 1) {
        // Single warehouse - go directly to manage_warehouse_inventory
        window.location.href = 'manage_warehouse_inventory.php?warehouse_id=' + warehousesWithInventory[0].id + '&project_id=' + projectId;
    } else {
        // Multiple warehouses - show warehouse selection page
        showWarehouseSelectionModal();
    }
}

function showWarehouseSelectionModal() {
    const modal = document.createElement('div');
    modal.className = 'warehouse-selection-modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3>Select Warehouse to Manage</h3>
                <span class="close-modal" onclick="closeWarehouseModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>This project has inventory in multiple warehouses. Select a warehouse to manage:</p>
                <div class="warehouse-selection-grid">
                    ${warehousesWithInventory.map(wh => `
                        <div class="warehouse-selection-card" onclick="goToWarehouseManagement(${wh.id})">
                            <h4>${wh.name}</h4>
                            <p><strong>Address:</strong> ${wh.address || 'N/A'}</p>
                            <p><strong>Pallets Stored:</strong> ${wh.pallets_in_warehouse || 0}</p>
                            <p><strong>Modules Stored:</strong> ${wh.modules_in_warehouse || 0}</p>
                            <p><strong>Pallets In Transit:</strong> ${wh.pallets_in_transit_to_wh || 0}</p>
                        </div>
                    `).join('')}
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    modal.style.display = 'block';
}

function closeWarehouseModal() {
    const modal = document.querySelector('.warehouse-selection-modal');
    if (modal) {
        modal.remove();
    }
}

function goToWarehouseManagement(warehouseId) {
    const projectId = <?php echo $project_id; ?>;
    window.location.href = 'manage_warehouse_inventory.php?warehouse_id=' + warehouseId + '&project_id=' + projectId;
}
<?php endif; ?>

<?php if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
// Customer view functions
let currentFilter = 'mws'; // Global filter state

// Conversion availability and actual actuals for pallets/truckloads
window.conversionAvailability = {
    isAdmin: false,
    modulesPerPalletAvailable: <?php echo ($average_modules_per_pallet !== null && $average_modules_per_pallet > 0) ? 'true' : 'false'; ?>,
    avgModulesPerPallet: <?php echo ($average_modules_per_pallet !== null && $average_modules_per_pallet > 0) ? (int)$average_modules_per_pallet : 'null'; ?>,
    palletsPerTruckAvailable: <?php echo ($average_pallets_per_truck !== null && $average_pallets_per_truck > 0) ? 'true' : 'false'; ?>,
    avgPalletsPerTruck: <?php echo ($average_pallets_per_truck !== null && $average_pallets_per_truck > 0) ? (int)$average_pallets_per_truck : 'null'; ?>,
    modulesPerTruckAvailable: <?php echo ($average_modules_per_truck !== null && $average_modules_per_truck > 0) ? 'true' : 'false'; ?>,
    avgModulesPerTruck: <?php echo ($average_modules_per_truck !== null && $average_modules_per_truck > 0) ? (int)$average_modules_per_truck : 'null'; ?>,
    wattageModulesPerPallet: <?php
        $mpp_by_wattage_avg = [];
        foreach ($mpp_by_wattage as $w => $sum_val) {
            $den = $modules_by_wattage_for_mpp[$w] ?? 0;
            if ($den > 0) $mpp_by_wattage_avg[$w] = round($sum_val / $den);
        }
        echo json_encode($mpp_by_wattage_avg);
    ?>
};

// Actual pallets by status for Module Delivery Status table
window.actualStatusData = {
    pallets: {
        main: <?php echo json_encode($pallets_status_main ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>,
        sub: <?php echo json_encode($pallets_sub_rows_status ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>
    }
};

function showView(viewId) {
    // Hide all sections
    document.getElementById('project-progress').style.display = 'none';
    document.getElementById('delivery-info').style.display = 'none';
    document.getElementById('financial-info').style.display = 'none';

    // Remove active class from all buttons
    document.getElementById('project-progress-btn').classList.remove('active');
    document.getElementById('delivery-info-btn').classList.remove('active');
    document.getElementById('financial-info-btn').classList.remove('active');

    // Show selected section and activate button
    if(viewId === 'project-progress'){
        document.getElementById('project-progress').style.display = 'block';
        document.getElementById('project-progress-btn').classList.add('active');
    } else if(viewId === 'delivery-info'){
        document.getElementById('delivery-info').style.display = 'block';
        document.getElementById('delivery-info-btn').classList.add('active');
        // Initialize charts if needed
        initializeFinancialCharts();
    } else {
        document.getElementById('financial-info').style.display = 'block';
        document.getElementById('financial-info-btn').classList.add('active');
        // Initialize charts if needed
        initializeFinancialCharts();
    }
    
    // Apply the current filter to the new section
    syncFiltersToState();
}

function toggleSubRows(cls){
    var rows = document.getElementsByClassName(cls);
    for(var i=0; i<rows.length; i++){
        if(rows[i].style.display==='' || rows[i].style.display==='none'){
            rows[i].style.display='table-row';
        } else {
            rows[i].style.display='none';
        }
    }
}

// Sync all filter buttons and data to the current state
function syncFiltersToState() {
    // Update all filter buttons to match current state
    document.querySelectorAll('.unit-filter-btn').forEach(btn => {
        if (btn.getAttribute('data-unit') === currentFilter) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    
    // Update all sections with current filter
    updateCustomerShippingBoxes(currentFilter, document.getElementById('project-progress'));
    updateCustomerShippingBoxes(currentFilter, document.getElementById('delivery-info'));
    
    // Handle large numbers after updating
    setTimeout(handleLargeNumbers, 100);
    
    // Update timeline remaining text
    updateTimelineRemainingText(currentFilter);
}

// Update timeline remaining text based on current filter
function updateTimelineRemainingText(filterType) {
    const timelineTexts = document.querySelectorAll('.timeline-remaining-text');
    if (!timelineTexts.length) return;
    
    // Check if project is completed
    const isCompleted = <?php echo $step5_completed ? 'true' : 'false'; ?>;
    if (isCompleted) return; // Don't update if project is already completed
    
    // Get project data for calculations  
    const totalModules = <?php echo (int)($total_raw_modules ?? 0); ?>;
    const deliveredModules = <?php echo (int)($delivered_raw_total ?? 0); ?>;
    const projectSizeMW = <?php echo is_numeric($project_size_mw) ? round($project_size_mw, 2) : 0; ?>;
    
    // Calculate delivered MW (approximate based on delivered/total ratio)
    const deliveryRatio = totalModules > 0 ? (deliveredModules / totalModules) : 0;
    const deliveredMW = projectSizeMW * deliveryRatio;
    
    // Calculate totals and remaining for each filter type
    let remaining, unit;
    
    switch(filterType) {
        case 'modules':
            remaining = Math.max(0, totalModules - deliveredModules);
            unit = 'modules';
            break;
            
        case 'mws':
            remaining = projectSizeMW - deliveredMW;
            unit = 'MWs';
            remaining = Math.max(0, parseFloat(remaining.toFixed(2)));
            break;
            
        case 'pallets':
            // Use actual pallet counts from the database
            const actualPalletData = <?php echo json_encode($pallets_status_main ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>;
            const totalActualPallets = actualPalletData.total_order;
            const deliveredActualPallets = actualPalletData.delivered;
            remaining = Math.max(0, totalActualPallets - deliveredActualPallets);
            unit = 'pallets';
            break;
            
        case 'truckloads':
            // Estimate truckloads based on modules (using average modules per truck if available)  
            const avgModulesPerTruck = <?php echo ($weighted_avg_modules_per_truck !== null && $weighted_avg_modules_per_truck > 0) ? (int)$weighted_avg_modules_per_truck : 500; ?>;
            const totalTrucks = Math.ceil(totalModules / avgModulesPerTruck);
            const deliveredTrucks = Math.floor(deliveredModules / avgModulesPerTruck);
            remaining = Math.max(0, totalTrucks - deliveredTrucks);
            unit = 'truckloads';
            break;
            
        default:
            remaining = Math.max(0, totalModules - deliveredModules);
            unit = 'modules';
    }
    
    // Update all timeline remaining text elements
    timelineTexts.forEach(element => {
        const countSpan = element.querySelector('.remaining-count');
        const unitSpan = element.querySelector('.remaining-unit');
        
        if (countSpan && unitSpan) {
            // Format the number properly
            const formattedRemaining = filterType === 'mws' ? 
                remaining.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) :
                remaining.toLocaleString('en-US');
                
            countSpan.textContent = formattedRemaining;
            unitSpan.textContent = unit;
        }
    });
}

// Customer Unit Filter functionality
function initializeCustomerUnitFilters() {
    const filterSections = document.querySelectorAll('.unit-filters');
    
    filterSections.forEach(section => {
        const filterButtons = section.querySelectorAll('.unit-filter-btn');
        
        filterButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const filterType = this.getAttribute('data-unit');
                
                // Update global filter state
                currentFilter = filterType;
                
                // Sync all sections to new filter
                syncFiltersToState();
            });
        });
    });
}

function updateCustomerShippingBoxes(filterType, section) {
    if (!section) return;
    
    // Update shipping boxes
    const shippingBoxes = section.querySelectorAll('.shipping-box-customer');
    
    shippingBoxes.forEach(box => {
        const statusCount = box.querySelector('.status-count');
        const statusUnit = box.querySelector('.status-unit');
        
        if (statusCount && statusUnit) {
            let value, unit;
            
            switch(filterType) {
                case 'modules':
                    value = parseInt(box.getAttribute('data-modules') || 0);
                    unit = 'modules';
                    break;
                case 'truckloads':
                    value = parseFloat(box.getAttribute('data-truckloads') || 0);
                    unit = 'truckloads';
                    break;
                case 'mws':
                    value = parseFloat(box.getAttribute('data-mws') || 0);
                    unit = 'MWs';
                    break;
                case 'pallets':
                default:
                    value = parseInt(box.getAttribute('data-pallets') || 0);
                    unit = 'pallets';
                    break;
            }
            
            // Format the value
            let displayText;
            if (isNaN(value)) {
                displayText = 'N/A';
            } else if (filterType === 'truckloads' || filterType === 'mws') {
                const decimals = filterType === 'mws' ? 2 : 1;
                displayText = value % 1 === 0 ? value.toString() : value.toFixed(decimals);
            } else {
                displayText = Math.round(value).toLocaleString();
            }

            statusCount.textContent = displayText;
            statusUnit.textContent = unit;

            // Add class for large numbers to shrink font
            statusCount.classList.remove('large-number', 'very-large-number');
            if (displayText.length >= 7) {
                statusCount.classList.add('very-large-number');
            } else if (displayText.length >= 5) {
                statusCount.classList.add('large-number');
            }
        }
    });

    // Update table data if this is the delivery-info section
    if (section.id === 'delivery-info') {
        updateDeliveryTables(filterType);
    }
}

function updateDeliveryTables(filterType) {
    // Update table data based on filter type
    const table1 = document.getElementById('table1');
    const table2 = document.getElementById('table2');
    
    if (!table1 || !table2) return;
    
    // Store original data if not already stored
    if (!window.originalTableData) {
        window.originalTableData = {
            mw: {
                total_order: <?php echo $total_order_combined; ?>,
                delivered: <?php echo $delivered_combined; ?>,
                at_manufacturer: <?php echo $at_manufacturer_combined; ?>,
                in_warehouse: <?php echo $in_warehouse_combined; ?>,
                on_water: <?php echo $on_water_combined; ?>,
                cleared_customs: <?php echo $cleared_customs_combined; ?>,
                in_transit_to_warehouse: <?php echo $in_transit_to_warehouse_combined; ?>,
                in_transit_to_project: <?php echo $in_transit_to_project_combined; ?>,
                weeks: [<?php foreach($anticipated_quantities_combined as $q): ?><?php echo $q; ?>,<?php endforeach; ?>],
                sub_rows: {
                    <?php foreach($sub_rows as $lbl => $sr): ?>
                    '<?php echo addslashes($lbl); ?>': {
                        total_order: <?php echo $sr['total_order']; ?>,
                        delivered: <?php echo $sr['delivered']; ?>,
                        weeks: [<?php foreach($sr['anticipated_quantities'] as $v): ?><?php echo $v; ?>,<?php endforeach; ?>]
                    },
                    <?php endforeach; ?>
                },
                sub_rows_status: {
                    <?php foreach($sub_rows_status as $lbl => $srs): ?>
                    '<?php echo addslashes($lbl); ?>': {
                        total_order: <?php echo $srs['total_order']; ?>,
                        delivered: <?php echo $srs['delivered']; ?>,
                        at_manufacturer: <?php echo ($srs['at_manufacturer'] ?? 0); ?>,
                        in_warehouse: <?php echo $srs['in_warehouse']; ?>,
                        on_water: <?php echo $srs['on_water']; ?>,
                        cleared_customs: <?php echo $srs['cleared_customs']; ?>,
                        in_transit_to_warehouse: <?php echo ($srs['in_transit_to_warehouse'] ?? 0); ?>,
                        in_transit_to_project: <?php echo ($srs['in_transit_to_project'] ?? 0); ?>,
                    },
                    <?php endforeach; ?>
                }
            }
        };
        
        // Convert MWs to modules for module data
        const avgWattage = <?php 
            if (!empty($wattages)) {
                echo array_sum($wattages) / count($wattages);
            } else {
                echo 555; // Default wattage if none available
            }
        ?>;
        
        window.originalTableData.modules = {
            total_order: Math.round(window.originalTableData.mw.total_order * 1000000 / avgWattage),
            delivered: Math.round(window.originalTableData.mw.delivered * 1000000 / avgWattage),
            at_manufacturer: Math.round(window.originalTableData.mw.at_manufacturer * 1000000 / avgWattage),
            in_warehouse: Math.round(window.originalTableData.mw.in_warehouse * 1000000 / avgWattage),
            on_water: Math.round(window.originalTableData.mw.on_water * 1000000 / avgWattage),
            cleared_customs: Math.round(window.originalTableData.mw.cleared_customs * 1000000 / avgWattage),
            in_transit_to_warehouse: Math.round(window.originalTableData.mw.in_transit_to_warehouse * 1000000 / avgWattage),
            in_transit_to_project: Math.round(window.originalTableData.mw.in_transit_to_project * 1000000 / avgWattage),
            weeks: window.originalTableData.mw.weeks.map(w => Math.round(w * 1000000 / avgWattage)),
            sub_rows: {},
            sub_rows_status: {}
        };
        
        // Calculate pallets data (assuming 30 modules per pallet)
        const modulesPerPallet = 30;
        window.originalTableData.pallets = {
            total_order: Math.round(window.originalTableData.modules.total_order / modulesPerPallet),
            delivered: Math.round(window.originalTableData.modules.delivered / modulesPerPallet),
            at_manufacturer: Math.round(window.originalTableData.modules.at_manufacturer / modulesPerPallet),
            in_warehouse: Math.round(window.originalTableData.modules.in_warehouse / modulesPerPallet),
            on_water: Math.round(window.originalTableData.modules.on_water / modulesPerPallet),
            cleared_customs: Math.round(window.originalTableData.modules.cleared_customs / modulesPerPallet),
            in_transit_to_warehouse: Math.round(window.originalTableData.modules.in_transit_to_warehouse / modulesPerPallet),
            in_transit_to_project: Math.round(window.originalTableData.modules.in_transit_to_project / modulesPerPallet),
            weeks: window.originalTableData.modules.weeks.map(w => Math.round(w / modulesPerPallet)),
            sub_rows: {},
            sub_rows_status: {}
        };
        
        // Calculate truckloads data only if a pallets-per-truck value is available; otherwise leave as empty
        const palletsPerTruck = (window.conversionAvailability && window.conversionAvailability.avgPalletsPerTruck) ? window.conversionAvailability.avgPalletsPerTruck : null;
        window.originalTableData.truckloads = { sub_rows: {}, sub_rows_status: {}, weeks: [], total_order: 0, delivered: 0 };
        if (palletsPerTruck) {
            window.originalTableData.truckloads = {
                total_order: (window.originalTableData.pallets.total_order / palletsPerTruck).toFixed(1),
                delivered: (window.originalTableData.pallets.delivered / palletsPerTruck).toFixed(1),
                at_manufacturer: (window.originalTableData.pallets.at_manufacturer / palletsPerTruck).toFixed(1),
                in_warehouse: (window.originalTableData.pallets.in_warehouse / palletsPerTruck).toFixed(1),
                on_water: (window.originalTableData.pallets.on_water / palletsPerTruck).toFixed(1),
                cleared_customs: (window.originalTableData.pallets.cleared_customs / palletsPerTruck).toFixed(1),
                in_transit_to_warehouse: (window.originalTableData.pallets.in_transit_to_warehouse / palletsPerTruck).toFixed(1),
                in_transit_to_project: (window.originalTableData.pallets.in_transit_to_project / palletsPerTruck).toFixed(1),
                weeks: window.originalTableData.pallets.weeks.map(w => (w / palletsPerTruck).toFixed(1)),
                sub_rows: {},
                sub_rows_status: {}
            };
        }
        
        // Convert sub_rows data
        for (const [key, data] of Object.entries(window.originalTableData.mw.sub_rows)) {
            const wattageMatch = key.match(/(\d+)W/);
            const wattage = wattageMatch ? parseFloat(wattageMatch[1]) : avgWattage;

            window.originalTableData.modules.sub_rows[key] = {
                total_order: Math.round(data.total_order * 1000000 / wattage),
                delivered: Math.round(data.delivered * 1000000 / wattage),
                weeks: data.weeks.map(w => Math.round(w * 1000000 / wattage))
            };

            window.originalTableData.pallets.sub_rows[key] = {
                total_order: Math.round(window.originalTableData.modules.sub_rows[key].total_order / modulesPerPallet),
                delivered: Math.round(window.originalTableData.modules.sub_rows[key].delivered / modulesPerPallet),
                weeks: window.originalTableData.modules.sub_rows[key].weeks.map(w => Math.round(w / modulesPerPallet))
            };

            if (palletsPerTruck) {
                window.originalTableData.truckloads.sub_rows[key] = {
                    total_order: (window.originalTableData.pallets.sub_rows[key].total_order / palletsPerTruck).toFixed(1),
                    delivered: (window.originalTableData.pallets.sub_rows[key].delivered / palletsPerTruck).toFixed(1),
                    weeks: window.originalTableData.pallets.sub_rows[key].weeks.map(w => (w / palletsPerTruck).toFixed(1))
                };
            }
        }

        // Convert sub_rows_status data
        for (const [key, data] of Object.entries(window.originalTableData.mw.sub_rows_status)) {
            const wattageMatch = key.match(/(\d+)W/);
            const wattage = wattageMatch ? parseFloat(wattageMatch[1]) : avgWattage;

            window.originalTableData.modules.sub_rows_status[key] = {
                total_order: Math.round(data.total_order * 1000000 / wattage),
                delivered: Math.round(data.delivered * 1000000 / wattage),
                at_manufacturer: Math.round(data.at_manufacturer * 1000000 / wattage),
                in_warehouse: Math.round(data.in_warehouse * 1000000 / wattage),
                on_water: Math.round(data.on_water * 1000000 / wattage),
                cleared_customs: Math.round(data.cleared_customs * 1000000 / wattage),
                in_transit_to_warehouse: Math.round(data.in_transit_to_warehouse * 1000000 / wattage),
                in_transit_to_project: Math.round(data.in_transit_to_project * 1000000 / wattage)
            };

            window.originalTableData.pallets.sub_rows_status[key] = {
                total_order: Math.round(window.originalTableData.modules.sub_rows_status[key].total_order / modulesPerPallet),
                delivered: Math.round(window.originalTableData.modules.sub_rows_status[key].delivered / modulesPerPallet),
                at_manufacturer: Math.round(window.originalTableData.modules.sub_rows_status[key].at_manufacturer / modulesPerPallet),
                in_warehouse: Math.round(window.originalTableData.modules.sub_rows_status[key].in_warehouse / modulesPerPallet),
                on_water: Math.round(window.originalTableData.modules.sub_rows_status[key].on_water / modulesPerPallet),
                cleared_customs: Math.round(window.originalTableData.modules.sub_rows_status[key].cleared_customs / modulesPerPallet),
                in_transit_to_warehouse: Math.round(window.originalTableData.modules.sub_rows_status[key].in_transit_to_warehouse / modulesPerPallet),
                in_transit_to_project: Math.round(window.originalTableData.modules.sub_rows_status[key].in_transit_to_project / modulesPerPallet)
            };

            if (palletsPerTruck) {
                window.originalTableData.truckloads.sub_rows_status[key] = {
                    total_order: (window.originalTableData.pallets.sub_rows_status[key].total_order / palletsPerTruck).toFixed(1),
                    delivered: (window.originalTableData.pallets.sub_rows_status[key].delivered / palletsPerTruck).toFixed(1),
                    at_manufacturer: (window.originalTableData.pallets.sub_rows_status[key].at_manufacturer / palletsPerTruck).toFixed(1),
                    in_warehouse: (window.originalTableData.pallets.sub_rows_status[key].in_warehouse / palletsPerTruck).toFixed(1),
                    on_water: (window.originalTableData.pallets.sub_rows_status[key].on_water / palletsPerTruck).toFixed(1),
                    cleared_customs: (window.originalTableData.pallets.sub_rows_status[key].cleared_customs / palletsPerTruck).toFixed(1),
                    in_transit_to_warehouse: (window.originalTableData.pallets.sub_rows_status[key].in_transit_to_warehouse / palletsPerTruck).toFixed(1),
                    in_transit_to_project: (window.originalTableData.pallets.sub_rows_status[key].in_transit_to_project / palletsPerTruck).toFixed(1)
                };
            }
        }
    }
    
    // Determine which data to use based on filter
    let dataType, decimals;
    switch(filterType) {
        case 'mws':
            dataType = 'mw';
            decimals = 2;
            break;
        case 'modules':
            dataType = 'modules';
            decimals = 0;
            break;
        case 'pallets':
            dataType = 'pallets';
            decimals = 0;
            break;
        case 'truckloads':
            dataType = 'truckloads';
            decimals = 1;
            break;
        default:
            dataType = 'mw';
            decimals = 2;
    }
    const data = window.originalTableData[dataType];
    
    // Update main rows in both tables
    const mainRow1 = table1.querySelector('tbody tr:first-child');
    if (mainRow1) {
        const cells = mainRow1.querySelectorAll('td');
        if (cells.length >= 3) {
            if (filterType === 'pallets') {
                if (window.conversionAvailability.modulesPerPalletAvailable && window.conversionAvailability.avgModulesPerPallet) {
                    const mpp = window.conversionAvailability.avgModulesPerPallet;
                    const src = window.originalTableData.modules;
                    cells[1].textContent = formatNumber(src.total_order / mpp, 0);
                    cells[2].textContent = formatNumber(src.delivered / mpp, 0);
                    for (let i = 0; i < src.weeks.length && i + 3 < cells.length; i++) {
                        cells[i + 3].textContent = formatNumber(src.weeks[i] / mpp, 0);
                    }
                } else {
                    // N/A when missing conversion
                    for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                }
            } else if (filterType === 'truckloads') {
                const src = window.originalTableData.modules;
                if (window.conversionAvailability.palletsPerTruckAvailable && window.conversionAvailability.avgPalletsPerTruck && window.conversionAvailability.modulesPerPalletAvailable && window.conversionAvailability.avgModulesPerPallet) {
                    const mpp = window.conversionAvailability.avgModulesPerPallet;
                    const ppt = window.conversionAvailability.avgPalletsPerTruck;
                    cells[1].textContent = formatNumber((src.total_order / mpp) / ppt, 1);
                    cells[2].textContent = formatNumber((src.delivered / mpp) / ppt, 1);
                    for (let i = 0; i < src.weeks.length && i + 3 < cells.length; i++) {
                        cells[i + 3].textContent = formatNumber((src.weeks[i] / mpp) / ppt, 1);
                    }
                } else if (window.conversionAvailability.modulesPerTruckAvailable && window.conversionAvailability.avgModulesPerTruck) {
                    const mpt = window.conversionAvailability.avgModulesPerTruck;
                    cells[1].textContent = formatNumber(src.total_order / mpt, 1);
                    cells[2].textContent = formatNumber(src.delivered / mpt, 1);
                    for (let i = 0; i < src.weeks.length && i + 3 < cells.length; i++) {
                        cells[i + 3].textContent = formatNumber(src.weeks[i] / mpt, 1);
                    }
                } else {
                    for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                }
            } else {
                cells[1].textContent = formatNumber(data.total_order, decimals);
                cells[2].textContent = formatNumber(data.delivered, decimals);
                // Update week cells
                for (let i = 0; i < data.weeks.length && i + 3 < cells.length; i++) {
                    cells[i + 3].textContent = formatNumber(data.weeks[i], decimals);
                }
            }
        }
    }
    
    const mainRow2 = table2.querySelector('tbody tr:first-child');
    if (mainRow2) {
        const cells = mainRow2.querySelectorAll('td');
        if (cells.length >= 3) {
            if (filterType === 'pallets') {
                const pal = window.actualStatusData.pallets.main;
                let idx = 1;
                cells[idx++].textContent = formatNumber(pal.total_order || 0, 0);
                cells[idx++].textContent = formatNumber(pal.at_manufacturer || 0, 0);
                if (data.on_water > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.on_water || 0, 0);
                if (data.cleared_customs > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.cleared_customs || 0, 0);
                if (data.in_transit_to_warehouse > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.in_transit_to_warehouse || 0, 0);
                if (data.in_warehouse > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.in_warehouse || 0, 0);
                if (data.in_transit_to_project > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.in_transit_to_project || 0, 0);
                if (cells[idx]) cells[idx].textContent = formatNumber(pal.delivered || 0, 0);
            } else if (filterType === 'truckloads') {
                const pal = window.actualStatusData.pallets.main;
                let tl = null;
                if (window.conversionAvailability.palletsPerTruckAvailable && window.conversionAvailability.avgPalletsPerTruck) {
                    tl = {
                        total_order: (pal.total_order || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        at_manufacturer: (pal.at_manufacturer || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        on_water: (pal.on_water || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        cleared_customs: (pal.cleared_customs || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        in_transit_to_warehouse: (pal.in_transit_to_warehouse || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        in_warehouse: (pal.in_warehouse || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        in_transit_to_project: (pal.in_transit_to_project || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        delivered: (pal.delivered || 0) / window.conversionAvailability.avgPalletsPerTruck,
                    };
                } else if (window.conversionAvailability.modulesPerTruckAvailable && window.conversionAvailability.avgModulesPerTruck) {
                    tl = {
                        total_order: (data.total_order || 0) / window.conversionAvailability.avgModulesPerTruck,
                        at_manufacturer: (data.at_manufacturer || 0) / window.conversionAvailability.avgModulesPerTruck,
                        on_water: (data.on_water || 0) / window.conversionAvailability.avgModulesPerTruck,
                        cleared_customs: (data.cleared_customs || 0) / window.conversionAvailability.avgModulesPerTruck,
                        in_transit_to_warehouse: (data.in_transit_to_warehouse || 0) / window.conversionAvailability.avgModulesPerTruck,
                        in_warehouse: (data.in_warehouse || 0) / window.conversionAvailability.avgModulesPerTruck,
                        in_transit_to_project: (data.in_transit_to_project || 0) / window.conversionAvailability.avgModulesPerTruck,
                        delivered: (data.delivered || 0) / window.conversionAvailability.avgModulesPerTruck,
                    };
                }
                let idx = 1;
                if (!tl) {
                    while (idx < cells.length) { cells[idx++].textContent = 'N/A'; }
                } else {
                    cells[idx++].textContent = formatNumber(tl.total_order, 1);
                    cells[idx++].textContent = formatNumber(tl.at_manufacturer || 0, 1);
                    if (data.on_water > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.on_water || 0, 1);
                    if (data.cleared_customs > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.cleared_customs || 0, 1);
                    if (data.in_transit_to_warehouse > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.in_transit_to_warehouse || 0, 1);
                    if (data.in_warehouse > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.in_warehouse || 0, 1);
                    if (data.in_transit_to_project > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.in_transit_to_project || 0, 1);
                    if (cells[idx]) cells[idx].textContent = formatNumber(tl.delivered || 0, 1);
                }
            } else {
                // Default (MWs/modules) with no damaged parentheses
                cells[1].textContent = formatNumber(data.total_order, decimals);
                cells[2].textContent = formatNumber((data.at_manufacturer || 0), decimals);
                let cellIndex = 3;
                if (data.on_water > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.on_water, decimals); }
                if (data.cleared_customs > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.cleared_customs, decimals); }
                if (data.in_transit_to_warehouse > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.in_transit_to_warehouse, decimals); }
                if (data.in_warehouse > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.in_warehouse, decimals); }
                if (data.in_transit_to_project > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.in_transit_to_project, decimals); }
                if (cells[cellIndex]) { cells[cellIndex].textContent = formatNumber(data.delivered, decimals); }
            }
        }
    }

    // Update sub rows in table1 (Next 5 Weeks)
    const subRows1 = table1.querySelectorAll('tr.delivery-row');
    subRows1.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 3) {
            const wattageLabel = cells[0].textContent;
            const subModules = (window.originalTableData.modules.sub_rows || {})[wattageLabel];
            const subGeneric = (data.sub_rows || {})[wattageLabel];
            if (filterType === 'pallets') {
                const wMatch = wattageLabel.match(/(\d+)W/);
                const w = wMatch ? parseInt(wMatch[1], 10) : null;
                const mppMap = window.conversionAvailability.wattageModulesPerPallet || {};
                const mpp = (w && mppMap[w]) ? mppMap[w] : window.conversionAvailability.avgModulesPerPallet;
                if (subModules && mpp && window.conversionAvailability.modulesPerPalletAvailable) {
                    cells[1].textContent = formatNumber(subModules.total_order / mpp, 0);
                    cells[2].textContent = formatNumber(subModules.delivered / mpp, 0);
                    for (let i = 0; i < subModules.weeks.length && i + 3 < cells.length; i++) {
                        cells[i + 3].textContent = formatNumber(subModules.weeks[i] / mpp, 0);
                    }
                } else {
                    for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                }
            } else if (filterType === 'truckloads') {
                const wMatch = wattageLabel.match(/(\d+)W/);
                const w = wMatch ? parseInt(wMatch[1], 10) : null;
                const mppMap = window.conversionAvailability.wattageModulesPerPallet || {};
                const mpp = (w && mppMap[w]) ? mppMap[w] : window.conversionAvailability.avgModulesPerPallet;
                if (subModules) {
                    if (window.conversionAvailability.palletsPerTruckAvailable && window.conversionAvailability.avgPalletsPerTruck && (mpp || window.conversionAvailability.modulesPerPalletAvailable)) {
                        const ppt = window.conversionAvailability.avgPalletsPerTruck;
                        const denom = (mpp && mpp > 0) ? mpp : null;
                        if (denom) {
                            cells[1].textContent = formatNumber((subModules.total_order / denom) / ppt, 1);
                            cells[2].textContent = formatNumber((subModules.delivered / denom) / ppt, 1);
                            for (let i = 0; i < subModules.weeks.length && i + 3 < cells.length; i++) {
                                cells[i + 3].textContent = formatNumber((subModules.weeks[i] / denom) / ppt, 1);
                            }
                        } else {
                            for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                        }
                    } else if (window.conversionAvailability.modulesPerTruckAvailable && window.conversionAvailability.avgModulesPerTruck) {
                        const mpt = window.conversionAvailability.avgModulesPerTruck;
                        cells[1].textContent = formatNumber(subModules.total_order / mpt, 1);
                        cells[2].textContent = formatNumber(subModules.delivered / mpt, 1);
                        for (let i = 0; i < subModules.weeks.length && i + 3 < cells.length; i++) {
                            cells[i + 3].textContent = formatNumber(subModules.weeks[i] / mpt, 1);
                        }
                    } else {
                        for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                    }
                }
            } else if (subGeneric) {
                // Default (MWs/modules) direct
                cells[1].textContent = formatNumber(subGeneric.total_order, decimals);
                cells[2].textContent = formatNumber(subGeneric.delivered, decimals);
                for (let i = 0; i < subGeneric.weeks.length && i + 3 < cells.length; i++) {
                    cells[i + 3].textContent = formatNumber(subGeneric.weeks[i], decimals);
                }
            }
        }
    });

    // Update sub rows in Module Delivery Status table
    const subRows2 = table2.querySelectorAll('tr.status-row');
    subRows2.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 3) {
            const wattageLabel = cells[0].textContent;
            const subData = data.sub_rows_status[wattageLabel];
            if (subData) {
                if (filterType === 'pallets') {
                    const palSub = window.actualStatusData.pallets.sub[wattageLabel] || {};
                    let idx2 = 1;
                    cells[idx2++].textContent = formatNumber(palSub.total_order || 0, 0);
                    cells[idx2++].textContent = formatNumber(palSub.at_manufacturer || 0, 0);
                    if (data.on_water > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.on_water || 0, 0);
                    if (data.cleared_customs > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.cleared_customs || 0, 0);
                    if (data.in_transit_to_warehouse > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.in_transit_to_warehouse || 0, 0);
                    if (data.in_warehouse > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.in_warehouse || 0, 0);
                    if (data.in_transit_to_project > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.in_transit_to_project || 0, 0);
                    if (cells[idx2]) cells[idx2].textContent = formatNumber(palSub.delivered || 0, 0);
                } else if (filterType === 'truckloads') {
                    let idx2 = 1;
                    let source;
                    if (window.conversionAvailability.palletsPerTruckAvailable && window.conversionAvailability.avgPalletsPerTruck) {
                        const palSub = window.actualStatusData.pallets.sub[wattageLabel] || {};
                        source = {
                            total_order: (palSub.total_order || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            at_manufacturer: (palSub.at_manufacturer || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            on_water: (palSub.on_water || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            cleared_customs: (palSub.cleared_customs || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            in_transit_to_warehouse: (palSub.in_transit_to_warehouse || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            in_warehouse: (palSub.in_warehouse || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            in_transit_to_project: (palSub.in_transit_to_project || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            delivered: (palSub.delivered || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        };
                    } else if (window.conversionAvailability.modulesPerTruckAvailable && window.conversionAvailability.avgModulesPerTruck) {
                        source = {
                            total_order: (subData.total_order || 0) / window.conversionAvailability.avgModulesPerTruck,
                            at_manufacturer: (subData.at_manufacturer || 0) / window.conversionAvailability.avgModulesPerTruck,
                            on_water: (subData.on_water || 0) / window.conversionAvailability.avgModulesPerTruck,
                            cleared_customs: (subData.cleared_customs || 0) / window.conversionAvailability.avgModulesPerTruck,
                            in_transit_to_warehouse: (subData.in_transit_to_warehouse || 0) / window.conversionAvailability.avgModulesPerTruck,
                            in_warehouse: (subData.in_warehouse || 0) / window.conversionAvailability.avgModulesPerTruck,
                            in_transit_to_project: (subData.in_transit_to_project || 0) / window.conversionAvailability.avgModulesPerTruck,
                            delivered: (subData.delivered || 0) / window.conversionAvailability.avgModulesPerTruck,
                        };
                    }
                    if (!source) {
                        while (idx2 < cells.length) { cells[idx2++].textContent = 'N/A'; }
                    } else {
                        cells[idx2++].textContent = formatNumber(source.total_order, 1);
                        cells[idx2++].textContent = formatNumber(source.at_manufacturer || 0, 1);
                        if (data.on_water > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.on_water || 0, 1);
                        if (data.cleared_customs > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.cleared_customs || 0, 1);
                        if (data.in_transit_to_warehouse > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.in_transit_to_warehouse || 0, 1);
                        if (data.in_warehouse > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.in_warehouse || 0, 1);
                        if (data.in_transit_to_project > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.in_transit_to_project || 0, 1);
                        if (cells[idx2]) cells[idx2].textContent = formatNumber(source.delivered || 0, 1);
                    }
                } else {
                    cells[1].textContent = formatNumber(subData.total_order, decimals);
                    cells[2].textContent = formatNumber((subData.at_manufacturer || 0), decimals);
                    let idx = 3;
                    if (data.on_water > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.on_water || 0), decimals); }
                    if (data.cleared_customs > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.cleared_customs || 0), decimals); }
                    if (data.in_transit_to_warehouse > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.in_transit_to_warehouse || 0), decimals); }
                    if (data.in_warehouse > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.in_warehouse || 0), decimals); }
                    if (data.in_transit_to_project > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.in_transit_to_project || 0), decimals); }
                    if (cells[idx]) cells[idx].textContent = formatNumber(subData.delivered, decimals);
                }
            }
        }
    });

    if (window.updatePieChart) {
        window.updatePieChart(filterType);
    }
}

function formatNumber(num, decimals) {
    return Number(num).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

// Admin-only conversion prompt helpers
function openConversionModal(type) {
    const modal = document.getElementById('conversionModal');
    const title = document.getElementById('conversionModalTitle');
    const body = document.getElementById('conversionModalBody');
    if (!modal || !title || !body) return;
    if (type === 'mpp') {
        title.textContent = 'Modules per Pallet not currently listed';
        body.innerHTML = '<label>Modules per Pallet</label>'+
            '<input type="number" id="inputModulesPerPallet" min="1" style="width:100%;padding:8px;margin-top:6px;">'+
            '<p style="margin-top:10px;color:#666;">Temporary for display. Update Module Batch to persist.</p>';
        modal.setAttribute('data-type','mpp');
    } else {
        title.textContent = 'Truckload conversion not currently listed';
        body.innerHTML = '<label>Pallets per Truck (preferred)</label>'+
            '<input type="number" id="inputPalletsPerTruck" min="1" style="width:100%;padding:8px;margin-top:6px;">'+
            '<div style="margin:10px 0;text-align:center;color:#888;">— or —</div>'+
            '<label>Modules per Truck</label>'+
            '<input type="number" id="inputModulesPerTruck" min="1" style="width:100%;padding:8px;margin-top:6px;">'+
            '<p style="margin-top:10px;color:#666;">Temporary for display. Update Module Batch to persist.</p>';
        modal.setAttribute('data-type','truck');
    }
    modal.style.display = 'block';
}
function closeConversionModal(){
    const modal = document.getElementById('conversionModal');
    if (modal) modal.style.display = 'none';
}
function saveConversionModal(){
    const modal = document.getElementById('conversionModal');
    if (!modal) return;
    const type = modal.getAttribute('data-type');
    if (type === 'mpp') {
        const val = parseInt(document.getElementById('inputModulesPerPallet').value || '0', 10);
        if (val > 0) {
            window.conversionAvailability.modulesPerPalletAvailable = true;
            window.conversionAvailability.avgModulesPerPallet = val;
        }
    } else {
        const ppt = parseInt(document.getElementById('inputPalletsPerTruck').value || '0', 10);
        const mpt = parseInt(document.getElementById('inputModulesPerTruck').value || '0', 10);
        if (ppt > 0) {
            window.conversionAvailability.palletsPerTruckAvailable = true;
            window.conversionAvailability.avgPalletsPerTruck = ppt;
        } else if (mpt > 0) {
            window.conversionAvailability.modulesPerTruckAvailable = true;
            window.conversionAvailability.avgModulesPerTruck = mpt;
        }
    }
    closeConversionModal();
    // Re-apply current filter to refresh
    syncFiltersToState();
}

// Customer Shipping Modal functionality
function showCustomerShippingModal(status) {
    const modal = document.getElementById('customerShippingModal');
    const title = document.getElementById('customerShippingModalTitle');
    const content = document.getElementById('customerShippingModalContent');
    
    if (!modal || !title || !content) {
        console.error('Customer shipping modal elements not found');
        return;
    }
    
    title.textContent = status + ' - Details';
    content.innerHTML = generateCustomerShippingContent(status);
    modal.style.display = 'block';
}

function closeCustomerShippingModal() {
    const modal = document.getElementById('customerShippingModal');
    if(modal) modal.style.display = 'none';
}

function generateCustomerShippingContent(status) {
    const shippingBreakdown = <?php echo json_encode($detailed_breakdown ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>;
    let html = '<div>';
    let has = false;
    
    // Handle special case for "Delivered" status
    if(status === 'Delivered') {
        has = true;
        const totalDeliveredRaw = <?php echo (int)($delivered_raw_total ?? 0); ?>;
        const totalPallets = Math.round(totalDeliveredRaw / 30);
        
        // Calculate MWs
        const wattages = <?php echo json_encode($wattages ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
        let totalMWs = 0;
        if (wattages.length > 0 && totalDeliveredRaw > 0) {
            const avgWattage = wattages.reduce((a, b) => a + b) / wattages.length;
            totalMWs = ((totalDeliveredRaw * avgWattage) / 1000000).toFixed(2);
        }
        
        const palletDisplay = totalPallets;
        const moduleDisplay = totalDeliveredRaw.toLocaleString();
        
        html += `<div style="margin-bottom:20px;padding:20px;background:#e8f5e8;border-radius:12px;border-left:4px solid #28a745;">` +
               `<h4 style="margin-top:0;color:#28a745;">🎉 Delivered to Project</h4>` +
               `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin:15px 0;">` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${palletDisplay}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Pallets</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${moduleDisplay}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Modules</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${totalMWs}</div>` +
               `<div style="font-size:0.9rem;color:#666;">MWs</div></div>` +
               `</div>`;
        
        // Show wattage breakdown
        const deliveredBreakdown = <?php echo json_encode($delivered_by_wattage ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
        if(deliveredBreakdown.length > 0) {
            html += '<div style="margin-top:20px;"><h5 style="color:#28a745;">Wattage Breakdown:</h5><ul style="list-style:none;padding:0;">';
            deliveredBreakdown.forEach(function(item) {
                const mws = ((item.modules * item.wattage) / 1000000).toFixed(2);
                let breakdownText = `${item.wattage}W: ${item.pallets} pallets • ${item.modules.toLocaleString()} modules • ${mws} MWs`;
                if(item.damaged_pallets > 0) {
                    breakdownText += ` (${item.damaged_pallets} damaged pallets, ${item.damaged_modules.toLocaleString()} damaged modules)`;
                }
                html += `<li style="padding:8px 0;border-bottom:1px solid #eee;">${breakdownText}</li>`;
            });
            html += '</ul></div>';
        }
        
        html += `<div style="text-align:center;margin-top:20px;">` +
               `<a href="view_project.php?project_id=<?php echo $project_id; ?>&status_filter=Delivered" class="customer-modal-btn">View Deliveries</a>` +
               `</div>`;
        html += '</div>';
    } else if (status === 'Exceptions') {
        // Handle Exceptions (Damaged Pallets)
        has = true;
        const exceptionsData = {
            damaged: <?php echo ($status_totals['Damaged']['pallets'] ?? 0); ?>,
            damaged_modules: <?php echo ($status_totals['Damaged']['modules'] ?? 0); ?>
        };
        
        const totalExceptionPallets = exceptionsData.damaged;
        const totalExceptionModules = exceptionsData.damaged_modules;
        
        // Calculate MWs
        const wattages = <?php echo json_encode($wattages ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
        let totalMWs = 0;
        if (wattages.length > 0 && totalExceptionModules > 0) {
            const avgWattage = wattages.reduce((a, b) => a + b) / wattages.length;
            totalMWs = ((totalExceptionModules * avgWattage) / 1000000).toFixed(2);
        }
        
        html += `<div style="margin-bottom:20px;padding:20px;background:#fff3e0;border-radius:12px;border-left:4px solid #f57c00;">` +
               `<h4 style="margin-top:0;color:#e65100;">⚠️ Module Exceptions</h4>` +
               `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin:15px 0;">` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#f57c00;">${totalExceptionPallets}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Pallets</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#f57c00;">${totalExceptionModules.toLocaleString()}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Modules</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#f57c00;">${totalMWs}</div>` +
               `<div style="font-size:0.9rem;color:#666;">MWs</div></div>` +
               `</div>`;
        
        // Show exception type breakdown
        if (exceptionsData.damaged > 0) {
            html += '<div style="margin-top:20px;"><h5 style="color:#e65100;">Exception Breakdown:</h5><ul style="list-style:none;padding:0;">';
            html += `<li style="padding:12px;margin-bottom:8px;background:#ffebee;border-radius:8px;border-left:3px solid #d32f2f;">` +
                   `<strong>Damaged:</strong> ${exceptionsData.damaged} pallets • ${exceptionsData.damaged_modules.toLocaleString()} modules</li>`;
            html += '</ul></div>';
        }
        
        html += `<div style="text-align:center;margin-top:20px;">` +
               `<a href="warranty.php?project_id=<?php echo $project_id; ?>" class="customer-modal-btn" style="background:#f57c00;border-color:#f57c00;">View Exceptions</a>` +
               `</div>`;
        html += '</div>';
    } else {
        // Handle other shipping statuses
        for(const key in shippingBreakdown){
            if(key.includes(status)){
                has = true;
                const data = shippingBreakdown[key];
                
                // Calculate MWs and truckloads
                const wattages = <?php echo json_encode($wattages ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
                let totalMWs = 0;
                if (wattages.length > 0 && data.total_modules > 0) {
                    const avgWattage = wattages.reduce((a, b) => a + b) / wattages.length;
                    totalMWs = ((data.total_modules * avgWattage) / 1000000).toFixed(2);
                }
                const avgPPT = <?php echo ($average_pallets_per_truck !== null && $average_pallets_per_truck > 0) ? (float)$average_pallets_per_truck : 'null'; ?>;
                const truckloads = avgPPT ? (data.pallet_count / avgPPT).toFixed(1) : 'N/A';
                
                html += `<div style="margin-bottom:20px;padding:20px;background:#f8f9fa;border-radius:12px;border-left:4px solid #488C9A;">`+
                       `<h4 style="margin-top:0;color:#488C9A;">${key}</h4>`+
                       `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:15px;margin:15px 0;">` +
                       `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
                       `<div style="font-size:1.5rem;font-weight:700;color:#488C9A;">${data.pallet_count}</div>` +
                       `<div style="font-size:0.8rem;color:#666;">Pallets</div></div>` +
                       `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
                       `<div style="font-size:1.5rem;font-weight:700;color:#488C9A;">${data.total_modules.toLocaleString()}</div>` +
                       `<div style="font-size:0.8rem;color:#666;">Modules</div></div>` +
                       `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
                       `<div style="font-size:1.5rem;font-weight:700;color:#488C9A;">${totalMWs}</div>` +
                       `<div style="font-size:0.8rem;color:#666;">MWs</div></div>` +
                       `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
                       `<div style="font-size:1.5rem;font-weight:700;color:#488C9A;">${truckloads}</div>` +
                       `<div style="font-size:0.8rem;color:#666;">Truckloads</div></div>` +
                       `</div>`;
                
                if(data.wattage_breakdown && Object.keys(data.wattage_breakdown).length>0){
                    html+='<div style="margin-top:20px;"><h5 style="color:#488C9A;">Wattage Breakdown:</h5><ul style="list-style:none;padding:0;">';
                    for(const w in data.wattage_breakdown){
                        const d = data.wattage_breakdown[w];
                        const mws = ((d.modules * parseFloat(w)) / 1000000).toFixed(2);
                        html+=`<li style="padding:8px 0;border-bottom:1px solid #eee;">${w}W: ${d.pallets} pallets • ${d.modules.toLocaleString()} modules • ${mws} MWs</li>`;
                    }
                    html+='</ul></div>';
                }
                
                // Add appropriate action buttons
                if(status === 'At Manufacturer') {
                    html += `<div style="text-align:center;margin-top:20px;">` +
                           `<a href="manage_pallets.php?project_id=<?php echo $project_id; ?>&status_filter=At%20Manufacturer" class="customer-modal-btn">View Pallets</a>` +
                           `</div>`;
                } else if(status === 'In Transit to Warehouse' || status === 'In Transit to Project') {
                    html += `<div style="text-align:center;margin-top:20px;">` +
                           `<a href="view_project.php?project_id=<?php echo $project_id; ?>&status_filter=${encodeURIComponent(status)}" class="customer-modal-btn">View Shipments</a>` +
                           `</div>`;
                } else if(status === 'In Warehouse') {
                    if(data.warehouse_id) {
                        html += `<div style="text-align:center;margin-top:20px;">` +
                               `<a href="warehouse_info.php?project_id=<?php echo $project_id; ?>&warehouse_id=${data.warehouse_id}" class="customer-modal-btn">View Inventory</a>` +
                               `</div>`;
                    } else {
                        html += `<div style="text-align:center;margin-top:20px;">` +
                               `<a href="warehouse_info.php?project_id=<?php echo $project_id; ?>" class="customer-modal-btn">View Inventory</a>` +
                               `</div>`;
                    }
                }
                html+='</div>';
            }
        }
        
        if(!has){html+='<p style="text-align:center;color:#666;font-style:italic;">No data available.</p>';}
    }
    
    html+='</div>';
    return html;
}

// Initialize customer filters when page loads
document.addEventListener('DOMContentLoaded', function() {
    initializeCustomerUnitFilters();
    syncFiltersToState(); // Set initial state
    
    // Initialize timeline remaining text with current filter
    updateTimelineRemainingText(currentFilter);
    
    // Handle large numbers in shipping boxes
    handleLargeNumbers();
});

// Function to detect and handle large numbers in shipping boxes
function handleLargeNumbers() {
    const shippingBoxes = document.querySelectorAll('.shipping-box .status-count, .shipping-box-customer .status-count');
    
    shippingBoxes.forEach(function(countElement) {
        const text = countElement.textContent || countElement.innerText;
        const numericText = text.replace(/[^\d]/g, ''); // Remove non-numeric characters
        
        // If number has 6+ digits (like 204540), mark it as large
        if (numericText.length >= 6) {
            countElement.setAttribute('data-large-number', 'true');
            
            // Also reduce padding on parent container for very large numbers
            const parentBox = countElement.closest('.shipping-box, .shipping-box-customer');
            if (parentBox && numericText.length >= 7) {
                parentBox.style.padding = '15px 8px';
            }
        }
    });
}

// Prepare costPie + budgetLineChart (for regular users)
var pieChartDataFinancial = <?php echo json_encode($pieChartDataFinancial ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]';?>;
var dateLabelsForBudget   = <?php echo $dateLabelsForBudget ?: '[]';?>;
var budgetLineData        = <?php echo $budgetLineChartDataJSON ?: '[]';?>;

function initializeFinancialCharts(){
    // Cost Breakdown Pie
    var costPieEl = document.getElementById('costPieChart');
    if (!costPieEl || costPieEl.chartInitialized) return; // Exit if element doesn't exist or chart already created
    
    var costPie = costPieEl.getContext('2d');
    var costPieLabels = Object.keys(pieChartDataFinancial);
    var costPieValues = Object.values(pieChartDataFinancial);

    var colorMap = {
        'Freight Cost': '#488C9A',
        'Warehousing':   '#293E4C',
        'Accessorial':   '#fbb040',
        'Solterra Fee':  '#BFBFBF'
    };
    var backgroundColors = costPieLabels.map(function(lbl){
        return colorMap[lbl] || '#000000';
    });

    new Chart(costPie,{
        type:'pie',
        data:{
            labels: costPieLabels,
            datasets:[{
                data: costPieValues,
                backgroundColor: backgroundColors
            }]
        },
        options:{
            title:{display:true, text:'Cost Breakdown'},
            tooltips:{
                callbacks:{
                    label:function(tooltipItem, data){
                        var val=data.datasets[0].data[tooltipItem.index];
                        var lbl=data.labels[tooltipItem.index];
                        return lbl+': $'+ parseFloat(val).toFixed(2);
                    }
                }
            }
        }
    });
    costPieEl.chartInitialized = true;

    // Forecasted vs Actual cost line chart
    var ctxBudgetEl = document.getElementById('budgetLineChart');
    if (!ctxBudgetEl || ctxBudgetEl.chartInitialized) return; // Exit if element doesn't exist or chart already created
    
    var ctxBudget = ctxBudgetEl.getContext('2d');
    var antCost = budgetLineData.anticipated_cost;
    var actCost = budgetLineData.actual_cost;

    new Chart(ctxBudget,{
        type:'line',
        data:{
            labels: dateLabelsForBudget,
            datasets:[
                {
                    label:'Anticipated Cost',
                    data: antCost,
                    borderColor:'#488C9A',
                    fill:false,
                    borderDash:[5,5],
                    pointRadius:0
                },
                {
                    label:'Actual Cost',
                    data: actCost,
                    borderColor:'#293E4C',
                    fill:false,
                    pointRadius:0,
                    spanGaps:false
                }
            ]
        },
        options:{
            interaction:{ mode:'index', intersect:false },
            responsive:true,
            animation:false,
            scales:{
                x:{
                    type:'time',
                    time:{
                        parser:'yyyy-MM-dd',
                        tooltipFormat:'PP',
                        unit:'month',
                        displayFormats:{month:'MMM yyyy'}
                    },
                    title:{display:true, text:'Date'},
                    ticks:{ maxRotation:70, minRotation:70, autoSkip:true, autoSkipPadding:12 }
                },
                y:{
                    beginAtZero:true,
                    ticks:{ callback:function(val){return '$'+Number(val).toFixed(2);} },
                    title:{display:true, text:'Cost (USD)'}
                }
            },
            plugins:{
                tooltip:{
                    callbacks:{
                        label:function(context){
                            var label = context.dataset.label || '';
                            var val = context.parsed.y || 0;
                            return label+': $'+ Number(val).toFixed(2);
                        }
                    }
                }
            }
        }
    });
    ctxBudgetEl.chartInitialized = true;
}
<?php endif; ?>

<?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
// Shipping Filter functionality
function initializeShippingFilters() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    const shippingBoxes = document.querySelectorAll('.shipping-box');
    
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const filterType = this.getAttribute('data-filter');
            
            // Update active button
            filterButtons.forEach(b => {
                b.style.background = 'transparent';
                b.style.color = '#293E4C';
                b.classList.remove('active');
            });
            
            this.style.background = '#488C9A';
            this.style.color = 'white';
            this.classList.add('active');
            
            // Update all shipping boxes
            updateShippingBoxes(filterType);
            
            // Handle large numbers after updating
            setTimeout(handleLargeNumbers, 100);
        });
    });
}

function updateShippingBoxes(filterType) {
    const shippingBoxes = document.querySelectorAll('.shipping-box');
    
    shippingBoxes.forEach(box => {
        const statusCount = box.querySelector('.status-count');
        const statusUnit = box.querySelector('.status-unit');
        
        if (statusCount && statusUnit) {
            let value, unit;
            
            switch(filterType) {
                case 'modules':
                    value = parseInt(box.getAttribute('data-modules') || 0);
                    unit = 'modules';
                    break;
                case 'truckloads':
                    value = parseFloat(box.getAttribute('data-truckloads') || 0);
                    unit = 'truckloads';
                    break;
                case 'mws':
                    value = parseFloat(box.getAttribute('data-mws') || 0);
                    unit = 'MWs';
                    break;
                case 'pallets':
                default:
                    value = parseInt(box.getAttribute('data-pallets') || 0);
                    unit = 'pallets';
                    break;
            }
            
            // Format the value based on type
            let displayText;
            if (isNaN(value)) {
                displayText = 'N/A';
            } else if (filterType === 'truckloads' || filterType === 'mws') {
                const decimals = filterType === 'mws' ? 2 : 1;
                displayText = value % 1 === 0 ? value.toString() : value.toFixed(decimals);
            } else {
                displayText = Math.round(value).toLocaleString();
            }

            statusCount.textContent = displayText;
            statusUnit.textContent = unit;

            // Add class for large numbers to shrink font
            statusCount.classList.remove('large-number', 'very-large-number');
            if (displayText.length >= 7) {
                statusCount.classList.add('very-large-number');
            } else if (displayText.length >= 5) {
                statusCount.classList.add('large-number');
            }
        }
    });
}

// Initialize shipping filters when page loads
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.shipping-filters')) {
        initializeShippingFilters();
    }
    
    // Initialize admin unit filters and timeline remaining text
    initializeAdminUnitFilters();
    updateTimelineRemainingTextAdmin();
    
    // Initialize shipping boxes with default filter (MWs)
    updateShippingBoxes('mws');
});

// Global filter state for admin
let currentAdminFilter = 'mws';

// Initialize admin unit filters functionality
function initializeAdminUnitFilters() {
    const filterSections = document.querySelectorAll('.unit-filters');
    
    filterSections.forEach(section => {
        const filterButtons = section.querySelectorAll('.unit-filter-btn');
        
        filterButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const filterType = this.getAttribute('data-unit');
                
                // Update global filter state
                currentAdminFilter = filterType;
                
                // Update active button states in all filter sections
                filterSections.forEach(fs => {
                    fs.querySelectorAll('.unit-filter-btn').forEach(b => {
                        b.classList.remove('active');
                        if (b.getAttribute('data-unit') === filterType) {
                            b.classList.add('active');
                        }
                    });
                });
                
                // Update timeline remaining text
                updateTimelineRemainingTextAdmin();
                
                // Update admin shipping boxes
                updateShippingBoxes(filterType);
                
                // Handle large numbers after updating
                setTimeout(handleLargeNumbers, 100);
            });
        });
    });
}

// Admin version of timeline remaining text update (with filters)
function updateTimelineRemainingTextAdmin() {
    const timelineTexts = document.querySelectorAll('.timeline-remaining-text');
    if (!timelineTexts.length) return;
    
    // Check if project is completed
    const isCompleted = <?php echo $step5_completed ? 'true' : 'false'; ?>;
    if (isCompleted) return; // Don't update if project is already completed
    
    // Get project data for calculations  
    const totalModules = <?php echo $total_raw_modules; ?>;
    const deliveredModules = <?php echo $delivered_raw_total; ?>;
    const projectSizeMW = <?php echo number_format($project_size_mw, 2); ?>;
    
    // Calculate delivered MW (approximate based on delivered/total ratio)
    const deliveryRatio = totalModules > 0 ? (deliveredModules / totalModules) : 0;
    const deliveredMW = projectSizeMW * deliveryRatio;
    
    // Calculate totals and remaining for each filter type
    let remaining, unit;
    
    switch(currentAdminFilter) {
        case 'modules':
            remaining = Math.max(0, totalModules - deliveredModules);
            unit = 'modules';
            break;
            
        case 'mws':
            remaining = projectSizeMW - deliveredMW;
            unit = 'MWs';
            remaining = Math.max(0, parseFloat(remaining.toFixed(2)));
            break;
            
        case 'pallets':
            // Use actual pallet counts from the database
            const actualPalletData = <?php echo json_encode($pallets_status_main ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>;
            const totalActualPallets = actualPalletData.total_order;
            const deliveredActualPallets = actualPalletData.delivered;
            remaining = Math.max(0, totalActualPallets - deliveredActualPallets);
            unit = 'pallets';
            break;
            
        case 'truckloads':
            // Estimate truckloads based on modules (using average modules per truck if available)  
            const avgModulesPerTruck = <?php echo ($weighted_avg_modules_per_truck !== null && $weighted_avg_modules_per_truck > 0) ? (int)$weighted_avg_modules_per_truck : 500; ?>;
            const totalTrucks = Math.ceil(totalModules / avgModulesPerTruck);
            const deliveredTrucks = Math.floor(deliveredModules / avgModulesPerTruck);
            remaining = Math.max(0, totalTrucks - deliveredTrucks);
            unit = 'truckloads';
            break;
            
        default:
            remaining = Math.max(0, totalModules - deliveredModules);
            unit = 'modules';
    }
    
    // Update all timeline remaining text elements
    timelineTexts.forEach(element => {
        const countSpan = element.querySelector('.remaining-count');
        const unitSpan = element.querySelector('.remaining-unit');
        
        if (countSpan && unitSpan) {
            countSpan.textContent = remaining.toLocaleString('en-US');
            unitSpan.textContent = unit;
        }
    });
}

<?php endif; ?>

// Dropdown functionality
function toggleCustomerModulesDropdown() {
    var dropdown = document.getElementById("customerModulesDropdown");
    var dropdownBtn = document.querySelector("#customer-buttons .dropdown:first-child .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleCustomerDeliveriesDropdown() {
    var dropdown = document.getElementById("customerDeliveriesDropdown");
    var dropdownBtn = document.querySelector("#customer-buttons .dropdown:nth-child(2) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleCustomerReportsDropdown() {
    var dropdown = document.getElementById("customerReportsDropdown");
    var dropdownBtn = document.querySelector("#customer-buttons .dropdown:nth-child(4) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleCustomerDocumentsDropdown() {
    var dropdown = document.getElementById("customerDocumentsDropdown");
    var dropdownBtn = document.querySelector("#customer-buttons .dropdown:nth-child(5) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleModulesDropdown() {
    var dropdown = document.getElementById("modulesDropdown");
    var dropdownBtn = document.querySelector("#admin-buttons .dropdown:first-child .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleAdminDeliveriesDropdown() {
    var dropdown = document.getElementById("adminDeliveriesDropdown");
    var dropdownBtn = document.querySelector("#admin-buttons .dropdown:nth-child(2) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleAdminDocumentsDropdown() {
    var dropdown = document.getElementById("adminDocumentsDropdown");
    var dropdownBtn = document.querySelector("#admin-buttons .dropdown:nth-child(3) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleMainDeliveriesDropdown() {
    // This function handles the main deliveries dropdown if it exists
    var dropdown = document.getElementById("mainDeliveriesDropdown");
    if (dropdown) {
        dropdown.classList.toggle("show");
    }
}

// Close dropdown when clicking outside
window.onclick = function(event) {
    if (!event.target.matches('.dropdown-btn') && !event.target.matches('.dropdown-arrow')) {
        var dropdowns = document.getElementsByClassName("dropdown-content");
        var dropdownBtns = document.getElementsByClassName("dropdown-btn");
        
        for (var i = 0; i < dropdowns.length; i++) {
            var openDropdown = dropdowns[i];
            if (openDropdown.classList.contains('show')) {
                openDropdown.classList.remove('show');
            }
        }
        
        for (var i = 0; i < dropdownBtns.length; i++) {
            dropdownBtns[i].classList.remove('active');
        }
    }
    
    <?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
    // NOTE: Removed modal closing on outside click to prevent glitches
    // Users must now use the "x" button to close modals
    
    // Close dropdowns when clicking outside
    if (!event.target.closest('.project-actions-dropdown')) {
        document.getElementById('projectActionsDropdown').style.display = 'none';
    }
    if (!event.target.closest('.module-actions-dropdown')) {
        document.getElementById('moduleActionsDropdown').style.display = 'none';
    }
    if (!event.target.closest('.batch-actions-dropdown')) {
        document.querySelectorAll('.batch-actions-content').forEach(function(content) {
            content.style.display = 'none';
        });
    }
    <?php endif; ?>
}

<?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
// Wattage Breakdown Toggle
function toggleWattageBreakdown(event) {
    event.stopPropagation();
    const box = event.currentTarget;
    const dropdown = box.querySelector('.wattage-breakdown');
    const icon = box.querySelector('.wattage-toggle-icon');

    if (dropdown) {
        const isOpen = dropdown.style.display === 'block';
        dropdown.style.display = isOpen ? 'none' : 'block';
        if (icon) {
            icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
        }
    }
}

// Close wattage breakdown when clicking outside
document.addEventListener('click', function(event) {
    const modulesBox = document.querySelector('.modules-ordered-box');
    if (modulesBox && !modulesBox.contains(event.target)) {
        const dropdown = modulesBox.querySelector('.wattage-breakdown');
        const icon = modulesBox.querySelector('.wattage-toggle-icon');
        if (dropdown) {
            dropdown.style.display = 'none';
        }
        if (icon) {
            icon.style.transform = 'rotate(0deg)';
        }
    }
});

// Project Actions Functions
function toggleProjectActions() {
    const dropdown = document.getElementById('projectActionsDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

// Module Actions Functions
function toggleModuleActions() {
    const dropdown = document.getElementById('moduleActionsDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

// Batch Actions Functions
function toggleBatchActions(batchId) {
    const dropdown = document.getElementById('batchActionsDropdown_' + batchId);
    // Close all other batch dropdowns
    document.querySelectorAll('.batch-actions-content').forEach(function(content) {
        if (content.id !== 'batchActionsDropdown_' + batchId) {
            content.style.display = 'none';
        }
    });
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

// Delete Project Functions
let deleteProjectId = null;
let deleteProjectName = null;

function confirmDeleteProject(projectId, projectName) {
    deleteProjectId = projectId;
    deleteProjectName = projectName;
    document.getElementById('deleteModalText').innerHTML = 
        `Are you sure you want to delete the project "<strong>${projectName}</strong>"?<br><br>
        This will permanently delete:<br>
        • All module batches and pallets<br>
        • All deliveries and shipments<br>
        • All project data and documents<br><br>
        <strong>This action cannot be undone.</strong>`;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    deleteProjectId = null;
    deleteProjectName = null;
}

function confirmDelete() {
    if (!deleteProjectId) return;
    
    // Show loading state
    const deleteBtn = document.querySelector('.btn-delete');
    deleteBtn.disabled = true;
    deleteBtn.textContent = 'Deleting...';
    
    // Send delete request
    fetch('delete_project_cascade.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'project_id=' + deleteProjectId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Project "' + deleteProjectName + '" deleted successfully.');
            window.location.href = 'manage_projects.php';
        } else {
            alert('Error deleting project: ' + (data.error || 'Unknown error'));
            deleteBtn.disabled = false;
            deleteBtn.textContent = 'Delete Project';
        }
    })
    .catch(error => {
        alert('Error deleting project: ' + error.message);
        deleteBtn.disabled = false;
        deleteBtn.textContent = 'Delete Project';
    });
}

// Add Module Modal Functions
function openAddModuleModal() {
    // Load manufacturers
    loadManufacturers();
    // Clear form
    document.getElementById('addModuleForm').reset();
    document.getElementById('modal_wattage_container').innerHTML = '';
    addModalWattageField(); // Add one default wattage field
    document.getElementById('addModuleModal').style.display = 'block';
}

function closeAddModuleModal() {
    document.getElementById('addModuleModal').style.display = 'none';
}

function loadManufacturers() {
    // Populate manufacturer dropdown (you'll need to implement this based on your data)
    const select = document.getElementById('modal_manufacturer_id');
    select.innerHTML = '<option value="">Select Manufacturer</option>';
    
    // Add manufacturers from PHP data or make AJAX call
    <?php if (!empty($manufacturers)): ?>
    <?php foreach ($manufacturers as $mfg): ?>
    const option<?php echo $mfg['id']; ?> = document.createElement('option');
    option<?php echo $mfg['id']; ?>.value = '<?php echo $mfg['id']; ?>';
    option<?php echo $mfg['id']; ?>.textContent = '<?php echo htmlspecialchars($mfg['name'], ENT_QUOTES); ?>';
    select.appendChild(option<?php echo $mfg['id']; ?>);
    <?php endforeach; ?>
    <?php endif; ?>
}

let modalWattageIndex = 0;
function addModalWattageField() {
    const container = document.getElementById('modal_wattage_container');
    const div = document.createElement('div');
    div.className = 'wattage-entry';
    div.innerHTML = `
        <div class="modal-form-group">
            <label>Wattage (W):</label>
            <input type="number" name="wattages[${modalWattageIndex}]" step="1" min="1" required placeholder="e.g., 555">
        </div>
        <div class="modal-form-group">
            <label>Quantity:</label>
            <input type="number" name="quantities[${modalWattageIndex}]" step="1" min="1" required placeholder="e.g., 1000">
        </div>
        <button type="button" class="remove-wattage-btn" onclick="this.closest('.wattage-entry').remove()">Remove</button>
    `;
    container.appendChild(div);
    modalWattageIndex++;
}

// Edit Batch Modal Functions
function openEditBatchModal(batchId, batchName) {
    document.getElementById('editBatchModalTitle').textContent = 'Edit Module Batch: ' + batchName;
    document.getElementById('edit_batch_id').value = batchId;
    
    // Load existing wattage data for this batch
    loadBatchWattages(batchId);
    
    document.getElementById('editBatchModal').style.display = 'block';
}

function closeEditBatchModal() {
    document.getElementById('editBatchModal').style.display = 'none';
}

function loadBatchWattages(batchId) {
    // Make AJAX call to get current wattages for this batch
    fetch('get_batch_wattages.php?batch_id=' + batchId)
    .then(response => response.json())
    .then(data => {
        const container = document.getElementById('edit_wattage_container');
        container.innerHTML = '';
        
        if (data.success && data.wattages) {
            data.wattages.forEach(function(wattage, index) {
                addEditWattageField(wattage.wattage, wattage.quantity, wattage.id);
            });
        }
        
        if (container.children.length === 0) {
            addEditWattageField(); // Add empty field if no existing data
        }
    })
    .catch(error => {
        console.error('Error loading batch wattages:', error);
        addEditWattageField(); // Add empty field on error
    });
}

let editWattageIndex = 0;
function addEditWattageField(wattage = '', quantity = '', itemId = '') {
    const container = document.getElementById('edit_wattage_container');
    const div = document.createElement('div');
    div.className = 'wattage-entry';
    div.innerHTML = `
        <div class="modal-form-group">
            <label>Wattage (W):</label>
            <input type="number" name="wattages[${editWattageIndex}]" step="1" min="1" required value="${wattage}" placeholder="e.g., 555">
            <input type="hidden" name="item_ids[${editWattageIndex}]" value="${itemId}">
        </div>
        <div class="modal-form-group">
            <label>Quantity:</label>
            <input type="number" name="quantities[${editWattageIndex}]" step="1" min="1" required value="${quantity}" placeholder="e.g., 1000">
        </div>
        <button type="button" class="remove-wattage-btn" onclick="this.closest('.wattage-entry').remove()">Remove</button>
    `;
    container.appendChild(div);
    editWattageIndex++;
}

// Form submission handlers
document.getElementById('addModuleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('project_id', <?php echo $project_id; ?>);
    formData.append('action', 'add_module_batch');
    
    fetch('handle_module_batch.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Module batch added successfully!');
            location.reload();
        } else {
            alert('Error adding module batch: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error adding module batch: ' + error.message);
    });
});

document.getElementById('editBatchForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'edit_module_batch');
    
    fetch('handle_module_batch.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Module batch updated successfully!');
            location.reload();
        } else {
            alert('Error updating module batch: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error updating module batch: ' + error.message);
    });
});
<?php endif; ?>
</script>

<?php include 'components/project_overview/modals.php'; ?>

</body>
</html>
