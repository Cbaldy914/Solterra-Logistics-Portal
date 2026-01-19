<?php
session_name("logistics_session");
session_start();

// Increase memory limit for handling large pallet datasets
ini_set('memory_limit', '512M');

require_once '../config.php'; // Placed early for CSV processing too
require_once 'milestone_helpers.php';

// Initialize messages array if not already set
if (!isset($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

// --- Handle CSV Upload for Linking Pallets to Deliveries ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_link_csv'])) {
    $conn_csv = getDBConnection(); // Use a separate connection variable for clarity within this block
    if (!$conn_csv) {
        $_SESSION['messages'][] = "<p class='error-message'>Database connection failed during CSV upload.</p>";
    } else {
        if (isset($_FILES['link_csv_file']) && $_FILES['link_csv_file']['error'] == 0) {
            $fileTmpPath = $_FILES['link_csv_file']['tmp_name'];
            $fileName    = $_FILES['link_csv_file']['name'];
            $fileSize    = $_FILES['link_csv_file']['size'];
            $fileType    = $_FILES['link_csv_file']['type'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedFileTypes = ['text/csv'];
            $allowedExtension = 'csv';
            $maxFileSize      = 5 * 1024 * 1024; // 5MB

            if (($fileType == $allowedFileTypes[0] || $fileExtension == $allowedExtension) && $fileSize <= $maxFileSize) {
                $csvFile = fopen($fileTmpPath, 'r');
                if ($csvFile === false) {
                    $_SESSION['messages'][] = "<p class='error-message'>Error opening the uploaded CSV file.</p>";
                } else {
                    $header = fgetcsv($csvFile); // Read the header row
                    $expectedHeaders = ['delivery_id', 'pallet_identifier'];
                    $header = array_map('trim', $header); // Trim whitespace from headers

                    if ($header !== $expectedHeaders) {
                        $_SESSION['messages'][] = "<p class='error-message'>CSV header mismatch. Expected columns: " . implode(', ', $expectedHeaders) . ". Found: " . implode(', ', $header) . "</p>";
                    } else {
                        $conn_csv->begin_transaction();
                        $linked_count = 0;
                        $error_count = 0;
                        $line_number = 1; // For error reporting, header is line 1
                        $milestone_triggered_deliveries = [];

                        $stmt_check_delivery = $conn_csv->prepare("SELECT id, wattage, status_of_delivery, project_id, warehouse_id, anticipated_delivery_date FROM deliveries WHERE id = ?");
                        $stmt_check_pallet = $conn_csv->prepare("SELECT id, wattage, status FROM inventory_pallets WHERE pallet_identifier = ?");
                        $stmt_check_existing_link = $conn_csv->prepare("SELECT COUNT(*) FROM delivery_pallets WHERE delivery_id = ? AND inventory_pallet_id = ?");
                        $stmt_insert_link = $conn_csv->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
                        $stmt_update_pallet_comprehensive = $conn_csv->prepare("UPDATE inventory_pallets SET status = ?, current_project_id = ?, current_warehouse_id = ?, arrival_date = ? WHERE id = ?");

                        while (($row = fgetcsv($csvFile)) !== FALSE) {
                            $line_number++;
                            if (count($row) !== count($expectedHeaders)) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Column count mismatch.</p>";
                                $error_count++;
                                continue;
                            }
                            $data = array_combine($header, $row);
                            $delivery_id_csv = trim($data['delivery_id']);
                            $pallet_identifier_csv = trim($data['pallet_identifier']);

                            if (empty($delivery_id_csv) || empty($pallet_identifier_csv)) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: delivery_id or pallet_identifier is empty. Skipping.</p>";
                                $error_count++;
                                continue;
                            }

                            // Validate Delivery - now fetching full delivery details
                            $stmt_check_delivery->bind_param("i", $delivery_id_csv);
                            $stmt_check_delivery->execute();
                            $result_delivery = $stmt_check_delivery->get_result();
                            if ($result_delivery->num_rows === 0) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Delivery ID '{$delivery_id_csv}' not found. Skipping.</p>";
                                $error_count++;
                                continue;
                            }
                            $delivery_data = $result_delivery->fetch_assoc();
                            $delivery_wattage = $delivery_data['wattage'];
                            $delivery_status = $delivery_data['status_of_delivery'];
                            $delivery_project_id = $delivery_data['project_id'];
                            $delivery_warehouse_id = $delivery_data['warehouse_id'];
                            $delivery_arrival_date = $delivery_data['anticipated_delivery_date'];

                            // Validate Pallet Identifier
                            $stmt_check_pallet->bind_param("s", $pallet_identifier_csv);
                            $stmt_check_pallet->execute();
                            $result_pallet = $stmt_check_pallet->get_result();
                            if ($result_pallet->num_rows === 0) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Pallet Identifier '{$pallet_identifier_csv}' not found. Skipping.</p>";
                                $error_count++;
                                continue;
                            }
                            $pallet_data = $result_pallet->fetch_assoc();
                            $inventory_pallet_id = $pallet_data['id'];
                            $pallet_wattage = $pallet_data['wattage'];
                            $pallet_status = $pallet_data['status'];

                            // Check Wattage Match
                            if ($delivery_wattage !== $pallet_wattage) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Wattage mismatch. Delivery '{$delivery_id_csv}' ({$delivery_wattage}W) vs Pallet '{$pallet_identifier_csv}' ({$pallet_wattage}W). Skipping.</p>";
                                $error_count++;
                                continue;
                            }

                            // Check Pallet Status (Example: allow linking if 'At Manufacturer' or 'In Warehouse')
                            $linkable_statuses = ['At Manufacturer', 'In Warehouse', 'Available']; // Add more as needed
                            if (!in_array($pallet_status, $linkable_statuses)) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Pallet '{$pallet_identifier_csv}' has status '{$pallet_status}' which is not linkable. Skipping.</p>";
                                $error_count++;
                                continue;
                            }

                            // Check for existing link
                            $stmt_check_existing_link->bind_param("ii", $delivery_id_csv, $inventory_pallet_id);
                            $stmt_check_existing_link->execute();
                            $result_existing_link = $stmt_check_existing_link->get_result();
                            $existing_link_row = $result_existing_link->fetch_assoc();
                            $link_count = $existing_link_row ? $existing_link_row['COUNT(*)'] : 0;
                            $result_existing_link->free();
                            if ($link_count > 0) {
                                $_SESSION['messages'][] = "<p class='info-message'>Line {$line_number}: Pallet '{$pallet_identifier_csv}' already linked to Delivery ID '{$delivery_id_csv}'. Skipping.</p>";
                                // Not strictly an error, but good to inform.
                                continue;
                            }

                            // All checks passed, attempt to insert link
                            $stmt_insert_link->bind_param("ii", $delivery_id_csv, $inventory_pallet_id);
                            if ($stmt_insert_link->execute()) {
                                // Update pallet status, location, and arrival date to match delivery
                                $new_pallet_status = $delivery_status; // Sync with delivery status
                                $new_current_project_id = $delivery_project_id; // Sync project assignment
                                $new_current_warehouse_id = $delivery_warehouse_id; // Sync warehouse assignment
                                $new_arrival_date = $delivery_arrival_date; // Sync arrival date
                                
                                $stmt_update_pallet_comprehensive->bind_param("siiss", $new_pallet_status, $new_current_project_id, $new_current_warehouse_id, $new_arrival_date, $inventory_pallet_id);
                                if (!$stmt_update_pallet_comprehensive->execute()) {
                                    $_SESSION['messages'][] = "<p class='warning-message'>Line {$line_number}: Pallet '{$pallet_identifier_csv}' linked to Delivery ID '{$delivery_id_csv}', but failed to update pallet status and location: " . htmlspecialchars($stmt_update_pallet_comprehensive->error) . "</p>";
                                    // Decide if this is a critical error to rollback or just a warning
                                }
                                
                                // Update delivery origin information if not already set
                                $stmt_check_origin = $conn_csv->prepare("SELECT origin_type FROM deliveries WHERE id = ?");
                                $stmt_check_origin->bind_param("i", $delivery_id_csv);
                                $stmt_check_origin->execute();
                                $result_origin = $stmt_check_origin->get_result();
                                $origin_data = $result_origin->fetch_assoc();
                                $stmt_check_origin->close();
                                
                                // If delivery doesn't have origin set, default to manufacturer
                                if (!$origin_data || empty($origin_data['origin_type'])) {
                                    $stmt_update_origin = $conn_csv->prepare("UPDATE deliveries SET origin_type = 'manufacturer', origin_id = NULL WHERE id = ?");
                                    $stmt_update_origin->bind_param("i", $delivery_id_csv);
                                    if (!$stmt_update_origin->execute()) {
                                        $_SESSION['messages'][] = "<p class='warning-message'>Line {$line_number}: Failed to set origin for Delivery ID '{$delivery_id_csv}': " . htmlspecialchars($stmt_update_origin->error) . "</p>";
                                    }
                                    $stmt_update_origin->close();
                                }

                                if (!isset($milestone_triggered_deliveries[$delivery_id_csv])) {
                                    trigger_delivery_milestones_for_status($delivery_id_csv, $delivery_status, $conn_csv, $_SESSION['user_id'] ?? null);
                                    $milestone_triggered_deliveries[$delivery_id_csv] = true;
                                }
                                
                                $linked_count++;
                            } else {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Failed to link Pallet '{$pallet_identifier_csv}' to Delivery ID '{$delivery_id_csv}'. Error: " . htmlspecialchars($stmt_insert_link->error) . "</p>";
                                $error_count++;
                            }
                        }
                        fclose($csvFile);

                        if ($error_count > 0) {
                            $conn_csv->rollback();
                            $_SESSION['messages'][] = "<p class='error-message'>CSV import finished with {$error_count} errors. No changes were committed. Please review messages and try again.</p>";
                        } else {
                            $conn_csv->commit();
                                                    if ($linked_count > 0) {
                            $_SESSION['messages'][] = "<p class='success-message'>Successfully linked {$linked_count} pallet(s) from the CSV with synchronized status, locations, and origin tracking.</p>";
                        } else {
                            $_SESSION['messages'][] = "<p class='info-message'>No new pallet links were made from the CSV (either all were already linked or no valid entries found).</p>";
                        }
                        }
                        // Close prepared statements
                        $stmt_check_delivery->close();
                        $stmt_check_pallet->close();
                        $stmt_check_existing_link->close();
                        $stmt_insert_link->close();
                        $stmt_update_pallet_comprehensive->close();
                    }
                }
            } else {
                $_SESSION['messages'][] = "<p class='error-message'>Invalid file type or file too large. Please upload a valid CSV file (max 5MB).</p>";
            }
        } else {
            $_SESSION['messages'][] = "<p class='error-message'>Error uploading the CSV file. Code: {$_FILES['link_csv_file']['error']}</p>";
        }
        if ($conn_csv) $conn_csv->close();
        // Redirect to avoid form resubmission on refresh
        header("Location: link_pallet_deliveries.php?" . http_build_query($_GET)); // Preserve existing GET params
        exit();
    }
}
// End CSV Upload Handling

// --- Handle Bulk Linking ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_link') {
    header('Content-Type: application/json');
    
    $conn_bulk = getDBConnection();
    if (!$conn_bulk) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit();
    }
    
    try {
        $delivery_ids = json_decode($_POST['delivery_ids'], true);
        $pallet_ids = json_decode($_POST['pallet_ids'], true);
        $strategy = $_POST['strategy'] ?? 'module_based';
        $wattage = $_POST['wattage'] ?? '';
        $pallets_per_truck = isset($_POST['pallets_per_truck']) ? max(1, intval($_POST['pallets_per_truck'])) : 17;
        
        if (empty($delivery_ids) || empty($pallet_ids)) {
            throw new Exception('No deliveries or pallets selected for bulk linking.');
        }
        
        $conn_bulk->begin_transaction();
        
        $linked_pairs = [];
        $linked_count = 0;
        $milestone_triggered_deliveries = [];
        
        // Fetch delivery and pallet details - now with comprehensive delivery info
        $delivery_placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));
        $pallet_placeholders = implode(',', array_fill(0, count($pallet_ids), '?'));
        
        $stmt_deliveries = $conn_bulk->prepare("SELECT id, wattage, quantity, status_of_delivery, project_id, warehouse_id, anticipated_delivery_date FROM deliveries WHERE id IN ($delivery_placeholders)");
        $stmt_deliveries->bind_param(str_repeat('i', count($delivery_ids)), ...$delivery_ids);
        $stmt_deliveries->execute();
        $deliveries_data = $stmt_deliveries->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_deliveries->close();
        
        $stmt_pallets = $conn_bulk->prepare("SELECT id, pallet_identifier, wattage, quantity FROM inventory_pallets WHERE id IN ($pallet_placeholders)");
        $stmt_pallets->bind_param(str_repeat('i', count($pallet_ids)), ...$pallet_ids);
        $stmt_pallets->execute();
        $pallets_data = $stmt_pallets->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_pallets->close();
        
        // Prepare linking statements - now with comprehensive pallet update
        $stmt_check_existing = $conn_bulk->prepare("SELECT COUNT(*) FROM delivery_pallets WHERE delivery_id = ? AND inventory_pallet_id = ?");
        $stmt_insert_link = $conn_bulk->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
        $stmt_update_pallet_comprehensive = $conn_bulk->prepare("UPDATE inventory_pallets SET status = ?, current_project_id = ?, current_warehouse_id = ?, arrival_date = ? WHERE id = ?");
        
        // Prepare origin update statement
        $stmt_update_delivery_origin = $conn_bulk->prepare("UPDATE deliveries SET origin_type = 'manufacturer', origin_id = NULL WHERE id = ? AND (origin_type IS NULL OR origin_type = '')");
        
        // Create a helper function to update pallet with delivery sync and set delivery origin
        $updatePalletWithDeliverySync = function($delivery, $pallet_id) use ($stmt_update_pallet_comprehensive, $stmt_update_delivery_origin) {
            $new_pallet_status = $delivery['status_of_delivery']; // Sync with delivery status
            $new_current_project_id = $delivery['project_id']; // Sync project assignment  
            $new_current_warehouse_id = $delivery['warehouse_id']; // Sync warehouse assignment
            $new_arrival_date = $delivery['anticipated_delivery_date']; // Sync arrival date
            
            $stmt_update_pallet_comprehensive->bind_param("siiss", $new_pallet_status, $new_current_project_id, $new_current_warehouse_id, $new_arrival_date, $pallet_id);
            $pallet_success = $stmt_update_pallet_comprehensive->execute();
            
            // Update delivery origin if not already set (default to manufacturer for direct shipments)
            $stmt_update_delivery_origin->bind_param("i", $delivery['id']);
            $origin_success = $stmt_update_delivery_origin->execute();
            
            return $pallet_success; // Return pallet update success (origin update is supplementary)
        };
        
        if ($strategy === 'module_based') {
            // Smart module-based matching - assigns pallets to exactly match delivery requirements
            $available_pallets = $pallets_data; // Copy to avoid modifying original
            
            foreach ($deliveries_data as $delivery) {
                $modules_needed = $delivery['quantity'];
                $modules_assigned = 0;
                
                // Sort available pallets by quantity (smallest first for better matching)
                usort($available_pallets, function($a, $b) {
                    return $a['quantity'] - $b['quantity'];
                });
                
                for ($i = 0; $i < count($available_pallets) && $modules_assigned < $modules_needed; $i++) {
                    if ($available_pallets[$i] === null) continue; // Skip already used pallets
                    
                    $pallet = $available_pallets[$i];
                    
                    // Check if this pallet would exceed requirements
                    if ($modules_assigned + $pallet['quantity'] <= $modules_needed) {
                        // Check if already linked
                        $stmt_check_existing->bind_param("ii", $delivery['id'], $pallet['id']);
                        $stmt_check_existing->execute();
                        $result_existing = $stmt_check_existing->get_result();
                        $existing_row = $result_existing->fetch_assoc();
                        $existing_count = $existing_row ? $existing_row['COUNT(*)'] : 0;
                        $result_existing->free();
                        
                        if ($existing_count == 0) {
                            // Create link
                            $stmt_insert_link->bind_param("ii", $delivery['id'], $pallet['id']);
                            if ($stmt_insert_link->execute()) {
                                // Update pallet status, location, and arrival date to match delivery
                                $updatePalletWithDeliverySync($delivery, $pallet['id']);
                                
                                $linked_pairs[] = [
                                    'delivery_id' => $delivery['id'],
                                    'pallet_id' => $pallet['id'],
                                    'pallet_identifier' => $pallet['pallet_identifier']
                                ];
                                if (!isset($milestone_triggered_deliveries[$delivery['id']])) {
                                    trigger_delivery_milestones_for_status($delivery['id'], $delivery['status_of_delivery'], $conn_bulk, $_SESSION['user_id'] ?? null);
                                    $milestone_triggered_deliveries[$delivery['id']] = true;
                                }
                                $linked_count++;
                                $modules_assigned += $pallet['quantity'];
                                
                                // Mark pallet as used
                                $available_pallets[$i] = null;
                            }
                        }
                    }
                }
                
                // Remove null entries to keep array clean for next delivery
                $available_pallets = array_filter($available_pallets);
                $available_pallets = array_values($available_pallets); // Re-index
            }
            
        } else if ($strategy === 'pallets_per_truck') {
            // Link specified number of pallets to each delivery (truck)
            $pallet_index = 0;
            
            foreach ($deliveries_data as $delivery) {
                $pallets_assigned_to_this_delivery = 0;
                
                // Assign up to $pallets_per_truck pallets to this delivery
                while ($pallets_assigned_to_this_delivery < $pallets_per_truck && $pallet_index < count($pallets_data)) {
                    $pallet = $pallets_data[$pallet_index];
                    
                    // Check if already linked
                    $stmt_check_existing->bind_param("ii", $delivery['id'], $pallet['id']);
                    $stmt_check_existing->execute();
                    $result_existing = $stmt_check_existing->get_result();
                    $existing_row = $result_existing->fetch_assoc();
                    $existing_count = $existing_row ? $existing_row['COUNT(*)'] : 0;
                    $result_existing->free();
                    
                    if ($existing_count == 0) {
                        // Create link
                        $stmt_insert_link->bind_param("ii", $delivery['id'], $pallet['id']);
                        if ($stmt_insert_link->execute()) {
                            // Update pallet status, location, and arrival date to match delivery
                            $updatePalletWithDeliverySync($delivery, $pallet['id']);
                            
                            $linked_pairs[] = [
                                'delivery_id' => $delivery['id'],
                                'pallet_id' => $pallet['id'],
                                'pallet_identifier' => $pallet['pallet_identifier']
                            ];
                            if (!isset($milestone_triggered_deliveries[$delivery['id']])) {
                                trigger_delivery_milestones_for_status($delivery['id'], $delivery['status_of_delivery'], $conn_bulk, $_SESSION['user_id'] ?? null);
                                $milestone_triggered_deliveries[$delivery['id']] = true;
                            }
                            $linked_count++;
                            $pallets_assigned_to_this_delivery++;
                        }
                    }
                    
                    $pallet_index++;
                }
                
                // If we've run out of pallets, break
                if ($pallet_index >= count($pallets_data)) {
                    break;
                }
            }
            
        } else if ($strategy === 'distribute') {
            // Distribute pallets evenly among deliveries
            $pallet_index = 0;
            
            foreach ($deliveries_data as $delivery) {
                if ($pallet_index >= count($pallets_data)) {
                    break; // No more pallets to assign
                }
                
                $pallet = $pallets_data[$pallet_index];
                
                // Check if already linked
                $stmt_check_existing->bind_param("ii", $delivery['id'], $pallet['id']);
                $stmt_check_existing->execute();
                $result_existing = $stmt_check_existing->get_result();
                $existing_row = $result_existing->fetch_assoc();
                $existing_count = $existing_row ? $existing_row['COUNT(*)'] : 0;
                $result_existing->free();
                
                if ($existing_count == 0) {
                    // Create link
                    $stmt_insert_link->bind_param("ii", $delivery['id'], $pallet['id']);
                    if ($stmt_insert_link->execute()) {
                        // Update pallet status, location, and arrival date to match delivery
                        $updatePalletWithDeliverySync($delivery, $pallet['id']);
                        
                        $linked_pairs[] = [
                            'delivery_id' => $delivery['id'],
                            'pallet_id' => $pallet['id'],
                            'pallet_identifier' => $pallet['pallet_identifier']
                        ];
                        if (!isset($milestone_triggered_deliveries[$delivery['id']])) {
                            trigger_delivery_milestones_for_status($delivery['id'], $delivery['status_of_delivery'], $conn_bulk, $_SESSION['user_id'] ?? null);
                            $milestone_triggered_deliveries[$delivery['id']] = true;
                        }
                        $linked_count++;
                    }
                }
                
                $pallet_index++;
            }
        }
        
        $stmt_check_existing->close();
        $stmt_insert_link->close();
        $stmt_update_pallet_comprehensive->close();
        $stmt_update_delivery_origin->close();
        
        $conn_bulk->commit();
        
        // Build strategy description
        $strategy_description = '';
        if ($strategy === 'module_based') {
            $strategy_description = "Module-Based Matching";
        } else if ($strategy === 'pallets_per_truck') {
            $strategy_description = "Fixed Pallets per Truck ($pallets_per_truck per delivery)";
        } else {
            $strategy_description = ucfirst(str_replace('_', ' ', $strategy));
        }
        
        $response = [
            'success' => true,
            'message' => "Successfully linked $linked_count pallet(s) to deliveries using the $strategy_description strategy with synchronized status, locations, and origin tracking.",
            'details' => "Wattage: {$wattage}W | Strategy: $strategy_description | Pallets synchronized with delivery status | Origin set to manufacturer for tracking",
            'linked_pairs' => $linked_pairs,
            'linked_count' => $linked_count
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        $conn_bulk->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Bulk linking failed: ' . $e->getMessage(),
            'details' => 'Please try again or use CSV upload for more complex linking requirements.'
        ]);
    }
    
    $conn_bulk->close();
    exit();
}
// End Bulk Linking Handling

// --- Handle One-Time Origin Update for Existing Deliveries ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_existing_origins'])) {
    $conn_origin = getDBConnection();
    if (!$conn_origin) {
        $_SESSION['messages'][] = "<p class='error-message'>Database connection failed during origin update.</p>";
    } else {
        try {
            $conn_origin->begin_transaction();
            
            // Update all deliveries that don't have origin information set
            $stmt_update_origins = $conn_origin->prepare("
                UPDATE deliveries 
                SET origin_type = 'manufacturer', origin_id = NULL 
                WHERE (origin_type IS NULL OR origin_type = '') 
                AND id IN (SELECT DISTINCT dp.delivery_id FROM delivery_pallets dp)
            ");
            
            if ($stmt_update_origins->execute()) {
                $updated_count = $stmt_update_origins->affected_rows;
                $conn_origin->commit();
                $_SESSION['messages'][] = "<p class='success-message'>Successfully updated origin information for {$updated_count} existing deliveries. They are now set to 'manufacturer' origin for proper movement tracking.</p>";
            } else {
                throw new Exception("Failed to update origins: " . $stmt_update_origins->error);
            }
            
            $stmt_update_origins->close();
            
        } catch (Exception $e) {
            $conn_origin->rollback();
            $_SESSION['messages'][] = "<p class='error-message'>Error updating existing delivery origins: " . $e->getMessage() . "</p>";
        }
        
        $conn_origin->close();
    }
    
    // Redirect to avoid form resubmission
    header("Location: link_pallet_deliveries.php?" . http_build_query($_GET));
    exit();
}
// End One-Time Origin Update

// Filter parameters (must be after potential redirect from CSV upload)
$filter_project_id = isset($_GET['filter_project_id']) ? $_GET['filter_project_id'] : 'all';
$search_term = isset($_GET['search_term']) ? trim($_GET['search_term']) : '';
$filter_needs_pallets = isset($_GET['filter_needs_pallets']) && $_GET['filter_needs_pallets'] === '1'; // New filter

// Database connection (main connection for page display)
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed for page display");
}

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'global_admin' && $_SESSION['role'] != 'admin')) {
    header("Location: unauthorized");
    exit();
}

// --- Admin Account Fetching and Permission Setup (from manage_deliveries) ---
$account_id_for_admin = null;
$is_global_admin = ($_SESSION['role'] === 'global_admin');

if (!$is_global_admin) { 
    $stmtAdminAcc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1");
    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $_SESSION['user_id']);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($acctID);
        if ($stmtAdminAcc->fetch()) {
            $account_id_for_admin = $acctID;
        }
        $stmtAdminAcc->close();
    }
    if (!$account_id_for_admin) {
        $_SESSION['messages'][] = "<p class='error-message'>Error: Admin user is not associated with an account.</p>";
    }
}

// Fetch all projects for the filter dropdown (from manage_deliveries)
$all_projects_for_filter = [];
if ($is_global_admin) {
    $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects WHERE (status IS NULL OR status = 'active') ORDER BY project_name ASC");
} else {
    if ($account_id_for_admin) {
        $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? AND (status IS NULL OR status = 'active') ORDER BY project_name ASC");
        $stmt_all_proj->bind_param("i", $account_id_for_admin);
    } else {
        $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects WHERE 1=0");
    }
}
if ($stmt_all_proj) {
    $stmt_all_proj->execute();
    $result_all_proj = $stmt_all_proj->get_result();
    while ($proj_row = $result_all_proj->fetch_assoc()) {
        $all_projects_for_filter[] = $proj_row;
    }
    $stmt_all_proj->close();
}

$page_title_project_name = "All Projects";
if (is_numeric($filter_project_id)) {
    $stmt_proj_details = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    if ($stmt_proj_details) {
        $stmt_proj_details->bind_param("i", $filter_project_id);
        $stmt_proj_details->execute();
        $stmt_proj_details->bind_result($project_name_specific);
        if ($stmt_proj_details->fetch()) {
            $page_title_project_name = $project_name_specific;
        }
        $stmt_proj_details->close();
    }
} elseif ($filter_project_id === 'unassigned') {
    $page_title_project_name = "Unassigned Deliveries";
}


// --- Fetch Deliveries for Display ---
$sql = "
    SELECT d.id, d.project_id, d.supplier, d.wattage, d.status_of_delivery, 
           d.quantity, d.bol_number, d.proof_of_delivery,
           p.project_name AS project_name_from_join,
           COALESCE(d.actual_delivery_date, d.anticipated_delivery_date) AS relevant_date,
           (SELECT COUNT(*) FROM delivery_pallets dp_count WHERE dp_count.delivery_id = d.id) AS linked_pallet_count
    FROM deliveries d
    LEFT JOIN projects p ON d.project_id = p.id
    WHERE 1=1
";

$params = [];
$paramTypes = "";

// Project Filtering
if (is_numeric($filter_project_id)) {
    $sql .= " AND d.project_id = ?";
    if (!$is_global_admin && $account_id_for_admin) {
        $sql .= " AND p.account_id = ?";
        $paramTypes .= "i";
        $params[] = $account_id_for_admin;
    }
    $paramTypes .= "i";
    $params[] = $filter_project_id;
} elseif ($filter_project_id === 'unassigned') {
    $sql .= " AND d.project_id IS NULL";
} elseif ($filter_project_id === 'all' && !$is_global_admin && $account_id_for_admin) {
    // Admin viewing their account's projects implicitly
    $sql .= " AND p.account_id = ?";
    $paramTypes .= "i";
    $params[] = $account_id_for_admin;
} // Global admin viewing 'all' needs no additional project SQL filter here

// New Filter: Show only deliveries needing pallets
if ($filter_needs_pallets) {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM delivery_pallets dp_check WHERE dp_check.delivery_id = d.id)";
}

// Search Term Filtering (similar to manage_pallets)
if (!empty($search_term)) {
    $sql .= " AND (
        d.supplier LIKE ? OR 
        d.wattage LIKE ? OR 
        d.status_of_delivery LIKE ? OR 
        d.bol_number LIKE ? OR 
        p.project_name LIKE ?
    )";
    $like_search_term = "%{$search_term}%";
    for ($i = 0; $i < 5; $i++) {
        $paramTypes .= "s";
        $params[] = $like_search_term;
    }
}

$sql .= " ORDER BY COALESCE(d.actual_delivery_date, d.anticipated_delivery_date, d.id) DESC"; // Keep a default sort

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($paramTypes)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    $stmt->execute();
    $deliveries_result = $stmt->get_result();
    $stmt->close();
} else {
    $_SESSION['messages'][] = "<p class='error-message'>Error preparing deliveries query: " . htmlspecialchars($conn->error) . "</p>";
    $deliveries_result = null; // Ensure it's set
}

// --- NEW: Fetch Available Pallets for Linking (with Pagination) ---
$available_pallets = [];
$pallets_per_page = 100; // Limit to 100 pallets per page
$current_page = isset($_GET['pallet_page']) ? max(1, intval($_GET['pallet_page'])) : 1;
$offset = ($current_page - 1) * $pallets_per_page;

// Simplified query without expensive GROUP_CONCAT
$sql_pallets_available_base = "
    SELECT 
        ip.id AS pallet_id, 
        ip.pallet_identifier, 
        ip.wattage, 
        ip.quantity, 
        ip.status AS pallet_status,
        ip.arrival_date AS pallet_status_timestamp,
        w.name AS current_warehouse_name,
        p_assigned.project_name AS assigned_project_name,
        ip.assigned_project_id
    FROM inventory_pallets ip
    LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
    LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
    LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
    WHERE dp.inventory_pallet_id IS NULL  /* Pallet is not in delivery_pallets */
    AND ip.status IN ('In Warehouse', 'Produced', 'At Manufacturer')
";

$pallets_available_conditions = [];
$pallets_available_params = [];
$pallets_available_types = "";

// Project filtering for available pallets
if (is_numeric($filter_project_id)) {
    $pallets_available_conditions[] = "(ip.assigned_project_id = ? OR ip.assigned_project_id IS NULL)";
    $pallets_available_params[] = $filter_project_id;
    $pallets_available_types .= "i";
    if (!$is_global_admin && $account_id_for_admin) {
        // Ensure that if a project is assigned, it belongs to the admin's account
        $pallets_available_conditions[] = "(ip.assigned_project_id IS NULL OR (ip.assigned_project_id = ? AND p_assigned.account_id = ?))";
        $pallets_available_params[] = $filter_project_id; // Redundant but matches structure
        $pallets_available_params[] = $account_id_for_admin;
        $pallets_available_types .= "ii";
    }
} elseif ($filter_project_id === 'unassigned') {
    $pallets_available_conditions[] = "ip.assigned_project_id IS NULL";
    // No account check needed for unassigned, admin can see them.
} elseif ($filter_project_id === 'all' && $is_global_admin) {
    // No additional project condition, global admin sees all unlinked.
} else { // Admin but not global, and filter_project_id is 'all' (or invalid state)
    if (!$is_global_admin && $account_id_for_admin) {
        $pallets_available_conditions[] = "(p_assigned.account_id = ? OR ip.assigned_project_id IS NULL)";
        $pallets_available_params[] = $account_id_for_admin;
        $pallets_available_types .= "i";
    } else if (!$is_global_admin && !$account_id_for_admin) {
        $pallets_available_conditions[] = "1=0"; // Admin with no account sees nothing
    }
}

// Get total count first (for pagination)
$sql_count = "SELECT COUNT(*) as total_count FROM inventory_pallets ip
              LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
              LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
              WHERE dp.inventory_pallet_id IS NULL
              AND ip.status IN ('In Warehouse', 'Produced', 'At Manufacturer')";

if (!empty($pallets_available_conditions)) {
    $sql_count .= " AND (" . implode(" AND ", $pallets_available_conditions) . ")";
}

$stmt_count = $conn->prepare($sql_count);
$total_pallets = 0;
if ($stmt_count) {
    if (!empty($pallets_available_types)) {
        $stmt_count->bind_param($pallets_available_types, ...$pallets_available_params);
    }
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    if ($row_count = $result_count->fetch_assoc()) {
        $total_pallets = $row_count['total_count'];
    }
    $stmt_count->close();
}

$total_pages = ceil($total_pallets / $pallets_per_page);

// Get paginated results
$sql_pallets_available = $sql_pallets_available_base;
if (!empty($pallets_available_conditions)) {
    $sql_pallets_available .= " AND (" . implode(" AND ", $pallets_available_conditions) . ")";
}
$sql_pallets_available .= " ORDER BY ip.id DESC LIMIT ? OFFSET ?";

$stmt_pallets_available = $conn->prepare($sql_pallets_available);
if ($stmt_pallets_available) {
    $extended_types = $pallets_available_types . "ii";
    $extended_params = array_merge($pallets_available_params, [$pallets_per_page, $offset]);
    
    if (!empty($extended_types)) {
        $stmt_pallets_available->bind_param($extended_types, ...$extended_params);
    }
    $stmt_pallets_available->execute();
    $result_pallets_available = $stmt_pallets_available->get_result();
    while ($row = $result_pallets_available->fetch_assoc()) {
        $available_pallets[] = $row;
    }
    $stmt_pallets_available->close();
} else {
    $_SESSION['messages'][] = "<p class='error-message'>Error fetching available pallets: " . $conn->error . "</p>";
}
// --- END NEW: Fetch Available Pallets ---

// --- NEW: Fetch Bulk Linking Data ---
$delivery_groups = [];
$pallet_groups = [];

// Group deliveries by wattage that need pallets
$sql_delivery_groups = "
    SELECT 
        d.wattage,
        d.project_id,
        p.project_name,
        COUNT(*) as delivery_count,
        SUM(d.quantity) as total_modules,
        GROUP_CONCAT(d.id ORDER BY d.id SEPARATOR ',') as delivery_ids
    FROM deliveries d
    LEFT JOIN projects p ON d.project_id = p.id
    WHERE NOT EXISTS (SELECT 1 FROM delivery_pallets dp WHERE dp.delivery_id = d.id)
";

$group_conditions = [];
$group_params = [];
$group_types = "";

// Apply same project filtering as main deliveries query
if (is_numeric($filter_project_id)) {
    $group_conditions[] = "d.project_id = ?";
    if (!$is_global_admin && $account_id_for_admin) {
        $group_conditions[] = "p.account_id = ?";
        $group_params[] = $account_id_for_admin;
        $group_types .= "i";
    }
    $group_params[] = $filter_project_id;
    $group_types .= "i";
} elseif ($filter_project_id === 'unassigned') {
    $group_conditions[] = "d.project_id IS NULL";
} elseif ($filter_project_id === 'all' && !$is_global_admin && $account_id_for_admin) {
    $group_conditions[] = "p.account_id = ?";
    $group_params[] = $account_id_for_admin;
    $group_types .= "i";
}

if (!empty($group_conditions)) {
    $sql_delivery_groups .= " AND " . implode(" AND ", $group_conditions);
}

$sql_delivery_groups .= " GROUP BY d.wattage, d.project_id, p.project_name ORDER BY d.wattage ASC, p.project_name ASC";

$stmt_delivery_groups = $conn->prepare($sql_delivery_groups);
if ($stmt_delivery_groups) {
    if (!empty($group_types)) {
        $stmt_delivery_groups->bind_param($group_types, ...$group_params);
    }
    $stmt_delivery_groups->execute();
    $result_delivery_groups = $stmt_delivery_groups->get_result();
    while ($row = $result_delivery_groups->fetch_assoc()) {
        $delivery_groups[] = $row;
    }
    $stmt_delivery_groups->close();
}

// Group available pallets by wattage (filtered for bulk linking)
$sql_pallet_groups = "
    SELECT 
        ip.wattage,
        ip.assigned_project_id,
        p_assigned.project_name as assigned_project_name,
        COUNT(*) as pallet_count,
        SUM(ip.quantity) as total_modules,
        GROUP_CONCAT(ip.id ORDER BY ip.id SEPARATOR ',') as pallet_ids
    FROM inventory_pallets ip
    LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
    LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
    WHERE dp.inventory_pallet_id IS NULL
    AND ip.status IN ('In Warehouse', 'Produced', 'At Manufacturer')
";

// Enhanced filtering for bulk linking - only show pallets for current project filter or unassigned
$bulk_pallet_conditions = [];
$bulk_pallet_params = [];
$bulk_pallet_types = "";

if (is_numeric($filter_project_id)) {
    // Show pallets assigned to this project OR unassigned pallets
    $bulk_pallet_conditions[] = "(ip.assigned_project_id = ? OR ip.assigned_project_id IS NULL)";
    $bulk_pallet_params[] = $filter_project_id;
    $bulk_pallet_types .= "i";
    
    // Admin account filtering
    if (!$is_global_admin && $account_id_for_admin) {
        $bulk_pallet_conditions[] = "(p_assigned.account_id = ? OR ip.assigned_project_id IS NULL)";
        $bulk_pallet_params[] = $account_id_for_admin;
        $bulk_pallet_types .= "i";
    }
} elseif ($filter_project_id === 'unassigned') {
    // Only show unassigned pallets when viewing unassigned deliveries
    $bulk_pallet_conditions[] = "ip.assigned_project_id IS NULL";
} elseif ($filter_project_id === 'all') {
    // For "all projects" view, apply account filtering for admins
    if (!$is_global_admin && $account_id_for_admin) {
        $bulk_pallet_conditions[] = "(p_assigned.account_id = ? OR ip.assigned_project_id IS NULL)";
        $bulk_pallet_params[] = $account_id_for_admin;
        $bulk_pallet_types .= "i";
    }
}

if (!empty($bulk_pallet_conditions)) {
    $sql_pallet_groups .= " AND (" . implode(" AND ", $bulk_pallet_conditions) . ")";
}

$sql_pallet_groups .= " GROUP BY ip.wattage, ip.assigned_project_id, p_assigned.project_name ORDER BY ip.wattage ASC, p_assigned.project_name ASC";

$stmt_pallet_groups = $conn->prepare($sql_pallet_groups);
if ($stmt_pallet_groups) {
    if (!empty($bulk_pallet_types)) {
        $stmt_pallet_groups->bind_param($bulk_pallet_types, ...$bulk_pallet_params);
    }
    $stmt_pallet_groups->execute();
    $result_pallet_groups = $stmt_pallet_groups->get_result();
    while ($row = $result_pallet_groups->fetch_assoc()) {
        $pallet_groups[] = $row;
    }
    $stmt_pallet_groups->close();
}
// --- END NEW: Fetch Bulk Linking Data ---

// --- Check if there are deliveries needing origin updates ---
$deliveries_needing_origin_update = 0;
$stmt_check_origins = $conn->prepare("
    SELECT COUNT(*) as count_needing_update 
    FROM deliveries d 
    WHERE (d.origin_type IS NULL OR d.origin_type = '') 
    AND EXISTS (SELECT 1 FROM delivery_pallets dp WHERE dp.delivery_id = d.id)
");
if ($stmt_check_origins) {
    $stmt_check_origins->execute();
    $result_origins = $stmt_check_origins->get_result();
    if ($row_origins = $result_origins->fetch_assoc()) {
        $deliveries_needing_origin_update = $row_origins['count_needing_update'];
    }
    $stmt_check_origins->close();
}


// Determine project name for page title (remains largely the same)
$project_name_for_title = "All Deliveries";
if (is_numeric($filter_project_id)) {
    $stmt_proj_title = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    if ($stmt_proj_title) {
        $stmt_proj_title->bind_param("i", $filter_project_id);
        $stmt_proj_title->execute();
        $stmt_proj_title->bind_result($p_name_title);
        if ($stmt_proj_title->fetch()) {
            $project_name_for_title = $p_name_title;
        }
        $stmt_proj_title->close();
    }
} elseif ($filter_project_id === 'unassigned') {
    $project_name_for_title = "Unassigned Deliveries";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Pallets to Deliveries <?php if ($project_name_for_title !== "All Deliveries") echo " - " . htmlspecialchars($project_name_for_title); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Styles from manage_deliveries.php & manage_pallets.php, simplified */
        .filter-container {
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            display: flex; 
            flex-wrap: wrap; 
            justify-content: space-between; 
            align-items: center; 
            gap: 15px; 
            margin-bottom: 20px;
        }
        .filter-group-left {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .filter-group-right {
            display: flex;
            gap: 10px; 
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
        .table-responsive {
            width: 100%;
            overflow-x: auto;
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
        }
        .action-button:hover {
            background-color: #3A6E7F;
        }
        .error-message {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-bottom: 20px;
            color: #721c24;
        }
        .success-message {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 10px;
            margin-bottom: 20px;
            color: #155724;
        }
        /* Modal styling (copied from manage_deliveries for associated pallets) */
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
            margin: 10% auto; 
            padding: 20px;
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
        .close-modal:hover, .close-modal:focus {
            color: black;
            text-decoration: none;
        }
        #palletList table {
            width: 100%;
            border-collapse: collapse;
        }
        #palletList th, #palletList td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        #palletList th {
            background-color: #e9ecef;
        }
        /* Tab styling */
        .tabs-container {
            text-align: center;
            width: 100%;
            margin-bottom: 20px;
            border-bottom: 1px solid #ccc;
        }
        .tabs {
            display: inline-flex;
            gap: 1px;
        }
        .tabs button.tab-link { /* More specific selector */
            background: #e9ecef;
            color: #333;
            padding: 10px 15px;
            cursor: pointer;
            font-weight: 600;
            border: 1px solid #ccc;
            border-bottom: none;
            border-top-left-radius: 5px;
            border-top-right-radius: 5px;
            font-size: 1em;
            margin-bottom: -1px; /* Overlap container's bottom border */
        }
        .tabs button.tab-link.active { /* More specific selector */
            background: #fff;
            color: #293E4C;
            border-bottom: 1px solid #fff;
        }
        .tab-content {
            display: none;
            padding-top: 10px; /* Add some space above tab content */
        }
        .tab-content.active {
            display: block;
        }
        /* Ensure filters are laid out nicely */
        #palletsTab .filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
            align-items: center;
        }
        #palletsTab .filter-controls label {
            font-weight: 500;
            margin-right: 5px;
        }
        #palletsTab .filter-controls input[type="text"],
        #palletsTab .filter-controls select {
            padding: 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
            height: 34px; /* Align height */
        }
        
        /* Bulk Linking Tab Specific Styles */
        .group-item {
            transition: all 0.2s ease;
        }
        .group-item:hover {
            background-color: #f8f9fa !important;
            cursor: pointer;
        }
        .group-item.selected-delivery {
            background-color: #e3f2fd !important;
            border-left: 4px solid #488C9A !important;
        }
        .group-item.selected-pallet {
            background-color: #fff3e0 !important;
            border-left: 4px solid #f39c12 !important;
        }
        .bulk-matching-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .bulk-matching-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
        .quick-match-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .quick-match-btn {
            background: linear-gradient(135deg, #488C9A, #6ab7c7);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.85em;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .quick-match-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            background: linear-gradient(135deg, #3A6E7F, #488C9A);
        }
        .strategy-selection {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            margin-top: 15px;
        }
        .wattage-highlight {
            background: linear-gradient(135deg, #488C9A, #6ab7c7);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 600;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Link Pallets to Deliveries: <?php echo htmlspecialchars($project_name_for_title); ?></h1>

    <!-- Display Messages -->
    <?php
    if (isset($_SESSION['messages']) && !empty($_SESSION['messages'])) {
        foreach ($_SESSION['messages'] as $message) {
            echo $message;
        }
        $_SESSION['messages'] = []; // Clear messages after displaying
    }
    ?>



    <!-- Tab Buttons MOVED HERE -->
    <div class="tabs-container">
        <div class="tabs">
            <button class="tab-link active" data-tab="deliveriesTab">Deliveries (<?php echo $deliveries_result ? $deliveries_result->num_rows : 0; ?>)</button>
            <button class="tab-link" data-tab="palletsTab">Available Pallets (<?php echo number_format($total_pallets); ?>)</button>
            <button class="tab-link" data-tab="bulkLinkingTab">Bulk Linking</button>
        </div>
    </div>

    <!-- Deliveries Tab Content -->
    <div id="deliveriesTab" class="tab-content active">
        <!-- Filters MOVED INSIDE Deliveries Tab -->
        <div class="filter-container">
            <form action="link_pallet_deliveries.php" method="get" id="filterFormDeliveries" class="filter-group-left">
                <label for="search_term_deliveries">Search Deliveries:</label>
                <input type="text" name="search_term" id="search_term_deliveries" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="BOL, Manufacturer, Project...">
                
                <label for="filter_project_id_deliveries">Project:</label>
                <select name="filter_project_id" id="filter_project_id_deliveries">
                    <?php if ($is_global_admin): ?>
                    <option value="all" <?php if($filter_project_id === 'all') echo 'selected'; ?>>All Projects</option>
                    <?php endif; ?>
                    <option value="unassigned" <?php if($filter_project_id === 'unassigned') echo 'selected'; ?>>Unassigned</option>
                    <?php foreach ($all_projects_for_filter as $proj_filter_item): ?>
                        <option value="<?php echo $proj_filter_item['id']; ?>" <?php if (is_numeric($filter_project_id) && $filter_project_id == $proj_filter_item['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($proj_filter_item['project_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="filter_needs_pallets" style="margin-left: 15px;">
                    <input type="checkbox" name="filter_needs_pallets" id="filter_needs_pallets" value="1" <?php if ($filter_needs_pallets) echo 'checked'; ?>>
                    Show only deliveries needing pallets
                </label>

                <button type="submit" class="action-button">Apply Filters</button>
            </form>
            <div class="filter-group-right">
                <button type="button" id="exportDeliveriesCsvBtn" class="action-button">Export Deliveries CSV</button>
            </div>
        </div>

        <!-- Deliveries Table -->
        <div class="table-responsive">
            <table class="deliveries-table" id="deliveriesTable">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                            <th>Project</th>
                        <?php endif; ?>
                        <th>Manufacturer</th>
                        <th>BOL Number</th>
                        <th>Delivery Wattage</th>
                        <th>Status</th>
                        <th>Associated Pallets</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($deliveries_result && $deliveries_result->num_rows > 0): ?>
                        <?php while($delivery = $deliveries_result->fetch_assoc()): ?>
                            <?php
                            // Fetch associated pallets for this delivery to display in the modal
                            $current_delivery_pallets = [];
                            $stmt_current_pallets = $conn->prepare("SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity FROM delivery_pallets dp JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id WHERE dp.delivery_id = ? ORDER BY ip.id");
                            if ($stmt_current_pallets) {
                                $stmt_current_pallets->bind_param("i", $delivery['id']);
                                $stmt_current_pallets->execute();
                                $pallets_res = $stmt_current_pallets->get_result();
                                while ($p_row = $pallets_res->fetch_assoc()) {
                                    $current_delivery_pallets[] = $p_row;
                                }
                                $stmt_current_pallets->close();
                            }
                            $palletDataJson = htmlspecialchars(json_encode($current_delivery_pallets), ENT_QUOTES, 'UTF-8');
                            $linked_pallet_display_count = $delivery['linked_pallet_count']; // Use pre-fetched count
                            
                            // Extract manufacturer name (remove anything after " - ")
                            $manufacturer = $delivery['supplier'] ?? 'N/A';
                            if (strpos($manufacturer, ' - ') !== false) {
                                $manufacturer = trim(explode(' - ', $manufacturer)[0]);
                            }
                            // Handle specific cases where supplier might be a warehouse name instead of manufacturer
                            if (in_array(strtolower($manufacturer), ['phoenix wh', 'phoenix warehouse'])) {
                                $manufacturer = 'Meyer Burger'; // Default to Meyer Burger for warehouse entries
                            }
                            ?>
                            <tr>
                                <td><?php echo $delivery['id']; ?></td>
                                <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                                    <td><?php echo htmlspecialchars($delivery['project_name_from_join'] ?? 'N/A'); ?></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($manufacturer); ?></td>
                                <td><?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($delivery['wattage'] ?? 'N/A'); ?>W</td>
                                <td><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($linked_pallet_display_count > 0): ?>
                                        <button type="button" class="action-button view-pallets-btn"
                                                data-pallets='<?php echo $palletDataJson; ?>'
                                                onclick="showAssociatedPalletsModal(this)">
                                            View (<?php echo $linked_pallet_display_count; ?>)
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #888;">None Linked</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($linked_pallet_display_count > 0): ?>
                                        <a href="manage_delivery_pallets.php?delivery_id=<?php echo $delivery['id']; ?>&wattage=<?php echo urlencode($delivery['wattage']); ?><?php if(is_numeric($filter_project_id) && $filter_project_id > 0) { echo '&project_id=' . $filter_project_id; } elseif (!empty($delivery['project_id'])) { echo '&project_id=' . $delivery['project_id'];} ?>"
                                           class="action-button"
                                           title="Edit pallet associations for this delivery">
                                           Edit
                                        </a>
                                    <?php else: ?>
                                        <a href="manage_delivery_pallets.php?delivery_id=<?php echo $delivery['id']; ?>&wattage=<?php echo urlencode($delivery['wattage']); ?><?php if(is_numeric($filter_project_id) && $filter_project_id > 0) { echo '&project_id=' . $filter_project_id; } elseif (!empty($delivery['project_id'])) { echo '&project_id=' . $delivery['project_id'];} ?>"
                                           class="action-button"
                                           title="Add pallets to this delivery">
                                           Add Pallets
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo ($filter_project_id === 'all' || $filter_project_id === 'unassigned') ? '8' : '7'; ?>">No deliveries found matching your criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div> <!-- End Deliveries Tab -->

    <!-- Pallets Tab Content -->
    <div id="palletsTab" class="tab-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0;">Available Pallets for Linking (<?php echo number_format($total_pallets); ?> total)</h2>
            <div style="display: flex; gap: 10px; align-items: center;">
                <!-- Pagination Controls -->
                <?php if ($total_pages > 1): ?>
                    <div style="display: flex; gap: 5px; align-items: center; margin-right: 15px;">
                        <?php if ($current_page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pallet_page' => $current_page - 1, 'active_tab' => 'palletsTab'])); ?>" class="action-button" style="padding: 5px 10px;">« Prev</a>
                        <?php endif; ?>
                        
                        <span style="margin: 0 10px; font-weight: 500;">
                            Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                        </span>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pallet_page' => $current_page + 1, 'active_tab' => 'palletsTab'])); ?>" class="action-button" style="padding: 5px 10px;">Next »</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="export_available_pallets_csv.php" style="margin: 0;">
                    <input type="hidden" name="filter_project_id_export" value="<?php echo htmlspecialchars($filter_project_id); ?>">
                    <button type="submit" name="export_pallets_csv" class="action-button">Export Available Pallets CSV</button>
                </form>
            </div>
        </div>

        <div class="filter-controls">
            <label for="palletSearchInput">Search Pallets:</label>
            <input type="text" id="palletSearchInput" placeholder="Filter by Identifier, Warehouse...">
            <label for="palletStatusFilter">Status:</label>
            <select id="palletStatusFilter">
                <option value="">All Statuses</option>
                <option value="In Warehouse">In Warehouse</option>
                <option value="Produced">Produced</option>
            </select>
            <label for="palletWattageFilter">Wattage:</label>
            <select id="palletWattageFilter">
                <option value="">All Wattages</option>
                <?php
                $unique_pallet_wattages = [];
                if (!empty($available_pallets)) {
                    foreach ($available_pallets as $p_avail) { $unique_pallet_wattages[$p_avail['wattage']] = true; }
                    ksort($unique_pallet_wattages);
                    foreach (array_keys($unique_pallet_wattages) as $wattage_avail):
                ?>
                    <option value="<?php echo htmlspecialchars($wattage_avail); ?>"><?php echo htmlspecialchars($wattage_avail); ?>W</option>
                <?php endforeach; 
                }
                ?>
            </select>
        </div>

        <!-- Show current range -->
        <?php if ($total_pallets > 0): ?>
            <div style="margin-bottom: 15px; padding: 10px; background-color: #e3f2fd; border-radius: 5px; text-align: center;">
                <strong>Showing pallets <?php echo number_format($offset + 1); ?> - <?php echo number_format(min($offset + $pallets_per_page, $total_pallets)); ?> of <?php echo number_format($total_pallets); ?></strong>
                <?php if ($total_pages > 1): ?>
                    <br><small style="color: #666;">Use pagination above to view more pallets</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="deliveries-table" id="availablePalletsTable">
                <thead>
                    <tr>
                        <th>Pallet Identifier</th>
                        <th>Wattage</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Arrival Date</th>
                        <th>Current Warehouse</th>
                        <th>Assigned Project</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($available_pallets)): ?>
                        <?php foreach ($available_pallets as $pallet_item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pallet_item['pallet_identifier'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['wattage'] ?? 'N/A'); ?>W</td>
                                <td><?php echo htmlspecialchars($pallet_item['quantity'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['pallet_status'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['pallet_status_timestamp'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['current_warehouse_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['assigned_project_name'] ?? 'Unassigned'); ?></td>
                                <td>
                                    <a href="pallet_details.php?pallet_id=<?php echo $pallet_item['pallet_id']; ?>" class="action-button" target="_blank">View Details</a>
                                    <a href="manage_delivery_pallets.php?pallet_id=<?php echo $pallet_item['pallet_id']; ?>&wattage=<?php echo urlencode($pallet_item['wattage']); ?>" class="action-button" style="background-color: #28a745;">Link to Delivery</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">No available pallets found matching the criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div> <!-- End Pallets Tab -->

    <!-- Bulk Linking Tab Content -->
    <div id="bulkLinkingTab" class="tab-content">
        <h2>Bulk Linking</h2>
        <p style="color: #666; margin-bottom: 20px;">Efficiently link existing pallets to existing deliveries using smart matching or CSV upload.</p>
        
        <!-- One-Time Origin Update Section -->
        <?php if ($deliveries_needing_origin_update > 0): ?>
        <div class="origin-update-section" style="margin-bottom: 30px; padding: 20px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px;">
            <h3 style="margin-top: 0; color: #856404;">🔧 One-Time Origin Update</h3>
            <p style="font-size: 0.9em; color: #856404; margin-bottom: 15px;">
                <strong><?php echo $deliveries_needing_origin_update; ?> deliveries</strong> with linked pallets need origin tracking for the movement map. This will set them to 'manufacturer' origin.
            </p>
            <form action="link_pallet_deliveries.php" method="post" style="display: flex; gap: 15px; align-items: center;">
                <button type="submit" name="update_existing_origins" class="action-button" style="background-color: #ffc107; color: #212529;" 
                        onclick="return confirm('This will update <?php echo $deliveries_needing_origin_update; ?> existing deliveries to have manufacturer origin. Continue?')">
                    Update <?php echo $deliveries_needing_origin_update; ?> Delivery Origins
                </button>
                <span style="font-size: 0.85em; color: #856404;">⚠️ Run this once to fix existing data</span>
            </form>
        </div>
        <?php endif; ?>

        <!-- CSV Upload Section (moved here) -->
        <div class="csv-upload-section" style="margin-bottom: 30px; padding: 20px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px;">
            <h3 style="margin-top: 0; color: #488C9A;">📄 Link Pallets via CSV</h3>
            <p style="font-size: 0.9em; color: #666; margin-bottom: 15px;">
                Upload a CSV file with columns: <code>delivery_id,pallet_identifier</code>
            </p>
            <form action="link_pallet_deliveries.php" method="post" enctype="multipart/form-data" style="display: flex; gap: 15px; align-items: center;">
                <input type="file" name="link_csv_file" accept=".csv" required style="flex: 1; padding: 8px;">
                <button type="submit" name="upload_link_csv" class="action-button">Upload CSV to Link</button>
            </form>
        </div>

        <!-- Smart Matching Section -->
        <div class="smart-matching-section" style="margin-bottom: 30px;">
            <h3 style="color: #293E4C; margin-bottom: 15px; font-size: 1.3em;">Smart Matching</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                
                <!-- Left Panel: Deliveries Needing Pallets -->
                <div>
                    <h4 style="margin-bottom: 15px; color: #293E4C;">📦 Deliveries Needing Pallets</h4>
                    <div class="delivery-groups" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px;">
                        <?php if (!empty($delivery_groups)): ?>
                            <?php foreach ($delivery_groups as $group): ?>
                                <?php
                                // Determine the lane (Origin-Destination) for this delivery group
                                // Check if any deliveries in this group have origin information
                                $delivery_ids_array = explode(',', $group['delivery_ids']);
                                $sample_delivery_id = $delivery_ids_array[0]; // Use first delivery as sample
                                
                                $lane_origin = 'Manufacturer'; // Default origin
                                $lane_destination = $group['project_name'] ?? 'Unassigned';
                                
                                // Check if this delivery has specific origin information
                                $stmt_check_lane = $conn->prepare("SELECT origin_type, warehouse_id FROM deliveries WHERE id = ?");
                                if ($stmt_check_lane) {
                                    $stmt_check_lane->bind_param("i", $sample_delivery_id);
                                    $stmt_check_lane->execute();
                                    $result_lane = $stmt_check_lane->get_result();
                                    if ($lane_data = $result_lane->fetch_assoc()) {
                                        if ($lane_data['origin_type'] === 'warehouse' && $lane_data['warehouse_id']) {
                                            // Get warehouse name for origin
                                            $stmt_wh = $conn->prepare("SELECT name FROM warehouses WHERE id = ?");
                                            if ($stmt_wh) {
                                                $stmt_wh->bind_param("i", $lane_data['warehouse_id']);
                                                $stmt_wh->execute();
                                                $result_wh = $stmt_wh->get_result();
                                                if ($wh_data = $result_wh->fetch_assoc()) {
                                                    $lane_origin = $wh_data['name'];
                                                }
                                                $stmt_wh->close();
                                            }
                                        }
                                        // If origin_type is 'manufacturer' or null, keep default 'Manufacturer'
                                    }
                                    $stmt_check_lane->close();
                                }
                                
                                $lane_display = $lane_origin . ' → ' . $lane_destination;
                                ?>
                                <div class="group-item delivery-group" data-wattage="<?php echo $group['wattage']; ?>" 
                                     data-project-id="<?php echo $group['project_id']; ?>"
                                     data-delivery-ids="<?php echo $group['delivery_ids']; ?>"
                                     data-delivery-count="<?php echo $group['delivery_count']; ?>"
                                     data-total-modules="<?php echo $group['total_modules']; ?>"
                                     style="padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; transition: background-color 0.2s;"
                                     onclick="selectDeliveryGroup(this)">
                                    <div style="font-weight: 600; color: #488C9A; margin-bottom: 5px;">
                                        <?php echo htmlspecialchars($group['wattage']); ?>W Deliveries
                                    </div>
                                    <div style="font-size: 0.9em; color: #666;">
                                        <strong><?php echo $group['delivery_count']; ?> deliveries</strong> need pallets
                                        (<?php echo number_format($group['total_modules']); ?> modules)
                                    </div>
                                    <div style="font-size: 0.85em; color: #666; margin-top: 3px;">
                                        Project: <?php echo htmlspecialchars($group['project_name'] ?? 'Unassigned'); ?>
                                    </div>
                                    <div style="font-size: 0.85em; color: #488C9A; margin-top: 2px; font-weight: 500;">
                                        Lane: <?php echo htmlspecialchars($lane_display); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 20px; text-align: center; color: #666;">
                                No deliveries found that need pallets.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Panel: Available Pallets -->
                <div>
                    <h4 style="margin-bottom: 15px; color: #293E4C;">🏗️ Available Pallets</h4>
                    <div class="pallet-groups" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px;">
                        <?php if (!empty($pallet_groups)): ?>
                            <?php foreach ($pallet_groups as $group): ?>
                                <?php
                                // Determine the lane for pallets - they're typically at manufacturer or warehouse
                                // Check the status of a sample pallet to determine current location
                                $pallet_ids_array = explode(',', $group['pallet_ids']);
                                $sample_pallet_id = $pallet_ids_array[0]; // Use first pallet as sample
                                
                                $pallet_lane_origin = 'Manufacturer'; // Default
                                $pallet_lane_destination = $group['assigned_project_name'] ?? 'Unassigned';
                                
                                // Check pallet's current location
                                $stmt_check_pallet_lane = $conn->prepare("SELECT status, current_warehouse_id FROM inventory_pallets WHERE id = ?");
                                if ($stmt_check_pallet_lane) {
                                    $stmt_check_pallet_lane->bind_param("i", $sample_pallet_id);
                                    $stmt_check_pallet_lane->execute();
                                    $result_pallet_lane = $stmt_check_pallet_lane->get_result();
                                    if ($pallet_lane_data = $result_pallet_lane->fetch_assoc()) {
                                        if ($pallet_lane_data['status'] === 'In Warehouse' && $pallet_lane_data['current_warehouse_id']) {
                                            // Get warehouse name for origin
                                            $stmt_pallet_wh = $conn->prepare("SELECT name FROM warehouses WHERE id = ?");
                                            if ($stmt_pallet_wh) {
                                                $stmt_pallet_wh->bind_param("i", $pallet_lane_data['current_warehouse_id']);
                                                $stmt_pallet_wh->execute();
                                                $result_pallet_wh = $stmt_pallet_wh->get_result();
                                                if ($pallet_wh_data = $result_pallet_wh->fetch_assoc()) {
                                                    $pallet_lane_origin = $pallet_wh_data['name'];
                                                }
                                                $stmt_pallet_wh->close();
                                            }
                                        }
                                        // For 'At Manufacturer', 'Produced', etc., keep default 'Manufacturer'
                                    }
                                    $stmt_check_pallet_lane->close();
                                }
                                
                                $pallet_lane_display = $pallet_lane_origin . ' → ' . $pallet_lane_destination;
                                ?>
                                <div class="group-item pallet-group" data-wattage="<?php echo $group['wattage']; ?>" 
                                     data-project-id="<?php echo $group['assigned_project_id']; ?>"
                                     data-pallet-ids="<?php echo $group['pallet_ids']; ?>"
                                     data-pallet-count="<?php echo $group['pallet_count']; ?>"
                                     data-total-modules="<?php echo $group['total_modules']; ?>"
                                     style="padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; transition: background-color 0.2s;"
                                     onclick="selectPalletGroup(this)">
                                    <div style="font-weight: 600; color: #f39c12; margin-bottom: 5px;">
                                        <?php echo htmlspecialchars($group['wattage']); ?>W Pallets
                                    </div>
                                    <div style="font-size: 0.9em; color: #666;">
                                        <strong><?php echo $group['pallet_count']; ?> pallets</strong> 
                                        (<?php echo number_format($group['total_modules']); ?> modules)
                                    </div>
                                    <div style="font-size: 0.85em; color: #666; margin-top: 3px;">
                                        Project: <?php echo htmlspecialchars($group['assigned_project_name'] ?? 'Unassigned'); ?>
                                    </div>
                                    <div style="font-size: 0.85em; color: #f39c12; margin-top: 2px; font-weight: 500;">
                                        Lane: <?php echo htmlspecialchars($pallet_lane_display); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 20px; text-align: center; color: #666;">
                                No available pallets found.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Match Buttons -->
            <div class="quick-match-buttons">
                <button class="quick-match-btn" onclick="clearSelections()">
                    🔄 Clear Selections
                </button>
            </div>

            <!-- Bulk Action Controls -->
            <div style="margin-top: 20px; padding: 20px; background-color: #f8f9fa; border-radius: 8px; border: 1px solid #dee2e6;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div>
                        <strong>Selected:</strong> 
                        <span id="selectedDeliveryGroup" style="color: #488C9A;">No delivery group selected</span>
                        <span style="margin: 0 10px;">+</span>
                        <span id="selectedPalletGroup" style="color: #f39c12;">No pallet group selected</span>
                    </div>
                    <div>
                        <button id="bulkLinkButton" class="action-button" disabled onclick="performBulkLink()">
                            Link Selected Groups
                        </button>
                    </div>
                </div>
                <div id="linkingStrategy" class="strategy-selection" style="display: none;">
                    <label style="font-weight: 500; margin-bottom: 10px; display: block;">Linking Strategy:</label>
                    <div style="margin-bottom: 15px;">
                        <label style="font-weight: normal; display: flex; align-items: center; margin-bottom: 15px;">
                            <input type="radio" name="link_strategy" value="module_based" checked style="margin-right: 8px;"> 
                            <strong>Module-Based Matching</strong> (Recommended)
                        </label>
                        <div style="margin-left: 25px; margin-bottom: 15px; font-size: 0.9em; color: #666;">
                            Automatically assigns pallets to match each delivery's exact module requirements
                        </div>
                        
                        <label style="font-weight: normal; display: flex; align-items: center; margin-bottom: 10px;">
                            <input type="radio" name="link_strategy" value="pallets_per_truck" style="margin-right: 8px;"> 
                            Fixed Pallets per Truck: 
                            <input type="number" id="palletsPerTruck" min="1" max="50" value="17" style="margin-left: 10px; width: 60px; padding: 4px; border: 1px solid #ccc; border-radius: 3px;">
                            pallets per delivery
                        </label>
                        <div style="margin-left: 25px; margin-bottom: 15px; font-size: 0.9em; color: #666;">
                            Use when all trucks carry the same number of pallets (may over/under allocate)
                        </div>
                        
                        <label style="font-weight: normal; display: flex; align-items: center;">
                            <input type="radio" name="link_strategy" value="distribute" style="margin-right: 8px;"> 
                            Distribute Evenly
                        </label>
                        <div style="margin-left: 25px; font-size: 0.9em; color: #666;">
                            Spreads all available pallets evenly across all deliveries
                        </div>
                    </div>
                    <div style="margin-top: 15px; font-size: 0.9em; color: #666; background-color: #e8f4f8; padding: 12px; border-radius: 5px; border-left: 4px solid #488C9A;">
                        <strong>💡 Tip:</strong> Use "Module-Based Matching" for accurate allocation based on actual delivery requirements. This prevents over/under allocation issues.
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <div id="bulkLinkResults" style="display: none; margin-top: 20px; padding: 15px; border-radius: 8px;">
            <h4 style="margin-top: 0;">Bulk Linking Results</h4>
            <div id="bulkLinkResultsContent"></div>
        </div>
    </div> <!-- End Bulk Linking Tab -->

    <!-- Associated Pallets Modal -->
    <div id="associatedPalletsModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeAssociatedPalletsModal()">&times;</span>
            <h2>Associated Pallets</h2>
            <div id="palletListContainer"> 
                <!-- Pallet details will be loaded here by JS -->
            </div>
        </div>
    </div>

</main>

<script>
    // Show Associated Pallets Modal
    var associatedPalletsModal = document.getElementById('associatedPalletsModal');
    var palletListContainerDiv = document.getElementById('palletListContainer'); 

    function showAssociatedPalletsModal(buttonElement) {
        var palletsJson = buttonElement.getAttribute('data-pallets');
        try {
            var pallets = JSON.parse(palletsJson);
            palletListContainerDiv.innerHTML = ''; 

            if (pallets.length > 0) {
                var table = document.createElement('table');
                table.style.width = '100%';
                table.style.borderCollapse = 'collapse';

                var thead = table.createTHead();
                var headerRow = thead.insertRow();
                var headers = ['Identifier', 'Wattage', 'Quantity', 'Actions'];
                headers.forEach(function(headerText) {
                    var th = document.createElement('th');
                    th.textContent = headerText;
                    th.style.border = '1px solid #ddd';
                    th.style.padding = '8px';
                    th.style.textAlign = 'left';
                    th.style.backgroundColor = '#e9ecef';
                    headerRow.appendChild(th);
                });

                var tbody = table.createTBody();
                pallets.forEach(function(pallet) {
                    var row = tbody.insertRow();
                    
                    var cellIdentifier = row.insertCell();
                    cellIdentifier.textContent = pallet.pallet_identifier ? pallet.pallet_identifier : `ID: ${pallet.id}`;
                    cellIdentifier.style.border = '1px solid #ddd';
                    cellIdentifier.style.padding = '8px';

                    var cellWattage = row.insertCell();
                    cellWattage.textContent = pallet.wattage ? `${pallet.wattage}W` : 'N/A';
                    cellWattage.style.border = '1px solid #ddd';
                    cellWattage.style.padding = '8px';

                    var cellQuantity = row.insertCell();
                    cellQuantity.textContent = pallet.quantity ? pallet.quantity : 'N/A';
                    cellQuantity.style.border = '1px solid #ddd';
                    cellQuantity.style.padding = '8px';

                    var cellActions = row.insertCell();
                    cellActions.style.border = '1px solid #ddd';
                    cellActions.style.padding = '8px';
                    cellActions.style.textAlign = 'center';

                    var viewBtn = document.createElement('a');
                    viewBtn.href = `pallet_details.php?pallet_id=${pallet.id}`;
                    viewBtn.textContent = 'View Details';
                    viewBtn.className = 'action-button';
                    viewBtn.target = '_blank';
                    cellActions.appendChild(viewBtn);
                });

                palletListContainerDiv.appendChild(table); 
            } else {
                 palletListContainerDiv.innerHTML = '<p>No pallets currently associated.</p>';
            }
            associatedPalletsModal.style.display = 'block';
        } catch (e) {
            console.error("Error in showAssociatedPalletsModal: ", e);
            palletListContainerDiv.innerHTML = '<p>Error loading pallet data.</p>';
            if (associatedPalletsModal) associatedPalletsModal.style.display = 'block';
        }
    }

    function closeAssociatedPalletsModal() {
         if(associatedPalletsModal) associatedPalletsModal.style.display = 'none';
         if(palletListContainerDiv) palletListContainerDiv.innerHTML = ''; 
    }

    // Placeholder for Manage Pallets Modal (Phase 3)
    function openManagePalletsModal(deliveryId, deliveryWattage) {
        document.getElementById('modalDeliveryId').textContent = deliveryId;
        document.getElementById('modalDeliveryWattage').textContent = deliveryWattage;
        // Here you would typically load available pallets via AJAX based on wattage and deliveryId
        // For now, just display the modal:
        var modal = document.getElementById('managePalletsLinkModal');
        if(modal) modal.style.display = 'block';
    }

    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target == associatedPalletsModal) {
            closeAssociatedPalletsModal();
        }
        var manageModal = document.getElementById('managePalletsLinkModal');
        if (event.target == manageModal) {
            manageModal.style.display = 'none';
        }
    });

    // Table search (from manage_deliveries - simplified for this page's filters)
    // This is a basic client-side search. For large datasets, server-side filtering is better.
    // The current PHP already handles server-side filtering based on the search_term input.
    // This JS search can be a quick local filter on the currently displayed results if desired,
    // but it might conflict or be redundant with the server-side search.
    // For now, relying on server-side search via form submission.

    // --- Tab Switching Logic ---
    document.addEventListener('DOMContentLoaded', function() {
        const tabLinks = document.querySelectorAll('.tabs .tab-link');
        const tabContents = document.querySelectorAll('.tab-content');

        function openTab(targetTabId) {
            tabContents.forEach(content => {
                content.classList.remove('active');
                content.style.display = 'none';
            });
            tabLinks.forEach(link => {
                link.classList.remove('active');
            });

            const activeTabContent = document.getElementById(targetTabId);
            const activeTabLink = document.querySelector(`.tab-link[data-tab="${targetTabId}"]`);

            if (activeTabContent) {
                activeTabContent.classList.add('active');
                activeTabContent.style.display = 'block';
            }
            if (activeTabLink) {
                activeTabLink.classList.add('active');
            }
        }

        tabLinks.forEach(link => {
            link.addEventListener('click', function(event) {
                event.preventDefault();
                const tabId = this.getAttribute('data-tab');
                openTab(tabId);
            });
        });

        // Check URL parameter for active tab, otherwise use default
        const urlParams = new URLSearchParams(window.location.search);
        const activeTabParam = urlParams.get('active_tab');
        
        if (activeTabParam && document.getElementById(activeTabParam)) {
            openTab(activeTabParam);
        } else {
            // Activate the default tab (Deliveries)
            const initialActiveTab = document.querySelector('.tab-link.active');
            if (initialActiveTab) {
                openTab(initialActiveTab.getAttribute('data-tab'));
            } else if (document.getElementById('deliveriesTab')) {
                openTab('deliveriesTab');
            }
        }
        
        // Initialize table filters if they are now visible
        filterDeliveriesTable(); 
        filterPalletsTable();
    });

    // Filter function for Deliveries Table (adapted from original searchTable)
    function filterDeliveriesTable() {
        var input = document.getElementById("search_term_deliveries"); // This is the search for deliveries
        var filter = input ? input.value.toLowerCase() : "";
        var table = document.getElementById("deliveriesTable");
        if (!table) return;
        var trs = table.getElementsByTagName("tr");

        for (var i = 1; i < trs.length; i++) { // Skip header
            var tds = trs[i].getElementsByTagName("td");
            var show = false;
            if (tds.length > 0) {
                for (var j = 0; j < tds.length; j++) {
                    // Avoid trying to read text from button/input cells if they cause issues
                    if (tds[j].querySelector('button, input, a.action-button, form')) continue; 
                    var txtValue = tds[j].textContent || tds[j].innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        show = true;
                        break;
                    }
                }
            }
            trs[i].style.display = show ? "" : "none";
        }
    }
    // Ensure the original search input for deliveries calls filterDeliveriesTable
    const deliveriesSearchInput = document.getElementById("search_term_deliveries"); 
    if(deliveriesSearchInput) {
        // deliveriesSearchInput.removeEventListener('keyup', searchTable); // If searchTable was globally defined
        deliveriesSearchInput.addEventListener('keyup', filterDeliveriesTable);
    }


    // --- Filter function for Available Pallets table ---
    function filterPalletsTable() {
        var input, statusFilterValue, wattageFilterValue, table, tr, td, i, txtValue, showRow;
        input = document.getElementById("palletSearchInput");
        var filter = input ? input.value.toLowerCase() : "";

        var statusSelect = document.getElementById("palletStatusFilter");
        statusFilterValue = statusSelect ? statusSelect.value.toLowerCase() : "";

        var wattageSelect = document.getElementById("palletWattageFilter");
        wattageFilterValue = wattageSelect ? wattageSelect.value.toLowerCase().replace('w','') : "";

        table = document.getElementById("availablePalletsTable");
        if (!table) return;
        tr = table.getElementsByTagName("tr");

        for (i = 1; i < tr.length; i++) { // Start from 1 to skip header row
            showRow = true;
            var palletIdentifierCell = tr[i].getElementsByTagName("td")[0];
            var wattageCell = tr[i].getElementsByTagName("td")[1];
            var statusCell = tr[i].getElementsByTagName("td")[3];
            var warehouseCell = tr[i].getElementsByTagName("td")[5];
            var projectCell = tr[i].getElementsByTagName("td")[6];

            // Text search (Identifier, Warehouse, Project)
            if (filter) {
                var searchableText = "";
                if (palletIdentifierCell) searchableText += (palletIdentifierCell.textContent || palletIdentifierCell.innerText);
                if (warehouseCell) searchableText += (warehouseCell.textContent || warehouseCell.innerText);
                if (projectCell) searchableText += (projectCell.textContent || projectCell.innerText);
                
                if (searchableText.toLowerCase().indexOf(filter) === -1) {
                    showRow = false;
                }
            }

            // Status filter
            if (showRow && statusFilterValue) {
                if (statusCell) {
                    txtValue = statusCell.textContent || statusCell.innerText;
                    if (txtValue.toLowerCase() !== statusFilterValue) {
                        showRow = false;
                    }
                } else { showRow = false; }
            }

            // Wattage filter
            if (showRow && wattageFilterValue) {
                if (wattageCell) {
                    txtValue = (wattageCell.textContent || wattageCell.innerText).toLowerCase().replace('w','');
                    if (txtValue !== wattageFilterValue) {
                        showRow = false;
                    }
                } else { showRow = false; }
            }
            
            tr[i].style.display = showRow ? "" : "none";
        }
    }

    // Attach event listeners for pallet table filters
    const palletSearchInputEl = document.getElementById("palletSearchInput");
    if (palletSearchInputEl) palletSearchInputEl.addEventListener('keyup', filterPalletsTable);

    const palletStatusFilterEl = document.getElementById("palletStatusFilter");
    if (palletStatusFilterEl) palletStatusFilterEl.addEventListener('change', filterPalletsTable);

    const palletWattageFilterEl = document.getElementById("palletWattageFilter");
    if (palletWattageFilterEl) palletWattageFilterEl.addEventListener('change', filterPalletsTable);

    // Export Deliveries CSV
    const exportDeliveriesCsvBtn = document.getElementById('exportDeliveriesCsvBtn');
    if (exportDeliveriesCsvBtn) {
        exportDeliveriesCsvBtn.addEventListener('click', function() {
            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('export_deliveries_csv', '1');
            window.location.href = currentUrl.toString();
        });
    }

    // Export Available Pallets CSV - Note: This button doesn't exist in current HTML
    const exportAvailablePalletsCsvBtn = document.getElementById('exportAvailablePalletsCsvBtn');
    if (exportAvailablePalletsCsvBtn) {
        exportAvailablePalletsCsvBtn.addEventListener('click', function() {
            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('export_available_pallets_csv', '1');
            window.location.href = currentUrl.toString();
        });
    }

    // --- BULK LINKING FUNCTIONALITY ---
    let selectedDeliveryGroup = null;
    let selectedPalletGroup = null;

    // Define functions in global scope
    function selectDeliveryGroup(element) {
        // Remove previous selection
        document.querySelectorAll('.delivery-groups .delivery-group').forEach(item => {
            item.style.backgroundColor = '';
            item.style.borderLeft = '';
            item.classList.remove('selected-delivery');
        });
        
        // Highlight selected
        element.style.backgroundColor = '#e3f2fd';
        element.style.borderLeft = '4px solid #488C9A';
        element.classList.add('selected-delivery');
        
        // Store selection
        selectedDeliveryGroup = {
            wattage: element.dataset.wattage,
            projectId: element.dataset.projectId,
            deliveryIds: element.dataset.deliveryIds.split(','),
            deliveryCount: element.dataset.deliveryCount,
            totalModules: element.dataset.totalModules,
            element: element
        };
        
        console.log('Selected delivery group:', selectedDeliveryGroup);
        updateBulkLinkUI();
    }

    // Make function available globally
    window.selectDeliveryGroup = selectDeliveryGroup;

    function selectPalletGroup(element) {
        // Remove previous selection
        document.querySelectorAll('.pallet-groups .pallet-group').forEach(item => {
            item.style.backgroundColor = '';
            item.style.borderLeft = '';
            item.classList.remove('selected-pallet');
        });
        
        // Highlight selected
        element.style.backgroundColor = '#fff3e0';
        element.style.borderLeft = '4px solid #f39c12';
        element.classList.add('selected-pallet');
        
        // Store selection
        selectedPalletGroup = {
            wattage: element.dataset.wattage,
            projectId: element.dataset.projectId,
            palletIds: element.dataset.palletIds.split(','),
            palletCount: element.dataset.palletCount,
            totalModules: element.dataset.totalModules,
            element: element
        };
        
        console.log('Selected pallet group:', selectedPalletGroup);
        updateBulkLinkUI();
    }

    // Make function available globally
    window.selectPalletGroup = selectPalletGroup;

    function updateBulkLinkUI() {
        const deliverySpan = document.getElementById('selectedDeliveryGroup');
        const palletSpan = document.getElementById('selectedPalletGroup');
        const linkButton = document.getElementById('bulkLinkButton');
        const strategyDiv = document.getElementById('linkingStrategy');
        
        // Add null checks
        if (!deliverySpan || !palletSpan || !linkButton || !strategyDiv) {
            console.log('Some bulk linking UI elements not found');
            return;
        }
        
        // Update delivery selection display
        if (selectedDeliveryGroup) {
            const projectName = selectedDeliveryGroup.element.querySelector('div:last-child').textContent.replace('Project: ', '');
            deliverySpan.textContent = `${selectedDeliveryGroup.wattage}W - ${selectedDeliveryGroup.deliveryCount} deliveries (${parseInt(selectedDeliveryGroup.totalModules).toLocaleString()} modules) - ${projectName}`;
        } else {
            deliverySpan.textContent = 'No delivery group selected';
        }
        
        // Update pallet selection display
        if (selectedPalletGroup) {
            const projectName = selectedPalletGroup.element.querySelector('div:last-child').textContent.replace('Project: ', '');
            palletSpan.textContent = `${selectedPalletGroup.wattage}W - ${selectedPalletGroup.palletCount} pallets (${parseInt(selectedPalletGroup.totalModules).toLocaleString()} modules) - ${projectName}`;
        } else {
            palletSpan.textContent = 'No pallet group selected';
        }
        
        // Enable/disable link button and show strategy options
        const canLink = selectedDeliveryGroup && selectedPalletGroup && 
                       selectedDeliveryGroup.wattage === selectedPalletGroup.wattage;
        
        linkButton.disabled = !canLink;
        strategyDiv.style.display = canLink ? 'block' : 'none';
        
        if (canLink && selectedDeliveryGroup.wattage !== selectedPalletGroup.wattage) {
            linkButton.textContent = 'Wattage Mismatch!';
            linkButton.disabled = true;
        } else if (canLink) {
            linkButton.textContent = 'Link Selected Groups';
            linkButton.disabled = false;
        }
    }

         function performBulkLink() {
        if (!selectedDeliveryGroup || !selectedPalletGroup) {
            alert('Please select both a delivery group and a pallet group.');
            return;
        }
        
        if (selectedDeliveryGroup.wattage !== selectedPalletGroup.wattage) {
            alert('Wattage mismatch! Please select groups with matching wattage.');
            return;
        }
        
        const strategy = document.querySelector('input[name="link_strategy"]:checked').value;
        const deliveryIds = selectedDeliveryGroup.deliveryIds;
        const palletIds = selectedPalletGroup.palletIds;
        
        // Get pallets per truck value if that strategy is selected
        let palletsPerTruck = 1;
        if (strategy === 'pallets_per_truck') {
            const palletsPerTruckInput = document.getElementById('palletsPerTruck');
            if (palletsPerTruckInput) {
                palletsPerTruck = parseInt(palletsPerTruckInput.value) || 1;
            }
        }
        
        // Show loading state
        const bulkLinkBtn = document.getElementById('bulkLinkButton');
        if (bulkLinkBtn) {
            bulkLinkBtn.textContent = 'Linking...';
            bulkLinkBtn.disabled = true;
        }
        
        // Prepare data for submission
        const formData = new FormData();
        formData.append('action', 'bulk_link');
        formData.append('delivery_ids', JSON.stringify(deliveryIds));
        formData.append('pallet_ids', JSON.stringify(palletIds));
        formData.append('strategy', strategy);
        formData.append('wattage', selectedDeliveryGroup.wattage);
        formData.append('pallets_per_truck', palletsPerTruck);
        
        // Submit via fetch
        fetch('link_pallet_deliveries.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            showBulkLinkResults(data);
            // Reset selections
            selectedDeliveryGroup = null;
            selectedPalletGroup = null;
            document.querySelectorAll('.group-item').forEach(item => {
                item.style.backgroundColor = '';
                item.style.borderLeft = '';
            });
            updateBulkLinkUI();
            
            // Refresh page to show updated counts
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        })
        .catch(error => {
            console.error('Error:', error);
            showBulkLinkResults({
                success: false,
                message: 'An error occurred during bulk linking.',
                details: error.message
            });
        })
        .finally(() => {
            const bulkLinkBtn = document.getElementById('bulkLinkButton');
            if (bulkLinkBtn) {
                bulkLinkBtn.textContent = 'Link Selected Groups';
                bulkLinkBtn.disabled = false;
            }
        });
    }

    // Make function available globally
    window.performBulkLink = performBulkLink;

    function showBulkLinkResults(data) {
        const resultsDiv = document.getElementById('bulkLinkResults');
        const contentDiv = document.getElementById('bulkLinkResultsContent');
        
        resultsDiv.style.display = 'block';
        resultsDiv.style.backgroundColor = data.success ? '#d4edda' : '#f8d7da';
        resultsDiv.style.border = data.success ? '1px solid #c3e6cb' : '1px solid #f5c6cb';
        resultsDiv.style.color = data.success ? '#155724' : '#721c24';
        
        let html = `<p><strong>${data.message}</strong></p>`;
        
        if (data.details) {
            html += `<div style="margin-top: 10px; font-size: 0.9em;">${data.details}</div>`;
        }
        
        if (data.linked_pairs && data.linked_pairs.length > 0) {
            html += '<div style="margin-top: 15px;"><strong>Linked:</strong><ul style="margin: 5px 0;">';
            data.linked_pairs.forEach(pair => {
                html += `<li>Delivery ${pair.delivery_id} ↔ Pallet ${pair.pallet_identifier}</li>`;
            });
            html += '</ul></div>';
        }
        
        contentDiv.innerHTML = html;
        
                 // Scroll to results
         resultsDiv.scrollIntoView({ behavior: 'smooth' });
     }

     // Quick match functions

     function clearSelections() {
         selectedDeliveryGroup = null;
         selectedPalletGroup = null;
         
         document.querySelectorAll('.delivery-group, .pallet-group').forEach(item => {
             item.style.backgroundColor = '';
             item.style.borderLeft = '';
             item.classList.remove('selected-delivery', 'selected-pallet');
         });
         
         updateBulkLinkUI();
         
         // Hide results if visible
         const resultsDiv = document.getElementById('bulkLinkResults');
         if (resultsDiv) {
             resultsDiv.style.display = 'none';
         }
     }

     // Make function available globally
     window.clearSelections = clearSelections;

     // Add event listeners for strategy selection
     document.addEventListener('DOMContentLoaded', function() {
         const strategyRadios = document.querySelectorAll('input[name="link_strategy"]');
         const palletsPerTruckInput = document.getElementById('palletsPerTruck');
         
         function updatePalletsPerTruckInput() {
             const selectedStrategy = document.querySelector('input[name="link_strategy"]:checked');
             if (palletsPerTruckInput && selectedStrategy) {
                 const isFixedPallets = selectedStrategy.value === 'pallets_per_truck';
                 palletsPerTruckInput.disabled = !isFixedPallets;
                 palletsPerTruckInput.style.opacity = isFixedPallets ? '1' : '0.5';
             }
         }
         
         strategyRadios.forEach(radio => {
             radio.addEventListener('change', updatePalletsPerTruckInput);
         });
         
         // Initialize on page load
         updatePalletsPerTruckInput();
     });

</script>
</body>
</html>
<?php
if ($conn) {
    $conn->close();
}
?> 
