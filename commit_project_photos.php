<?php
header('Content-Type: application/json');
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Not logged in']); exit(); }
$user_id = intval($_SESSION['user_id']);
$role = $_SESSION['role'] ?? 'user';

require_once '../config.php';
require_once 'document_helpers.php';
$conn = getDBConnection();
if (!$conn) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB connection failed']); exit(); }

$payload = json_decode(file_get_contents('php://input'), true);
$project_id = intval($payload['project_id'] ?? 0);
$token = preg_replace('/[^A-Za-z0-9_-]/','', $payload['token'] ?? '');
$order = $payload['order'] ?? [];
if ($project_id <= 0 || !is_array($order)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid input']); exit(); }

// Access check
if ($role === 'global_admin') {
  $stmt = $conn->prepare('SELECT id FROM projects WHERE id = ?');
  $stmt->bind_param('i', $project_id);
} else {
  $stmt = $conn->prepare('SELECT p.id FROM projects p JOIN customer_account_users cau ON p.account_id = cau.account_id WHERE p.id = ? AND cau.user_id = ?');
  $stmt->bind_param('ii', $project_id, $user_id);
}
$stmt->execute(); $res = $stmt->get_result(); $stmt->close();
if (!$res || $res->num_rows === 0) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'No access to project']); exit(); }

$temp_dir = __DIR__ . '/uploads/tmp_photos/' . $token;
// Map temp files to new doc IDs
$id_map = [];
foreach ($order as $idx => $entry) {
  if (strpos($entry, 'temp:') === 0) {
    $name = substr($entry, 5);
    $src = $temp_dir . '/' . $name;
    if (is_file($src)) {
      try {
        $doc = [
          'project_id' => $project_id,
          'document_type' => 'pictures',
          'document_sub_type' => 'Project Photo',
          'original_name' => $name,
          'uploaded_by' => $user_id,
          'description' => 'Project Photo'
        ];
        $resImp = importExistingFileToProjectDocuments($conn, $doc, $src);
        $id_map[$name] = intval($resImp['document_id']);
      } catch (Exception $e) {
        // skip failed import
      }
    }
  }
}

// Build final ordered IDs
$final_ids = [];
foreach ($order as $entry) {
  if (strpos($entry, 'id:') === 0) {
    $final_ids[] = intval(substr($entry, 3));
  } elseif (strpos($entry, 'temp:') === 0) {
    $name = substr($entry, 5);
    if (isset($id_map[$name])) $final_ids[] = $id_map[$name];
  }
}

// Save order JSON
$dir = __DIR__ . "/uploads/project_documents/{$project_id}/pictures";
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
@file_put_contents($dir . '/.order.json', json_encode($final_ids));

// Update cover image to first in order
if (!empty($final_ids)) {
  $fid = $final_ids[0];
  $q = $conn->prepare('SELECT file_path FROM project_documents WHERE id = ? AND project_id = ? AND is_active = 1');
  $q->bind_param('ii', $fid, $project_id); $q->execute(); $rs = $q->get_result(); $row = $rs->fetch_assoc(); $q->close();
  if ($row && !empty($row['file_path'])) {
    $p = $conn->prepare('UPDATE projects SET image_url = ? WHERE id = ?');
    $p->bind_param('si', $row['file_path'], $project_id); $p->execute(); $p->close();
  }
}

// Cleanup temp dir
if (is_dir($temp_dir)) {
  foreach (glob($temp_dir.'/*') as $f) { @unlink($f); }
  @rmdir($temp_dir);
}

echo json_encode(['success'=>true]);
$conn->close();
exit();
?>

