<?php
/**
 * Upload Manufacturer Schedule
 *
 * Multi-step upload process for importing manufacturer shipping schedules.
 * Step 1: Select manufacturer and upload file
 * Step 2: Map columns (if no saved mapping exists)
 * Step 3: Preview and confirm import
 * Step 4: Results
 */

session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Allow admin, global_admin, and customer_admin
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
require_once 'schedule_parser.php';

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$account_id = null;

// Get user's account
if ($role === 'global_admin') {
    // Global admin can work with any account
    $account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : null;
} else {
    // Get account from customer_account_users
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $account_id = $row['account_id'];
    }
    $stmt->close();
}

// Get project_id from URL if provided
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;

// Validate project belongs to user's account
$project = null;
if ($project_id) {
    $stmt = $conn->prepare("SELECT id, project_name, account_id FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();
    $stmt->close();

    if (!$project) {
        die("Project not found");
    }

    // Non-global admins must have access to this project's account
    if ($role !== 'global_admin' && $project['account_id'] != $account_id) {
        header("Location: unauthorized");
        exit();
    }

    $account_id = $project['account_id'];
}

// Fetch manufacturers for dropdown
$manufacturers = [];
$sqlMfg = "SELECT id, name, short_name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC";
$resMfg = $conn->query($sqlMfg);
while ($row = $resMfg->fetch_assoc()) {
    $manufacturers[] = $row;
}

// Fetch warehouses for destination options
$warehouses = [];
$sqlWh = "SELECT id, name, city, state FROM warehouses ORDER BY name ASC";
$resWh = $conn->query($sqlWh);
while ($row = $resWh->fetch_assoc()) {
    $warehouses[] = $row;
}

// Fetch projects for destination options (if not already on a project page)
$projects = [];
if ($account_id) {
    $stmt = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }
    $stmt->close();
}

// Get valid statuses from ScheduleParser
$validStatuses = ScheduleParser::$validStatuses;

// Check if Excel is supported
$excelSupported = ScheduleParser::isExcelSupported();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Manufacturer Schedule<?php echo $project ? ' - ' . htmlspecialchars($project['project_name']) : ''; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .container { padding: 20px; max-width: 1200px; margin: 0 auto; }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
        }
        .page-header p {
            color: #6c757d;
            margin: 0;
        }

        /* Step Indicator */
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 32px;
            gap: 0;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            color: #6c757d;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .step:first-child { border-radius: 50px 0 0 50px; }
        .step:last-child { border-radius: 0 50px 50px 0; }
        .step.active {
            background: linear-gradient(135deg, #488C9A 0%, #3a7086 100%);
            border-color: #488C9A;
            color: white;
        }
        .step.completed {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .step-number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: currentColor;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
        }
        .step.active .step-number { background: white; color: #488C9A; }
        .step.completed .step-number { background: #28a745; }

        /* Content Card */
        .content-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
            padding: 32px;
            margin-bottom: 24px;
        }
        .content-card h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 2px solid #488C9A;
        }

        /* Info Banner */
        .info-banner {
            background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%);
            border: 1px solid #b8daff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .info-banner h3 {
            color: #0056b3;
            margin: 0 0 8px 0;
            font-size: 1rem;
        }
        .info-banner p {
            color: #004085;
            margin: 0;
            font-size: 0.9rem;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 24px;
        }
        .form-group label {
            display: block;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
        }
        .form-group label.required::after {
            content: " *";
            color: #dc3545;
        }
        .form-group select,
        .form-group input[type="text"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e8e8e8;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s ease;
            background: #fafafa;
        }
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #488C9A;
            background: white;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }

        /* File Upload Area */
        .file-upload-area {
            border: 2px dashed rgba(72, 140, 154, 0.3);
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }
        .file-upload-area:hover {
            border-color: #488C9A;
            background: linear-gradient(135deg, #f0f8ff 0%, #f8f9fa 100%);
        }
        .file-upload-area.drag-over {
            border-color: #488C9A;
            background: #e7f3ff;
        }
        .upload-icon {
            font-size: 48px;
            color: #488C9A;
            margin-bottom: 16px;
        }
        .upload-text {
            font-size: 1.1rem;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 8px;
        }
        .upload-subtext {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .selected-file {
            margin-top: 16px;
            padding: 12px 16px;
            background: #d4edda;
            border-radius: 8px;
            color: #155724;
            display: none;
        }
        .selected-file.show { display: block; }

        /* Column Mapping Table */
        .mapping-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .mapping-table th,
        .mapping-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        .mapping-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #293E4C;
        }
        .mapping-table td select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .required-field { color: #dc3545; }

        /* Preview Table */
        .preview-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .preview-table th,
        .preview-table td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
            white-space: nowrap;
        }
        .preview-table th {
            background: #f8f9fa;
            position: sticky;
            top: 0;
            font-weight: 600;
        }
        .preview-table tr:hover {
            background: #f8f9fa;
        }

        /* Summary Stats */
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #488C9A;
        }
        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 4px;
        }

        /* Warnings/Errors */
        .warnings-list {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            max-height: 200px;
            overflow-y: auto;
        }
        .warnings-list h4 {
            color: #856404;
            margin: 0 0 12px 0;
        }
        .warnings-list ul {
            margin: 0;
            padding-left: 20px;
            color: #856404;
        }
        .errors-list {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .errors-list h4 {
            color: #721c24;
            margin: 0 0 12px 0;
        }
        .errors-list ul {
            margin: 0;
            padding-left: 20px;
            color: #721c24;
        }

        /* Buttons */
        .btn-group {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3a7086 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .btn-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #e9ecef;
        }
        .btn-secondary:hover {
            background: #e9ecef;
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
        }

        /* Loading Overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255,255,255,0.9);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        .loading-overlay.show { display: flex; }
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #488C9A;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .loading-text {
            margin-top: 16px;
            font-weight: 500;
            color: #293E4C;
        }

        /* Step content visibility */
        .step-content { display: none; }
        .step-content.active { display: block; }

        /* Destination Options */
        .destination-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 12px;
        }
        .destination-option {
            padding: 16px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .destination-option:hover {
            border-color: #488C9A;
        }
        .destination-option.selected {
            border-color: #488C9A;
            background: rgba(72, 140, 154, 0.05);
        }
        .destination-option h4 {
            margin: 0 0 4px 0;
            color: #293E4C;
        }
        .destination-option p {
            margin: 0;
            font-size: 0.85rem;
            color: #6c757d;
        }

        /* Results */
        .results-summary {
            text-align: center;
            padding: 40px;
        }
        .results-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        .results-icon.success { color: #28a745; }
        .results-summary h2 {
            color: #293E4C;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="container">
        <!-- Breadcrumb -->
        <?php
            require_once 'components/breadcrumbs.php';
            $breadcrumb_extra = [];
            if ($project) {
                $breadcrumb_extra[] = ['label' => htmlspecialchars($project['project_name']), 'url' => 'project_overview.php?project_id=' . $project_id];
            }
            echo slp_render_breadcrumbs([
                'current_label' => 'Import Manufacturer Schedule',
                'extra' => $breadcrumb_extra
            ]);
        ?>

        <!-- Page Header -->
        <div class="page-header">
            <h1>Import Manufacturer Schedule</h1>
            <p>Upload a shipping schedule from your manufacturer to automatically create deliveries and pallets.</p>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step active" data-step="1">
                <span class="step-number">1</span>
                <span>Upload File</span>
            </div>
            <div class="step" data-step="2">
                <span class="step-number">2</span>
                <span>Map Columns</span>
            </div>
            <div class="step" data-step="3">
                <span class="step-number">3</span>
                <span>Preview</span>
            </div>
            <div class="step" data-step="4">
                <span class="step-number">4</span>
                <span>Complete</span>
            </div>
        </div>

        <!-- Step 1: Upload File -->
        <div class="step-content active" id="step1">
            <div class="content-card">
                <h2>Step 1: Select Manufacturer & Upload Schedule</h2>

                <div class="info-banner">
                    <h3>How This Works</h3>
                    <p>Upload your manufacturer's shipping schedule (Excel or CSV). We'll automatically create pallets and deliveries based on the data. You can re-upload updated schedules anytime - existing records will be matched and updated.</p>
                </div>

                <form id="uploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="project_id" value="<?php echo $project_id ?? ''; ?>">
                    <input type="hidden" name="account_id" value="<?php echo $account_id ?? ''; ?>">

                    <div class="form-group">
                        <label class="required" for="manufacturer_id">Manufacturer</label>
                        <select name="manufacturer_id" id="manufacturer_id" required>
                            <option value="">Select Manufacturer</option>
                            <?php foreach ($manufacturers as $mfg): ?>
                                <option value="<?php echo $mfg['id']; ?>">
                                    <?php echo htmlspecialchars($mfg['name']); ?>
                                    <?php if ($mfg['short_name']): ?>(<?php echo htmlspecialchars($mfg['short_name']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!$project_id): ?>
                    <div class="form-group">
                        <label class="required" for="destination_project_id">Destination Project</label>
                        <select name="destination_project_id" id="destination_project_id" required>
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo $p['id']; ?>">
                                    <?php echo htmlspecialchars($p['project_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="destination_project_id" value="<?php echo $project_id; ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="required" for="default_status">Default Status for New Records</label>
                        <select name="default_status" id="default_status" required>
                            <?php foreach ($validStatuses as $status): ?>
                                <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status === 'At Manufacturer' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #6c757d; display: block; margin-top: 4px;">
                            This status will be used if the schedule doesn't include a status column, or if a row's status is blank.
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="required">Schedule File</label>
                        <div class="file-upload-area" id="fileDropArea">
                            <div class="upload-icon">📄</div>
                            <div class="upload-text">Drop your file here or click to browse</div>
                            <div class="upload-subtext">
                                Supports: CSV<?php echo $excelSupported ? ', Excel (.xlsx, .xls)' : ''; ?>
                                <?php if (!$excelSupported): ?>
                                <br><em style="color: #856404;">Excel support requires PhpSpreadsheet library</em>
                                <?php endif; ?>
                            </div>
                        </div>
                        <input type="file" name="schedule_file" id="scheduleFile" accept=".csv<?php echo $excelSupported ? ',.xlsx,.xls' : ''; ?>" style="display: none;">
                        <div class="selected-file" id="selectedFile"></div>
                    </div>

                    <div class="btn-group">
                        <?php if ($project): ?>
                        <a href="project_overview.php?project_id=<?php echo $project_id; ?>" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-primary" id="btnNext1" disabled>Next: Map Columns</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Step 2: Map Columns -->
        <div class="step-content" id="step2">
            <div class="content-card">
                <h2>Step 2: Map Columns</h2>

                <div class="info-banner">
                    <h3>Match Your Columns</h3>
                    <p>We've detected the columns in your file. Please map them to the corresponding system fields. Fields marked with <span class="required-field">*</span> are required.</p>
                </div>

                <div id="existingMappingNotice" style="display: none; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                    <strong style="color: #155724;">Saved Mapping Found!</strong>
                    <p style="color: #155724; margin: 8px 0 0 0;">We found a saved column mapping for this manufacturer. The mappings below have been pre-filled.</p>
                </div>

                <table class="mapping-table">
                    <thead>
                        <tr>
                            <th>System Field</th>
                            <th>Your Column</th>
                        </tr>
                    </thead>
                    <tbody id="mappingTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="saveMappingCheckbox" checked>
                        Save this mapping for future uploads from this manufacturer
                    </label>
                </div>

                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" id="btnBack2">Back</button>
                    <button type="button" class="btn btn-primary" id="btnNext2">Next: Preview Import</button>
                </div>
            </div>
        </div>

        <!-- Step 3: Preview -->
        <div class="step-content" id="step3">
            <div class="content-card">
                <h2>Step 3: Preview Import</h2>

                <div class="summary-stats" id="summaryStats">
                    <!-- Populated by JavaScript -->
                </div>

                <div id="previewWarnings" class="warnings-list" style="display: none;">
                    <h4>Warnings</h4>
                    <ul id="warningsList"></ul>
                </div>

                <div id="previewErrors" class="errors-list" style="display: none;">
                    <h4>Errors</h4>
                    <ul id="errorsList"></ul>
                </div>

                <h3 style="margin-bottom: 12px;">Preview (First 20 Rows)</h3>
                <div class="preview-container">
                    <table class="preview-table" id="previewTable">
                        <thead>
                            <tr id="previewTableHead"></tr>
                        </thead>
                        <tbody id="previewTableBody"></tbody>
                    </table>
                </div>

                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" id="btnBack3">Back</button>
                    <button type="button" class="btn btn-success" id="btnConfirm">Confirm Import</button>
                </div>
            </div>
        </div>

        <!-- Step 4: Results -->
        <div class="step-content" id="step4">
            <div class="content-card">
                <div class="results-summary" id="resultsContent">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loadingOverlay">
            <div class="spinner"></div>
            <div class="loading-text" id="loadingText">Processing...</div>
        </div>
    </div>
</main>

<script>
(function() {
    // State
    let currentStep = 1;
    let uploadedFile = null;
    let fileHeaders = [];
    let columnMapping = {};
    let parsedData = [];
    let summary = {};
    let warnings = [];

    const manufacturerId = () => document.getElementById('manufacturer_id').value;
    const projectId = () => document.querySelector('[name="destination_project_id"]')?.value || '<?php echo $project_id ?? ''; ?>';
    const accountId = () => document.querySelector('[name="account_id"]')?.value || '<?php echo $account_id ?? ''; ?>';
    const defaultStatus = () => document.getElementById('default_status').value;

    // System fields configuration
    const systemFields = <?php echo json_encode(ScheduleParser::$systemFields); ?>;

    // DOM Elements
    const fileDropArea = document.getElementById('fileDropArea');
    const fileInput = document.getElementById('scheduleFile');
    const selectedFileDiv = document.getElementById('selectedFile');
    const btnNext1 = document.getElementById('btnNext1');
    const btnNext2 = document.getElementById('btnNext2');
    const btnBack2 = document.getElementById('btnBack2');
    const btnBack3 = document.getElementById('btnBack3');
    const btnConfirm = document.getElementById('btnConfirm');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const loadingText = document.getElementById('loadingText');

    // Step Navigation
    function goToStep(step) {
        currentStep = step;

        // Update step indicators
        document.querySelectorAll('.step').forEach(s => {
            const stepNum = parseInt(s.dataset.step);
            s.classList.remove('active', 'completed');
            if (stepNum < step) s.classList.add('completed');
            if (stepNum === step) s.classList.add('active');
        });

        // Show/hide step content
        document.querySelectorAll('.step-content').forEach(c => c.classList.remove('active'));
        document.getElementById('step' + step).classList.add('active');
    }

    // File Upload Handling
    fileDropArea.addEventListener('click', () => fileInput.click());

    fileDropArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        fileDropArea.classList.add('drag-over');
    });

    fileDropArea.addEventListener('dragleave', () => {
        fileDropArea.classList.remove('drag-over');
    });

    fileDropArea.addEventListener('drop', (e) => {
        e.preventDefault();
        fileDropArea.classList.remove('drag-over');
        if (e.dataTransfer.files.length) {
            handleFileSelect(e.dataTransfer.files[0]);
        }
    });

    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length) {
            handleFileSelect(e.target.files[0]);
        }
    });

    function handleFileSelect(file) {
        const validTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        const validExtensions = ['csv', 'xlsx', 'xls'];
        const ext = file.name.split('.').pop().toLowerCase();

        if (!validExtensions.includes(ext)) {
            alert('Please upload a CSV or Excel file.');
            return;
        }

        uploadedFile = file;
        selectedFileDiv.textContent = '✓ ' + file.name + ' (' + formatFileSize(file.size) + ')';
        selectedFileDiv.classList.add('show');

        updateStep1Validation();
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function updateStep1Validation() {
        const mfgSelected = manufacturerId();
        const projSelected = projectId();
        btnNext1.disabled = !(uploadedFile && mfgSelected && projSelected);
    }

    document.getElementById('manufacturer_id').addEventListener('change', updateStep1Validation);
    const projSelect = document.getElementById('destination_project_id');
    if (projSelect) projSelect.addEventListener('change', updateStep1Validation);

    // Step 1 -> Step 2
    btnNext1.addEventListener('click', async () => {
        showLoading('Analyzing file...');

        const formData = new FormData();
        formData.append('schedule_file', uploadedFile);
        formData.append('action', 'parse_headers');
        formData.append('manufacturer_id', manufacturerId());

        try {
            const response = await fetch('process_schedule_upload.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            hideLoading();

            if (result.error) {
                alert('Error: ' + result.error);
                return;
            }

            fileHeaders = result.headers;
            const suggestedMappings = result.suggested_mappings;
            const savedMapping = result.saved_mapping;

            // Build mapping table
            buildMappingTable(suggestedMappings, savedMapping);

            // Show notice if saved mapping was found
            document.getElementById('existingMappingNotice').style.display = savedMapping ? 'block' : 'none';

            goToStep(2);

        } catch (err) {
            hideLoading();
            alert('Error processing file: ' + err.message);
        }
    });

    function buildMappingTable(suggestedMappings, savedMapping) {
        const tbody = document.getElementById('mappingTableBody');
        tbody.innerHTML = '';

        // Use saved mapping if available, otherwise use suggestions
        const mappingsToUse = savedMapping || suggestedMappings;

        for (const [fieldKey, fieldConfig] of Object.entries(systemFields)) {
            const row = document.createElement('tr');

            const labelCell = document.createElement('td');
            labelCell.innerHTML = fieldConfig.label;
            if (fieldConfig.required) {
                labelCell.innerHTML += ' <span class="required-field">*</span>';
            }

            const selectCell = document.createElement('td');
            const select = document.createElement('select');
            select.name = 'mapping_' + fieldKey;
            select.id = 'mapping_' + fieldKey;

            // Add empty option
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = fieldConfig.required ? '-- Select Column --' : '(Not mapped)';
            select.appendChild(emptyOpt);

            // Add file headers as options
            fileHeaders.forEach(header => {
                const opt = document.createElement('option');
                opt.value = header;
                opt.textContent = header;
                if (mappingsToUse && mappingsToUse[fieldKey] === header) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });

            selectCell.appendChild(select);

            row.appendChild(labelCell);
            row.appendChild(selectCell);
            tbody.appendChild(row);
        }
    }

    // Step 2 -> Step 1
    btnBack2.addEventListener('click', () => goToStep(1));

    // Step 2 -> Step 3
    btnNext2.addEventListener('click', async () => {
        // Collect mappings
        columnMapping = {};
        let missingRequired = [];

        for (const [fieldKey, fieldConfig] of Object.entries(systemFields)) {
            const select = document.getElementById('mapping_' + fieldKey);
            columnMapping[fieldKey] = select.value;

            if (fieldConfig.required && !select.value) {
                missingRequired.push(fieldConfig.label);
            }
        }

        if (missingRequired.length > 0) {
            alert('Please map the required fields: ' + missingRequired.join(', '));
            return;
        }

        showLoading('Parsing data...');

        const formData = new FormData();
        formData.append('schedule_file', uploadedFile);
        formData.append('action', 'parse_data');
        formData.append('manufacturer_id', manufacturerId());
        formData.append('project_id', projectId());
        formData.append('account_id', accountId());
        formData.append('default_status', defaultStatus());
        formData.append('column_mapping', JSON.stringify(columnMapping));
        formData.append('save_mapping', document.getElementById('saveMappingCheckbox').checked ? '1' : '0');

        try {
            const response = await fetch('process_schedule_upload.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            hideLoading();

            if (result.error) {
                alert('Error: ' + result.error);
                return;
            }

            parsedData = result.data;
            summary = result.summary;
            warnings = result.warnings || [];

            // Show preview
            buildPreview();
            goToStep(3);

        } catch (err) {
            hideLoading();
            alert('Error parsing data: ' + err.message);
        }
    });

    function buildPreview() {
        // Summary stats
        const statsDiv = document.getElementById('summaryStats');
        statsDiv.innerHTML = `
            <div class="stat-card">
                <div class="stat-value">${summary.total_rows}</div>
                <div class="stat-label">Total Rows</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${summary.unique_bols}</div>
                <div class="stat-label">Unique BOLs</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${summary.unique_pallets}</div>
                <div class="stat-label">Pallets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${summary.total_quantity?.toLocaleString() || 0}</div>
                <div class="stat-label">Total Modules</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${summary.pallets_existing || 0}</div>
                <div class="stat-label">Will Update</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${summary.pallets_new || summary.unique_pallets}</div>
                <div class="stat-label">Will Create</div>
            </div>
        `;

        // Warnings
        const warningsDiv = document.getElementById('previewWarnings');
        const warningsList = document.getElementById('warningsList');
        if (warnings.length > 0) {
            warningsList.innerHTML = warnings.slice(0, 20).map(w =>
                `<li>Row ${w.row}: ${w.message}</li>`
            ).join('');
            if (warnings.length > 20) {
                warningsList.innerHTML += `<li>...and ${warnings.length - 20} more warnings</li>`;
            }
            warningsDiv.style.display = 'block';
        } else {
            warningsDiv.style.display = 'none';
        }

        // Preview table
        const thead = document.getElementById('previewTableHead');
        const tbody = document.getElementById('previewTableBody');

        // Headers
        const headerCols = ['BOL', 'Container', 'Pallet ID', 'Wattage', 'Qty', 'Status', 'Est. Delivery'];
        thead.innerHTML = headerCols.map(h => `<th>${h}</th>`).join('');

        // Rows (first 20)
        tbody.innerHTML = parsedData.slice(0, 20).map(row => `
            <tr>
                <td>${row.bol_number || '-'}</td>
                <td>${row.container_number || '-'}</td>
                <td>${row.pallet_id || '-'}</td>
                <td>${row.wattage || '-'}W</td>
                <td>${row.quantity || '-'}</td>
                <td>${row.status || '-'}</td>
                <td>${row.estimated_delivery || '-'}</td>
            </tr>
        `).join('');

        if (parsedData.length > 20) {
            tbody.innerHTML += `<tr><td colspan="7" style="text-align:center; color:#6c757d;">...and ${parsedData.length - 20} more rows</td></tr>`;
        }
    }

    // Step 3 -> Step 2
    btnBack3.addEventListener('click', () => goToStep(2));

    // Step 3 -> Confirm Import
    btnConfirm.addEventListener('click', async () => {
        if (!confirm('Are you sure you want to import this schedule? This will create/update pallets and deliveries.')) {
            return;
        }

        showLoading('Importing data...');

        const formData = new FormData();
        formData.append('schedule_file', uploadedFile);
        formData.append('action', 'import');
        formData.append('manufacturer_id', manufacturerId());
        formData.append('project_id', projectId());
        formData.append('account_id', accountId());
        formData.append('default_status', defaultStatus());
        formData.append('column_mapping', JSON.stringify(columnMapping));
        formData.append('save_mapping', document.getElementById('saveMappingCheckbox').checked ? '1' : '0');

        try {
            const response = await fetch('process_schedule_upload.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            hideLoading();

            if (result.error) {
                alert('Error: ' + result.error);
                return;
            }

            // Show results
            showResults(result);
            goToStep(4);

        } catch (err) {
            hideLoading();
            alert('Error importing: ' + err.message);
        }
    });

    function showResults(result) {
        const container = document.getElementById('resultsContent');

        if (result.success) {
            container.innerHTML = `
                <div class="results-icon success">✓</div>
                <h2>Import Complete!</h2>
                <div class="summary-stats">
                    <div class="stat-card">
                        <div class="stat-value">${result.pallets_created}</div>
                        <div class="stat-label">Pallets Created</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${result.pallets_updated}</div>
                        <div class="stat-label">Pallets Updated</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${result.deliveries_created}</div>
                        <div class="stat-label">Deliveries Created</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${result.deliveries_updated}</div>
                        <div class="stat-label">Deliveries Updated</div>
                    </div>
                </div>
                <div class="btn-group" style="justify-content: center; margin-top: 32px;">
                    <a href="project_overview.php?project_id=${projectId()}" class="btn btn-primary">View Project</a>
                    <a href="manage_deliveries.php?project_id=${projectId()}" class="btn btn-secondary">View Deliveries</a>
                    <button type="button" class="btn btn-secondary" onclick="location.reload()">Import Another</button>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="results-icon" style="color: #dc3545;">✗</div>
                <h2>Import Failed</h2>
                <p style="color: #721c24;">${result.error || 'An unknown error occurred.'}</p>
                <div class="btn-group" style="justify-content: center; margin-top: 32px;">
                    <button type="button" class="btn btn-secondary" onclick="goToStep(1)">Try Again</button>
                </div>
            `;
        }
    }

    function showLoading(text) {
        loadingText.textContent = text;
        loadingOverlay.classList.add('show');
    }

    function hideLoading() {
        loadingOverlay.classList.remove('show');
    }

    // Make goToStep available globally for the results page
    window.goToStep = goToStep;
})();
</script>
</body>
</html>
