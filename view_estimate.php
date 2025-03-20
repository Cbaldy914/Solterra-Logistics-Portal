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

$user_id = $_SESSION['user_id'];

$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check for an 'id' GET parameter (the ID of the saved estimate)
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "No estimate ID specified.";
    exit();
}

$estimate_id = intval($_GET['id']);

// Retrieve the estimate from the database
$stmt = $conn->prepare("SELECT * FROM warehouse_estimates WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $estimate_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Either no such estimate or it doesn't belong to this user
    echo "Estimate not found or access denied.";
    exit();
}

$estimate_row = $result->fetch_assoc();
$stmt->close();

// Decode the stored estimate_data
$estimate_data = json_decode($estimate_row['estimate_data'], true);

// Extract data from the JSON. This matches what was saved in calculator_results.
$months_array = isset($estimate_data['months']) ? $estimate_data['months'] : [];

// Build display_months from $months_array
$display_months = [];
foreach ($months_array as $m) {
    $dateObj = DateTime::createFromFormat('Y-m', $m);
    $display_months[] = $dateObj ? $dateObj->format('F Y') : '';
}

$current_inventory_w1 = isset($estimate_data['current_inventory']['w1']) ? intval($estimate_data['current_inventory']['w1']) : 0;
$current_inventory_w2 = isset($estimate_data['current_inventory']['w2']) ? intval($estimate_data['current_inventory']['w2']) : 0;
$current_inventory_w3 = isset($estimate_data['current_inventory']['w3']) ? intval($estimate_data['current_inventory']['w3']) : 0;

$pallets_entering_w1 = isset($estimate_data['pallets_entering_w1']) ? $estimate_data['pallets_entering_w1'] : [];
$pallets_leaving_w1  = isset($estimate_data['pallets_leaving_w1'])  ? $estimate_data['pallets_leaving_w1']  : [];
$pallets_entering_w2 = isset($estimate_data['pallets_entering_w2']) ? $estimate_data['pallets_entering_w2'] : [];
$pallets_leaving_w2  = isset($estimate_data['pallets_leaving_w2'])  ? $estimate_data['pallets_leaving_w2']  : [];
$pallets_entering_w3 = isset($estimate_data['pallets_entering_w3']) ? $estimate_data['pallets_entering_w3'] : [];
$pallets_leaving_w3  = isset($estimate_data['pallets_leaving_w3'])  ? $estimate_data['pallets_leaving_w3']  : [];

$in_fee_w1  = isset($estimate_data['in_fee_per_pallet_w1'])  ? floatval($estimate_data['in_fee_per_pallet_w1'])  : 0.00;
$in_fee_w2  = isset($estimate_data['in_fee_per_pallet_w2'])  ? floatval($estimate_data['in_fee_per_pallet_w2'])  : 0.00;
$in_fee_w3  = isset($estimate_data['in_fee_per_pallet_w3'])  ? floatval($estimate_data['in_fee_per_pallet_w3'])  : 0.00;

$out_fee_w1 = isset($estimate_data['out_fee_per_pallet_w1']) ? floatval($estimate_data['out_fee_per_pallet_w1']) : 0.00;
$out_fee_w2 = isset($estimate_data['out_fee_per_pallet_w2']) ? floatval($estimate_data['out_fee_per_pallet_w2']) : 0.00;
$out_fee_w3 = isset($estimate_data['out_fee_per_pallet_w3']) ? floatval($estimate_data['out_fee_per_pallet_w3']) : 0.00;

$sto_fee_w1 = isset($estimate_data['storage_fee_per_pallet_w1']) ? floatval($estimate_data['storage_fee_per_pallet_w1']) : 0.00;
$sto_fee_w2 = isset($estimate_data['storage_fee_per_pallet_w2']) ? floatval($estimate_data['storage_fee_per_pallet_w2']) : 0.00;
$sto_fee_w3 = isset($estimate_data['storage_fee_per_pallet_w3']) ? floatval($estimate_data['storage_fee_per_pallet_w3']) : 0.00;

// Helper function to do the calculations, copied from calculator_results.php
function calculateWarehouse($months, $display_months, $in_fee, $out_fee, $sto_fee, $pallets_entering, $pallets_leaving, $current_inventory) {
    $results = [];
    $num_months = count($months);
    $pallets_in_storage_previous = $current_inventory;
    $grand_total = 0;
    for($i=0; $i<$num_months; $i++){
        $enter = isset($pallets_entering[$i]) ? intval($pallets_entering[$i]) : 0;
        $leave = isset($pallets_leaving[$i]) ? intval($pallets_leaving[$i]) : 0;

        $this_storage = max(0, $pallets_in_storage_previous + $enter - $leave);

        $this_in_fee  = $in_fee  * $enter;
        $this_out_fee = $out_fee * $leave;
        $this_sto_fee = $sto_fee * $this_storage;

        $this_total = $this_in_fee + $this_out_fee + $this_sto_fee;
        $grand_total += $this_total;

        $results[] = [
            'month'             => $display_months[$i],
            'pallets_in_storage'=> $this_storage,
            'in_fee'            => $this_in_fee,
            'out_fee'           => $this_out_fee,
            'storage_fee'       => $this_sto_fee,
            'total'             => $this_total,
            'delivering'        => $enter,
            'leaving'           => $leave
        ];
        $pallets_in_storage_previous = $this_storage;
    }
    return [
        'rows' => $results,
        'grand_total' => $grand_total
    ];
}

// Build array for each warehouse
$warehouseCalculations = [];
$num_warehouses = 0;

// If warehouse 1 has any non-zero fees OR data, consider it used
if($in_fee_w1!=0 || $out_fee_w1!=0 || $sto_fee_w1!=0 || !empty($pallets_entering_w1) || !empty($pallets_leaving_w1)) {
    $num_warehouses = 1;
    $warehouseCalculations[1] = calculateWarehouse(
        $months_array, $display_months,
        $in_fee_w1, $out_fee_w1, $sto_fee_w1,
        $pallets_entering_w1, $pallets_leaving_w1, 
        $current_inventory_w1
    );
}
if($in_fee_w2!=0 || $out_fee_w2!=0 || $sto_fee_w2!=0 || !empty($pallets_entering_w2) || !empty($pallets_leaving_w2)) {
    $num_warehouses = max($num_warehouses, 2);
    $warehouseCalculations[2] = calculateWarehouse(
        $months_array, $display_months,
        $in_fee_w2, $out_fee_w2, $sto_fee_w2,
        $pallets_entering_w2, $pallets_leaving_w2, 
        $current_inventory_w2
    );
}
if($in_fee_w3!=0 || $out_fee_w3!=0 || $sto_fee_w3!=0 || !empty($pallets_entering_w3) || !empty($pallets_leaving_w3)) {
    $num_warehouses = max($num_warehouses, 3);
    $warehouseCalculations[3] = calculateWarehouse(
        $months_array, $display_months,
        $in_fee_w3, $out_fee_w3, $sto_fee_w3,
        $pallets_entering_w3, $pallets_leaving_w3, 
        $current_inventory_w3
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Saved Estimate</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .success-message { color: green; }
        .error-message { color: red; }
        table {
            border-collapse: collapse;
            margin-top: 15px;
            width: 100%;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 8px;
        }
        .back-icon {
            display: inline-block;
            margin-bottom: 20px;
            text-decoration: none;
            color: #000;
        }
        .back-icon svg {
            width: 20px;
            height: 20px;
            vertical-align: middle;
            margin-right: 5px;
        }
        .summary-table th, .summary-table td {
            border: 1px solid #bbb;
            padding: 8px;
        }
        .overall-total-row {
            background-color: #fbb040;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .page-header h1 {
            margin: 0;
        }
        /* Simulation container styling */
        #top-row-container {
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
            align-items: flex-start;
        }
        #summary-container {
            flex: 1 1 400px;
        }
        #simulation-container {
            flex: 1 1 400px;
            text-align: center;
        }
        .simulate-button {
            background-color: #293E4C;
            color: #fff;
            border: none;
            padding: 6px 12px;
            cursor: pointer;
        }
        table.simulation-table thead th {
            background-color: #293E4C;
            color: #fff;
        }
        table.simulation-summary thead th {
            background-color: #293E4C;
            color: #fff;
        }
        .simulation-section h2 {
            margin-top: 30px;
            color: #293E4C;
        }
        table.summary-table {
            margin-top: 70px;
        }
        .info-tooltip {
            margin-left: 0px;
            margin-right: 5px;
        }
        /* Hidden simulation details by default */
        #simulation-detailed-container {
            display: none;
        }
    </style>
    <script>
        // We'll replicate the simulation logic from calculator_results
        function downloadCSV(csvContent, filename) {
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            if (link.download !== undefined) {
                var url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // We pull data from JS variables for the simulation
        function simulateDelay(){
            var delayVal = parseInt(document.getElementById('delay_months').value) || 0;
            var summaryDiv = document.getElementById('simulation-summary');
            var detailDiv  = document.getElementById('simulation-detailed-container');
            summaryDiv.innerHTML = "";
            detailDiv.innerHTML = "";
            detailDiv.style.display = "none";

            if(months.length===0 || numWarehouses<1){
                summaryDiv.innerHTML = "<p>No months to simulate.</p>";
                return;
            }

            var simData = [];
            for(var w=1; w<=numWarehouses; w++){
                let inFee, outFee, stoFee, pIn, pOut, cInv;
                if(w===1){
                    inFee = inFeeW1; outFee = outFeeW1; stoFee = stoFeeW1;
                    pIn = palEnterW1; pOut = palLeaveW1; cInv = currentInventoryW1;
                } else if(w===2){
                    inFee = inFeeW2; outFee = outFeeW2; stoFee = stoFeeW2;
                    pIn = palEnterW2; pOut = palLeaveW2; cInv = currentInventoryW2;
                } else {
                    inFee = inFeeW3; outFee = outFeeW3; stoFee = stoFeeW3;
                    pIn = palEnterW3; pOut = palLeaveW3; cInv = currentInventoryW3;
                }
                var calc = monthlyCalc(inFee, outFee, stoFee, pIn, pOut, cInv, delayVal);
                simData.push({warehouse: w, result: calc});
            }

            var totalSim = 0;
            var summaryHtml = `
                <table class="simulation-summary" style="width:100%;margin-top:15px;">
                    <thead>
                        <tr>
                            <th>Warehouse</th>
                            <th>Grand Total</th>
                            <th>Difference vs. Original</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            for(var i=0; i<simData.length; i++){
                var w = simData[i].warehouse;
                var gTot = simData[i].result.grandTotal;
                totalSim += gTot;
                var diff = gTot - originalGrandTotals[w-1];
                var diffSign = (diff < 0) ? "green" : (diff > 0 ? "red" : "black");
                summaryHtml += `
                    <tr>
                      <td>Warehouse ${w}</td>
                      <td>$${formatCurrency(gTot)}</td>
                      <td style="color:${diffSign};">$${formatCurrency(diff)}</td>
                    </tr>
                `;
            }
            var overallDiff = 0;
            for(var j=0; j<numWarehouses; j++){
                overallDiff += (simData[j].result.grandTotal - originalGrandTotals[j]);
            }
            var overallDiffColor = (overallDiff < 0) ? "green" : (overallDiff > 0 ? "red" : "black");
            summaryHtml += `
                  <tr class="overall-total-row">
                    <td><strong>Overall Total</strong></td>
                    <td><strong>$${formatCurrency(totalSim)}</strong></td>
                    <td style="color:${overallDiffColor};"><strong>$${formatCurrency(overallDiff)}</strong></td>
                  </tr>
                  </tbody>
                </table>
            `;
            summaryDiv.innerHTML = summaryHtml;

            detailDiv.style.display = "block";
            detailDiv.innerHTML = `<h2>Detailed Monthly Costs (Simulation)</h2>`;
            for(var k=0; k<simData.length; k++){
                var w = simData[k].warehouse;
                var simRows = simData[k].result.rows;
                var simGtot = simData[k].result.grandTotal;
                var whHtml = `
                  <h3>Warehouse ${w}</h3>
                  <table class="simulation-table">
                    <thead>
                      <tr>
                        <th>Month</th>
                        <th>Pallets in Storage</th>
                        <th>In Fee</th>
                        <th>Out Fee</th>
                        <th>Storage Fee</th>
                        <th>Total</th>
                      </tr>
                    </thead>
                    <tbody>
                `;
                for(var r=0; r<simRows.length; r++){
                    var R = simRows[r];
                    whHtml += `
                      <tr>
                        <td>${R.month}</td>
                        <td>${R.palletsInStorage.toLocaleString()}</td>
                        <td>$${formatCurrency(R.inFee)}</td>
                        <td>$${formatCurrency(R.outFee)}</td>
                        <td>$${formatCurrency(R.storageFee)}</td>
                        <td>$${formatCurrency(R.total)}</td>
                      </tr>
                    `;
                }
                whHtml += `
                    </tbody>
                  </table>
                  <p><strong>Grand Total (Warehouse ${w}):</strong> $${formatCurrency(simGtot)}</p>
                `;
                detailDiv.innerHTML += whHtml;
            }
        }

        // Helper to format numbers as currency with commas
        function formatCurrency(num){
            return num.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        // The monthlyCalc function used by simulateDelay:
        function monthlyCalc(inFee, outFee, stoFee, palEnterArr, palLeaveArr, currentInv, delay){
            var result = {rows:[], grandTotal:0};
            var monthMap = {};
            var leavingMap = {};

            for(var i=0; i<months.length; i++){
                var mKey = months[i];
                if(!monthMap[mKey]) monthMap[mKey] = 0;
                monthMap[mKey] += parseInt(palEnterArr[i]) || 0;
            }

            for(var i=0; i<months.length; i++){
                var orig = months[i];
                var parts = orig.split('-');
                var Y = parseInt(parts[0]), M = parseInt(parts[1]);
                M += delay;
                while(M>12){ M-=12; Y++; }
                while(M<1){ M+=12; Y--; }
                var newKey = Y.toString().padStart(4,'0') + '-' + M.toString().padStart(2,'0');
                leavingMap[newKey] = (leavingMap[newKey]||0) + (parseInt(palLeaveArr[i])||0);
            }

            var allMonthsSet = new Set(Object.keys(monthMap));
            Object.keys(leavingMap).forEach(k=>allMonthsSet.add(k));
            var allMonths = Array.from(allMonthsSet);
            allMonths.sort();

            var storageSoFar = currentInv;
            for(var i=0; i<allMonths.length; i++){
                var mk = allMonths[i];
                var Y = parseInt(mk.split('-')[0]);
                var M = parseInt(mk.split('-')[1]);
                var dispDate = new Date(Y, M-1, 1);
                var dispStr = dispDate.toLocaleString('default',{month:'long', year:'numeric'});

                var delivering = monthMap[mk] || 0;
                var leaving = leavingMap[mk] || 0;

                storageSoFar = Math.max(0, storageSoFar + delivering - leaving);

                var costIn = inFee * delivering;
                var costOut = outFee * leaving;
                var costSto = stoFee * storageSoFar;
                var total = costIn + costOut + costSto;
                result.grandTotal += total;
                result.rows.push({
                    month: dispStr,
                    inFee: costIn,
                    outFee: costOut,
                    storageFee: costSto,
                    total: total,
                    palletsInStorage: storageSoFar
                });
            }
            return result;
        }

        // We'll define the main variables in JavaScript for the simulation
        var months             = <?php echo json_encode($months_array); ?>;
        var currentInventoryW1 = <?php echo json_encode($current_inventory_w1); ?>;
        var currentInventoryW2 = <?php echo json_encode($current_inventory_w2); ?>;
        var currentInventoryW3 = <?php echo json_encode($current_inventory_w3); ?>;

        var inFeeW1  = <?php echo json_encode($in_fee_w1); ?>;
        var inFeeW2  = <?php echo json_encode($in_fee_w2); ?>;
        var inFeeW3  = <?php echo json_encode($in_fee_w3); ?>;

        var outFeeW1 = <?php echo json_encode($out_fee_w1); ?>;
        var outFeeW2 = <?php echo json_encode($out_fee_w2); ?>;
        var outFeeW3 = <?php echo json_encode($out_fee_w3); ?>;

        var stoFeeW1 = <?php echo json_encode($sto_fee_w1); ?>;
        var stoFeeW2 = <?php echo json_encode($sto_fee_w2); ?>;
        var stoFeeW3 = <?php echo json_encode($sto_fee_w3); ?>;

        var palEnterW1 = <?php echo json_encode($pallets_entering_w1); ?>;
        var palLeaveW1 = <?php echo json_encode($pallets_leaving_w1);  ?>;
        var palEnterW2 = <?php echo json_encode($pallets_entering_w2); ?>;
        var palLeaveW2 = <?php echo json_encode($pallets_leaving_w2);  ?>;
        var palEnterW3 = <?php echo json_encode($pallets_entering_w3); ?>;
        var palLeaveW3 = <?php echo json_encode($pallets_leaving_w3);  ?>;

        var numWarehouses = <?php
            echo json_encode($num_warehouses);
        ?>;

        // We'll track the "originalGrandTotals" for the simulation difference
        var originalGrandTotals = [0,0,0];
        <?php
        for($w=1; $w<=$num_warehouses; $w++):
            $gt = isset($warehouseCalculations[$w]) 
                    ? $warehouseCalculations[$w]['grand_total'] 
                    : 0;
        ?>
            originalGrandTotals[<?php echo ($w-1); ?>] = <?php echo $gt; ?>;
        <?php endfor; ?>

    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Page Header -->
    <div class="page-header">
                <!-- Provide a back button or link to estimates list -->
                <a href="javascript:history.back()" class="back-icon">
            <!-- SVG for Back Arrow -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
            </svg>Back
        </a>
    </div>
    <h1>Viewing Saved Estimate: <?php echo htmlspecialchars($estimate_row['name']); ?></h1>



    <?php if(empty($months_array)): ?>
        <p>This saved estimate has no months data.</p>
    <?php else: ?>
        <?php 
            // Compute summary across all warehouses
            $total_of_all_warehouses = 0;
            foreach($warehouseCalculations as $w => $calc) {
                $total_of_all_warehouses += $calc['grand_total'];
            }
        ?>

        <div id="top-row-container">
            <!-- Left: Summary of All Warehouses -->
            <div id="summary-container">
                <h2>Summary of All Warehouses</h2>
                <table class="summary-table">
                    <thead>
                        <tr style="background-color: #293E4C; color:#fff;">
                            <th>Warehouse</th>
                            <th>Grand Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php for($w=1; $w<=$num_warehouses; $w++): ?>
                        <?php if(!isset($warehouseCalculations[$w])) continue; ?>
                        <tr>
                            <td>Warehouse <?php echo $w; ?></td>
                            <td>$<?php echo number_format($warehouseCalculations[$w]['grand_total'], 2); ?></td>
                        </tr>
                    <?php endfor; ?>
                    <tr class="overall-total-row">
                        <td><strong>Overall Total</strong></td>
                        <td><strong>$<?php echo number_format($total_of_all_warehouses, 2); ?></strong></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <!-- Right: Delay/Pull-In Simulation -->
            <div id="simulation-container">
                <h2>Project Delay/Pull-In Simulation</h2>
                <label for="delay_months">Adjust Project Schedule (in months):</label>
                <span class="info-tooltip">?
                    <span class="tooltip-text">
                        Enter a positive number of months to simulate a delay (e.g., 2), 
                        or a negative number to simulate a pull-in (e.g., -1).
                    </span>
                </span>
                <input type="number" id="delay_months" name="delay_months" min="-12" max="36" value="0">
                <button type="button" class="simulate-button" onclick="simulateDelay()">Simulate</button>
                <div id="simulation-summary" style="margin-top:20px;"></div>
            </div>
        </div><!-- end top-row-container -->

        <h2>Detailed Monthly Costs (Original)</h2>
        <!-- Original Tables for Each Warehouse -->
        <?php for($w=1; $w<=$num_warehouses; $w++): ?>
            <?php 
                if(!isset($warehouseCalculations[$w])) continue; 
                $calc = $warehouseCalculations[$w];
                $rows = $calc['rows'];
                $grand_total = $calc['grand_total'];
            ?>
            <h3>Warehouse <?php echo $w; ?></h3>
            <table class="original-table">
                <thead>
                    <tr style="background-color: #ccc;">
                        <th>Month</th>
                        <th>Pallets in Storage</th>
                        <th>In Fee</th>
                        <th>Out Fee</th>
                        <th>Storage Fee</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['month']); ?></td>
                        <td><?php echo number_format($r['pallets_in_storage']); ?></td>
                        <td>$<?php echo number_format($r['in_fee'], 2); ?></td>
                        <td>$<?php echo number_format($r['out_fee'], 2); ?></td>
                        <td>$<?php echo number_format($r['storage_fee'], 2); ?></td>
                        <td>$<?php echo number_format($r['total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <strong>Grand Total (Warehouse <?php echo $w; ?>):</strong>
                $<?php echo number_format($grand_total, 2); ?>
            </p>
        <?php endfor; ?>

        <!-- Simulation Detailed Costs (hidden by default) -->
        <div id="simulation-detailed-container" class="simulation-section">
            <!-- Simulation results appended here by simulateDelay() -->
        </div>

    <?php endif; ?>

</main>
</body>
</html>
