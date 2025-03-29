<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$delivery_id = intval($_GET['delivery_id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch POD path and verify access
$stmt = $conn->prepare("
    SELECT d.proof_of_delivery, p.user_id, u.username, p.id as project_id
    FROM deliveries d 
    JOIN projects p ON d.project_id = p.id 
    JOIN users u ON p.user_id = u.id 
    WHERE d.id = ?
");
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$stmt->bind_result($pod_path, $project_user_id, $username, $project_id);
$stmt->fetch();
$stmt->close();

// Check if user has access
if ($_SESSION['role'] === 'customer' && $_SESSION['user_id'] !== $project_user_id) {
    die("Access denied.");
}

// Construct path with Solterra-Logistics-Portal subfolder
$web_root  = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$subfolder = '/Solterra-Logistics-Portal';
$full_path = $web_root . $subfolder . '/' . ltrim($pod_path, '/');

// Debug info
error_log("Attempting to access POD file for Delivery #{$delivery_id}:");
error_log("  Web root:      " . $web_root);
error_log("  Subfolder:     " . $subfolder);
error_log("  POD path (DB): " . $pod_path);
error_log("  Full path:     " . $full_path);
error_log("  File exists?   " . (file_exists($full_path) ? 'Yes' : 'No'));

// Serve the file if it exists
if (file_exists($full_path)) {
    // Clean output buffer to avoid any stray characters
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Determine Content-Type from extension
    $file_extension = strtolower(pathinfo($pod_path, PATHINFO_EXTENSION));
    switch ($file_extension) {
        case 'pdf':
            $content_type = 'application/pdf';
            break;
        case 'jpg':
        case 'jpeg':
            $content_type = 'image/jpeg';
            break;
        case 'png':
            $content_type = 'image/png';
            break;
        default:
            die("Unsupported file type: " . htmlspecialchars($file_extension));
    }

    header('Content-Type: ' . $content_type);
    header('Content-Disposition: inline; filename="' . basename($pod_path) . '"');
    // Avoid partial data by not using Content-Length
    // header('Content-Length: ' . filesize($full_path));

    // Read the actual file contents
    readfile($full_path);
    exit();
} else {
    echo "File not found at path: " . htmlspecialchars($full_path);
    error_log("POD file not found: " . $full_path);
    error_log("Current directory: " . getcwd());
    error_log("Script location:   " . __FILE__);
}

$conn->close();
