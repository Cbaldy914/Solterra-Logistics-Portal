<?php
/**
 * POST /api/audit/update_equipment.php
 * Creates or updates a warehouse_audit_equipment_checks row.
 *
 * Required: session_id, equipment_type
 * Optional: id (for update), manufacturer_name, model_number, serial_number,
 *           asset_tag, expected_quantity, verified_quantity, status, notes
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
    audit_require_session_access($conn, $session_id, $user_id, $role);

    $equipment_id = audit_upsert_equipment_check($conn, $session_id, $_POST, $user_id);

    // Reload for return
    $stmt = $conn->prepare("SELECT * FROM warehouse_audit_equipment_checks WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    audit_json_ok(['equipment' => $row]);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 400);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
