<?php
/**
 * GET /api/audit/session_state.php?session_id=N
 * Returns the full session state payload used by the client to rehydrate
 * the live scanning page after a reload, resume, or offline-return.
 */

require_once __DIR__ . '/../../warehouse_audit_helpers.php';
require_once __DIR__ . '/../../../config.php';

list($user_id, $role) = audit_require_admin_boot();
// GET is idempotent, no CSRF

$conn = getDBConnection();
if (!$conn) {
    audit_json_error('Database connection failed', 500);
}

try {
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    if (!$session_id) {
        audit_json_error('session_id required', 400);
    }
    audit_require_session_access($conn, $session_id, $user_id, $role);

    $state = audit_get_session_state($conn, $session_id);
    if (!$state) {
        audit_json_error('Session not found', 404);
    }
    audit_json_ok($state);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 400);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
