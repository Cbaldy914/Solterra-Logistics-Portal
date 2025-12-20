<?php
/**
 * Upload Pallets
 *
 * Multi-step upload process for importing pallets/modules into inventory.
 * Step 1: Select manufacturer and upload file
 * Step 2: Map columns
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
    $account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : null;
} else {
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

// Fetch projects for destination options
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

// Check if Excel is supported
$excelSupported = ScheduleParser::isExcelSupported();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Pallets<?php echo $project ? ' - ' . htmlspecialchars($project['project_name']) : ''; ?></title>
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
        .info-banner ul {
            color: #004085;
            margin: 8px 0 0 0;
            padding-left: 20px;
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
        .form-group input[type="text"],
        .form-group input[type="number"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e8e8e8;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s ease;
            background: #fafafa;
            box-sizing: border-box;
        }
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #488C9A;
            background: white;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }

        /* Optional Fields Section */
        .optional-fields-toggle {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .optional-fields-toggle:hover {
            background: #e9ecef;
        }
        .optional-fields-toggle h4 {
            margin: 0;
            color: #495057;
            font-size: 0.95rem;
        }
        .optional-fields-toggle .toggle-icon {
            transition: transform 0.3s;
        }
        .optional-fields-toggle.open .toggle-icon {
            transform: rotate(180deg);
        }
        .optional-fields-content {
            display: none;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-top: none;
            border-radius: 0 0 8px 8px;
            padding: 20px;
            margin-top: -24px;
            margin-bottom: 24px;
        }
        .optional-fields-content.show {
            display: block;
        }
        .optional-fields-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .optional-fields-grid .form-group {
            margin-bottom: 0;
        }
        .optional-fields-grid label {
            font-size: 0.9rem;
        }
        .optional-fields-grid input {
            padding: 10px 12px;
            font-size: 0.9rem;
        }
        .field-hint {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 4px;
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
            text-decoration: none;
            display: inline-flex;
            align-items: center;
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
                'current_label' => 'Import Pallets',
                'extra' => $breadcrumb_extra
            ]);
        ?>

        <!-- Page Header -->
        <div class="page-header">
            <h1>Import Pallets</h1>
            <p>Upload a pallet manifest from your manufacturer to add pallets to inventory.</p>
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
                <h2>Step 1: Select Manufacturer & Upload Pallet Manifest</h2>

                <div class="info-banner">
                    <h3>What This Does</h3>
                    <p>Upload your manufacturer's pallet manifest to add pallets to your inventory. Each row represents one pallet.</p>
                    <ul>
                        <li><strong>Pallet ID</strong> - Manufacturer's pallet identifier (required)</li>
                        <li><strong>Wattage</strong> - Module wattage on this pallet (required)</li>
                        <li><strong>Quantity</strong> - Number of modules on this pallet (required)</li>
                    </ul>
                </div>

                <form id="uploadForm" enctype="multipart/form-data">
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

                    <div class="form-group" id="locationGroup" style="display: none;">
                        <label class="required" for="manufacturer_location_id">Manufacturer Location</label>
                        <select name="manufacturer_location_id" id="manufacturer_location_id">
                            <option value="">Select Location</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="destination_project_id">Project</label>
                        <select name="destination_project_id" id="destination_project_id">
                            <option value="">Unassigned</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo ($project_id && $project_id == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['project_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Leave as "Unassigned" if these pallets are not yet assigned to a specific project.</div>
                    </div>

                    <!-- Optional Module Details Section -->
                    <div class="optional-fields-toggle" id="optionalFieldsToggle">
                        <h4>Optional Module Details</h4>
                        <span class="toggle-icon">&#9660;</span>
                    </div>
                    <div class="optional-fields-content" id="optionalFieldsContent">
                        <p style="margin: 0 0 16px 0; color: #6c757d; font-size: 0.9rem;">
                            These fields are optional and will be applied to all imported pallets.
                            <strong>Modules per Pallet</strong> will be automatically calculated from your file's Quantity column.
                        </p>
                        <div class="optional-fields-grid">
                            <div class="form-group">
                                <label for="modules_per_pallet">Modules per Pallet</label>
                                <input type="number" id="modules_per_pallet" name="modules_per_pallet" placeholder="Auto from file" readonly style="background: #e9ecef;">
                                <div class="field-hint">Deduced from Quantity column</div>
                            </div>
                            <div class="form-group">
                                <label for="pallets_per_truck">Pallets per Truck</label>
                                <input type="number" id="pallets_per_truck" name="pallets_per_truck" min="1" placeholder="e.g., 24">
                            </div>
                            <div class="form-group">
                                <label for="modules_per_truck">Modules per Truck</label>
                                <input type="number" id="modules_per_truck" name="modules_per_truck" placeholder="Auto-calculated" readonly style="background: #e9ecef;">
                                <div class="field-hint">Pallets x Modules</div>
                            </div>
                            <div class="form-group">
                                <label for="trucks_needed">Trucks Needed</label>
                                <input type="number" id="trucks_needed" name="trucks_needed" placeholder="Auto-calculated" readonly style="background: #e9ecef;">
                                <div class="field-hint">Based on total pallets</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="required">Pallet Manifest File</label>
                        <div class="file-upload-area" id="fileDropArea">
                            <div class="upload-icon">📦</div>
                            <div class="upload-text">Drop your file here or click to browse</div>
                            <div class="upload-subtext">
                                Supports: CSV<?php echo $excelSupported ? ', Excel (.xlsx, .xls)' : ''; ?>
                            </div>
                        </div>
                        <input type="file" name="pallet_file" id="palletFile" accept=".csv<?php echo $excelSupported ? ',.xlsx,.xls' : ''; ?>" style="display: none;">
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
                    <p>Map your file columns to the system fields. Fields marked with <span class="required-field">*</span> are required.</p>
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
    const manufacturerLocationId = () => document.getElementById('manufacturer_location_id').value;
    const projectId = () => document.getElementById('destination_project_id')?.value || '';
    const accountId = () => document.querySelector('[name="account_id"]')?.value || '<?php echo $account_id ?? ''; ?>';

    // System fields for pallet import (simplified)
    const systemFields = {
        'pallet_id': {
            label: 'Pallet ID',
            required: true,
            common_names: ['Pallet', 'Pallet #', 'Pallet ID', 'Pallet Number', 'Serial', 'Serial #']
        },
        'wattage': {
            label: 'Wattage',
            required: true,
            common_names: ['Wattage', 'Watts', 'W', 'Power', 'Module Wattage', 'Wp']
        },
        'quantity': {
            label: 'Quantity (Modules per Pallet)',
            required: true,
            common_names: ['Quantity', 'Qty', 'Count', 'Modules', 'Module Count', 'PCS', 'Pieces']
        }
    };

    // DOM Elements
    const fileDropArea = document.getElementById('fileDropArea');
    const fileInput = document.getElementById('palletFile');
    const selectedFileDiv = document.getElementById('selectedFile');
    const btnNext1 = document.getElementById('btnNext1');
    const btnNext2 = document.getElementById('btnNext2');
    const btnBack2 = document.getElementById('btnBack2');
    const btnBack3 = document.getElementById('btnBack3');
    const btnConfirm = document.getElementById('btnConfirm');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const loadingText = document.getElementById('loadingText');

    // Optional fields toggle
    document.getElementById('optionalFieldsToggle').addEventListener('click', function() {
        this.classList.toggle('open');
        document.getElementById('optionalFieldsContent').classList.toggle('show');
    });

    // Auto-calculate modules per truck
    document.getElementById('pallets_per_truck').addEventListener('input', function() {
        updateCalculatedFields();
    });

    function updateCalculatedFields() {
        const modulesPerPallet = parseInt(document.getElementById('modules_per_pallet').value) || 0;
        const palletsPerTruck = parseInt(document.getElementById('pallets_per_truck').value) || 0;

        if (modulesPerPallet && palletsPerTruck) {
            document.getElementById('modules_per_truck').value = modulesPerPallet * palletsPerTruck;
        }

        // Calculate trucks needed based on total pallets in preview
        if (summary.total_pallets && palletsPerTruck > 0) {
            document.getElementById('trucks_needed').value = Math.ceil(summary.total_pallets / palletsPerTruck);
        }
    }

    // Manufacturer change - load locations
    document.getElementById('manufacturer_id').addEventListener('change', function() {
        const mfgId = this.value;
        const locationGroup = document.getElementById('locationGroup');
        const locationSelect = document.getElementById('manufacturer_location_id');

        if (mfgId) {
            fetch(`get_manufacturer_locations.php?manufacturer_id=${mfgId}`)
                .then(r => r.json())
                .then(data => {
                    locationSelect.innerHTML = '<option value="">Select Location</option>';
                    if (data.locations && data.locations.length > 0) {
                        data.locations.forEach(loc => {
                            locationSelect.innerHTML += `<option value="${loc.id}">${loc.location_name || loc.city}</option>`;
                        });
                        locationGroup.style.display = 'block';
                    } else {
                        locationGroup.style.display = 'none';
                    }
                    updateStep1Validation();
                })
                .catch(() => {
                    locationGroup.style.display = 'none';
                    updateStep1Validation();
                });
        } else {
            locationGroup.style.display = 'none';
            locationSelect.innerHTML = '<option value="">Select Location</option>';
        }
        updateStep1Validation();
    });

    document.getElementById('manufacturer_location_id').addEventListener('change', updateStep1Validation);

    // Step Navigation
    function goToStep(step) {
        currentStep = step;

        document.querySelectorAll('.step').forEach(s => {
            const stepNum = parseInt(s.dataset.step);
            s.classList.remove('active', 'completed');
            if (stepNum < step) s.classList.add('completed');
            if (stepNum === step) s.classList.add('active');
        });

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
        // Project is optional now (can be unassigned)
        btnNext1.disabled = !(uploadedFile && mfgSelected);
    }

    // Step 1 -> Step 2
    btnNext1.addEventListener('click', async () => {
        showLoading('Analyzing file...');

        const formData = new FormData();
        formData.append('pallet_file', uploadedFile);
        formData.append('action', 'parse_headers');
        formData.append('manufacturer_id', manufacturerId());

        try {
            const response = await fetch('process_pallet_upload.php', {
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

            buildMappingTable(suggestedMappings, savedMapping);

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

            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = fieldConfig.required ? '-- Select Column --' : '(Not mapped)';
            select.appendChild(emptyOpt);

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
        formData.append('pallet_file', uploadedFile);
        formData.append('action', 'parse_data');
        formData.append('manufacturer_id', manufacturerId());
        formData.append('manufacturer_location_id', manufacturerLocationId());
        formData.append('project_id', projectId());
        formData.append('account_id', accountId());
        formData.append('column_mapping', JSON.stringify(columnMapping));
        formData.append('save_mapping', document.getElementById('saveMappingCheckbox').checked ? '1' : '0');

        // Add optional module details
        formData.append('pallets_per_truck', document.getElementById('pallets_per_truck').value || '');

        try {
            const response = await fetch('process_pallet_upload.php', {
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

            // Update modules per pallet field with average from data
            if (summary.avg_modules_per_pallet) {
                document.getElementById('modules_per_pallet').value = summary.avg_modules_per_pallet;
                updateCalculatedFields();
            }

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
                <div class="stat-value">${summary.total_pallets || 0}</div>
                <div class="stat-label">Total Pallets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${(summary.total_modules || 0).toLocaleString()}</div>
                <div class="stat-label">Total Modules</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${summary.unique_wattages || 0}</div>
                <div class="stat-label">Wattage Types</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${summary.avg_modules_per_pallet || '-'}</div>
                <div class="stat-label">Avg Modules/Pallet</div>
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
        const headerCols = ['Pallet ID', 'Wattage', 'Quantity'];
        thead.innerHTML = headerCols.map(h => `<th>${h}</th>`).join('');

        // Rows (first 20)
        tbody.innerHTML = parsedData.slice(0, 20).map(row => `
            <tr>
                <td>${row.pallet_id || '-'}</td>
                <td>${row.wattage || '-'}W</td>
                <td>${row.quantity || '-'}</td>
            </tr>
        `).join('');

        if (parsedData.length > 20) {
            tbody.innerHTML += `<tr><td colspan="3" style="text-align:center; color:#6c757d;">...and ${parsedData.length - 20} more rows</td></tr>`;
        }
    }

    // Step 3 -> Step 2
    btnBack3.addEventListener('click', () => goToStep(2));

    // Step 3 -> Confirm Import
    btnConfirm.addEventListener('click', async () => {
        if (!confirm('Are you sure you want to import these pallets? This will add them to your inventory.')) {
            return;
        }

        showLoading('Importing pallets...');

        const formData = new FormData();
        formData.append('pallet_file', uploadedFile);
        formData.append('action', 'import');
        formData.append('manufacturer_id', manufacturerId());
        formData.append('manufacturer_location_id', manufacturerLocationId());
        formData.append('project_id', projectId());
        formData.append('account_id', accountId());
        formData.append('column_mapping', JSON.stringify(columnMapping));
        formData.append('save_mapping', document.getElementById('saveMappingCheckbox').checked ? '1' : '0');

        // Add optional module details
        formData.append('pallets_per_truck', document.getElementById('pallets_per_truck').value || '');

        try {
            const response = await fetch('process_pallet_upload.php', {
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
        const pid = projectId();

        if (result.success) {
            let viewLink = '';
            if (pid) {
                viewLink = `<a href="project_overview.php?project_id=${pid}" class="btn btn-primary">View Project</a>`;
            } else {
                viewLink = `<a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>`;
            }

            container.innerHTML = `
                <div class="results-icon success">✓</div>
                <h2>Import Complete!</h2>
                <div class="summary-stats">
                    <div class="stat-card">
                        <div class="stat-value">${result.pallets_created}</div>
                        <div class="stat-label">Pallets Created</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${result.modules_created || 0}</div>
                        <div class="stat-label">Module Items Created</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${(result.total_modules || 0).toLocaleString()}</div>
                        <div class="stat-label">Total Modules</div>
                    </div>
                </div>
                <div class="btn-group" style="justify-content: center; margin-top: 32px;">
                    ${viewLink}
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
