<?php
/**
 * POST /api/audit/complete_session.php
 * Marks a session as completed. Requires that committed_to_inventory_at is set
 * (i.e., commit_inventory.php has run successfully).
 *
 * Required: session_id
 */

require_once __DIR__ . '/../../warehouse_audit_helpers.php';
require_once __DIR__ . '/../../../config.php';

list($user_id, $role) = audit_require_admin_boot();
audit_require_csrf();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    audit_json_error('POST required', 405);
}

$conn = getDBConnection();
if (!$conn) {
    audit_json_error('Database connection failed', 500);
}

try {
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    if (!$session_id) {
        audit_json_error('session_id required', 400);
    }
    $session = audit_require_session_access($conn, $session_id, $user_id, $role);

    if (empty($session['committed_to_inventory_at'])) {
        audit_json_error('Cannot complete session before committing to inventory', 409);
    }

    audit_update_session($conn, $session_id, [
        'status'       => 'completed',
        'completed_at' => date('Y-m-d H:i:s'),
    ]);
    audit_write_session_event($conn, $session_id, $user_id, 'completed', null);

    audit_json_ok(['session_id' => $session_id, 'status' => 'completed']);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 400);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
