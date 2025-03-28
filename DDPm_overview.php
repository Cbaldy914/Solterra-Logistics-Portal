<?php
session_name("logistics_session");
session_start();



// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

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

// We'll still support the "mw" vs "modules" toggle for the Overview tab
$view_mode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'mw';

/**
 * Convert raw quantity to either:
 *   - Number of modules
 *   - MW (modules * wattage / 1,000,000)
 */
function calculateQuantity($quantity, $wattage, $view_mode) {
    if ($view_mode == 'modules') {
        return $quantity;
    } elseif ($view_mode == 'mw') {
        return ($quantity * $wattage) / 1000000;
    } else {
        return $quantity;
    }
}

// Fetch project info
$stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    die("Project not found.");
}
$project = $result->fetch_assoc();
$stmt->close();

// Fetch total orders (wattage + total_order)
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

    // For overall project size
    $project_size_mw += ($t * $w) / 1_000_000;

    // For table display
    $label = $w . 'W';
    $total_orders[$label] = [
        'wattage'      => $w,
        'total_order'  => calculateQuantity($t, $w, $view_mode),
        'raw_quantity' => $t,
    ];
}
$stmt->close();

// Build a combined label for modules
$non_zero_watts = array_filter($wattages, fn($v) => $v > 0);
if (count($non_zero_watts) > 0) {
    $min_w = min($non_zero_watts);
    $max_w = max($non_zero_watts);
    $module_type_combined = ($min_w == $max_w)
        ? ($min_w . 'W')
        : ($min_w . 'W-' . $max_w . 'W');
} else {
    $module_type_combined = "N/A";
}

// Fetch anticipated vs actual deliveries (line chart data for the Overview)
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
    $d = $r['delivery_date'];
    $raw_q = (int)$r['quantity'];
    $q_calc = calculateQuantity($raw_q, $w, $view_mode);

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
    $d = $r['delivery_date'];
    $raw_q = (int)$r['quantity'];
    $q_calc = calculateQuantity($raw_q, $w, $view_mode);

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
        // future dates => no actual data
        $lineChartData_actual[] = null;
    }
}
$lineChartData = [
    'anticipated' => $lineChartData_anticipated,
    'actual'      => $lineChartData_actual,
];
$dateLabelsJSON    = json_encode($date_labels);
$lineChartDataJSON = json_encode($lineChartData);

// Fetch status_of_delivery sums
$stmt = $conn->prepare("
    SELECT wattage, status_of_delivery, SUM(quantity) AS total_quantity
    FROM deliveries
    WHERE project_id = ?
    GROUP BY wattage, status_of_delivery
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$del_st_res = $stmt->get_result();
$stmt->close();

$delivery_totals = [];
while ($row = $del_st_res->fetch_assoc()) {
    $wattage = (float)$row['wattage'];
    $lbl     = $wattage . 'W';
    $status  = $row['status_of_delivery'];
    $calc_q  = calculateQuantity((int)$row['total_quantity'], $wattage, $view_mode);

    if (!isset($delivery_totals[$lbl])) {
        // We'll track these columns: Delivered, Cleared Customs, In Warehouse, Produced
        $delivery_totals[$lbl] = [
            'Delivered'      => 0,
            'Cleared Customs' => 0,
            'In Warehouse'   => 0,
            'Produced'       => 0,
        ];
    }

    if (isset($delivery_totals[$lbl][$status])) {
        $delivery_totals[$lbl][$status] = $calc_q;
    }
}

$total_order_combined      = 0;
$delivered_combined        = 0;
$cleared_customs_combined  = 0;
$in_warehouse_combined     = 0;
$produced_combined         = 0;
$not_yet_produced_combined = 0;

$sub_rows        = []; // for Next 5 weeks
$sub_rows_status = []; // for Delivery Status

foreach ($total_orders as $lbl => $info) {
    $w  = (float)$info['wattage'];
    $to = (float)$info['total_order'];

    $del = $delivery_totals[$lbl]['Delivered']      ?? 0;
    $clr = $delivery_totals[$lbl]['Cleared Customs'] ?? 0;
    $inw = $delivery_totals[$lbl]['In Warehouse']   ?? 0;
    $prd = $delivery_totals[$lbl]['Produced']       ?? 0;
    $nyp = $to - ($del + $clr + $inw + $prd);

    // For the Next 5 weeks table
    $sub_rows[$lbl] = [
        'wattage_label' => $lbl,
        'total_order'   => $to,
        'delivered'     => $del,
        'anticipated_quantities' => [],
    ];

    // For the Delivery Status table
    $sub_rows_status[$lbl] = [
        'wattage_label'     => $lbl,
        'total_order'       => $to,
        'delivered'         => $del,
        'cleared_customs'   => $clr,
        'in_warehouse'      => $inw,
        'produced'          => $prd,
        'not_yet_produced'  => $nyp,
    ];

    $total_order_combined      += $to;
    $delivered_combined        += $del;
    $cleared_customs_combined  += $clr;
    $in_warehouse_combined     += $inw;
    $produced_combined         += $prd;
    $not_yet_produced_combined += $nyp;
}

// Next 5 weeks date logic
$today2     = new DateTime();
$weeks      = [];
$weekEnding = clone $today2;
$dayOfWeek  = $weekEnding->format('w');
if ($dayOfWeek != 0) {
    $weekEnding->modify('next Sunday');
}
for ($i = 0; $i < 5; $i++) {
    $start = clone $weekEnding;
    $start->modify('-6 days');
    $weeks[] = ['start' => $start, 'end' => clone $weekEnding];
    $weekEnding->modify('+1 week');
}

// Get anticipated deliveries by label
$anticipated_deliveries_by_lbl = [];
foreach ($total_orders as $lbl => $info) {
    $anticipated_deliveries_by_lbl[$lbl] = [];
}
$stmt = $conn->prepare("
    SELECT wattage, anticipated_delivery_date AS ddate, SUM(quantity) AS q
    FROM deliveries
    WHERE project_id = ? AND anticipated_delivery_date IS NOT NULL
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

// --- New Section for Warranty/Safety Metrics ---

// Open a new connection for these queries
$conn2 = getDBConnection();
if (!$conn2) {
    die("Connection failed");
}

// Helper function to get total modules rejected from a JSON string
function getModulesRejectedCount($json) {
    $sum = 0;
    if (!empty($json)) {
        $arr = json_decode($json, true);
        if (is_array($arr)) {
            foreach ($arr as $item) {
                if (isset($item['qty'])) {
                    $sum += (int)$item['qty'];
                }
            }
        }
    }
    return $sum;
}

// Warranty Overview query
$warrantyOverviewQuery = "
SELECT w.id AS warranty_id, w.status, w.modules_rejected, s.id AS scheduling_id
FROM warranty_claims w
JOIN site_scheduling s ON w.scheduling_id = s.id
JOIN sites si ON s.site_id = si.id
WHERE si.project_id = ?
";
$stmt_w = $conn2->prepare($warrantyOverviewQuery);
$stmt_w->bind_param("i", $project_id);
$stmt_w->execute();
$result_w = $stmt_w->get_result();

$warranty_incidents = 0;
$pending_claims = 0;
$resolved_claims = 0;
$rejected_claims = 0;
$modules_damaged = 0;
$warranty_delivery_ids = [];

while ($row = $result_w->fetch_assoc()) {
    $warranty_incidents++;
    $status = strtolower($row['status']);
    if (!in_array($row['scheduling_id'], $warranty_delivery_ids)) {
        $warranty_delivery_ids[] = $row['scheduling_id'];
    }
    if ($status == 'pending') {
        $pending_claims++;
    } elseif ($status == 'resolved') {
        $resolved_claims++;
    } elseif ($status == 'rejected') {
        $rejected_claims++;
    }
    $modules_damaged += getModulesRejectedCount($row['modules_rejected']);
}
$total_deliveries = count($warranty_delivery_ids);
$stmt_w->close();

// Site Safety/Carrier Performance query (modified to include scheduling_id)
$siteSafetyQuery = "
SELECT ss.id AS safety_id, ss.report_driver, s.id AS scheduling_id
FROM site_safety ss
JOIN site_scheduling s ON ss.scheduling_id = s.id
JOIN sites si ON s.site_id = si.id
WHERE si.project_id = ?
";
$stmt_s = $conn2->prepare($siteSafetyQuery);
$stmt_s->bind_param("i", $project_id);
$stmt_s->execute();
$result_s = $stmt_s->get_result();

$safety_incidents = 0;
$driver_set = [];
$safety_delivery_ids = [];
while ($row = $result_s->fetch_assoc()) {
    $safety_incidents++;
    if (!in_array($row['scheduling_id'], $safety_delivery_ids)) {
        $safety_delivery_ids[] = $row['scheduling_id'];
    }
    if (!empty($row['report_driver'])) {
        $driver_set[$row['report_driver']] = true;
    }
}
$total_safety_deliveries = count($safety_delivery_ids);
$reported_drivers = count($driver_set);
$stmt_s->close();

$conn2->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DDPm Overview - <?php echo htmlspecialchars($project['project_name']); ?></title>
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
#overview-info,
#warranty-info {
    display: none; /* We toggle them */
}

/* New styles for Warranty/Safety quadrants */
.warranty-safety-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: auto auto;
    gap: 20px;
}
.quadrant {
    background-color: #f9f9f9;
    padding: 20px;
    border: 1px solid #ccc;
    border-radius: 8px;
}
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Simple back button -->
    <a href="dashboard" class="back-icon" style="margin:20px;">
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
            <!-- Buttons -->
            <button onclick="window.location.href='DDPm_deliveries?project_id=<?php echo $project_id; ?>'">Deliveries</button>
            <button onclick="window.location.href='warehouse_info?project_id=<?php echo $project_id; ?>'">Warehousing</button>
            <button onclick="window.location.href='project_warranty?project_id=<?php echo $project_id; ?>'">Warranty</button>
            <button onclick="window.location.href='project_safety?project_id=<?php echo $project_id; ?>'">Safety</button>
            <button onclick="window.location.href='project_documents?project_id=<?php echo $project_id; ?>'">Documents</button>
            <button onclick="window.location.href='project_sustainability_details?project_id=<?php echo $project_id; ?>'">Sustainability</button>
        </div>
    </div>

    <!-- Tabs: Removed KPIs tab; Warranty tab renamed to Warranty/Safety -->
    <div class="toggle-buttons">
        <button id="overview-tab-btn"  class="active" onclick="showTab('overview-info')">Overview</button>
        <button id="warranty-tab-btn"  onclick="showTab('warranty-info')">Warranty/Safety</button>
    </div>

    <!-- OVERVIEW TAB -->
    <div id="overview-info" style="display:block;"> 
        <!-- MW vs Modules toggle -->
        <form method="GET" id="filter-form">
            <input type="hidden" name="id" value="<?php echo $project_id; ?>">
            <label>
                <input type="radio" name="view_mode" value="mw"
                       <?php if($view_mode=='mw') echo 'checked';?>
                       onchange="document.getElementById('filter-form').submit();"> MW
            </label>
            <label>
                <input type="radio" name="view_mode" value="modules"
                       <?php if($view_mode=='modules') echo 'checked';?>
                       onchange="document.getElementById('filter-form').submit();"> Modules
            </label>
        </form>

        <div class="tables-and-charts">
            <div class="left-side">
                <!-- Next 5 Weeks Table -->
                <h2>Next 5 Weeks of Deliveries</h2>
                <div class="table-responsive">
                    <table id="table1">
                        <thead>
                            <tr>
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
                                <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                <?php foreach($anticipated_quantities_combined as $qq): ?>
                                    <td><?php echo number_format($qq,($view_mode=='mw')?2:0);?></td>
                                <?php endforeach;?>
                            </tr>
                            <?php foreach($sub_rows as $lbl=>$sr): ?>
                                <tr class="delivery-row" style="display:none;">
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
                <!-- Module Delivery Status Table -->
                <h2>Module Delivery Status</h2>
                <div class="table-responsive">
                    <table id="table2">
                        <thead>
                            <tr>
                                <th>Module Type</th>
                                <th>Total Order</th>
                                <th>Delivered</th>
                                <th>Cleared Customs</th>
                                <th>In Warehouse</th>
                                <th>Produced</th>
                                <th>Not Yet Produced</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr onclick="toggleSubRows('status-row')">
                                <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($cleared_customs_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($in_warehouse_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($produced_combined,($view_mode=='mw')?2:0);?></td>
                                <td><?php echo number_format($not_yet_produced_combined,($view_mode=='mw')?2:0);?></td>
                            </tr>
                            <?php foreach($sub_rows_status as $lbl=>$srs): ?>
                                <tr class="status-row" style="display:none;">
                                    <td><?php echo htmlspecialchars($srs['wattage_label']);?></td>
                                    <td><?php echo number_format($srs['total_order'],($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($srs['delivered'],($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($srs['cleared_customs'],($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($srs['in_warehouse'],($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($srs['produced'],($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($srs['not_yet_produced'],($view_mode=='mw')?2:0);?></td>
                                </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
                <h2>Overview</h2>
                <div class="chart-container">
                    <canvas id="pieChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- WARRANTY/SAFETY TAB (4 Quadrants) -->
    <div id="warranty-info">
        <div class="warranty-safety-container">
            <!-- Top Left Quadrant: Warranty Overview -->
            <div class="quadrant" id="quadrant1">
                <h2>Warranty Overview</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Total Deliveries</th>
                            <th>Warranty Incidents</th>
                            <th>Pending Claims</th>
                            <th>Resolved Claims</th>
                            <th>Rejected Claims</th>
                            <th>Modules Damaged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo $total_deliveries; ?></td>
                            <td><a href="project_warranty.php?project_id=<?php echo $project_id; ?>"><?php echo $warranty_incidents; ?></a></td>
                            <td><?php echo $pending_claims; ?></td>
                            <td><?php echo $resolved_claims; ?></td>
                            <td><?php echo $rejected_claims; ?></td>
                            <td><?php echo $modules_damaged; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <!-- Top Right Quadrant: Site Safety/Carrier Performance -->
            <div class="quadrant" id="quadrant2">
                <h2>Site Safety/Carrier Performance</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Total Deliveries</th>
                            <th>Safety Incidents</th>
                            <th>Reported Drivers</th>
                            <th>OTD %</th>
                            <th>Arrived without Appt</th>
                            <th>No Call No Show</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo $total_safety_deliveries; ?></td>
                            <td><a href="project_safety.php?project_id=<?php echo $project_id; ?>"><?php echo $safety_incidents; ?></a></td>
                            <td><?php echo $reported_drivers; ?></td>
                            <td>N/A</td>
                            <td>N/A</td>
                            <td>N/A</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
// Handle tab switching: "Overview", "Warranty/Safety"
function showTab(tabId) {
    document.getElementById('overview-info').style.display = 'none';
    document.getElementById('warranty-info').style.display = 'none';

    document.getElementById('overview-tab-btn').classList.remove('active');
    document.getElementById('warranty-tab-btn').classList.remove('active');

    document.getElementById(tabId).style.display = 'block';

    if (tabId === 'overview-info') {
        document.getElementById('overview-tab-btn').classList.add('active');
    } else {
        document.getElementById('warranty-tab-btn').classList.add('active');
    }
}

// Toggle sub-rows in the Overview tables
function toggleSubRows(rowClass) {
    var rows = document.getElementsByClassName(rowClass);
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].style.display === '' || rows[i].style.display === 'none') {
            rows[i].style.display = 'table-row';
        } else {
            rows[i].style.display = 'none';
        }
    }
}

// Build the Anticipated vs Actual Deliveries line chart
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
        tooltips: {
            mode: 'index',
            intersect: false
        },
        hover: {
            mode: 'index',
            intersect: false
        },
        scales: {
            xAxes: [{
                type: 'time',
                time: {
                    parser: 'YYYY-MM-DD',
                    tooltipFormat: 'll',
                    unit: 'month',
                    displayFormats: { month: 'MMM YYYY' }
                },
                scaleLabel: {
                    display: true,
                    labelString: 'Date'
                }
            }],
            yAxes: [{
                ticks: { beginAtZero: true, precision: 0 },
                scaleLabel: {
                    display: true,
                    labelString: '<?php echo ($view_mode=="mw") ? "MW" : "Number of Modules"; ?>'
                }
            }]
        }
    }
});

// Build the Delivery Overview pie chart (dummy data for now)
var ctxPie = document.getElementById('pieChart').getContext('2d');
var pieChart = new Chart(ctxPie, {
    type: 'pie',
    data: {
        labels: ['Delivered','Cleared Customs','In Warehouse','Produced','Not Yet Produced'],
        datasets: [{
            data: [
                <?php echo $delivered_combined; ?>,
                <?php echo $cleared_customs_combined; ?>,
                <?php echo $in_warehouse_combined; ?>,
                <?php echo $produced_combined; ?>,
                <?php echo $not_yet_produced_combined; ?>
            ],
            backgroundColor: [
                '#488C9A',
                '#fbb040',
                '#293E4C',
                '#a3c293',
                '#E4572E'
            ]
        }]
    },
    options: {
        tooltips: {
            callbacks: {
                label: function(tooltipItem, data) {
                    var label = data.labels[tooltipItem.index];
                    var val   = data.datasets[0].data[tooltipItem.index] || 0;
                    return label+': '+val.toLocaleString();
                }
            }
        }
    }
});
</script>
</body>
</html>
