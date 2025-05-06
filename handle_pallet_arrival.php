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

// Basic input validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'receive_pallets') {
    $_SESSION['move_pallet_message'] = "Error: Invalid request method or action.";
    header("Location: manage_warehouses.php");
    exit();
}

$receiving_warehouse_id = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : 0;
$receive_pallet_id = isset($_POST['receive_pallet_id']) ? intval($_POST['receive_pallet_id']) : 0;

// Construct the key for the hidden delivery ID input
$delivery_id_key = 'delivery_id_' . $receive_pallet_id;
$delivery_id = isset($_POST[$delivery_id_key]) ? intval($_POST[$delivery_id_key]) : 0;

$redirect_url = "manage_warehouse_inventory.php?warehouse_id=" . $receiving_warehouse_id;

// Validate inputs
if ($receiving_warehouse_id <= 0) {
    $_SESSION['move_pallet_message'] = "Error: Invalid receiving warehouse ID.";
    header("Location: manage_warehouses.php"); // Redirect to general page if ID is bad
    exit();
}
if ($receive_pallet_id <= 0) {
    $_SESSION['move_pallet_message'] = "Error: Invalid pallet ID specified for receiving.";
    header("Location: " . $redirect_url);
    exit();
}
if ($delivery_id <= 0) {
    // Log this, as it might indicate a form issue
    error_log("Missing delivery ID for receiving pallet ID: " . $receive_pallet_id . " at warehouse ID: " . $receiving_warehouse_id);
    $_SESSION['move_pallet_message'] = "Error: Could not find associated delivery information. Please contact support.";
    header("Location: " . $redirect_url);
    exit();
}

$conn = getDBConnection();
$conn->begin_transaction();

try {
    $current_timestamp = date('Y-m-d H:i:s');
    $new_pallet_status = 'In Warehouse';
    $new_delivery_status = 'Delivered'; // Or 'Complete' - using 'Delivered' for arrival

    // 1. Update inventory_pallets table
    $sql_update_pallet = "UPDATE inventory_pallets 
                          SET status = ?, 
                              current_warehouse_id = ?, 
                              arrival_date = ? 
                          WHERE id = ? AND status = 'In Transit to Warehouse'";
    $stmt_update_pallet = $conn->prepare($sql_update_pallet);
    if (!$stmt_update_pallet) {
        throw new Exception("Error preparing pallet update query: " . $conn->error);
    }
    $stmt_update_pallet->bind_param("sisi", $new_pallet_status, $receiving_warehouse_id, $current_timestamp, $receive_pallet_id);
    
    if (!$stmt_update_pallet->execute()) {
        throw new Exception("Error updating pallet status: " . $stmt_update_pallet->error);
    }
    // Check if the pallet was actually updated (it might have already been received)
    if ($stmt_update_pallet->affected_rows === 0) {
         throw new Exception("Pallet ID {$receive_pallet_id} was not found or was not in 'In Transit to Warehouse' status.");
    }
    $stmt_update_pallet->close();

    // 2. Update deliveries table
    $sql_update_delivery = "UPDATE deliveries 
                            SET status_of_delivery = ?, 
                                warehouse_arrival_date = ? 
                            WHERE id = ?";
    $stmt_update_delivery = $conn->prepare($sql_update_delivery);
    if (!$stmt_update_delivery) {
        throw new Exception("Error preparing delivery update query: " . $conn->error);
    }
    $stmt_update_delivery->bind_param("ssi", $new_delivery_status, $current_timestamp, $delivery_id);

    if (!$stmt_update_delivery->execute()) {
        // Log this error but don't necessarily fail the whole transaction, 
        // as the pallet update is more critical for inventory location.
        // However, this might indicate an issue linking pallets to deliveries.
        error_log("Warning: Failed to update delivery status for ID {$delivery_id}: " . $stmt_update_delivery->error);
    }
    $stmt_update_delivery->close();

    // 3. Commit Transaction
    $conn->commit();
    $_SESSION['move_pallet_message'] = "Successfully received Pallet ID {$receive_pallet_id} into warehouse.";

} catch (Exception $e) {
    $conn->rollback();
    // Close statements if they exist and connection is still valid
    if (isset($stmt_update_pallet) && $stmt_update_pallet) $stmt_update_pallet->close();
    if (isset($stmt_update_delivery) && $stmt_update_delivery) $stmt_update_delivery->close();
    
    $_SESSION['move_pallet_message'] = "Error receiving pallet: " . $e->getMessage();
}

$conn->close();

// Redirect back to the warehouse inventory page
header("Location: " . $redirect_url);
exit();

?> 