<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if (!isset($_GET['id'])) {
    die("Project ID not specified.");
}

$user_id = $_SESSION['user_id'];
$project_id = intval($_GET['id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Verify that the project belongs to the user
$stmt = $conn->prepare("SELECT id FROM forecast_projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows == 0) {
    die("Project not found or you do not have permission to delete it.");
}
$stmt->close();

// Delete associated estimates
$stmt = $conn->prepare("DELETE FROM forecast_items WHERE forecast_id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->close();

// Delete the project
$stmt = $conn->prepare("DELETE FROM forecast_projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
if ($stmt->execute()) {
    // Redirect back to future_projects
    header("Location: future_projects");
    exit();
} else {
    die("Error deleting project: " . $stmt->error);
}
?>
