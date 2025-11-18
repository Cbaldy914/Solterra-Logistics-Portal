<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized');
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    die('Database connection failed');
}

// Get document ID
$document_id = intval($_GET['id'] ?? 0);
if ($document_id <= 0) {
    http_response_code(400);
    die('Invalid document ID');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get document details and verify access
$sql = "
    SELECT 
        pd.file_path,
        pd.original_file_name,
        pd.mime_type,
        pd.project_id,
        p.project_name
    FROM project_documents pd
    JOIN projects p ON pd.project_id = p.id
    WHERE pd.id = ? AND pd.is_active = 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $document_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    die('Document not found');
}

$document = $result->fetch_assoc();
$stmt->close();

// Verify user has access to this project
$project_id = $document['project_id'];

if ($user_role === 'admin') {
    // Admin users can only access projects in their account
    $access_stmt = $conn->prepare("
        SELECT COUNT(*) as has_access
        FROM projects p 
        JOIN customer_account_users cau ON p.account_id = cau.account_id 
        WHERE p.id = ? AND cau.user_id = ?
    ");
    $access_stmt->bind_param("ii", $project_id, $user_id);
    $access_stmt->execute();
    $access_result = $access_stmt->get_result();
    $access_data = $access_result->fetch_assoc();
    
    if ($access_data['has_access'] == 0) {
        http_response_code(403);
        die('No access to this project');
    }
    $access_stmt->close();
} elseif ($user_role === 'global_admin') {
    // Global admin can access any project - no additional check needed
} else {
    // Regular users and DDPm can only access their account's projects
    $access_stmt = $conn->prepare("
        SELECT COUNT(*) as has_access
        FROM projects p 
        JOIN customer_account_users cau ON p.account_id = cau.account_id 
        WHERE p.id = ? AND cau.user_id = ?
    ");
    $access_stmt->bind_param("ii", $project_id, $user_id);
    $access_stmt->execute();
    $access_result = $access_stmt->get_result();
    $access_data = $access_result->fetch_assoc();
    
    if ($access_data['has_access'] == 0) {
        http_response_code(403);
        die('No access to this project');
    }
    $access_stmt->close();
}

// Check if file exists on disk
$file_path = $document['file_path'];
if (!file_exists($file_path)) {
    http_response_code(404);
    die('File not found on disk');
}

$conn->close();

// Set appropriate headers for file download
$filename = $document['original_file_name'];
$mime_type = $document['mime_type'];

// Security: prevent path traversal attacks
$filename = basename($filename);

// Set headers
header('Content-Type: ' . $mime_type);
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Clear any output buffers
if (ob_get_level()) {
    ob_end_clean();
}

// Output file
readfile($file_path);
exit();
?>

