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
    $sql = "SELECT id, name FROM warehouses WHERE (is_port = 0 OR is_port IS NULL) ORDER BY name ASC";
    $stmt = $conn->prepare($sql);
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

