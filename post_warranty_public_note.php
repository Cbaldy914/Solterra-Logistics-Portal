<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { header('Location: login'); exit(); }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';
require_once __DIR__ . '/notifications.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) { http_response_code(403); die('Unauthorized'); }

$claimId = isset($_POST['claim_id']) ? (int)$_POST['claim_id'] : 0;
$publicNotes = trim((string)($_POST['public_notes'] ?? ''));
// CSRF check
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    http_response_code(400);
    die('Invalid CSRF token');
}
if ($claimId <= 0 || $publicNotes === '') { header('Location: warranty_detail.php?id=' . $claimId); exit(); }

$conn = getDBConnection();

// ensure user can access claim
$projectId = getClaimProjectId($conn, $claimId);
if ($projectId === null) { $conn->close(); die('Invalid claim'); }
$allowed = getAllowedProjectIds($conn, $userId, $role);
if (is_array($allowed) && !in_array($projectId, $allowed, true)) { $conn->close(); die('Unauthorized'); }

insertEvent($conn, $claimId, $userId, $publicNotes, 1);
setLastPublicUpdateNow($conn, $claimId);
notifyUsers($claimId);

$conn->close();
header('Location: warranty_detail.php?id=' . $claimId);
exit();
?>


