<?php
/**
 * Upload Shipments
 *
 * Multi-step upload process for importing shipments/deliveries.
 * Links existing pallets to deliveries based on BOL/Container number.
 * Works like create_shipment.php - user selects destination type (project or warehouse).
 *
 * Step 1: Upload file and select destination
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

// Fetch warehouses for destination options
$warehouses = [];
$warehouse_where = '';
$warehouse_params = [];
$warehouse_types = '';
if ($account_id) {
    $warehouse_where = 'WHERE account_id = ?';
    $warehouse_types = 'i';
    $warehouse_params[] = $account_id;
}
$sqlWh = "SELECT id, name, city, state FROM warehouses $warehouse_where ORDER BY name ASC";
$stmtWh = $conn->prepare($sqlWh);
if ($stmtWh) {
    if (!empty($warehouse_params)) {
        $stmtWh->bind_param($warehouse_types, ...$warehouse_params);
    }
    $stmtWh->execute();
    $resWh = $stmtWh->get_result();
    while ($row = $resWh->fetch_assoc()) {
        $warehouses[] = $row;
    }
    $stmtWh->close();
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
    <title>Import Shipments<?php echo $project ? ' - ' . htmlspecialchars($project['project_name']) : ''; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
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
        .info-banner.warning {
            background: linear-gradient(135deg, #fff3cd 0%, #fffbe6 100%);
            border-color: #ffc107;
        }
        .info-banner h3 {
            color: #0056b3;
            margin: 0 0 8px 0;
            font-size: 1rem;
        }
        .info-banner.warning h3 {
            color: #856404;
        }
        .info-banner p {
            color: #004085;
            margin: 0;
            font-size: 0.9rem;
        }
        .info-banner.warning p {
            color: #856404;
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
        .form-group input[type="date"],
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

        /* Destination Type Toggle */
        .destination-toggle {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }
        .destination-toggle label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-weight: 500;
            padding: 12px 20px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .destination-toggle label:hover {
            border-color: #488C9A;
        }
        .destination-toggle input[type="radio"] {
            width: auto;
        }
        .destination-toggle input[type="radio"]:checked + span {
            color: #488C9A;
        }
        .destination-toggle label:has(input:checked) {
            border-color: #488C9A;
            background: linear-gradient(135deg, #f0f8ff 0%, #ffffff 100%);
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
            border: none;
        }
        .preview-table thead tr {
            background: #488C9A !important;
        }
        .preview-table th {
            background: #488C9A !important;
            color: #ffffff !important;
            position: sticky;
            top: 0;
            font-weight: 600;
            text-align: left;
        }
        .preview-table tbody tr:hover {
            background: #f8f9fa;
        }
        .preview-table tbody td {
            background: #ffffff;
            color: #293E4C;
        }
        .preview-table .found { color: #28a745; }
        .preview-table .not-found { color: #dc3545; }

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
        .stat-value.warning { color: #ffc107; }
        .stat-value.danger { color: #dc3545; }
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

        /* Duplicate Info Banner */
        .duplicate-info-banner {
            background: linear-gradient(135deg, #e7f3ff 0%, #d4edda 100%);
            border: 2px solid #17a2b8;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .duplicate-info-banner h4 {
            color: #0c5460;
            margin: 0 0 12px 0;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
        }
        .duplicate-info-banner p {
            color: #0c5460;
            margin: 0 0 8px 0;
            font-size: 0.9rem;
        }
        .duplicate-info-banner p:last-child {
            margin-bottom: 0;
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

        /* Optional Fields */
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
        .field-hint {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 4px;
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
                'current_label' => 'Import Shipments',
                'extra' => $breadcrumb_extra
            ]);
        ?>

        <!-- Page Header -->
        <div class="page-header">
            <h1>Import Shipments</h1>
            <p>Upload a shipping manifest to create deliveries and link existing pallets.</p>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step active" data-step="1">
                <span class="step-number">1</span>
                <span>Upload & Configure</span>
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
                <h2>Step 1: Upload Shipping Manifest & Configure Destination</h2>

                <div class="info-banner warning">
                    <h3>Important: Import Pallets First</h3>
                    <p>Make sure your pallets are already in the system before importing shipments. This process links existing pallets to deliveries based on Pallet ID.</p>
                </div>

                <div class="info-banner">
                    <h3>Required Fields</h3>
                    <ul>
                        <li><strong>BOL/Container Number</strong> - Used to group pallets into shipments</li>
                        <li><strong>Pallet ID</strong> - Must match existing pallets in inventory</li>
                        <li><strong>Ship Date</strong> - When the shipment departed from the manufacturer</li>
                    </ul>
                    <p style="margin-top: 8px;"><strong>Optional:</strong> Freight Cost, Estimated Delivery Date, Actual Delivery Date</p>
                </div>

                <form id="uploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="account_id" value="<?php echo $account_id ?? ''; ?>">

                    <!-- Destination Type Selection -->
                    <div class="form-group">
                        <label class="required">Destination</label>
                        <div class="destination-toggle">
                            <label>
                                <input type="radio" name="destination_type" value="project" <?php echo $project_id ? 'checked' : ''; ?>>
                                <span>Project</span>
                            </label>
                            <label>
                                <input type="radio" name="destination_type" value="warehouse" <?php echo !$project_id ? 'checked' : ''; ?>>
                                <span>Warehouse</span>
                            </label>
                        </div>
                    </div>

                    <!-- Project Destination -->
                    <div class="form-group" id="projectDestGroup" style="<?php echo !$project_id ? 'display:none;' : ''; ?>">
                        <label class="required" for="destination_project_id">Select Project</label>
                        <select name="destination_project_id" id="destination_project_id">
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo ($project_id && $project_id == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['project_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Warehouse Destination -->
                    <div class="form-group" id="warehouseDestGroup" style="<?php echo $project_id ? 'display:none;' : ''; ?>">
                        <label class="required" for="destination_warehouse_id">Select Warehouse</label>
                        <select name="destination_warehouse_id" id="destination_warehouse_id">
                            <option value="">Select Warehouse</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?php echo $wh['id']; ?>">
                                    <?php echo htmlspecialchars($wh['name']); ?>
                                    <?php if ($wh['city'] || $wh['state']): ?>
                                        (<?php echo htmlspecialchars(trim($wh['city'] . ', ' . $wh['state'], ', ')); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- File Upload -->
                    <div class="form-group">
                        <label class="required">Shipping Manifest File</label>
                        <div class="file-upload-area" id="fileDropArea">
                            <div class="upload-icon">📥</div>
                            <div class="upload-text">Drop your file here or click to browse</div>
                            <div class="upload-subtext">
                                Supports: CSV<?php echo $excelSupported ? ', Excel (.xlsx, .xls)' : ''; ?>
                            </div>
                        </div>
                        <input type="file" name="shipment_file" id="shipmentFile" accept=".csv<?php echo $excelSupported ? ',.xlsx,.xls' : ''; ?>" style="display: none;">
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
                    <p style="color: #155724; margin: 8px 0 0 0;">We found a saved column mapping. The mappings below have been pre-filled.</p>
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

                <div class="btn-group" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <label style="display: flex; align-items: center; gap: 8px; margin: 0; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" id="saveMappingCheckbox" checked style="width: 18px; height: 18px; cursor: pointer;">
                        <span style="font-size: 0.9rem; color: #495057;">Save this mapping for future uploads</span>
                    </label>
                    <div style="display: flex; gap: 12px;">
                        <button type="button" class="btn btn-secondary" id="btnBack2">Back</button>
                        <button type="button" class="btn btn-primary" id="btnNext2">Next: Preview Import</button>
                    </div>
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

                <!-- Duplicate BOL Info Banner -->
                <div class="duplicate-info-banner" id="duplicateInfoBanner" style="display: none;">
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

    const getDestinationType = () => document.querySelector('input[name="destination_type"]:checked')?.value || 'project';
    const getDestinationId = () => {
        const type = getDestinationType();
        if (type === 'project') {
            return document.getElementById('destination_project_id').value;
        } else {
            return document.getElementById('destination_warehouse_id').value;
        }
    };
    const accountId = () => document.querySelector('[name="account_id"]')?.value || '<?php echo $account_id ?? ''; ?>';

    // System fields for shipment import
    const systemFields = {
        'bol_number': {
            label: 'BOL / Container Number',
            required: true,
            common_names: ['BOL', 'BOL #', 'BOL Number', 'Container', 'Container #', 'Container Number', 'CNTR', 'Tracking']
        },
        'pallet_id': {
            label: 'Pallet ID',
            required: true,
            common_names: ['Pallet', 'Pallet #', 'Pallet ID', 'Pallet Number', 'Serial', 'Serial #']
        },
        'ship_date': {
            label: 'Ship Date',
            required: true,
            common_names: ['Ship Date', 'Shipping Date', 'Departure Date', 'Departure', 'Shipped', 'Ship', 'Dispatch Date', 'Date Shipped']
        },
        'freight_cost': {
            label: 'Freight Cost',
            required: false,
            common_names: ['Freight', 'Cost', 'Freight Cost', 'Shipping Cost', 'Price']
        },
        'estimated_delivery': {
            label: 'Estimated Delivery Date',
            required: false,
            common_names: ['ETA', 'Est. Delivery', 'Estimated Delivery', 'Expected', 'Due Date']
        },
        'actual_delivery': {
            label: 'Actual Delivery Date',
            required: false,
            common_names: ['Delivery Date', 'Actual Delivery', 'Delivered', 'Arrival Date']
        }
    };

    // DOM Elements
    const fileDropArea = document.getElementById('fileDropArea');
    const fileInput = document.getElementById('shipmentFile');
    const selectedFileDiv = document.getElementById('selectedFile');
    const btnNext1 = document.getElementById('btnNext1');
    const btnNext2 = document.getElementById('btnNext2');
    const btnBack2 = document.getElementById('btnBack2');
    const btnBack3 = document.getElementById('btnBack3');
    const btnConfirm = document.getElementById('btnConfirm');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const loadingText = document.getElementById('loadingText');

    // Destination type toggle
    document.querySelectorAll('input[name="destination_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const isProject = this.value === 'project';
            document.getElementById('projectDestGroup').style.display = isProject ? 'block' : 'none';
            document.getElementById('warehouseDestGroup').style.display = isProject ? 'none' : 'block';
            updateStep1Validation();
        });
    });

    document.getElementById('destination_project_id').addEventListener('change', updateStep1Validation);
    document.getElementById('destination_warehouse_id').addEventListener('change', updateStep1Validation);

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
        const destId = getDestinationId();
        btnNext1.disabled = !(uploadedFile && destId);
    }

    // Step 1 -> Step 2
    btnNext1.addEventListener('click', async () => {
        showLoading('Analyzing file...');

        const formData = new FormData();
        formData.append('shipment_file', uploadedFile);
        formData.append('action', 'parse_headers');
        formData.append('account_id', accountId());

        try {
            const response = await fetch('process_shipment_upload.php', {
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

        showLoading('Parsing data and validating pallets...');

        const formData = new FormData();
        formData.append('shipment_file', uploadedFile);
        formData.append('action', 'parse_data');
        formData.append('destination_type', getDestinationType());
        formData.append('destination_id', getDestinationId());
        formData.append('account_id', accountId());
        formData.append('column_mapping', JSON.stringify(columnMapping));
        formData.append('save_mapping', document.getElementById('saveMappingCheckbox').checked ? '1' : '0');

        try {
            const response = await fetch('process_shipment_upload.php', {
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
        const palletsNotFoundClass = summary.pallets_not_found > 0 ? 'danger' : '';
        const hasExistingBols = summary.existing_bols && summary.existing_bols > 0;

        statsDiv.innerHTML = `
            <div class="stat-card">
                <div class="stat-value">${summary.unique_shipments || 0}</div>
                <div class="stat-label">Shipments (BOL/Container)</div>
            </div>
            ${hasExistingBols ? `
            <div class="stat-card" style="border: 2px solid #17a2b8;">
                <div class="stat-value" style="color: #17a2b8;">${summary.new_bols || 0}</div>
                <div class="stat-label">New Shipments</div>
            </div>
            <div class="stat-card" style="border: 2px solid #ffc107;">
                <div class="stat-value" style="color: #856404;">${summary.existing_bols || 0}</div>
                <div class="stat-label">Will Update</div>
            </div>
            ` : ''}
            <div class="stat-card">
                <div class="stat-value">${summary.pallets_found || 0}</div>
                <div class="stat-label">Pallets Found</div>
            </div>
            <div class="stat-card">
                <div class="stat-value ${palletsNotFoundClass}">${summary.pallets_not_found || 0}</div>
                <div class="stat-label">Not Found</div>
            </div>
        `;

        // Show BOL duplicate info banner if there are existing BOLs
        const duplicateInfoDiv = document.getElementById('duplicateInfoBanner');
        if (hasExistingBols && duplicateInfoDiv) {
            const existingBolList = summary.existing_bol_list || [];
            const examples = existingBolList.slice(0, 3).join(', ');

            duplicateInfoDiv.innerHTML = `
                <h4><span>🔄</span> Existing Shipments Detected</h4>
                <p><strong>${summary.existing_bols}</strong> BOL/Container number(s) already exist in the system and their pallet assignments will be <strong>updated</strong>.</p>
                <p><strong>${summary.new_bols}</strong> new shipment(s) will be created.</p>
                ${existingBolList.length > 0 ? `<p style="font-size: 0.85rem; margin-top: 8px; opacity: 0.8;">Existing BOLs: ${examples}${existingBolList.length > 3 ? '...' : ''}</p>` : ''}
            `;
            duplicateInfoDiv.style.display = 'block';
        } else if (duplicateInfoDiv) {
            duplicateInfoDiv.style.display = 'none';
        }

        // Warnings - categorize by type
        const warningsDiv = document.getElementById('previewWarnings');
        const warningsList = document.getElementById('warningsList');
        if (warnings.length > 0) {
            const errors = warnings.filter(w => w.type === 'error');
            const warns = warnings.filter(w => w.type !== 'error');

            let html = '';
            if (errors.length > 0) {
                html += '<li style="color: #dc3545; font-weight: 600; list-style: none; margin-bottom: 8px;">Errors (will be skipped):</li>';
                html += errors.slice(0, 10).map(w =>
                    `<li style="color: #dc3545;">${w.row > 0 ? `Row ${w.row}: ` : ''}${w.message}</li>`
                ).join('');
                if (errors.length > 10) html += `<li style="color: #dc3545;">...and ${errors.length - 10} more errors</li>`;
            }
            if (warns.length > 0) {
                if (errors.length > 0) html += '<li style="list-style: none; margin: 12px 0 8px 0; border-top: 1px solid #ddd; padding-top: 8px;"></li>';
                html += '<li style="color: #856404; font-weight: 600; list-style: none; margin-bottom: 8px;">Warnings (review recommended):</li>';
                html += warns.slice(0, 10).map(w =>
                    `<li style="color: #856404;">${w.row > 0 ? `Row ${w.row}: ` : ''}${w.message}</li>`
                ).join('');
                if (warns.length > 10) html += `<li style="color: #856404;">...and ${warns.length - 10} more warnings</li>`;
            }
            warningsList.innerHTML = html;
            warningsDiv.style.display = 'block';
        } else {
            warningsDiv.style.display = 'none';
        }

        // Preview table
        const thead = document.getElementById('previewTableHead');
        const tbody = document.getElementById('previewTableBody');

        // Check which optional columns have data
        const hasFreightCost = parsedData.some(row => row.freight_cost && row.freight_cost > 0);
        const hasEstDelivery = parsedData.some(row => row.estimated_delivery);
        const hasActualDelivery = parsedData.some(row => row.actual_delivery);

        // Build headers dynamically based on mapped columns
        let headerCols = ['BOL/Container', 'Pallet ID', 'Ship Date'];
        if (hasEstDelivery) headerCols.push('Est. Delivery');
        if (hasActualDelivery) headerCols.push('Actual Delivery');
        if (hasFreightCost) headerCols.push('Freight Cost');
        headerCols.push('Status', 'Pallet Found');

        thead.innerHTML = headerCols.map(h => `<th>${h}</th>`).join('');

        // Rows (first 20)
        tbody.innerHTML = parsedData.slice(0, 20).map(row => {
            const foundClass = row.pallet_found ? 'found' : 'not-found';
            const foundText = row.pallet_found ? '✓ Yes' : '✗ Not Found';
            const shipDate = row.ship_date || '-';
            const estDelivery = row.estimated_delivery || '-';
            const actualDelivery = row.actual_delivery || '-';
            const freightCost = row.freight_cost ? '$' + parseFloat(row.freight_cost).toFixed(2) : '-';

            let cells = `
                <td>${row.bol_number || '-'}</td>
                <td>${row.pallet_id || '-'}</td>
                <td>${shipDate}</td>
            `;
            if (hasEstDelivery) cells += `<td>${estDelivery}</td>`;
            if (hasActualDelivery) cells += `<td>${actualDelivery}</td>`;
            if (hasFreightCost) cells += `<td>${freightCost}</td>`;
            cells += `
                <td>${row.calculated_status || '-'}</td>
                <td class="${foundClass}">${foundText}</td>
            `;

            return `<tr>${cells}</tr>`;
        }).join('');

        if (parsedData.length > 20) {
            tbody.innerHTML += `<tr><td colspan="${headerCols.length}" style="text-align:center; color:#6c757d;">...and ${parsedData.length - 20} more rows</td></tr>`;
        }
    }

    // Step 3 -> Step 2
    btnBack3.addEventListener('click', () => goToStep(2));

    // Step 3 -> Confirm Import
    btnConfirm.addEventListener('click', async () => {
        if (summary.pallets_not_found > 0) {
            if (!confirm(`Warning: ${summary.pallets_not_found} pallet(s) were not found in the system and will be skipped. Continue anyway?`)) {
                return;
            }
        } else {
            if (!confirm('Are you sure you want to import these shipments?')) {
                return;
            }
        }

        showLoading('Creating shipments...');

        const formData = new FormData();
        formData.append('shipment_file', uploadedFile);
        formData.append('action', 'import');
        formData.append('destination_type', getDestinationType());
        formData.append('destination_id', getDestinationId());
        formData.append('account_id', accountId());
        formData.append('column_mapping', JSON.stringify(columnMapping));
        formData.append('save_mapping', document.getElementById('saveMappingCheckbox').checked ? '1' : '0');

        try {
            const response = await fetch('process_shipment_upload.php', {
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
        const destType = getDestinationType();
        const destId = getDestinationId();

        if (result.success) {
            let viewLink = '';
            if (destType === 'project') {
                viewLink = `<a href="project_overview.php?project_id=${destId}" class="btn btn-primary">View Project</a>`;
            } else {
                viewLink = `<a href="manage_deliveries.php" class="btn btn-primary">View Deliveries</a>`;
            }

            container.innerHTML = `
                <div class="results-icon success">✓</div>
                <h2>Import Complete!</h2>
                <div class="summary-stats">
                    <div class="stat-card">
                        <div class="stat-value">${result.deliveries_created || 0}</div>
                        <div class="stat-label">Deliveries Created</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${result.pallets_linked || 0}</div>
                        <div class="stat-label">Pallets Linked</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${result.pallets_skipped || 0}</div>
                        <div class="stat-label">Pallets Skipped</div>
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
