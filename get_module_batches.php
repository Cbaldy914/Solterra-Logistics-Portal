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
    // Fetch module batches for the given project, aggregate wattages
    $sql = "
        SELECT m.id, m.vendor_name,
               COALESCE((SELECT GROUP_CONCAT(DISTINCT umi.wattage ORDER BY umi.wattage SEPARATOR ', ')
                         FROM unassigned_module_items umi
                         WHERE umi.unassigned_module_id = m.id), '') AS wattages
        FROM modules m
        WHERE (? = 0 OR m.project_id = ?)
        ORDER BY m.vendor_name ASC, m.id ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $project_id, $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $label = sprintf("Batch #%d — %s%s", (int)$row['id'], $row['vendor_name'], $row['wattages'] ? " — {$row['wattages']}W" : "");
        $out[] = ['id' => (int)$row['id'], 'name' => $label];
    }
    $stmt->close();
    echo json_encode(['success' => true, 'data' => $out]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed']);
}

$conn->close();
?>

