<?php
header('Content-Type: application/json');
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false]); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit(); }

$payload = json_decode(file_get_contents('php://input'), true);
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
$requestCsrf = (string)($payload['csrf_token'] ?? '');
if ($sessionCsrf === '' || $requestCsrf === '' || !hash_equals($sessionCsrf, $requestCsrf)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Invalid request token']);
    exit();
}
$token = preg_replace('/[^A-Za-z0-9_-]/','', $payload['token'] ?? '');
$name = basename($payload['name'] ?? '');
if ($token === '' || $name === '') { echo json_encode(['success'=>false]); exit(); }
$path = __DIR__ . '/uploads/tmp_photos/' . $token . '/' . $name;
if (is_file($path)) @unlink($path);
echo json_encode(['success'=>true]);
exit();
?>
