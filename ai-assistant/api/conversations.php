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
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $stmt = $conn->prepare("SELECT id, title, started_at, last_message_at FROM sunny_conversations WHERE user_id = ? AND is_archived = 0 AND last_message_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY last_message_at DESC LIMIT 100");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    if ($action === 'messages') {
        $cid = intval($_GET['conversation_id'] ?? 0);
        if ($cid <= 0) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid id']); exit; }
        $stmt = $conn->prepare("SELECT id FROM sunny_conversations WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $cid, $userId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$ok) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); exit; }
        $stmt = $conn->prepare("SELECT role, content, created_at FROM sunny_messages WHERE conversation_id = ? ORDER BY created_at ASC, id ASC LIMIT 500");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $res = $stmt->get_result();
        $msgs = [];
        while ($m = $res->fetch_assoc()) { $msgs[] = $m; }
        $stmt->close();
        echo json_encode(['success'=>true,'data'=>$msgs]);
        exit;
    }

    // JSON payload for post actions
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($action === 'create') {
        $title = trim($payload['title'] ?? 'New chat');
        $stmt = $conn->prepare("INSERT INTO sunny_conversations (user_id, title) VALUES (?, ?)");
        $stmt->bind_param('is', $userId, $title);
        $ok = $stmt->execute();
        $cid = $conn->insert_id;
        $stmt->close();
        if ($ok) $_SESSION['sunny_active_conversation_id'] = $cid;
        echo json_encode(['success'=>$ok===true, 'id'=>$cid]);
        exit;
    }

    if ($action === 'set-active') {
        $cid = intval($payload['conversation_id'] ?? 0);
        if ($cid <= 0) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid id']); exit; }
        $stmt = $conn->prepare("SELECT id FROM sunny_conversations WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $cid, $userId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$ok) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); exit; }
        $_SESSION['sunny_active_conversation_id'] = $cid;
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'rename') {
        $cid = intval($payload['conversation_id'] ?? 0);
        $title = trim($payload['title'] ?? '');
        if ($cid <= 0 || $title === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
        $stmt = $conn->prepare("UPDATE sunny_conversations SET title = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param('sii', $title, $cid, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success'=>$ok===true]);
        exit;
    }

    if ($action === 'delete') {
        $cid = intval($payload['conversation_id'] ?? 0);
        if ($cid <= 0) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid id']); exit; }
        $stmt = $conn->prepare("DELETE FROM sunny_conversations WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $cid, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!empty($_SESSION['sunny_active_conversation_id']) && intval($_SESSION['sunny_active_conversation_id']) === $cid) {
            unset($_SESSION['sunny_active_conversation_id']);
        }
        echo json_encode(['success'=>$ok===true]);
        exit;
    }

    if ($action === 'get-active') {
        $activeId = intval($_SESSION['sunny_active_conversation_id'] ?? 0);
        if ($activeId > 0) {
            $stmt = $conn->prepare("SELECT id FROM sunny_conversations WHERE id = ? AND user_id = ? AND is_archived = 0 LIMIT 1");
            $stmt->bind_param('ii', $activeId, $userId);
            $stmt->execute();
            $isValid = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if (!$isValid) {
                $activeId = 0;
                unset($_SESSION['sunny_active_conversation_id']);
            }
        }

        echo json_encode(['success'=>true, 'conversation_id'=>($activeId > 0 ? $activeId : null)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Unsupported action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
?>

