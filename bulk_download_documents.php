<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['document_ids']) || !is_array($input['document_ids'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid document IDs provided']);
    exit();
}

$document_ids = array_filter(array_map('intval', $input['document_ids']));
if (empty($document_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid document IDs provided']);
    exit();
}

// Build query to get documents with permission checking
$placeholders = str_repeat('?,', count($document_ids) - 1) . '?';
$base_sql = "
    SELECT 
        pd.id,
        pd.original_file_name,
        pd.file_path,
        pd.project_id,
        p.project_name
    FROM project_documents pd
    JOIN projects p ON pd.project_id = p.id
    WHERE pd.id IN ($placeholders) AND pd.is_active = 1
";

$params = $document_ids;
$param_types = str_repeat('i', count($document_ids));

// Add permission-based filtering
if ($user_role === 'global_admin') {
    // Global admin can access all documents - no additional filtering needed
} else {
    // Admin and regular users can only access their account's documents
    $base_sql .= " AND p.account_id IN (
        SELECT account_id 
        FROM customer_account_users 
        WHERE user_id = ?
    )";
    $params[] = $user_id;
    $param_types .= "i";
}

try {
    $stmt = $conn->prepare($base_sql);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();
    
    if (empty($documents)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No accessible documents found']);
        exit();
    }
    
    // Create temporary directory for the zip file
    $temp_dir = sys_get_temp_dir();
    $zip_filename = 'documents_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.zip';
    $zip_path = $temp_dir . DIRECTORY_SEPARATOR . $zip_filename;
    
    // Create ZIP archive
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not create zip file']);
        exit();
    }
    
    $added_files = 0;
    $file_counter = [];
    
    foreach ($documents as $doc) {
        $file_path = $doc['file_path'];
        
        // Check if file exists
        if (!file_exists($file_path)) {
            error_log("File not found: " . $file_path);
            continue;
        }
        
        // Create a clean filename for the zip
        $clean_filename = $doc['original_file_name'];
        $project_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $doc['project_name']);
        
        // Handle duplicate filenames by adding a counter
        $zip_filename_base = $project_name . '/' . $clean_filename;
        $zip_filename_final = $zip_filename_base;
        
        if (isset($file_counter[$zip_filename_final])) {
            $file_counter[$zip_filename_final]++;
            $pathinfo = pathinfo($clean_filename);
            $name = $pathinfo['filename'];
            $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
            $zip_filename_final = $project_name . '/' . $name . '_' . $file_counter[$zip_filename_final] . $extension;
        } else {
            $file_counter[$zip_filename_final] = 0;
        }
        
        // Add file to zip
        if ($zip->addFile($file_path, $zip_filename_final)) {
            $added_files++;
        }
    }
    
    if ($added_files === 0) {
        $zip->close();
        unlink($zip_path);
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No files could be added to archive']);
        exit();
    }
    
    // Close the zip file
    $zip->close();
    
    // Check if zip file was created successfully
    if (!file_exists($zip_path)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Zip file creation failed']);
        exit();
    }
    
    // Set headers for file download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zip_filename) . '"');
    header('Content-Length: ' . filesize($zip_path));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output the file
    readfile($zip_path);
    
    // Clean up - delete the temporary zip file
    unlink($zip_path);
    
} catch (Exception $e) {
    error_log("Error in bulk_download_documents.php: " . $e->getMessage());
    
    // Clean up zip file if it exists
    if (isset($zip_path) && file_exists($zip_path)) {
        unlink($zip_path);
    }
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

$conn->close();
exit();
?>
