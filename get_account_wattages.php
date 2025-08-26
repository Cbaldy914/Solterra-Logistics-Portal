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

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$where = [];
$params = [];
$types = '';

if ($user_role === 'global_admin') {
    // No restriction
} else {
    $where[] = "p.account_id IN (SELECT account_id FROM customer_account_users WHERE user_id = ?)";
    $params[] = $user_id;
    $types .= 'i';
}

if ($project_id > 0) {
    $where[] = "p.id = ?";
    $params[] = $project_id;
    $types .= 'i';
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

$sql = "
    SELECT DISTINCT d.wattage
    FROM deliveries d
    JOIN projects p ON p.id = d.project_id
    $where_sql
    AND d.wattage IS NOT NULL
    ORDER BY d.wattage ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query prepare failed']);
    exit();
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$wattages = [];
while ($row = $res->fetch_assoc()) {
    $wattages[] = (int)$row['wattage'];
}
$stmt->close();

echo json_encode(['success' => true, 'data' => $wattages]);
$conn->close();
?>

