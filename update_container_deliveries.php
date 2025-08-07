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
$container_number = $input['container_number'] ?? '';

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
    
    $conn->begin_transaction();
    
    // First, remove pallet associations from the original container deliveries
    // This prevents duplication when the new drayage delivery is received
    $placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));
    $types = str_repeat('i', count($delivery_ids));
    
    $sql_unlink = "
        DELETE FROM delivery_pallets 
        WHERE delivery_id IN ($placeholders)
    ";
    
    $stmt_unlink = $conn->prepare($sql_unlink);
    if (!$stmt_unlink) {
        throw new Exception("Failed to prepare unlink statement: " . $conn->error);
    }
    
    $stmt_unlink->bind_param($types, ...$delivery_ids);
    
    if (!$stmt_unlink->execute()) {
        throw new Exception("Failed to unlink pallets from original deliveries: " . $stmt_unlink->error);
    }
    
    $unlinked_count = $stmt_unlink->affected_rows;
    $stmt_unlink->close();
    
    // Update the original container deliveries to mark them as departed from port
    $sql = "
        UPDATE deliveries 
        SET status_of_delivery = 'Departed Port',
            actual_delivery_date = CURDATE()
        WHERE id IN ($placeholders)
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare update statement: " . $conn->error);
    }
    
    $stmt->bind_param($types, ...$delivery_ids);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to update deliveries: " . $stmt->error);
    }
    
    $updated_count = $stmt->affected_rows;
    $stmt->close();
    
    $conn->commit();
    $conn->close();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => "Successfully updated {$updated_count} container deliveries and unlinked {$unlinked_count} pallet associations",
        'updated_count' => $updated_count,
        'unlinked_count' => $unlinked_count
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
        $conn->close();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>