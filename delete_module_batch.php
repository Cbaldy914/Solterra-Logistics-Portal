<?php
session_name("logistics_session");
session_start();

// Ensure authorized
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Validate POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['batch_id'])) {
    header("Location: unassigned_modules.php");
    exit();
}
$batchId = intval($_POST['batch_id']);

try {
    $conn->begin_transaction();

    // Admin role: ensure belongs to their account
    if ($_SESSION['role'] === 'admin') {
        $stmtCheck = $conn->prepare(
            "SELECT 1 FROM modules um
             JOIN customer_account_users cau ON um.account_id = cau.account_id
             WHERE um.id = ? AND cau.user_id = ? AND cau.role = 'admin'"
        );
        $stmtCheck->bind_param("ii", $batchId, $_SESSION['user_id']);
        $stmtCheck->execute();
        $stmtCheck->store_result();
        if ($stmtCheck->num_rows === 0) {
            throw new Exception("Access denied.");
        }
        $stmtCheck->close();
    }

    // Fetch related item IDs
    $itemIds = [];
    $stmtItems = $conn->prepare("SELECT id FROM unassigned_module_items WHERE unassigned_module_id = ?");
    $stmtItems->bind_param("i", $batchId);
    $stmtItems->execute();
    $resItems = $stmtItems->get_result();
    while ($row = $resItems->fetch_assoc()) {
        $itemIds[] = $row['id'];
    }
    $stmtItems->close();

    $deleted_counts = [
        'delivery_pallets' => 0,
        'deliveries' => 0,
        'pallets' => 0,
        'module_items' => 0
    ];

    // If there are item IDs, cascade delete pallets, deliveries, and their associations
    if (!empty($itemIds)) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $types        = str_repeat('i', count($itemIds));
        
        // Get pallet IDs associated with this module batch
        $sqlPal = "SELECT id FROM inventory_pallets WHERE unassigned_module_item_id IN ($placeholders)";
        $stmtPal = $conn->prepare($sqlPal);
        $stmtPal->bind_param($types, ...$itemIds);
        $stmtPal->execute();
        $resPal = $stmtPal->get_result();
        $palletIds = [];
        while ($r = $resPal->fetch_assoc()) {
            $palletIds[] = $r['id'];
        }
        $stmtPal->close();

        // If we have pallets, find and delete associated deliveries
        if (!empty($palletIds)) {
            $ph = implode(',', array_fill(0, count($palletIds), '?'));
            $t  = str_repeat('i', count($palletIds));
            
            // Step 1: Find delivery IDs that use these pallets
            $deliveryIds = [];
            $stmtFindDeliveries = $conn->prepare("SELECT DISTINCT delivery_id FROM delivery_pallets WHERE inventory_pallet_id IN ($ph)");
            $stmtFindDeliveries->bind_param($t, ...$palletIds);
            $stmtFindDeliveries->execute();
            $resDeliveries = $stmtFindDeliveries->get_result();
            while ($row = $resDeliveries->fetch_assoc()) {
                $deliveryIds[] = $row['delivery_id'];
            }
            $stmtFindDeliveries->close();
            
            // Step 2: Delete delivery_pallets records that reference these pallets
            $delDeliveryPallets = $conn->prepare("DELETE FROM delivery_pallets WHERE inventory_pallet_id IN ($ph)");
            $delDeliveryPallets->bind_param($t, ...$palletIds);
            $delDeliveryPallets->execute();
            $deleted_counts['delivery_pallets'] = $delDeliveryPallets->affected_rows;
            $delDeliveryPallets->close();
            
            // Step 3: Delete deliveries that were using these pallets (if they exist)
            if (!empty($deliveryIds)) {
                $deliveryPh = implode(',', array_fill(0, count($deliveryIds), '?'));
                $deliveryT = str_repeat('i', count($deliveryIds));
                
                $delDeliveries = $conn->prepare("DELETE FROM deliveries WHERE id IN ($deliveryPh)");
                $delDeliveries->bind_param($deliveryT, ...$deliveryIds);
                $delDeliveries->execute();
                $deleted_counts['deliveries'] = $delDeliveries->affected_rows;
                $delDeliveries->close();
            }
            
            // Step 4: Delete the inventory_pallets themselves
            $delPal = $conn->prepare("DELETE FROM inventory_pallets WHERE id IN ($ph)");
            $delPal->bind_param($t, ...$palletIds);
            $delPal->execute();
            $deleted_counts['pallets'] = $delPal->affected_rows;
            $delPal->close();
        }
    }

    // Step 5: Delete unassigned_module_items
    $stmtDelItems = $conn->prepare("DELETE FROM unassigned_module_items WHERE unassigned_module_id = ?");
    $stmtDelItems->bind_param("i", $batchId);
    $stmtDelItems->execute();
    $deleted_counts['module_items'] = $stmtDelItems->affected_rows;
    $stmtDelItems->close();

    // Step 6: Delete the module batch itself
    $stmtDelBatch = $conn->prepare("DELETE FROM modules WHERE id = ?");
    $stmtDelBatch->bind_param("i", $batchId);
    $stmtDelBatch->execute();
    $batch_deleted = $stmtDelBatch->affected_rows > 0;
    $stmtDelBatch->close();

    if (!$batch_deleted) {
        throw new Exception("Failed to delete module batch");
    }

    $conn->commit();
    
    // Create detailed success message
    $message_parts = ["Module batch #{$batchId} deleted successfully"];
    if ($deleted_counts['deliveries'] > 0) {
        $message_parts[] = "{$deleted_counts['deliveries']} associated delivery(ies) deleted";
    }
    if ($deleted_counts['pallets'] > 0) {
        $message_parts[] = "{$deleted_counts['pallets']} pallet(s) deleted";
    }
    if ($deleted_counts['module_items'] > 0) {
        $message_parts[] = "{$deleted_counts['module_items']} module item(s) deleted";
    }
    
    $_SESSION['del_message'] = implode(', ', $message_parts) . ".";
    
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['del_message'] = "Error deleting batch: " . $e->getMessage();
}

$conn->close();
header("Location: modules.php");
exit();
?> 