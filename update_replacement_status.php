<?php
session_name('logistics_session');
session_start();

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Check authorization (admin/global_admin only)
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit();
}

// CSRF check
if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

$claimId = isset($input['claim_id']) ? (int)$input['claim_id'] : 0;
$newStatus = isset($input['new_status']) ? trim($input['new_status']) : '';

if ($claimId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
    exit();
}

if (empty($newStatus)) {
    echo json_encode(['success' => false, 'message' => 'Status is required']);
    exit();
}

// Validate status
$validStatuses = ['At Manufacturer', 'On Water', 'Cleared Customs', 'In Transit to Warehouse', 'In Warehouse', 'In Transit to Project', 'Delivered to Project'];
if (!in_array($newStatus, $validStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

$conn = getDBConnection();

try {
    // Verify access to claim
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $projectId = getClaimProjectId($conn, $claimId);
    if ($projectId === null) {
        throw new Exception('Invalid claim');
    }
    
    $allowed = getAllowedProjectIds($conn, $userId, $role);
    if (is_array($allowed) && !in_array($projectId, $allowed, true)) {
        throw new Exception('Unauthorized access to claim');
    }

    // Get linked replacement pallets
    $linkedIds = listLinkedReplacementPalletIds($conn, $claimId);
    if (empty($linkedIds)) {
        throw new Exception('No replacement pallets linked to this claim');
    }

    // Update all linked pallets to the new status
    $conn->begin_transaction();
    
    $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
    $types = str_repeat('i', count($linkedIds));
    
    $stmt = $conn->prepare("UPDATE inventory_pallets SET status = ? WHERE id IN ($placeholders)");
    if (!$stmt) {
        throw new Exception('Failed to prepare update statement');
    }
    
    $stmt->bind_param('s' . $types, $newStatus, ...$linkedIds);
    if (!$stmt->execute()) {
        throw new Exception('Failed to update pallet statuses');
    }
    $stmt->close();
    
    // Log the status update as a warranty event
    $eventText = "Replacement pallets updated to status: $newStatus";
    $eventStmt = $conn->prepare("INSERT INTO warranty_claim_events (claim_id, user_id, event_text, is_public) VALUES (?, ?, ?, 1)");
    if ($eventStmt) {
        $eventStmt->bind_param('iis', $claimId, $userId, $eventText);
        $eventStmt->execute();
        $eventStmt->close();
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => "Updated " . count($linkedIds) . " replacement pallet(s) to status: $newStatus"
    ]);

} catch (Exception $e) {
    if ($conn->in_transaction) {
        $conn->rollback();
    }
    
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>
