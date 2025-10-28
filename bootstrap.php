<?php
/**
 * Loads the correct configuration (prod or dev) + Composer autoloader
 * and sets up any site-wide settings.
 */

/* 1. Config (prod first, dev fallback) */
if (!@include_once __DIR__ . '/../config.php') {
    require __DIR__ . '/config.dev.php';
}

/* 2. Composer autoload (optional but nice if you use packages) */
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

/* 3. Other global setup you already have */
// Start session once, with consistent name and cookie params
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    // Align cookie parameters with the portal's login settings
    if (function_exists('session_set_cookie_params')) {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );
        session_set_cookie_params([
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}
date_default_timezone_set('America/New_York'); // example
