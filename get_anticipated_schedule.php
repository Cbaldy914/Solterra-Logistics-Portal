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

// Get project ID from query string
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$chart_data = isset($_GET['chart_data']) ? true : false;

if (!$project_id) {
    echo json_encode(['success' => false, 'message' => 'Project ID is required']);
    exit();
}

// Database connection
require_once '../config.php';
require_once 'anticipated_schedule_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Verify user has access to this project
if ($role === 'admin') {
    $stmt = $conn->prepare("
        SELECT p.id 
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
} elseif ($role === 'global_admin') {
    $stmt = $conn->prepare("SELECT id FROM projects WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $project_id);
} else {
    // Regular user - verify they have access
    $stmt = $conn->prepare("
        SELECT p.id 
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
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

// Get schedule data
$schedule = getActiveSchedule($conn, $project_id);

if (!$schedule) {
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'No active schedule found']);
    exit();
}

// If chart data is requested, generate it
if ($chart_data) {
    $chart_data_result = generateAnticipatedDeliveriesFromSchedule($conn, $project_id);
    $conn->close();

    echo json_encode([
        'success' => true,
        'schedule' => $schedule,
        'chart_data' => $chart_data_result
    ]);
} else {
    $conn->close();
    echo json_encode([
        'success' => true,
        'schedule' => $schedule
    ]);
}


