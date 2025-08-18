<?php
// Delete one or more project documents (soft delete + remove file)
// Only accessible by admin and global_admin

header('Content-Type: application/json');

session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$userId = intval($_SESSION['user_id']);
$role = $_SESSION['role'];

$payload = json_decode(file_get_contents('php://input'), true);
$ids = $payload['ids'] ?? [];

if (!is_array($ids) || count($ids) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No document IDs provided']);
    exit();
}

// Normalize to integers and unique
$ids = array_values(array_unique(array_map('intval', $ids)));

$deleted = [];
$errors = [];

foreach ($ids as $docId) {
    if ($docId <= 0) { continue; }

    // Fetch document
    $stmt = $conn->prepare("SELECT id, project_id, file_path FROM project_documents WHERE id = ? AND is_active = 1");
    if (!$stmt) { $errors[] = [ 'id' => $docId, 'error' => 'Query prepare failed' ]; continue; }
    $stmt->bind_param('i', $docId);
    $stmt->execute();
    $result = $stmt->get_result();
    $doc = $result->fetch_assoc();
    $stmt->close();

    if (!$doc) { $errors[] = [ 'id' => $docId, 'error' => 'Document not found or already deleted' ]; continue; }

    // Access check for admin (global_admin can delete any)
    if ($role === 'admin') {
        $accessStmt = $conn->prepare("SELECT p.id FROM projects p JOIN customer_account_users cau ON p.account_id = cau.account_id WHERE p.id = ? AND cau.user_id = ? AND cau.role = 'admin'");
        $projectId = intval($doc['project_id']);
        $accessStmt->bind_param('ii', $projectId, $userId);
        $accessStmt->execute();
        $accessRes = $accessStmt->get_result();
        $hasAccess = $accessRes && $accessRes->num_rows > 0;
        $accessStmt->close();
        if (!$hasAccess) { $errors[] = [ 'id' => $docId, 'error' => 'No access to this project' ]; continue; }
    }

    // Soft delete in DB
    $upd = $conn->prepare("UPDATE project_documents SET is_active = 0 WHERE id = ?");
    $upd->bind_param('i', $docId);
    $ok = $upd->execute();
    $upd->close();

    if (!$ok) { $errors[] = [ 'id' => $docId, 'error' => 'Failed to update database' ]; continue; }

    // Attempt to remove file from disk (best-effort)
    $path = $doc['file_path'];
    $candidates = [$path, __DIR__ . '/' . ltrim($path, '/\\')];
    foreach ($candidates as $candidate) {
        if ($candidate && @is_file($candidate)) {
            @unlink($candidate);
            break;
        }
    }

    $deleted[] = $docId;
}

echo json_encode([
    'success' => count($deleted) > 0,
    'deleted_ids' => $deleted,
    'failed' => $errors,
]);

$conn->close();
exit();
?>


