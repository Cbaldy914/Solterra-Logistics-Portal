<?php
/* Local dev secrets template. Copy to env.local.php and fill in real values. */

// Database
putenv('DB_HOST=127.0.0.1');
putenv('DB_NAME=solterra_dev');
putenv('DB_USER=dev_user');
putenv('DB_PASS=');

// Environment flag
putenv('APP_ENV=dev');

// Optional non-prod API keys
// putenv('GOOGLE_MAPS_API_KEY=');
// putenv('OPENAI_API_KEY=');
?>
