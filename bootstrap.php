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
session_start();            // example
date_default_timezone_set('America/New_York'); // example
