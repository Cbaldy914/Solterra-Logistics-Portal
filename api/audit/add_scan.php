<?php
/**
 * POST /api/audit/add_scan.php
 * Upserts a scan row by (session_id, client_uuid). Offline-replay-safe.
 *
 * Required: session_id, client_uuid, scan_method, raw_value
 * Optional: parsed_wattage, quantity, notes, ai_confidence, ai_raw_response,
 *           client_captured_at, retry_count, label_unreadable, damage_photo
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
    $client_uuid = $_POST['client_uuid'] ?? '';
    if (!$session_id || !$client_uuid) {
        audit_json_error('session_id and client_uuid required', 400);
    }

    $session = audit_require_session_access($conn, $session_id, $user_id, $role);

    // Quick duplicate log check — if we've already accepted this client_uuid,
    // return the existing row idempotently.
    $retry_count = isset($_POST['retry_count']) ? (int)$_POST['retry_count'] : 0;
    $client_captured_at = $_POST['client_captured_at'] ?? null;

    $scan = audit_upsert_scan($conn, $session_id, $_POST, $user_id);

    // Log the offline queue attempt outcome
    audit_log_offline_queue_attempt(
        $conn, $session_id, $client_uuid,
        'accepted', $retry_count, null, $client_captured_at
    );

    $reconciliation = audit_build_reconciliation($conn, $session_id);

    audit_json_ok([
        'scan'           => $scan,
        'reconciliation' => $reconciliation,
    ]);
} catch (Throwable $e) {
    if (isset($session_id, $client_uuid)) {
        audit_log_offline_queue_attempt($conn, $session_id, $client_uuid, 'rejected', $retry_count ?? 0, substr($e->getMessage(), 0, 500));
    }
    audit_json_error($e->getMessage(), 400);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
