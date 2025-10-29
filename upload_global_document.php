<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in and has upload permissions
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Only admin and global_admin can upload
if (!in_array($user_role, ['admin', 'global_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Include helper functions
require_once 'document_helpers.php';
require_once 'notification_helpers.php';

try {
    // Validate required fields
    if (!isset($_POST['project_id']) || !isset($_POST['document_type']) || !isset($_POST['document_sub_type'])) {
        throw new Exception('Missing required fields: project_id, document_type, or document_sub_type');
    }

    $project_id = (int)$_POST['project_id'];
    $document_type = trim($_POST['document_type']);
    $document_sub_type = trim($_POST['document_sub_type']);
    $is_safe_harbor = isset($_POST['is_safe_harbor']) && $_POST['is_safe_harbor'] === '1';

    // Verify user has access to this project
    if ($user_role === 'global_admin') {
        // Global admin can access any project
        $stmt = $conn->prepare("SELECT id, project_name FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
    } else {
        // Admin can only access projects in their account
        $stmt = $conn->prepare("
            SELECT p.id, p.project_name 
            FROM projects p 
            JOIN customer_account_users cau ON p.account_id = cau.account_id 
            WHERE p.id = ? AND cau.user_id = ? AND cau.role = 'admin'
        ");
        $stmt->bind_param("ii", $project_id, $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception('Project not found or access denied');
    }
    $project = $result->fetch_assoc();
    $stmt->close();

    // Validate files
    if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        throw new Exception('No files were uploaded');
    }

    $uploaded_documents = [];
    $safe_harbor_documents = [];

    // Process each file
    for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error for file: ' . $_FILES['files']['name'][$i]);
        }

        // Prepare file data for processing
        $file_data = [
            'name' => $_FILES['files']['name'][$i],
            'type' => $_FILES['files']['type'][$i],
            'tmp_name' => $_FILES['files']['tmp_name'][$i],
            'error' => $_FILES['files']['error'][$i],
            'size' => $_FILES['files']['size'][$i]
        ];

        // Process the uploaded file
        $processed_file = processDocumentUpload($file_data, $document_type);

        // Get description from form
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        if (empty($description)) {
            $description = "Global upload: {$document_sub_type}";
        }

        // Prepare document data
        $document_data = [
            'project_id' => $project_id,
            'document_type' => $document_type,
            'document_sub_type' => $document_sub_type,
            'original_name' => $processed_file['original_name'],
            'file_size' => $processed_file['size'],
            'mime_type' => $processed_file['mime_type'],
            'uploaded_by' => $user_id,
            'tmp_name' => $processed_file['tmp_name'],
            'description' => $description,
            'entity_context' => "Global document upload for project: {$project['project_name']}"
        ];

        // Add optional/derived foreign key references based on form data
        if (isset($_POST['delivery_id']) && !empty($_POST['delivery_id'])) {
            $document_data['delivery_id'] = (int)$_POST['delivery_id'];
        }

        // For shipments, require container number and lookup delivery_id by project + container
        if ($document_type === 'shipments') {
            $container_number = isset($_POST['container_number']) ? trim($_POST['container_number']) : '';
            if ($container_number === '') {
                throw new Exception('Container number is required for shipments.');
            }
            // Find matching delivery on this project by container number
            $dlv = null;
            $stmt = $conn->prepare("SELECT id FROM deliveries WHERE project_id = ? AND container_number = ? LIMIT 1");
            $stmt->bind_param("is", $project_id, $container_number);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $dlv = $res->fetch_assoc();
                $document_data['delivery_id'] = (int)$dlv['id'];
            } else {
                throw new Exception('No delivery found with that container number for the selected project.');
            }
            $stmt->close();
        }
        
        if (isset($_POST['warehouse_id']) && !empty($_POST['warehouse_id'])) {
            $document_data['warehouse_id'] = (int)$_POST['warehouse_id'];
        }
        
        if (isset($_POST['manufacturer_id']) && !empty($_POST['manufacturer_id'])) {
            $document_data['manufacturer_id'] = (int)$_POST['manufacturer_id'];
        }

        // Link to module batch when provided
        if (isset($_POST['module_id']) && !empty($_POST['module_id'])) {
            $document_data['module_id'] = (int)$_POST['module_id'];
        }

        // Enforce module batch for Modules Flash Test Data and Spec Sheets
        if ($document_type === 'modules' && in_array($document_sub_type, ['Flash Test Data', 'Spec Sheets'])) {
            if (empty($document_data['module_id'])) {
                throw new Exception('Module Batch # is required for this upload.');
            }
        }

        // Exception Reports: require valid ticket_id (warranty_claims.id) and persist in entity_context
        if ($document_type === 'exception_reports') {
            $ticket_id = isset($_POST['ticket_id']) ? trim($_POST['ticket_id']) : '';
            if ($ticket_id === '' || !ctype_digit($ticket_id)) {
                throw new Exception('Valid Ticket Number is required for exception reports.');
            }
            // Validate ticket belongs to selected project
            $tid = (int)$ticket_id;
            $stmtT = $conn->prepare("SELECT w.id FROM warranty_claims w JOIN site_scheduling ss ON ss.id = w.scheduling_id WHERE w.id = ? AND ss.project_id = ? LIMIT 1");
            $stmtT->bind_param("ii", $tid, $project_id);
            $stmtT->execute();
            $resT = $stmtT->get_result();
            if (!$resT || $resT->num_rows === 0) {
                throw new Exception('Ticket Number not found for the selected project.');
            }
            $stmtT->close();

            // Append to entity_context for filtering and traceability
            $document_data['entity_context'] = trim(($document_data['entity_context'] ?? '') . " | incident_ticket_id:" . $tid);
        }

        // Handle invoice documents - save to project_invoices if it's an invoice
        if ($document_type === 'invoices' && in_array($document_sub_type, ['Solterra Invoice', 'Module Invoice', 'Freight Invoice'])) {
            // Validate required invoice fields for Solterra/Module
            if (in_array($document_sub_type, ['Solterra Invoice', 'Module Invoice'])) {
                if (empty($_POST['invoice_total']) || empty($_POST['invoice_date']) || empty($_POST['invoice_due_date'])) {
                    throw new Exception('Invoice Total, Invoice Date, and Due Date are required for invoice uploads.');
                }
            }
            $invoice_id = createProjectInvoice($conn, $project_id, $_POST, $processed_file);
            if ($invoice_id) {
                $document_data['project_invoice_id'] = $invoice_id;
            }
        }

        // Save to project_documents table
        $save_result = saveDocumentToProjectDocuments($conn, $document_data);
        $document_id = is_array($save_result) && isset($save_result['document_id']) ? (int)$save_result['document_id'] : 0;
        
        if ($document_id <= 0) {
            throw new Exception('Failed to save document: ' . $processed_file['original_name']);
        }

        // Update is_safe_harbor flag if needed
        if ($is_safe_harbor) {
            $stmt = $conn->prepare("UPDATE project_documents SET is_safe_harbor = 1 WHERE id = ?");
            $stmt->bind_param("i", $document_id);
            $stmt->execute();
            $stmt->close();
            
            // Copy to Safe Harbor folder
            $safe_harbor_path = copySafeHarborDocument($processed_file, $project_id, $document_type);
            if ($safe_harbor_path) {
                $safe_harbor_documents[] = [
                    'original_name' => $processed_file['original_name'],
                    'safe_harbor_path' => $safe_harbor_path
                ];
            }
        }

        $uploaded_documents[] = [
            'id' => $document_id,
            'original_name' => $processed_file['original_name'],
            'document_type' => $document_type,
            'document_sub_type' => $document_sub_type,
            'is_safe_harbor' => $is_safe_harbor
        ];
    }

    // Send notifications to project users
    if (!empty($uploaded_documents)) {
        $docCount = count($uploaded_documents);
        $docType = $document_sub_type;
        $projectName = $project['project_name'];
        
        notify_project_users(
            $project_id,
            'document_upload',
            "New {$docType} uploaded",
            "{$docCount} document(s) uploaded to {$projectName}",
            "project_documents?project_id={$project_id}"
        );
    }
    
    $response = [
        'success' => true,
        'message' => count($uploaded_documents) . ' document(s) uploaded successfully',
        'uploaded_documents' => $uploaded_documents
    ];

    if (!empty($safe_harbor_documents)) {
        $response['safe_harbor_documents'] = $safe_harbor_documents;
        $response['message'] .= ' (' . count($safe_harbor_documents) . ' also saved to Safe Harbor)';
    }

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Global document upload error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();

/**
 * Create a project invoice record for invoice documents
 */
function createProjectInvoice($conn, $project_id, $post_data, $processed_file) {
    try {
        $amount = isset($post_data['invoice_total']) ? (float)$post_data['invoice_total'] : 0.00;
        $invoice_date = isset($post_data['invoice_date']) ? $post_data['invoice_date'] : date('Y-m-d');
        $due_date = isset($post_data['invoice_due_date']) ? $post_data['invoice_due_date'] : date('Y-m-d', strtotime($invoice_date . ' +30 days'));
        
        $stmt = $conn->prepare("
            INSERT INTO project_invoices (
                project_id, amount, status, issued_date, due_date, 
                invoice_file, uploaded_at, notes
            ) VALUES (?, ?, 'Open', ?, ?, ?, NOW(), ?)
        ");
        
        $invoice_file = $processed_file['file_path'] ?? '';
        $notes = "Uploaded via Global Documents";
        
        $stmt->bind_param("idssss", 
            $project_id, 
            $amount, 
            $invoice_date, 
            $due_date, 
            $invoice_file, 
            $notes
        );
        
        if ($stmt->execute()) {
            $invoice_id = $conn->insert_id;
            $stmt->close();
            return $invoice_id;
        }
        
        $stmt->close();
        return null;
        
    } catch (Exception $e) {
        error_log("Failed to create project invoice: " . $e->getMessage());
        return null;
    }
}

/**
 * Copy document to Safe Harbor folder for compliance
 */
function copySafeHarborDocument($processed_file, $project_id, $document_type) {
    try {
        $safe_harbor_dir = "uploads/safe_harbor_evidence/{$project_id}/";
        
        // Create directory if it doesn't exist
        if (!is_dir($safe_harbor_dir)) {
            if (!mkdir($safe_harbor_dir, 0755, true)) {
                throw new Exception("Failed to create Safe Harbor directory");
            }
        }
        
        $source_path = $processed_file['file_path'] ?? $processed_file['tmp_name'];
        $destination_path = $safe_harbor_dir . basename($processed_file['file_name']);
        
        if (copy($source_path, $destination_path)) {
            return $destination_path;
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log("Failed to copy Safe Harbor document: " . $e->getMessage());
        return null;
    }
}
?>
