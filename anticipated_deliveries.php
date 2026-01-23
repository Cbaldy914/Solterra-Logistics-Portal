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

// ========== AJAX HANDLERS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Handle PO Execution Date save
    if ($action === 'save_po_execution_date') {
        header('Content-Type: application/json');

        if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit();
        }

        $allocation_id = intval($_POST['allocation_id'] ?? 0);
        $po_execution_date = $_POST['po_execution_date'] ?? '';

        if (!$allocation_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid allocation ID']);
            exit();
        }

        // Validate date format
        $date_value = null;
        if (!empty($po_execution_date)) {
            $date = DateTime::createFromFormat('Y-m-d', $po_execution_date);
            if ($date) {
                $date_value = $date->format('Y-m-d');
            }
        }

        // Update the allocation with PO execution date
        $stmt = $conn->prepare("UPDATE projection_module_allocations SET po_execution_date = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $date_value, $allocation_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'PO execution date saved']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }

        $conn->close();
        exit();
    }
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
$total_modules = 0;
$total_contract_value = 0;
foreach ($allocated_modules as $alloc) {
    $total_pallets += $alloc['pallets'] ?? 0;
    $total_modules += $alloc['quantity'] ?? 0;
    $total_contract_value += $alloc['contract_value'] ?? 0;
}

// Get available templates
$templates = get_projection_templates($conn);

// Get project size summary for header stats
require_once 'anticipated_schedule_helpers.php';
$project_summary = getProjectSizeSummary($conn, $project_id);

// Manufacturer suggestions for manual entry
$manufacturer_names = [];
$manufacturer_locations = [];
$manufacturer_location_map = [];
if (!empty($project['account_id'])) {
    $stmt = $conn->prepare("
        SELECT vendor_name, initial_location
        FROM modules
        WHERE account_id = ?
          AND vendor_name IS NOT NULL
          AND vendor_name <> ''
    ");
    if ($stmt) {
        $stmt->bind_param("i", $project['account_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $name_set = [];
        $location_set = [];
        $location_map = [];
        while ($row = $result->fetch_assoc()) {
            $vendor = trim($row['vendor_name'] ?? '');
            $location = trim($row['initial_location'] ?? '');
            if ($vendor !== '') {
                $name_set[$vendor] = true;
            }
            if ($location !== '') {
                $location_set[$location] = true;
                if ($vendor !== '') {
                    if (!isset($location_map[$vendor])) {
                        $location_map[$vendor] = [];
                    }
                    $location_map[$vendor][$location] = true;
                }
            }
        }
        $stmt->close();

        $manufacturer_names = array_keys($name_set);
        sort($manufacturer_names, SORT_NATURAL | SORT_FLAG_CASE);

        $manufacturer_locations = array_keys($location_set);
        sort($manufacturer_locations, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($location_map as $vendor => $locations) {
            $locations_list = array_keys($locations);
            sort($locations_list, SORT_NATURAL | SORT_FLAG_CASE);
            $manufacturer_location_map[$vendor] = $locations_list;
        }
    }
}

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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php
    $google_maps_key = getenv('GOOGLE_MAPS_API_KEY') ?: '';
    if (!empty($google_maps_key)):
    ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo $google_maps_key; ?>&libraries=geometry,places"></script>
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
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .planner-main {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .module-cost-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 24px;
        }

        .planner-split {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.8fr);
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1200px) {
            .planner-split {
                grid-template-columns: 1fr;
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

        /* ==================== COLLAPSIBLE SECTIONS ==================== */
        .collapsible-section {
            margin-bottom: 24px;
        }

        .collapsible-header {
            background: white;
            border-radius: 18px;
            box-shadow: 0 4px 24px rgba(41, 62, 76, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
        }

        .collapsible-header:hover {
            border-color: rgba(72, 140, 154, 0.2);
            box-shadow: 0 6px 28px rgba(41, 62, 76, 0.1);
        }

        .collapsible-header.collapsed {
            border-radius: 18px;
        }

        .collapsible-header:not(.collapsed) {
            border-radius: 18px 18px 0 0;
            border-bottom: 1px solid #e9ecef;
        }

        .collapsible-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.1em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
        }

        .collapsible-title svg {
            color: #488C9A;
            flex-shrink: 0;
        }

        .collapsible-meta {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .collapsible-badge {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(58, 110, 127, 0.1) 100%);
            color: #488C9A;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .collapsible-toggle {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            transition: all 0.3s ease;
        }

        .collapsible-header:hover .collapsible-toggle {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(58, 110, 127, 0.1) 100%);
            border-color: rgba(72, 140, 154, 0.2);
            color: #488C9A;
        }

        .collapsible-toggle svg {
            transition: transform 0.3s ease;
        }

        .collapsible-header.collapsed .collapsible-toggle svg {
            transform: rotate(-90deg);
        }

        .collapsible-content {
            background: white;
            border: 1px solid rgba(72, 140, 154, 0.08);
            border-top: none;
            border-radius: 0 0 18px 18px;
            box-shadow: 0 4px 24px rgba(41, 62, 76, 0.06);
            overflow: hidden;
            max-height: 5000px;
            transition: max-height 0.5s ease, opacity 0.3s ease, padding 0.3s ease;
            opacity: 1;
        }

        .collapsible-content.collapsed {
            max-height: 0;
            opacity: 0;
            padding-top: 0;
            padding-bottom: 0;
            border: none;
        }

        .collapsible-inner {
            padding: 24px;
        }

        /* ==================== LOGISTICS PLAN ==================== */
        .logistics-plan .card-header {
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }

        .plan-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .plan-chip {
            background: #f1f5f7;
            border: 1px solid #dfe7ea;
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 0.75em;
            font-weight: 600;
            color: #425866;
        }

        .plan-intro {
            margin: 0 0 20px;
            color: #6c757d;
            font-size: 0.95em;
        }

        .delivery-plan {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .delivery-step {
            background: #f9fbfc;
            border: 1px solid #e3e7ea;
            border-radius: 16px;
            padding: 18px;
        }

        .delivery-step-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .delivery-step-title {
            font-weight: 600;
            color: #293E4C;
            font-size: 1.05em;
        }

        .delivery-badge {
            background: #ffffff;
            border: 1px solid #dce3e6;
            color: #6c757d;
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 0.7em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .delivery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .delivery-field label {
            font-size: 0.75em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6c757d;
            display: block;
            margin-bottom: 6px;
        }

        .delivery-input,
        .delivery-select,
        .delivery-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #dfe3e8;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95em;
            background: #fff;
        }

        .delivery-input[readonly] {
            background: #f0f4f6;
            color: #6c757d;
        }

        .delivery-location {
            background: #ffffff;
            border: 1px dashed #ccd5d9;
            border-radius: 12px;
            padding: 12px;
        }

        .delivery-location strong {
            display: block;
            color: #293E4C;
        }

        .delivery-location small {
            color: #6c757d;
        }

        .delivery-schedule,
        .delivery-fees {
            margin-top: 16px;
            background: #ffffff;
            border: 1px solid #e4e8eb;
            border-radius: 12px;
            padding: 16px;
        }

        .delivery-fees-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .delivery-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .delivery-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 10px;
        }

        .delivery-milestone {
            background: rgba(72, 140, 154, 0.12);
            color: #488C9A;
            padding: 6px 10px;
            border-radius: 12px;
            font-size: 0.7em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .delivery-plan-empty {
            display: none;
        }

        .fee-row {
            display: grid;
            grid-template-columns: 1fr 1fr 120px 120px 40px;
            gap: 10px;
            align-items: end;
            margin-bottom: 10px;
        }

        @media (max-width: 900px) {
            .fee-row {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* ==================== JOURNEY PLANNER VISUAL ==================== */
        .journey-planner {
            padding: 24px;
        }

        .journey-intro {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(58, 110, 127, 0.05) 100%);
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 24px;
            color: #495057;
            font-size: 0.95em;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .journey-intro svg {
            color: #488C9A;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .journey-flow {
            display: flex;
            flex-direction: column;
            gap: 0;
            position: relative;
        }

        .journey-node {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            position: relative;
        }

        .journey-node-indicator {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-shrink: 0;
            width: 50px;
        }

        .journey-node-dot {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.9em;
            z-index: 2;
            position: relative;
        }

        .journey-node-dot.origin {
            background: linear-gradient(135deg, #28a745 0%, #20883a 100%);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .journey-node-dot.warehouse {
            background: linear-gradient(135deg, #E07F3A 0%, #c76a2e 100%);
            box-shadow: 0 4px 12px rgba(224, 127, 58, 0.3);
        }

        .journey-node-dot.port {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
        }

        .journey-node-dot.destination {
            background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .journey-connector {
            width: 4px;
            flex-grow: 1;
            background: linear-gradient(180deg, #488C9A 0%, rgba(72, 140, 154, 0.3) 100%);
            min-height: 20px;
            margin: 4px 0;
        }

        .journey-node-content {
            flex: 1;
            padding-bottom: 20px;
        }

        .journey-node-card {
            background: white;
            border: 1px solid #e3e7ea;
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .journey-node-card:hover {
            border-color: #488C9A;
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.12);
        }

        .journey-node-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }

        .journey-node-title {
            font-weight: 600;
            color: #293E4C;
            font-size: 1.1em;
            margin: 0;
        }

        .journey-node-type {
            font-size: 0.8em;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        .journey-node-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .journey-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
            text-transform: uppercase;
        }

        .journey-badge.milestone {
            background: rgba(72, 140, 154, 0.12);
            color: #488C9A;
        }

        .journey-badge.customs {
            background: rgba(255, 193, 7, 0.15);
            color: #856404;
        }

        .journey-node-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .journey-leg-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f5f7 100%);
            border: 1px dashed #ccd5d9;
            border-radius: 14px;
            padding: 16px 20px;
            margin: -10px 0 10px 70px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .journey-leg-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: white;
            border: 1px solid #e3e7ea;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #488C9A;
        }

        .journey-leg-details {
            flex: 1;
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .journey-leg-field {
            min-width: 120px;
        }

        .journey-leg-field label {
            font-size: 0.7em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6c757d;
            display: block;
            margin-bottom: 4px;
        }

        .journey-leg-field .value {
            font-weight: 500;
            color: #293E4C;
        }

        .journey-add-stop {
            margin: 10px 0 10px 70px;
        }

        .journey-add-stop-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(72, 140, 154, 0.04) 100%);
            border: 2px dashed rgba(72, 140, 154, 0.3);
            border-radius: 12px;
            color: #488C9A;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            justify-content: center;
        }

        .journey-add-stop-btn:hover {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(72, 140, 154, 0.1) 100%);
            border-color: rgba(72, 140, 154, 0.5);
        }

        /* ==================== COLLAPSIBLE WAREHOUSE/PORT CARD ==================== */
        .warehouse-stop-card {
            background: white;
            border: 1px solid #e3e7ea;
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .warehouse-stop-card:hover {
            border-color: #488C9A;
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.12);
        }

        .warehouse-stop-header {
            padding: 16px 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            transition: background 0.2s ease;
        }

        .warehouse-stop-header:hover {
            background: #f8f9fa;
        }

        .warehouse-stop-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .warehouse-stop-title-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .warehouse-stop-title {
            font-weight: 600;
            color: #293E4C;
            font-size: 1.05em;
            margin: 0;
        }

        .warehouse-stop-type-badge {
            font-size: 0.7em;
            padding: 3px 10px;
            border-radius: 10px;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .warehouse-stop-type-badge.warehouse {
            background: rgba(224, 127, 58, 0.15);
            color: #c76a2e;
        }

        .warehouse-stop-type-badge.port {
            background: rgba(23, 162, 184, 0.15);
            color: #138496;
        }

        .warehouse-stop-type-badge.customs {
            background: rgba(111, 66, 193, 0.15);
            color: #5a33a1;
        }

        .warehouse-stop-summary {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 0.85em;
            color: #6c757d;
        }

        .warehouse-stop-summary-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .warehouse-stop-summary-item svg {
            width: 14px;
            height: 14px;
            color: #488C9A;
        }

        .warehouse-stop-summary-item strong {
            color: #293E4C;
        }

        .warehouse-stop-toggle {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #f1f5f7;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            transition: all 0.3s ease;
            cursor: pointer;
            flex-shrink: 0;
        }

        .warehouse-stop-toggle:hover {
            background: #488C9A;
            color: white;
        }

        .warehouse-stop-toggle svg {
            transition: transform 0.3s ease;
        }

        .warehouse-stop-card.collapsed .warehouse-stop-toggle svg {
            transform: rotate(-90deg);
        }

        .warehouse-stop-body {
            padding: 20px;
            border-top: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .warehouse-stop-card.collapsed .warehouse-stop-body {
            display: none;
        }

        /* Override portal.css .warehouse-card styles for this page */
        .journey-flow .warehouse-card,
        .journey-planner .warehouse-card {
            width: auto;
            border: none;
            border-radius: 0;
        }

        /* ==================== GOOGLE PLACES AUTOCOMPLETE ==================== */
        .address-input-wrapper {
            position: relative;
        }

        .address-input-wrapper input {
            width: 100%;
            padding-right: 36px;
        }

        .address-input-wrapper .address-loading {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            border: 2px solid #e9ecef;
            border-top-color: #488C9A;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            display: none;
        }

        .address-input-wrapper.loading .address-loading {
            display: block;
        }

        .address-verified {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #28a745;
            display: none;
        }

        .address-input-wrapper.verified .address-verified {
            display: block;
        }

        .pac-container {
            font-family: 'Poppins', sans-serif;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            border: 1px solid #e9ecef;
            margin-top: 4px;
            z-index: 10000;
        }

        .pac-item {
            padding: 10px 14px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .pac-item:hover {
            background: rgba(72, 140, 154, 0.08);
        }

        .pac-item-selected {
            background: rgba(72, 140, 154, 0.12);
        }

        .pac-icon {
            margin-right: 10px;
        }

        .pac-item-query {
            font-weight: 500;
            color: #293E4C;
        }

        .address-error {
            color: #dc3545;
            font-size: 0.85em;
            margin-top: 4px;
            display: none;
        }

        .address-input-wrapper.error .address-error {
            display: block;
        }

        .address-input-wrapper.error input {
            border-color: #dc3545;
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

        /* ==================== TIMELINE CHARTS TABS ==================== */
        .timeline-tabs {
            display: flex;
            gap: 0;
            border-bottom: 1px solid #e9ecef;
            padding: 0 24px;
            background: #f8f9fa;
        }

        .timeline-tab {
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            color: #6c757d;
            font-weight: 600;
            font-size: 0.9em;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Poppins', sans-serif;
        }

        .timeline-tab:hover {
            color: #488C9A;
            background: rgba(72, 140, 154, 0.05);
        }

        .timeline-tab.active {
            color: #488C9A;
            border-bottom-color: #488C9A;
            background: white;
        }

        .timeline-panel {
            display: none;
        }

        .timeline-panel.active {
            display: block;
        }

        /* Monthly Forecast Line Chart */
        .monthly-forecast-container {
            padding: 24px;
        }

        .monthly-chart-wrapper {
            position: relative;
            height: 280px;
            margin-bottom: 20px;
        }

        .monthly-chart-legend {
            display: flex;
            justify-content: center;
            gap: 24px;
            flex-wrap: wrap;
            padding: 12px 0;
            border-top: 1px solid #e9ecef;
        }

        .legend-item-chart {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85em;
            color: #495057;
        }

        .legend-color {
            width: 16px;
            height: 4px;
            border-radius: 2px;
        }

        .legend-color.freight { background: #488C9A; }
        .legend-color.warehousing { background: #E07F3A; }
        .legend-color.milestone { background: #28a745; }
        .legend-color.total { background: #293E4C; }

        /* Weekly Projections Table */
        .weekly-projections-section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e9ecef;
        }

        .weekly-projections-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 16px 0;
        }

        .weekly-projections-title svg {
            color: #488C9A;
        }

        .weekly-table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }

        .weekly-projections-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
        }

        .weekly-projections-table th,
        .weekly-projections-table td {
            padding: 12px 16px;
            text-align: right;
            border-bottom: 1px solid #e9ecef;
        }

        .weekly-projections-table th:first-child,
        .weekly-projections-table td:first-child {
            text-align: left;
        }

        .weekly-projections-table th:nth-child(2),
        .weekly-projections-table td:nth-child(2) {
            text-align: left;
        }

        .weekly-projections-table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #f0f4f5 100%);
            font-weight: 600;
            color: #293E4C;
            text-transform: uppercase;
            font-size: 0.75em;
            letter-spacing: 0.5px;
        }

        .weekly-projections-table tbody tr:hover {
            background: rgba(72, 140, 154, 0.04);
        }

        .weekly-projections-table tbody tr:last-child td {
            border-bottom: none;
        }

        .weekly-projections-table .week-number {
            font-weight: 600;
            color: #488C9A;
        }

        .weekly-projections-table .date-range {
            font-size: 0.85em;
            color: #6c757d;
        }

        .weekly-projections-table .amount {
            font-weight: 500;
        }

        .weekly-projections-table .amount.freight { color: #488C9A; }
        .weekly-projections-table .amount.warehousing { color: #E07F3A; }
        .weekly-projections-table .amount.milestone { color: #28a745; }

        .weekly-projections-table .weekly-total {
            font-weight: 600;
            color: #293E4C;
        }

        .weekly-projections-table .cumulative {
            font-weight: 700;
            color: #E07F3A;
        }

        .weekly-empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .weekly-empty-state svg {
            margin-bottom: 12px;
        }

        .weekly-empty-state p {
            margin: 0;
            font-size: 0.95em;
        }

        .weekly-note {
            margin-top: 12px;
            font-size: 0.85em;
            color: #6c757d;
            text-align: right;
        }

        /* Fee Table Styles for Journey */
        .fee-table-journey {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .fee-table-journey th {
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f5f7 100%);
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.8em;
            color: #495057;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid #e9ecef;
        }

        .fee-table-journey td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .fee-table-journey tr:last-child td {
            border-bottom: none;
        }

        .fee-table-journey input,
        .fee-table-journey select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #e3e7ea;
            border-radius: 8px;
            font-size: 0.9em;
            transition: all 0.2s ease;
        }

        .fee-table-journey input:focus,
        .fee-table-journey select:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }

        .fee-table-journey .fee-amount-col {
            width: 100px;
        }

        .fee-table-journey .fee-action-col {
            width: 40px;
            text-align: center;
        }

        .fee-remove-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .fee-remove-btn:hover {
            background: rgba(220, 53, 69, 0.1);
        }

        /* Cadence Field Styles */
        .cadence-field {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .cadence-field input {
            width: 60px !important;
        }

        .cadence-field span {
            font-size: 0.85em;
            color: #6c757d;
        }

        .truck-info-badge {
            font-size: 0.75em;
            padding: 4px 10px;
            background: rgba(72, 140, 154, 0.1);
            color: #488C9A;
            border-radius: 12px;
            font-weight: 600;
        }

        .end-date-display {
            font-size: 0.85em;
            color: #28a745;
            font-weight: 600;
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

        <!-- Main Content Layout -->
        <div class="planner-layout">
            <div class="planner-main">
                <!-- Collapsible: Modules & Manufacturers -->
                <div class="collapsible-section" data-section="modules-costs">
                    <div class="collapsible-header" onclick="toggleSection('modules-costs')">
                        <div class="collapsible-title">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                <line x1="8" y1="21" x2="16" y2="21"/>
                                <line x1="12" y1="17" x2="12" y2="21"/>
                            </svg>
                            Modules & Manufacturers
                        </div>
                        <div class="collapsible-meta">
                            <div class="card-summary">
                                <span class="summary-value" id="totalModulesCount"><?php echo number_format($total_modules); ?></span>
                                <span class="summary-label">modules</span>
                                <span class="summary-divider">|</span>
                                <span class="summary-value" id="totalContractValue">$<?php echo number_format($total_contract_value, 2); ?></span>
                                <span class="summary-label">contract value</span>
                            </div>
                            <span class="collapsible-badge" id="modulesBadge"><?php echo number_format($total_pallets); ?> pallets</span>
                            <div class="collapsible-toggle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </div>
                        </div>
                    </div>
                    <div class="collapsible-content" id="modules-costs-content">
                        <div class="collapsible-inner">
                            <!-- Module Selector Component -->
                            <?php include 'components/projection_module_selector.php'; ?>
                        </div>
                    </div>
                </div>

                <!-- Collapsible: Logistics Plan -->
                <div class="collapsible-section" data-section="logistics-plan">
                    <div class="collapsible-header" onclick="toggleSection('logistics-plan')">
                        <div class="collapsible-title">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 2a10 10 0 0 1 0 20"/>
                                <path d="M12 8v4l3 3"/>
                            </svg>
                            Logistics Plan
                        </div>
                        <div class="collapsible-meta">
                            <span class="collapsible-badge" id="stopsBadge"><?php echo count($stops); ?> stops</span>
                            <div class="collapsible-toggle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </div>
                        </div>
                    </div>
                    <div class="collapsible-content" id="logistics-plan-content">
                        <div class="journey-planner">
                            <div class="journey-intro">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 16v-4"/>
                                    <path d="M12 8h.01"/>
                                </svg>
                                <div>
                                    <strong>Plan your delivery journey step by step</strong><br>
                                    Define the route from manufacturer through warehouses/ports to the final destination. Each stop can have fees, and each leg can have transport details and costs.
                                </div>
                            </div>
                            <div id="journeyFlow" class="journey-flow">
                                <!-- Journey nodes rendered by JavaScript -->
                            </div>
                            <div id="journeyEmpty" class="empty-state" style="display: none;">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                                    <path d="M7 12h10"/>
                                </svg>
                                <p>Your delivery journey will appear here.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Collapsible: Route Map -->
                <div class="collapsible-section" data-section="route-map">
                    <div class="collapsible-header" onclick="toggleSection('route-map')">
                        <div class="collapsible-title">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
                                <line x1="8" y1="2" x2="8" y2="18"/>
                                <line x1="16" y1="6" x2="16" y2="22"/>
                            </svg>
                            Route Map
                        </div>
                        <div class="collapsible-meta">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="event.stopPropagation(); toggleMapFullscreen()">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="15 3 21 3 21 9"/>
                                    <polyline points="9 21 3 21 3 15"/>
                                    <line x1="21" y1="3" x2="14" y2="10"/>
                                    <line x1="3" y1="21" x2="10" y2="14"/>
                                </svg>
                            </button>
                            <div class="collapsible-toggle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </div>
                        </div>
                    </div>
                    <div class="collapsible-content" id="route-map-content">
                        <div class="route-map-container">
                            <div id="routeMap"></div>
                            <div class="map-placeholder" id="mapPlaceholder">
                                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
                                    <line x1="8" y1="2" x2="8" y2="18"/>
                                    <line x1="16" y1="6" x2="16" y2="22"/>
                                </svg>
                                <p>Add delivery steps to see the route</p>
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
                </div>

            </div>
        </div>

        <!-- Collapsible: Timeline & Costs -->
        <div class="collapsible-section" data-section="timeline">
            <div class="collapsible-header" onclick="toggleSection('timeline')">
                <div class="collapsible-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Projected Timeline & Costs
                </div>
                <div class="collapsible-meta">
                    <span class="collapsible-badge" id="grandTotalBadge">$0</span>
                    <div class="collapsible-toggle">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="collapsible-content" id="timeline-content">
                <!-- Chart Type Tabs -->
                <div class="timeline-tabs">
                    <button type="button" class="timeline-tab active" data-tab="cost-summary" onclick="switchTimelineTab('cost-summary')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: middle;">
                            <line x1="12" y1="1" x2="12" y2="23"/>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                        Cost Summary
                    </button>
                    <button type="button" class="timeline-tab" data-tab="line-chart" onclick="switchTimelineTab('line-chart')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: middle;">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        Monthly Forecast
                    </button>
                </div>

                <!-- Cost Summary Panel -->
                <div class="timeline-panel active" id="cost-summary-panel">
                    <?php include 'components/projection_cost_summary.php'; ?>
                </div>

                <!-- Line Chart Panel -->
                <div class="timeline-panel" id="line-chart-panel">
                    <div class="monthly-forecast-container">
                        <div class="monthly-chart-wrapper">
                            <canvas id="monthlyForecastChart"></canvas>
                        </div>
                        <div class="monthly-chart-legend">
                            <div class="legend-item-chart">
                                <span class="legend-color freight"></span>
                                <span>Freight Costs</span>
                            </div>
                            <div class="legend-item-chart">
                                <span class="legend-color warehousing"></span>
                                <span>Warehousing Costs</span>
                            </div>
                            <div class="legend-item-chart">
                                <span class="legend-color milestone"></span>
                                <span>Milestone Payments</span>
                            </div>
                            <div class="legend-item-chart">
                                <span class="legend-color total"></span>
                                <span>Cumulative Total</span>
                            </div>
                        </div>

                        <!-- Weekly Cost Projections Table -->
                        <div class="weekly-projections-section">
                            <h4 class="weekly-projections-title">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                Weekly Cost Projections
                            </h4>
                            <div class="weekly-table-wrapper">
                                <table class="weekly-projections-table" id="weeklyProjectionsTable">
                                    <thead>
                                        <tr>
                                            <th>Week</th>
                                            <th>Date Range</th>
                                            <th>Freight</th>
                                            <th>Warehousing</th>
                                            <th>Milestones</th>
                                            <th>Weekly Total</th>
                                            <th>Cumulative</th>
                                        </tr>
                                    </thead>
                                    <tbody id="weeklyProjectionsBody">
                                        <!-- Populated by JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                            <div class="weekly-note">
                                PO execution milestones are applied to the week of each PO execution date (or the current week if no date is set).
                            </div>
                            <div class="weekly-empty-state" id="weeklyEmptyState" style="display: none;">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                <p>Add dates to your logistics plan to see weekly cost projections.</p>
                            </div>
                        </div>
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

    </main>

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
        const projectInfo = {
            name: <?php echo json_encode($project['project_name']); ?>,
            address: <?php echo json_encode($project['project_address']); ?>,
            modulesPerPallet: <?php echo (int)($project_summary['modules_per_pallet'] ?? 30); ?>,
            palletsPerTruck: <?php echo (int)($project_summary['pallets_per_truck'] ?? 20); ?>,
            totalTrucks: <?php echo (float)($project_summary['trucks'] ?? 0); ?>
        };

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
            poExecutionDate: <?php echo json_encode($current_projection['po_execution_date'] ?? ''); ?>,
            moduleAllocations: <?php echo json_encode($allocated_modules); ?> || [],
            stops: <?php echo json_encode($stops); ?> || [],
            legs: <?php echo json_encode($legs); ?> || []
        };

        // Flatpickr instances
        let datePickerInstances = [];

        // Google Places autocomplete instances
        let autocompleteInstances = [];

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            initializeDatePickers();
            updateUIFromState();
            renderJourneyPlan();
            initializeMap();
            updateTimelineChart();
            loadCollapsibleStates();
        });

        // ==================== COLLAPSIBLE SECTIONS ====================
        function toggleSection(sectionId) {
            const header = document.querySelector(`[data-section="${sectionId}"] .collapsible-header`);
            const content = document.getElementById(`${sectionId}-content`);

            if (!header || !content) return;

            const isCollapsed = header.classList.contains('collapsed');

            if (isCollapsed) {
                header.classList.remove('collapsed');
                content.classList.remove('collapsed');
            } else {
                header.classList.add('collapsed');
                content.classList.add('collapsed');
            }

            // Save state to localStorage
            saveCollapsibleStates();

            // If opening the map section, trigger a resize
            if (sectionId === 'route-map' && isCollapsed && map) {
                setTimeout(() => {
                    google.maps.event.trigger(map, 'resize');
                    updateMapFromState();
                }, 350);
            }
        }

        function saveCollapsibleStates() {
            const states = {};
            document.querySelectorAll('.collapsible-section').forEach(section => {
                const sectionId = section.dataset.section;
                const header = section.querySelector('.collapsible-header');
                states[sectionId] = header.classList.contains('collapsed');
            });
            localStorage.setItem(`projection_collapsed_${projectId}`, JSON.stringify(states));
        }

        function loadCollapsibleStates() {
            try {
                const saved = localStorage.getItem(`projection_collapsed_${projectId}`);
                if (saved) {
                    const states = JSON.parse(saved);
                    Object.keys(states).forEach(sectionId => {
                        if (states[sectionId]) {
                            const header = document.querySelector(`[data-section="${sectionId}"] .collapsible-header`);
                            const content = document.getElementById(`${sectionId}-content`);
                            if (header && content) {
                                header.classList.add('collapsed');
                                content.classList.add('collapsed');
                            }
                        }
                    });
                }
            } catch (e) {
                console.log('Could not load collapsible states');
            }
        }

        // ==================== GOOGLE PLACES AUTOCOMPLETE ====================
        function initializeAddressAutocomplete(inputElement, onPlaceSelected) {
            if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                console.log('Google Places API not available');
                return null;
            }

            const autocomplete = new google.maps.places.Autocomplete(inputElement, {
                types: ['address'],
                fields: ['formatted_address', 'geometry', 'address_components', 'name']
            });

            // Prevent form submission on enter key in autocomplete
            inputElement.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });

            autocomplete.addListener('place_changed', function() {
                const place = autocomplete.getPlace();

                if (!place.geometry) {
                    // User entered text that is not a place
                    inputElement.parentElement.classList.add('error');
                    inputElement.parentElement.classList.remove('verified');
                    return;
                }

                inputElement.parentElement.classList.remove('error');
                inputElement.parentElement.classList.add('verified');

                // Explicitly set the input value to the formatted address
                const formattedAddress = place.formatted_address || inputElement.value;
                inputElement.value = formattedAddress;

                const placeData = {
                    address: formattedAddress,
                    latitude: place.geometry.location.lat(),
                    longitude: place.geometry.location.lng(),
                    name: place.name || ''
                };

                if (onPlaceSelected) {
                    onPlaceSelected(placeData);
                }

                // Trigger change event to update state
                inputElement.dispatchEvent(new Event('change', { bubbles: true }));
            });

            autocompleteInstances.push(autocomplete);
            return autocomplete;
        }

        function cleanupAutocompleteInstances() {
            // Remove pac-container elements that are orphaned
            document.querySelectorAll('.pac-container').forEach(el => {
                if (!document.body.contains(el.closest('.address-input-wrapper'))) {
                    el.remove();
                }
            });
            autocompleteInstances = [];
        }

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

            syncPlanState();
            showLoading('Saving projection...');

            const payload = {
                project_id: projectId,
                projection_id: workingState.projectionId,
                projection_name: workingState.projectionName,
                status: workingState.status,
                notes: workingState.notes,
                is_primary: workingState.isPrimary,
                po_execution_date: workingState.poExecutionDate || null,
                module_allocations: workingState.moduleAllocations.map(a => ({
                    module_id: a.module_id,
                    wattage: a.wattage,
                    quantity: a.quantity,
                    pallets: a.pallets,
                    po_execution_date: a.po_execution_date || null,
                    milestones: a.milestones || [],
                    // Include additional fields for manual entries
                    vendor_name: a.vendor_name,
                    manufacturer_address: a.manufacturer_address,
                    modules_per_pallet: a.modules_per_pallet,
                    pallets_per_truck: a.pallets_per_truck,
                    cost_per_watt: a.cost_per_watt,
                    is_manual: a.is_manual || false
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
            .then(async response => {
                const text = await response.text();
                if (!text) {
                    throw new Error('Empty response from server');
                }
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    throw new Error(text);
                }
                if (!response.ok) {
                    throw new Error(data.error || data.message || `Server error (${response.status})`);
                }
                return data;
            })
            .then(data => {
                hideLoading();
                if (data.success) {
                    markAsSaved();
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
                showToast('Error saving projection: ' + error.message, 'error');
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
            if (workingState.moduleAllocations.some(allocation => String(allocation.module_id) === String(batchId))) {
                showToast('This batch is already added to the projection.', 'error');
                return;
            }

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
                manufacturer_address: batch.manufacturer_address,
                contract_value: contractValue,
                has_milestones: batch.has_milestones,
                milestones: batch.milestones || []
            };

            workingState.moduleAllocations.push(allocation);
            showToast('Module batch added. Remember to save!', 'success');

            // Mark as unsaved and update UI
            markAsUnsaved();
            renderModuleAllocations();
            updateBadges();
        }

        function removeAllocation(allocationId) {
            workingState.moduleAllocations = workingState.moduleAllocations.filter(a => a.id != allocationId);
            showToast('Module removed. Remember to save!', 'info');

            // Mark as unsaved and update UI
            markAsUnsaved();
            renderModuleAllocations();
            updateBadges();
        }

        function addManualModuleAllocation(data) {
            // Add manual module allocation directly to working state
            const milestones = data.milestones || [];
            const allocation = {
                module_id: data.is_manual ? ('manual_' + Date.now()) : data.module_id,
                wattage: parseInt(data.wattage),
                quantity: parseInt(data.quantity),
                pallets: data.pallets,
                vendor_name: data.vendor_name,
                manufacturer_name: data.manufacturer_name,
                manufacturer_address: data.manufacturer_address || '',
                modules_per_pallet: data.modules_per_pallet,
                pallets_per_truck: data.pallets_per_truck,
                cost_per_watt: data.cost_per_watt,
                contract_value: data.contract_value,
                has_milestones: milestones.length > 0,
                milestones: milestones,
                is_manual: true
            };

            workingState.moduleAllocations.push(allocation);
            showToast('Manual module entry added. Remember to save!', 'success');

            // Mark as unsaved and update UI
            markAsUnsaved();
            renderModuleAllocations();
            updateBadges();
        }

        function renderModuleAllocations() {
            // Re-render the module allocations list based on workingState
            const container = document.getElementById('moduleAllocationsList');
            if (!container) return;

            if (workingState.moduleAllocations.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                        <p>No modules allocated to this projection yet.</p>
                    </div>
                `;
                updateModuleSummary();
                if (typeof updateAvailableBatchStates === 'function') {
                    updateAvailableBatchStates();
                }
                return;
            }

            let html = '';
            workingState.moduleAllocations.forEach((alloc, index) => {
                const pallets = alloc.pallets || Math.ceil(alloc.quantity / (alloc.modules_per_pallet || 30));
                const contractValue = alloc.contract_value || 0;
                const vendorName = alloc.vendor_name || alloc.manufacturer_name || 'Unknown';
                const modsPerPallet = alloc.modules_per_pallet || '-';
                const palletsPerTruck = alloc.pallets_per_truck || '-';

                html += `
                    <div class="module-allocation-item" data-allocation-index="${index}">
                        <div class="allocation-header" onclick="toggleAllocationExpand(${index})">
                            <div class="allocation-summary">
                                <span class="vendor-name">${escapeHtml(vendorName)}</span>
                                <span class="summary-stat">${alloc.wattage}W</span>
                                <span class="summary-divider">&bull;</span>
                                <span class="summary-stat">${alloc.quantity.toLocaleString()} modules</span>
                                <span class="summary-divider">&bull;</span>
                                <span class="summary-stat">${pallets.toLocaleString()} pallets</span>
                                ${palletsPerTruck !== '-' ? `
                                    <span class="summary-divider">&bull;</span>
                                    <span class="summary-stat">${palletsPerTruck} pallets/truck</span>
                                ` : ''}
                                <span class="summary-divider">&bull;</span>
                                <span class="summary-stat contract-value">$${contractValue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                            </div>
                            <div class="allocation-toggle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </div>
                        </div>
                        <div class="allocation-details" style="display: none;">
                            <div class="module-info-grid">
                                <div class="info-item">
                                    <span class="info-label">Wattage</span>
                                    <span class="info-value">${alloc.wattage}W</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Modules</span>
                                    <span class="info-value">${alloc.quantity.toLocaleString()}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Pallets</span>
                                    <span class="info-value">${pallets.toLocaleString()}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Mods/Pallet</span>
                                    <span class="info-value">${modsPerPallet}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Pallets/Truck</span>
                                    <span class="info-value">${palletsPerTruck}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Contract Value</span>
                                    <span class="info-value">$${contractValue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                                </div>
                            </div>
                            ${alloc.manufacturer_address ? `
                                <div class="info-item full-width">
                                    <span class="info-label">Location</span>
                                    <span class="info-value">${escapeHtml(alloc.manufacturer_address)}</span>
                                </div>
                            ` : ''}
                            <div class="allocation-actions">
                                <button type="button" class="btn btn-sm btn-danger" onclick="removeAllocation(${alloc.id || index})">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                    </svg>
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
            updateModuleSummary();
            if (typeof updateAvailableBatchStates === 'function') {
                updateAvailableBatchStates();
            }
        }

        function expandSection(sectionId) {
            const header = document.querySelector(`[data-section="${sectionId}"] .collapsible-header`);
            const content = document.getElementById(`${sectionId}-content`);
            if (!header || !content) return;

            header.classList.remove('collapsed');
            content.classList.remove('collapsed');
            saveCollapsibleStates();
        }

        function toggleAllocationExpand(index) {
            const item = document.querySelector(`.module-allocation-item[data-allocation-index="${index}"]`);
            if (!item) return;
            const details = item.querySelector('.allocation-details');
            const toggle = item.querySelector('.allocation-toggle');
            if (details.style.display === 'none') {
                details.style.display = 'block';
                toggle.style.transform = 'rotate(180deg)';
            } else {
                details.style.display = 'none';
                toggle.style.transform = 'rotate(0deg)';
            }
        }

        function updateModuleSummary() {
            const totalModules = workingState.moduleAllocations.reduce((sum, a) => sum + (a.quantity || 0), 0);
            const totalValue = workingState.moduleAllocations.reduce((sum, a) => sum + (a.contract_value || 0), 0);

            const modulesEl = document.getElementById('totalModulesCount');
            const valueEl = document.getElementById('totalContractValue');

            if (modulesEl) modulesEl.textContent = totalModules.toLocaleString();
            if (valueEl) valueEl.textContent = '$' + totalValue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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
            const modal = document.getElementById('stopEditorModal');
            if (modal) {
                modal.classList.remove('active');
            }
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
            showToast('Stop updated. Remember to save the projection!', 'success');
            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
            updateBadges();
        }

        function removeStop(stopId) {
            workingState.stops = workingState.stops.filter(s => s.id != stopId);
            // Also remove associated legs
            workingState.legs = workingState.legs.filter(l => l.from_stop_id != stopId && l.to_stop_id != stopId);
            showToast('Stop removed. Remember to save the projection!', 'info');
            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
            updateBadges();
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
            const modal = document.getElementById('legEditorModal');
            if (modal) {
                modal.classList.remove('active');
            }
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
            showToast('Leg updated. Remember to save the projection!', 'success');
            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
            updateBadges();
        }

        // ==================== LOGISTICS PLAN (INLINE) ====================
        function ensureStops() {
            const stops = workingState.stops || [];
            let origin = stops.find(stop => stop.stop_type === 'origin');
            let destination = stops.find(stop => stop.stop_type === 'destination');

            if (!origin) {
                const manufacturer = workingState.moduleAllocations[0]?.manufacturer_name || 'Manufacturer';
                const manufacturerAddress = workingState.moduleAllocations[0]?.manufacturer_address || '';
                origin = {
                    id: `origin_${Date.now()}`,
                    stop_type: 'origin',
                    location_name: manufacturer,
                    location_address: manufacturerAddress,
                    latitude: null,
                    longitude: null,
                    fees: []
                };
                // Geocode manufacturer address if available
                if (manufacturerAddress && !origin.latitude) {
                    geocodeAddress(manufacturerAddress, (coords) => {
                        if (coords) {
                            origin.latitude = coords.lat;
                            origin.longitude = coords.lng;
                            updateMapFromState();
                        }
                    });
                }
            }

            if (!destination) {
                destination = {
                    id: `destination_${Date.now()}`,
                    stop_type: 'destination',
                    location_name: projectInfo.name || 'Project Site',
                    location_address: projectInfo.address || '',
                    latitude: null,
                    longitude: null,
                    fees: []
                };
            }

            // Geocode destination address if not already geocoded
            if (destination.location_address && !destination.latitude) {
                geocodeAddress(destination.location_address, (coords) => {
                    if (coords) {
                        destination.latitude = coords.lat;
                        destination.longitude = coords.lng;
                        updateMapFromState();
                    }
                });
            }

            const intermediates = stops.filter(stop => stop.stop_type !== 'origin' && stop.stop_type !== 'destination');
            const orderedStops = [];
            if (origin) orderedStops.push(origin);
            orderedStops.push(...intermediates);
            if (destination) orderedStops.push(destination);

            orderedStops.forEach((stop, index) => {
                if (!stop.id) {
                    stop.id = `stop_${Date.now()}_${index}`;
                }
                if (!Array.isArray(stop.fees)) {
                    stop.fees = [];
                }
            });

            workingState.stops = orderedStops;
        }

        function escapeHtml(value) {
            if (value === null || value === undefined) return '';
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function getMilestoneForStop(stop) {
            if (!stop) return { value: '', label: 'Milestone' };
            if (stop.stop_type === 'destination') {
                return { value: 'project_delivery', label: 'Project Delivery' };
            }
            if (stop.is_customs_clearance || stop.stop_type === 'customs') {
                return { value: 'customs_cleared', label: 'Customs Cleared' };
            }
            return { value: 'shipping', label: 'Shipping' };
        }

        function getLegForStops(fromId, toId) {
            let leg = workingState.legs.find(l => l.from_stop_id == fromId && l.to_stop_id == toId);
            if (!leg) {
                leg = {
                    id: `leg_${Date.now()}_${Math.random().toString(16).slice(2)}`,
                    from_stop_id: fromId,
                    to_stop_id: toId,
                    transport_mode: 'truck',
                    delivery_rate_unit: 'per_week'
                };
                workingState.legs.push(leg);
            }
            return leg;
        }

        function calculateEndDate(startDate, deliveryRate, rateUnit, trucks) {
            if (!startDate || !deliveryRate || deliveryRate <= 0) return '';
            const rate = parseFloat(deliveryRate);
            const totalTrucks = parseInt(trucks, 10) || 1;
            let daysPerDelivery = 1;

            if (rateUnit === 'per_week') {
                daysPerDelivery = 7 / rate;
            } else if (rateUnit === 'per_month') {
                daysPerDelivery = 30 / rate;
            } else {
                daysPerDelivery = 1 / rate;
            }

            const totalDays = Math.ceil(totalTrucks * daysPerDelivery);
            const start = new Date(startDate);
            if (Number.isNaN(start.getTime())) return '';
            start.setDate(start.getDate() + totalDays);
            return start.toISOString().split('T')[0];
        }

        function calculateFeeEstimate(fee, stop) {
            const rate = parseFloat(fee.rate) || 0;
            if (!rate) return 0;
            const pallets = getTotalPallets() || 1;
            const modules = workingState.moduleAllocations.reduce((sum, alloc) => sum + (alloc.quantity || 0), 0) || 1;
            const trucks = getTotalTrucks();

            // Calculate base cost based on unit
            let baseCost = 0;
            switch (fee.rate_unit) {
                case 'per_module':
                    baseCost = rate * modules;
                    break;
                case 'per_truck':
                    baseCost = rate * trucks;
                    break;
                case 'per_sqft':
                    // Assume 13.33 sqft per pallet default
                    const sqftPerPallet = 13.33;
                    baseCost = rate * pallets * sqftPerPallet;
                    break;
                case 'flat':
                    baseCost = rate;
                    break;
                case 'per_pallet':
                default:
                    baseCost = rate * pallets;
                    break;
            }

            // For monthly (storage) fees, multiply by number of months in storage
            if (fee.fee_type === 'storage' && stop) {
                const stopIndex = workingState.stops.indexOf(stop);
                if (stopIndex === -1) return baseCost;

                const nextStop = workingState.stops[stopIndex + 1];

                if (stop.estimated_arrival_date && nextStop?.estimated_arrival_date) {
                    const entryDate = new Date(stop.estimated_arrival_date);
                    const exitDate = new Date(nextStop.estimated_arrival_date);
                    const daysInStorage = Math.ceil((exitDate - entryDate) / (1000 * 60 * 60 * 24));
                    const monthsInStorage = Math.max(1, Math.ceil(daysInStorage / 30));
                    return baseCost * monthsInStorage;
                }
            }

            return baseCost;
        }

        function syncPlanState() {
            ensureStops();

            const stops = workingState.stops || [];
            const legPairs = new Set();
            for (let i = 0; i < stops.length - 1; i++) {
                const fromStop = stops[i];
                const toStop = stops[i + 1];
                if (!fromStop || !toStop) continue;
                legPairs.add(`${fromStop.id}__${toStop.id}`);
                getLegForStops(fromStop.id, toStop.id);
            }

            workingState.legs = workingState.legs.filter(leg => legPairs.has(`${leg.from_stop_id}__${leg.to_stop_id}`));

            workingState.legs.forEach(leg => {
                const toStop = workingState.stops.find(stop => stop.id == leg.to_stop_id);
                const milestone = getMilestoneForStop(toStop);
                leg.triggers_milestone = milestone.value;

                const trucks = parseInt(leg.trucks_required, 10) || getTotalTrucks();
                leg.trucks_required = trucks;

                const endDate = calculateEndDate(leg.start_date, leg.delivery_rate, leg.delivery_rate_unit, trucks);
                if (endDate) {
                    leg.end_date = endDate;
                }

                const freight = parseFloat(leg.freight_cost_per_truck) || 0;
                const accessorial = parseFloat(leg.accessorial_cost_per_truck) || 0;
                leg.total_freight_cost = trucks * (freight + accessorial);

                if (toStop && leg.end_date) {
                    toStop.estimated_arrival_date = leg.end_date;
                }
            });

            workingState.stops.forEach(stop => {
                if (!Array.isArray(stop.fees)) {
                    stop.fees = [];
                }
                stop.fees = stop.fees.map(fee => ({
                    ...fee,
                    estimated_cost: calculateFeeEstimate(fee, stop)
                }));
            });
        }

        // ==================== JOURNEY PLAN (NEW VISUAL) ====================
        function renderJourneyPlan() {
            syncPlanState();
            cleanupAutocompleteInstances();

            const container = document.getElementById('journeyFlow');
            const emptyState = document.getElementById('journeyEmpty');
            if (!container) return;

            const disabledAttr = canEdit ? '' : 'disabled';
            const stops = workingState.stops || [];

            if (stops.length < 2) {
                container.innerHTML = '';
                if (emptyState) emptyState.style.display = 'block';
                return;
            }

            if (emptyState) emptyState.style.display = 'none';

            let html = '';

            stops.forEach((stop, index) => {
                const isFirst = index === 0;
                const isLast = index === stops.length - 1;
                const isOrigin = stop.stop_type === 'origin';
                const isDestination = stop.stop_type === 'destination';
                const isWarehouse = !isOrigin && !isDestination;

                const dotClass = isOrigin ? 'origin' : (isDestination ? 'destination' : (stop.stop_type === 'port' ? 'port' : 'warehouse'));
                const stepNumber = index + 1;

                const milestone = getMilestoneForStop(stop);
                const totalFees = (stop.fees || []).reduce((sum, f) => sum + (f.estimated_cost || 0), 0);

                // Build badges
                let badges = '';
                if (isOrigin) {
                    badges += '<span class="journey-badge milestone">Start</span>';
                }
                if (isDestination) {
                    badges += '<span class="journey-badge milestone">Final Delivery</span>';
                }
                if (stop.is_customs_clearance) {
                    badges += '<span class="journey-badge customs">Customs</span>';
                }
                if (!isOrigin && !isDestination && milestone.value) {
                    badges += `<span class="journey-badge milestone">${milestone.label}</span>`;
                }

                // Build fees section for warehouses - Updated format to match add_warehouse.php
                // Order: Fee Name → When Charged (trigger) → Rate → Per (unit)
                let feesHtml = '';
                if (isWarehouse) {
                    const feeRows = (stop.fees || []).map((fee, feeIndex) => `
                        <tr>
                            <td>
                                <input type="text" class="delivery-input" data-fee-field="fee_name" data-stop-id="${stop.id}" data-fee-index="${feeIndex}" value="${escapeHtml(fee.fee_name || '')}" placeholder="Fee name" ${disabledAttr}>
                            </td>
                            <td>
                                <select class="delivery-select" data-fee-field="fee_type" data-stop-id="${stop.id}" data-fee-index="${feeIndex}" ${disabledAttr}>
                                    <option value="receiving" ${fee.fee_type === 'receiving' ? 'selected' : ''}>On Entry</option>
                                    <option value="storage" ${fee.fee_type === 'storage' ? 'selected' : ''}>Monthly</option>
                                    <option value="outbound" ${fee.fee_type === 'outbound' ? 'selected' : ''}>On Exit</option>
                                    <option value="customs" ${fee.fee_type === 'customs' ? 'selected' : ''}>Customs Clearance</option>
                                    <option value="handling" ${fee.fee_type === 'handling' ? 'selected' : ''}>Drayage</option>
                                    <option value="other" ${fee.fee_type === 'other' ? 'selected' : ''}>Other</option>
                                </select>
                            </td>
                            <td class="fee-amount-col">
                                <input type="number" class="delivery-input" data-fee-field="rate" data-stop-id="${stop.id}" data-fee-index="${feeIndex}" value="${fee.rate || ''}" placeholder="$0" step="0.01" ${disabledAttr}>
                            </td>
                            <td>
                                <select class="delivery-select" data-fee-field="rate_unit" data-stop-id="${stop.id}" data-fee-index="${feeIndex}" ${disabledAttr}>
                                    <option value="per_pallet" ${fee.rate_unit === 'per_pallet' ? 'selected' : ''}>Per Pallet</option>
                                    <option value="per_truck" ${fee.rate_unit === 'per_truck' ? 'selected' : ''}>Per Truck</option>
                                    <option value="per_sqft" ${fee.rate_unit === 'per_sqft' ? 'selected' : ''}>Per Sq. Ft.</option>
                                    <option value="flat" ${fee.rate_unit === 'flat' ? 'selected' : ''}>Flat Rate</option>
                                </select>
                            </td>
                            <td class="fee-action-col">
                                ${canEdit ? `<button type="button" class="fee-remove-btn" data-action="remove-fee" data-stop-id="${stop.id}" data-fee-index="${feeIndex}">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>` : ''}
                            </td>
                        </tr>
                    `).join('');

                    const feeTableHtml = feeRows ? `
                        <table class="fee-table-journey">
                            <thead>
                                <tr>
                                    <th>Fee Name</th>
                                    <th>When Charged</th>
                                    <th>Rate ($)</th>
                                    <th>Per</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                ${feeRows}
                            </tbody>
                        </table>
                    ` : '<p style="margin: 0; color: #6c757d; font-size: 0.9em;">No fees added yet.</p>';

                    feesHtml = `
                        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px dashed #e3e7ea;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                <strong style="font-size: 0.9em; color: #293E4C;">Warehouse Fees</strong>
                                ${canEdit ? `<button type="button" class="btn btn-sm btn-secondary" data-action="add-fee" data-stop-id="${stop.id}">+ Add Fee</button>` : ''}
                            </div>
                            ${feeTableHtml}
                            <div style="margin-top: 12px;">
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                    <input type="checkbox" data-stop-id="${stop.id}" data-stop-field="is_customs_clearance" ${stop.is_customs_clearance ? 'checked' : ''} ${disabledAttr}>
                                    <span style="font-size: 0.9em;">Customs clearance at this location</span>
                                </label>
                            </div>
                            ${totalFees > 0 ? `<div style="margin-top: 8px; text-align: right; font-weight: 600; color: #488C9A;">Est. Total: $${totalFees.toLocaleString()}</div>` : ''}
                        </div>
                    `;
                }

                // Build address input with autocomplete
                const addressInput = isOrigin || isDestination
                    ? `<input class="delivery-input" value="${escapeHtml(stop.location_address || (isOrigin ? 'From manufacturer location' : 'Project address'))}" readonly style="background: #f8f9fa;">`
                    : `<div class="address-input-wrapper" id="address-wrapper-${stop.id}">
                        <input type="text" class="delivery-input address-autocomplete" data-stop-id="${stop.id}" data-stop-field="location_address" value="${escapeHtml(stop.location_address || '')}" placeholder="Start typing to search for address..." ${disabledAttr}>
                        <div class="address-loading"></div>
                        <svg class="address-verified" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        <div class="address-error">Please select a valid address from the suggestions</div>
                    </div>`;

                // Build node card content
                let nodeContent = '';

                if (isOrigin) {
                    nodeContent = `
                        <div class="journey-node-header">
                            <div>
                                <h4 class="journey-node-title">${escapeHtml(stop.location_name || 'Manufacturer')}</h4>
                                <div class="journey-node-type">Origin / Manufacturer</div>
                            </div>
                            <div class="journey-node-badges">${badges}</div>
                        </div>
                        <div class="journey-node-details">
                            <div class="delivery-field">
                                <label>Location</label>
                                ${addressInput}
                            </div>
                        </div>
                    `;
                } else if (isDestination) {
                    nodeContent = `
                        <div class="journey-node-header">
                            <div>
                                <h4 class="journey-node-title">${escapeHtml(stop.location_name || 'Project Site')}</h4>
                                <div class="journey-node-type">Final Destination</div>
                            </div>
                            <div class="journey-node-badges">${badges}</div>
                        </div>
                        <div class="journey-node-details">
                            <div class="delivery-field">
                                <label>Location</label>
                                ${addressInput}
                            </div>
                        </div>
                    `;
                } else {
                    // Warehouse/Port stop - Now collapsible
                    const stopTypeBadge = stop.stop_type === 'port' ? 'port' : (stop.stop_type === 'customs' ? 'customs' : 'warehouse');
                    const stopTypeLabel = stop.stop_type === 'port' ? 'Port' : (stop.stop_type === 'customs' ? 'Customs Facility' : 'Warehouse');
                    const feeCount = (stop.fees || []).length;
                    const collapsedClass = stop.is_collapsed ? 'collapsed' : '';
                    const savedIndicator = stop.is_saved
                        ? '<span class="saved-indicator" style="margin-left: 8px; display: inline-flex; align-items: center;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></span>'
                        : '';

                    // Calculate estimated duration if arrival dates are available
                    const nextStopIdx = stops.indexOf(stop) + 1;
                    const nextStop = stops[nextStopIdx];
                    let durationDisplay = '';
                    if (stop.estimated_arrival_date && nextStop?.estimated_arrival_date) {
                        const arrival = new Date(stop.estimated_arrival_date);
                        const departure = new Date(nextStop.estimated_arrival_date);
                        const daysStored = Math.ceil((departure - arrival) / (1000 * 60 * 60 * 24));
                        if (daysStored > 0) {
                            durationDisplay = daysStored > 30 ? `${Math.round(daysStored / 30)} months` : `${daysStored} days`;
                        }
                    }

                    nodeContent = `
                        <div class="warehouse-stop-card ${collapsedClass}" data-stop-id="${stop.id}">
                            <div class="warehouse-stop-header" onclick="toggleWarehouseCard('${stop.id}')">
                                <div class="warehouse-stop-info">
                                    <div class="warehouse-stop-title-row">
                                        <h4 class="warehouse-stop-title">${escapeHtml(stop.location_name || 'Unnamed ' + stopTypeLabel)}</h4>
                                        <span class="warehouse-stop-type-badge ${stopTypeBadge}">${stopTypeLabel}</span>
                                        ${badges}
                                        ${savedIndicator}
                                    </div>
                                    <div class="warehouse-stop-summary">
                                        <div class="warehouse-stop-summary-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                                            <span>${feeCount} fee${feeCount !== 1 ? 's' : ''}</span>
                                        </div>
                                        ${totalFees > 0 ? `<div class="warehouse-stop-summary-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                            <strong>$${totalFees.toLocaleString()}</strong>
                                        </div>` : ''}
                                        ${durationDisplay ? `<div class="warehouse-stop-summary-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                            <span>${durationDisplay}</span>
                                        </div>` : ''}
                                        ${stop.location_address ? `<div class="warehouse-stop-summary-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                            <span style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(stop.location_address)}</span>
                                        </div>` : ''}
                                    </div>
                                </div>
                                <button type="button" class="warehouse-stop-toggle">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                            </div>
                            <div class="warehouse-stop-body">
                                <div class="journey-node-details">
                                    <div class="delivery-field">
                                        <label>Stop Type</label>
                                        <select class="delivery-select" data-stop-id="${stop.id}" data-stop-field="stop_type" ${disabledAttr}>
                                            <option value="warehouse" ${stop.stop_type === 'warehouse' ? 'selected' : ''}>Warehouse</option>
                                            <option value="port" ${stop.stop_type === 'port' ? 'selected' : ''}>Port</option>
                                            <option value="customs" ${stop.stop_type === 'customs' ? 'selected' : ''}>Customs Facility</option>
                                        </select>
                                    </div>
                                    <div class="delivery-field">
                                        <label>Location Name</label>
                                        <input type="text" class="delivery-input" data-stop-id="${stop.id}" data-stop-field="location_name" value="${escapeHtml(stop.location_name || '')}" placeholder="e.g., Houston Distribution Center" ${disabledAttr}>
                                    </div>
                                    <div class="delivery-field" style="grid-column: 1 / -1;">
                                        <label>Address</label>
                                        ${addressInput}
                                    </div>
                                </div>
                                ${feesHtml}
                                ${canEdit ? `
                                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center;">
                                    <button type="button" class="btn btn-sm btn-danger" data-action="remove-stop" data-stop-id="${stop.id}">Remove This Stop</button>
                                    <button type="button" class="btn btn-sm btn-primary" data-action="save-stop" data-stop-id="${stop.id}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><polyline points="20 6 9 17 4 12"/></svg>
                                        Save &amp; Next
                                    </button>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                }

                // Build the node HTML
                if (isWarehouse) {
                    // Warehouse/Port uses the new collapsible card structure directly
                    html += `
                        <div class="journey-node" data-stop-id="${stop.id}">
                            <div class="journey-node-indicator">
                                <div class="journey-node-dot ${dotClass}">${stepNumber}</div>
                                ${!isLast ? '<div class="journey-connector"></div>' : ''}
                            </div>
                            <div class="journey-node-content">
                                ${nodeContent}
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="journey-node" data-stop-id="${stop.id}">
                            <div class="journey-node-indicator">
                                <div class="journey-node-dot ${dotClass}">${stepNumber}</div>
                                ${!isLast ? '<div class="journey-connector"></div>' : ''}
                            </div>
                            <div class="journey-node-content">
                                <div class="journey-node-card">
                                    ${nodeContent}
                                </div>
                            </div>
                        </div>
                    `;
                }

                // Add leg card between stops (except after last stop)
                if (!isLast) {
                    const nextStop = stops[index + 1];
                    const leg = getLegForStops(stop.id, nextStop.id);
                    const maxTrucks = getTotalTrucks();
                    const trucks = parseInt(leg.trucks_required, 10) || maxTrucks;
                    const totalFreight = leg.total_freight_cost || 0;
                    const cadence = parseInt(leg.delivery_rate, 10) || 0;
                    const cadenceUnit = leg.delivery_rate_unit || 'per_week';

                    // Calculate end date based on cadence
                    let endDateDisplay = '';
                    if (leg.start_date && cadence > 0) {
                        const calculatedEnd = calculateEndDate(leg.start_date, cadence, cadenceUnit, trucks);
                        if (calculatedEnd) {
                            endDateDisplay = `<span class="end-date-display">→ ${formatDate(calculatedEnd)}</span>`;
                        }
                    }

                    const transportIcon = getTransportIcon(leg.transport_mode);

                    html += `
                        <div class="journey-leg-card" data-leg-id="${leg.id}">
                            <div class="journey-leg-icon">${transportIcon}</div>
                            <div class="journey-leg-details">
                                <div class="journey-leg-field">
                                    <label>Transport</label>
                                    <select class="delivery-select" data-leg-id="${leg.id}" data-leg-field="transport_mode" style="padding: 6px 10px; font-size: 0.9em;" ${disabledAttr}>
                                        <option value="truck" ${leg.transport_mode === 'truck' ? 'selected' : ''}>Truck</option>
                                        <option value="ocean" ${leg.transport_mode === 'ocean' ? 'selected' : ''}>Ocean</option>
                                        <option value="rail" ${leg.transport_mode === 'rail' ? 'selected' : ''}>Rail</option>
                                        <option value="air" ${leg.transport_mode === 'air' ? 'selected' : ''}>Air</option>
                                    </select>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Trucks <span class="truck-info-badge">${maxTrucks} max</span></label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="trucks_required" value="${leg.trucks_required || ''}" placeholder="${maxTrucks}" min="1" max="${maxTrucks}" style="padding: 6px 10px; font-size: 0.9em; width: 80px;" ${disabledAttr}>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Cadence</label>
                                    <div class="cadence-field">
                                        <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="delivery_rate" value="${cadence || ''}" placeholder="0" min="1" style="padding: 6px 10px; font-size: 0.9em;" ${disabledAttr}>
                                        <select class="delivery-select" data-leg-id="${leg.id}" data-leg-field="delivery_rate_unit" style="padding: 6px 8px; font-size: 0.85em;" ${disabledAttr}>
                                            <option value="per_week" ${cadenceUnit === 'per_week' ? 'selected' : ''}>/week</option>
                                            <option value="per_day" ${cadenceUnit === 'per_day' ? 'selected' : ''}>/day</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Start Date ${endDateDisplay}</label>
                                    <input type="text" class="delivery-input flatpickr-date" data-leg-id="${leg.id}" data-leg-field="start_date" value="${leg.start_date || ''}" placeholder="Select date" style="padding: 6px 10px; font-size: 0.9em;" ${disabledAttr}>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Freight/Truck</label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="freight_cost_per_truck" value="${leg.freight_cost_per_truck || ''}" placeholder="$0" step="0.01" style="padding: 6px 10px; font-size: 0.9em; width: 100px;" ${disabledAttr}>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Total Freight</label>
                                    <div class="value" style="font-weight: 600; color: #488C9A;">$${totalFreight.toLocaleString()}</div>
                                </div>
                            </div>
                        </div>
                    `;

                    // Add "Add Stop Here" button only AFTER the last intermediate stop (before destination)
                    // This prevents showing multiple buttons (one before and one after)
                    const isLastStopBeforeDestination = nextStop && nextStop.stop_type === 'destination';

                    if (canEdit && isLastStopBeforeDestination) {
                        html += `
                            <div class="journey-add-stop">
                                <button type="button" class="journey-add-stop-btn" data-action="add-warehouse-after" data-stop-id="${stop.id}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Add Stop Here
                                </button>
                            </div>
                        `;
                    }
                }
            });

            container.innerHTML = html;

            // Initialize date pickers and autocomplete
            initializeDatePickers();
            initializeAddressAutocompletes();
            bindJourneyPlanListeners();

            // Update badges
            updateBadges();
        }

        function getTransportIcon(mode) {
            switch (mode) {
                case 'ocean':
                    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.6 2 5 2 2.4 0 2.4-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M19.38 20A11.6 11.6 0 0 0 21 14l-9-4-9 4c0 2.9.94 5.34 2.81 7.76"/><path d="M19 13V7a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v6"/><path d="M12 10v4"/></svg>';
                case 'rail':
                    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16"/><path d="M12 3v8"/><path d="m8 19-2 3"/><path d="m18 22-2-3"/><path d="M8 15h0"/><path d="M16 15h0"/></svg>';
                case 'air':
                    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>';
                default: // truck
                    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>';
            }
        }

        function initializeAddressAutocompletes() {
            document.querySelectorAll('.address-autocomplete').forEach(input => {
                const stopId = input.dataset.stopId;

                initializeAddressAutocomplete(input, (placeData) => {
                    const stop = workingState.stops.find(s => s.id == stopId);
                    if (stop) {
                        stop.location_address = placeData.address;
                        stop.latitude = placeData.latitude;
                        stop.longitude = placeData.longitude;

                        markAsUnsaved();
                        updateMapFromState();
                    }
                });
            });
        }

        function bindJourneyPlanListeners() {
            const container = document.getElementById('journeyFlow');
            if (!container) return;

            container.onchange = function(event) {
                const target = event.target;
                if (target.dataset.stopField) {
                    updateJourneyStopField(target);
                } else if (target.dataset.legField) {
                    updateJourneyLegField(target);
                } else if (target.dataset.feeField) {
                    updateJourneyFeeField(target);
                }
            };

            container.onclick = function(event) {
                const actionButton = event.target.closest('[data-action]');
                if (!actionButton) return;
                const action = actionButton.dataset.action;
                if (action === 'add-fee') {
                    addJourneyFee(actionButton.dataset.stopId);
                } else if (action === 'remove-fee') {
                    removeJourneyFee(actionButton.dataset.stopId, parseInt(actionButton.dataset.feeIndex, 10));
                } else if (action === 'remove-stop') {
                    removeJourneyStop(actionButton.dataset.stopId);
                } else if (action === 'add-warehouse' || action === 'add-warehouse-after') {
                    addJourneyStop(actionButton.dataset.stopId);
                } else if (action === 'save-stop') {
                    saveAndCollapseStop(actionButton.dataset.stopId);
                }
            };
        }

        function saveAndCollapseStop(stopId) {
            // Capture current form state first
            captureJourneyFormState();

            // Sync state to recalculate fees
            syncPlanState();

            const stops = workingState.stops || [];
            let nextStopId = null;
            const currentIndex = stops.findIndex(stop => stop.id == stopId);
            if (currentIndex >= 0) {
                stops[currentIndex].is_collapsed = true;
                stops[currentIndex].is_saved = true;

                for (let i = currentIndex + 1; i < stops.length; i++) {
                    if (!['origin', 'destination'].includes(stops[i].stop_type)) {
                        stops[i].is_collapsed = false;
                        nextStopId = stops[i].id;
                        break;
                    }
                }
            }

            // Re-render to update summary info
            renderJourneyPlan();
            updateTimelineChart();
            showToast('Stop updated. Remember to save the projection!', 'success');

            if (nextStopId) {
                setTimeout(() => {
                    const nextCard = document.querySelector(`.warehouse-stop-card[data-stop-id="${nextStopId}"]`);
                    if (nextCard) {
                        nextCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }, 100);
            } else {
                expandSection('route-map');
                const routeSection = document.querySelector('[data-section="route-map"]');
                if (routeSection) {
                    routeSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }

        function updateJourneyStopField(element) {
            const stop = workingState.stops.find(s => s.id == element.dataset.stopId);
            if (!stop) return;

            const field = element.dataset.stopField;
            const value = element.type === 'checkbox' ? (element.checked ? 1 : 0) : element.value;
            stop[field] = value;
            stop.is_saved = false;

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        function updateJourneyLegField(element) {
            const leg = workingState.legs.find(l => l.id == element.dataset.legId);
            if (!leg) return;

            const field = element.dataset.legField;
            let value = element.value;
            if (['delivery_rate', 'freight_cost_per_truck', 'accessorial_cost_per_truck', 'trucks_required'].includes(field)) {
                value = value === '' ? '' : parseFloat(value);
            }
            leg[field] = value;

            markAsUnsaved();
            syncPlanState();
            renderJourneyPlan();
            updateTimelineChart();
        }

        function updateJourneyFeeField(element) {
            const stop = workingState.stops.find(s => s.id == element.dataset.stopId);
            if (!stop || !stop.fees) return;
            const index = parseInt(element.dataset.feeIndex, 10);
            const fee = stop.fees[index];
            if (!fee) return;

            fee[element.dataset.feeField] = element.value;
            fee.estimated_cost = calculateFeeEstimate(fee);
            stop.is_saved = false;

            markAsUnsaved();
            renderJourneyPlan();
            updateTimelineChart();
        }

        function addJourneyFee(stopId) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop) return;
            if (!Array.isArray(stop.fees)) stop.fees = [];

            stop.fees.push({
                fee_type: 'storage',
                fee_name: '',
                rate: '',
                rate_unit: 'per_pallet',
                estimated_cost: 0
            });
            stop.is_saved = false;

            markAsUnsaved();
            renderJourneyPlan();
        }

        function removeJourneyFee(stopId, feeIndex) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop || !stop.fees) return;
            stop.fees.splice(feeIndex, 1);
            stop.is_saved = false;
            markAsUnsaved();
            renderJourneyPlan();
        }

        // Capture all current form values from the journey plan before re-rendering
        function captureJourneyFormState() {
            const container = document.getElementById('journeyFlow');
            if (!container) return;

            // Capture stop field values
            container.querySelectorAll('[data-stop-id][data-stop-field]').forEach(el => {
                const stopId = el.dataset.stopId;
                const field = el.dataset.stopField;
                const stop = workingState.stops.find(s => s.id == stopId);
                if (!stop) return;

                if (el.type === 'checkbox') {
                    stop[field] = el.checked ? 1 : 0;
                } else {
                    stop[field] = el.value;
                }
            });

            // Capture leg field values
            container.querySelectorAll('[data-leg-id][data-leg-field]').forEach(el => {
                const legId = el.dataset.legId;
                const field = el.dataset.legField;
                const leg = workingState.legs.find(l => l.id == legId);
                if (!leg) return;

                if (['delivery_rate', 'freight_cost_per_truck', 'accessorial_cost_per_truck', 'trucks_required'].includes(field)) {
                    leg[field] = el.value === '' ? '' : parseFloat(el.value);
                } else {
                    leg[field] = el.value;
                }
            });

            // Capture fee field values
            container.querySelectorAll('[data-stop-id][data-fee-field]').forEach(el => {
                const stopId = el.dataset.stopId;
                const feeIndex = parseInt(el.dataset.feeIndex, 10);
                const field = el.dataset.feeField;
                const stop = workingState.stops.find(s => s.id == stopId);
                if (!stop || !stop.fees || !stop.fees[feeIndex]) return;

                stop.fees[feeIndex][field] = el.value;
            });
        }

        function removeJourneyStop(stopId) {
            captureJourneyFormState(); // Capture state before removing
            const stopIndex = workingState.stops.findIndex(s => s.id == stopId);
            if (stopIndex <= 0 || stopIndex >= workingState.stops.length - 1) return;

            const prevStop = workingState.stops[stopIndex - 1];
            const nextStop = workingState.stops[stopIndex + 1];
            workingState.stops.splice(stopIndex, 1);

            workingState.legs = workingState.legs.filter(leg => leg.from_stop_id != stopId && leg.to_stop_id != stopId);
            if (prevStop && nextStop) {
                getLegForStops(prevStop.id, nextStop.id);
            }

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        function addJourneyStop(afterStopId) {
            if (!canEdit) return;

            // IMPORTANT: Capture all current form values before re-rendering
            captureJourneyFormState();

            ensureStops();

            const stops = workingState.stops;
            let insertIndex;

            if (afterStopId) {
                insertIndex = stops.findIndex(s => s.id == afterStopId) + 1;
            } else {
                insertIndex = stops.findIndex(stop => stop.stop_type === 'destination');
            }

            if (insertIndex <= 0 || insertIndex >= stops.length) {
                insertIndex = stops.length - 1;
            }

            const newStop = {
                id: `warehouse_${Date.now()}`,
                stop_type: 'warehouse',
                location_name: '',
                location_address: '',
                latitude: null,
                longitude: null,
                fees: []
            };

            const prevStop = stops[insertIndex - 1];
            const nextStop = stops[insertIndex];

            stops.splice(insertIndex, 0, newStop);

            // Remove the old leg between prev and next
            workingState.legs = workingState.legs.filter(leg => !(leg.from_stop_id == prevStop?.id && leg.to_stop_id == nextStop?.id));

            // Create new legs
            if (prevStop) {
                getLegForStops(prevStop.id, newStop.id);
            }
            if (nextStop) {
                getLegForStops(newStop.id, nextStop.id);
            }

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        function updateBadges() {
            const pallets = getTotalPallets();
            const stopsCount = workingState.stops.length;

            const modulesBadge = document.getElementById('modulesBadge');
            const stopsBadge = document.getElementById('stopsBadge');

            if (modulesBadge) {
                modulesBadge.textContent = `${pallets.toLocaleString()} pallets`;
            }
            if (stopsBadge) {
                stopsBadge.textContent = `${stopsCount} stops`;
            }
        }

        // Keep renderDeliveryPlan for backwards compatibility
        function renderDeliveryPlan() {
            syncPlanState();

            const container = document.getElementById('deliveryPlan');
            const emptyState = document.getElementById('deliveryPlanEmpty');
            if (!container) return;

            const disabledAttr = canEdit ? '' : 'disabled';
            const stops = workingState.stops || [];
            const segments = [];
            for (let i = 0; i < stops.length - 1; i++) {
                const fromStop = stops[i];
                const toStop = stops[i + 1];
                if (!fromStop || !toStop) continue;

                const leg = getLegForStops(fromStop.id, toStop.id);
                const milestone = getMilestoneForStop(toStop);
                const isDestination = toStop.stop_type === 'destination';
                const trucks = parseInt(leg.trucks_required, 10) || getTotalTrucks();
                const totalFreight = leg.total_freight_cost || 0;
                const endDate = leg.end_date || '';
                const feeRows = (toStop.fees || []).map((fee, feeIndex) => {
                    return `
                        <div class="fee-row" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}">
                            <div>
                                <label class="form-label" style="font-size: 0.8em;">Fee Type</label>
                                <select class="delivery-select" data-fee-field="fee_type" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}" ${disabledAttr}>
                                    <option value="receiving" ${fee.fee_type === 'receiving' ? 'selected' : ''}>Receiving</option>
                                    <option value="storage" ${fee.fee_type === 'storage' ? 'selected' : ''}>Storage</option>
                                    <option value="outbound" ${fee.fee_type === 'outbound' ? 'selected' : ''}>Outbound</option>
                                    <option value="customs" ${fee.fee_type === 'customs' ? 'selected' : ''}>Customs</option>
                                    <option value="handling" ${fee.fee_type === 'handling' ? 'selected' : ''}>Handling</option>
                                    <option value="other" ${fee.fee_type === 'other' ? 'selected' : ''}>Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.8em;">Description</label>
                                <input type="text" class="delivery-input" data-fee-field="fee_name" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}" value="${escapeHtml(fee.fee_name || '')}" placeholder="Fee description" ${disabledAttr}>
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.8em;">Rate</label>
                                <input type="number" class="delivery-input" data-fee-field="rate" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}" value="${fee.rate || ''}" placeholder="$0" step="0.01" min="0" ${disabledAttr}>
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.8em;">Per</label>
                                <select class="delivery-select" data-fee-field="rate_unit" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}" ${disabledAttr}>
                                    <option value="per_pallet" ${fee.rate_unit === 'per_pallet' ? 'selected' : ''}>Pallet</option>
                                    <option value="per_module" ${fee.rate_unit === 'per_module' ? 'selected' : ''}>Module</option>
                                    <option value="per_truck" ${fee.rate_unit === 'per_truck' ? 'selected' : ''}>Truck</option>
                                    <option value="flat" ${fee.rate_unit === 'flat' ? 'selected' : ''}>Flat</option>
                                </select>
                            </div>
                            ${canEdit ? `
                            <button type="button" class="btn btn-sm btn-danger" data-action="remove-fee" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                            ` : ''}
                        </div>
                    `;
                }).join('');

                const showActions = canEdit && !isDestination && toStop.id === stops[stops.length - 2]?.id;
                const removeButton = canEdit && !isDestination
                    ? `<button type="button" class="btn btn-sm btn-danger" data-action="remove-stop" data-stop-id="${toStop.id}">Remove Warehouse</button>`
                    : '';

                const destinationTypeInput = isDestination
                    ? `<input class="delivery-input" value="Project Site" readonly>`
                    : `
                        <select class="delivery-select" data-stop-id="${toStop.id}" data-stop-field="stop_type" ${disabledAttr}>
                            <option value="warehouse" ${toStop.stop_type === 'warehouse' ? 'selected' : ''}>Warehouse</option>
                            <option value="port" ${toStop.stop_type === 'port' ? 'selected' : ''}>Port</option>
                            <option value="customs" ${toStop.stop_type === 'customs' ? 'selected' : ''}>Customs Facility</option>
                        </select>
                    `;

                const locationNameInput = isDestination
                    ? `<input class="delivery-input" value="${escapeHtml(toStop.location_name || 'Project Site')}" readonly>`
                    : `<input class="delivery-input" data-stop-id="${toStop.id}" data-stop-field="location_name" value="${escapeHtml(toStop.location_name || '')}" placeholder="Warehouse name" ${disabledAttr}>`;

                const locationAddressInput = isDestination
                    ? `<input class="delivery-input" value="${escapeHtml(toStop.location_address || '')}" readonly>`
                    : `<input class="delivery-input" data-stop-id="${toStop.id}" data-stop-field="location_address" value="${escapeHtml(toStop.location_address || '')}" placeholder="Street, city, state" ${disabledAttr}>`;

                segments.push(`
                    <div class="delivery-step" data-from-stop-id="${fromStop.id}" data-to-stop-id="${toStop.id}">
                        <div class="delivery-step-header">
                            <div class="delivery-step-title">Delivery ${i + 1}</div>
                            <div class="delivery-badge">${milestone.label}</div>
                            ${removeButton}
                        </div>
                        <div class="delivery-grid">
                            <div class="delivery-field">
                                <label>From</label>
                                <div class="delivery-location">
                                    <strong>${escapeHtml(fromStop.location_name || 'Manufacturer')}</strong>
                                    <small>${escapeHtml(fromStop.location_address || 'Set from module manufacturer')}</small>
                                </div>
                            </div>
                            <div class="delivery-field">
                                <label>Destination Type</label>
                                ${destinationTypeInput}
                            </div>
                            <div class="delivery-field">
                                <label>Destination Name</label>
                                ${locationNameInput}
                            </div>
                            <div class="delivery-field">
                                <label>Destination Address</label>
                                ${locationAddressInput}
                            </div>
                        </div>
                        <div class="delivery-schedule">
                            <div class="delivery-grid">
                                <div class="delivery-field">
                                    <label>Transport Mode</label>
                                    <select class="delivery-select" data-leg-id="${leg.id}" data-leg-field="transport_mode" ${disabledAttr}>
                                        <option value="truck" ${leg.transport_mode === 'truck' ? 'selected' : ''}>Truck</option>
                                        <option value="ocean" ${leg.transport_mode === 'ocean' ? 'selected' : ''}>Ocean</option>
                                        <option value="rail" ${leg.transport_mode === 'rail' ? 'selected' : ''}>Rail</option>
                                        <option value="air" ${leg.transport_mode === 'air' ? 'selected' : ''}>Air</option>
                                    </select>
                                </div>
                                <div class="delivery-field">
                                    <label>Start Date</label>
                                    <input type="text" class="delivery-input flatpickr-date" data-leg-id="${leg.id}" data-leg-field="start_date" value="${leg.start_date || ''}" placeholder="Select date" ${disabledAttr}>
                                </div>
                                <div class="delivery-field">
                                    <label>Cadence</label>
                                    <div class="delivery-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                                        <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="delivery_rate" value="${leg.delivery_rate || ''}" placeholder="Rate" min="0" step="0.1" ${disabledAttr}>
                                        <select class="delivery-select" data-leg-id="${leg.id}" data-leg-field="delivery_rate_unit" ${disabledAttr}>
                                            <option value="per_week" ${leg.delivery_rate_unit === 'per_week' ? 'selected' : ''}>Per Week</option>
                                            <option value="per_day" ${leg.delivery_rate_unit === 'per_day' ? 'selected' : ''}>Per Day</option>
                                            <option value="per_month" ${leg.delivery_rate_unit === 'per_month' ? 'selected' : ''}>Per Month</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="delivery-field">
                                    <label>End Date (Auto)</label>
                                    <input type="text" class="delivery-input" value="${endDate}" readonly>
                                </div>
                            </div>
                            <div class="delivery-grid" style="margin-top: 16px;">
                                <div class="delivery-field">
                                    <label>Trucks Required</label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="trucks_required" value="${leg.trucks_required || ''}" placeholder="${trucks}" min="1" ${disabledAttr}>
                                </div>
                                <div class="delivery-field">
                                    <label>Freight Per Truck</label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="freight_cost_per_truck" value="${leg.freight_cost_per_truck || ''}" placeholder="$0" step="0.01" min="0" ${disabledAttr}>
                                </div>
                                <div class="delivery-field">
                                    <label>Accessorial Per Truck</label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="accessorial_cost_per_truck" value="${leg.accessorial_cost_per_truck || ''}" placeholder="$0" step="0.01" min="0" ${disabledAttr}>
                                </div>
                                <div class="delivery-field">
                                    <label>Total Freight</label>
                                    <input type="text" class="delivery-input" value="$${totalFreight.toFixed(2)}" readonly>
                                </div>
                            </div>
                            <div class="delivery-summary">
                                <span>Estimated deliveries: ${trucks}</span>
                                <span class="delivery-milestone">${milestone.label}</span>
                            </div>
                        </div>
                        ${!isDestination ? `
                            <div class="delivery-fees">
                                <div class="delivery-fees-header">
                                    <strong>Warehouse Fees</strong>
                                    ${canEdit ? `<button type="button" class="btn btn-sm btn-secondary" data-action="add-fee" data-stop-id="${toStop.id}">Add Fee</button>` : ''}
                                </div>
                                ${feeRows || '<p style="margin:0;color:#6c757d;">No fees added yet.</p>'}
                                <div class="delivery-field" style="margin-top: 12px;">
                                    <label style="display:flex; align-items:center; gap:10px;">
                                        <input type="checkbox" data-stop-id="${toStop.id}" data-stop-field="is_customs_clearance" ${toStop.is_customs_clearance ? 'checked' : ''} ${disabledAttr}>
                                        <span>Customs clearance at this stop</span>
                                    </label>
                                </div>
                            </div>
                        ` : ''}
                        ${showActions ? `
                            <div class="delivery-actions">
                                <button type="button" class="btn btn-secondary" data-action="add-warehouse" data-stop-id="${toStop.id}">Add another warehouse delivery</button>
                                <span style="color:#6c757d; font-size:0.85em;">Next leg delivers to project site.</span>
                            </div>
                        ` : ''}
                    </div>
                `);
            }

            container.innerHTML = segments.join('');
            if (emptyState) {
                emptyState.style.display = segments.length ? 'none' : 'block';
            }

            initializeDatePickers();
            bindDeliveryPlanListeners();
        }

        function bindDeliveryPlanListeners() {
            const container = document.getElementById('deliveryPlan');
            if (!container) return;

            container.onchange = function(event) {
                const target = event.target;
                if (target.dataset.stopField) {
                    updateStopField(target);
                } else if (target.dataset.legField) {
                    updateLegField(target);
                } else if (target.dataset.feeField) {
                    updateFeeField(target);
                }
            };

            container.onclick = function(event) {
                const actionButton = event.target.closest('[data-action]');
                if (!actionButton) return;
                const action = actionButton.dataset.action;
                if (action === 'add-fee') {
                    addPlanFee(actionButton.dataset.stopId);
                } else if (action === 'remove-fee') {
                    removePlanFee(actionButton.dataset.stopId, parseInt(actionButton.dataset.feeIndex, 10));
                } else if (action === 'remove-stop') {
                    removeWarehouseStop(actionButton.dataset.stopId);
                } else if (action === 'add-warehouse') {
                    addWarehouseDelivery();
                }
            };
        }

        function updateStopField(element) {
            const stop = workingState.stops.find(s => s.id == element.dataset.stopId);
            if (!stop) return;

            const field = element.dataset.stopField;
            const value = element.type === 'checkbox' ? (element.checked ? 1 : 0) : element.value;
            stop[field] = value;

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        function updateLegField(element) {
            const leg = workingState.legs.find(l => l.id == element.dataset.legId);
            if (!leg) return;

            const field = element.dataset.legField;
            let value = element.value;
            if (['delivery_rate', 'freight_cost_per_truck', 'accessorial_cost_per_truck', 'trucks_required'].includes(field)) {
                value = value === '' ? '' : parseFloat(value);
            }
            leg[field] = value;

            markAsUnsaved();
            renderJourneyPlan();
            updateTimelineChart();
        }

        function updateFeeField(element) {
            const stop = workingState.stops.find(s => s.id == element.dataset.stopId);
            if (!stop || !stop.fees) return;
            const index = parseInt(element.dataset.feeIndex, 10);
            const fee = stop.fees[index];
            if (!fee) return;

            fee[element.dataset.feeField] = element.value;
            fee.estimated_cost = calculateFeeEstimate(fee);

            markAsUnsaved();
            renderJourneyPlan();
            updateTimelineChart();
        }

        function addPlanFee(stopId) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop) return;
            if (!Array.isArray(stop.fees)) stop.fees = [];

            stop.fees.push({
                fee_type: 'storage',
                fee_name: '',
                rate: '',
                rate_unit: 'per_pallet',
                estimated_cost: 0
            });

            markAsUnsaved();
            renderJourneyPlan();
        }

        function removePlanFee(stopId, feeIndex) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop || !stop.fees) return;
            stop.fees.splice(feeIndex, 1);
            markAsUnsaved();
            renderJourneyPlan();
        }

        function removeWarehouseStop(stopId) {
            const stopIndex = workingState.stops.findIndex(s => s.id == stopId);
            if (stopIndex <= 0 || stopIndex >= workingState.stops.length - 1) return;

            const prevStop = workingState.stops[stopIndex - 1];
            const nextStop = workingState.stops[stopIndex + 1];
            workingState.stops.splice(stopIndex, 1);

            workingState.legs = workingState.legs.filter(leg => leg.from_stop_id != stopId && leg.to_stop_id != stopId);
            if (prevStop && nextStop) {
                getLegForStops(prevStop.id, nextStop.id);
            }

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        function addWarehouseDelivery() {
            if (!canEdit) return;
            ensureStops();

            const stops = workingState.stops;
            const destinationIndex = stops.findIndex(stop => stop.stop_type === 'destination');
            if (destinationIndex === -1) return;

            const newStop = {
                id: `warehouse_${Date.now()}`,
                stop_type: 'warehouse',
                location_name: '',
                location_address: '',
                fees: []
            };

            stops.splice(destinationIndex, 0, newStop);

            const prevStop = stops[destinationIndex - 1];
            const destinationStop = stops[destinationIndex + 1];

            workingState.legs = workingState.legs.filter(leg => !(leg.from_stop_id == prevStop?.id && leg.to_stop_id == destinationStop?.id));

            if (prevStop) {
                getLegForStops(prevStop.id, newStop.id);
            }
            if (destinationStop) {
                getLegForStops(newStop.id, destinationStop.id);
            }

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        // ==================== UTILITY FUNCTIONS ====================
        function getTotalPallets() {
            return workingState.moduleAllocations.reduce((sum, a) => sum + (a.pallets || 0), 0);
        }

        function getTotalTrucks() {
            // Use project configuration for accurate truck count
            const palletsPerTruck = projectInfo.palletsPerTruck || 20;
            const pallets = getTotalPallets();

            // If we have the pre-calculated total from PHP, use it
            if (projectInfo.totalTrucks > 0) {
                return Math.ceil(projectInfo.totalTrucks);
            }

            return Math.ceil(pallets / palletsPerTruck) || 1;
        }

        function recalculateCosts() {
            showToast('Costs will be recalculated when you save', 'info');
        }

        function updatePOExecutionDate(dateValue) {
            workingState.poExecutionDate = dateValue;
            markAsUnsaved();
            updateTimelineChart();
            showToast('PO Execution date updated. Remember to save the projection!', 'success');
        }

        function updateModuleAllocationPoDate(allocationId, dateValue) {
            const allocation = workingState.moduleAllocations.find(item => item.id == allocationId);
            if (!allocation) {
                return;
            }

            const normalizedDate = dateValue || '';
            if ((allocation.po_execution_date || '') === normalizedDate) {
                return;
            }

            allocation.po_execution_date = normalizedDate;
            markAsUnsaved();
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

        // ==================== GEOCODING ====================
        function geocodeAddress(address, callback) {
            if (typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
                console.log('Google Geocoder not available');
                callback(null);
                return;
            }

            const geocoder = new google.maps.Geocoder();
            geocoder.geocode({ address: address }, (results, status) => {
                if (status === 'OK' && results[0]) {
                    const location = results[0].geometry.location;
                    callback({ lat: location.lat(), lng: location.lng() });
                } else {
                    console.log('Geocode failed for:', address, status);
                    callback(null);
                }
            });
        }

        // ==================== TIMELINE CHART TABS ====================
        function switchTimelineTab(tabId) {
            // Update tab buttons
            document.querySelectorAll('.timeline-tab').forEach(tab => {
                tab.classList.toggle('active', tab.dataset.tab === tabId);
            });

            // Update panels
            document.querySelectorAll('.timeline-panel').forEach(panel => {
                panel.classList.toggle('active', panel.id === tabId + '-panel');
            });

            // If switching to line chart, render it and weekly projections
            if (tabId === 'line-chart') {
                renderMonthlyForecastChart();
                renderWeeklyProjectionsTable();
            }
        }

        // ==================== MONTHLY FORECAST LINE CHART ====================
        let monthlyChartInstance = null;

        function renderMonthlyForecastChart() {
            const ctx = document.getElementById('monthlyForecastChart');
            if (!ctx) return;

            // Destroy existing chart
            if (monthlyChartInstance) {
                monthlyChartInstance.destroy();
            }

            // Collect monthly data
            const monthlyData = collectMonthlyData();

            if (monthlyData.labels.length === 0) {
                ctx.parentElement.innerHTML = '<div style="text-align: center; padding: 60px; color: #6c757d;">Add dates to your stops and legs to see the monthly forecast.</div>';
                return;
            }

            monthlyChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: monthlyData.labels,
                    datasets: [
                        {
                            label: 'Freight Costs',
                            data: monthlyData.freight,
                            borderColor: '#488C9A',
                            backgroundColor: 'rgba(72, 140, 154, 0.1)',
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'Warehousing Costs',
                            data: monthlyData.warehousing,
                            borderColor: '#E07F3A',
                            backgroundColor: 'rgba(224, 127, 58, 0.1)',
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'Milestone Payments',
                            data: monthlyData.milestones,
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'Cumulative Total',
                            data: monthlyData.cumulative,
                            borderColor: '#293E4C',
                            backgroundColor: 'transparent',
                            borderWidth: 3,
                            borderDash: [5, 5],
                            fill: false,
                            tension: 0.3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': $' + context.raw.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '$' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });
        }

        function collectMonthlyData() {
            const monthlyBuckets = {};
            const milestoneEvents = collectMilestoneEvents();
            const milestoneTotals = collectMilestoneTotals(milestoneEvents);
            const addedMilestones = new Set();

            // Collect freight costs by month
            workingState.legs.forEach(leg => {
                if (leg.start_date) {
                    const monthKey = leg.start_date.substring(0, 7); // YYYY-MM
                    if (!monthlyBuckets[monthKey]) {
                        monthlyBuckets[monthKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }
                    monthlyBuckets[monthKey].freight += leg.total_freight_cost || 0;

                    // Add milestone payment if triggered
                    if (leg.triggers_milestone && milestoneTotals[leg.triggers_milestone] && !addedMilestones.has(leg.triggers_milestone)) {
                        monthlyBuckets[monthKey].milestones += milestoneTotals[leg.triggers_milestone].amount || 0;
                        addedMilestones.add(leg.triggers_milestone);
                    }
                }
            });

            milestoneEvents
                .filter(event => event.trigger === 'po_execution')
                .forEach(event => {
                    const eventDate = event.date || new Date().toISOString().split('T')[0];
                    const monthKey = eventDate.substring(0, 7);
                    if (!monthlyBuckets[monthKey]) {
                        monthlyBuckets[monthKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }
                    monthlyBuckets[monthKey].milestones += event.amount || 0;
                });

            // Collect warehousing costs by month
            workingState.stops.forEach(stop => {
                if (stop.estimated_arrival_date && stop.fees && stop.fees.length > 0) {
                    const monthKey = stop.estimated_arrival_date.substring(0, 7);
                    if (!monthlyBuckets[monthKey]) {
                        monthlyBuckets[monthKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }

                    // Calculate storage fees considering duration
                    stop.fees.forEach(fee => {
                        const cost = fee.estimated_cost || 0;
                        if (fee.fee_type === 'storage' || fee.trigger === 'monthly') {
                            // Distribute monthly fees across months in storage
                            const arrivalDate = new Date(stop.estimated_arrival_date);
                            const nextStopIndex = workingState.stops.indexOf(stop) + 1;
                            const nextStop = workingState.stops[nextStopIndex];
                            const departureDate = nextStop?.estimated_arrival_date
                                ? new Date(nextStop.estimated_arrival_date)
                                : new Date(arrivalDate.getTime() + 30 * 24 * 60 * 60 * 1000);

                            const monthsInStorage = Math.max(1, Math.ceil((departureDate - arrivalDate) / (30 * 24 * 60 * 60 * 1000)));
                            const monthlyFee = cost / monthsInStorage;

                            for (let i = 0; i < monthsInStorage; i++) {
                                const storageMonth = new Date(arrivalDate);
                                storageMonth.setMonth(storageMonth.getMonth() + i);
                                const storageMonthKey = storageMonth.toISOString().substring(0, 7);
                                if (!monthlyBuckets[storageMonthKey]) {
                                    monthlyBuckets[storageMonthKey] = { freight: 0, warehousing: 0, milestones: 0 };
                                }
                                monthlyBuckets[storageMonthKey].warehousing += monthlyFee;
                            }
                        } else {
                            monthlyBuckets[monthKey].warehousing += cost;
                        }
                    });
                }
            });

            // Sort months and prepare arrays
            const sortedMonths = Object.keys(monthlyBuckets).sort();

            const labels = sortedMonths.map(m => {
                const date = new Date(m + '-01');
                return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
            });

            let cumulativeTotal = 0;
            const freight = [];
            const warehousing = [];
            const milestones = [];
            const cumulative = [];

            sortedMonths.forEach(month => {
                const data = monthlyBuckets[month];
                freight.push(data.freight);
                warehousing.push(data.warehousing);
                milestones.push(data.milestones);
                cumulativeTotal += data.freight + data.warehousing + data.milestones;
                cumulative.push(cumulativeTotal);
            });

            return { labels, freight, warehousing, milestones, cumulative };
        }

        // ==================== WEEKLY PROJECTIONS TABLE ====================
        function renderWeeklyProjectionsTable() {
            const tbody = document.getElementById('weeklyProjectionsBody');
            const emptyState = document.getElementById('weeklyEmptyState');
            const tableWrapper = document.querySelector('.weekly-table-wrapper');

            if (!tbody) return;

            // Collect weekly data
            const weeklyData = collectWeeklyData();

            if (weeklyData.length === 0) {
                if (tableWrapper) tableWrapper.style.display = 'none';
                if (emptyState) emptyState.style.display = 'block';
                return;
            }

            if (tableWrapper) tableWrapper.style.display = 'block';
            if (emptyState) emptyState.style.display = 'none';

            let html = '';
            let cumulativeTotal = 0;

            weeklyData.forEach((week, index) => {
                const weeklyTotal = week.freight + week.warehousing + week.milestones;
                cumulativeTotal += weeklyTotal;

                html += `
                    <tr>
                        <td class="week-number">Week ${index + 1}</td>
                        <td class="date-range">${week.dateRange}</td>
                        <td class="amount freight">${formatCurrency(week.freight)}</td>
                        <td class="amount warehousing">${formatCurrency(week.warehousing)}</td>
                        <td class="amount milestone">${formatCurrency(week.milestones)}</td>
                        <td class="weekly-total">${formatCurrency(weeklyTotal)}</td>
                        <td class="cumulative">${formatCurrency(cumulativeTotal)}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        function collectWeeklyData() {
            const weeklyBuckets = {};
            const milestoneEvents = collectMilestoneEvents();
            const milestoneTotals = collectMilestoneTotals(milestoneEvents);
            const addedMilestones = new Set();

            // Helper to get week key from date
            function getWeekKey(dateStr) {
                const date = new Date(dateStr);
                const startOfWeek = new Date(date);
                startOfWeek.setDate(date.getDate() - date.getDay()); // Start of week (Sunday)
                return startOfWeek.toISOString().split('T')[0];
            }

            // Helper to get week end date
            function getWeekEnd(weekStartStr) {
                const start = new Date(weekStartStr);
                const end = new Date(start);
                end.setDate(start.getDate() + 6);
                return end;
            }

            // Collect freight costs by week
            workingState.legs.forEach(leg => {
                if (leg.start_date) {
                    const weekKey = getWeekKey(leg.start_date);
                    if (!weeklyBuckets[weekKey]) {
                        weeklyBuckets[weekKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }
                    weeklyBuckets[weekKey].freight += leg.total_freight_cost || 0;

                    // Add milestone payment if triggered
                    if (leg.triggers_milestone && milestoneTotals[leg.triggers_milestone] && !addedMilestones.has(leg.triggers_milestone)) {
                        weeklyBuckets[weekKey].milestones += milestoneTotals[leg.triggers_milestone].amount || 0;
                        addedMilestones.add(leg.triggers_milestone);
                    }
                }
            });

            milestoneEvents
                .filter(event => event.trigger === 'po_execution')
                .forEach(event => {
                    const eventDate = event.date || new Date().toISOString().split('T')[0];
                    const weekKey = getWeekKey(eventDate);
                    if (!weeklyBuckets[weekKey]) {
                        weeklyBuckets[weekKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }
                    weeklyBuckets[weekKey].milestones += event.amount || 0;
                });

            // Collect warehousing costs by week
            workingState.stops.forEach(stop => {
                if (stop.estimated_arrival_date && stop.fees && stop.fees.length > 0) {
                    const weekKey = getWeekKey(stop.estimated_arrival_date);
                    if (!weeklyBuckets[weekKey]) {
                        weeklyBuckets[weekKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }

                    stop.fees.forEach(fee => {
                        const cost = fee.estimated_cost || 0;
                        if (fee.fee_type === 'storage' || fee.trigger === 'monthly') {
                            // Distribute storage fees across weeks
                            const arrivalDate = new Date(stop.estimated_arrival_date);
                            const nextStopIndex = workingState.stops.indexOf(stop) + 1;
                            const nextStop = workingState.stops[nextStopIndex];
                            const departureDate = nextStop?.estimated_arrival_date
                                ? new Date(nextStop.estimated_arrival_date)
                                : new Date(arrivalDate.getTime() + 30 * 24 * 60 * 60 * 1000);

                            const weeksInStorage = Math.max(1, Math.ceil((departureDate - arrivalDate) / (7 * 24 * 60 * 60 * 1000)));
                            const weeklyFee = cost / weeksInStorage;

                            for (let i = 0; i < weeksInStorage; i++) {
                                const storageWeek = new Date(arrivalDate);
                                storageWeek.setDate(storageWeek.getDate() + (i * 7));
                                const storageWeekKey = getWeekKey(storageWeek.toISOString().split('T')[0]);
                                if (!weeklyBuckets[storageWeekKey]) {
                                    weeklyBuckets[storageWeekKey] = { freight: 0, warehousing: 0, milestones: 0 };
                                }
                                weeklyBuckets[storageWeekKey].warehousing += weeklyFee;
                            }
                        } else {
                            weeklyBuckets[weekKey].warehousing += cost;
                        }
                    });
                }
            });

            // Sort weeks and prepare array with date ranges
            const sortedWeeks = Object.keys(weeklyBuckets).sort();

            return sortedWeeks.map(weekStart => {
                const weekEnd = getWeekEnd(weekStart);
                const startFormatted = new Date(weekStart).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                const endFormatted = weekEnd.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

                return {
                    dateRange: `${startFormatted} - ${endFormatted}`,
                    freight: weeklyBuckets[weekStart].freight,
                    warehousing: weeklyBuckets[weekStart].warehousing,
                    milestones: weeklyBuckets[weekStart].milestones
                };
            });
        }

        function formatCurrency(amount) {
            if (amount === 0) return '-';
            return '$' + amount.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }

        // ==================== WAREHOUSE CARD COLLAPSE ====================
        function toggleWarehouseCard(stopId) {
            const card = document.querySelector(`.warehouse-stop-card[data-stop-id="${stopId}"]`);
            const stop = workingState.stops.find(item => item.id == stopId);
            if (card) {
                card.classList.toggle('collapsed');
            }
            if (stop) {
                stop.is_collapsed = card ? card.classList.contains('collapsed') : !stop.is_collapsed;
            }
        }

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
            syncPlanState();
            if (!map) return;

            // Clear existing markers and polylines
            mapMarkers.forEach(m => m.setMap(null));
            mapPolylines.forEach(p => p.setMap(null));
            mapMarkers = [];
            mapPolylines = [];

            const stops = workingState.stops || [];
            const placeholder = document.getElementById('mapPlaceholder');
            if (stops.length === 0) {
                if (placeholder) {
                    placeholder.style.display = 'block';
                }
                return;
            }

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

            if (pathCoordinates.length === 0) {
                if (placeholder) {
                    placeholder.style.display = 'block';
                }
                return;
            }

            if (placeholder) {
                placeholder.style.display = 'none';
            }

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
            syncPlanState();
            const container = document.getElementById('timelineChart');
            if (!container) return;

            const events = [];
            let totalFreight = 0;
            let totalWarehousing = 0;
            const milestoneEvents = collectMilestoneEvents();
            const milestoneTotals = collectMilestoneTotals(milestoneEvents);
            const totalMilestones = milestoneEvents.reduce((sum, event) => sum + (event.amount || 0), 0);
            const addedMilestones = new Set();

            const poEventsByDate = {};
            milestoneEvents
                .filter(event => event.trigger === 'po_execution')
                .forEach(event => {
                    const date = event.date || new Date().toISOString().split('T')[0];
                    if (!poEventsByDate[date]) {
                        poEventsByDate[date] = { amount: 0, label: event.label || getMilestoneLabel('po_execution') };
                    }
                    poEventsByDate[date].amount += event.amount || 0;
                });

            // Collect events from stops (warehousing fees)
            workingState.stops.forEach((stop, stopIndex) => {
                if (stop.stop_type === 'origin' || stop.stop_type === 'destination') return;

                const stopFees = (stop.fees || []).reduce((sum, fee) => sum + (parseFloat(fee.estimated_cost) || 0), 0);

                // Calculate fees including monthly fees across time
                const arrivalDate = stop.estimated_arrival_date || null;
                const nextStop = workingState.stops[stopIndex + 1];
                const departureDate = nextStop?.estimated_arrival_date || null;

                if (stopFees > 0) {
                    totalWarehousing += stopFees;

                    // Add warehousing event
                    events.push({
                        date: arrivalDate || new Date().toISOString().split('T')[0],
                        label: stop.location_name || 'Warehouse',
                        type: 'warehousing',
                        amount: stopFees
                    });
                }
            });

            // Collect events from legs (freight costs)
            workingState.legs.forEach(leg => {
                const legCost = parseFloat(leg.total_freight_cost) || 0;
                const legDate = leg.start_date || leg.end_date || null;

                if (legCost > 0) {
                    totalFreight += legCost;

                    events.push({
                        date: legDate || new Date().toISOString().split('T')[0],
                        label: getTransportModeLabel(leg.transport_mode),
                        type: 'freight',
                        amount: legCost
                    });
                }

                // Add milestone as a separate event if triggered
                if (leg.triggers_milestone && milestoneTotals[leg.triggers_milestone] && !addedMilestones.has(leg.triggers_milestone)) {
                    const milestoneInfo = milestoneTotals[leg.triggers_milestone];
                    const milestoneAmount = milestoneInfo.amount || 0;
                    if (milestoneAmount > 0) {
                        addedMilestones.add(leg.triggers_milestone);

                        events.push({
                            date: leg.end_date || leg.start_date || new Date().toISOString().split('T')[0],
                            label: milestoneInfo.label || getMilestoneLabel(leg.triggers_milestone),
                            type: 'milestone',
                            amount: milestoneAmount
                        });
                    }
                }
            });

            Object.entries(poEventsByDate).forEach(([date, info]) => {
                if (info.amount <= 0) return;
                events.push({
                    date,
                    label: info.label,
                    type: 'milestone',
                    amount: info.amount
                });
            });

            // Sort by date
            events.sort((a, b) => new Date(a.date) - new Date(b.date));

            // Handle empty state
            if (events.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #6c757d; width: 100%;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" style="margin-bottom: 12px;">
                            <line x1="18" y1="20" x2="18" y2="10"/>
                            <line x1="12" y1="20" x2="12" y2="4"/>
                            <line x1="6" y1="20" x2="6" y2="14"/>
                        </svg>
                        <p style="margin: 0;">Add costs to your stops and legs to see the timeline chart.</p>
                    </div>
                `;
            } else {
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
                            </div>
                        </div>
                    `;
                }).join('');
            }

            // Update cumulative displays
            const grandTotal = totalFreight + totalWarehousing + totalMilestones;
            document.getElementById('totalFreightDisplay').textContent = '$' + totalFreight.toLocaleString();
            document.getElementById('totalWarehousingDisplay').textContent = '$' + totalWarehousing.toLocaleString();
            document.getElementById('totalMilestonesDisplay').textContent = '$' + totalMilestones.toLocaleString();
            document.getElementById('grandTotalDisplay').textContent = '$' + grandTotal.toLocaleString();

            // Also update the badge in the collapsible header
            const grandTotalBadge = document.getElementById('grandTotalBadge');
            if (grandTotalBadge) {
                grandTotalBadge.textContent = '$' + grandTotal.toLocaleString();
            }
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
                'po_execution': 'PO Execution',
                'shipping': 'Shipping',
                'customs_cleared': 'Customs',
                'project_delivery': 'Delivery'
            };
            return labels[milestone] || milestone;
        }

        function collectMilestoneEvents() {
            const events = [];

            workingState.moduleAllocations.forEach(alloc => {
                const contractValue = parseFloat(alloc.contract_value) || 0;
                if (!contractValue || !Array.isArray(alloc.milestones)) {
                    return;
                }

                alloc.milestones.forEach(milestone => {
                    const trigger = milestone.trigger_event;
                    const percentage = parseFloat(milestone.percentage) || 0;
                    if (!trigger || percentage <= 0) {
                        return;
                    }

                    const poDate = alloc.po_execution_date || alloc.poExecutionDate || '';
                    const eventDate = trigger === 'po_execution' ? poDate : '';

                    events.push({
                        trigger,
                        amount: contractValue * (percentage / 100),
                        label: milestone.milestone_name || getMilestoneLabel(trigger),
                        date: eventDate
                    });
                });
            });

            return events;
        }

        function collectMilestoneTotals(milestoneEvents = null) {
            const events = milestoneEvents || collectMilestoneEvents();
            const totals = {};

            events.forEach(event => {
                if (!totals[event.trigger]) {
                    totals[event.trigger] = {
                        amount: 0,
                        label: event.label || getMilestoneLabel(event.trigger)
                    };
                }
                totals[event.trigger].amount += event.amount;
            });

            return totals;
        }

        function getMilestoneAmount(milestone, milestoneTotals = null) {
            const totals = milestoneTotals || collectMilestoneTotals();
            return totals[milestone]?.amount || 0;
        }

        // Note: formatDate is defined earlier in the file

        // ==================== COMPARISON TABLE ====================
        function updateComparisonTable() {
            syncPlanState();
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
                        renderJourneyPlan();
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

        // ==================== UNSAVED CHANGES TRACKING ====================
        let hasUnsavedChanges = false;

        function markAsUnsaved() {
            hasUnsavedChanges = true;
            // Show unsaved indicator
            const indicator = document.getElementById('autosaveIndicator');
            if (indicator) {
                indicator.classList.add('visible');
                indicator.classList.remove('saving', 'saved');
                indicator.querySelector('span').textContent = 'Unsaved changes';
            }
        }

        function markAsSaved() {
            hasUnsavedChanges = false;
            const indicator = document.getElementById('autosaveIndicator');
            if (indicator) {
                indicator.classList.remove('visible', 'saving');
            }
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
                if (canEdit) addWarehouseDelivery();
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
