<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

require_once __DIR__ . '/../config.php';

function notifyUsers(int $claimId): void {
    // Placeholder for future email/in-app notifications.
    // Intentionally minimal to avoid side effects in this environment.
    return;
}

?>


