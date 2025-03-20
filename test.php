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

// Get the user's ID
$user_id = $_SESSION['user_id'];

$servername = "localhost";
$db_username = "SolterraSolutions"; 
$db_password = "CompanyAdmin!";     
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle estimate deletion
if (isset($_GET['delete_estimate'])) {
    $estimate_id_to_delete = intval($_GET['delete_estimate']);

    // Verify that the estimate belongs to the current user
    $stmt = $conn->prepare("DELETE FROM warehouse_estimates WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $estimate_id_to_delete, $user_id);

    if ($stmt->execute()) {
        $success_message = "Estimate deleted successfully!";
    } else {
        $error_message = "Error deleting estimate: " . $stmt->error;
    }
    $stmt->close();

    // Refresh the page to update the list
    header("Location: cost_estimate_calculator");
    exit();
}

// Fetch saved estimates for the current user
$sql = "SELECT id, name, estimate_data, created_at FROM warehouse_estimates WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Initialize an array to hold the estimates
$saved_estimates = [];
while ($row = $result->fetch_assoc()) {
    $saved_estimates[] = $row;
}
$stmt->close();


// Initialize variables
$in_fee_per_pallet = isset($_POST['in_fee_per_pallet']) ? floatval($_POST['in_fee_per_pallet']) : 0;
$out_fee_per_pallet = isset($_POST['out_fee_per_pallet']) ? floatval($_POST['out_fee_per_pallet']) : 0;
$storage_fee_per_pallet = isset($_POST['storage_fee_per_pallet']) ? floatval($_POST['storage_fee_per_pallet']) : 0;

// Handle months and pallets arrays
if (isset($_POST['months'])) {
    $months = $_POST['months'];
} else {
    // Define months array for the next 6 months
    $months = [];
    $currentMonth = new DateTime('first day of this month');
    for ($i = 0; $i < 6; $i++) {
        $months[] = $currentMonth->format('Y-m');
        $currentMonth->modify('+1 month');
    }
}

if (isset($_POST['pallets_delivering'])) {
    $pallets_delivering = $_POST['pallets_delivering'];
} else {
    $pallets_delivering = array_fill(0, count($months), 0);
}

if (isset($_POST['pallets_leaving'])) {
    $pallets_leaving = $_POST['pallets_leaving'];
} else {
    $pallets_leaving = array_fill(0, count($months), 0);
}

// Convert month inputs to display format (e.g., "January 2024")
$display_months = [];
foreach ($months as $month) {
    if ($month != '') {
        $date = DateTime::createFromFormat('Y-m', $month);
        $display_months[] = $date->format('F Y');
    } else {
        $display_months[] = '';
    }
}

// Perform calculations if form is submitted
$totals = [];
$grand_total = 0;
$total_pallets_in_storage = 0;
$pallets_in_storage = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($months)) {
    // **Always perform calculations**
    $num_months = count($months);
    for ($i = 0; $i < $num_months; $i++) {
        $month = $display_months[$i];
        $delivering = isset($pallets_delivering[$i]) ? intval($pallets_delivering[$i]) : 0;
        $leaving = isset($pallets_leaving[$i]) ? intval($pallets_leaving[$i]) : 0;

        // Calculate pallets in storage
        if ($i == 0) {
            $total_pallets_in_storage = $delivering - $leaving;
        } else {
            $total_pallets_in_storage = $pallets_in_storage[$i - 1] + $delivering - $leaving;
        }
        $pallets_in_storage[$i] = max($total_pallets_in_storage, 0);

        // Calculate fees
        $in_fee = $in_fee_per_pallet * $delivering;
        $out_fee = $out_fee_per_pallet * $leaving;
        $storage_fee = $storage_fee_per_pallet * $pallets_in_storage[$i];

        $total = $in_fee + $out_fee + $storage_fee;

        $totals[$i] = [
            'month' => $month,
            'delivering' => $delivering,
            'leaving' => $leaving,
            'in_fee' => $in_fee,
            'out_fee' => $out_fee,
            'storage_fee' => $storage_fee,
            'total' => $total,
            'pallets_in_storage' => $pallets_in_storage[$i]
        ];

        $grand_total += $total;
    }
}

    // Check if total pallets in matches total pallets out
    $total_pallets_in = array_sum($pallets_delivering);
    $total_pallets_out = array_sum($pallets_leaving);

    if ($total_pallets_in !== $total_pallets_out) {
        $difference = $total_pallets_in - $total_pallets_out;
        if ($difference > 0) {
            $error_message = "Total pallets delivering to WH exceeds pallets leaving WH by $difference pallets.";
        } else {
            $error_message = "Total pallets leaving WH exceeds pallets delivering to WH by " . abs($difference) . " pallets.";
        }
    }

    if (isset($_POST['save_estimate'])) {
        // Get estimate name
        $estimate_name = trim($_POST['estimate_name']);
    
        // Combine all estimate data into an array
        $estimate_data = [
            'in_fee_per_pallet' => $in_fee_per_pallet,
            'out_fee_per_pallet' => $out_fee_per_pallet,
            'storage_fee_per_pallet' => $storage_fee_per_pallet,
            'months' => $months,
            'pallets_delivering' => $pallets_delivering,
            'pallets_leaving' => $pallets_leaving,
            'totals' => $totals,
            'grand_total' => $grand_total
        ];
    
        // Serialize the estimate data to JSON
        $estimate_data_json = json_encode($estimate_data);
    
        // Prepare SQL statement
        $stmt = $conn->prepare("INSERT INTO warehouse_estimates (user_id, name, estimate_data, created_at) VALUES (?, ?, ?, NOW())");
    
        // Bind parameters
        $stmt->bind_param(
            "iss",
            $user_id,
            $estimate_name,
            $estimate_data_json
        );
    
        if ($stmt->execute()) {
            $success_message = "Estimate saved successfully!";
        } else {
            $error_message = "Error saving estimate: " . $stmt->error;
        }
        $stmt->close();
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cost Estimate Calculator</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Additional styling for Save buttons */
    .save-button {
        float: right;
        margin-bottom: 10px;
    }
    .section-header {
        overflow: hidden;
    }
    .section-header h2 {
        float: left;
        margin: 0;
    }
        /* Style for the modal */
    #saveModal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.4);
    }

    #saveModalContent {
        background-color: #fefefe;
        margin: 10% auto;
        padding: 20px;
        width: 300px;
    }
/* Styles for the Saved Estimates table */
    #saved-estimates-list table {
        width: 100%;
        border-collapse: collapse;
    }

    #saved-estimates-list tr:nth-child(even){background-color: #f9f9f9;}

    #saved-estimates-list tr:hover {background-color: #ddd;}

    #saved-estimates-list td a {
        margin-right: 10px;
    }

    #saved-estimates-list td a.delete-estimate {
        color: red;
        text-decoration: none;
    }

    #saved-estimates-list td a.delete-estimate:hover {
        color: darkred;
    }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <main>
        <h1>Cost Estimate Calculator</h1>

        <!-- Saved Estimates Section -->
        <div id="saved-estimates">
            <button id="saved-estimates-button" class="submit-button">Saved Estimates</button>
            <div id="saved-estimates-list" style="display: none;">
                <?php if (!empty($saved_estimates)): ?>
                    <table>
                        <tr>
                            <th>Name</th>
                            <th>Created Date</th>
                            <th>Actions</th>
                        </tr>
                        <?php foreach ($saved_estimates as $estimate): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($estimate['name']); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($estimate['created_at'])); ?></td>
                                <td>
                                    <a href="view_estimate?id=<?php echo $estimate['id']; ?>">View</a>
                                    |
                                    <a href="#" class="delete-estimate" data-id="<?php echo $estimate['id']; ?>" title="Delete Estimate">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p>You have no saved estimates.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Display success or error messages -->
        <?php
        if (isset($success_message)) {
            echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
        }
        if (isset($error_message) && !isset($_POST['save_estimate'])) {
            echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
        }
        ?>
        <form method="POST" action="test">
            <!-- Warehouse Fees Table -->
            <h2>Warehouse Fees</h2>
            <table id="cost-calculator-table">
                <tr>
                    <th>Fee Type</th>
                    <th>Amount</th>
                </tr>
                <tr>
                    <td>In Fee (per pallet)</td>
                    <td><input type="number" step="0.01" name="in_fee_per_pallet" value="<?php echo htmlspecialchars($in_fee_per_pallet); ?>" required></td>
                </tr>
                <tr>
                    <td>Out Fee (per pallet)</td>
                    <td><input type="number" step="0.01" name="out_fee_per_pallet" value="<?php echo htmlspecialchars($out_fee_per_pallet); ?>" required></td>
                </tr>
                <tr>
                    <td>Monthly Storage Cost (per pallet per month)</td>
                    <td><input type="number" step="0.01" name="storage_fee_per_pallet" value="<?php echo htmlspecialchars($storage_fee_per_pallet); ?>" required></td>
                </tr>
            </table>

            <!-- Module Storage Costs Table -->
            <h2>Module Storage Costs</h2>
            <table id="module-storage-table">
                <tr>
                    <th>Month</th>
                    <th>Pallets Delivering to WH</th>
                    <th>Pallets Leaving WH</th>
                </tr>
                <?php for ($i = 0; $i < count($months); $i++): ?>
                <tr>
                    <td>
                        <?php echo $display_months[$i]; ?>
                        <input type="hidden" name="months[]" value="<?php echo htmlspecialchars($months[$i]); ?>">
                    </td>
                    <td><input type="number" name="pallets_delivering[]" value="<?php echo htmlspecialchars($pallets_delivering[$i]); ?>" required></td>
                    <td><input type="number" name="pallets_leaving[]" value="<?php echo htmlspecialchars($pallets_leaving[$i]); ?>" required></td>
                </tr>
                <?php endfor; ?>
            </table>

            <!-- Totals Table -->
            <h3>Totals</h3>
            <table id="totals-table">
                <tr>
                    <th>Total Pallets Delivering to WH</th>
                    <th>Total Pallets Leaving WH</th>
                </tr>
                <tr>
                    <td id="total-delivering">0</td>
                    <td id="total-leaving">0</td>
                </tr>
            </table>

            <button type="button" class="submit-button" onclick="addMonth()">Add Month</button>
            <button type="button" class="delete-button" onclick="deleteLastMonth()">Delete Month</button>

            <br><br>
            <input type="submit" value="Calculate" class="submit-button">
        </form>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($months)): ?>
            <!-- Cost Estimate Results Section -->
            <div class="section-header">
                <h2>Cost Estimate Results</h2>
                <button type="button" class="save-button" onclick="showSaveModal()">Save Estimate</button>
            </div>
            <table>
                <tr>
                    <th>Month</th>
                    <th>Pallets in Storage</th>
                    <th>In Fee</th>
                    <th>Out Fee</th>
                    <th>Storage Fee</th>
                    <th>Total</th>
                </tr>
                <?php foreach ($totals as $total): ?>
                <tr>
                    <td><?php echo $total['month']; ?></td>
                    <td><?php echo number_format($total['pallets_in_storage']); ?></td>
                    <td>$<?php echo number_format($total['in_fee'], 2); ?></td>
                    <td>$<?php echo number_format($total['out_fee'], 2); ?></td>
                    <td>$<?php echo number_format($total['storage_fee'], 2); ?></td>
                    <td>$<?php echo number_format($total['total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <h3>Grand Total: $<?php echo number_format($grand_total, 2); ?></h3>

            <!-- Save Estimate Modal -->
            <div id="saveModal">
                <div id="saveModalContent">
                    <form method="POST" action="test">
                        <label for="name">Estimate Name:</label>
                        <input type="text" id="name" name="estimate_name" required>
                        <!-- Include all necessary hidden inputs -->
                        <input type="hidden" name="save_estimate" value="1">
                        <input type="hidden" name="in_fee_per_pallet" value="<?php echo htmlspecialchars($in_fee_per_pallet); ?>">
                        <input type="hidden" name="out_fee_per_pallet" value="<?php echo htmlspecialchars($out_fee_per_pallet); ?>">
                        <input type="hidden" name="storage_fee_per_pallet" value="<?php echo htmlspecialchars($storage_fee_per_pallet); ?>">

                        <?php foreach ($months as $month): ?>
                            <input type="hidden" name="months[]" value="<?php echo htmlspecialchars($month); ?>">
                        <?php endforeach; ?>

                        <?php foreach ($pallets_delivering as $delivering): ?>
                            <input type="hidden" name="pallets_delivering[]" value="<?php echo htmlspecialchars($delivering); ?>">
                        <?php endforeach; ?>

                        <?php foreach ($pallets_leaving as $leaving): ?>
                            <input type="hidden" name="pallets_leaving[]" value="<?php echo htmlspecialchars($leaving); ?>">
                        <?php endforeach; ?>

                        <button type="submit">Save</button>
                        <button type="button" onclick="hideSaveModal()">Cancel</button>
                    </form>
                </div>
            </div>

            <!-- Delay/Pull-In Simulation Section -->
            <div class="section-header">
                <h2>Project Delay/Pull-In Simulation</h2>
                <button type="button" class="save-button" onclick="saveSimulation()">Save Simulation</button>
            </div>
            <label for="delay_months">Adjust Project Schedule (in months):</label>
            <input type="number" id="delay_months" name="delay_months" min="-12" max="12" value="0">
            <button type="button" class="submit-button" onclick="simulateDelay()">Simulate</button>

            <!-- Div to Display Simulation Results -->
            <div id="simulation-results"></div>
        <?php endif; ?>
    </main>
    <!-- JavaScript Functions -->
    <script>
    // Initialize months array from PHP
    var months = <?php echo json_encode($months); ?>;
    var displayMonths = <?php echo json_encode($display_months); ?>;
    var originalGrandTotal = <?php echo json_encode($grand_total); ?>;

    function updateTotals() {
        var totalDelivering = 0;
        var totalLeaving = 0;

        // Sum up pallets delivering
        var deliveringInputs = document.getElementsByName('pallets_delivering[]');
        for (var i = 0; i < deliveringInputs.length; i++) {
            totalDelivering += parseInt(deliveringInputs[i].value) || 0;
        }

        // Sum up pallets leaving
        var leavingInputs = document.getElementsByName('pallets_leaving[]');
        for (var i = 0; i < leavingInputs.length; i++) {
            totalLeaving += parseInt(leavingInputs[i].value) || 0;
        }

        // Update the Totals Table
        document.getElementById('total-delivering').textContent = totalDelivering.toLocaleString();
        document.getElementById('total-leaving').textContent = totalLeaving.toLocaleString();
    }

    function attachInputListeners() {
        var deliveringInputs = document.getElementsByName('pallets_delivering[]');
        for (var i = 0; i < deliveringInputs.length; i++) {
            deliveringInputs[i].addEventListener('input', updateTotals);
        }

        var leavingInputs = document.getElementsByName('pallets_leaving[]');
        for (var i = 0; i < leavingInputs.length; i++) {
            leavingInputs[i].addEventListener('input', updateTotals);
        }
    }

    window.onload = function() {
        updateTotals();
        attachInputListeners();
    };

    function addMonth() {
        var table = document.getElementById('module-storage-table');
        var rowCount = table.rows.length;

        var lastRow = table.rows[rowCount - 1];
        var lastMonthInput = lastRow.cells[0].getElementsByTagName('input')[0];
        var lastMonthValue = lastMonthInput.value;

        // Parse the last month value
        var lastYear = parseInt(lastMonthValue.split('-')[0]);
        var lastMonth = parseInt(lastMonthValue.split('-')[1]) - 1; // JS months are 0-based

        // Create date object in UTC to avoid time zone issues
        var date = new Date(Date.UTC(lastYear, lastMonth, 1));

        // Increment month
        date.setUTCMonth(date.getUTCMonth() + 1);

        // Get new month value in 'YYYY-MM' format
        var year = date.getUTCFullYear();
        var month = (date.getUTCMonth() + 1).toString().padStart(2, '0'); // getUTCMonth() returns 0-11
        var newMonthValue = year + '-' + month;

        // Format display date
        var displayDate = date.toLocaleString('default', { month: 'long', year: 'numeric', timeZone: 'UTC' });

        var row = table.insertRow(rowCount);

        // Month Cell
        var cell1 = row.insertCell(0);
        cell1.textContent = displayDate;
        var monthInput = document.createElement('input');
        monthInput.type = 'hidden';
        monthInput.name = 'months[]';
        monthInput.value = newMonthValue;
        cell1.appendChild(monthInput);

        // Pallets Delivering Cell
        var cell2 = row.insertCell(1);
        var deliveringInput = document.createElement('input');
        deliveringInput.type = 'number';
        deliveringInput.name = 'pallets_delivering[]';
        deliveringInput.value = '0';
        deliveringInput.required = true;
        cell2.appendChild(deliveringInput);

        // Pallets Leaving Cell
        var cell3 = row.insertCell(2);
        var leavingInput = document.createElement('input');
        leavingInput.type = 'number';
        leavingInput.name = 'pallets_leaving[]';
        leavingInput.value = '0';
        leavingInput.required = true;
        cell3.appendChild(leavingInput);

        // Attach event listeners to the new inputs
        deliveringInput.addEventListener('input', updateTotals);
        leavingInput.addEventListener('input', updateTotals);

        // Update months arrays
        months.push(newMonthValue);
        displayMonths.push(displayDate);

        // Update totals
        updateTotals();
    }

    function deleteLastMonth() {
        var table = document.getElementById('module-storage-table');
        var rowCount = table.rows.length;

        // There must be at least one data row (excluding the header)
        if (rowCount > 1) {
            table.deleteRow(rowCount - 1);

            // Remove the last month from the months arrays
            months.pop();
            displayMonths.pop();

            // Update totals
            updateTotals();
        }
    }

    function showSaveModal() {
        document.getElementById('saveModal').style.display = 'block';
    }

    function hideSaveModal() {
        document.getElementById('saveModal').style.display = 'none';
    }

    // Function to save simulation results
    function saveSimulation() {
        alert("Saving simulation is not yet implemented.");
        // You can implement similar functionality as saving the estimate,
        // but you'll need to capture the simulation results and store them.
    }
    function simulateDelay() {
        var delayMonths = parseInt(document.getElementById('delay_months').value) || 0;

        // Rebuild the data arrays from the DOM
        var months = [];
        var palletsDelivering = [];
        var palletsLeaving = [];

        var table = document.getElementById('module-storage-table');
        var rows = table.getElementsByTagName('tr');

        // Skip the header row (index 0)
        for (var i = 1; i < rows.length; i++) {
            var cells = rows[i].getElementsByTagName('td');

            // Get the hidden month input
            var monthInput = cells[0].getElementsByTagName('input')[0];
            months.push(monthInput.value);

            // Get the pallets delivering input
            var deliveringInput = cells[1].getElementsByTagName('input')[0];
            palletsDelivering.push(parseInt(deliveringInput.value) || 0);

            // Get the pallets leaving input
            var leavingInput = cells[2].getElementsByTagName('input')[0];
            palletsLeaving.push(parseInt(leavingInput.value) || 0);
        }

        var inFeePerPallet = <?php echo json_encode($in_fee_per_pallet); ?>;
        var outFeePerPallet = <?php echo json_encode($out_fee_per_pallet); ?>;
        var storageFeePerPallet = <?php echo json_encode($storage_fee_per_pallet); ?>;

        // Create a mapping of months to palletsDelivering and adjusted palletsLeaving
        var monthMap = {};
        var allMonthsSet = new Set();

        // Add palletsDelivering to monthMap
        for (var i = 0; i < months.length; i++) {
            var month = months[i];
            monthMap[month] = monthMap[month] || { delivering: 0, leaving: 0 };
            monthMap[month].delivering += palletsDelivering[i];
            allMonthsSet.add(month);
        }

        // Adjust the pallets leaving dates
        var adjustedPalletsLeaving = {};

        for (var i = 0; i < months.length; i++) {
            var originalMonth = months[i];
            var dateParts = originalMonth.split('-');
            var date = new Date(Date.UTC(parseInt(dateParts[0]), parseInt(dateParts[1]) - 1, 1));

            // Adjust the date for pallets leaving
            date.setUTCMonth(date.getUTCMonth() + delayMonths);

            // Get adjusted month in 'YYYY-MM' format
            var adjustedYear = date.getUTCFullYear();
            var adjustedMonth = (date.getUTCMonth() + 1).toString().padStart(2, '0');
            var adjustedMonthKey = adjustedYear + '-' + adjustedMonth;

            adjustedPalletsLeaving[adjustedMonthKey] = adjustedPalletsLeaving[adjustedMonthKey] || 0;
            adjustedPalletsLeaving[adjustedMonthKey] += palletsLeaving[i];

            // Add adjusted month to the set of all months
            allMonthsSet.add(adjustedMonthKey);
        }

        // Sort all months
        var allMonthsArray = Array.from(allMonthsSet);
        allMonthsArray.sort();

        // Prepare display months
        var displayMonthsMap = {};
        for (var i = 0; i < allMonthsArray.length; i++) {
            var monthKey = allMonthsArray[i];
            var dateParts = monthKey.split('-');
            var date = new Date(Date.UTC(parseInt(dateParts[0]), parseInt(dateParts[1]) - 1, 1));
            var displayDate = date.toLocaleString('default', { month: 'long', year: 'numeric', timeZone: 'UTC' });
            displayMonthsMap[monthKey] = displayDate;
        }

        // Calculate pallets in storage and fees
        var adjustedPalletsInStorage = [];
        var totalPalletsInStorage = 0;
        var totals = [];
        var grandTotal = 0;

        for (var i = 0; i < allMonthsArray.length; i++) {
            var monthKey = allMonthsArray[i];
            var delivering = monthMap[monthKey] ? monthMap[monthKey].delivering : 0;
            var leaving = adjustedPalletsLeaving[monthKey] || 0;

            if (i == 0) {
                totalPalletsInStorage = delivering - leaving;
            } else {
                totalPalletsInStorage = adjustedPalletsInStorage[i - 1] + delivering - leaving;
            }
            totalPalletsInStorage = Math.max(totalPalletsInStorage, 0);
            adjustedPalletsInStorage.push(totalPalletsInStorage);

            // Calculate fees
            var inFee = inFeePerPallet * delivering;
            var outFee = outFeePerPallet * leaving;
            var storageFee = storageFeePerPallet * totalPalletsInStorage;

            var total = inFee + outFee + storageFee;
            grandTotal += total;

            // Store totals
            totals.push({
                month: displayMonthsMap[monthKey],
                palletsInStorage: totalPalletsInStorage,
                inFee: inFee,
                outFee: outFee,
                storageFee: storageFee,
                total: total
            });
        }

        // Calculate the difference
        var difference = grandTotal - originalGrandTotal;
        var differenceColor = difference < 0 ? 'green' : (difference > 0 ? 'red' : 'black');

        // Display the results
        var resultsDiv = document.getElementById('simulation-results');
        var html = '<h3>Simulation Results</h3>';
        html += '<table><tr><th>Month</th><th>Pallets in Storage</th><th>In Fee</th><th>Out Fee</th><th>Storage Fee</th><th>Total</th></tr>';

        for (var j = 0; j < totals.length; j++) {
            html += '<tr>';
            html += '<td>' + totals[j].month + '</td>';
            html += '<td>' + totals[j].palletsInStorage.toLocaleString() + '</td>';
            html += '<td>$' + totals[j].inFee.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>';
            html += '<td>$' + totals[j].outFee.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>';
            html += '<td>$' + totals[j].storageFee.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>';
            html += '<td>$' + totals[j].total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>';
            html += '</tr>';
        }

        html += '</table>';
        html += '<h3>Grand Total: $' + grandTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</h3>';
        html += '<h3>Difference: <span style="color:' + differenceColor + ';">$' + difference.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span></h3>';

        resultsDiv.innerHTML = html;
    }
    </script>
    <script>
    // Wait for the DOM to load
    document.addEventListener('DOMContentLoaded', function() {
        var savedEstimatesButton = document.getElementById('saved-estimates-button');
        var savedEstimatesList = document.getElementById('saved-estimates-list');

        savedEstimatesButton.addEventListener('click', function() {
            if (savedEstimatesList.style.display === 'none' || savedEstimatesList.style.display === '') {
                savedEstimatesList.style.display = 'block';
            } else {
                savedEstimatesList.style.display = 'none';
            }
        });

        // Add event listener for delete buttons
        var deleteButtons = document.querySelectorAll('.delete-estimate');

        deleteButtons.forEach(function(button) {
            button.addEventListener('click', function(event) {
                event.preventDefault(); // Prevent default link behavior
                var estimateId = this.getAttribute('data-id');

                if (confirm('Are you sure you want to delete this estimate?')) {
                    // Proceed with deletion
                    window.location.href = 'test?delete_estimate=' + estimateId;
                }
            });
        });
    });
    </script>
</body>
</html>