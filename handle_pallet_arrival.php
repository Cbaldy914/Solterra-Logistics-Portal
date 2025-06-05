<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    $_SESSION['move_pallet_message'] = "Error: Unauthorized access.";
    header("Location: manage_warehouses.php"); 
    exit();
}

require_once '../config.php';

// Check the action to determine what type of receiving we're doing
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['receive_pallets', 'receive_truckload'])) {
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
        // Handle POD file upload if provided
        $pod_path = null;
        if (isset($_FILES['pod_file']) && $_FILES['pod_file']['error'] === UPLOAD_ERR_OK) {
            // Get delivery info to determine storage path
            $stmt_delivery = $conn->prepare("
                SELECT d.project_id, c.name AS account_name
                FROM deliveries d
                LEFT JOIN projects p ON d.project_id = p.id
                LEFT JOIN customer_accounts c ON p.account_id = c.id
                WHERE d.id = ?
            ");
            if (!$stmt_delivery) {
                throw new Exception("Failed to prepare delivery info query: " . $conn->error);
            }
            
            $stmt_delivery->bind_param("i", $delivery_id);
            $stmt_delivery->execute();
            $stmt_delivery->bind_result($project_id, $account_name);
            $stmt_delivery->fetch();
            $stmt_delivery->close();
            
            $original_name = $_FILES['pod_file']['name'];
            $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Invalid file type for POD. Only PDF, JPG, PNG allowed.");
            }
            
            if ($_FILES['pod_file']['size'] > 5 * 1024 * 1024) { // 5MB limit to match existing code
                throw new Exception("POD file exceeds 5MB limit.");
            }
            
            // Use existing directory structure
            if ($project_id && $account_name) {
                // Project delivery - use project-based structure
                $account_dir = preg_replace('/[^A-Za-z0-9_-]/', '_', $account_name);
                $upload_dir = "customers/{$account_dir}/projects/{$project_id}/documents/pods/";
            } else {
                // Warehouse delivery - use warehouse-based structure
                $upload_dir = "warehouse_documents/pods/";
            }
            
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }
            
            // Create filename using existing pattern
            $original_filename = pathinfo($original_name, PATHINFO_FILENAME);
            $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '_', $original_filename);
            $sanitized = substr($sanitized, 0, 100);
            
            $final_filename = $delivery_id . '_' . $sanitized . '.' . $file_extension;
            $pod_path = $upload_dir . $final_filename;
            
            if (!move_uploaded_file($_FILES['pod_file']['tmp_name'], $pod_path)) {
                throw new Exception("Failed to upload POD file.");
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
            SET status = 'In Warehouse', current_warehouse_id = ?, arrival_date = ? 
            WHERE id = ?
        ");
        if (!$stmt_update_pallets) {
            throw new Exception("Failed to prepare pallet update: " . $conn->error);
        }
        
        $updated_count = 0;
        foreach ($pallet_ids as $pallet_id) {
            $stmt_update_pallets->bind_param("isi", $receiving_warehouse_id, $actual_arrival_date, $pallet_id);
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
            SET status_of_delivery = 'Delivered to Warehouse', 
                warehouse_arrival_date = ?,
                bol_number = ?,
                proof_of_delivery = ?
            WHERE id = ?
        ");
        if (!$stmt_update_delivery) {
            throw new Exception("Failed to prepare delivery update: " . $conn->error);
        }
        
        $stmt_update_delivery->bind_param("sssi", $actual_arrival_date, $bol_number, $pod_path, $delivery_id);
        if (!$stmt_update_delivery->execute()) {
            throw new Exception("Failed to update delivery: " . $stmt_update_delivery->error);
        }
        $stmt_update_delivery->close();
        
        $conn->commit();
        $_SESSION['move_pallet_message'] = "Successfully received truckload with $updated_count pallets. Delivery updated with arrival date: $actual_arrival_date.";
        
    } catch (Exception $e) {
        $conn->rollback();
        // Clean up uploaded file if it exists and there was an error
        if ($pod_path && file_exists($pod_path)) {
            unlink($pod_path);
        }
        $_SESSION['move_pallet_message'] = "Error receiving truckload: " . $e->getMessage();
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
    $new_pallet_status = 'In Warehouse';
    $new_delivery_status = 'Delivered to Warehouse';
    
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
            // 1. Update inventory_pallets table
            $sql_update_pallet = "UPDATE inventory_pallets SET status = ?, current_warehouse_id = ?, arrival_date = ? WHERE id = ? AND status = 'In Transit to Warehouse'";
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
                }
                $stmt_update_delivery->close();
            }
            $successes[] = $pallet_id;
        }
        $conn->commit();
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