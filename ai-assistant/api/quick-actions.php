<?php
// Quick Actions API (per-user)
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
    $action = $_GET['action'] ?? ($method === 'GET' ? 'list' : 'save');

    if ($action === 'list') {
        $stmt = $conn->prepare("SELECT id, label, message, position FROM sunny_quick_actions WHERE user_id = ? ORDER BY position ASC, id ASC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();

        if (empty($rows)) {
            // Fallback to defaults
            $defaults = [
                ['label' => 'Recent Deliveries', 'message' => 'Show my recent deliveries', 'position' => 0],
                ['label' => 'Project Status', 'message' => 'Show the status of my active projects', 'position' => 1],
                ['label' => 'Inventory Summary', 'message' => 'Show my inventory summary', 'position' => 2],
            ];
            echo json_encode(['success' => true, 'data' => $defaults]);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // Parse JSON body for mutations
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: [];

    if ($action === 'save') {
        $id = isset($payload['id']) ? intval($payload['id']) : null;
        $label = trim($payload['label'] ?? '');
        $message = trim($payload['message'] ?? '');
        $position = isset($payload['position']) ? max(0, min(4, intval($payload['position']))) : 0;

        if ($label === '' || mb_strlen($label) > 20) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Label is required and must be ≤ 20 characters']);
            exit;
        }
        if ($message === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Message is required']);
            exit;
        }

        // Count existing actions
        $countRes = $conn->prepare('SELECT COUNT(*) AS c FROM sunny_quick_actions WHERE user_id = ?');
        $countRes->bind_param('i', $userId);
        $countRes->execute();
        $cnt = $countRes->get_result()->fetch_assoc()['c'] ?? 0;
        $countRes->close();

        if ($id === null && $cnt >= 5) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You can have at most 5 quick actions']);
            exit;
        }

        if ($id) {
            // Update existing (ensure ownership)
            $stmt = $conn->prepare('UPDATE sunny_quick_actions SET label = ?, message = ?, position = ? WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ssiii', $label, $message, $position, $id, $userId);
            $ok = $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => $ok === true]);
            exit;
        } else {
            $stmt = $conn->prepare('INSERT INTO sunny_quick_actions (user_id, label, message, position) VALUES (?,?,?,?)');
            $stmt->bind_param('issi', $userId, $label, $message, $position);
            $ok = $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();
            echo json_encode(['success' => $ok === true, 'id' => $newId]);
            exit;
        }
    }

    if ($action === 'delete') {
        $id = intval($payload['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid id']);
            exit;
        }
        $stmt = $conn->prepare('DELETE FROM sunny_quick_actions WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok === true]);
        exit;
    }

    // If reached here, bad request
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported action']);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

