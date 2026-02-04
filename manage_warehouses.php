<?php
session_name("logistics_session");
session_start();

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'warehousing_overview.php';
if (!empty($query)) {
    $target .= '?' . $query;
}

header("Location: " . $target);
exit();
