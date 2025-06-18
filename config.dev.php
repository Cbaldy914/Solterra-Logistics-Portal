<?php
/**
 * Development-time configuration for Codex, Docker, or local testing.
 * No secrets are hard-coded; everything pulls from environment variables.
 */

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'solterra_solutions');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'solterra_portal');

define('SESSION_LIFETIME', 3600);        // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT', 900);            // 15 minutes

define('GOOGLE_MAPS_API_KEY', getenv('GOOGLE_MAPS_API_KEY') ?: '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function getDBConnection(): mysqli
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
    return $conn;
}
