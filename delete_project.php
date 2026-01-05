<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    die("Unauthorized: You must be 'admin', 'global_admin', or 'customer_admin' to delete projects.");
}


// Get the project ID from the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Delete the project from the database
$stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);

if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
}

if ($stmt->execute()) {
    echo "Project deleted successfully.";
    
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
