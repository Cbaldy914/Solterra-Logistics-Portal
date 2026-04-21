<?php
/**
 * POST /api/audit/create_session.php
 * Creates a new warehouse_audit_sessions row and returns its ID + session_code.
 *
 * Required POST fields: warehouse_id
 * Optional: project_id, manufacturer_id, customer_name, service_description,
 *           service_fee_usd, expected_pallet_count, expected_wattages (array),
 *           expected_modules_per_pallet, expected_equipment (array),
 *           ai_vision_enabled, ai_vision_call_cap
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
    $account_id = audit_get_user_account_id($conn, $user_id, $role);
    if ($role !== 'global_admin' && $account_id === null) {
        audit_json_error('Account not found for user', 403);
    }
    // global_admin may specify account_id explicitly
    if ($role === 'global_admin' && !empty($_POST['account_id'])) {
        $account_id = (int)$_POST['account_id'];
    }
    if ($account_id === null) {
        audit_json_error('account_id required for global_admin', 400);
    }

    $warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;
    if ($warehouse_id <= 0) {
        audit_json_error('warehouse_id required', 400);
    }

    // Verify warehouse belongs to this account (or is a global warehouse)
    $stmt = $conn->prepare("SELECT id, name, city FROM warehouses WHERE id = ? AND (account_id = ? OR account_id IS NULL) LIMIT 1");
    $stmt->bind_param("ii", $warehouse_id, $account_id);
    $stmt->execute();
    $warehouse = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$warehouse) {
        audit_json_error('Warehouse not found or access denied', 404);
    }

    // Verify project (if given) belongs to the account
    $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    if ($project_id !== null) {
        $stmt = $conn->prepare("SELECT id FROM projects WHERE id = ? AND account_id = ? LIMIT 1");
        $stmt->bind_param("ii", $project_id, $account_id);
        $stmt->execute();
        $proj = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$proj) {
            audit_json_error('Project not found or not owned by this account', 404);
        }
    }

    // Verify manufacturer (if given)
    $manufacturer_id = !empty($_POST['manufacturer_id']) ? (int)$_POST['manufacturer_id'] : null;
    if ($manufacturer_id !== null) {
        $stmt = $conn->prepare("SELECT id FROM manufacturers WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param("i", $manufacturer_id);
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$m) {
            audit_json_error('Manufacturer not found or inactive', 404);
        }
    }

    // Generate session code using warehouse city as prefix
    $prefix = 'AUD';
    if (!empty($warehouse['city'])) {
        $prefix = substr(preg_replace('/[^A-Za-z]/', '', $warehouse['city']), 0, 3) ?: 'AUD';
    }
    $session_code = audit_generate_session_code($prefix);

    // Collect optional expected_wattages array (may arrive as a[]=690&a[]=695 or JSON string)
    $expected_wattages = [];
    if (isset($_POST['expected_wattages'])) {
        if (is_array($_POST['expected_wattages'])) {
            $expected_wattages = array_values(array_filter(array_map('intval', $_POST['expected_wattages'])));
        } elseif (is_string($_POST['expected_wattages'])) {
            $decoded = json_decode($_POST['expected_wattages'], true);
            if (is_array($decoded)) {
                $expected_wattages = array_values(array_filter(array_map('intval', $decoded)));
            }
        }
    }

    $expected_equipment = [];
    if (isset($_POST['expected_equipment']) && is_string($_POST['expected_equipment'])) {
        $decoded = json_decode($_POST['expected_equipment'], true);
        if (is_array($decoded)) {
            $expected_equipment = $decoded;
        }
    } elseif (isset($_POST['expected_equipment']) && is_array($_POST['expected_equipment'])) {
        $expected_equipment = $_POST['expected_equipment'];
    }

    $session_id = audit_create_session($conn, [
        'account_id'                  => $account_id,
        'warehouse_id'                => $warehouse_id,
        'project_id'                  => $project_id,
        'manufacturer_id'             => $manufacturer_id,
        'created_by_user_id'          => $user_id,
        'customer_name'               => $_POST['customer_name']       ?? null,
        'service_description'         => $_POST['service_description'] ?? null,
        'service_fee_usd'             => $_POST['service_fee_usd']     ?? null,
        'session_code'                => $session_code,
        'status'                      => 'in_progress',
        'expected_pallet_count'       => $_POST['expected_pallet_count']       ?? null,
        'expected_wattages'           => $expected_wattages,
        'expected_modules_per_pallet' => $_POST['expected_modules_per_pallet'] ?? null,
        'expected_equipment'          => $expected_equipment,
        'ai_vision_enabled'           => isset($_POST['ai_vision_enabled']) ? (int)(bool)$_POST['ai_vision_enabled'] : 1,
        'ai_vision_call_cap'          => isset($_POST['ai_vision_call_cap']) ? (int)$_POST['ai_vision_call_cap'] : 2000,
    ]);

    audit_write_session_event($conn, $session_id, $user_id, 'started', [
        'warehouse_id' => $warehouse_id,
        'project_id'   => $project_id,
        'expected_pallet_count' => $_POST['expected_pallet_count'] ?? null,
    ]);

    // Seed expected equipment rows
    if (!empty($expected_equipment)) {
        foreach ($expected_equipment as $eq) {
            audit_upsert_equipment_check($conn, $session_id, [
                'equipment_type'    => $eq['type']         ?? 'Equipment',
                'manufacturer_name' => $eq['manufacturer'] ?? null,
                'model_number'      => $eq['model']        ?? null,
                'expected_quantity' => isset($eq['quantity']) ? (int)$eq['quantity'] : 1,
                'status'            => 'not_verified',
            ], $user_id);
        }
    }

    audit_json_ok([
        'session_id'   => $session_id,
        'session_code' => $session_code,
    ]);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 400);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
