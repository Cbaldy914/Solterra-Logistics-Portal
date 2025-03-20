<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

if (isset($_GET['inventory_id'])) {
    $inventory_id = intval($_GET['inventory_id']);

    $stmt = $conn->prepare("
        SELECT id, vendor_id, wattage, quantity, status, project_id
        FROM module_inventory
        WHERE id = ?
    ");
    $stmt->bind_param("i", $inventory_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $inventory = $result->fetch_assoc();
    $stmt->close();

    echo json_encode($inventory);
} else {
    echo json_encode(['error' => 'Inventory ID not provided']);
}
?>
