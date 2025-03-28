<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: unauthorized");
    exit();
}

// Check if warehouse_id is provided
if (!isset($_GET['warehouse_id']) || empty($_GET['warehouse_id'])) {
    die("Warehouse ID is missing.");
}

$warehouse_id = intval($_GET['warehouse_id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Delete the warehouse
$stmt = $conn->prepare("DELETE FROM warehouses WHERE id = ?");
$stmt->bind_param("i", $warehouse_id);
if ($stmt->execute()) {
    // Optionally, you might want to handle deleting associated files or data
    header("Location: warehouses");
    exit();
} else {
    echo "Error deleting warehouse: " . htmlspecialchars($stmt->error);
}
$stmt->close();
$conn->close();
?>
