<?php
require_once 'bootstrap.php';

// Establish database connection
$conn = getDBConnection();
if (!$conn) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Set content type to JSON
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

// Check if manufacturer_id is provided
if (!isset($_GET['manufacturer_id']) || empty($_GET['manufacturer_id'])) {
    echo json_encode(['error' => 'Manufacturer ID is required']);
    exit;
}

$manufacturer_id = intval($_GET['manufacturer_id']);


// Fetch all active locations for the manufacturer
$stmt = $conn->prepare("
    SELECT 
        ml.id,
        ml.location_name,
        ml.street_address,
        ml.city,
        ml.state,
        ml.zip_code,
        ml.is_primary
    FROM manufacturer_locations ml
    JOIN manufacturers m ON m.id = ml.manufacturer_id
    WHERE ml.manufacturer_id = ? AND ml.is_active = TRUE
    ORDER BY ml.is_primary DESC, ml.location_name ASC
");

if (!$stmt) {
    echo json_encode(['error' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param("i", $manufacturer_id);
$stmt->execute();
$result = $stmt->get_result();

$locations = [];
while ($row = $result->fetch_assoc()) {
    // Format the address
    $address_parts = array_filter([
        $row['street_address'],
        $row['city'],
        $row['state'],
        $row['zip_code']
    ]);
    $formatted_address = implode(', ', $address_parts);
    
    $locations[] = [
        'id' => $row['id'],
        'location_name' => $row['location_name'],
        'formatted_address' => $formatted_address,
        'street_address' => $row['street_address'],
        'city' => $row['city'],
        'state' => $row['state'],
        'zip_code' => $row['zip_code'],
        'is_primary' => $row['is_primary']
    ];
}

$stmt->close();

echo json_encode(['locations' => $locations]);
?> 
