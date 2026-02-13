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
$can_upload = in_array($user_role, ['admin', 'global_admin', 'customer_admin']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exception Reports - <?php echo htmlspecialchars($project_name); ?></title>
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

        .documents-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .documents-title {
            font-size: 1.5em;
            font-weight: 600;
            color: #293E4C;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .documents-title i {
            color: #488C9A;
        }

        .search-container {
            position: relative;
            max-width: 300px;
            flex: 1;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px 12px 48px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 12px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .search-input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }

        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
        }
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 24px;
        }

        .document-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(72, 140, 154, 0.08);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }

        .document-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(72, 140, 154, 0.15);
            border-color: rgba(72, 140, 154, 0.2);
        }

        .document-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
            position: relative;
            padding-right: 44px;
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

        .icon-pdf { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); }
        .icon-image { background: linear-gradient(135deg, #059669 0%, #047857 100%); }
        .icon-word { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
        .icon-excel { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); }
        .icon-text { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }
        .icon-default { background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%); }

        .document-info {
            flex: 1;
            min-width: 0;
        }

        .document-name {
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 1.1em;
        }

        .document-meta {
            color: #6c757d;
            font-size: 0.9em;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .document-description {
            color: #6c757d;
            font-size: 0.9em;
            margin: 12px 0;
            line-height: 1.4;
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
        }

        .btn-download:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-1px);
        }

        .btn-delete {
            flex: 1;
            padding: 10px 16px;
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
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
        }

        .btn-delete:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%);
            transform: translateY(-1px);
        }

        .header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .doc-select-label {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #6c757d;
            font-size: 0.9em;
        }

        .doc-select-pill {
            position: absolute;
            top: 10px;
            right: 10px;
            background: transparent;
            border: 0;
            padding: 0;
            border-radius: 8px;
            box-shadow: none;
            cursor: pointer;
            user-select: none;
        }

        .doc-select-pill input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .document-card.selected {
            border-color: rgba(72, 140, 154, 0.4);
            box-shadow: 0 12px 35px rgba(72, 140, 154, 0.2);
        }

        .empty-state { 
            text-align: center; 
            padding: 80px 20px; 
            color: #6c757d; 
        }
        .empty-state-icon { 
            font-size: 4rem; 
            margin-bottom: 16px; 
            opacity: 0.5; 
        }
        .empty-state h3 { 
            color: #495057; 
            margin-bottom: 8px; 
            font-weight: 600; 
        }
        .empty-state p { 
            font-size: 1.1rem; 
            line-height: 1.6; 
            margin-bottom: 24px;
        }

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
            color: white;
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
            position: relative;
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

        .overlay-file-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 1;
        }

        .inline-notice {
            margin: 0 0 16px 0;
            padding: 12px 16px;
            border-radius: 10px;
            font-weight: 600;
        }
        .inline-notice.success { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: #ffffff; }
        .inline-notice.error { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: #ffffff; }

        @media (max-width: 768px) {
            main { padding: 15px; }
            .documents-container { padding: 20px; border-radius: 16px; }
            .breadcrumb { padding: 10px 15px; margin: 10px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .documents-header { flex-direction: column; align-items: flex-start; }
            .search-container { max-width: 100%; }
            .documents-grid { grid-template-columns: 1fr; }
            .modal-content { margin: 10% auto; width: 95%; }
            .modal-body { padding: 24px; }
        }
    </style>
    </head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Exception Reports',
            'extra' => [ ['label' => 'Project Documents', 'url' => 'project_documents.php?project_id='.(int)$project_id] ]
        ]);
    ?>
    
    <div class="page-header">
        <h1>Exception Reports</h1>
        <?php if ($can_upload): ?>
            <button class="upload-button" onclick="openUploadModal()">
                <i class="fas fa-plus"></i>
                Upload Document
            </button>
        <?php endif; ?>
    </div>

    <div class="documents-container">
        <div class="documents-header">
            <div class="documents-title">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="document-count">Loading documents...</span>
            </div>
            <div class="search-container">
                <input type="text" id="document-search" class="search-input" placeholder="Search documents...">
                <i class="fas fa-search search-icon"></i>
            </div>
            <?php if ($can_upload): ?>
            <div class="header-actions">
                <label class="doc-select-label">
                    <input type="checkbox" id="selectAllDocs">
                    <span>Select all</span>
                </label>
                <button id="deleteSelectedBtn" class="btn-delete" disabled>
                    <i class="fas fa-trash"></i>
                    Delete Selected
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div id="documents-list">
        <div class="empty-state">
            <div class="empty-state-icon">⚠️</div>
                <h3>Loading Exception Reports...</h3>
                <p><i class="fas fa-spinner fa-spin"></i> Please wait while we load your documents.</p>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <?php if ($can_upload): ?>
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Upload Incident Report</h2>
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
                        <input type="file" id="fileInput" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.csv" multiple class="overlay-file-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description (Optional)</label>
                        <textarea id="description" name="description" class="form-input" rows="3" placeholder="Brief description of the document..."></textarea>
    </div>

                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <input type="hidden" name="document_type" value="exception_reports">

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
    
    loadDocuments();

    document.getElementById('document-search').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const documentCards = document.querySelectorAll('.document-card');
        
        documentCards.forEach(card => {
            const documentName = card.querySelector('.document-name').textContent.toLowerCase();
            const documentDescription = card.querySelector('.document-description')?.textContent.toLowerCase() || '';
            
            if (documentName.includes(searchTerm) || documentDescription.includes(searchTerm)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    });

    function loadDocuments() {
        fetch(`get_project_documents.php?project_id=${projectId}&document_type=exception_reports`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayDocuments(data.documents);
                    updateDocumentCount(data.total_count);
                } else {
                    showError('Failed to load documents');
                }
            })
            .catch(() => {
                showError('Error loading documents');
            });
    }

    function displayDocuments(documents) {
        const documentsList = document.getElementById('documents-list');
        
        if (documents.length === 0) {
            documentsList.innerHTML = `
        <div class="empty-state">
            <div class="empty-state-icon">⚠️</div>
            <h3>No Exception Reports Found</h3>
            <p>Exception reports will appear here once uploaded for this project.</p>
                    ${canUpload ? '<p><button class="upload-button" onclick="openUploadModal()"><i class="fas fa-plus"></i> Upload First Document</button></p>' : ''}
                </div>
            `;
            return;
        }

        const documentsGrid = document.createElement('div');
        documentsGrid.className = 'documents-grid';

        documents.forEach(doc => {
            const iconClass = getIconClass(doc.icon);
            const uploadedDate = new Date(doc.uploaded_at).toLocaleDateString();
            
            const documentCard = document.createElement('div');
            documentCard.className = 'document-card';
            documentCard.dataset.id = doc.id;
            documentCard.innerHTML = `
                <div class="document-header">
                    <div class="document-icon ${iconClass}">
                        <i class="${doc.icon}"></i>
                    </div>
                    <div class="document-info">
                        <div class="document-name" title="${escapeHtml(doc.filename)}">${escapeHtml(doc.filename)}</div>
                        <div class="document-meta">
                            <span><i class="fas fa-file"></i> ${doc.size}</span>
                            <span><i class="fas fa-calendar"></i> ${uploadedDate}</span>
                        </div>
                </div>
            </div>
                ${canUpload ? `<div class=\"doc-select-pill\"><input type=\"checkbox\" class=\"doc-select\" data-id=\"${doc.id}\" aria-label=\"Select document\"></div>` : ''}
                ${doc.description ? `<div class=\"document-description\">${escapeHtml(doc.description)}</div>` : ''}
                <div class="document-actions">
                    <button class="btn-download" onclick="downloadDocument(${doc.id})">
                        <i class="fas fa-download"></i>
                        Download
                    </button>
                    ${canUpload ? `<button class=\"btn-delete\" onclick=\"deleteDocuments([${doc.id}])\"><i class=\"fas fa-trash\"></i> Delete</button>` : ''}
                </div>
            `;
            
            documentsGrid.appendChild(documentCard);
        });

        documentsList.innerHTML = '';
        documentsList.appendChild(documentsGrid);

        const selectAll = document.getElementById('selectAllDocs');
        const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
        if (selectAll && deleteSelectedBtn) {
            const checkboxes = Array.from(document.querySelectorAll('.doc-select'));
            const refreshDeleteState = () => {
                const anyChecked = checkboxes.some(cb => cb.checked);
                deleteSelectedBtn.disabled = !anyChecked;
            };
            checkboxes.forEach(cb => cb.addEventListener('change', () => {
                const card = cb.closest('.document-card');
                if (card) card.classList.toggle('selected', cb.checked);
                refreshDeleteState();
                if (!cb.checked) {
                    selectAll.checked = false;
                } else {
                    selectAll.checked = checkboxes.every(x => x.checked);
                }
            }));
            selectAll.onchange = () => {
                checkboxes.forEach(cb => {
                    cb.checked = selectAll.checked;
                    const card = cb.closest('.document-card');
                    if (card) {
                        card.classList.toggle('selected', cb.checked);
                    }
                });
                refreshDeleteState();
            };
            deleteSelectedBtn.onclick = () => {
                const ids = checkboxes.filter(cb => cb.checked).map(cb => parseInt(cb.dataset.id, 10));
                if (ids.length) deleteDocuments(ids);
            };
            refreshDeleteState();
        }

        documentsGrid.querySelectorAll('.document-card').forEach(card => {
            card.addEventListener('click', (e) => {
                const isButton = e.target.closest('.btn-download, .btn-delete');
                const isCheckbox = e.target.closest('input[type="checkbox"]');
                const inSelectPill = e.target.closest('.doc-select-pill');
                if (isButton || isCheckbox || inSelectPill) return;
                const checkbox = card.querySelector('.doc-select');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    card.classList.toggle('selected', checkbox.checked);
                    const selectAllCb = document.getElementById('selectAllDocs');
                    if (selectAllCb) {
                        const all = Array.from(document.querySelectorAll('.doc-select'));
                        selectAllCb.checked = all.length > 0 && all.every(cb => cb.checked);
                    }
                    const delBtn = document.getElementById('deleteSelectedBtn');
                    if (delBtn) {
                        const anyChecked = Array.from(document.querySelectorAll('.doc-select')).some(cb => cb.checked);
                        delBtn.disabled = !anyChecked;
                    }
                }
            });
        });
    }

    function updateDocumentCount(count) {
        const countElement = document.getElementById('document-count');
        countElement.textContent = `${count} Document${count !== 1 ? 's' : ''} Found`;
    }

    function showError(message) {
        const documentsList = document.getElementById('documents-list');
        documentsList.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">⚠️</div>
                <h3>Error Loading Documents</h3>
                <p>${message}</p>
                <button class="upload-button" onclick="loadDocuments()" style="margin-top: 16px;">
                    <i class="fas fa-refresh"></i> Try Again
                </button>
            </div>
        `;
    }

    window.openUploadModal = function() {
        const modal = document.getElementById('uploadModal');
        modal.style.display = 'block';
        
        setTimeout(() => {
            setupModalListeners();
            updateUploadArea();
        }, 10);
    };

    function setupModalListeners() {
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        
        if (!uploadArea || !fileInput) { return; }
        
        fileInput.value = '';
        window.modalUploadArea = uploadArea;
        window.modalFileInput = fileInput;
        
        uploadArea.onclick = function(e) {
            const input = document.getElementById('fileInput');
            if (!input) return;
            if (e.target === input) return;
            input.click();
        };
        
        uploadArea.ondragover = function(e) {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        };

        uploadArea.ondragleave = function() {
            uploadArea.classList.remove('dragover');
        };

        uploadArea.ondrop = function(e) {
            e.preventDefault();
            e.stopPropagation();
            uploadArea.classList.remove('dragover');
            if (window.modalFileInput && e.dataTransfer.files.length > 0) {
                const dt = new DataTransfer();
                for (let i = 0; i < e.dataTransfer.files.length; i++) {
                    dt.items.add(e.dataTransfer.files[i]);
                }
                window.modalFileInput.files = dt.files;
                updateUploadArea();
            }
        };
        
        fileInput.onchange = function() { updateUploadArea(); };
    }

    window.closeUploadModal = function() {
        document.getElementById('uploadModal').style.display = 'none';
        document.getElementById('uploadForm').reset();
        updateUploadArea();
    };

    function updateUploadArea() {
        const uploadArea = document.getElementById('uploadArea');
        let fileInput = window.modalFileInput || document.getElementById('fileInput');
        
        if (!uploadArea) { return; }
        
        let contentHTML = '';
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            if (fileInput.files.length === 1) {
                const file = fileInput.files[0];
                uploadArea.classList.remove('multiple-files');
                contentHTML = `
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
                const totalSize = Array.from(fileInput.files).reduce((sum, file) => sum + file.size, 0);
                uploadArea.classList.add('multiple-files');
                contentHTML = `
                    <div class="upload-icon">
                        <i class="fas fa-files"></i>
                    </div>
                    <div class="upload-text">
                        <strong>${fileInput.files.length} files selected</strong>
                    </div>
                    <div class="upload-subtext">
                        Total: ${formatFileSize(totalSize)} - Click to change files
                    </div>
                `;
            }
        } else {
            uploadArea.classList.remove('multiple-files');
            contentHTML = `
                <div class="upload-icon">
                    <i class="fas fa-cloud-upload-alt"></i>
                </div>
                <div class="upload-text">
                    <strong>Drop files here or click to browse</strong>
                </div>
                <div class="upload-subtext">
                    Supports: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, CSV (Max: 50MB each)
                </div>
            `;
        }

        uploadArea.innerHTML = contentHTML;
        if (!fileInput) {
            fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.id = 'fileInput';
            fileInput.name = 'document';
            fileInput.accept = '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.csv';
            fileInput.multiple = true;
        }
        fileInput.className = 'overlay-file-input';
        fileInput.onchange = function() { updateUploadArea(); };
        if (!uploadArea.contains(fileInput)) {
            uploadArea.appendChild(fileInput);
        }
        window.modalFileInput = fileInput;
    }

    window.uploadDocument = async function() {
        const fileInput = window.modalFileInput || document.getElementById('fileInput');
        const uploadBtn = document.getElementById('uploadBtn');
        const progressBar = document.getElementById('progressBar');
        const progressFill = document.getElementById('progressFill');

        if (!fileInput || !fileInput.files || !fileInput.files.length) {
            alert('Please select a file to upload');
            return;
        }

        const files = Array.from(fileInput.files);
        const description = document.getElementById('description').value;
        
        uploadBtn.disabled = true;
        progressBar.style.display = 'block';

        let totalFiles = files.length;
        
        if (totalFiles === 1) {
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        } else {
            uploadBtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Uploading 1/${totalFiles}...`;
        }

        try {
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                if (totalFiles > 1) {
                    uploadBtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Uploading ${i + 1}/${totalFiles}...`;
                }

                const formData = new FormData();
                formData.append('document', file);
                formData.append('description', description);
                formData.append('project_id', projectId);
                formData.append('document_type', 'exception_reports');

                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    
                    xhr.upload.onprogress = function(e) {
                        if (e.lengthComputable) {
                            const fileProgress = (e.loaded / e.total) * 100;
                            const totalProgress = ((i * 100) + fileProgress) / totalFiles;
                            progressFill.style.width = totalProgress + '%';
                        }
                    };

                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                resolve();
                            } else {
                                reject(new Error(response.message || 'Upload failed'));
                            }
                        } else {
                            reject(new Error('Upload failed. Please try again.'));
                        }
                    };

                    xhr.onerror = function() {
                        reject(new Error('Upload failed. Please check your connection and try again.'));
                    };

                    xhr.open('POST', 'upload_project_document.php');
                    xhr.send(formData);
                });
            }

            closeUploadModal();
            loadDocuments();
            
            if (totalFiles === 1) {
                showNotification('Document uploaded successfully!', 'success');
            } else {
                showNotification(`All ${totalFiles} documents uploaded successfully!`, 'success');
            }

        } catch (error) {
            alert(`Upload failed: ${error.message}`);
        } finally {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Document';
            progressBar.style.display = 'none';
            progressFill.style.width = '0%';
        }
    };

    window.downloadDocument = function(documentId) {
        window.open(`download_document.php?id=${documentId}`, '_blank');
    };

    window.deleteDocuments = async function(documentIds) {
        if (!Array.isArray(documentIds) || documentIds.length === 0) return;
        if (!confirm(`Delete ${documentIds.length} document${documentIds.length > 1 ? 's' : ''}? This cannot be undone.`)) return;

        try {
            const response = await fetch('delete_project_documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: documentIds })
            });
            const data = await response.json();
            if (data.success) {
                showNotification('Document(s) deleted', 'success');
                loadDocuments();
            } else {
                alert(data.message || 'Failed to delete');
            }
        } catch (err) {
            alert('Delete request failed');
        }
    };

    function getIconClass(icon) {
        if (icon.includes('file-pdf')) return 'icon-pdf';
        if (icon.includes('file-image')) return 'icon-image';
        if (icon.includes('file-word')) return 'icon-word';
        if (icon.includes('file-excel')) return 'icon-excel';
        if (icon.includes('file-alt')) return 'icon-text';
        return 'icon-default';
    }

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
        const container = document.querySelector('.documents-container') || document.querySelector('main');
        if (!container) return alert(message);
        const banner = document.createElement('div');
        banner.className = `inline-notice ${type}`;
        banner.textContent = message;
        container.insertBefore(banner, container.firstChild);
        setTimeout(() => { banner.remove(); }, 3000);
    }

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


