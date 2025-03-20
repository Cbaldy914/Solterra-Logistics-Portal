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

// Database connection parameters
$servername = "localhost";
$db_username = "SolterraSolutions"; // Replace with your actual database username
$db_password = "CompanyAdmin!";     // Replace with your actual database password
$dbname = "solterra_portal";        // Replace with your actual database name

// Create a new database connection
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
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
