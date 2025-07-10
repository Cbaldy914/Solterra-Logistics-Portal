<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin, global_admin, or user
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','user'])) {
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

// --- Account Access Control ---
$account_id_for_user = null;
$is_global_admin = ($_SESSION['role'] === 'global_admin');
$is_admin = ($_SESSION['role'] === 'admin');
$is_user = ($_SESSION['role'] === 'user');

if (!$is_global_admin) {
    // For admins and users, get their account_id
    $conn_account = getDBConnection();
    if ($conn_account) {
        if ($is_admin) {
            $stmt_account = $conn_account->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1");
        } else {
            $stmt_account = $conn_account->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
        }
        
        if ($stmt_account) {
            $stmt_account->bind_param("i", $_SESSION['user_id']);
            $stmt_account->execute();
            $stmt_account->bind_result($account_id);
            if ($stmt_account->fetch()) {
                $account_id_for_user = $account_id;
            }
            $stmt_account->close();
        }
        $conn_account->close();
    }
    
    if (!$account_id_for_user) {
        $errorMessage = "Error: User is not associated with an account.";
    }
}

// --- Handle CSV Upload (Global Admins Only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    // Check if user is global admin
    if ($_SESSION['role'] !== 'global_admin') {
        $errorMessage = "Error: Only global administrators can upload CSV files.";
        $_SESSION['manage_pallets_message'] = $errorMessage;
        header("Location: manage_pallets.php");
        exit();
    }
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
        
        // Cost and logistics fields
        $freightCost = isset($_POST['freight_cost']) && $_POST['freight_cost'] !== '' ? (float)$_POST['freight_cost'] : 0.0;
        $accessorialCost = isset($_POST['accessorial_cost']) && $_POST['accessorial_cost'] !== '' ? (float)$_POST['accessorial_cost'] : 0.0;
        $customerCost = isset($_POST['customer_cost']) && $_POST['customer_cost'] !== '' ? (float)$_POST['customer_cost'] : 0.0;
        $miles = isset($_POST['miles']) && $_POST['miles'] !== '' ? (float)$_POST['miles'] : null;

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
                        $sqlDeliveryInsert = "INSERT INTO deliveries (project_id, supplier, origin_type, origin_id, wattage, quantity, bol_number, anticipated_delivery_date, left_warehouse_date, status_of_delivery, freight_cost, accessorial_costs, customer_cost, miles) VALUES (?, ?, 'manufacturer', NULL, ?, ?, ?, ?, ?, 'In Transit to Project', ?, ?, ?, ?)";
                        $deliveryTypes = "ississsdddd";
                        $deliveryParams = [$destinationId, $supplierForDelivery, $wattage, $groupQty, $bolNumber, $estArrivalDate, $departureDate, $freightCost, $accessorialCost, $customerCost, $miles];
                    } else { 
                        $sqlDeliveryInsert = "INSERT INTO deliveries (warehouse_id, supplier, origin_type, origin_id, wattage, quantity, bol_number, left_warehouse_date, anticipated_delivery_date, status_of_delivery, freight_cost, accessorial_costs, customer_cost, miles) VALUES (?, ?, 'manufacturer', NULL, ?, ?, ?, ?, ?, 'In Transit to Warehouse', ?, ?, ?, ?)";
                        $deliveryTypes = "ississsdddd"; 
                        $deliveryParams = [$destinationId, $supplierForDelivery, $wattage, $groupQty, $bolNumber, $departureDate, $estArrivalDate, $freightCost, $accessorialCost, $customerCost, $miles];
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

// --- Handle Pallet Deletion ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_pallets') {
    $deleteProcessMessage = '';
    $conn_delete = getDBConnection();
    if (!$conn_delete) {
        $deleteProcessMessage = "Error: Database connection failed for deletion.";
    } else {
        $conn_delete->begin_transaction();
        try {
            $palletIdsToDelete = $_POST['selected_pallets'] ?? [];
            if (empty($palletIdsToDelete)) {
                throw new Exception('No pallets selected for deletion.');
            }

            // Check if any pallets are linked to deliveries
            $placeholders = implode(',', array_fill(0, count($palletIdsToDelete), '?'));
            $types = str_repeat('i', count($palletIdsToDelete));
            
            $stmtCheckDeliveries = $conn_delete->prepare("SELECT inventory_pallet_id FROM delivery_pallets WHERE inventory_pallet_id IN ($placeholders)");
            if (!$stmtCheckDeliveries) throw new Exception("Failed to prepare delivery check: " . $conn_delete->error);
            
            $stmtCheckDeliveries->bind_param($types, ...$palletIdsToDelete);
            $stmtCheckDeliveries->execute();
            $resultDeliveries = $stmtCheckDeliveries->get_result();
            $linkedPallets = [];
            while ($row = $resultDeliveries->fetch_assoc()) {
                $linkedPallets[] = $row['inventory_pallet_id'];
            }
            $stmtCheckDeliveries->close();

            if (!empty($linkedPallets)) {
                throw new Exception('Cannot delete pallets that are linked to deliveries. Pallet IDs: ' . implode(', ', $linkedPallets));
            }

            // Delete pallets
            $stmtDelete = $conn_delete->prepare("DELETE FROM inventory_pallets WHERE id IN ($placeholders)");
            if (!$stmtDelete) throw new Exception("Failed to prepare pallet deletion: " . $conn_delete->error);
            
            $stmtDelete->bind_param($types, ...$palletIdsToDelete);
            if (!$stmtDelete->execute()) {
                throw new Exception("Failed to delete pallets: " . $stmtDelete->error);
            }
            
            $deletedCount = $stmtDelete->affected_rows;
            $stmtDelete->close();
            $conn_delete->commit();
            
            $deleteProcessMessage = "Successfully deleted $deletedCount pallet(s).";
            
        } catch (Exception $e) {
            $conn_delete->rollback();
            $deleteProcessMessage = "Error deleting pallets: " . $e->getMessage();
        } finally {
            $conn_delete->close();
        }
    }
    $_SESSION['manage_pallets_message'] = $deleteProcessMessage;
    header("Location: manage_pallets.php");
    exit();
}
// --- END Pallet Deletion ---


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
                ip.assigned_project_id,
                ip.flash_test_data,
                m.vendor_name AS origin_vendor,
                w.name AS current_warehouse_name,
                w.street_address as warehouse_street, w.city as warehouse_city, w.state as warehouse_state, w.zip_code as warehouse_zip,
                p_current.project_name AS current_project_name,
                p_current.street_address as project_street, p_current.city as project_city, p_current.state as project_state, p_current.zip_code as project_zip,
                p_assigned.project_name AS assigned_project_name,
                COALESCE(p_current.project_name, p_assigned.project_name, 'Unassigned') AS display_project_name,
                (SELECT COUNT(*) FROM delivery_pallets dp WHERE dp.inventory_pallet_id = ip.id) AS delivery_association_count,
                CASE
                    WHEN ip.status = 'At Manufacturer' THEN 'At Manufacturer'
                    WHEN ip.status = 'In Warehouse' AND w.name IS NOT NULL THEN CONCAT('Warehouse: ', w.name)
                    WHEN ip.status = 'In Transit to Warehouse' AND w.name IS NOT NULL THEN CONCAT('In Transit to Warehouse: ', w.name)
                    WHEN ip.status = 'Delivered to Project' AND p_current.project_name IS NOT NULL THEN CONCAT('Project: ', p_current.project_name)
                    WHEN ip.status = 'In Transit to Project' AND p_current.project_name IS NOT NULL THEN CONCAT('In Transit to Project: ', p_current.project_name)
                    ELSE ip.status
                END AS current_location_display
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
            LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id";
    
    // Add account filtering for non-global admins
    if (!$is_global_admin && $account_id_for_user) {
        $sql .= " WHERE (p_current.account_id = ? OR p_assigned.account_id = ? OR (ip.current_project_id IS NULL AND ip.assigned_project_id IS NULL))";
    }
    
    $sql .= " ORDER BY ip.id DESC";
    
    // Execute query with or without parameters
    if (!$is_global_admin && $account_id_for_user) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $account_id_for_user, $account_id_for_user);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $pallets[] = $row;
                }
            } else {
                throw new Exception("Error fetching pallets: " . $stmt->error);
            }
            $stmt->close();
        } else {
            throw new Exception("Error preparing pallets query: " . $conn->error);
        }
    } else {
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $pallets[] = $row;
            }
        } else {
            throw new Exception("Error fetching pallets: " . $conn->error);
        }
    }

    // Add full addresses for origin determination (like in module_overview.php)
    foreach ($pallets as &$pallet) {
        if ($pallet['status'] === 'In Warehouse' && $pallet['current_warehouse_id']) {
            $warehouse_address_parts = array_filter([$pallet['warehouse_street'], $pallet['warehouse_city'], $pallet['warehouse_state'], $pallet['warehouse_zip']]);
            $pallet['warehouse_full_address'] = implode(', ', $warehouse_address_parts);
        }
        if ($pallet['status'] === 'Delivered to Project' && $pallet['current_project_id']) {
            $project_address_parts = array_filter([$pallet['project_street'], $pallet['project_city'], $pallet['project_state'], $pallet['project_zip']]);
            $pallet['project_full_address'] = implode(', ', $project_address_parts);
        }
    }
    unset($pallet); // Unset reference after loop

    // Fetch all projects for page filter dropdown
    if ($is_global_admin) {
        $sqlProjects = "SELECT id, project_name FROM projects ORDER BY project_name ASC";
        $resultProjects = $conn->query($sqlProjects);
        if ($resultProjects) {
            while ($row = $resultProjects->fetch_assoc()) {
                $projects[] = $row; 
            }
        }
    } else if ($account_id_for_user) {
        $sqlProjects = "SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC";
        $stmtProjects = $conn->prepare($sqlProjects);
        if ($stmtProjects) {
            $stmtProjects->bind_param("i", $account_id_for_user);
            $stmtProjects->execute();
            $resultProjects = $stmtProjects->get_result();
            if ($resultProjects) {
                while ($row = $resultProjects->fetch_assoc()) {
                    $projects[] = $row; 
                }
            }
            $stmtProjects->close();
        }
    }

    // Get Google Maps API key from config
    $google_maps_api_key = getGoogleMapsApiKey();

    // Fetch all projects and warehouses for SHIPPING MODAL (with addresses)
    $all_projects_for_shipping = [];
    if ($is_global_admin) {
        $sqlAllProjectsModal = "SELECT id, project_name, street_address, city, state, zip_code FROM projects ORDER BY project_name ASC";
        $resultAllProjectsModal = $conn->query($sqlAllProjectsModal);
        if ($resultAllProjectsModal) {
            while ($row = $resultAllProjectsModal->fetch_assoc()) {
                // Build full address for Google Maps
                $address_parts = array_filter([$row['street_address'], $row['city'], $row['state'], $row['zip_code']]);
                $row['full_address'] = implode(', ', $address_parts);
                $all_projects_for_shipping[] = $row;
            }
        }
    } else if ($account_id_for_user) {
        $sqlAllProjectsModal = "SELECT id, project_name, street_address, city, state, zip_code FROM projects WHERE account_id = ? ORDER BY project_name ASC";
        $stmtAllProjectsModal = $conn->prepare($sqlAllProjectsModal);
        if ($stmtAllProjectsModal) {
            $stmtAllProjectsModal->bind_param("i", $account_id_for_user);
            $stmtAllProjectsModal->execute();
            $resultAllProjectsModal = $stmtAllProjectsModal->get_result();
            if ($resultAllProjectsModal) {
                while ($row = $resultAllProjectsModal->fetch_assoc()) {
                    // Build full address for Google Maps
                    $address_parts = array_filter([$row['street_address'], $row['city'], $row['state'], $row['zip_code']]);
                    $row['full_address'] = implode(', ', $address_parts);
                    $all_projects_for_shipping[] = $row;
                }
            }
            $stmtAllProjectsModal->close();
        }
    }

    $all_warehouses_for_shipping = [];
    $sqlAllWarehousesModal = "SELECT id, name, street_address, city, state, zip_code FROM warehouses ORDER BY name ASC";
    $resultAllWarehousesModal = $conn->query($sqlAllWarehousesModal);
    if ($resultAllWarehousesModal) {
        while ($row = $resultAllWarehousesModal->fetch_assoc()) {
            // Build full address for Google Maps
            $address_parts = array_filter([$row['street_address'], $row['city'], $row['state'], $row['zip_code']]);
            $row['full_address'] = implode(', ', $address_parts);
            $all_warehouses_for_shipping[] = $row;
        }
    }

    // Fetch Manufacturers for origin selection (with addresses)
    $all_manufacturers_for_shipping = [];
    $sqlAllManufacturersModal = "SELECT id, name, street_address, city, state, zip_code FROM manufacturers WHERE is_active = 1 ORDER BY name ASC";
    $resultAllManufacturersModal = $conn->query($sqlAllManufacturersModal);
    if ($resultAllManufacturersModal) {
        while ($row = $resultAllManufacturersModal->fetch_assoc()) {
            // Build full address for Google Maps
            $address_parts = array_filter([$row['street_address'], $row['city'], $row['state'], $row['zip_code']]);
            $row['full_address'] = implode(', ', $address_parts);
            $all_manufacturers_for_shipping[] = $row;
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
        
        /* Red delete button styling */
        .action-button[style*="background-color: #dc3545"]:hover:not(:disabled) {
            background-color: #c82333 !important;
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
            width: 100%; max-width: 600px; border-radius: 8px; position: relative; box-shadow: 0 4px 16px rgba(0,0,0,0.15);
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
        
        /* Origin-Destination Layout Styling */
        #shipModal .origin-destination-section {
            margin: 20px 0;
        }
        
        #shipModal .location-container {
            align-items: flex-start;
            display: flex;
            gap: 20px;
        }
        
        #shipModal .origin-section, #shipModal .destination-section {
            min-width: 0; /* Allow flex items to shrink */
            flex: 1;
        }
        
        #shipModal .distance-separator {
            min-width: 80px;
            text-align: center;
        }
        
        #shipModal .destination-radio-group {
            flex-wrap: wrap;
            display: flex;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            #shipModal .location-container {
                flex-direction: column;
                gap: 15px;
            }
            
            #shipModal .distance-separator {
                flex-direction: row;
                justify-content: center;
                margin-top: 0;
            }
            
            #shipModal .distance-separator > div:first-child {
                transform: rotate(90deg);
                margin-right: 10px;
            }
        }

        .table-responsive { width: 100%; overflow-x: auto; }
        .warning-message { /* For CSV warnings */
            background-color: #fff3cd; border-color: #ffeeba; color: #856404; padding: 10px; 
            margin-bottom: 15px; border: 1px solid; border-radius: 4px;
        }
        .warning-message a { margin-left: 10px; font-weight: bold; color: #856404; }
        
        /* Pagination styles */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 15px 0;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        .pagination-info {
            font-size: 0.9em;
            color: #666;
        }
        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .pagination-controls label {
            font-size: 0.9em;
            margin-right: 5px;
        }
        .pagination-controls input,
        .pagination-controls select {
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }
        .pagination-controls button {
            padding: 5px 10px;
            background-color: #488C9A;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .pagination-controls button:hover {
            background-color: #3A6E7F;
        }
        .pagination-controls button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Add breadcrumb navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <?php if ($is_global_admin): ?>
            <a href="admin_dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <?php else: ?>
            <a href="dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <?php endif; ?>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Manage Pallets</span>
    </div>
    
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

    <!-- CSV Upload Section (Global Admins Only) -->
    <?php if ($is_global_admin): ?>
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
    <?php endif; ?>

    <!-- Main Form for Shipping Pallets -->
    <form id="shipPalletsForm" method="POST" action="manage_pallets.php">
        <input type="hidden" name="action" value="ship_pallets">
        
        <div class="filter-container">
            <div class="filter-group-left">
                <label for="filterInput">Search:</label>
                <input type="text" id="filterInput" onkeyup="filterBySearch()" placeholder="Filter table...">
                <label for="projectFilter">Project:</label>
                <select id="projectFilter" onchange="filterByProject()">
                    <option value="">All Projects</option>
                    <option value="Unassigned">Unassigned</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?php echo htmlspecialchars($proj['project_name']); ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="wattageFilter">Wattage:</label>
                <select id="wattageFilter" onchange="filterByWattage()">
                    <option value="">All</option>
                    <?php
                    // Get unique wattages from pallets for filter dropdown
                    $wattages = array_unique(array_map(function($p) { return $p['wattage']; }, $pallets));
                    sort($wattages);
                    foreach ($wattages as $w) {
                        echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>';
                    }
                    ?>
                </select>
            </div>
            <?php if (!$is_user): ?>
            <div class="filter-group-center">
                <span id="selectedCount" style="font-weight: bold; color: #488C9A;">0 pallets selected</span>
            </div>
            <?php endif; ?>
            <div class="filter-group-right">
                <?php if (!$is_user): ?>
                <button type="button" id="openShipModalBtn" class="action-button" style="padding: 8px 15px; font-size: 0.9em;" disabled>
                    Create Delivery for Selected
                </button>
                <button type="button" id="deletePalletsBtn" class="action-button" style="padding: 8px 15px; font-size: 0.9em; background-color: #dc3545;" disabled>
                    Delete
                </button>
                <?php endif; ?>
                <button type="button" id="exportCsvBtn" class="action-button" style="padding: 8px 15px; font-size: 0.9em;">Export to CSV</button>
            </div>
        </div>

        <div class="pagination-container">
            <div class="pagination-info">
                <span id="paginationInfo">Showing 0 of 0 pallets</span>
            </div>
            <div class="pagination-controls">
                <label for="itemsPerPage">Show:</label>
                <input type="number" id="itemsPerPage" value="100" min="1" max="500" style="width: 80px;">
                <label>pallets per page</label>
                <button type="button" id="prevPage" disabled>Previous</button>
                <span id="pageInfo">Page 1 of 1</span>
                <button type="button" id="nextPage" disabled>Next</button>
            </div>
        </div>

        <div class="table-responsive">
            <table id="palletsTable">
                <thead>
                    <tr>
                        <?php if (!$is_user): ?>
                        <th><input type="checkbox" id="selectAllPallets" title="Select/Deselect all visible pallets"></th>
                        <?php endif; ?>
                        <th>Project</th>
                        <th>Identifier</th>
                        <th>Manufacturer</th>
                        <th>Wattage</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Current Location</th>
                        <th>Associated Deliveries</th>
                        <th>Flash Test Data</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($pallets)): ?>
                        <?php foreach ($pallets as $pallet): ?>
                            <?php
                            // Extract manufacturer name (remove anything after " - ")
                            $manufacturer = $pallet['origin_vendor'] ?? 'N/A';
                            if ($manufacturer !== 'N/A' && strpos($manufacturer, ' - ') !== false) {
                                $manufacturer = trim(explode(' - ', $manufacturer)[0]);
                            }
                            // Handle specific cases where vendor might be a warehouse name instead of manufacturer
                            if (in_array(strtolower($manufacturer), ['phoenix wh', 'phoenix warehouse'])) {
                                $manufacturer = 'Meyer Burger'; // Default to Meyer Burger for warehouse entries
                            }
                            ?>
                            <tr data-id="<?php echo htmlspecialchars($pallet['pallet_id']); ?>">
                                <?php if (!$is_user): ?>
                                <td><input type="checkbox" name="selected_pallets[]" value="<?php echo htmlspecialchars($pallet['pallet_id']); ?>" class="pallet-checkbox"></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($pallet['display_project_name']); ?></td>
                                <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($manufacturer); ?></td>
                                <td><?php echo htmlspecialchars($pallet['wattage']); ?></td>
                                <td><?php echo number_format($pallet['quantity']); ?></td>
                                <td><?php echo htmlspecialchars($pallet['status']); ?></td>
                                <td><?php echo htmlspecialchars($pallet['current_location_display']); ?></td>
                                <td><?php echo $pallet['delivery_association_count']; ?></td>
                                <td>
                                    <?php if (!empty($pallet['flash_test_data'])): ?>
                                        <a href="view_flash_test.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" target="_blank" class="action-button" style="background-color: #5bc0de;">View</a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="pallet_details.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" class="action-button">Pallet Details</a>
                                    <?php if (!$is_user): ?>
                                    <button type="button" class="action-button" onclick="window.location.href='edit_pallet.php?pallet_id=<?php echo $pallet['pallet_id']; ?>'" style="background-color:#f0ad4e;">Edit Pallet</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $is_user ? '10' : '11'; ?>">No pallets found in the system.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="back-link" style="margin-top: 20px;">
            <?php if ($is_global_admin): ?>
                <a href="admin_dashboard.php" class="action-button">&larr; Back to Admin Dashboard</a>
            <?php else: ?>
                <a href="dashboard.php" class="action-button">&larr; Back to Dashboard</a>
            <?php endif; ?>
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
                    <div class="form-row">
                        <div>
                            <label for="freight_cost_single">Freight Cost ($):</label>
                            <input type="number" id="freight_cost_single" name="freight_cost_single" step="0.01" min="0">
                        </div>
                        <div>
                            <label for="customer_cost_single">Customer Cost ($):</label>
                            <input type="number" id="customer_cost_single" name="customer_cost_single" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <!-- Origin and Destination Section -->
                    <div class="origin-destination-section">
                        <div class="location-container" style="display: flex; align-items: flex-start; gap: 20px;">
                            <div class="origin-section" style="flex: 1;">
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Origin:</label>
                                <div id="originDisplay" style="padding: 12px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; min-height: 45px; display: flex; align-items: center;">
                                    <strong id="originLocationText">Select pallets to see origin</strong>
                                </div>
                                <input type="hidden" id="origin_type" name="origin_type" value="">
                                <input type="hidden" id="origin_id" name="origin_id" value="">
                            </div>
                            
                            <div class="distance-separator" style="display: flex; flex-direction: column; justify-content: center; align-items: center; margin-top: 35px;">
                                <div style="font-size: 1.8em; color: #488C9A; margin-bottom: 5px;">→</div>
                                <div id="distanceDisplay" style="text-align: center; font-weight: bold; color: #488C9A; white-space: nowrap; font-size: 0.85em;">
                                    <!-- Distance will be calculated and displayed here -->
                                </div>
                            </div>
                            
                            <div class="destination-section" style="flex: 1;">
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Destination:</label>
                                <div class="destination-radio-group" style="display: flex; gap: 15px; margin-bottom: 10px;">
                                    <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                        <input type="radio" name="destination_type" value="project" checked onchange="toggleDestinationSelectSingle()" style="margin-right: 5px;"> Project
                                    </label>
                                    <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                        <input type="radio" name="destination_type" value="warehouse" onchange="toggleDestinationSelectSingle()" style="margin-right: 5px;"> Warehouse
                                    </label>
                                </div>
                                <div id="destinationSelectContainer">
                                    <select name="destination_id" id="destination_id" required onchange="calculateDistance()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                        <!-- Filled by JS -->
                                    </select>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="miles" name="miles" value="">
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
                    <div class="form-row">
                        <div>
                            <label for="freight_cost_multi">Freight Cost ($):</label>
                            <input type="number" id="freight_cost_multi" name="freight_cost_multi" step="0.01" min="0">
                        </div>
                        <div>
                            <label for="customer_cost_multi">Customer Cost ($):</label>
                            <input type="number" id="customer_cost_multi" name="customer_cost_multi" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <!-- Origin and Destination Section -->
                    <div class="origin-destination-section">
                        <div class="location-container" style="display: flex; align-items: flex-start; gap: 20px;">
                            <div class="origin-section" style="flex: 1;">
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Origin:</label>
                                <div id="originDisplayMulti" style="padding: 12px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; min-height: 45px; display: flex; align-items: center;">
                                    <strong id="originLocationTextMulti">Select pallets to see origin</strong>
                                </div>
                                <input type="hidden" id="origin_type_multi" name="origin_type_multi" value="">
                                <input type="hidden" id="origin_id_multi" name="origin_id_multi" value="">
                            </div>
                            
                            <div class="distance-separator" style="display: flex; flex-direction: column; justify-content: center; align-items: center; margin-top: 35px;">
                                <div style="font-size: 1.8em; color: #488C9A; margin-bottom: 5px;">→</div>
                                <div id="distanceDisplayMulti" style="text-align: center; font-weight: bold; color: #488C9A; white-space: nowrap; font-size: 0.85em;">
                                    <!-- Distance will be calculated and displayed here -->
                                </div>
                            </div>
                            
                            <div class="destination-section" style="flex: 1;">
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Destination:</label>
                                <div class="destination-radio-group" style="display: flex; gap: 15px; margin-bottom: 10px;">
                                    <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                        <input type="radio" name="destination_type_multi" value="project" checked onchange="toggleDestinationSelectMulti()" style="margin-right: 5px;"> Project
                                    </label>
                                    <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                        <input type="radio" name="destination_type_multi" value="warehouse" onchange="toggleDestinationSelectMulti()" style="margin-right: 5px;"> Warehouse
                                    </label>
                                </div>
                                <div id="destinationSelectContainerMulti">
                                    <select name="destination_id_multi" id="destination_id_multi" required onchange="calculateDistanceMulti()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                        <!-- Filled by JS -->
                                    </select>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="miles_multi" name="miles_multi" value="">
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
    const manufacturersData = <?php echo json_encode($all_manufacturers_for_shipping); ?>;
    const palletsData = <?php echo json_encode($pallets); ?>;
    const isUser = <?php echo $is_user ? 'true' : 'false'; ?>;
    const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
    const isGlobalAdmin = <?php echo $is_global_admin ? 'true' : 'false'; ?>;
</script>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places"></script>

<script>
// Combined filter for project, wattage, and general search
function filterTable() {
    // Reset to page 1 when filter changes
    currentPage = 1;
    updatePagination();
}

// Add individual filter functions that call the main filter
function filterBySearch() {
    filterTable();
}

function filterByProject() {
    filterTable();
}

function filterByWattage() {
    filterTable();
}

// ----------------- PAGINATION -----------------
let currentPage = 1;
let itemsPerPage = 100;
let allPalletRows = [];

function initializePagination() {
    const table = document.getElementById('palletsTable');
    if (!table) return;
    
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    
    allPalletRows = Array.from(tbody.querySelectorAll('tr'));
    
    const itemsPerPageInput = document.getElementById('itemsPerPage');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');
    
    if (itemsPerPageInput) {
        itemsPerPageInput.addEventListener('change', function() {
            itemsPerPage = Math.min(Math.max(1, parseInt(this.value) || 100), 500);
            this.value = itemsPerPage;
            currentPage = 1;
            updatePagination();
        });
    }
    
    if (prevButton) {
        prevButton.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updatePagination();
            }
        });
    }
    
    if (nextButton) {
        nextButton.addEventListener('click', function() {
            const maxPages = Math.ceil(getFilteredRows().length / itemsPerPage);
            if (currentPage < maxPages) {
                currentPage++;
                updatePagination();
            }
        });
    }
    
    updatePagination();
}

function getFilteredRows() {
    return allPalletRows.filter(row => {
        // Check if row matches current filters (not just display style)
        if (!row || !row.cells) return false;
        
        const input = document.getElementById("filterInput")?.value.toUpperCase() || '';
        const projectFilterValue = document.getElementById("projectFilter")?.value || '';
        const wattageFilterValue = document.getElementById("wattageFilter")?.value || '';
        
        // Adjust column indices based on user role (users don't have checkbox column)
        const projectColumnIndex = isUser ? 0 : 1;
        const wattageColumnIndex = isUser ? 3 : 4;
        
        // Check project filter
        const projectCell = row.cells[projectColumnIndex];
        let matchesProject = false;
        if (projectFilterValue === "") { 
            matchesProject = true;
        } else if (projectFilterValue === "Unassigned") {
            matchesProject = projectCell && (projectCell.textContent || projectCell.innerText) === "Unassigned";
        } else {
            matchesProject = projectCell && (projectCell.textContent || projectCell.innerText) === projectFilterValue;
        }
        
        // Check wattage filter
        const wattageCell = row.cells[wattageColumnIndex];
        let matchesWattage = false;
        if (wattageFilterValue === "") {
            matchesWattage = true;
        } else {
            const cellWattage = wattageCell ? (wattageCell.textContent || wattageCell.innerText).trim() : '';
            matchesWattage = cellWattage === wattageFilterValue;
        }
        
        // Check search filter
        let matchesSearch = false;
        if (input === "") {
            matchesSearch = true; 
        } else {
            const startColumn = isUser ? 0 : 1; // Skip checkbox column for admins
            for (let j = startColumn; j < row.cells.length - 1; j++) { 
                if (row.cells[j]) {
                    const txtValue = row.cells[j].textContent || row.cells[j].innerText;
                    if (txtValue.toUpperCase().indexOf(input) > -1) {
                        matchesSearch = true;
                        break;
                    }
                }
            }
        }
        
        return matchesProject && matchesWattage && matchesSearch;
    });
}

function updatePagination() {
    const filteredRows = getFilteredRows();
    const totalItems = filteredRows.length;
    const maxPages = Math.ceil(totalItems / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    
    // Hide all rows first
    allPalletRows.forEach(row => {
        row.style.display = 'none';
    });
    
    // Show only current page rows from filtered set
    filteredRows.slice(startIndex, endIndex).forEach(row => {
        row.style.display = '';
    });
    
    // Update pagination info
    const paginationInfo = document.getElementById('paginationInfo');
    const pageInfo = document.getElementById('pageInfo');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');
    
    if (paginationInfo) {
        const showing = Math.min(endIndex, totalItems);
        const displayStart = totalItems > 0 ? startIndex + 1 : 0;
        paginationInfo.textContent = `Showing ${displayStart}-${showing} of ${totalItems} pallets`;
    }
    
    if (pageInfo) {
        pageInfo.textContent = `Page ${Math.max(1, currentPage)} of ${Math.max(1, maxPages)}`;
    }
    
    if (prevButton) {
        prevButton.disabled = currentPage <= 1;
    }
    
    if (nextButton) {
        nextButton.disabled = currentPage >= maxPages || totalItems === 0;
    }
    
    // Update selection counts after pagination
    if (!isUser) {
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
}

// CSV Instructions Modal (moved inside DOMContentLoaded)
function initializeCsvModal() {
    const openCsvBtn = document.getElementById('openCsvInstructions');
    if (openCsvBtn) {
        openCsvBtn.addEventListener('click', function() {
            document.getElementById('csvInstructionsModal').style.display = 'block';
        });
    }
    
    window.addEventListener('click', function(event) {
        var csvModal = document.getElementById('csvInstructionsModal');
        if (event.target == csvModal) {
            csvModal.style.display = 'none';
        }
    });
}

// Export to CSV (moved inside DOMContentLoaded)
function initializeExportCsv() {
    const exportBtn = document.getElementById('exportCsvBtn');
    if (!exportBtn) return;
    
    exportBtn.addEventListener('click', function() {
    var table = document.getElementById('palletsTable');
    var rows = table.querySelectorAll('tbody tr');
    var csvData = [];

                var headers = [
                "id", "pallet_identifier", "wattage", "quantity", "status", 
                "flash_test_data", "Project", "Manufacturer", "Current Location", 
                "Associated Deliveries"
            ];
    csvData.push(headers.map(header => '"' + header.replace(/"/g, '""') + '"').join(','));

    rows.forEach(function(row) {
        if (row.style.display !== 'none') { 
            var cells = row.querySelectorAll('td');
            var rowData = [];
            
            const columnIndices = {
                id: row.getAttribute('data-id'), 
                Project: isUser ? 0 : 1,
                pallet_identifier: isUser ? 1 : 2,
                Manufacturer: isUser ? 2 : 3,
                wattage: isUser ? 3 : 4,
                quantity: isUser ? 4 : 5,
                status: isUser ? 5 : 6,
                Current_Location: isUser ? 6 : 7,
                Associated_Deliveries: isUser ? 7 : 8,
                flash_test_data: isUser ? 8 : 9 
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
}


// --- Pallet Selection and Shipping Modal JS (Adapted from module_overview.php) ---
function toggleAllPalletCheckboxes(isChecked) {
    if (isUser) return; // Users don't have checkboxes
    
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
    if (isUser) return; // Users don't have these buttons
    
    const openBtn = document.getElementById('openShipModalBtn');
    const deleteBtn = document.getElementById('deletePalletsBtn');
    const checkedCount = document.querySelectorAll('#palletsTable .pallet-checkbox:checked').length;
    
    if (openBtn) openBtn.disabled = (checkedCount === 0);
    if (deleteBtn) deleteBtn.disabled = (checkedCount === 0);
}

function updateSelectedCount() {
    if (isUser) return; // Users don't have selection functionality
    
    let count = 0;
    document.querySelectorAll('#palletsTable .pallet-checkbox').forEach(function(checkbox) {
        if (checkbox.checked) {
            count++;
        }
    });
    
    const countEl = document.getElementById('selectedCount');
    if (countEl) {
        countEl.textContent = count + ' pallet' + (count === 1 ? '' : 's') + ' selected';
    }
    
    // Update button states
    updateOpenShipModalButtonState();
    
    if (typeof updateMultiShipSummary === 'function') {
        updateMultiShipSummary();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (!isUser) {
        const selectAllPalletsCheckbox = document.getElementById('selectAllPallets');
        if (selectAllPalletsCheckbox) {
            selectAllPalletsCheckbox.addEventListener('change', function() {
                toggleAllPalletCheckboxes(this.checked);
                updateOriginDisplay();
                updateMultiShipSummary();
            });
        }

        // Use event delegation for checkbox changes to handle dynamically shown/hidden rows
        document.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('pallet-checkbox')) {
                updateSelectedCount();
                updateOriginDisplay();
                updateMultiShipSummary();
                if (!e.target.checked && selectAllPalletsCheckbox) {
                    selectAllPalletsCheckbox.checked = false;
                } else if (e.target.checked) {
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
            }
        });

        updateOpenShipModalButtonState();
        updateSelectedCount();
    }
    
    // Initialize pagination
    initializePagination();
    
    // Initialize CSV modal and export functionality
    initializeCsvModal();
    initializeExportCsv();
    
    // Initialize filters and counts
    if (!isUser) {
        updateSelectedCount();
        updateOpenShipModalButtonState();
    }
    
    // Initialize shipping modal functions
    initializeShippingModal();
    initializeModalHandlers();
    initializeShipmentButtons();
    
    // Update origin display when pallets are selected
    if (!isUser) {
        updateOriginDisplay();
    }

    // Delete pallets functionality (admins only)
    if (!isUser) {
        const deletePalletsBtn = document.getElementById('deletePalletsBtn');
        if (deletePalletsBtn) {
            deletePalletsBtn.addEventListener('click', function() {
                const checkedPallets = document.querySelectorAll('#palletsTable .pallet-checkbox:checked');
                if (checkedPallets.length === 0) {
                    alert('Please select pallets to delete.');
                    return;
                }
                
                const confirmation = confirm(`Are you sure you want to delete ${checkedPallets.length} selected pallet(s)? This action cannot be undone.`);
                if (!confirmation) return;
                
                // Create a form and submit it
                const form = document.getElementById('shipPalletsForm');
                const actionInput = form.querySelector('input[name="action"]');
                if (actionInput) {
                    actionInput.value = 'delete_pallets';
                } else {
                    const newActionInput = document.createElement('input');
                    newActionInput.type = 'hidden';
                    newActionInput.name = 'action';
                    newActionInput.value = 'delete_pallets';
                    form.appendChild(newActionInput);
                }
                
                form.submit();
            });
        }
    }
});

// Shipment Modal Logic (moved to function)
function initializeShippingModal() {
    if (isUser) return; // Users don't have shipping functionality
    
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
}

// ---------- DISTANCE CALCULATION WITH GOOGLE MAPS ----------
let directionsService;
let initialized = false;

function initializeGoogleMaps() {
    if (isUser) return;
    if (initialized || !window.google) return;
    directionsService = new google.maps.DirectionsService();
    initialized = true;
}

function calculateDistanceFromAddresses(originAddress, destinationAddress, callback) {
    if (isUser) return;
    if (!initialized) {
        initializeGoogleMaps();
    }
    
    if (!directionsService || !originAddress || !destinationAddress) {
        callback(null, 'Missing address information');
        return;
    }

    if (originAddress.toLowerCase() === destinationAddress.toLowerCase()) {
        callback(null, 'Origin and destination cannot be the same');
        return;
    }

    const request = {
        origin: originAddress,
        destination: destinationAddress,
        travelMode: 'DRIVING'
    };

    directionsService.route(request, function(result, status) {
        if (status === 'OK') {
            const distanceInMeters = result.routes[0].legs[0].distance.value;
            const distanceInMiles = (distanceInMeters / 1609.34).toFixed(2);
            callback(distanceInMiles, null);
        } else {
            callback(null, 'Could not calculate distance');
        }
    });
}

// ---------- ORIGIN DETERMINATION FROM PALLETS ----------
function getSelectedPallets() {
    if (isUser) return [];
    const selectedCheckboxes = document.querySelectorAll('#palletsTable .pallet-checkbox:checked');
    const selectedPallets = [];
    
    selectedCheckboxes.forEach(checkbox => {
        const palletId = parseInt(checkbox.value);
        const pallet = palletsData.find(p => p.pallet_id === palletId);
        if (pallet) {
            selectedPallets.push(pallet);
        }
    });
    
    return selectedPallets;
}

function determineOriginFromSelectedPallets() {
    if (isUser) return { success: false, message: 'User role cannot create deliveries' };
    
    const selectedPallets = getSelectedPallets();
    
    if (selectedPallets.length === 0) {
        return {
            success: false,
            message: 'No pallets selected'
        };
    }

    // Group pallets by their origin
    const origins = {};
    
    selectedPallets.forEach(pallet => {
        let originKey;
        if (pallet.status === 'At Manufacturer') {
            originKey = `manufacturer_${pallet.origin_vendor}`;
        } else if (pallet.status === 'In Warehouse' && pallet.current_warehouse_name) {
            originKey = `warehouse_${pallet.current_warehouse_id}`;
        } else if (pallet.status === 'Delivered to Project' && pallet.current_project_name) {
            originKey = `project_${pallet.current_project_id}`;
        } else {
            originKey = 'unknown';
        }
        
        if (!origins[originKey]) {
            origins[originKey] = [];
        }
        origins[originKey].push(pallet);
    });

    const originKeys = Object.keys(origins);
    
    if (originKeys.length > 1) {
        return {
            success: false,
            message: 'Selected pallets must all have the same origin location'
        };
    }
    
    if (originKeys.length === 0 || originKeys[0] === 'unknown') {
        return {
            success: false,
            message: 'Cannot determine origin for selected pallets'
        };
    }

    const originKey = originKeys[0];
    const firstPallet = origins[originKey][0];
    
    // Determine origin details
    let originInfo = {};
    
    if (firstPallet.status === 'At Manufacturer') {
        // Find manufacturer by vendor name
        const manufacturer = manufacturersData.find(m => m.name === firstPallet.origin_vendor);
        if (manufacturer) {
            originInfo = {
                type: 'manufacturer',
                id: manufacturer.id,
                name: manufacturer.name,
                address: manufacturer.full_address,
                displayText: `Manufacturer: ${manufacturer.name}`
            };
        } else {
            return {
                success: false,
                message: `Manufacturer "${firstPallet.origin_vendor}" not found in system`
            };
        }
    } else if (firstPallet.status === 'In Warehouse') {
        originInfo = {
            type: 'warehouse',
            id: firstPallet.current_warehouse_id,
            name: firstPallet.current_warehouse_name,
            address: firstPallet.warehouse_full_address,
            displayText: `Warehouse: ${firstPallet.current_warehouse_name}`
        };
    } else if (firstPallet.status === 'Delivered to Project') {
        originInfo = {
            type: 'project',
            id: firstPallet.current_project_id,
            name: firstPallet.current_project_name,
            address: firstPallet.project_full_address,
            displayText: `Project: ${firstPallet.current_project_name}`
        };
    }

    return {
        success: true,
        origin: originInfo
    };
}

function updateOriginDisplay() {
    if (isUser) return;
    const result = determineOriginFromSelectedPallets();
    
    // Update single shipment display
    const originText = document.getElementById('originLocationText');
    const originType = document.getElementById('origin_type');
    const originId = document.getElementById('origin_id');
    
    // Update multi shipment display
    const originTextMulti = document.getElementById('originLocationTextMulti');
    const originTypeMulti = document.getElementById('origin_type_multi');
    const originIdMulti = document.getElementById('origin_id_multi');
    
    if (result.success) {
        const displayText = result.origin.displayText;
        
        if (originText) originText.textContent = displayText;
        if (originTextMulti) originTextMulti.textContent = displayText;
        
        if (originType) originType.value = result.origin.type;
        if (originId) originId.value = result.origin.id;
        if (originTypeMulti) originTypeMulti.value = result.origin.type;
        if (originIdMulti) originIdMulti.value = result.origin.id;
        
        // Calculate distance
        calculateDistance();
        calculateDistanceMulti();
    } else {
        const errorText = result.message;
        
        if (originText) originText.textContent = errorText;
        if (originTextMulti) originTextMulti.textContent = errorText;
        
        if (originType) originType.value = '';
        if (originId) originId.value = '';
        if (originTypeMulti) originTypeMulti.value = '';
        if (originIdMulti) originIdMulti.value = '';
        
        // Clear distance display
        const distanceDisplay = document.getElementById('distanceDisplay');
        const distanceDisplayMulti = document.getElementById('distanceDisplayMulti');
        if (distanceDisplay) distanceDisplay.innerHTML = '';
        if (distanceDisplayMulti) distanceDisplayMulti.innerHTML = '';
    }
}

// ---------- DESTINATION SELECTION FUNCTIONS ----------
function populateDropdown(selectElement, type, dataSource, nameField, placeholderPrefix) {
    if (isUser) return;
    if (!selectElement) return;
    
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
            opt.setAttribute('data-address', item.full_address || '');
            selectElement.appendChild(opt);
        });
    }
}

function getAddressFromSelection(selectElement, type) {
    if (isUser) return '';
    if (!selectElement || !selectElement.value) return '';
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    return selectedOption ? selectedOption.getAttribute('data-address') : '';
}

function toggleDestinationSelectSingle() {
    if (isUser) return;
    const destType = document.querySelector('input[name="destination_type"]:checked').value;
    const destSelect = document.getElementById('destination_id');
    
    const data = (destType === 'project') ? projectsData : warehousesData;
    const nameField = (destType === 'project') ? 'project_name' : 'name';
    const placeholder = (destType === 'project') ? 'Project' : 'Warehouse';

    populateDropdown(destSelect, destType, data, nameField, placeholder);
    calculateDistance();
}

function toggleDestinationSelectMulti() {
    if (isUser) return;
    const destType = document.querySelector('input[name="destination_type_multi"]:checked').value;
    const destSelect = document.getElementById('destination_id_multi');
    
    const data = (destType === 'project') ? projectsData : warehousesData;
    const nameField = (destType === 'project') ? 'project_name' : 'name';
    const placeholder = (destType === 'project') ? 'Project' : 'Warehouse';

    populateDropdown(destSelect, destType, data, nameField, placeholder);
    calculateDistanceMulti();
}

// ---------- DISTANCE CALCULATION FUNCTIONS ----------
function calculateDistance() {
    if (isUser) return;
    const destSelect = document.getElementById('destination_id');
    const distanceDisplay = document.getElementById('distanceDisplay');
    const milesInput = document.getElementById('miles');

    if (!destSelect || !distanceDisplay) return;

    const result = determineOriginFromSelectedPallets();
    if (!result.success) {
        distanceDisplay.innerHTML = '';
        milesInput.value = '';
        return;
    }

    const originAddress = result.origin.address;
    const destAddress = getAddressFromSelection(destSelect, 'destination');

    if (!originAddress || !destAddress) {
        distanceDisplay.innerHTML = '';
        milesInput.value = '';
        return;
    }

    distanceDisplay.innerHTML = '<span style="color: #666;">Calculating distance...</span>';

    calculateDistanceFromAddresses(originAddress, destAddress, function(distance, error) {
        if (error) {
            if (error.includes('cannot be the same')) {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">⚠️ Same location</span>';
            } else {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">Error</span>';
            }
            milesInput.value = '';
        } else {
            distanceDisplay.innerHTML = `${distance} miles`;
            milesInput.value = distance;
        }
    });
}

function calculateDistanceMulti() {
    if (isUser) return;
    const destSelect = document.getElementById('destination_id_multi');
    const distanceDisplay = document.getElementById('distanceDisplayMulti');
    const milesInput = document.getElementById('miles_multi');

    if (!destSelect || !distanceDisplay) return;

    const result = determineOriginFromSelectedPallets();
    if (!result.success) {
        distanceDisplay.innerHTML = '';
        milesInput.value = '';
        return;
    }

    const originAddress = result.origin.address;
    const destAddress = getAddressFromSelection(destSelect, 'destination');

    if (!originAddress || !destAddress) {
        distanceDisplay.innerHTML = '';
        milesInput.value = '';
        return;
    }

    distanceDisplay.innerHTML = '<span style="color: #666;">Calculating distance...</span>';

    calculateDistanceFromAddresses(originAddress, destAddress, function(distance, error) {
        if (error) {
            if (error.includes('cannot be the same')) {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">⚠️ Same location</span>';
            } else {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">Error</span>';
            }
            milesInput.value = '';
        } else {
            distanceDisplay.innerHTML = `${distance} miles`;
            milesInput.value = distance;
        }
    });
}

// ---------- FORM SUBMISSION FUNCTIONS ----------
function setOrCreateHidden(form, fieldName, fieldValue) {
    if (isUser) return;
    if (!form) return;
    let el = form.querySelector(`input[name="${fieldName}"]`);
    if (!el) {
        el = document.createElement('input');
        el.type = 'hidden';
        el.name = fieldName;
        form.appendChild(el);
    }
    el.value = fieldValue;
}

function initializeShipmentButtons() {
    if (isUser) return;
    
    const confirmShipmentBtn = document.getElementById('confirmShipmentBtn');
    const confirmMultiShipmentBtn = document.getElementById('confirmMultiShipmentBtn');
    const mainShipForm = document.getElementById('shipPalletsForm');

    if (confirmShipmentBtn) {
        confirmShipmentBtn.addEventListener('click', function() {
            if (!mainShipForm) return;

            // Single shipment mode
            let shipmentModeInput = mainShipForm.querySelector('input[name="shipment_mode"]');
            if (!shipmentModeInput) {
                shipmentModeInput = document.createElement('input');
                shipmentModeInput.type = 'hidden';
                shipmentModeInput.name = 'shipment_mode';
                mainShipForm.appendChild(shipmentModeInput);
            }
            shipmentModeInput.value = 'single';

            // Pallets per truck (not used in single, so set to 1)
            let pTruckInput = mainShipForm.querySelector('input[name="pallets_per_truck"]');
            if (!pTruckInput) {
                pTruckInput = document.createElement('input');
                pTruckInput.type = 'hidden';
                pTruckInput.name = 'pallets_per_truck';
                mainShipForm.appendChild(pTruckInput);
            }
            pTruckInput.value = '1';

            // Validate origin can be determined
            const originResult = determineOriginFromSelectedPallets();
            if (!originResult.success) {
                alert('Error: ' + originResult.message);
                return;
            }

            // Get single form values
            const originType = document.getElementById('origin_type').value;
            const originId = document.getElementById('origin_id').value;
            const destinationType = document.querySelector('input[name="destination_type"]:checked').value;
            const destinationId = document.getElementById('destination_id').value;
            const bol = document.getElementById('bol_number_single_modal').value;
            const departure = document.getElementById('departure_date_single_modal').value;
            const arrival = document.getElementById('est_arrival_date_single_modal').value;
            const freightCost = document.getElementById('freight_cost_single').value;
            const customerCost = document.getElementById('customer_cost_single').value;
            const miles = document.getElementById('miles').value;

            if (!originId) {
                alert('Please select pallets to determine origin location.');
                return;
            }
            if (!destinationId) {
                alert('Please select a destination location.');
                return;
            }

            // Check if origin and destination are the same
            if (originType === destinationType && originId === destinationId) {
                alert('Origin and destination cannot be the same location.');
                return;
            }

            // Populate hidden inputs
            setOrCreateHidden(mainShipForm, 'origin_type', originType);
            setOrCreateHidden(mainShipForm, 'origin_id', originId);
            setOrCreateHidden(mainShipForm, 'destination_type', destinationType);
            setOrCreateHidden(mainShipForm, 'destination_id', destinationId);
            setOrCreateHidden(mainShipForm, 'bol_number', bol);
            setOrCreateHidden(mainShipForm, 'departure_date', departure);
            setOrCreateHidden(mainShipForm, 'est_arrival_date', arrival);
            setOrCreateHidden(mainShipForm, 'freight_cost', freightCost);
            setOrCreateHidden(mainShipForm, 'accessorial_cost', '0'); // Default to 0 since field is removed
            setOrCreateHidden(mainShipForm, 'customer_cost', customerCost);
            setOrCreateHidden(mainShipForm, 'miles', miles);

            mainShipForm.submit();
        });
    }

    if (confirmMultiShipmentBtn) {
        confirmMultiShipmentBtn.addEventListener('click', function() {
            if (!mainShipForm) return;

            // Multi shipment mode
            let shipmentModeInput = mainShipForm.querySelector('input[name="shipment_mode"]');
            if (!shipmentModeInput) {
                shipmentModeInput = document.createElement('input');
                shipmentModeInput.type = 'hidden';
                shipmentModeInput.name = 'shipment_mode';
                mainShipForm.appendChild(shipmentModeInput);
            }
            shipmentModeInput.value = 'multi';

            // Pallets per truck
            let perTruckInput = mainShipForm.querySelector('input[name="pallets_per_truck"]');
            if (!perTruckInput) {
                perTruckInput = document.createElement('input');
                perTruckInput.type = 'hidden';
                perTruckInput.name = 'pallets_per_truck';
                mainShipForm.appendChild(perTruckInput);
            }
            perTruckInput.value = document.getElementById('palletsPerTruck_modal').value || '1';

            // Validate origin can be determined
            const originResult = determineOriginFromSelectedPallets();
            if (!originResult.success) {
                alert('Error: ' + originResult.message);
                return;
            }

            // Get multi form values
            const originType = document.getElementById('origin_type_multi').value;
            const originId = document.getElementById('origin_id_multi').value;
            const destinationType = document.querySelector('input[name="destination_type_multi"]:checked').value;
            const destinationId = document.getElementById('destination_id_multi').value;
            const bol = document.getElementById('bol_number_multi_modal').value;
            const departure = document.getElementById('departure_date_multi_modal').value;
            const arrival = document.getElementById('est_arrival_date_multi_modal').value;
            const freightCost = document.getElementById('freight_cost_multi').value;
            const customerCost = document.getElementById('customer_cost_multi').value;
            const miles = document.getElementById('miles_multi').value;

            if (!originId) {
                alert('Please select pallets to determine origin location.');
                return;
            }
            if (!destinationId) {
                alert('Please select a destination location.');
                return;
            }

            // Check if origin and destination are the same
            if (originType === destinationType && originId === destinationId) {
                alert('Origin and destination cannot be the same location.');
                return;
            }

            // Populate hidden inputs
            setOrCreateHidden(mainShipForm, 'origin_type', originType);
            setOrCreateHidden(mainShipForm, 'origin_id', originId);
            setOrCreateHidden(mainShipForm, 'destination_type', destinationType);
            setOrCreateHidden(mainShipForm, 'destination_id', destinationId);
            setOrCreateHidden(mainShipForm, 'bol_number', bol);
            setOrCreateHidden(mainShipForm, 'departure_date', departure);
            setOrCreateHidden(mainShipForm, 'est_arrival_date', arrival);
            setOrCreateHidden(mainShipForm, 'freight_cost', freightCost);
            setOrCreateHidden(mainShipForm, 'accessorial_cost', '0'); // Default to 0 since field is removed
            setOrCreateHidden(mainShipForm, 'customer_cost', customerCost);
            setOrCreateHidden(mainShipForm, 'miles', miles);

            mainShipForm.submit();
        });
    }
}

// ---------- MULTI-SHIPMENT SUMMARY ----------
function updateMultiShipSummary() {
    if (isUser) return;
    const palletsPerTruckInput = document.getElementById('palletsPerTruck_modal');
    const multiShipSummary = document.getElementById('multiShipSummary');
    
    if (!palletsPerTruckInput || !multiShipSummary) return;
    const selected = document.querySelectorAll('#palletsTable .pallet-checkbox:checked').length;
    const perTruck = parseInt(palletsPerTruckInput.value, 10) || 1;
    const numDeliveries = Math.ceil(selected / perTruck);
    multiShipSummary.textContent = selected > 0
        ? (numDeliveries + ' deliveries will be created (' + perTruck + ' pallets per truck)')
        : '';
}

// ---------- INITIALIZE FUNCTIONS ----------
function initializeDestinationToggles() {
    if (isUser) return;
    // Default load the destination dropdowns
    toggleDestinationSelectSingle();
    toggleDestinationSelectMulti();
}

function initializeModalHandlers() {
    if (isUser) return;
    
    // Initialize Google Maps when DOM is ready
    if (window.google) {
        initializeGoogleMaps();
    } else {
        // Wait for Google Maps to load
        window.addEventListener('load', initializeGoogleMaps);
    }
    
    // Initialize destination toggles
    initializeDestinationToggles();
    
    // Initialize origin display
    updateOriginDisplay();
    
    // Multi-shipment summary
    const palletsPerTruckInput = document.getElementById('palletsPerTruck_modal');
    if (palletsPerTruckInput) {
        palletsPerTruckInput.addEventListener('input', updateMultiShipSummary);
        updateMultiShipSummary();
    }
}
</script>
</body>
</html> 