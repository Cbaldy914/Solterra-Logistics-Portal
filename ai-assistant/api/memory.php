<?php
// Memory Management API
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
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $userId = intval($_SESSION['user_id']);
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'list';

    // Get user's account ID
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $accountId = null;
    if ($row = $result->fetch_assoc()) {
        $accountId = $row['account_id'];
    }
    $stmt->close();

    if ($action === 'list') {
        $stmt = $conn->prepare("
            SELECT id, title, content, memory_type, category, entity_id, importance, created_at, updated_at
            FROM sunny_memory 
            WHERE user_id = ? AND (account_id = ? OR account_id IS NULL)
            ORDER BY importance DESC, created_at DESC
        ");
        $stmt->bind_param('ii', $userId, $accountId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // POST actions
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($action === 'create') {
        $title = trim($payload['title'] ?? '');
        $content = trim($payload['content'] ?? '');
        $importance = intval($payload['importance'] ?? 1);
        
        if (empty($title) || empty($content)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Title and content are required']);
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO sunny_memory (user_id, account_id, memory_type, title, content, importance)
            VALUES (?, ?, 'preference', ?, ?, ?)
        ");
        $stmt->bind_param('iissi', $userId, $accountId, $title, $content, $importance);
        $ok = $stmt->execute();
        $insertId = $conn->insert_id;
        $stmt->close();

        echo json_encode(['success' => $ok, 'insert_id' => $insertId]);
        exit;
    }

    if ($action === 'update') {
        $id = intval($payload['id'] ?? 0);
        $title = isset($payload['title']) ? trim($payload['title']) : null;
        $content = isset($payload['content']) ? trim($payload['content']) : null;
        $importance = isset($payload['importance']) ? intval($payload['importance']) : null;

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid memory ID']);
            exit;
        }

        $updates = [];
        $params = [];
        $types = '';

        if ($title !== null) {
            $updates[] = "title = ?";
            $params[] = $title;
            $types .= 's';
        }
        if ($content !== null) {
            $updates[] = "content = ?";
            $params[] = $content;
            $types .= 's';
        }
        if ($importance !== null) {
            $updates[] = "importance = ?";
            $params[] = $importance;
            $types .= 'i';
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No updates provided']);
            exit;
        }

        $sql = "UPDATE sunny_memory SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?";
        $params[] = $id;
        $params[] = $userId;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => $ok]);
        exit;
    }

    if ($action === 'delete') {
        $id = intval($payload['id'] ?? 0);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid memory ID']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM sunny_memory WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $id, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => $ok]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

