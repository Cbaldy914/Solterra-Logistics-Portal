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

// Optional project context from deep-link (e.g. Project Overview "View Pallets")
$project_id_from_url = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

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
    // Optional filter from deep link: restrict to specific pallet IDs
    $pallet_ids_filter = [];
    if (!empty($_GET['pallet_ids'])) {
        $raw_ids = explode(',', $_GET['pallet_ids']);
        foreach ($raw_ids as $rid) {
            $ival = intval($rid);
            if ($ival > 0) { $pallet_ids_filter[] = $ival; }
        }
        $pallet_ids_filter = array_values(array_unique($pallet_ids_filter));
    }
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
                GROUP_CONCAT(DISTINCT CONCAT(d.id, ':', COALESCE(d.bol_number, 'No BOL')) ORDER BY d.id SEPARATOR '|') as delivery_info
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
            LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
            LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
            LEFT JOIN deliveries d ON dp.delivery_id = d.id";

    // Build conditional filters dynamically
    $conditions = [];
    $params = [];
    $types = '';

    // Account scoping for non-global admins
    if (!$is_global_admin && $account_id_for_user) {
        $conditions[] = "(p_current.account_id = ? OR p_assigned.account_id = ? OR (ip.current_project_id IS NULL AND ip.assigned_project_id IS NULL))";
        $params[] = $account_id_for_user;
        $params[] = $account_id_for_user;
        $types .= 'ii';
    }

    // Do not restrict dataset to a single project so users can switch projects in the UI filter

    // Optional deep-link filter to specific pallet IDs
    if (!empty($pallet_ids_filter)) {
        $placeholders = implode(',', array_fill(0, count($pallet_ids_filter), '?'));
        $conditions[] = "ip.id IN ($placeholders)";
        foreach ($pallet_ids_filter as $pid) { $params[] = $pid; $types .= 'i'; }
    }

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= " GROUP BY ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status, ip.arrival_date, ip.unassigned_module_item_id, ip.current_warehouse_id, ip.current_project_id, ip.assigned_project_id, ip.flash_test_data, m.vendor_name, w.name, w.street_address, w.city, w.state, w.zip_code, p_current.project_name, p_current.street_address, p_current.city, p_current.state, p_current.zip_code, p_assigned.project_name";
    $sql .= " ORDER BY ip.id DESC";

    // Execute query (prepared if we have params)
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) { throw new Exception("Error preparing pallets query: " . $conn->error); }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) { $pallets[] = $row; }
        } else {
            throw new Exception("Error fetching pallets: " . $stmt->error);
        }
        $stmt->close();
    } else {
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) { $pallets[] = $row; }
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

    // Fetch Manufacturers for origin selection (with addresses from primary locations)
    $all_manufacturers_for_shipping = [];
    $sqlAllManufacturersModal = "
        SELECT 
            m.id, 
            m.name, 
            ml.street_address, 
            ml.city, 
            ml.state, 
            ml.zip_code 
        FROM manufacturers m
        LEFT JOIN manufacturer_locations ml ON m.id = ml.manufacturer_id AND ml.is_primary = TRUE
        WHERE m.is_active = 1 
        ORDER BY m.name ASC";
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



        .table-responsive { width: 100%; overflow-x: auto; }
        #palletsTable { margin-top: 0; border-collapse: collapse; width: 100%; }
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
        
        /* Filter dropdown styles */
        .filter-dropdown {
            position: relative;
            display: inline-block;
        }
        .filter-toggle-btn {
            background-color: #488C9A;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-toggle-btn:hover {
            background-color: #3A6E7F;
        }
        .filter-arrow {
            transition: transform 0.3s ease;
        }
        .filter-toggle-btn.active .filter-arrow {
            transform: rotate(180deg);
        }
        .filter-content {
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 600px;
            z-index: 1000;
            background-color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .filters-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
<?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Manage Pallets']); ?>
    <style>
        .delivery-tracker-header { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 24px; padding: 32px; margin: 16px 0 20px 0; box-shadow: 0 8px 32px rgba(0,0,0,0.06); border: 1px solid rgba(72,140,154,0.08); position: relative; overflow: hidden; }
        .delivery-tracker-header::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
        .header-content { display:flex; align-items:center; justify-content: space-between; flex-wrap:wrap; gap:24px; }
        .header-info h1 { font-size: 2.2em; font-weight: 700; background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0 0 6px 0; line-height: 1.2; }
        .header-subtitle { color:#6c757d; font-size:1.05em; font-weight:500; margin:0; }
    </style>
    <div class="delivery-tracker-header">
        <div class="header-content">
            <div class="header-info">
                <h1>Manage All Pallets</h1>
                <p class="header-subtitle">View and manage all pallets</p>
            </div>
        </div>
    </div>
    
    <!-- Unified Filter Section (styled like view_project) -->
    <style>
        .filter-section {background: linear-gradient(135deg,#ffffff 0%,#f8f9fa 100%); border-radius:20px; padding:24px; margin:16px 0; box-shadow:0 8px 32px rgba(0,0,0,.06); border:1px solid rgba(72,140,154,.08);} 
        .filter-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;}
        .filter-title{font-size:1.2em;font-weight:600;color:#293E4C;margin:0;display:flex;align-items:center;gap:10px}
        .filter-title i{color:#488C9A}
        .filter-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .btn-clear,.btn-apply{padding:10px 16px;border-radius:10px;font-size:.9em;font-weight:600;cursor:pointer;border:none;display:flex;align-items:center;gap:8px}
        .btn-clear{background:linear-gradient(135deg,rgba(239,68,68,.1) 0%,rgba(220,38,38,.15) 100%);color:#dc2626;border:1px solid rgba(239,68,68,.2)}
        .btn-apply{background:linear-gradient(135deg,#488C9A 0%,#3A6E7F 100%);color:#fff}
        .filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px}
        .filter-group{display:flex;flex-direction:column}
        .filter-label{font-weight:600;color:#293E4C;font-size:.95em;margin-bottom:6px}
        .filter-select,.filter-input{width:100%;padding:10px 12px;border:2px solid rgba(72,140,154,.15);border-radius:10px;background:#fff;font-size:.95em;box-sizing:border-box}
        .deliveries-container{background:linear-gradient(135deg,#ffffff 0%,#f8f9fa 100%);border-radius:20px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.06);border:1px solid rgba(72,140,154,.08);margin-top: 12px}
        .table-header{background:linear-gradient(135deg,#488C9A 0%,#3A6E7F 100%);color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
        .table-title{font-size:1.2em;font-weight:600;margin:0;display:flex;align-items:center;gap:10px;color:#fff}
        .table-header-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .btn-export-header{background:rgba(255,255,255,.95);color:#16a34a;border:none;box-shadow:0 2px 8px rgba(0,0,0,.15);cursor:pointer;padding:8px 14px;border-radius:10px;font-size:.85em;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:all .2s ease}
        .action-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;font-size:.85em;font-weight:600;text-decoration:none;border:none;cursor:pointer;white-space:nowrap;transition:all .2s ease}
        .action-btn-danger{background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%);color:#fff;box-shadow:0 2px 8px rgba(239,68,68,.25)}
        .action-btn-danger:hover:not([disabled]){background:linear-gradient(135deg,#dc2626 0%,#b91c1c 100%);transform:translateY(-2px);box-shadow:0 4px 12px rgba(239,68,68,.35)}
        .action-btn:hover:not([disabled]){transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
        .btn-export-header:hover{background:#fff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.2)}
        .action-btn[disabled]{opacity:.5;cursor:not-allowed;filter:grayscale(20%);box-shadow:none}
    </style>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <div class="filter-section">
        <div class="filter-header">
            <h2 class="filter-title"><i class="fas fa-filter"></i> Filter Pallets</h2>
            <div class="filter-actions">
                <button type="button" class="btn-clear" onclick="clearMpFilters()"><i class="fas fa-times"></i> Clear</button>
                <button type="button" class="btn-apply" onclick="filterTable()"><i class="fas fa-search"></i> Apply Filters</button>
            </div>
        </div>
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label" for="mp_search">Search</label>
                <input type="text" id="mp_search" class="filter-input" placeholder="Search pallets..." onkeyup="filterTable()">
            </div>
            <div class="filter-group">
                <label class="filter-label" for="mp_project">Project</label>
                <select id="mp_project" class="filter-select" onchange="filterTable()">
                    <option value="">All Projects</option>
                    <option value="Unassigned">Unassigned</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?php echo htmlspecialchars($proj['project_name']); ?>" <?php echo ($project_id_from_url > 0 && (int)$proj['id'] === $project_id_from_url) ? 'selected' : ''; ?>><?php echo htmlspecialchars($proj['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label" for="mp_wattage">Wattage</label>
                <select id="mp_wattage" class="filter-select" onchange="filterTable()">
                    <option value="">All Wattages</option>
                    <?php $wattages = array_unique(array_map(function($p) { return $p['wattage']; }, $pallets)); sort($wattages); foreach ($wattages as $w) { echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>'; } ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label" for="mp_status">Status</label>
                <select id="mp_status" class="filter-select" onchange="filterTable()">
                    <option value="">All Statuses</option>
                    <?php $statuses = array_unique(array_map(function($p) { return $p['status']; }, $pallets)); sort($statuses); foreach ($statuses as $s) { echo '<option value="' . htmlspecialchars($s) . '">' . htmlspecialchars($s) . '</option>'; } ?>
                </select>
            </div>
        </div>
    </div>
    


    <?php if (!empty($successMessage)): /* This is for general page messages, including shipment outcomes */ ?>
        <div class="success-message">
            <?php echo $successMessage; // Already HTML escaped if from $_SESSION, or needs to be if set directly ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($warningDetails) && is_array($warningDetails)): ?>
        <div class="warning-message">
            <?php echo htmlspecialchars($warningDetails['message'] ?? 'An unspecified warning occurred.'); ?>
            <?php if (!empty($warningDetails['batch_id'])): ?>
                <?php $wd_pid = isset($warningDetails['project_id']) ? (int)$warningDetails['project_id'] : 0; $wd_bid = (int)$warningDetails['batch_id']; ?>
                <a href="<?php echo $wd_pid ? ('edit_module_batch.php?project_id='.$wd_pid.'&batch_id='.$wd_bid) : ('edit_module_batch.php?batch_id='.$wd_bid); ?>">Update Batch Details</a>
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
        
        <!-- Filters and Controls -->
        <div class="filters-container" style="display:none; margin-bottom: 15px; justify-content: space-between; align-items: flex-start; gap: 20px;">
            <div class="filter-dropdown" style="width: 300px;">
                <button type="button" class="filter-toggle-btn" onclick="toggleFilters()" style="display:none;">
                    <span>Filters</span> <span class="filter-arrow">▼</span>
                </button>
                <div class="filter-content" id="filterContent" style="display: block;">
                    <div style="display: flex; flex-direction: row; align-items: center; flex-wrap: wrap; gap: 12px; padding: 10px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
                        <div style="display: flex; align-items: center; gap: 10px; min-width: 240px; flex: 1;">
                            <input type="text" id="filterInput" onkeyup="filterBySearch()" placeholder="Search pallets..." style="flex: 1;">
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; min-width: 200px;">
                            <label for="projectFilter" style="display:none;">Project:</label>
                            <select id="projectFilter" onchange="filterByProject()" style="min-width: 200px;">
                                <option value="">All Projects</option>
                                <option value="Unassigned">Unassigned</option>
                                <?php foreach ($projects as $proj): ?>
                                    <option value="<?php echo htmlspecialchars($proj['project_name']); ?>" <?php echo ($project_id_from_url > 0 && (int)$proj['id'] === $project_id_from_url) ? 'selected' : ''; ?>><?php echo htmlspecialchars($proj['project_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; min-width: 160px;">
                            <label for="wattageFilter" style="display:none;">Wattage:</label>
                            <select id="wattageFilter" onchange="filterByWattage()" style="min-width: 160px;">
                                <option value="">All Wattages</option>
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
                        <div style="display: flex; align-items: center; gap: 10px; min-width: 200px;">
                            <label for="statusFilter" style="display:none;">Status:</label>
                            <select id="statusFilter" onchange="filterTable()" style="min-width: 200px;">
                                <option value="">All Statuses</option>
                                <?php
                                $statuses = array_unique(array_map(function($p) { return $p['status']; }, $pallets));
                                sort($statuses);
                                foreach ($statuses as $s) {
                                    echo '<option value="' . htmlspecialchars($s) . '">' . htmlspecialchars($s) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; justify-content: center; flex: 1;">
                <?php if (!$is_user): ?>
                <span id="selectedCount" style="font-weight: bold; color: #488C9A;">0 pallets selected</span>
                <?php endif; ?>
            </div>
            <div style="display: none; align-items: center; gap: 15px;">
                <?php if (!$is_user): ?>
                <button type="button" id="deletePalletsBtn_old" class="action-button" style="padding: 8px 15px; font-size: 0.9em; background-color: #dc3545;" disabled>
                    Delete
                </button>
                <?php endif; ?>
                <button type="button" id="exportCsvBtn_old" class="action-button" style="padding: 8px 15px; font-size: 0.9em;">Export</button>
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

        <div class="deliveries-container">
            <div class="table-header">
                <h3 class="table-title"><i class="fas fa-boxes"></i> Pallets</h3>
                <div class="table-header-actions">
                    <?php if (!$is_user): ?>
                    <button type="button" id="deletePalletsBtn" class="action-btn action-btn-danger" disabled><i class="fas fa-trash"></i> Delete</button>
                    <?php endif; ?>
                    <button type="button" id="exportCsvBtn" class="btn-export-header"><i class="fas fa-download"></i> Export</button>
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
                        <th>Deliveries</th>
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
                                <td>
                                    <?php 
                                    $status = htmlspecialchars($pallet['status']);
                                    if ($status === 'In Transit to Warehouse' && $pallet['current_warehouse_id']) {
                                        echo '<a href="manage_warehouse_inventory.php?warehouse_id=' . $pallet['current_warehouse_id'] . '&view=inbound_transit" style="color: #488C9A; text-decoration: underline;">' . $status . '</a>';
                                    } elseif ($status === 'In Warehouse' && $pallet['current_warehouse_id']) {
                                        if ($is_user) {
                                            $backProjectParam = $project_id_from_url > 0 ? ('&project_id='.(int)$project_id_from_url) : '';
                                            echo '<a href="warehouse_info.php?warehouse_id=' . $pallet['current_warehouse_id'] . '&from=manage_pallets' . $backProjectParam . '" style="color: #488C9A; text-decoration: underline;">' . $status . '</a>';
                                        } else {
                                            echo '<a href="manage_warehouse_inventory.php?warehouse_id=' . $pallet['current_warehouse_id'] . '&view=stored_inventory" style="color: #488C9A; text-decoration: underline;">' . $status . '</a>';
                                        }
                                    } else {
                                        echo $status;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $deliveryInfo = $pallet['delivery_info'] ?? '';
                                    if (empty($deliveryInfo)) {
                                        echo 'No deliveries';
                                    } else {
                                        $deliveries = explode('|', $deliveryInfo);
                                        if (count($deliveries) == 1) {
                                            $parts = explode(':', $deliveries[0]);
                                            $deliveryId = $parts[0];
                                            $bolNumber = $parts[1];
                                            if ($is_user) {
                                                // Try to determine a project context for the pallet
                                                $projIdForLink = 0;
                                                if (!empty($pallet['current_project_id'])) { $projIdForLink = (int)$pallet['current_project_id']; }
                                                elseif (!empty($pallet['assigned_project_id'])) { $projIdForLink = (int)$pallet['assigned_project_id']; }
                                                if ($projIdForLink > 0) {
                                                    echo '<a href="view_project.php?project_id=' . $projIdForLink . '&delivery_id=' . htmlspecialchars($deliveryId) . '" style="color: #488C9A; text-decoration: underline;">' . htmlspecialchars($bolNumber) . '</a>';
                                                } else {
                                                    echo htmlspecialchars($bolNumber);
                                                }
                                            } else {
                                                echo '<a href="manage_deliveries.php?delivery_id=' . htmlspecialchars($deliveryId) . '" style="color: #488C9A; text-decoration: underline;">' . htmlspecialchars($bolNumber) . '</a>';
                                            }
                                        } else {
                                            echo '<div class="delivery-dropdown">';
                                            echo '<button type="button" class="delivery-toggle" onclick="toggleDeliveryDropdown(this)" style="background: #488C9A; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer;">Multiple (' . count($deliveries) . ')</button>';
                                            echo '<div class="delivery-list" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 3px; z-index: 1000; min-width: 150px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">';
                                            foreach ($deliveries as $delivery) {
                                                $parts = explode(':', $delivery);
                                                $deliveryId = $parts[0];
                                                $bolNumber = $parts[1];
                                                if ($is_user) {
                                                    $projIdForLink = 0;
                                                    if (!empty($pallet['current_project_id'])) { $projIdForLink = (int)$pallet['current_project_id']; }
                                                    elseif (!empty($pallet['assigned_project_id'])) { $projIdForLink = (int)$pallet['assigned_project_id']; }
                                                    if ($projIdForLink > 0) {
                                                        echo '<a href="view_project.php?project_id=' . $projIdForLink . '&delivery_id=' . htmlspecialchars($deliveryId) . '" style="display: block; padding: 8px 12px; color: #488C9A; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor=\'#f5f5f5\'" onmouseout="this.style.backgroundColor=\'white\'">' . htmlspecialchars($bolNumber) . '</a>';
                                                    } else {
                                                        echo '<span style="display: block; padding: 8px 12px; color: #333; border-bottom: 1px solid #eee;">' . htmlspecialchars($bolNumber) . '</span>';
                                                    }
                                                } else {
                                                    echo '<a href="manage_deliveries.php?delivery_id=' . htmlspecialchars($deliveryId) . '" style="display: block; padding: 8px 12px; color: #488C9A; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor=\'#f5f5f5\'" onmouseout="this.style.backgroundColor=\'white\'">' . htmlspecialchars($bolNumber) . '</a>';
                                                }
                                            }
                                            echo '</div>';
                                            echo '</div>';
                                        }
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
                                    <?php $pdUrl = 'pallet_details.php?pallet_id=' . (int)$pallet['pallet_id'] . ($project_id_from_url > 0 ? ('&project_id='.(int)$project_id_from_url) : ''); ?>
                                    <a href="<?php echo $pdUrl; ?>" class="action-button">Pallet Details</a>
                                    <?php if (!$is_user): ?>
                                    <button type="button" class="action-button" onclick="window.location.href='edit_pallet.php?pallet_id=<?php echo $pallet['pallet_id']; ?>'" style="background-color:#f0ad4e;">Edit Pallet</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $is_user ? '9' : '10'; ?>">No pallets found in the system.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>

        <div class="back-link" style="margin-top: 20px;">
            
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



<!-- Embed PHP data for table functionality -->
<script>
    const isUser = <?php echo $is_user ? 'true' : 'false'; ?>;
    const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
    const isGlobalAdmin = <?php echo $is_global_admin ? 'true' : 'false'; ?>;
</script>

<script>
function clearMpFilters(){
  const ids=['mp_search','mp_project','mp_wattage','mp_status'];
  ids.forEach(id=>{const el=document.getElementById(id); if(el) el.value='';});
  filterTable();
}
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
        
        const input = (document.getElementById('mp_search')?.value || document.getElementById('filterInput')?.value || '').toUpperCase();
        const projectFilterValue = document.getElementById('mp_project')?.value || document.getElementById('projectFilter')?.value || '';
        const wattageFilterValue = document.getElementById('mp_wattage')?.value || document.getElementById('wattageFilter')?.value || '';
        const statusFilterValue = document.getElementById('mp_status')?.value || document.getElementById('statusFilter')?.value || '';
        
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
        
        // Check status filter (Status is in column 5 for users, 6 for admins)
        const statusColumnIndex = isUser ? 5 : 6;
        const statusCell = row.cells[statusColumnIndex];
        let matchesStatus = false;
        if (statusFilterValue === "") {
            matchesStatus = true;
        } else {
            const cellStatus = statusCell ? (statusCell.textContent || statusCell.innerText).trim() : '';
            matchesStatus = cellStatus === statusFilterValue;
        }
        
        return matchesProject && matchesWattage && matchesSearch && matchesStatus;
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
    updateDeleteButtonState();
    updateSelectedCount();
}

function updateDeleteButtonState() {
    if (isUser) return; // Users don't have these buttons
    
    const deleteBtn = document.getElementById('deletePalletsBtn');
    const checkedCount = document.querySelectorAll('#palletsTable .pallet-checkbox:checked').length;
    
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
    updateDeleteButtonState();
}

document.addEventListener('DOMContentLoaded', function() {
    if (!isUser) {
        const selectAllPalletsCheckbox = document.getElementById('selectAllPallets');
        if (selectAllPalletsCheckbox) {
            selectAllPalletsCheckbox.addEventListener('change', function() {
                toggleAllPalletCheckboxes(this.checked);
            });
        }

        // Use event delegation for checkbox changes to handle dynamically shown/hidden rows
        document.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('pallet-checkbox')) {
                updateSelectedCount();
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

        updateDeleteButtonState();
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
        updateDeleteButtonState();
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















// ----------------- FILTER DROPDOWN FUNCTIONALITY -----------------
function toggleFilters() {
    const filterContent = document.getElementById('filterContent');
    const toggleBtn = document.querySelector('.filter-toggle-btn');
    
    if (filterContent.style.display === 'none' || filterContent.style.display === '') {
        filterContent.style.display = 'block';
        toggleBtn.classList.add('active');
    } else {
        filterContent.style.display = 'none';
        toggleBtn.classList.remove('active');
    }
}

// ----------------- DELIVERY DROPDOWN FUNCTIONALITY -----------------
function toggleDeliveryDropdown(button) {
    const dropdown = button.nextElementSibling;
    const isVisible = dropdown.style.display !== 'none';
    
    // Close all other dropdowns first
    document.querySelectorAll('.delivery-list').forEach(function(list) {
        list.style.display = 'none';
    });
    
    // Toggle this dropdown
    dropdown.style.display = isVisible ? 'none' : 'block';
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    // Close delivery dropdowns
    if (!event.target.closest('.delivery-dropdown')) {
        document.querySelectorAll('.delivery-list').forEach(function(list) {
            list.style.display = 'none';
        });
    }
    
    // Close filter dropdown
    if (!event.target.closest('.filter-dropdown')) {
        const filterContent = document.getElementById('filterContent');
        const toggleBtn = document.querySelector('.filter-toggle-btn');
        if (filterContent && toggleBtn) {
            filterContent.style.display = 'none';
            toggleBtn.classList.remove('active');
        }
    }
});
</script>
</body>
</html> 
