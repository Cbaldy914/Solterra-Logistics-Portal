<?php
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

