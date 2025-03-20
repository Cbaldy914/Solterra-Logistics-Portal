<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: unauthorized");
    exit();
}


// Get the project ID from the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['id']);

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions"; // Replace with your actual database username
$db_password = "CompanyAdmin!";     // Replace with your actual database password
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Delete the project from the database
$stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);

if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
}

if ($stmt->execute()) {
    echo "Project deleted successfully.";
    echo "<br><a href='manage_projects'>Back to Manage Projects</a>";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
