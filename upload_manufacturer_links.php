<?php
/**
 * Map Real Pallet IDs
 *
 * Multi-step upload flow for linking real manufacturer pallet IDs to existing Solterra pallets.
 */

session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
require_once 'schedule_parser.php';

$conn = getDBConnection();
if (!$conn) {
    die('Connection failed');
}

$user_id = (int)$_SESSION['user_id'];
$is_global_admin = ($role === 'global_admin');
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$project = null;
$projects = [];
$accounts = [];
$errorMessage = '';

if (!$is_global_admin) {
    if (in_array($role, ['admin', 'customer_admin'], true)) {
        $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    } else {
        $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    }
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->bind_result($account_id);
        $stmt->fetch();
        $stmt->close();
    }

    if (!$account_id) {
        $errorMessage = 'Unable to resolve account scope for this user.';
    }
}

if ($project_id > 0 && $errorMessage === '') {
    $stmtProject = $conn->prepare("SELECT p.id, p.project_name, p.account_id, ca.name AS account_name FROM projects p LEFT JOIN customer_accounts ca ON p.account_id = ca.id WHERE p.id = ? LIMIT 1");
    if ($stmtProject) {
        $stmtProject->bind_param('i', $project_id);
        $stmtProject->execute();
        $project = $stmtProject->get_result()->fetch_assoc();
        $stmtProject->close();
    }

    if (!$project) {
        $errorMessage = 'Project not found.';
    } elseif (!$is_global_admin && (int)$project['account_id'] !== (int)$account_id) {
        header('Location: unauthorized');
        exit();
    } elseif ($is_global_admin) {
        $account_id = (int)$project['account_id'];
    }
}

if ($is_global_admin) {
    $resAccounts = $conn->query("SELECT id, name FROM customer_accounts ORDER BY name ASC");
    if ($resAccounts) {
        while ($row = $resAccounts->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
}

if ($account_id > 0) {
    $stmtProjects = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
    if ($stmtProjects) {
        $stmtProjects->bind_param('i', $account_id);
        $stmtProjects->execute();
        $resProjects = $stmtProjects->get_result();
        while ($row = $resProjects->fetch_assoc()) {
            $projects[] = $row;
        }
        $stmtProjects->close();
    }
}

$excelSupported = ScheduleParser::isExcelSupported();
$requireGlobalAccountSelection = $is_global_admin && $project_id <= 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map Real Pallet IDs<?php echo $project ? ' - ' . htmlspecialchars($project['project_name']) : ''; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .page-header { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 24px; padding: 32px; margin-bottom: 24px; box-shadow: 0 8px 32px rgba(0,0,0,.06); border: 1px solid rgba(72,140,154,.08); position: relative; overflow: hidden; }
        .page-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
        .page-header h1 { font-size: 2rem; font-weight: 700; background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin: 0 0 8px; }
        .page-header p { color: #6c757d; margin: 0; }

        .step-indicator { display: flex; justify-content: center; margin-bottom: 30px; }
        .step { display: flex; align-items: center; gap: 8px; padding: 12px 20px; background: #f8f9fa; border: 2px solid #e9ecef; color: #6c757d; font-weight: 500; }
        .step:first-child { border-radius: 50px 0 0 50px; }
        .step:last-child { border-radius: 0 50px 50px 0; }
        .step.active { background: linear-gradient(135deg, #488C9A 0%, #3a7086 100%); border-color: #488C9A; color: #fff; }
        .step.completed { background: #d4edda; border-color: #28a745; color: #155724; }
        .step-number { width: 28px; height: 28px; border-radius: 50%; background: currentColor; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; }
        .step.active .step-number { background: #fff; color: #488C9A; }
        .step.completed .step-number { background: #28a745; }

        .content-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.06); padding: 30px; margin-bottom: 24px; }
        .content-card h2 { font-size: 1.35rem; font-weight: 600; color: #293E4C; margin-bottom: 22px; padding-bottom: 10px; border-bottom: 2px solid #488C9A; }

        .info-banner { background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%); border: 1px solid #b8daff; border-radius: 12px; padding: 18px; margin-bottom: 18px; }
        .info-banner h3 { color: #0056b3; margin: 0 0 8px; font-size: 1rem; }
        .info-banner p { color: #004085; margin: 0; font-size: .92rem; }
        .info-banner ul { color: #004085; margin: 8px 0 0; padding-left: 20px; font-size: .9rem; }
        .info-banner.warning { background: linear-gradient(135deg, #fff3cd 0%, #fffbe6 100%); border-color: #ffc107; }
        .info-banner.warning h3, .info-banner.warning p, .info-banner.warning ul { color: #856404; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 500; color: #333; margin-bottom: 8px; }
        .form-group label.required::after { content: " *"; color: #dc3545; }
        .form-group select,
        .form-group input[type="text"] { width: 100%; padding: 12px 16px; border: 2px solid #e8e8e8; border-radius: 8px; font-size: .95rem; background: #fafafa; box-sizing: border-box; }
        .form-group select:focus,
        .form-group input[type="text"]:focus { outline: none; border-color: #488C9A; background: #fff; box-shadow: 0 0 0 3px rgba(72, 140, 154, .1); }
        .field-hint { font-size: .82rem; color: #6c757d; margin-top: 4px; }

        .file-upload-area { border: 2px dashed rgba(72,140,154,.3); border-radius: 16px; padding: 38px 20px; text-align: center; cursor: pointer; transition: all .3s ease; background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%); }
        .file-upload-area:hover { border-color: #488C9A; background: linear-gradient(135deg, #f0f8ff 0%, #f8f9fa 100%); }
        .file-upload-area.drag-over { border-color: #488C9A; background: #e7f3ff; }
        .upload-icon { font-size: 46px; color: #488C9A; margin-bottom: 14px; }
        .upload-text { font-size: 1.05rem; font-weight: 600; color: #293E4C; margin-bottom: 8px; }
        .upload-subtext { font-size: .84rem; color: #6c757d; }
        .selected-file { margin-top: 14px; padding: 12px 16px; background: #d4edda; border-radius: 8px; color: #155724; display: none; }
        .selected-file.show { display: block; }

        .mapping-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .mapping-table th, .mapping-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #e9ecef; }
        .mapping-table th { background: #f8f9fa; font-weight: 600; color: #293E4C; }
        .mapping-table td select { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; }
        .required-field { color: #dc3545; }

        .summary-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .stat-card { background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%); border: 1px solid #e9ecef; border-radius: 12px; padding: 15px; text-align: center; }
        .stat-value { font-size: 1.7rem; font-weight: 700; color: #488C9A; }
        .stat-value.warning { color: #856404; }
        .stat-value.danger { color: #dc3545; }
        .stat-label { font-size: .82rem; color: #6c757d; margin-top: 4px; }

        .warnings-list { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 14px; margin-bottom: 18px; max-height: 220px; overflow-y: auto; }
        .warnings-list h4 { color: #856404; margin: 0 0 10px; }
        .warnings-list ul { margin: 0; padding-left: 18px; color: #856404; }

        .preview-container { max-height: 430px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 8px; }
        .preview-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        .preview-table th, .preview-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e9ecef; }
        .preview-table thead tr { background: #488C9A; }
        .preview-table th { color: #fff; position: sticky; top: 0; }
        .status-pill { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: .76rem; font-weight: 600; }
        .status-pill.ok { background: #dcfce7; color: #166534; }
        .status-pill.warn { background: #fff7d1; color: #8a6100; }
        .status-pill.err { background: #fee2e2; color: #991b1b; }

        .btn-group { display: flex; gap: 12px; justify-content: flex-end; margin-top: 22px; }
        .btn { padding: 12px 22px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all .2s ease; border: none; font-size: .96rem; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-primary { background: linear-gradient(135deg, #488C9A 0%, #3a7086 100%); color: #fff; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(72,140,154,.3); }
        .btn-primary:disabled { opacity: .55; cursor: not-allowed; transform: none; }
        .btn-secondary { background: #f8f9fa; color: #6c757d; border: 2px solid #e9ecef; }
        .btn-secondary:hover { background: #e9ecef; }
        .btn-success { background: linear-gradient(135deg, #28a745 0%, #218838 100%); color: #fff; }

        .loading-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,.9); z-index: 1000; justify-content: center; align-items: center; flex-direction: column; }
        .loading-overlay.show { display: flex; }
        .spinner { width: 50px; height: 50px; border: 4px solid #f3f3f3; border-top: 4px solid #488C9A; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .loading-text { margin-top: 14px; font-weight: 500; color: #293E4C; }

        .step-content { display: none; }
        .step-content.active { display: block; }
        .results-summary { text-align: center; padding: 24px; }
        .results-icon { font-size: 58px; margin-bottom: 10px; }
        .results-icon.success { color: #28a745; }
        .sync-mfr-page { margin: 0 20px; }

        @media (max-width: 900px) {
            .step-indicator { overflow-x: auto; justify-content: flex-start; padding-bottom: 8px; }
            .step { white-space: nowrap; }
            .content-card { padding: 22px; }
            .btn-group { flex-wrap: wrap; justify-content: center; }
            .sync-mfr-page { margin: 0 10px; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="sync-mfr-page">
        <?php
            require_once 'components/breadcrumbs.php';
            $managePalletsUrl = 'create_shipment.php';
            if ($project_id > 0) {
                $managePalletsUrl .= '?project_id=' . $project_id;
            }
            echo slp_render_breadcrumbs([
                'current_label' => 'Map Real Pallet IDs',
                'extra' => [
                    ['label' => 'Manage Pallets', 'url' => $managePalletsUrl]
                ]
            ]);
        ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="content-card">
                <h2>Unable to Continue</h2>
                <p style="color:#dc3545; margin:0;"><?php echo htmlspecialchars($errorMessage); ?></p>
            </div>
        <?php else: ?>
        <div class="page-header">
            <h1>Map Real Pallet IDs</h1>
            <p>Pair Solterra identifiers with manufacturer pallet IDs in bulk.</p>
        </div>

        <div class="step-indicator">
            <div class="step active" data-step="1"><span class="step-number">1</span><span>Upload & Scope</span></div>
            <div class="step" data-step="2"><span class="step-number">2</span><span>Map Columns</span></div>
            <div class="step" data-step="3"><span class="step-number">3</span><span>Preview</span></div>
            <div class="step" data-step="4"><span class="step-number">4</span><span>Complete</span></div>
        </div>

        <div class="step-content active" id="step1">
            <div class="content-card">
                <h2>Step 1: Upload File and Scope</h2>

                <div class="info-banner warning">
                    <h3>How this works</h3>
                    <ul>
                        <li>Start by exporting pallets from <strong>Manage Pallets</strong> to get the current Solterra identifiers.</li>
                        <li>In your file, keep the Solterra Identifier column unchanged and fill in the Manufacturer Pallet ID values you want to link.</li>
                        <li>Your file must pair each <strong>Solterra Identifier</strong> with a <strong>Manufacturer Pallet ID</strong>.</li>
                        <li>If a matched pallet already has a manufacturer ID, it will be updated when the value differs.</li>
                        <li>Rows with missing/ambiguous/conflicting data are skipped and shown in preview.</li>
                    </ul>
                </div>

                <form id="uploadForm" enctype="multipart/form-data">
                    <input type="hidden" id="projectScopeFixed" value="<?php echo $project_id > 0 ? (int)$project_id : 0; ?>">
                    <input type="hidden" id="defaultAccountId" value="<?php echo (int)$account_id; ?>">

                    <?php if ($is_global_admin && $project_id <= 0): ?>
                    <div class="form-group">
                        <label class="required" for="account_scope_id">Account Scope</label>
                        <select id="account_scope_id" name="account_id">
                            <option value="">Select account scope</option>
                            <?php foreach ($accounts as $acct): ?>
                                <option value="<?php echo (int)$acct['id']; ?>" <?php echo ((int)$account_id === (int)$acct['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($acct['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Required for global admin unless a project scope is selected.</div>
                    </div>
                    <?php elseif ($is_global_admin && $project_id > 0): ?>
                    <div class="form-group">
                        <label>Account Scope</label>
                        <input type="text" value="<?php echo htmlspecialchars($project['account_name'] ?? 'Selected Account'); ?>" disabled>
                        <div class="field-hint">Account scope is locked by selected project context.</div>
                    </div>
                    <?php endif; ?>

                    <?php if ($project_id > 0): ?>
                        <div class="form-group">
                            <label>Project Scope</label>
                            <input type="text" value="<?php echo htmlspecialchars($project['project_name'] ?? 'Selected Project'); ?>" disabled>
                            <div class="field-hint">Matching is limited to this project context.</div>
                        </div>
                    <?php elseif (!empty($projects)): ?>
                        <div class="form-group">
                            <label for="project_scope_id">Project Scope (Optional)</label>
                            <select id="project_scope_id" name="project_scope_id">
                                <option value="0">All Projects</option>
                                <?php foreach ($projects as $proj): ?>
                                    <option value="<?php echo (int)$proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-hint">Optional filter to reduce ambiguity.</div>
                        </div>
                    <?php else: ?>
                        <input type="hidden" id="project_scope_id" value="0">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="required">Pallet ID Link File</label>
                        <div class="file-upload-area" id="fileDropArea">
                            <div class="upload-icon">📄</div>
                            <div class="upload-text">Drop your file here or click to browse</div>
                            <div class="upload-subtext">Supports: CSV<?php echo $excelSupported ? ', Excel (.xlsx, .xls)' : ''; ?></div>
                        </div>
                        <input type="file" id="linkFile" accept=".csv<?php echo $excelSupported ? ',.xlsx,.xls' : ''; ?>" style="display:none;">
                        <div class="selected-file" id="selectedFile"></div>
                    </div>

                    <div class="btn-group" style="justify-content: space-between;">
                        <a href="<?php echo htmlspecialchars($managePalletsUrl); ?>" class="btn btn-secondary">Back to Manage Pallets</a>
                        <button type="button" class="btn btn-primary" id="btnNext1" disabled>Next: Map Columns</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="step-content" id="step2">
            <div class="content-card">
                <h2>Step 2: Map Columns</h2>
                <div class="info-banner">
                    <h3>Required mappings</h3>
                    <p>Map only these two required fields exactly once.</p>
                </div>

                <table class="mapping-table">
                    <thead>
                        <tr>
                            <th>System Field</th>
                            <th>Your Column</th>
                        </tr>
                    </thead>
                    <tbody id="mappingTableBody"></tbody>
                </table>

                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" id="btnBack2">Back</button>
                    <button type="button" class="btn btn-primary" id="btnNext2">Next: Preview</button>
                </div>
            </div>
        </div>

        <div class="step-content" id="step3">
            <div class="content-card">
                <h2>Step 3: Review Import Plan</h2>

                <div class="summary-stats" id="summaryStats"></div>

                <div class="warnings-list" id="previewWarnings" style="display:none;">
                    <h4>Warnings</h4>
                    <ul id="warningsList"></ul>
                </div>

                <h3 style="margin-bottom:10px;">Row Preview</h3>
                <div class="preview-container">
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Solterra Identifier</th>
                                <th>New Manufacturer ID</th>
                                <th>Matched Pallet</th>
                                <th>Current Manufacturer ID</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody id="previewTableBody"></tbody>
                    </table>
                </div>

                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" id="btnBack3">Back</button>
                    <button type="button" class="btn btn-success" id="btnConfirm">Apply ID Sync</button>
                </div>
            </div>
        </div>

        <div class="step-content" id="step4">
            <div class="content-card">
                <div class="results-summary" id="resultsContent"></div>
            </div>
        </div>

        <div class="loading-overlay" id="loadingOverlay">
            <div class="spinner"></div>
            <div class="loading-text" id="loadingText">Processing...</div>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
(function() {
    const hasBlockingError = <?php echo $errorMessage !== '' ? 'true' : 'false'; ?>;
    if (hasBlockingError) return;

    let currentStep = 1;
    let uploadedFile = null;
    let fileHeaders = [];
    let columnMapping = {};
    let parsedData = [];
    let summary = {};
    let warnings = [];

    const requiresGlobalAccountScope = <?php echo $requireGlobalAccountSelection ? 'true' : 'false'; ?>;
    const isGlobalAdmin = <?php echo $is_global_admin ? 'true' : 'false'; ?>;

    const systemFields = {
        solterra_identifier: { label: 'Solterra Identifier', required: true },
        manufacturer_pallet_id: { label: 'Manufacturer Pallet ID', required: true }
    };

    const fileDropArea = document.getElementById('fileDropArea');
    const fileInput = document.getElementById('linkFile');
    const selectedFileDiv = document.getElementById('selectedFile');
    const btnNext1 = document.getElementById('btnNext1');
    const btnBack2 = document.getElementById('btnBack2');
    const btnNext2 = document.getElementById('btnNext2');
    const btnBack3 = document.getElementById('btnBack3');
    const btnConfirm = document.getElementById('btnConfirm');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const loadingText = document.getElementById('loadingText');
    const accountScopeSelect = document.getElementById('account_scope_id');

    function getProjectScopeId() {
        const fixed = Number(document.getElementById('projectScopeFixed')?.value || 0);
        if (fixed > 0) return fixed;
        return Number(document.getElementById('project_scope_id')?.value || 0);
    }

    function getAccountId() {
        if (!isGlobalAdmin) {
            return Number(document.getElementById('defaultAccountId')?.value || 0);
        }
        if (accountScopeSelect) {
            return Number(accountScopeSelect.value || 0);
        }
        return Number(document.getElementById('defaultAccountId')?.value || 0);
    }

    function goToStep(step) {
        currentStep = step;
        document.querySelectorAll('.step').forEach(stepEl => {
            const stepNum = Number(stepEl.dataset.step || 0);
            stepEl.classList.remove('active', 'completed');
            if (stepNum < step) stepEl.classList.add('completed');
            if (stepNum === step) stepEl.classList.add('active');
        });

        document.querySelectorAll('.step-content').forEach(content => content.classList.remove('active'));
        const target = document.getElementById('step' + step);
        if (target) target.classList.add('active');
    }

    function showLoading(text) {
        loadingText.textContent = text;
        loadingOverlay.classList.add('show');
    }

    function hideLoading() {
        loadingOverlay.classList.remove('show');
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function updateStep1Validation() {
        const hasFile = !!uploadedFile;
        const hasRequiredAccount = !requiresGlobalAccountScope || getAccountId() > 0 || getProjectScopeId() > 0;
        btnNext1.disabled = !(hasFile && hasRequiredAccount);
    }

    function handleFileSelect(file) {
        const validExtensions = ['csv', 'xlsx', 'xls'];
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (!validExtensions.includes(ext)) {
            alert('Please upload a CSV or Excel file.');
            return;
        }

        uploadedFile = file;
        selectedFileDiv.textContent = '✓ ' + file.name + ' (' + formatFileSize(file.size) + ')';
        selectedFileDiv.classList.add('show');
        updateStep1Validation();
    }

    fileDropArea?.addEventListener('click', () => fileInput.click());
    fileDropArea?.addEventListener('dragover', (event) => { event.preventDefault(); fileDropArea.classList.add('drag-over'); });
    fileDropArea?.addEventListener('dragleave', () => fileDropArea.classList.remove('drag-over'));
    fileDropArea?.addEventListener('drop', (event) => {
        event.preventDefault();
        fileDropArea.classList.remove('drag-over');
        if (event.dataTransfer.files.length > 0) {
            handleFileSelect(event.dataTransfer.files[0]);
        }
    });
    fileInput?.addEventListener('change', (event) => {
        if (event.target.files.length > 0) {
            handleFileSelect(event.target.files[0]);
        }
    });

    accountScopeSelect?.addEventListener('change', updateStep1Validation);
    document.getElementById('project_scope_id')?.addEventListener('change', updateStep1Validation);

    btnNext1?.addEventListener('click', async () => {
        showLoading('Reading file headers...');

        const formData = new FormData();
        formData.append('action', 'parse_headers');
        formData.append('link_file', uploadedFile);
        if (getAccountId() > 0) {
            formData.append('account_id', String(getAccountId()));
        }

        try {
            const response = await fetch('process_manufacturer_link_upload.php', { method: 'POST', body: formData });
            const result = await response.json();
            hideLoading();

            if (result.error) {
                alert('Error: ' + result.error);
                return;
            }

            fileHeaders = result.headers || [];
            buildMappingTable(result.suggested_mappings || {});
            goToStep(2);
        } catch (error) {
            hideLoading();
            alert('Error parsing headers: ' + error.message);
        }
    });

    function buildMappingTable(suggestedMappings) {
        const tbody = document.getElementById('mappingTableBody');
        if (!tbody) return;

        tbody.innerHTML = '';
        Object.entries(systemFields).forEach(([fieldKey, config]) => {
            const row = document.createElement('tr');

            const labelCell = document.createElement('td');
            labelCell.innerHTML = config.label + ' <span class="required-field">*</span>';

            const selectCell = document.createElement('td');
            const select = document.createElement('select');
            select.id = 'mapping_' + fieldKey;

            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = '-- Select Column --';
            select.appendChild(emptyOption);

            fileHeaders.forEach((header) => {
                const option = document.createElement('option');
                option.value = header;
                option.textContent = header;
                if (suggestedMappings[fieldKey] === header) {
                    option.selected = true;
                }
                select.appendChild(option);
            });

            selectCell.appendChild(select);
            row.appendChild(labelCell);
            row.appendChild(selectCell);
            tbody.appendChild(row);
        });
    }

    btnBack2?.addEventListener('click', () => goToStep(1));

    btnNext2?.addEventListener('click', async () => {
        columnMapping = {};
        Object.keys(systemFields).forEach((fieldKey) => {
            const select = document.getElementById('mapping_' + fieldKey);
            columnMapping[fieldKey] = select ? select.value : '';
        });

        const missing = [];
        if (!columnMapping.solterra_identifier) missing.push('Solterra Identifier');
        if (!columnMapping.manufacturer_pallet_id) missing.push('Manufacturer Pallet ID');

        if (missing.length > 0) {
            alert('Please map required fields:\n- ' + missing.join('\n- '));
            return;
        }

        showLoading('Building import preview...');

        const formData = new FormData();
        formData.append('action', 'parse_data');
        formData.append('link_file', uploadedFile);
        formData.append('column_mapping', JSON.stringify(columnMapping));
        formData.append('project_scope_id', String(getProjectScopeId()));
        if (getAccountId() > 0) {
            formData.append('account_id', String(getAccountId()));
        }

        try {
            const response = await fetch('process_manufacturer_link_upload.php', { method: 'POST', body: formData });
            const result = await response.json();
            hideLoading();

            if (result.error) {
                alert('Error: ' + result.error);
                return;
            }

            parsedData = result.data || [];
            summary = result.summary || {};
            warnings = result.warnings || [];

            buildPreview();
            goToStep(3);
        } catch (error) {
            hideLoading();
            alert('Error creating preview: ' + error.message);
        }
    });

    function statusClass(code) {
        if (code === 'will_link' || code === 'will_update' || code === 'no_change') return 'ok';
        if (code && code.startsWith('skip_')) return 'warn';
        return 'err';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    function buildPreview() {
        const stats = document.getElementById('summaryStats');
        const updates = Number(summary.updates_to_apply || 0);
        const conflicts = Number(summary.skip_conflict_existing || 0) + Number(summary.skip_duplicate_manufacturer || 0);
        const notFound = Number(summary.skip_not_found || 0);

        stats.innerHTML = `
            <div class="stat-card"><div class="stat-value">${Number(summary.total_rows || 0)}</div><div class="stat-label">Total Rows</div></div>
            <div class="stat-card"><div class="stat-value">${Number(summary.matched_rows || 0)}</div><div class="stat-label">Rows Matched</div></div>
            <div class="stat-card"><div class="stat-value">${updates}</div><div class="stat-label">Updates to Apply</div></div>
            <div class="stat-card"><div class="stat-value warning">${conflicts}</div><div class="stat-label">Conflicts/Blocked</div></div>
            <div class="stat-card"><div class="stat-value ${notFound > 0 ? 'danger' : ''}">${notFound}</div><div class="stat-label">Not Found</div></div>
        `;

        const warningsWrap = document.getElementById('previewWarnings');
        const warningsList = document.getElementById('warningsList');
        if (warnings.length > 0) {
            warningsList.innerHTML = warnings.slice(0, 25).map((warning) => {
                const prefix = warning.row && Number(warning.row) > 0 ? `Row ${warning.row}: ` : '';
                return `<li>${escapeHtml(prefix + (warning.message || 'Warning'))}</li>`;
            }).join('');
            warningsWrap.style.display = 'block';
        } else {
            warningsWrap.style.display = 'none';
        }

        const tbody = document.getElementById('previewTableBody');
        const rows = parsedData.slice(0, 50).map((row) => {
            const matched = row.matched_pallet_id ? `#${row.matched_pallet_id}` : '-';
            const currentMfr = row.current_manufacturer_pallet_id || 'Unlinked';
            const pillClass = statusClass(row.status_code || '');

            return `
                <tr>
                    <td>${escapeHtml(row.row_number || '')}</td>
                    <td>${escapeHtml(row.solterra_identifier || '')}</td>
                    <td>${escapeHtml(row.manufacturer_pallet_id || '')}</td>
                    <td>${escapeHtml(matched)}</td>
                    <td>${escapeHtml(currentMfr)}</td>
                    <td>
                        <span class="status-pill ${pillClass}">${escapeHtml(row.status_label || 'Unknown')}</span>
                        <div style="margin-top:4px; color:#64748b;">${escapeHtml(row.message || '')}</div>
                    </td>
                </tr>
            `;
        }).join('');

        tbody.innerHTML = rows || '<tr><td colspan="6" style="text-align:center; color:#64748b;">No rows to preview.</td></tr>';
        if (parsedData.length > 50) {
            tbody.innerHTML += `<tr><td colspan="6" style="text-align:center; color:#64748b;">...and ${parsedData.length - 50} more row(s)</td></tr>`;
        }
    }

    btnBack3?.addEventListener('click', () => goToStep(2));

    btnConfirm?.addEventListener('click', async () => {
        const updates = Number(summary.updates_to_apply || 0);
        if (updates === 0) {
            if (!confirm('No updates are currently queued. Continue anyway?')) return;
        } else if (!confirm(`Apply ${updates} manufacturer ID update(s)?`)) {
            return;
        }

        showLoading('Applying ID links...');

        const formData = new FormData();
        formData.append('action', 'import');
        formData.append('link_file', uploadedFile);
        formData.append('column_mapping', JSON.stringify(columnMapping));
        formData.append('project_scope_id', String(getProjectScopeId()));
        if (getAccountId() > 0) {
            formData.append('account_id', String(getAccountId()));
        }

        try {
            const response = await fetch('process_manufacturer_link_upload.php', { method: 'POST', body: formData });
            const result = await response.json();
            hideLoading();

            if (result.error) {
                alert('Error: ' + result.error);
                return;
            }

            showResults(result);
            goToStep(4);
        } catch (error) {
            hideLoading();
            alert('Import failed: ' + error.message);
        }
    });

    function showResults(result) {
        const container = document.getElementById('resultsContent');
        const backUrl = <?php echo json_encode($managePalletsUrl); ?>;

        if (result.success) {
            container.innerHTML = `
                <div class="results-icon success">✓</div>
                <h2>Manufacturer IDs Synced</h2>
                <div class="summary-stats">
                    <div class="stat-card"><div class="stat-value">${Number(result.updated_count || 0)}</div><div class="stat-label">Rows Updated</div></div>
                    <div class="stat-card"><div class="stat-value">${Number(result.linked_count || 0)}</div><div class="stat-label">New Links</div></div>
                    <div class="stat-card"><div class="stat-value warning">${Number(result.updated_existing_count || result.overwritten_count || 0)}</div><div class="stat-label">Existing Links Updated</div></div>
                    <div class="stat-card"><div class="stat-value">${Number(result.skipped_count || 0)}</div><div class="stat-label">Skipped Rows</div></div>
                </div>
                <div class="btn-group" style="justify-content:center; margin-top:24px;">
                    <a href="${backUrl}" class="btn btn-primary">Return to Manage Pallets</a>
                    <button type="button" class="btn btn-secondary" onclick="location.reload()">Link Another File</button>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="results-icon" style="color:#dc3545;">✗</div>
                <h2>Import Failed</h2>
                <p style="color:#721c24;">${escapeHtml(result.error || 'An unknown error occurred.')}</p>
            `;
        }
    }

    updateStep1Validation();
})();
</script>
</body>
</html>
