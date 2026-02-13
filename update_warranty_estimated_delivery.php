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
$estimatedDate = isset($input['estimated_delivery_date']) ? trim($input['estimated_delivery_date']) : '';

if ($claimId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
    exit();
}

// Validate date format if provided
if ($estimatedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $estimatedDate)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
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

    // First check if the column exists, if not add it
    $checkColumn = $conn->query("SHOW COLUMNS FROM warranty_claims LIKE 'estimated_delivery_date'");
    if ($checkColumn->num_rows === 0) {
        // Add the column
        $alterTable = $conn->query("ALTER TABLE warranty_claims ADD COLUMN estimated_delivery_date DATE NULL AFTER resolution_type");
        if (!$alterTable) {
            throw new Exception('Failed to add estimated_delivery_date column');
        }
    }

    // Update the estimated delivery date
    $stmt = $conn->prepare("UPDATE warranty_claims SET estimated_delivery_date = ? WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare update statement');
    }
    
    $dateValue = $estimatedDate !== '' ? $estimatedDate : null;
    $stmt->bind_param('si', $dateValue, $claimId);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update estimated delivery date');
    }
    $stmt->close();
    
    // Log the update as a warranty event if date was set (not cleared)
    if ($estimatedDate !== '') {
        $eventText = "Estimated delivery date set to: $estimatedDate";
        $eventStmt = $conn->prepare("INSERT INTO warranty_claim_events (claim_id, user_id, event_text, is_public) VALUES (?, ?, ?, 0)");
        if ($eventStmt) {
            $eventStmt->bind_param('iis', $claimId, $userId, $eventText);
            $eventStmt->execute();
            $eventStmt->close();
        }
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $estimatedDate !== '' ? "Estimated delivery date updated to $estimatedDate" : "Estimated delivery date cleared"
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>
