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
require_once 'cost_helpers.php';

// Basic input validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'move_pallets') {
    $_SESSION['move_pallet_message'] = "Error: Invalid request.";
    header("Location: manage_warehouses.php");
    exit();
}

$current_warehouse_id = isset($_POST['current_warehouse_id']) ? intval($_POST['current_warehouse_id']) : 0;
$selected_pallets     = isset($_POST['selected_pallets']) && is_array($_POST['selected_pallets']) ? $_POST['selected_pallets'] : [];
$destination_type     = $_POST['destination_type'] ?? '';
$destination_id       = isset($_POST['destination_id']) ? intval($_POST['destination_id']) : 0;
$departure_date       = $_POST['departure_date'] ?? '';
$bol_number           = trim($_POST['bol_number'] ?? '');
$est_arrival_date     = !empty($_POST['est_arrival_date']) ? $_POST['est_arrival_date'] : null;

$redirect_url = "manage_warehouse_inventory.php?warehouse_id=" . $current_warehouse_id;

// Validation
if ($current_warehouse_id <= 0) {
    $_SESSION['move_pallet_message'] = "Error: Invalid origin warehouse ID.";
    header("Location: manage_warehouses.php");
    exit();
}
if (empty($selected_pallets)) {
    $_SESSION['move_pallet_message'] = "Error: No pallets selected to move.";
    header("Location: " . $redirect_url);
    exit();
}
if (empty($destination_type) || !in_array($destination_type, ['project', 'warehouse'])) {
    $_SESSION['move_pallet_message'] = "Error: Invalid destination type specified.";
    header("Location: " . $redirect_url);
    exit();
}
if ($destination_id <= 0) {
    $_SESSION['move_pallet_message'] = "Error: No destination selected.";
    header("Location: " . $redirect_url);
    exit();
}
if (empty($departure_date)) {
    $_SESSION['move_pallet_message'] = "Error: Departure date is required.";
    header("Location: " . $redirect_url);
    exit();
}
if (empty($est_arrival_date)) {
    $_SESSION['move_pallet_message'] = "Error: Estimated arrival date is required.";
    header("Location: " . $redirect_url);
    exit();
}

// Convert selected pallet IDs to integers
$palletIds = array_map('intval', $selected_pallets);
$placeholders = implode(',', array_fill(0, count($palletIds), '?'));
$types        = str_repeat('i', count($palletIds));

$conn = getDBConnection();
$conn->begin_transaction();

try {
    // 1. Get Origin Warehouse Name
    $stmtOrigin = $conn->prepare("SELECT name FROM warehouses WHERE id = ?");
    $stmtOrigin->bind_param("i", $current_warehouse_id);
    $stmtOrigin->execute();
    $stmtOrigin->bind_result($origin_warehouse_name);
    if (!$stmtOrigin->fetch()) {
        $origin_warehouse_name = "Unknown Warehouse";
    }
    $stmtOrigin->close();

    // 2. Fetch Pallet Data (Project, Arrival Date, Current Cost)
    $sqlPallets = "SELECT id, current_project_id, arrival_date, warehouse_cost FROM inventory_pallets WHERE id IN ($placeholders)";
    $stmtGetPallets = $conn->prepare($sqlPallets);
    $stmtGetPallets->bind_param($types, ...$palletIds);
    $stmtGetPallets->execute();
    $resultPallets = $stmtGetPallets->get_result();
    
    $palletsByProject = [];
    $fetchedPalletIds = [];

    while ($row = $resultPallets->fetch_assoc()) {
        $pid = $row['id'];
        $projId = $row['current_project_id'] ? $row['current_project_id'] : 0;
        
        // Grouping Logic
        if ($destination_type === 'project') {
            $groupKey = $destination_id;
        } else {
            $groupKey = $projId;
        }
        
        if (!isset($palletsByProject[$groupKey])) {
            $palletsByProject[$groupKey] = [];
        }
        $palletsByProject[$groupKey][] = $row;
        $fetchedPalletIds[] = $pid;
    }
    $stmtGetPallets->close();

    if (empty($fetchedPalletIds)) {
        throw new Exception("No valid pallets found.");
    }

    // Prepare statements
    $stmtDelivery = $conn->prepare("INSERT INTO deliveries (project_id, supplier, status_of_delivery, actual_delivery_date, bol_number, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmtLink = $conn->prepare("INSERT INTO delivery_pallets (delivery_id, pallet_id) VALUES (?, ?)");
    $stmtPalletUpdate = $conn->prepare("UPDATE inventory_pallets SET status = ?, current_warehouse_id = ?, current_project_id = ?, delivery_id = ?, warehouse_cost = ? WHERE id = ?");

    $createdDeliveryIds = [];

    foreach ($palletsByProject as $projectId => $palletsInGroup) {
        // Determine Delivery Status
        $delivery_status = '';
        $is_same_day_warehouse_arrival = false;

        if ($destination_type === 'project') {
            $delivery_status = 'In Transit to Project';
        } else {
            $today = date('Y-m-d');
            if ($est_arrival_date <= $today) {
                $delivery_status = 'Delivered to Warehouse';
                $is_same_day_warehouse_arrival = true;
            } else {
                $delivery_status = 'In Transit to Warehouse';
            }
        }

        // Create Delivery Record
        $dbProjectId = ($projectId > 0) ? $projectId : null;
        
        $stmtDelivery->bind_param("issss", $dbProjectId, $origin_warehouse_name, $delivery_status, $est_arrival_date, $bol_number);
        if (!$stmtDelivery->execute()) {
            throw new Exception("Failed to create delivery: " . $stmtDelivery->error);
        }
        $delivery_id = $stmtDelivery->insert_id;
        $createdDeliveryIds[] = $delivery_id;

        // Process Pallets
        foreach ($palletsInGroup as $pallet) {
            $pid = $pallet['id'];
            $arrival_date = $pallet['arrival_date'];
            $current_cost = $pallet['warehouse_cost'];

            // Calculate Storage Cost
            $storage_cost = 0.0;
            if ($arrival_date) {
                $storage_cost = calculate_pallet_storage_cost($conn, $pid, $current_warehouse_id, $arrival_date, $departure_date);
            }
            $new_total_cost = $current_cost + $storage_cost;

            // Determine New Status/Location
            $new_pallet_status = '';
            $target_warehouse_id = null;
            $target_project_id = null;

            if ($destination_type === 'project') {
                $new_pallet_status = 'In Transit to Project';
                $target_project_id = $destination_id;
            } else {
                if ($is_same_day_warehouse_arrival) {
                    $new_pallet_status = 'In Warehouse';
                    $target_warehouse_id = $destination_id;
                } else {
                    $new_pallet_status = 'In Transit to Warehouse';
                    $target_warehouse_id = null;
                }
                $target_project_id = ($projectId > 0) ? $projectId : null;
            }

            // Link to Delivery
            $stmtLink->bind_param("ii", $delivery_id, $pid);
            $stmtLink->execute();

            // Update Pallet
            $stmtPalletUpdate->bind_param("siiidi", $new_pallet_status, $target_warehouse_id, $target_project_id, $delivery_id, $new_total_cost, $pid);
            $stmtPalletUpdate->execute();
        }
    }

    $stmtDelivery->close();
    $stmtLink->close();
    $stmtPalletUpdate->close();

    $conn->commit();
    $_SESSION['move_pallet_message'] = "Successfully moved " . count($fetchedPalletIds) . " pallets. Created Deliveries: " . implode(", ", $createdDeliveryIds);

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['move_pallet_message'] = "Error moving pallets: " . $e->getMessage();
}

header("Location: " . $redirect_url);
exit();
?>