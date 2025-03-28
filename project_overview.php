<?php
session_name("logistics_session");
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Grab the user's role from the session
$role = $_SESSION['role'] ?? 'user';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['id']);

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
$stmt = $conn->prepare("
    SELECT wattage, status_of_delivery, SUM(quantity) AS total_quantity
    FROM deliveries
    WHERE project_id=?
    GROUP BY wattage, status_of_delivery
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$del_st_res = $stmt->get_result();
$stmt->close();

$delivery_totals = [];
while ($row = $del_st_res->fetch_assoc()) {
    $w   = (float)$row['wattage'];
    $lbl = $w . 'W';
    $st  = $row['status_of_delivery'];
    $q_calc = calculateQuantity((int)$row['total_quantity'], $w, $view_mode);

    if (!isset($delivery_totals[$lbl])) {
        $delivery_totals[$lbl] = [
            'Delivered'    => 0,
            'In Warehouse' => 0,
            'Produced'     => 0,
        ];
    }
    if (isset($delivery_totals[$lbl][$st])) {
        $delivery_totals[$lbl][$st] = $q_calc;
    }
}

// Summaries
$total_order_combined      = 0;
$delivered_combined        = 0;
$in_warehouse_combined     = 0;
$produced_combined         = 0;
$not_yet_produced_combined = 0;

$pieChartData = [
    'Delivered to Site' => 0,
    'In Warehouse'      => 0,
    'Produced'          => 0,
    'Not Yet Produced'  => 0,
];

$sub_rows        = [];
$sub_rows_status = [];

foreach ($total_orders as $lbl => $info) {
    $w  = (float)$info['wattage'];
    $to = (float)$info['total_order'];

    $del = $delivery_totals[$lbl]['Delivered']    ?? 0;
    $inw = $delivery_totals[$lbl]['In Warehouse'] ?? 0;
    $prd = $delivery_totals[$lbl]['Produced']     ?? 0;
    $nyp = $to - ($del + $inw + $prd);

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
        'produced'           => $prd,
        'not_yet_produced'   => $nyp,
    ];

    $total_order_combined      += $to;
    $delivered_combined        += $del;
    $in_warehouse_combined     += $inw;
    $produced_combined         += $prd;
    $not_yet_produced_combined += $nyp;

    $pieChartData['Delivered to Site'] += $del;
    $pieChartData['In Warehouse']      += $inw;
    $pieChartData['Produced']          += $prd;
    $pieChartData['Not Yet Produced']  += $nyp;
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
$total_freight_cost      = 0;
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
    SELECT w.id, w.in_fee, w.out_fee, w.monthly_storage_fee
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
    $warehouse = $whres->fetch_assoc();
}

// Build actual total cost from deliveries
while ($dv = $dres->fetch_assoc()) {
    $stat = $dv['status_of_delivery'];
    $watt= (float)$dv['wattage'];
    $f   = (float)$dv['freight_cost'];
    $a   = (float)$dv['accessorial_costs'];
    $q   = (int)$dv['quantity'];

    $wcost = calcWarehousingCost($dv, $warehouse);

    if (!empty($dv['actual_delivery_date'])) {
        $soltFeeForThisDelivery = $solterra_fee * ($watt * $q);
    } else {
        $soltFeeForThisDelivery = 0;
    }

    $tc = $f + $a + $wcost + $soltFeeForThisDelivery;

    $total_freight_cost      += $f;
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
    'Freight'      => $total_freight_cost,
    'Warehousing'  => $total_warehousing_cost,
    'Accessorial'  => $total_accessorial_costs,
    'Solterra Fee' => $total_solterra_fee,
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
        $fr   = (float)$dv['freight_cost'];
        $ac   = (float)$dv['accessorial_costs'];
        $fee  = $solterra_fee*($watt*$qty);
        $actual_tc = $fr + $ac + $wh + $fee;
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

$conn->close();

// Determine the correct link for the "Deliveries" button
// If user is "admin", go to manage_deliveries.php; else "view_project.php"
$deliveriesLink = ($role === 'admin')
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
.toggle-buttons {
    margin: 20px 0;
    text-align: center;
}
.toggle-buttons button {
    padding: 10px 20px;
    margin: 0 10px;
    cursor: pointer;
    font-size: 16px;
}
.toggle-buttons button.active {
    background-color: #293E4C;
    color: #fff;
}
#financial-info {
    display: none;
}
.tables-and-charts table tr:hover {
    background-color: #f1f1f1;
}
.tables-and-charts table tr {
    cursor: pointer;
}
.chart-container {
    max-width: 400px;
    margin: 0 auto;
}
.table-responsive {
    overflow-x: auto;
}
.table-responsive table {
    width: 100%;
    border-collapse: collapse;
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
}
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <a href="dashboard" class="back-icon" style="margin:20px;">
        <!-- Simple Back Arrow -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:24px;height:24px;">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>
    <div class="project-overview-container">
        <!-- Mobile Project Name -->
        <h1 class="project-name-mobile"><?php echo htmlspecialchars($project['project_name']); ?></h1>
        
        <div class="project-overview-image">
            <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="Project Overview Image">
        </div>
        
        <div class="project-info">
            <!-- Desktop Project Name -->
            <h1 class="project-name-desktop"><?php echo htmlspecialchars($project['project_name']); ?></h1>
            <p><strong>Project Address:</strong> <?php echo htmlspecialchars($project['project_address']); ?></p>
            <p><strong>Project Size:</strong> <?php echo number_format($project_size_mw, 2); ?> MWs</p>
            <!-- Deliveries button goes to manage_deliveries if admin, otherwise view_project -->
            <button onclick="window.location.href='<?php echo $deliveriesLink; ?>'">Deliveries</button>
            <button onclick="window.location.href='warehouse_info?project_id=<?php echo $project_id; ?>'">Warehousing</button>
            <button onclick="window.location.href='project_cost_details?project_id=<?php echo $project_id; ?>'">Costs</button>
            <button onclick="window.location.href='project_documents?project_id=<?php echo $project_id; ?>'">Documents</button>
            <button onclick="window.location.href='project_sustainability_details?project_id=<?php echo $project_id; ?>'">Sustainability</button>
        </div>
    </div>

    <div class="toggle-buttons">
        <button id="delivery-info-btn" class="active" onclick="showView('delivery-info')">Delivery View</button>
        <button id="financial-info-btn" onclick="showView('financial-info')">Financial View</button>
    </div>

    <!-- Delivery Info -->
    <div id="delivery-info">
        <form method="GET" id="filter-form">
            <input type="hidden" name="id" value="<?php echo $project_id; ?>">
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
                                <th>Delivered</th>
                                <th>In Warehouse</th>
                                <th>Produced</th>
                                <th>Not Yet Produced</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr onclick="toggleSubRows('status-row')">
                                <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($in_warehouse_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($produced_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($not_yet_produced_combined,($view_mode=='mw')?2:0);?></td>
                            </tr>
                            <?php foreach($sub_rows_status as $lbl=>$srs): ?>
                                <tr class="status-row" style="display:none;">
                                    <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                    <td><?php echo htmlspecialchars($srs['wattage_label']);?></td>
                                    <td><?php echo number_format($srs['total_order'],($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($srs['delivered'],($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($srs['in_warehouse'],($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($srs['produced'],($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($srs['not_yet_produced'],($view_mode=='mw')?2:0);?></td>
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
</main>

<script>
// Delivery View line chart
var dateLabels = <?php echo $dateLabelsJSON; ?>;
var lineData   = <?php echo $lineChartDataJSON; ?>;
var ctxLine    = document.getElementById('lineChart').getContext('2d');

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

// Delivery Overview pie
var pieChartData   = <?php echo json_encode(array_values($pieChartPercentages));?>;
var pieChartLabels = <?php echo json_encode(array_keys($pieChartPercentages));?>;
var ctxPie         = document.getElementById('pieChart').getContext('2d');
var pieChart = new Chart(ctxPie,{
    type:'pie',
    data:{
        labels: pieChartLabels,
        datasets:[{
            data: pieChartData,
            backgroundColor:[
                '#488C9A',
                '#293E4C',
                '#fbb040',
                '#E4572E'
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

function showView(viewId) {
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

// Prepare costPie + budgetLineChart
var pieChartDataFinancial = <?php echo json_encode($pieChartDataFinancial);?>;
var dateLabelsForBudget   = <?php echo $dateLabelsForBudget;?>;
var budgetLineData        = <?php echo $budgetLineChartDataJSON;?>;

function initializeFinancialCharts(){
    // Cost Breakdown Pie
    var costPie = document.getElementById('costPieChart').getContext('2d');
    var costPieLabels = Object.keys(pieChartDataFinancial);
    var costPieValues = Object.values(pieChartDataFinancial);

    var colorMap = {
        'Freight':      '#488C9A',
        'Warehousing':  '#293E4C',
        'Accessorial':  '#fbb040',
        'Solterra Fee': '#BFBFBF'
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
</script>
</body>
</html>
