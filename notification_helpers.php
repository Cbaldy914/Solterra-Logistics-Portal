<?php
/**
 * Notification Helper Functions for Solterra Logistics Portal
 * Adapted from Solterra CRM notification system
 */

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

/**
 * Get notification settings for a user
 * @param int $userId User ID
 * @return array Notification settings with defaults if not found
 */
function notification_settings_for(int $userId): array {
    require_once __DIR__ . '/../config.php';
    $conn = getDBConnection();
    
    $defaults = [
        'user_id' => $userId,
        'in_app_document_upload' => 1,
        'in_app_project_update' => 1,
        'in_app_delivery_status' => 1,
        'in_app_warranty_claim' => 1,
        'in_app_freight_estimate_request' => 0,
        'in_app_freight_estimate_rated' => 0,
        'email_enabled' => 0,
        'email_document_upload' => 0,
        'email_project_update' => 0,
        'email_delivery_status' => 0,
        'email_warranty_claim' => 0,
        'email_freight_estimate_request' => 0,
        'email_freight_estimate_rated' => 0,
        'in_app_warehouse_estimate_request' => 0,
        'in_app_warehouse_estimate_rated' => 0,
        'email_warehouse_estimate_request' => 0,
        'email_warehouse_estimate_rated' => 0,
    ];

    try {
        $stmt = $conn->prepare('SELECT * FROM notification_settings WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        
        if ($row) {
            return array_merge($defaults, $row);
        }
    } catch (Throwable $e) {
        // Table may not exist yet during deployment
        error_log('Notification settings error: ' . $e->getMessage());
    }
    
    return $defaults;
}

/**
 * Helper: check a notification flag with a sensible default when the column is missing.
 */
function notification_flag_enabled(array $settings, string $key, bool $default = false): bool {
    return array_key_exists($key, $settings) ? !empty($settings[$key]) : $default;
}

/**
 * Ensure notification settings exist for a user (create with defaults if not)
 * @param int $userId User ID
 */
function ensure_notification_settings(int $userId): void {
    require_once __DIR__ . '/../config.php';
    $conn = getDBConnection();
    
    try {
        $stmt = $conn->prepare('INSERT IGNORE INTO notification_settings (user_id) VALUES (?)');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } catch (Throwable $e) {
        error_log('Ensure notification settings error: ' . $e->getMessage());
    }
}

/**
 * Get count of unread notifications for a user
 * @param int $userId User ID
 * @return int Count of unread notifications
 */
function unread_notification_count(int $userId): int {
    require_once __DIR__ . '/../config.php';
    $conn = getDBConnection();
    
    try {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        $conn->close();
        return (int)$count;
    } catch (Throwable $e) {
        error_log('Unread notification count error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Create a notification for a user
 * @param int $userId User ID to notify
 * @param string $type Notification type (document_upload, project_update, delivery_status, warranty_claim)
 * @param string $title Notification title
 * @param string $message Notification message body
 * @param string|null $link Optional link to related resource
 */
function notify_user(int $userId, string $type, string $title, string $message = '', ?string $link = null): void {
    require_once __DIR__ . '/../config.php';
    $conn = getDBConnection();
    
    try {
        ensure_notification_settings($userId);
        
        // Check if user has in-app notifications enabled for this type
        $settings = notification_settings_for($userId);
        $typeKey = 'in_app_' . $type;
        $inAppEnabled = notification_flag_enabled($settings, $typeKey);
        
        if (!$inAppEnabled) {
            // User has disabled in-app notifications for this type
            $conn->close();
            return;
        }
        
        // Insert in-app notification
        $stmt = $conn->prepare('INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->bind_param('issss', $userId, $type, $title, $message, $link);
        $stmt->execute();
        $stmt->close();
        
        // Send email if enabled
        if (!empty($settings['email_enabled'])) {
            $emailTypeKey = 'email_' . $type;
            if (notification_flag_enabled($settings, $emailTypeKey)) {
                send_notification_email($userId, $title, $message, $link);
            }
        }
        
        $conn->close();
    } catch (Throwable $e) {
        error_log('Notify user error: ' . $e->getMessage());
        // Don't break the main flow if notification fails
    }
}

/**
 * Send notification email to user
 * @param int $userId User ID
 * @param string $title Email subject
 * @param string $message Email body
 * @param string|null $link Optional link to include
 */
function send_notification_email(int $userId, string $title, string $message, ?string $link = null): void {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/Mailer.php';
    
    $conn = getDBConnection();
    
    try {
        // Get user email
        $stmt = $conn->prepare('SELECT username, email, first_name, last_name FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        
        if (!$user || empty($user['email'])) {
            return; // No email address
        }
        
        // Build email body
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if (empty($name)) {
            $name = $user['username'];
        }
        
        $body = "Hi {$name},\n\n{$title}\n\n{$message}\n";
        
        if ($link) {
            // Convert relative link to absolute URL
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
                     . "://{$_SERVER['HTTP_HOST']}"
                     . dirname($_SERVER['SCRIPT_NAME']);
            $fullLink = $baseUrl . '/' . ltrim($link, '/');
            $body .= "\nView details: {$fullLink}\n";
        }
        
        $body .= "\n---\nSolterra Logistics Portal\n";
        
        // Send email
        Mailer::send(
            $user['email'],
            "[Solterra Logistics] {$title}",
            $body
        );
        
    } catch (Throwable $e) {
        error_log('Send notification email error: ' . $e->getMessage());
    }
}

/**
 * Mark notification as read if mark_read parameter is present
 * Call this at the top of pages that can be linked from notifications
 */
function mark_notification_read_if_requested(): void {
    if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
        if (isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            $notifId = (int)$_GET['mark_read'];
            
            require_once __DIR__ . '/../config.php';
            $conn = getDBConnection();
            
            try {
                $stmt = $conn->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
                $stmt->bind_param('ii', $notifId, $userId);
                $stmt->execute();
                $stmt->close();
                $conn->close();
            } catch (Throwable $e) {
                error_log('Mark notification read error: ' . $e->getMessage());
            }
        }
    }
}

/**
 * Notify all users associated with a project (excludes the current user who made the change)
 * @param int $projectId Project ID
 * @param string $type Notification type
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string|null $link Optional link
 */
function notify_project_users(int $projectId, string $type, string $title, string $message = '', ?string $link = null): void {
    require_once __DIR__ . '/../config.php';
    $conn = getDBConnection();
    
    try {
        // Get current user ID from session (to exclude them)
        $currentUserId = $_SESSION['user_id'] ?? 0;
        
        // Get all users associated with this project's account (except the current user)
        $stmt = $conn->prepare('
            SELECT DISTINCT cau.user_id 
            FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE p.id = ? AND cau.user_id != ?
        ');
        $stmt->bind_param('ii', $projectId, $currentUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            notify_user((int)$row['user_id'], $type, $title, $message, $link);
        }
        
        $stmt->close();
        $conn->close();
    } catch (Throwable $e) {
        error_log('Notify project users error: ' . $e->getMessage());
    }
}

/**
 * Notify global admins
 * @param string $type Notification type
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string|null $link Optional link
 */
function notify_global_admins(string $type, string $title, string $message = '', ?string $link = null): void {
    require_once __DIR__ . '/../config.php';
    $conn = getDBConnection();
    
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'global_admin'");
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            notify_user((int)$row['id'], $type, $title, $message, $link);
        }
        
        $stmt->close();
        $conn->close();
    } catch (Throwable $e) {
        error_log('Notify global admins error: ' . $e->getMessage());
    }
}

/**
 * Get account IDs for a given user (supports multiple accounts).
 *
 * @param int $userId
 * @return int[]
 */
function account_ids_for_user(int $userId): array {
    require_once __DIR__ . '/../config.php';
    $conn = getDBConnection();

    $ids = [];

    try {
        $stmt = $conn->prepare('SELECT account_id FROM customer_account_users WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['account_id'];
        }

        $stmt->close();
        $conn->close();
    } catch (Throwable $e) {
        error_log('Account lookup error: ' . $e->getMessage());
    }

    return array_values(array_unique($ids));
}

/**
 * Notify all admins tied to the provided account IDs.
 */
function notify_account_admins(array $accountIds, string $type, string $title, string $message = '', ?string $link = null): void {
    if (empty($accountIds)) {
        return;
    }

    require_once __DIR__ . '/../config.php';
    $conn = getDBConnection();

    $accountIds = array_values(array_unique(array_map('intval', $accountIds)));

    try {
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $types = str_repeat('i', count($accountIds));

        $stmt = $conn->prepare("SELECT DISTINCT user_id FROM customer_account_users WHERE account_id IN ($placeholders) AND role = 'admin'");
        $stmt->bind_param($types, ...$accountIds);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            notify_user((int)$row['user_id'], $type, $title, $message, $link);
        }

        $stmt->close();
        $conn->close();
    } catch (Throwable $e) {
        error_log('Notify account admins error: ' . $e->getMessage());
    }
}
