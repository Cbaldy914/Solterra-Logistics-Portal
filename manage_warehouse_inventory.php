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
$pallets_in_transit = [];
$errorMessage = '';
$successMessage = $_SESSION['move_pallet_message'] ?? '';
if (isset($_SESSION['move_pallet_message'])) unset($_SESSION['move_pallet_message']);

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

    // Fetch Pallets in Warehouse
    $stmtP_Stored = $conn->prepare("
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
    if (!$stmtP_Stored) throw new Exception("Failed to prepare stored pallets query: " . $conn->error);
    $stmtP_Stored->bind_param("i", $warehouse_id);
    $stmtP_Stored->execute();
    $resultP_Stored = $stmtP_Stored->get_result();
    
    $today = new DateTime();
    $daily_storage_rate = ($warehouse['monthly_storage_fee'] ?? 0) / 30;
    $total_pallets = 0;

    while ($pallet = $resultP_Stored->fetch_assoc()) {
        $pallets_in_storage[] = $pallet;
        $total_pallets++;
    }
    $stmtP_Stored->close();
    
    // Fetch Pallets in transit
    $stmtP_Transit = $conn->prepare("
        SELECT 
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            m.vendor_name AS origin_vendor,
            d.bol_number AS delivery_bol,
            d.anticipated_delivery_date AS est_arrival_date,
            d.id AS delivery_id 
        FROM inventory_pallets ip
        JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
        JOIN deliveries d ON dp.delivery_id = d.id
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE ip.status = 'In Transit to Warehouse' AND d.warehouse_id = ?
        ORDER BY d.anticipated_delivery_date ASC, ip.id DESC
    ");
    if (!$stmtP_Transit) throw new Exception("Failed to prepare transit pallets query: " . $conn->error);
    $stmtP_Transit->bind_param("i", $warehouse_id);
    $stmtP_Transit->execute();
    $resultP_Transit = $stmtP_Transit->get_result();
    while ($pallet = $resultP_Transit->fetch_assoc()) {
        $pallets_in_transit[] = $pallet;
    }
    $stmtP_Transit->close();
    
    // Monthly cost estimate
    $total_storage_cost_monthly_rate = $total_pallets * ($warehouse['monthly_storage_fee'] ?? 0);

    // Fetch all projects
    $stmtAllP = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
    if ($stmtAllP) {
        $stmtAllP->execute();
        $resultAllP = $stmtAllP->get_result();
        while ($proj = $resultAllP->fetch_assoc()) {
            $all_projects[] = $proj;
        }
        $stmtAllP->close();
    }

    // Fetch other warehouses
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
    <title>Manage Warehouse Inventory - <?php echo htmlspecialchars($warehouse['name'] ?? 'Unknown'); ?></title>
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
            flex-wrap: wrap;
        }
        .warehouse-info p {
            margin: 6px 0;
            line-height: 1.5;
        }
        .cost-summary {
            margin-bottom: 25px;
        }
        .error-message, .success-message {
            color: red;
            background-color: #ffe6e6;
            padding: 10px;
            border: 1px solid red;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .success-message {
            color: green;
            border-color: green;
            background-color: #e6ffed;
        }
        .table-controls-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        .filter-controls {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .tabs-container {
            text-align: center;
            width: 100%;
            margin-bottom: 10px;
        }
        .tabs {
            display: inline-flex;
            gap: 1px;
        }
        .tabs button {
            background: #293E4C;
            color: #fff;
            padding: 10px;
            cursor: pointer;
            font-weight: 600;
            border: none;
            font-size: 1em;
        }
        .tabs button.active {
            background: #f39c12;
            color: #000;
        }
        .page-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }
        .inventory-section {
            display: none;
            margin-top: 10px;
        }
        .inventory-section.active {
            display: block;
        }
        .table-responsive {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        th {
            background-color: #e9ecef;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .modal {
            display: none !important;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0;
            width: 100%; height: 100%;
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
        #movePalletFormContainer {
            background-color: #f0f8ff;
            padding: 20px;
            border: 1px solid #b0e0e6;
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
            min-width: 200px;
        }
        .form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        label.radio-label {
            display: inline-block;
            margin-right: 15px;
            font-weight: normal;
        }
        label.radio-label input[type=radio] {
            width: auto;
            margin-right: 5px;
            vertical-align: middle;
        }
        .action-button {
            padding: 10px 20px;
            background-color: #488C9A;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .action-button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        .filter-controls label {
            font-weight: 500;
        }
        .filter-controls input[type="text"],
        .filter-controls select {
            padding: 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .back-link {
            margin-top: 20px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>
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
        <!-- Warehouse Details -->
        <div class="warehouse-details-container">
            <div class="warehouse-info">
                <h1><?php echo htmlspecialchars($warehouse['name']); ?></h1>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($warehouse['address']); ?></p>
                <p><strong>In Fee:</strong> $<?php echo number_format($warehouse['in_fee'] ?? 0, 2); ?></p>
                <p><strong>Out Fee:</strong> $<?php echo number_format($warehouse['out_fee'] ?? 0, 2); ?></p>
                <p><strong>Monthly Storage Fee (per Pallet):</strong> $<?php echo number_format($warehouse['monthly_storage_fee'] ?? 0, 2); ?></p>
            </div>
            <div class="warehouse-actions">
                <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="action-buttons">
                    Edit Warehouse Info
                </a>
            </div>
        </div>

        <!-- Cost Summary -->
        <div class="cost-summary">
            <h2>Current Inventory Summary</h2>
            <p>Total Pallets Currently Stored: <span><?php echo number_format($total_pallets); ?></span></p>
            <p>Estimated Monthly Storage Cost (Current Rate): <span>$<?php echo number_format($total_storage_cost_monthly_rate, 2); ?></span></p>
        </div>

        <!-- TABS (always visible) -->
        <div class="tabs-container">
            <div class="tabs">
                <button id="toggleStoredBtn" class="active">Stored Inventory</button>
                <button id="toggleTransitBtn">Inbound Transit</button>
            </div>
        </div>

        <!-- STORED INVENTORY SECTION -->
        <div id="storedInventorySection" class="inventory-section active">
            <h2>Pallet Inventory (In Warehouse)</h2>
            <div class="table-controls-header">
                <div class="filter-controls">
                    <label>Search:</label>
                    <input type="text" id="storedSearch" placeholder="Filter by Identifier, Vendor...">
                    <label>Wattage:</label>
                    <select id="storedWattageFilter">
                        <option value="">All</option>
                        <?php
                        $storedWattages = [];
                        foreach ($pallets_in_storage as $p) {
                            $storedWattages[] = $p['wattage'];
                        }
                        $storedWattages = array_unique($storedWattages);
                        sort($storedWattages);
                        foreach ($storedWattages as $w) {
                            echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="page-actions">
                    <button id="movePalletsBtn" class="action-button" disabled>Move Selected Pallets</button>
                </div>
            </div>

            <form id="palletInventoryForm" method="POST" action="handle_pallet_move.php">
                <input type="hidden" name="current_warehouse_id" value="<?php echo $warehouse_id; ?>">
                <input type="hidden" name="action" value="move_pallets">

                <div class="table-responsive">
                    <table id="storedTable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all-stored"></th>
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
                                        <td>
                                            <input type="checkbox"
                                                   name="selected_pallets[]"
                                                   value="<?php echo $pallet['pallet_id']; ?>"
                                                   class="pallet-checkbox-stored">
                                        </td>
                                        <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                        <td><?php echo number_format($pallet['quantity']); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['arrival_date'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6">No pallets currently stored in this warehouse.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Hidden "Move Pallet" form container (for modal) -->
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
                            <label for="est_arrival_date">Est. Arrival Date:</label>
                            <input type="date" id="est_arrival_date" name="est_arrival_date" required>
                        </div>
                    </div>
                    <div>
                        <label style="margin-bottom: 10px; display:block;">Destination:</label>
                        <label class="radio-label">
                            <input type="radio" name="destination_type" value="project" checked> Project
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="destination_type" value="warehouse"> Another Warehouse
                        </label>
                    </div>
                    <div id="destinationSelectContainer">
                        <label for="destination_id" id="destinationLabel">Project:</label>
                        <select name="destination_id" id="destination_id" required></select>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="button" id="submitMoveBtn" class="action-button">Create Transfer Delivery</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- INBOUND TRANSIT SECTION -->
        <div id="transitInventorySection" class="inventory-section">
            <h2>Pallet Inventory (Inbound Transit)</h2>
            <div class="table-controls-header">
                <div class="filter-controls">
                    <label>Search:</label>
                    <input type="text" id="transitSearch" placeholder="Filter by Identifier, Vendor...">
                    <label>Wattage:</label>
                    <select id="transitWattageFilter">
                        <option value="">All</option>
                        <?php
                        $transitWattages = [];
                        foreach ($pallets_in_transit as $p) {
                            $transitWattages[] = $p['wattage'];
                        }
                        $transitWattages = array_unique($transitWattages);
                        sort($transitWattages);
                        foreach ($transitWattages as $w) {
                            echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="page-actions">
                    <button id="receivePalletsBtn" class="action-button" disabled>Receive Selected Pallets</button>
                </div>
            </div>

            <form id="receivePalletsForm" method="POST" action="handle_pallet_arrival.php">
                <input type="hidden" name="warehouse_id" value="<?php echo $warehouse_id; ?>">
                <input type="hidden" name="action" value="receive_pallets">

                <div class="table-responsive">
                    <table id="transitTable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all-transit"></th>
                                <th>Identifier</th>
                                <th>Origin Vendor</th>
                                <th>Wattage</th>
                                <th>Quantity</th>
                                <th>Delivery BOL</th>
                                <th>Est. Arrival Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($pallets_in_transit)): ?>
                                <?php foreach ($pallets_in_transit as $pallet): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox"
                                                   name="inbound_pallets[]"
                                                   value="<?php echo $pallet['pallet_id']; ?>"
                                                   class="transit-checkbox">
                                            <input type="hidden"
                                                   name="delivery_id_for_pallet[<?php echo $pallet['pallet_id']; ?>]"
                                                   value="<?php echo $pallet['delivery_id']; ?>">
                                        </td>
                                        <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                        <td><?php echo number_format($pallet['quantity']); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['delivery_bol'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['est_arrival_date'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7">No pallets currently in transit to this warehouse.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Hidden "Receive Pallets" form container -->
                <div id="receiveFormContainer" style="display: none;">
                    <h3>Receive Pallets</h3>
                    <div class="form-row">
                        <div>
                            <label for="receive_bol">BOL Number (Confirm/Update):</label>
                            <input type="text" id="receive_bol" name="receive_bol">
                        </div>
                        <div>
                            <label for="actual_arrival_date">Actual Arrival Date:</label>
                            <input type="date" id="actual_arrival_date" name="actual_arrival_date" required>
                        </div>
                    </div>
                    <button type="button" id="confirmReceiveBtn" class="action-button">Mark as Received</button>
                </div>
            </form>
        </div>

        <div class="back-link">
            <a href="manage_warehouses.php" class="action-buttons">&larr; Back to Warehouses List</a>
        </div>
    <?php endif; ?>
</main>

<!-- MOVE PALLETS MODAL -->
<div id="moveModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <!-- #movePalletFormContainer is moved here dynamically -->
    </div>
</div>

<!-- RECEIVE PALLETS MODAL -->
<div id="receiveModal" class="modal">
    <div class="modal-content">
        <span class="close-receive-modal">&times;</span>
        <!-- #receiveFormContainer is moved here dynamically -->
    </div>
</div>

<script>
// Projects & Warehouses for the Move Pallets dropdown
const allProjectsData = <?php echo json_encode($all_projects); ?>;
const otherWarehousesData = <?php echo json_encode($other_warehouses); ?>;

/** Show or hide the stored vs. transit sections. */
function showInventoryView(viewType) {
    // Sections
    const storedSec  = document.getElementById('storedInventorySection');
    const transitSec = document.getElementById('transitInventorySection');
    storedSec.classList.remove('active');
    transitSec.classList.remove('active');

    // Tab buttons
    document.getElementById('toggleStoredBtn').classList.remove('active');
    document.getElementById('toggleTransitBtn').classList.remove('active');

    // Activate whichever the user clicked
    if (viewType === 'stored') {
        storedSec.classList.add('active');
        document.getElementById('toggleStoredBtn').classList.add('active');
    } else {
        transitSec.classList.add('active');
        document.getElementById('toggleTransitBtn').classList.add('active');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // On load, default to "stored"
    showInventoryView('stored');

    // Hook tab button clicks
    document.getElementById('toggleStoredBtn').addEventListener('click', () => showInventoryView('stored'));
    document.getElementById('toggleTransitBtn').addEventListener('click', () => showInventoryView('transit'));

    // Set up "select all" checkboxes
    const selAllStored = document.getElementById('select-all-stored');
    if (selAllStored) {
        selAllStored.addEventListener('change', (e) => {
            toggleAllCheckboxesInTable('storedTable', e.target.checked);
            updateMoveButtonState();
        });
    }
    const selAllTransit = document.getElementById('select-all-transit');
    if (selAllTransit) {
        selAllTransit.addEventListener('change', (e) => {
            toggleAllCheckboxesInTable('transitTable', e.target.checked);
            updateReceiveButtonState();
        });
    }

    // Child checkboxes for stored
    document.querySelectorAll('.pallet-checkbox-stored').forEach(cb => {
        cb.addEventListener('change', updateMoveButtonState);
    });
    // Child checkboxes for transit
    document.querySelectorAll('.transit-checkbox').forEach(cb => {
        cb.addEventListener('change', updateReceiveButtonState);
    });

    // Initial states
    updateMoveButtonState();
    updateReceiveButtonState();

    // Filter watchers (Stored)
    document.getElementById('storedSearch').addEventListener('keyup', filterStoredTable);
    document.getElementById('storedWattageFilter').addEventListener('change', filterStoredTable);

    // Filter watchers (Transit)
    document.getElementById('transitSearch').addEventListener('keyup', filterTransitTable);
    document.getElementById('transitWattageFilter').addEventListener('change', filterTransitTable);
});

/** Toggle all child checkboxes in a given table. */
function toggleAllCheckboxesInTable(tableId, isChecked) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const checkboxes = table.querySelectorAll('tbody input[type="checkbox"]');
    checkboxes.forEach(cb => { cb.checked = isChecked; });
}

/** MOVE button state */
function updateMoveButtonState() {
    const moveBtn = document.getElementById('movePalletsBtn');
    const table = document.getElementById('storedTable');
    if (!table) return;
    const checked = table.querySelectorAll('.pallet-checkbox-stored:checked').length;
    moveBtn.disabled = (checked === 0);
    if (checked === 0) closeMoveModal();
}

/** RECEIVE button state */
function updateReceiveButtonState() {
    const receiveBtn = document.getElementById('receivePalletsBtn');
    const table = document.getElementById('transitTable');
    if (!table) return;
    const checked = table.querySelectorAll('.transit-checkbox:checked').length;
    receiveBtn.disabled = (checked === 0);
    if (checked === 0) closeReceiveModal();
}

/** Filter stored table */
function filterStoredTable() {
    const textFilter = document.getElementById('storedSearch').value.toLowerCase();
    const wattageFilter = document.getElementById('storedWattageFilter').value;
    const rows = document.querySelectorAll('#storedTable tbody tr');

    rows.forEach(row => {
        let show = true;
        let rowText = '';
        // Build row text for searching
        for (let i = 1; i < row.cells.length; i++) {
            rowText += row.cells[i].textContent.toLowerCase() + ' ';
        }
        if (textFilter && !rowText.includes(textFilter)) show = false;
        let wattageText = row.cells[3]?.textContent.replace('W','').trim() || '';
        if (wattageFilter && wattageText !== wattageFilter) show = false;

        row.style.display = show ? '' : 'none';
    });
}

/** Filter transit table */
function filterTransitTable() {
    const textFilter = document.getElementById('transitSearch').value.toLowerCase();
    const wattageFilter = document.getElementById('transitWattageFilter').value;
    const rows = document.querySelectorAll('#transitTable tbody tr');

    rows.forEach(row => {
        let show = true;
        let rowText = '';
        for (let i = 1; i < row.cells.length; i++) {
            rowText += row.cells[i].textContent.toLowerCase() + ' ';
        }
        if (textFilter && !rowText.includes(textFilter)) show = false;

        let wattageText = row.cells[3]?.textContent.replace('W','').trim() || '';
        if (wattageFilter && wattageText !== wattageFilter) show = false;

        row.style.display = show ? '' : 'none';
    });
}

/** MOVE PALLETS MODAL */
const moveModal = document.getElementById('moveModal');
const closeModalBtn = moveModal.querySelector('.close-modal');
const moveFormContainer = document.getElementById('movePalletFormContainer');
const moveOriginalParent = moveFormContainer.parentNode;

document.getElementById('movePalletsBtn').addEventListener('click', openMoveModal);
closeModalBtn.addEventListener('click', closeMoveModal);
window.addEventListener('click', (e) => {
    if (e.target === moveModal) closeMoveModal();
});

function openMoveModal() {
    const moveBtn = document.getElementById('movePalletsBtn');
    if (moveBtn.disabled) return;
    if (closeModalBtn.nextSibling) {
        moveModal.querySelector('.modal-content').insertBefore(moveFormContainer, closeModalBtn.nextSibling);
    } else {
        moveModal.querySelector('.modal-content').appendChild(moveFormContainer);
    }
    moveFormContainer.style.display = 'block';
    moveModal.style.setProperty('display', 'block', 'important');
    toggleDestinationSelect(); // populate project/warehouse
}
function closeMoveModal() {
    if (moveModal.style.display === 'block') {
        moveOriginalParent.appendChild(moveFormContainer);
        moveFormContainer.style.display = 'none';
        moveModal.style.display = 'none';
    }
}

/** RECEIVE PALLETS MODAL */
const receiveModal = document.getElementById('receiveModal');
const closeReceiveBtn = receiveModal.querySelector('.close-receive-modal');
const receiveFormContainer = document.getElementById('receiveFormContainer');
const receiveOriginalParent = receiveFormContainer.parentNode;

document.getElementById('receivePalletsBtn').addEventListener('click', openReceiveModal);
closeReceiveBtn.addEventListener('click', closeReceiveModal);
window.addEventListener('click', (e) => {
    if (e.target === receiveModal) closeReceiveModal();
});

function openReceiveModal() {
    const btn = document.getElementById('receivePalletsBtn');
    if (btn.disabled) return;
    if (closeReceiveBtn.nextSibling) {
        receiveModal.querySelector('.modal-content').insertBefore(receiveFormContainer, closeReceiveBtn.nextSibling);
    } else {
        receiveModal.querySelector('.modal-content').appendChild(receiveFormContainer);
    }
    receiveFormContainer.style.display = 'block';
    receiveModal.style.setProperty('display', 'block', 'important');
}
function closeReceiveModal() {
    if (receiveModal.style.display === 'block') {
        receiveOriginalParent.appendChild(receiveFormContainer);
        receiveFormContainer.style.display = 'none';
        receiveModal.style.display = 'none';
    }
}

/** SUBMIT: Move Pallets */
document.getElementById('submitMoveBtn').addEventListener('click', () => {
    const mainForm = document.getElementById('palletInventoryForm');
    const destType = document.querySelector('input[name="destination_type"]:checked').value;
    const destId = document.getElementById('destination_id').value;

    setHidden(mainForm, 'destination_type', destType);
    setHidden(mainForm, 'destination_id', destId);
    setHidden(mainForm, 'bol_number', document.getElementById('bol_number').value);
    setHidden(mainForm, 'departure_date', document.getElementById('departure_date').value);
    setHidden(mainForm, 'est_arrival_date', document.getElementById('est_arrival_date').value);

    mainForm.submit();
});

/** SUBMIT: Receive Pallets */
document.getElementById('confirmReceiveBtn').addEventListener('click', () => {
    const mainForm = document.getElementById('receivePalletsForm');

    let bolHidden = mainForm.querySelector('input[name="receive_bol_hidden"]');
    if (!bolHidden) {
        bolHidden = document.createElement('input');
        bolHidden.type = 'hidden';
        bolHidden.name = 'receive_bol_hidden';
        mainForm.appendChild(bolHidden);
    }
    bolHidden.value = document.getElementById('receive_bol').value;

    let arrivalHidden = mainForm.querySelector('input[name="actual_arrival_date_hidden"]');
    if (!arrivalHidden) {
        arrivalHidden = document.createElement('input');
        arrivalHidden.type = 'hidden';
        arrivalHidden.name = 'actual_arrival_date_hidden';
        mainForm.appendChild(arrivalHidden);
    }
    arrivalHidden.value = document.getElementById('actual_arrival_date').value;

    mainForm.submit();
});

/** Toggle Destination (Project vs Warehouse) for move modal */
function toggleDestinationSelect() {
    const destType = document.querySelector('input[name="destination_type"]:checked').value;
    const lbl = document.getElementById('destinationLabel');
    const sel = document.getElementById('destination_id');
    sel.innerHTML = '';
    if (destType === 'project') {
        lbl.textContent = 'Project:';
        if (allProjectsData.length === 0) {
            sel.innerHTML = '<option value="">No projects found</option>';
            sel.disabled = true;
        } else {
            sel.disabled = false;
            sel.innerHTML = '<option value="">-- Select Project --</option>';
            allProjectsData.forEach(proj => {
                const opt = document.createElement('option');
                opt.value = proj.id;
                opt.textContent = proj.project_name;
                sel.appendChild(opt);
            });
        }
    } else {
        lbl.textContent = 'Destination Warehouse:';
        if (otherWarehousesData.length === 0) {
            sel.innerHTML = '<option value="">No other warehouses found</option>';
            sel.disabled = true;
        } else {
            sel.disabled = false;
            sel.innerHTML = '<option value="">-- Select Warehouse --</option>';
            otherWarehousesData.forEach(wh => {
                const opt = document.createElement('option');
                opt.value = wh.id;
                opt.textContent = wh.name;
                sel.appendChild(opt);
            });
        }
    }
}

/** Utility to set a hidden field in a form. */
function setHidden(form, fieldName, val) {
    let el = form.querySelector(`[name="${fieldName}"]`);
    if (!el) {
        el = document.createElement('input');
        el.type = 'hidden';
        el.name = fieldName;
        form.appendChild(el);
    }
    el.value = val;
}
</script>
</body>
</html>
