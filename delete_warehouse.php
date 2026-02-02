<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
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

$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$account_id = null;

if ($role !== 'global_admin') {
    $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    if ($stmtAccount) {
        $stmtAccount->bind_param("i", $user_id);
        $stmtAccount->execute();
        $stmtAccount->bind_result($account_id);
        $stmtAccount->fetch();
        $stmtAccount->close();
    }
    if (!$account_id) {
        die("No valid account found for this user.");
    }
}

// Delete the warehouse
if ($role === 'global_admin') {
    $stmt = $conn->prepare("DELETE FROM warehouses WHERE id = ?");
    $stmt->bind_param("i", $warehouse_id);
} else {
    $stmt = $conn->prepare("DELETE FROM warehouses WHERE id = ? AND account_id = ?");
    $stmt->bind_param("ii", $warehouse_id, $account_id);
}
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
