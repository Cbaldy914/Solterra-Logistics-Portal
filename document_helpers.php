<?php
/**
 * Document Management Helper Functions
 * Handles the new project_documents table structure with sub-types and entity linking
 */

// Security Constants
define('MAX_DOCUMENT_SIZE', 50 * 1024 * 1024); // 50MB
define('ALLOWED_DOCUMENT_TYPES', [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain' => 'txt',
    'text/csv' => 'csv'
]);

/**
 * Determine document sub-type based on context
 */
function determineDocumentSubType($document_type, $context = []) {
    switch ($document_type) {
        case 'pods':
            if (isset($context['delivery_status'])) {
                $status = strtolower($context['delivery_status']);
                if (strpos($status, 'warehouse') !== false || strpos($status, 'received') !== false) {
                    return 'Warehouse POD';
                } elseif (strpos($status, 'project') !== false || strpos($status, 'delivered') !== false) {
                    return 'Project POD';
                }
            }
            // Fallback to warehouse_id check
            if (isset($context['warehouse_id']) && $context['warehouse_id']) {
                return 'Warehouse POD';
            }
            return 'Project POD';
            
        case 'invoices':
            if (isset($context['invoice_type'])) {
                switch (strtolower($context['invoice_type'])) {
                    case 'solterra':
                        return 'Solterra Invoices';
                    case 'oem':
                        return 'OEM Invoices';
                    case 'warehouse':
                        return 'Warehouse Invoices';
                    case 'freight':
                        return 'Freight Invoices';
                }
            }
            return 'General Invoices';
            
        case 'bills_of_lading':
            if (isset($context['bol_type'])) {
                switch (strtolower($context['bol_type'])) {
                    case 'inbound':
                        return 'Inbound BOL';
                    case 'outbound':
                        return 'Outbound BOL';
                    case 'intercompany':
                        return 'Intercompany BOL';
                }
            }
            return 'Shipping BOL';
            
        case 'warehousing':
            if (isset($context['warehouse_type'])) {
                switch (strtolower($context['warehouse_type'])) {
                    case 'storage':
                        return 'Storage Receipts';
                    case 'handling':
                        return 'Handling Receipts';
                    case 'inspection':
                        return 'Inspection Reports';
                }
            }
            return 'Warehouse Documents';

        case 'carrier_documents':
            if (isset($context['carrier_doc_type'])) {
                switch (strtolower($context['carrier_doc_type'])) {
                    case 'coi':
                        return 'COI';
                    case 'insurance_cert':
                        return 'Insurance Certificate';
                    case 'carrier_contract':
                        return 'Carrier Contract';
                }
            }
            return 'Other';

        default:
            return null;
    }
}

/**
 * Process file upload and validate
 */
function processDocumentUpload($file_data, $document_type) {
    if (!isset($file_data) || $file_data['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("No file uploaded or upload error occurred.");
    }
    
    // Validate file size
    if ($file_data['size'] > MAX_DOCUMENT_SIZE) {
        throw new Exception("File size exceeds the maximum limit of 50MB.");
    }
    
    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_data['tmp_name']);
    finfo_close($finfo);
    
    if (!array_key_exists($mime_type, ALLOWED_DOCUMENT_TYPES)) {
        throw new Exception("Invalid file type. Allowed types: " . implode(', ', array_unique(array_values(ALLOWED_DOCUMENT_TYPES))));
    }
    
    // Validate file extension matches MIME
    $extension = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
    if ($extension !== ALLOWED_DOCUMENT_TYPES[$mime_type]) {
        throw new Exception("File extension does not match file type.");
    }
    
    return [
        'original_name' => $file_data['name'],
        'size' => $file_data['size'],
        'mime_type' => $mime_type,
        'extension' => $extension,
        'tmp_name' => $file_data['tmp_name']
    ];
}

/**
 * Generate secure filename for document storage
 */
function generateSecureFilename($original_name, $prefix = '') {
    $pathinfo = pathinfo($original_name);
    $filename = $pathinfo['filename'];
    $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
    
    // Sanitize filename
    $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '_', $filename);
    $sanitized = substr($sanitized, 0, 100);
    
    // Add timestamp and random hash for uniqueness
    $timestamp = time();
    $hash = substr(md5(uniqid()), 0, 8);
    
    $final_name = $timestamp . '_' . $hash;
    if ($prefix) {
        $final_name = $prefix . '_' . $final_name;
    }
    $final_name .= '_' . $sanitized . $extension;
    
    return $final_name;
}

/**
 * Create directory path for document storage
 */
function createDocumentPath($project_id, $document_type) {
    $base_path = 'uploads/project_documents/' . $project_id . '/' . $document_type . '/';
    
    if (!is_dir($base_path)) {
        if (!mkdir($base_path, 0755, true)) {
            throw new Exception("Failed to create upload directory: " . $base_path);
        }
    }
    
    return $base_path;
}

/**
 * Save document to project_documents table
 */
function saveDocumentToProjectDocuments($conn, $document_data) {
    // Validate required fields - file_path OR tmp_name should be provided
    // Note: project_id is now optional (can be NULL for warehouse-level documents)
    $required_fields = ['document_type', 'original_name', 'file_size', 'mime_type', 'uploaded_by'];
    foreach ($required_fields as $field) {
        if (!isset($document_data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate that at least one context is provided
    if (empty($document_data['project_id']) && empty($document_data['warehouse_id']) && empty($document_data['carrier_id']) && empty($document_data['entity_context'])) {
        throw new Exception("At least one of project_id, warehouse_id, carrier_id, or entity_context must be provided");
    }
    
    // Either tmp_name (for upload) or file_path (for existing file) should be provided
    if (!isset($document_data['tmp_name']) && !isset($document_data['file_path'])) {
        throw new Exception("Missing required field: tmp_name or file_path");
    }
    
    // Handle file path - either move uploaded file or use existing path
    if (isset($document_data['tmp_name'])) {
        // Generate unique server filename
        $server_filename = generateSecureFilename($document_data['original_name']);
        // Use project_id if available, otherwise use a generic path
        $context_id = $document_data['project_id'] ?? 'warehouse_' . ($document_data['warehouse_id'] ?? 'global');
        $document_path = createDocumentPath($context_id, $document_data['document_type']);
        $full_path = $document_path . $server_filename;
        
        // Move uploaded file
        if (!move_uploaded_file($document_data['tmp_name'], $full_path)) {
            throw new Exception("Failed to move uploaded file to: " . $full_path);
        }
    } else {
        // Use existing file path
        $full_path = $document_data['file_path'];
        $server_filename = basename($full_path);
    }
    
    // Insert into database
    $stmt = $conn->prepare("
        INSERT INTO project_documents (
            project_id, document_type, document_sub_type, delivery_id, warehouse_id,
            pallet_id, module_id, project_invoice_id, carrier_id, entity_context, file_name, original_file_name,
            file_path, file_size, mime_type, uploaded_by, description
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Extract values into variables for bind_param (required for pass-by-reference)
    $project_id = $document_data['project_id'];
    $document_type = $document_data['document_type'];
    $document_sub_type = $document_data['document_sub_type'] ?? null;
    $delivery_id = $document_data['delivery_id'] ?? null;
    $warehouse_id = $document_data['warehouse_id'] ?? null;
    $pallet_id = $document_data['pallet_id'] ?? null;
    $module_id = $document_data['module_id'] ?? null;
    $entity_context = $document_data['entity_context'] ?? null;
    $project_invoice_id = $document_data['project_invoice_id'] ?? null;
    $carrier_id = $document_data['carrier_id'] ?? null;
    $original_name = $document_data['original_name'];
    $file_size = $document_data['file_size'];
    $mime_type = $document_data['mime_type'];
    $uploaded_by = $document_data['uploaded_by'];
    $description = $document_data['description'] ?? '';

    $stmt->bind_param("issiiiiiissssisis",
        $project_id,
        $document_type,
        $document_sub_type,
        $delivery_id,
        $warehouse_id,
        $pallet_id,
        $module_id,
        $project_invoice_id,
        $carrier_id,
        $entity_context,
        $server_filename,
        $original_name,
        $full_path,
        $file_size,
        $mime_type,
        $uploaded_by,
        $description
    );
    
    if (!$stmt->execute()) {
        // Clean up file if database insert fails
        @unlink($full_path);
        throw new Exception("Failed to save document to database: " . $stmt->error);
    }
    
    $document_id = $conn->insert_id;
    $stmt->close();
    
    return [
        'document_id' => $document_id,
        'file_path' => $full_path,
        'server_filename' => $server_filename
    ];
}

/**
 * Upload POD specifically (convenience function)
 */
function uploadPODDocument($conn, $project_id, $delivery_id, $file_data, $delivery_status, $warehouse_id = null) {
    try {
        // Process the uploaded file
        $processed_file = processDocumentUpload($file_data, 'pods');
        
        // Determine sub-type based on delivery status
        $context = [
            'delivery_status' => $delivery_status,
            'warehouse_id' => $warehouse_id
        ];
        $sub_type = determineDocumentSubType('pods', $context);
        
        // Prepare document data
        $document_data = [
            'project_id' => $project_id,
            'document_type' => 'pods',
            'document_sub_type' => $sub_type,
            'delivery_id' => $delivery_id,
            'warehouse_id' => $warehouse_id,
            'original_name' => $processed_file['original_name'],
            'file_size' => $processed_file['size'],
            'mime_type' => $processed_file['mime_type'],
            'uploaded_by' => $_SESSION['user_id'],
            'tmp_name' => $processed_file['tmp_name'],
            'entity_context' => "POD for delivery ID: $delivery_id"
        ];
        
        // Save to project_documents table
        $result = saveDocumentToProjectDocuments($conn, $document_data);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("POD Upload Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Get documents from project_documents table with filters
 */
function getProjectDocuments($conn, $filters = []) {
    $where_conditions = ["pd.is_active = 1"];
    $params = [];
    $param_types = "";
    
    // Build WHERE conditions
    if (isset($filters['project_id'])) {
        $where_conditions[] = "pd.project_id = ?";
        $params[] = $filters['project_id'];
        $param_types .= "i";
    }
    
    if (isset($filters['document_type'])) {
        $where_conditions[] = "pd.document_type = ?";
        $params[] = $filters['document_type'];
        $param_types .= "s";
    }
    
    if (isset($filters['document_sub_type'])) {
        $where_conditions[] = "pd.document_sub_type = ?";
        $params[] = $filters['document_sub_type'];
        $param_types .= "s";
    }
    
    if (isset($filters['delivery_id'])) {
        $where_conditions[] = "pd.delivery_id = ?";
        $params[] = $filters['delivery_id'];
        $param_types .= "i";
    }

    if (isset($filters['carrier_id'])) {
        $where_conditions[] = "pd.carrier_id = ?";
        $params[] = $filters['carrier_id'];
        $param_types .= "i";
    }

    // Build query
    $sql = "
        SELECT 
            pd.id,
            pd.project_id,
            pd.document_type,
            pd.document_sub_type,
            pd.delivery_id,
            pd.warehouse_id,
            pd.original_file_name,
            pd.file_path,
            pd.file_size,
            pd.mime_type,
            pd.uploaded_at,
            pd.description,
            u.username as uploaded_by_name,
            d.bol_number,
            d.status_of_delivery
        FROM project_documents pd
        LEFT JOIN users u ON pd.uploaded_by = u.id
        LEFT JOIN deliveries d ON pd.delivery_id = d.id
        WHERE " . implode(" AND ", $where_conditions) . "
        ORDER BY pd.uploaded_at DESC
    ";
    
    if (isset($filters['limit'])) {
        $sql .= " LIMIT " . intval($filters['limit']);
    }
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $documents = [];
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
        
        $row['formatted_size'] = $formatted_size;
        $documents[] = $row;
    }
    
    $stmt->close();
    return $documents;
}

/**
 * Delete document (hard delete as per business rules)
 */
function deleteProjectDocument($conn, $document_id, $user_id) {
    // Get document info first
    $stmt = $conn->prepare("SELECT file_path, uploaded_by FROM project_documents WHERE id = ? AND is_active = 1");
    $stmt->bind_param("i", $document_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $document = $result->fetch_assoc();
    $stmt->close();
    
    if (!$document) {
        throw new Exception("Document not found or already deleted.");
    }
    
    // Check if user has permission (uploaded by them or they're admin/global_admin)
    $user_role = $_SESSION['role'] ?? '';
    if ($document['uploaded_by'] != $user_id && !in_array($user_role, ['admin', 'global_admin', 'customer_admin'])) {
        throw new Exception("You don't have permission to delete this document.");
    }
    
    // Delete from database
    $delete_stmt = $conn->prepare("DELETE FROM project_documents WHERE id = ?");
    $delete_stmt->bind_param("i", $document_id);
    
    if ($delete_stmt->execute()) {
        // Delete physical file
        if (file_exists($document['file_path'])) {
            @unlink($document['file_path']);
        }
        $delete_stmt->close();
        return true;
    } else {
        $delete_stmt->close();
        throw new Exception("Failed to delete document from database.");
    }
}

/**
 * Import an existing file on disk into project_documents.
 * Moves the file into the proper project_documents folder and inserts a DB row.
 * $document_data keys (required): project_id, document_type, original_name, uploaded_by
 * Optional: document_sub_type, delivery_id, warehouse_id, pallet_id, module_id, project_invoice_id, entity_context, description
 */
function importExistingFileToProjectDocuments($conn, $document_data, $source_path) {
    if (!isset($document_data['project_id'], $document_data['document_type'], $document_data['original_name'], $document_data['uploaded_by'])) {
        throw new Exception('Missing required document data for import');
    }
    if (!is_file($source_path)) {
        throw new Exception('Source file does not exist for import');
    }

    // Determine mime and extension
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $source_path);
    finfo_close($finfo);

    // Fallback extension if unknown
    $ext = 'bin';
    if (isset(ALLOWED_DOCUMENT_TYPES[$mime_type])) { $ext = ALLOWED_DOCUMENT_TYPES[$mime_type]; }
    else {
        // Try by original name
        $orig_ext = strtolower(pathinfo($document_data['original_name'], PATHINFO_EXTENSION));
        if ($orig_ext) { $ext = $orig_ext; }
    }

    $server_filename = generateSecureFilename($document_data['original_name']);
    // Ensure extension matches detected ext
    if (strtolower(pathinfo($server_filename, PATHINFO_EXTENSION)) !== $ext) {
        $server_filename = preg_replace('/\.[^.]+$/', '', $server_filename) . '.' . $ext;
    }

    // Build destination path and move
    $dest_dir = createDocumentPath($document_data['project_id'], $document_data['document_type']);
    $dest_path = $dest_dir . $server_filename;

    if (!@rename($source_path, $dest_path)) {
        // Fallback to copy if rename fails
        if (!@copy($source_path, $dest_path)) {
            throw new Exception('Failed to move imported file');
        }
        @unlink($source_path);
    }

    // Sizes
    $file_size = filesize($dest_path);
    if ($file_size === false) { $file_size = 0; }

    // Insert DB row
    $stmt = $conn->prepare("
        INSERT INTO project_documents (
            project_id, document_type, document_sub_type, delivery_id, warehouse_id,
            pallet_id, module_id, project_invoice_id, carrier_id, entity_context, file_name, original_file_name,
            file_path, file_size, mime_type, uploaded_by, description
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $project_id = $document_data['project_id'];
    $document_type = $document_data['document_type'];
    $document_sub_type = $document_data['document_sub_type'] ?? null;
    $delivery_id = $document_data['delivery_id'] ?? null;
    $warehouse_id = $document_data['warehouse_id'] ?? null;
    $pallet_id = $document_data['pallet_id'] ?? null;
    $module_id = $document_data['module_id'] ?? null;
    $project_invoice_id = $document_data['project_invoice_id'] ?? null;
    $carrier_id = $document_data['carrier_id'] ?? null;
    $entity_context = $document_data['entity_context'] ?? null;
    $original_name = $document_data['original_name'];
    $uploaded_by = $document_data['uploaded_by'];
    $description = $document_data['description'] ?? '';

    $stmt->bind_param(
        "issiiiiiissssisis",
        $project_id,
        $document_type,
        $document_sub_type,
        $delivery_id,
        $warehouse_id,
        $pallet_id,
        $module_id,
        $project_invoice_id,
        $carrier_id,
        $entity_context,
        $server_filename,
        $original_name,
        $dest_path,
        $file_size,
        $mime_type,
        $uploaded_by,
        $description
    );

    if (!$stmt->execute()) {
        // Rollback file if DB insert fails
        @unlink($dest_path);
        throw new Exception('Failed to import file: ' . $stmt->error);
    }
    $doc_id = $conn->insert_id;
    $stmt->close();

    return [ 'document_id' => $doc_id, 'file_path' => $dest_path, 'server_filename' => $server_filename ];
}
?>
