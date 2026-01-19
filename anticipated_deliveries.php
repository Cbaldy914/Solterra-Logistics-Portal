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
require_once 'projection_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// ========== PORTFOLIO MODE (No project_id) ==========
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
            // Check if projection exists
            $row['has_projection'] = project_has_projection($conn, $row['id']);
            $projects[] = $row;
        }
        $stmt->close();
    }

    $conn->close();
    include 'components/anticipated_deliveries_portfolio.php';
    exit();
}

$project_id = intval($_GET['project_id']);

// ========== ACCESS CONTROL ==========
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

// Determine if user can edit
$can_edit = in_array($role, ['admin', 'global_admin', 'customer_admin']);

// ========== FETCH PROJECT DATA ==========
$projections = get_project_projections($conn, $project_id);
$available_batches = get_available_module_batches($conn, $project_id);

// Check if specific projection_id is requested
$requested_projection_id = isset($_GET['projection_id']) ? intval($_GET['projection_id']) : null;

// Load the requested projection or fall back to primary
if ($requested_projection_id) {
    $current_projection = get_projection($conn, $requested_projection_id);
} else {
    $current_projection = get_primary_projection($conn, $project_id);
}

// Prepare data for initial load
$allocated_modules = $current_projection['module_allocations'] ?? [];
$stops = $current_projection['stops'] ?? [];
$legs = $current_projection['legs'] ?? [];
$cost_summary = $current_projection['cost_summary'] ?? [];
$total_pallets = 0;
foreach ($allocated_modules as $alloc) {
    $total_pallets += $alloc['pallets'] ?? 0;
}

// Get available templates
$templates = get_projection_templates($conn);

// Get project size summary for header stats
require_once 'anticipated_schedule_helpers.php';
$project_summary = getProjectSizeSummary($conn, $project_id);

// Note: Don't close connection here - components may need it
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery & Cost Planning - <?php echo htmlspecialchars($project['project_name']); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <?php
    $google_maps_key = getenv('GOOGLE_MAPS_API_KEY') ?: '';
    if (!empty($google_maps_key)):
    ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo $google_maps_key; ?>&libraries=geometry"></script>
    <?php endif; ?>
    <style>
        /* ==================== BASE STYLES ==================== */
        body {
            background: linear-gradient(180deg, #f0f4f5 0%, #e8eef0 100%);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
        }

        main {
            padding-bottom: 60px;
        }

        /* ==================== PAGE HEADER ==================== */
        .page-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafb 100%);
            border-radius: 20px;
            padding: 28px 32px;
            margin-bottom: 28px;
            box-shadow: 0 8px 32px rgba(41, 62, 76, 0.08);
            border: 1px solid rgba(72, 140, 154, 0.1);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #E07F3A 50%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.3);
        }

        .header-info h1 {
            font-size: 1.8em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 4px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1em;
            font-weight: 500;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-subtitle a {
            color: #488C9A;
            text-decoration: none;
            transition: color 0.2s;
        }

        .header-subtitle a:hover {
            color: #3A6E7F;
        }

        .header-stats {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(58, 110, 127, 0.08) 100%);
            border-radius: 14px;
            padding: 14px 18px;
            text-align: center;
            min-width: 90px;
            border: 1px solid rgba(72, 140, 154, 0.15);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(72, 140, 154, 0.15);
        }

        .stat-value {
            font-size: 1.5em;
            font-weight: 700;
            color: #488C9A;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.75em;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* ==================== MAIN LAYOUT ==================== */
        .planner-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
            align-items: start;
        }

        .planner-main {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .planner-sidebar {
            display: flex;
            flex-direction: column;
            gap: 24px;
            position: sticky;
            top: 24px;
        }

        @media (max-width: 1200px) {
            .planner-layout {
                grid-template-columns: 1fr;
            }
            .planner-sidebar {
                position: static;
            }
        }

        /* ==================== CARD STYLES ==================== */
        .card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 4px 24px rgba(41, 62, 76, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.05em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
        }

        .card-title svg {
            color: #488C9A;
        }

        .card-body {
            padding: 24px;
        }

        /* ==================== READ-ONLY MESSAGE ==================== */
        .readonly-banner {
            background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%);
            border: 1px solid #ffc107;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: #856404;
        }

        .readonly-banner svg {
            flex-shrink: 0;
        }

        .readonly-banner strong {
            display: block;
            margin-bottom: 2px;
        }

        /* ==================== BUTTONS ==================== */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-size: 0.95em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 4px 16px rgba(72, 140, 154, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(72, 140, 154, 0.4);
        }

        .btn-secondary {
            background: #e9ecef;
            color: #495057;
        }

        .btn-secondary:hover {
            background: #dee2e6;
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20883a 100%);
            color: white;
            box-shadow: 0 4px 16px rgba(40, 167, 69, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);
            color: white;
        }

        .btn-orange {
            background: linear-gradient(135deg, #E07F3A 0%, #c76a2e 100%);
            color: white;
            box-shadow: 0 4px 16px rgba(224, 127, 58, 0.3);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9em;
        }

        .btn-icon {
            padding: 10px;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ==================== FORM STYLES ==================== */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 8px;
            font-size: 0.9em;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 1em;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            background: white;
            box-sizing: border-box;
        }

        .form-input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 4px rgba(72, 140, 154, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-help {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 6px;
        }

        /* ==================== MODAL STYLES ==================== */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(41, 62, 76, 0.6);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            position: relative;
            background: white;
            border-radius: 20px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.2em;
            color: #293E4C;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border: none;
            background: #f8f9fa;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: #e9ecef;
            color: #293E4C;
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f8f9fa;
        }

        /* ==================== ALERTS & TOASTS ==================== */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .toast {
            background: white;
            border-radius: 14px;
            padding: 16px 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            animation: toastSlideIn 0.3s ease;
        }

        @keyframes toastSlideIn {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .toast-success {
            border-left: 4px solid #28a745;
        }

        .toast-error {
            border-left: 4px solid #dc3545;
        }

        .toast-info {
            border-left: 4px solid #488C9A;
        }

        .toast-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .toast-success .toast-icon {
            background: #d4edda;
            color: #28a745;
        }

        .toast-error .toast-icon {
            background: #f8d7da;
            color: #dc3545;
        }

        .toast-info .toast-icon {
            background: rgba(72, 140, 154, 0.1);
            color: #488C9A;
        }

        .toast-message {
            flex: 1;
            font-size: 0.95em;
            color: #293E4C;
        }

        /* ==================== LOADING STATE ==================== */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 16px;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #e9ecef;
            border-top-color: #488C9A;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            font-size: 1.1em;
            color: #293E4C;
            font-weight: 500;
        }

        /* ==================== EMPTY STATE ==================== */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: #6c757d;
        }

        .empty-state svg {
            color: #dee2e6;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 1.2em;
            color: #293E4C;
            margin: 0 0 8px;
        }

        .empty-state p {
            margin: 0 0 20px;
        }

        /* ==================== RESPONSIVE ==================== */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-stats {
                width: 100%;
                justify-content: space-between;
            }

            .stat-card {
                flex: 1;
                min-width: 70px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                max-height: 100vh;
                border-radius: 0;
            }
        }

        /* ==================== MAP STYLES ==================== */
        .route-map-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 4px 24px rgba(41, 62, 76, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .route-map-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .route-map-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
        }

        .route-map-title svg {
            color: #488C9A;
        }

        .route-map-container {
            height: 300px;
            position: relative;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        #routeMap {
            width: 100%;
            height: 100%;
        }

        .map-placeholder {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #6c757d;
        }

        .map-placeholder svg {
            color: #dee2e6;
            margin-bottom: 12px;
        }

        .map-legend {
            padding: 12px 20px;
            background: #f8f9fa;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 0.85em;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #495057;
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .legend-dot.origin { background: #28a745; }
        .legend-dot.warehouse { background: #E07F3A; }
        .legend-dot.destination { background: #dc3545; }
        .legend-line {
            width: 20px;
            height: 3px;
            background: #488C9A;
        }

        /* ==================== TIMELINE STYLES ==================== */
        .timeline-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 4px 24px rgba(41, 62, 76, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            overflow: hidden;
            margin-top: 24px;
        }

        .timeline-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .timeline-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
        }

        .timeline-title svg {
            color: #488C9A;
        }

        .timeline-container {
            padding: 24px;
            overflow-x: auto;
        }

        .timeline-chart {
            display: flex;
            align-items: flex-end;
            min-width: 600px;
            height: 200px;
            position: relative;
            padding-bottom: 60px;
        }

        .timeline-bar {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        .timeline-bar-fill {
            width: 40px;
            border-radius: 8px 8px 0 0;
            transition: height 0.5s ease;
            position: relative;
            cursor: pointer;
        }

        .timeline-bar-fill:hover {
            filter: brightness(1.1);
        }

        .timeline-bar-fill.freight { background: linear-gradient(180deg, #488C9A 0%, #3A6E7F 100%); }
        .timeline-bar-fill.warehousing { background: linear-gradient(180deg, #E07F3A 0%, #c76a2e 100%); }
        .timeline-bar-fill.milestone { background: linear-gradient(180deg, #28a745 0%, #20883a 100%); }
        .timeline-bar-fill.customs { background: linear-gradient(180deg, #6f42c1 0%, #5a33a1 100%); }

        .timeline-bar-value {
            position: absolute;
            top: -24px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.75em;
            font-weight: 600;
            color: #293E4C;
            white-space: nowrap;
        }

        .timeline-bar-label {
            position: absolute;
            bottom: -50px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.7em;
            color: #6c757d;
            text-align: center;
            width: 80px;
            line-height: 1.3;
        }

        .timeline-bar-date {
            font-weight: 600;
            color: #293E4C;
            display: block;
        }

        .timeline-cumulative {
            display: flex;
            justify-content: space-between;
            padding: 16px 24px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(72, 140, 154, 0.04) 100%);
            border-top: 1px solid #e9ecef;
        }

        .cumulative-item {
            text-align: center;
        }

        .cumulative-label {
            font-size: 0.75em;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cumulative-value {
            font-size: 1.1em;
            font-weight: 700;
            color: #488C9A;
        }

        /* ==================== COMPARISON PANEL ==================== */
        .comparison-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 4px 24px rgba(41, 62, 76, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            overflow: hidden;
            margin-top: 24px;
        }

        .comparison-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .comparison-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
        }

        .comparison-title svg {
            color: #488C9A;
        }

        .comparison-body {
            padding: 20px;
        }

        .comparison-table {
            width: 100%;
            border-collapse: collapse;
        }

        .comparison-table th,
        .comparison-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .comparison-table th {
            font-size: 0.8em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
            background: #f8f9fa;
        }

        .comparison-table td {
            font-size: 0.95em;
        }

        .comparison-table tr:last-child td {
            border-bottom: none;
            font-weight: 600;
            background: rgba(72, 140, 154, 0.05);
        }

        .variance-positive {
            color: #28a745;
        }

        .variance-negative {
            color: #dc3545;
        }

        .progress-indicator {
            padding: 16px 20px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(72, 140, 154, 0.04) 100%);
            border-top: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .progress-bar-container {
            flex: 1;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #488C9A 0%, #28a745 100%);
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .progress-text {
            font-size: 0.9em;
            color: #495057;
            white-space: nowrap;
        }

        /* ==================== TEMPLATE SELECTOR ==================== */
        .template-selector {
            background: linear-gradient(135deg, rgba(224, 127, 58, 0.08) 0%, rgba(224, 127, 58, 0.04) 100%);
            border: 1px dashed #E07F3A;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .template-info {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #c76a2e;
        }

        .template-info svg {
            flex-shrink: 0;
        }

        .template-actions {
            display: flex;
            gap: 10px;
        }

        .template-dropdown {
            padding: 10px 16px;
            border: 2px solid #E07F3A;
            border-radius: 10px;
            font-size: 0.95em;
            font-family: 'Poppins', sans-serif;
            background: white;
            color: #293E4C;
            cursor: pointer;
            min-width: 200px;
        }

        .template-dropdown:focus {
            outline: none;
            box-shadow: 0 0 0 4px rgba(224, 127, 58, 0.2);
        }

        /* ==================== AUTO-SAVE INDICATOR ==================== */
        .autosave-indicator {
            position: fixed;
            bottom: 24px;
            left: 24px;
            background: white;
            border-radius: 12px;
            padding: 12px 18px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9em;
            z-index: 100;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s ease;
        }

        .autosave-indicator.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .autosave-indicator.saving {
            color: #E07F3A;
        }

        .autosave-indicator.saved {
            color: #28a745;
        }

        .autosave-spinner {
            width: 16px;
            height: 16px;
            border: 2px solid #e9ecef;
            border-top-color: #E07F3A;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* ==================== TOOLTIPS ==================== */
        .tooltip-wrapper {
            position: relative;
            display: inline-flex;
        }

        .tooltip-trigger {
            cursor: help;
            color: #6c757d;
        }

        .tooltip-content {
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: #293E4C;
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.85em;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
            z-index: 100;
        }

        .tooltip-content::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #293E4C;
        }

        .tooltip-wrapper:hover .tooltip-content {
            opacity: 1;
            visibility: visible;
        }

        /* ==================== KEYBOARD SHORTCUT HINTS ==================== */
        .kbd {
            display: inline-block;
            padding: 2px 6px;
            font-size: 0.75em;
            font-family: monospace;
            background: #e9ecef;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            color: #495057;
            margin-left: 8px;
        }

        /* ==================== QUICK TOUR ==================== */
        .tour-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 4000;
            display: none;
        }

        .tour-overlay.active {
            display: block;
        }

        .tour-highlight {
            position: absolute;
            box-shadow: 0 0 0 4000px rgba(0, 0, 0, 0.7);
            border-radius: 8px;
            z-index: 4001;
        }

        .tour-tooltip {
            position: absolute;
            background: white;
            border-radius: 16px;
            padding: 20px;
            max-width: 320px;
            box-shadow: 0 12px 48px rgba(0, 0, 0, 0.2);
            z-index: 4002;
        }

        .tour-tooltip h4 {
            margin: 0 0 8px;
            color: #293E4C;
        }

        .tour-tooltip p {
            margin: 0 0 16px;
            color: #495057;
            font-size: 0.95em;
        }

        .tour-progress {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .tour-dots {
            display: flex;
            gap: 6px;
        }

        .tour-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #dee2e6;
        }

        .tour-dot.active {
            background: #488C9A;
        }

        .tour-buttons {
            display: flex;
            gap: 8px;
        }

        /* ==================== EMPTY PROJECTION STATE ==================== */
        .empty-projection {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.04) 0%, rgba(72, 140, 154, 0.08) 100%);
            border: 2px dashed rgba(72, 140, 154, 0.3);
            border-radius: 20px;
            padding: 60px 40px;
            text-align: center;
        }

        .empty-projection-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .empty-projection h3 {
            font-size: 1.5em;
            color: #293E4C;
            margin: 0 0 12px;
        }

        .empty-projection p {
            color: #6c757d;
            margin: 0 0 24px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .getting-started-steps {
            display: flex;
            justify-content: center;
            gap: 32px;
            margin-top: 32px;
            flex-wrap: wrap;
        }

        .getting-started-step {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #495057;
        }

        .step-number {
            width: 32px;
            height: 32px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #488C9A;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <main>
        <?php
            require_once 'components/breadcrumbs.php';
            echo slp_render_breadcrumbs([
                'current_label' => 'Delivery & Cost Planning',
                'project_id' => $project_id
            ]);
        ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="3" width="15" height="13"/>
                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                            <circle cx="5.5" cy="18.5" r="2.5"/>
                            <circle cx="18.5" cy="18.5" r="2.5"/>
                        </svg>
                    </div>
                    <div class="header-info">
                        <h1>Delivery & Cost Planning</h1>
                        <p class="header-subtitle">
                            <a href="view_project.php?id=<?php echo $project_id; ?>"><?php echo htmlspecialchars($project['project_name']); ?></a>
                            <span style="color: #dee2e6;">|</span>
                            <?php echo htmlspecialchars($project['account_name']); ?>
                        </p>
                    </div>
                </div>
                <div class="header-stats">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo number_format($project_summary['mw'], 2); ?></div>
                        <div class="stat-label">Total MW</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo number_format($project_summary['modules']); ?></div>
                        <div class="stat-label">Modules</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo number_format($project_summary['pallets'], 0); ?></div>
                        <div class="stat-label">Pallets</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo number_format($project_summary['trucks'], 0); ?></div>
                        <div class="stat-label">Trucks</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$can_edit): ?>
        <div class="readonly-banner">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <div>
                <strong>View Only Mode</strong>
                You can view delivery projections, but only administrators can create or modify them.
            </div>
        </div>
        <?php endif; ?>

        <!-- Projection Header Component -->
        <?php
            include 'components/projection_header.php';
        ?>

        <?php if ($can_edit && !$current_projection): ?>
        <!-- Empty State - No Projection Yet -->
        <div class="empty-projection">
            <div class="empty-projection-icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="3" width="15" height="13"/>
                    <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                    <circle cx="5.5" cy="18.5" r="2.5"/>
                    <circle cx="18.5" cy="18.5" r="2.5"/>
                </svg>
            </div>
            <h3>Plan Your Delivery Journey</h3>
            <p>Create a projection to plan the complete delivery route from manufacturer to jobsite, including warehouse stops, shipping legs, and cost tracking.</p>
            <button type="button" class="btn btn-primary" onclick="createNewProjection()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Create New Projection
            </button>
            <div class="getting-started-steps">
                <div class="getting-started-step">
                    <span class="step-number">1</span>
                    <span>Add module batches</span>
                </div>
                <div class="getting-started-step">
                    <span class="step-number">2</span>
                    <span>Plan warehouse stops</span>
                </div>
                <div class="getting-started-step">
                    <span class="step-number">3</span>
                    <span>Configure shipping legs</span>
                </div>
                <div class="getting-started-step">
                    <span class="step-number">4</span>
                    <span>Review costs & milestones</span>
                </div>
            </div>
        </div>
        <?php else: ?>

        <!-- Template Selector (for new projections) -->
        <?php if ($can_edit && !empty($templates) && empty($current_projection['stops'])): ?>
        <div class="template-selector">
            <div class="template-info">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <line x1="3" y1="9" x2="21" y2="9"/>
                    <line x1="9" y1="21" x2="9" y2="9"/>
                </svg>
                <div>
                    <strong>Quick Start from Template</strong>
                    <span style="display: block; font-size: 0.9em; opacity: 0.8;">Load a saved template to pre-fill stops and legs</span>
                </div>
            </div>
            <div class="template-actions">
                <select class="template-dropdown" id="templateSelector">
                    <option value="">Select a template...</option>
                    <?php foreach ($templates as $template): ?>
                    <option value="<?php echo $template['id']; ?>">
                        <?php echo htmlspecialchars($template['template_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-orange" onclick="loadFromTemplate()">
                    Load Template
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Route Map -->
        <div class="route-map-card">
            <div class="route-map-header">
                <h3 class="route-map-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
                        <line x1="8" y1="2" x2="8" y2="18"/>
                        <line x1="16" y1="6" x2="16" y2="22"/>
                    </svg>
                    Route Map
                </h3>
                <button type="button" class="btn btn-sm btn-secondary" onclick="toggleMapFullscreen()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 3 21 3 21 9"/>
                        <polyline points="9 21 3 21 3 15"/>
                        <line x1="21" y1="3" x2="14" y2="10"/>
                        <line x1="3" y1="21" x2="10" y2="14"/>
                    </svg>
                </button>
            </div>
            <div class="route-map-container">
                <div id="routeMap"></div>
                <div class="map-placeholder" id="mapPlaceholder">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
                        <line x1="8" y1="2" x2="8" y2="18"/>
                        <line x1="16" y1="6" x2="16" y2="22"/>
                    </svg>
                    <p>Add stops to see the route</p>
                </div>
            </div>
            <div class="map-legend">
                <div class="legend-item">
                    <span class="legend-dot origin"></span>
                    <span>Origin (Manufacturer)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot warehouse"></span>
                    <span>Warehouse/Port</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot destination"></span>
                    <span>Destination (Jobsite)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-line"></span>
                    <span>Shipping Route</span>
                </div>
            </div>
        </div>

        <!-- Main Content Layout -->
        <div class="planner-layout">
            <div class="planner-main">
                <!-- Module Selector Component -->
                <?php include 'components/projection_module_selector.php'; ?>

                <!-- Journey Planner Component -->
                <?php include 'components/projection_journey_planner.php'; ?>
            </div>

            <div class="planner-sidebar">
                <!-- Cost Summary Component -->
                <?php include 'components/projection_cost_summary.php'; ?>

                <!-- Quick Actions Card -->
                <?php if ($can_edit): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                            </svg>
                            Quick Actions
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="btn-group" style="flex-direction: column;">
                            <button type="button" class="btn btn-primary" onclick="saveProjection()">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                    <polyline points="17 21 17 13 7 13 7 21"/>
                                    <polyline points="7 3 7 8 15 8"/>
                                </svg>
                                Save Projection
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="recalculateCosts()">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="23 4 23 10 17 10"/>
                                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                                </svg>
                                Recalculate Costs
                            </button>
                            <button type="button" class="btn btn-orange" onclick="openAddStopModal()">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19"/>
                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                </svg>
                                Add Warehouse Stop
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Projected Timeline -->
        <div class="timeline-card">
            <div class="timeline-header">
                <h3 class="timeline-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Projected Timeline & Costs
                </h3>
            </div>
            <div class="timeline-container">
                <div class="timeline-chart" id="timelineChart">
                    <!-- Timeline bars generated by JavaScript -->
                </div>
            </div>
            <div class="timeline-cumulative">
                <div class="cumulative-item">
                    <div class="cumulative-label">Total Freight</div>
                    <div class="cumulative-value" id="totalFreightDisplay">$0</div>
                </div>
                <div class="cumulative-item">
                    <div class="cumulative-label">Total Warehousing</div>
                    <div class="cumulative-value" id="totalWarehousingDisplay">$0</div>
                </div>
                <div class="cumulative-item">
                    <div class="cumulative-label">Total Milestones</div>
                    <div class="cumulative-value" id="totalMilestonesDisplay">$0</div>
                </div>
                <div class="cumulative-item">
                    <div class="cumulative-label">Grand Total</div>
                    <div class="cumulative-value" id="grandTotalDisplay" style="color: #E07F3A; font-size: 1.3em;">$0</div>
                </div>
            </div>
        </div>

        <!-- Actual vs Projected Comparison -->
        <?php if ($current_projection && isset($current_projection['actual_deliveries']) && count($current_projection['actual_deliveries']) > 0): ?>
        <div class="comparison-card">
            <div class="comparison-header">
                <h3 class="comparison-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="20" x2="18" y2="10"/>
                        <line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                    Actual vs Projected
                </h3>
            </div>
            <div class="comparison-body">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Projected</th>
                            <th>Actual</th>
                            <th>Variance</th>
                        </tr>
                    </thead>
                    <tbody id="comparisonTableBody">
                        <!-- Generated by JavaScript -->
                    </tbody>
                </table>
            </div>
            <div class="progress-indicator">
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" id="deliveryProgressBar" style="width: 0%;"></div>
                </div>
                <div class="progress-text" id="deliveryProgressText">0 of 0 deliveries completed</div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // End of projection exists else block ?>

        <!-- Auto-save Indicator -->
        <div class="autosave-indicator" id="autosaveIndicator">
            <div class="autosave-spinner"></div>
            <span>Saving...</span>
        </div>
    </main>

    <!-- Stop Editor Modal -->
    <div id="stopEditorModal" class="modal">
        <div class="modal-backdrop" onclick="closeStopEditorModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="stopModalTitle">Add Warehouse Stop</h3>
                <button type="button" class="modal-close" onclick="closeStopEditorModal()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editStopId" value="">

                <div class="form-group">
                    <label class="form-label">Stop Type</label>
                    <select id="stopType" class="form-input">
                        <option value="warehouse">Warehouse</option>
                        <option value="port">Port</option>
                        <option value="customs">Customs Facility</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Location Name</label>
                    <input type="text" id="stopName" class="form-input" placeholder="e.g., LA Port Warehouse">
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" id="stopAddress" class="form-input" placeholder="Full address">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Estimated Arrival</label>
                        <input type="text" id="stopArrival" class="form-input flatpickr-date" placeholder="Select date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estimated Departure</label>
                        <input type="text" id="stopDeparture" class="form-input flatpickr-date" placeholder="Select date">
                    </div>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" id="stopIsCustoms" style="width: 20px; height: 20px; accent-color: #488C9A;">
                        <span>Customs Clearance Point</span>
                    </label>
                    <p class="form-help">Check if customs clearance happens at this stop (triggers Customs Cleared milestone)</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea id="stopNotes" class="form-input" rows="2" placeholder="Optional notes about this stop"></textarea>
                </div>

                <!-- Fees Section -->
                <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e9ecef;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h4 style="margin: 0; color: #293E4C;">Fees at This Stop</h4>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="addFeeRow()">+ Add Fee</button>
                    </div>
                    <div id="feesContainer">
                        <!-- Fee rows added dynamically -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStopEditorModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveStop()">Save Stop</button>
            </div>
        </div>
    </div>

    <!-- Leg Editor Modal -->
    <div id="legEditorModal" class="modal">
        <div class="modal-backdrop" onclick="closeLegEditorModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="legModalTitle">Edit Shipping Leg</h3>
                <button type="button" class="modal-close" onclick="closeLegEditorModal()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editLegId" value="">
                <input type="hidden" id="legFromStopId" value="">
                <input type="hidden" id="legToStopId" value="">

                <div class="form-group">
                    <label class="form-label">Transport Mode</label>
                    <select id="legTransportMode" class="form-input">
                        <option value="truck">Truck</option>
                        <option value="ocean">Ocean Freight</option>
                        <option value="rail">Rail</option>
                        <option value="air">Air</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="text" id="legStartDate" class="form-input flatpickr-date" placeholder="Select date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="text" id="legEndDate" class="form-input flatpickr-date" placeholder="Select date">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Delivery Rate</label>
                        <input type="number" id="legDeliveryRate" class="form-input" placeholder="e.g., 4" step="0.1" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rate Unit</label>
                        <select id="legRateUnit" class="form-input">
                            <option value="per_week">Per Week</option>
                            <option value="per_day">Per Day</option>
                            <option value="per_month">Per Month</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Trucks Required</label>
                    <input type="number" id="legTrucksRequired" class="form-input" placeholder="Auto-calculated or manual" min="1">
                    <p class="form-help">Leave blank to auto-calculate from pallet count</p>
                </div>

                <div style="padding: 16px; background: #f8f9fa; border-radius: 12px; margin: 20px 0;">
                    <h4 style="margin: 0 0 12px; font-size: 0.95em; color: #293E4C;">Freight Costs</h4>
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Cost Per Truck</label>
                            <input type="number" id="legFreightCost" class="form-input" placeholder="$0.00" step="0.01" min="0" onchange="calculateLegTotal()">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Accessorial Per Truck</label>
                            <input type="number" id="legAccessorialCost" class="form-input" placeholder="$0.00" step="0.01" min="0" onchange="calculateLegTotal()">
                        </div>
                    </div>
                    <div style="margin-top: 16px; padding-top: 12px; border-top: 1px dashed #dee2e6; display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 600; color: #293E4C;">Total Freight Cost</span>
                        <span id="legTotalDisplay" style="font-size: 1.3em; font-weight: 700; color: #488C9A;">$0.00</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Triggers Milestone</label>
                    <select id="legTriggersMilestone" class="form-input">
                        <option value="">None</option>
                        <option value="shipping">Shipping</option>
                        <option value="customs_cleared">Customs Cleared</option>
                        <option value="project_delivery">Project Delivery</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea id="legNotes" class="form-input" rows="2" placeholder="Optional notes about this leg"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeLegEditorModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveLeg()">Save Leg</button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading...</div>
    </div>

    <script>
        // ==================== GLOBAL STATE ====================
        const projectId = <?php echo $project_id; ?>;
        const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;

        let currentProjection = <?php echo $current_projection ? json_encode($current_projection) : 'null'; ?>;
        let projections = <?php echo json_encode($projections); ?>;
        let availableBatches = <?php echo json_encode($available_batches); ?>;

        // Working state (modified by user before save)
        let workingState = {
            projectionId: currentProjection?.id || null,
            projectionName: currentProjection?.projection_name || 'Default Projection',
            status: currentProjection?.status || 'draft',
            notes: currentProjection?.notes || '',
            isPrimary: currentProjection?.is_primary || false,
            moduleAllocations: <?php echo json_encode($allocated_modules); ?> || [],
            stops: <?php echo json_encode($stops); ?> || [],
            legs: <?php echo json_encode($legs); ?> || []
        };

        // Flatpickr instances
        let datePickerInstances = [];

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            initializeDatePickers();
            updateUIFromState();
        });

        function initializeDatePickers() {
            // Clean up existing instances
            datePickerInstances.forEach(fp => fp.destroy());
            datePickerInstances = [];

            // Initialize new pickers
            document.querySelectorAll('.flatpickr-date').forEach(input => {
                const fp = flatpickr(input, {
                    dateFormat: 'Y-m-d',
                    allowInput: true
                });
                datePickerInstances.push(fp);
            });
        }

        function updateUIFromState() {
            // Update projection selector
            const selector = document.getElementById('projectionSelector');
            if (selector && workingState.projectionId) {
                selector.value = workingState.projectionId;
            }
        }

        // ==================== PROJECTION MANAGEMENT ====================
        function loadProjection(projectionId) {
            if (projectionId === 'new') {
                createNewProjection();
                return;
            }

            showLoading('Loading projection...');

            fetch(`api/projection_load.php?projection_id=${projectionId}`)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        currentProjection = data.projection;
                        workingState = {
                            projectionId: data.projection.id,
                            projectionName: data.projection.projection_name,
                            status: data.projection.status,
                            notes: data.projection.notes || '',
                            isPrimary: data.projection.is_primary,
                            moduleAllocations: data.projection.module_allocations || [],
                            stops: data.projection.stops || [],
                            legs: data.projection.legs || []
                        };
                        availableBatches = data.available_batches || [];
                        // Reload page to update components
                        window.location.href = `anticipated_deliveries.php?project_id=${projectId}&projection_id=${projectionId}`;
                    } else {
                        showToast('Failed to load projection: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    hideLoading();
                    showToast('Error loading projection', 'error');
                    console.error(error);
                });
        }

        function createNewProjection() {
            if (!canEdit) {
                showToast('You do not have permission to create projections', 'error');
                return;
            }

            const name = prompt('Enter a name for the new projection:', 'New Projection');
            if (!name) return;

            showLoading('Creating projection...');

            fetch('api/projection_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: projectId,
                    projection_name: name,
                    is_primary: projections.length === 0
                })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showToast('Projection created successfully', 'success');
                    window.location.href = `anticipated_deliveries.php?project_id=${projectId}&projection_id=${data.projection_id}`;
                } else {
                    showToast('Failed to create projection: ' + data.error, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Error creating projection', 'error');
                console.error(error);
            });
        }

        function saveProjection() {
            if (!canEdit) {
                showToast('You do not have permission to save', 'error');
                return;
            }

            if (!workingState.projectionId) {
                showToast('Please create a projection first', 'error');
                return;
            }

            showLoading('Saving projection...');

            const payload = {
                project_id: projectId,
                projection_id: workingState.projectionId,
                projection_name: workingState.projectionName,
                status: workingState.status,
                notes: workingState.notes,
                is_primary: workingState.isPrimary,
                module_allocations: workingState.moduleAllocations.map(a => ({
                    module_id: a.module_id,
                    wattage: a.wattage,
                    quantity: a.quantity,
                    pallets: a.pallets
                })),
                stops: workingState.stops.map((s, i) => ({
                    id: s.id,
                    stop_type: s.stop_type,
                    location_name: s.location_name,
                    location_address: s.location_address,
                    latitude: s.latitude,
                    longitude: s.longitude,
                    warehouse_id: s.warehouse_id,
                    is_customs_clearance: s.is_customs_clearance ? 1 : 0,
                    estimated_arrival_date: s.estimated_arrival_date,
                    estimated_departure_date: s.estimated_departure_date,
                    notes: s.notes,
                    fees: s.fees || []
                })),
                legs: workingState.legs.map(l => ({
                    id: l.id,
                    from_stop_id: l.from_stop_id,
                    to_stop_id: l.to_stop_id,
                    transport_mode: l.transport_mode,
                    start_date: l.start_date,
                    end_date: l.end_date,
                    delivery_rate: l.delivery_rate,
                    delivery_rate_unit: l.delivery_rate_unit,
                    trucks_required: l.trucks_required,
                    freight_cost_per_truck: l.freight_cost_per_truck,
                    accessorial_cost_per_truck: l.accessorial_cost_per_truck,
                    total_freight_cost: l.total_freight_cost,
                    triggers_milestone: l.triggers_milestone,
                    notes: l.notes
                }))
            };

            fetch('api/projection_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showToast('Projection saved successfully', 'success');
                    // Refresh to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast('Failed to save: ' + data.error, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Error saving projection', 'error');
                console.error(error);
            });
        }

        function deleteProjection() {
            if (!canEdit || !workingState.projectionId) return;

            if (!confirm('Are you sure you want to delete this projection? This cannot be undone.')) {
                return;
            }

            showLoading('Deleting projection...');

            fetch('api/projection_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ projection_id: workingState.projectionId })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showToast('Projection deleted', 'success');
                    window.location.href = `anticipated_deliveries.php?project_id=${projectId}`;
                } else {
                    showToast('Failed to delete: ' + data.error, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Error deleting projection', 'error');
            });
        }

        function editProjectionName() {
            const newName = prompt('Enter new projection name:', workingState.projectionName);
            if (newName && newName !== workingState.projectionName) {
                workingState.projectionName = newName;
                // Update selector display
                const selector = document.getElementById('projectionSelector');
                if (selector) {
                    const option = selector.querySelector(`option[value="${workingState.projectionId}"]`);
                    if (option) {
                        option.textContent = newName + (workingState.isPrimary ? ' (Primary)' : '');
                    }
                }
                showToast('Name updated. Remember to save!', 'info');
            }
        }

        function setAsPrimary() {
            workingState.isPrimary = true;
            saveProjection();
        }

        function saveAsTemplate() {
            const templateName = prompt('Enter template name:', workingState.projectionName + ' Template');
            if (!templateName) return;

            showLoading('Saving as template...');

            fetch('api/projection_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ...workingState,
                    project_id: projectId,
                    projection_id: workingState.projectionId,
                    is_template: true,
                    template_name: templateName
                })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showToast('Saved as template', 'success');
                } else {
                    showToast('Failed to save template: ' + data.error, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Error saving template', 'error');
            });
        }

        // ==================== MODULE ALLOCATION ====================
        function addModuleAllocation(batchId, wattage, quantity) {
            const batch = availableBatches.find(b => b.id == batchId);
            if (!batch) {
                showToast('Module batch not found', 'error');
                return;
            }

            // Calculate pallets
            const modulesPerPallet = batch.modules_per_pallet || 30;
            const pallets = Math.ceil(quantity / modulesPerPallet);

            // Calculate contract value
            const totalWatts = wattage * quantity;
            const contractValue = batch.cost_per_watt ? (batch.cost_per_watt * totalWatts) : 0;

            const allocation = {
                module_id: batchId,
                wattage: parseInt(wattage),
                quantity: parseInt(quantity),
                pallets: pallets,
                vendor_name: batch.vendor_name,
                manufacturer_name: batch.manufacturer_name,
                contract_value: contractValue,
                has_milestones: batch.has_milestones,
                milestones: batch.milestones || []
            };

            workingState.moduleAllocations.push(allocation);
            showToast('Module batch added. Remember to save!', 'success');

            // Refresh page to update UI
            saveProjection();
        }

        function removeAllocation(allocationId) {
            workingState.moduleAllocations = workingState.moduleAllocations.filter(a => a.id != allocationId);
            showToast('Module removed. Remember to save!', 'info');
            saveProjection();
        }

        // ==================== STOP MANAGEMENT ====================
        function openAddStopModal() {
            document.getElementById('stopModalTitle').textContent = 'Add Warehouse Stop';
            document.getElementById('editStopId').value = '';
            document.getElementById('stopType').value = 'warehouse';
            document.getElementById('stopName').value = '';
            document.getElementById('stopAddress').value = '';
            document.getElementById('stopArrival').value = '';
            document.getElementById('stopDeparture').value = '';
            document.getElementById('stopIsCustoms').checked = false;
            document.getElementById('stopNotes').value = '';
            document.getElementById('feesContainer').innerHTML = '';

            // Add one empty fee row
            addFeeRow();

            document.getElementById('stopEditorModal').classList.add('active');
            initializeDatePickers();
        }

        function openStopEditorModal(stopId) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop) {
                showToast('Stop not found', 'error');
                return;
            }

            document.getElementById('stopModalTitle').textContent = 'Edit Stop';
            document.getElementById('editStopId').value = stopId;
            document.getElementById('stopType').value = stop.stop_type || 'warehouse';
            document.getElementById('stopName').value = stop.location_name || '';
            document.getElementById('stopAddress').value = stop.location_address || '';
            document.getElementById('stopArrival').value = stop.estimated_arrival_date || '';
            document.getElementById('stopDeparture').value = stop.estimated_departure_date || '';
            document.getElementById('stopIsCustoms').checked = stop.is_customs_clearance == 1;
            document.getElementById('stopNotes').value = stop.notes || '';

            // Populate fees
            document.getElementById('feesContainer').innerHTML = '';
            if (stop.fees && stop.fees.length > 0) {
                stop.fees.forEach(fee => addFeeRow(fee));
            } else {
                addFeeRow();
            }

            document.getElementById('stopEditorModal').classList.add('active');
            initializeDatePickers();
        }

        function closeStopEditorModal() {
            document.getElementById('stopEditorModal').classList.remove('active');
        }

        function addFeeRow(feeData = null) {
            const container = document.getElementById('feesContainer');
            const rowId = 'fee_' + Date.now();

            const html = `
                <div class="fee-row" id="${rowId}" style="display: grid; grid-template-columns: 1fr 1fr 100px 100px 40px; gap: 10px; margin-bottom: 12px; align-items: end;">
                    <div>
                        <label class="form-label" style="font-size: 0.8em;">Fee Type</label>
                        <select class="form-input fee-type" style="padding: 10px;">
                            <option value="receiving" ${feeData?.fee_type === 'receiving' ? 'selected' : ''}>Receiving</option>
                            <option value="storage" ${feeData?.fee_type === 'storage' ? 'selected' : ''}>Storage</option>
                            <option value="outbound" ${feeData?.fee_type === 'outbound' ? 'selected' : ''}>Outbound</option>
                            <option value="customs" ${feeData?.fee_type === 'customs' ? 'selected' : ''}>Customs</option>
                            <option value="handling" ${feeData?.fee_type === 'handling' ? 'selected' : ''}>Handling</option>
                            <option value="other" ${feeData?.fee_type === 'other' ? 'selected' : ''}>Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8em;">Description</label>
                        <input type="text" class="form-input fee-name" value="${feeData?.fee_name || ''}" placeholder="Fee name" style="padding: 10px;">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8em;">Rate</label>
                        <input type="number" class="form-input fee-rate" value="${feeData?.rate || ''}" placeholder="$0" step="0.01" style="padding: 10px;">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8em;">Per</label>
                        <select class="form-input fee-unit" style="padding: 10px;">
                            <option value="per_pallet" ${feeData?.rate_unit === 'per_pallet' ? 'selected' : ''}>Pallet</option>
                            <option value="per_module" ${feeData?.rate_unit === 'per_module' ? 'selected' : ''}>Module</option>
                            <option value="per_truck" ${feeData?.rate_unit === 'per_truck' ? 'selected' : ''}>Truck</option>
                            <option value="flat" ${feeData?.rate_unit === 'flat' ? 'selected' : ''}>Flat</option>
                        </select>
                    </div>
                    <button type="button" onclick="document.getElementById('${rowId}').remove()" style="padding: 10px; background: #f8d7da; border: none; border-radius: 8px; cursor: pointer; color: #dc3545;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            `;

            container.insertAdjacentHTML('beforeend', html);
        }

        function saveStop() {
            const stopId = document.getElementById('editStopId').value;
            const stopData = {
                stop_type: document.getElementById('stopType').value,
                location_name: document.getElementById('stopName').value,
                location_address: document.getElementById('stopAddress').value,
                estimated_arrival_date: document.getElementById('stopArrival').value || null,
                estimated_departure_date: document.getElementById('stopDeparture').value || null,
                is_customs_clearance: document.getElementById('stopIsCustoms').checked ? 1 : 0,
                notes: document.getElementById('stopNotes').value,
                fees: []
            };

            // Collect fees
            document.querySelectorAll('.fee-row').forEach(row => {
                const feeType = row.querySelector('.fee-type').value;
                const feeName = row.querySelector('.fee-name').value;
                const rate = parseFloat(row.querySelector('.fee-rate').value) || 0;
                const rateUnit = row.querySelector('.fee-unit').value;

                if (feeName && rate > 0) {
                    stopData.fees.push({
                        fee_type: feeType,
                        fee_name: feeName,
                        rate: rate,
                        rate_unit: rateUnit,
                        estimated_cost: rate * (getTotalPallets() || 1) // Simple estimate
                    });
                }
            });

            if (!stopData.location_name) {
                showToast('Please enter a location name', 'error');
                return;
            }

            if (stopId) {
                // Update existing stop
                const stopIndex = workingState.stops.findIndex(s => s.id == stopId);
                if (stopIndex >= 0) {
                    stopData.id = stopId;
                    workingState.stops[stopIndex] = { ...workingState.stops[stopIndex], ...stopData };
                }
            } else {
                // Add new stop
                stopData.id = 'new_' + Date.now();
                // Insert before destination
                const destIndex = workingState.stops.findIndex(s => s.stop_type === 'destination');
                if (destIndex >= 0) {
                    workingState.stops.splice(destIndex, 0, stopData);
                } else {
                    workingState.stops.push(stopData);
                }
            }

            closeStopEditorModal();
            showToast('Stop saved. Remember to save projection!', 'success');
            saveProjection();
        }

        function removeStop(stopId) {
            workingState.stops = workingState.stops.filter(s => s.id != stopId);
            // Also remove associated legs
            workingState.legs = workingState.legs.filter(l => l.from_stop_id != stopId && l.to_stop_id != stopId);
            showToast('Stop removed. Remember to save!', 'info');
            saveProjection();
        }

        // ==================== LEG MANAGEMENT ====================
        function openLegEditorModal(legId, fromStopId, toStopId) {
            const leg = legId ? workingState.legs.find(l => l.id == legId) : null;

            // Determine title and get stop names for context
            let title = leg ? 'Edit Shipping Leg' : 'Configure Shipping';
            const fromStop = fromStopId ? workingState.stops.find(s => s.id == fromStopId) : null;
            const toStop = toStopId ? workingState.stops.find(s => s.id == toStopId) : null;

            if (fromStop && toStop) {
                title = `Configure Shipping: ${fromStop.location_name} → ${toStop.location_name}`;
            }

            document.getElementById('legModalTitle').textContent = title;
            document.getElementById('editLegId').value = legId || '';
            document.getElementById('legFromStopId').value = leg?.from_stop_id || fromStopId || '';
            document.getElementById('legToStopId').value = leg?.to_stop_id || toStopId || '';
            document.getElementById('legTransportMode').value = leg?.transport_mode || 'truck';
            document.getElementById('legStartDate').value = leg?.start_date || '';
            document.getElementById('legEndDate').value = leg?.end_date || '';
            document.getElementById('legDeliveryRate').value = leg?.delivery_rate || '';
            document.getElementById('legRateUnit').value = leg?.delivery_rate_unit || 'per_week';
            document.getElementById('legTrucksRequired').value = leg?.trucks_required || '';
            document.getElementById('legFreightCost').value = leg?.freight_cost_per_truck || '';
            document.getElementById('legAccessorialCost').value = leg?.accessorial_cost_per_truck || '';
            document.getElementById('legTriggersMilestone').value = leg?.triggers_milestone || '';
            document.getElementById('legNotes').value = leg?.notes || '';

            calculateLegTotal();

            document.getElementById('legEditorModal').classList.add('active');
            initializeDatePickers();
        }

        function closeLegEditorModal() {
            document.getElementById('legEditorModal').classList.remove('active');
        }

        function calculateLegTotal() {
            const trucks = parseInt(document.getElementById('legTrucksRequired').value) || getTotalTrucks();
            const freightCost = parseFloat(document.getElementById('legFreightCost').value) || 0;
            const accessorialCost = parseFloat(document.getElementById('legAccessorialCost').value) || 0;
            const total = trucks * (freightCost + accessorialCost);

            document.getElementById('legTotalDisplay').textContent = '$' + total.toLocaleString('en-US', { minimumFractionDigits: 2 });
        }

        function saveLeg() {
            const legId = document.getElementById('editLegId').value;
            const trucks = parseInt(document.getElementById('legTrucksRequired').value) || getTotalTrucks();
            const freightCost = parseFloat(document.getElementById('legFreightCost').value) || 0;
            const accessorialCost = parseFloat(document.getElementById('legAccessorialCost').value) || 0;

            const legData = {
                from_stop_id: document.getElementById('legFromStopId').value,
                to_stop_id: document.getElementById('legToStopId').value,
                transport_mode: document.getElementById('legTransportMode').value,
                start_date: document.getElementById('legStartDate').value || null,
                end_date: document.getElementById('legEndDate').value || null,
                delivery_rate: parseFloat(document.getElementById('legDeliveryRate').value) || null,
                delivery_rate_unit: document.getElementById('legRateUnit').value,
                trucks_required: trucks,
                freight_cost_per_truck: freightCost,
                accessorial_cost_per_truck: accessorialCost,
                total_freight_cost: trucks * (freightCost + accessorialCost),
                triggers_milestone: document.getElementById('legTriggersMilestone').value || null,
                notes: document.getElementById('legNotes').value
            };

            if (legId) {
                // Update existing leg
                const legIndex = workingState.legs.findIndex(l => l.id == legId);
                if (legIndex >= 0) {
                    legData.id = legId;
                    workingState.legs[legIndex] = { ...workingState.legs[legIndex], ...legData };
                }
            } else {
                // New leg
                legData.id = 'new_' + Date.now();
                workingState.legs.push(legData);
            }

            closeLegEditorModal();
            showToast('Leg saved. Remember to save projection!', 'success');
            saveProjection();
        }

        // ==================== UTILITY FUNCTIONS ====================
        function getTotalPallets() {
            return workingState.moduleAllocations.reduce((sum, a) => sum + (a.pallets || 0), 0);
        }

        function getTotalTrucks() {
            const pallets = getTotalPallets();
            // Assume 24 pallets per truck if not specified
            return Math.ceil(pallets / 24) || 1;
        }

        function recalculateCosts() {
            showToast('Costs will be recalculated when you save', 'info');
        }

        // ==================== UI HELPERS ====================
        function showLoading(text = 'Loading...') {
            document.querySelector('.loading-text').textContent = text;
            document.getElementById('loadingOverlay').classList.add('active');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('active');
        }

        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const iconSvg = type === 'success'
                ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>'
                : type === 'error'
                ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
                : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';

            toast.innerHTML = `
                <div class="toast-icon">${iconSvg}</div>
                <div class="toast-message">${message}</div>
            `;

            container.appendChild(toast);

            // Auto remove after 5 seconds
            setTimeout(() => {
                toast.style.animation = 'toastSlideIn 0.3s ease reverse';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStopEditorModal();
                closeLegEditorModal();
                closeModuleSelectorModal();
                closeWattageQuantityModal();
            }
        });

        // ==================== GOOGLE MAPS ====================
        let map = null;
        let mapMarkers = [];
        let mapPolylines = [];

        function initializeMap() {
            if (typeof google === 'undefined' || !google.maps) {
                console.log('Google Maps not available');
                return;
            }

            const mapContainer = document.getElementById('routeMap');
            if (!mapContainer) return;

            map = new google.maps.Map(mapContainer, {
                zoom: 2,
                center: { lat: 20, lng: 0 },
                mapTypeId: 'roadmap',
                styles: [
                    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#e9ecef' }] },
                    { featureType: 'landscape', elementType: 'geometry', stylers: [{ color: '#f8f9fa' }] },
                    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#ffffff' }] },
                    { featureType: 'poi', stylers: [{ visibility: 'off' }] }
                ],
                disableDefaultUI: true,
                zoomControl: true
            });

            updateMapFromState();
        }

        function updateMapFromState() {
            if (!map) return;

            // Clear existing markers and polylines
            mapMarkers.forEach(m => m.setMap(null));
            mapPolylines.forEach(p => p.setMap(null));
            mapMarkers = [];
            mapPolylines = [];

            const stops = workingState.stops || [];
            if (stops.length === 0) {
                document.getElementById('mapPlaceholder').style.display = 'block';
                return;
            }

            document.getElementById('mapPlaceholder').style.display = 'none';

            const bounds = new google.maps.LatLngBounds();
            const pathCoordinates = [];

            stops.forEach((stop, index) => {
                if (!stop.latitude || !stop.longitude) return;

                const position = { lat: parseFloat(stop.latitude), lng: parseFloat(stop.longitude) };
                pathCoordinates.push(position);
                bounds.extend(position);

                // Determine marker color
                let markerColor = '#E07F3A'; // Default warehouse
                if (stop.stop_type === 'origin') markerColor = '#28a745';
                else if (stop.stop_type === 'destination') markerColor = '#dc3545';

                const marker = new google.maps.Marker({
                    position: position,
                    map: map,
                    title: stop.location_name,
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 12,
                        fillColor: markerColor,
                        fillOpacity: 1,
                        strokeColor: '#ffffff',
                        strokeWeight: 3
                    }
                });

                // Info window
                const infoWindow = new google.maps.InfoWindow({
                    content: `
                        <div style="padding: 8px; font-family: Poppins, sans-serif;">
                            <strong style="color: #293E4C;">${stop.location_name}</strong>
                            <div style="color: #6c757d; font-size: 0.9em;">${stop.location_address || ''}</div>
                            ${stop.estimated_arrival_date ? `<div style="margin-top: 4px; color: #488C9A;">Arrival: ${stop.estimated_arrival_date}</div>` : ''}
                        </div>
                    `
                });

                marker.addListener('click', () => {
                    infoWindow.open(map, marker);
                });

                mapMarkers.push(marker);
            });

            // Draw route polyline
            if (pathCoordinates.length > 1) {
                const routePath = new google.maps.Polyline({
                    path: pathCoordinates,
                    geodesic: true,
                    strokeColor: '#488C9A',
                    strokeOpacity: 0.8,
                    strokeWeight: 4
                });
                routePath.setMap(map);
                mapPolylines.push(routePath);
            }

            // Fit bounds
            if (pathCoordinates.length > 0) {
                map.fitBounds(bounds);
                if (pathCoordinates.length === 1) {
                    map.setZoom(10);
                }
            }
        }

        function toggleMapFullscreen() {
            const mapCard = document.querySelector('.route-map-card');
            const mapContainer = document.querySelector('.route-map-container');

            if (mapCard.classList.contains('fullscreen')) {
                mapCard.classList.remove('fullscreen');
                mapCard.style.cssText = '';
                mapContainer.style.height = '300px';
            } else {
                mapCard.classList.add('fullscreen');
                mapCard.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 5000; margin: 0; border-radius: 0;';
                mapContainer.style.height = 'calc(100vh - 120px)';
            }

            setTimeout(() => {
                if (map) {
                    google.maps.event.trigger(map, 'resize');
                    updateMapFromState();
                }
            }, 100);
        }

        // ==================== TIMELINE CHART ====================
        function updateTimelineChart() {
            const container = document.getElementById('timelineChart');
            if (!container) return;

            const events = [];
            let totalFreight = 0;
            let totalWarehousing = 0;
            let totalMilestones = 0;

            // Collect events from stops and legs
            workingState.stops.forEach(stop => {
                if (stop.estimated_arrival_date) {
                    const stopFees = (stop.fees || []).reduce((sum, f) => sum + (f.estimated_cost || 0), 0);
                    totalWarehousing += stopFees;

                    events.push({
                        date: stop.estimated_arrival_date,
                        label: stop.location_name,
                        type: 'warehousing',
                        amount: stopFees
                    });
                }
            });

            workingState.legs.forEach(leg => {
                if (leg.start_date) {
                    const legCost = leg.total_freight_cost || 0;
                    totalFreight += legCost;

                    events.push({
                        date: leg.start_date,
                        label: getTransportModeLabel(leg.transport_mode),
                        type: 'freight',
                        amount: legCost,
                        milestone: leg.triggers_milestone
                    });

                    // Add milestone if triggered
                    if (leg.triggers_milestone) {
                        const milestoneAmount = getMilestoneAmount(leg.triggers_milestone);
                        totalMilestones += milestoneAmount;
                    }
                }
            });

            // Sort by date
            events.sort((a, b) => new Date(a.date) - new Date(b.date));

            // Generate bars
            const maxAmount = Math.max(...events.map(e => e.amount), 1);

            container.innerHTML = events.map((event, i) => {
                const height = Math.max((event.amount / maxAmount) * 140, 20);
                const formattedDate = formatDate(event.date);
                const formattedAmount = '$' + (event.amount || 0).toLocaleString();

                return `
                    <div class="timeline-bar">
                        <div class="timeline-bar-fill ${event.type}" style="height: ${height}px;">
                            <span class="timeline-bar-value">${formattedAmount}</span>
                        </div>
                        <div class="timeline-bar-label">
                            <span class="timeline-bar-date">${formattedDate}</span>
                            ${event.label}
                            ${event.milestone ? `<br><span style="color: #28a745; font-size: 0.9em;">${getMilestoneLabel(event.milestone)}</span>` : ''}
                        </div>
                    </div>
                `;
            }).join('');

            // Update cumulative displays
            const grandTotal = totalFreight + totalWarehousing + totalMilestones;
            document.getElementById('totalFreightDisplay').textContent = '$' + totalFreight.toLocaleString();
            document.getElementById('totalWarehousingDisplay').textContent = '$' + totalWarehousing.toLocaleString();
            document.getElementById('totalMilestonesDisplay').textContent = '$' + totalMilestones.toLocaleString();
            document.getElementById('grandTotalDisplay').textContent = '$' + grandTotal.toLocaleString();
        }

        function getTransportModeLabel(mode) {
            const labels = {
                'truck': 'Truck',
                'ocean': 'Ocean',
                'rail': 'Rail',
                'air': 'Air'
            };
            return labels[mode] || mode;
        }

        function getMilestoneLabel(milestone) {
            const labels = {
                'shipping': 'Shipping',
                'customs_cleared': 'Customs',
                'project_delivery': 'Delivery'
            };
            return labels[milestone] || milestone;
        }

        function getMilestoneAmount(milestone) {
            // Calculate from module allocations
            let totalContract = 0;
            workingState.moduleAllocations.forEach(alloc => {
                totalContract += alloc.contract_value || 0;
            });

            const percentages = {
                'shipping': 0.20,
                'customs_cleared': 0.20,
                'project_delivery': 0.30
            };

            return totalContract * (percentages[milestone] || 0);
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }

        // ==================== COMPARISON TABLE ====================
        function updateComparisonTable() {
            const tbody = document.getElementById('comparisonTableBody');
            if (!tbody) return;

            // Get projected and actual values
            const projected = {
                milestones: 0,
                freight: 0,
                warehousing: 0
            };

            const actual = {
                milestones: 0,
                freight: 0,
                warehousing: 0
            };

            // Calculate projected
            workingState.moduleAllocations.forEach(alloc => {
                projected.milestones += alloc.contract_value || 0;
            });

            workingState.legs.forEach(leg => {
                projected.freight += leg.total_freight_cost || 0;
            });

            workingState.stops.forEach(stop => {
                (stop.fees || []).forEach(fee => {
                    projected.warehousing += fee.estimated_cost || 0;
                });
            });

            // Calculate actual (would come from actual_deliveries data)
            if (currentProjection && currentProjection.actual_deliveries) {
                currentProjection.actual_deliveries.forEach(delivery => {
                    actual.milestones += delivery.milestone_amount || 0;
                    actual.freight += delivery.freight_cost || 0;
                    actual.warehousing += delivery.warehousing_cost || 0;
                });
            }

            const categories = [
                { label: 'Milestone Payments', projected: projected.milestones, actual: actual.milestones },
                { label: 'Freight Costs', projected: projected.freight, actual: actual.freight },
                { label: 'Warehousing', projected: projected.warehousing, actual: actual.warehousing }
            ];

            const totalProjected = projected.milestones + projected.freight + projected.warehousing;
            const totalActual = actual.milestones + actual.freight + actual.warehousing;

            tbody.innerHTML = categories.map(cat => {
                const variance = cat.projected > 0 ? ((cat.actual / cat.projected) * 100).toFixed(0) : 0;
                const varianceClass = cat.actual <= cat.projected ? 'variance-positive' : 'variance-negative';
                return `
                    <tr>
                        <td>${cat.label}</td>
                        <td>$${cat.projected.toLocaleString()}</td>
                        <td>$${cat.actual.toLocaleString()}</td>
                        <td class="${varianceClass}">${variance}%</td>
                    </tr>
                `;
            }).join('') + `
                <tr>
                    <td><strong>TOTAL</strong></td>
                    <td><strong>$${totalProjected.toLocaleString()}</strong></td>
                    <td><strong>$${totalActual.toLocaleString()}</strong></td>
                    <td class="${totalActual <= totalProjected ? 'variance-positive' : 'variance-negative'}">
                        <strong>${totalProjected > 0 ? ((totalActual / totalProjected) * 100).toFixed(0) : 0}%</strong>
                    </td>
                </tr>
            `;

            // Update progress bar
            if (currentProjection && currentProjection.actual_deliveries) {
                const completedDeliveries = currentProjection.actual_deliveries.length;
                const totalDeliveries = getTotalTrucks();
                const progress = totalDeliveries > 0 ? (completedDeliveries / totalDeliveries) * 100 : 0;

                document.getElementById('deliveryProgressBar').style.width = progress + '%';
                document.getElementById('deliveryProgressText').textContent =
                    `${completedDeliveries} of ${totalDeliveries} deliveries completed`;
            }
        }

        // ==================== TEMPLATE FUNCTIONS ====================
        function loadFromTemplate() {
            const selector = document.getElementById('templateSelector');
            if (!selector || !selector.value) {
                showToast('Please select a template first', 'error');
                return;
            }

            showLoading('Loading template...');

            fetch(`api/projection_load.php?projection_id=${selector.value}&as_template=1`)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success && data.projection) {
                        // Apply template data to working state
                        workingState.stops = data.projection.stops || [];
                        workingState.legs = data.projection.legs || [];

                        showToast('Template loaded! Configure your stops and save.', 'success');

                        // Refresh UI
                        updateMapFromState();
                        updateTimelineChart();
                    } else {
                        showToast('Failed to load template: ' + (data.error || 'Unknown error'), 'error');
                    }
                })
                .catch(error => {
                    hideLoading();
                    showToast('Error loading template', 'error');
                    console.error(error);
                });
        }

        // ==================== AUTO-SAVE ====================
        let autoSaveTimer = null;
        let hasUnsavedChanges = false;

        function markAsUnsaved() {
            hasUnsavedChanges = true;

            // Clear existing timer
            if (autoSaveTimer) {
                clearTimeout(autoSaveTimer);
            }

            // Set auto-save timer (30 seconds)
            autoSaveTimer = setTimeout(() => {
                if (hasUnsavedChanges && canEdit && workingState.projectionId) {
                    autoSave();
                }
            }, 30000);
        }

        function autoSave() {
            if (!canEdit || !workingState.projectionId) return;

            const indicator = document.getElementById('autosaveIndicator');
            indicator.classList.add('visible', 'saving');
            indicator.querySelector('span').textContent = 'Saving...';

            const payload = {
                project_id: projectId,
                projection_id: workingState.projectionId,
                projection_name: workingState.projectionName,
                status: workingState.status,
                notes: workingState.notes,
                is_primary: workingState.isPrimary,
                module_allocations: workingState.moduleAllocations.map(a => ({
                    module_id: a.module_id,
                    wattage: a.wattage,
                    quantity: a.quantity,
                    pallets: a.pallets
                })),
                stops: workingState.stops,
                legs: workingState.legs
            };

            fetch('api/projection_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    hasUnsavedChanges = false;
                    indicator.classList.remove('saving');
                    indicator.classList.add('saved');
                    indicator.querySelector('span').textContent = 'All changes saved';

                    setTimeout(() => {
                        indicator.classList.remove('visible', 'saved');
                    }, 2000);
                } else {
                    indicator.classList.remove('visible', 'saving');
                    showToast('Auto-save failed: ' + data.error, 'error');
                }
            })
            .catch(error => {
                indicator.classList.remove('visible', 'saving');
                console.error('Auto-save error:', error);
            });
        }

        // ==================== KEYBOARD SHORTCUTS ====================
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (canEdit) saveProjection();
            }

            // Ctrl+N for new projection
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                if (canEdit) createNewProjection();
            }

            // Ctrl+W for new warehouse stop
            if ((e.ctrlKey || e.metaKey) && e.key === 'w') {
                e.preventDefault();
                if (canEdit) openAddStopModal();
            }
        });

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            initializeDatePickers();
            updateUIFromState();

            // Initialize map after page load
            if (typeof google !== 'undefined' && google.maps) {
                initializeMap();
            }

            // Initialize timeline
            updateTimelineChart();

            // Initialize comparison table
            updateComparisonTable();

            // Warn before leaving with unsaved changes
            window.addEventListener('beforeunload', function(e) {
                if (hasUnsavedChanges) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        });

        // Re-initialize map when Google Maps loads
        if (typeof google === 'undefined') {
            window.initMap = initializeMap;
        }
    </script>
</body>
</html>
