<?php
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    );

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_name('logistics_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Global filter for benign session warnings/notices.
// This keeps logs clean without changing page logic.
set_error_handler(function ($errno, $errstr) {
    // Only intercept warnings/notices from session_* calls that are harmless
    if ($errno === E_WARNING || $errno === E_NOTICE) {
        if (strpos($errstr, 'session_name(): Session name cannot be changed when a session is active') !== false) {
            return true; // suppress
        }
        if (strpos($errstr, 'session_set_cookie_params(): Session cookie parameters cannot be changed when a session is active') !== false) {
            return true; // suppress
        }
        if (strpos($errstr, 'session_start(): Ignoring session_start() because a session is already active') !== false) {
            return true; // suppress
        }
    }
    // Defer all other errors to default handlers
    return false;
});
