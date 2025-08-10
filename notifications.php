<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

require_once __DIR__ . '/../config.php';

function notifyUsers(int $claimId): void {
    // Placeholder for future email integration
    // Intentionally left minimal per spec
    // You can later wire this into SendGrid/SES/etc.
}

?>


