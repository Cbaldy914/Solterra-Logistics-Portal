<?php
header('Content-Type: application/json');
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false]); exit(); }

$payload = json_decode(file_get_contents('php://input'), true);
$token = preg_replace('/[^A-Za-z0-9_-]/','', $payload['token'] ?? '');
$name = basename($payload['name'] ?? '');
if ($token === '' || $name === '') { echo json_encode(['success'=>false]); exit(); }
$path = __DIR__ . '/uploads/tmp_photos/' . $token . '/' . $name;
if (is_file($path)) @unlink($path);
echo json_encode(['success'=>true]);
exit();
?>

