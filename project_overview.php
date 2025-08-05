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
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
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
$project_size_mw = 0;
$wattages = [];

while ($row = $total_orders_result->fetch_assoc()) {
    $w = (float)$row['wattage'];
    $t = (int)$row['total_order'];
    $wattages[] = $w;
    // Calculate total in MW for project size
    $project_size_mw += ($t * $w) / 1_000_000;

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
function fetchDeliveriesByDate($conn, $project_id, $date_field) {
    $stmt = $conn->prepare("
        SELECT wattage, $date_field AS delivery_date, SUM(quantity) AS quantity
        FROM deliveries
        WHERE project_id = ? AND $date_field IS NOT NULL
        GROUP BY wattage, $date_field
        ORDER BY $date_field ASC
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    return $stmt->get_result();
}

$anticipated_res = fetchDeliveriesByDate($conn, $project_id, 'anticipated_delivery_date');
$actual_res      = fetchDeliveriesByDate($conn, $project_id, 'actual_delivery_date');

$anticipated_deliveries = [];
$actual_deliveries = [];
$date_labels = [];

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

$cumulative_ant = 0;
$cumulative_act = 0;
$lineChartData_anticipated = [];
$lineChartData_actual      = [];

foreach ($date_labels as $dt) {
    $val_ant = $anticipated_deliveries[$dt] ?? 0;
    $cumulative_ant += $val_ant;
    $lineChartData_anticipated[] = $cumulative_ant;

    if ($dt <= $today_str) {
        $val_act = $actual_deliveries[$dt] ?? 0;
        $cumulative_act += $val_act;
        $lineChartData_actual[] = $cumulative_act;
    } else {
        $lineChartData_actual[] = null;
    }
}

$lineChartData = [
    'anticipated' => $lineChartData_anticipated,
    'actual'      => $lineChartData_actual,
];
$dateLabelsJSON    = json_encode($date_labels);
$lineChartDataJSON = json_encode($lineChartData);

// --------------- Delivery Status Table ---------------
// Get delivered to project
$stmt = $conn->prepare("
    SELECT wattage, SUM(quantity) AS total_quantity
    FROM deliveries
    WHERE project_id=? AND status_of_delivery = 'Delivered to Project'
    GROUP BY wattage
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$delivered_result = $stmt->get_result();

$delivery_totals = [];
$delivered_raw_total = 0; // Raw module count for timeline calculations
while ($row = $delivered_result->fetch_assoc()) {
    $w   = (float)$row['wattage'];
    $lbl = $w . 'W';
    $raw_qty = (int)$row['total_quantity'];
    $q_calc = calculateQuantity($raw_qty, $w, $view_mode);

    // Track raw delivered total for timeline
    $delivered_raw_total += $raw_qty;

    if (!isset($delivery_totals[$lbl])) {
        $delivery_totals[$lbl] = [
            'Delivered to Project' => 0,
            'In Warehouse' => 0,
            'Pending' => 0,
        ];
    }
    $delivery_totals[$lbl]['Delivered to Project'] = $q_calc;
}
$stmt->close();

// Get currently in warehouse using inventory_pallets status (matching module_movements.php logic)
$stmt = $conn->prepare("
    SELECT ip.wattage, SUM(ip.quantity) AS total_quantity
    FROM inventory_pallets ip
    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
    WHERE ip.status = 'In Warehouse' 
    AND (m.project_id = ? OR ip.assigned_project_id = ? OR ip.current_project_id = ?)
    GROUP BY ip.wattage
");
$stmt->bind_param("iii", $project_id, $project_id, $project_id);
$stmt->execute();
$warehouse_result = $stmt->get_result();

while ($row = $warehouse_result->fetch_assoc()) {
    $w   = (float)$row['wattage'];
    $lbl = $w . 'W';
    $q_calc = calculateQuantity((int)$row['total_quantity'], $w, $view_mode);

    if (!isset($delivery_totals[$lbl])) {
        $delivery_totals[$lbl] = [
            'Delivered to Project' => 0,
            'In Warehouse' => 0,
            'Pending' => 0,
        ];
    }
    $delivery_totals[$lbl]['In Warehouse'] = $q_calc;
}
$stmt->close();

// Note: We calculate pending as Total Order - Delivered to Project - In Warehouse
// This ensures we don't double-count modules that have moved through the system

// Summaries
$total_order_combined      = 0;
$delivered_combined        = 0;
$in_warehouse_combined     = 0;
$on_water_combined         = 0;
$cleared_customs_combined  = 0;
$pending_combined          = 0;

$pieChartData = [
    'Delivered to Project' => 0,
    'In Warehouse'      => 0,
    'On Water'         => 0,
    'Cleared Customs'  => 0,
    'Pending'          => 0,
];

$sub_rows        = [];
$sub_rows_status = [];

foreach ($total_orders as $lbl => $info) {
    $w  = (float)$info['wattage'];
    $to = (float)$info['total_order'];

    $del = $delivery_totals[$lbl]['Delivered to Project'] ?? 0;
    $inw = $delivery_totals[$lbl]['In Warehouse'] ?? 0;
    $onw = $delivery_totals[$lbl]['On Water'] ?? 0;
    $clr = $delivery_totals[$lbl]['Cleared Customs'] ?? 0;
    // Calculate pending as total order minus all known statuses
    $pending = $to - ($del + $inw + $onw + $clr);
    // Ensure pending is never negative  
    $pending = max(0, $pending);

    // Next 5 Weeks
    $sub_rows[$lbl] = [
        'wattage_label'      => $lbl,
        'total_order'        => $to,
        'delivered'          => $del,
        'anticipated_quantities'=> [],
    ];
    // Delivery Status
    $sub_rows_status[$lbl] = [
        'wattage_label'      => $lbl,
        'total_order'        => $to,
        'delivered'          => $del,
        'in_warehouse'       => $inw,
        'on_water'           => $onw,
        'cleared_customs'    => $clr,
        'pending'            => $pending,
    ];

    $total_order_combined      += $to;
    $delivered_combined        += $del;
    $in_warehouse_combined     += $inw;
    $on_water_combined         += $onw;
    $cleared_customs_combined  += $clr;
    $pending_combined          += $pending;

    $pieChartData['Delivered to Project'] += $del;
    $pieChartData['In Warehouse']      += $inw;
    $pieChartData['On Water']         += $onw;
    $pieChartData['Cleared Customs']  += $clr;
    $pieChartData['Pending']          += $pending;
}
$total_pie = array_sum($pieChartData);
$pieChartPercentages = [];
foreach ($pieChartData as $k => $v) {
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
    'Customer Cost' => $total_customer_cost,
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
$status_totals = [
    'At Manufacturer' => ['pallets' => 0, 'modules' => 0],
    'On Water' => ['pallets' => 0, 'modules' => 0],
    'Cleared Customs' => ['pallets' => 0, 'modules' => 0],
    'In Transit to Warehouse' => ['pallets' => 0, 'modules' => 0],
    'In Warehouse' => ['pallets' => 0, 'modules' => 0],
    'In Transit to Project' => ['pallets' => 0, 'modules' => 0]
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
while ($row = $res_status->fetch_assoc()) {
    $status  = $row['status'];
    $wattage = (int)$row['wattage'];
    $qty     = (int)$row['quantity'];
    $wh_id   = $row['current_warehouse_id'];
    $wh_name = $row['warehouse_name'];
    $proj_id = $row['current_project_id'];
    $proj_name = $row['project_name'];

    if (!isset($status_totals[$status])) {
        $status_totals[$status] = ['pallets' => 0, 'modules' => 0];
    }
    $status_totals[$status]['pallets'] += 1;
    $status_totals[$status]['modules'] += $qty;

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

// Calculate total expected pallets (modules / 30)
$expected_pallets = ceil($total_raw_modules / 30);

// Get delivered modules breakdown for JavaScript
$delivered_by_wattage = [];
$stmt_delivered = $conn->prepare("
    SELECT wattage, SUM(quantity) AS total_quantity
    FROM deliveries
    WHERE project_id=? AND status_of_delivery = 'Delivered to Project'
    GROUP BY wattage
");
$stmt_delivered->bind_param("i", $project_id);
$stmt_delivered->execute();
$delivered_result = $stmt_delivered->get_result();
while ($row = $delivered_result->fetch_assoc()) {
    $delivered_by_wattage[] = [
        'wattage' => (float)$row['wattage'],
        'modules' => (int)$row['total_quantity']
    ];
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

// Fetch module batches for this project with wattage information
$module_batches = [];
$stmt_modules = $conn->prepare("
    SELECT m.*, c.name as account_name
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
        WHERE unassigned_module_id = ? 
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

// Calculate average pallets_per_truck for truckload calculations
$pallets_per_truck_values = [];
$total_modules_for_ppt = 0;
$weighted_ppt_sum = 0;

foreach ($module_batches as $batch) {
    if (!empty($batch['pallets_per_truck']) && $batch['pallets_per_truck'] > 0) {
        $ppt = (int)$batch['pallets_per_truck'];
        // Calculate total modules for this batch to weight the average
        $batch_modules = 0;
        foreach ($batch['wattages'] as $wattage_info) {
            $batch_modules += (int)$wattage_info['quantity'];
        }
        
        $pallets_per_truck_values[] = $ppt;
        $weighted_ppt_sum += ($ppt * $batch_modules);
        $total_modules_for_ppt += $batch_modules;
    }
}

// Use weighted average if available, otherwise use 26 as default
$default_pallets_per_truck = 26;
if ($total_modules_for_ppt > 0 && $weighted_ppt_sum > 0) {
    $average_pallets_per_truck = round($weighted_ppt_sum / $total_modules_for_ppt);
} elseif (!empty($pallets_per_truck_values)) {
    $average_pallets_per_truck = round(array_sum($pallets_per_truck_values) / count($pallets_per_truck_values));
} else {
    $average_pallets_per_truck = $default_pallets_per_truck;
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
$deliveriesLink = ($role === 'admin' || $role === 'global_admin')
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
<link rel="icon" href="pictures/favicon.png" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* Modern Toggle Buttons */
.toggle-buttons {
    margin: 30px auto;
    max-width: fit-content;
    background: #ffffff;
    padding: 8px;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    display: flex;
    gap: 4px;
    border: 1px solid #e9ecef;
    position: relative;
}

.toggle-buttons button {
    padding: 12px 28px;
    margin: 0;
    cursor: pointer;
    font-size: 16px;
    font-weight: 500;
    border: none;
    border-radius: 8px;
    transition: all 0.3s ease;
    background: transparent;
    color: #6c757d;
    position: relative;
}

.toggle-buttons button.active {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    color: #fff;
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
    transform: translateY(-1px);
}

.toggle-buttons button:not(.active):hover {
    background: #f8f9fa;
    color: #495057;
}

/* Enhanced visual connection between tabs and content */
.toggle-buttons::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 50%;
    transform: translateX(-50%);
    width: 20px;
    height: 4px;
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    border-radius: 0 0 4px 4px;
    opacity: 0.7;
}

<?php if ($role === 'admin' || $role === 'global_admin'): ?>
/* Enhanced Timeline Styles - Jony Ive Inspired */
.timeline-container {
    background: linear-gradient(135deg, #fafbfc 0%, #f1f3f4 100%);
    border-radius: 20px;
    padding: 40px 30px;
    margin: 0 auto 40px auto;
    box-shadow: 
        0 20px 40px rgba(0, 0, 0, 0.12),
        0 8px 16px rgba(0, 0, 0, 0.08),
        0 2px 4px rgba(0, 0, 0, 0.06),
        inset 0 1px 0 rgba(255, 255, 255, 0.9),
        inset 0 -1px 0 rgba(0, 0, 0, 0.03);
    border: 2px solid rgba(255, 255, 255, 0.8);
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(10px);
}

.timeline-container::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    border-radius: 20px 20px 0 0;
}

/* Add connecting element from tabs to container */
.admin-content-wrapper {
    position: relative;
}

.admin-content-wrapper::before {
    content: '';
    position: absolute;
    top: -15px;
    left: 50%;
    transform: translateX(-50%);
    width: 2px;
    height: 15px;
    background: linear-gradient(to bottom, rgba(72, 140, 154, 0.3), rgba(72, 140, 154, 0.8));
    border-radius: 1px;
    z-index: 1;
}

.timeline {
    display: flex;
    justify-content: space-between;
    list-style: none;
    padding: 0;
    margin: 0;
    position: relative;
}

.timeline::before {
    content: '';
    position: absolute;
    top: 35px;
    left: 10%;
    right: 10%;
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    z-index: 0;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
}

.timeline::after {
    content: '';
    position: absolute;
    top: 35px;
    left: 10%;
    width: var(--progress-width, 0%);
    max-width: 80%;
    height: 6px;
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    border-radius: 3px;
    z-index: 1;
    transition: width 1s ease-in-out;
    box-shadow: 0 2px 8px rgba(72, 140, 154, 0.3);
}

.timeline-item {
    flex: 1;
    text-align: center;
    position: relative;
    padding: 0 15px;
}

.timeline-item .circle {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border: 4px solid #dee2e6;
    margin: 0 auto 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 24px;
    color: #adb5bd;
    position: relative;
    z-index: 2;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.timeline-item.completed .circle {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    border-color: #488C9A;
    color: #ffffff;
    transform: scale(1.1);
    box-shadow: 0 12px 35px rgba(72, 140, 154, 0.4);
}

.timeline-item.current .circle {
    background: linear-gradient(135deg, #ffc107 0%, #ffb300 100%);
    border-color: #ffc107;
    color: #ffffff;
    transform: scale(1.05);
    box-shadow: 0 10px 30px rgba(255, 193, 7, 0.4);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { box-shadow: 0 10px 30px rgba(255, 193, 7, 0.4); }
    50% { box-shadow: 0 10px 30px rgba(255, 193, 7, 0.6), 0 0 0 10px rgba(255, 193, 7, 0.1); }
    100% { box-shadow: 0 10px 30px rgba(255, 193, 7, 0.4); }
}

.timeline-item .circle.clickable {
    cursor: pointer;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.timeline-item .circle.clickable:hover {
    transform: scale(1.1);
    box-shadow: 0 12px 35px rgba(72, 140, 154, 0.4);
}

.timeline-item.completed .circle.clickable:hover {
    transform: scale(1.15);
    box-shadow: 0 15px 40px rgba(72, 140, 154, 0.5);
}

.timeline-item.current .circle.clickable:hover {
    transform: scale(1.1);
    box-shadow: 0 12px 35px rgba(255, 193, 7, 0.5);
}

.timeline-item .label {
    font-weight: 600;
    color: #293E4C;
    font-size: 16px;
    margin-bottom: 8px;
    transition: color 0.3s ease;
}

.timeline-item .label a {
    color: inherit;
    text-decoration: none;
    transition: color 0.3s ease;
}

.timeline-item .label a:hover {
    color: #488C9A;
}

.timeline-item.completed .label {
    color: #488C9A;
    font-weight: 700;
}

.timeline-item .description {
    font-size: 12px;
    color: #6c757d;
    font-weight: 400;
    margin-top: 5px;
}

/* Enhanced Shipping Statuses */
.shipping-statuses {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    margin-top: 25px;
    max-width: 800px;
    margin-left: auto;
    margin-right: auto;
}

.shipping-box {
    background: linear-gradient(135deg, #fafbfc 0%, #f1f3f4 100%);
    border: 2px solid rgba(255, 255, 255, 0.8);
    border-radius: 12px;
    padding: 20px 15px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    text-align: center;
    position: relative;
    overflow: hidden;
    box-shadow: 
        0 8px 16px rgba(0,0,0,0.08),
        0 2px 4px rgba(0,0,0,0.04),
        inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.shipping-box::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.3s ease;
}

.shipping-box:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(72, 140, 154, 0.15);
    border-color: #488C9A;
}

.shipping-box:hover::before {
    transform: scaleX(1);
}

.shipping-box .status-label {
    font-weight: 600;
    color: #293E4C;
    font-size: 14px;
    margin-bottom: 8px;
    line-height: 1.3;
}

.shipping-box .status-count {
    font-size: 24px;
    font-weight: 700;
    color: #488C9A;
    margin-bottom: 2px;
}

.shipping-box .status-unit {
    font-size: 11px;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Shipping Status Connection Line */
.shipping-statuses {
    position: relative;
}

.shipping-statuses::before {
    content: '';
    position: absolute;
    top: -15px;
    left: 50%;
    transform: translateX(-50%);
    width: 2px;
    height: 15px;
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    border-radius: 1px;
}

.shipping-statuses::after {
    content: '';
    position: absolute;
    top: -15px;
    left: 20%;
    right: 20%;
    height: 2px;
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    border-radius: 1px;
}
<?php endif; ?>

#financial-info {
    display: none;
}
/* Enhanced Layout Containers */
.tables-and-charts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin: 20px;
}

.left-side, .right-side {
    background: #ffffff;
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
    border: 1px solid #e9ecef;
}

.left-side h2, .right-side h2 {
    color: #293E4C;
    margin-bottom: 20px;
    font-weight: 600;
    font-size: 1.4em;
    padding-bottom: 12px;
    border-bottom: 3px solid #488C9A;
    position: relative;
}

.left-side h2::after, .right-side h2::after {
    content: '';
    position: absolute;
    bottom: -3px;
    left: 0;
    width: 40px;
    height: 3px;
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    border-radius: 2px;
}
.chart-container {
    max-width: 400px;
    margin: 0 auto;
}
/* Enhanced Table Styling */
.table-responsive {
    overflow-x: auto;
    border-radius: 12px;
    background: #ffffff;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    margin-bottom: 25px;
    border: 1px solid #e9ecef;
}

.table-responsive table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.95em;
}

.table-responsive th {
    background: #488C9A;
    color: #ffffff;
    padding: 16px 12px;
    font-weight: 600;
    font-size: 0.9em;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table-responsive th:first-child {
    border-top-left-radius: 12px;
}

.table-responsive th:last-child {
    border-top-right-radius: 12px;
}

.table-responsive td {
    padding: 14px 12px;
    border-bottom: 1px solid #f1f3f4;
    color: #495057;
    font-weight: 500;
}

.table-responsive tbody tr {
    transition: all 0.2s ease;
    cursor: pointer;
}

.table-responsive tbody tr:hover {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    transform: translateX(2px);
}

.table-responsive tbody tr:last-child td {
    border-bottom: none;
}

.table-responsive tbody tr:last-child td:first-child {
    border-bottom-left-radius: 12px;
}

.table-responsive tbody tr:last-child td:last-child {
    border-bottom-right-radius: 12px;
}
.project-overview-container {
    display: flex;
    align-items: center;
    margin: 20px;
    flex-wrap: wrap; 
}
.project-name-mobile {
    display: none;
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

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .tables-and-charts {
        grid-template-columns: 1fr;
        gap: 20px;
        margin: 15px;
    }
    
    .left-side, .right-side {
        padding: 20px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    }
    
    .toggle-buttons {
        margin: 20px 15px;
        width: calc(100% - 30px);
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        gap: 2px;
    }
    
    .toggle-buttons button {
        padding: 14px 16px;
        flex: 1;
        font-size: 14px;
        min-width: auto;
    }
    
    <?php if ($role === 'admin' || $role === 'global_admin'): ?>
    /* Timeline Mobile Responsiveness */
    .timeline-container {
        margin: 0 15px 20px 15px;
        padding: 30px 20px;
        box-shadow: 
            0 16px 32px rgba(0, 0, 0, 0.10),
            0 6px 12px rgba(0, 0, 0, 0.06),
            0 2px 4px rgba(0, 0, 0, 0.04),
            inset 0 1px 0 rgba(255, 255, 255, 0.9),
            inset 0 -1px 0 rgba(0, 0, 0, 0.02);
    }
    
    .timeline {
        flex-direction: column;
        max-width: 100%;
        margin: 0;
        align-items: center;
    }
    
    .timeline::before {
        width: 6px;
        height: 80%;
        top: 10%;
        left: 50%;
        right: auto;
        transform: translateX(-50%);
    }
    
    .timeline::after {
        width: 6px !important;
        max-width: none !important;
        height: calc(var(--progress-width, 0%) * 0.8);
        top: 10%;
        left: 50%;
        right: auto;
        transform: translateX(-50%);
    }
    
    .timeline-item {
        width: 100%;
        max-width: 400px;
        margin-bottom: 40px;
        padding: 0;
    }
    
    .timeline-item .circle {
        width: 60px;
        height: 60px;
        font-size: 20px;
        margin-bottom: 15px;
    }
    
    .timeline-item .label {
        font-size: 18px;
        margin-bottom: 5px;
    }
    
    .timeline-item .description {
        font-size: 14px;
        margin-bottom: 15px;
    }
    
    .shipping-statuses {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
        margin-top: 20px;
    }
    
    .shipping-box {
        padding: 15px 10px;
    }
    
    .shipping-box .status-label {
        font-size: 12px;
    }
    
    .shipping-box .status-count {
        font-size: 20px;
    }
    <?php endif; ?>
    
    /* Better table responsiveness */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        max-width: 100%;
    }
    
    .table-responsive table {
        min-width: 600px;
        width: auto;
    }
    
    .table-responsive th,
    .table-responsive td {
        padding: 10px 8px;
        font-size: 0.85em;
        white-space: nowrap;
    }
    
    /* Chart containers */
    .chart-container {
        max-width: 100%;
        margin: 20px 0;
    }
    
    canvas {
        max-width: 100% !important;
        height: auto !important;
    }
}

@media (max-width: 580px) {
    .project-overview-container {
        flex-direction: column;
        align-items: center;
    }
    .project-overview-container h1 {
        order: -1;
        text-align: center;
        width: 100%;
    }
    .project-overview-image {
        order: 0;
    }
    .project-info {
        order: 1;
        margin-left: 0;
        text-align: center;
    }
    .project-info button {
        margin: 10px 0;
    }
    
    /* Mobile responsive for view toggle */
    .view-toggle-header {
        width: calc(100% - 40px);
        justify-content: center;
        margin: 15px 20px;
    }
    
    .toggle-view-btn {
        flex: 1;
        text-align: center;
    }
    
    .button-group {
        flex-direction: column;
        gap: 15px;
        width: 100%;
    }
    
    .button-group button,
    .button-group .dropdown {
        width: 100%;
    }
    
    .button-group button {
        padding: 12px 20px;
        font-size: 1em;
    }
    .project-name-mobile {
        display: block;
        text-align: center;
    }
    .project-name-desktop {
        display: none;
    }
    main {
        padding: 0px;
    }
    .back-icon {
        margin-top: 20px;
        margin-left: 20px;
    }
    
    <?php if ($role === 'admin' || $role === 'global_admin'): ?>
    /* Enhanced mobile timeline for smallest screens */
    .timeline-container {
        margin: 15px 10px;
        padding: 25px 15px;
        box-shadow: 
            0 12px 24px rgba(0, 0, 0, 0.08),
            0 4px 8px rgba(0, 0, 0, 0.04),
            inset 0 1px 0 rgba(255, 255, 255, 0.9),
            inset 0 -1px 0 rgba(0, 0, 0, 0.02);
    }
    
    .timeline {
        margin: 0;
    }
    
    .timeline-item {
        margin-bottom: 30px;
        max-width: 350px;
    }
    
    .timeline-item .circle {
        width: 50px;
        height: 50px;
        font-size: 18px;
    }
    
    .timeline-item .label {
        font-size: 16px;
    }
    
    .timeline-item .description {
        font-size: 12px;
    }
    
    .shipping-statuses {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .shipping-box {
        padding: 12px 8px;
    }
    
    .shipping-box .status-label {
        font-size: 11px;
    }
    
    .shipping-box .status-count {
        font-size: 18px;
    }
    <?php endif; ?>
    
    /* Enhanced mobile layout for tables and charts */
    .tables-and-charts {
        margin: 10px;
        gap: 15px;
    }
    
    .left-side, .right-side {
        padding: 15px;
        margin: 0;
        border-radius: 12px;
    }
    
    .toggle-buttons {
        margin: 15px 10px;
        width: calc(100% - 20px);
    }
    
    .toggle-buttons button {
        padding: 12px 10px;
        font-size: 13px;
    }
    
    /* Table improvements for small screens */
    .table-responsive {
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .table-responsive table {
        min-width: 500px;
    }
    
    .table-responsive th,
    .table-responsive td {
        padding: 8px 6px;
        font-size: 0.8em;
    }
    
    .left-side h2, .right-side h2 {
        font-size: 1.2em;
        margin-bottom: 15px;
    }
    
    /* Chart container adjustments */
    .chart-container {
        margin: 15px 0;
        padding: 0 10px;
    }
}

/* Warehouse Selection Modal */
.warehouse-selection-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}

.warehouse-selection-modal .modal-content {
    background-color: white;
    margin: 5% auto;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    overflow: hidden;
    animation: modalSlideIn 0.3s ease;
}

.warehouse-selection-modal .modal-header {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.warehouse-selection-modal .modal-header h3 {
    margin: 0;
    font-size: 1.3em;
    color: white;
}

.warehouse-selection-modal .close-modal {
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
    line-height: 1;
    transition: opacity 0.3s ease;
}

.warehouse-selection-modal .close-modal:hover {
    opacity: 0.7;
}

.warehouse-selection-modal .modal-body {
    padding: 20px;
}

.warehouse-selection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.warehouse-selection-card {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    background: #f8f9fa;
    cursor: pointer;
    transition: all 0.3s ease;
}

.warehouse-selection-card:hover {
    border-color: #488C9A;
    background: #ffffff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.15);
}

.warehouse-selection-card h4 {
    margin: 0 0 10px 0;
    color: #293E4C;
    font-size: 1.1em;
}

.warehouse-selection-card p {
    margin: 5px 0;
    color: #555;
    font-size: 0.9em;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@media (max-width: 768px) {
    .warehouse-selection-grid {
        grid-template-columns: 1fr;
    }
    
    .warehouse-selection-modal .modal-content {
        width: 95%;
        margin: 10% auto;
    }
    
    .warehouse-selection-modal .modal-header {
        padding: 15px;
    }
    
    .warehouse-selection-modal .modal-body {
        padding: 15px;
    }
}

/* View Toggle Styling */
.view-toggle-header {
    display: flex;
    gap: 2px;
    background: #ffffff;
    padding: 3px;
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border: 1px solid #e9ecef;
    width: fit-content;
    margin: 20px auto 20px auto;
}

.toggle-view-btn {
    padding: 6px 12px;
    border: none;
    background: transparent;
    color: #6c757d;
    cursor: pointer;
    font-size: 0.85em;
    font-weight: 500;
    border-radius: 4px;
    transition: all 0.3s ease;
    min-width: 80px;
}

.toggle-view-btn.active {
    background: linear-gradient(135deg, #293E4C 0%, #243642 100%);
    color: #fff;
    box-shadow: 0 2px 6px rgba(41, 62, 76, 0.3);
}

.toggle-view-btn:not(.active):hover {
    background: #f8f9fa;
    color: #495057;
}

.button-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

/* Dropdown Styling */
.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-btn {
    background: #488C9A;
    color: #fff;
    padding: 12px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-weight: 600;
    font-size: 1em;
    transition: background-color 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 140px;
    margin: 5px;
}

.dropdown-btn:hover {
    background: #293E4C;
}

.dropdown-arrow {
    font-size: 0.8em;
    transition: transform 0.3s ease;
}

.dropdown-btn.active .dropdown-arrow {
    transform: rotate(180deg);
}

.dropdown-content {
    display: none;
    position: absolute;
    background-color: #fff;
    min-width: 200px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    border-radius: 8px;
    z-index: 1000;
    border: 1px solid #e0e0e0;
    top: 100%;
    left: 0;
    margin-top: 5px;
}

.dropdown-content.show {
    display: block;
    animation: fadeIn 0.2s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.dropdown-content a {
    color: #333;
    padding: 12px 16px;
    text-decoration: none;
    display: block;
    transition: background-color 0.3s ease;
    font-weight: 500;
    border-radius: 6px;
    margin: 4px;
}

.dropdown-content a:hover {
    background-color: #f8f9fa;
    color: #488C9A;
}

.dropdown-content a:first-child {
    border-top-left-radius: 8px;
    border-top-right-radius: 8px;
}

.dropdown-content a:last-child {
    border-bottom-left-radius: 8px;
    border-bottom-right-radius: 8px;
    
    .table-responsive th,
    .table-responsive td {
        padding: 12px 8px;
        font-size: 0.9em;
    }
}

/* Info Container Styles */
.info-container {
    background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: 20px;
    padding: 30px;
    margin: 20px;
    box-shadow: 
        0 12px 24px rgba(0, 0, 0, 0.08),
        0 4px 8px rgba(0, 0, 0, 0.04),
        inset 0 1px 0 rgba(255, 255, 255, 0.9),
        inset 0 -1px 0 rgba(0, 0, 0, 0.02);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.info-container h2 {
    margin-top: 0;
    margin-bottom: 25px;
    color: #293E4C;
    font-size: 1.8em;
    font-weight: 700;
    border-bottom: 3px solid #488C9A;
    padding-bottom: 10px;
    background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.info-container .header-with-button {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.info-container .header-with-button h2 {
    margin: 0;
    border-bottom: none;
    padding-bottom: 0;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 25px;
    margin-bottom: 20px;
}

.info-section {
    background: rgba(255, 255, 255, 0.7);
    border-radius: 12px;
    padding: 20px;
    border: 1px solid rgba(72, 140, 154, 0.1);
    transition: all 0.3s ease;
}

.info-section:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(72, 140, 154, 0.1);
}

.info-section h3, .info-section h4 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #488C9A;
    font-size: 1.2em;
    font-weight: 600;
    border-bottom: 2px solid #e9ecef;
    padding-bottom: 8px;
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
    padding: 8px 0;
    border-bottom: 1px solid rgba(233, 236, 239, 0.5);
}

.info-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.info-item label {
    font-weight: 600;
    color: #293E4C;
    margin-right: 15px;
    min-width: 140px;
    flex-shrink: 0;
}

.info-item span {
    color: #666;
    text-align: right;
    word-break: break-word;
}

.info-action-button {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    color: white;
    padding: 12px 24px;
    border-radius: 25px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
    display: inline-block;
    cursor: pointer;
}

.info-action-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(72, 140, 154, 0.4);
    color: white;
    text-decoration: none;
}

.batch-header {
    margin-bottom: 20px;
}

.batch-header h3 {
    margin: 0 0 5px 0;
    color: #293E4C;
    font-size: 1.4em;
}

.batch-meta {
    margin-top: 5px;
}

.no-modules-message {
    text-align: center;
    padding: 40px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 12px;
    border: 2px dashed #e9ecef;
}

/* Mobile responsiveness for info containers */
@media (max-width: 768px) {
    .info-container {
        margin: 10px;
        padding: 20px;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .info-item {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .info-item label {
        margin-bottom: 5px;
        min-width: auto;
    }
    
    .info-item span {
        text-align: left;
    }
}

/* Project Actions Dropdown */
.project-actions-dropdown {
    position: relative;
    display: inline-block;
}

.project-actions-dropdown .project-actions-btn {
    background: none !important;
    border: none !important;
    padding: 6px !important;
    border-radius: 50% !important;
    cursor: pointer !important;
    color: #adb5bd !important;
    transition: all 0.3s ease !important;
    font-size: 16px !important;
    width: auto !important;
    margin: 0 !important;
    font-weight: normal !important;
}

.project-actions-dropdown .project-actions-btn:hover {
    background: rgba(72, 140, 154, 0.08) !important;
    color: #6c757d !important;
    transform: scale(1.1) !important;
}

.project-actions-content {
    display: none;
    position: absolute;
    right: 0;
    background-color: white;
    min-width: 180px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    border-radius: 8px;
    z-index: 1000;
    border: 1px solid #e9ecef;
}

.project-actions-content a {
    color: #333;
    padding: 12px 16px;
    text-decoration: none;
    display: block;
    transition: background-color 0.3s ease;
}

.project-actions-content a:hover {
    background-color: #f8f9fa;
    color: #488C9A;
}

/* Module Actions Dropdown */
.module-actions-dropdown {
    position: relative;
    display: inline-block;
}

.module-actions-content {
    display: none;
    position: absolute;
    right: 0;
    background-color: white;
    min-width: 220px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    border-radius: 8px;
    z-index: 1000;
    border: 1px solid #e9ecef;
}

.module-actions-content a {
    color: #333;
    padding: 12px 16px;
    text-decoration: none;
    display: block;
    transition: background-color 0.3s ease;
}

.module-actions-content a:hover {
    background-color: #f8f9fa;
    color: #488C9A;
}

/* Batch Actions Dropdown */
.batch-actions-dropdown {
    position: relative;
    display: inline-block;
}

.batch-actions-btn {
    background: #488C9A;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9em;
    transition: background-color 0.3s ease;
}

.batch-actions-btn:hover {
    background: #3A6E7F;
}

.batch-actions-content {
    display: none;
    position: absolute;
    right: 0;
    background-color: white;
    min-width: 200px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    border-radius: 8px;
    z-index: 1000;
    border: 1px solid #e9ecef;
}

.batch-actions-content a {
    color: #333;
    padding: 12px 16px;
    text-decoration: none;
    display: block;
    transition: background-color 0.3s ease;
}

.batch-actions-content a:hover {
    background-color: #f8f9fa;
    color: #488C9A;
}

/* Delete Modal Styles */
.delete-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.7);
}

.delete-modal-content {
    background-color: white;
    margin: 10% auto;
    padding: 30px;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    text-align: center;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
}

.delete-modal h3 {
    color: #dc3545;
    margin-bottom: 20px;
    font-size: 1.5em;
}

.delete-modal p {
    color: #666;
    margin-bottom: 30px;
    line-height: 1.5;
}

.delete-modal .modal-buttons {
    display: flex;
    justify-content: center;
    gap: 15px;
}

.delete-modal .modal-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}

.delete-modal .btn-cancel {
    background: #6c757d;
    color: white;
}

.delete-modal .btn-cancel:hover {
    background: #5a6268;
}

.delete-modal .btn-delete {
    background: #dc3545;
    color: white;
}

.delete-modal .btn-delete:hover {
    background: #c82333;
}

/* Add Module Modal Styles */
.add-module-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.7);
    overflow-y: auto;
}

.add-module-modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 30px;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
}

.add-module-modal h3 {
    color: #293E4C;
    margin-bottom: 25px;
    font-size: 1.5em;
    border-bottom: 2px solid #488C9A;
    padding-bottom: 10px;
}

.modal-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 25px;
}

.modal-form-group {
    margin-bottom: 20px;
}

.modal-form-group label {
    display: block;
    font-weight: 600;
    color: #293E4C;
    margin-bottom: 8px;
}

.modal-form-group input,
.modal-form-group select,
.modal-form-group textarea {
    width: 100%;
    padding: 10px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
    box-sizing: border-box;
}

.modal-form-group input:focus,
.modal-form-group select:focus,
.modal-form-group textarea:focus {
    outline: none;
    border-color: #488C9A;
}

.wattage-section {
    grid-column: 1 / -1;
    margin-top: 20px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
}

.wattage-entry {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 15px;
    align-items: end;
    margin-bottom: 15px;
}

.add-wattage-btn {
    background: #488C9A;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    margin-bottom: 15px;
}

.remove-wattage-btn {
    background: #dc3545;
    color: white;
    border: none;
    padding: 10px 15px;
    border-radius: 6px;
    cursor: pointer;
}

.modal-buttons {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
}

.modal-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-primary {
    background: #488C9A;
    color: white;
}

@media (max-width: 768px) {
    .modal-form-grid {
        grid-template-columns: 1fr;
    }
    
    .wattage-entry {
        grid-template-columns: 1fr;
        gap: 10px;
    }
}
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
    $backLink = 'dashboard.php';
    ?>
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="<?php echo $backLink; ?>">Dashboard</a>
        <span class="separator">&raquo;</span>
        <span><?php echo htmlspecialchars($project['project_name']); ?></span>
    </div>

    <!-- View Toggle (only visible to admins) -->
    <?php if ($role === 'admin' || $role === 'global_admin'): ?>
        <div class="view-toggle-header">
            <button id="admin-view-btn" class="toggle-view-btn active" onclick="switchView('admin')">Admin View</button>
            <button id="customer-view-btn" class="toggle-view-btn" onclick="switchView('customer')">Customer View</button>
        </div>
    <?php endif; ?>

    <div class="project-overview-container">
        <!-- Mobile Project Name -->
        <h1 class="project-name-mobile"><?php echo htmlspecialchars($project['project_name']); ?></h1>
        
        <div class="project-overview-image">
            <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="Project Overview Image">
        </div>
        
        <div class="project-info">
            <!-- Desktop Project Name -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1;">
                    <h1 class="project-name-desktop"><?php echo htmlspecialchars($project['project_name']); ?></h1>
                    <p><strong>Project Address:</strong> <?php echo htmlspecialchars($project['project_address']); ?></p>
                    <p><strong>Project Size:</strong> <?php echo number_format($project_size_mw, 2); ?> MWs</p>
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
            
            <!-- Admin View Buttons -->
            <div id="admin-buttons" class="button-group" <?php echo ($role === 'admin' || $role === 'global_admin') ? 'style="display: block;"' : 'style="display: none;"'; ?>>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleModulesDropdown()">
                        Modules <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="modulesDropdown">
                        <a href="module_overview.php?project_id=<?php echo $project_id; ?>">Module Overview</a>
                        <a href="manage_pallets.php?project_id=<?php echo $project_id; ?>">Manage Pallets</a>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleAdminDeliveriesDropdown()">
                        Deliveries <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="adminDeliveriesDropdown">
                        <a href="create_shipment.php?project_id=<?php echo $project_id; ?>">Create Shipments</a>
                        <a href="manage_deliveries.php?project_id=<?php echo $project_id; ?>">Manage Deliveries</a>
                        <a href="scheduling.php?project_id=<?php echo $project_id; ?>">Scheduling</a>
                    </div>
                </div>
                <button onclick="handleAdminWarehousing()">Warehousing</button>
                <button onclick="window.location.href='warranty.php?project_id=<?php echo $project_id; ?>'">Warranty</button>
            </div>
            
            <!-- Customer View Buttons -->
            <div id="customer-buttons" class="button-group" <?php echo ($role === 'admin' || $role === 'global_admin') ? 'style="display: none;"' : 'style="display: block;"'; ?>>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleCustomerDeliveriesDropdown()">
                        Deliveries <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="customerDeliveriesDropdown">
                        <a href="<?php echo $deliveriesLink; ?>">📋 Delivery Schedule</a>
                        <a href="module_movements.php?project_id=<?php echo $project_id; ?>">📍 Module Movements</a>
                    </div>
                </div>
                <button onclick="window.location.href='warehouse_info?project_id=<?php echo $project_id; ?>'">Warehousing</button>
                <button onclick="window.location.href='project_cost_details?project_id=<?php echo $project_id; ?>'">Costs</button>
                <button onclick="window.location.href='project_documents?project_id=<?php echo $project_id; ?>'">Documents</button>
                <button onclick="window.location.href='project_sustainability_details?project_id=<?php echo $project_id; ?>'">Sustainability</button>
            </div>
        </div>
    </div>

    <?php if ($role === 'admin' || $role === 'global_admin'): ?>
        <!-- Admin Timeline View -->
        <div id="admin-view-content">
            <div class="toggle-buttons">
                <button id="progress-info-btn" class="active" onclick="showView('progress-info')">Project Progress</button>
                <button id="site-info-btn" onclick="showView('site-info')">Site Information</button>
                <button id="module-info-btn" onclick="showView('module-info')">Module Information</button>
            </div>

            <div class="admin-content-wrapper">
                <!-- Project Progress -->
                <div id="progress-info">        
                    <div class="timeline-container">
                        <ul class="timeline" style="--progress-width: <?php echo $progress_percentage; ?>%">
                        <li class="timeline-item<?php echo $step1_completed ? ' completed' : ''; ?><?php echo $current_step == 1 ? ' current' : ''; ?>">
                            <div class="circle clickable" onclick="window.location.href='edit_project.php?project_id=<?php echo $project_id; ?>'">1</div>
                            <span class="label">
                                <a href="edit_project.php?project_id=<?php echo $project_id; ?>">Project Created</a>
                            </span>
                            <div class="description">Foundation established</div>
                        </li>
                        
                        <li class="timeline-item<?php echo $step2_completed ? ' completed' : ''; ?><?php echo $current_step == 2 ? ' current' : ''; ?>">
                            <div class="circle clickable" onclick="window.location.href='modules.php?project_id=<?php echo $project_id; ?>'">2</div>
                            <span class="label">
                                <a href="modules.php?project_id=<?php echo $project_id; ?>">Add Modules</a>
                            </span>
                            <div class="description"><?php echo number_format($total_raw_modules); ?> modules added</div>
                        </li>
                        
                        <li class="timeline-item<?php echo $step3_completed ? ' completed' : ''; ?><?php echo $current_step == 3 ? ' current' : ''; ?>">
                            <div class="circle clickable" onclick="window.location.href='module_overview.php?project_id=<?php echo $project_id; ?>'">3</div>
                            <span class="label">
                                <a href="module_overview.php?project_id=<?php echo $project_id; ?>">Palletize Modules</a>
                            </span>
                            <div class="description">
                                <?php 
                                echo number_format($actual_palletized_count) . ' of ' . number_format($expected_pallets) . ' palletized';
                                ?>
                            </div>
                        </li>
                        
                        <li class="timeline-item<?php echo $step4_completed ? ' completed' : ''; ?><?php echo $current_step == 4 ? ' current' : ''; ?>">
                            <div class="circle clickable" onclick="window.location.href='<?php echo $deliveriesLink; ?>'">4</div>
                            <span class="label">
                                <a href="<?php echo $deliveriesLink; ?>">Shipping</a>
                            </span>
                            <div class="description">Logistics in progress</div>
                            
                            <?php if ($current_step == 4): ?>
                            <!-- Shipping Filters for Admin/Global Admin -->
                            <?php if ($role === 'admin' || $role === 'global_admin'): ?>
                            <div class="shipping-filters" style="margin: 20px auto 15px; text-align: center; max-width: 800px;">
                                <div class="filter-buttons" style="display: inline-flex; background: #f8f9fa; border-radius: 8px; padding: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <button type="button" class="filter-btn active" data-filter="pallets" style="background: #488C9A; color: white; border: none; padding: 8px 16px; border-radius: 6px; margin: 0 2px; cursor: pointer; font-weight: 600; transition: all 0.3s ease;">Pallets</button>
                                    <button type="button" class="filter-btn" data-filter="modules" style="background: transparent; color: #293E4C; border: none; padding: 8px 16px; border-radius: 6px; margin: 0 2px; cursor: pointer; font-weight: 600; transition: all 0.3s ease;">Modules</button>
                                    <button type="button" class="filter-btn" data-filter="truckloads" style="background: transparent; color: #293E4C; border: none; padding: 8px 16px; border-radius: 6px; margin: 0 2px; cursor: pointer; font-weight: 600; transition: all 0.3s ease;">Truckloads</button>
                                    <button type="button" class="filter-btn" data-filter="mws" style="background: transparent; color: #293E4C; border: none; padding: 8px 16px; border-radius: 6px; margin: 0 2px; cursor: pointer; font-weight: 600; transition: all 0.3s ease;">MWs</button>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="shipping-statuses">
                                <?php if(($status_totals['At Manufacturer']['pallets'] ?? 0) > 0): 
                                    $pallets = $status_totals['At Manufacturer']['pallets'];
                                    $modules = $status_totals['At Manufacturer']['modules'];
                                    $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                    $mws = 0;
                                    // Calculate MWs - we need wattage information
                                    // For now, we'll calculate based on average wattage if available
                                    if (!empty($wattages) && $modules > 0) {
                                        $total_watts = 0;
                                        $total_modules_for_avg = 0;
                                        foreach ($wattages as $w) {
                                            $total_watts += $w;
                                            $total_modules_for_avg++;
                                        }
                                        $avg_wattage = $total_modules_for_avg > 0 ? ($total_watts / $total_modules_for_avg) : 0;
                                        $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                    }
                                ?>
                                <div class="shipping-box" onclick="showShippingBreakdown('At Manufacturer')" 
                                     data-pallets="<?php echo $pallets; ?>" 
                                     data-modules="<?php echo $modules; ?>" 
                                     data-truckloads="<?php echo $truckloads; ?>" 
                                     data-mws="<?php echo $mws; ?>">
                                    <div class="status-label">At Manufacturer</div>
                                    <div class="status-count"><?php echo $pallets; ?></div>
                                    <div class="status-unit">pallets</div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if(($status_totals['On Water']['pallets'] ?? 0) > 0): 
                                    $pallets = $status_totals['On Water']['pallets'];
                                    $modules = $status_totals['On Water']['modules'];
                                    $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                    $mws = 0;
                                    if (!empty($wattages) && $modules > 0) {
                                        $total_watts = 0;
                                        $total_modules_for_avg = 0;
                                        foreach ($wattages as $w) {
                                            $total_watts += $w;
                                            $total_modules_for_avg++;
                                        }
                                        $avg_wattage = $total_modules_for_avg > 0 ? ($total_watts / $total_modules_for_avg) : 0;
                                        $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                    }
                                ?>
                                <div class="shipping-box" onclick="showShippingBreakdown('On Water')" 
                                     data-pallets="<?php echo $pallets; ?>" 
                                     data-modules="<?php echo $modules; ?>" 
                                     data-truckloads="<?php echo $truckloads; ?>" 
                                     data-mws="<?php echo $mws; ?>">
                                    <div class="status-label">On Water</div>
                                    <div class="status-count"><?php echo $pallets; ?></div>
                                    <div class="status-unit">pallets</div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if(($status_totals['Cleared Customs']['pallets'] ?? 0) > 0): 
                                    $pallets = $status_totals['Cleared Customs']['pallets'];
                                    $modules = $status_totals['Cleared Customs']['modules'];
                                    $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                    $mws = 0;
                                    if (!empty($wattages) && $modules > 0) {
                                        $total_watts = 0;
                                        $total_modules_for_avg = 0;
                                        foreach ($wattages as $w) {
                                            $total_watts += $w;
                                            $total_modules_for_avg++;
                                        }
                                        $avg_wattage = $total_modules_for_avg > 0 ? ($total_watts / $total_modules_for_avg) : 0;
                                        $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                    }
                                ?>
                                <div class="shipping-box" onclick="showShippingBreakdown('Cleared Customs')" 
                                     data-pallets="<?php echo $pallets; ?>" 
                                     data-modules="<?php echo $modules; ?>" 
                                     data-truckloads="<?php echo $truckloads; ?>" 
                                     data-mws="<?php echo $mws; ?>">
                                    <div class="status-label">Cleared Customs</div>
                                    <div class="status-count"><?php echo $pallets; ?></div>
                                    <div class="status-unit">pallets</div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if(($status_totals['In Transit to Warehouse']['pallets'] ?? 0) > 0): 
                                    $pallets = $status_totals['In Transit to Warehouse']['pallets'];
                                    $modules = $status_totals['In Transit to Warehouse']['modules'];
                                    $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                    $mws = 0;
                                    if (!empty($wattages) && $modules > 0) {
                                        $total_watts = 0;
                                        $total_modules_for_avg = 0;
                                        foreach ($wattages as $w) {
                                            $total_watts += $w;
                                            $total_modules_for_avg++;
                                        }
                                        $avg_wattage = $total_modules_for_avg > 0 ? ($total_watts / $total_modules_for_avg) : 0;
                                        $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                    }
                                ?>
                                <div class="shipping-box" onclick="showShippingBreakdown('In Transit to Warehouse')" 
                                     data-pallets="<?php echo $pallets; ?>" 
                                     data-modules="<?php echo $modules; ?>" 
                                     data-truckloads="<?php echo $truckloads; ?>" 
                                     data-mws="<?php echo $mws; ?>">
                                    <div class="status-label">In Transit to Warehouse</div>
                                    <div class="status-count"><?php echo $pallets; ?></div>
                                    <div class="status-unit">pallets</div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if(($status_totals['In Warehouse']['pallets'] ?? 0) > 0): 
                                    $pallets = $status_totals['In Warehouse']['pallets'];
                                    $modules = $status_totals['In Warehouse']['modules'];
                                    $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                    $mws = 0;
                                    if (!empty($wattages) && $modules > 0) {
                                        $total_watts = 0;
                                        $total_modules_for_avg = 0;
                                        foreach ($wattages as $w) {
                                            $total_watts += $w;
                                            $total_modules_for_avg++;
                                        }
                                        $avg_wattage = $total_modules_for_avg > 0 ? ($total_watts / $total_modules_for_avg) : 0;
                                        $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                    }
                                ?>
                                <div class="shipping-box" onclick="showShippingBreakdown('In Warehouse')" 
                                     data-pallets="<?php echo $pallets; ?>" 
                                     data-modules="<?php echo $modules; ?>" 
                                     data-truckloads="<?php echo $truckloads; ?>" 
                                     data-mws="<?php echo $mws; ?>">
                                    <div class="status-label">In Warehouse</div>
                                    <div class="status-count"><?php echo $pallets; ?></div>
                                    <div class="status-unit">pallets</div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if(($status_totals['In Transit to Project']['pallets'] ?? 0) > 0): 
                                    $pallets = $status_totals['In Transit to Project']['pallets'];
                                    $modules = $status_totals['In Transit to Project']['modules'];
                                    $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                    $mws = 0;
                                    if (!empty($wattages) && $modules > 0) {
                                        $total_watts = 0;
                                        $total_modules_for_avg = 0;
                                        foreach ($wattages as $w) {
                                            $total_watts += $w;
                                            $total_modules_for_avg++;
                                        }
                                        $avg_wattage = $total_modules_for_avg > 0 ? ($total_watts / $total_modules_for_avg) : 0;
                                        $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                    }
                                ?>
                                <div class="shipping-box" onclick="showShippingBreakdown('In Transit to Project')" 
                                     data-pallets="<?php echo $pallets; ?>" 
                                     data-modules="<?php echo $modules; ?>" 
                                     data-truckloads="<?php echo $truckloads; ?>" 
                                     data-mws="<?php echo $mws; ?>">
                                    <div class="status-label">In Transit to Project</div>
                                    <div class="status-count"><?php echo $pallets; ?></div>
                                    <div class="status-unit">pallets</div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if(($delivered_raw_total > 0)): 
                                    $pallets = ceil($delivered_raw_total / 30);
                                    $modules = $delivered_raw_total;
                                    $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                    $mws = 0;
                                    if (!empty($wattages) && $modules > 0) {
                                        $total_watts = 0;
                                        $total_modules_for_avg = 0;
                                        foreach ($wattages as $w) {
                                            $total_watts += $w;
                                            $total_modules_for_avg++;
                                        }
                                        $avg_wattage = $total_modules_for_avg > 0 ? ($total_watts / $total_modules_for_avg) : 0;
                                        $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                    }
                                ?>
                                <div class="shipping-box" onclick="showShippingBreakdown('Delivered')" 
                                     data-pallets="<?php echo $pallets; ?>" 
                                     data-modules="<?php echo $modules; ?>" 
                                     data-truckloads="<?php echo $truckloads; ?>" 
                                     data-mws="<?php echo $mws; ?>">
                                    <div class="status-label">Delivered to Project</div>
                                    <div class="status-count"><?php echo $pallets; ?></div>
                                    <div class="status-unit">pallets</div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </li>
                        
                        <li class="timeline-item<?php echo $step5_completed ? ' completed' : ''; ?><?php echo $current_step == 5 ? ' current' : ''; ?>">
                            <div class="circle clickable" onclick="window.location.href='project_overview.php?project_id=<?php echo $project_id; ?>'">5</div>
                            <span class="label">
                                <a href="project_overview.php?project_id=<?php echo $project_id; ?>">Project Completed</a>
                            </span>
                            <div class="description">
                                <?php 
                                if ($step5_completed) {
                                    echo "All modules delivered";
                                } else {
                                    $remaining = $total_raw_modules - $delivered_raw_total;
                                    echo number_format($remaining) . ' modules remaining';
                                }
                                ?>
                            </div>
                        </li>
                    </ul>
                    </div>
                </div>

                <!-- Site Information -->
                <div id="site-info" style="display:none;">
                    <div class="info-container">
                        <div class="header-with-button">
                            <h2>Site Information</h2>
                            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                <button onclick="window.location.href='edit_project.php?project_id=<?php echo $project_id; ?>'" class="info-action-button" style="margin: 0;">
                                    Edit Site Information
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="info-grid">
                            <div class="info-section">
                                <h3>Project Details</h3>
                                <div class="info-item">
                                    <label>Project Name:</label>
                                    <span><?php echo htmlspecialchars($project['project_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Project Address:</label>
                                    <span><?php 
                                        $address_parts = array_filter([
                                            $project['street_address'], 
                                            $project['city'], 
                                            $project['state'], 
                                            $project['zip_code']
                                        ]);
                                        echo htmlspecialchars(implode(', ', $address_parts));
                                    ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Estimated Completion:</label>
                                    <span><?php echo $project['estimated_completion_date'] ? date('F j, Y', strtotime($project['estimated_completion_date'])) : 'Not set'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Project Size:</label>
                                    <span><?php echo number_format($project_size_mw, 2); ?> MW</span>
                                </div>
                            </div>
                            
                            <div class="info-section">
                                <h3>Contact Information</h3>
                                <div class="info-item">
                                    <label>Primary Phone:</label>
                                    <span><?php echo !empty($project['phone1']) ? htmlspecialchars($project['phone1']) : 'Not provided'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Secondary Phone:</label>
                                    <span><?php echo !empty($project['phone2']) ? htmlspecialchars($project['phone2']) : 'Not provided'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Timezone:</label>
                                    <span><?php 
                                        $timezone_display = [
                                            'America/New_York' => 'Eastern',
                                            'America/Chicago' => 'Central', 
                                            'America/Denver' => 'Mountain',
                                            'America/Los_Angeles' => 'Pacific',
                                            'UTC' => 'UTC'
                                        ];
                                        echo $timezone_display[$project['timezone']] ?? htmlspecialchars($project['timezone'] ?? 'Not set');
                                    ?></span>
                                </div>
                            </div>
                            
                            <div class="info-section">
                                <h3>Additional Information</h3>
                                <div class="info-item">
                                    <label>Reference Numbers:</label>
                                    <span><?php echo !empty($project['reference_numbers']) ? htmlspecialchars($project['reference_numbers']) : 'None provided'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Special Instructions:</label>
                                    <span><?php echo !empty($project['instructions']) ? htmlspecialchars($project['instructions']) : 'None provided'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Additional Notes:</label>
                                    <span><?php echo !empty($project['additional_notes']) ? htmlspecialchars($project['additional_notes']) : 'None provided'; ?></span>
                                </div>
                                <?php if (!empty($project['driver_handout_url'])): ?>
                                <div class="info-item">
                                    <label>Driver Handout:</label>
                                    <span><a href="<?php echo htmlspecialchars($project['driver_handout_url']); ?>" target="_blank" style="color: #488C9A;">Download</a></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Module Information -->
                <div id="module-info" style="display:none;">
                    <div class="info-container">
                        <div class="header-with-button">
                            <h2>Module Information</h2>
                            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                <div class="module-actions-dropdown">
                                    <button class="info-action-button" onclick="toggleModuleActions()" style="margin: 0;">
                                        Edit Module Information ▼
                                    </button>
                                    <div class="module-actions-content" id="moduleActionsDropdown">
                                        <a href="edit_module_batch.php?project_id=<?php echo $project_id; ?>&batch_id=<?php echo !empty($module_batches) ? $module_batches[0]['id'] : ''; ?>">Edit Current Module Batch</a>
                                        <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>">+ Add New Module Batch</a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($module_batches)): ?>
                            <?php foreach ($module_batches as $index => $batch): ?>
                                <div class="module-batch-section" style="<?php echo $index > 0 ? 'margin-top: 30px; border-top: 2px solid #e9ecef; padding-top: 20px;' : ''; ?>">
                                    <div class="batch-header">
                                        <h3>Module Batch <?php echo $index + 1; ?>: <?php echo htmlspecialchars($batch['vendor_name']); ?></h3>
                                        <div class="batch-meta">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <span style="color: #666; font-size: 0.9em;">
                                                    Added: <?php echo date('F j, Y', strtotime($batch['created_at'])); ?>
                                                </span>

                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-grid">
                                        <div class="info-section">
                                            <h4>Basic Information</h4>
                                            <div class="info-item">
                                                <label>Vendor/Manufacturer:</label>
                                                <span><?php echo htmlspecialchars($batch['vendor_name']); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Initial Location:</label>
                                                <span><?php echo htmlspecialchars($batch['initial_location']); ?></span>
                                            </div>
                                                                                            <div class="info-item">
                                                    <label>Account:</label>
                                                    <span><?php echo htmlspecialchars($batch['account_name']); ?></span>
                                                </div>
                                                <div class="info-item">
                                                    <label>Module Configuration:</label>
                                                    <span>
                                                        <?php if (!empty($batch['wattages'])): ?>
                                                            <?php 
                                                            $wattage_details = [];
                                                            foreach ($batch['wattages'] as $watt_info) {
                                                                $wattage_details[] = $watt_info['wattage'] . 'W (' . number_format($watt_info['quantity']) . ' modules)';
                                                            }
                                                            echo implode('<br>', $wattage_details);
                                                            ?>
                                                        <?php else: ?>
                                                            No modules configured yet
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($batch['module_notes'])): ?>
                                                <div class="info-item">
                                                    <label>Module Notes:</label>
                                                    <span><?php echo htmlspecialchars($batch['module_notes']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                        </div>
                                        
                                        <div class="info-section">
                                            <h4>Pallet Specifications</h4>
                                            <div class="info-item">
                                                <label>Modules per Pallet:</label>
                                                <span><?php echo $batch['modules_per_pallet'] ?: 'Not specified'; ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallets per Truck:</label>
                                                <span><?php echo $batch['pallets_per_truck'] ?: 'Not specified'; ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Modules per Truck:</label>
                                                <span><?php echo $batch['modules_per_truck'] ?: 'Not specified'; ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallet Dimensions:</label>
                                                <span>
                                                    <?php if ($batch['pallet_length_mm'] && $batch['pallet_depth_mm']): ?>
                                                        <?php echo $batch['pallet_length_mm']; ?>mm × <?php echo $batch['pallet_depth_mm']; ?>mm
                                                    <?php else: ?>
                                                        Not specified
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallet Height (Stacked):</label>
                                                <span><?php echo $batch['pallet_double_stacked_height_mm'] ? $batch['pallet_double_stacked_height_mm'] . 'mm' : 'Not specified'; ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallet Weight:</label>
                                                <span><?php echo $batch['pallet_total_weight_kg'] ? $batch['pallet_total_weight_kg'] . 'kg' : 'Not specified'; ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="info-section">
                                            <h4>Handling Requirements</h4>
                                            <div class="info-item">
                                                <label>Forklift Requirements:</label>
                                                <span>
                                                    <?php if ($batch['forklift_truck_long_side_mm'] && $batch['forklift_truck_short_side_mm']): ?>
                                                        <?php echo $batch['forklift_truck_long_side_mm']; ?>mm × <?php echo $batch['forklift_truck_short_side_mm']; ?>mm
                                                    <?php else: ?>
                                                        Not specified
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallet Jack Requirements:</label>
                                                <span>
                                                    <?php if ($batch['pallet_jack_long_side_mm'] && $batch['pallet_jack_short_side_mm']): ?>
                                                        <?php echo $batch['pallet_jack_long_side_mm']; ?>mm × <?php echo $batch['pallet_jack_short_side_mm']; ?>mm
                                                    <?php else: ?>
                                                        Not specified
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($batch['stacking_in_warehouse'])): ?>
                                            <div class="info-item">
                                                <label>Warehouse Stacking:</label>
                                                <span><?php echo htmlspecialchars($batch['stacking_in_warehouse']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($batch['stacking_during_transport'])): ?>
                                            <div class="info-item">
                                                <label>Transport Stacking:</label>
                                                <span><?php echo htmlspecialchars($batch['stacking_during_transport']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($batch['module_docs_url'])): ?>
                                            <div class="info-item">
                                                <label>Module Documentation:</label>
                                                <span><a href="<?php echo htmlspecialchars($batch['module_docs_url']); ?>" target="_blank" style="color: #488C9A;">Download</a></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: 20px; text-align: center;">
                                        <a href="module_overview.php?batch_id=<?php echo $batch['id']; ?>" class="info-action-button">
                                            View Pallets & Module Status
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-modules-message">
                                <p style="text-align: center; color: #666; margin: 40px 0;">
                                    No module batches have been added to this project yet.
                                </p>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <div style="text-align: center;">
                                        <a href="modules.php?project_id=<?php echo $project_id; ?>" class="info-action-button">
                                            + Add Module Batch
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer View Content (for admins switching to customer view) -->
        <div id="customer-view-content" style="display: none;">
            <div class="toggle-buttons">
                <button id="delivery-info-btn-admin" class="active" onclick="showCustomerView('delivery-info')">Delivery View</button>
                <button id="financial-info-btn-admin" onclick="showCustomerView('financial-info')">Financial View</button>
            </div>

            <!-- Delivery Info -->
            <div id="delivery-info-admin">
                <form method="GET" id="filter-form-admin">
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <label>
                        <input type="radio" name="view_mode" value="mw"
                               <?php if($view_mode=='mw') echo 'checked';?>
                               onchange="this.form.submit();"> MWs
                    </label>
                    <label>
                        <input type="radio" name="view_mode" value="modules"
                               <?php if($view_mode=='modules') echo 'checked';?>
                               onchange="this.form.submit();"> Number of Modules
                    </label>
                </form>
                <div class="tables-and-charts">
                    <div class="left-side">
                        <h2>Next 5 Weeks of Deliveries</h2>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Module Type</th>
                                        <th>Total Order</th>
                                        <th>Delivered</th>
                                        <?php foreach($weeks as $wk): ?>
                                            <th><?php echo $wk['end']->format('n/j'); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr onclick="toggleSubRowsAdmin('delivery-row-admin')">
                                        <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                        <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                        <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                        <?php foreach($anticipated_quantities_combined as $qq): ?>
                                            <td><?php echo number_format($qq,($view_mode=='mw')?2:0);?></td>
                                        <?php endforeach;?>
                                    </tr>
                                    <?php foreach($sub_rows as $lbl=>$sr): ?>
                                        <tr class="delivery-row-admin" style="display:none;">
                                            <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                            <td><?php echo htmlspecialchars($sr['wattage_label']);?></td>
                                            <td><?php echo number_format($sr['total_order'],($view_mode=='mw')?2:0);?></td>
                                            <td><?php echo number_format($sr['delivered'],($view_mode=='mw')?2:0);?></td>
                                            <?php foreach($sr['anticipated_quantities'] as $val): ?>
                                                <td><?php echo number_format($val,($view_mode=='mw')?2:0);?></td>
                                            <?php endforeach;?>
                                        </tr>
                                    <?php endforeach;?>
                                </tbody>
                            </table>
                        </div>
                        <h2>Anticipated vs Actual Deliveries</h2>
                        <canvas id="lineChartAdmin"></canvas>
                    </div>

                    <div class="right-side">
                        <h2>Module Delivery Status</h2>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Module Type</th>
                                        <th>Total Order</th>
                                        <th>Delivered to Project</th>
                                        <th>In Warehouse</th>
                                        <?php if ($on_water_combined > 0): ?>
                                        <th>On Water</th>
                                        <?php endif; ?>
                                        <?php if ($cleared_customs_combined > 0): ?>
                                        <th>Cleared Customs</th>
                                        <?php endif; ?>
                                        <th>Pending</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr onclick="toggleSubRowsAdmin('status-row-admin')">
                                        <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                        <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                        <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format($in_warehouse_combined,($view_mode=='mw')?2:0);?></td>
                                        <?php if ($on_water_combined > 0): ?>
                                        <td><?php echo number_format($on_water_combined,($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($cleared_customs_combined > 0): ?>
                                        <td><?php echo number_format($cleared_customs_combined,($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <td><?php echo number_format($pending_combined,($view_mode=='mw')?2:0);?></td>
                                    </tr>
                                    <?php foreach($sub_rows_status as $lbl=>$srs): ?>
                                        <tr class="status-row-admin" style="display:none;">
                                            <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                            <td><?php echo htmlspecialchars($srs['wattage_label']);?></td>
                                            <td><?php echo number_format($srs['total_order'],($view_mode=='mw')?2:0);?></td>
                                            <td><?php echo number_format($srs['delivered'],($view_mode=='mw')?2:0);?></td>
                                            <td><?php echo number_format($srs['in_warehouse'],($view_mode=='mw')?2:0);?></td>
                                            <?php if ($on_water_combined > 0): ?>
                                            <td><?php echo number_format($srs['on_water'],($view_mode=='mw')?2:0);?></td>
                                            <?php endif; ?>
                                            <?php if ($cleared_customs_combined > 0): ?>
                                            <td><?php echo number_format($srs['cleared_customs'],($view_mode=='mw')?2:0);?></td>
                                            <?php endif; ?>
                                            <td><?php echo number_format($srs['pending'],($view_mode=='mw')?2:0);?></td>
                                        </tr>
                                    <?php endforeach;?>
                                </tbody>
                            </table>
                        </div>
                        <h2>Delivery Overview</h2>
                        <div class="chart-container">
                            <canvas id="pieChartAdmin"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Info -->
            <div id="financial-info-admin" style="display: none;">
                <div class="tables-and-charts">
                    <div class="left-side">
                        <h2>Invoices and Cashflow Forecast</h2>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Open Invoices</th>
                                        <th>Total Costs</th>
                                        <?php foreach($weeks_financial as $wf): ?>
                                            <th><?php echo $wf['end']->format('n/j'); ?></th>
                                        <?php endforeach;?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                        <td>
                                            <a href="invoices.php?project_id=<?php echo $project_id; ?>">
                                                $<?php echo number_format($open_invoices_total,2);?>
                                            </a>
                                        </td>
                                        <td>$<?php echo number_format($total_logistics_cost,2);?></td>
                                        <?php foreach($weeks_financial as $ix=>$wf){
                                            $val = $anticipated_deliveries_financial[$ix] ?? 0;
                                            echo "<td>$".number_format($val,2)."</td>";
                                        } ?>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <h2>Forecasted vs Actual Cost</h2>
                        <canvas id="budgetLineChartAdmin"></canvas>
                    </div>

                    <div class="right-side">
                        <h2>Cost per Unit</h2>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Module Type</th>
                                        <th>Total Costs</th>
                                        <th>Price Per Pallet</th>
                                        <th>Price Per Module</th>
                                        <th>Price Per Watt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Combined row -->
                                    <tr onclick="toggleSubRowsAdmin('cost-row-admin')">
                                        <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                        <td><?php echo htmlspecialchars($combined_label);?></td>
                                        <td>$<?php echo number_format($combined_total_costs,2);?></td>
                                        <td>$<?php echo number_format($combined_ppp,2);?></td>
                                        <td>$<?php echo number_format($combined_ppm,2);?></td>
                                        <td>$<?php echo number_format($combined_ppw,4);?></td>
                                    </tr>
                                    <!-- Detailed rows -->
                                    <?php foreach($cost_data as $key=>$cd): ?>
                                        <tr class="cost-row-admin" style="display:none;">
                                            <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                            <td><?php echo htmlspecialchars($cd['module_type']);?></td>
                                            <td>$<?php echo number_format($cd['total_costs'],2);?></td>
                                            <td>$<?php echo number_format($cd['price_per_pallet'],2);?></td>
                                            <td>$<?php echo number_format($cd['price_per_module'],2);?></td>
                                            <td>$<?php echo number_format($cd['price_per_watt'],4);?></td>
                                        </tr>
                                    <?php endforeach;?>
                                </tbody>
                            </table>
                        </div>
                        <h2>Cost Breakdown</h2>
                        <div class="chart-container">
                            <canvas id="costPieChartAdmin"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shipping Breakdown Modal -->
        <div id="shippingModal" class="warehouse-selection-modal" style="display:none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="shippingModalTitle"></h3>
                    <span class="close-modal" onclick="closeShippingModal()">&times;</span>
                </div>
                <div class="modal-body" id="shippingModalContent"></div>
            </div>
        </div>
    <?php else: ?>
        <!-- Regular User Delivery/Financial View -->
        <div class="toggle-buttons">
            <button id="delivery-info-btn" class="active" onclick="showView('delivery-info')">Delivery View</button>
            <button id="financial-info-btn" onclick="showView('financial-info')">Financial View</button>
        </div>

        <!-- Delivery Info -->
        <div id="delivery-info">
            <form method="GET" id="filter-form">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                <label>
                    <input type="radio" name="view_mode" value="mw"
                           <?php if($view_mode=='mw') echo 'checked';?>
                           onchange="this.form.submit();"> MWs
                </label>
                <label>
                    <input type="radio" name="view_mode" value="modules"
                           <?php if($view_mode=='modules') echo 'checked';?>
                           onchange="this.form.submit();"> Number of Modules
                </label>
            </form>
            <div class="tables-and-charts">
                <div class="left-side">
                    <h2>Next 5 Weeks of Deliveries</h2>
                    <div class="table-responsive">
                        <table id="table1">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Module Type</th>
                                    <th>Total Order</th>
                                    <th>Delivered</th>
                                    <?php foreach($weeks as $wk): ?>
                                        <th><?php echo $wk['end']->format('n/j'); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr onclick="toggleSubRows('delivery-row')">
                                    <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                    <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                    <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php foreach($anticipated_quantities_combined as $qq): ?>
                                        <td><?php echo number_format($qq,($view_mode=='mw')?2:0);?></td>
                                    <?php endforeach;?>
                                </tr>
                                <?php foreach($sub_rows as $lbl=>$sr): ?>
                                    <tr class="delivery-row" style="display:none;">
                                        <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                        <td><?php echo htmlspecialchars($sr['wattage_label']);?></td>
                                        <td><?php echo number_format($sr['total_order'],($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format($sr['delivered'],($view_mode=='mw')?2:0);?></td>
                                        <?php foreach($sr['anticipated_quantities'] as $val): ?>
                                            <td><?php echo number_format($val,($view_mode=='mw')?2:0);?></td>
                                        <?php endforeach;?>
                                    </tr>
                                <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                    <h2>Anticipated vs Actual Deliveries</h2>
                    <canvas id="lineChart"></canvas>
                </div>

                <div class="right-side">
                    <h2>Module Delivery Status</h2>
                    <div class="table-responsive">
                        <table id="table2">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Module Type</th>
                                    <th>Total Order</th>
                                    <th>Delivered to Project</th>
                                    <th>In Warehouse</th>
                                    <?php if ($on_water_combined > 0): ?>
                                    <th>On Water</th>
                                    <?php endif; ?>
                                    <?php if ($cleared_customs_combined > 0): ?>
                                    <th>Cleared Customs</th>
                                    <?php endif; ?>
                                    <th>Pending</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr onclick="toggleSubRows('status-row')">
                                    <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                    <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                    <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($in_warehouse_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php if ($on_water_combined > 0): ?>
                                    <td><?php echo number_format($on_water_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <?php if ($cleared_customs_combined > 0): ?>
                                    <td><?php echo number_format($cleared_customs_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <td><?php echo number_format($pending_combined,($view_mode=='mw')?2:0);?></td>
                                </tr>
                                <?php foreach($sub_rows_status as $lbl=>$srs): ?>
                                    <tr class="status-row" style="display:none;">
                                        <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                        <td><?php echo htmlspecialchars($srs['wattage_label']);?></td>
                                        <td><?php echo number_format($srs['total_order'],($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format($srs['delivered'],($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format($srs['in_warehouse'],($view_mode=='mw')?2:0);?></td>
                                        <?php if ($on_water_combined > 0): ?>
                                        <td><?php echo number_format($srs['on_water'],($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($cleared_customs_combined > 0): ?>
                                        <td><?php echo number_format($srs['cleared_customs'],($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <td><?php echo number_format($srs['pending'],($view_mode=='mw')?2:0);?></td>
                                    </tr>
                                <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                    <h2>Delivery Overview</h2>
                    <div class="chart-container">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Info -->
        <div id="financial-info">
            <div class="tables-and-charts">
                <div class="left-side">
                    <h2>Invoices and Cashflow Forecast</h2>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Open Invoices</th>
                                    <th>Total Costs</th>
                                    <?php foreach($weeks_financial as $wf): ?>
                                        <th><?php echo $wf['end']->format('n/j'); ?></th>
                                    <?php endforeach;?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                    <td>
                                        <a href="invoices.php?project_id=<?php echo $project_id; ?>">
                                            $<?php echo number_format($open_invoices_total,2);?>
                                        </a>
                                    </td>
                                    <td>$<?php echo number_format($total_logistics_cost,2);?></td>
                                    <?php foreach($weeks_financial as $ix=>$wf){
                                        $val = $anticipated_deliveries_financial[$ix] ?? 0;
                                        echo "<td>$".number_format($val,2)."</td>";
                                    } ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <h2>Forecasted vs Actual Cost</h2>
                    <canvas id="budgetLineChart"></canvas>
                </div>

                <div class="right-side">
                    <h2>Cost per Unit</h2>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Module Type</th>
                                    <th>Total Costs</th>
                                    <th>Price Per Pallet</th>
                                    <th>Price Per Module</th>
                                    <th>Price Per Watt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Combined row -->
                                <tr onclick="toggleSubRows('cost-row')">
                                    <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                    <td><?php echo htmlspecialchars($combined_label);?></td>
                                    <td>$<?php echo number_format($combined_total_costs,2);?></td>
                                    <td>$<?php echo number_format($combined_ppp,2);?></td>
                                    <td>$<?php echo number_format($combined_ppm,2);?></td>
                                    <td>$<?php echo number_format($combined_ppw,4);?></td>
                                </tr>
                                <!-- Detailed rows -->
                                <?php foreach($cost_data as $key=>$cd): ?>
                                    <tr class="cost-row" style="display:none;">
                                        <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                        <td><?php echo htmlspecialchars($cd['module_type']);?></td>
                                        <td>$<?php echo number_format($cd['total_costs'],2);?></td>
                                        <td>$<?php echo number_format($cd['price_per_pallet'],2);?></td>
                                        <td>$<?php echo number_format($cd['price_per_module'],2);?></td>
                                        <td>$<?php echo number_format($cd['price_per_watt'],4);?></td>
                                    </tr>
                                <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                    <h2>Cost Breakdown</h2>
                    <div class="chart-container">
                        <canvas id="costPieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<script>
<?php if ($role !== 'admin' && $role !== 'global_admin'): ?>
// Delivery View line chart (for regular users)
var dateLabels = <?php echo $dateLabelsJSON; ?>;
var lineData   = <?php echo $lineChartDataJSON; ?>;
var ctxLineEl  = document.getElementById('lineChart');
if(ctxLineEl){
var ctxLine    = ctxLineEl.getContext('2d');
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
        tooltips: { mode:'index', intersect:false },
        hover:     { mode:'index', intersect:false },
        scales: {
            xAxes: [{
                type:'time',
                time:{
                    parser:'YYYY-MM-DD',
                    tooltipFormat:'ll',
                    unit:'month',
                    displayFormats:{month:'MMM YYYY'}
                },
                scaleLabel:{display:true, labelString:'Date'}
            }],
            yAxes: [{
                ticks:{beginAtZero:true, precision:0},
                scaleLabel:{
                    display:true,
                    labelString:'<?php echo ($view_mode=="mw") ? "MWs" : "Number of Modules";?>'
                }
            }]
        }
    }
});
}

// Delivery Overview pie (for regular users)
var pieChartData   = <?php echo json_encode(array_values($pieChartPercentages));?>;
var pieChartLabels = <?php echo json_encode(array_keys($pieChartPercentages));?>;
var ctxPieEl       = document.getElementById('pieChart');
if(ctxPieEl){
var ctxPie         = ctxPieEl.getContext('2d');
var pieChart = new Chart(ctxPie,{
    type:'pie',
    data:{
        labels: pieChartLabels,
        datasets:[{
            data: pieChartData,
            backgroundColor:[
                '#488C9A',
                '#293E4C',
                '#fbb040'
            ]
        }]
    },
    options:{
        plugins:{
            tooltip:{
                callbacks:{
                    label:function(context){
                        var lab=context.label||'';
                        var val=context.parsed||0;
                        return lab+': '+ val.toFixed(2)+'%';
                    }
                }
            }
        }
    }
});
}
<?php endif; ?>

function showView(viewId) {
    <?php if ($role === 'admin' || $role === 'global_admin'): ?>
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

<?php if ($role === 'admin' || $role === 'global_admin'): ?>
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
const shippingBreakdown = <?php echo json_encode($detailed_breakdown); ?>;
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
    let html='<div style="max-height:400px;overflow-y:auto;">';
    let has=false;
    
    // Handle special case for "Delivered" status
    if(filter === 'Delivered') {
        has = true;
        const totalDeliveredRaw = <?php echo $delivered_raw_total; ?>;
        const totalDeliveredMW = <?php echo $delivered_combined; ?>;
        const totalPallets = Math.round(totalDeliveredRaw / 30);
        
        html += `<div style="margin-bottom:20px;padding:15px;background:#e8f5e8;border-radius:8px;border-left:4px solid #28a745;">` +
               `<h4 style="margin-top:0;color:#28a745;">Delivered to Project</h4>` +
               `<p><strong>Total:</strong> ${totalPallets} pallets, ${totalDeliveredRaw.toLocaleString()} modules` +
               `<?php if($view_mode == 'mw'): ?> (${totalDeliveredMW.toFixed(2)} MW)<?php endif; ?></p>`;
        
        // Show wattage breakdown for delivered modules        
        const deliveredBreakdown = <?php echo json_encode($delivered_by_wattage); ?>;
        if(deliveredBreakdown.length > 0) {
            html += '<p><strong>Wattage Breakdown:</strong></p><ul>';
            deliveredBreakdown.forEach(function(item) {
                const pallets = Math.round(item.modules / 30);
                html += `<li>${item.wattage}W: ${pallets} pallets (${item.modules.toLocaleString()} modules)</li>`;
            });
            html += '</ul>';
        }
        
        html += `<div style="text-align:center;margin-top:15px;">` +
               `<a href="manage_deliveries?project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#28a745;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">View Delivery Details</a>` +
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
            html+=`<div style="text-align:center;margin-top:15px;"><a href="create_shipment.php?project_id=<?php echo $project_id; ?>&status_filter=Cleared%20Customs" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Create Shipment</a></div>`;
        }else if(filter==='In Warehouse'){
            html+=`<div style="text-align:center;margin-top:15px;"><a href="create_shipment.php?project_id=<?php echo $project_id; ?>&status_filter=In%20Warehouse" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Create Shipment</a></div>`;
        }else if(filter==='In Transit to Project'){
            html+=`<div style="text-align:center;margin-top:15px;"><a href="scheduling.php?project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Schedule Deliveries</a></div>`;
        }
    }
    
    html+='</div>';
    return html;
}

// Admin warehousing functionality
const warehousesWithInventory = <?php echo json_encode($warehouses_with_inventory); ?>;
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

<?php if ($role !== 'admin' && $role !== 'global_admin'): ?>
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

// Prepare costPie + budgetLineChart (for regular users)
var pieChartDataFinancial = <?php echo json_encode($pieChartDataFinancial);?>;
var dateLabelsForBudget   = <?php echo $dateLabelsForBudget;?>;
var budgetLineData        = <?php echo $budgetLineChartDataJSON;?>;

function initializeFinancialCharts(){
    // Cost Breakdown Pie
    var costPie = document.getElementById('costPieChart').getContext('2d');
    var costPieLabels = Object.keys(pieChartDataFinancial);
    var costPieValues = Object.values(pieChartDataFinancial);

    var colorMap = {
        'Customer Cost': '#488C9A',
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

    // Forecasted vs Actual cost line chart
    var ctxBudget = document.getElementById('budgetLineChart').getContext('2d');
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
            tooltips:{
                mode:'index',
                intersect:false,
                callbacks:{
                    label:function(ti, data){
                        return data.datasets[ti.datasetIndex].label+': $'+ parseFloat(ti.value).toFixed(2);
                    }
                }
            },
            hover:{mode:'index', intersect:false},
            scales:{
                xAxes:[{
                    type:'time',
                    time:{
                        parser:'YYYY-MM-DD',
                        tooltipFormat:'ll',
                        unit:'month',
                        displayFormats:{month:'MMM YYYY'}
                    },
                    scaleLabel:{display:true, labelString:'Date'}
                }],
                yAxes:[{
                    ticks:{
                        beginAtZero:true,
                        callback:function(val){return '$'+val.toFixed(2);}
                    },
                    scaleLabel:{display:true, labelString:'Cost (USD)'}
                }]
            }
        }
    });
}
<?php endif; ?>

<?php if ($role === 'admin' || $role === 'global_admin'): ?>
// View Toggle functionality (for admins only)
function switchView(view) {
    const adminButtons = document.getElementById('admin-buttons');
    const customerButtons = document.getElementById('customer-buttons');
    const adminBtn = document.getElementById('admin-view-btn');
    const customerBtn = document.getElementById('customer-view-btn');
    const adminContent = document.getElementById('admin-view-content');
    const customerContent = document.getElementById('customer-view-content');
    
    if (view === 'admin') {
        adminButtons.style.display = 'block';
        customerButtons.style.display = 'none';
        adminBtn.classList.add('active');
        customerBtn.classList.remove('active');
        adminContent.style.display = 'block';
        customerContent.style.display = 'none';
    } else {
        adminButtons.style.display = 'none';
        customerButtons.style.display = 'block';
        adminBtn.classList.remove('active');
        customerBtn.classList.add('active');
        adminContent.style.display = 'none';
        customerContent.style.display = 'block';
        initializeAdminCustomerCharts();
    }
}

// Customer view functions for admins
function showCustomerView(viewId) {
    document.getElementById('delivery-info-admin').style.display = 'none';
    document.getElementById('financial-info-admin').style.display = 'none';

    document.getElementById('delivery-info-btn-admin').classList.remove('active');
    document.getElementById('financial-info-btn-admin').classList.remove('active');

    if(viewId === 'delivery-info'){
        document.getElementById('delivery-info-admin').style.display = 'block';
        document.getElementById('delivery-info-btn-admin').classList.add('active');
        initializeAdminCustomerCharts();
    } else {
        document.getElementById('financial-info-admin').style.display = 'block';
        document.getElementById('financial-info-btn-admin').classList.add('active');
        initializeAdminFinancialCharts();
    }
}

function toggleSubRowsAdmin(cls){
    var rows = document.getElementsByClassName(cls);
    for(var i=0; i<rows.length; i++){
        if(rows[i].style.display==='' || rows[i].style.display==='none'){
            rows[i].style.display='table-row';
        } else {
            rows[i].style.display='none';
        }
    }
}

// Initialize charts for admin customer view
function initializeAdminCustomerCharts() {
    // Delivery View line chart (for admin customer view)
    var dateLabels = <?php echo $dateLabelsJSON; ?>;
    var lineData   = <?php echo $lineChartDataJSON; ?>;
    var ctxLineEl  = document.getElementById('lineChartAdmin');
    
    if(ctxLineEl && !ctxLineEl.chartInitialized){
        var ctxLine = ctxLineEl.getContext('2d');
        new Chart(ctxLine, {
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
                tooltips: { mode:'index', intersect:false },
                hover:     { mode:'index', intersect:false },
                scales: {
                    xAxes: [{
                        type:'time',
                        time:{
                            parser:'YYYY-MM-DD',
                            tooltipFormat:'ll',
                            unit:'month',
                            displayFormats:{month:'MMM YYYY'}
                        },
                        scaleLabel:{display:true, labelString:'Date'}
                    }],
                    yAxes: [{
                        ticks:{beginAtZero:true, precision:0},
                        scaleLabel:{
                            display:true,
                            labelString:'<?php echo ($view_mode=="mw") ? "MWs" : "Number of Modules";?>'
                        }
                    }]
                }
            }
        });
        ctxLineEl.chartInitialized = true;
    }

    // Delivery Overview pie (for admin customer view)
    var pieChartData   = <?php echo json_encode(array_values($pieChartPercentages));?>;
    var pieChartLabels = <?php echo json_encode(array_keys($pieChartPercentages));?>;
    var ctxPieEl       = document.getElementById('pieChartAdmin');
    
    if(ctxPieEl && !ctxPieEl.chartInitialized){
        var ctxPie = ctxPieEl.getContext('2d');
        new Chart(ctxPie,{
            type:'pie',
            data:{
                labels: pieChartLabels,
                datasets:[{
                    data: pieChartData,
                    backgroundColor:[
                        '#488C9A',
                        '#293E4C',
                        '#fbb040'
                    ]
                }]
            },
            options:{
                plugins:{
                    tooltip:{
                        callbacks:{
                            label:function(context){
                                var lab=context.label||'';
                                var val=context.parsed||0;
                                return lab+': '+ val.toFixed(2)+'%';
                            }
                        }
                    }
                }
            }
        });
        ctxPieEl.chartInitialized = true;
    }
}

// Initialize financial charts for admin customer view
function initializeAdminFinancialCharts() {
    var pieChartDataFinancial = <?php echo json_encode($pieChartDataFinancial);?>;
    var dateLabelsForBudget   = <?php echo $dateLabelsForBudget;?>;
    var budgetLineData        = <?php echo $budgetLineChartDataJSON;?>;

    // Cost Breakdown Pie
    var costPieEl = document.getElementById('costPieChartAdmin');
    if(costPieEl && !costPieEl.chartInitialized) {
        var costPie = costPieEl.getContext('2d');
        var costPieLabels = Object.keys(pieChartDataFinancial);
        var costPieValues = Object.values(pieChartDataFinancial);

        var colorMap = {
            'Customer Cost': '#488C9A',
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
    }

    // Forecasted vs Actual cost line chart
    var ctxBudgetEl = document.getElementById('budgetLineChartAdmin');
    if(ctxBudgetEl && !ctxBudgetEl.chartInitialized) {
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
                tooltips:{
                    mode:'index',
                    intersect:false,
                    callbacks:{
                        label:function(ti, data){
                            return data.datasets[ti.datasetIndex].label+': $'+ parseFloat(ti.value).toFixed(2);
                        }
                    }
                },
                hover:{mode:'index', intersect:false},
                scales:{
                    xAxes:[{
                        type:'time',
                        time:{
                            parser:'YYYY-MM-DD',
                            tooltipFormat:'ll',
                            unit:'month',
                            displayFormats:{month:'MMM YYYY'}
                        },
                        scaleLabel:{display:true, labelString:'Date'}
                    }],
                    yAxes:[{
                        ticks:{
                            beginAtZero:true,
                            callback:function(val){return '$'+val.toFixed(2);}
                        },
                        scaleLabel:{display:true, labelString:'Cost (USD)'}
                    }]
                }
            }
        });
        ctxBudgetEl.chartInitialized = true;
    }
}

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
            if (filterType === 'truckloads' || filterType === 'mws') {
                statusCount.textContent = value % 1 === 0 ? value.toString() : value.toFixed(1);
            } else {
                statusCount.textContent = Math.round(value).toLocaleString();
            }
            
            statusUnit.textContent = unit;
        }
    });
}

// Initialize shipping filters when page loads
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.shipping-filters')) {
        initializeShippingFilters();
    }
});

<?php endif; ?>

// Dropdown functionality
function toggleCustomerDeliveriesDropdown() {
    var dropdown = document.getElementById("customerDeliveriesDropdown");
    var dropdownBtn = document.querySelector("#customer-buttons .dropdown-btn");
    
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
    var dropdownBtn = document.querySelector("#admin-buttons .dropdown:last-child .dropdown-btn");
    
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
    
    <?php if ($role === 'admin' || $role === 'global_admin'): ?>
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

<?php if ($role === 'admin' || $role === 'global_admin'): ?>
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

<!-- Delete Confirmation Modal -->
<?php if ($role === 'admin' || $role === 'global_admin'): ?>
<div id="deleteModal" class="delete-modal">
    <div class="delete-modal-content">
        <h3>⚠️ Confirm Project Deletion</h3>
        <p id="deleteModalText">Are you sure you want to delete this project? This action cannot be undone.</p>
        <div class="modal-buttons">
            <button class="modal-btn btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <button class="modal-btn btn-delete" onclick="confirmDelete()">Delete Project</button>
        </div>
    </div>
</div>

<!-- Add Module Modal -->
<div id="addModuleModal" class="add-module-modal">
    <div class="add-module-modal-content">
        <h3>Add New Module Batch</h3>
        <form id="addModuleForm">
            <div class="modal-form-grid">
                <div class="modal-form-group">
                    <label for="modal_manufacturer_id">Manufacturer:</label>
                    <select id="modal_manufacturer_id" name="manufacturer_id">
                        <option value="">Select Manufacturer</option>
                    </select>
                </div>
                
                <div class="modal-form-group">
                    <label for="modal_vendor_name">Vendor Name:</label>
                    <input type="text" id="modal_vendor_name" name="vendor_name" required>
                </div>
                
                <div class="modal-form-group">
                    <label for="modal_initial_location">Initial Location:</label>
                    <input type="text" id="modal_initial_location" name="initial_location" required>
                </div>
                
                <div class="modal-form-group">
                    <label for="modal_modules_per_pallet">Modules per Pallet:</label>
                    <input type="number" id="modal_modules_per_pallet" name="modules_per_pallet" min="1">
                </div>
                
                <div class="wattage-section">
                    <h4>Module Wattages & Quantities</h4>
                    <div id="modal_wattage_container">
                        <!-- Wattage entries will be added here -->
                    </div>
                    <button type="button" class="add-wattage-btn" onclick="addModalWattageField()">+ Add Wattage</button>
                </div>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="modal-btn btn-secondary" onclick="closeAddModuleModal()">Cancel</button>
                <button type="submit" class="modal-btn btn-primary">Add Module Batch</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Batch Modal -->
<div id="editBatchModal" class="add-module-modal">
    <div class="add-module-modal-content">
        <h3 id="editBatchModalTitle">Edit Module Batch</h3>
        <form id="editBatchForm">
            <input type="hidden" id="edit_batch_id" name="batch_id">
            <div class="wattage-section">
                <h4>Module Wattages & Quantities</h4>
                <div id="edit_wattage_container">
                    <!-- Existing wattage entries will be loaded here -->
                </div>
                <button type="button" class="add-wattage-btn" onclick="addEditWattageField()">+ Add Wattage</button>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="modal-btn btn-secondary" onclick="closeEditBatchModal()">Cancel</button>
                <button type="submit" class="modal-btn btn-primary">Update Module Batch</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

</body>
</html>
