<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../config.php';

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['delivery_ids']) || !is_array($input['delivery_ids'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing or invalid delivery_ids']);
    exit();
}

$delivery_ids = $input['delivery_ids'];

// Validate delivery IDs are integers
$delivery_ids = array_filter(array_map('intval', $delivery_ids), function($id) {
    return $id > 0;
});

if (empty($delivery_ids)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No valid delivery IDs provided']);
    exit();
}

try {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    // Get all pallet IDs from the delivery_pallets table for the given delivery IDs
    $placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));
    $types = str_repeat('i', count($delivery_ids));
    
    $sql = "
        SELECT DISTINCT dp.inventory_pallet_id 
        FROM delivery_pallets dp 
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id 
        WHERE dp.delivery_id IN ($placeholders)
        AND ip.status IN ('Delivered to Project', 'Delivered to Warehouse', 'Cleared Customs', 'In Warehouse')
        ORDER BY dp.inventory_pallet_id
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    $stmt->bind_param($types, ...$delivery_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pallet_ids = [];
    while ($row = $result->fetch_assoc()) {
        $pallet_ids[] = intval($row['inventory_pallet_id']);
    }
    
    $stmt->close();
    $conn->close();
    
    if (empty($pallet_ids)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No pallets found for the selected containers']);
        exit();
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'pallet_ids' => $pallet_ids,
        'count' => count($pallet_ids)
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>