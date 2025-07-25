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

// Check if manufacturer_id is provided
if (!isset($_GET['manufacturer_id']) || empty($_GET['manufacturer_id'])) {
    echo json_encode(['error' => 'Manufacturer ID is required']);
    exit;
}

$manufacturer_id = intval($_GET['manufacturer_id']);

// Fetch all active locations for the manufacturer
$stmt = $conn->prepare("
    SELECT 
        id,
        location_name,
        street_address,
        city,
        state,
        zip_code,
        is_primary
    FROM manufacturer_locations 
    WHERE manufacturer_id = ? AND is_active = TRUE
    ORDER BY is_primary DESC, location_name ASC
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