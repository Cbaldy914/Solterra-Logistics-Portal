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

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

$project_id = intval($_GET['project_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

if ($project_id <= 0 || $q === '') {
    echo json_encode(['success' => true, 'deliveries' => []]);
    exit();
}

try {
    $sql = "
        SELECT d.id, d.container_number, d.supplier, d.status_of_delivery as status, w.name as warehouse_name
        FROM deliveries d
        LEFT JOIN warehouses w ON d.warehouse_id = w.id
        WHERE d.project_id = ? AND d.container_number LIKE ?
        ORDER BY d.container_number ASC
        LIMIT 20
    ";
    $like = '%' . $q . '%';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $project_id, $like);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'deliveries' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed']);
}

$conn->close();
?>

