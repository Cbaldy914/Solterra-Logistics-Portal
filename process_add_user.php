<?php
session_name("logistics_session");
session_start();
if ($_SESSION['role'] != 'admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$username = $_POST['username'];
$password = $_POST['password'];
$role = $_POST['role']; // role can be 'user', 'admin', or 'site_user'

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// If role is site_user, insert into site_users table,
// otherwise insert into users table (for user or admin).
if ($role === 'site_user') {
    $stmt = $conn->prepare("INSERT INTO site_users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $hashed_password, $role);
} else {
    // role is either 'user' or 'admin'
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $hashed_password, $role);
}

if ($stmt->execute()) {
    echo "New user added successfully.";
    echo "<br><a href='admin_dashboard'>Back to Admin Dashboard</a>";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
