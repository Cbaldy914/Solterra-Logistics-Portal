<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
// $conn = getDBConnection(); // Connection will be established before CSV or main data fetch

$pallets = [];
$projects = [];
$errorMessage = '';
$successMessage = $_SESSION['manage_pallets_message'] ?? ''; // Check for messages from edit_pallet redirect
$warningDetails = $_SESSION['manage_pallets_warning'] ?? null; // Check for warning message
if (isset($_SESSION['manage_pallets_message'])) unset($_SESSION['manage_pallets_message']);
if (isset($_SESSION['manage_pallets_warning'])) unset($_SESSION['manage_pallets_warning']);

// --- Handle CSV Upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileSize = $_FILES['csv_file']['size'];
        $fileType = $_FILES['csv_file']['type'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));

        $allowedfileExtensions = array('csv');
        if (in_array($fileExtension, $allowedfileExtensions)) {
            $csvFile = fopen($fileTmpPath, 'r');
            if ($csvFile === false) {
                $errorMessage = "Error: Could not open the CSV file.";
            } else {
                fgetcsv($csvFile); // Skip header row

                $conn_csv = getDBConnection();
                if (!$conn_csv) {
                    $errorMessage = "Error: Could not connect to the database for CSV processing.";
                } else {
                    $conn_csv->begin_transaction();
                    $updated_count = 0;
                    $skipped_count = 0;
                    $error_rows = [];
                    $wattage_changed_flag = false;

                    // Prepare statement for updating pallets by id
                    // Fields updatable via CSV: pallet_identifier, wattage, flash_test_data
                    $stmt_update = $conn_csv->prepare("UPDATE inventory_pallets SET pallet_identifier = ?, wattage = ?, flash_test_data = ? WHERE id = ?");

                    if (!$stmt_update) {
                        $errorMessage = "Error preparing update statement: " . $conn_csv->error;
                    } else {
                        $rowNumber = 1;
                        while (($row_data = fgetcsv($csvFile)) !== FALSE) {
                            $rowNumber++;
                            if (empty(array_filter($row_data))) {
                                continue;
                            }
                            // Expected columns: id, pallet_identifier, wattage, (quantity - informational), (status - informational), (damaged_quantity - informational), flash_test_data
                            // Actual columns used for update: id, pallet_identifier, wattage, flash_test_data
                            if (count($row_data) >= 7) { // Ensure enough columns for core + informational
                                $id              = trim($row_data[0]);
                                $identifier      = trim($row_data[1]);
                                $wattage_csv     = trim($row_data[2]);
                                // quantity (index 3) - informational
                                // status (index 4) - informational
                                // damaged_quantity (index 5) - informational (if we add it later)
                                $flash_data_ref  = trim($row_data[6]); // Assuming flash_test_data is the 7th column (index 6)

                                if (empty($id) || !is_numeric($id)) {
                                    $error_rows[] = "Row {$rowNumber}: id is missing or invalid.";
                                    $skipped_count++;
                                    continue;
                                }
                                if (empty($identifier)) {
                                    $error_rows[] = "Row {$rowNumber}: pallet_identifier for id '{$id}' is missing.";
                                    $skipped_count++;
                                    continue;
                                }
                                if (empty($wattage_csv)) {
                                    $error_rows[] = "Row {$rowNumber}: Wattage for id '{$id}' cannot be empty.";
                                    $skipped_count++;
                                    continue;
                                }

                                // Check if wattage is being changed
                                $stmt_check_wattage = $conn_csv->prepare("SELECT wattage FROM inventory_pallets WHERE id = ?");
                                $stmt_check_wattage->bind_param("i", $id);
                                $stmt_check_wattage->execute();
                                $stmt_check_wattage->bind_result($current_wattage_db);
                                if ($stmt_check_wattage->fetch()) {
                                    if ($current_wattage_db != $wattage_csv) {
                                        $wattage_changed_flag = true;
                                    }
                                }
                                $stmt_check_wattage->close();

                                $stmt_update->bind_param("sssi", $identifier, $wattage_csv, $flash_data_ref, $id);
                                if ($stmt_update->execute()) {
                                    if ($stmt_update->affected_rows > 0) {
                                        $updated_count++;
                                    } else {
                                        $error_rows[] = "Row {$rowNumber}: Pallet with id '{$id}' not found or data unchanged.";
                                        $skipped_count++;
                                    }
                                } else {
                                    $error_rows[] = "Row {$rowNumber}: DB error updating pallet with id '{$id}': " . $stmt_update->error;
                                    $skipped_count++;
                                }
                            } else {
                                $error_rows[] = "Row {$rowNumber}: Insufficient columns. Expected at least 7 (id, pallet_identifier, wattage, quantity, status, damaged_quantity, flash_test_data). Row skipped.";
                                $skipped_count++;
                            }
                        }
                        $stmt_update->close();
                    }
                    fclose($csvFile);

                    if (empty($error_rows) || $updated_count > 0) {
                        $conn_csv->commit();
                        $successMessage = "CSV Processed. Successfully updated {$updated_count} pallets.";
                        if ($wattage_changed_flag) {
                            $successMessage .= " <br><b>Warning:</b> Wattage was changed for one or more pallets. Please review the original module batch details in 'Manage Modules' to ensure consistency.";
                        }
                        if ($skipped_count > 0) {
                            $errorMessage .= " Skipped {$skipped_count} rows due to errors or missing ids. Details: " . implode("; ", $error_rows);
                        }
                    } else {
                        $conn_csv->rollback();
                        $errorMessage .= " CSV import failed. No pallets were updated. Errors: " . implode("; ", $error_rows);
                    }
                    $conn_csv->close();
                }
            }
        } else {
            $errorMessage = "Upload failed. Allowed file types: " . implode(',', $allowedfileExtensions);
        }
    } else {
        $errorMessage = "Error uploading file. Code: " . ($_FILES['csv_file']['error'] ?? 'Unknown error');
    }
     // Store message in session to display after redirect
    $_SESSION['manage_pallets_message'] = $successMessage;
    if (!empty($errorMessage)) { // If there's an error, append it or set it
        $_SESSION['manage_pallets_message'] = ($_SESSION['manage_pallets_message'] ?? '') . ' ' . $errorMessage;
    }
    header("Location: manage_pallets.php");
    exit();
}

// --- Handle Pallet Shipment (Adapted from module_overview.php) ---
// This $successMessage is for the general page, $shipMessage is specific to this block
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ship_pallets') {
    $shipProcessMessage = ''; // Use a local variable for this block
    $createdDeliveryIds = [];
    $conn_ship = getDBConnection(); 
    if (!$conn_ship) {
        $shipProcessMessage = "Error: Database connection failed for shipment.";
    } else {
        $conn_ship->begin_transaction();
        try {
            $destinationType = $_POST['destination_type'] ?? 'project';
            $destinationId   = isset($_POST['destination_id']) ? intval($_POST['destination_id']) : 0;
            $bolNumber       = trim($_POST['bol_number'] ?? '');
            $departureDate   = $_POST['departure_date'] ?? null;
            $estArrivalDate  = $_POST['est_arrival_date'] ?? null;
            $palletIdsToShip = $_POST['selected_pallets'] ?? []; 
            $shipmentMode    = $_POST['shipment_mode'] ?? 'single';
            $palletsPerTruck = (isset($_POST['pallets_per_truck']) && is_numeric($_POST['pallets_per_truck']))
                               ? intval($_POST['pallets_per_truck'])
                               : 1;

            if (empty($palletIdsToShip)) {
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

            $placeholders = implode(',', array_fill(0, count($palletIdsToShip), '?'));
            $types        = str_repeat('i', count($palletIdsToShip));
            
            $stmtFetchPallets = $conn_ship->prepare(
                "SELECT ip.id, ip.wattage, ip.quantity, m.vendor_name AS origin_vendor 
                 FROM inventory_pallets ip
                 LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                 LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                 WHERE ip.id IN ($placeholders)"
            );

            if (!$stmtFetchPallets) throw new Exception("Failed to prepare pallet fetch: " . $conn_ship->error);
            $stmtFetchPallets->bind_param($types, ...$palletIdsToShip);
            $stmtFetchPallets->execute();
            $resultPallets = $stmtFetchPallets->get_result();
            $allPalletsDetails = [];
            while ($pallet = $resultPallets->fetch_assoc()) {
                $allPalletsDetails[] = $pallet;
            }
            $stmtFetchPallets->close();

            if (count($allPalletsDetails) !== count($palletIdsToShip)) {
                throw new Exception("Could not retrieve details for all selected pallets.");
            }

            $palletGroups = [];
            if ($shipmentMode === 'multi' && $palletsPerTruck > 0 && count($allPalletsDetails) > 0) {
                for ($i = 0; $i < count($allPalletsDetails); $i += $palletsPerTruck) {
                    $palletGroups[] = array_slice($allPalletsDetails, $i, $palletsPerTruck);
                }
            } else {
                if (!empty($allPalletsDetails)) { 
                   $palletGroups[] = $allPalletsDetails;
                } else {
                    throw new Exception('No valid pallet details found to create shipments.');
                }
            }

            $stmtLink = $conn_ship->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
            if (!$stmtLink) throw new Exception("Failed to prepare pallet link insert: " . $conn_ship->error);

            $sqlPalletUpdate = "UPDATE inventory_pallets SET status = ?, current_project_id = ?, current_warehouse_id = ?, arrival_date = ? WHERE id = ?";
            $stmtPalletUpdate = $conn_ship->prepare($sqlPalletUpdate);
            if (!$stmtPalletUpdate) throw new Exception("Failed to prepare pallet update: " . $conn_ship->error);

            foreach ($palletGroups as $group) {
                if (empty($group)) continue;
                $groupByWattage = [];
                foreach ($group as $pallet) {
                    $w = $pallet['wattage'];
                    if (!isset($groupByWattage[$w])) $groupByWattage[$w] = ['pallets' => [], 'total_quantity' => 0, 'origin_vendors' => []];
                    $groupByWattage[$w]['pallets'][] = $pallet;
                    $groupByWattage[$w]['total_quantity'] += $pallet['quantity'];
                    if (!empty($pallet['origin_vendor']) && !in_array($pallet['origin_vendor'], $groupByWattage[$w]['origin_vendors'])) {
                        $groupByWattage[$w]['origin_vendors'][] = $pallet['origin_vendor'];
                    }
                }

                foreach ($groupByWattage as $wattage => $wattageGroupData) {
                    $groupQty = $wattageGroupData['total_quantity'];
                    $palletsForThisWattageDelivery = $wattageGroupData['pallets'];
                    
                    $supplierForDelivery = "Stock Transfer"; 
                    if (count($wattageGroupData['origin_vendors']) === 1) {
                        $supplierForDelivery = $wattageGroupData['origin_vendors'][0];
                    } elseif (!empty($wattageGroupData['origin_vendors'])) {
                        $supplierForDelivery = $wattageGroupData['origin_vendors'][0]; // Default to first if mixed
                    }

                    $deliveryParams = [];
                    $sqlDeliveryInsert = "";
                    $deliveryTypes = "";

                    if ($destinationType === 'project') {
                        $sqlDeliveryInsert = "INSERT INTO deliveries (project_id, supplier, wattage, quantity, bol_number, anticipated_delivery_date, left_warehouse_date, status_of_delivery) VALUES (?, ?, ?, ?, ?, ?, ?, 'In Transit to Project')";
                        $deliveryTypes = "isissss";
                        $deliveryParams = [$destinationId, $supplierForDelivery, $wattage, $groupQty, $bolNumber, $estArrivalDate, $departureDate];
                    } else { 
                        $sqlDeliveryInsert = "INSERT INTO deliveries (warehouse_id, supplier, wattage, quantity, bol_number, left_warehouse_date, anticipated_delivery_date, status_of_delivery) VALUES (?, ?, ?, ?, ?, ?, ?, 'In Transit to Warehouse')";
                        $deliveryTypes = "isissss"; 
                        $deliveryParams = [$destinationId, $supplierForDelivery, $wattage, $groupQty, $bolNumber, $departureDate, $estArrivalDate];
                    }
                    
                    $stmtDelivery = $conn_ship->prepare($sqlDeliveryInsert);
                    if (!$stmtDelivery) throw new Exception("Prepare delivery insert failed for {$wattage}W: " . $conn_ship->error);
                    
                    $stmtDelivery->bind_param($deliveryTypes, ...$deliveryParams);
                    if (!$stmtDelivery->execute()) {
                        throw new Exception("Execute delivery insert failed for {$wattage}W: " . $stmtDelivery->error);
                    }
                    $deliveryId = $conn_ship->insert_id;
                    $createdDeliveryIds[] = $deliveryId;
                    $stmtDelivery->close();

                    foreach ($palletsForThisWattageDelivery as $palletToUpdate) {
                        $stmtLink->bind_param("ii", $deliveryId, $palletToUpdate['id']);
                        if (!$stmtLink->execute()) {
                            throw new Exception("Link pallet ID {$palletToUpdate['id']} to delivery {$deliveryId} failed: " . $stmtLink->error);
                        }
                        $newPalletStatus = ($destinationType === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';
                        $targetProjectId = ($destinationType === 'project') ? $destinationId : null;
                        $targetWarehouseId = ($destinationType === 'warehouse') ? $destinationId : null;
                        $stmtPalletUpdate->bind_param("siisi", $newPalletStatus, $targetProjectId, $targetWarehouseId, $estArrivalDate, $palletToUpdate['id']);
                        if (!$stmtPalletUpdate->execute()) {
                            throw new Exception("Update pallet ID {$palletToUpdate['id']} failed: " . $stmtPalletUpdate->error);
                        }
                    }
                }
            }
            $stmtLink->close();
            $stmtPalletUpdate->close();
            $conn_ship->commit();
            if (!empty($createdDeliveryIds)) {
                 $shipProcessMessage = "Successfully created delivery/deliveries (IDs: " . implode(", ", $createdDeliveryIds) . ") for " . count($palletIdsToShip) . " pallets.";
            } else {
                 $shipProcessMessage = "No deliveries were created, although the process reported success. Please verify pallet statuses.";
            }
           
        } catch (Exception $e) {
            if ($conn_ship) $conn_ship->rollback();
            $shipProcessMessage = "Error creating transfer delivery: " . $e->getMessage();
        } finally {
            if ($conn_ship) $conn_ship->close();
        }
    }
    $_SESSION['manage_pallets_message'] = $shipProcessMessage; 
    header("Location: manage_pallets.php"); // Redirect to show the message and prevent resubmission
    exit();
}
// --- END Pallet Shipment ---


try {
    $conn = getDBConnection(); // Main connection for displaying data
    // Comprehensive query to fetch pallet details
    $sql = "SELECT 
                ip.id AS pallet_id,
                ip.pallet_identifier,
                ip.wattage,
                ip.quantity,
                ip.status,
                ip.arrival_date,
                ip.unassigned_module_item_id,
                ip.current_warehouse_id,
                ip.current_project_id,
                ip.flash_test_data,
                m.vendor_name AS origin_vendor,
                w.name AS current_warehouse_name,
                p.project_name AS current_project_name,
                (SELECT COUNT(*) FROM delivery_pallets dp WHERE dp.inventory_pallet_id = ip.id) AS delivery_association_count,
                CASE
                    WHEN ip.status = 'At Manufacturer' THEN 'At Manufacturer'
                    WHEN ip.status = 'In Warehouse' AND w.name IS NOT NULL THEN CONCAT('Warehouse: ', w.name)
                    WHEN ip.status = 'In Transit to Warehouse' AND w.name IS NOT NULL THEN CONCAT('In Transit to Warehouse: ', w.name)
                    WHEN ip.status = 'Delivered to Project' AND p.project_name IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                    WHEN ip.status = 'In Transit to Project' AND p.project_name IS NOT NULL THEN CONCAT('In Transit to Project: ', p.project_name)
                    ELSE ip.status
                END AS current_location_display
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
            LEFT JOIN projects p ON ip.current_project_id = p.id
            ORDER BY ip.id DESC";
            
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pallets[] = $row;
        }
    } else {
        throw new Exception("Error fetching pallets: " . $conn->error);
    }

    // Fetch all projects for page filter dropdown
    $sqlProjects = "SELECT id, project_name FROM projects ORDER BY project_name ASC";
    $resultProjects = $conn->query($sqlProjects);
    if ($resultProjects) {
        while ($row = $resultProjects->fetch_assoc()) {
            $projects[] = $row; 
        }
    }

    // Fetch all projects and warehouses for SHIPPING MODAL
    $all_projects_for_shipping = [];
    $sqlAllProjectsModal = "SELECT id, project_name FROM projects ORDER BY project_name ASC";
    $resultAllProjectsModal = $conn->query($sqlAllProjectsModal);
    if ($resultAllProjectsModal) {
        while ($row = $resultAllProjectsModal->fetch_assoc()) {
            $all_projects_for_shipping[] = $row;
        }
    }

    $all_warehouses_for_shipping = [];
    $sqlAllWarehousesModal = "SELECT id, name FROM warehouses ORDER BY name ASC";
    $resultAllWarehousesModal = $conn->query($sqlAllWarehousesModal);
    if ($resultAllWarehousesModal) {
        while ($row = $resultAllWarehousesModal->fetch_assoc()) {
            $all_warehouses_for_shipping[] = $row;
        }
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage(); // This will be displayed if page load fails
}

if ($conn && $conn->ping()) { // Close connection if it was opened and is still active
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Pallets</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .filter-container {
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            display: flex; 
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
            justify-content: space-between; /* Key for spacing out items */
            align-items: center; 
            gap: 15px; /* Consistent gap between all items/groups */
        }
        .filter-group-left {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .filter-group-center {
            flex-grow: 1; /* Allows it to take up space and push right group */
            text-align: center; /* Centers the text within its allocated space */
        }
        .filter-group-right {
            display: flex;
            gap: 10px; /* Gap between buttons */
            align-items: center;
        }
        .filter-container label {
            margin-right: 5px; 
            font-weight: 500;
            white-space: nowrap; 
        }
        .filter-container input[type="text"],
        .filter-container select {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .filter-container input[type="text"] { /* Search input specific */
            /* flex-grow: 1; Removed to keep it fixed size */
            min-width: 150px; /* Adjusted min-width */
            max-width: 200px;
        }

        .success-message {
            color: green;
            background-color: #e6ffed;
            padding: 10px;
            border: 1px solid green;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .error-message { /* For critical page load errors */
            color: red;
            background-color: #ffe6e6;
            padding: 10px;
            border: 1px solid red;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .action-button {
            background-color: #488C9A;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            padding: 5px 10px;
            margin: 2px;
            font-weight: bold;
            cursor: pointer;
            border: none;
            font-size: 0.9em;
            display: inline-block;
            white-space: nowrap;
        }
        .action-button:hover {
            background-color: #3A6E7F;
        }
        .action-button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        
        /* CSV Instructions Modal Styles */
        #csvInstructionsModal.modal {
            display: none; position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%;
            overflow: auto; background-color: rgba(0,0,0,0.6);
        }
        #csvInstructionsModal .modal-content {
            background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888;
            width: 80%; max-width: 600px; border-radius: 5px; position: relative;
        }
        #csvInstructionsModal .close-button {
            color: #aaa; position: absolute; top: 10px; right: 15px; font-size: 28px;
            font-weight: bold; cursor: pointer;
        }
        #csvInstructionsModal .close-button:hover, #csvInstructionsModal .close-button:focus {
            color: black; text-decoration: none;
        }

        /* Shipment Modal Styles (from module_overview.php, prefixed with #shipModal) */
        #shipModal.modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%;
            overflow: auto; background-color: rgba(0,0,0,0.5);
        }
        #shipModal .modal-content {
            background-color: #fefefe; margin: 5% auto; padding: 30px 30px 20px 30px; border: 1px solid #888;
            width: 100%; max-width: 500px; border-radius: 8px; position: relative; box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        #shipModal .close-modal-btn {
            color: #aaa; position: absolute; top: 10px; right: 20px; font-size: 28px;
            font-weight: bold; cursor: pointer;
        }
        #shipModal .close-modal-btn:hover, #shipModal .close-modal-btn:focus {
            color: black; text-decoration: none;
        }
        #shipModal .shipment-details-modal-content h2 {
            margin-top: 0; margin-bottom: 20px; color: #293E4C; font-size: 1.3em;
            border-bottom: 1px solid #eee; padding-bottom: 10px; text-align: center;
        }
        #shipModal .form-row { display: flex; gap: 20px; margin-bottom: 18px; }
        #shipModal .form-row > div { flex: 1; min-width: 150px; }
        #shipModal .shipment-details-modal-content label { font-weight: 500; margin-bottom: 6px; display: block; }
        #shipModal .shipment-details-modal-content input[type="text"],
        #shipModal .shipment-details-modal-content input[type="date"],
        #shipModal .shipment-details-modal-content input[type="number"],
        #shipModal .shipment-details-modal-content select {
            width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;
            margin-bottom: 8px; font-size: 1em; box-sizing: border-box;
        }
        #shipModal .radio-label { display: inline-block; margin-right: 20px; font-weight: normal; }
        #shipModal .radio-label input[type=radio] { margin-right: 5px; vertical-align: middle; }
        #shipModal #destinationSelectContainer, 
        #shipModal #destinationSelectContainerMulti { margin-top: 10px; }
        #shipModal .tabs { display: flex; justify-content: center; gap: 1px; margin-bottom: 20px; }
        #shipModal .tabs button {
            flex: 1; min-width: 120px; max-width: 200px; background: #293E4C; color: #fff;
            padding: 10px; cursor: pointer; font-weight: 600; border: none; font-size: 1em;
            transition: background 0.2s, color 0.2s;
        }
        #shipModal .tabs button.active { background: #f39c12; color: #000; }
        #shipModal .action-button { /* For buttons INSIDE shipModal */
            background-color: #488C9A; color: white; border: none; padding: 10px 24px;
            border-radius: 4px; cursor: pointer; font-weight: 500; font-size: 1em; margin-top: 10px;
        }
        #shipModal .action-button:hover { background-color: #3A6E7F; }

        .table-responsive { width: 100%; overflow-x: auto; }
        .warning-message { /* For CSV warnings */
            background-color: #fff3cd; border-color: #ffeeba; color: #856404; padding: 10px; 
            margin-bottom: 15px; border: 1px solid; border-radius: 4px;
        }
        .warning-message a { margin-left: 10px; font-weight: bold; color: #856404; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Manage All Pallets</h1>

    <?php if (!empty($successMessage)): /* This is for general page messages, including shipment outcomes */ ?>
        <div class="success-message">
            <?php echo $successMessage; // Already HTML escaped if from $_SESSION, or needs to be if set directly ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($warningDetails) && is_array($warningDetails)): ?>
        <div class="warning-message">
            <?php echo htmlspecialchars($warningDetails['message'] ?? 'An unspecified warning occurred.'); ?>
            <?php if (!empty($warningDetails['batch_id'])): ?>
                <a href="edit_module.php?batch_id=<?php echo htmlspecialchars($warningDetails['batch_id']); ?>">Update Batch Details</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): /* This is for critical page load errors */ ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <!-- CSV Upload Section -->
    <div class="csv-upload-section" style="margin-bottom: 20px; padding: 15px; background-color: #f0f0f0; border: 1px solid #ddd; border-radius: 5px;">
        <h3>
            Bulk Update Pallets via CSV
            <button type="button" id="openCsvInstructions" class="action-button" style="font-size: 0.8em; padding: 3px 8px; background-color: #6c757d;">(View Instructions)</button>
        </h3>
        <form action="manage_pallets.php" method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <button type="submit" name="upload_csv" class="action-button" style="background-color: #28a745;">Upload and Process CSV</button>
        </form>
    </div>

    <!-- Main Form for Shipping Pallets -->
    <form id="shipPalletsForm" method="POST" action="manage_pallets.php">
        <input type="hidden" name="action" value="ship_pallets">
        
        <div class="filter-container">
            <div class="filter-group-left">
                <label for="filterInput">Search:</label>
                <input type="text" id="filterInput" onkeyup="filterTable()" placeholder="Filter table...">
                <label for="projectFilter">Project:</label>
                <select id="projectFilter" onchange="filterTable()">
                    <option value="">All Projects</option>
                    <option value="Unassigned">Unassigned</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?php echo htmlspecialchars($proj['project_name']); ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group-center">
                <span id="selectedCount" style="font-weight: bold; color: #488C9A;">0 pallets selected</span>
            </div>
            <div class="filter-group-right">
                <button type="button" id="openShipModalBtn" class="action-button" style="padding: 8px 15px; font-size: 0.9em;" disabled>
                    Create Delivery for Selected
                </button>
                <button type="button" id="exportCsvBtn" class="action-button" style="padding: 8px 15px; font-size: 0.9em;">Export to CSV</button>
            </div>
        </div>

        <div class="table-responsive">
            <table id="palletsTable">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAllPallets" title="Select/Deselect all visible pallets"></th>
                        <th>Project</th>
                        <th>Identifier</th>
                        <th>Origin Vendor</th>
                        <th>Wattage</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Current Location</th>
                        <th>Associated Deliveries</th>
                        <th>Arrival Date</th>
                        <th>Flash Test Data</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($pallets)): ?>
                        <?php foreach ($pallets as $pallet): ?>
                            <tr data-id="<?php echo htmlspecialchars($pallet['pallet_id']); ?>">
                                <td><input type="checkbox" name="selected_pallets[]" value="<?php echo htmlspecialchars($pallet['pallet_id']); ?>" class="pallet-checkbox"></td>
                                <td><?php echo htmlspecialchars($pallet['current_project_name'] ?? 'Unassigned'); ?></td>
                                <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet['wattage']); ?></td>
                                <td><?php echo number_format($pallet['quantity']); ?></td>
                                <td><?php echo htmlspecialchars($pallet['status']); ?></td>
                                <td><?php echo htmlspecialchars($pallet['current_location_display']); ?></td>
                                <td><?php echo $pallet['delivery_association_count']; ?></td>
                                <td>
                                    <?php 
                                    if (!empty($pallet['arrival_date']) && $pallet['arrival_date'] !== 'N/A') {
                                        try {
                                            // Assuming arrival_date might have time, just format the date part
                                            $date = new DateTime($pallet['arrival_date']);
                                            echo $date->format('m-d-Y');
                                        } catch (Exception $e) {
                                            echo htmlspecialchars($pallet['arrival_date']); // Fallback
                                        }
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($pallet['flash_test_data'])): ?>
                                        <a href="view_flash_test.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" target="_blank" class="action-button" style="background-color: #5bc0de;">View</a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="pallet_details.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" class="action-button">Pallet Details</a>
                                    <button class="action-button" onclick="window.location.href='edit_pallet.php?pallet_id=<?php echo $pallet['pallet_id']; ?>'" style="background-color:#f0ad4e;">Edit Pallet</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12">No pallets found in the system.</td> <!-- Updated colspan -->
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="back-link" style="margin-top: 20px;">
            <a href="admin_dashboard.php" class="action-button">&larr; Back to Admin Dashboard</a>
        </div>
    </form> <!-- End shipPalletsForm -->
</main>

<!-- CSV Instructions Modal -->
<div id="csvInstructionsModal" class="modal">
    <div class="modal-content">
        <span class="close-button" onclick="document.getElementById('csvInstructionsModal').style.display='none'">&times;</span>
        <h2>CSV File Format Instructions</h2>
        <p><strong>Important:</strong> Only <code>pallet_identifier</code>, <code>wattage</code>, and <code>flash_test_data</code> can be updated via this CSV. Other fields like <code>quantity</code> and <code>status</code> are informational if included in your export and will not be changed by this import.</p>
        <ul>
            <li>The first row of the CSV file will be ignored (assumed to be a header row).</li>
            <li><strong>Columns in order:</strong>
                <ol>
                    <li><code>id</code> (Number, <b>Required</b>): The unique database ID. This is used to match records. <b>Do not change this value.</b></li>
                    <li><code>pallet_identifier</code> (Text, Updatable): The user-defined identifier (barcode, label, etc.).</li>
                    <li><code>wattage</code> (Text, Updatable): Wattage of the modules (e.g., 450W). If changed, you may need to update the original module batch.</li>
                    <li><code>quantity</code> (Number, Informational Only): Number of modules. <em>Cannot be updated via this CSV.</em></li>
                    <li><code>status</code> (Text, Informational Only): Current condition status. <em>Cannot be updated via this CSV.</em></li>
                    <li><code>damaged_quantity</code> (Number, Informational Only, if present): Number of damaged modules. <em>Cannot be updated via this CSV.</em></li>
                    <li><code>flash_test_data</code> (Text, Updatable): Filename or reference for flash test data.</li>
                </ol>
            </li>
            <li>The <code>id</code> column is used to match the correct pallet. Rows with missing/invalid <code>id</code> will be skipped.</li>
            <li>If <code>wattage</code> is updated, please manually review and update the corresponding unassigned module batch in "Manage Modules" if necessary.</li>
        </ul>
    </div>
</div>

<!-- Shipment Modal (Copied from module_overview.php and adapted) -->
<div id="shipModal" class="modal">
    <div class="modal-content">
        <span class="close-modal-btn">&times;</span>
        <div class="shipment-details-modal-content">
            <h2 style="margin-top:0; text-align:center;">Create Delivery</h2>
            <div class="tabs">
                <button type="button" class="modal-tab active" id="singleTabBtn">Single Shipment</button>
                <button type="button" class="modal-tab" id="multiTabBtn">Multiple Shipments</button>
            </div>
            <!-- SINGLE SHIPMENT SECTION -->
            <div id="singleShipmentSection">
                <form id="singleShipmentFormInternal" onsubmit="return false;">
                    <label for="bol_number_single_modal">BOL Number (Optional):</label>
                    <input type="text" id="bol_number_single_modal" name="bol_number_single_modal">
                    <div class="form-row">
                        <div>
                            <label for="departure_date_single_modal">Departure Date:</label>
                            <input type="date" id="departure_date_single_modal" name="departure_date_single_modal" required>
                        </div>
                        <div>
                            <label for="est_arrival_date_single_modal">Est. Arrival Date:</label>
                            <input type="date" id="est_arrival_date_single_modal" name="est_arrival_date_single_modal" required>
                        </div>
                    </div>
                    <label style="margin-bottom: 10px; display:block;">Destination:</label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type_single_modal" value="project" checked onchange="toggleDestinationSelectSingle()"> Project
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type_single_modal" value="warehouse" onchange="toggleDestinationSelectSingle()"> Warehouse
                    </label>
                    <div id="destinationSelectContainer">
                        <label for="destination_id_single_modal" id="destinationLabelSingle">Project:</label>
                        <select name="destination_id_single_modal" id="destination_id_single" required>
                            <!-- Options will be populated by JavaScript -->
                        </select>
                    </div>
                    <button type="button" id="confirmShipmentBtn" class="action-button" style="margin-top:15px;">
                        Create Delivery
                    </button>
                </form>
            </div>
            <!-- MULTIPLE SHIPMENTS SECTION -->
            <div id="multiShipmentSection" style="display:none;">
                 <form id="multiShipmentFormInternal" onsubmit="return false;">
                    <label for="palletsPerTruck_modal">Pallets per Truck:</label>
                    <input type="number" id="palletsPerTruck_modal" name="pallets_per_truck_multi_modal" min="1" value="1" style="width:100px;">
                    <div id="multiShipSummary" style="margin-top:10px; color:#488C9A;"></div>
                    <label for="bol_number_multi_modal">BOL Number (Optional):</label>
                    <input type="text" id="bol_number_multi_modal" name="bol_number_multi_modal">
                    <div class="form-row">
                        <div>
                            <label for="departure_date_multi_modal">Departure Date:</label>
                            <input type="date" id="departure_date_multi_modal" name="departure_date_multi_modal" required>
                        </div>
                        <div>
                            <label for="est_arrival_date_multi_modal">Est. Arrival Date:</label>
                            <input type="date" id="est_arrival_date_multi_modal" name="est_arrival_date_multi_modal" required>
                        </div>
                    </div>
                    <label style="margin-bottom: 10px; display:block;">Destination:</label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type_multi_modal" value="project" checked onchange="toggleDestinationSelectMulti()"> Project
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="destination_type_multi_modal" value="warehouse" onchange="toggleDestinationSelectMulti()"> Warehouse
                    </label>
                    <div id="destinationSelectContainerMulti">
                        <label for="destination_id_multi_modal" id="destinationLabelMulti">Project:</label>
                        <select name="destination_id_multi_modal" id="destination_id_multi" required>
                           <!-- Options will be populated by JavaScript -->
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

<!-- Embed PHP data for shipping modal JS -->
<script>
    const projectsData = <?php echo json_encode($all_projects_for_shipping); ?>;
    const warehousesData = <?php echo json_encode($all_warehouses_for_shipping); ?>;
</script>

<script>
// Combined filter for project and general search
function filterTable() {
    var input = document.getElementById("filterInput").value.toUpperCase();
    var projectFilterValue = document.getElementById("projectFilter").value;
    var table = document.getElementById("palletsTable");
    var tr = table.getElementsByTagName("tr");

    for (var i = 1; i < tr.length; i++) { // Start from 1 to skip header row
        tr[i].style.display = "none"; 
        var td = tr[i].getElementsByTagName("td");
        if (td.length > 0) { // Ensure row has cells
            var projectCell = td[1]; // Project is the second column (index 1) after checkbox
            
            var matchesProject = false;
            if (projectFilterValue === "") { 
                matchesProject = true;
            } else if (projectFilterValue === "Unassigned") {
                matchesProject = projectCell && (projectCell.textContent || projectCell.innerText) === "Unassigned";
            } else {
                matchesProject = projectCell && (projectCell.textContent || projectCell.innerText) === projectFilterValue;
            }

            var matchesSearch = false;
            if (input === "") {
                matchesSearch = true; 
            } else {
                for (var j = 1; j < td.length - 1; j++) { 
                    if (td[j]) {
                        var txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(input) > -1) {
                            matchesSearch = true;
                            break;
                        }
                    }
                }
            }
            if (matchesProject && matchesSearch) {
                tr[i].style.display = "";
            }
        }
    }
    updateOpenShipModalButtonState(); 
    updateSelectedCount();
    const selectAllCheckbox = document.getElementById('selectAllPallets');
    if (selectAllCheckbox) {
        let allVisibleAreChecked = true;
        let hasVisibleRows = false;
        document.querySelectorAll('#palletsTable tbody tr').forEach(function(row) {
            if (row.style.display !== 'none') {
                hasVisibleRows = true;
                const checkbox = row.querySelector('.pallet-checkbox');
                if (checkbox && !checkbox.checked) {
                    allVisibleAreChecked = false;
                }
            }
        });
        if(hasVisibleRows && allVisibleAreChecked){
            selectAllCheckbox.checked = true;
        } else {
            selectAllCheckbox.checked = false;
        }
    }
}

// CSV Instructions Modal
document.getElementById('openCsvInstructions').addEventListener('click', function() {
    document.getElementById('csvInstructionsModal').style.display = 'block';
});
window.addEventListener('click', function(event) {
    var csvModal = document.getElementById('csvInstructionsModal');
    if (event.target == csvModal) {
        csvModal.style.display = 'none';
    }
});

// Export to CSV 
document.getElementById('exportCsvBtn').addEventListener('click', function() {
    var table = document.getElementById('palletsTable');
    var rows = table.querySelectorAll('tbody tr');
    var csvData = [];

    var headers = [
        "id", "pallet_identifier", "wattage", "quantity", "status", 
        "flash_test_data", "Project", "Origin Vendor", "Current Location", 
        "Associated Deliveries", "Arrival Date"
    ];
    csvData.push(headers.map(header => '"' + header.replace(/"/g, '""') + '"').join(','));

    rows.forEach(function(row) {
        if (row.style.display !== 'none') { 
            var cells = row.querySelectorAll('td');
            var rowData = [];
            
            const columnIndices = {
                id: row.getAttribute('data-id'), 
                Project: 1,
                pallet_identifier: 2,
                Origin_Vendor: 3,
                wattage: 4,
                quantity: 5,
                status: 6,
                Current_Location: 7,
                Associated_Deliveries: 8,
                Arrival_Date: 9,
                flash_test_data: 10 
            };

            headers.forEach(function(headerKey) {
                var cellText = 'N/A';
                var keyForMapping = headerKey.replace(/\s+/g, '_'); 

                if (keyForMapping === 'id') {
                    cellText = columnIndices.id;
                } else if (columnIndices.hasOwnProperty(keyForMapping) && cells[columnIndices[keyForMapping]]) {
                    let cellElement = cells[columnIndices[keyForMapping]];
                    if (keyForMapping === 'flash_test_data') {
                        var link = cellElement.querySelector('a');
                        if (link) { 
                           let href = link.getAttribute('href');
                           if (href && href.includes('view_flash_test.php')) {
                               cellText = link.getAttribute('data-filename') || 'File Present'; 
                           } else {
                               cellText = 'File Present';
                           }
                        } else { 
                            cellText = cellElement.textContent || cellElement.innerText;
                        }
                    } else {
                        cellText = cellElement.textContent || cellElement.innerText;
                    }
                }
                var escapedText = (cellText || 'N/A').toString().trim().replace(/"/g, '""');
                if (escapedText.includes(",")) {
                    escapedText = '"' + escapedText + '"';
                }
                rowData.push(escapedText);
            });
            csvData.push(rowData.join(','));
        }
    });

    var csvContent = csvData.join("\r\n");
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement("a");
    var filename = "pallets_export_" + new Date().toISOString().slice(0,10) + ".csv";

    if (navigator.msSaveBlob) { 
        navigator.msSaveBlob(blob, filename);
    } else if (link.download !== undefined) { 
        var url = URL.createObjectURL(blob);
        link.setAttribute("href", url);
        link.setAttribute("download", filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } else {
         alert("CSV export is not directly supported. Please try copying the data.");
    }
});


// --- Pallet Selection and Shipping Modal JS (Adapted from module_overview.php) ---
function toggleAllPalletCheckboxes(isChecked) {
    document.querySelectorAll('#palletsTable tbody tr').forEach(function(row) {
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
    if (!openBtn) return;
    const checkedCount = document.querySelectorAll('#palletsTable .pallet-checkbox:checked').length;
    openBtn.disabled = (checkedCount === 0);
}

function updateSelectedCount() {
    let count = document.querySelectorAll('#palletsTable .pallet-checkbox:checked').length;
    const countEl = document.getElementById('selectedCount');
    if (countEl) {
        countEl.textContent = count + ' pallet' + (count === 1 ? '' : 's') + ' selected';
    }
    if (typeof updateMultiShipSummary === 'function') {
        updateMultiShipSummary();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAllPalletsCheckbox = document.getElementById('selectAllPallets');
    if (selectAllPalletsCheckbox) {
        selectAllPalletsCheckbox.addEventListener('change', function() {
            toggleAllPalletCheckboxes(this.checked);
        });
    }

    document.querySelectorAll('#palletsTable .pallet-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            updateOpenShipModalButtonState();
            updateSelectedCount();
            if (!this.checked && selectAllPalletsCheckbox) {
                selectAllPalletsCheckbox.checked = false;
            } else if (this.checked) {
                let allVisibleChecked = true;
                document.querySelectorAll('#palletsTable tbody tr').forEach(function(row) {
                    if (row.style.display !== 'none') {
                        const cb = row.querySelector('.pallet-checkbox');
                        if (!cb || !cb.checked) {
                            allVisibleChecked = false;
                        }
                    }
                });
                if (allVisibleChecked && selectAllPalletsCheckbox) {
                    selectAllPalletsCheckbox.checked = true;
                }
            }
        });
    });

    updateOpenShipModalButtonState();
    updateSelectedCount();
    
    if (typeof toggleDestinationSelectSingle === 'function') toggleDestinationSelectSingle();
    if (typeof toggleDestinationSelectMulti === 'function') toggleDestinationSelectMulti();
    if (typeof updateMultiShipSummary === 'function') updateMultiShipSummary();
});

// Shipment Modal Logic
const shipModal = document.getElementById('shipModal');
const openShipModalBtn = document.getElementById('openShipModalBtn');
const closeShipModalBtn = shipModal ? shipModal.querySelector('.close-modal-btn') : null;

function openShipModal() {
    if (openShipModalBtn && openShipModalBtn.disabled) return;
    if (shipModal) shipModal.style.display = 'block';
    if (typeof toggleDestinationSelectSingle === 'function') toggleDestinationSelectSingle();
    if (typeof toggleDestinationSelectMulti === 'function') toggleDestinationSelectMulti();
}
function closeShipModal() {
    if (shipModal) shipModal.style.display = 'none';
}

if (openShipModalBtn) openShipModalBtn.addEventListener('click', openShipModal);
if (closeShipModalBtn) closeShipModalBtn.addEventListener('click', closeShipModal);
window.addEventListener('click', function(e) {
    if (shipModal && e.target === shipModal) closeShipModal();
});

// Tab Switching
const singleTabBtn = document.getElementById('singleTabBtn');
const multiTabBtn = document.getElementById('multiTabBtn');
const singleSection = document.getElementById('singleShipmentSection');
const multiSection = document.getElementById('multiShipmentSection');

if (singleTabBtn && multiTabBtn && singleSection && multiSection) {
    function setActiveTab(isSingle) {
        singleTabBtn.classList.toggle('active', isSingle);
        multiTabBtn.classList.toggle('active', !isSingle);
        singleSection.style.display = isSingle ? '' : 'none';
        multiSection.style.display = isSingle ? 'none' : '';
        singleTabBtn.style.background = isSingle ? '#f39c12' : '#293E4C';
        singleTabBtn.style.color = isSingle ? '#000' : '#fff';
        multiTabBtn.style.background = !isSingle ? '#f39c12' : '#293E4C';
        multiTabBtn.style.color = !isSingle ? '#000' : '#fff';
    }
    singleTabBtn.addEventListener('click', () => setActiveTab(true));
    multiTabBtn.addEventListener('click', () => setActiveTab(false));
    setActiveTab(true); // Default
}

// Toggle Destination Dropdowns
function populateDestinationDropdown(selectElement, labelElement, destinationType, dataSource, nameField, placeholderPrefix) {
    if (!selectElement || !labelElement) return;
    labelElement.textContent = destinationType === 'project' ? 'Project:' : 'Warehouse:';
    selectElement.innerHTML = ''; 
    if (!dataSource || dataSource.length === 0) {
        selectElement.innerHTML = `<option value="">No ${placeholderPrefix.toLowerCase()} found</option>`;
        selectElement.disabled = true;
    } else {
        selectElement.disabled = false;
        selectElement.innerHTML = `<option value="">-- Select ${placeholderPrefix} --</option>`;
        dataSource.forEach(function(item) {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item[nameField];
            selectElement.appendChild(opt);
        });
    }
}

function toggleDestinationSelectSingle() {
    const assignType = document.querySelector('input[name="destination_type_single_modal"]:checked')?.value;
    if (!assignType) return;
    populateDestinationDropdown(
        document.getElementById('destination_id_single'),
        document.getElementById('destinationLabelSingle'),
        assignType,
        assignType === 'project' ? projectsData : warehousesData,
        assignType === 'project' ? 'project_name' : 'name',
        assignType === 'project' ? 'Project' : 'Warehouse'
    );
}

function toggleDestinationSelectMulti() {
    const assignType = document.querySelector('input[name="destination_type_multi_modal"]:checked')?.value;
    if (!assignType) return;
     populateDestinationDropdown(
        document.getElementById('destination_id_multi'),
        document.getElementById('destinationLabelMulti'),
        assignType,
        assignType === 'project' ? projectsData : warehousesData,
        assignType === 'project' ? 'project_name' : 'name',
        assignType === 'project' ? 'Project' : 'Warehouse'
    );
}

document.querySelectorAll('input[name="destination_type_single_modal"]').forEach(r => r.addEventListener('change', toggleDestinationSelectSingle));
document.querySelectorAll('input[name="destination_type_multi_modal"]').forEach(r => r.addEventListener('change', toggleDestinationSelectMulti));

// Confirm Shipment Buttons
const confirmShipmentBtn = document.getElementById('confirmShipmentBtn');
const confirmMultiShipmentBtn = document.getElementById('confirmMultiShipmentBtn');
const mainShipForm = document.getElementById('shipPalletsForm'); 

function setOrCreateHidden(form, fieldName, fieldValue) {
    if (!form) return;
    let el = form.querySelector(`input[type="hidden"][name="${fieldName}"]`);
    if (!el) {
        el = document.createElement('input');
        el.type = 'hidden'; el.name = fieldName; form.appendChild(el);
    }
    el.value = fieldValue;
}

if (confirmShipmentBtn && mainShipForm) {
    confirmShipmentBtn.addEventListener('click', function() {
        setOrCreateHidden(mainShipForm, 'shipment_mode', 'single');
        setOrCreateHidden(mainShipForm, 'pallets_per_truck', '1');

        const assignType = document.querySelector('input[name="destination_type_single_modal"]:checked').value;
        const targetId = document.getElementById('destination_id_single').value;
        const bol = document.getElementById('bol_number_single_modal').value;
        const departure = document.getElementById('departure_date_single_modal').value;
        const arrival = document.getElementById('est_arrival_date_single_modal').value;

        if (!targetId) { alert('Please select a destination.'); return; }
        if (!departure) { alert('Departure date is required.'); return; }
        if (!arrival) { alert('Estimated arrival date is required.'); return; }

        setOrCreateHidden(mainShipForm, 'destination_type', assignType);
        setOrCreateHidden(mainShipForm, 'destination_id', targetId);
        setOrCreateHidden(mainShipForm, 'bol_number', bol);
        setOrCreateHidden(mainShipForm, 'departure_date', departure);
        setOrCreateHidden(mainShipForm, 'est_arrival_date', arrival);
        mainShipForm.submit();
    });
}

if (confirmMultiShipmentBtn && mainShipForm) {
    confirmMultiShipmentBtn.addEventListener('click', function() {
        setOrCreateHidden(mainShipForm, 'shipment_mode', 'multi');
        
        const palletsPerTruckVal = document.getElementById('palletsPerTruck_modal').value;
        if (!palletsPerTruckVal || parseInt(palletsPerTruckVal) < 1) {
            alert('Pallets per truck must be at least 1.'); return;
        }
        setOrCreateHidden(mainShipForm, 'pallets_per_truck', palletsPerTruckVal);

        const assignType = document.querySelector('input[name="destination_type_multi_modal"]:checked').value;
        const targetId = document.getElementById('destination_id_multi').value;
        const bol = document.getElementById('bol_number_multi_modal').value;
        const departure = document.getElementById('departure_date_multi_modal').value;
        const arrival = document.getElementById('est_arrival_date_multi_modal').value;

        if (!targetId) { alert('Please select a destination.'); return; }
        if (!departure) { alert('Departure date is required.'); return; }
        if (!arrival) { alert('Estimated arrival date is required.'); return; }
        
        setOrCreateHidden(mainShipForm, 'destination_type', assignType);
        setOrCreateHidden(mainShipForm, 'destination_id', targetId);
        setOrCreateHidden(mainShipForm, 'bol_number', bol);
        setOrCreateHidden(mainShipForm, 'departure_date', departure);
        setOrCreateHidden(mainShipForm, 'est_arrival_date', arrival);
        mainShipForm.submit();
    });
}

// Multi-Shipment Summary
const palletsPerTruckInput = document.getElementById('palletsPerTruck_modal'); // Corrected ID
const multiShipSummary = document.getElementById('multiShipSummary');

function updateMultiShipSummary() {
    if (!palletsPerTruckInput || !multiShipSummary) return;
    const selected = document.querySelectorAll('#palletsTable .pallet-checkbox:checked').length;
    const perTruck = parseInt(palletsPerTruckInput.value, 10) || 1; 
    if (selected > 0 && perTruck > 0) {
        const numDeliveries = Math.ceil(selected / perTruck);
        multiShipSummary.textContent = 
            `${numDeliveries} deliver${numDeliveries === 1 ? 'y' : 'ies'} will be created (${perTruck} pallet${perTruck === 1 ? '' : 's'} per truck).`;
    } else {
        multiShipSummary.textContent = 'Select pallets and specify pallets per truck.';
    }
}
if (palletsPerTruckInput) {
    palletsPerTruckInput.addEventListener('input', updateMultiShipSummary);
}
</script>
</body>
</html> 