<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and has appropriate role (admin/global_admin)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['global_admin', 'admin'])) {
    http_response_code(403); // Forbidden
    die("Access Denied: You do not have permission to view this file.");
}

$pallet_id = isset($_GET['pallet_id']) ? intval($_GET['pallet_id']) : 0;

if ($pallet_id <= 0) {
    http_response_code(400); // Bad Request
    die("Invalid Pallet ID provided.");
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500); // Internal Server Error
    die("Database connection failed.");
}

// Fetch the flash test data path for the given pallet ID
$sql = "SELECT flash_test_data FROM inventory_pallets WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Failed to prepare statement to fetch flash test data: " . $conn->error);
    http_response_code(500);
    die("Error fetching file information.");
}

$stmt->bind_param("i", $pallet_id);
$stmt->execute();
$stmt->bind_result($flash_test_path);

if (!$stmt->fetch()) {
    $stmt->close();
    $conn->close();
    http_response_code(404); // Not Found
    die("Flash test data not found for this pallet or pallet does not exist.");
}

$stmt->close();
$conn->close();

// Check if a path is actually stored
if (empty($flash_test_path)) {
    http_response_code(404);
    die("No flash test file path associated with this pallet.");
}

// Construct the full, absolute path to the file on the server
// IMPORTANT: Ensure the base path is correct for your server setup.
$base_path = $_SERVER['DOCUMENT_ROOT'] . '/Solterra-Logistics-Portal/'; // Adjust if your structure differs
$full_path = realpath($base_path . ltrim($flash_test_path, '/'));

// Security check: Ensure the resolved path is within the intended directory
if ($full_path === false || strpos($full_path, realpath($base_path . 'uploads/flash_tests/')) !== 0) {
    error_log("Attempted path traversal or invalid path for pallet {$pallet_id}: {$flash_test_path} -> {$full_path}");
    http_response_code(404); // Not Found (or 403 Forbidden)
    die("File not found or access denied.");
}

// Serve the file if it exists
if (file_exists($full_path)) {
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Determine Content-Type (using mime_content_type is generally preferred)
    $content_type = mime_content_type($full_path);
    if ($content_type === false) {
        // Fallback based on extension if mime type fails
        $file_extension = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
        switch ($file_extension) {
            case 'pdf': $content_type = 'application/pdf'; break;
            case 'csv': $content_type = 'text/csv'; break;
            case 'txt': $content_type = 'text/plain'; break;
            case 'jpg':
            case 'jpeg': $content_type = 'image/jpeg'; break;
            case 'png': $content_type = 'image/png'; break;
            default: $content_type = 'application/octet-stream'; // Generic binary type
        }
    }

    header('Content-Type: ' . $content_type);
    // Use 'inline' to attempt display in browser, 'attachment' to force download
    header('Content-Disposition: inline; filename="' . basename($flash_test_path) . '"');
    header('Content-Length: ' . filesize($full_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public'); // HTTP 1.0 compatibility

    // Read the file and send its content to the browser
    if (!readfile($full_path)) {
        // Log error if readfile fails
        error_log("Failed to read flash test file: " . $full_path);
        http_response_code(500);
        // Avoid echoing sensitive info like path here in production
        die("Error reading file."); 
    }
    exit();
} else {
    error_log("Flash test file not found at path: " . $full_path . " (Original DB path: {$flash_test_path})");
    http_response_code(404);
    die("File not found on server.");
}
?> 