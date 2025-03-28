<?php
session_name("logistics_session");
session_start();



// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Get the user's ID
$user_id = $_SESSION['user_id'];

// -----------------------------------------------------------
// Database connection
// -----------------------------------------------------------
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// -----------------------------------------------------------
// Handle estimate deletion (restrict to app_type='calculator')
// -----------------------------------------------------------
if (isset($_GET['delete_estimate'])) {
    $estimate_id_to_delete = intval($_GET['delete_estimate']);

    $stmt = $conn->prepare("
        DELETE FROM warehouse_estimates
         WHERE id = ? 
           AND user_id = ?
           AND app_type = 'calculator'
    ");
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

// -----------------------------------------------------------
// Fetch saved estimates for this user (only app_type='calculator')
// -----------------------------------------------------------
$sql = "
    SELECT id, name, estimate_data, created_at
      FROM warehouse_estimates
     WHERE user_id = ?
       AND app_type = 'calculator'
  ORDER BY created_at DESC
";
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

// -----------------------------------------------------------
// (We still fetch $saved_quotes if you have that logic; it's just unused now)
// -----------------------------------------------------------
$sql = "SELECT id, name, estimate_data, created_at
          FROM warehouse_quotes
         WHERE user_id = ?
      ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Initialize an array to hold the quotes (not displayed now)
$saved_quotes = [];
while ($row = $result->fetch_assoc()) {
    $estimate_data = json_decode($row['estimate_data'], true);
    if (isset($estimate_data['quotes'])) {
        $row['quotes'] = $estimate_data['quotes'];
        $saved_quotes[] = $row;
    }
}
$stmt->close();

// -----------------------------------------------------------
// Initialize selectedQuotes from POST if present
// -----------------------------------------------------------
$selectedQuotes = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['selected_quotes'])) {
        foreach ($_POST['selected_quotes'] as $selected_quote_json) {
            $selectedQuotes[] = json_decode($selected_quote_json, true);
        }
    }
}

// -----------------------------------------------------------
// Prepare default 6 months if none are in POST
// -----------------------------------------------------------
$months = [];
if (isset($_POST['months'])) {
    $months = $_POST['months'];
} else {
    // Define months array for the next 6 months
    $currentMonth = new DateTime('first day of this month');
    for ($i = 0; $i < 6; $i++) {
        $months[] = $currentMonth->format('Y-m');
        $currentMonth->modify('+1 month');
    }
}

// Convert them to display format
$display_months = [];
foreach ($months as $m) {
    if ($m != '') {
        $dt = DateTime::createFromFormat('Y-m', $m);
        $display_months[] = $dt->format('F Y');
    } else {
        $display_months[] = '';
    }
}

// -----------------------------------------------------------
// Initialize arrays for "Pallets In" and "Pallets Out" (up to 3 warehouses)
// -----------------------------------------------------------
$max_warehouses = 3;
$pallets_entering = [];
$pallets_leaving = [];

for ($w = 0; $w < $max_warehouses; $w++) {
    $keyEntering = "pallets_entering_w" . ($w+1);
    $keyLeaving  = "pallets_leaving_w" . ($w+1);

    if (isset($_POST[$keyEntering])) {
        $pallets_entering[$w] = $_POST[$keyEntering];
    } else {
        $pallets_entering[$w] = array_fill(0, count($months), 0);
    }

    if (isset($_POST[$keyLeaving])) {
        $pallets_leaving[$w] = $_POST[$keyLeaving];
    } else {
        $pallets_leaving[$w] = array_fill(0, count($months), 0);
    }
}

// -----------------------------------------------------------
// Initialize Current Inventory for up to 3 warehouses
// -----------------------------------------------------------
$current_inventory = [];
for ($w = 0; $w < $max_warehouses; $w++) {
    $keyCI = "current_inventory_w" . ($w+1);
    $current_inventory[$w] = isset($_POST[$keyCI]) ? intval($_POST[$keyCI]) : 0;
}

// -----------------------------------------------------------
// Fee inputs (In, Out, Storage) for up to 3 warehouses
// -----------------------------------------------------------
$in_fee_per_pallet = [];
$out_fee_per_pallet = [];
$storage_fee_per_pallet = [];

for ($w = 0; $w < $max_warehouses; $w++) {
    $keyIn  = "in_fee_per_pallet_w" . ($w+1);
    $keyOut = "out_fee_per_pallet_w" . ($w+1);
    $keySto = "storage_fee_per_pallet_w" . ($w+1);

    $in_fee_per_pallet[$w]      = isset($_POST[$keyIn])  ? floatval($_POST[$keyIn]) : 0.00;
    $out_fee_per_pallet[$w]     = isset($_POST[$keyOut]) ? floatval($_POST[$keyOut]) : 0.00;
    $storage_fee_per_pallet[$w] = isset($_POST[$keySto]) ? floatval($_POST[$keySto]) : 0.00;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Estimate Calculator</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        .success-message { color: green; }
        .error-message { color: red; }
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

        .section-header {
            overflow: hidden;
            margin-top: 30px;
        }
        .section-header h2 {
            float: left;
            margin: 0;
        }

        #buttons-section {
            margin-bottom: 20px;
        }
        #saved-estimates-list table,
        #warehouse-fees-table,
        #module-storage-table {
            border-collapse: collapse;
            margin-top: 10px;
        }
        #saved-estimates-list th, #saved-estimates-list td,
        #warehouse-fees-table th,  #warehouse-fees-table td,
        #module-storage-table th,  #module-storage-table td {
            border: 1px solid #ccc;
            padding: 8px;
        }

        .submit-button, .delete-button {
            margin: 5px 0;
            padding: 6px 12px;
            cursor: pointer;
        }

        /* Warehouse color backgrounds */
        th.warehouse2 {
            background-color: #293E4C;
            color: white;
        }
        th.warehouse3 {
            background-color: #fbb040;
            color: white;
        }

        /* Sticky row for "Current Inventory" */
        .sticky-current-inventory {
            background: #e6e6e6;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .hidden {
            display: none;
        }

        /* Allow horizontal scrolling on smaller screens */
        @media screen and (max-width: 768px) {
            #saved-estimates-list table {
                width: 100%;
            }
            #warehouse-fees-table {
                width: 100%;
            }
            #module-storage-table {
                width: 100%;
                overflow-x: auto;
                display: block;
            }
        }

        /* Update table container styles */
        .table-scroll-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 1rem 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            max-width: 100%;
        }

        #warehouse-fees-table {
            min-width: 500px; /* Reduced from 600px */
            margin: 0;
            table-layout: fixed;
        }

        /* Adjust table cells for mobile */
        @media screen and (max-width: 768px) {
            .table-scroll-container {
                margin-left: -15px;
                margin-right: -15px;
                width: calc(100% + 30px);
            }

            #warehouse-fees-table {
                min-width: 100%;
            }

            #warehouse-fees-table th,
            #warehouse-fees-table td {
                padding: 8px 6px;
                font-size: 14px;
            }

            #warehouse-fees-table input {
                width: 90%;
                padding: 4px;
                font-size: 12px;
            }

            /* Stack fee type and toggle */
            #warehouse-fees-table th:first-child {
                width: 120px;
            }

            .fee-type-header {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            #feeTypeToggle {
                width: 100%;
                margin-top: 4px;
            }
        }
    </style>
    <script>
        (function() {
            var referrer = document.referrer;
            if (!referrer) {
                return;
            }
            var referrerAnchor = document.createElement('a');
            referrerAnchor.href = referrer;
            var currentAnchor = document.createElement('a');
            currentAnchor.href = window.location.href;

            var referrerPath = referrerAnchor.protocol + '//' + referrerAnchor.host + referrerAnchor.pathname;
            var currentPath  = currentAnchor.protocol + '//' + currentAnchor.host + currentAnchor.pathname;

            if (referrerPath !== currentPath) {
                sessionStorage.setItem('backButtonURL', referrer);
            }
        })();

        function goBack(){
            var backURL = sessionStorage.getItem('backButtonURL');
            if (backURL && backURL !== window.location.href) {
                window.location.href = backURL;
            } else {
                window.history.back();
            }
        }
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <a href="#" onclick="goBack()" class="back-icon">
        <!-- SVG for Back Arrow -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>

    <h1>Cost Estimate Calculator</h1>

    <?php
    if (isset($success_message)) {
        echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
    }
    if (isset($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    ?>

    <div id="buttons-section">
        <!-- Saved Estimates -->
        <button id="saved-estimates-button" class="submit-button">Saved Estimates</button>
        <div id="saved-estimates-list" style="display:none;">
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
                                <a href="view_estimate?id=<?php echo $estimate['id']; ?>">View</a> |
                                <a href="#" class="delete-estimate" data-id="<?php echo $estimate['id']; ?>"
                                   title="Delete Estimate">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p>You have no saved estimates.</p>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="calculator_results.php" id="calcForm">
        <!-- Hidden container for selected quotes -->
        <div id="selected-quotes-inputs">
            <?php if (!empty($selectedQuotes)): ?>
                <?php foreach ($selectedQuotes as $quote): ?>
                    <input type="hidden" name="selected_quotes[]" value="<?php echo htmlspecialchars(json_encode($quote)); ?>">
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Warehouse Fees Table -->
        <h2>Warehouse Fees</h2>
        <div class="table-scroll-container">
            <table id="warehouse-fees-table">
                <tr>
                    <th>
                        <div class="fee-type-header">
                            Fee Type
                            <select id="feeTypeToggle">
                                <option value="pallet">Per Pallet</option>
                                <option value="sqft">Per Sq. Ft.</option>
                            </select>
                        </div>
                    </th>
                    <th>WH 1</th>
                    <th class="warehouse2 hidden">WH 2</th>
                    <th class="warehouse3 hidden">WH 3</th>
                </tr>
                <!-- In Fee row -->
                <tr>
                    <td><span id="inFeeLabel">In Fee (per pallet)</span></td>
                    <td>
                        <input type="number" step="0.01" name="in_fee_per_pallet_w1"
                               value="<?php echo htmlspecialchars($in_fee_per_pallet[0]); ?>">
                    </td>
                    <td class="warehouse2 hidden">
                        <input type="number" step="0.01" name="in_fee_per_pallet_w2"
                               value="<?php echo htmlspecialchars($in_fee_per_pallet[1]); ?>">
                    </td>
                    <td class="warehouse3 hidden">
                        <input type="number" step="0.01" name="in_fee_per_pallet_w3"
                               value="<?php echo htmlspecialchars($in_fee_per_pallet[2]); ?>">
                    </td>
                </tr>
                <!-- Out Fee row -->
                <tr>
                    <td><span id="outFeeLabel">Out Fee (per pallet)</span></td>
                    <td>
                        <input type="number" step="0.01" name="out_fee_per_pallet_w1"
                               value="<?php echo htmlspecialchars($out_fee_per_pallet[0]); ?>">
                    </td>
                    <td class="warehouse2 hidden">
                        <input type="number" step="0.01" name="out_fee_per_pallet_w2"
                               value="<?php echo htmlspecialchars($out_fee_per_pallet[1]); ?>">
                    </td>
                    <td class="warehouse3 hidden">
                        <input type="number" step="0.01" name="out_fee_per_pallet_w3"
                               value="<?php echo htmlspecialchars($out_fee_per_pallet[2]); ?>">
                    </td>
                </tr>
                <!-- Monthly Storage Fee row -->
                <tr>
                    <td><span id="storageFeeLabel">Monthly Storage Cost (per pallet per month)</span></td>
                    <td>
                        <input type="number" step="0.01" name="storage_fee_per_pallet_w1"
                               value="<?php echo htmlspecialchars($storage_fee_per_pallet[0]); ?>">
                    </td>
                    <td class="warehouse2 hidden">
                        <input type="number" step="0.01" name="storage_fee_per_pallet_w2"
                               value="<?php echo htmlspecialchars($storage_fee_per_pallet[1]); ?>">
                    </td>
                    <td class="warehouse3 hidden">
                        <input type="number" step="0.01" name="storage_fee_per_pallet_w3"
                               value="<?php echo htmlspecialchars($storage_fee_per_pallet[2]); ?>">
                    </td>
                </tr>
            </table>
        </div>

        <button type="button" class="submit-button" onclick="addWarehouse()">Add Warehouse</button>
        <button type="button" class="delete-button" onclick="removeWarehouse()">Remove Warehouse</button>

        <br><br>
        <h2>Module Storage Costs</h2>
        <table id="module-storage-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <!-- By default, 1 warehouse: columns "WH 1 In", "WH 1 Out" -->
                    <th>WH 1 In</th>
                    <th>WH 1 Out</th>
                    <!-- Additional columns appear for warehouses 2 and 3 -->
                    <th class="warehouse2 hidden">WH 2 In</th>
                    <th class="warehouse2 hidden">WH 2 Out</th>
                    <th class="warehouse3 hidden">WH 3 In</th>
                    <th class="warehouse3 hidden">WH 3 Out</th>
                </tr>
                <tr class="sticky-current-inventory">
                    <th>Current Inventory</th>
                    <td>
                        <input type="number" name="current_inventory_w1"
                               value="<?php echo htmlspecialchars($current_inventory[0]); ?>">
                    </td>
                    <td></td>
                    <td class="warehouse2 hidden">
                        <input type="number" name="current_inventory_w2"
                               value="<?php echo htmlspecialchars($current_inventory[1]); ?>">
                    </td>
                    <td class="warehouse2 hidden"></td>
                    <td class="warehouse3 hidden">
                        <input type="number" name="current_inventory_w3"
                               value="<?php echo htmlspecialchars($current_inventory[2]); ?>">
                    </td>
                    <td class="warehouse3 hidden"></td>
                </tr>
            </thead>
            <tbody>
            <?php for ($i = 0; $i < count($months); $i++): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($display_months[$i]); ?>
                        <input type="hidden" name="months[]" value="<?php echo htmlspecialchars($months[$i]); ?>">
                    </td>
                    <!-- Warehouse 1 In/Out -->
                    <td>
                        <input type="number" name="pallets_entering_w1[]"
                               value="<?php echo htmlspecialchars($pallets_entering[0][$i]); ?>">
                    </td>
                    <td>
                        <input type="number" name="pallets_leaving_w1[]"
                               value="<?php echo htmlspecialchars($pallets_leaving[0][$i]); ?>">
                    </td>
                    <!-- Warehouse 2 In/Out -->
                    <td class="warehouse2 hidden">
                        <input type="number" name="pallets_entering_w2[]"
                               value="<?php echo htmlspecialchars($pallets_entering[1][$i]); ?>">
                    </td>
                    <td class="warehouse2 hidden">
                        <input type="number" name="pallets_leaving_w2[]"
                               value="<?php echo htmlspecialchars($pallets_leaving[1][$i]); ?>">
                    </td>
                    <!-- Warehouse 3 In/Out -->
                    <td class="warehouse3 hidden">
                        <input type="number" name="pallets_entering_w3[]"
                               value="<?php echo htmlspecialchars($pallets_entering[2][$i]); ?>">
                    </td>
                    <td class="warehouse3 hidden">
                        <input type="number" name="pallets_leaving_w3[]"
                               value="<?php echo htmlspecialchars($pallets_leaving[2][$i]); ?>">
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>

        <button type="button" class="submit-button" onclick="addMonth()">Add Month</button>
        <button type="button" class="delete-button" onclick="deleteLastMonth()">Delete Month</button>

        <br><br>
        <h3>Totals</h3>
        <table id="totals-table">
            <thead>
                <tr>
                    <th id="warehouse1_in_label">WH 1 In</th>
                    <th id="warehouse1_out_label">WH 1 Out</th>
                    <th id="warehouse2_in_label" class="warehouse2 hidden">WH 2 In</th>
                    <th id="warehouse2_out_label" class="warehouse2 hidden">WH 2 Out</th>
                    <th id="warehouse3_in_label" class="warehouse3 hidden">WH 3 In</th>
                    <th id="warehouse3_out_label" class="warehouse3 hidden">WH 3 Out</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td id="total_in_wh1">0</td>
                    <td id="total_out_wh1">0</td>
                    <td id="total_in_wh2" class="warehouse2 hidden">0</td>
                    <td id="total_out_wh2" class="warehouse2 hidden">0</td>
                    <td id="total_in_wh3" class="warehouse3 hidden">0</td>
                    <td id="total_out_wh3" class="warehouse3 hidden">0</td>
                </tr>
            </tbody>
        </table>

        <br>
        <input type="submit" value="Calculate" class="submit-button">
    </form>
</main>

<script>
    // Toggle the "Saved Estimates" list
    const savedEstimatesButton = document.getElementById('saved-estimates-button');
    const savedEstimatesList   = document.getElementById('saved-estimates-list');
    savedEstimatesButton.addEventListener('click', function(){
        if (savedEstimatesList.style.display === 'none' || savedEstimatesList.style.display === '') {
            savedEstimatesList.style.display = 'block';
        } else {
            savedEstimatesList.style.display = 'none';
        }
    });

    // Confirm delete
    const deleteLinks = document.querySelectorAll('.delete-estimate');
    deleteLinks.forEach(function(link){
        link.addEventListener('click', function(event){
            event.preventDefault();
            const id = this.getAttribute('data-id');
            if(confirm("Are you sure you want to delete this estimate?")) {
                window.location.href = "cost_estimate_calculator?delete_estimate=" + id;
            }
        });
    });

    // Manage number of warehouses (1..3)
    let currentWarehouseCount = 1;
    function addWarehouse(){
        if(currentWarehouseCount >= 3){
            alert("You already have 3 warehouses. Can't add more.");
            return;
        }
        currentWarehouseCount++;
        showHideWarehouses();
    }

    function removeWarehouse(){
        if(currentWarehouseCount <= 1){
            alert("You must have at least 1 warehouse.");
            return;
        }
        currentWarehouseCount--;
        showHideWarehouses();
    }

    function showHideWarehouses(){
        const w2 = document.querySelectorAll('.warehouse2');
        const w3 = document.querySelectorAll('.warehouse3');

        // Hide all 2 & 3 columns
        w2.forEach(el => el.classList.add('hidden'));
        w3.forEach(el => el.classList.add('hidden'));

        // Reveal as needed
        if(currentWarehouseCount >= 2){
            w2.forEach(el => el.classList.remove('hidden'));
        }
        if(currentWarehouseCount === 3){
            w3.forEach(el => el.classList.remove('hidden'));
        }

        updateTotals();
    }

    // Add Month row
    function addMonth() {
        const table = document.getElementById('module-storage-table').getElementsByTagName('tbody')[0];
        const rowCount = table.rows.length;
        if(rowCount < 1) return; // if no rows exist, might need custom logic

        // Grab the last row's hidden month value to increment
        const lastRow  = table.rows[rowCount - 1];
        const lastMonthInput = lastRow.querySelector('input[name="months[]"]');
        const lastValue = lastMonthInput.value;
        const [y, m] = lastValue.split('-');
        let year  = parseInt(y);
        let month = parseInt(m);

        month++;
        if(month > 12){
            month = 1;
            year++;
        }
        const newMonthValue = year.toString().padStart(4,'0') + '-' + month.toString().padStart(2,'0');
        const tempDate = new Date(year, month-1, 1);
        const display = tempDate.toLocaleString('default', {month:'long', year:'numeric'});

        const newRow = document.createElement('tr');

        // Month cell
        const monthTd = document.createElement('td');
        monthTd.textContent = display;
        const hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = 'months[]';
        hidden.value = newMonthValue;
        monthTd.appendChild(hidden);
        newRow.appendChild(monthTd);

        // 3 Warehouses
        for(let w=1; w<=3; w++){
            const tdEnter = document.createElement('td');
            const tdLeave = document.createElement('td');

            tdEnter.innerHTML = '<input type="number" name="pallets_entering_w'+w+'[]" value="0">';
            tdLeave.innerHTML = '<input type="number" name="pallets_leaving_w'+w+'[]" value="0">';

            if(w === 2) {
                tdEnter.classList.add('warehouse2');
                tdLeave.classList.add('warehouse2');
                if(currentWarehouseCount < 2){
                    tdEnter.classList.add('hidden');
                    tdLeave.classList.add('hidden');
                }
            }
            if(w === 3) {
                tdEnter.classList.add('warehouse3');
                tdLeave.classList.add('warehouse3');
                if(currentWarehouseCount < 3){
                    tdEnter.classList.add('hidden');
                    tdLeave.classList.add('hidden');
                }
            }

            newRow.appendChild(tdEnter);
            newRow.appendChild(tdLeave);
        }

        table.appendChild(newRow);
        updateTotals();
    }

    function deleteLastMonth(){
        const table = document.getElementById('module-storage-table').getElementsByTagName('tbody')[0];
        const rowCount = table.rows.length;
        if(rowCount > 0){
            table.deleteRow(rowCount-1);
        }
        updateTotals();
    }

    // Totals
    function updateTotals(){
        let totalIn  = [0,0,0];
        let totalOut = [0,0,0];

        // Current Inventory -> add to totalIn
        const ciW1 = parseInt(document.querySelector('input[name="current_inventory_w1"]').value) || 0;
        const ciW2 = parseInt(document.querySelector('input[name="current_inventory_w2"]').value) || 0;
        const ciW3 = parseInt(document.querySelector('input[name="current_inventory_w3"]').value) || 0;
        totalIn[0] += ciW1;
        totalIn[1] += ciW2;
        totalIn[2] += ciW3;

        const table = document.getElementById('module-storage-table').getElementsByTagName('tbody')[0];
        const rows  = table.querySelectorAll('tr');

        rows.forEach(function(row){
            const inputsEntering = row.querySelectorAll('input[name^="pallets_entering_w"]');
            const inputsLeaving  = row.querySelectorAll('input[name^="pallets_leaving_w"]');
            inputsEntering.forEach(function(inp){
                const whIndex = parseInt(inp.name.replace('pallets_entering_w','')) - 1;
                totalIn[whIndex] += parseInt(inp.value) || 0;
            });
            inputsLeaving.forEach(function(inp){
                const whIndex = parseInt(inp.name.replace('pallets_leaving_w','')) - 1;
                totalOut[whIndex] += parseInt(inp.value) || 0;
            });
        });

        document.getElementById('total_in_wh1').textContent  = totalIn[0].toLocaleString();
        document.getElementById('total_out_wh1').textContent = totalOut[0].toLocaleString();

        if(document.getElementById('total_in_wh2')) {
            document.getElementById('total_in_wh2').textContent  = totalIn[1].toLocaleString();
            document.getElementById('total_out_wh2').textContent = totalOut[1].toLocaleString();
        }
        if(document.getElementById('total_in_wh3')) {
            document.getElementById('total_in_wh3').textContent  = totalIn[2].toLocaleString();
            document.getElementById('total_out_wh3').textContent = totalOut[2].toLocaleString();
        }
    }

    // Initialize
    function attachListeners(){
        const table = document.getElementById('module-storage-table');
        table.addEventListener('input', function(e){
            if(e.target && e.target.tagName.toLowerCase() === 'input'){
                updateTotals();
            }
        });
        showHideWarehouses();
        updateTotals();
    }
    attachListeners();

    // Toggle "Per Pallet" vs "Per Sq. Ft." labels
    const feeTypeToggle = document.getElementById('feeTypeToggle');
    feeTypeToggle.addEventListener('change', function() {
        if (this.value === 'sqft') {
            document.getElementById('inFeeLabel').textContent = 'In Fee (per sq. ft.)';
            document.getElementById('outFeeLabel').textContent = 'Out Fee (per sq. ft.)';
            document.getElementById('storageFeeLabel').textContent = 'Monthly Storage Cost (per sq. ft. per month)';
        } else {
            document.getElementById('inFeeLabel').textContent = 'In Fee (per pallet)';
            document.getElementById('outFeeLabel').textContent = 'Out Fee (per pallet)';
            document.getElementById('storageFeeLabel').textContent = 'Monthly Storage Cost (per pallet per month)';
        }
    });
</script>
</body>
</html>
