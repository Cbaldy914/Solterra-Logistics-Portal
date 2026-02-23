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

// Best-effort cleanup for container tracking data when projects are deleted from this endpoint.
$waypointsTableCheck = $conn->query("SHOW TABLES LIKE 'container_tracking_waypoints'");
if ($waypointsTableCheck && $waypointsTableCheck->num_rows > 0) {
    $stmtDelWaypoints = $conn->prepare("DELETE FROM container_tracking_waypoints WHERE project_id = ?");
    if ($stmtDelWaypoints) {
        $stmtDelWaypoints->bind_param("i", $project_id);
        $stmtDelWaypoints->execute();
        $stmtDelWaypoints->close();
    }
}
if ($waypointsTableCheck) {
    $waypointsTableCheck->close();
}

$positionsTableCheck = $conn->query("SHOW TABLES LIKE 'container_tracking_positions'");
if ($positionsTableCheck && $positionsTableCheck->num_rows > 0) {
    $stmtDelPositions = $conn->prepare("DELETE FROM container_tracking_positions WHERE project_id = ?");
    if ($stmtDelPositions) {
        $stmtDelPositions->bind_param("i", $project_id);
        $stmtDelPositions->execute();
        $stmtDelPositions->close();
    }
}
if ($positionsTableCheck) {
    $positionsTableCheck->close();
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
