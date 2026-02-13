<?php
session_name("logistics_session");
session_start();

header('Content-Type: application/json');

// Authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Only admins and global_admins can delete schedules
if ($role !== 'admin' && $role !== 'global_admin' && $role !== 'customer_admin') {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit();
}

$project_id = isset($data['project_id']) ? intval($data['project_id']) : 0;

if (!$project_id) {
    echo json_encode(['success' => false, 'message' => 'Project ID is required']);
    exit();
}

// Database connection
require_once '../config.php';

$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Verify user has access to this project
if (in_array($role, ['admin', 'customer_admin'], true)) {
    $stmt = $conn->prepare("
        SELECT p.id 
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ? AND cau.role IN ('admin', 'customer_admin')
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
} else {
    $stmt = $conn->prepare("SELECT id FROM projects WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $project_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Project not found or access denied']);
    exit();
}
$stmt->close();

// Delete schedule (CASCADE will handle details table)
$stmt = $conn->prepare("
    DELETE FROM anticipated_delivery_schedule 
    WHERE project_id = ? AND is_active = 1
");
$stmt->bind_param("i", $project_id);

if ($stmt->execute()) {
    $deleted = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    if ($deleted > 0) {
        echo json_encode([
            'success' => true, 
            'message' => 'Schedule deleted successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'No active schedule found to delete'
        ]);
    }
} else {
    $stmt->close();
    $conn->close();
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to delete schedule'
    ]);
}


