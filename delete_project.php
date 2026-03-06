<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    die("Unauthorized: You must be 'admin', 'global_admin', or 'customer_admin' to delete projects.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Only POST requests are allowed.");
}

if (
    empty($_SESSION['csrf_token']) ||
    !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])
) {
    http_response_code(400);
    die("Invalid request token.");
}

// Get the project ID from the request
if (!isset($_POST['project_id']) || empty($_POST['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_POST['project_id']);

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
