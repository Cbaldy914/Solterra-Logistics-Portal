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
    // Collect form values
    $in_app_document_upload = isset($_POST['in_app_document_upload']) ? 1 : 0;
    $in_app_project_update = isset($_POST['in_app_project_update']) ? 1 : 0;
    $in_app_delivery_status = isset($_POST['in_app_delivery_status']) ? 1 : 0;
    $in_app_warranty_claim = isset($_POST['in_app_warranty_claim']) ? 1 : 0;
    
    $email_enabled = isset($_POST['email_enabled']) ? 1 : 0;
    $email_document_upload = ($email_enabled && isset($_POST['email_document_upload'])) ? 1 : 0;
    $email_project_update = ($email_enabled && isset($_POST['email_project_update'])) ? 1 : 0;
    $email_delivery_status = ($email_enabled && isset($_POST['email_delivery_status'])) ? 1 : 0;
    $email_warranty_claim = ($email_enabled && isset($_POST['email_warranty_claim'])) ? 1 : 0;
    
    // Update settings
    $stmt = $conn->prepare("
        UPDATE notification_settings 
        SET in_app_document_upload = ?,
            in_app_project_update = ?,
            in_app_delivery_status = ?,
            in_app_warranty_claim = ?,
            email_enabled = ?,
            email_document_upload = ?,
            email_project_update = ?,
            email_delivery_status = ?,
            email_warranty_claim = ?
        WHERE user_id = ?
    ");
    
    $stmt->bind_param('iiiiiiiiii',
        $in_app_document_upload,
        $in_app_project_update,
        $in_app_delivery_status,
        $in_app_warranty_claim,
        $email_enabled,
        $email_document_upload,
        $email_project_update,
        $email_delivery_status,
        $email_warranty_claim,
        $user_id
    );
    
    $stmt->execute();
    $stmt->close();
    
    $_SESSION['success_message'] = 'Notification settings saved successfully';
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Failed to save notification settings';
    error_log('Save notification settings error: ' . $e->getMessage());
}

$conn->close();
header("Location: ../notifications");
exit();

