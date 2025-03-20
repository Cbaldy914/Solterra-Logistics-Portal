<?php
session_name("logistics_session");
session_start();
session_unset();
session_destroy();
header("Location: login");
exit();
?>
