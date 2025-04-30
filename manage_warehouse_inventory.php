<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

// Get and validate warehouse ID
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
if ($warehouse_id <= 0) {
    die("Invalid Warehouse ID provided.");
}

$warehouse = null;
$pallets_in_storage = [];
$errorMessage = '';
$successMessage = $_SESSION['move_pallet_message'] ?? ''; // Check for success message from POST redirect
if (isset($_SESSION['move_pallet_message'])) unset($_SESSION['move_pallet_message']); // Clear message after displaying

$total_storage_cost_monthly_rate = 0;
$all_projects = [];
$other_warehouses = [];

try {
    // Fetch Warehouse Details
    $stmtW = $conn->prepare("SELECT * FROM warehouses WHERE id = ?");
    if (!$stmtW) throw new Exception("Failed to prepare warehouse query: " . $conn->error);
    $stmtW->bind_param("i", $warehouse_id);
    $stmtW->execute();
    $resultW = $stmtW->get_result();
    if ($resultW->num_rows === 0) {
        throw new Exception("Warehouse not found.");
    }
    $warehouse = $resultW->fetch_assoc();
    $stmtW->close();

    // Fetch Pallets currently in this warehouse
    $stmtP = $conn->prepare("
        SELECT 
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            ip.arrival_date,
            m.vendor_name AS origin_vendor
        FROM inventory_pallets ip
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE ip.current_warehouse_id = ? AND ip.status = 'In Warehouse'
        ORDER BY ip.arrival_date DESC, ip.id DESC
    ");
    if (!$stmtP) throw new Exception("Failed to prepare pallets query: " . $conn->error);
    $stmtP->bind_param("i", $warehouse_id);
    $stmtP->execute();
    $resultP = $stmtP->get_result();
    
    $today = new DateTime(); // For cost calculation
    $daily_storage_rate = ($warehouse['monthly_storage_fee'] ?? 0) / 30;
    $total_pallets = 0;

    while ($pallet = $resultP->fetch_assoc()) {
        $pallets_in_storage[] = $pallet;
        $total_pallets++;
        // Calculate storage cost for this pallet since arrival
        if (!empty($pallet['arrival_date']) && $daily_storage_rate > 0) {
            try {
                $arrival_date = new DateTime($pallet['arrival_date']);
                // Calculate days difference (inclusive, so add 1 if needed based on business logic)
                // For a monthly rate estimate, let's calculate based on days passed
                $interval = $arrival_date->diff($today);
                $days_in_storage = $interval->days + 1; // Include arrival day
                // Accumulate cost based on daily rate * days
                // Note: This is an estimate. Accurate monthly billing might require different logic.
                // $total_storage_cost += ($days_in_storage * $daily_storage_rate);
            } catch (Exception $dateEx) {
                // Ignore pallets with invalid arrival dates for cost calculation
                error_log("Invalid date format for pallet ID {$pallet['pallet_id']}: {$pallet['arrival_date']}");
            }
        }
    }
    $stmtP->close();
    
    // Simple monthly rate calculation based on current pallet count
    $total_storage_cost_monthly_rate = $total_pallets * ($warehouse['monthly_storage_fee'] ?? 0);

    // Fetch all projects for the destination dropdown
    $stmtAllP = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
    if ($stmtAllP) {
        $stmtAllP->execute();
        $resultAllP = $stmtAllP->get_result();
        while ($proj = $resultAllP->fetch_assoc()) {
            $all_projects[] = $proj;
        }
        $stmtAllP->close();
    }

    // Fetch other warehouses for the destination dropdown
    $stmtOtherW = $conn->prepare("SELECT id, name FROM warehouses WHERE id != ? ORDER BY name ASC");
    if ($stmtOtherW) {
        $stmtOtherW->bind_param("i", $warehouse_id);
        $stmtOtherW->execute();
        $resultOtherW = $stmtOtherW->get_result();
        while ($wh = $resultOtherW->fetch_assoc()) {
            $other_warehouses[] = $wh;
        }
        $stmtOtherW->close();
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Warehouse Inventory - <?php echo htmlspecialchars($warehouse['name'] ?? 'Unknown Warehouse'); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .warehouse-details-container {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .warehouse-info p {
            margin: 6px 0;
            line-height: 1.5;
        }
        .warehouse-info strong {
             color: #293E4C; 
        }
        .warehouse-actions a {
            margin-left: 15px; /* Add space between buttons if needed */
        }
        .cost-summary {
            margin-bottom: 25px;
        }
        .cost-summary span {
             font-weight: bold; color: #488C9A; font-size: 1.1em;
        }
         .error-message,
         .success-message { /* Added success message style */
            color: red;
            background-color: #ffe6e6;
            padding: 10px;
            border: 1px solid red;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center; /* Center align text */
        }
        .success-message {
            color: green;
            border-color: green;
            background-color: #e6ffed;
        }
        /* Style for future buttons like "Move Pallets" */
        .page-actions {
             text-align: right;
             margin-bottom: 20px;
        }
        /* Styles for the move pallet form */
        #movePalletFormContainer {
             background-color: #f0f8ff; /* Light blue background */
             padding: 20px;
             border: 1px solid #b0e0e6; /* Powder blue border */
             border-radius: 8px;
             margin-top: 30px;
        }
        #movePalletFormContainer h3 {
             margin-top: 0;
             color: #293E4C;
             border-bottom: 1px solid #ccc;
             padding-bottom: 10px;
             margin-bottom: 15px;
        }
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
        }
        .form-row > div {
            flex: 1;
            min-width: 200px; /* Adjust as needed */
        }
        .form-row label,
        #movePalletFormContainer label {
             display: block;
             margin-bottom: 5px;
             font-weight: 500;
        }
        .form-row input[type="text"],
        .form-row input[type="date"],
        .form-row select,
        #movePalletFormContainer select {
             width: 100%;
             padding: 8px;
             border: 1px solid #ccc;
             border-radius: 4px;
             box-sizing: border-box;
        }
        label.radio-label {
            display: inline-block; 
            margin-right: 15px; 
            font-weight: normal; 
        }
        label.radio-label input[type=radio] {
             width: auto; margin-right: 5px; vertical-align: middle; 
        }

        /* Modal Styling */
        .modal {
            display: none !important; /* Hidden by default - ensure this takes precedence initially */
            position: fixed; 
            z-index: 1000; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.5); 
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto; 
            padding: 25px;
            border: 1px solid #888;
            width: 80%; 
            max-width: 600px; 
            border-radius: 8px;
            position: relative;
        }
        .close-modal {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-modal:hover,
        .close-modal:focus {
            color: black;
            text-decoration: none;
        }
        /* Ensure form elements inside modal content look okay */
        .modal-content #movePalletFormContainer {
            background-color: transparent; /* Remove specific bg if modal provides it */
            border: none;
            padding: 0;
            margin-top: 0;
        }

    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php if (!empty($successMessage)): ?>
        <div class="success-message">
            <?php echo htmlspecialchars($successMessage); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
        <p><a href="manage_warehouses.php" class="action-buttons">&larr; Back to Warehouses List</a></p>
    <?php elseif (!$warehouse): ?>
        <div class="error-message">
            Warehouse not found or could not be loaded.
        </div>
        <p><a href="manage_warehouses.php" class="action-buttons">&larr; Back to Warehouses List</a></p>
    <?php else: ?>
        <!-- Warehouse Details Header -->
        <div class="warehouse-details-container">
            <div class="warehouse-info">
                <h1><?php echo htmlspecialchars($warehouse['name']); ?></h1>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($warehouse['address']); ?></p>
                <p><strong>In Fee:</strong> $<?php echo number_format($warehouse['in_fee'] ?? 0, 2); ?></p>
                <p><strong>Out Fee:</strong> $<?php echo number_format($warehouse['out_fee'] ?? 0, 2); ?></p>
                <p><strong>Monthly Storage Fee (per Pallet):</strong> $<?php echo number_format($warehouse['monthly_storage_fee'] ?? 0, 2); ?></p>
            </div>
            <div class="warehouse-actions">
                <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="action-buttons">Edit Warehouse Info</a>
            </div>
        </div>

        <!-- Cost Summary -->
        <div class="cost-summary">
            <h2>Current Inventory Summary</h2>
            <p>Total Pallets Currently Stored: <span><?php echo number_format($total_pallets); ?></span></p>
            <p>Estimated Monthly Storage Cost (Current Rate): <span>$<?php echo number_format($total_storage_cost_monthly_rate, 2); ?></span></p>
            <!-- Add more cost details here if calculated (e.g., total accrued cost) -->
        </div>
        
        <!-- Move Pallets Button -->
        <div class="page-actions">
            <button id="movePalletsBtn" class="action-button" disabled>Move Selected Pallets</button>
        </div>

        <!-- Pallet Inventory Table (wrapped in a form for submission) -->
        <h2>Pallet Inventory (Status: In Warehouse)</h2>
        <form id="palletInventoryForm" method="POST" action="handle_pallet_move.php"> <!-- Action points to a new handler script -->
            <input type="hidden" name="current_warehouse_id" value="<?php echo $warehouse_id; ?>">
            <input type="hidden" name="action" value="move_pallets">
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-pallets" onclick="toggleAllPalletCheckboxes(this.checked)"></th>
                            <th>Pallet ID</th>
                            <th>Identifier</th>
                            <th>Origin Vendor</th>
                            <th>Wattage</th>
                            <th>Quantity</th>
                            <th>Arrival Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($pallets_in_storage)): ?>
                            <?php foreach ($pallets_in_storage as $pallet): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_pallets[]" value="<?php echo $pallet['pallet_id']; ?>" class="pallet-checkbox" onclick="updateMoveButtonState()"></td>
                                    <td><?php echo $pallet['pallet_id']; ?></td>
                                    <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                    <td><?php echo number_format($pallet['quantity']); ?></td>
                                    <td><?php echo htmlspecialchars($pallet['arrival_date'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">No pallets currently stored in this warehouse.</td> <!-- Updated colspan -->
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Hidden Move Pallet Form Container -->
            <div id="movePalletFormContainer" style="display: none;">
                <h3>Create Transfer Delivery</h3>
                <div class="form-row">
                    <div>
                        <label for="bol_number">BOL Number (Optional):</label>
                        <input type="text" id="bol_number" name="bol_number">
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label for="departure_date">Departure Date:</label>
                        <input type="date" id="departure_date" name="departure_date" required>
                    </div>
                     <div>
                        <label for="est_arrival_date">Est. Arrival Date (Optional):</label>
                        <input type="date" id="est_arrival_date" name="est_arrival_date">
                    </div>
                </div>
                 <div>
                    <label style="margin-bottom: 10px; display:block;">Destination:</label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type" value="project" checked onchange="toggleDestinationSelect()"> Project
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type" value="warehouse" onchange="toggleDestinationSelect()"> Another Warehouse
                    </label>
                </div>
                 <div id="destinationSelectContainer">
                    <label for="destination_id" id="destinationLabel">Project:</label>
                    <select name="destination_id" id="destination_id" required>
                        <!-- Options loaded by JS -->
                    </select>
                </div>
                
                <div style="margin-top: 20px;">
                    <button type="button" id="submitMoveBtn" class="action-button">Create Transfer Delivery</button>
                </div>
            </div>
            
        </form> <!-- End palletInventoryForm -->

        <div class="back-link" style="margin-top: 20px;">
            <a href="manage_warehouses.php" class="action-button">&larr; Back to Warehouses List</a>
        </div>
    <?php endif; ?>
</main>

<!-- Modal Structure -->
<div id="moveModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <!-- Content from #movePalletFormContainer will be moved here -->
    </div>
</div>

<!-- Embed PHP data for JS -->
<script>
    const allProjectsData = <?php echo json_encode($all_projects); ?>;
    const otherWarehousesData = <?php echo json_encode($other_warehouses); ?>;
</script>

<script>
// Select/Deselect All Pallet Checkboxes
function toggleAllPalletCheckboxes(isChecked) {
    document.querySelectorAll('.pallet-checkbox').forEach(function(checkbox) {
        checkbox.checked = isChecked;
    });
    updateMoveButtonState(); // Update button state after toggling all
}

// Enable/disable Move Pallets button and reset form location on disable
function updateMoveButtonState() {
    const moveBtn = document.getElementById('movePalletsBtn');
    const checkedBoxes = document.querySelectorAll('.pallet-checkbox:checked');
    const shouldBeDisabled = checkedBoxes.length === 0;
    console.log(`updateMoveButtonState: ${checkedBoxes.length} boxes checked. Button disabled: ${shouldBeDisabled}`);
    moveBtn.disabled = shouldBeDisabled;

    // If no boxes are checked, ensure the modal is closed and form is back in place
    if (checkedBoxes.length === 0) {
        closeMoveModal(); 
    }
}

// Get references to modal elements
const moveModal = document.getElementById('moveModal');
const modalContent = moveModal.querySelector('.modal-content');
const closeModalBtn = moveModal.querySelector('.close-modal');
const formContainer = document.getElementById('movePalletFormContainer');
const originalFormParent = formContainer.parentNode; // Keep track of where the form belongs

// Show the move pallet modal
function openMoveModal() {
    console.log("openMoveModal called."); // Log start of function
    const moveBtn = document.getElementById('movePalletsBtn');

    // Only open if the button is NOT disabled
    if (!moveBtn.disabled) {
        console.log("Button is enabled, proceeding to open modal."); // Log inside if
        // Move the form content into the modal's content area
        // Insert the form container after the close button
        if (closeModalBtn.nextSibling) {
            modalContent.insertBefore(formContainer, closeModalBtn.nextSibling);
        } else {
            modalContent.appendChild(formContainer);
        }
        formContainer.style.display = 'block'; // Make the form content visible
        console.log("Form container display set to block.");
        
        moveModal.style.setProperty('display', 'block', 'important'); // Use setProperty to add !important
        console.log("Modal display set to block !important.");
        
        toggleDestinationSelect(); // Initialize dropdown
    } else {
        console.log("Button is disabled, modal not opened."); // Log else case
    }
}

// Close the move pallet modal
function closeMoveModal() {
    if (moveModal.style.display === 'block') {
         // Move the form container back to its original parent and hide it
        originalFormParent.appendChild(formContainer);
        formContainer.style.display = 'none';
        moveModal.style.display = 'none'; // Hide the modal
    }
}

// --- Event Listeners ---

// Open modal when the Move button is clicked
document.getElementById('movePalletsBtn').addEventListener('click', openMoveModal);

// Close modal listeners
closeModalBtn.addEventListener('click', closeMoveModal);

window.addEventListener('click', function(event) {
    if (event.target == moveModal) { // Clicked outside the modal content
        closeMoveModal();
    }
});

// Trigger form submission when the button inside the modal is clicked
document.getElementById('submitMoveBtn').addEventListener('click', function() {
    console.log("Submit button inside modal clicked. Submitting form...");
    const mainForm = document.getElementById('palletInventoryForm');

    // Find selected destination type and value from modal
    const selectedType = document.querySelector('input[name="destination_type"]:checked').value;
    const selectedId = document.getElementById('destination_id').value;

    // --- Create/update hidden fields in the main form ---
    let typeInput = mainForm.querySelector('input[name="destination_type_hidden"]');
    if (!typeInput) {
        typeInput = document.createElement('input');
        typeInput.type = 'hidden';
        typeInput.name = 'destination_type'; // Use the original name expected by backend
        mainForm.appendChild(typeInput);
    }
    typeInput.value = selectedType;

    let idInput = mainForm.querySelector('input[name="destination_id_hidden"]');
    if (!idInput) {
        idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'destination_id'; // Use the original name expected by backend
        mainForm.appendChild(idInput);
    }
    idInput.value = selectedId;
    
    // Also ensure other modal fields are submitted if needed (BOL, Dates)
    let bolInput = mainForm.querySelector('input[name="bol_number_hidden"]');
    if (!bolInput) {
        bolInput = document.createElement('input');
        bolInput.type = 'hidden';
        bolInput.name = 'bol_number';
        mainForm.appendChild(bolInput);
    }
    bolInput.value = document.getElementById('bol_number').value;

    let departureDateInput = mainForm.querySelector('input[name="departure_date_hidden"]');
    if (!departureDateInput) {
        departureDateInput = document.createElement('input');
        departureDateInput.type = 'hidden';
        departureDateInput.name = 'departure_date';
        mainForm.appendChild(departureDateInput);
    }
    departureDateInput.value = document.getElementById('departure_date').value;

    let estArrivalDateInput = mainForm.querySelector('input[name="est_arrival_date_hidden"]');
    if (!estArrivalDateInput) {
        estArrivalDateInput = document.createElement('input');
        estArrivalDateInput.type = 'hidden';
        estArrivalDateInput.name = 'est_arrival_date';
        mainForm.appendChild(estArrivalDateInput);
    }
    estArrivalDateInput.value = document.getElementById('est_arrival_date').value;
    // --- End of hidden field creation/update ---

    mainForm.submit();
});

// Toggle Destination Select Dropdown Label and Content 
function toggleDestinationSelect() {
    console.log("toggleDestinationSelect called."); // Log start of function
    var destType = document.querySelector('input[name="destination_type"]:checked').value;
    var destLabel = document.getElementById('destinationLabel');
    var destSelect = document.getElementById('destination_id');

    destLabel.textContent = (destType === 'project') ? 'Project:' : 'Destination Warehouse:';
    destSelect.innerHTML = ''; // Clear existing options

    var data = (destType === 'project') ? allProjectsData : otherWarehousesData;
    var placeholder = (destType === 'project') ? '-- Select Project --' : '-- Select Warehouse --';
    var nameField = (destType === 'project') ? 'project_name' : 'name';

    if (data.length === 0) {
        destSelect.innerHTML = `<option value="">No ${destType}s found</option>`;
        destSelect.disabled = true;
    } else {
        destSelect.innerHTML = `<option value="">${placeholder}</option>`;
        data.forEach(function(item) {
            var option = document.createElement('option');
            option.value = item.id;
            option.textContent = item[nameField];
            destSelect.appendChild(option);
        });
        destSelect.disabled = false;
    }
}

// Initial setup on page load
document.addEventListener('DOMContentLoaded', function() {
    updateMoveButtonState(); // Set initial button state
    // Ensure form is hidden initially (it has style="display: none" in HTML)
});

</script>

</body>
</html> 