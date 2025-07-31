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

try {
    $stmt = $conn->prepare("
        SELECT id, name, city, state, address 
        FROM warehouses 
        WHERE is_port = 1 
        ORDER BY name ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $ports = [];
    while ($row = $result->fetch_assoc()) {
        $ports[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'city' => $row['city'] ?? '',
            'state' => $row['state'] ?? '',
            'address' => $row['address'] ?? ''
        ];
    }
    
    echo json_encode(['ports' => $ports]);
    
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?> 