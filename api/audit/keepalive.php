<?php
/**
 * GET /api/audit/keepalive.php?session_id=N
 * Called by the live session page every ~2 minutes to keep the PHP session
 * alive while the auditor is on the phone for a long sweep.
 */

require_once __DIR__ . '/../../warehouse_audit_helpers.php';
require_once __DIR__ . '/../../../config.php';

list($user_id, $role) = audit_require_admin_boot();

$conn = getDBConnection();
if (!$conn) {
    audit_json_error('Database connection failed', 500);
}

try {
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    if (!$session_id) {
        audit_json_error('session_id required', 400);
    }
    $session = audit_require_session_access($conn, $session_id, $user_id, $role);
    // Touch the updated_at via a no-op update to nudge the row
    $stmt = $conn->prepare("UPDATE warehouse_audit_sessions SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $stmt->close();

    audit_json_ok([
        'session_id' => $session_id,
        'status'     => $session['status'],
        'timestamp'  => date('c'),
    ]);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 400);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
