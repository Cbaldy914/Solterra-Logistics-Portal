<?php
// Upload pre-creation project photos to a temporary folder keyed by a token
header('Content-Type: application/json');
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Not logged in']); exit(); }

$token = isset($_POST['token']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['token']) : '';
if ($token === '') { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Missing token']); exit(); }

// Ensure upload exists
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400); echo json_encode(['success'=>false,'message'=>'No file or upload error']); exit();
}

// Validate image
$allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);
if (!isset($allowed[$mime])) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid image type']); exit(); }
if ($_FILES['file']['size'] > 10*1024*1024) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Image too large (10MB max)']); exit(); }

// Save to temp dir
$dir = __DIR__ . "/uploads/tmp_photos/{$token}";
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
// Use timestamp + random to preserve client-side order if needed
$ext = $allowed[$mime];
$safeBase = preg_replace('/[^A-Za-z0-9_.-]/','_', pathinfo($_FILES['file']['name'], PATHINFO_FILENAME));
$filename = time() . '_' . substr(md5(uniqid()),0,6) . '_' . $safeBase . '.' . $ext;
$dest = $dir . '/' . $filename;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Failed to save file']); exit(); }

// Public path
$publicPath = 'uploads/tmp_photos/' . $token . '/' . $filename;
echo json_encode(['success'=>true, 'file'=>[ 'name'=>$filename, 'path'=>$publicPath ]]);
exit();
?>

