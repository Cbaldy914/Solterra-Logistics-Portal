<?php
session_name("logistics_session");
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}
// Rest of your admin dashboard code

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
</body>
</html>
