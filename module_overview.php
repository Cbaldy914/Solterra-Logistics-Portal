<?php
session_name("logistics_session");
session_start();

// Allow access for admin, global_admin, customer_admin, and user roles.
// Specific functionalities will be controlled by role checks within the page.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin', 'user'])) {
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
    // Graceful handling when neither is provided
    $view_mode = 'project';
    $project_id = 0;
}

// Handle bulk pallet generation by modules per pallet
$successMessage = '';
// Track pallets created during this request to enable deep-linking to views
$created_pallet_ids = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_pallets') {
    $itemId           = intval($_POST['item_id']);
    $modulesPerPallet = max(1, intval($_POST['modules_per_pallet']));
    $updateModulesTable = isset($_POST['update_modules_table']) && $_POST['update_modules_table'] === 'true';
    
    // Get wattage and total modules for this item, plus the current modules_per_pallet from modules table
    $stmtW = $conn->prepare("
        SELECT umi.wattage, umi.quantity, m.modules_per_pallet 
        FROM unassigned_module_items umi 
        JOIN modules m ON umi.unassigned_module_id = m.id 
        WHERE umi.id = ? LIMIT 1
    ");
    $stmtW->bind_param("i", $itemId);
    $stmtW->execute();
    $stmtW->bind_result($wattage, $orderedQuantity, $currentModulesPerPallet);
    $stmtW->fetch();
    $stmtW->close();
    
    // Check if we need to update the modules table
    if ($updateModulesTable || ($currentModulesPerPallet === null && $modulesPerPallet > 0)) {
        // Update the modules_per_pallet in the modules table
        $stmtUpdate = $conn->prepare("
            UPDATE modules 
            SET modules_per_pallet = ? 
            WHERE id = (SELECT unassigned_module_id FROM unassigned_module_items WHERE id = ?)
        ");
        if ($stmtUpdate) {
            $stmtUpdate->bind_param("ii", $modulesPerPallet, $itemId);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        }
    }
    
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
            $newId = insertPallet($itemId, $wattage, $modulesPerPallet);
            if ($newId) { $created_pallet_ids[] = $newId; }
        }
        // Insert last pallet if there's a remainder
        if ($remainder > 0) {
            $newId = insertPallet($itemId, $wattage, $remainder);
            if ($newId) { $created_pallet_ids[] = $newId; }
        }
        // Persist the created pallet IDs for this session so the View Pallets button can deep-link
        if (!empty($created_pallet_ids)) {
            $_SESSION['recent_created_pallet_ids'] = [
                'ids' => $created_pallet_ids,
                'ts'  => time()
            ];
        }
        $successMessage = "Created $totalPallets pallets (up to $modulesPerPallet modules each) for $remainingModules remaining modules.";
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'undo_palletization') {
    $undoMessage = '';
    $wattage = intval($_POST['wattage']);
    $conn_undo = getDBConnection();
    if (!$conn_undo) {
        $undoMessage = "Error: Database connection failed.";
    } else {
        $conn_undo->begin_transaction();
        try {
            // Get all pallet IDs for this wattage across all batches in the current view
            $pallet_ids = [];
            
            if ($view_mode === 'project') {
                // For project view, get pallets for all batches in the project
                $stmt_get_pallets = $conn_undo->prepare("
                    SELECT ip.id 
                    FROM inventory_pallets ip 
                    JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id 
                    JOIN modules m ON umi.unassigned_module_id = m.id 
                    WHERE m.project_id = ? AND ip.wattage = ?
                ");
                $stmt_get_pallets->bind_param("ii", $project_id, $wattage);
            } else {
                // For batch view, get pallets for the specific batch
                $stmt_get_pallets = $conn_undo->prepare("
                    SELECT ip.id 
                    FROM inventory_pallets ip 
                    JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id 
                    WHERE umi.unassigned_module_id = ? AND ip.wattage = ?
                ");
                $stmt_get_pallets->bind_param("ii", $batch_id, $wattage);
            }
            
            if (!$stmt_get_pallets) throw new Exception("Failed to prepare pallet query: " . $conn_undo->error);
            
            $stmt_get_pallets->execute();
            $result_pallets = $stmt_get_pallets->get_result();
            while ($row = $result_pallets->fetch_assoc()) {
                $pallet_ids[] = $row['id'];
            }
            $stmt_get_pallets->close();
            
            if (empty($pallet_ids)) {
                throw new Exception("No pallets found for {$wattage}W modules.");
            }

            // Check if any pallets are linked to deliveries
            $placeholders = implode(',', array_fill(0, count($pallet_ids), '?'));
            $types = str_repeat('i', count($pallet_ids));
            
            $stmtCheckDeliveries = $conn_undo->prepare("SELECT inventory_pallet_id FROM delivery_pallets WHERE inventory_pallet_id IN ($placeholders)");
            if (!$stmtCheckDeliveries) throw new Exception("Failed to prepare delivery check: " . $conn_undo->error);
            
            $stmtCheckDeliveries->bind_param($types, ...$pallet_ids);
            $stmtCheckDeliveries->execute();
            $resultDeliveries = $stmtCheckDeliveries->get_result();
            $linkedPallets = [];
            while ($row = $resultDeliveries->fetch_assoc()) {
                $linkedPallets[] = $row['inventory_pallet_id'];
            }
            $stmtCheckDeliveries->close();

            if (!empty($linkedPallets)) {
                throw new Exception("Cannot undo palletization: Some {$wattage}W pallets are already linked to deliveries. Pallet IDs: " . implode(', ', $linkedPallets));
            }

            // Delete pallets
            $stmtDelete = $conn_undo->prepare("DELETE FROM inventory_pallets WHERE id IN ($placeholders)");
            if (!$stmtDelete) throw new Exception("Failed to prepare pallet deletion: " . $conn_undo->error);
            
            $stmtDelete->bind_param($types, ...$pallet_ids);
            if (!$stmtDelete->execute()) {
                throw new Exception("Failed to delete pallets: " . $stmtDelete->error);
            }
            
            $deletedCount = $stmtDelete->affected_rows;
            $stmtDelete->close();
            $conn_undo->commit();
            
            $undoMessage = "Successfully deleted all $deletedCount pallet(s) for {$wattage}W modules. These modules are now available for re-palletization.";
            
        } catch (Exception $e) {
            $conn_undo->rollback();
            $undoMessage = "Error undoing palletization: " . $e->getMessage();
        } finally {
            $conn_undo->close();
        }
    }
    
    // Store message in session and redirect to prevent form resubmission
    $_SESSION['module_overview_message'] = $undoMessage;
    
    // Construct appropriate redirect URL based on view mode
    if ($view_mode === 'project' && $project_id > 0) {
        header("Location: module_overview.php?project_id=" . $project_id);
    } else {
        header("Location: module_overview.php?batch_id=" . $batch_id);
    }
    exit();
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

    // --- Fetch assigned project_id and full manufacturer info from the parent module batch --- 
    $assignedProjectId = null; // Default to NULL
    $manufacturer = null; // Default to NULL
    $manufacturer_location_id = null; // Store the specific manufacturer location ID
    $stmtFetchProject = $conn->prepare("
        SELECT m.project_id, m.vendor_name, m.initial_location
        FROM modules m 
        JOIN unassigned_module_items umi ON m.id = umi.unassigned_module_id 
        WHERE umi.id = ? 
        LIMIT 1
    ");
    if ($stmtFetchProject) {
        $stmtFetchProject->bind_param("i", $itemId);
        if ($stmtFetchProject->execute()) {
            $stmtFetchProject->bind_result($fetchedProjectId, $vendor_name, $initial_location);
            if ($stmtFetchProject->fetch()) {
                $assignedProjectId = $fetchedProjectId; // Can be NULL if the module batch wasn't assigned
                
                // Extract manufacturer name from vendor_name
                $manufacturer = $vendor_name;
                $manufacturer_name = $vendor_name;
                if (strpos($vendor_name, ' - ') !== false) {
                    $manufacturer_name = trim(explode(' - ', $vendor_name)[0]);
                }
                
                // Close the first statement before making new queries
                $stmtFetchProject->close();
                
                // Get manufacturer ID
                $stmt_mfg = $conn->prepare("SELECT id FROM manufacturers WHERE name = ? OR short_name = ? LIMIT 1");
                if ($stmt_mfg) {
                    $stmt_mfg->bind_param("ss", $manufacturer_name, $manufacturer_name);
                    $stmt_mfg->execute();
                    $stmt_mfg->bind_result($manufacturer_id);
                    if ($stmt_mfg->fetch()) {
                        $stmt_mfg->close();
                        
                        // Try to match initial_location against manufacturer_locations addresses
                        if (!empty($initial_location)) {
                            // Parse the initial_location to extract components
                            $location_parts = array_map('trim', explode(',', $initial_location));
                            $street_address = $location_parts[0] ?? '';
                            $city = $location_parts[1] ?? '';
                            $state_zip = $location_parts[2] ?? '';
                            
                            // Extract state and zip if they're in the same part
                            $state = '';
                            $zip = '';
                            if (!empty($state_zip)) {
                                $state_zip_parts = explode(' ', trim($state_zip));
                                if (count($state_zip_parts) >= 2) {
                                    $state = implode(' ', array_slice($state_zip_parts, 0, -1));
                                    $zip = end($state_zip_parts);
                                } else {
                                    $state = $state_zip;
                                }
                            }
                            
                            // Try to find exact match first
                            $stmt_exact = $conn->prepare("
                                SELECT id FROM manufacturer_locations 
                                WHERE manufacturer_id = ? 
                                AND is_active = 1 
                                AND (
                                    (street_address = ? AND city = ? AND state = ?) OR
                                    (street_address = ? AND city = ? AND zip_code = ?) OR
                                    (street_address = ? AND city = ?) OR
                                    (CONCAT(street_address, ', ', city, ', ', state, ' ', zip_code) = ?) OR
                                    (CONCAT(street_address, ', ', city, ', ', state, ', ', zip_code) = ?)
                                )
                                LIMIT 1
                            ");
                            if ($stmt_exact) {
                                                             $stmt_exact->bind_param("isssissssss", 
                                 $manufacturer_id, 
                                 $street_address, $city, $state,
                                 $street_address, $city, $zip,
                                 $street_address, $city,
                                 $initial_location, $initial_location
                             );
                                $stmt_exact->execute();
                                $stmt_exact->bind_result($exact_location_id);
                                if ($stmt_exact->fetch()) {
                                    $manufacturer_location_id = $exact_location_id;
                                }
                                $stmt_exact->close();
                            }
                            
                            // If no exact match, try partial matches
                            if (!$manufacturer_location_id && !empty($street_address)) {
                                $stmt_partial = $conn->prepare("
                                    SELECT id FROM manufacturer_locations 
                                    WHERE manufacturer_id = ? 
                                    AND is_active = 1 
                                    AND street_address LIKE ?
                                    LIMIT 1
                                ");
                                if ($stmt_partial) {
                                    $street_search = "%{$street_address}%";
                                    $stmt_partial->bind_param("is", $manufacturer_id, $street_search);
                                    $stmt_partial->execute();
                                    $stmt_partial->bind_result($partial_location_id);
                                    if ($stmt_partial->fetch()) {
                                        $manufacturer_location_id = $partial_location_id;
                                    }
                                    $stmt_partial->close();
                                }
                            }
                        }
                        
                        // If no location match found, use primary location as fallback
                        if (!$manufacturer_location_id) {
                            $stmt_primary = $conn->prepare("SELECT id FROM manufacturer_locations WHERE manufacturer_id = ? AND is_primary = 1 AND is_active = 1 LIMIT 1");
                            if ($stmt_primary) {
                                $stmt_primary->bind_param("i", $manufacturer_id);
                                $stmt_primary->execute();
                                $stmt_primary->bind_result($primary_location_id);
                                if ($stmt_primary->fetch()) {
                                    $manufacturer_location_id = $primary_location_id;
                                }
                                $stmt_primary->close();
                            }
                        }
                    } else {
                        $stmt_mfg->close();
                    }
                }
            } else {
                $stmtFetchProject->close();
            }
        } else {
            // Log error or handle appropriately if project fetch fails
            error_log("Error executing project fetch for item ID {$itemId}: " . $stmtFetchProject->error);
            $stmtFetchProject->close();
        }
    } else {
        error_log("Error preparing project fetch for item ID {$itemId}: " . $conn->error);
    }
    // --- END --- 

    $stmtIns = $conn->prepare(
        "INSERT INTO inventory_pallets 
         (pallet_identifier, unassigned_module_item_id, assigned_project_id, wattage, quantity, status, manufacturer, manufacturer_location_id) 
         VALUES (?, ?, ?, ?, ?, 'At Manufacturer', ?, ?)"
    );
    if (!$stmtIns) {
        // Log error or handle appropriately
        error_log("Error preparing pallet insert for item ID {$itemId}: " . $conn->error);
        // Potentially throw an exception or return false to indicate failure
        return null; // Exit function if prepare fails
    }

    $emptyId = ''; // Pallet identifier will be set after insert
    // Bind parameters including the fetched assigned project ID, manufacturer, and manufacturer location ID
    $stmtIns->bind_param("siiidsi", $emptyId, $itemId, $assignedProjectId, $watt, $qty, $manufacturer, $manufacturer_location_id);
    
    if (!$stmtIns->execute()) {
        error_log("Error executing pallet insert for item ID {$itemId}: " . $stmtIns->error);
        $stmtIns->close();
        return null; // Exit function if execute fails
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
    return $newId;
}

// --- Data Fetching --- 
$batch_data = null;
$batch_items = []; // Keep this to store raw items if needed elsewhere, but we'll process into wattage summary
$pallets = [];     // Keep this raw pallet data
$summary_stats = [ // Keep overall status counts
    'status_counts' => [],
    'detailed_breakdown' => [],
];
// Aggregate structures
$wattage_summary = []; // Existing: per-wattage summary across all batches (kept for compatibility in some views)
$batch_wattage_summary = []; // NEW: per-batch, per-wattage summary used in project view UI
$umi_id_to_batch_id = []; // Map each UMI id -> module batch id
// Track UMI meta for later replacement adjustments
$item_quantity_by_id = [];
$item_wattage_by_id = [];
// Replacement tracking totals for callouts
$replacement_totals = ['pallets' => 0, 'modules' => 0];
// Replacement module totals by wattage (to subtract from 'ordered')
$replacement_modules_by_wattage = [];
// Damaged tracking for display
$damaged_totals = ['pallets' => 0, 'modules' => 0];
$damaged_by_batch_wattage = [];
$damaged_by_wattage = [];
// Replacement batch flags for labeling
$replacement_batch_set = [];

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
        if ($project_id > 0) {
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
        } else {
            $project_data = null;
        }

        // Calculate MW metrics for project view
        $project_size_mw = 0;
        $ordered_mw = 0;
        if ($project_data) {
            $project_size_mw = floatval($project_data['project_size'] ?? 0);

            // Calculate total ordered MW
            $stmtMw = $conn->prepare("
                SELECT SUM(umi.wattage * umi.quantity) / 1000000 as ordered_mw
                FROM unassigned_module_items umi
                JOIN modules m ON umi.unassigned_module_id = m.id
                WHERE m.project_id = ?
            ");
            if ($stmtMw) {
                $stmtMw->bind_param("i", $project_id);
                $stmtMw->execute();
                $mwResult = $stmtMw->get_result()->fetch_assoc();
                $ordered_mw = floatval($mwResult['ordered_mw'] ?? 0);
                $stmtMw->close();
            }
        }

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
        
        // If none found, we'll render a friendly empty state
        if (!empty($module_batches)) {
            // Set batch_data to the first batch for compatibility with existing code
            $batch_data = $module_batches[0];
        }
    }

    // Access Control for Admin role
    if ($role === 'admin' && !empty($module_batches)) {
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
        
        $stmtItems = $conn->prepare("SELECT id, unassigned_module_id, wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id IN ($placeholders_batches) AND wattage > 0 AND quantity > 0 ORDER BY unassigned_module_id, wattage ASC");
        if (!$stmtItems) throw new Exception("Prepare items fetch failed: " . $conn->error);
        $stmtItems->bind_param($types_batches, ...$batch_ids);
        $stmtItems->execute();
        $resultItems = $stmtItems->get_result();
        
        while ($item = $resultItems->fetch_assoc()) {
            $item_ids[] = $item['id'];
            $wattage = (int)$item['wattage'];
            $batchId = (int)$item['unassigned_module_id'];
            $umi_id_to_batch_id[(int)$item['id']] = $batchId;
            if (!isset($batch_wattage_summary[$batchId])) {
                $batch_wattage_summary[$batchId] = [];
            }
            if (!isset($batch_wattage_summary[$batchId][$wattage])) {
                $batch_wattage_summary[$batchId][$wattage] = [
                    'item_id' => (int)$item['id'],
                    'ordered_quantity' => 0,
                    'palletized_quantity' => 0,
                    'remaining_quantity' => 0,
                    'pallet_distribution' => [],
                    'modules_per_pallet' => null,
                    'batch_id' => $batchId
                ];
            }
            $batch_wattage_summary[$batchId][$wattage]['ordered_quantity'] += (int)$item['quantity'];
            // Maintain legacy aggregate by wattage as a sum across batches
            if (!isset($wattage_summary[$wattage])) {
                $wattage_summary[$wattage] = [
                    'item_id' => (int)$item['id'],
                    'ordered_quantity' => 0,
                    'palletized_quantity' => 0,
                    'remaining_quantity' => 0,
                    'pallet_distribution' => [],
                    'modules_per_pallet' => null,
                    'batch_id' => $batchId
                ];
            }
            $wattage_summary[$wattage]['ordered_quantity'] += (int)$item['quantity'];
            // Track UMI details for replacement adjustments later
            $item_quantity_by_id[$item['id']] = (int)$item['quantity'];
            $item_wattage_by_id[$item['id']] = (int)$item['wattage'];
            $batch_items[] = $item; // Still store raw items if needed later
        }
        $stmtItems->close();
        
        // Fetch modules_per_pallet values for each batch
        if (!empty($batch_ids)) {
            $stmtModulesPerPallet = $conn->prepare("SELECT id, modules_per_pallet FROM modules WHERE id IN ($placeholders_batches)");
            if ($stmtModulesPerPallet) {
                $stmtModulesPerPallet->bind_param($types_batches, ...$batch_ids);
                $stmtModulesPerPallet->execute();
                $resultModulesPerPallet = $stmtModulesPerPallet->get_result();
                $modules_per_pallet_data = [];
                while ($mpp = $resultModulesPerPallet->fetch_assoc()) {
                    $modules_per_pallet_data[$mpp['id']] = $mpp['modules_per_pallet'];
                }
                $stmtModulesPerPallet->close();
                
                // Associate modules_per_pallet with wattage summary
                foreach ($batch_wattage_summary as $bId => &$byW) {
                    foreach ($byW as $w => &$data) {
                        if (isset($modules_per_pallet_data[$bId])) {
                            $data['modules_per_pallet'] = $modules_per_pallet_data[$bId];
                        }
                    }
                    unset($data);
                }
                unset($byW);
                unset($data); // Clean up reference
            }
        }

        // Determine which pallets (and UMIs) are replacements for warranty claims within this view
        $replacement_pallet_id_set = [];
        $replacement_umi_id_set = [];
        $replacement_umi_qty_by_wattage = [];
        if (!empty($item_ids)) {
            $ph_items = implode(',', array_fill(0, count($item_ids), '?'));
            $types_items = str_repeat('i', count($item_ids));
            $stmtRepl = $conn->prepare(
                "SELECT ip.id as pallet_id, ip.unassigned_module_item_id as umi_id\n"
                . "FROM inventory_pallets ip\n"
                . "JOIN warranty_claim_replacements wcr ON wcr.pallet_id = ip.id\n"
                . "WHERE ip.unassigned_module_item_id IN ($ph_items)"
            );
            if ($stmtRepl) {
                $stmtRepl->bind_param($types_items, ...$item_ids);
                $stmtRepl->execute();
                $resRepl = $stmtRepl->get_result();
                while ($row = $resRepl->fetch_assoc()) {
                    $replacement_pallet_id_set[(int)$row['pallet_id']] = true;
                    $umiId = (int)$row['umi_id'];
                    $replacement_umi_id_set[$umiId] = true;
                }
                $stmtRepl->close();
            }

            // Adjust 'ordered' to remove any UMI quantities that were created
            // solely for replacement linkage (quick mode used UMI qty as template)
            if (!empty($replacement_umi_id_set)) {
                foreach ($replacement_umi_id_set as $umiId => $unused) {
                    $w = $item_wattage_by_id[$umiId] ?? null;
                    $q = $item_quantity_by_id[$umiId] ?? 0;
                    if ($w !== null && isset($wattage_summary[$w])) {
                        $wattage_summary[$w]['ordered_quantity'] = max(0, (int)$wattage_summary[$w]['ordered_quantity'] - (int)$q);
                    }
                }
            }

            // Calculate UMI quantities by wattage for replacements to subtract from 'ordered'
            if (!empty($replacement_umi_id_set)) {
                foreach ($replacement_umi_id_set as $umiId => $unused) {
                    $w = $item_wattage_by_id[$umiId] ?? null;
                    $q = $item_quantity_by_id[$umiId] ?? 0;
                    if ($w !== null) {
                        if (!isset($replacement_umi_qty_by_wattage[$w])) $replacement_umi_qty_by_wattage[$w] = 0;
                        $replacement_umi_qty_by_wattage[$w] += (int)$q;
                        if (!isset($replacement_modules_by_wattage[$w])) $replacement_modules_by_wattage[$w] = 0;
                        $replacement_modules_by_wattage[$w] += (int)$q;
                    }
                }
            }

            // Do not adjust 'ordered' yet; we will subtract replacement quantities
            // using the actual pallet quantities gathered in the main pallet loop below
        }
    }

    // Fetch associated pallets and aggregate palletized quantity by wattage
    if (!empty($item_ids)) {
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $types = str_repeat('i', count($item_ids));
        
        // First get healthy pallets for palletization calculation
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
                         AND ip.status != 'Damaged'
                       GROUP BY ip.id, ip.pallet_identifier, ip.unassigned_module_item_id, ip.wattage, ip.quantity, ip.status, ip.arrival_date, ip.current_warehouse_id, ip.current_project_id, w.name, p.project_name,
                              w.street_address, w.city, w.state, w.zip_code,
                              p.street_address, p.city, p.state, p.zip_code
                       ORDER BY ip.id ASC";
        
        // Also get damaged pallets separately for display
        $sqlDamagedPallets = "SELECT ip.id, ip.pallet_identifier, ip.unassigned_module_item_id, ip.wattage, ip.quantity, ip.status, ip.arrival_date, ip.current_warehouse_id, ip.current_project_id, w.name as warehouse_name, p.project_name,
                              w.street_address as warehouse_street, w.city as warehouse_city, w.state as warehouse_state, w.zip_code as warehouse_zip,
                              p.street_address as project_street, p.city as project_city, p.state as project_state, p.zip_code as project_zip,
                              GROUP_CONCAT(DISTINCT CONCAT(d.id, ':', COALESCE(d.bol_number, 'No BOL')) ORDER BY d.id SEPARATOR '|') as delivery_info
                       FROM inventory_pallets ip
                       LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
                       LEFT JOIN projects p ON ip.current_project_id = p.id
                       LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                       LEFT JOIN deliveries d ON dp.delivery_id = d.id
                       WHERE ip.unassigned_module_item_id IN ($placeholders)
                         AND ip.status = 'Damaged'
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
            $wattage = (int)$pallet['wattage'];
            $umiId = (int)$pallet['unassigned_module_item_id'];
            $batchId = $umi_id_to_batch_id[$umiId] ?? null;
            if ($batchId !== null && isset($batch_wattage_summary[$batchId][$wattage])) {
                // Exclude pallets created as replacements from palletization math, but still display below
                $isReplacement = isset($replacement_pallet_id_set[(int)$pallet['id']]);
                if ($isReplacement && $batchId !== null) { $replacement_batch_set[$batchId] = true; }
                // Always count pallets (including replacements) toward display palletization
                $qty = (int)$pallet['quantity'];
                $batch_wattage_summary[$batchId][$wattage]['palletized_quantity'] += $qty;
                // Track pallet distribution by quantity for this batch
                if (!isset($batch_wattage_summary[$batchId][$wattage]['pallet_distribution'][$qty])) {
                    $batch_wattage_summary[$batchId][$wattage]['pallet_distribution'][$qty] = 0;
                }
                $batch_wattage_summary[$batchId][$wattage]['pallet_distribution'][$qty]++;
                // Maintain legacy aggregate too
                if (!isset($wattage_summary[$wattage]['pallet_distribution'][$qty])) {
                    $wattage_summary[$wattage]['pallet_distribution'][$qty] = 0;
                }
                $wattage_summary[$wattage]['pallet_distribution'][$qty]++;
                $wattage_summary[$wattage]['palletized_quantity'] += $qty;
                
                // Track replacement totals for callout only
                if ($isReplacement) {
                    $replacement_totals['pallets'] += 1;
                    $replacement_totals['modules'] += $qty;
                }
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
        
        // Now process damaged pallets for display purposes only (don't count toward palletization)
        $stmtDamagedPallets = $conn->prepare($sqlDamagedPallets);
        if (!$stmtDamagedPallets) throw new Exception("Prepare damaged pallets fetch failed: " . $conn->error);
        $stmtDamagedPallets->bind_param($types, ...$item_ids);
        $stmtDamagedPallets->execute();
        $resultDamagedPallets = $stmtDamagedPallets->get_result();
        while ($pallet = $resultDamagedPallets->fetch_assoc()) {
            $wattage = $pallet['wattage'];
            $status = $pallet['status'];
            $quantity = $pallet['quantity'];
            $umiId = (int)$pallet['unassigned_module_item_id'];
            $batchId = $umi_id_to_batch_id[$umiId] ?? null;
            $isReplacementDamaged = isset($replacement_pallet_id_set[(int)$pallet['id']]);
            if ($isReplacementDamaged && $batchId !== null) { $replacement_batch_set[$batchId] = true; }
            if ($batchId !== null && isset($batch_wattage_summary[$batchId][$wattage])) {
                $batch_wattage_summary[$batchId][$wattage]['palletized_quantity'] += (int)$quantity;
                if (!isset($batch_wattage_summary[$batchId][$wattage]['pallet_distribution'][$quantity])) {
                    $batch_wattage_summary[$batchId][$wattage]['pallet_distribution'][$quantity] = 0;
                }
                $batch_wattage_summary[$batchId][$wattage]['pallet_distribution'][$quantity]++;
                if (!isset($wattage_summary[$wattage]['pallet_distribution'][$quantity])) {
                    $wattage_summary[$wattage]['pallet_distribution'][$quantity] = 0;
                }
                $wattage_summary[$wattage]['pallet_distribution'][$quantity]++;
                $wattage_summary[$wattage]['palletized_quantity'] += (int)$quantity;
                if (!isset($damaged_by_batch_wattage[$batchId])) { $damaged_by_batch_wattage[$batchId] = []; }
                if (!isset($damaged_by_batch_wattage[$batchId][$wattage])) { $damaged_by_batch_wattage[$batchId][$wattage] = ['pallets' => 0, 'modules' => 0]; }
                $damaged_by_batch_wattage[$batchId][$wattage]['pallets'] += 1;
                $damaged_by_batch_wattage[$batchId][$wattage]['modules'] += (int)$quantity;
            }
            if (!isset($damaged_by_wattage[$wattage])) { $damaged_by_wattage[$wattage] = ['pallets' => 0, 'modules' => 0]; }
            $damaged_by_wattage[$wattage]['pallets'] += 1;
            $damaged_by_wattage[$wattage]['modules'] += (int)$quantity;
            $damaged_totals['pallets'] += 1;
            $damaged_totals['modules'] += (int)$quantity;
            
            // Add to pallets array for display
            if ($pallet['status'] === 'Damaged') {
                $pallet['display_location'] = 'Damaged';
            }
            $pallets[] = $pallet;
            
            // Create status breakdown key
            $breakdown_key = $status;
            
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
        $stmtDamagedPallets->close();
    }

    // Calculate remaining quantity for each wattage. Now that palletized includes
    // both healthy and replacement pallets while 'ordered' has excluded the
    // replacement UMI templates, Remaining is simply Ordered - Palletized.
    // Per-batch remaining
    foreach ($batch_wattage_summary as $bId => &$byW) {
        foreach ($byW as $w => &$data) {
            $data['remaining_quantity'] = (int)$data['ordered_quantity'] - (int)$data['palletized_quantity'];
        }
        unset($data);
    }
    unset($byW);
    // Maintain legacy aggregate remaining as well
    foreach ($wattage_summary as $wattage => &$data) {
        $data['remaining_quantity'] = (int)$data['ordered_quantity'] - (int)$data['palletized_quantity'];
    }
    unset($data);

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
    if (!empty($batch_data['account_id'])) {
        $stmtW = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM warehouses WHERE account_id = ? ORDER BY name ASC");
        if ($stmtW) {
            $stmtW->bind_param("i", $batch_data['account_id']);
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
    }

    // Fetch Manufacturers for origin selection (with addresses from primary locations)
    $all_manufacturers = [];
    if (!empty($batch_data['account_id'])) {
        $stmtM = $conn->prepare("
            SELECT 
                m.id, 
                m.name, 
                ml.street_address, 
                ml.city, 
                ml.state, 
                ml.zip_code 
            FROM manufacturers m
            LEFT JOIN manufacturer_locations ml ON m.id = ml.manufacturer_id AND ml.is_primary = TRUE
            WHERE m.is_active = 1 AND m.account_id = ?
            ORDER BY m.name ASC");
        if ($stmtM) {
            $stmtM->bind_param("i", $batch_data['account_id']);
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
        /* Modern Page Header */
        .overview-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .overview-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .overview-header .edit-button {
            position: absolute;
            top: 32px;
            right: 32px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            text-decoration: none;
            border-radius: 12px;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
            transition: all 0.3s ease;
        }
        .overview-header .edit-button:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }
        .overview-header h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 12px 0;
            line-height: 1.2;
        }
        .overview-header p, .summary-section p {
            margin: 5px 0;
            line-height: 1.5;
            color: #6c757d;
        }
        .overview-header p strong {
            color: #293E4C;
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
    <?php
        require_once 'components/breadcrumbs.php';
        if ($view_mode === 'project') {
            echo slp_render_breadcrumbs([
                'current_label' => 'Modules',
                'project_id' => (int)$project_id
            ]);
        } else {
            $label = 'Batch: '.($batch_data ? ($batch_data['vendor_name'] ?? 'Module Batch') : 'Module Batch');
            echo slp_render_breadcrumbs([
                'current_label' => $label,
                'extra' => [ ['label' => 'Modules', 'url' => 'modules.php'] ]
            ]);
        }
    ?>

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
                <h1>Modules for <?php echo htmlspecialchars($project_data['project_name'] ?? 'Project'); ?></h1>
                <?php if (!empty($project_data)): ?>
                    <p><strong>Project Address:</strong> <?php echo htmlspecialchars($project_data['project_address']); ?></p>
                <?php endif; ?>
                <p><strong>Number of Module Batches:</strong> <?php echo count($module_batches); ?></p>
                <?php if ($project_size_mw > 0): ?>
                <div style="margin-top: 16px; padding: 20px; background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%); border: 1px solid #b8daff; border-radius: 12px;">
                    <h3 style="margin: 0 0 12px 0; color: #0056b3; font-size: 1rem;">Project Capacity</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 12px;">
                        <div style="background: white; border-radius: 8px; padding: 12px; text-align: center;">
                            <div style="font-size: 1.4rem; font-weight: 700; color: #488C9A;"><?php echo number_format($ordered_mw, 2); ?> MW</div>
                            <div style="font-size: 0.8rem; color: #6c757d;">Ordered</div>
                        </div>
                        <div style="background: white; border-radius: 8px; padding: 12px; text-align: center;">
                            <div style="font-size: 1.4rem; font-weight: 700; color: #293E4C;"><?php echo number_format($project_size_mw, 2); ?> MW</div>
                            <div style="font-size: 0.8rem; color: #6c757d;">Target</div>
                        </div>
                        <div style="background: white; border-radius: 8px; padding: 12px; text-align: center;">
                            <?php $remaining_mw = max(0, $project_size_mw - $ordered_mw); ?>
                            <div style="font-size: 1.4rem; font-weight: 700; color: <?php echo $remaining_mw > 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($remaining_mw, 2); ?> MW</div>
                            <div style="font-size: 0.8rem; color: #6c757d;">Remaining</div>
                        </div>
                    </div>
                    <?php $capacity_pct = $project_size_mw > 0 ? min(100, ($ordered_mw / $project_size_mw) * 100) : 0; ?>
                    <div style="background: #e9ecef; border-radius: 8px; height: 16px; overflow: hidden;">
                        <div style="background: linear-gradient(90deg, #488C9A 0%, #3a7086 100%); height: 100%; width: <?php echo $capacity_pct; ?>%; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 10px; font-weight: 600;">
                            <?php echo number_format($capacity_pct, 1); ?>%
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (count($module_batches) === 0): ?>
                    <div style="margin-top: 16px; padding: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center;">
                        <div style="font-size: 16px; color: #293E4C; font-weight: 600;">No modules found for this project</div>
                        <div style="font-size: 13px; color: #6b7280; margin-top: 6px;">Once you add a module batch, you can palletize and track them here.</div>
                        <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin']) && !empty($project_id)): ?>
                            <div style="margin-top: 16px;">
                                <a href="add_module_batch.php?project_id=<?php echo (int)$project_id; ?>" style="display:inline-block; padding:10px 16px; background:#488C9A; color:#fff; border-radius:8px; text-decoration:none;">+ Add Module Batch</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (count($module_batches) > 1): ?>
                    <div style="margin-top: 15px; padding: 15px; background-color: #f8f9fa; border-radius: 8px;">
                        <h3 style="margin-top: 0; color: #293E4C;">Module Batch Details:</h3>
                        <?php foreach ($module_batches as $batch): ?>
                            <div style="margin-bottom: 10px; padding: 10px; background-color: white; border-left: 4px solid #488C9A; border-radius: 4px;">
                                <strong>Batch:</strong> <?php echo htmlspecialchars($batch['vendor_name']); ?><?php echo !empty($replacement_batch_set[$batch['id']]) ? ' (replacements)' : ''; ?> 
                                <span style="color: #666;">(ID: <?php echo $batch['id']; ?>)</span><br>
                                <strong>Initial Location:</strong> <?php echo htmlspecialchars($batch['initial_location']); ?><br>
                                <strong>Date Added:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['created_at'])); ?>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <br><a href="edit_module_batch.php?project_id=<?php echo (int)$project_data['id']; ?>&batch_id=<?php echo (int)$batch['id']; ?>" style="color: #488C9A; text-decoration: none;">Edit Batch</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (count($module_batches) === 1): ?>
                    <p><strong>Batch:</strong> <?php echo htmlspecialchars($module_batches[0]['vendor_name']); ?><?php echo !empty($replacement_batch_set[$module_batches[0]['id']] ?? null) ? ' (replacements)' : ''; ?></p>
                    <p><strong>Account:</strong> <?php echo htmlspecialchars($module_batches[0]['account_name']); ?></p>
                    <p><strong>Initial Location:</strong> <?php echo htmlspecialchars($module_batches[0]['initial_location']); ?></p>
                    <p><strong>Date Added:</strong> <?php echo date('Y-m-d H:i', strtotime($module_batches[0]['created_at'])); ?></p>
                    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                    <button class="edit-button" onclick="window.location.href='edit_module_batch.php?project_id=<?php echo (int)$project_data['id']; ?>&batch_id=<?php echo (int)$module_batches[0]['id']; ?>'">Edit Batch Details</button>
                    <?php endif; ?>
                <?php endif; ?>
                
            <?php else: ?>
                <h1>Module Batch: <?php echo htmlspecialchars($batch_data['vendor_name']); ?><?php echo !empty($replacement_batch_set[$batch_data['id']] ?? null) ? ' (replacements)' : ''; ?></h1>
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
                <button class="edit-button" onclick="window.location.href='<?php echo !empty($batch_data['project_id']) ? ('edit_module_batch.php?project_id='.(int)$batch_data['project_id'].'&batch_id='.(int)$batch_id) : ('edit_module_batch.php?batch_id='.(int)$batch_id); ?>'">Edit Batch Details</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="summary-section">
            <h2 class="section-title">Summary & Pallet Generation</h2>
            
            <!-- Container for wattage blocks -->
            <div class="wattage-blocks-container">
                <?php if ($view_mode === 'project' && !empty($batch_wattage_summary)): ?>
                    <?php foreach ($module_batches as $batch): $bId = (int)$batch['id']; ?>
                        <div style="width: 100%; background:#f7fbfc; border:1px solid #e2ecef; padding:12px; border-radius:8px; margin-bottom: 20px;">
                            <h3 style="margin-top:0; color:#293E4C;">Batch #<?php echo $bId; ?> — <?php echo htmlspecialchars($batch['vendor_name'] ?? ''); ?><?php echo !empty($replacement_batch_set[$bId]) ? ' (replacements)' : ''; ?></h3>
                            <?php if (!empty($batch_wattage_summary[$bId])): ?>
                                <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 15px;">
                                <?php foreach ($batch_wattage_summary[$bId] as $wattage => $data): ?>
                                    <div class="wattage-summary-block">
                                        <h4><?php echo htmlspecialchars($wattage); ?>W Modules</h4>
                                        <p><strong>Ordered:</strong> <?php echo number_format($data['ordered_quantity']); ?></p>
                                        <p><strong>On Pallets:</strong> <?php echo number_format($data['palletized_quantity']); ?></p>
                                        <?php $damP = $damaged_by_batch_wattage[$bId][$wattage]['pallets'] ?? 0; $damM = $damaged_by_batch_wattage[$bId][$wattage]['modules'] ?? 0; if ($damM > 0): ?>
                                            <p style="color:#b45309; font-size:0.92em;"><strong>Includes Damaged:</strong> <?php echo (int)$damP; ?> pallet<?php echo $damP===1?'':'s'; ?> (<?php echo number_format($damM); ?> modules)</p>
                                        <?php endif; ?>
                                        <?php if ($data['palletized_quantity'] > 0 && !empty($data['pallet_distribution'])): ?>
                                            <div style="margin: 8px 0; padding: 8px; background-color: #f8f9fa; border-radius: 4px; border-left: 3px solid #488C9A;">
                                                <strong>Pallet Distribution:</strong><br>
                                                <?php $distribution=$data['pallet_distribution']; krsort($distribution); $parts=[]; foreach ($distribution as $mpp=>$cnt){ $parts[] = $cnt.' '.($cnt===1?'pallet':'pallets')." with {$mpp} modules"; } echo '<span style="font-size:0.9em;color:#555;">'.implode('<br>', $parts).'</span>'; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($data['remaining_quantity'] < 0): ?>
                                            <p><strong>Over-palletized:</strong> <span style="color:#d32f2f;">&nbsp;<?php echo number_format(abs($data['remaining_quantity'])); ?> excess modules</span></p>
                                            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','global_admin'])): ?>
                                                <form method="POST" style="margin-top: 10px;">
                                                    <input type="hidden" name="action" value="undo_palletization">
                                                    <input type="hidden" name="wattage" value="<?php echo $wattage; ?>">
                                                    <button type="submit" onclick="return confirm('Are you sure you want to delete ALL pallets for <?php echo $wattage; ?>W modules in this batch?');" style="background-color: #dc3545; color: white; border: none; padding: 6px 10px; border-radius: 3px; cursor: pointer; font-size: 0.85em;">
                                                        Undo All Palletization (Batch)
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php elseif ($data['remaining_quantity'] > 0): ?>
                                            <p><strong>Remaining:</strong> <span style="color:#2e7d32;">&nbsp;<?php echo number_format($data['remaining_quantity']); ?></span></p>
                                            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','global_admin'])): ?>
                                                <form method="POST" class="palletization-form" onsubmit="return handlePalletizationSubmit(event)">
                                                    <input type="hidden" name="action" value="generate_pallets">
                                                    <input type="hidden" name="item_id" value="<?php echo $data['item_id']; ?>">
                                                    <input type="hidden" name="remaining_modules" value="<?php echo $data['remaining_quantity']; ?>">
                                                    <input type="hidden" name="wattage" value="<?php echo $wattage; ?>">
                                                    <input type="hidden" name="batch_id" value="<?php echo $data['batch_id']; ?>">
                                                    <input type="hidden" name="current_modules_per_pallet" value="<?php echo $data['modules_per_pallet'] ?? ''; ?>">
                                                    <input type="hidden" name="update_modules_table" value="false">
                                                    <div>
                                                        <label for="modules_per_pallet_<?php echo $bId.'_'.$wattage; ?>">Modules per Pallet:</label>
                                                        <input type="number" name="modules_per_pallet" id="modules_per_pallet_<?php echo $bId.'_'.$wattage; ?>" min="1" value="<?php echo $data['modules_per_pallet'] ?? 1; ?>" required data-original-value="<?php echo $data['modules_per_pallet'] ?? ''; ?>" data-batch-id="<?php echo $data['batch_id']; ?>">
                                                        <button type="submit">Generate</button>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <p><strong>Remaining:</strong> <span style="color:green;">0 (Perfect match)</span></p>
                                            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','global_admin'])): ?>
                                                <form method="POST" style="margin-top: 10px;">
                                                    <input type="hidden" name="action" value="undo_palletization">
                                                    <input type="hidden" name="wattage" value="<?php echo $wattage; ?>">
                                                    <button type="submit" onclick="return confirm('Are you sure you want to delete ALL pallets for <?php echo $wattage; ?>W modules in this batch?');" style="background-color: #dc3545; color: white; border: none; padding: 6px 10px; border-radius: 3px; cursor: pointer; font-size: 0.85em;">
                                                        Undo All Palletization (Batch)
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p style="margin:0; color:#6c757d;">No module items in this batch.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <?php if (!empty($wattage_summary)):
                    foreach ($wattage_summary as $wattage => $data):
                ?>
                        <div class="wattage-summary-block">
                            <h4><?php echo htmlspecialchars($wattage); ?>W Modules</h4>
                            <p><strong>Ordered:</strong> <?php echo number_format($data['ordered_quantity']); ?></p>
                            <p><strong>On Pallets:</strong> <?php echo number_format($data['palletized_quantity']); ?></p>
                            <?php $damP2 = $damaged_by_wattage[$wattage]['pallets'] ?? 0; $damM2 = $damaged_by_wattage[$wattage]['modules'] ?? 0; if ($damM2 > 0): ?>
                                <p style="color:#b45309; font-size:0.92em;"><strong>Includes Damaged:</strong> <?php echo (int)$damP2; ?> pallet<?php echo $damP2===1?'':'s'; ?> (<?php echo number_format($damM2); ?> modules)</p>
                            <?php endif; ?>
                            
                            <?php if ($data['palletized_quantity'] > 0 && !empty($data['pallet_distribution'])): ?>
                                <div style="margin: 8px 0; padding: 8px; background-color: #f8f9fa; border-radius: 4px; border-left: 3px solid #488C9A;">
                                    <strong>Pallet Distribution:</strong><br>
                                    <?php 
                                    // Sort by quantity (descending) for better readability
                                    $distribution = $data['pallet_distribution'];
                                    krsort($distribution);
                                    $distribution_parts = [];
                                    foreach ($distribution as $modules_per_pallet => $pallet_count) {
                                        $distribution_parts[] = "{$pallet_count} " . ($pallet_count === 1 ? "pallet" : "pallets") . " with {$modules_per_pallet} modules";
                                    }
                                    echo '<span style="font-size: 0.9em; color: #555;">' . implode('<br>', $distribution_parts) . '</span>';
                                    ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($data['remaining_quantity'] < 0): ?>
                                <p><strong>Over-palletized:</strong> <span style="color: #d32f2f;"><?php echo number_format(abs($data['remaining_quantity'])); ?> excess modules</span></p>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <p style="color: #d32f2f; font-size: 0.9em; margin-top: 10px;">
                                        ⚠️ You have <?php echo number_format(abs($data['remaining_quantity'])); ?> more modules on pallets than ordered. 
                                        Consider removing excess pallets via the pallet list below.
                                    </p>
                                    <?php if ($data['palletized_quantity'] > 0): ?>
                                        <form method="POST" style="margin-top: 15px;">
                                            <input type="hidden" name="action" value="undo_palletization">
                                            <input type="hidden" name="wattage" value="<?php echo $wattage; ?>">
                                            <button type="submit" onclick="return confirm('Are you sure you want to delete ALL pallets for <?php echo $wattage; ?>W modules? This will make all modules available for re-palletization.')" style="background-color: #dc3545; color: white; border: none; padding: 8px 12px; border-radius: 3px; cursor: pointer; font-size: 0.9em;">
                                                Undo All Palletization
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php elseif ($data['remaining_quantity'] > 0): ?>
                                <p><strong>Remaining:</strong> <span style="color: #2e7d32;"><?php echo number_format($data['remaining_quantity']); ?></span></p>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <form method="POST" class="palletization-form" onsubmit="return handlePalletizationSubmit(event)">
                                        <input type="hidden" name="action" value="generate_pallets">
                                        <input type="hidden" name="item_id" value="<?php echo $data['item_id']; ?>">
                                        <input type="hidden" name="remaining_modules" value="<?php echo $data['remaining_quantity']; ?>">
                                        <input type="hidden" name="wattage" value="<?php echo $wattage; ?>">
                                        <input type="hidden" name="batch_id" value="<?php echo $data['batch_id']; ?>">
                                        <input type="hidden" name="current_modules_per_pallet" value="<?php echo $data['modules_per_pallet'] ?? ''; ?>">
                                        <input type="hidden" name="update_modules_table" value="false">
                                        <div>
                                            <label for="modules_per_pallet_<?php echo $wattage; ?>">Modules per Pallet:</label>
                                            <input type="number" name="modules_per_pallet" id="modules_per_pallet_<?php echo $wattage; ?>" min="1" value="<?php echo $data['modules_per_pallet'] ?? 1; ?>" required data-original-value="<?php echo $data['modules_per_pallet'] ?? ''; ?>" data-batch-id="<?php echo $data['batch_id']; ?>">
                                            <button type="submit">Generate</button>
                                        </div>
                                    </form>
                                    <?php if ($data['palletized_quantity'] > 0): ?>
                                        <form method="POST" style="margin-top: 10px;">
                                            <input type="hidden" name="action" value="undo_palletization">
                                            <input type="hidden" name="wattage" value="<?php echo $wattage; ?>">
                                            <button type="submit" onclick="return confirm('Are you sure you want to delete ALL pallets for <?php echo $wattage; ?>W modules? This will make all modules available for re-palletization.')" style="background-color: #dc3545; color: white; border: none; padding: 6px 10px; border-radius: 3px; cursor: pointer; font-size: 0.85em;">
                                                Undo All Palletization
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <p><strong>Remaining:</strong> <span style="color: green;">0 (Perfect match)</span></p>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <p style="color: green; margin-top: 15px;">✅ All modules perfectly palletized.</p>
                                    <?php if ($data['palletized_quantity'] > 0): ?>
                                        <form method="POST" style="margin-top: 10px;">
                                            <input type="hidden" name="action" value="undo_palletization">
                                            <input type="hidden" name="wattage" value="<?php echo $wattage; ?>">
                                            <button type="submit" onclick="return confirm('Are you sure you want to delete ALL pallets for <?php echo $wattage; ?>W modules? This will make all modules available for re-palletization.')" style="background-color: #dc3545; color: white; border: none; padding: 6px 10px; border-radius: 3px; cursor: pointer; font-size: 0.85em;">
                                                Undo All Palletization
                                            </button>
                                        </form>
                                    <?php endif; ?>
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
            <?php endif; ?>
        </div>

        <!-- Separate Overall Pallet Status Breakdown section -->
        <div class="pallet-status-section">
            <h3 style="margin-top: 30px;">Overall Pallet Status Breakdown:</h3>
            <?php if (!empty($summary_stats['detailed_breakdown'])): ?>
                <div class="status-breakdown-detailed">
                    <?php foreach ($summary_stats['detailed_breakdown'] as $status => $data): ?>
                        <div class="status-item" style="margin-bottom: 15px; padding: 12px; background-color: #f8f9fa; border-left: 4px solid #488C9A; border-radius: 4px;">
                            <div style="font-weight: 600; color: #293E4C; margin-bottom: 8px;">
                                <?php echo htmlspecialchars($status); ?>: 
                                <span style="color: #488C9A;"><?php echo $data['pallet_count']; ?> pallets, <?php echo number_format($data['total_modules']); ?> modules</span>
                                <?php if ($status === 'Damaged'): ?>
                                    <?php if (($replacement_totals['pallets'] ?? 0) > 0): ?>
                                        <span style="margin-left: 10px; color: #2e7d32; font-weight: 600;">
                                            (Replacements created: <?php echo (int)$replacement_totals['pallets']; ?> pallets, <?php echo number_format((int)$replacement_totals['modules']); ?> modules)
                                        </span>
                                    <?php else: ?>
                                        <span style="margin-left: 10px; color: #d32f2f; font-weight: 600;">(No replacements created yet)</span>
                                    <?php endif; ?>
                                <?php endif; ?>
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
                
                <!-- Links to Movement view and consolidated Pallets view -->
                <div style="margin-top: 20px; text-align: center; display:flex; flex-direction:column; align-items:center; gap:8px;">
                    <?php 
                    $movement_url = "module_movements?batch_id=" . urlencode($batch_id);
                    if (!empty($batch_data['project_id'])) {
                        $movement_url .= "&project_id=" . urlencode($batch_data['project_id']);
                    }

                    // Build deep-link for pallets created in this session (if any)
                    $pallet_ids_query = '';
                    if (!empty($_SESSION['recent_created_pallet_ids']['ids'])) {
                        $ts_ok = !empty($_SESSION['recent_created_pallet_ids']['ts']) && (time() - intval($_SESSION['recent_created_pallet_ids']['ts']) < 600);
                        $ids = array_filter(array_map('intval', $_SESSION['recent_created_pallet_ids']['ids']));
                        if ($ts_ok && !empty($ids)) {
                            $pallet_ids_query = 'pallet_ids=' . urlencode(implode(',', $ids));
                        }
                    }
                    // Choose destination page based on role
                    $pallets_target_base = (in_array($role, ['admin','global_admin'])) ? 'create_shipment.php' : 'manage_pallets.php';
                    $pallets_url_parts = [];
                    if (!empty($batch_data['project_id'])) { $pallets_url_parts[] = 'project_id=' . urlencode($batch_data['project_id']); }
                    if (!empty($pallet_ids_query)) { $pallets_url_parts[] = $pallet_ids_query; }
                    $pallets_url = $pallets_target_base . (empty($pallets_url_parts) ? '' : '?' . implode('&', $pallets_url_parts));
                    ?>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center;">
                        <a href="<?php echo $movement_url; ?>" class="action-button" style="background-color: #488C9A; color: white; padding: 12px 24px; font-size: 1em; text-decoration: none; display: inline-block;">
                            <strong>📍 View Module Movement Map</strong>
                        </a>
                        <a href="<?php echo $pallets_url; ?>" class="action-button" style="background-color: #488C9A; color: white; padding: 12px 24px; font-size: 1em; text-decoration: none; display: inline-block;">
                            <strong>📦 View Pallets</strong>
                        </a>
                    </div>
                    <p style="font-size: 0.9em; color: #666;">
                        View and manage pallets in the consolidated table
                    </p>
                </div>
            <?php else: ?>
                <p>No pallets have been created/recorded for this batch yet.</p>
            <?php endif; ?>
        </div>

        <!-- Pallets table removed in favor of consolidated View Pallets page -->

    <?php endif; ?>
    <?php if (empty($errorMessage) && empty($batch_data)): ?>
        <?php if (in_array($role, ['admin', 'global_admin'])): ?>
            <div style="text-align: center; padding: 60px 20px; background: #f8f9fa; border-radius: 12px; margin: 20px 0;">
                <div style="font-size: 3em; margin-bottom: 20px; color: #6c757d;">📦</div>
                <h2 style="color: #293E4C; margin-bottom: 16px;">No Modules Found</h2>
                <p style="color: #6c757d; margin-bottom: 32px; font-size: 1.1em;">
                    This project doesn't have any modules assigned yet. Would you like to add some?
                </p>
                <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" 
                   style="background: #488C9A; color: white; padding: 16px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 1.1em; transition: all 0.3s ease; display: inline-block;">
                    ➕ Add Module Batch
                </a>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 60px 20px; background: #f8f9fa; border-radius: 12px; margin: 20px 0;">
                <div style="font-size: 3em; margin-bottom: 20px; color: #6c757d;">📦</div>
                <h2 style="color: #293E4C; margin-bottom: 16px;">No Modules Assigned</h2>
                <p style="color: #6c757d; font-size: 1.1em;">
                    There are no modules assigned to this project currently.
                </p>
                
            </div>
        <?php endif; ?>
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

<!-- Warning Modal for Uneven Palletization -->
<div id="warningModal" class="modal">
    <div class="modal-content">
        <span class="close-modal-btn" onclick="closeWarningModal()">&times;</span>
        <h2>Palletization Warning</h2>
        <div id="warningMessage"></div>
        <div style="margin-top: 20px; text-align: right;">
            <button type="button" onclick="closeWarningModal()" style="background-color: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 4px; margin-right: 10px; cursor: pointer;">
                Cancel
            </button>
            <button type="button" onclick="confirmPalletization()" style="background-color: #488C9A; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                Proceed
            </button>
        </div>
    </div>
</div>

<!-- Modal for Modules Per Pallet Change Confirmation -->
<div id="modulesPerPalletModal" class="modal">
    <div class="modal-content">
        <span class="close-modal-btn" onclick="closeModulesPerPalletModal()">&times;</span>
        <h2>Update Modules Per Pallet?</h2>
        <div id="modulesPerPalletMessage"></div>
        <div style="margin-top: 20px; text-align: right;">
            <button type="button" onclick="closeModulesPerPalletModal()" style="background-color: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 4px; margin-right: 10px; cursor: pointer;">
                Keep Original
            </button>
            <button type="button" onclick="confirmModulesPerPalletUpdate()" style="background-color: #488C9A; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                Update & Proceed
            </button>
        </div>
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

// ----------------- PALLETIZATION WARNING MODAL -----------------
let currentForm = null;

function handlePalletizationSubmit(event) {
    event.preventDefault();
    
    const form = event.target;
    const modulesPerPallet = parseInt(form.querySelector('input[name="modules_per_pallet"]').value);
    const remainingModules = parseInt(form.querySelector('input[name="remaining_modules"]').value);
    const wattage = form.querySelector('input[name="wattage"]').value;
    const currentModulesPerPallet = form.querySelector('input[name="current_modules_per_pallet"]').value;
    const originalValue = form.querySelector('input[name="modules_per_pallet"]').getAttribute('data-original-value');
    const updateField = form.querySelector('input[name="update_modules_table"]');
    const updateApproved = updateField && updateField.value === 'true';
    
    if (isNaN(modulesPerPallet) || modulesPerPallet <= 0) {
        alert('Please enter a valid number of modules per pallet.');
        return false;
    }
    
    // Check if the entered value differs from the database value
    const dbValue = currentModulesPerPallet ? parseInt(currentModulesPerPallet) : null;
    if (dbValue !== null && dbValue !== modulesPerPallet && !updateApproved) {
        // Show modules per pallet confirmation modal
        currentForm = form;
        showModulesPerPalletModal(dbValue, modulesPerPallet, wattage);
        return false;
    }
    
    // If no database value exists, mark to update the modules table
    if (dbValue === null && modulesPerPallet > 0) {
        form.querySelector('input[name="update_modules_table"]').value = 'true';
    }
    
    // Proceed with palletization checks
    const fullPallets = Math.floor(remainingModules / modulesPerPallet);
    const remainder = remainingModules % modulesPerPallet;
    
    if (remainder === 0) {
        // Even distribution, proceed without warning
        showLoadingModal();
        form.submit();
        return true;
    } else {
        // Uneven distribution, show warning
        currentForm = form;
        showWarningModal(fullPallets, modulesPerPallet, remainder, wattage);
        return false;
    }
}

function showWarningModal(fullPallets, modulesPerPallet, remainder, wattage) {
    const modal = document.getElementById('warningModal');
    const messageDiv = document.getElementById('warningMessage');
    
    let message = `<div style="background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 15px; margin-bottom: 15px;">
        <h4 style="margin-top: 0; color: #856404;">⚠️ Uneven Distribution Warning</h4>
        <p style="margin-bottom: 10px; color: #856404;">This will create an uneven distribution of ${wattage}W modules:</p>
        <ul style="margin: 10px 0; padding-left: 20px; color: #856404;">`;
    
    if (fullPallets > 0) {
        message += `<li><strong>${fullPallets}</strong> ${fullPallets === 1 ? 'pallet' : 'pallets'} with <strong>${modulesPerPallet}</strong> modules each</li>`;
    }
    
    message += `<li><strong>1</strong> pallet with <strong>${remainder}</strong> modules</li>
        </ul>
        <p style="margin-bottom: 0; color: #856404;"><strong>Do you want to proceed with this distribution?</strong></p>
    </div>`;
    
    messageDiv.innerHTML = message;
    modal.style.display = 'block';
}

function closeWarningModal() {
    const modal = document.getElementById('warningModal');
    modal.style.display = 'none';
}

function confirmPalletization() {
    if (currentForm) {
        const form = currentForm;
        closeWarningModal();
        showLoadingModal();
        form.submit();
    }
}

// ----------------- MODULES PER PALLET MODAL -----------------
function showModulesPerPalletModal(originalValue, newValue, wattage) {
    const modal = document.getElementById('modulesPerPalletModal');
    const messageDiv = document.getElementById('modulesPerPalletMessage');
    
    const message = `<div style="background-color: #e3f2fd; border: 1px solid #bbdefb; border-radius: 4px; padding: 15px; margin-bottom: 15px;">
        <h4 style="margin-top: 0; color: #1976d2;">⚠️ Modules Per Pallet Difference Detected</h4>
        <p style="margin-bottom: 10px; color: #1976d2;">The modules per pallet you entered is different than what was set up for this project:</p>
        <ul style="margin: 10px 0; padding-left: 20px; color: #1976d2;">
            <li><strong>Original (Project Setting):</strong> ${originalValue} modules per pallet</li>
            <li><strong>Your Entry:</strong> ${newValue} modules per pallet</li>
        </ul>
        <p style="margin-bottom: 0; color: #1976d2;"><strong>Do you want to change this to ${newValue} modules per pallet for this ${wattage}W module batch?</strong></p>
        <p style="margin: 10px 0 0 0; font-size: 0.9em; color: #666;">This will update the project settings for future palletizations.</p>
    </div>`;
    
    messageDiv.innerHTML = message;
    modal.style.display = 'block';
}

function closeModulesPerPalletModal() {
    const modal = document.getElementById('modulesPerPalletModal');
    modal.style.display = 'none';
}

function confirmModulesPerPalletUpdate() {
    if (currentForm) {
        const form = currentForm;
        // Set flag to update modules table
        const updateField = form.querySelector('input[name="update_modules_table"]');
        if (updateField) updateField.value = 'true';
        closeModulesPerPalletModal();
        
        // Continue with normal palletization flow
        const modulesPerPallet = parseInt(form.querySelector('input[name="modules_per_pallet"]').value);
        const remainingModules = parseInt(form.querySelector('input[name="remaining_modules"]').value);
        const wattage = form.querySelector('input[name="wattage"]').value;
        
        const fullPallets = Math.floor(remainingModules / modulesPerPallet);
        const remainder = remainingModules % modulesPerPallet;
        
        if (remainder === 0) {
            // Even distribution, proceed without warning
            showLoadingModal();
            form.submit();
        } else {
            // Uneven distribution, show warning
            showWarningModal(fullPallets, modulesPerPallet, remainder, wattage);
        }
    }
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const warningModal = document.getElementById('warningModal');
    const modulesPerPalletModal = document.getElementById('modulesPerPalletModal');
    
    if (event.target === warningModal) {
        closeWarningModal();
    } else if (event.target === modulesPerPalletModal) {
        closeModulesPerPalletModal();
    }
}

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

// Add loading spinner to non-palletization forms and initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Hide loading modal if page loads (in case of refresh/back button)
    hideLoadingModal();
    
    // Initialize pagination for pallets
    initializePalletPagination();
});
</script>
</body>
</html>
