<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: unauthorized");
    exit();
}

// Retrieve form data
$module_id = intval($_POST['module_id']);
$user_id = intval($_POST['user_id']);
$vendor = trim($_POST['vendor']);
$wattage = intval($_POST['wattage']);
$quantity = intval($_POST['quantity']);
$current_location = trim($_POST['current_location']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Update the module
$stmt = $conn->prepare("
    UPDATE unassigned_modules
    SET user_id = ?, vendor = ?, wattage = ?, quantity = ?, current_location = ?
    WHERE id = ?
");

if (!$stmt) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}

$stmt->bind_param("isdisi", $user_id, $vendor, $wattage, $quantity, $current_location, $module_id);

if (!$stmt->execute()) {
    die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
}

$stmt->close();
$conn->close();

// Redirect to manage_unassigned_modules
header("Location: manage_unassigned_modules");
exit();
?>
