<?php
/**
 * Warranty Notification Helpers
 * Replaces the placeholder notifyUsers function in legacy warranty system
 */

require_once __DIR__ . '/notification_helpers.php';

function notifyUsers(int $claimId): void {
    // Get warranty claim details
    require_once __DIR__ . '/../config.php';
    $conn = getDBConnection();
    
    try {
        $stmt = $conn->prepare("
            SELECT wc.*, s.project_id, p.project_name
            FROM warranty_claims wc
            LEFT JOIN scheduling s ON wc.scheduling_id = s.id
            LEFT JOIN projects p ON s.project_id = p.id
            WHERE wc.id = ?
        ");
        $stmt->bind_param('i', $claimId);
        $stmt->execute();
        $result = $stmt->get_result();
        $claim = $result->fetch_assoc();
        $stmt->close();
        
        if ($claim && !empty($claim['project_id'])) {
            $projectName = $claim['project_name'] ?? 'Unknown Project';
            $status = $claim['status'] ?? 'New';
            
            notify_project_users(
                $claim['project_id'],
                'warranty_claim',
                "Warranty Claim: {$projectName}",
                "A warranty claim has been created or updated. Status: {$status}",
                "warranty_detail.php?id={$claimId}"
            );
        }
        
        $conn->close();
    } catch (Exception $e) {
        error_log('Warranty notification error: ' . $e->getMessage());
    }
}

