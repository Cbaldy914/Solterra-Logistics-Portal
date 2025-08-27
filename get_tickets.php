<?php
session_name("logistics_session");
session_start();

header('Content-Type: application/json');

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

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

try {
    $sql = "SELECT w.id
            FROM warranty_claims w
            JOIN site_scheduling ss ON ss.id = w.scheduling_id
            WHERE (? = 0 OR ss.project_id = ?)
            ORDER BY w.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $project_id, $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [ 'id' => (int)$row['id'], 'name' => (string)$row['id'] ];
    }
    $stmt->close();
    echo json_encode(['success' => true, 'data' => $out]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed']);
}

$conn->close();
?>

