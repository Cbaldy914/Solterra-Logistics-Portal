<?php
// Clean output buffer and suppress any warnings
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Set content type header
header('Content-Type: application/json');

session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get parameters
$project_id = intval($_GET['project_id'] ?? 0);
$document_type = trim($_GET['document_type'] ?? '');
$document_sub_type = trim($_GET['document_sub_type'] ?? '');
// New: support multi-type and multi-subtype filters (CSV)
$document_type_in = trim($_GET['document_type_in'] ?? '');
$document_sub_type_in = trim($_GET['document_sub_type_in'] ?? '');
$limit = intval($_GET['limit'] ?? 0); // 0 means no limit

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Verify project access based on user role
if ($user_role === 'admin') {
    // Admin users can only access projects in their account
    $stmt = $conn->prepare("
        SELECT p.id 
        FROM projects p 
        JOIN customer_account_users cau ON p.account_id = cau.account_id 
        WHERE p.id = ? AND cau.user_id = ?
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
} elseif ($user_role === 'global_admin') {
    // Global admin can access any project
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
} else {
    // Regular users and DDPm can only access their account's projects
    $stmt = $conn->prepare("
        SELECT p.id 
        FROM projects p 
        JOIN customer_account_users cau ON p.account_id = cau.account_id 
        WHERE p.id = ? AND cau.user_id = ?
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
}

// Initialize documents array
$documents = [];
$total_count = 0;

// Try to query project_documents table - handle gracefully if table doesn't exist
try {
    // Check if project_documents table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'project_documents'");
    
    if ($table_check && $table_check->num_rows > 0) {
        // Build query for general documents
        $sql = "
            SELECT 
                pd.id,
                pd.original_file_name,
                pd.file_size,
                pd.mime_type,
                pd.uploaded_at,
                pd.description,
                pd.file_path,
                u.username as uploaded_by_name
            FROM project_documents pd
            LEFT JOIN users u ON pd.uploaded_by = u.id
            WHERE pd.project_id = ? AND pd.is_active = 1
        ";

        $params = [$project_id];
        $param_types = "i";

        // Apply document_type filters (IN takes precedence)
        if (!empty($document_type_in)) {
            $types = array_values(array_filter(array_map('trim', explode(',', $document_type_in))));
            if (!empty($types)) {
                $placeholders = implode(',', array_fill(0, count($types), '?'));
                $sql .= " AND pd.document_type IN ($placeholders)";
                foreach ($types as $t) { $params[] = $t; $param_types .= 's'; }
            }
        } elseif (!empty($document_type)) {
            $sql .= " AND pd.document_type = ?";
            $params[] = $document_type;
            $param_types .= "s";
        }

        // Apply sub_type filters (IN takes precedence)
        if (!empty($document_sub_type_in)) {
            $subs = array_values(array_filter(array_map('trim', explode(',', $document_sub_type_in))));
            if (!empty($subs)) {
                $placeholders = implode(',', array_fill(0, count($subs), '?'));
                $sql .= " AND pd.document_sub_type IN ($placeholders)";
                foreach ($subs as $st) { $params[] = $st; $param_types .= 's'; }
            }
        } elseif (!empty($document_sub_type)) {
            $sql .= " AND pd.document_sub_type = ?";
            $params[] = $document_sub_type;
            $param_types .= "s";
        }

        $sql .= " ORDER BY pd.uploaded_at DESC";

        if ($limit > 0) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
            $param_types .= "i";
        }

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                // Format file size
                $size = $row['file_size'];
                if ($size < 1024) {
                    $formatted_size = $size . ' B';
                } elseif ($size < 1024 * 1024) {
                    $formatted_size = round($size / 1024, 1) . ' KB';
                } else {
                    $formatted_size = round($size / (1024 * 1024), 1) . ' MB';
                }
                
                // Get file icon based on MIME type
                $icon = 'fas fa-file';
                $mime_type = $row['mime_type'];
                if (strpos($mime_type, 'pdf') !== false) {
                    $icon = 'fas fa-file-pdf';
                } elseif (strpos($mime_type, 'image') !== false) {
                    $icon = 'fas fa-file-image';
                } elseif (strpos($mime_type, 'word') !== false || strpos($mime_type, 'document') !== false) {
                    $icon = 'fas fa-file-word';
                } elseif (strpos($mime_type, 'excel') !== false || strpos($mime_type, 'sheet') !== false) {
                    $icon = 'fas fa-file-excel';
                } elseif (strpos($mime_type, 'text') !== false) {
                    $icon = 'fas fa-file-alt';
                }
                
                $documents[] = [
                    'id' => $row['id'],
                    'filename' => $row['original_file_name'],
                    'size' => $formatted_size,
                    'size_bytes' => $row['file_size'],
                    'mime_type' => $row['mime_type'],
                    'uploaded_at' => $row['uploaded_at'],
                    'uploaded_by' => $row['uploaded_by_name'],
                    'description' => $row['description'],
                    'icon' => $icon,
                    'file_path' => $row['file_path']
                ];
            }
            $stmt->close();

            // Get total count if limit was applied
            $total_count = count($documents);
            if ($limit > 0) {
                $count_sql = "
                    SELECT COUNT(*) as total
                    FROM project_documents pd
                    WHERE pd.project_id = ? AND pd.is_active = 1
                ";
                
                $count_params = [$project_id];
                $count_param_types = "i";
                
                if (!empty($document_type_in)) {
                    $types = array_values(array_filter(array_map('trim', explode(',', $document_type_in))));
                    if (!empty($types)) {
                        $placeholders = implode(',', array_fill(0, count($types), '?'));
                        $count_sql .= " AND pd.document_type IN ($placeholders)";
                        foreach ($types as $t) { $count_params[] = $t; $count_param_types .= 's'; }
                    }
                } elseif (!empty($document_type)) {
                    $count_sql .= " AND pd.document_type = ?";
                    $count_params[] = $document_type;
                    $count_param_types .= "s";
                }
                if (!empty($document_sub_type_in)) {
                    $subs = array_values(array_filter(array_map('trim', explode(',', $document_sub_type_in))));
                    if (!empty($subs)) {
                        $placeholders = implode(',', array_fill(0, count($subs), '?'));
                        $count_sql .= " AND pd.document_sub_type IN ($placeholders)";
                        foreach ($subs as $st) { $count_params[] = $st; $count_param_types .= 's'; }
                    }
                } elseif (!empty($document_sub_type)) {
                    $count_sql .= " AND pd.document_sub_type = ?";
                    $count_params[] = $document_sub_type;
                    $count_param_types .= "s";
                }
                
                $count_stmt = $conn->prepare($count_sql);
                if ($count_stmt) {
                    $count_stmt->bind_param($count_param_types, ...$count_params);
                    $count_stmt->execute();
                    $count_result = $count_stmt->get_result();
                    $total_count = $count_result->fetch_assoc()['total'];
                    $count_stmt->close();
                }
            } else {
                $total_count = count($documents);
            }
        }
    }
    // If table doesn't exist, we'll just return empty results gracefully
    
} catch (Exception $e) {
    // Log error but don't fail - just return empty results
    error_log("Error querying project_documents: " . $e->getMessage());
    $documents = [];
    $total_count = 0;
}

// Clean any output that might have been generated
ob_end_clean();

// Send clean JSON response
echo json_encode([
    'success' => true,
    'documents' => $documents,
    'total_count' => $total_count,
    'showing_count' => count($documents)
]);

$conn->close();
exit();
?>

