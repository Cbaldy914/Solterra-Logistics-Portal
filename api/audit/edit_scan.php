<?php
/**
 * POST /api/audit/edit_scan.php
 * Updates an existing scan by scan_id. Re-runs discrepancy classification.
 *
 * Required: session_id, scan_id
 * Any other field from add_scan.php is accepted as an override.
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

    // Load existing client_uuid so we go through the same upsert path
    $stmt = $conn->prepare("SELECT client_uuid FROM warehouse_audit_scans WHERE id = ? AND session_id = ? LIMIT 1");
    $stmt->bind_param("ii", $scan_id, $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        audit_json_error('Scan not found', 404);
    }
    $_POST['client_uuid'] = $row['client_uuid'];

    $scan = audit_upsert_scan($conn, $session_id, $_POST, $user_id);
    $reconciliation = audit_build_reconciliation($conn, $session_id);

    audit_json_ok([
        'scan'           => $scan,
        'reconciliation' => $reconciliation,
    ]);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 400);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
