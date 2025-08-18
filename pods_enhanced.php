<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Check for project_id parameter
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Verify project access based on user role
if ($user_role === 'admin') {
    // Admin users can only access projects in their account
    $stmt = $conn->prepare("
        SELECT p.project_name 
        FROM projects p 
        JOIN customer_account_users cau ON p.account_id = cau.account_id 
        WHERE p.id = ? AND cau.user_id = ?
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($project_name);
    $stmt->fetch();
    $stmt->close();
} elseif ($user_role === 'global_admin') {
    // Global admin can access any project
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($project_name);
    $stmt->fetch();
    $stmt->close();
} else {
    // Regular users can only access their account's projects
    $stmt = $conn->prepare("
        SELECT p.project_name 
        FROM projects p 
        JOIN customer_account_users cau ON p.account_id = cau.account_id 
        WHERE p.id = ? AND cau.user_id = ?
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($project_name);
    $stmt->fetch();
    $stmt->close();
}

if (!$project_name) {
    die("You do not have access to this project or it does not exist.");
}

// Check if user can upload (admin or global_admin only)
$can_upload = in_array($user_role, ['admin', 'global_admin']);

// Fetch entity-specific PODs from deliveries table
$delivery_pods = [];
$stmt = $conn->prepare("
    SELECT 
        d.id as delivery_id,
        d.proof_of_delivery,
        d.bol_number,
        d.supplier,
        d.wattage,
        d.quantity,
        d.warehouse_arrival_date
    FROM deliveries d
    WHERE d.project_id = ? 
      AND d.proof_of_delivery IS NOT NULL
      AND d.proof_of_delivery != ''
    ORDER BY d.warehouse_arrival_date DESC
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $delivery_pods[] = $row;
}
$stmt->close();

// Fetch general POD documents from project_documents table
$general_pods = [];
try {
    $table_check = $conn->query("SHOW TABLES LIKE 'project_documents'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn->prepare("
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
            WHERE pd.project_id = ? 
              AND pd.document_type = 'pods' 
              AND pd.is_active = 1
            ORDER BY pd.uploaded_at DESC
        ");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $general_pods[] = $row;
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error fetching general POD documents: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POD Documents - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/png">
    <link rel="shortcut icon" href="pictures/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: #2c3e50;
            line-height: 1.6;
        }

        .breadcrumb {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 12px 20px;
            border-radius: 12px;
            margin: 10px 10px 20px 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
        }
        .breadcrumb a { color: #488C9A; text-decoration: none; font-weight: 500; transition: all 0.2s ease; }
        .breadcrumb a:hover { color: #3a6e7f; text-decoration: underline; }
        .breadcrumb .separator { margin: 0 12px; color: #6c757d; font-weight: 600; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0 10px 20px 10px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-header h1 {
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text; 
            -webkit-text-fill-color: transparent; 
            background-clip: text;
            margin: 0;
            font-size: 2.2em;
            font-weight: 700;
        }

        .upload-button {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.3);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1em;
        }

        .upload-button:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 140, 154, 0.4);
        }

        .documents-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin: 0 10px 20px 10px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid rgba(72, 140, 154, 0.1);
        }

        .section-title {
            font-size: 1.4em;
            font-weight: 600;
            color: #293E4C;
        }

        .section-count {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .document-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(72, 140, 154, 0.08);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .document-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(72, 140, 154, 0.15);
            border-color: rgba(72, 140, 154, 0.2);
        }

        .delivery-pod-card {
            border-left: 4px solid #3b82f6;
        }

        .general-pod-card {
            border-left: 4px solid #10b981;
        }

        .document-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .document-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            flex-shrink: 0;
        }

        .delivery-icon {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        .general-icon {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .document-info {
            flex: 1;
            min-width: 0;
        }

        .document-name {
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 4px;
            font-size: 1.1em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .document-meta {
            color: #6c757d;
            font-size: 0.9em;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .document-details {
            background: rgba(72, 140, 154, 0.05);
            border-radius: 8px;
            padding: 12px;
            margin: 12px 0;
            font-size: 0.9em;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }

        .detail-row:last-child {
            margin-bottom: 0;
        }

        .detail-label {
            font-weight: 500;
            color: #488C9A;
        }

        .detail-value {
            color: #293E4C;
        }

        .document-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
        }

        .btn-download {
            flex: 1;
            padding: 10px 16px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-download:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-1px);
        }

        .empty-section {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 16px;
            border: 2px dashed rgba(72, 140, 154, 0.2);
        }

        .empty-section i {
            font-size: 3em;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-section h3 {
            color: #495057;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .empty-section p {
            font-size: 1.05em;
            line-height: 1.6;
        }

        /* Upload Modal - Reusing styles from modules_docs.php */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            margin: 5% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-weight: 600;
        }

        .close-button {
            background: none;
            border: none;
            color: white;
            font-size: 1.5em;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .close-button:hover {
            transform: scale(1.1);
        }

        .modal-body {
            padding: 32px;
        }

        .upload-area {
            border: 2px dashed rgba(72, 140, 154, 0.3);
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-bottom: 24px;
        }

        .upload-area:hover {
            border-color: #488C9A;
            background: rgba(72, 140, 154, 0.05);
        }

        .upload-area.dragover {
            border-color: #488C9A;
            background: rgba(72, 140, 154, 0.1);
            transform: scale(1.02);
        }

        .upload-icon {
            font-size: 3em;
            color: #488C9A;
            margin-bottom: 16px;
        }

        .upload-text {
            color: #6c757d;
            margin-bottom: 8px;
        }

        .upload-subtext {
            color: #9ca3af;
            font-size: 0.9em;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #293E4C;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #488C9A;
        }

        .modal-footer {
            padding: 24px 32px;
            background: rgba(72, 140, 154, 0.05);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .btn-cancel {
            padding: 10px 20px;
            border: 2px solid rgba(72, 140, 154, 0.3);
            background: transparent;
            color: #488C9A;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-cancel:hover {
            background: rgba(72, 140, 154, 0.1);
        }

        .btn-upload {
            padding: 10px 20px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-upload:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
        }

        .btn-upload:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(72, 140, 154, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 16px;
            display: none;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            width: 0%;
            transition: width 0.3s ease;
        }

        @media (max-width: 768px) {
            main { padding: 15px; }
            .documents-container { padding: 20px; border-radius: 16px; }
            .breadcrumb { padding: 10px 15px; margin: 10px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .documents-grid { grid-template-columns: 1fr; }
            .modal-content { margin: 10% auto; width: 95%; }
            .modal-body { padding: 24px; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <span class="separator">&raquo;</span>
        <a href="project_documents.php?project_id=<?php echo $project_id; ?>">Project Documents</a>
        <span class="separator">&raquo;</span>
        <span>POD Documents</span>
    </div>
    
    <div class="page-header">
        <h1>POD Documents</h1>
        <?php if ($can_upload): ?>
            <button class="upload-button" onclick="openUploadModal()">
                <i class="fas fa-plus"></i>
                Upload POD Document
            </button>
        <?php endif; ?>
    </div>

    <div class="documents-container">
        <!-- Delivery-Specific PODs Section -->
        <div class="section-header">
            <i class="fas fa-truck"></i>
            <span class="section-title">Delivery PODs</span>
            <span class="section-count"><?php echo count($delivery_pods); ?></span>
        </div>

        <?php if (count($delivery_pods) > 0): ?>
            <div class="documents-grid">
                <?php foreach ($delivery_pods as $pod): ?>
                    <div class="document-card delivery-pod-card">
                        <div class="document-header">
                            <div class="document-icon delivery-icon">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div class="document-info">
                                <div class="document-name">
                                    Delivery #<?php echo htmlspecialchars($pod['delivery_id']); ?> POD
                                </div>
                                <div class="document-meta">
                                    <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($pod['warehouse_arrival_date'])); ?></span>
                                    <span><i class="fas fa-file"></i> <?php echo pathinfo($pod['proof_of_delivery'], PATHINFO_EXTENSION); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="document-details">
                            <div class="detail-row">
                                <span class="detail-label">BOL Number:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($pod['bol_number']); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Supplier:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($pod['supplier']); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Modules:</span>
                                <span class="detail-value"><?php echo number_format($pod['quantity']); ?>x <?php echo htmlspecialchars($pod['wattage']); ?>W</span>
                            </div>
                        </div>

                        <div class="document-actions">
                            <a href="view_pod.php?delivery_id=<?php echo $pod['delivery_id']; ?>" class="btn-download">
                                <i class="fas fa-eye"></i>
                                View POD
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-section">
                <i class="fas fa-clipboard-check"></i>
                <h3>No Delivery PODs Found</h3>
                <p>Proof of delivery documents will appear here when uploaded for specific deliveries.</p>
            </div>
        <?php endif; ?>

        <!-- General POD Documents Section -->
        <div class="section-header">
            <i class="fas fa-folder"></i>
            <span class="section-title">General POD Documents</span>
            <span class="section-count"><?php echo count($general_pods); ?></span>
        </div>

        <?php if (count($general_pods) > 0): ?>
            <div class="documents-grid">
                <?php foreach ($general_pods as $doc): ?>
                    <div class="document-card general-pod-card">
                        <div class="document-header">
                            <div class="document-icon general-icon">
                                <i class="fas fa-file"></i>
                            </div>
                            <div class="document-info">
                                <div class="document-name" title="<?php echo htmlspecialchars($doc['original_file_name']); ?>">
                                    <?php echo htmlspecialchars($doc['original_file_name']); ?>
                                </div>
                                <div class="document-meta">
                                    <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?></span>
                                    <span><i class="fas fa-file"></i> <?php 
                                        $size = $doc['file_size'];
                                        if ($size < 1024) {
                                            echo $size . ' B';
                                        } elseif ($size < 1024 * 1024) {
                                            echo round($size / 1024, 1) . ' KB';
                                        } else {
                                            echo round($size / (1024 * 1024), 1) . ' MB';
                                        }
                                    ?></span>
                                    <?php if ($doc['uploaded_by_name']): ?>
                                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($doc['uploaded_by_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($doc['description']): ?>
                            <div class="document-details">
                                <div class="detail-row">
                                    <span class="detail-label">Description:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($doc['description']); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="document-actions">
                            <a href="download_document.php?id=<?php echo $doc['id']; ?>" target="_blank" class="btn-download">
                                <i class="fas fa-download"></i>
                                Download
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-section">
                <i class="fas fa-folder-open"></i>
                <h3>No General POD Documents</h3>
                <p>General POD documents will appear here once uploaded for this project.</p>
                <?php if ($can_upload): ?>
                    <p><button class="upload-button" onclick="openUploadModal()" style="margin-top: 16px;"><i class="fas fa-plus"></i> Upload First Document</button></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Upload Modal -->
    <?php if ($can_upload): ?>
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Upload POD Document</h2>
                <button class="close-button" onclick="closeUploadModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="uploadForm" enctype="multipart/form-data">
                    <div class="upload-area" id="uploadArea">
                        <div class="upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div class="upload-text">
                            <strong>Drop files here or click to browse</strong>
                        </div>
                        <div class="upload-subtext">
                            Supports: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, CSV (Max: 50MB)
                        </div>
                        <input type="file" id="fileInput" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.csv" style="display: none;">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description (Optional)</label>
                        <textarea id="description" name="description" class="form-input" rows="3" placeholder="Brief description of the POD document..."></textarea>
                    </div>

                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <input type="hidden" name="document_type" value="pods">

                    <div class="progress-bar" id="progressBar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeUploadModal()">Cancel</button>
                <button type="button" class="btn-upload" id="uploadBtn" onclick="uploadDocument()">
                    <i class="fas fa-upload"></i>
                    Upload Document
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const projectId = <?php echo $project_id; ?>;
    const canUpload = <?php echo $can_upload ? 'true' : 'false'; ?>;
    
    // Upload modal functionality
    if (canUpload) {
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');

        uploadArea.addEventListener('click', () => fileInput.click());
        
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            fileInput.files = e.dataTransfer.files;
            updateUploadArea();
        });

        fileInput.addEventListener('change', updateUploadArea);
    }

    // Upload modal functions
    window.openUploadModal = function() {
        document.getElementById('uploadModal').style.display = 'block';
    };

    window.closeUploadModal = function() {
        document.getElementById('uploadModal').style.display = 'none';
        document.getElementById('uploadForm').reset();
        updateUploadArea();
    };

    function updateUploadArea() {
        const fileInput = document.getElementById('fileInput');
        const uploadArea = document.getElementById('uploadArea');
        
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            uploadArea.innerHTML = `
                <div class="upload-icon">
                    <i class="fas fa-file"></i>
                </div>
                <div class="upload-text">
                    <strong>${escapeHtml(file.name)}</strong>
                </div>
                <div class="upload-subtext">
                    ${formatFileSize(file.size)} - Click to change file
                </div>
            `;
        } else {
            uploadArea.innerHTML = `
                <div class="upload-icon">
                    <i class="fas fa-cloud-upload-alt"></i>
                </div>
                <div class="upload-text">
                    <strong>Drop files here or click to browse</strong>
                </div>
                <div class="upload-subtext">
                    Supports: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, CSV (Max: 50MB)
                </div>
            `;
        }
    }

    window.uploadDocument = function() {
        const fileInput = document.getElementById('fileInput');
        const uploadBtn = document.getElementById('uploadBtn');
        const progressBar = document.getElementById('progressBar');
        const progressFill = document.getElementById('progressFill');

        if (!fileInput.files.length) {
            alert('Please select a file to upload');
            return;
        }

        const formData = new FormData();
        formData.append('document', fileInput.files[0]);
        formData.append('description', document.getElementById('description').value);
        formData.append('project_id', projectId);
        formData.append('document_type', 'pods');

        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        progressBar.style.display = 'block';

        const xhr = new XMLHttpRequest();
        
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const percentComplete = (e.loaded / e.total) * 100;
                progressFill.style.width = percentComplete + '%';
            }
        };

        xhr.onload = function() {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Document';
            progressBar.style.display = 'none';
            progressFill.style.width = '0%';

            if (xhr.status === 200) {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    closeUploadModal();
                    location.reload(); // Reload to show new document
                    showNotification('POD document uploaded successfully!', 'success');
                } else {
                    alert('Upload failed: ' + response.message);
                }
            } else {
                alert('Upload failed. Please try again.');
            }
        };

        xhr.onerror = function() {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Document';
            progressBar.style.display = 'none';
            progressFill.style.width = '0%';
            alert('Upload failed. Please check your connection and try again.');
        };

        xhr.open('POST', 'upload_project_document.php');
        xhr.send(formData);
    };

    // Helper functions
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#22c55e' : '#ef4444'};
            color: white;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 1001;
            font-weight: 500;
        `;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('uploadModal');
        if (event.target === modal) {
            closeUploadModal();
        }
    };
});
</script>
</body>
</html>

<?php
$conn->close();
?>
