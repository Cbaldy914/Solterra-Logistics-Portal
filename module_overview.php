<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized");
    exit();
}
// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$role     = $_SESSION['role'];
$user_id  = $_SESSION['user_id'];
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;

if ($batch_id <= 0) {
    die("Invalid Batch ID provided.");
}

// Handle bulk pallet generation by modules per pallet
$successMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_pallets') {
    $itemId           = intval($_POST['item_id']);
    $modulesPerPallet = max(1, intval($_POST['modules_per_pallet']));
    // Get wattage and total modules for this item
    $stmtW = $conn->prepare("SELECT wattage, quantity FROM unassigned_module_items WHERE id = ? LIMIT 1");
    $stmtW->bind_param("i", $itemId);
    $stmtW->execute();
    $stmtW->bind_result($wattage, $totalModules);
    $stmtW->fetch();
    $stmtW->close();
    // Calculate number of pallets and distribution of modules
    $fullPallets  = intdiv($totalModules, $modulesPerPallet);
    $remainder    = $totalModules % $modulesPerPallet;
    $totalPallets = $fullPallets + ($remainder > 0 ? 1 : 0);
    // Insert full pallets
    for ($i = 0; $i < $fullPallets; $i++) {
        insertPallet($itemId, $wattage, $modulesPerPallet);
    }
    // Insert last pallet if there's a remainder
    if ($remainder > 0) {
        insertPallet($itemId, $wattage, $remainder);
    }
    $successMessage = "Created $totalPallets pallets (up to $modulesPerPallet modules each) for item ID $itemId.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ship_pallets') {
    $shipMessage = '';
    $createdDeliveryIds = [];
    $conn = getDBConnection();
    $conn->begin_transaction();
    try {
        // --- NEW: Fetch current batch's vendor_name and project_id directly --- 
        $current_batch_info_stmt = $conn->prepare("SELECT vendor_name, project_id FROM modules WHERE id = ? LIMIT 1");
        if (!$current_batch_info_stmt) {
            throw new Exception("Failed to prepare batch info query: " . $conn->error);
        }
        $current_batch_info_stmt->bind_param("i", $batch_id); // $batch_id is from $_GET
        $current_batch_info_stmt->execute();
        $current_batch_info_result = $current_batch_info_stmt->get_result();
        if ($current_batch_info_row = $current_batch_info_result->fetch_assoc()) {
            $current_batch_vendor_name = $current_batch_info_row['vendor_name'];
            $source_project_id_for_delivery = $current_batch_info_row['project_id']; // This can be NULL
        } else {
            $current_batch_info_stmt->close();
            throw new Exception("Module batch with ID {$batch_id} not found.");
        }
        $current_batch_info_stmt->close();
        // --- END NEW --- 

        $destinationType = $_POST['destination_type'] ?? 'project';
        $destinationId   = isset($_POST['destination_id']) ? intval($_POST['destination_id']) : 0;
        $bolNumber       = trim($_POST['bol_number'] ?? '');
        $departureDate   = $_POST['departure_date'] ?? null;
        $estArrivalDate  = $_POST['est_arrival_date'] ?? null;
        $palletIds       = $_POST['selected_pallets'] ?? [];
        $shipmentMode    = $_POST['shipment_mode'] ?? 'single';
        $palletsPerTruck = (isset($_POST['pallets_per_truck']) && is_numeric($_POST['pallets_per_truck']))
                           ? intval($_POST['pallets_per_truck'])
                           : 1;

        if (empty($palletIds)) {
            throw new Exception('No pallets selected to ship.');
        }
        if ($destinationId <= 0) {
            throw new Exception('No destination selected.');
        }
        if (empty($departureDate)) {
            throw new Exception('Departure date is required.');
        }
        if (empty($estArrivalDate)) {
            throw new Exception('Estimated arrival date is required.');
        }

        // Fetch supplier/vendor_name for this batch - NOW USING $current_batch_vendor_name
        $vendor_name = $current_batch_vendor_name ?? 'Unknown Vendor';
        // Get the project_id from the module batch itself - NOW USING $source_project_id_for_delivery
        // This was the main issue: $batch_data was not populated at this stage of POST handling.

        // Fetch details for selected pallets
        $placeholders = implode(',', array_fill(0, count($palletIds), '?'));
        $types        = str_repeat('i', count($palletIds));
        $stmtFetchPallets = $conn->prepare("SELECT id, wattage, quantity FROM inventory_pallets WHERE id IN ($placeholders)");
        if (!$stmtFetchPallets) throw new Exception("Failed to prepare pallet fetch: " . $conn->error);
        $stmtFetchPallets->bind_param($types, ...$palletIds);
        $stmtFetchPallets->execute();
        $resultPallets = $stmtFetchPallets->get_result();
        $allPallets = [];
        while ($pallet = $resultPallets->fetch_assoc()) {
            $allPallets[] = $pallet;
        }
        $stmtFetchPallets->close();

        // Split into groups if multi-shipment
        $palletGroups = [];
        if ($shipmentMode === 'multi' && $palletsPerTruck > 0) {
            for ($i = 0; $i < count($allPallets); $i += $palletsPerTruck) {
                $palletGroups[] = array_slice($allPallets, $i, $palletsPerTruck);
            }
        } else {
            $palletGroups[] = $allPallets;
        }

        // Prepare statements for delivery and pallet updates
        $sqlDelivery = "";
        $deliveryTypes = "";
        $deliveryParams = [];
        if ($destinationType === 'project') {
            $sqlDelivery = "INSERT INTO deliveries (project_id, supplier, wattage, quantity, bol_number, anticipated_delivery_date, left_warehouse_date, status_of_delivery) VALUES (?, ?, ?, ?, ?, ?, ?, 'In Transit to Project')";
            $deliveryTypes = "ississs";
        } else {
            $sqlDelivery = "INSERT INTO deliveries (project_id, warehouse_id, supplier, wattage, quantity, bol_number, left_warehouse_date, anticipated_delivery_date, status_of_delivery) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'In Transit to Warehouse')";
            $deliveryTypes = "iississs";
        }
        $stmtDelivery = $conn->prepare($sqlDelivery);
        if (!$stmtDelivery) throw new Exception("Failed to prepare delivery insert: " . $conn->error);

        $stmtLink = $conn->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
        if (!$stmtLink) throw new Exception("Failed to prepare pallet link insert: " . $conn->error);

        $sqlUp = "UPDATE inventory_pallets SET status = ?, current_project_id = ?, current_warehouse_id = ?, arrival_date = ? WHERE id = ?";
        $stmtUp = $conn->prepare($sqlUp);
        if (!$stmtUp) throw new Exception("Failed to prepare pallet update: " . $conn->error);

        foreach ($palletGroups as $group) {
            // Group by wattage for each delivery
            $groupByWattage = [];
            foreach ($group as $pallet) {
                $w = $pallet['wattage'];
                if (!isset($groupByWattage[$w])) $groupByWattage[$w] = [];
                $groupByWattage[$w][] = $pallet;
            }
            foreach ($groupByWattage as $wattage => $palletsForWatt) {
                $groupQty = array_sum(array_column($palletsForWatt, 'quantity'));
                if ($destinationType === 'project') {
                    $deliveryParams = [$destinationId, $vendor_name, $wattage, $groupQty, $bolNumber, $estArrivalDate, $departureDate];
                } else {
                    error_log("Attempting to insert delivery to warehouse. Project ID for delivery: " . print_r($source_project_id_for_delivery, true)); // DEBUG LINE - Uses the newly fetched project_id
                    $deliveryParams = [$source_project_id_for_delivery, $destinationId, $vendor_name, $wattage, $groupQty, $bolNumber, $departureDate, $estArrivalDate];
                }
                $stmtDelivery->bind_param($deliveryTypes, ...$deliveryParams);
                if (!$stmtDelivery->execute()) {
                    throw new Exception("Failed to insert delivery for {$wattage}W: " . $stmtDelivery->error);
                }
                $deliveryId = $conn->insert_id;
                $createdDeliveryIds[] = $deliveryId;
                foreach ($palletsForWatt as $pallet) {
                    $stmtLink->bind_param("ii", $deliveryId, $pallet['id']);
                    if (!$stmtLink->execute()) {
                        throw new Exception("Failed to link pallet ID {$pallet['id']} to delivery {$deliveryId}: " . $stmtLink->error);
                    }
                    // Update pallet status/location
                    $status = ($destinationType === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';
                    $projectId = ($destinationType === 'project') ? $destinationId : null;
                    $warehouseId = ($destinationType === 'warehouse') ? $destinationId : null;
                    $stmtUp->bind_param("siisi", $status, $projectId, $warehouseId, $estArrivalDate, $pallet['id']);
                    if (!$stmtUp->execute()) {
                        throw new Exception("Failed to update pallet ID {$pallet['id']}: " . $stmtUp->error);
                    }
                }
            }
        }
        $stmtDelivery->close();
        $stmtLink->close();
        $stmtUp->close();
        $conn->commit();
        $shipMessage = "Successfully created transfer delivery (IDs: " . implode(", ", $createdDeliveryIds) . ") for " . count($palletIds) . " pallets.";
    } catch (Exception $e) {
        $conn->rollback();
        $shipMessage = "Error creating transfer delivery: " . $e->getMessage();
    }
}

// Helper to insert a pallet row and assign its identifier
function insertPallet($itemId, $watt, $qty) {
    global $conn;

    // --- NEW: Fetch assigned project_id from the parent module batch --- 
    $assignedProjectId = null; // Default to NULL
    $stmtFetchProject = $conn->prepare("
        SELECT m.project_id 
        FROM modules m 
        JOIN unassigned_module_items umi ON m.id = umi.unassigned_module_id 
        WHERE umi.id = ? 
        LIMIT 1
    ");
    if ($stmtFetchProject) {
        $stmtFetchProject->bind_param("i", $itemId);
        if ($stmtFetchProject->execute()) {
            $stmtFetchProject->bind_result($fetchedProjectId);
            if ($stmtFetchProject->fetch()) {
                $assignedProjectId = $fetchedProjectId; // Can be NULL if the module batch wasn't assigned
            }
        } else {
            // Log error or handle appropriately if project fetch fails
            error_log("Error executing project fetch for item ID {$itemId}: " . $stmtFetchProject->error);
        }
        $stmtFetchProject->close();
    } else {
        error_log("Error preparing project fetch for item ID {$itemId}: " . $conn->error);
    }
    // --- END NEW --- 

    $stmtIns = $conn->prepare(
        "INSERT INTO inventory_pallets 
         (pallet_identifier, unassigned_module_item_id, assigned_project_id, wattage, quantity, status) 
         VALUES (?, ?, ?, ?, ?, 'At Manufacturer')"
    );
    if (!$stmtIns) {
        // Log error or handle appropriately
        error_log("Error preparing pallet insert for item ID {$itemId}: " . $conn->error);
        // Potentially throw an exception or return false to indicate failure
        return; // Exit function if prepare fails
    }

    $emptyId = ''; // Pallet identifier will be set after insert
    // Bind parameters including the fetched assigned project ID (type 'i' handles NULL correctly)
    $stmtIns->bind_param("siiid", $emptyId, $itemId, $assignedProjectId, $watt, $qty);
    
    if (!$stmtIns->execute()) {
        error_log("Error executing pallet insert for item ID {$itemId}: " . $stmtIns->error);
        $stmtIns->close();
        return; // Exit function if execute fails
    }
    
    $newId = $conn->insert_id;
    $identifier = 'P' . $newId;
    $stmtUpd = $conn->prepare("UPDATE inventory_pallets SET pallet_identifier = ? WHERE id = ?");
    if ($stmtUpd) {
        $stmtUpd->bind_param("si", $identifier, $newId);
        $stmtUpd->execute();
        $stmtUpd->close();
    } else {
         error_log("Error preparing pallet identifier update for ID {$newId}: " . $conn->error);
    }
    $stmtIns->close();
}

// --- Data Fetching --- 
$batch_data = null;
$batch_items = []; // Keep this to store raw items if needed elsewhere, but we'll process into wattage summary
$pallets = [];     // Keep this raw pallet data
$summary_stats = [ // Keep overall status counts
    'status_counts' => [],
];
$wattage_summary = []; // NEW: Array to hold summary data per wattage

$account_id_for_admin = null;
$errorMessage = '';

try {
    // Fetch main batch data and account name
    $stmtBatch = $conn->prepare("
        SELECT um.*, c.name as account_name, p.project_name
        FROM modules um 
        JOIN customer_accounts c ON um.account_id = c.id
        LEFT JOIN projects p ON um.project_id = p.id
        WHERE um.id = ?
    ");
    if (!$stmtBatch) throw new Exception("Prepare batch fetch failed: " . $conn->error);
    $stmtBatch->bind_param("i", $batch_id);
    $stmtBatch->execute();
    $resultBatch = $stmtBatch->get_result();
    if ($resultBatch->num_rows === 0) {
        throw new Exception("Module batch not found.");
    }
    $batch_data = $resultBatch->fetch_assoc();
    $stmtBatch->close();

    // Access Control for Admin role
    if ($role === 'admin') {
        $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
        $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
        if ($stmtAdminAcc) {
            $stmtAdminAcc->bind_param("i", $user_id);
            $stmtAdminAcc->execute();
            $stmtAdminAcc->bind_result($account_id_for_admin);
            $stmtAdminAcc->fetch();
            $stmtAdminAcc->close();
        }
        if (!$account_id_for_admin || $batch_data['account_id'] != $account_id_for_admin) {
            throw new Exception("Access Denied: You do not have permission to view this batch.");
        }
    }

    // Fetch batch items and aggregate ordered quantity by wattage
    $stmtItems = $conn->prepare("SELECT id, wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ? ORDER BY wattage ASC");
    if (!$stmtItems) throw new Exception("Prepare items fetch failed: " . $conn->error);
    $stmtItems->bind_param("i", $batch_id);
    $stmtItems->execute();
    $resultItems = $stmtItems->get_result();
    $item_ids = []; // Keep track of item IDs to fetch pallets
    while ($item = $resultItems->fetch_assoc()) {
        $item_ids[] = $item['id'];
        $wattage = $item['wattage'];
        if (!isset($wattage_summary[$wattage])) {
            $wattage_summary[$wattage] = [
                'item_id' => $item['id'], // Assumes one item row per wattage
                'ordered_quantity' => 0,
                'palletized_quantity' => 0,
                'remaining_quantity' => 0
            ];
        }
        $wattage_summary[$wattage]['ordered_quantity'] += $item['quantity'];
        $batch_items[] = $item; // Still store raw items if needed later
    }
    $stmtItems->close();

    // Fetch associated pallets and aggregate palletized quantity by wattage
    if (!empty($item_ids)) {
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $types = str_repeat('i', count($item_ids));
        
        $sqlPallets = "SELECT ip.id, ip.pallet_identifier, ip.unassigned_module_item_id, ip.wattage, ip.quantity, ip.status, ip.arrival_date, ip.current_warehouse_id, ip.current_project_id, w.name as warehouse_name, p.project_name 
                       FROM inventory_pallets ip
                       LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
                       LEFT JOIN projects p ON ip.current_project_id = p.id
                       WHERE ip.unassigned_module_item_id IN ($placeholders)
                       ORDER BY ip.id ASC";
        $stmtPallets = $conn->prepare($sqlPallets);
        if (!$stmtPallets) throw new Exception("Prepare pallets fetch failed: " . $conn->error);
        $stmtPallets->bind_param($types, ...$item_ids);
        $stmtPallets->execute();
        $resultPallets = $stmtPallets->get_result();
        while ($pallet = $resultPallets->fetch_assoc()) {
            $wattage = $pallet['wattage'];
            if (isset($wattage_summary[$wattage])) {
                $wattage_summary[$wattage]['palletized_quantity'] += $pallet['quantity'];
            }
            // Determine display location
            if ($pallet['status'] === 'In Warehouse' && $pallet['current_warehouse_id']) {
                $pallet['display_location'] = 'Warehouse: ' . htmlspecialchars($pallet['warehouse_name'] ?? 'Unknown');
            } elseif ($pallet['status'] === 'Delivered to Project' && $pallet['current_project_id']) {
                $pallet['display_location'] = 'Project: ' . htmlspecialchars($pallet['project_name'] ?? 'Unknown');
            } elseif ($pallet['status'] === 'Allocated to Project' && $pallet['current_project_id']){
                $pallet['display_location'] = 'Project: ' . htmlspecialchars($pallet['project_name'] ?? 'Unknown') . ' (Allocated)';
            } else {
                $pallet['display_location'] = $pallet['status'];
            }
            $pallets[] = $pallet; // Store raw pallet data
            // Update overall status counts
            $status = $pallet['status'];
            $summary_stats['status_counts'][$status] = ($summary_stats['status_counts'][$status] ?? 0) + 1;
        }
        $stmtPallets->close();
    }

    // Calculate remaining quantity for each wattage
    foreach ($wattage_summary as $wattage => &$data) { // Use reference to modify directly
        $data['remaining_quantity'] = $data['ordered_quantity'] - $data['palletized_quantity'];
    }
    unset($data); // Unset reference after loop

    // Fetch Projects for the account associated with this batch
    $account_projects = [];
    if (isset($batch_data['account_id'])) {
        $stmtP = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
        if ($stmtP) {
            $stmtP->bind_param("i", $batch_data['account_id']);
            $stmtP->execute();
            $resultP = $stmtP->get_result();
            while ($proj = $resultP->fetch_assoc()) {
                $account_projects[] = $proj;
            }
            $stmtP->close();
        }
    }

    // Fetch Warehouses (assuming all are potential destinations for now)
    $all_warehouses = [];
    $stmtW = $conn->prepare("SELECT id, name FROM warehouses ORDER BY name ASC");
    if ($stmtW) {
        $stmtW->execute();
        $resultW = $stmtW->get_result();
        while ($wh = $resultW->fetch_assoc()) {
            $all_warehouses[] = $wh;
        }
        $stmtW->close();
    }

} catch (Exception $e) {
    $errorMessage = "Error loading data: " . $e->getMessage();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Overview</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .overview-header {
            position: relative; /* Needed for absolute positioning of child */
            background-color: #f9f9f9;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
        }
        .overview-header .edit-button {
            position: absolute;
            top: 15px;
            right: 15px;
            background-color: #488C9A;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            padding: 5px 10px;
            border: none;
            cursor: pointer;
        }
        .overview-header h1 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1.6em;
            color: #293E4C;
        }
        .overview-header p, .summary-section p {
            margin: 5px 0;
            line-height: 1.5;
        }
        .section-title {
            font-size: 1.3em;
            margin-bottom: 15px;
            color: #488C9A;
            border-bottom: 2px solid #488C9A;
            padding-bottom: 5px;
        }
        .action-buttons {
            margin-bottom: 20px;
            text-align: right;
        }
        .action-buttons .action-button {
             margin-left: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th {
            background-color: #e9ecef;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .status-counts li {
            margin-bottom: 5px;
        }
        .summary-section .wattage-blocks-container {
            display: flex;
            flex-wrap: wrap; 
            gap: 20px; 
            margin-top: 20px;
        }
        .wattage-summary-block {
            border: 1px solid #ccc;
            padding: 15px;
            border-radius: 5px;
            background-color: #fff;
            flex: 1; 
            min-width: 220px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .wattage-summary-block h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #293E4C;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        .wattage-summary-block p {
            margin: 4px 0;
            font-size: 0.9em;
        }
        .wattage-summary-block form {
            margin-top: 15px;
        }
        .wattage-summary-block label {
            display: block;
            font-size: 0.85em;
            margin-bottom: 3px;
        }
        .wattage-summary-block input[type="number"] {
            width: 80px;
            padding: 5px;
            margin-right: 10px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }
        .wattage-summary-block button {
            padding: 6px 12px;
            font-size: 0.9em;
            background-color: #488C9A;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .wattage-summary-block button:hover {
            background-color: #3A6E7F;
        }
        .pallet-table-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        /* Modal Styles for Transfer Delivery */
        .modal {
            display: none; 
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
            margin: 5% auto;
            padding: 30px 30px 20px 30px;
            border: 1px solid #888;
            width: 100%;
            max-width: 500px;
            border-radius: 8px;
            position: relative;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .close-modal-btn {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-modal-btn:hover,
        .close-modal-btn:focus {
            color: black;
            text-decoration: none;
        }
        .shipment-details-modal-content h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #293E4C;
            font-size: 1.3em;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 18px;
        }
        .form-row > div {
            flex: 1;
            min-width: 150px;
        }
        .shipment-details-modal-content label {
            font-weight: 500;
            margin-bottom: 6px;
            display: block;
        }
        .shipment-details-modal-content input[type="text"],
        .shipment-details-modal-content input[type="date"],
        .shipment-details-modal-content select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-bottom: 8px;
            font-size: 1em;
            box-sizing: border-box;
        }
        .radio-label {
            display: inline-block;
            margin-right: 20px;
            font-weight: normal;
        }
        .radio-label input[type=radio] {
            margin-right: 5px;
            vertical-align: middle;
        }
        #destinationSelectContainer {
            margin-top: 10px;
        }
        .tabs {
            display: flex;
            justify-content: center;
            gap: 1px;
            margin-bottom: 20px;
        }
        .tabs button {
            flex: 1;
            min-width: 120px;
            max-width: 200px;
            background: #293E4C;
            color: #fff;
            padding: 10px;
            cursor: pointer;
            font-weight: 600;
            border: none;
            font-size: 1em;
            transition: background 0.2s, color 0.2s;
        }
        .tabs button.active {
            background: #f39c12;
            color: #000;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php elseif ($batch_data): ?>
        
        <div class="overview-header">
            <h1>Module Batch: <?php echo htmlspecialchars($batch_data['vendor_name']); ?></h1>
            <p><strong>Account:</strong> <?php echo htmlspecialchars($batch_data['account_name']); ?></p>
            <p><strong>Initial Location:</strong> <?php echo htmlspecialchars($batch_data['initial_location']); ?></p>
            <p><strong>Assigned Project:</strong> 
                <?php 
                if (!empty($batch_data['project_id']) && !empty($batch_data['project_name'])) {
                    echo htmlspecialchars($batch_data['project_name']); 
                } else {
                    echo "<em>Unassigned</em>";
                }
                ?>
            </p>
            <p><strong>Batch ID:</strong> <?php echo $batch_data['id']; ?></p>
            <p><strong>Date Added:</strong> <?php echo date('Y-m-d H:i', strtotime($batch_data['created_at'])); ?></p>
            <button class="edit-button" onclick="window.location.href='edit_module.php?batch_id=<?php echo $batch_id; ?>'">Edit Batch Details</button>
        </div>

        <div class="summary-section">
            <h2 class="section-title">Summary & Pallet Generation</h2>
            
            <!-- Container for wattage blocks -->
            <div class="wattage-blocks-container">
                <?php if (!empty($wattage_summary)): ?>
                    <?php foreach ($wattage_summary as $wattage => $data): ?>
                        <div class="wattage-summary-block">
                            <h4><?php echo htmlspecialchars($wattage); ?>W Modules</h4>
                            <p><strong>Ordered:</strong> <?php echo number_format($data['ordered_quantity']); ?></p>
                            <p><strong>On Pallets:</strong> <?php echo number_format($data['palletized_quantity']); ?></p>
                            <p><strong>Remaining:</strong> <?php echo number_format($data['remaining_quantity']); ?></p>
                            
                            <?php if ($data['remaining_quantity'] > 0): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="generate_pallets">
                                    <input type="hidden" name="item_id" value="<?php echo $data['item_id']; ?>">
                                    <div>
                                        <label for="modules_per_pallet_<?php echo $wattage; ?>">Modules per Pallet:</label>
                                        <input type="number" name="modules_per_pallet" id="modules_per_pallet_<?php echo $wattage; ?>" min="1" value="1" required>
                                        <button type="submit">Generate</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <p style="color: green; margin-top: 15px;">All modules palletized.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No module items found for this batch.</p>
                <?php endif; ?>
            </div>

            <!-- Keep Pallet Status Breakdown -->
            <h3 style="margin-top: 30px;">Overall Pallet Status Breakdown:</h3>
            <?php if (!empty($summary_stats['status_counts'])): ?>
                <ul class="status-counts">
                    <?php foreach ($summary_stats['status_counts'] as $status => $count): ?>
                        <li><?php echo htmlspecialchars($status); ?>: <?php echo $count; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No pallets have been created/recorded for this batch yet.</p>
            <?php endif; ?>

            <?php if (!empty($successMessage)): ?>
                <div class="success-message" style="margin-top: 15px;"><?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
        </div>

        <!-- ====== SHIP PALLETS FORM ====== -->
        <form method="POST" id="shipPalletsForm">
            <input type="hidden" name="action" value="ship_pallets">
            
            <div class="pallets-section">
                <h2 class="section-title">Select Inventory Pallets to Include in Shipment</h2>
                <div class="pallet-table-actions" style="justify-content: flex-start; gap: 20px; align-items: center;">
                    <label>Filter Table:
                        <input type="text" id="palletSearch" placeholder="Filter by ID, Identifier, Wattage..." onkeyup="filterPallets()">
                    </label>
                    <label for="wattageFilter">Wattage:</label>
                    <select id="wattageFilter" onchange="filterPallets()">
                        <option value="">All</option>
                        <?php
                        $wattages = array_unique(array_map(function($p) { return $p['wattage']; }, $pallets));
                        sort($wattages);
                        foreach ($wattages as $w) {
                            echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>';
                        }
                        ?>
                    </select>
                    <span id="selectedCount" style="margin-left: 20px; font-weight: bold; color: #488C9A;">0 pallets selected</span>
                    <button type="button" id="openShipModalBtn" class="action-button" style="padding: 10px 20px; font-size: 1em; margin-left:auto;" disabled>
                        Create Delivery for Selected Pallets
                    </button>
                </div>
                <?php if (!empty($pallets)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllPallets" onclick="toggleAllPalletCheckboxes(this.checked)"></th>
                                <th>Identifier</th>
                                <th>Wattage</th>
                                <th>Quantity</th>
                                <th>Status</th>
                                <th>Current Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pallets as $pallet): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_pallets[]" value="<?php echo $pallet['id']; ?>" class="pallet-checkbox"></td>
                                    <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                    <td><?php echo $pallet['wattage']; ?>W</td>
                                    <td><?php echo number_format($pallet['quantity']); ?></td>
                                    <td><?php echo htmlspecialchars($pallet['status']); ?></td>
                                    <td><?php echo htmlspecialchars($pallet['display_location']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No pallets created or recorded for this batch yet.</p>
                <?php endif; ?>
            </div>
        </form>

    <?php else: ?>
         <p>Batch data could not be loaded.</p>
         <a href="modules.php">Back to Modules List</a>
    <?php endif; ?>
</main>

<!-- Modal for Shipment Details -->
<div id="shipModal" class="modal">
    <div class="modal-content">
        <span class="close-modal-btn">&times;</span>
        <div class="shipment-details-modal-content">
            <h2 class="section-title" style="margin-top:0; text-align:center;">Create Delivery</h2>
            <div class="tabs">
                <button type="button" class="modal-tab active" id="singleTabBtn">Single Shipment</button>
                <button type="button" class="modal-tab" id="multiTabBtn">Multiple Shipments</button>
            </div>
            <!-- SINGLE SHIPMENT SECTION -->
            <div id="singleShipmentSection">
                <form id="singleShipmentForm" onsubmit="return false;">
                    <label for="bol_number">BOL Number (Optional):</label>
                    <input type="text" id="bol_number" name="bol_number">
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
                    <label style="margin-bottom: 10px; display:block;">Destination:</label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type" value="project" checked onchange="toggleDestinationSelectSingle()"> Project
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type" value="warehouse" onchange="toggleDestinationSelectSingle()"> Warehouse
                    </label>
                    <div id="destinationSelectContainer">
                        <label for="destination_id" id="destinationLabel">Project:</label>
                        <select name="destination_id" id="destination_id" required>
                            <!-- Filled by JS -->
                        </select>
                    </div>
                    <button type="button" id="confirmShipmentBtn" class="action-button" style="margin-top:15px;">
                        Create Delivery
                    </button>
                </form>
            </div>
            <!-- MULTIPLE SHIPMENTS SECTION -->
            <div id="multiShipmentSection" style="display:none;">
                <form id="multiShipmentForm" onsubmit="return false;">
                    <label for="palletsPerTruck">Pallets per Truck:</label>
                    <input type="number" id="palletsPerTruck" min="1" value="1" style="width:100px;">
                    <div id="multiShipSummary" style="margin-top:10px; color:#488C9A;"></div>
                    <label for="bol_number_multi">BOL Number (Optional):</label>
                    <input type="text" id="bol_number_multi" name="bol_number_multi">
                    <div class="form-row">
                        <div>
                            <label for="departure_date_multi">Departure Date:</label>
                            <input type="date" id="departure_date_multi" name="departure_date_multi" required>
                        </div>
                        <div>
                            <label for="est_arrival_date_multi">Est. Arrival Date:</label>
                            <input type="date" id="est_arrival_date_multi" name="est_arrival_date_multi" required>
                        </div>
                    </div>
                    <label style="margin-bottom: 10px; display:block;">Destination:</label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type_multi" value="project" checked onchange="toggleDestinationSelectMulti()"> Project
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type_multi" value="warehouse" onchange="toggleDestinationSelectMulti()"> Warehouse
                    </label>
                    <div id="destinationSelectContainerMulti">
                        <label for="destination_id_multi" id="destinationLabelMulti">Project:</label>
                        <select name="destination_id_multi" id="destination_id_multi" required>
                            <!-- Filled by JS -->
                        </select>
                    </div>
                    <button type="button" id="confirmMultiShipmentBtn" class="action-button" style="margin-top:15px;">
                        Create Deliveries
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Embed PHP data as JS variables for populating dropdowns -->
<script>
    const projectsData = <?php echo json_encode($account_projects); ?>;
    const warehousesData = <?php echo json_encode($all_warehouses); ?>;
</script>

<script>
// ----------------- PALLET TABLE CHECKBOXES -----------------
function toggleAllPalletCheckboxes(isChecked) {
    document.querySelectorAll('.pallets-section table tbody tr').forEach(function(row) {
        if (row.style.display !== 'none') {
            const checkbox = row.querySelector('.pallet-checkbox');
            if (checkbox) checkbox.checked = isChecked;
        }
    });
    updateOpenShipModalButtonState();
    updateSelectedCount();
}

function updateOpenShipModalButtonState() {
    const openBtn = document.getElementById('openShipModalBtn');
    const checked = document.querySelectorAll('.pallet-checkbox:checked').length;
    if (openBtn) {
        openBtn.disabled = (checked === 0);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAllPallets');
    const palletCheckboxes = document.querySelectorAll('.pallet-checkbox');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            toggleAllPalletCheckboxes(this.checked);
        });
    }
    palletCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            updateOpenShipModalButtonState();
            updateSelectedCount();
        });
    });
    // Initial state
    updateOpenShipModalButtonState();
    updateSelectedCount();
});

// ----------------- PALLET FILTER -----------------
function filterPallets() {
    let filter = document.getElementById('palletSearch').value.toLowerCase();
    let wattageFilter = document.getElementById('wattageFilter').value;
    let rows = document.querySelectorAll('.pallets-section table tbody tr');

    rows.forEach(function(row) {
        let show = true;
        // Check text content
        let textContent = '';
        for (let i = 1; i < row.cells.length; i++) {
            textContent += row.cells[i].textContent.toLowerCase() + ' ';
        }
        if (filter && !textContent.includes(filter)) {
            show = false;
        }
        // Wattage filter
        if (wattageFilter && row.cells[2].textContent.replace('W','').trim() !== wattageFilter) {
            show = false;
        }
        row.style.display = show ? '' : 'none';
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    let count = document.querySelectorAll('.pallet-checkbox:checked').length;
    document.getElementById('selectedCount').textContent =
        count + ' pallet' + (count === 1 ? '' : 's') + ' selected';
}

// ----------------- MODAL LOGIC -----------------
const shipModal = document.getElementById('shipModal');
const openShipModalBtn = document.getElementById('openShipModalBtn');
const closeShipModalBtn = shipModal.querySelector('.close-modal-btn');

function openShipModal() {
    shipModal.style.display = 'block';
}
function closeShipModal() {
    shipModal.style.display = 'none';
}

if (openShipModalBtn) {
    openShipModalBtn.addEventListener('click', openShipModal);
}
if (closeShipModalBtn) {
    closeShipModalBtn.addEventListener('click', closeShipModal);
}
window.addEventListener('click', function(e) {
    if (e.target === shipModal) {
        closeShipModal();
    }
});

// ----------------- TAB SWITCHING (SINGLE vs MULTI) -----------------
const singleTabBtn = document.getElementById('singleTabBtn');
const multiTabBtn = document.getElementById('multiTabBtn');
const singleSection = document.getElementById('singleShipmentSection');
const multiSection = document.getElementById('multiShipmentSection');

if (singleTabBtn && multiTabBtn && singleSection && multiSection) {
    singleTabBtn.addEventListener('click', () => {
        singleTabBtn.classList.add('active');
        multiTabBtn.classList.remove('active');
        singleSection.style.display = '';
        multiSection.style.display = 'none';
        singleTabBtn.style.background = '#f39c12';
        singleTabBtn.style.color = '#000';
        multiTabBtn.style.background = '#293E4C';
        multiTabBtn.style.color = '#fff';
    });
    multiTabBtn.addEventListener('click', () => {
        singleTabBtn.classList.remove('active');
        multiTabBtn.classList.add('active');
        singleSection.style.display = 'none';
        multiSection.style.display = '';
        multiTabBtn.style.background = '#f39c12';
        multiTabBtn.style.color = '#000';
        singleTabBtn.style.background = '#293E4C';
        singleTabBtn.style.color = '#fff';
    });
    // Init default tab look
    singleTabBtn.style.background = '#f39c12';
    singleTabBtn.style.color = '#000';
}

// ----------------- TOGGLE DESTINATION (SINGLE) -----------------
function toggleDestinationSelectSingle() {
    const assignType = document.querySelector('input[name="destination_type"]:checked').value;
    const targetLabel = document.getElementById('destinationLabel');
    const targetSelect = document.getElementById('destination_id');
    const data = (assignType === 'project') ? projectsData : warehousesData;
    const placeholder = (assignType === 'project') ? '-- Select Project --' : '-- Select Warehouse --';
    const nameField = (assignType === 'project') ? 'project_name' : 'name';

    targetLabel.textContent = (assignType === 'project') ? 'Project:' : 'Warehouse:';
    targetSelect.innerHTML = '';

    if (!data || data.length === 0) {
        targetSelect.innerHTML = `<option value="">No ${assignType === 'project' ? 'projects' : 'warehouses'} found</option>`;
        targetSelect.disabled = true;
    } else {
        targetSelect.disabled = false;
        targetSelect.innerHTML = `<option value="">${placeholder}</option>`;
        data.forEach(function(item) {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item[nameField];
            targetSelect.appendChild(opt);
        });
    }
}

// ----------------- TOGGLE DESTINATION (MULTI) -----------------
function toggleDestinationSelectMulti() {
    const assignType = document.querySelector('input[name="destination_type_multi"]:checked').value;
    const targetLabel = document.getElementById('destinationLabelMulti');
    const targetSelect = document.getElementById('destination_id_multi');
    const data = (assignType === 'project') ? projectsData : warehousesData;
    const placeholder = (assignType === 'project') ? '-- Select Project --' : '-- Select Warehouse --';
    const nameField = (assignType === 'project') ? 'project_name' : 'name';

    targetLabel.textContent = (assignType === 'project') ? 'Project:' : 'Warehouse:';
    targetSelect.innerHTML = '';

    if (!data || data.length === 0) {
        targetSelect.innerHTML = `<option value="">No ${assignType === 'project' ? 'projects' : 'warehouses'} found</option>`;
        targetSelect.disabled = true;
    } else {
        targetSelect.disabled = false;
        targetSelect.innerHTML = `<option value="">${placeholder}</option>`;
        data.forEach(function(item) {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item[nameField];
            targetSelect.appendChild(opt);
        });
    }
}

// ----------------- CONFIRM BUTTONS (SINGLE & MULTI) -----------------
const confirmShipmentBtn = document.getElementById('confirmShipmentBtn');
const confirmMultiShipmentBtn = document.getElementById('confirmMultiShipmentBtn');

if (confirmShipmentBtn) {
    confirmShipmentBtn.addEventListener('click', function() {
        const mainForm = document.getElementById('shipPalletsForm');
        if (!mainForm) return;

        // Single shipment mode
        let shipmentModeInput = mainForm.querySelector('input[name="shipment_mode"]');
        if (!shipmentModeInput) {
            shipmentModeInput = document.createElement('input');
            shipmentModeInput.type = 'hidden';
            shipmentModeInput.name = 'shipment_mode';
            mainForm.appendChild(shipmentModeInput);
        }
        shipmentModeInput.value = 'single';

        // Pallets per truck (not used in single, so set blank or 1)
        let pTruckInput = mainForm.querySelector('input[name="pallets_per_truck"]');
        if (!pTruckInput) {
            pTruckInput = document.createElement('input');
            pTruckInput.type = 'hidden';
            pTruckInput.name = 'pallets_per_truck';
            mainForm.appendChild(pTruckInput);
        }
        pTruckInput.value = '1';

        // Get single form values
        const assignType = document.querySelector('input[name="destination_type"]:checked').value;
        const targetId = document.getElementById('destination_id').value;
        const bol = document.getElementById('bol_number').value;
        const departure = document.getElementById('departure_date').value;
        const arrival = document.getElementById('est_arrival_date').value;

        if (!targetId) {
            alert('Please select a destination (Project/Warehouse).');
            return;
        }

        // Populate hidden inputs
        setOrCreateHidden(mainForm, 'destination_type', assignType);
        setOrCreateHidden(mainForm, 'destination_id', targetId);
        setOrCreateHidden(mainForm, 'bol_number', bol);
        setOrCreateHidden(mainForm, 'departure_date', departure);
        setOrCreateHidden(mainForm, 'est_arrival_date', arrival);

        mainForm.submit();
    });
}

// *** NEW: Handle Multi‐shipment confirm ***
if (confirmMultiShipmentBtn) {
    confirmMultiShipmentBtn.addEventListener('click', function() {
        const mainForm = document.getElementById('shipPalletsForm');
        if (!mainForm) return;

        // Multi shipment mode
        let shipmentModeInput = mainForm.querySelector('input[name="shipment_mode"]');
        if (!shipmentModeInput) {
            shipmentModeInput = document.createElement('input');
            shipmentModeInput.type = 'hidden';
            shipmentModeInput.name = 'shipment_mode';
            mainForm.appendChild(shipmentModeInput);
        }
        shipmentModeInput.value = 'multi';

        // Pallets per truck
        let perTruckInput = mainForm.querySelector('input[name="pallets_per_truck"]');
        if (!perTruckInput) {
            perTruckInput = document.createElement('input');
            perTruckInput.type = 'hidden';
            perTruckInput.name = 'pallets_per_truck';
            mainForm.appendChild(perTruckInput);
        }
        perTruckInput.value = document.getElementById('palletsPerTruck').value || '1';

        // Get multi form values
        const assignType = document.querySelector('input[name="destination_type_multi"]:checked').value;
        const targetId = document.getElementById('destination_id_multi').value;
        const bol = document.getElementById('bol_number_multi').value;
        const departure = document.getElementById('departure_date_multi').value;
        const arrival = document.getElementById('est_arrival_date_multi').value;

        if (!targetId) {
            alert('Please select a destination (Project/Warehouse).');
            return;
        }

        // Populate hidden inputs
        setOrCreateHidden(mainForm, 'destination_type', assignType);
        setOrCreateHidden(mainForm, 'destination_id', targetId);
        setOrCreateHidden(mainForm, 'bol_number', bol);
        setOrCreateHidden(mainForm, 'departure_date', departure);
        setOrCreateHidden(mainForm, 'est_arrival_date', arrival);

        mainForm.submit();
    });
}

function setOrCreateHidden(form, fieldName, fieldValue) {
    let el = form.querySelector(`input[name="${fieldName}"]`);
    if (!el) {
        el = document.createElement('input');
        el.type = 'hidden';
        el.name = fieldName;
        form.appendChild(el);
    }
    el.value = fieldValue;
}

// ----------------- MULTI-SHIPMENT SUMMARY -----------------
const palletsPerTruckInput = document.getElementById('palletsPerTruck');
const multiShipSummary = document.getElementById('multiShipSummary');

function updateMultiShipSummary() {
    const selected = document.querySelectorAll('.pallet-checkbox:checked').length;
    const perTruck = parseInt(palletsPerTruckInput.value, 10) || 1;
    const numDeliveries = Math.ceil(selected / perTruck);
    multiShipSummary.textContent = selected > 0
        ? (numDeliveries + ' deliveries will be created (' + perTruck + ' pallets per truck)')
        : '';
}

if (palletsPerTruckInput && multiShipSummary) {
    palletsPerTruckInput.addEventListener('input', updateMultiShipSummary);
    document.querySelectorAll('.pallet-checkbox').forEach(function(cb) {
        cb.addEventListener('change', updateMultiShipSummary);
    });
    updateMultiShipSummary();
}

// ----------------- INITIALIZE DEFAULT DROPDOWNS -----------------
document.addEventListener('DOMContentLoaded', () => {
    // Default load the single shipment dropdown with "project" pre‐selected
    toggleDestinationSelectSingle();
    // Default load the multi shipment dropdown with "project" pre‐selected
    toggleDestinationSelectMulti();
});
</script>
</body>
</html>
