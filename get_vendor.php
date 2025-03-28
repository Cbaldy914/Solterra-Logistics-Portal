<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

if (isset($_GET['vendor_id'])) {
    $vendor_id = intval($_GET['vendor_id']);

    $stmt = $conn->prepare("
        SELECT id, name, contact_info, committed_volume, commitment_start_date, commitment_end_date, module_cost
        FROM vendors
        WHERE id = ?
    ");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $vendor = $result->fetch_assoc();
    $stmt->close();

    echo json_encode($vendor);
} else {
    echo json_encode(['error' => 'Vendor ID not provided']);
}
?>
