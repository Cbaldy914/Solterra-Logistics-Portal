<?php
session_name("logistics_session");
session_start();

// Ensure authorized
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
require_once __DIR__ . '/module_reconciliation_audit_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

function syncProjectWattageOrdersCanonical($conn, $projectId) {
    $projectId = (int)$projectId;
    if ($projectId <= 0) {
        return;
    }

    $totals = [];
    $stmtTotals = $conn->prepare("
        SELECT CAST(umi.wattage AS CHAR) AS wattage_key, SUM(umi.quantity) AS total_qty
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ? AND umi.wattage > 0 AND umi.quantity > 0
        GROUP BY umi.wattage
    ");
    if (!$stmtTotals) {
        throw new Exception('Failed preparing canonical totals query: ' . $conn->error);
    }
    $stmtTotals->bind_param('i', $projectId);
    $stmtTotals->execute();
    $resTotals = $stmtTotals->get_result();
    while ($row = $resTotals->fetch_assoc()) {
        $wattage = (string)($row['wattage_key'] ?? '');
        $qty = (int)($row['total_qty'] ?? 0);
        if ($wattage === '' || $qty <= 0) { continue; }
        $totals[$wattage] = $qty;
    }
    $stmtTotals->close();

    $stmtDelete = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ?");
    if (!$stmtDelete) {
        throw new Exception('Failed preparing project_wattage_orders cleanup: ' . $conn->error);
    }
    $stmtDelete->bind_param('i', $projectId);
    $stmtDelete->execute();
    $stmtDelete->close();

    if (!empty($totals)) {
        $stmtInsert = $conn->prepare("INSERT INTO project_wattage_orders (project_id, wattage, total_order) VALUES (?, ?, ?)");
        if (!$stmtInsert) {
            throw new Exception('Failed preparing project_wattage_orders insert: ' . $conn->error);
        }
        foreach ($totals as $wattage => $qty) {
            $stmtInsert->bind_param('isi', $projectId, $wattage, $qty);
            $stmtInsert->execute();
        }
        $stmtInsert->close();
    }
}

// Validate POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['batch_id'])) {
    header("Location: unassigned_modules.php");
    exit();
}
$batchId = intval($_POST['batch_id']);
$actorUserId = (int)($_SESSION['user_id'] ?? 0);
$actorRole = (string)($_SESSION['role'] ?? '');

try {
    $conn->begin_transaction();
    $auditMetaBeforeDelete = mr_get_module_batch_meta($conn, $batchId);
    $auditRowsBeforeDelete = mr_get_module_batch_rows($conn, $batchId);

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

    // Get project linkage and possible docs path
    $projId = null; $moduleDocsUrl = null;
    if ($stmtMeta = $conn->prepare("SELECT project_id, module_docs_url FROM modules WHERE id = ?")) {
        $stmtMeta->bind_param("i", $batchId);
        $stmtMeta->execute();
        $stmtMeta->bind_result($pid, $docs);
        if ($stmtMeta->fetch()) { $projId = $pid ? (int)$pid : null; $moduleDocsUrl = $docs; }
        $stmtMeta->close();
    }

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
            
            // Step 1: Guardrail - block delete if any pallets are linked to deliveries
            $deliveryIds = [];
            $stmtFindDeliveries = $conn->prepare("SELECT DISTINCT delivery_id FROM delivery_pallets WHERE inventory_pallet_id IN ($ph)");
            $stmtFindDeliveries->bind_param($t, ...$palletIds);
            $stmtFindDeliveries->execute();
            $resDeliveries = $stmtFindDeliveries->get_result();
            while ($row = $resDeliveries->fetch_assoc()) {
                $deliveryIds[] = $row['delivery_id'];
            }
            $stmtFindDeliveries->close();

            // Step 2: Guardrail - block delete if any pallets are linked to warranty replacements
            $warrantyLinkedPallets = [];
            $stmtWarrantyLinks = $conn->prepare("SELECT DISTINCT pallet_id FROM warranty_claim_replacements WHERE pallet_id IN ($ph)");
            $stmtWarrantyLinks->bind_param($t, ...$palletIds);
            $stmtWarrantyLinks->execute();
            $resWarrantyLinks = $stmtWarrantyLinks->get_result();
            while ($row = $resWarrantyLinks->fetch_assoc()) {
                $warrantyLinkedPallets[] = (int)$row['pallet_id'];
            }
            $stmtWarrantyLinks->close();

            if (!empty($deliveryIds) || !empty($warrantyLinkedPallets)) {
                $detailParts = [];
                if (!empty($deliveryIds)) {
                    $detailParts[] = count($deliveryIds) . ' delivery link(s)';
                }
                if (!empty($warrantyLinkedPallets)) {
                    $detailParts[] = count($warrantyLinkedPallets) . ' warranty replacement link(s)';
                }
                throw new Exception(
                    "Cannot delete module batch #{$batchId}: associated pallets are linked downstream (" .
                    implode(', ', $detailParts) .
                    "). Remove downstream links first."
                );
            }
            
            // Step 3: Safe to delete the inventory_pallets themselves
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

    if (!empty($projId) && $projId > 0) {
        syncProjectWattageOrdersCanonical($conn, $projId);
    }

    mr_insert_reconciliation_audit($conn, [
        'module_batch_id' => $batchId,
        'project_id' => (int)($auditMetaBeforeDelete['project_id'] ?? 0),
        'account_id' => (int)($auditMetaBeforeDelete['account_id'] ?? 0),
        'action_type' => 'batch_delete',
        'reason' => 'Batch deleted via delete_module_batch.php',
        'reconciliation_mode' => 'n/a',
        'preview_signature' => '',
        'actor_user_id' => $actorUserId,
        'actor_role' => $actorRole,
        'source_page' => 'delete_module_batch.php',
        'before_state' => [
            'module' => $auditMetaBeforeDelete,
            'rows' => $auditRowsBeforeDelete
        ],
        'after_state' => null,
        'impact' => [
            'deleted_counts' => $deleted_counts
        ]
    ]);

    // Step 7: Remove projection allocations tied to this batch and clear logistics if empty
    $projection_ids = [];
    if ($stmtProj = $conn->prepare("SELECT DISTINCT projection_id FROM projection_module_allocations WHERE module_id = ? AND is_projection_module = 0")) {
        $stmtProj->bind_param("i", $batchId);
        $stmtProj->execute();
        $resProj = $stmtProj->get_result();
        while ($row = $resProj->fetch_assoc()) {
            $projection_ids[] = (int)$row['projection_id'];
        }
        $stmtProj->close();
    }

    if (!empty($projection_ids)) {
        if ($stmtDelAlloc = $conn->prepare("DELETE FROM projection_module_allocations WHERE module_id = ? AND is_projection_module = 0")) {
            $stmtDelAlloc->bind_param("i", $batchId);
            $stmtDelAlloc->execute();
            $stmtDelAlloc->close();
        }

        if ($stmtCount = $conn->prepare("SELECT COUNT(*) FROM projection_module_allocations WHERE projection_id = ?")) {
            foreach ($projection_ids as $projection_id) {
                $stmtCount->bind_param("i", $projection_id);
                $stmtCount->execute();
                $stmtCount->bind_result($alloc_count);
                $stmtCount->fetch();
                $stmtCount->free_result();

                if ((int)$alloc_count === 0) {
                    // Clear logistics data for empty projections
                    $conn->query("DELETE FROM projection_stop_fees WHERE stop_id IN (SELECT id FROM projection_stops WHERE projection_id = " . (int)$projection_id . ")");
                    $clear_legs = $conn->prepare("DELETE FROM projection_legs WHERE projection_id = ?");
                    if ($clear_legs) {
                        $clear_legs->bind_param("i", $projection_id);
                        $clear_legs->execute();
                        $clear_legs->close();
                    }
                    $clear_stops = $conn->prepare("DELETE FROM projection_stops WHERE projection_id = ?");
                    if ($clear_stops) {
                        $clear_stops->bind_param("i", $projection_id);
                        $clear_stops->execute();
                        $clear_stops->close();
                    }
                    $clear_summary = $conn->prepare("DELETE FROM projection_cost_summary WHERE projection_id = ?");
                    if ($clear_summary) {
                        $clear_summary->bind_param("i", $projection_id);
                        $clear_summary->execute();
                        $clear_summary->close();
                    }
                }
            }
            $stmtCount->close();
        }
    }

    // Best-effort: remove uploaded docs folder for this batch if it exists and is not a project document
    if (!empty($moduleDocsUrl) && strpos($moduleDocsUrl, 'uploads/module_batches/') === 0) {
        $baseDir = dirname($moduleDocsUrl);
        if (is_dir($baseDir)) {
            foreach (glob($baseDir . '/*') as $f) { @unlink($f); }
            @rmdir($baseDir);
        } else {
            @unlink($moduleDocsUrl);
        }
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
