<?php
/**
 * API: Link General Projection to Project
 * Associates a general projection with a real project
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

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
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

// Handle list_projects action
if (!empty($input['action']) && $input['action'] === 'list_projects') {
    $projects = [];
    if ($role === 'global_admin') {
        $stmt = $conn->prepare("
            SELECT p.id, p.project_name, p.project_address
            FROM projects p
            WHERE p.status IS NULL OR p.status = 'active'
            ORDER BY p.project_name ASC
        ");
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("
            SELECT p.id, p.project_name, p.project_address
            FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE cau.user_id = ? AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
    $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => true, 'projects' => $projects]);
    exit();
}

// Link projection to project
$projection_id = intval($input['projection_id'] ?? 0);
$project_id = intval($input['project_id'] ?? 0);

if (!$projection_id || !$project_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'projection_id and project_id are required']);
    exit();
}

try {
    // Verify the projection exists and is general
    $proj_check = $conn->prepare("SELECT id, created_by, is_general FROM delivery_projections WHERE id = ?");
    $proj_check->bind_param("i", $projection_id);
    $proj_check->execute();
    $projection = $proj_check->get_result()->fetch_assoc();
    $proj_check->close();

    if (!$projection || !$projection['is_general']) {
        throw new Exception('Projection not found or is not a general projection');
    }

    // Verify user has access to the target project
    if ($role !== 'global_admin') {
        $access = $conn->prepare("
            SELECT p.id FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE p.id = ? AND cau.user_id = ?
        ");
        $access->bind_param("ii", $project_id, $user_id);
        $access->execute();
        if ($access->get_result()->num_rows === 0) {
            throw new Exception('Access denied to target project');
        }
        $access->close();
    }

    // Perform the link
    $result = link_general_to_project($conn, $projection_id, $project_id);

    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'redirect_url' => "anticipated_deliveries.php?project_id={$project_id}&projection_id={$projection_id}"
        ]);
    } else {
        throw new Exception($result['error'] ?? 'Failed to link projection');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
