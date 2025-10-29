<?php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    require_once dirname(__DIR__, 3) . '/config.php';
    $conn = getDBConnection();
    if (!$conn) throw new Exception('Database connection failed');

    $userId = intval($_SESSION['user_id']);
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $rating = intval($payload['rating'] ?? 0);
    $comment = trim($payload['comment'] ?? '');
    $conversationId = isset($payload['conversation_id']) ? intval($payload['conversation_id']) : null;
    $frontendMessageId = isset($payload['frontend_message_id']) ? substr($payload['frontend_message_id'], 0, 64) : null;
    $serverMessageId = isset($payload['server_message_id']) ? intval($payload['server_message_id']) : null;

    if (!in_array($rating, [1, -1], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid rating']);
        exit;
    }

    $stmt = $conn->prepare('INSERT INTO sunny_feedback (user_id, conversation_id, frontend_message_id, server_message_id, rating, comment) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('iisiss', $userId, $conversationId, $frontendMessageId, $serverMessageId, $rating, $comment);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>$ok===true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

