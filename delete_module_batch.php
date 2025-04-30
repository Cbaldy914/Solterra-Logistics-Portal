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

    // If there are item IDs, delete their pallets and movement links
    if (!empty($itemIds)) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $types        = str_repeat('i', count($itemIds));
        // Get pallet IDs
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

        // Delete associated pallets if they exist
        if (!empty($palletIds)) {
            $ph = implode(',', array_fill(0, count($palletIds), '?'));
            $t  = str_repeat('i', count($palletIds));
            $delPal = $conn->prepare("DELETE FROM inventory_pallets WHERE id IN ($ph)");
            $delPal->bind_param($t, ...$palletIds);
            $delPal->execute();
            $delPal->close();
        }
    }

    // Delete items
    $stmtDelItems = $conn->prepare("DELETE FROM unassigned_module_items WHERE unassigned_module_id = ?");
    $stmtDelItems->bind_param("i", $batchId);
    $stmtDelItems->execute();
    $stmtDelItems->close();

    // Delete batch
    $stmtDelBatch = $conn->prepare("DELETE FROM modules WHERE id = ?");
    $stmtDelBatch->bind_param("i", $batchId);
    $stmtDelBatch->execute();
    $stmtDelBatch->close();

    $conn->commit();
    $_SESSION['del_message'] = "Batch #{$batchId} deleted successfully.";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['del_message'] = "Error deleting batch: " . $e->getMessage();
}

$conn->close();
header("Location: modules.php");
exit();
?> 