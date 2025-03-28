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
    SELECT d.proof_of_delivery, p.user_id 
    FROM deliveries d 
    JOIN projects p ON d.project_id = p.id 
    WHERE d.id = ?
");
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$stmt->bind_result($pod_path, $project_user_id);
$stmt->fetch();
$stmt->close();

// Check if user has access
if ($_SESSION['role'] == 'customer' && $_SESSION['user_id'] != $project_user_id) {
    die("Access denied.");
}

// Serve the file
if (file_exists($pod_path)) {
    // Get the file extension to determine Content-Type
    $file_extension = strtolower(pathinfo($pod_path, PATHINFO_EXTENSION));
    
    // Set the appropriate Content-Type header
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
            die("Unsupported file type.");
    }

    header('Content-Type: ' . $content_type);
    header('Content-Disposition: inline; filename="' . basename($pod_path) . '"');
    header('Content-Length: ' . filesize($pod_path));
    readfile($pod_path);
    exit();
} else {
    echo "File not found.";
}

$conn->close();
?>