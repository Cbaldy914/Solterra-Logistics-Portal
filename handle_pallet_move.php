<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    $_SESSION['move_pallet_message'] = "Error: Unauthorized access.";
    // Redirect back to a safe page, maybe the dashboard or the last known warehouse page if possible
    header("Location: manage_warehouses.php"); 
    exit();
}

require_once '../config.php';

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

// More specific validation
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

// Convert selected pallet IDs to integers
$palletIds = array_map('intval', $selected_pallets);
$placeholders = implode(',', array_fill(0, count($palletIds), '?'));
$types        = str_repeat('i', count($palletIds));

$conn = getDBConnection();
$conn->begin_transaction();

try {
    // 1. Get Origin Warehouse Name (for supplier field)
    $stmtOrigin = $conn->prepare("SELECT name FROM warehouses WHERE id = ?");
    if (!$stmtOrigin) throw new Exception("Failed to prepare origin warehouse query: " . $conn->error);
    $stmtOrigin->bind_param("i", $current_warehouse_id);
    $stmtOrigin->execute();
    $stmtOrigin->bind_result($origin_warehouse_name);
    if (!$stmtOrigin->fetch()) {
        $stmtOrigin->close();
        throw new Exception("Origin warehouse not found.");
    }
    $stmtOrigin->close();

    // 2. Fetch details of selected pallets to verify they are in the origin warehouse and group them
    $sqlFetchPallets = "SELECT id, wattage, quantity FROM inventory_pallets WHERE id IN ($placeholders) AND current_warehouse_id = ? AND status = 'In Warehouse'";
    $stmtFetch = $conn->prepare($sqlFetchPallets);
    if (!$stmtFetch) throw new Exception("Failed to prepare pallet fetch query: " . $conn->error);
    $params = array_merge($palletIds, [$current_warehouse_id]);
    $stmtFetch->bind_param($types . 'i', ...$params);
    $stmtFetch->execute();
    $resultPallets = $stmtFetch->get_result();

    $palletsByWattage = [];
    $fetchedPalletIds = [];
    if ($resultPallets->num_rows !== count($palletIds)) {
         throw new Exception("Mismatch: Some selected pallets were not found in the origin warehouse or are not in 'In Warehouse' status.");
    }
    while ($pallet = $resultPallets->fetch_assoc()) {
        $fetchedPalletIds[] = $pallet['id']; // Keep track of verified IDs
        $wattage = $pallet['wattage'];
        if (!isset($palletsByWattage[$wattage])) {
            $palletsByWattage[$wattage] = ['quantity' => 0, 'ids' => []];
        }
        $palletsByWattage[$wattage]['quantity'] += $pallet['quantity'];
        $palletsByWattage[$wattage]['ids'][] = $pallet['id'];
    }
    $stmtFetch->close();

    // Ensure we only process verified pallets
    if (empty($fetchedPalletIds)) {
        throw new Exception("No valid pallets to move after verification.");
    }

    // 3. Prepare statements for Insert/Update
    $sqlDeliveryInsert = "";
    $bindTypesDelivery = "";
    $paramsDeliveryBase = [];

    if ($destination_type === 'project') {
        $sqlDeliveryInsert = "INSERT INTO deliveries (project_id, supplier, wattage, quantity, bol_number, left_warehouse_date, anticipated_delivery_date, status_of_delivery) VALUES (?, ?, ?, ?, ?, ?, ?, 'In Transit to Project')";
        $bindTypesDelivery = "issssss"; // project_id, supplier, wattage, quantity, bol, left_date, arrival_date
        $paramsDeliveryBase = [$destination_id, $origin_warehouse_name];
    } else { // Moving to another warehouse
        $sqlDeliveryInsert = "INSERT INTO deliveries (warehouse_id, supplier, wattage, quantity, bol_number, left_warehouse_date, anticipated_delivery_date, status_of_delivery) VALUES (?, ?, ?, ?, ?, ?, ?, 'In Transit to Warehouse')";
        $bindTypesDelivery = "issssss"; // warehouse_id, supplier, wattage, quantity, bol, left_date, arrival_date
        $paramsDeliveryBase = [$destination_id, $origin_warehouse_name];
    }
    $stmtDelivery = $conn->prepare($sqlDeliveryInsert);
    if (!$stmtDelivery) throw new Exception("Failed to prepare delivery insert: " . $conn->error);

    $stmtLink = $conn->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
    if (!$stmtLink) throw new Exception("Failed to prepare pallet link insert: " . $conn->error);

    $newStatus = ($destination_type === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';
    $updateProjectId = ($destination_type === 'project') ? $destination_id : null;
    $updateWarehouseId = null; // Pallets are leaving the current warehouse

    $sqlPalletUpdate = "UPDATE inventory_pallets SET status = ?, current_warehouse_id = ?, current_project_id = ? WHERE id = ?";
    $stmtPalletUpdate = $conn->prepare($sqlPalletUpdate);
    if (!$stmtPalletUpdate) throw new Exception("Failed to prepare pallet update: " . $conn->error);

    // 4. Loop through wattage groups, create delivery, link/update pallets
    $createdDeliveryIds = [];
    foreach ($palletsByWattage as $wattage => $group) {
        $groupQty = $group['quantity'];
        $groupPalletIds = $group['ids'];

        // Execute Delivery Insert
        $paramsDelivery = array_merge($paramsDeliveryBase, [$wattage, $groupQty, $bol_number, $departure_date, $est_arrival_date]);
        $stmtDelivery->bind_param($bindTypesDelivery, ...$paramsDelivery);
        if (!$stmtDelivery->execute()) throw new Exception("Failed to execute delivery insert for {$wattage}W: " . $stmtDelivery->error);
        $deliveryId = $conn->insert_id;
        $createdDeliveryIds[] = $deliveryId;

        // Link and Update Pallets in this group
        foreach ($groupPalletIds as $pid) {
            // Update Pallet Status/Location
            $stmtPalletUpdate->bind_param("siii", $newStatus, $updateWarehouseId, $updateProjectId, $pid);
            if (!$stmtPalletUpdate->execute()) throw new Exception("Failed to update pallet ID {$pid}: " . $stmtPalletUpdate->error);
            
            // Link Pallet to new Delivery
            $stmtLink->bind_param("ii", $deliveryId, $pid);
            if (!$stmtLink->execute()) throw new Exception("Failed to link pallet ID {$pid} to delivery {$deliveryId}: " . $stmtLink->error);
        }
    }

    // 5. Commit Transaction
    $conn->commit();
    $_SESSION['move_pallet_message'] = "Successfully created transfer delivery (IDs: " . implode(", ", $createdDeliveryIds) . ") for " . count($fetchedPalletIds) . " pallets.";

    // Close statements
    $stmtDelivery->close();
    $stmtLink->close();
    $stmtPalletUpdate->close();

} catch (Exception $e) {
    $conn->rollback();
    // Close statements if they were prepared before error
    if (isset($stmtDelivery) && $stmtDelivery) $stmtDelivery->close();
    if (isset($stmtLink) && $stmtLink) $stmtLink->close();
    if (isset($stmtPalletUpdate) && $stmtPalletUpdate) $stmtPalletUpdate->close();
    
    $_SESSION['move_pallet_message'] = "Error moving pallets: " . $e->getMessage();
}

// Redirect back
header("Location: " . $redirect_url);
exit();

?> 