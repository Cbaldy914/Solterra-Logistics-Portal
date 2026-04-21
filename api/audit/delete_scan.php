<?php
/**
 * POST /api/audit/delete_scan.php
 * Soft-deletes a scan. Photos remain linked but are hidden from the live view.
 *
 * Required: session_id, scan_id
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
    $scan_id    = isset($_POST['scan_id'])    ? (int)$_POST['scan_id']    : 0;
    if (!$session_id || !$scan_id) {
        audit_json_error('session_id and scan_id required', 400);
    }
    audit_require_session_access($conn, $session_id, $user_id, $role);

    audit_soft_delete_scan($conn, $session_id, $scan_id, $user_id);
    $reconciliation = audit_build_reconciliation($conn, $session_id);

    audit_json_ok(['reconciliation' => $reconciliation]);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 400);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
