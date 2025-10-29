<?php
/**
 * Delivery Notification Helpers
 * 
 * Centralized notification system for delivery status changes.
 * Call notify_delivery_status_change() whenever a delivery's status is updated.
 */

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

/**
 * Notify users when a delivery status changes
 * 
 * @param int $deliveryId The delivery ID
 * @param string $oldStatus The previous status
 * @param string $newStatus The new status
 */
function notify_delivery_status_change(int $deliveryId, string $oldStatus, string $newStatus): void {
    // Only notify if status actually changed
    if ($oldStatus === $newStatus) {
        return;
    }
    
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/notification_helpers.php';
    
    $conn = getDBConnection();
    if (!$conn) {
        error_log('Failed to connect to database for delivery notification');
        return;
    }
    
    try {
        // Get delivery details including project info
        $stmt = $conn->prepare('
            SELECT d.*, p.project_name, p.id as project_id
            FROM deliveries d
            LEFT JOIN projects p ON d.project_id = p.id
            WHERE d.id = ?
        ');
        $stmt->bind_param('i', $deliveryId);
        $stmt->execute();
        $result = $stmt->get_result();
        $delivery = $result->fetch_assoc();
        $stmt->close();
        
        if (!$delivery) {
            error_log("Delivery {$deliveryId} not found for notification");
            $conn->close();
            return;
        }
        
        // Build notification message
        $identifier = $delivery['bol_number'] ?: $delivery['container_number'] ?: "Delivery #{$deliveryId}";
        $title = "Delivery Status Updated";
        $message = "{$identifier} status changed from \"{$oldStatus}\" to \"{$newStatus}\"";
        
        if ($delivery['project_name']) {
            $message .= " for project {$delivery['project_name']}";
        }
        
        // If delivery is assigned to a project, notify project users
        if ($delivery['project_id']) {
            $link = "view_project?project_id={$delivery['project_id']}&delivery_id={$deliveryId}";
            notify_project_users(
                $delivery['project_id'],
                'delivery_status',
                $title,
                $message,
                $link
            );
        } else {
            // If not assigned to project, notify global admins
            notify_global_admins(
                'delivery_status',
                $title,
                $message,
                "edit_delivery?delivery_id={$deliveryId}"
            );
        }
        
        $conn->close();
    } catch (Throwable $e) {
        error_log('Delivery notification error: ' . $e->getMessage());
    }
}

/**
 * Notify users when a delivery date changes (anticipated, actual, etc.)
 * 
 * @param int $deliveryId The delivery ID
 * @param string $dateType Type of date changed (e.g., 'anticipated', 'actual', 'warehouse_arrival')
 * @param string|null $oldDate Previous date value
 * @param string|null $newDate New date value
 */
function notify_delivery_date_change(int $deliveryId, string $dateType, ?string $oldDate, ?string $newDate): void {
    // Only notify if date actually changed
    if ($oldDate === $newDate) {
        return;
    }
    
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/notification_helpers.php';
    
    $conn = getDBConnection();
    if (!$conn) {
        return;
    }
    
    try {
        // Get delivery details
        $stmt = $conn->prepare('
            SELECT d.*, p.project_name, p.id as project_id
            FROM deliveries d
            LEFT JOIN projects p ON d.project_id = p.id
            WHERE d.id = ?
        ');
        $stmt->bind_param('i', $deliveryId);
        $stmt->execute();
        $result = $stmt->get_result();
        $delivery = $result->fetch_assoc();
        $stmt->close();
        
        if (!$delivery || !$delivery['project_id']) {
            $conn->close();
            return;
        }
        
        // Build notification message
        $identifier = $delivery['bol_number'] ?: $delivery['container_number'] ?: "Delivery #{$deliveryId}";
        $dateLabel = ucwords(str_replace('_', ' ', $dateType));
        
        $title = "Delivery Date Updated";
        $message = "{$identifier}: {$dateLabel} date updated";
        
        if ($newDate) {
            $message .= " to " . date('M j, Y', strtotime($newDate));
        } else {
            $message .= " (removed)";
        }
        
        if ($delivery['project_name']) {
            $message .= " for project {$delivery['project_name']}";
        }
        
        $link = "view_project?project_id={$delivery['project_id']}&delivery_id={$deliveryId}";
        
        notify_project_users(
            $delivery['project_id'],
            'delivery_status',
            $title,
            $message,
            $link
        );
        
        $conn->close();
    } catch (Throwable $e) {
        error_log('Delivery date notification error: ' . $e->getMessage());
    }
}

?>

