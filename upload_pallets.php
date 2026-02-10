<?php
/**
 * Upload Pallets
 *
 * Multi-step upload process for importing pallets/modules into inventory.
 * Step 1: Select manufacturer and upload file
 * Step 2: Map columns
 * Step 3: Pricing & Milestones
 * Step 4: Preview and confirm import
 * Step 5: Results
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
$project_size_mw = 0;
$existing_modules = 0;
$existing_mw = 0;
if ($project_id) {
    $stmt = $conn->prepare("SELECT id, project_name, account_id, project_size FROM projects WHERE id = ?");
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
    $project_size_mw = floatval($project['project_size'] ?? 0);

    // Get existing modules count and MW for this project
    $stmt = $conn->prepare("
        SELECT SUM(ip.quantity) as total_modules,
               SUM(ip.quantity * ip.wattage) / 1000000 as total_mw
        FROM inventory_pallets ip
        WHERE ip.assigned_project_id = ?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();

    $existing_modules = intval($existing['total_modules'] ?? 0);
    $existing_mw = floatval($existing['total_mw'] ?? 0);
}

// Fetch manufacturers for dropdown
$manufacturers = [];
$manufacturer_where = "WHERE is_active = 1";
$manufacturer_params = [];
$manufacturer_types = '';
$manufacturer_has_account_id = false;
$accountColumnResult = $conn->query("SHOW COLUMNS FROM manufacturers LIKE 'account_id'");
if ($accountColumnResult) {
    $manufacturer_has_account_id = $accountColumnResult->num_rows > 0;
    $accountColumnResult->close();
}

if ($account_id && $manufacturer_has_account_id) {
    $manufacturer_where .= " AND account_id = ?";
    $manufacturer_types = 'i';
    $manufacturer_params[] = $account_id;
}
$sqlMfg = "SELECT id, name, short_name FROM manufacturers $manufacturer_where ORDER BY name ASC";
$stmtMfg = $conn->prepare($sqlMfg);
if ($stmtMfg) {
    if (!empty($manufacturer_params)) {
        $stmtMfg->bind_param($manufacturer_types, ...$manufacturer_params);
    }
    $stmtMfg->execute();
    $resMfg = $stmtMfg->get_result();
    while ($row = $resMfg->fetch_assoc()) {
        $manufacturers[] = $row;
    }
    $stmtMfg->close();
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

        /* Location Address Display */
        .location-address-display {
            margin-top: 8px;
            padding: 10px 14px;
            background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%);
            border: 1px solid #b8daff;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #0056b3;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .location-address-display .address-icon {
            font-size: 1rem;
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

        /* Inline Logistics Button (for steps 2, 3, 4) */
        .logistics-inline-btn,
        .module-docs-inline-btn {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 2px solid #488C9A;
            border-radius: 8px;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #488C9A;
            transition: all 0.2s ease;
            margin-bottom: 24px;
        }
        .module-docs-inline-btn {
            border-color: #6f42c1;
            color: #6f42c1;
            margin-left: 12px;
        }
        .logistics-inline-btn:hover {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
        }
        .module-docs-inline-btn:hover {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
            color: #fff;
        }
        .logistics-inline-btn.has-data {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border-color: #28a745;
            color: #155724;
        }
        .logistics-inline-btn.has-data:hover {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: #fff;
        }
        .module-docs-inline-btn.has-data {
            background: linear-gradient(135deg, #e2d9f3 0%, #d4c7eb 100%);
            border-color: #6f42c1;
            color: #6f42c1;
        }
        .module-docs-inline-btn.has-data:hover {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
            color: #fff;
        }
        .logistics-inline-btn .badge,
        .module-docs-inline-btn .badge {
            background: #28a745;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 4px;
        }
        .module-docs-inline-btn .badge {
            background: #6f42c1;
        }
        .inline-buttons-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0;
            margin-bottom: 24px;
        }
        .inline-buttons-row .logistics-inline-btn,
        .inline-buttons-row .module-docs-inline-btn {
            margin-bottom: 0;
        }
        .logistics-panel-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        .logistics-panel-overlay.open {
            opacity: 1;
            visibility: visible;
        }
        .logistics-panel {
            position: fixed;
            top: 0;
            right: -450px;
            width: 450px;
            max-width: 90vw;
            height: 100vh;
            background: #fff;
            box-shadow: -5px 0 25px rgba(0, 0, 0, 0.15);
            z-index: 1002;
            transition: right 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .logistics-panel.open {
            right: 0;
        }
        .logistics-panel-header {
            padding: 20px 24px;
            background: #ffffff !important;
            color: #293E4C !important;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e9ecef;
        }
        .logistics-panel-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #293E4C !important;
        }
        .logistics-panel-close {
            background: none;
            border: none;
            color: #6c757d !important;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            opacity: 0.8;
        }
        .logistics-panel-close:hover {
            opacity: 1;
        }
        .logistics-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }
        .logistics-panel-section {
            margin-bottom: 24px;
        }
        .logistics-panel-section h5 {
            margin: 0 0 12px 0;
            color: #293E4C;
            font-size: 14px;
            font-weight: 600;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 8px;
        }
        .logistics-panel-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .logistics-panel-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .logistics-panel-field label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .logistics-panel-field input,
        .logistics-panel-field textarea {
            padding: 8px 10px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 13px;
            transition: border-color 0.2s;
        }
        .logistics-panel-field input:focus,
        .logistics-panel-field textarea:focus {
            outline: none;
            border-color: #488C9A;
        }
        .logistics-panel-field input[readonly] {
            background: #f8f9fa;
            color: #6c757d;
        }
        .logistics-panel-field.full-width {
            grid-column: 1 / -1;
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

        /* Project Info Banner */
        .project-info-banner {
            background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%);
            border: 1px solid #b8daff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .project-info-banner h3 {
            color: #0056b3;
            margin: 0 0 12px 0;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .project-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        .project-info-item {
            background: white;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }
        .project-info-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #488C9A;
        }
        .project-info-label {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 4px;
        }
        .project-info-item.target .project-info-value {
            color: #293E4C;
        }
        .project-info-progress {
            margin-top: 12px;
            background: white;
            border-radius: 8px;
            padding: 12px;
        }
        .progress-bar-bg {
            background: #e9ecef;
            border-radius: 8px;
            height: 20px;
            overflow: hidden;
        }
        .progress-bar-fill {
            background: linear-gradient(90deg, #488C9A 0%, #3a7086 100%);
            height: 100%;
            border-radius: 8px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 11px;
            font-weight: 600;
        }
        .progress-labels {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #6c757d;
            margin-top: 6px;
        }

        /* MW Comparison Section in Preview */
        .mw-comparison {
            background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%);
            border: 1px solid #b8daff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .mw-comparison h4 {
            color: #0056b3;
            margin: 0 0 16px 0;
            font-size: 1rem;
        }
        .mw-comparison-grid {
            display: grid;
            grid-template-columns: 1fr auto 1fr auto 1fr;
            gap: 8px;
            align-items: center;
            text-align: center;
        }
        .mw-box {
            background: white;
            border-radius: 8px;
            padding: 16px;
        }
        .mw-box-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: #488C9A;
        }
        .mw-box-label {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .mw-box.total .mw-box-value {
            color: #28a745;
        }
        .mw-box.target .mw-box-value {
            color: #293E4C;
        }
        .mw-operator {
            font-size: 1.5rem;
            color: #6c757d;
            font-weight: bold;
        }

        /* Over Capacity Warning */
        .over-capacity-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe8a1 100%);
            border: 2px solid #ffc107;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            display: none;
        }
        .over-capacity-warning.show {
            display: block;
        }
        .over-capacity-warning.critical {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            border-color: #dc3545;
        }
        .over-capacity-warning h4 {
            color: #856404;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .over-capacity-warning.critical h4 {
            color: #721c24;
        }
        .over-capacity-warning p {
            color: #856404;
            margin: 0;
        }
        .over-capacity-warning.critical p {
            color: #721c24;
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

        /* Highlight existing pallets in preview */
        .preview-table tbody tr.existing-pallet {
            background: #fff3cd !important;
        }
        .preview-table tbody tr.existing-pallet td {
            background: #fff3cd !important;
        }
        .preview-table tbody tr.existing-pallet:hover {
            background: #ffe8a1 !important;
        }
        .preview-table tbody tr.existing-pallet:hover td {
            background: #ffe8a1 !important;
        }
        .pallet-status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .pallet-status-badge.new {
            background: #d4edda;
            color: #155724;
        }
        .pallet-status-badge.update {
            background: #fff3cd;
            color: #856404;
        }
        /* Pricing & Domestic Content Grid */
        .pricing-top-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }
        .pricing-top-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
        }
        .pricing-top-card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        .pricing-top-icon {
            font-size: 1.1rem;
        }
        .pricing-top-card .form-group label {
            font-size: 0.9rem;
        }
        .pricing-top-card .form-group input[type="number"] {
            width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 700px) {
            .pricing-top-grid { grid-template-columns: 1fr; }
        }

        /* Milestone Styles (from module_batch_styles for step 3) */
        .form-subsection-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #293E4C;
            margin: 24px 0 12px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }
        .form-subsection-title:first-of-type { margin-top: 0; }
        .milestone-row {
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
            border: 1px solid #e9ecef;
        }
        .milestone-row-inner {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }
        .milestone-trigger-col { flex: 3; min-width: 0; }
        .milestone-pct-col { flex: 0 0 120px; }
        .milestone-remove-col { flex: 0 0 auto; }
        .milestone-label { font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.3px; display: block; margin-bottom: 4px; }
        .milestone-select, .milestone-input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 13px;
        }
        .milestone-input { width: 80px; }
        .milestone-remove-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        .milestone-trigger-info {
            margin-top: 8px;
            font-size: 11px;
            color: #17a2b8;
            font-style: italic;
            min-height: 14px;
        }
        .milestone-po-date-row {
            display: none;
            margin-top: 8px;
            padding: 10px 12px;
            background: linear-gradient(135deg, #e8f4f8 0%, #f0f8ff 100%);
            border: 1px solid #b8daff;
            border-radius: 6px;
        }
        .milestone-po-date-row.visible {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .milestone-po-date-row label {
            font-size: 12px;
            font-weight: 600;
            color: #488C9A;
            white-space: nowrap;
        }
        .milestone-po-date-row input[type="date"] {
            padding: 6px 10px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 13px;
            max-width: 200px;
        }
        .milestone-po-date-row input[type="date"]:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 2px rgba(72, 140, 154, 0.15);
        }
        .help-text { font-size: 0.85rem; color: #6c757d; margin-top: 6px; }

        /* Inline form row (2-column grid) */
        .form-row-inline {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 700px) {
            .form-row-inline { grid-template-columns: 1fr; }
        }

        /* Clickable step indicators */
        .step { cursor: default; }
        .step.completed { cursor: pointer; }
        .step.completed:hover { filter: brightness(0.95); }
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
                <span>Pricing & Milestones</span>
            </div>
            <div class="step" data-step="4">
                <span class="step-number">4</span>
                <span>Preview</span>
            </div>
            <div class="step" data-step="5">
                <span class="step-number">5</span>
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

                <?php if ($project && $project_size_mw > 0): ?>
                <div class="project-info-banner" id="projectInfoBanner">
                    <h3><span>📊</span> Current Project Status: <?php echo htmlspecialchars($project['project_name']); ?></h3>
                    <div class="project-info-grid">
                        <div class="project-info-item">
                            <div class="project-info-value"><?php echo number_format($existing_modules); ?></div>
                            <div class="project-info-label">Modules Added</div>
                        </div>
                        <div class="project-info-item">
                            <div class="project-info-value"><?php echo number_format($existing_mw, 2); ?> MW</div>
                            <div class="project-info-label">Current Capacity</div>
                        </div>
                        <div class="project-info-item target">
                            <div class="project-info-value"><?php echo number_format($project_size_mw, 2); ?> MW</div>
                            <div class="project-info-label">Project Target</div>
                        </div>
                        <div class="project-info-item">
                            <div class="project-info-value"><?php echo number_format(max(0, $project_size_mw - $existing_mw), 2); ?> MW</div>
                            <div class="project-info-label">Remaining</div>
                        </div>
                    </div>
                    <?php
                    $current_pct = $project_size_mw > 0 ? min(100, ($existing_mw / $project_size_mw) * 100) : 0;
                    ?>
                    <div class="project-info-progress">
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: <?php echo $current_pct; ?>%;">
                                <?php echo number_format($current_pct, 1); ?>%
                            </div>
                        </div>
                        <div class="progress-labels">
                            <span>0 MW</span>
                            <span><?php echo number_format($project_size_mw, 2); ?> MW Target</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <form id="uploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="account_id" value="<?php echo $account_id ?? ''; ?>">

                    <!-- Row 1: Batch Name | Project -->
                    <div class="form-row-inline">
                        <div class="form-group">
                            <label for="batch_name">Batch Name</label>
                            <input type="text" name="batch_name" id="batch_name" placeholder="e.g. Q1 Delivery, Phase 2 Panels...">
                            <div class="field-hint">Optional label for this import batch.</div>
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
                    </div>

                    <!-- Row 2: Manufacturer | Location -->
                    <div class="form-row-inline">
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
                        <div class="form-group" id="locationGroup">
                            <label class="required" for="manufacturer_location_id">Manufacturer Location</label>
                            <select name="manufacturer_location_id" id="manufacturer_location_id" disabled>
                                <option value="">Select a manufacturer first</option>
                            </select>
                            <div id="locationAddressDisplay" class="location-address-display" style="display: none;">
                                <span class="address-icon">📍</span>
                                <span id="locationAddressText"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Logistics & Documentation Buttons for Step 1 -->
                    <div class="inline-buttons-row">
                        <button type="button" class="logistics-inline-btn" id="logisticsBtn1" onclick="openLogisticsPanel()">
                            <span>📦</span>
                            <span>Logistics Specifications</span>
                            <span class="badge" id="logisticsBadge1" style="display: none;">Set</span>
                        </button>
                        <button type="button" class="module-docs-inline-btn" id="moduleDocsBtn1" onclick="openModuleDocsPanel()">
                            <span>📄</span>
                            <span>Module Documentation</span>
                            <span class="badge" id="moduleDocsBadge1" style="display: none;"></span>
                        </button>
                    </div>

                    <!-- Hidden fields container to store logistics values -->
                    <div class="optional-fields-content" id="optionalFieldsContent" style="display: none;">
                        <p style="margin: 0 0 16px 0; color: #6c757d; font-size: 0.9rem;">
                            These fields are optional and will be applied to the module batch for freight planning, warehouse handling,
                            and site receiving. <strong>Modules per Pallet</strong> will be automatically calculated from your file's
                            Quantity column.
                        </p>

                        <!-- Truck/Pallet Calculations -->
                        <h5 style="margin: 0 0 12px 0; color: #293E4C; font-size: 0.95rem; border-bottom: 1px solid #e9ecef; padding-bottom: 8px;">Truck & Pallet Info</h5>
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

                        <!-- Pallet Dimensions -->
                        <h5 style="margin: 20px 0 12px 0; color: #293E4C; font-size: 0.95rem; border-bottom: 1px solid #e9ecef; padding-bottom: 8px;">Pallet Dimensions</h5>
                        <div class="optional-fields-grid">
                            <div class="form-group">
                                <label for="pallet_length_mm">Length (mm)</label>
                                <input type="number" id="pallet_length_mm" name="pallet_length_mm" min="1" placeholder="e.g., 2384">
                            </div>
                            <div class="form-group">
                                <label for="pallet_depth_mm">Depth (mm)</label>
                                <input type="number" id="pallet_depth_mm" name="pallet_depth_mm" min="1" placeholder="e.g., 1303">
                            </div>
                            <div class="form-group">
                                <label for="pallet_double_stacked_height_mm">Stack Height (mm)</label>
                                <input type="number" id="pallet_double_stacked_height_mm" name="pallet_double_stacked_height_mm" min="1" placeholder="e.g., 2200">
                            </div>
                            <div class="form-group">
                                <label for="pallet_total_weight_kg">Weight (kg)</label>
                                <input type="number" id="pallet_total_weight_kg" name="pallet_total_weight_kg" min="1" placeholder="e.g., 1200">
                            </div>
                        </div>

                        <!-- Handling Requirements -->
                        <h5 style="margin: 20px 0 12px 0; color: #293E4C; font-size: 0.95rem; border-bottom: 1px solid #e9ecef; padding-bottom: 8px;">Handling Requirements</h5>
                        <div class="optional-fields-grid">
                            <div class="form-group">
                                <label for="forklift_truck_long_side_mm">Forklift Long Side (mm)</label>
                                <input type="number" id="forklift_truck_long_side_mm" name="forklift_truck_long_side_mm" min="1" placeholder="e.g., 1200">
                            </div>
                            <div class="form-group">
                                <label for="forklift_truck_short_side_mm">Forklift Short Side (mm)</label>
                                <input type="number" id="forklift_truck_short_side_mm" name="forklift_truck_short_side_mm" min="1" placeholder="e.g., 1000">
                            </div>
                            <div class="form-group">
                                <label for="pallet_jack_long_side_mm">Pallet Jack Long (mm)</label>
                                <input type="number" id="pallet_jack_long_side_mm" name="pallet_jack_long_side_mm" min="1" placeholder="e.g., 1150">
                            </div>
                            <div class="form-group">
                                <label for="pallet_jack_short_side_mm">Pallet Jack Short (mm)</label>
                                <input type="number" id="pallet_jack_short_side_mm" name="pallet_jack_short_side_mm" min="1" placeholder="e.g., 800">
                            </div>
                        </div>

                        <!-- Stacking Instructions -->
                        <h5 style="margin: 20px 0 12px 0; color: #293E4C; font-size: 0.95rem; border-bottom: 1px solid #e9ecef; padding-bottom: 8px;">Stacking Instructions</h5>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label for="stacking_in_warehouse">Warehouse Stacking</label>
                                <textarea id="stacking_in_warehouse" name="stacking_in_warehouse" rows="2" placeholder="Instructions for warehouse stacking..."></textarea>
                            </div>
                            <div class="form-group">
                                <label for="stacking_during_transport">Transport Stacking</label>
                                <textarea id="stacking_during_transport" name="stacking_during_transport" rows="2" placeholder="Instructions for transport stacking..."></textarea>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: 12px;">
                            <label for="module_notes">Module Notes</label>
                            <textarea id="module_notes" name="module_notes" rows="2" placeholder="General notes about the modules..."></textarea>
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

                <!-- Logistics & Documentation Buttons for Step 2 -->
                <div class="inline-buttons-row">
                    <button type="button" class="logistics-inline-btn" id="logisticsBtn2" onclick="openLogisticsPanel()">
                        <span>📦</span>
                        <span>Logistics Specifications</span>
                        <span class="badge" id="logisticsBadge2" style="display: none;">Set</span>
                    </button>
                    <button type="button" class="module-docs-inline-btn" id="moduleDocsBtn2" onclick="openModuleDocsPanel()">
                        <span>📄</span>
                        <span>Module Documentation</span>
                        <span class="badge" id="moduleDocsBadge2" style="display: none;"></span>
                    </button>
                </div>

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

                <div class="btn-group" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <label style="display: flex; align-items: center; gap: 8px; margin: 0; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" id="saveMappingCheckbox" checked style="width: 18px; height: 18px; cursor: pointer;">
                        <span style="font-size: 0.9rem; color: #495057;">Save this mapping for future uploads</span>
                    </label>
                    <div style="display: flex; gap: 12px;">
                        <button type="button" class="btn btn-secondary" id="btnBack2">Back</button>
                        <button type="button" class="btn btn-primary" id="btnNext2">Next: Pricing & Milestones</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Pricing & Milestones -->
        <div class="step-content" id="step3">
            <div class="content-card">
                <h2>Step 3: Pricing & Milestones</h2>

                <?php $module = []; $existingMilestones = []; ?>

                <!-- Pricing & Domestic Content side by side -->
                <div class="pricing-top-grid">
                    <div class="pricing-top-card">
                        <div class="pricing-top-card-header">
                            <span class="pricing-top-icon">💲</span>
                            <span>Cost Per Watt</span>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="cost_per_watt">Price per Watt ($/W)</label>
                            <input type="number" step="0.000001" min="0" name="cost_per_watt" id="cost_per_watt"
                                   placeholder="e.g. 0.25"
                                   value="<?php echo htmlspecialchars($module['cost_per_watt'] ?? ''); ?>"
                                   oninput="mb_updateMilestoneAvailability()">
                            <span class="help-text">Used for reporting and milestone calculations.</span>
                        </div>
                    </div>
                    <div class="pricing-top-card">
                        <div class="pricing-top-card-header">
                            <span class="pricing-top-icon">🏭</span>
                            <span>Domestic Content</span>
                        </div>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 0;">
                                <input type="checkbox" id="track_domestic_content" name="track_domestic_content" value="1"
                                       style="width: 18px; height: 18px; accent-color: #488C9A; cursor: pointer;">
                                <span>Track Domestic Content %</span>
                            </label>
                        </div>
                        <div class="form-group" id="domesticContentPctGroup" style="display: none; margin-bottom: 0;">
                            <label for="domestic_content_pct">Percentage</label>
                            <input type="number" step="0.01" min="0" max="100" id="domestic_content_pct" name="domestic_content_pct" placeholder="e.g. 45.50">
                            <span class="help-text">Applied to all imported rows in this batch.</span>
                        </div>
                        <div id="domesticContentDisabledHint" class="help-text" style="margin-top: 4px;">Enable to apply a domestic-content percentage to this batch.</div>
                    </div>
                </div>

                <!-- Milestones section from shared component (without the cost per watt part) -->
                <div class="form-subsection-title">Payment Milestones <span style="font-weight: 400; font-size: 0.85rem; color: #6c757d;">(optional)</span></div>
                <p style="margin: 8px 0 16px 0; color: #6c757d; font-size: 0.85rem;">
                    Configure when payments are triggered based on delivery events.
                    Payment = <strong>cost per watt &times; wattage &times; quantity &times; percentage</strong>
                </p>
                <div id="mb_milestoneRequiresCost" style="display: none; padding: 12px 16px; background: #f8f9fa; border: 1px dashed #dee2e6; border-radius: 8px; color: #6c757d; font-size: 13px; text-align: center;">
                    Enter a cost per watt above to configure payment milestones.
                </div>
                <div id="mb_milestoneConfigArea">
                    <div id="mb_milestoneContainer"></div>
                    <button type="button" id="mb_addMilestoneBtn" style="margin-top: 12px; padding: 10px 16px; background: #17a2b8; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">
                        + Add Milestone
                    </button>
                </div>
                <div id="mb_milestoneTotalArea" style="margin-top: 20px; padding: 12px 16px; background: #f8f9fa; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 500; color: #293E4C;">Total Percentage:</span>
                    <span id="mb_milestoneTotalPercent" style="font-weight: 600; font-size: 1.1rem; color: #17a2b8;">0%</span>
                </div>
                <div id="mb_milestonePercentWarning" style="display: none; margin-top: 8px; padding: 8px 12px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; color: #856404; font-size: 12px;">
                    Note: Total percentage does not equal 100%. This is allowed but may indicate incomplete milestone configuration.
                </div>
                <div id="mb_milestoneHiddenInputs"></div>
                <input type="hidden" name="po_execution_date" id="po_execution_date"
                       value="<?php echo htmlspecialchars($module['po_execution_date'] ?? ''); ?>">

                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" id="btnBack3">Back</button>
                    <button type="button" class="btn btn-primary" id="btnNext3">Next: Preview Import</button>
                </div>
            </div>
        </div>

        <!-- Step 4: Preview -->
        <div class="step-content" id="step4">
            <div class="content-card">
                <h2>Step 4: Preview Import</h2>

                <!-- Logistics & Documentation Buttons for Preview -->
                <div class="inline-buttons-row">
                    <button type="button" class="logistics-inline-btn" id="logisticsBtn3" onclick="openLogisticsPanel()">
                        <span>📦</span>
                        <span>Logistics Specifications</span>
                        <span class="badge" id="logisticsBadge3" style="display: none;">Set</span>
                    </button>
                    <button type="button" class="module-docs-inline-btn" id="moduleDocsBtn3" onclick="openModuleDocsPanel()">
                        <span>📄</span>
                        <span>Module Documentation</span>
                        <span class="badge" id="moduleDocsBadge3" style="display: none;"></span>
                    </button>
                </div>

                <div class="summary-stats" id="summaryStats">
                    <!-- Populated by JavaScript -->
                </div>

                <!-- Pricing & Milestone Summary -->
                <div id="pricingMilestoneSummary" style="display: none;"></div>

                <!-- Duplicate Pallets Info Banner -->
                <div class="duplicate-info-banner" id="duplicateInfoBanner" style="display: none;">
                    <!-- Populated by JavaScript -->
                </div>

                <!-- MW Comparison Section (only shown when project has a size target) -->
                <div class="mw-comparison" id="mwComparison" style="display: none;">
                    <h4>Capacity Impact Analysis</h4>
                    <div class="mw-comparison-grid">
                        <div class="mw-box">
                            <div class="mw-box-value" id="existingMwValue">0.00 MW</div>
                            <div class="mw-box-label">Currently Added</div>
                        </div>
                        <div class="mw-operator">+</div>
                        <div class="mw-box">
                            <div class="mw-box-value" id="importMwValue">0.00 MW</div>
                            <div class="mw-box-label" id="importMwLabel">This Import</div>
                        </div>
                        <div class="mw-operator">=</div>
                        <div class="mw-box total">
                            <div class="mw-box-value" id="totalMwValue">0.00 MW</div>
                            <div class="mw-box-label">New Total</div>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 12px; padding: 8px; background: white; border-radius: 8px;">
                        <span style="color: #6c757d; font-size: 0.9rem;">Project Target: </span>
                        <strong style="color: #293E4C;" id="targetMwValue">0.00 MW</strong>
                        <span style="margin-left: 16px; color: #6c757d; font-size: 0.9rem;">After Import: </span>
                        <strong id="percentOfTarget" style="color: #28a745;">0%</strong>
                        <span style="color: #6c757d; font-size: 0.9rem;"> of target</span>
                    </div>
                </div>

                <!-- Over Capacity Warning -->
                <div class="over-capacity-warning" id="overCapacityWarning">
                    <h4><span>⚠️</span> <span id="warningTitle">Exceeds Project Capacity</span></h4>
                    <p id="warningMessage">This import will exceed the project's target capacity.</p>
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
                    <button type="button" class="btn btn-secondary" id="btnBack4">Back</button>
                    <button type="button" class="btn btn-success" id="btnConfirm">Confirm Import</button>
                </div>
            </div>
        </div>

        <!-- Step 5: Results -->
        <div class="step-content" id="step5">
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

    <!-- Logistics Panel Overlay -->
    <div class="logistics-panel-overlay" id="logisticsPanelOverlay"></div>

    <!-- Logistics Slide-out Panel -->
    <div class="logistics-panel" id="logisticsPanel">
        <div class="logistics-panel-header">
            <h3>Logistics Specifications</h3>
            <button type="button" class="logistics-panel-close" id="logisticsPanelClose">&times;</button>
        </div>
        <div class="logistics-panel-body">
            <p style="margin: 0 0 20px 0; color: #6c757d; font-size: 13px;">
                These specifications are used for freight planning, warehouse handling, and site receiving. Edit
                values here or in Step 1; they apply to the imported module batch.
            </p>

            <!-- Truck & Pallet Info -->
            <div class="logistics-panel-section">
                <h5>Truck & Pallet Info</h5>
                <div class="logistics-panel-grid">
                    <div class="logistics-panel-field">
                        <label>Modules per Pallet</label>
                        <input type="number" id="panel_modules_per_pallet" readonly placeholder="From file">
                    </div>
                    <div class="logistics-panel-field">
                        <label>Pallets per Truck</label>
                        <input type="number" id="panel_pallets_per_truck" placeholder="e.g., 24">
                    </div>
                    <div class="logistics-panel-field">
                        <label>Modules per Truck</label>
                        <input type="number" id="panel_modules_per_truck" readonly placeholder="Auto">
                    </div>
                    <div class="logistics-panel-field">
                        <label>Trucks Needed</label>
                        <input type="number" id="panel_trucks_needed" readonly placeholder="Auto">
                    </div>
                </div>
            </div>

            <!-- Pallet Dimensions -->
            <div class="logistics-panel-section">
                <h5>Pallet Dimensions</h5>
                <div class="logistics-panel-grid">
                    <div class="logistics-panel-field">
                        <label>Length (mm)</label>
                        <input type="number" id="panel_pallet_length_mm" placeholder="e.g., 2384">
                    </div>
                    <div class="logistics-panel-field">
                        <label>Depth (mm)</label>
                        <input type="number" id="panel_pallet_depth_mm" placeholder="e.g., 1303">
                    </div>
                    <div class="logistics-panel-field">
                        <label>Stack Height (mm)</label>
                        <input type="number" id="panel_pallet_double_stacked_height_mm" placeholder="e.g., 2200">
                    </div>
                    <div class="logistics-panel-field">
                        <label>Weight (kg)</label>
                        <input type="number" id="panel_pallet_total_weight_kg" placeholder="e.g., 1200">
                    </div>
                </div>
            </div>

            <!-- Handling Requirements -->
            <div class="logistics-panel-section">
                <h5>Handling Requirements</h5>
                <div class="logistics-panel-grid">
                    <div class="logistics-panel-field">
                        <label>Forklift Long Side (mm)</label>
                        <input type="number" id="panel_forklift_truck_long_side_mm" placeholder="e.g., 1200">
                    </div>
                    <div class="logistics-panel-field">
                        <label>Forklift Short Side (mm)</label>
                        <input type="number" id="panel_forklift_truck_short_side_mm" placeholder="e.g., 1000">
                    </div>
                    <div class="logistics-panel-field">
                        <label>Pallet Jack Long (mm)</label>
                        <input type="number" id="panel_pallet_jack_long_side_mm" placeholder="e.g., 1150">
                    </div>
                    <div class="logistics-panel-field">
                        <label>Pallet Jack Short (mm)</label>
                        <input type="number" id="panel_pallet_jack_short_side_mm" placeholder="e.g., 800">
                    </div>
                </div>
            </div>

            <!-- Stacking Instructions -->
            <div class="logistics-panel-section">
                <h5>Stacking Instructions</h5>
                <div class="logistics-panel-grid">
                    <div class="logistics-panel-field full-width">
                        <label>Warehouse Stacking</label>
                        <textarea id="panel_stacking_in_warehouse" rows="2" placeholder="Instructions..."></textarea>
                    </div>
                    <div class="logistics-panel-field full-width">
                        <label>Transport Stacking</label>
                        <textarea id="panel_stacking_during_transport" rows="2" placeholder="Instructions..."></textarea>
                    </div>
                    <div class="logistics-panel-field full-width">
                        <label>Module Notes</label>
                        <textarea id="panel_module_notes" rows="2" placeholder="General notes..."></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Module Documentation Panel Overlay -->
    <div class="logistics-panel-overlay" id="moduleDocsPanelOverlay"></div>

    <!-- Module Documentation Slide-out Panel -->
    <div class="logistics-panel" id="moduleDocsPanel" style="border-left: 4px solid #6f42c1;">
        <div class="logistics-panel-header" style="border-bottom: 2px solid #6f42c1;">
            <h3 style="color: #6f42c1;"><span style="margin-right: 8px;">📄</span>Module Documentation</h3>
            <button type="button" class="logistics-panel-close" id="moduleDocsPanelClose">&times;</button>
        </div>
        <div class="logistics-panel-body">
            <p style="margin: 0 0 20px 0; color: #6c757d; font-size: 13px;">
                Attach documentation files (spec sheets, flash test data, invoices) to this module batch.
                Files will be saved to the project's document library.
            </p>

            <!-- Document Type Selection -->
            <div class="logistics-panel-section">
                <h5>Document Details</h5>
                <div class="logistics-panel-field full-width" style="margin-bottom: 16px;">
                    <label>Document Sub-Type</label>
                    <select id="module_docs_sub_type" name="module_docs_sub_type" style="width: 100%; padding: 10px; border: 1px solid #dee2e6; border-radius: 6px; font-size: 13px;">
                        <option value="">Choose sub-type...</option>
                        <option value="Module Invoice">Module Invoice</option>
                        <option value="Flash Test Data">Flash Test Data</option>
                        <option value="Spec Sheets">Spec Sheets</option>
                    </select>
                </div>
                <div class="logistics-panel-field full-width" style="margin-bottom: 16px;">
                    <label>Description (Optional)</label>
                    <input type="text" id="module_docs_description" name="module_docs_description" placeholder="Brief description of documents" style="width: 100%; padding: 10px; border: 1px solid #dee2e6; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                </div>
            </div>

            <!-- File Upload Area -->
            <div class="logistics-panel-section">
                <h5>Select Files</h5>
                <div id="moduleDocsDropArea" style="border: 2px dashed #6f42c1; border-radius: 12px; padding: 30px 20px; text-align: center; cursor: pointer; background: linear-gradient(135deg, #f8f5fc 0%, #ffffff 100%); transition: all 0.3s ease;">
                    <div style="font-size: 36px; color: #6f42c1; margin-bottom: 12px;">📎</div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: #293E4C; margin-bottom: 6px;">Drop files here or click to browse</div>
                    <div style="font-size: 0.8rem; color: #6c757d;">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, CSV</div>
                </div>
                <input type="file" id="module_docs_files" name="module_docs[]" multiple style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.csv">

                <!-- Selected Files List -->
                <div id="moduleDocsFileList" style="margin-top: 16px;"></div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 12px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e9ecef;">
                <button type="button" id="moduleDocsClearBtn" style="flex: 1; padding: 12px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; cursor: pointer; font-size: 14px; color: #6c757d;">
                    Clear All
                </button>
                <button type="button" id="moduleDocsDoneBtn" style="flex: 2; padding: 12px; background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); border: none; border-radius: 8px; cursor: pointer; font-size: 14px; color: white; font-weight: 600;">
                    Done
                </button>
            </div>
        </div>
    </div>
</main>

<?php
$existingWattages = [];
$existingMilestones = [];
$prefLocationId = 0;
include 'components/module_batch_scripts.php';
?>

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
    let overCapacityConfirmed = false;

    // Project capacity data from PHP
    const projectData = {
        projectSizeMw: <?php echo json_encode($project_size_mw); ?>,
        existingModules: <?php echo json_encode($existing_modules); ?>,
        existingMw: <?php echo json_encode($existing_mw); ?>,
        projectName: <?php echo json_encode($project ? $project['project_name'] : ''); ?>
    };

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
            required: false, // Allow manual entry if not in file
            allowManualEntry: true,
            manualEntryLabel: 'Default quantity for all pallets',
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
    const btnNext3 = document.getElementById('btnNext3');
    const btnBack3 = document.getElementById('btnBack3');
    const btnBack4 = document.getElementById('btnBack4');
    const btnConfirm = document.getElementById('btnConfirm');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const loadingText = document.getElementById('loadingText');
    const trackDomesticCheckbox = document.getElementById('track_domestic_content');
    const domesticContentPctGroup = document.getElementById('domesticContentPctGroup');
    const domesticContentPctInput = document.getElementById('domestic_content_pct');

    function toggleDomesticContentInput() {
        const enabled = !!(trackDomesticCheckbox && trackDomesticCheckbox.checked);
        if (domesticContentPctGroup) {
            domesticContentPctGroup.style.display = enabled ? '' : 'none';
        }
        if (domesticContentPctInput) {
            domesticContentPctInput.required = enabled;
        }
        const hint = document.getElementById('domesticContentDisabledHint');
        if (hint) hint.style.display = enabled ? 'none' : '';
    }
    if (trackDomesticCheckbox) {
        trackDomesticCheckbox.addEventListener('change', toggleDomesticContentInput);
        toggleDomesticContentInput();
    }

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

    // Store location data for address display
    let locationDataCache = {};

    // Manufacturer change - load locations
    document.getElementById('manufacturer_id').addEventListener('change', function() {
        const mfgId = this.value;
        const locationSelect = document.getElementById('manufacturer_location_id');
        const addressDisplay = document.getElementById('locationAddressDisplay');
        const addressText = document.getElementById('locationAddressText');

        // Reset address display
        addressDisplay.style.display = 'none';
        addressText.textContent = '';
        locationDataCache = {};

        if (mfgId) {
            locationSelect.disabled = true;
            locationSelect.innerHTML = '<option value="">Loading...</option>';
            fetch(`get_manufacturer_locations.php?manufacturer_id=${mfgId}`)
                .then(r => r.json())
                .then(data => {
                    locationSelect.innerHTML = '<option value="">Select Location</option>';
                    if (data.locations && data.locations.length > 0) {
                        data.locations.forEach(loc => {
                            // Store location data for address display
                            locationDataCache[loc.id] = loc.formatted_address || loc.city || '';
                            // Show location name with address in dropdown
                            const displayText = loc.location_name
                                ? `${loc.location_name} — ${loc.formatted_address || loc.city}`
                                : (loc.formatted_address || loc.city);
                            locationSelect.innerHTML += `<option value="${loc.id}">${displayText}</option>`;
                        });
                        locationSelect.disabled = false;
                    } else {
                        locationSelect.innerHTML = '<option value="">No locations available</option>';
                        locationSelect.disabled = true;
                    }
                    updateStep1Validation();
                })
                .catch(() => {
                    locationSelect.innerHTML = '<option value="">Error loading locations</option>';
                    locationSelect.disabled = true;
                    updateStep1Validation();
                });
        } else {
            locationSelect.innerHTML = '<option value="">Select a manufacturer first</option>';
            locationSelect.disabled = true;
        }
        updateStep1Validation();
    });

    // Location change - show address display
    document.getElementById('manufacturer_location_id').addEventListener('change', function() {
        const addressDisplay = document.getElementById('locationAddressDisplay');
        const addressText = document.getElementById('locationAddressText');

        if (this.value && locationDataCache[this.value]) {
            addressText.textContent = locationDataCache[this.value];
            addressDisplay.style.display = 'flex';
        } else {
            addressDisplay.style.display = 'none';
            addressText.textContent = '';
        }
        updateStep1Validation();
    });

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

    // Clickable completed step indicators
    document.querySelectorAll('.step').forEach(s => {
        s.addEventListener('click', () => {
            const stepNum = parseInt(s.dataset.step);
            if (stepNum < currentStep) {
                goToStep(stepNum);
            }
        });
    });

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

            // Add manual entry option for fields that support it
            if (fieldConfig.allowManualEntry) {
                const manualDiv = document.createElement('div');
                manualDiv.id = 'manual_' + fieldKey + '_container';
                manualDiv.style.marginTop = '10px';
                manualDiv.style.display = 'none';
                manualDiv.innerHTML = `
                    <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 10px;">
                        <label style="display: block; font-size: 0.85rem; color: #856404; margin-bottom: 6px;">
                            ${fieldConfig.manualEntryLabel || 'Enter value manually'}:
                        </label>
                        <input type="number" id="manual_${fieldKey}" name="manual_${fieldKey}"
                               placeholder="e.g., 30" min="1"
                               style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                `;
                selectCell.appendChild(manualDiv);

                // Show/hide manual entry based on column selection
                select.addEventListener('change', function() {
                    manualDiv.style.display = this.value ? 'none' : 'block';
                });

                // Initially show if not mapped
                if (!mappingsToUse || !mappingsToUse[fieldKey]) {
                    manualDiv.style.display = 'block';
                }
            }

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

            // Check for manual entry if field supports it and isn't mapped
            if (fieldConfig.allowManualEntry && !select.value) {
                const manualInput = document.getElementById('manual_' + fieldKey);
                if (manualInput && manualInput.value) {
                    columnMapping['manual_' + fieldKey] = manualInput.value;
                } else {
                    missingRequired.push(fieldConfig.label + ' (map column or enter default value)');
                }
            }
        }

        if (missingRequired.length > 0) {
            alert('Please provide the following:\n\n' + missingRequired.join('\n'));
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

            // Go to Pricing & Milestones step
            goToStep(3);

        } catch (err) {
            hideLoading();
            alert('Error parsing data: ' + err.message);
        }
    });

    function buildPreview() {
        // Summary stats
        const statsDiv = document.getElementById('summaryStats');
        const hasExisting = summary.pallets_existing && summary.pallets_existing > 0;

        statsDiv.innerHTML = `
            <div class="stat-card">
                <div class="stat-value">${summary.total_pallets || 0}</div>
                <div class="stat-label">Total Pallets</div>
            </div>
            ${hasExisting ? `
            <div class="stat-card" style="border: 2px solid #17a2b8;">
                <div class="stat-value" style="color: #17a2b8;">${summary.pallets_new || 0}</div>
                <div class="stat-label">New Pallets</div>
            </div>
            <div class="stat-card" style="border: 2px solid #ffc107;">
                <div class="stat-value" style="color: #856404;">${summary.pallets_existing || 0}</div>
                <div class="stat-label">Will Update</div>
            </div>
            ` : ''}
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

        // Pricing & Milestone Summary
        const pricingSummaryDiv = document.getElementById('pricingMilestoneSummary');
        if (pricingSummaryDiv) {
            const cpw = document.getElementById('cost_per_watt')?.value || '';
            const hasCpw = cpw && parseFloat(cpw) > 0;
            const dcChecked = document.getElementById('track_domestic_content')?.checked;
            const dcPct = document.getElementById('domestic_content_pct')?.value || '';

            // Build milestone rows
            let milestoneHtml = '';
            if (typeof mb_milestones !== 'undefined' && mb_milestones.length > 0 && hasCpw) {
                const items = mb_milestones
                    .filter(m => m.trigger_event && parseFloat(m.percentage) > 0)
                    .map(m => {
                        const label = (typeof mb_triggerEventLabels !== 'undefined' && mb_triggerEventLabels[m.trigger_event])
                            ? mb_triggerEventLabels[m.trigger_event]
                            : m.trigger_event;
                        return `<div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; background: #f0f7f8; border-radius: 6px; margin-bottom: 6px;"><span style="color: #293E4C; font-size: 0.85rem;">${label}</span><span style="font-weight: 600; color: #488C9A; font-size: 0.85rem; background: white; padding: 2px 8px; border-radius: 4px;">${parseFloat(m.percentage).toFixed(1)}%</span></div>`;
                    }).join('');
                if (items) {
                    milestoneHtml = items;
                }
            }
            if (!milestoneHtml) {
                milestoneHtml = '<div style="color: #6c757d; font-size: 0.9rem;">Default: 100% Project Delivery</div>';
            }

            // Domestic content display
            let dcHtml = '<span style="color: #6c757d;">Not tracked</span>';
            if (dcChecked && dcPct) {
                dcHtml = `<span style="font-weight: 600; color: #488C9A;">${parseFloat(dcPct).toFixed(2)}%</span>`;
            }

            pricingSummaryDiv.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 16px; margin-bottom: 24px;">
                    <div style="background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border: 1px solid #e9ecef; border-radius: 12px; padding: 20px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center;">
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: #6c757d; letter-spacing: 0.5px; margin-bottom: 10px;">Cost Per Watt</div>
                        <div style="font-size: 1.4rem; font-weight: 700; color: ${hasCpw ? '#488C9A' : '#6c757d'};">${hasCpw ? '$' + parseFloat(cpw).toFixed(4) + '/W' : 'Not set'}</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border: 1px solid #e9ecef; border-radius: 12px; padding: 20px;">
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: #6c757d; letter-spacing: 0.5px; margin-bottom: 10px;">Payment Milestones</div>
                        ${milestoneHtml}
                    </div>
                    <div style="background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border: 1px solid #e9ecef; border-radius: 12px; padding: 20px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center;">
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: #6c757d; letter-spacing: 0.5px; margin-bottom: 10px;">Domestic Content</div>
                        <div style="font-size: 1.4rem; font-weight: 700;">${dcHtml}</div>
                    </div>
                </div>
            `;
            pricingSummaryDiv.style.display = 'block';
        }

        // Show duplicate info banner if there are existing pallets
        const duplicateInfoDiv = document.getElementById('duplicateInfoBanner');
        if (hasExisting && duplicateInfoDiv) {
            const wattageChanges = summary.wattage_changes || 0;
            const quantityChanges = summary.quantity_changes || 0;
            const mwDiff = summary.mw_difference_from_updates || 0;

            let changesHtml = '';
            if (wattageChanges > 0 || quantityChanges > 0) {
                changesHtml = '<div style="margin-top: 12px; padding: 12px; background: #fff; border-radius: 8px; border-left: 4px solid #ffc107;">';
                changesHtml += '<strong style="color: #856404;">Changes Detected in Updates:</strong><ul style="margin: 8px 0 0 0; padding-left: 20px; color: #856404;">';
                if (wattageChanges > 0) {
                    changesHtml += `<li><strong>${wattageChanges}</strong> pallet(s) have different wattage values</li>`;
                }
                if (quantityChanges > 0) {
                    changesHtml += `<li><strong>${quantityChanges}</strong> pallet(s) have different quantity values</li>`;
                }
                if (mwDiff !== 0) {
                    const mwDiffStr = mwDiff > 0 ? `+${mwDiff.toFixed(2)}` : mwDiff.toFixed(2);
                    const mwColor = mwDiff > 0 ? '#28a745' : '#dc3545';
                    changesHtml += `<li>Net MW change from updates: <strong style="color: ${mwColor};">${mwDiffStr} MW</strong></li>`;
                }
                changesHtml += '</ul></div>';
            }

            duplicateInfoDiv.innerHTML = `
                <h4><span>🔄</span> Duplicate Pallets Detected</h4>
                <p><strong>${summary.pallets_existing}</strong> pallet(s) already exist in this project and will be <strong>updated</strong> with the new data from your file.</p>
                <p><strong>${summary.pallets_new}</strong> new pallet(s) will be added.</p>
                ${changesHtml}
                <p style="font-size: 0.85rem; margin-top: 12px; opacity: 0.8;">Existing pallets are highlighted in <span style="background: #fff3cd; padding: 2px 6px; border-radius: 4px;">yellow</span> in the preview below.</p>
            `;
            duplicateInfoDiv.style.display = 'block';
        } else if (duplicateInfoDiv) {
            duplicateInfoDiv.style.display = 'none';
        }

        // MW Comparison (only if project has a target size)
        const mwComparisonDiv = document.getElementById('mwComparison');
        const overCapacityWarning = document.getElementById('overCapacityWarning');

        if (projectData.projectSizeMw > 0 && projectId()) {
            // Calculate MW being imported - count NEW pallets plus changes from updates
            let newPalletsMw = 0;
            const existingPalletIds = summary.existing_pallet_ids || [];
            const existingPalletSet = new Set(existingPalletIds);

            parsedData.forEach(row => {
                // Only count MW for pallets that don't already exist
                if (!existingPalletSet.has(row.pallet_id)) {
                    const wattage = parseFloat(row.wattage) || 0;
                    const quantity = parseInt(row.quantity) || 0;
                    newPalletsMw += (wattage * quantity) / 1000000;
                }
            });

            // Add the MW difference from updates (can be positive or negative)
            const mwDiffFromUpdates = summary.mw_difference_from_updates || 0;
            const totalImportMw = newPalletsMw + mwDiffFromUpdates;

            const existingMw = projectData.existingMw || 0;
            const totalMw = existingMw + totalImportMw;
            const targetMw = projectData.projectSizeMw;
            const percentOfTarget = targetMw > 0 ? (totalMw / targetMw) * 100 : 0;

            // Update MW comparison display
            document.getElementById('existingMwValue').textContent = existingMw.toFixed(2) + ' MW';

            // Format import MW with +/- sign and update label based on what's happening
            const importMwEl = document.getElementById('importMwValue');
            const importLabelEl = document.getElementById('importMwLabel');

            if (totalImportMw === 0) {
                importMwEl.textContent = '0.00 MW';
                importLabelEl.textContent = 'Net Change';
            } else if (totalImportMw > 0) {
                importMwEl.textContent = '+' + totalImportMw.toFixed(2) + ' MW';
                importMwEl.style.color = '#28a745';
                importLabelEl.textContent = newPalletsMw > 0 && mwDiffFromUpdates !== 0 ? 'New + Updates' :
                                           newPalletsMw > 0 ? 'New Pallets' : 'From Updates';
            } else {
                importMwEl.textContent = totalImportMw.toFixed(2) + ' MW';
                importMwEl.style.color = '#dc3545';
                importLabelEl.textContent = 'From Updates';
            }

            document.getElementById('totalMwValue').textContent = totalMw.toFixed(2) + ' MW';
            document.getElementById('targetMwValue').textContent = targetMw.toFixed(2) + ' MW';

            const percentEl = document.getElementById('percentOfTarget');
            percentEl.textContent = percentOfTarget.toFixed(1) + '%';

            // Color code the percentage
            if (percentOfTarget > 100) {
                percentEl.style.color = '#dc3545'; // Red for over
            } else if (percentOfTarget >= 90) {
                percentEl.style.color = '#28a745'; // Green for near completion
            } else {
                percentEl.style.color = '#488C9A'; // Default blue
            }

            mwComparisonDiv.style.display = 'block';

            // Show over-capacity warning if needed
            if (totalMw > targetMw) {
                const excessMw = totalMw - targetMw;
                const excessPercent = ((totalMw / targetMw) - 1) * 100;

                document.getElementById('warningTitle').textContent = 'Exceeds Project Capacity';
                document.getElementById('warningMessage').textContent =
                    `This import will bring the project to ${totalMw.toFixed(2)} MW, which is ${excessMw.toFixed(2)} MW (${excessPercent.toFixed(1)}%) over the target of ${targetMw.toFixed(2)} MW. You will be asked to confirm before proceeding.`;

                overCapacityWarning.classList.add('show');
                if (excessPercent > 10) {
                    overCapacityWarning.classList.add('critical');
                } else {
                    overCapacityWarning.classList.remove('critical');
                }
            } else {
                overCapacityWarning.classList.remove('show', 'critical');
            }
        } else {
            mwComparisonDiv.style.display = 'none';
            overCapacityWarning.classList.remove('show', 'critical');
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

        // Get list of existing pallet IDs
        const existingPalletIds = summary.existing_pallet_ids || [];
        const existingPalletSet = new Set(existingPalletIds);

        // Headers - add Status column if there are duplicates
        const headerCols = hasExisting
            ? ['Status', 'Pallet ID', 'Wattage', 'Quantity']
            : ['Pallet ID', 'Wattage', 'Quantity'];
        thead.innerHTML = headerCols.map(h => `<th>${h}</th>`).join('');

        // Rows (first 20)
        tbody.innerHTML = parsedData.slice(0, 20).map(row => {
            const isExisting = existingPalletSet.has(row.pallet_id);
            const rowClass = isExisting ? 'existing-pallet' : '';
            const statusBadge = isExisting
                ? '<span class="pallet-status-badge update">Update</span>'
                : '<span class="pallet-status-badge new">New</span>';

            if (hasExisting) {
                return `
                    <tr class="${rowClass}">
                        <td>${statusBadge}</td>
                        <td>${row.pallet_id || '-'}</td>
                        <td>${row.wattage || '-'}W</td>
                        <td>${row.quantity || '-'}</td>
                    </tr>
                `;
            } else {
                return `
                    <tr>
                        <td>${row.pallet_id || '-'}</td>
                        <td>${row.wattage || '-'}W</td>
                        <td>${row.quantity || '-'}</td>
                    </tr>
                `;
            }
        }).join('');

        const colSpan = hasExisting ? 4 : 3;
        if (parsedData.length > 20) {
            tbody.innerHTML += `<tr><td colspan="${colSpan}" style="text-align:center; color:#6c757d;">...and ${parsedData.length - 20} more rows</td></tr>`;
        }
    }

    // Step 3 (Pricing & Milestones) -> Step 2
    btnBack3.addEventListener('click', () => goToStep(2));

    // Step 3 -> Step 4 (Preview)
    btnNext3.addEventListener('click', () => {
        // Validate milestones if cost_per_watt is set
        const costInput = document.getElementById('cost_per_watt');
        const hasCost = costInput && costInput.value && parseFloat(costInput.value) > 0;
        if (hasCost && typeof mb_milestones !== 'undefined' && mb_milestones.length > 0) {
            if (typeof mb_validateMilestoneTotal === 'function' && !mb_validateMilestoneTotal()) {
                return;
            }
            if (typeof mb_validatePoExecutionDate === 'function' && !mb_validatePoExecutionDate()) {
                return;
            }
        }

        buildPreview();
        goToStep(4);
    });

    // Step 4 (Preview) -> Step 3
    btnBack4.addEventListener('click', () => goToStep(3));

    // Step 4 -> Confirm Import
    btnConfirm.addEventListener('click', async () => {
        // Check if over capacity and require explicit confirmation
        // Use same calculation as preview: only count NEW pallets + MW diff from updates
        let newPalletsMw = 0;
        const existingPalletIds = summary.existing_pallet_ids || [];
        const existingPalletSet = new Set(existingPalletIds);

        parsedData.forEach(row => {
            // Only count MW for pallets that don't already exist
            if (!existingPalletSet.has(row.pallet_id)) {
                const wattage = parseFloat(row.wattage) || 0;
                const quantity = parseInt(row.quantity) || 0;
                newPalletsMw += (wattage * quantity) / 1000000;
            }
        });

        // Add the MW difference from updates (can be positive or negative)
        const mwDiffFromUpdates = summary.mw_difference_from_updates || 0;
        const totalImportMw = newPalletsMw + mwDiffFromUpdates;

        const existingMw = projectData.existingMw || 0;
        const totalMw = existingMw + totalImportMw;
        const targetMw = projectData.projectSizeMw || 0;

        if (targetMw > 0 && totalMw > targetMw && projectId()) {
            const excessMw = totalMw - targetMw;
            const excessPercent = ((totalMw / targetMw) - 1) * 100;

            const importLabel = totalImportMw >= 0 ? `+${totalImportMw.toFixed(2)}` : totalImportMw.toFixed(2);
            const confirmMsg = `WARNING: This import will exceed the project capacity!\n\n` +
                `Current: ${existingMw.toFixed(2)} MW\n` +
                `Import: ${importLabel} MW\n` +
                `New Total: ${totalMw.toFixed(2)} MW\n` +
                `Target: ${targetMw.toFixed(2)} MW\n\n` +
                `This is ${excessMw.toFixed(2)} MW (${excessPercent.toFixed(1)}%) over the target.\n\n` +
                `Are you sure you want to proceed?`;

            if (!confirm(confirmMsg)) {
                return;
            }
        } else {
            // Build a more informative confirmation message
            const palletsNew = summary.pallets_new || 0;
            const palletsExisting = summary.pallets_existing || 0;
            let confirmMsg = 'Are you sure you want to import these pallets?\n\n';
            if (palletsNew > 0) confirmMsg += `• ${palletsNew} new pallet(s) will be added\n`;
            if (palletsExisting > 0) confirmMsg += `• ${palletsExisting} existing pallet(s) will be updated\n`;
            if (totalImportMw !== 0) {
                const mwLabel = totalImportMw >= 0 ? `+${totalImportMw.toFixed(2)}` : totalImportMw.toFixed(2);
                confirmMsg += `• Net MW change: ${mwLabel} MW`;
            }

            if (!confirm(confirmMsg)) {
                return;
            }
        }

        showLoading('Importing pallets...');

        const formData = new FormData();
        formData.append('pallet_file', uploadedFile);
        formData.append('action', 'import');
        formData.append('manufacturer_id', manufacturerId());
        formData.append('manufacturer_location_id', manufacturerLocationId());
        formData.append('project_id', projectId());
        formData.append('account_id', accountId());
        formData.append('batch_name', document.getElementById('batch_name')?.value || '');
        formData.append('column_mapping', JSON.stringify(columnMapping));
        formData.append('save_mapping', document.getElementById('saveMappingCheckbox').checked ? '1' : '0');
        formData.append('track_domestic_content', trackDomesticCheckbox && trackDomesticCheckbox.checked ? '1' : '0');
        if (trackDomesticCheckbox && trackDomesticCheckbox.checked && domesticContentPctInput && domesticContentPctInput.value) {
            formData.append('domestic_content_pct', domesticContentPctInput.value);
        }

        // Add all logistics specifications
        const logisticsFields = [
            'modules_per_pallet', 'pallets_per_truck', 'modules_per_truck', 'trucks_needed',
            'pallet_length_mm', 'pallet_depth_mm', 'pallet_double_stacked_height_mm', 'pallet_total_weight_kg',
            'forklift_truck_long_side_mm', 'forklift_truck_short_side_mm',
            'pallet_jack_long_side_mm', 'pallet_jack_short_side_mm',
            'stacking_in_warehouse', 'stacking_during_transport', 'module_notes', 'cost_per_watt'
        ];
        logisticsFields.forEach(field => {
            const el = document.getElementById(field);
            if (el && el.value) {
                formData.append(field, el.value);
            }
        });

        // Add PO execution date
        const poDateInput = document.getElementById('po_execution_date');
        if (poDateInput && poDateInput.value) {
            formData.append('po_execution_date', poDateInput.value);
        }

        // Add milestone hidden inputs
        if (typeof mb_milestones !== 'undefined' && mb_milestones.length > 0) {
            mb_milestones.forEach((m, index) => {
                if (m.trigger_event && parseFloat(m.percentage) > 0) {
                    formData.append('milestones[' + index + '][trigger_event]', m.trigger_event);
                    formData.append('milestones[' + index + '][percentage]', m.percentage);
                }
            });
        }

        // Add module documentation files if any
        const moduleDocsFiles = window.getModuleDocsFiles ? window.getModuleDocsFiles() : [];
        if (moduleDocsFiles.length > 0) {
            moduleDocsFiles.forEach((file, index) => {
                formData.append('module_docs[]', file);
            });
            formData.append('module_docs_sub_type', window.getModuleDocsSubType ? window.getModuleDocsSubType() : '');
            formData.append('module_docs_description', window.getModuleDocsDescription ? window.getModuleDocsDescription() : '');
        }

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
            goToStep(5);

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

            const hasUpdates = result.pallets_updated && result.pallets_updated > 0;

            container.innerHTML = `
                <div class="results-icon success">✓</div>
                <h2>Import Complete!</h2>
                <div class="summary-stats">
                    <div class="stat-card">
                        <div class="stat-value">${result.pallets_created}</div>
                        <div class="stat-label">New Pallets Created</div>
                    </div>
                    ${hasUpdates ? `
                    <div class="stat-card" style="border: 2px solid #ffc107;">
                        <div class="stat-value" style="color: #856404;">${result.pallets_updated}</div>
                        <div class="stat-label">Pallets Updated</div>
                    </div>
                    ` : ''}
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

    // ========== Logistics Panel Sync ==========
    const logisticsPanel = document.getElementById('logisticsPanel');
    const logisticsPanelOverlay = document.getElementById('logisticsPanelOverlay');
    const logisticsPanelClose = document.getElementById('logisticsPanelClose');
    const logisticsBtn1 = document.getElementById('logisticsBtn1');
    const logisticsBtn2 = document.getElementById('logisticsBtn2');
    const logisticsBtn3 = document.getElementById('logisticsBtn3');
    const logisticsBadge1 = document.getElementById('logisticsBadge1');
    const logisticsBadge2 = document.getElementById('logisticsBadge2');
    const logisticsBadge3 = document.getElementById('logisticsBadge3');

    // Field mappings between Step 1 form and panel
    const logisticsFieldMappings = [
        { form: 'modules_per_pallet', panel: 'panel_modules_per_pallet' },
        { form: 'pallets_per_truck', panel: 'panel_pallets_per_truck' },
        { form: 'modules_per_truck', panel: 'panel_modules_per_truck' },
        { form: 'trucks_needed', panel: 'panel_trucks_needed' },
        { form: 'pallet_length_mm', panel: 'panel_pallet_length_mm' },
        { form: 'pallet_depth_mm', panel: 'panel_pallet_depth_mm' },
        { form: 'pallet_double_stacked_height_mm', panel: 'panel_pallet_double_stacked_height_mm' },
        { form: 'pallet_total_weight_kg', panel: 'panel_pallet_total_weight_kg' },
        { form: 'forklift_truck_long_side_mm', panel: 'panel_forklift_truck_long_side_mm' },
        { form: 'forklift_truck_short_side_mm', panel: 'panel_forklift_truck_short_side_mm' },
        { form: 'pallet_jack_long_side_mm', panel: 'panel_pallet_jack_long_side_mm' },
        { form: 'pallet_jack_short_side_mm', panel: 'panel_pallet_jack_short_side_mm' },
        { form: 'stacking_in_warehouse', panel: 'panel_stacking_in_warehouse' },
        { form: 'stacking_during_transport', panel: 'panel_stacking_during_transport' },
        { form: 'module_notes', panel: 'panel_module_notes' }
    ];

    // Sync from Step 1 form to panel
    function syncFormToPanel() {
        logisticsFieldMappings.forEach(mapping => {
            const formField = document.getElementById(mapping.form);
            const panelField = document.getElementById(mapping.panel);
            if (formField && panelField) {
                panelField.value = formField.value;
            }
        });
        updateLogisticsButtonStatus();
    }

    // Sync from panel to Step 1 form
    function syncPanelToForm() {
        logisticsFieldMappings.forEach(mapping => {
            const formField = document.getElementById(mapping.form);
            const panelField = document.getElementById(mapping.panel);
            if (formField && panelField && !formField.readOnly) {
                formField.value = panelField.value;
            }
        });
        updateLogisticsButtonStatus();
    }

    // Update inline button appearance based on whether data exists
    function updateLogisticsButtonStatus() {
        let hasData = false;
        logisticsFieldMappings.forEach(mapping => {
            const formField = document.getElementById(mapping.form);
            if (formField && formField.value && formField.value.trim() !== '') {
                hasData = true;
            }
        });

        // Update Step 1 button
        if (logisticsBtn1) {
            if (hasData) {
                logisticsBtn1.classList.add('has-data');
                if (logisticsBadge1) logisticsBadge1.style.display = 'inline';
            } else {
                logisticsBtn1.classList.remove('has-data');
                if (logisticsBadge1) logisticsBadge1.style.display = 'none';
            }
        }

        // Update Step 2 button
        if (logisticsBtn2) {
            if (hasData) {
                logisticsBtn2.classList.add('has-data');
                if (logisticsBadge2) logisticsBadge2.style.display = 'inline';
            } else {
                logisticsBtn2.classList.remove('has-data');
                if (logisticsBadge2) logisticsBadge2.style.display = 'none';
            }
        }

        // Update Step 3 button
        if (logisticsBtn3) {
            if (hasData) {
                logisticsBtn3.classList.add('has-data');
                if (logisticsBadge3) logisticsBadge3.style.display = 'inline';
            } else {
                logisticsBtn3.classList.remove('has-data');
                if (logisticsBadge3) logisticsBadge3.style.display = 'none';
            }
        }
    }

    // Open panel - made global so onclick can access it
    window.openLogisticsPanel = function() {
        syncFormToPanel();
        logisticsPanel.classList.add('open');
        logisticsPanelOverlay.classList.add('open');
    };

    // Close panel
    function closeLogisticsPanel() {
        syncPanelToForm();
        logisticsPanel.classList.remove('open');
        logisticsPanelOverlay.classList.remove('open');
    }

    // Event listeners
    if (logisticsPanelClose) {
        logisticsPanelClose.addEventListener('click', closeLogisticsPanel);
    }
    if (logisticsPanelOverlay) {
        logisticsPanelOverlay.addEventListener('click', closeLogisticsPanel);
    }

    // Sync panel changes to form in real-time
    logisticsFieldMappings.forEach(mapping => {
        const panelField = document.getElementById(mapping.panel);
        if (panelField && !panelField.readOnly) {
            panelField.addEventListener('input', function() {
                const formField = document.getElementById(mapping.form);
                if (formField && !formField.readOnly) {
                    formField.value = this.value;
                    // Trigger any calculations
                    formField.dispatchEvent(new Event('input', { bubbles: true }));
                }
                updateLogisticsButtonStatus();
            });
        }
    });

    // Also sync form changes to panel and update button status
    logisticsFieldMappings.forEach(mapping => {
        const formField = document.getElementById(mapping.form);
        if (formField) {
            formField.addEventListener('input', function() {
                const panelField = document.getElementById(mapping.panel);
                if (panelField) {
                    panelField.value = this.value;
                }
                updateLogisticsButtonStatus();
            });
        }
    });

    // Initial update of button status
    updateLogisticsButtonStatus();

    // ========== Module Documentation Panel ==========
    const moduleDocsPanel = document.getElementById('moduleDocsPanel');
    const moduleDocsPanelOverlay = document.getElementById('moduleDocsPanelOverlay');
    const moduleDocsPanelClose = document.getElementById('moduleDocsPanelClose');
    const moduleDocsDropArea = document.getElementById('moduleDocsDropArea');
    const moduleDocsFileInput = document.getElementById('module_docs_files');
    const moduleDocsFileList = document.getElementById('moduleDocsFileList');
    const moduleDocsClearBtn = document.getElementById('moduleDocsClearBtn');
    const moduleDocsDoneBtn = document.getElementById('moduleDocsDoneBtn');

    // Track selected files
    let moduleDocsSelectedFiles = [];

    // Open module docs panel - made global so onclick can access it
    window.openModuleDocsPanel = function() {
        moduleDocsPanel.classList.add('open');
        moduleDocsPanelOverlay.classList.add('open');
        updateModuleDocsFileList();
    };

    // Close module docs panel
    function closeModuleDocsPanel() {
        moduleDocsPanel.classList.remove('open');
        moduleDocsPanelOverlay.classList.remove('open');
        updateModuleDocsButtonStatus();
    }

    // Handle file drop area click
    if (moduleDocsDropArea) {
        moduleDocsDropArea.addEventListener('click', () => moduleDocsFileInput.click());

        moduleDocsDropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            moduleDocsDropArea.style.borderColor = '#5a32a3';
            moduleDocsDropArea.style.background = '#e2d9f3';
        });

        moduleDocsDropArea.addEventListener('dragleave', () => {
            moduleDocsDropArea.style.borderColor = '#6f42c1';
            moduleDocsDropArea.style.background = 'linear-gradient(135deg, #f8f5fc 0%, #ffffff 100%)';
        });

        moduleDocsDropArea.addEventListener('drop', (e) => {
            e.preventDefault();
            moduleDocsDropArea.style.borderColor = '#6f42c1';
            moduleDocsDropArea.style.background = 'linear-gradient(135deg, #f8f5fc 0%, #ffffff 100%)';
            if (e.dataTransfer.files.length) {
                handleModuleDocsFiles(e.dataTransfer.files);
            }
        });
    }

    // Handle file input change
    if (moduleDocsFileInput) {
        moduleDocsFileInput.addEventListener('change', (e) => {
            if (e.target.files.length) {
                handleModuleDocsFiles(e.target.files);
            }
        });
    }

    // Process selected files
    function handleModuleDocsFiles(files) {
        const allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'csv'];

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const ext = file.name.split('.').pop().toLowerCase();

            if (!allowedExtensions.includes(ext)) {
                alert('Invalid file type: ' + file.name + '. Allowed: ' + allowedExtensions.join(', '));
                continue;
            }

            // Check if file already added
            const exists = moduleDocsSelectedFiles.some(f => f.name === file.name && f.size === file.size);
            if (!exists) {
                moduleDocsSelectedFiles.push(file);
            }
        }

        updateModuleDocsFileList();
    }

    // Update file list display
    function updateModuleDocsFileList() {
        if (!moduleDocsFileList) return;

        if (moduleDocsSelectedFiles.length === 0) {
            moduleDocsFileList.innerHTML = '';
            return;
        }

        let html = '';
        moduleDocsSelectedFiles.forEach((file, index) => {
            const sizeKB = (file.size / 1024).toFixed(1);
            const sizeMB = (file.size / 1024 / 1024).toFixed(2);
            const sizeStr = file.size > 1024 * 1024 ? sizeMB + ' MB' : sizeKB + ' KB';

            html += `
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f8f9fa; border-radius: 8px; margin-bottom: 8px; border: 1px solid #e9ecef;">
                    <div style="display: flex; align-items: center; gap: 10px; overflow: hidden;">
                        <span style="font-size: 16px;">📄</span>
                        <div style="overflow: hidden;">
                            <div style="font-size: 0.9rem; color: #293E4C; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${file.name}</div>
                            <div style="font-size: 0.75rem; color: #6c757d;">${sizeStr}</div>
                        </div>
                    </div>
                    <button type="button" onclick="removeModuleDocsFile(${index})" style="background: none; border: none; color: #dc3545; cursor: pointer; font-size: 18px; padding: 0 4px;">&times;</button>
                </div>
            `;
        });

        moduleDocsFileList.innerHTML = html;
    }

    // Remove a file from the list
    window.removeModuleDocsFile = function(index) {
        moduleDocsSelectedFiles.splice(index, 1);
        updateModuleDocsFileList();
        updateModuleDocsButtonStatus();
    };

    // Clear all files
    if (moduleDocsClearBtn) {
        moduleDocsClearBtn.addEventListener('click', () => {
            moduleDocsSelectedFiles = [];
            document.getElementById('module_docs_sub_type').value = '';
            document.getElementById('module_docs_description').value = '';
            updateModuleDocsFileList();
            updateModuleDocsButtonStatus();
        });
    }

    // Done button
    if (moduleDocsDoneBtn) {
        moduleDocsDoneBtn.addEventListener('click', () => {
            // Validate if files are selected but no sub-type
            if (moduleDocsSelectedFiles.length > 0) {
                const subType = document.getElementById('module_docs_sub_type').value;
                if (!subType) {
                    alert('Please select a Document Sub-Type when uploading files.');
                    return;
                }
            }
            closeModuleDocsPanel();
        });
    }

    // Close panel event listeners
    if (moduleDocsPanelClose) {
        moduleDocsPanelClose.addEventListener('click', closeModuleDocsPanel);
    }
    if (moduleDocsPanelOverlay) {
        moduleDocsPanelOverlay.addEventListener('click', closeModuleDocsPanel);
    }

    // Update button status across all steps
    function updateModuleDocsButtonStatus() {
        const hasFiles = moduleDocsSelectedFiles.length > 0;
        const fileCount = moduleDocsSelectedFiles.length;

        const buttons = [
            { btn: document.getElementById('moduleDocsBtn1'), badge: document.getElementById('moduleDocsBadge1') },
            { btn: document.getElementById('moduleDocsBtn2'), badge: document.getElementById('moduleDocsBadge2') },
            { btn: document.getElementById('moduleDocsBtn3'), badge: document.getElementById('moduleDocsBadge3') }
        ];

        buttons.forEach(({ btn, badge }) => {
            if (btn) {
                if (hasFiles) {
                    btn.classList.add('has-data');
                    if (badge) {
                        badge.textContent = fileCount + ' file' + (fileCount > 1 ? 's' : '');
                        badge.style.display = 'inline';
                    }
                } else {
                    btn.classList.remove('has-data');
                    if (badge) badge.style.display = 'none';
                }
            }
        });
    }

    // Get files for form submission
    window.getModuleDocsFiles = function() {
        return moduleDocsSelectedFiles;
    };

    window.getModuleDocsSubType = function() {
        return document.getElementById('module_docs_sub_type')?.value || '';
    };

    window.getModuleDocsDescription = function() {
        return document.getElementById('module_docs_description')?.value || '';
    };
})();
</script>
</body>
</html>
