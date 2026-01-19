<?php
/**
 * API: Delete Delivery Projection
 */

session_name("logistics_session");
session_start();

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Check permission
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$projection_id = intval($input['projection_id'] ?? $_GET['projection_id'] ?? 0);

if (!$projection_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Projection ID is required']);
    exit();
}

require_once '../../config.php';
require_once '../projection_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

try {
    // Get projection to verify access and check if primary
    $check_stmt = $conn->prepare("
        SELECT dp.id, dp.project_id, dp.is_primary, dp.projection_name
        FROM delivery_projections dp
        WHERE dp.id = ?
    ");
    $check_stmt->bind_param("i", $projection_id);
    $check_stmt->execute();
    $projection = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if (!$projection) {
        throw new Exception('Projection not found');
    }

    // Verify user access
    $access_check = $conn->prepare("
        SELECT p.id FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ?
    ");
    $access_check->bind_param("ii", $projection['project_id'], $user_id);
    $access_check->execute();
    if ($access_check->get_result()->num_rows === 0 && $role !== 'global_admin') {
        throw new Exception('Access denied');
    }
    $access_check->close();

    // Warn if deleting primary projection
    $was_primary = $projection['is_primary'];

    // Delete projection (related records cascade)
    $success = delete_projection($conn, $projection_id);

    if (!$success) {
        throw new Exception('Failed to delete projection');
    }

    // If was primary, set another projection as primary
    if ($was_primary) {
        $new_primary = $conn->prepare("
            SELECT id FROM delivery_projections
            WHERE project_id = ? AND is_template = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $new_primary->bind_param("i", $projection['project_id']);
        $new_primary->execute();
        $new_primary_result = $new_primary->get_result()->fetch_assoc();
        $new_primary->close();

        if ($new_primary_result) {
            update_projection($conn, $new_primary_result['id'], ['is_primary' => true]);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Projection "' . $projection['projection_name'] . '" deleted successfully'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
