<?php
/**
 * POST /api/audit/commit_inventory.php
 * Atomically commits all verified (non-discrepancy) scans from a session
 * into inventory_pallets. Returns counts and skip reasons.
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

    // Block commit if any equipment row is still in 'not_verified' state.
    // Auditor must explicitly mark as missing/damaged/etc. to proceed.
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM warehouse_audit_equipment_checks WHERE session_id = ? AND status = 'not_verified'");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['n'] > 0) {
        audit_json_error(
            'Cannot commit while ' . (int)$row['n'] . ' equipment item(s) are still marked not_verified. Mark each explicitly as verified, missing, damaged, or wrong_model first.',
            409
        );
    }

    $result = audit_commit_scans_to_inventory($conn, $session_id, $user_id);
    audit_json_ok($result);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 500);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
