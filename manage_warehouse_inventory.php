<?php
$qs = $_SERVER['QUERY_STRING'];
header("Location: warehouse.php" . ($qs ? "?{$qs}" : ""));
exit();
