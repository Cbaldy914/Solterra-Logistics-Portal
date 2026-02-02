<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;

if ($location_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid location ID']);
    exit();
}

try {
    $stmt = $conn->prepare("SELECT ml.country FROM manufacturer_locations ml JOIN manufacturers m ON m.id = ml.manufacturer_id WHERE ml.id = ? AND ml.is_active = 1");
    $stmt->bind_param("i", $location_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['country' => $row['country']]);
    } else {
        echo json_encode(['country' => 'USA']); // Default to USA if not found
    }
    
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?> 
