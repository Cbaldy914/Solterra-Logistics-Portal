<?php
session_name("logistics_session");
session_start();

// ========== AUTHENTICATION & PERMISSIONS ==========
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Database connection
require_once '../config.php';
require_once 'anticipated_schedule_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// ========== PORTFOLIO MODE (No project_id) ==========
// If no project_id provided, show project selector
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    // Only admin, global_admin, customer_admin can access portfolio view
    if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
        header("Location: dashboard");
        exit();
    }

    // Get user's accounts
    $accountIds = [];
    if ($role === 'global_admin') {
        $resultAccts = $conn->query("SELECT id FROM customer_accounts");
        if ($resultAccts) {
            while ($row = $resultAccts->fetch_assoc()) {
                $accountIds[] = (int)$row['id'];
            }
        }
    } else {
        $stmtAccts = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ?");
        $stmtAccts->bind_param("i", $user_id);
        $stmtAccts->execute();
        $resultAccts = $stmtAccts->get_result();
        while ($row = $resultAccts->fetch_assoc()) {
            $accountIds[] = (int)$row['account_id'];
        }
        $stmtAccts->close();
    }

    // Get projects
    $projects = [];
    if (count($accountIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $conn->prepare("
            SELECT p.id, p.project_name, p.project_address, p.image_url, p.estimated_completion_date,
                   ca.name as account_name
            FROM projects p
            JOIN customer_accounts ca ON p.account_id = ca.id
            WHERE p.account_id IN ($placeholders)
            AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
        $stmt->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Check if schedule exists
            $scheduleCheck = $conn->prepare("SELECT id FROM anticipated_delivery_schedules WHERE project_id = ? AND is_active = 1 LIMIT 1");
            $scheduleCheck->bind_param("i", $row['id']);
            $scheduleCheck->execute();
            $row['has_schedule'] = $scheduleCheck->get_result()->num_rows > 0;
            $scheduleCheck->close();
            $projects[] = $row;
        }
        $stmt->close();
    }

    $conn->close();

    // Include the portfolio view
    include 'components/anticipated_deliveries_portfolio.php';
    exit();
}

$project_id = intval($_GET['project_id']);

// ========== ACCESS CONTROL ==========
// Verify user has access to this project
if ($role === 'admin') {
    $stmt = $conn->prepare("
        SELECT p.*, ca.name as account_name
        FROM projects p
        JOIN customer_accounts ca ON p.account_id = ca.id
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ? AND cau.role = 'admin'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
} elseif ($role === 'global_admin') {
    $stmt = $conn->prepare("
        SELECT p.*, ca.name as account_name
        FROM projects p
        JOIN customer_accounts ca ON p.account_id = ca.id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $project_id);
} else {
    // Regular users can view read-only
    $stmt = $conn->prepare("
        SELECT p.*, ca.name as account_name
        FROM projects p
        JOIN customer_accounts ca ON p.account_id = ca.id
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("You do not have access to this project.");
}

$project = $result->fetch_assoc();
$stmt->close();

// Determine if user can edit (admin/global_admin only)
$can_edit = ($role === 'admin' || $role === 'global_admin');

// ========== FETCH PROJECT DATA ==========
$project_summary = getProjectSizeSummary($conn, $project_id);
$existing_schedule = getActiveSchedule($conn, $project_id);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anticipated Delivery Schedule - <?php echo htmlspecialchars($project['project_name']); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        /* ==================== MODERN DESIGN ==================== */
        body {
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }

        /* Hero Header - Matching view_project.php style */
        .schedule-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .schedule-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: flex-start; /* Ensure left-to-right flow */
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        .header-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-left: auto; /* Push stats to the right side */
        }

        .stat-item {
            text-align: center;
            background: rgba(72, 140, 154, 0.08);
            padding: 16px 20px;
            border-radius: 16px;
            min-width: 100px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.2);
        }

        .stat-number {
            font-size: 2em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.85em;
            color: #6c757d;
            margin: 4px 0 0 0;
            font-weight: 500;
        }

        /* Main Content Area */
        .main-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.8);
            margin-bottom: 30px;
        }

        /* Tab Switcher */
        .tab-switcher {
            display: flex;
            gap: 10px;
            margin-bottom: 40px;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 16px;
            width: fit-content;
        }

        .tab-btn {
            padding: 14px 32px;
            border: none;
            background: transparent;
            color: #6c757d;
            font-size: 1.05em;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.3);
            transform: translateY(-2px);
        }

        .tab-btn:not(.active):hover {
            background: #e9ecef;
            color: #495057;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 30px;
        }

        .form-label {
            display: block;
            font-size: 0.95em;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 1em;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            background: white;
            box-sizing: border-box; /* Prevent width overflow with padding/border */
        }

        .form-input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 4px rgba(72, 140, 154, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 15px;
            align-items: start;
        }

        .form-row-inline {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
        }

        .rate-inputs {
            display: flex;
            gap: 12px;
            align-items: flex-end; /* Proper bottom alignment in flex */
            flex-wrap: nowrap; /* Keep on one line at normal widths */
        }

        .rate-inputs .form-input {
            flex: 1;
        }

        select.form-input {
            cursor: pointer;
        }

        /* Preview Section */
        .preview-box {
            background: linear-gradient(135deg, #488C9A15 0%, #3A6E7F15 100%);
            border-radius: 16px;
            padding: 30px;
            margin: 30px 0;
            border: 2px dashed #488C9A;
        }

        .preview-title {
            font-size: 1.1em;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .preview-content-wrapper {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 30px;
            align-items: start;
        }

        .preview-content {
            font-size: 1.05em;
            color: #495057;
            line-height: 1.8;
        }

        .preview-highlight {
            font-size: 1.4em;
            font-weight: 700;
            color: #488C9A;
        }

        /* Buttons */
        .btn {
            padding: 14px 32px;
            border: none;
            border-radius: 12px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 140, 154, 0.4);
        }

        .btn-secondary {
            background: #e9ecef;
            color: #495057;
        }

        .btn-secondary:hover {
            background: #dee2e6;
        }

        .btn-danger {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(231, 76, 60, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(231, 76, 60, 0.4);
        }

        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        /* Week Editor (Detailed Mode) */
        .week-list {
            margin-top: 30px;
        }

        .week-item {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        .week-item:hover {
            border-color: #488C9A;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.1);
        }

        .week-info {
            flex: 1;
        }

        .week-date {
            font-weight: 600;
            color: #293E4C;
            font-size: 1.05em;
            margin-bottom: 5px;
        }

        .week-amount {
            color: #488C9A;
            font-weight: 700;
            font-size: 1.2em;
        }

        .week-actions {
            display: flex;
            gap: 10px;
        }

        .btn-icon {
            padding: 8px 16px;
            font-size: 0.9em;
        }

        /* Progress Bar */
        .progress-container {
            margin: 30px 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.95em;
            font-weight: 600;
            color: #293E4C;
        }

        .progress-bar-bg {
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 10px;
            transition: width 0.5s ease;
            position: relative;
            overflow: hidden;
        }

        .progress-bar-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer-progress 2s infinite;
        }

        @keyframes shimmer-progress {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Chart Container */
        .chart-container {
            margin-top: 40px;
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        .chart-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 20px;
        }

        .preview-chart-container {
            background: white;
            padding: 20px;
            border-radius: 12px;
        }

        .preview-chart-title {
            font-size: 1em;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 15px;
        }

        /* Existing Schedule Display */
        .schedule-display {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            border: 2px solid #dee2e6;
        }

        /* Scope the schedule display header styles to avoid overriding hero header */
        .schedule-display .schedule-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 20px;
        }

        .schedule-info {
            flex: 1;
        }

        .schedule-title {
            font-size: 1.3em;
            font-weight: 700;
            color: #293E4C;
            margin-bottom: 10px;
        }

        .schedule-meta {
            font-size: 0.9em;
            color: #6c757d;
            line-height: 1.6;
        }

        .schedule-actions {
            display: flex;
            gap: 10px;
        }

        .schedule-details {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #293E4C;
        }

        .detail-value {
            color: #488C9A;
            font-weight: 600;
        }

        /* Loading & Alert States */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #488C9A;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }

            .header-info h1 {
                font-size: 1.8em;
            }

            .header-stats {
                justify-content: space-between;
            }

            .stat-item {
                flex: 1;
                min-width: 80px;
            }

            .tab-switcher {
                flex-direction: column;
                width: 100%;
            }

            .tab-btn {
                width: 100%;
                text-align: center;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-row-inline {
                grid-template-columns: 1fr;
            }

            .rate-inputs {
                flex-direction: column;
                align-items: stretch;
            }

            .rate-inputs .form-input {
                width: 100%;
            }

            .preview-content-wrapper {
                grid-template-columns: 1fr;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* ---------------- Page-specific layout fixes ---------------- */
        /* Prevent inputs from overflowing their grid columns and overlapping */
        .form-row-inline > .form-group { min-width: 0; }
        .rate-inputs .form-input { min-width: 0; }

        /* Keep columns top-aligned; input heights will match */
        .form-row-inline { align-items: start; }

        /* Normalize control heights for consistent alignment */
        input.form-input,
        select.form-input {
            height: 48px;
            line-height: 1.5;
            padding-top: 10px;
            padding-bottom: 14px;
        }
        
        /* Fix dropdown text alignment so it's not cut off at bottom */
        select.form-input option {
            padding: 8px;
        }

        /* Ensure flatpickr-styled input behaves like our inputs */
        .flatpickr-input.form-input {
            box-sizing: border-box;
            line-height: 1.2;
            width: 100% !important; /* Override any external width constraints */
        }


        .readonly-message {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            color: #856404;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <main>
        <?php
            require_once 'components/breadcrumbs.php';
            echo slp_render_breadcrumbs([
                'current_label' => 'Anticipated Delivery Schedule',
                'project_id' => $project_id
            ]);
        ?>

        <!-- Hero Header -->
        <div class="schedule-header">
            <div class="header-content">
                <div class="header-info">
                    <h1>Anticipated Delivery Schedule</h1>
                    <p class="header-subtitle"><?php echo htmlspecialchars($project['project_name']); ?></p>
                </div>
                <div class="header-stats">
                    <div class="stat-item">
                        <p class="stat-number"><?php echo number_format($project_summary['mw'], 2); ?></p>
                        <p class="stat-label">Total MW</p>
                    </div>
                    <div class="stat-item">
                        <p class="stat-number"><?php echo number_format($project_summary['modules']); ?></p>
                        <p class="stat-label">Total Modules</p>
                    </div>
                    <div class="stat-item">
                        <p class="stat-number"><?php echo number_format($project_summary['pallets'], 0); ?></p>
                        <p class="stat-label">Estimated Pallets</p>
                    </div>
                    <div class="stat-item">
                        <p class="stat-number"><?php echo number_format($project_summary['trucks'], 0); ?></p>
                        <p class="stat-label">Estimated Trucks</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Read-only message for regular users -->
        <?php if (!$can_edit): ?>
        <div class="readonly-message">
            <span style="font-size: 1.5em;">🔒</span>
            <div>
                <strong>View Only Mode</strong><br>
                You can view the delivery schedule, but only administrators can create or modify it.
            </div>
        </div>
        <?php endif; ?>

        <!-- Existing Schedule Display (Saved View styled like Quick Schedule) -->
        <?php if ($existing_schedule): ?>
        <div class="main-content" id="savedScheduleView">
            <?php if ($can_edit): ?>
            <div class="schedule-actions" style="display:flex; gap:10px; justify-content:flex-end; margin-bottom: 10px;">
                <button class="btn btn-secondary btn-icon" onclick="editSchedule()">✏️ Edit</button>
                <button class="btn btn-danger btn-icon" onclick="deleteSchedule()">🗑️ Delete</button>
            </div>
            <?php endif; ?>

            <div class="form-row-inline" style="margin-bottom: 30px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">📅 Delivery Start Date</label>
                    <input type="text" 
                           class="form-input" 
                           id="savedStartDate" 
                           value="<?php echo htmlspecialchars($existing_schedule['delivery_start_date']); ?>" 
                           disabled>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">📦 Delivery Rate</label>
                    <div class="rate-inputs">
                        <input type="number" 
                               class="form-input" 
                               id="savedRateValue" 
                               value="<?php echo ($existing_schedule['schedule_type'] === 'quick' ? htmlspecialchars(number_format($existing_schedule['quick_rate_value'], 3, '.', '')) : ''); ?>" 
                               disabled>
                        
                        <select class="form-input" id="savedRateUnit" disabled style="width: 150px;">
                            <option value="mw" <?php echo ($existing_schedule['schedule_type'] === 'quick' && $existing_schedule['quick_rate_unit'] === 'mw') ? 'selected' : ''; ?>>MW</option>
                            <option value="modules" <?php echo ($existing_schedule['schedule_type'] === 'quick' && $existing_schedule['quick_rate_unit'] === 'modules') ? 'selected' : ''; ?>>Modules</option>
                            <option value="pallets" <?php echo ($existing_schedule['schedule_type'] === 'quick' && $existing_schedule['quick_rate_unit'] === 'pallets') ? 'selected' : ''; ?>>Pallets</option>
                            <option value="trucks" <?php echo ($existing_schedule['schedule_type'] === 'quick' && $existing_schedule['quick_rate_unit'] === 'trucks') ? 'selected' : ''; ?>>Trucks</option>
                        </select>

                        <select class="form-input" id="savedRateFrequency" disabled style="width: 150px;">
                            <option value="per_week" <?php echo ($existing_schedule['schedule_type'] === 'quick' && $existing_schedule['quick_rate_frequency'] === 'per_week') ? 'selected' : ''; ?>>Per Week</option>
                            <option value="per_month" <?php echo ($existing_schedule['schedule_type'] === 'quick' && $existing_schedule['quick_rate_frequency'] === 'per_month') ? 'selected' : ''; ?>>Per Month</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="preview-box">
                <div class="preview-content-wrapper">
                    <div class="preview-content" id="savedPreviewContent"></div>
                    <div class="preview-chart-container">
                        <div class="preview-chart-title">📈 Anticipated Delivery Timeline</div>
                        <canvas id="mainScheduleChart" height="120"></canvas>
                    </div>
                </div>
            </div>

            <?php if (!empty($existing_schedule['notes'])): ?>
            <div class="form-group" style="margin-top: 20px;">
                <label class="form-label">📝 Notes</label>
                <div class="form-input" style="padding: 16px 18px; background: #fff;">
                    <?php echo nl2br(htmlspecialchars($existing_schedule['notes'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Main Schedule Editor -->
        <?php if ($can_edit): ?>
        <div class="main-content" id="scheduleEditor" style="<?php echo $existing_schedule ? 'display: none;' : ''; ?>">
            <!-- Tab Switcher -->
            <div class="tab-switcher">
                <button class="tab-btn active" onclick="switchTab('quick')">⚡ Quick Schedule</button>
                <button class="tab-btn" onclick="switchTab('detailed')">📋 Detailed Schedule</button>
            </div>

            <!-- Alerts -->
            <div id="alertContainer"></div>

            <!-- Quick Schedule Tab -->
            <div class="tab-content active" id="quickTab">
                <form id="quickScheduleForm">
                    <input type="hidden" name="schedule_type" value="quick">
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">

                    <div class="form-row-inline" style="margin-bottom: 30px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">📅 Delivery Start Date</label>
                            <input type="text" 
                                   class="form-input" 
                                   id="quickStartDate" 
                                   name="delivery_start_date" 
                                   placeholder="Select start date" 
                                   required>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">📦 Delivery Rate</label>
                            <div class="rate-inputs">
                                <input type="number" 
                                       class="form-input" 
                                       id="quickRateValue" 
                                       name="quick_rate_value" 
                                       placeholder="Enter amount" 
                                       step="0.001" 
                                       min="0.001" 
                                       required
                                       oninput="calculatePreview()">
                                
                                <select class="form-input" 
                                        id="quickRateUnit" 
                                        name="quick_rate_unit" 
                                        required
                                        onchange="calculatePreview()"
                                        style="width: 150px;">
                                    <option value="mw">MW</option>
                                    <option value="modules">Modules</option>
                                    <option value="pallets">Pallets</option>
                                    <option value="trucks">Trucks</option>
                                </select>

                                <select class="form-input" 
                                        id="quickRateFrequency" 
                                        name="quick_rate_frequency" 
                                        required
                                        onchange="calculatePreview()"
                                        style="width: 150px;">
                                    <option value="per_week" selected>Per Week</option>
                                    <option value="per_month">Per Month</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="preview-box" id="quickPreview" style="display: none;">
                        <div class="preview-title">
                            🎯 Preview
                        </div>
                        <div class="preview-content-wrapper">
                            <div class="preview-content" id="quickPreviewContent">
                                <!-- Dynamic content -->
                            </div>
                            
                            <!-- Chart in Preview -->
                            <div class="preview-chart-container" id="previewChartContainer" style="display: none;">
                                <div class="preview-chart-title">📈 Anticipated Delivery Timeline</div>
                                <canvas id="scheduleChart" height="120"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">📝 Notes (Optional)</label>
                        <textarea class="form-input" 
                                  name="notes" 
                                  rows="3" 
                                  placeholder="Add any notes about this schedule..."></textarea>
                    </div>

                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" onclick="saveQuickSchedule()">
                            💾 Save Schedule
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="calculatePreview()">
                            🧮 Calculate Preview
                        </button>
                        <?php if ($existing_schedule): ?>
                        <button type="button" class="btn btn-secondary" onclick="cancelEdit()">
                            ✖️ Cancel
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Detailed Schedule Tab -->
            <div class="tab-content" id="detailedTab">
                <form id="detailedScheduleForm">
                    <input type="hidden" name="schedule_type" value="detailed">
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">

                    <div class="form-group">
                        <label class="form-label">📅 Schedule Start Date</label>
                        <input type="text" 
                               class="form-input" 
                               id="detailedStartDate" 
                               name="delivery_start_date" 
                               placeholder="Select start date" 
                               required>
                    </div>

                    <div class="form-group">
                        <button type="button" class="btn btn-secondary" onclick="autoGenerateWeeks()">
                            ✨ Auto-Generate Weekly Schedule
                        </button>
                        <p style="font-size: 0.9em; color: #6c757d; margin-top: 10px;">
                            Automatically creates a weekly schedule based on equal distribution across the project timeline.
                        </p>
                    </div>

                    <div class="week-list" id="weekList">
                        <!-- Dynamic weeks will be added here -->
                    </div>

                    <button type="button" class="btn btn-secondary btn-icon" onclick="addWeek()">
                        ➕ Add Week
                    </button>

                    <div class="progress-container" id="detailedProgress" style="display: none;">
                        <div class="progress-label">
                            <span>Schedule Progress</span>
                            <span id="progressText">0 / <?php echo number_format($project_summary['mw'], 2); ?> MW</span>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" id="progressBar" style="width: 0%;"></div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 30px;">
                        <label class="form-label">📝 Notes (Optional)</label>
                        <textarea class="form-input" 
                                  name="notes" 
                                  rows="3" 
                                  placeholder="Add any notes about this schedule..."></textarea>
                    </div>

                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" onclick="saveDetailedSchedule()">
                            💾 Save Detailed Schedule
                        </button>
                        <?php if ($existing_schedule): ?>
                        <button type="button" class="btn btn-secondary" onclick="cancelEdit()">
                            ✖️ Cancel
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Visualization chart is included inside the saved preview-box above -->
    </main>

    <script>
        // ==================== GLOBAL STATE ====================
        const projectId = <?php echo $project_id; ?>;
        const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
        const projectSummary = <?php echo json_encode($project_summary); ?>;
        const existingSchedule = <?php echo $existing_schedule ? json_encode($existing_schedule) : 'null'; ?>;

        let weekCount = 0;
        let scheduleChart = null;
        let mainChart = null;

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Flatpickr for date inputs
            flatpickr("#quickStartDate", {
                dateFormat: "Y-m-d",
                minDate: "today"
            });

            flatpickr("#detailedStartDate", {
                dateFormat: "Y-m-d",
                minDate: "today"
            });

            // Initialize chart
            initializeChart();

            // If existing schedule, populate edit form
            if (existingSchedule && canEdit) {
                // populateEditForm(); // Can be implemented if needed
            }
        });

        // ==================== TAB SWITCHING ====================
        function switchTab(tab) {
            const tabs = document.querySelectorAll('.tab-btn');
            const contents = document.querySelectorAll('.tab-content');

            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));

            if (tab === 'quick') {
                tabs[0].classList.add('active');
                document.getElementById('quickTab').classList.add('active');
            } else {
                tabs[1].classList.add('active');
                document.getElementById('detailedTab').classList.add('active');
            }
        }

        // ==================== QUICK SCHEDULE FUNCTIONS ====================
        function calculatePreview() {
            const rateValue = parseFloat(document.getElementById('quickRateValue').value);
            const rateUnit = document.getElementById('quickRateUnit').value;
            const frequency = document.getElementById('quickRateFrequency').value;
            const startDate = document.getElementById('quickStartDate').value;

            if (!rateValue || !rateUnit || !frequency || !startDate) {
                document.getElementById('quickPreview').style.display = 'none';
                return;
            }

            // Show loading
            showAlert('Calculating...', 'info');

            // AJAX call to calculate
            fetch('calculate_schedule_preview.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    project_id: projectId,
                    rate_value: rateValue,
                    rate_unit: rateUnit,
                    frequency: frequency,
                    start_date: startDate
                })
            })
            .then(response => response.json())
            .then(data => {
                clearAlerts();
                if (data.success) {
                    const preview = document.getElementById('quickPreview');
                    const content = document.getElementById('quickPreviewContent');
                    
                    content.innerHTML = `
                        With this delivery rate, the project will be completed in:<br>
                        <span class="preview-highlight">${data.duration_weeks} weeks</span><br>
                        Estimated completion: <span class="preview-highlight">${data.completion_date}</span><br>
                        <small style="color: #6c757d;">Total: ${data.total_deliveries} deliveries</small>
                    `;
                    preview.style.display = 'block';

                    // Update chart with preview
                    updateChartPreview(data.chart_data);
                    document.getElementById('previewChartContainer').style.display = 'block';
                } else {
                    showAlert(data.message || 'Calculation failed', 'error');
                }
            })
            .catch(error => {
                clearAlerts();
                showAlert('Error calculating preview: ' + error.message, 'error');
            });
        }

        function saveQuickSchedule() {
            const form = document.getElementById('quickScheduleForm');
            const formData = new FormData(form);

            if (!form.checkValidity()) {
                showAlert('Please fill in all required fields', 'error');
                return;
            }

            // Show confirmation if overwriting
            if (existingSchedule) {
                if (!confirm('⚠️ This will overwrite your existing schedule. Are you sure you want to continue?')) {
                    return;
                }
            }

            // Convert to JSON
            const data = {};
            formData.forEach((value, key) => data[key] = value);

            // Show loading
            showAlert('Saving schedule...', 'info');

            fetch('save_anticipated_schedule.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                clearAlerts();
                if (result.success) {
                    showAlert('✅ Schedule saved successfully!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message || 'Failed to save schedule', 'error');
                }
            })
            .catch(error => {
                clearAlerts();
                showAlert('Error saving schedule: ' + error.message, 'error');
            });
        }

        // ==================== DETAILED SCHEDULE FUNCTIONS ====================
        function addWeek(date = '', amount = '', unit = 'mw') {
            weekCount++;
            const weekList = document.getElementById('weekList');
            
            const weekItem = document.createElement('div');
            weekItem.className = 'week-item';
            weekItem.id = `week-${weekCount}`;
            weekItem.innerHTML = `
                <div class="week-info">
                    <input type="date" 
                           class="form-input week-date-input" 
                           name="weeks[${weekCount}][date]" 
                           value="${date}"
                           onchange="updateDetailedProgress()"
                           required
                           style="width: 180px; display: inline-block; margin-right: 15px;">
                    <input type="number" 
                           class="form-input week-amount-input" 
                           name="weeks[${weekCount}][amount]" 
                           value="${amount}"
                           step="0.001" 
                           min="0.001"
                           onchange="updateDetailedProgress()"
                           placeholder="Amount" 
                           required
                           style="width: 120px; display: inline-block; margin-right: 10px;">
                    <select class="form-input" 
                            name="weeks[${weekCount}][unit]" 
                            required
                            onchange="updateDetailedProgress()"
                            style="width: 120px; display: inline-block;">
                        <option value="mw" ${unit === 'mw' ? 'selected' : ''}>MW</option>
                        <option value="modules" ${unit === 'modules' ? 'selected' : ''}>Modules</option>
                        <option value="pallets" ${unit === 'pallets' ? 'selected' : ''}>Pallets</option>
                        <option value="trucks" ${unit === 'trucks' ? 'selected' : ''}>Trucks</option>
                    </select>
                </div>
                <div class="week-actions">
                    <button type="button" class="btn btn-secondary btn-icon" onclick="removeWeek(${weekCount})">
                        🗑️
                    </button>
                </div>
            `;
            
            weekList.appendChild(weekItem);
            updateDetailedProgress();
        }

        function removeWeek(id) {
            const weekItem = document.getElementById(`week-${id}`);
            if (weekItem) {
                weekItem.remove();
                updateDetailedProgress();
            }
        }

        function autoGenerateWeeks() {
            const startDate = document.getElementById('detailedStartDate').value;
            if (!startDate) {
                showAlert('Please select a start date first', 'error');
                return;
            }

            // Clear existing weeks
            document.getElementById('weekList').innerHTML = '';
            weekCount = 0;

            // Generate 52 weeks of equal distribution (1 year as default)
            const totalMW = projectSummary.mw;
            const numWeeks = 52;
            const mwPerWeek = (totalMW / numWeeks).toFixed(3);

            let currentDate = new Date(startDate);

            for (let i = 0; i < numWeeks; i++) {
                // Get next Sunday
                const daysUntilSunday = (7 - currentDate.getDay()) % 7;
                currentDate.setDate(currentDate.getDate() + daysUntilSunday);
                
                const dateStr = currentDate.toISOString().split('T')[0];
                addWeek(dateStr, mwPerWeek, 'mw');
                
                // Move to next week
                currentDate.setDate(currentDate.getDate() + 7);
            }

            showAlert(`✨ Generated ${numWeeks} weeks automatically!`, 'success');
            setTimeout(clearAlerts, 3000);
        }

        function updateDetailedProgress() {
            const weekItems = document.querySelectorAll('.week-item');
            if (weekItems.length === 0) {
                document.getElementById('detailedProgress').style.display = 'none';
                return;
            }

            let totalMW = 0;

            weekItems.forEach(item => {
                const amountInput = item.querySelector('.week-amount-input');
                const unitSelect = item.querySelector('select[name*="[unit]"]');
                
                if (amountInput && unitSelect) {
                    const amount = parseFloat(amountInput.value) || 0;
                    const unit = unitSelect.value;

                    // Convert to MW
                    let mw = amount;
                    if (unit === 'modules') {
                        mw = (amount * projectSummary.avg_wattage) / 1000000;
                    } else if (unit === 'pallets') {
                        mw = (amount * projectSummary.modules_per_pallet * projectSummary.avg_wattage) / 1000000;
                    } else if (unit === 'trucks') {
                        mw = (amount * projectSummary.pallets_per_truck * projectSummary.modules_per_pallet * projectSummary.avg_wattage) / 1000000;
                    }

                    totalMW += mw;
                }
            });

            const percentage = (totalMW / projectSummary.mw) * 100;
            
            document.getElementById('detailedProgress').style.display = 'block';
            document.getElementById('progressText').textContent = 
                `${totalMW.toFixed(2)} / ${projectSummary.mw.toFixed(2)} MW (${percentage.toFixed(1)}%)`;
            document.getElementById('progressBar').style.width = Math.min(percentage, 100) + '%';
        }

        function saveDetailedSchedule() {
            const form = document.getElementById('detailedScheduleForm');
            const startDate = document.getElementById('detailedStartDate').value;
            const notes = form.querySelector('textarea[name="notes"]').value;

            if (!startDate) {
                showAlert('Please select a start date', 'error');
                return;
            }

            // Collect weeks data
            const weeks = [];
            const weekItems = document.querySelectorAll('.week-item');

            if (weekItems.length === 0) {
                showAlert('Please add at least one week to the schedule', 'error');
                return;
            }

            weekItems.forEach(item => {
                const dateInput = item.querySelector('.week-date-input');
                const amountInput = item.querySelector('.week-amount-input');
                const unitSelect = item.querySelector('select[name*="[unit]"]');

                if (dateInput && amountInput && unitSelect) {
                    weeks.push({
                        date: dateInput.value,
                        amount: parseFloat(amountInput.value),
                        unit: unitSelect.value
                    });
                }
            });

            // Show confirmation if overwriting
            if (existingSchedule) {
                if (!confirm('⚠️ This will overwrite your existing schedule. Are you sure you want to continue?')) {
                    return;
                }
            }

            const data = {
                project_id: projectId,
                schedule_type: 'detailed',
                delivery_start_date: startDate,
                notes: notes,
                weeks: weeks
            };

            // Show loading
            showAlert('Saving detailed schedule...', 'info');

            fetch('save_anticipated_schedule.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                clearAlerts();
                if (result.success) {
                    showAlert('✅ Detailed schedule saved successfully!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message || 'Failed to save schedule', 'error');
                }
            })
            .catch(error => {
                clearAlerts();
                showAlert('Error saving schedule: ' + error.message, 'error');
            });
        }

        // ==================== SCHEDULE MANAGEMENT ====================
        function editSchedule() {
            document.getElementById('scheduleEditor').style.display = 'block';
            // Hide the saved read-only view while editing to avoid duplication
            var savedView = document.getElementById('savedScheduleView');
            if (savedView) { savedView.style.display = 'none'; }
            
            // Populate form with existing data
            if (existingSchedule.schedule_type === 'quick') {
                document.getElementById('quickStartDate').value = existingSchedule.delivery_start_date;
                document.getElementById('quickRateValue').value = existingSchedule.quick_rate_value;
                document.getElementById('quickRateUnit').value = existingSchedule.quick_rate_unit;
                document.getElementById('quickRateFrequency').value = existingSchedule.quick_rate_frequency;
                document.querySelector('#quickScheduleForm textarea[name="notes"]').value = existingSchedule.notes || '';
                switchTab('quick');
            } else {
                document.getElementById('detailedStartDate').value = existingSchedule.delivery_start_date;
                document.querySelector('#detailedScheduleForm textarea[name="notes"]').value = existingSchedule.notes || '';
                
                // Populate weeks
                if (existingSchedule.details) {
                    existingSchedule.details.forEach(detail => {
                        addWeek(detail.delivery_week_ending, detail.delivery_amount, detail.delivery_unit);
                    });
                }
                switchTab('detailed');
            }

            // Scroll to editor
            document.getElementById('scheduleEditor').scrollIntoView({ behavior: 'smooth' });
        }

        function cancelEdit() {
            document.getElementById('scheduleEditor').style.display = 'none';
            // Restore the saved read-only view if it exists
            var savedView = document.getElementById('savedScheduleView');
            if (savedView) { savedView.style.display = 'block'; }
            
            // Reset forms
            document.getElementById('quickScheduleForm').reset();
            document.getElementById('detailedScheduleForm').reset();
            document.getElementById('weekList').innerHTML = '';
            weekCount = 0;
        }

        function deleteSchedule() {
            if (!confirm('⚠️ Are you sure you want to delete this delivery schedule? This action cannot be undone.')) {
                return;
            }

            showAlert('Deleting schedule...', 'info');

            fetch('delete_anticipated_schedule.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    project_id: projectId
                })
            })
            .then(response => response.json())
            .then(result => {
                clearAlerts();
                if (result.success) {
                    showAlert('✅ Schedule deleted successfully!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message || 'Failed to delete schedule', 'error');
                }
            })
            .catch(error => {
                clearAlerts();
                showAlert('Error deleting schedule: ' + error.message, 'error');
            });
        }

        // ==================== CHART FUNCTIONS ====================
        function initializeChart() {
            // Fetch chart data if schedule exists
            if (existingSchedule) {
                fetch(`get_anticipated_schedule.php?project_id=${projectId}&chart_data=1`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.chart_data) {
                            createMainChart(data.chart_data);
                            // Populate saved preview summary if present
                            const savedContent = document.getElementById('savedPreviewContent');
                            if (savedContent) {
                                const dates = data.chart_data.dates || [];
                                const last = dates.length ? new Date(dates[dates.length - 1]) : null;
                                const durationWeeks = dates.length;
                                const completion = last ? last.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) : '-';
                                const totalDeliveries = durationWeeks; // one point per week

                                savedContent.innerHTML = `
                                    With this delivery rate, the project will be completed in:<br>
                                    <span class="preview-highlight">${durationWeeks} weeks</span><br>
                                    Estimated completion: <span class="preview-highlight">${completion}</span><br>
                                    <small style=\"color: #6c757d;\">Total: ${totalDeliveries} deliveries</small>
                                `;
                            }
                        }
                    })
                    .catch(() => console.error('Failed to load chart data'));
            }
        }

        function createMainChart(chartData) {
            const canvas = document.getElementById('mainScheduleChart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            
            if (mainChart) {
                mainChart.destroy();
            }

            mainChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartData.dates,
                    datasets: [{
                        label: 'Anticipated Deliveries (MW)',
                        data: chartData.cumulative_mw,
                        borderColor: '#488C9A',
                        borderWidth: 3,
                        fill: false,
                        tension: 0.1,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#488C9A'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: 'month',
                                displayFormats: {
                                    month: 'MMM yyyy'
                                }
                            },
                            title: {
                                display: true,
                                text: 'Date'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Cumulative MW'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    }
                }
            });
        }

        function createChart(chartData) {
            const ctx = document.getElementById('scheduleChart').getContext('2d');
            
            if (scheduleChart) {
                scheduleChart.destroy();
            }

            scheduleChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartData.dates,
                    datasets: [{
                        label: 'Anticipated Deliveries (MW)',
                        data: chartData.cumulative_mw,
                        borderColor: '#488C9A',
                        borderWidth: 3,
                        fill: false,
                        tension: 0.1,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#488C9A'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: 'month',
                                displayFormats: {
                                    month: 'MMM yyyy'
                                }
                            },
                            title: {
                                display: true,
                                text: 'Date'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Cumulative MW'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    }
                }
            });
        }


        function updateChartPreview(chartData) {
            if (chartData) {
                createChart(chartData);
            }
        }

        // ==================== ALERT FUNCTIONS ====================
        function showAlert(message, type = 'info') {
            clearAlerts();
            
            const container = document.getElementById('alertContainer');
            const alertDiv = document.createElement('div');
            
            let className = 'alert ';
            let icon = '';
            
            switch(type) {
                case 'success':
                    className += 'alert-success';
                    icon = '✅';
                    break;
                case 'error':
                    className += 'alert-error';
                    icon = '❌';
                    break;
                case 'warning':
                    className += 'alert-warning';
                    icon = '⚠️';
                    break;
                default:
                    className += 'alert-warning';
                    icon = 'ℹ️';
            }
            
            alertDiv.className = className;
            alertDiv.innerHTML = `${icon} <span>${message}</span>`;
            container.appendChild(alertDiv);
        }

        function clearAlerts() {
            document.getElementById('alertContainer').innerHTML = '';
        }
    </script>
</body>
</html>
