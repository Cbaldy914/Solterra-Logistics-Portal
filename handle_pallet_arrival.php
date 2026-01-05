<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
    $_SESSION['move_pallet_message'] = "Error: Unauthorized access.";
    header("Location: manage_warehouses.php");
    exit();
}

require_once '../config.php';
require_once 'document_helpers.php';
require_once 'delivery_notification_helpers.php';

// Check the action to determine what type of receiving we're doing
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['receive_pallets', 'receive_truckload', 'receive_multiple_truckloads'])) {
    $_SESSION['move_pallet_message'] = "Error: Invalid request method or action.";
    header("Location: manage_warehouses.php");
    exit();
}

$receiving_warehouse_id = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : 0;
$redirect_url = "manage_warehouse_inventory.php?warehouse_id=" . $receiving_warehouse_id;

// Validate warehouse ID
if ($receiving_warehouse_id <= 0) {
    $_SESSION['move_pallet_message'] = "Error: Invalid receiving warehouse ID.";
    header("Location: manage_warehouses.php");
    exit();
}

$conn = getDBConnection();

// 🚢 PORT DETECTION - Check if receiving warehouse is a port
$is_port = false;
$stmt_check_port = $conn->prepare("SELECT is_port FROM warehouses WHERE id = ?");
if ($stmt_check_port) {
    $stmt_check_port->bind_param("i", $receiving_warehouse_id);
    $stmt_check_port->execute();
    $stmt_check_port->bind_result($port_flag);
    if ($stmt_check_port->fetch()) {
        $is_port = ($port_flag == 1);
    }
    $stmt_check_port->close();
}

// Set status based on facility type
$received_pallet_status = $is_port ? 'Cleared Customs' : 'In Warehouse';
$delivery_status = $is_port ? 'Cleared Customs' : 'Delivered to Warehouse';

if ($action === 'receive_truckload') {
    // Handle truckload receiving
    $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
    $actual_arrival_date = $_POST['actual_arrival_date'] ?? '';
    $bol_number = $_POST['bol_number'] ?? '';
    
    if ($delivery_id <= 0) {
        $_SESSION['move_pallet_message'] = "Error: Invalid delivery ID for truckload.";
        header("Location: " . $redirect_url);
        exit();
    }
    
    if (empty($actual_arrival_date)) {
        $_SESSION['move_pallet_message'] = "Error: Actual arrival date is required.";
        header("Location: " . $redirect_url);
        exit();
    }
    
    $conn->begin_transaction();
    
    try {
        // Get delivery info including old status for notifications (before any updates)
        $stmt_get_delivery = $conn->prepare("SELECT project_id, status_of_delivery FROM deliveries WHERE id = ?");
        if (!$stmt_get_delivery) {
            throw new Exception("Failed to prepare delivery info query: " . $conn->error);
        }
        $stmt_get_delivery->bind_param("i", $delivery_id);
        $stmt_get_delivery->execute();
        $stmt_get_delivery->bind_result($project_id_for_notif, $old_delivery_status);
        $stmt_get_delivery->fetch();
        $stmt_get_delivery->close();
        
        // Handle POD file upload using new document management system
        $pod_path = null;
        if (isset($_FILES['pod_file']) && $_FILES['pod_file']['error'] === UPLOAD_ERR_OK) {
            try {
                // Get delivery info for POD upload
                $stmt_delivery = $conn->prepare("SELECT project_id FROM deliveries WHERE id = ?");
                if (!$stmt_delivery) {
                    throw new Exception("Failed to prepare delivery info query: " . $conn->error);
                }
                
                $stmt_delivery->bind_param("i", $delivery_id);
                $stmt_delivery->execute();
                $stmt_delivery->bind_result($project_id);
                $stmt_delivery->fetch();
                $stmt_delivery->close();
                
                if (!$project_id) {
                    throw new Exception("Could not find project for delivery");
                }
                
                // Prepare file data for upload
                $file_data = [
                    'name' => $_FILES['pod_file']['name'],
                    'type' => $_FILES['pod_file']['type'],
                    'tmp_name' => $_FILES['pod_file']['tmp_name'],
                    'error' => $_FILES['pod_file']['error'],
                    'size' => $_FILES['pod_file']['size']
                ];
                
                // Upload using new document system
                $processed_file = processDocumentUpload($file_data, 'pods');
                
                // Prepare document data
                $document_data = [
                    'project_id' => $project_id,
                    'document_type' => 'pods',
                    'document_sub_type' => 'Warehouse POD',
                    'delivery_id' => $delivery_id,
                    'warehouse_id' => $receiving_warehouse_id,
                    'original_name' => $processed_file['original_name'],
                    'file_size' => $processed_file['size'],
                    'mime_type' => $processed_file['mime_type'],
                    'uploaded_by' => $_SESSION['user_id'],
                    'tmp_name' => $processed_file['tmp_name'],
                    'entity_context' => "Warehouse POD uploaded during truckload receiving for delivery ID: $delivery_id"
                ];
                
                // Save to project_documents table
                $result = saveDocumentToProjectDocuments($conn, $document_data);
                $pod_path = $result['file_path'];
                
            } catch (Exception $e) {
                error_log("Failed to upload POD via new system: " . $e->getMessage());
                throw new Exception("POD upload failed: " . $e->getMessage());
            }
        }
        
        // Get all pallets for this delivery
        $stmt_get_pallets = $conn->prepare("
            SELECT ip.id 
            FROM inventory_pallets ip
            JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
            WHERE dp.delivery_id = ? AND ip.status = 'In Transit to Warehouse'
        ");
        if (!$stmt_get_pallets) {
            throw new Exception("Failed to prepare pallet query: " . $conn->error);
        }
        
        $stmt_get_pallets->bind_param("i", $delivery_id);
        $stmt_get_pallets->execute();
        $result = $stmt_get_pallets->get_result();
        
        $pallet_ids = [];
        while ($row = $result->fetch_assoc()) {
            $pallet_ids[] = $row['id'];
        }
        $stmt_get_pallets->close();
        
        if (empty($pallet_ids)) {
            throw new Exception("No pallets found for this delivery or pallets already received.");
        }
        
        // Update all pallets in the truckload
        $stmt_update_pallets = $conn->prepare("
            UPDATE inventory_pallets 
            SET status = ?, current_warehouse_id = ?, arrival_date = ? 
            WHERE id = ?
        ");
        if (!$stmt_update_pallets) {
            throw new Exception("Failed to prepare pallet update: " . $conn->error);
        }
        
        $updated_count = 0;
        foreach ($pallet_ids as $pallet_id) {
            $stmt_update_pallets->bind_param("sisi", $received_pallet_status, $receiving_warehouse_id, $actual_arrival_date, $pallet_id);
            if ($stmt_update_pallets->execute()) {
                $updated_count++;
            } else {
                error_log("Failed to update pallet ID $pallet_id: " . $stmt_update_pallets->error);
            }
        }
        $stmt_update_pallets->close();
        
        // Update delivery record
        $stmt_update_delivery = $conn->prepare("
            UPDATE deliveries 
            SET status_of_delivery = ?, 
                warehouse_arrival_date = ?,
                bol_number = ?,
                proof_of_delivery = ?
            WHERE id = ?
        ");
        if (!$stmt_update_delivery) {
            throw new Exception("Failed to prepare delivery update: " . $conn->error);
        }
        
        $stmt_update_delivery->bind_param("ssssi", $delivery_status, $actual_arrival_date, $bol_number, $pod_path, $delivery_id);
        if (!$stmt_update_delivery->execute()) {
            throw new Exception("Failed to update delivery: " . $stmt_update_delivery->error);
        }
        $stmt_update_delivery->close();

        // If receiving at a port, also stamp customs_cleared_date
        if ($is_port) {
            $stmt_update_customs = $conn->prepare("UPDATE deliveries SET customs_cleared_date = ? WHERE id = ?");
            if ($stmt_update_customs) {
                $stmt_update_customs->bind_param("si", $actual_arrival_date, $delivery_id);
                if (!$stmt_update_customs->execute()) {
                    throw new Exception("Failed to update customs cleared date: " . $stmt_update_customs->error);
                }
                $stmt_update_customs->close();
            }
        }
        
        $conn->commit();
        
        // Send notification about delivery status change
        if ($old_delivery_status !== $delivery_status) {
            notify_delivery_status_change($delivery_id, $old_delivery_status, $delivery_status);
        }
        
        $_SESSION['move_pallet_message'] = "Successfully received truckload with $updated_count pallets. Delivery updated with arrival date: $actual_arrival_date.";
        
    } catch (Exception $e) {
        $conn->rollback();
        // Clean up uploaded file if it exists and there was an error
        if ($pod_path && file_exists($pod_path)) {
            unlink($pod_path);
        }
        $_SESSION['move_pallet_message'] = "Error receiving truckload: " . $e->getMessage();
    }
    
} elseif ($action === 'receive_multiple_truckloads') {
    // Handle bulk truckload receiving
    $delivery_ids = isset($_POST['delivery_ids']) && is_array($_POST['delivery_ids']) ? $_POST['delivery_ids'] : [];
    $actual_arrival_date = $_POST['actual_arrival_date'] ?? '';
    $bol_number_override = $_POST['bol_number_override'] ?? '';
    
    if (empty($delivery_ids)) {
        $_SESSION['move_pallet_message'] = "Error: No truckloads selected for receiving.";
        header("Location: " . $redirect_url);
        exit();
    }
    
    if (empty($actual_arrival_date)) {
        $_SESSION['move_pallet_message'] = "Error: Actual arrival date is required.";
        header("Location: " . $redirect_url);
        exit();
    }
    
    $conn->begin_transaction();
    
    try {
        // Handle POD file upload using new document management system
        $pod_path = null;
        $uploaded_pod_data = null;
        if (isset($_FILES['pod_file']) && $_FILES['pod_file']['error'] === UPLOAD_ERR_OK) {
            try {
                // Get project_id from first delivery
                $first_delivery_id = intval($delivery_ids[0]);
                $stmt_delivery = $conn->prepare("SELECT project_id FROM deliveries WHERE id = ?");
                if (!$stmt_delivery) {
                    throw new Exception("Failed to prepare delivery info query: " . $conn->error);
                }
                
                $stmt_delivery->bind_param("i", $first_delivery_id);
                $stmt_delivery->execute();
                $stmt_delivery->bind_result($project_id);
                $stmt_delivery->fetch();
                $stmt_delivery->close();
                
                if (!$project_id) {
                    throw new Exception("Could not find project for delivery");
                }
                
                // Prepare file data for upload
                $file_data = [
                    'name' => $_FILES['pod_file']['name'],
                    'type' => $_FILES['pod_file']['type'],
                    'tmp_name' => $_FILES['pod_file']['tmp_name'],
                    'error' => $_FILES['pod_file']['error'],
                    'size' => $_FILES['pod_file']['size']
                ];
                
                // Upload using new document system
                $processed_file = processDocumentUpload($file_data, 'pods');
                
                // Store the processed file data for later use with each delivery
                $uploaded_pod_data = [
                    'project_id' => $project_id,
                    'processed_file' => $processed_file
                ];
                
            } catch (Exception $e) {
                error_log("Failed to upload POD via new system: " . $e->getMessage());
                throw new Exception("POD upload failed: " . $e->getMessage());
            }
        }
        
        $total_pallets_updated = 0;
        $successful_deliveries = [];
        $errors = [];
        $old_delivery_statuses = []; // Store old statuses for notifications
        
        // Fetch old delivery statuses before updates for notifications
        foreach ($delivery_ids as $del_id) {
            $del_id_int = intval($del_id);
            $stmt_old_status = $conn->prepare("SELECT status_of_delivery FROM deliveries WHERE id = ?");
            if ($stmt_old_status) {
                $stmt_old_status->bind_param("i", $del_id_int);
                $stmt_old_status->execute();
                $stmt_old_status->bind_result($old_status);
                if ($stmt_old_status->fetch()) {
                    $old_delivery_statuses[$del_id_int] = $old_status;
                }
                $stmt_old_status->close();
            }
        }
        
        foreach ($delivery_ids as $delivery_id) {
            $delivery_id = intval($delivery_id);
            if ($delivery_id <= 0) {
                $errors[] = "Invalid delivery ID: $delivery_id";
                continue;
            }
            
            try {
                // Get all pallets for this delivery (including overseas statuses)
                $stmt_get_pallets = $conn->prepare("
                    SELECT ip.id 
                    FROM inventory_pallets ip
                    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                    WHERE dp.delivery_id = ? AND ip.status IN ('In Transit to Warehouse', 'On Water', 'Cleared Customs')
                ");
                if (!$stmt_get_pallets) {
                    throw new Exception("Failed to prepare pallet query for delivery $delivery_id: " . $conn->error);
                }
                
                $stmt_get_pallets->bind_param("i", $delivery_id);
                $stmt_get_pallets->execute();
                $result = $stmt_get_pallets->get_result();
                
                $pallet_ids = [];
                while ($row = $result->fetch_assoc()) {
                    $pallet_ids[] = $row['id'];
                }
                $stmt_get_pallets->close();
                
                if (empty($pallet_ids)) {
                    $errors[] = "No pallets found for delivery $delivery_id or pallets already received";
                    continue;
                }
                
                // Update all pallets in this truckload
                $stmt_update_pallets = $conn->prepare("
                    UPDATE inventory_pallets 
                    SET status = ?, current_warehouse_id = ?, arrival_date = ? 
                    WHERE id = ?
                ");
                if (!$stmt_update_pallets) {
                    throw new Exception("Failed to prepare pallet update for delivery $delivery_id: " . $conn->error);
                }
                
                $delivery_pallet_count = 0;
                foreach ($pallet_ids as $pallet_id) {
                    $stmt_update_pallets->bind_param("sisi", $received_pallet_status, $receiving_warehouse_id, $actual_arrival_date, $pallet_id);
                    if ($stmt_update_pallets->execute()) {
                        $delivery_pallet_count++;
                        $total_pallets_updated++;
                    } else {
                        error_log("Failed to update pallet ID $pallet_id in delivery $delivery_id: " . $stmt_update_pallets->error);
                    }
                }
                $stmt_update_pallets->close();
                
                // Get existing BOL number if no override provided
                $bol_to_use = $bol_number_override;
                if (empty($bol_to_use)) {
                    $stmt_get_bol = $conn->prepare("SELECT bol_number FROM deliveries WHERE id = ?");
                    if ($stmt_get_bol) {
                        $stmt_get_bol->bind_param("i", $delivery_id);
                        $stmt_get_bol->execute();
                        $stmt_get_bol->bind_result($existing_bol);
                        if ($stmt_get_bol->fetch()) {
                            $bol_to_use = $existing_bol;
                        }
                        $stmt_get_bol->close();
                    }
                }
                
                // Update delivery record (without old POD field)
                $stmt_update_delivery = $conn->prepare("
                    UPDATE deliveries 
                    SET status_of_delivery = ?, 
                        warehouse_arrival_date = ?,
                        bol_number = ?
                    WHERE id = ?
                ");
                if (!$stmt_update_delivery) {
                    throw new Exception("Failed to prepare delivery update for delivery $delivery_id: " . $conn->error);
                }
                
                $stmt_update_delivery->bind_param("sssi", $delivery_status, $actual_arrival_date, $bol_to_use, $delivery_id);
                if (!$stmt_update_delivery->execute()) {
                    throw new Exception("Failed to update delivery $delivery_id: " . $stmt_update_delivery->error);
                }
                $stmt_update_delivery->close();

                // If receiving at a port, also stamp customs_cleared_date
                if ($is_port) {
                    $stmt_update_customs = $conn->prepare("UPDATE deliveries SET customs_cleared_date = ? WHERE id = ?");
                    if ($stmt_update_customs) {
                        $stmt_update_customs->bind_param("si", $actual_arrival_date, $delivery_id);
                        if (!$stmt_update_customs->execute()) {
                            throw new Exception("Failed to update customs cleared date for delivery $delivery_id: " . $stmt_update_customs->error);
                        }
                        $stmt_update_customs->close();
                    }
                }
                
                // Save POD to project_documents table if uploaded
                if ($uploaded_pod_data) {
                    $document_data = [
                        'project_id' => $uploaded_pod_data['project_id'],
                        'document_type' => 'pods',
                        'document_sub_type' => 'Warehouse POD',
                        'delivery_id' => $delivery_id,
                        'warehouse_id' => $receiving_warehouse_id,
                        'original_name' => $uploaded_pod_data['processed_file']['original_name'],
                        'file_size' => $uploaded_pod_data['processed_file']['size'],
                        'mime_type' => $uploaded_pod_data['processed_file']['mime_type'],
                        'uploaded_by' => $_SESSION['user_id'],
                        'tmp_name' => $uploaded_pod_data['processed_file']['tmp_name'],
                        'entity_context' => "Warehouse POD uploaded during bulk truckload receiving for delivery ID: $delivery_id"
                    ];
                    
                    // Save to project_documents table
                    $result = saveDocumentToProjectDocuments($conn, $document_data);
                    $pod_path = $result['file_path']; // For cleanup if needed
                }
                
                $successful_deliveries[] = "Delivery $delivery_id ($delivery_pallet_count pallets)";
                
            } catch (Exception $e) {
                $errors[] = "Delivery $delivery_id: " . $e->getMessage();
            }
        }
        
        if (!empty($successful_deliveries)) {
            $conn->commit();
            
            // Send notifications for each successfully updated delivery
            foreach ($successful_deliveries as $delivery_id_success) {
                if (isset($old_delivery_statuses[$delivery_id_success]) && $old_delivery_statuses[$delivery_id_success] !== $delivery_status) {
                    notify_delivery_status_change($delivery_id_success, $old_delivery_statuses[$delivery_id_success], $delivery_status);
                }
            }
            
            $success_message = "Successfully received " . count($successful_deliveries) . " truckload(s) with $total_pallets_updated total pallets. ";
            $success_message .= "Deliveries: " . implode(", ", $successful_deliveries) . ". ";
            $success_message .= "Arrival date: $actual_arrival_date.";
            
            if (!empty($errors)) {
                $success_message .= " Errors: " . implode(", ", $errors);
            }
            
            $_SESSION['move_pallet_message'] = $success_message;
        } else {
            throw new Exception("No deliveries were successfully processed. Errors: " . implode(", ", $errors));
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        // Clean up uploaded file if it exists and there was an error
        if ($pod_path && file_exists($pod_path)) {
            unlink($pod_path);
        }
        $_SESSION['move_pallet_message'] = "Error receiving multiple truckloads: " . $e->getMessage();
    }
    
} else {
    // Handle individual pallet receiving (existing functionality)
    $pallet_ids = isset($_POST['inbound_pallets']) && is_array($_POST['inbound_pallets']) ? $_POST['inbound_pallets'] : [];
    $delivery_ids_for_pallet = isset($_POST['delivery_id_for_pallet']) && is_array($_POST['delivery_id_for_pallet']) ? $_POST['delivery_id_for_pallet'] : [];
    
    if (empty($pallet_ids)) {
        $_SESSION['move_pallet_message'] = "Error: No pallets selected for receiving.";
        header("Location: " . $redirect_url);
        exit();
    }
    
    $conn->begin_transaction();
    
    $successes = [];
    $errors = [];
    $current_timestamp = date('Y-m-d H:i:s');
    $current_date = date('Y-m-d');
    $new_pallet_status = $received_pallet_status;
    $new_delivery_status = $delivery_status;
    $old_delivery_statuses_pallets = []; // Track old statuses for notifications
    $updated_deliveries = []; // Track which deliveries were updated
    
    try {
        foreach ($pallet_ids as $pallet_id) {
            $pallet_id = intval($pallet_id);
            if ($pallet_id <= 0) {
                $errors[] = "Invalid pallet ID: $pallet_id.";
                continue;
            }
            // Get delivery ID for this pallet
            $delivery_id = isset($delivery_ids_for_pallet[$pallet_id]) ? intval($delivery_ids_for_pallet[$pallet_id]) : 0;
            if ($delivery_id <= 0) {
                $errors[] = "Missing delivery ID for pallet $pallet_id.";
                continue;
            }
            
            // Fetch old delivery status before updating (for notifications)
            if (!isset($old_delivery_statuses_pallets[$delivery_id])) {
                $stmt_old_status = $conn->prepare("SELECT status_of_delivery FROM deliveries WHERE id = ?");
                if ($stmt_old_status) {
                    $stmt_old_status->bind_param("i", $delivery_id);
                    $stmt_old_status->execute();
                    $stmt_old_status->bind_result($old_status);
                    if ($stmt_old_status->fetch()) {
                        $old_delivery_statuses_pallets[$delivery_id] = $old_status;
                    }
                    $stmt_old_status->close();
                }
            }
            
            // 1. Update inventory_pallets table (handle overseas statuses too)
            $sql_update_pallet = "UPDATE inventory_pallets SET status = ?, current_warehouse_id = ?, arrival_date = ? WHERE id = ? AND status IN ('In Transit to Warehouse', 'On Water', 'Cleared Customs')";
            $stmt_update_pallet = $conn->prepare($sql_update_pallet);
            if (!$stmt_update_pallet) {
                $errors[] = "Error preparing pallet update for pallet $pallet_id: " . $conn->error;
                continue;
            }
            $stmt_update_pallet->bind_param("sisi", $new_pallet_status, $receiving_warehouse_id, $current_timestamp, $pallet_id);
            if (!$stmt_update_pallet->execute()) {
                $errors[] = "Error updating pallet $pallet_id: " . $stmt_update_pallet->error;
                $stmt_update_pallet->close();
                continue;
            }
            if ($stmt_update_pallet->affected_rows === 0) {
                $errors[] = "Pallet ID $pallet_id was not found or not in 'In Transit to Warehouse' status.";
                $stmt_update_pallet->close();
                continue;
            }
            $stmt_update_pallet->close();
            // 2. Update deliveries table
            $sql_update_delivery = "UPDATE deliveries SET status_of_delivery = ?, warehouse_arrival_date = ? WHERE id = ?";
            $stmt_update_delivery = $conn->prepare($sql_update_delivery);
            if ($stmt_update_delivery) {
                $stmt_update_delivery->bind_param("ssi", $new_delivery_status, $current_timestamp, $delivery_id);
                if (!$stmt_update_delivery->execute()) {
                    error_log("Warning: Failed to update delivery status for ID $delivery_id: " . $stmt_update_delivery->error);
                } else {
                    // Track this delivery for notifications
                    if (!in_array($delivery_id, $updated_deliveries)) {
                        $updated_deliveries[] = $delivery_id;
                    }
                }
                $stmt_update_delivery->close();
            }

            // 2b. If receiving at a port, stamp customs_cleared_date (DATE)
            if ($is_port) {
                $stmt_update_customs = $conn->prepare("UPDATE deliveries SET customs_cleared_date = ? WHERE id = ?");
                if ($stmt_update_customs) {
                    $stmt_update_customs->bind_param("si", $current_date, $delivery_id);
                    if (!$stmt_update_customs->execute()) {
                        error_log("Warning: Failed to set customs cleared date for delivery ID $delivery_id: " . $stmt_update_customs->error);
                    }
                    $stmt_update_customs->close();
                }
            }
            $successes[] = $pallet_id;
        }
        $conn->commit();
        
        // Send notifications for each updated delivery
        foreach ($updated_deliveries as $updated_delivery_id) {
            if (isset($old_delivery_statuses_pallets[$updated_delivery_id]) && $old_delivery_statuses_pallets[$updated_delivery_id] !== $new_delivery_status) {
                notify_delivery_status_change($updated_delivery_id, $old_delivery_statuses_pallets[$updated_delivery_id], $new_delivery_status);
            }
        }
        
        $msg = '';
        if (!empty($successes)) {
            $msg .= "Successfully received Pallet ID(s): " . implode(", ", $successes) . ". ";
        }
        if (!empty($errors)) {
            $msg .= "Errors: " . implode(" ", $errors);
        }
        $_SESSION['move_pallet_message'] = $msg;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['move_pallet_message'] = "Error receiving pallets: " . $e->getMessage();
    }
}

$conn->close();
header("Location: " . $redirect_url);
exit();
?> 
