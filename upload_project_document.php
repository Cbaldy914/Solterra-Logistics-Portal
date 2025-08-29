<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if request is POST with file upload
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['document'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Sanitize and validate inputs
$project_id = intval($_POST['project_id'] ?? 0);
$document_type = trim($_POST['document_type'] ?? '');
$document_sub_type = isset($_POST['document_sub_type']) && $_POST['document_sub_type'] !== '' ? trim($_POST['document_sub_type']) : null;
$description = trim($_POST['description'] ?? '');
$user_id = $_SESSION['user_id'];

// Validate document type - supporting all document types now
$allowed_types = ['invoices', 'pods', 'flash_test_data', 'bills_of_lading', 'warehousing', 'modules', 'delivery_packet', 'incident_reports', 'safe_harbor_evidence', 'shipments'];
if (!in_array($document_type, $allowed_types)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid document type']);
    exit();
}

// Verify project access for admin users
if ($_SESSION['role'] === 'admin') {
    $stmt = $conn->prepare("
        SELECT p.id 
        FROM projects p 
        JOIN customer_account_users cau ON p.account_id = cau.account_id 
        WHERE p.id = ? AND cau.user_id = ? AND cau.role = 'admin'
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No access to this project']);
        exit();
    }
    $stmt->close();
} elseif ($_SESSION['role'] === 'global_admin') {
    // Global admin can access any project, just verify it exists
    $stmt = $conn->prepare("SELECT id FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit();
    }
    $stmt->close();
}

// Validate uploaded file
$file = $_FILES['document'];
$allowed_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'csv'];
$allowed_mime_types = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'text/plain',
    'text/csv',
    'application/vnd.ms-office'
];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $error_messages = [
        UPLOAD_ERR_INI_SIZE => 'File is too large (exceeds server limit)',
        UPLOAD_ERR_FORM_SIZE => 'File is too large (exceeds form limit)',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
    ];
    
    $message = $error_messages[$file['error']] ?? 'Unknown upload error';
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

// Validate file size (max 50MB)
$max_file_size = 50 * 1024 * 1024; // 50MB
if ($file['size'] > $max_file_size) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File size exceeds 50MB limit']);
    exit();
}

// Validate file extension and MIME type
$file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($file_extension, $allowed_extensions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File type not allowed. Allowed: ' . implode(', ', $allowed_extensions)]);
    exit();
}

// Get MIME type
$mime_type = mime_content_type($file['tmp_name']);
if (!in_array($mime_type, $allowed_mime_types)) {
    // Fallback to file extension check for some common types
    $extension_mime_map = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'txt' => 'text/plain',
        'csv' => 'text/csv'
    ];
    
    if (isset($extension_mime_map[$file_extension])) {
        $mime_type = $extension_mime_map[$file_extension];
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file type']);
        exit();
    }
}

// Create upload directory structure
$upload_base_dir = 'uploads/project_documents';
$project_upload_dir = $upload_base_dir . '/' . $project_id;
$type_upload_dir = $project_upload_dir . '/' . $document_type;

if (!is_dir($upload_base_dir)) {
    mkdir($upload_base_dir, 0755, true);
}
if (!is_dir($project_upload_dir)) {
    mkdir($project_upload_dir, 0755, true);
}
if (!is_dir($type_upload_dir)) {
    mkdir($type_upload_dir, 0755, true);
}

// Generate unique filename to prevent conflicts
$original_filename = $file['name'];
$safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_filename);
$unique_filename = time() . '_' . uniqid() . '_' . $safe_filename;
$file_path = $type_upload_dir . '/' . $unique_filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $file_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    exit();
}

// Save to database
$stmt = $conn->prepare("
    INSERT INTO project_documents 
    (project_id, document_type, document_sub_type, file_name, original_file_name, file_path, file_size, mime_type, uploaded_by, description) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "isssssisis",
    $project_id,
    $document_type,
    $document_sub_type,
    $unique_filename,
    $original_filename,
    $file_path,
    $file['size'],
    $mime_type,
    $user_id,
    $description
);

if ($stmt->execute()) {
    $document_id = $conn->insert_id;
    echo json_encode([
        'success' => true,
        'message' => 'Document uploaded successfully',
        'document_id' => $document_id,
        'filename' => $original_filename
    ]);
} else {
    // Delete the file if database insert failed
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save document information']);
}

$stmt->close();
$conn->close();
?>

