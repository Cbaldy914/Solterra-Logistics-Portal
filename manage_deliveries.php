<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'global_admin' && $_SESSION['role'] != 'admin')) {
    header("Location: unauthorized");
    exit();
}

// NEW: Get selected project filter. Can be 'all', 'unassigned', or a specific project ID.
$filter_project_id = isset($_GET['filter_project_id']) ? $_GET['filter_project_id'] : 'all'; // Default to 'all'

// OLD project_id from URL is now used to pre-select the filter if `filter_project_id` isn't set
if (isset($_GET['project_id']) && empty($_GET['filter_project_id'])) {
    $filter_project_id = intval($_GET['project_id']);
}

// Set up time filter variables (if not passed, use defaults)
$time_filter   = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date      = isset($_GET['ref_date']) ? $_GET['ref_date'] : date('Y-m-d');
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$delivery_type = isset($_GET['delivery_type']) ? $_GET['delivery_type'] : 'all';

// Handle delivery_id parameter for highlighting specific delivery
$highlight_delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : null;

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

/**
 * Returns pallets to their origin based on delivery's origin_type and origin_id
 * @param mysqli $conn Database connection
 * @param array $delivery_ids Array of delivery IDs to process
 * @return array Array with success status and message
 */
function returnPalletsToOrigin($conn, $delivery_ids) {
    if (empty($delivery_ids)) {
        return ['success' => false, 'message' => 'No delivery IDs provided'];
    }
    
    $returned_pallets = 0;
    $errors = [];
    
    try {
        // Get all pallets associated with these deliveries along with their delivery origin info
        $placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));
        $types = str_repeat('i', count($delivery_ids));
        
        $stmt = $conn->prepare("
            SELECT dp.inventory_pallet_id, d.origin_type, d.origin_id, d.id as delivery_id,
                   w.name as origin_warehouse_name, p.project_name as origin_project_name
            FROM delivery_pallets dp
            JOIN deliveries d ON dp.delivery_id = d.id
            LEFT JOIN warehouses w ON d.origin_type = 'warehouse' AND d.origin_id = w.id
            LEFT JOIN projects p ON d.origin_type = 'project' AND d.origin_id = p.id
            WHERE dp.delivery_id IN ($placeholders)
        ");
        
        if (!$stmt) {
            throw new Exception("Failed to prepare pallet query: " . $conn->error);
        }
        
        $stmt->bind_param($types, ...$delivery_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $pallets_by_origin = [];
        while ($row = $result->fetch_assoc()) {
            $origin_key = $row['origin_type'] . '_' . ($row['origin_id'] ?? 'null');
            if (!isset($pallets_by_origin[$origin_key])) {
                $pallets_by_origin[$origin_key] = [
                    'type' => $row['origin_type'],
                    'id' => $row['origin_id'],
                    'name' => $row['origin_warehouse_name'] ?? $row['origin_project_name'] ?? 'Manufacturer',
                    'pallets' => []
                ];
            }
            $pallets_by_origin[$origin_key]['pallets'][] = $row['inventory_pallet_id'];
        }
        $stmt->close();
        
        // Process each origin group
        foreach ($pallets_by_origin as $origin_data) {
            $origin_type = $origin_data['type'];
            $origin_id = $origin_data['id'];
            $origin_name = $origin_data['name'];
            $pallet_ids = $origin_data['pallets'];
            
            if (empty($pallet_ids)) continue;
            
            // Determine the correct status and location based on origin type
            $new_status = 'At Manufacturer';
            $new_warehouse_id = null;
            $new_project_id = null;
            
            switch ($origin_type) {
                case 'warehouse':
                    $new_status = 'In Warehouse';
                    $new_warehouse_id = $origin_id;
                    break;
                case 'project':
                    $new_status = 'Delivered to Project';
                    $new_project_id = $origin_id;
                    break;
                case 'manufacturer':
                default:
                    $new_status = 'At Manufacturer';
                    break;
            }
            
            // Update pallets to return to origin
            $pallet_placeholders = implode(',', array_fill(0, count($pallet_ids), '?'));
            $pallet_types = 's' . ($new_warehouse_id ? 'i' : '') . ($new_project_id ? 'i' : '') . str_repeat('i', count($pallet_ids));
            
            $update_sql = "UPDATE inventory_pallets SET status = ?";
            $update_params = [$new_status];
            
            if ($new_warehouse_id) {
                $update_sql .= ", current_warehouse_id = ?";
                $update_params[] = $new_warehouse_id;
            } else {
                $update_sql .= ", current_warehouse_id = NULL";
            }
            
            if ($new_project_id) {
                $update_sql .= ", current_project_id = ?";
                $update_params[] = $new_project_id;
            } else {
                $update_sql .= ", current_project_id = NULL";
            }
            
            $update_sql .= " WHERE id IN ($pallet_placeholders)";
            $update_params = array_merge($update_params, $pallet_ids);
            
            $stmt_update = $conn->prepare($update_sql);
            if (!$stmt_update) {
                $errors[] = "Failed to prepare update for {$origin_name}: " . $conn->error;
                continue;
            }
            
            $stmt_update->bind_param($pallet_types, ...$update_params);
            if ($stmt_update->execute()) {
                $returned_pallets += $stmt_update->affected_rows;
            } else {
                $errors[] = "Failed to return pallets to {$origin_name}: " . $stmt_update->error;
            }
            $stmt_update->close();
        }
        
        $message = "Successfully returned {$returned_pallets} pallet(s) to their origin.";
        if (!empty($errors)) {
            $message .= " Errors: " . implode(', ', $errors);
        }
        
        return ['success' => true, 'message' => $message, 'returned_count' => $returned_pallets];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error returning pallets to origin: ' . $e->getMessage()];
    }
}

// --- NEW: Admin Account Fetching and Permission Setup ---
$account_id_for_admin = null;
$is_global_admin = ($_SESSION['role'] === 'global_admin');

if (!$is_global_admin) { // User role is 'admin'
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
        // Optional: Handle admin with no assigned account - perhaps redirect or show error
        // For now, they won't see any projects or deliveries if $account_id_for_admin remains null.
         $_SESSION['messages'][] = "<p class='error-message'>Error: Admin user is not associated with an account.</p>";
        // Consider adding exit() here if needed
    }
    // Allow admins to see all their account deliveries by default
    // No need to force 'unassigned' - admins can see 'all' their account deliveries
}
// --- END NEW --- 

// Fetch all projects for the filter dropdown
$all_projects_for_filter = [];
// --- MODIFIED: Filter projects based on role --- 
if ($is_global_admin) {
    $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
} else { // Admin role
    if ($account_id_for_admin) {
        // Only fetch projects for this admin's account
        $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
        $stmt_all_proj->bind_param("i", $account_id_for_admin);
    } else {
        // Prepare a statement that returns nothing if admin has no account
        $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects WHERE 1=0"); 
    }
}
// --- END MODIFIED --- 
if ($stmt_all_proj) {
    $stmt_all_proj->execute();
    $result_all_proj = $stmt_all_proj->get_result();
    while ($proj_row = $result_all_proj->fetch_assoc()) {
        $all_projects_for_filter[] = $proj_row;
    }
    $stmt_all_proj->close();
}

// Initialize messages
if (!isset($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

$project_name = "All Projects"; // Default page title
$default_freight_cost = 0; // Default if no specific project is selected

if (is_numeric($filter_project_id)) {
    // Fetch the specific project name and default freight cost
    $stmt_proj_details = $conn->prepare("SELECT project_name, default_freight_cost FROM projects WHERE id = ?");
    if ($stmt_proj_details) {
        $stmt_proj_details->bind_param("i", $filter_project_id);
        $stmt_proj_details->execute();
        $stmt_proj_details->bind_result($project_name_specific, $default_freight_cost_specific);
        if ($stmt_proj_details->fetch()) {
            $project_name = $project_name_specific;
            $default_freight_cost = $default_freight_cost_specific ?: 0;
        }
        $stmt_proj_details->close();
    }
} elseif ($filter_project_id === 'unassigned') {
    $project_name = "Unassigned Deliveries";
}

/*
  -----------------------------------------------------------------------------
  1) Handle Bulk Edit
  -----------------------------------------------------------------------------
*/
if (isset($_POST['bulk_edit_submit'])) {
    if (isset($_POST['selected_deliveries']) && !empty($_POST['selected_deliveries'])) {
        $selected_ids = array_map('intval', $_POST['selected_deliveries']);

        // --- NEW: Security check for admin role ---
        if (!$is_global_admin && $account_id_for_admin) {
            $valid_ids_for_admin = [];
            $id_placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $check_stmt = $conn->prepare(
                "SELECT d.id FROM deliveries d JOIN projects p ON d.project_id = p.id WHERE d.id IN ($id_placeholders) AND p.account_id = ?"
            );
            $check_types = str_repeat('i', count($selected_ids)) . 'i';
            $check_params = array_merge($selected_ids, [$account_id_for_admin]);
            $check_stmt->bind_param($check_types, ...$check_params);
            $check_stmt->execute();
            $result_check = $check_stmt->get_result();
            while ($row = $result_check->fetch_assoc()) {
                $valid_ids_for_admin[] = $row['id'];
            }
            $check_stmt->close();

            // Filter out any IDs that don't belong to the admin or are unassigned (unassigned handled separately or not allowed for bulk edit by admin)
            $selected_ids = array_intersect($selected_ids, $valid_ids_for_admin);

            if (empty($selected_ids)) {
                $_SESSION['messages'][] = "<p class='error-message'>Bulk edit failed: No valid deliveries selected for your account.</p>";
                header("Location: manage_deliveries?" . $_SERVER['QUERY_STRING']); // Redirect with original query params
                exit();
            }
        }
        // --- END NEW ---

        // Build dynamic SET clauses based on non-empty fields
        $updates = [];
        $types   = '';
        $values  = [];

        // List of possible fields to update - map manufacturer to supplier for backward compatibility
        $fields_to_update = [
            'manufacturer'            => 's',  // This will update the supplier field
            'wattage'                 => 's',
            'status_of_delivery'      => 's',
            'quantity'                => 'i',
            'bol_number'              => 's',
            'anticipated_delivery_date' => 's',
            'warehouse_arrival_date'  => 's',
            'actual_delivery_date'    => 's',
            'miles'                   => 'd',
            'freight_cost'            => 'd',
            'accessorial_costs'       => 'd',
            'customer_cost'           => 'd'
        ];

        // For each field, if user has provided a value, add to the update set
        foreach ($fields_to_update as $field => $bindType) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                // Map manufacturer field to supplier column in database
                $db_field = ($field === 'manufacturer') ? 'supplier' : $field;
                $updates[] = "$db_field = ?";
                $types    .= $bindType;

                // Convert to proper type if needed
                if ($bindType == 'i') {
                    $values[] = (int)$_POST[$field];
                } elseif ($bindType == 'd') {
                    $values[] = (float)$_POST[$field];
                } else {
                    $values[] = $_POST[$field];
                }
            }
        }

        if (!empty($updates)) {
            // Start transaction for bulk update
            $conn->begin_transaction();
            try {
                // Build the UPDATE query with placeholders for each selected ID
                $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                $sql = "UPDATE deliveries SET " . implode(", ", $updates) . " WHERE id IN ($placeholders)";

                $stmt = $conn->prepare($sql);
                // Add 'i' for each selected ID to bind their values
                $types .= str_repeat('i', count($selected_ids));

                // Merge $values with the list of selected IDs
                foreach ($selected_ids as $id) {
                    $values[] = $id;
                }

                $stmt->bind_param($types, ...$values);
                if (!$stmt->execute()) {
                    throw new Exception("Error on bulk update: " . $stmt->error);
                }
                $stmt->close();

                // Update associated pallet statuses if delivery status was changed
                $total_pallet_updates = 0;
                $new_delivery_status = null;
                
                // Check if status_of_delivery was updated
                if (isset($_POST['status_of_delivery']) && $_POST['status_of_delivery'] !== '') {
                    $new_delivery_status = $_POST['status_of_delivery'];
                    
                    // Map delivery statuses to corresponding pallet statuses
                    $status_mapping = [
                        'Delivered to Project' => 'Delivered to Project',
                        'Delivered to Warehouse' => 'In Warehouse',
                        'In Transit to Project' => 'In Transit to Project', 
                        'In Transit to Warehouse' => 'In Transit to Warehouse',
                        'Pending' => 'At Manufacturer' // Assume pallets go back to manufacturer if delivery is pending
                    ];
                    
                    if (isset($status_mapping[$new_delivery_status])) {
                        $new_pallet_status = $status_mapping[$new_delivery_status];
                        
                        // Get all pallets associated with the selected deliveries
                        $delivery_placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                        $stmt_get_pallets = $conn->prepare("
                            SELECT dp.inventory_pallet_id 
                            FROM delivery_pallets dp 
                            WHERE dp.delivery_id IN ($delivery_placeholders)
                        ");
                        
                        if ($stmt_get_pallets) {
                            $pallet_types = str_repeat('i', count($selected_ids));
                            $stmt_get_pallets->bind_param($pallet_types, ...$selected_ids);
                            $stmt_get_pallets->execute();
                            $result_pallets = $stmt_get_pallets->get_result();
                            $pallet_ids = [];
                            
                            while ($row = $result_pallets->fetch_assoc()) {
                                $pallet_ids[] = $row['inventory_pallet_id'];
                            }
                            $stmt_get_pallets->close();
                            
                            // Update pallet statuses if we found any pallets
                            if (!empty($pallet_ids)) {
                                $pallet_placeholders = implode(',', array_fill(0, count($pallet_ids), '?'));
                                $pallet_update_types = 's' . str_repeat('i', count($pallet_ids)); // status + pallet IDs
                                
                                $stmt_update_pallets = $conn->prepare("
                                    UPDATE inventory_pallets 
                                    SET status = ? 
                                    WHERE id IN ($pallet_placeholders)
                                ");
                                
                                if ($stmt_update_pallets) {
                                    $stmt_update_pallets->bind_param($pallet_update_types, $new_pallet_status, ...$pallet_ids);
                                    if ($stmt_update_pallets->execute()) {
                                        $total_pallet_updates = $stmt_update_pallets->affected_rows;
                                    }
                                    $stmt_update_pallets->close();
                                }
                            }
                        }
                    }
                }

                $conn->commit();
                
                $success_msg = "Bulk update successful.";
                if ($total_pallet_updates > 0) {
                    $success_msg .= " Also updated status for $total_pallet_updates associated pallet(s) to '$new_pallet_status'.";
                }
                $_SESSION['messages'][] = "<p>$success_msg</p>";
                
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['messages'][] = "<p class='error-message'>Error during bulk update: " . $e->getMessage() . "</p>";
            }
        } else {
            $_SESSION['messages'][] = "<p>No fields were filled in for bulk update.</p>";
        }
    } else {
        $_SESSION['messages'][] = "<p>No deliveries selected for bulk edit.</p>";
    }

    // Redirect back
    $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id) . "&delivery_type=" . urlencode($delivery_type);
    header("Location: manage_deliveries?" . $redirect_params);
    exit();
}

/*
  -----------------------------------------------------------------------------
  2) Handle Bulk Delete
  -----------------------------------------------------------------------------
*/
if (isset($_POST['delete_selected'])) {
    if (isset($_POST['selected_deliveries']) && !empty($_POST['selected_deliveries'])) {
        $selected_ids   = array_map('intval', $_POST['selected_deliveries']);
        
        // --- Initialize delete parameters based on initially selected IDs ---
        $delete_params = $selected_ids;
        $delete_types = str_repeat('i', count($selected_ids));
        $ids_placeholder = implode(',', array_fill(0, count($delete_params), '?')); // Placeholder based on initial count

        // --- NEW: Security check for admin role for delete ---
        if (!$is_global_admin && $account_id_for_admin) {
             $valid_ids_for_delete = [];
             $id_placeholders_del = implode(',', array_fill(0, count($selected_ids), '?'));
             
             // Check project-assigned deliveries
             $sql_check_project_deliveries = "SELECT d.id FROM deliveries d JOIN projects p ON d.project_id = p.id WHERE d.id IN ($id_placeholders_del) AND p.account_id = ?";
             $stmt_check_proj = $conn->prepare($sql_check_project_deliveries);
             $types_check_proj = str_repeat('i', count($selected_ids)) . 'i';
             $params_check_proj = array_merge($selected_ids, [$account_id_for_admin]);
             $stmt_check_proj->bind_param($types_check_proj, ...$params_check_proj);
             $stmt_check_proj->execute();
             $res_check_proj = $stmt_check_proj->get_result();
             while($r = $res_check_proj->fetch_assoc()) { $valid_ids_for_delete[] = $r['id']; }
             $stmt_check_proj->close();

             // Check unassigned deliveries (admins can delete unassigned too if $filter_project_id === 'unassigned')
             if ($filter_project_id === 'unassigned') {
                 $sql_check_unassigned = "SELECT id FROM deliveries WHERE id IN ($id_placeholders_del) AND project_id IS NULL";
                 $stmt_check_unassigned = $conn->prepare($sql_check_unassigned);
                 $types_check_unassigned = str_repeat('i', count($selected_ids));
                 $stmt_check_unassigned->bind_param($types_check_unassigned, ...$selected_ids);
                 $stmt_check_unassigned->execute();
                 $res_check_unassigned = $stmt_check_unassigned->get_result();
                 while($r = $res_check_unassigned->fetch_assoc()) { $valid_ids_for_delete[] = $r['id']; }
                 $stmt_check_unassigned->close();
             }

             $selected_ids = array_values(array_unique(array_intersect($selected_ids, $valid_ids_for_delete)));

             if (empty($selected_ids)) {
                 $_SESSION['messages'][] = "<p class='error-message'>Bulk delete failed: No valid deliveries selected for your account scope.</p>";
                 // Rebuild redirect_params for safety as it might not be set here
                 $redirect_params_del = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id) . "&delivery_type=" . urlencode($delivery_type);
                 header("Location: manage_deliveries?" . $redirect_params_del);
                 exit();
             }
             // Re-prepare delete parameters after filtering
             // --- IMPORTANT: Update params and types ONLY if IDs were filtered ---
             $delete_params = $selected_ids;
             $delete_types = str_repeat('i', count($selected_ids));
             $ids_placeholder = implode(',', array_fill(0, count($delete_params), '?')); // Regenerate placeholder
        }
        // --- END NEW ---

        // --- Apply project condition AFTER security check --- 
        $delete_project_condition = ''; // Initialize
        if (is_numeric($filter_project_id)) {
            $delete_project_condition = " AND project_id = ?";
            $delete_params[] = $filter_project_id; // Add to the (potentially filtered) params
            $delete_types .= 'i';
        } elseif ($filter_project_id === 'unassigned') {
            $delete_project_condition = " AND project_id IS NULL";
            // No additional param needed
        }
        // If 'all', no project condition, so it deletes from any project (dangerous, ensure this is intended or add further checks)

        // Start transaction for safe deletion with pallet return
        $conn->begin_transaction();
        try {
            // Return pallets to their origin before deleting deliveries
            $return_result = returnPalletsToOrigin($conn, $selected_ids);
            
            // Delete delivery_pallets links first
            $delivery_pallets_deleted = 0;
            if (!empty($selected_ids)) {
                $dp_placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                $dp_types = str_repeat('i', count($selected_ids));
                $stmt_dp = $conn->prepare("DELETE FROM delivery_pallets WHERE delivery_id IN ($dp_placeholders)");
                if ($stmt_dp) {
                    $stmt_dp->bind_param($dp_types, ...$selected_ids);
                    if ($stmt_dp->execute()) {
                        $delivery_pallets_deleted = $stmt_dp->affected_rows;
                    }
                    $stmt_dp->close();
                }
            }
            
            // Now delete the deliveries
            $stmt = $conn->prepare("DELETE FROM deliveries WHERE id IN ($ids_placeholder) $delete_project_condition");
            $stmt->bind_param($delete_types, ...$delete_params);
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete deliveries: " . $stmt->error);
            }
            $deleted_deliveries = $stmt->affected_rows;
            $stmt->close();
            
            $conn->commit();
            
            $success_message = "Successfully deleted {$deleted_deliveries} delivery(ies).";
            if ($return_result['success'] && $return_result['returned_count'] > 0) {
                $success_message .= " " . $return_result['message'];
            }
            $_SESSION['messages'][] = "<p>{$success_message}</p>";
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['messages'][] = "<p class='error-message'>Error deleting deliveries: " . $e->getMessage() . "</p>";
        }
    } else {
        $_SESSION['messages'][] = "<p>No deliveries selected for deletion.</p>";
    }

    $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id) . "&delivery_type=" . urlencode($delivery_type);
    header("Location: manage_deliveries?" . $redirect_params);
    exit();
}

/*
  -----------------------------------------------------------------------------
  3) Handle CSV Upload (now includes miles, freight_cost, accessorial_costs)
  -----------------------------------------------------------------------------
*/
if (isset($_POST['upload_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName    = $_FILES['csv_file']['name'];
        $fileSize    = $_FILES['csv_file']['size'];
        $fileType    = $_FILES['csv_file']['type'];

        $allowedFileTypes = ['text/csv', 'application/vnd.ms-excel'];
        $maxFileSize      = 2 * 1024 * 1024; // 2MB

        if (in_array($fileType, $allowedFileTypes) && $fileSize <= $maxFileSize) {
            $csvData = file_get_contents($fileTmpPath);
            $lines   = explode(PHP_EOL, $csvData);
            $header  = null;
            $data    = [];
            $importErrors = [];
            $insertedRows = 0;
            $updatedRows  = 0;

            foreach ($lines as $lineNumber => $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                $row = str_getcsv($line);

                // Skip empty lines
                $allFieldsEmpty = true;
                foreach ($row as $field) {
                    if (trim($field) !== '') {
                        $allFieldsEmpty = false;
                        break;
                    }
                }
                if ($allFieldsEmpty) {
                    continue;
                }

                // First non-empty row is the header
                if (!$header) {
                    $header = $row;
                } else {
                    // Validate column count
                    if (count($row) != count($header)) {
                        $rowNumber = $lineNumber + 1;
                        $importErrors[] = "Row $rowNumber: Column count does not match header.";
                        continue;
                    }
                    $data[] = array_combine($header, $row);
                }
            }

            // Process rows
            foreach ($data as $rowIndex => $rowData) {
                $rowNumber = $rowIndex + 2; // +2 because header is row 1, array index starts at 0

                // Required fields
                $requiredFields = ['manufacturer', 'wattage', 'status_of_delivery', 'quantity', 'bol_number', 'anticipated_delivery_date'];
                foreach ($requiredFields as $field) {
                    if (!isset($rowData[$field]) || trim($rowData[$field]) === '') {
                        $importErrors[] = "Row $rowNumber: Missing required field '$field'.";
                        continue 2;
                    }
                }

                // Gather required - map manufacturer to supplier for backward compatibility
                $supplier                = $rowData['manufacturer'];
                $wattage                 = $rowData['wattage'];
                $status_of_delivery      = $rowData['status_of_delivery'];
                $quantity                = intval($rowData['quantity']);
                $bol_number              = $rowData['bol_number'];
                $anticipated_delivery_date = $rowData['anticipated_delivery_date'];

                // Optional fields
                $warehouse_arrival_date  = !empty($rowData['warehouse_arrival_date']) ? $rowData['warehouse_arrival_date'] : null;
                $actual_delivery_date    = !empty($rowData['actual_delivery_date']) ? $rowData['actual_delivery_date'] : null;

                // New optional fields (miles, freight_cost, accessorial_costs)
                $miles = (isset($rowData['miles']) && trim($rowData['miles']) !== '') ? floatval($rowData['miles']) : null;
                $freight_cost = (isset($rowData['freight_cost']) && trim($rowData['freight_cost']) !== '') ? floatval($rowData['freight_cost']) : null;
                $accessorial_costs = (isset($rowData['accessorial_costs']) && trim($rowData['accessorial_costs']) !== '') ? floatval($rowData['accessorial_costs']) : null;

                // Check if this delivery (by BOL number) already exists FOR THE CURRENT PROJECT CONTEXT
                // If filter_project_id is 'all' or 'unassigned', this check might need adjustment or be less strict.
                // For now, assuming CSV upload is primarily for a specific project context.
                // If uploading for 'unassigned', project_id in DB should be NULL.
                // If uploading for 'all', this logic is complex and needs careful thought - perhaps disable CSV upload for 'all'.
                
                $current_upload_project_id_context = null;
                if (is_numeric($filter_project_id)) {
                    $current_upload_project_id_context = $filter_project_id;
                } // For 'unassigned', project_id is NULL. For 'all', it's tricky.

                if ($current_upload_project_id_context !== null) {
                    $stmt = $conn->prepare("SELECT id FROM deliveries WHERE project_id = ? AND bol_number = ?");
                    $stmt->bind_param("is", $current_upload_project_id_context, $bol_number);
                } else if ($filter_project_id === 'unassigned') {
                     $stmt = $conn->prepare("SELECT id FROM deliveries WHERE project_id IS NULL AND bol_number = ?");
                     $stmt->bind_param("s", $bol_number);
                } else {
                    // Potentially disallow CSV upload when "All Projects" is selected, or require a project_id column in CSV
                     $_SESSION['messages'][] = "<p>CSV Upload is not supported when 'All Projects' is selected without a project identifier in the CSV.</p>";
                     $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id) . "&delivery_type=" . urlencode($delivery_type);
                     header("Location: manage_deliveries?" . $redirect_params);
                     exit();
                }
                
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    // Update existing
                    $stmt->bind_result($existing_delivery_id);
                    $stmt->fetch();
                    $stmt->close();

                    $update_stmt = $conn->prepare("
                        UPDATE deliveries SET
                            supplier = ?,
                            wattage = ?,
                            status_of_delivery = ?,
                            quantity = ?,
                            anticipated_delivery_date = ?,
                            warehouse_arrival_date = ?,
                            actual_delivery_date = ?,
                            miles = ?,
                            freight_cost = ?,
                            accessorial_costs = ?
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param(
                        "sssisssdddi",
                        $supplier,
                        $wattage,
                        $status_of_delivery,
                        $quantity,
                        $anticipated_delivery_date,
                        $warehouse_arrival_date,
                        $actual_delivery_date,
                        $miles,
                        $freight_cost,
                        $accessorial_costs,
                        $existing_delivery_id
                    );

                    if ($update_stmt->execute()) {
                        $updatedRows++;
                    } else {
                        $importErrors[] = "Row $rowNumber: Database error during update - " . $update_stmt->error;
                    }
                    $update_stmt->close();

                } else {
                    // Insert new
                    $stmt->close();
                    $insert_stmt = $conn->prepare("
                        INSERT INTO deliveries
                        (project_id, supplier, wattage, status_of_delivery, quantity, bol_number, anticipated_delivery_date, warehouse_arrival_date, actual_delivery_date, miles, freight_cost, accessorial_costs)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    // Use $current_upload_project_id_context for project_id (which will be NULL if 'unassigned')
                    $insert_stmt->bind_param(
                        "isssissssddd",
                        $current_upload_project_id_context, 
                        $supplier,
                        $wattage,
                        $status_of_delivery,
                        $quantity,
                        $bol_number,
                        $anticipated_delivery_date,
                        $warehouse_arrival_date,
                        $actual_delivery_date,
                        $miles,
                        $freight_cost,
                        $accessorial_costs
                    );

                    if ($insert_stmt->execute()) {
                        $insertedRows++;
                    } else {
                        $importErrors[] = "Row $rowNumber: Database error during insert - " . $insert_stmt->error;
                    }
                    $insert_stmt->close();
                }
            }

            // Show errors if any
            if (!empty($importErrors)) {
                $errorMessages = "<div class='error-messages'>";
                $errorMessages .= "<h3>Some errors occurred during import:</h3>";
                $errorMessages .= "<ul>";
                foreach ($importErrors as $error) {
                    $errorMessages .= "<li>" . htmlspecialchars($error) . "</li>";
                }
                $errorMessages .= "</ul></div>";
                $_SESSION['messages'][] = $errorMessages;
            }

            $_SESSION['messages'][] = "<p>Successfully imported $insertedRows new entries and updated $updatedRows existing entries.</p>";
            $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id) . "&delivery_type=" . urlencode($delivery_type);
            header("Location: manage_deliveries?" . $redirect_params);
            exit();

        } else {
            $_SESSION['messages'][] = "<p>Invalid file type or file too large. Please upload a valid CSV file (max 2MB).</p>";
            $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id) . "&delivery_type=" . urlencode($delivery_type);
            header("Location: manage_deliveries?" . $redirect_params);
            exit();
        }
    } else {
        $_SESSION['messages'][] = "<p>Error uploading the file. Please try again.</p>";
        $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id) . "&delivery_type=" . urlencode($delivery_type);
        header("Location: manage_deliveries?" . $redirect_params);
        exit();
    }
}

/*
  -----------------------------------------------------------------------------
  4) Fetch Deliveries for Display
  -----------------------------------------------------------------------------
*/
$filterColumn = "COALESCE(d.actual_delivery_date, d.anticipated_delivery_date)";
$dateCondition = "";
$projectConditionSQL = ""; // New variable for project filtering condition
$paramTypes    = "d"; // for default_freight_cost (d) - will be prepended
$params        = [$default_freight_cost];

if (is_numeric($filter_project_id)) {
    $projectConditionSQL = " AND d.project_id = ?";
    $paramTypes .= "i";
    $params[] = $filter_project_id;
    // NEW: Add account check for admin
    if (!$is_global_admin && $account_id_for_admin) {
        $projectConditionSQL .= " AND p.account_id = ?";
        $paramTypes .= "i";
        $params[] = $account_id_for_admin;
    }
} elseif ($filter_project_id === 'unassigned') {
    $projectConditionSQL = " AND d.project_id IS NULL";
    // No parameter to add for IS NULL
} elseif ($filter_project_id === 'all' && $is_global_admin) {
    // No project condition for global admin viewing all
    $projectConditionSQL = ""; 
} else { 
    // Admin viewing their account's projects implicitly (or error state)
    // $projectConditionSQL should effectively filter by account_id if $filter_project_id isn't 'unassigned'
    if (!$is_global_admin && $account_id_for_admin) {
         $projectConditionSQL = " AND p.account_id = ?"; // Filter by account
         $paramTypes .= "i";
         $params[] = $account_id_for_admin;
    } else {
         $projectConditionSQL = " AND 1=0 "; // Prevent admin without account from seeing anything if logic reaches here unexpectedly
    }
}
// If $filter_project_id is 'all', $projectConditionSQL remains empty, showing all projects.

if ($time_filter === 'day') {
    $dateCondition .= " AND DATE($filterColumn) = ?";
    $paramTypes .= "s";
    $params[] = $ref_date;

    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($ref_date . " -1 day"));
    $next_date = date('Y-m-d', strtotime($ref_date . " +1 day"));
} elseif ($time_filter === 'week') {
    $timestamp   = strtotime($ref_date);
    $dayOfWeek   = date('w', $timestamp);
    $startOfWeek = date('Y-m-d', strtotime("-{$dayOfWeek} days", $timestamp));
    $endOfWeek   = date('Y-m-d', strtotime("+" . (6 - $dayOfWeek) . " days", $timestamp));

    $dateCondition .= " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    $params[] = $startOfWeek;
    $params[] = $endOfWeek;

    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime($startOfWeek . " -7 days"));
    $next_date = date('Y-m-d', strtotime($startOfWeek . " +7 days"));
} elseif ($time_filter === 'month') {
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth   = date('Y-m-t', strtotime($ref_date));

    $dateCondition .= " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    $params[] = $startOfMonth;
    $params[] = $endOfMonth;

    $dateLabel = date('F Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($startOfMonth . " -1 month"));
    $next_date = date('Y-m-d', strtotime($startOfMonth . " +1 month"));
} else {
    $dateLabel = "All Deliveries";
    $prev_date = $ref_date;
    $next_date = $ref_date;
}

if (!empty($status_filter)) {
    $statusCondition = " AND status_of_delivery = ?";
    $paramTypes .= "s";
    $params[] = $status_filter;
} else {
    $statusCondition = "";
}

// Add delivery type condition
$deliveryTypeCondition = "";
if ($delivery_type === 'project') {
    $deliveryTypeCondition = " AND (d.status_of_delivery = 'In Transit to Project' OR d.status_of_delivery = 'Delivered to Project')";
} elseif ($delivery_type === 'warehouse') {
    $deliveryTypeCondition = " AND d.warehouse_id IS NOT NULL";
}
// For 'all', no additional condition needed

$sql = "
    SELECT d.*, p.project_name AS project_name_from_join,
           IFNULL(d.freight_cost, ?) AS freight_cost_with_default,
           CASE 
               WHEN COALESCE(
                   GROUP_CONCAT(DISTINCT m.vendor_name ORDER BY m.vendor_name SEPARATOR ', '),
                   d.supplier
               ) LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(COALESCE(
                   GROUP_CONCAT(DISTINCT m.vendor_name ORDER BY m.vendor_name SEPARATOR ', '),
                   d.supplier
               ), '-', 1))
               ELSE COALESCE(
                   GROUP_CONCAT(DISTINCT m.vendor_name ORDER BY m.vendor_name SEPARATOR ', '),
                   d.supplier
               )
           END AS manufacturer_name,
           ss.id AS appointment_id
    FROM deliveries d
    LEFT JOIN projects p ON d.project_id = p.id
    LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
    LEFT JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
    LEFT JOIN site_scheduling ss ON d.id = ss.delivery_id
    WHERE 1=1
    $projectConditionSQL
    $dateCondition
    $statusCondition
    $deliveryTypeCondition
    GROUP BY d.id, p.project_name, ss.id
    ORDER BY $filterColumn DESC
";
$stmt = $conn->prepare($sql);
$deliveries_result = null;
if ($stmt) {
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $deliveries_result = $stmt->get_result();
    $stmt->close();
}

/*
  -----------------------------------------------------------------------------
  5) Calculate Total Freight and Accessorial Costs (unfiltered totals)
  -----------------------------------------------------------------------------
*/
$currentProjectConditionForCosts = ""; 
$costParams = [$default_freight_cost];
$costParamTypes = "d";

// --- REVISED: Build cost conditions based on role and filter ---
if (!$is_global_admin && !$account_id_for_admin) {
    // Admin without an account should see no costs
    $currentProjectConditionForCosts = " AND 1=0 "; // Effectively prevents matching any rows
} elseif ($is_global_admin) {
    // Global admin: Filter costs based on the selected project filter
    if (!$is_global_admin && $account_id_for_admin) {
        // Costs for a specific project (global admin doesn't need account check)
        $currentProjectConditionForCosts = " AND d.project_id = ?";
        $costParams[] = $filter_project_id;
        $costParamTypes .= "i";
    } elseif ($filter_project_id === 'unassigned') {
        // Costs for unassigned deliveries
        $currentProjectConditionForCosts = " AND d.project_id IS NULL";
    }
    // If $filter_project_id is 'all', $currentProjectConditionForCosts remains "" (no extra condition needed)
} else {
    // Regular admin with an account: Filter costs based on their account scope
    if (is_numeric($filter_project_id)) {
        // Costs for a specific project, ONLY if it belongs to their account
        $currentProjectConditionForCosts = " AND d.project_id = ? AND d.project_id IN (SELECT id FROM projects WHERE account_id = ?)";
        $costParams[] = $filter_project_id;
        // For cost calculation, filter deliveries that belong to projects in the admin's account
        $costParams[] = $account_id_for_admin;
        $costParamTypes .= "ii"; // one for project_id, one for account_id in subquery
    } elseif ($filter_project_id === 'unassigned') {
        // Costs for unassigned deliveries (admin can see these)
        $currentProjectConditionForCosts = " AND d.project_id IS NULL";
    } else {
        // 'all' filter (or any other unexpected state) for admin: sum costs for ALL projects in their account
        $currentProjectConditionForCosts = " AND d.project_id IN (SELECT id FROM projects WHERE account_id = ?)";
        $costParams[] = $account_id_for_admin;
        $costParamTypes .= "i";
    }
}
// --- END REVISED ---

$sql_costs = "
    SELECT 
        SUM(IF(d.status_of_delivery IN ('Delivered to Project', 'Delivered to Warehouse'), IFNULL(d.freight_cost, ?), 0)) AS total_freight_cost,
        SUM(IF(d.status_of_delivery IN ('Delivered to Project', 'Delivered to Warehouse'), IFNULL(d.accessorial_costs, 0), 0)) AS total_accessorial_costs,
        SUM(IF(d.status_of_delivery IN ('Delivered to Project', 'Delivered to Warehouse'), IFNULL(d.customer_cost, 0), 0)) AS total_customer_cost
    FROM deliveries d
    WHERE 1=1 
    {$currentProjectConditionForCosts}  -- Use the correctly built condition for costs
";

$stmt = $conn->prepare($sql_costs);
if ($stmt) {
    $stmt->bind_param($costParamTypes, ...$costParams);
    $stmt->execute();
    $stmt->bind_result($total_freight_cost, $total_accessorial_costs, $total_customer_cost);
    $stmt->fetch();
    $stmt->close();
}

/*
  -----------------------------------------------------------------------------
  6) Get Delivery Counts for Tabs
  -----------------------------------------------------------------------------
*/
$delivery_counts = ['project' => 0, 'warehouse' => 0, 'all' => 0];

// Build count query parameters (no freight cost parameter needed)
$count_params = [];
$count_types = "";

// Add project condition parameters
if (is_numeric($filter_project_id)) {
    $count_types .= "i";
    $count_params[] = $filter_project_id;
    if (!$is_global_admin && $account_id_for_admin) {
        $count_types .= "i";
        $count_params[] = $account_id_for_admin;
    }
} elseif ($filter_project_id === 'all' && !$is_global_admin && $account_id_for_admin) {
    $count_types .= "i";
    $count_params[] = $account_id_for_admin;
}

// Add date condition params if needed
if ($time_filter === 'day') {
    $count_types .= "s";
    $count_params[] = $ref_date;
} elseif ($time_filter === 'week') {
    $count_types .= "ss";
    $count_params[] = $startOfWeek;
    $count_params[] = $endOfWeek;
} elseif ($time_filter === 'month') {
    $count_types .= "ss";
    $count_params[] = $startOfMonth;
    $count_params[] = $endOfMonth;
}

// Add status condition param if needed
if (!empty($status_filter)) {
    $count_types .= "s";
    $count_params[] = $status_filter;
}

$count_sql = "
    SELECT 
        COUNT(DISTINCT CASE WHEN (d.status_of_delivery = 'In Transit to Project' OR d.status_of_delivery = 'Delivered to Project') THEN d.bol_number ELSE NULL END) as project_count,
        COUNT(DISTINCT CASE WHEN d.warehouse_id IS NOT NULL THEN d.bol_number ELSE NULL END) as warehouse_count,
        COUNT(DISTINCT d.bol_number) as total_count
    FROM deliveries d
    LEFT JOIN projects p ON d.project_id = p.id
    WHERE 1=1
    $projectConditionSQL
    $dateCondition
    $statusCondition
";

$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $count_stmt->bind_result($project_count, $warehouse_count, $total_count);
    $count_stmt->fetch();
    $count_stmt->close();
}

$delivery_counts['project'] = $project_count ?: 0;
$delivery_counts['warehouse'] = $warehouse_count ?: 0;
$delivery_counts['all'] = $total_count ?: 0;

/*
  -----------------------------------------------------------------------------
  7) Handle CSV Export
  -----------------------------------------------------------------------------
*/
if (isset($_GET['export_csv']) && $_GET['export_csv'] === '1') {
    // Build the same query as the main display but for export
    $export_params = [$default_freight_cost];
    $export_param_types = "d";
    
    // Apply same filtering logic as main query
    $export_project_condition = "";
    if (is_numeric($filter_project_id)) {
        $export_project_condition = " AND d.project_id = ?";
        $export_param_types .= "i";
        $export_params[] = $filter_project_id;
        if (!$is_global_admin && $account_id_for_admin) {
            $export_project_condition .= " AND p.account_id = ?";
            $export_param_types .= "i";
            $export_params[] = $account_id_for_admin;
        }
    } elseif ($filter_project_id === 'unassigned') {
        $export_project_condition = " AND d.project_id IS NULL";
    } elseif ($filter_project_id === 'all' && $is_global_admin) {
        $export_project_condition = "";
    } else {
        if (!$is_global_admin && $account_id_for_admin) {
            $export_project_condition = " AND p.account_id = ?";
            $export_param_types .= "i";
            $export_params[] = $account_id_for_admin;
        } else {
            $export_project_condition = " AND 1=0";
        }
    }
    
    // Add date conditions
    $export_date_condition = "";
    if ($time_filter === 'day') {
        $export_date_condition .= " AND DATE($filterColumn) = ?";
        $export_param_types .= "s";
        $export_params[] = $ref_date;
    } elseif ($time_filter === 'week') {
        $timestamp   = strtotime($ref_date);
        $dayOfWeek   = date('w', $timestamp);
        $startOfWeek = date('Y-m-d', strtotime("-{$dayOfWeek} days", $timestamp));
        $endOfWeek   = date('Y-m-d', strtotime("+" . (6 - $dayOfWeek) . " days", $timestamp));
        $export_date_condition .= " AND DATE($filterColumn) BETWEEN ? AND ?";
        $export_param_types .= "ss";
        $export_params[] = $startOfWeek;
        $export_params[] = $endOfWeek;
    } elseif ($time_filter === 'month') {
        $startOfMonth = date('Y-m-01', strtotime($ref_date));
        $endOfMonth   = date('Y-m-t', strtotime($ref_date));
        $export_date_condition .= " AND DATE($filterColumn) BETWEEN ? AND ?";
        $export_param_types .= "ss";
        $export_params[] = $startOfMonth;
        $export_params[] = $endOfMonth;
    }
    
    // Add status condition
    $export_status_condition = "";
    if (!empty($status_filter)) {
        $export_status_condition = " AND status_of_delivery = ?";
        $export_param_types .= "s";
        $export_params[] = $status_filter;
    }
    
    // Add delivery type condition
    $export_delivery_type_condition = "";
    if ($delivery_type === 'project') {
        $export_delivery_type_condition = " AND (d.status_of_delivery = 'In Transit to Project' OR d.status_of_delivery = 'Delivered to Project')";
    } elseif ($delivery_type === 'warehouse') {
        $export_delivery_type_condition = " AND d.warehouse_id IS NOT NULL";
    }
    
    // Export query
    $export_sql = "
        SELECT d.id, 
               COALESCE(p.project_name, 'Unassigned') as project_name,
               CASE 
                   WHEN COALESCE(
                       GROUP_CONCAT(DISTINCT m.vendor_name ORDER BY m.vendor_name SEPARATOR ', '),
                       d.supplier
                   ) LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(COALESCE(
                       GROUP_CONCAT(DISTINCT m.vendor_name ORDER BY m.vendor_name SEPARATOR ', '),
                       d.supplier
                   ), '-', 1))
                   ELSE COALESCE(
                       GROUP_CONCAT(DISTINCT m.vendor_name ORDER BY m.vendor_name SEPARATOR ', '),
                       d.supplier
                   )
               END AS manufacturer_name,
               d.wattage,
               d.status_of_delivery,
               d.quantity,
               d.bol_number,
               d.anticipated_delivery_date,
               d.warehouse_arrival_date,
               d.actual_delivery_date,
               d.miles,
               IFNULL(d.freight_cost, ?) AS freight_cost_with_default,
               d.accessorial_costs,
               d.customer_cost,
               d.created_at,
               ss.id AS appointment_id
        FROM deliveries d
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
        LEFT JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN site_scheduling ss ON d.id = ss.delivery_id
        WHERE 1=1
        $export_project_condition
        $export_date_condition
        $export_status_condition
        $export_delivery_type_condition
        GROUP BY d.id, p.project_name, ss.id
        ORDER BY $filterColumn DESC
    ";
    
    $export_stmt = $conn->prepare($export_sql);
    if ($export_stmt) {
        $export_stmt->bind_param($export_param_types, ...$export_params);
        $export_stmt->execute();
        $export_result = $export_stmt->get_result();
        
        // Generate filename
        $filename = 'deliveries_export_' . date('Y-m-d_H-i-s') . '.csv';
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate');
        
        // Create file pointer
        $output = fopen('php://output', 'w');
        
        // Add CSV headers
        $csv_headers = [
            'Delivery ID',
            'Project Name',
            'Manufacturer',
            'Wattage',
            'Status',
            'Quantity',
            'BOL Number',
            'Anticipated Delivery Date',
            'Warehouse Arrival Date',
            'Actual Delivery Date',
            'Miles',
            'Freight Cost',
            'Accessorial Costs',
            'Customer Cost',
            'Created At',
            'Appointment ID'
        ];
        fputcsv($output, $csv_headers);
        
        // Add data rows
        while ($row = $export_result->fetch_assoc()) {
            $csv_row = [
                $row['id'],
                $row['project_name'],
                $row['manufacturer_name'],
                $row['wattage'],
                $row['status_of_delivery'],
                $row['quantity'],
                $row['bol_number'],
                $row['anticipated_delivery_date'],
                $row['warehouse_arrival_date'],
                $row['actual_delivery_date'],
                $row['miles'],
                number_format($row['freight_cost_with_default'], 2),
                number_format($row['accessorial_costs'], 2),
                number_format($row['customer_cost'], 2),
                $row['created_at'],
                $row['appointment_id']
            ];
            fputcsv($output, $csv_row);
        }
        
        fclose($output);
        $export_stmt->close();
    }
    if ($conn && !$conn->connect_errno) {
        $conn->close();
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project_name); ?> - Manage Deliveries</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Modern Design System */
        :root {
            --primary-color: #488C9A;
            --primary-dark: #3A6E7F;
            --secondary-color: #f8f9fa;
            --accent-color: #28a745;
            --danger-color: #dc3545;
            --text-primary: #293E4C;
            --text-secondary: #6c757d;
            --border-radius: 12px;
            --box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .error-messages {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            border: none;
            padding: 20px;
            margin-bottom: 24px;
            color: #721c24;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        /* Clean Stats Container */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin: 32px 0;
            padding: 0;
        }

        .stats-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border: none;
            border-radius: var(--border-radius);
            padding: 32px;
            text-align: center;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .stats-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(72, 140, 154, 0.15);
        }

        .stats-card h2 {
            color: var(--text-primary);
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 16px;
            letter-spacing: 0.5px;
        }
        /* Modern Bulk Actions */
        .bulk-actions-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: var(--border-radius);
            padding: 24px;
            margin: 24px 0;
            box-shadow: var(--box-shadow);
            border-top: 4px solid var(--primary-color);
        }

        .bulk-actions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .bulk-actions-header h2 {
            color: var(--text-primary);
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }

        .selected-count {
            background: var(--primary-color);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .bulk-actions-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .bulk-action-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }

        .bulk-action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .bulk-action-btn.edit {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .bulk-action-btn.delete {
            background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
            color: white;
        }

        .bulk-action-btn.export {
            background: linear-gradient(135deg, var(--accent-color) 0%, #218838 100%);
            color: white;
        }

        .bulk-action-btn:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        /* Modern Filter Header */
        .time-filter-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: var(--border-radius);
            padding: 24px;
            margin: 24px 0;
            box-shadow: var(--box-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .time-filters {
            display: flex;
            gap: 4px;
            background: var(--secondary-color);
            padding: 4px;
            border-radius: 10px;
        }

        .time-filters a {
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            transition: var(--transition);
            position: relative;
        }

        .time-filters a.active {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }

        .time-filters a:not(.active):hover {
            background: rgba(72, 140, 154, 0.1);
            color: var(--primary-color);
        }
        .date-navigation {
            display: flex;
            align-items: center;
            gap: 16px;
            background: white;
            padding: 12px 16px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .nav-arrow {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 50%;
            background: var(--secondary-color);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: var(--transition);
        }

        .nav-arrow:hover {
            background: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }

        .date-label {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--text-primary);
            min-width: 160px;
            text-align: center;
        }

        .right-filters {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        /* Modern Table Styling */
        .table-responsive {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            margin: 24px 0;
        }

        .deliveries-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .deliveries-table th {
            background: linear-gradient(135deg, var(--text-primary) 0%, #1f2937 100%);
            color: white;
            padding: 16px 12px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
            position: relative;
        }

        .deliveries-table td {
            padding: 16px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-primary);
            font-size: 0.9rem;
            vertical-align: middle;
        }

        .deliveries-table tbody tr {
            transition: var(--transition);
        }

        .deliveries-table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        /* Expandable Row Styling */
        .grouped-delivery-row {
            cursor: pointer;
            position: relative;
        }

        .grouped-delivery-row::after {
            content: '▼';
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-color);
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .grouped-delivery-row.expanded::after {
            transform: translateY(-50%) rotate(180deg);
        }

        .delivery-detail-row {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            display: none;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .delivery-detail-row.show {
            display: table-row;
            opacity: 1;
            transform: translateY(0);
        }

        .delivery-detail-row td {
            padding: 12px 24px;
            font-size: 0.85rem;
            border-left: 4px solid var(--primary-color);
            position: relative;
        }

        .delivery-detail-row td:first-child::before {
            content: '';
            position: absolute;
            left: -4px;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
        }

        /* Mixed Wattage Styling */
        .mixed-wattage {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }
        @media screen and (max-width: 768px) {
            .mobile-hide {
                display: none !important;
            }
        }
        /* Highlighted delivery row for specific delivery_id links */
        .highlighted-delivery {
            background-color: #fffacd !important;
            border: 2px solid #488C9A !important;
            animation: highlight-pulse 2s ease-in-out;
        }
        @keyframes highlight-pulse {
            0% { background-color: #e8f4f8; }
            50% { background-color: #fffacd; }
            100% { background-color: #fffacd; }
        }
        /* Modal styling for Bulk Edit */
        #bulkEditModal {
            display: none; /* Hidden by default */
            position: fixed; 
            z-index: 9999; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0, 0, 0, 0.5);
        }
        #bulkEditModalContent {
            background-color: #fff;
            margin: 5% auto;
            padding: 20px;
            width: 600px; /* Increased width to prevent overlap */
            max-width: 90%;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            position: relative;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .modal-header h2 {
            margin: 0;
            color: #293E4C;
        }
        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 24px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
            border: none;
            background: transparent;
        }
        .close-modal:hover {
            color: #488C9A;
        }
        .modal-field {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
        }
        .modal-field label {
            display: inline-block;
            width: 150px;
            font-weight: 500;
            color: #555;
        }
        .modal-field input {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .modal-field input:focus {
            border-color: #488C9A;
            outline: none;
            box-shadow: 0 0 3px rgba(72, 140, 154, 0.3);
        }
        .modal-sections {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }
        .modal-section {
            flex: 1;
            min-width: 200px; /* Increased minimum width */
        }
        .section-header {
            font-weight: 600;
            margin: 10px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
            color: #555;
        }
        .modal-cost-section {
            width: 100%;
            margin-top: 10px;
        }
        .modal-cost-fields {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .cost-row {
            display: flex;
            gap: 15px;
        }
        .cost-field-full {
            width: 100%;
        }
        .cost-field-half {
            flex: 1;
            min-width: 160px;
        }
        .modal-buttons {
            text-align: center; /* Changed to center */
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .modal-buttons button {
            background-color: #488C9A;
            color: white;
            border: none;
            padding: 8px 20px; /* Slightly wider button */
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
        }
        .modal-buttons button:hover {
            background-color: #3A6E7F;
        }
        /* Modal styling for Miles & Costs section */
        .modal-cost-section {
            width: 100%;
            margin-top: 10px;
        }
        .modal-cost-fields {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .cost-row {
            display: flex;
            gap: 15px;
        }
        /* Miles row styling */
        .miles-row {
            display: flex;
            justify-content: center;
        }
        .miles-field {
            width: 60%;
        }
        /* Costs row styling */
        .costs-row {
            display: flex;
            gap: 15px;
        }
        .cost-field-half {
            flex: 1;
            min-width: 160px;
        }
        /* Media query for mobile view */
        @media screen and (max-width: 600px) {
            .costs-row {
                flex-direction: column;
                gap: 12px;
            }
            .cost-field-half {
                width: 100%;
            }
            .miles-field {
                width: 100%;
            }
        }
        /* Associated Pallets Modal Styling */
        #associatedPalletsModal {
             display: none;
             /* Reuse general modal styles */
        }
         #associatedPalletsModal .modal-content {
             max-width: 500px; /* Smaller modal */
             position: relative; /* Added for absolute positioning of child */
         }
        /* UPDATED: Changed from #associatedPalletsModal ul to #palletList for the div container */
        #palletList {
             /* list-style: none; */ /* No longer a ul */
             padding: 0;
             max-height: 300px; /* Or your desired max height */
             overflow-y: auto;  /* Add scroll for vertical overflow */
             border: 1px solid #eee; /* Optional: to see the scroll container bounds */
        }
        /* Styles for the table within #palletList, if not already covered by inline styles */
        #palletList table {
            /* Add any specific table styling here if needed, e.g., for borders within the scrollable area */
        }
        
        /* Modern Button Styling */
        .modern-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .modern-btn.primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }

        .modern-btn.secondary {
            background: var(--secondary-color);
            color: var(--text-primary);
            border: 2px solid #e9ecef;
        }

        .modern-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        /* Filters Dropdown Styling */
        .filters-dropdown-container {
            position: relative;
            display: inline-block;
        }
        
        .filters-dropdown-btn {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        
        .filters-dropdown-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(72, 140, 154, 0.4);
        }
        
        .filters-dropdown-content {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            min-width: 300px;
            max-width: 350px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .filters-dropdown-header {
            padding: 12px 16px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #ddd;
            font-weight: 600;
            color: #293E4C;
        }
        
        .filter-item {
            padding: 10px 16px;
            border-bottom: 1px solid #eee;
        }
        
        .filter-item:last-child {
            border-bottom: none;
        }
        
        .filter-item label {
            display: block;
            font-weight: 500;
            color: #555;
            margin-bottom: 5px;
        }
        
        .filter-item input, .filter-item select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 0.9em;
        }
        
        .filter-item input:focus, .filter-item select:focus {
            border-color: #488C9A;
            outline: none;
            box-shadow: 0 0 3px rgba(72, 140, 154, 0.3);
        }

        /* Column Chooser Styling */
        .column-chooser-container {
            position: relative;
            display: inline-block;
        }
        
        .column-chooser-btn {
            background-color: #488C9A;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background-color 0.3s ease;
        }
        
        .column-chooser-btn:hover {
            background-color: #3A6E7F;
        }
        
        .column-chooser-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            min-width: 250px;
            max-width: 300px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .column-chooser-header {
            padding: 12px 16px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #ddd;
            font-weight: 600;
            color: #293E4C;
        }
        
        .column-chooser-options {
            padding: 8px 0;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .column-option {
            display: flex;
            align-items: center;
            padding: 6px 16px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .column-option:hover {
            background-color: #f8f9fa;
        }
        
        .column-option input[type="checkbox"] {
            margin-right: 8px;
            cursor: pointer;
        }
        
        .column-chooser-footer {
            padding: 8px 16px;
            border-top: 1px solid #ddd;
            background-color: #f8f9fa;
        }
        
        .reset-columns-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8em;
            transition: background-color 0.3s ease;
        }
        
        .reset-columns-btn:hover {
            background-color: #5a6268;
        }
        
        /* Hidden column styling */
        .column-hidden {
            display: none !important;
        }

    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Add breadcrumb navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <?php 
        // Check if we're viewing deliveries for a specific project (coming from project overview)
        if (is_numeric($filter_project_id) && $filter_project_id > 0): 
        ?>
            <a href="project_overview.php?project_id=<?php echo $filter_project_id; ?>" style="color: #488C9A; text-decoration: none;"><?php echo htmlspecialchars($project_name); ?></a>
            <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <?php endif; ?>
        <span>Manage Deliveries</span>
    </div>

    <h1>Manage Deliveries: <?php echo htmlspecialchars($project_name); ?></h1>

    <!-- Delivery Type Tabs -->
    <div class="delivery-type-tabs" style="margin: 24px 0;">
        <div style="display: flex; gap: 4px; background: var(--secondary-color); padding: 6px; border-radius: var(--border-radius); box-shadow: var(--box-shadow);">
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=<?php echo urlencode($time_filter); ?>&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&delivery_type=all" 
               class="delivery-tab modern-btn <?php echo ($delivery_type === 'all') ? 'primary' : 'secondary'; ?>"
               style="flex: 1; text-align: center; font-weight: 500; transition: var(--transition); <?php echo ($delivery_type === 'all') ? 'background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%); color: white; box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);' : ''; ?>">
                📦 All Deliveries (<?php echo $delivery_counts['all']; ?>)
            </a>
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=<?php echo urlencode($time_filter); ?>&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&delivery_type=project" 
               class="delivery-tab modern-btn <?php echo ($delivery_type === 'project') ? 'primary' : 'secondary'; ?>"
               style="flex: 1; text-align: center; font-weight: 500; transition: var(--transition); <?php echo ($delivery_type === 'project') ? 'background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%); color: white; box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);' : ''; ?>">
                🏗️ Project Deliveries (<?php echo $delivery_counts['project']; ?>)
            </a>
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=<?php echo urlencode($time_filter); ?>&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&delivery_type=warehouse" 
               class="delivery-tab modern-btn <?php echo ($delivery_type === 'warehouse') ? 'primary' : 'secondary'; ?>"
               style="flex: 1; text-align: center; font-weight: 500; transition: var(--transition); <?php echo ($delivery_type === 'warehouse') ? 'background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%); color: white; box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);' : ''; ?>">
                🏢 Warehouse Deliveries (<?php echo $delivery_counts['warehouse']; ?>)
            </a>
        </div>
    </div>

    <!-- Display Messages -->
    <?php
    if (isset($_SESSION['messages']) && !empty($_SESSION['messages'])) {
        foreach ($_SESSION['messages'] as $message) {
            echo $message;
        }
        $_SESSION['messages'] = [];
    }
    ?>

    <!-- Stats Overview -->
    <div class="stats-container">
        <div class="stats-card">
            <h2>💰 Customer Cost</h2>
            <div style="font-size: 2.2rem; font-weight: 700; color: var(--primary-color); margin: 16px 0;">
                $<?php echo number_format($total_customer_cost, 2); ?>
            </div>
            <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">Total charged to customers</p>
        </div>
        
        <div class="stats-card">
            <h2>🚛 Carrier Cost</h2>
            <div style="font-size: 2.2rem; font-weight: 700; color: var(--primary-color); margin: 16px 0;">
                $<?php echo number_format($total_freight_cost, 2); ?>
            </div>
            <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">Total paid to carriers</p>
        </div>
        
        <div class="stats-card">
            <h2>📋 Accessorial Costs</h2>
            <div style="font-size: 2.2rem; font-weight: 700; color: var(--primary-color); margin: 16px 0;">
                $<?php echo number_format($total_accessorial_costs, 2); ?>
            </div>
            <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">Additional service charges</p>
        </div>
    </div>

    <!-- Bulk Actions -->
    <div class="bulk-actions-container">
        <div class="bulk-actions-header">
            <h2>🔧 Bulk Actions</h2>
            <span id="selectedRowCount" class="selected-count" style="display: none;"></span>
        </div>
        <div class="bulk-actions-buttons">
            <button type="button" id="bulkEditBtn" class="bulk-action-btn edit" disabled onclick="openBulkEditModal()">
                ✏️ Bulk Edit
            </button>
            <button type="submit" form="deliveriesForm" name="delete_selected" id="bulkDeleteBtn" class="bulk-action-btn delete" disabled
                onclick="return confirm('Are you sure you want to delete the selected deliveries?');">
                🗑️ Bulk Delete
            </button>
            <button type="button" id="exportCsvBtn" class="bulk-action-btn export" onclick="exportToCSV()">
                📥 Export CSV
            </button>
        </div>
    </div>

    <!-- Time Filter Header -->
    <div class="time-filter-header">
        <!-- Left: Time Filters -->
        <div class="time-filters">
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=all&delivery_type=<?php echo urlencode($delivery_type); ?>&status_filter=<?php echo urlencode($status_filter); ?>"
               class="<?php echo ($time_filter === 'all') ? 'active' : ''; ?>">All</a>
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=day&ref_date=<?php echo $ref_date; ?>&delivery_type=<?php echo urlencode($delivery_type); ?>&status_filter=<?php echo urlencode($status_filter); ?>"
               class="<?php echo ($time_filter === 'day') ? 'active' : ''; ?>">Day</a>
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=week&ref_date=<?php echo $ref_date; ?>&delivery_type=<?php echo urlencode($delivery_type); ?>&status_filter=<?php echo urlencode($status_filter); ?>"
               class="<?php echo ($time_filter === 'week') ? 'active' : ''; ?>">Week</a>
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=month&ref_date=<?php echo $ref_date; ?>&delivery_type=<?php echo urlencode($delivery_type); ?>&status_filter=<?php echo urlencode($status_filter); ?>"
               class="<?php echo ($time_filter === 'month') ? 'active' : ''; ?>">Month</a>
        </div>
        <!-- Center: Date Navigation -->
        <div class="date-navigation">
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&delivery_type=<?php echo urlencode($delivery_type); ?>'">
                    &larr;
                </button>
            <?php endif; ?>
            <span class="date-label"><?php echo $dateLabel; ?></span>
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&delivery_type=<?php echo urlencode($delivery_type); ?>'">
                    &rarr;
                </button>
            <?php endif; ?>
        </div>
        <!-- Right: Filters Dropdown and Column Chooser -->
        <div class="right-filters">
            <div style="display: flex; gap: 10px; align-items: center;">
                <!-- Filters Dropdown -->
                <div class="filters-dropdown-container">
                    <button type="button" id="filtersDropdownBtn" class="filters-dropdown-btn" onclick="toggleFiltersDropdown()">
                        🔽 Filters
                    </button>
                    <div id="filtersDropdown" class="filters-dropdown-content" style="display: none;">
                        <div class="filters-dropdown-header">Filter Options:</div>
                        
                        <!-- Search Input -->
                        <div class="filter-item">
                            <label for="searchInput">Search in Table:</label>
                            <input type="text" id="searchInput" placeholder="Type to filter..." onkeyup="searchTable()">
                        </div>
                        
                        <!-- Filter Form -->
                        <form method="get" action="" id="filterForm">
                            <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter ?? ''); ?>">
                            <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date ?? ''); ?>">
                            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter ?? ''); ?>">
                            <input type="hidden" name="delivery_type" value="<?php echo htmlspecialchars($delivery_type ?? ''); ?>">
                            
                            <div class="filter-item">
                                <label for="filter_project_id">Project:</label>
                                <select name="filter_project_id" id="filter_project_id" onchange="this.form.submit()">
                                    <?php if ($is_global_admin): // Only show "All Projects" for global admin ?>
                                    <option value="all" <?php if($filter_project_id === 'all') echo 'selected'; ?>>All Projects</option>
                                    <?php endif; ?>
                                    <option value="unassigned" <?php if($filter_project_id === 'unassigned') echo 'selected'; ?>>Unassigned Deliveries</option>
                                    <?php foreach ($all_projects_for_filter as $proj_filter_item): ?>
                                        <option value="<?php echo $proj_filter_item['id']; ?>" <?php if (is_numeric($filter_project_id) && $filter_project_id == $proj_filter_item['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($proj_filter_item['project_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-item">
                                <label for="status_filter">Status:</label>
                                <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                                    <option value="">All</option>
                                    <option value="Pending" <?php if($status_filter === 'Pending') echo 'selected'; ?>>Pending</option>
                                    <option value="In Transit to Warehouse" <?php if($status_filter === 'In Transit to Warehouse') echo 'selected'; ?>>In Transit to Warehouse</option>
                                    <option value="Delivered to Warehouse" <?php if($status_filter === 'Delivered to Warehouse') echo 'selected'; ?>>Delivered to Warehouse</option>
                                    <option value="In Transit to Project" <?php if($status_filter === 'In Transit to Project') echo 'selected'; ?>>In Transit to Project</option>
                                    <option value="Delivered to Project" <?php if($status_filter === 'Delivered to Project') echo 'selected'; ?>>Delivered to Project</option>
                                    <option value="Canceled" <?php if($status_filter === 'Canceled') echo 'selected'; ?>>Canceled</option>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Column Chooser -->
                <div class="column-chooser-container">
                    <button type="button" id="columnChooserBtn" class="column-chooser-btn" onclick="toggleColumnChooser()">
                        📋 Choose Columns
                    </button>
                    <div id="columnChooserDropdown" class="column-chooser-dropdown" style="display: none;">
                        <div class="column-chooser-header">Select Columns to Show:</div>
                        <div class="column-chooser-options">
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="select-column" checked>
                                Select
                            </label>
                            <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="project-column" checked>
                                Project
                            </label>
                            <?php endif; ?>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="manufacturer-column" checked>
                                Manufacturer
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="bol-column" checked>
                                BOL Number
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="wattage-column" checked>
                                Wattage
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="quantity-column" checked>
                                Quantity
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="status-column" checked>
                                Status of Delivery
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="anticipated-column" checked>
                                Anticipated Delivery Date
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="warehouse-arrival-column" checked>
                                Warehouse Arrival Date
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="actual-column" checked>
                                Actual Delivery Date
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="pod-column" checked>
                                Proof of Delivery
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="miles-column" checked>
                                Miles
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="freight-column" checked>
                                Freight Cost
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="accessorial-column" checked>
                                Accessorial Costs
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="customer-column" checked>
                                Customer Cost
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="scheduled-column" checked>
                                Scheduled
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="pallets-column" checked>
                                Associated Pallets
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="actions-column" checked>
                                Actions
                            </label>
                        </div>
                        <div class="column-chooser-footer">
                            <button type="button" onclick="resetColumns()" class="reset-columns-btn">Reset to Default</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Deliveries Form + Table -->
    <form action="manage_deliveries?filter_project_id=<?php echo urlencode($filter_project_id); ?>" method="post" id="deliveriesForm">
        <!-- Preserve filter parameters -->
        <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter ?? ''); ?>">
        <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date ?? ''); ?>">
        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter ?? ''); ?>">
        <input type="hidden" name="delivery_type" value="<?php echo htmlspecialchars($delivery_type ?? ''); ?>">

        <div class="table-responsive">

            <table class="deliveries-table" id="deliveriesTable">
                <tr>
                    <th class="select-column"><input type="checkbox" id="select-all"></th>
                    <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                        <th class="project-column">Project</th>
                    <?php endif; ?>
                    <th class="manufacturer-column">Manufacturer</th>
                    <th class="bol-column">BOL Number</th>
                    <th class="wattage-column">Wattage</th>
                    <th class="quantity-column">Quantity</th>
                    <th class="status-column">Status of Delivery</th>
                    <th class="anticipated-column">Anticipated Delivery Date</th>
                    <th class="warehouse-arrival-column">Warehouse Arrival Date</th>
                    <th class="actual-column">Actual Delivery Date</th>
                    <th class="pod-column">Proof of Delivery</th>
                    <th class="miles-column">Miles</th>
                    <th class="freight-column">Freight Cost</th>
                    <th class="accessorial-column">Accessorial Costs</th>
                    <th class="customer-column">Customer Cost</th>
                    <th class="scheduled-column">Scheduled</th>
                    <th class="pallets-column">Associated Pallets</th>
                    <th class="actions-column">Actions</th>
                </tr>
                <?php if ($deliveries_result && $deliveries_result->num_rows > 0): ?>
                    <?php 
                    // Group deliveries by BOL number and prepare grouped data
                    $all_deliveries = [];
                    $grouped_deliveries = [];
                    
                    // Fetch all deliveries into an array
                    while($delivery = $deliveries_result->fetch_assoc()) {
                        $all_deliveries[] = $delivery;
                    }
                    
                    // Group by BOL number
                    $bol_groups = [];
                    foreach ($all_deliveries as $delivery) {
                        $bol = $delivery['bol_number'];
                        if (!isset($bol_groups[$bol])) {
                            $bol_groups[$bol] = [];
                        }
                        $bol_groups[$bol][] = $delivery;
                    }
                    
                    // Create grouped delivery data with mixed wattage detection
                    foreach ($bol_groups as $bol => $deliveries) {
                        if (count($deliveries) > 1) {
                            // Check if wattages are different (mixed shipment)
                            $wattages = array_unique(array_column($deliveries, 'wattage'));
                            $is_mixed = count($wattages) > 1;
                            
                            // Create a master delivery record
                            $master_delivery = $deliveries[0]; // Use first delivery as base
                            $master_delivery['is_grouped'] = true;
                            $master_delivery['is_mixed_wattage'] = $is_mixed;
                            $master_delivery['grouped_deliveries'] = $deliveries;
                            
                            // Calculate totals
                            $total_quantity = array_sum(array_column($deliveries, 'quantity'));
                            $total_freight = array_sum(array_column($deliveries, 'freight_cost_with_default'));
                            $total_accessorial = array_sum(array_column($deliveries, 'accessorial_costs'));
                            $total_customer = array_sum(array_column($deliveries, 'customer_cost'));
                            $total_miles = array_sum(array_column($deliveries, 'miles'));
                            
                            $master_delivery['total_quantity'] = $total_quantity;
                            $master_delivery['total_freight_cost'] = $total_freight;
                            $master_delivery['total_accessorial_costs'] = $total_accessorial;
                            $master_delivery['total_customer_cost'] = $total_customer;
                            $master_delivery['total_miles'] = $total_miles;
                            
                            if ($is_mixed) {
                                $master_delivery['display_wattage'] = 'Mixed';
                            }
                            
                            $grouped_deliveries[] = $master_delivery;
                        } else {
                            // Single delivery, add normally
                            $deliveries[0]['is_grouped'] = false;
                            $deliveries[0]['is_mixed_wattage'] = false;
                            $grouped_deliveries[] = $deliveries[0];
                        }
                    }
                    
                    // Create a fresh connection for pallet fetching to avoid connection issues
                    $palletConn = getDBConnection();
                    $stmtPallets = null;
                    if ($palletConn && !$palletConn->connect_errno) {
                        $stmtPallets = $palletConn->prepare("SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity FROM delivery_pallets dp JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id WHERE dp.delivery_id = ? ORDER BY ip.id");
                        if (!$stmtPallets) {
                            // Handle error appropriately - maybe log it or display a generic error
                            echo "Error preparing pallet statement: " . $palletConn->error;
                            // Optionally exit or skip the loop
                        }
                    } else {
                        echo "Database connection is not available for pallet fetching.";
                    }
                    ?>
                    <?php foreach($grouped_deliveries as $index => $delivery): ?>
                        <?php
                        // Fetch associated pallets for this delivery (or grouped deliveries)
                        $associatedPallets = [];
                        $palletDataJson = '[]'; // Default to empty JSON array
                        $palletCount = 0;
                        
                        if ($stmtPallets) { // Check if statement was prepared successfully
                            if ($delivery['is_grouped']) {
                                // For grouped deliveries, collect pallets from all deliveries in the group
                                foreach ($delivery['grouped_deliveries'] as $grouped_del) {
                                    $stmtPallets->bind_param("i", $grouped_del['id']);
                                    $stmtPallets->execute();
                                    $palletsResult = $stmtPallets->get_result();
                                    while ($palletRow = $palletsResult->fetch_assoc()) {
                                        $associatedPallets[] = $palletRow;
                                    }
                                }
                            } else {
                                // For single deliveries, get pallets normally
                                $stmtPallets->bind_param("i", $delivery['id']);
                                $stmtPallets->execute();
                                $palletsResult = $stmtPallets->get_result();
                                while ($palletRow = $palletsResult->fetch_assoc()) {
                                    $associatedPallets[] = $palletRow;
                                }
                            }
                            $palletCount = count($associatedPallets);
                            if ($palletCount > 0) {
                                $palletDataJson = htmlspecialchars(json_encode($associatedPallets), ENT_QUOTES, 'UTF-8');
                            }
                        } else {
                            // Handle case where statement couldn't be prepared
                            $associatedPallets = 'Error fetching pallets'; // Or some other indicator
                        }
                        
                        $row_class = '';
                        if ($highlight_delivery_id && $delivery['id'] == $highlight_delivery_id) {
                            $row_class .= ' highlighted-delivery';
                        }
                        if ($delivery['is_grouped'] && $delivery['is_mixed_wattage']) {
                            $row_class .= ' grouped-delivery-row';
                        }
                        ?>
                        
                        <!-- Main delivery row -->
                        <tr class="<?php echo trim($row_class); ?>" <?php if ($delivery['is_grouped'] && $delivery['is_mixed_wattage']): ?>onclick="toggleDeliveryDetails(<?php echo $index; ?>)"<?php endif; ?> data-delivery-index="<?php echo $index; ?>">
                            <td class="select-column">
                                <?php if ($delivery['is_grouped']): ?>
                                    <!-- For grouped deliveries, we need multiple checkboxes for bulk operations -->
                                    <?php foreach ($delivery['grouped_deliveries'] as $grouped_del): ?>
                                        <input type="checkbox" name="selected_deliveries[]" value="<?php echo $grouped_del['id']; ?>" onclick="updateBulkActionButtons()" style="display: none;" class="grouped-checkbox-<?php echo $index; ?>">
                                    <?php endforeach; ?>
                                    <input type="checkbox" onclick="toggleGroupedCheckboxes(<?php echo $index; ?>); updateBulkActionButtons();">
                                <?php else: ?>
                                    <input type="checkbox" name="selected_deliveries[]" value="<?php echo $delivery['id']; ?>" onclick="updateBulkActionButtons()">
                                <?php endif; ?>
                            </td>
                            <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                                <td class="project-column">
                                    <?php 
                                    if (!empty($delivery['project_id'])) {
                                        echo htmlspecialchars($delivery['project_name_from_join'] ?? 'N/A');
                                    } else {
                                        echo "<em>Unassigned</em>";
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td class="manufacturer-column">
                                <?php echo htmlspecialchars($delivery['manufacturer_name']); ?>
                            </td>
                            <td class="bol-column"><?php echo htmlspecialchars($delivery['bol_number']); ?></td>
                            <td class="wattage-column">
                                <?php if ($delivery['is_mixed_wattage']): ?>
                                    <span class="mixed-wattage">Mixed</span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($delivery['wattage']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="quantity-column">
                                <?php if ($delivery['is_grouped']): ?>
                                    <?php echo htmlspecialchars($delivery['total_quantity']); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($delivery['quantity']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="status-column"><?php echo htmlspecialchars($delivery['status_of_delivery']); ?></td>
                            <td class="anticipated-column"><?php echo htmlspecialchars($delivery['anticipated_delivery_date']); ?></td>
                            <td class="warehouse-arrival-column"><?php echo htmlspecialchars($delivery['warehouse_arrival_date']); ?></td>
                            <td class="actual-column"><?php echo htmlspecialchars($delivery['actual_delivery_date']); ?></td>
                            <td class="pod-column">
                                <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                    <a href="view_pod?delivery_id=<?php echo $delivery['id']; ?>" target="_blank">View POD</a>
                                <?php else: ?>
                                    <?php if ($_SESSION['role'] == 'global_admin'): ?>
                                        <a href="upload_pod?delivery_id=<?php echo $delivery['id']; ?>">Upload POD</a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="miles-column">
                                <?php if ($delivery['is_grouped']): ?>
                                    <?php echo htmlspecialchars($delivery['total_miles']); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($delivery['miles']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="freight-column">
                                <?php if ($delivery['is_grouped']): ?>
                                    $<?php echo number_format($delivery['total_freight_cost'], 2); ?>
                                <?php else: ?>
                                    $<?php echo number_format($delivery['freight_cost_with_default'], 2); ?>
                                <?php endif; ?>
                            </td>
                            <td class="accessorial-column">
                                <?php if ($delivery['is_grouped']): ?>
                                    $<?php echo number_format($delivery['total_accessorial_costs'], 2); ?>
                                <?php else: ?>
                                    $<?php echo number_format($delivery['accessorial_costs'], 2); ?>
                                <?php endif; ?>
                            </td>
                            <td class="customer-column">
                                <?php if ($delivery['is_grouped']): ?>
                                    $<?php echo number_format($delivery['total_customer_cost'], 2); ?>
                                <?php else: ?>
                                    $<?php echo number_format($delivery['customer_cost'], 2); ?>
                                <?php endif; ?>
                            </td>
                            <td class="scheduled-column">
                                <?php if ($delivery['scheduled'] == 1): ?>
                                    <?php if (!empty($delivery['project_id']) && !empty($delivery['appointment_id'])): ?>
                                        <a href="scheduling.php?project_id=<?php echo $delivery['project_id']; ?>&delivery_id=<?php echo $delivery['id']; ?>&appointment_id=<?php echo $delivery['appointment_id']; ?>&auto_edit=1" 
                                           style="color: #488C9A; text-decoration: underline;">View Appointment</a>
                                    <?php else: ?>
                                        <span style="color: #28a745;">Scheduled</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (!empty($delivery['project_id']) && $delivery['status_of_delivery'] === 'In Transit to Project'): ?>
                                        <a href="scheduling.php?project_id=<?php echo $delivery['project_id']; ?>&delivery_id=<?php echo $delivery['id']; ?>" 
                                           style="color: #fbb040; text-decoration: underline;">Schedule Delivery</a>
                                    <?php else: ?>
                                        <span style="color: #666;">N/A</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="pallets-column">
                                <?php if ($stmtPallets && $palletCount > 0): ?>
                                    <button type="button" class="modern-btn secondary" 
                                            onclick="showPalletModal(this)" 
                                            data-pallets='<?php echo $palletDataJson; ?>'
                                            >📦 Pallets (<?php echo $palletCount; ?>)</button>
                                <?php elseif ($stmtPallets): ?>
                                    N/A
                                <?php else: ?>
                                    Error
                                <?php endif; ?>
                            </td>
                            <td class="actions-column">
                                <?php if ($delivery['is_grouped']): ?>
                                    <span style="color: #666;">Grouped</span>
                                <?php else: ?>
                                    <a href="edit_delivery?delivery_id=<?php echo $delivery['id']; ?><?php echo (is_numeric($filter_project_id)) ? '&project_id=' . $filter_project_id : ((!empty($delivery['project_id'])) ? '&project_id=' . $delivery['project_id'] : ''); ?>" class="modern-btn primary">
                                        ✏️ Edit
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <!-- Detail rows for grouped deliveries -->
                        <?php if ($delivery['is_grouped'] && $delivery['is_mixed_wattage']): ?>
                            <?php foreach ($delivery['grouped_deliveries'] as $detail_delivery): ?>
                                <tr class="delivery-detail-row" id="detail-<?php echo $index; ?>-<?php echo $detail_delivery['id']; ?>">
                                    <td class="select-column"></td>
                                    <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                                        <td class="project-column"></td>
                                    <?php endif; ?>
                                    <td class="manufacturer-column" style="padding-left: 30px;">
                                        ↳ <?php echo htmlspecialchars($detail_delivery['manufacturer_name']); ?>
                                    </td>
                                    <td class="bol-column"></td>
                                    <td class="wattage-column">
                                        <strong><?php echo htmlspecialchars($detail_delivery['wattage']); ?></strong>
                                    </td>
                                    <td class="quantity-column">
                                        <strong><?php echo htmlspecialchars($detail_delivery['quantity']); ?></strong>
                                    </td>
                                    <td class="status-column"><?php echo htmlspecialchars($detail_delivery['status_of_delivery']); ?></td>
                                    <td class="anticipated-column"><?php echo htmlspecialchars($detail_delivery['anticipated_delivery_date']); ?></td>
                                    <td class="warehouse-arrival-column"><?php echo htmlspecialchars($detail_delivery['warehouse_arrival_date']); ?></td>
                                    <td class="actual-column"><?php echo htmlspecialchars($detail_delivery['actual_delivery_date']); ?></td>
                                    <td class="pod-column">-</td>
                                    <td class="miles-column"><?php echo htmlspecialchars($detail_delivery['miles']); ?></td>
                                    <td class="freight-column">$<?php echo number_format($detail_delivery['freight_cost_with_default'], 2); ?></td>
                                    <td class="accessorial-column">$<?php echo number_format($detail_delivery['accessorial_costs'], 2); ?></td>
                                    <td class="customer-column">$<?php echo number_format($detail_delivery['customer_cost'], 2); ?></td>
                                    <td class="scheduled-column">-</td>
                                    <td class="pallets-column">-</td>
                                    <td class="actions-column">
                                        <a href="edit_delivery?delivery_id=<?php echo $detail_delivery['id']; ?><?php echo (is_numeric($filter_project_id)) ? '&project_id=' . $filter_project_id : ((!empty($detail_delivery['project_id'])) ? '&project_id=' . $detail_delivery['project_id'] : ''); ?>" class="modern-btn secondary" style="font-size: 0.8rem; padding: 6px 12px;">
                                            ✏️ Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php 
                    // Close the prepared statement after the loop
                    if ($stmtPallets) $stmtPallets->close(); 
                    // Close the pallet connection
                    if ($palletConn && !$palletConn->connect_errno) {
                        $palletConn->close();
                    }
                    ?>
                <?php else: ?>
                    <tr id="no-entries-row">
                        <td colspan="<?php echo ($filter_project_id === 'all' || $filter_project_id === 'unassigned') ? '18' : '17'; ?>">No delivery entries found.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
        <!-- The bulk delete button is now triggered from the top (middle-section) with "Bulk Delete" -->
    </form>

    <!-- Bulk Edit Modal -->
    <div id="bulkEditModal">
        <div id="bulkEditModalContent">
            <span class="close-modal" onclick="closeBulkEditModal()">&times;</span>
            
            <div class="modal-header">
                <h2>Bulk Edit</h2>
            </div>
            
            <p>Fill in only the fields you want to update. Leave others blank.</p>
            
            <form method="post" action="manage_deliveries?filter_project_id=<?php echo urlencode($filter_project_id); ?>">
                <!-- Keep time/status filters in case you want to preserve them after submit -->
                <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter ?? ''); ?>">
                <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date ?? ''); ?>">
                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter ?? ''); ?>">
                <input type="hidden" name="delivery_type" value="<?php echo htmlspecialchars($delivery_type ?? ''); ?>">

                <!-- Hidden checkboxes for selected_deliveries[] will be appended by JS -->
                <div id="bulkEditSelectedIds"></div>

                <!-- Two column layout for Delivery Details and Dates -->
                <div class="modal-sections">
                    <div class="modal-section">
                        <div class="section-header">Delivery Details</div>
                        <div class="modal-field">
                            <label for="bulk_manufacturer">Manufacturer</label>
                            <input type="text" id="bulk_manufacturer" name="manufacturer">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_wattage">Wattage</label>
                            <input type="text" id="bulk_wattage" name="wattage">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_status_of_delivery">Status</label>
                            <select id="bulk_status_of_delivery" name="status_of_delivery">
                                <option value="">-- No Change --</option>
                                <option value="Pending">Pending</option>
                                <option value="In Transit to Warehouse">In Transit to Warehouse</option>
                                <option value="Delivered to Warehouse">Delivered to Warehouse</option>
                                <option value="In Transit to Project">In Transit to Project</option>
                                <option value="Delivered to Project">Delivered to Project</option>
                                <option value="Canceled">Canceled</option>
                            </select>
                        </div>
                        <div class="modal-field">
                            <label for="bulk_quantity">Quantity</label>
                            <input type="number" step="1" id="bulk_quantity" name="quantity">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_bol_number">BOL #</label>
                            <input type="text" id="bulk_bol_number" name="bol_number">
                        </div>
                    </div>
                    
                    <div class="modal-section">
                        <div class="section-header">Dates</div>
                        <div class="modal-field">
                            <label for="bulk_anticipated_delivery_date">Anticipated Date</label>
                            <input type="date" id="bulk_anticipated_delivery_date" name="anticipated_delivery_date">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_warehouse_arrival_date">Warehouse Date</label>
                            <input type="date" id="bulk_warehouse_arrival_date" name="warehouse_arrival_date">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_actual_delivery_date">Actual Date</label>
                            <input type="date" id="bulk_actual_delivery_date" name="actual_delivery_date">
                        </div>
                    </div>
                </div>
                
                <!-- Miles & Costs section with improved layout -->
                <div class="modal-cost-section">
                    <div class="section-header">Miles & Costs</div>
                    <div class="modal-cost-fields">
                        <!-- Miles centered on its own line -->
                        <div class="miles-row">
                            <div class="modal-field miles-field">
                                <label for="bulk_miles">Miles</label>
                                <input type="number" step="0.01" id="bulk_miles" name="miles">
                            </div>
                        </div>
                        
                        <!-- Costs on a second line that collapses on mobile -->
                        <div class="costs-row">
                            <div class="modal-field cost-field-half">
                                <label for="bulk_freight_cost">Freight Cost ($)</label>
                                <input type="number" step="0.01" id="bulk_freight_cost" name="freight_cost">
                            </div>
                            <div class="modal-field cost-field-half">
                                <label for="bulk_accessorial_costs">Accessorial ($)</label>
                                <input type="number" step="0.01" id="bulk_accessorial_costs" name="accessorial_costs">
                            </div>
                        </div>
                        
                        <!-- Customer Cost on a separate line -->
                        <div class="customer-cost-row">
                            <div class="modal-field cost-field-half">
                                <label for="bulk_customer_cost">Customer Cost ($)</label>
                                <input type="number" step="0.01" id="bulk_customer_cost" name="customer_cost">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-buttons">
                    <button type="submit" name="bulk_edit_submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Associated Pallets Modal -->
    <div id="associatedPalletsModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeAssociatedPalletModal()">&times;</span>
            <h2>Associated Pallets</h2>
            <div id="palletList"> 
                <!-- Pallet details will be loaded here by JS -->
            </div>
        </div>
    </div>

</main>

<script>
    // Handle Select All
    document.getElementById('select-all').onclick = function() {
        var checkboxes = document.getElementsByName('selected_deliveries[]');
        for (var checkbox of checkboxes) {
            checkbox.checked = this.checked;
        }
        updateBulkActionButtons();
    };

    // Enable/Disable Bulk Edit/Delete buttons based on selection
    function updateBulkActionButtons() {
        var checkboxes = document.getElementsByName('selected_deliveries[]');
        var anyChecked = false;
        for (var checkbox of checkboxes) {
            if (checkbox.checked) {
                anyChecked = true;
                break;
            }
        }
        document.getElementById('bulkEditBtn').disabled = !anyChecked;
        document.getElementById('bulkDeleteBtn').disabled = !anyChecked;

        // Update selected row count with modern styling
        var count = 0;
        for (var checkbox of checkboxes) {
            if (checkbox.checked) {
                count++;
            }
        }
        var countDisplay = document.getElementById('selectedRowCount');
        if (countDisplay) {
            if (count > 0) {
                countDisplay.textContent = count + (count === 1 ? " delivery selected" : " deliveries selected");
                countDisplay.style.display = 'inline-block';
            } else {
                countDisplay.style.display = 'none';
            }
        }
    }

    // Toggle grouped delivery details
    function toggleDeliveryDetails(index) {
        var detailRows = document.querySelectorAll('[id^="detail-' + index + '-"]');
        var mainRow = document.querySelector('[data-delivery-index="' + index + '"]');
        
        var isExpanded = mainRow.classList.contains('expanded');
        
        if (isExpanded) {
            // Collapse
            detailRows.forEach(function(row) {
                row.classList.remove('show');
                setTimeout(function() {
                    row.style.display = 'none';
                }, 300);
            });
            mainRow.classList.remove('expanded');
        } else {
            // Expand
            detailRows.forEach(function(row) {
                row.style.display = 'table-row';
                setTimeout(function() {
                    row.classList.add('show');
                }, 10);
            });
            mainRow.classList.add('expanded');
        }
    }

    // Toggle grouped checkboxes
    function toggleGroupedCheckboxes(index) {
        var groupedCheckboxes = document.querySelectorAll('.grouped-checkbox-' + index);
        var mainCheckbox = event.target;
        
        groupedCheckboxes.forEach(function(checkbox) {
            checkbox.checked = mainCheckbox.checked;
        });
    }

    // Table search
    function searchTable() {
        var input = document.getElementById("searchInput");
        var filter = input.value.toLowerCase();
        var table = document.getElementById("deliveriesTable");
        var trs = table.getElementsByTagName("tr");
        // Skip header row (index 0)
        for (var i = 1; i < trs.length; i++) {
            var tds = trs[i].getElementsByTagName("td");
            var show = false;
            for (var j = 0; j < tds.length; j++) {
                var txtValue = tds[j].textContent || tds[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    show = true;
                    break;
                }
            }
            trs[i].style.display = show ? "" : "none";
        }
    }

    // Open Bulk Edit Modal
    function openBulkEditModal() {
        // Copy selected delivery IDs into hidden inputs in the modal
        var checkboxes = document.getElementsByName('selected_deliveries[]');
        var container = document.getElementById('bulkEditSelectedIds');
        container.innerHTML = ''; // Clear old data

        for (var checkbox of checkboxes) {
            if (checkbox.checked) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_deliveries[]';
                input.value = checkbox.value;
                container.appendChild(input);
            }
        }

        document.getElementById('bulkEditModal').style.display = 'block';
    }

    // Close Bulk Edit Modal
    function closeBulkEditModal() {
        document.getElementById('bulkEditModal').style.display = 'none';
    }

    // Close modal when clicking outside the modal content
    window.onclick = function(event) {
        var modal = document.getElementById('bulkEditModal');
        if (event.target == modal) {
            closeBulkEditModal();
        }
    }

    // --- Associated Pallets Modal --- 
    var associatedPalletsModal = document.getElementById('associatedPalletsModal');
    var palletListUl = document.getElementById('palletList'); // This will now be the container for the table

    function showPalletModal(buttonElement) {
        var palletsJson = buttonElement.getAttribute('data-pallets');
        try {
            var pallets = JSON.parse(palletsJson);
            palletListUl.innerHTML = ''; // Clear previous content (table or list)

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
                    th.style.textAlign = 'center';
                    th.style.backgroundColor = '#293E4C';
                    th.style.maxHeight = '50px';
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

                    var viewDetailsBtn = document.createElement('a');
                    viewDetailsBtn.href = `pallet_details.php?pallet_id=${pallet.id}`;
                    viewDetailsBtn.textContent = 'View Details';
                    viewDetailsBtn.className = 'action-button'; 
                    cellActions.appendChild(viewDetailsBtn);
                });

                palletListUl.appendChild(table); // Append the table to the designated container
            } else {
                 var p = document.createElement('p');
                 p.textContent = 'No pallets found.';
                 palletListUl.appendChild(p);
            }

            associatedPalletsModal.style.display = 'block';
        } catch (e) {
            console.error("Error parsing pallet data or creating table:", e);
            palletListUl.innerHTML = '<p>Error loading pallet data.</p>';
            associatedPalletsModal.style.display = 'block';
        }
    }

    function closeAssociatedPalletModal() {
         associatedPalletsModal.style.display = 'none';
         palletListUl.innerHTML = ''; // Clear list on close
    }

    // Add event listener for clicks outside the associated pallets modal too
    window.addEventListener('click', function(event) {
        if (event.target == associatedPalletsModal) {
            closeAssociatedPalletModal();
        }
    });

    // Export to CSV function
    function exportToCSV() {
        // Get current URL parameters to maintain filters
        var currentUrl = new URL(window.location.href);
        var params = new URLSearchParams(currentUrl.search);
        
        // Add export parameter
        params.set('export_csv', '1');
        
        // Create export URL
        var exportUrl = window.location.pathname + '?' + params.toString();
        
        // Trigger download
        window.location.href = exportUrl;
    }

    // Filters Dropdown Functions
    function toggleFiltersDropdown() {
        var dropdown = document.getElementById('filtersDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        
        // Close column chooser if open
        document.getElementById('columnChooserDropdown').style.display = 'none';
    }

    // Column Chooser Functions
    function toggleColumnChooser() {
        var dropdown = document.getElementById('columnChooserDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        
        // Close filters dropdown if open
        document.getElementById('filtersDropdown').style.display = 'none';
    }

    function toggleColumn(columnClass, isVisible) {
        var elements = document.querySelectorAll('.' + columnClass);
        elements.forEach(function(element) {
            if (isVisible) {
                element.classList.remove('column-hidden');
            } else {
                element.classList.add('column-hidden');
            }
        });
        
        // Update the no-entries row colspan if it exists
        updateNoEntriesColspan();
    }

    function updateNoEntriesColspan() {
        var noEntriesRow = document.getElementById('no-entries-row');
        if (noEntriesRow) {
            var visibleColumns = document.querySelectorAll('th:not(.column-hidden)').length;
            noEntriesRow.querySelector('td').setAttribute('colspan', visibleColumns);
        }
    }

    function resetColumns() {
        // Reset all checkboxes to checked
        var checkboxes = document.querySelectorAll('.column-toggle');
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = true;
            toggleColumn(checkbox.getAttribute('data-column'), true);
        });
        
        // Save to localStorage
        saveColumnPreferences();
    }

    function saveColumnPreferences() {
        var preferences = {};
        var checkboxes = document.querySelectorAll('.column-toggle');
        checkboxes.forEach(function(checkbox) {
            preferences[checkbox.getAttribute('data-column')] = checkbox.checked;
        });
        localStorage.setItem('deliveriesColumnPreferences', JSON.stringify(preferences));
    }

    function loadColumnPreferences() {
        var saved = localStorage.getItem('deliveriesColumnPreferences');
        if (saved) {
            var preferences = JSON.parse(saved);
            var checkboxes = document.querySelectorAll('.column-toggle');
            checkboxes.forEach(function(checkbox) {
                var columnClass = checkbox.getAttribute('data-column');
                if (preferences.hasOwnProperty(columnClass)) {
                    checkbox.checked = preferences[columnClass];
                    toggleColumn(columnClass, preferences[columnClass]);
                }
            });
        }
    }

    // Initialize column chooser functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Load saved preferences
        loadColumnPreferences();
        
        // Add event listeners to column toggles
        var checkboxes = document.querySelectorAll('.column-toggle');
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                var columnClass = this.getAttribute('data-column');
                toggleColumn(columnClass, this.checked);
                saveColumnPreferences();
            });
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            var columnContainer = document.querySelector('.column-chooser-container');
            var columnDropdown = document.getElementById('columnChooserDropdown');
            var filtersContainer = document.querySelector('.filters-dropdown-container');
            var filtersDropdown = document.getElementById('filtersDropdown');
            
            if (columnContainer && !columnContainer.contains(event.target)) {
                columnDropdown.style.display = 'none';
            }
            
            if (filtersContainer && !filtersContainer.contains(event.target)) {
                filtersDropdown.style.display = 'none';
            }
        });
    });

</script>
</body>
</html>
<?php
if ($conn && !$conn->connect_errno) {
    $conn->close();
}
?>
