<?php
header('Content-Type: application/json');
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'message'=>'Not logged in']);
  exit();
}
$user_id = intval($_SESSION['user_id']);
$role = $_SESSION['role'] ?? 'user';

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB connection failed']); exit(); }

$payload = json_decode(file_get_contents('php://input'), true);
$project_id = intval($payload['project_id'] ?? 0);
$order = $payload['order'] ?? [];

if ($project_id <= 0 || !is_array($order)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid input']); exit(); }

// Access check: global_admin or admin of account
if ($role === 'global_admin') {
  $stmt = $conn->prepare('SELECT id FROM projects WHERE id = ?');
  $stmt->bind_param('i', $project_id);
} else {
  $stmt = $conn->prepare('SELECT p.id FROM projects p JOIN customer_account_users cau ON p.account_id = cau.account_id WHERE p.id = ? AND cau.user_id = ? AND cau.role IN (\'admin\',\'user\')');
  $stmt->bind_param('ii', $project_id, $user_id);
}
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
if (!$res || $res->num_rows === 0) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'No access to project']); exit(); }

// Persist order JSON
$dir = __DIR__ . "/uploads/project_documents/{$project_id}/pictures";
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$path = $dir . '/.order.json';
@file_put_contents($path, json_encode(array_map('intval',$order)));

// Update cover image to first in order
if (!empty($order) && intval($order[0]) > 0) {
  $first_id = intval($order[0]);
  $q = $conn->prepare('SELECT file_path FROM project_documents WHERE id = ? AND project_id = ? AND is_active = 1');
  $q->bind_param('ii', $first_id, $project_id);
  $q->execute();
  $rs = $q->get_result();
  $row = $rs->fetch_assoc();
  $q->close();
  if ($row && !empty($row['file_path'])) {
    $p = $conn->prepare('UPDATE projects SET image_url = ? WHERE id = ?');
    $p->bind_param('si', $row['file_path'], $project_id);
    $p->execute();
    $p->close();
  }
}

echo json_encode(['success'=>true]);
$conn->close();
exit();
?>

