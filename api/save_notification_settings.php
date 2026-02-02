<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login");
    exit();
}

require_once '../../config.php';
require_once '../notification_helpers.php';

$user_id = $_SESSION['user_id'];

// Ensure settings record exists
ensure_notification_settings($user_id);

$conn = getDBConnection();

try {
    // Map of potential fields and their values
    $fields = [
        'in_app_document_upload' => isset($_POST['in_app_document_upload']) ? 1 : 0,
        'in_app_project_update' => isset($_POST['in_app_project_update']) ? 1 : 0,
        'in_app_delivery_status' => isset($_POST['in_app_delivery_status']) ? 1 : 0,
        'in_app_warranty_claim' => isset($_POST['in_app_warranty_claim']) ? 1 : 0,
        'in_app_freight_estimate_request' => isset($_POST['in_app_freight_estimate_request']) ? 1 : 0,
        'in_app_freight_estimate_rated' => isset($_POST['in_app_freight_estimate_rated']) ? 1 : 0,
        'in_app_warehouse_estimate_request' => isset($_POST['in_app_warehouse_estimate_request']) ? 1 : 0,
        'in_app_warehouse_estimate_rated' => isset($_POST['in_app_warehouse_estimate_rated']) ? 1 : 0,
        'in_app_manufacturer_request' => isset($_POST['in_app_manufacturer_request']) ? 1 : 0,
        'email_enabled' => isset($_POST['email_enabled']) ? 1 : 0,
        'email_document_upload' => 0,
        'email_project_update' => 0,
        'email_delivery_status' => 0,
        'email_warranty_claim' => 0,
        'email_freight_estimate_request' => 0,
        'email_freight_estimate_rated' => 0,
        'email_warehouse_estimate_request' => 0,
        'email_warehouse_estimate_rated' => 0,
        'email_manufacturer_request' => 0,
    ];

    // Respect email enabled for sub-options
    if (!empty($fields['email_enabled'])) {
        $fields['email_document_upload'] = isset($_POST['email_document_upload']) ? 1 : 0;
        $fields['email_project_update'] = isset($_POST['email_project_update']) ? 1 : 0;
        $fields['email_delivery_status'] = isset($_POST['email_delivery_status']) ? 1 : 0;
        $fields['email_warranty_claim'] = isset($_POST['email_warranty_claim']) ? 1 : 0;
        $fields['email_freight_estimate_request'] = isset($_POST['email_freight_estimate_request']) ? 1 : 0;
        $fields['email_freight_estimate_rated'] = isset($_POST['email_freight_estimate_rated']) ? 1 : 0;
        $fields['email_warehouse_estimate_request'] = isset($_POST['email_warehouse_estimate_request']) ? 1 : 0;
        $fields['email_warehouse_estimate_rated'] = isset($_POST['email_warehouse_estimate_rated']) ? 1 : 0;
        $fields['email_manufacturer_request'] = isset($_POST['email_manufacturer_request']) ? 1 : 0;
    }

    // Determine which columns actually exist
    $existingCols = [];
    try {
        $resCols = $conn->query("SHOW COLUMNS FROM notification_settings");
        while ($col = $resCols->fetch_assoc()) {
            $existingCols[] = $col['Field'];
        }
    } catch (Throwable $e) {
        error_log('Column discovery error: ' . $e->getMessage());
    }

    $updates = [];
    $params = [];
    $types = '';
    foreach ($fields as $field => $value) {
        if (in_array($field, $existingCols, true)) {
            $updates[] = "$field = ?";
            $params[] = $value;
            $types .= 'i';
        }
    }

    if (!empty($updates)) {
        $types .= 'i';
        $params[] = $user_id;

        $sql = 'UPDATE notification_settings SET ' . implode(', ', $updates) . ' WHERE user_id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success_message'] = 'Notification settings saved successfully';
    } else {
        $_SESSION['error_message'] = 'No matching settings columns to update.';
    }
} catch (Throwable $e) {
    $_SESSION['error_message'] = 'Failed to save notification settings';
    error_log('Save notification settings error: ' . $e->getMessage());
}

$conn->close();
header("Location: ../notifications");
exit();
