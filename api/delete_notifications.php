<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../notifications");
    exit();
}

if (
    empty($_SESSION['csrf_token']) ||
    !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])
) {
    $_SESSION['error_message'] = 'Invalid request token';
    header("Location: ../notifications");
    exit();
}

require_once '../../config.php';

$user_id = $_SESSION['user_id'];
$ids = $_POST['ids'] ?? [];

if (empty($ids)) {
    $_SESSION['error_message'] = 'No notifications selected';
    header("Location: ../notifications");
    exit();
}

$conn = getDBConnection();

try {
    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $stmt = $conn->prepare("
        DELETE FROM notifications 
        WHERE user_id = ? 
        AND id IN ($placeholders)
    ");
    
    // Bind parameters: first the user_id, then all the ids
    $types = 'i' . str_repeat('i', count($ids));
    $params = array_merge([$user_id], $ids);
    $stmt->bind_param($types, ...$params);
    
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    $_SESSION['success_message'] = "{$affected} notification(s) deleted";
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Failed to delete notifications';
    error_log('Delete notifications error: ' . $e->getMessage());
}

$conn->close();
header("Location: ../notifications");
exit();

