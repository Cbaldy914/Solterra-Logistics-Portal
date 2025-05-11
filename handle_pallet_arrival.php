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
$pallet_ids = isset($_POST['inbound_pallets']) && is_array($_POST['inbound_pallets']) ? $_POST['inbound_pallets'] : [];
$delivery_ids_for_pallet = isset($_POST['delivery_id_for_pallet']) && is_array($_POST['delivery_id_for_pallet']) ? $_POST['delivery_id_for_pallet'] : [];

$redirect_url = "manage_warehouse_inventory.php?warehouse_id=" . $receiving_warehouse_id;

// Validate inputs
if ($receiving_warehouse_id <= 0) {
    $_SESSION['move_pallet_message'] = "Error: Invalid receiving warehouse ID.";
    header("Location: manage_warehouses.php");
    exit();
}
if (empty($pallet_ids)) {
    $_SESSION['move_pallet_message'] = "Error: No pallets selected for receiving.";
    header("Location: " . $redirect_url);
    exit();
}

$conn = getDBConnection();
$conn->begin_transaction();

$successes = [];
$errors = [];
$current_timestamp = date('Y-m-d H:i:s');
$new_pallet_status = 'In Warehouse';
$new_delivery_status = 'Delivered';

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

$conn->close();
header("Location: " . $redirect_url);
exit();

?> 