<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: unauthorized");
    exit();
}

// Get the module ID from the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Module ID is missing.");
}

$module_id = intval($_GET['id']);

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

// Delete the module
$stmt = $conn->prepare("DELETE FROM unassigned_modules WHERE id = ?");
$stmt->bind_param("i", $module_id);

if (!$stmt->execute()) {
    die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
}

$stmt->close();
$conn->close();

// Redirect to manage_unassigned_modules
header("Location: manage_unassigned_modules");
exit();
?>
