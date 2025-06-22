<?php
/**  Dev‑time config for Codex / Docker  **/

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'solterra_read');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'logistics');

/* AI variables so Codex compiles locally */
define('OC_AI_HOST', getenv('OC_AI_HOST') ?: '127.0.0.1');
define('OC_AI_PORT', getenv('OC_AI_PORT') ?: '11434');
define('OC_AI_MODEL', getenv('OC_AI_MODEL') ?: 'gemma:2b');

define('SESSION_LIFETIME', 3600);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT', 900);

define('GOOGLE_MAPS_API_KEY', getenv('GOOGLE_MAPS_API_KEY') ?: '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* DB connector */
function getDBConnection(): mysqli {
    $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $c->set_charset('utf8mb4');
    return $c;
}

/* Schema helpers (mirror prod) */
function listTables(mysqli $c): array {
    $t=[]; $r=$c->query("SHOW TABLES");
    while($row=$r->fetch_array()) $t[]=$row[0];
    return $t;
}
function listColumns(mysqli $c,string $table): array {
    $cols=[]; $r=$c->query("SHOW COLUMNS FROM `".$c->real_escape_string($table)."`");
    while($row=$r->fetch_assoc()) $cols[]=$row['Field'];
    return $cols;
}

function getGoogleMapsApiKey(): string { return GOOGLE_MAPS_API_KEY; }
?>
