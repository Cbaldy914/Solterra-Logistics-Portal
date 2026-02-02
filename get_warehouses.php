<?php
session_name("logistics_session");
session_start();

header('Content-Type: application/json');

// Only allow logged in users
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

try {
    // Return all non-port warehouses ordered by name
    $role = $_SESSION['role'] ?? '';
    $user_id = $_SESSION['user_id'] ?? 0;
    $account_id = null;
    if ($role !== 'global_admin') {
        $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
        if ($stmtAccount) {
            $stmtAccount->bind_param("i", $user_id);
            $stmtAccount->execute();
            $stmtAccount->bind_result($account_id);
            $stmtAccount->fetch();
            $stmtAccount->close();
        }
        if (!$account_id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Account access denied']);
            exit();
        }
    }

    $account_clause = $role === 'global_admin' ? '' : ' AND account_id = ?';
    $sql = "SELECT id, name FROM warehouses WHERE (is_port = 0 OR is_port IS NULL)$account_clause ORDER BY name ASC";
    $stmt = $conn->prepare($sql);
    if ($role !== 'global_admin') {
        $stmt->bind_param("i", $account_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'name' => $row['name']
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $items]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed']);
}

$conn->close();
?>

