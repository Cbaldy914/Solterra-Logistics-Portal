<?php
session_name("logistics_session");
session_start();

// Allow access for admin, global_admin, and user roles.
// Specific functionalities will be controlled by role checks within the page.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'user'])) {
    header("Location: unauthorized");
    exit();
}
// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

$role     = $_SESSION['role'];
$user_id  = $_SESSION['user_id'];
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

// Determine view mode: single batch or project view
if ($project_id > 0) {
    $view_mode = 'project';
} elseif ($batch_id > 0) {
    $view_mode = 'batch';
} else {
    die("Either Batch ID or Project ID must be provided.");
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
    $stmtW->bind_result($wattage, $orderedQuantity);
    $stmtW->fetch();
    $stmtW->close();
    
    // Calculate already palletized quantity for this item
    $stmtP = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM inventory_pallets WHERE unassigned_module_item_id = ?");
    $stmtP->bind_param("i", $itemId);
    $stmtP->execute();
    $stmtP->bind_result($palletizedQuantity);
    $stmtP->fetch();
    $stmtP->close();
    
    // Calculate remaining modules to palletize
    $remainingModules = $orderedQuantity - $palletizedQuantity;
    
    if ($remainingModules <= 0) {
        $successMessage = "No modules remaining to palletize for this wattage.";
    } else {
        // Calculate number of pallets and distribution of modules based on REMAINING quantity
        $fullPallets  = intdiv($remainingModules, $modulesPerPallet);
        $remainder    = $remainingModules % $modulesPerPallet;
        $totalPallets = $fullPallets + ($remainder > 0 ? 1 : 0);
        
        // Insert full pallets
        for ($i = 0; $i < $fullPallets; $i++) {
            insertPallet($itemId, $wattage, $modulesPerPallet);
        }
        // Insert last pallet if there's a remainder
        if ($remainder > 0) {
            insertPallet($itemId, $wattage, $remainder);
        }
        $successMessage = "Created $totalPallets pallets (up to $modulesPerPallet modules each) for $remainingModules remaining modules.";
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_pallets') {
    $deleteMessage = '';
    $conn_delete = getDBConnection(); // Use separate connection variable
    if (!$conn_delete) {
        $deleteMessage = "Error: Database connection failed.";
    } else {
        $conn_delete->begin_transaction();
        try {
            $palletIds = $_POST['selected_pallets'] ?? [];
            if (empty($palletIds)) {
                throw new Exception('No pallets selected for deletion.');
            }

            // Check if any pallets are linked to deliveries
            $placeholders = implode(',', array_fill(0, count($palletIds), '?'));
            $types = str_repeat('i', count($palletIds));
            
            $stmtCheckDeliveries = $conn_delete->prepare("SELECT inventory_pallet_id FROM delivery_pallets WHERE inventory_pallet_id IN ($placeholders)");
            if (!$stmtCheckDeliveries) throw new Exception("Failed to prepare delivery check: " . $conn_delete->error);
            
            $stmtCheckDeliveries->bind_param($types, ...$palletIds);
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
            
            $stmtDelete->bind_param($types, ...$palletIds);
            if (!$stmtDelete->execute()) {
                throw new Exception("Failed to delete pallets: " . $stmtDelete->error);
            }
            
            $deletedCount = $stmtDelete->affected_rows;
            $stmtDelete->close();
            $conn_delete->commit();
            
            $deleteMessage = "Successfully deleted $deletedCount pallet(s).";
            
        } catch (Exception $e) {
            $conn_delete->rollback();
            $deleteMessage = "Error deleting pallets: " . $e->getMessage();
        } finally {
            $conn_delete->close();
        }
    }
    
    // Store message in session and redirect to prevent form resubmission
    $_SESSION['module_overview_message'] = $deleteMessage;
    
    // Construct appropriate redirect URL based on view mode
    if ($view_mode === 'project' && $project_id > 0) {
        header("Location: module_overview.php?project_id=" . $project_id);
    } else {
        header("Location: module_overview.php?batch_id=" . $batch_id);
    }
    exit();
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
    'detailed_breakdown' => [],
];
$wattage_summary = []; // NEW: Array to hold summary data per wattage

$account_id_for_admin = null;
$errorMessage = '';

try {
    // Initialize variables
    $batch_data = null;
    $project_data = null;
    $module_batches = [];
    
    if ($view_mode === 'batch') {
        // Fetch single batch data
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
        
        $module_batches[] = $batch_data; // Single batch in array for consistent processing
        
    } elseif ($view_mode === 'project') {
        // Fetch project data and all its module batches
        $stmtProject = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        if (!$stmtProject) throw new Exception("Prepare project fetch failed: " . $conn->error);
        $stmtProject->bind_param("i", $project_id);
        $stmtProject->execute();
        $resultProject = $stmtProject->get_result();
        if ($resultProject->num_rows === 0) {
            throw new Exception("Project not found.");
        }
        $project_data = $resultProject->fetch_assoc();
        $stmtProject->close();
        
        // Fetch all module batches for this project
        $stmtBatches = $conn->prepare("
            SELECT um.*, c.name as account_name, p.project_name
            FROM modules um 
            JOIN customer_accounts c ON um.account_id = c.id
            LEFT JOIN projects p ON um.project_id = p.id
            WHERE um.project_id = ?
            ORDER BY um.vendor_name, um.created_at
        ");
        if (!$stmtBatches) throw new Exception("Prepare project batches fetch failed: " . $conn->error);
        $stmtBatches->bind_param("i", $project_id);
        $stmtBatches->execute();
        $resultBatches = $stmtBatches->get_result();
        while ($batch = $resultBatches->fetch_assoc()) {
            $module_batches[] = $batch;
        }
        $stmtBatches->close();
        
        if (empty($module_batches)) {
            throw new Exception("No module batches found for this project.");
        }
        
        // Set batch_data to the first batch for compatibility with existing code
        $batch_data = $module_batches[0];
    }

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
    $item_ids = []; // Keep track of item IDs to fetch pallets
    
    // Collect batch IDs for fetching items
    $batch_ids = [];
    foreach ($module_batches as $batch) {
        $batch_ids[] = $batch['id'];
    }
    
    if (!empty($batch_ids)) {
        $placeholders_batches = implode(',', array_fill(0, count($batch_ids), '?'));
        $types_batches = str_repeat('i', count($batch_ids));
        
        $stmtItems = $conn->prepare("SELECT id, unassigned_module_id, wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id IN ($placeholders_batches) ORDER BY wattage ASC");
        if (!$stmtItems) throw new Exception("Prepare items fetch failed: " . $conn->error);
        $stmtItems->bind_param($types_batches, ...$batch_ids);
        $stmtItems->execute();
        $resultItems = $stmtItems->get_result();
        
        while ($item = $resultItems->fetch_assoc()) {
            $item_ids[] = $item['id'];
            $wattage = $item['wattage'];
            if (!isset($wattage_summary[$wattage])) {
                $wattage_summary[$wattage] = [
                    'item_id' => $item['id'], // For single batch mode compatibility
                    'ordered_quantity' => 0,
                    'palletized_quantity' => 0,
                    'remaining_quantity' => 0
                ];
            }
            $wattage_summary[$wattage]['ordered_quantity'] += $item['quantity'];
            $batch_items[] = $item; // Still store raw items if needed later
        }
        $stmtItems->close();
    }

    // Fetch associated pallets and aggregate palletized quantity by wattage
    if (!empty($item_ids)) {
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $types = str_repeat('i', count($item_ids));
        
        $sqlPallets = "SELECT ip.id, ip.pallet_identifier, ip.unassigned_module_item_id, ip.wattage, ip.quantity, ip.status, ip.arrival_date, ip.current_warehouse_id, ip.current_project_id, w.name as warehouse_name, p.project_name,
                              w.street_address as warehouse_street, w.city as warehouse_city, w.state as warehouse_state, w.zip_code as warehouse_zip,
                              p.street_address as project_street, p.city as project_city, p.state as project_state, p.zip_code as project_zip,
                              GROUP_CONCAT(DISTINCT CONCAT(d.id, ':', COALESCE(d.bol_number, 'No BOL')) ORDER BY d.id SEPARATOR '|') as delivery_info
                       FROM inventory_pallets ip
                       LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
                       LEFT JOIN projects p ON ip.current_project_id = p.id
                       LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                       LEFT JOIN deliveries d ON dp.delivery_id = d.id
                       WHERE ip.unassigned_module_item_id IN ($placeholders)
                       GROUP BY ip.id, ip.pallet_identifier, ip.unassigned_module_item_id, ip.wattage, ip.quantity, ip.status, ip.arrival_date, ip.current_warehouse_id, ip.current_project_id, w.name, p.project_name,
                              w.street_address, w.city, w.state, w.zip_code,
                              p.street_address, p.city, p.state, p.zip_code
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
            
            // Add full addresses for origin determination
            if ($pallet['status'] === 'In Warehouse' && $pallet['current_warehouse_id']) {
                $warehouse_address_parts = array_filter([$pallet['warehouse_street'], $pallet['warehouse_city'], $pallet['warehouse_state'], $pallet['warehouse_zip']]);
                $pallet['warehouse_full_address'] = implode(', ', $warehouse_address_parts);
            }
            if ($pallet['status'] === 'Delivered to Project' && $pallet['current_project_id']) {
                $project_address_parts = array_filter([$pallet['project_street'], $pallet['project_city'], $pallet['project_state'], $pallet['project_zip']]);
                $pallet['project_full_address'] = implode(', ', $project_address_parts);
            }
            $pallets[] = $pallet; // Store raw pallet data
            
            // Create detailed status breakdown with location and wattage info
            $status = $pallet['status'];
            $wattage = $pallet['wattage'];
            $quantity = $pallet['quantity'];
            
            // Create location-specific key for breakdown
            $breakdown_key = '';
            if ($status === 'In Warehouse' && $pallet['warehouse_name']) {
                $breakdown_key = 'In Warehouse - ' . $pallet['warehouse_name'];
            } elseif ($status === 'Delivered to Project' && $pallet['project_name']) {
                $breakdown_key = 'Delivered to Project - ' . $pallet['project_name'];
            } elseif ($status === 'In Transit to Project' && $pallet['project_name']) {
                $breakdown_key = 'In Transit to Project - ' . $pallet['project_name'];
            } elseif ($status === 'In Transit to Warehouse' && $pallet['warehouse_name']) {
                $breakdown_key = 'In Transit to Warehouse - ' . $pallet['warehouse_name'];
            } else {
                $breakdown_key = $status;
            }
            
            // Initialize if not exists
            if (!isset($summary_stats['detailed_breakdown'][$breakdown_key])) {
                $summary_stats['detailed_breakdown'][$breakdown_key] = [
                    'pallet_count' => 0,
                    'total_modules' => 0,
                    'wattage_breakdown' => []
                ];
            }
            
            // Update counts
            $summary_stats['detailed_breakdown'][$breakdown_key]['pallet_count']++;
            $summary_stats['detailed_breakdown'][$breakdown_key]['total_modules'] += $quantity;
            
            // Track wattage breakdown
            if (!isset($summary_stats['detailed_breakdown'][$breakdown_key]['wattage_breakdown'][$wattage])) {
                $summary_stats['detailed_breakdown'][$breakdown_key]['wattage_breakdown'][$wattage] = [
                    'pallets' => 0,
                    'modules' => 0
                ];
            }
            $summary_stats['detailed_breakdown'][$breakdown_key]['wattage_breakdown'][$wattage]['pallets']++;
            $summary_stats['detailed_breakdown'][$breakdown_key]['wattage_breakdown'][$wattage]['modules'] += $quantity;
            
            // Keep legacy simple count for compatibility
            $summary_stats['status_counts'][$status] = ($summary_stats['status_counts'][$status] ?? 0) + 1;
        }
        $stmtPallets->close();
    }

    // Calculate remaining quantity for each wattage
    foreach ($wattage_summary as $wattage => &$data) { // Use reference to modify directly
        $data['remaining_quantity'] = $data['ordered_quantity'] - $data['palletized_quantity'];
    }
    unset($data); // Unset reference after loop

    // Fetch Projects for the account associated with this batch (with addresses)
    $account_projects = [];
    if (isset($batch_data['account_id'])) {
        $stmtP = $conn->prepare("SELECT id, project_name, street_address, city, state, zip_code FROM projects WHERE account_id = ? ORDER BY project_name ASC");
        if ($stmtP) {
            $stmtP->bind_param("i", $batch_data['account_id']);
            $stmtP->execute();
            $resultP = $stmtP->get_result();
            while ($proj = $resultP->fetch_assoc()) {
                // Build full address for Google Maps
                $address_parts = array_filter([$proj['street_address'], $proj['city'], $proj['state'], $proj['zip_code']]);
                $proj['full_address'] = implode(', ', $address_parts);
                $account_projects[] = $proj;
            }
            $stmtP->close();
        }
    }

    // Fetch Warehouses (with addresses)
    $all_warehouses = [];
    $stmtW = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM warehouses ORDER BY name ASC");
    if ($stmtW) {
        $stmtW->execute();
        $resultW = $stmtW->get_result();
        while ($wh = $resultW->fetch_assoc()) {
            // Build full address for Google Maps
            $address_parts = array_filter([$wh['street_address'], $wh['city'], $wh['state'], $wh['zip_code']]);
            $wh['full_address'] = implode(', ', $address_parts);
            $all_warehouses[] = $wh;
        }
        $stmtW->close();
    }

    // Fetch Manufacturers for origin selection (with addresses)
    $all_manufacturers = [];
    $stmtM = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM manufacturers WHERE is_active = 1 ORDER BY name ASC");
    if ($stmtM) {
        $stmtM->execute();
        $resultM = $stmtM->get_result();
        while ($mfg = $resultM->fetch_assoc()) {
            // Build full address for Google Maps
            $address_parts = array_filter([$mfg['street_address'], $mfg['city'], $mfg['state'], $mfg['zip_code']]);
            $mfg['full_address'] = implode(', ', $address_parts);
            $all_manufacturers[] = $mfg;
        }
        $stmtM->close();
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
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
            margin-top: 10px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
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
            max-width: 600px;
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
        .shipment-details-modal-content input[type="number"],
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
        
        /* Origin-Destination Layout Styling */
        .origin-destination-section {
            margin: 20px 0;
        }
        
        .location-container {
            align-items: flex-start;
        }
        
        .origin-section, .destination-section {
            min-width: 0; /* Allow flex items to shrink */
        }
        
        .distance-separator {
            min-width: 80px;
            text-align: center;
        }
        
        .destination-radio-group {
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .location-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .distance-separator {
                flex-direction: row;
                justify-content: center;
                margin-top: 0;
            }
            
            .distance-separator > div:first-child {
                transform: rotate(90deg);
                margin-right: 10px;
            }
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
        
        /* Portal-style action buttons */
        .action-button {
            background: #488C9A !important;
            color: #fff !important;
            border: none !important;
            padding: 12px 20px !important;
            cursor: pointer !important;
            border-radius: 4px !important;
            font-weight: 600 !important;
            font-size: 1em !important;
            text-decoration: none !important;
            display: inline-block !important;
            transition: background-color 0.3s ease !important;
            margin: 0 !important;
        }
        .action-button:hover {
            background: #293E4C !important;
            color: #fff !important;
        }
        .action-button:disabled {
            background: #cccccc !important;
            color: #666666 !important;
            cursor: not-allowed !important;
        }
        .action-button:disabled:hover {
            background: #cccccc !important;
            color: #666666 !important;
        }
        
        /* Red delete button styling */
        .action-button[style*="background-color: #dc3545"]:hover:not(:disabled) {
            background-color: #c82333 !important;
        }
        
        /* Success and error message styling consistency */
        .success-message {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 15px;
            border-radius: 4px;
            text-align: center;
        }
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 4px;
            text-align: center;
        }
        
        /* Loading spinner modal styles */
        .loading-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        .loading-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #488C9A;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 2s linear infinite;
            margin: 0 auto 15px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
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
    <div class="breadcrumb">
        <a href="<?php echo ($role === 'admin' || $role === 'global_admin') ? 'admin_dashboard' : 'dashboard'; ?>">Dashboard</a>
        <span class="separator">&raquo;</span>
        <?php if ($view_mode === 'project'): ?>
            <a href="project_overview.php?project_id=<?php echo $project_id; ?>">Project Overview</a>
            <span class="separator">&raquo;</span>
            <span>Modules for <?php echo htmlspecialchars($project_data['project_name']); ?></span>
        <?php else: ?>
            <a href="modules">Modules</a>
            <span class="separator">&raquo;</span>
            <span>Batch: <?php echo $batch_data ? htmlspecialchars($batch_data['vendor_name']) : 'Module Batch'; ?></span>
        <?php endif; ?>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php elseif ($batch_data): ?>
        
        <!-- SUCCESS/ERROR MESSAGES AT TOP -->
        <?php if (!empty($successMessage)): ?>
            <div class="success-message" style="margin-bottom: 20px;"><strong><?php echo htmlspecialchars($successMessage); ?></strong></div>
        <?php endif; ?>
        
        <?php 
        // Check for session messages (for shipment and delete operations after redirect)
        $sessionShipMessage = $_SESSION['module_overview_ship_message'] ?? '';
        $sessionDeleteMessage = $_SESSION['module_overview_message'] ?? '';
        
        // Clear session messages after retrieving them
        if (!empty($sessionShipMessage)) {
            unset($_SESSION['module_overview_ship_message']);
        }
        if (!empty($sessionDeleteMessage)) {
            unset($_SESSION['module_overview_message']);
        }
        
        // Display ship message (either from session or local variable)
        $displayShipMessage = !empty($sessionShipMessage) ? $sessionShipMessage : (!empty($shipMessage) ? $shipMessage : '');
        if (!empty($displayShipMessage)): ?>
            <?php 
            $messageClass = (strpos(strtolower($displayShipMessage), 'error') !== false) ? 'error-message' : 'success-message';
            ?>
            <div class="<?php echo $messageClass; ?>" style="margin-bottom: 20px;"><strong><?php 
                // Check if the message contains HTML (specifically a link) and display accordingly
                if (strpos($displayShipMessage, '<a href=') !== false) {
                    echo $displayShipMessage; // Don't escape if it contains HTML links
                } else {
                    echo htmlspecialchars($displayShipMessage); // Escape for safety if no HTML
                }
            ?></strong></div>
        <?php endif; ?>
        
        <?php 
        // Display delete message (either from session or local variable) 
        $displayDeleteMessage = !empty($sessionDeleteMessage) ? $sessionDeleteMessage : (!empty($deleteMessage) ? $deleteMessage : '');
        if (!empty($displayDeleteMessage)): ?>
            <?php 
            $messageClass = (strpos(strtolower($displayDeleteMessage), 'error') !== false) ? 'error-message' : 'success-message';
            ?>
            <div class="<?php echo $messageClass; ?>" style="margin-bottom: 20px;"><strong><?php echo htmlspecialchars($displayDeleteMessage); ?></strong></div>
        <?php endif; ?>
        
        <div class="overview-header">
            <?php if ($view_mode === 'project'): ?>
                <h1>Modules for <?php echo htmlspecialchars($project_data['project_name']); ?></h1>
                <p><strong>Project Address:</strong> <?php echo htmlspecialchars($project_data['project_address']); ?></p>
                <p><strong>Number of Module Batches:</strong> <?php echo count($module_batches); ?></p>
                
                <?php if (count($module_batches) > 1): ?>
                    <div style="margin-top: 15px; padding: 15px; background-color: #f8f9fa; border-radius: 8px;">
                        <h3 style="margin-top: 0; color: #293E4C;">Module Batch Details:</h3>
                        <?php foreach ($module_batches as $batch): ?>
                            <div style="margin-bottom: 10px; padding: 10px; background-color: white; border-left: 4px solid #488C9A; border-radius: 4px;">
                                <strong>Batch:</strong> <?php echo htmlspecialchars($batch['vendor_name']); ?> 
                                <span style="color: #666;">(ID: <?php echo $batch['id']; ?>)</span><br>
                                <strong>Initial Location:</strong> <?php echo htmlspecialchars($batch['initial_location']); ?><br>
                                <strong>Date Added:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['created_at'])); ?>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <br><a href="edit_module?batch_id=<?php echo $batch['id']; ?>" style="color: #488C9A; text-decoration: none;">Edit Batch</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p><strong>Batch:</strong> <?php echo htmlspecialchars($module_batches[0]['vendor_name']); ?></p>
                    <p><strong>Account:</strong> <?php echo htmlspecialchars($module_batches[0]['account_name']); ?></p>
                    <p><strong>Initial Location:</strong> <?php echo htmlspecialchars($module_batches[0]['initial_location']); ?></p>
                    <p><strong>Date Added:</strong> <?php echo date('Y-m-d H:i', strtotime($module_batches[0]['created_at'])); ?></p>
                    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                    <button class="edit-button" onclick="window.location.href='edit_module?batch_id=<?php echo $module_batches[0]['id']; ?>'">Edit Batch Details</button>
                    <?php endif; ?>
                <?php endif; ?>
                
            <?php else: ?>
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
                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                <button class="edit-button" onclick="window.location.href='edit_module?batch_id=<?php echo $batch_id; ?>'">Edit Batch Details</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="summary-section">
            <h2 class="section-title">Summary & Pallet Generation</h2>
            
            <!-- Container for wattage blocks -->
            <div class="wattage-blocks-container">
                <?php if (!empty($wattage_summary)):
                    foreach ($wattage_summary as $wattage => $data):
                ?>
                        <div class="wattage-summary-block">
                            <h4><?php echo htmlspecialchars($wattage); ?>W Modules</h4>
                            <p><strong>Ordered:</strong> <?php echo number_format($data['ordered_quantity']); ?></p>
                            <p><strong>On Pallets:</strong> <?php echo number_format($data['palletized_quantity']); ?></p>
                            
                            <?php if ($data['remaining_quantity'] < 0): ?>
                                <p><strong>Over-palletized:</strong> <span style="color: #d32f2f;"><?php echo number_format(abs($data['remaining_quantity'])); ?> excess modules</span></p>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <p style="color: #d32f2f; font-size: 0.9em; margin-top: 10px;">
                                        ⚠️ You have <?php echo number_format(abs($data['remaining_quantity'])); ?> more modules on pallets than ordered. 
                                        Consider removing excess pallets via the pallet list below.
                                    </p>
                                <?php endif; ?>
                            <?php elseif ($data['remaining_quantity'] > 0): ?>
                                <p><strong>Remaining:</strong> <span style="color: #2e7d32;"><?php echo number_format($data['remaining_quantity']); ?></span></p>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="generate_pallets">
                                        <input type="hidden" name="item_id" value="<?php echo $data['item_id']; ?>">
                                        <div>
                                            <label for="modules_per_pallet_<?php echo $wattage; ?>">Modules per Pallet:</label>
                                            <input type="number" name="modules_per_pallet" id="modules_per_pallet_<?php echo $wattage; ?>" min="1" value="1" required>
                                            <button type="submit">Generate</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <p><strong>Remaining:</strong> <span style="color: green;">0 (Perfect match)</span></p>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <p style="color: green; margin-top: 15px;">✅ All modules perfectly palletized.</p>
                                <?php elseif ($_SESSION['role'] === 'user'): ?>
                                    <p style="color: green; margin-top: 15px;">All modules palletized.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                <?php 
                    endforeach; // End foreach $wattage_summary
                    endif; // End if !empty($wattage_summary)
                ?>
            </div>

            <!-- Keep Pallet Status Breakdown -->
            <h3 style="margin-top: 30px;">Overall Pallet Status Breakdown:</h3>
            <?php if (!empty($summary_stats['detailed_breakdown'])): ?>
                <div class="status-breakdown-detailed">
                    <?php foreach ($summary_stats['detailed_breakdown'] as $status => $data): ?>
                        <div class="status-item" style="margin-bottom: 15px; padding: 12px; background-color: #f8f9fa; border-left: 4px solid #488C9A; border-radius: 4px;">
                            <div style="font-weight: 600; color: #293E4C; margin-bottom: 8px;">
                                <?php echo htmlspecialchars($status); ?>: 
                                <span style="color: #488C9A;"><?php echo $data['pallet_count']; ?> pallets, <?php echo number_format($data['total_modules']); ?> modules</span>
                            </div>
                            <?php if (!empty($data['wattage_breakdown'])): ?>
                                <div style="margin-left: 20px; font-size: 0.9em; color: #666;">
                                    <strong>Breakdown by Wattage:</strong>
                                    <?php 
                                    $wattage_details = [];
                                    foreach ($data['wattage_breakdown'] as $wattage => $watt_data) {
                                        $wattage_details[] = "{$wattage}W: {$watt_data['pallets']} pallets (" . number_format($watt_data['modules']) . " modules)";
                                    }
                                    echo implode(' • ', $wattage_details);
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Link to detailed movement view -->
                <div style="margin-top: 20px; text-align: center;">
                    <?php 
                    $movement_url = "module_movements?batch_id=" . urlencode($batch_id);
                    if (!empty($batch_data['project_id'])) {
                        $movement_url .= "&project_id=" . urlencode($batch_data['project_id']);
                    }
                    ?>
                    <a href="<?php echo $movement_url; ?>" class="action-button" style="background-color: #488C9A; color: white; padding: 12px 24px; font-size: 1em; text-decoration: none; display: inline-block;">
                        <strong>📍 View Module Movement Map</strong>
                    </a>
                    <p style="font-size: 0.9em; color: #666; margin-top: 8px;">
                        See detailed movement tracking and geographic flow of these modules
                    </p>
                </div>
            <?php else: ?>
                <p>No pallets have been created/recorded for this batch yet.</p>
            <?php endif; ?>
        </div>

        <!-- ====== PALLET INFORMATION SECTION ====== -->
        <?php if (!empty($pallets)): ?>
            <div class="pallets-section" style="margin-top: 30px;">
                <h2 class="section-title">Associated Pallets</h2>
                <!-- Filters Dropdown -->
                <div class="filters-container" style="margin-bottom: 15px;">
                    <div class="filter-dropdown">
                        <button type="button" class="filter-toggle-btn" onclick="toggleFilters()">
                            <span>Filters</span> <span class="filter-arrow">▼</span>
                        </button>
                        <div class="filter-content" id="filterContent" style="display: none;">
                            <div style="display: flex; flex-direction: column; gap: 15px; padding: 15px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 0 0 4px 4px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label>Search:</label>
                                    <input type="text" id="palletSearch" placeholder="Filter by ID, Identifier, Wattage..." onkeyup="filterPallets()" style="flex: 1;">
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label for="wattageFilter">Wattage:</label>
                                    <select id="wattageFilter" onchange="filterPallets()" style="flex: 1;">
                                        <option value="">All</option>
                                        <?php
                                        $wattages = array_unique(array_map(function($p) { return $p['wattage']; }, $pallets));
                                        sort($wattages);
                                        foreach ($wattages as $w) {
                                            echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label for="statusFilter">Status:</label>
                                    <select id="statusFilter" onchange="filterPallets()" style="flex: 1;">
                                        <option value="">All</option>
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
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                            <a href="create_shipment.php<?php echo ($view_mode === 'project' && $project_id) ? '?project_id=' . $project_id : ''; ?>" class="action-button" style="background-color: #488C9A; color: white; padding: 8px 16px; text-decoration: none; border-radius: 3px; font-size: 0.9em;">Create Shipment</a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="pagination-container">
                    <div class="pagination-info">
                        <span id="paginationInfo">Showing 0 of 0 pallets</span>
                    </div>
                    <div class="pagination-controls">
                        <label for="itemsPerPage">Show:</label>
                        <input type="number" id="itemsPerPage" value="100" min="1" max="300" style="width: 80px;">
                        <label>pallets per page</label>
                        <button type="button" id="prevPage" disabled>Previous</button>
                        <span id="pageInfo">Page 1 of 1</span>
                        <button type="button" id="nextPage" disabled>Next</button>
                    </div>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Identifier</th>
                            <th>Wattage</th>
                            <th>Quantity</th>
                            <th>Status</th>
                            <th>Deliveries</th>
                            <th>Pallet Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pallets as $pallet): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                <td><?php echo $pallet['wattage']; ?>W</td>
                                <td><?php echo number_format($pallet['quantity']); ?></td>
                                <td>
                                    <?php 
                                    $status = htmlspecialchars($pallet['status']);
                                    if ($status === 'In Transit to Warehouse' && $pallet['current_warehouse_id']) {
                                        echo '<a href="manage_warehouse_inventory.php?warehouse_id=' . $pallet['current_warehouse_id'] . '&view=inbound_transit" style="color: #488C9A; text-decoration: underline;">' . $status . '</a>';
                                    } elseif ($status === 'In Warehouse' && $pallet['current_warehouse_id']) {
                                        echo '<a href="manage_warehouse_inventory.php?warehouse_id=' . $pallet['current_warehouse_id'] . '&view=stored_inventory" style="color: #488C9A; text-decoration: underline;">' . $status . '</a>';
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
                                            echo '<a href="manage_deliveries.php?delivery_id=' . htmlspecialchars($deliveryId) . '" style="color: #488C9A; text-decoration: underline;">' . htmlspecialchars($bolNumber) . '</a>';
                                        } else {
                                            echo '<div class="delivery-dropdown">';
                                            echo '<button type="button" class="delivery-toggle" onclick="toggleDeliveryDropdown(this)" style="background: #488C9A; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer;">Multiple (' . count($deliveries) . ')</button>';
                                            echo '<div class="delivery-list" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 3px; z-index: 1000; min-width: 150px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">';
                                            foreach ($deliveries as $delivery) {
                                                $parts = explode(':', $delivery);
                                                $deliveryId = $parts[0];
                                                $bolNumber = $parts[1];
                                                echo '<a href="manage_deliveries.php?delivery_id=' . htmlspecialchars($deliveryId) . '" style="display: block; padding: 8px 12px; color: #488C9A; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor=\'#f5f5f5\'" onmouseout="this.style.backgroundColor=\'white\'">' . htmlspecialchars($bolNumber) . '</a>';
                                            }
                                            echo '</div>';
                                            echo '</div>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="pallet_details.php?pallet_id=<?php echo $pallet['id']; ?>" class="action-button" style="background-color: #488C9A; color: white; padding: 4px 8px; text-decoration: none; border-radius: 3px; font-size: 0.9em;">View Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="margin-top:20px;">No pallets have been created or recorded for this batch yet.</p>
        <?php endif; ?>

    <?php else: ?>
         <p>Batch data could not be loaded.</p>
         <a href="modules">Back to Modules List</a>
    <?php endif; ?>
</main>

<!-- Loading Modal for Palletization -->
<div id="loadingModal" class="loading-modal">
    <div class="loading-content">
        <div class="spinner"></div>
        <h3>Creating Pallets...</h3>
        <p>Please wait while we generate your pallets. This may take a few moments.</p>
    </div>
</div>





<script>
// ----------------- PAGINATION FOR PALLETS -----------------
let currentPage = 1;
let itemsPerPage = 100;
let allPalletRows = [];

function initializePalletPagination() {
    const table = document.querySelector('.pallets-section table');
    if (!table) return;
    
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    
    allPalletRows = Array.from(tbody.querySelectorAll('tr'));
    
    const itemsPerPageInput = document.getElementById('itemsPerPage');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');
    
    if (itemsPerPageInput) {
        itemsPerPageInput.addEventListener('change', function() {
            itemsPerPage = Math.min(Math.max(1, parseInt(this.value) || 100), 300);
            this.value = itemsPerPage;
            currentPage = 1;
            updatePalletPagination();
        });
    }
    
    if (prevButton) {
        prevButton.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updatePalletPagination();
            }
        });
    }
    
    if (nextButton) {
        nextButton.addEventListener('click', function() {
            const maxPages = Math.ceil(getFilteredPalletRows().length / itemsPerPage);
            if (currentPage < maxPages) {
                currentPage++;
                updatePalletPagination();
            }
        });
    }
    
    updatePalletPagination();
}

function getFilteredPalletRows() {
    const filter = document.getElementById('palletSearch')?.value.toLowerCase() || '';
    const wattageFilter = document.getElementById('wattageFilter')?.value || '';
    const statusFilter = document.getElementById('statusFilter')?.value || '';
    
    return allPalletRows.filter(row => {
        if (!row || !row.cells) return false;
        
        // Get cell contents
        let textContent = '';
        for (let i = 0; i < row.cells.length; i++) {
            textContent += (row.cells[i].textContent || row.cells[i].innerText || '').toLowerCase() + ' ';
        }
        
        let showRow = true;
        
        // Check search filter
        if (filter && !textContent.includes(filter)) {
            showRow = false;
        }
        
        // Check wattage filter (wattage is in column 1, index 1)
        if (showRow && wattageFilter && row.cells[1]) {
            const cellWattage = (row.cells[1].textContent || row.cells[1].innerText || '').replace('W','').trim();
            if (cellWattage !== wattageFilter) {
                showRow = false;
            }
        }
        
        // Check status filter (status is in column 3, index 3)
        if (showRow && statusFilter && row.cells[3]) {
            const cellStatus = (row.cells[3].textContent || row.cells[3].innerText || '').trim();
            if (cellStatus !== statusFilter) {
                showRow = false;
            }
        }
        
        return showRow;
    });
}

function updatePalletPagination() {
    const filteredRows = getFilteredPalletRows();
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
}

// ----------------- SIMPLE PALLET FILTER -----------------
function filterPallets() {
    // Reset to page 1 when filter changes
    currentPage = 1;
    updatePalletPagination();
}

// ----------------- PALLETIZATION LOADING SPINNER -----------------
function showLoadingModal() {
    const modal = document.getElementById('loadingModal');
    if (modal) modal.style.display = 'block';
}

function hideLoadingModal() {
    const modal = document.getElementById('loadingModal');
    if (modal) modal.style.display = 'none';
}

// Add loading spinner to pallet generation forms
document.addEventListener('DOMContentLoaded', function() {
    const palletForms = document.querySelectorAll('.wattage-summary-block form');
    palletForms.forEach(function(form) {
        form.addEventListener('submit', function() {
            showLoadingModal();
        });
    });
    
    // Hide loading modal if page loads (in case of refresh/back button)
    hideLoadingModal();
    
    // Initialize pagination for pallets
    initializePalletPagination();
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
