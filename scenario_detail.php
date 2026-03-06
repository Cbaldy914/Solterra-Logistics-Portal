<?php
/**
 * Scenario Detail Page
 *
 * This page allows users to view and manage a planning scenario including:
 * - Framework contracts and period constraints
 * - Planned projects with delivery windows
 * - Inventory pools
 * - Module allocations across projects
 * - Activation requests
 */

// Initialize session
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Get Google Maps API key for location autocomplete
$google_maps_api_key = getGoogleMapsApiKey();

// Get scenario ID
$scenario_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';

if (!$scenario_id) {
    header("Location: project_planning.php");
    exit();
}

// Determine which accounts the user belongs to
$accountIds = [];
if ($role === 'global_admin') {
    $resultAccts = $conn->query("SELECT id FROM customer_accounts");
    if ($resultAccts) {
        while ($row = $resultAccts->fetch_assoc()) {
            $accountIds[] = (int)$row['id'];
        }
    }
} else {
    $sqlAccts = "SELECT account_id FROM customer_account_users WHERE user_id = ?";
    $stmtAccts = $conn->prepare($sqlAccts);
    $stmtAccts->bind_param("i", $user_id);
    $stmtAccts->execute();
    $resultAccts = $stmtAccts->get_result();
    while ($row = $resultAccts->fetch_assoc()) {
        $accountIds[] = (int)$row['account_id'];
    }
    $stmtAccts->close();
}

// Fetch the scenario and verify access
$scenario = null;
$stmt = $conn->prepare("
    SELECT ps.*, u.first_name, u.last_name, u.username, ca.name as account_name
    FROM planning_scenarios ps
    LEFT JOIN users u ON ps.created_by = u.id
    LEFT JOIN customer_accounts ca ON ps.account_id = ca.id
    WHERE ps.id = ?
");
$stmt->bind_param("i", $scenario_id);
$stmt->execute();
$result = $stmt->get_result();
$scenario = $result->fetch_assoc();
$stmt->close();

// Verify access
if (!$scenario || !in_array($scenario['account_id'], $accountIds)) {
    header("Location: project_planning.php");
    exit();
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        $message = 'Invalid request token.';
        $messageType = 'error';
    } else {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add_manufacturer':
            $name = trim($_POST['manufacturer_name'] ?? '');
            if (!empty($name)) {
                $stmt = $conn->prepare("INSERT INTO planning_manufacturers (scenario_id, account_id, name, created_by) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iisi", $scenario_id, $scenario['account_id'], $name, $user_id);
                if ($stmt->execute()) {
                    $message = "Manufacturer added successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Error adding manufacturer: " . $conn->error;
                    $messageType = 'error';
                }
                $stmt->close();
            }
            break;

        case 'add_contract':
            $manufacturerId = (int)($_POST['manufacturer_id'] ?? 0);
            $contractName = trim($_POST['contract_name'] ?? '');
            $totalMw = (float)($_POST['total_mw'] ?? 0);
            $startDate = $_POST['start_date'] ?? null;
            $endDate = $_POST['end_date'] ?? null;

            // Set defaults if dates are empty
            if (empty($startDate)) $startDate = date('Y-m-d');
            if (empty($endDate)) $endDate = date('Y-m-d', strtotime('+1 year'));

            if (!empty($contractName)) {
                $stmt = $conn->prepare("
                    INSERT INTO planning_framework_contracts
                    (scenario_id, account_id, planning_manufacturer_id, name, total_mw_dc, contract_start_date, contract_end_date, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $mfrId = $manufacturerId > 0 ? $manufacturerId : null;
                $stmt->bind_param("iiisdssi", $scenario_id, $scenario['account_id'], $mfrId, $contractName, $totalMw, $startDate, $endDate, $user_id);
                if ($stmt->execute()) {
                    $message = "Contract added successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Error adding contract: " . $conn->error;
                    $messageType = 'error';
                }
                $stmt->close();
            }
            break;

        case 'add_project':
            $projectName = trim($_POST['project_name'] ?? '');
            $requiredMw = (float)($_POST['required_mw'] ?? 0);
            $deliveryStart = $_POST['delivery_start'] ?? null;
            $deliveryEnd = $_POST['delivery_end'] ?? null;
            $location = trim($_POST['location'] ?? '');

            // Set defaults if dates are empty
            if (empty($deliveryStart)) $deliveryStart = date('Y-m-d');
            if (empty($deliveryEnd)) $deliveryEnd = date('Y-m-d', strtotime('+6 months'));

            if (!empty($projectName)) {
                $stmt = $conn->prepare("
                    INSERT INTO planning_projects
                    (scenario_id, account_id, name, required_mw_dc, primary_delivery_start, primary_delivery_end, location_region, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iisdsssi", $scenario_id, $scenario['account_id'], $projectName, $requiredMw, $deliveryStart, $deliveryEnd, $location, $user_id);
                if ($stmt->execute()) {
                    $message = "Project added successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Error adding project: " . $conn->error;
                    $messageType = 'error';
                }
                $stmt->close();
            }
            break;

        case 'add_allocation':
            $projectId = (int)($_POST['project_id'] ?? 0);
            $contractId = (int)($_POST['contract_id'] ?? 0);
            $allocatedMw = (float)($_POST['allocated_mw'] ?? 0);
            $periodStart = $_POST['period_start'] ?? null;
            $periodEnd = $_POST['period_end'] ?? null;

            // Set defaults if dates are empty
            if (empty($periodStart)) $periodStart = date('Y-m-d');
            if (empty($periodEnd)) $periodEnd = date('Y-m-d', strtotime('+3 months'));

            if ($projectId > 0 && $contractId > 0 && $allocatedMw > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO planning_allocations
                    (scenario_id, planning_project_id, contract_id, period_start, period_end, allocated_mw_dc, source_type)
                    VALUES (?, ?, ?, ?, ?, ?, 'contract')
                ");
                $stmt->bind_param("iiissd", $scenario_id, $projectId, $contractId, $periodStart, $periodEnd, $allocatedMw);
                if ($stmt->execute()) {
                    $message = "Allocation added successfully!";
                    $messageType = 'success';
                    updateScenarioTotals($conn, $scenario_id);
                } else {
                    $message = "Error adding allocation: " . $conn->error;
                    $messageType = 'error';
                }
                $stmt->close();
            }
            break;

        case 'delete_allocation':
            $allocationId = (int)($_POST['allocation_id'] ?? 0);
            if ($allocationId > 0) {
                $stmt = $conn->prepare("DELETE FROM planning_allocations WHERE id = ? AND scenario_id = ?");
                $stmt->bind_param("ii", $allocationId, $scenario_id);
                if ($stmt->execute()) {
                    $message = "Allocation deleted.";
                    $messageType = 'success';
                    updateScenarioTotals($conn, $scenario_id);
                }
                $stmt->close();
            }
            break;

        case 'update_scenario':
            $name = trim($_POST['scenario_name'] ?? '');
            $description = trim($_POST['scenario_description'] ?? '');
            if (!empty($name)) {
                $stmt = $conn->prepare("UPDATE planning_scenarios SET name = ?, description = ? WHERE id = ?");
                $stmt->bind_param("ssi", $name, $description, $scenario_id);
                if ($stmt->execute()) {
                    $message = "Scenario updated successfully!";
                    $messageType = 'success';
                    $scenario['name'] = $name;
                    $scenario['description'] = $description;
                }
                $stmt->close();
            }
            break;

        case 'request_activation':
            $notes = trim($_POST['activation_notes'] ?? '');
            $stmt = $conn->prepare("
                INSERT INTO planning_activation_requests
                (scenario_id, account_id, requested_by, notes)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("iiis", $scenario_id, $scenario['account_id'], $user_id, $notes);
            if ($stmt->execute()) {
                $message = "Activation request submitted! An admin will review your scenario.";
                $messageType = 'success';
            } else {
                $message = "Error submitting activation request: " . $conn->error;
                $messageType = 'error';
            }
            $stmt->close();
            break;
    }
    }
}

// Helper function to update scenario totals
function updateScenarioTotals($conn, $scenarioId) {
    $sql = "
        UPDATE planning_scenarios ps
        SET
            total_allocated_mw = (SELECT COALESCE(SUM(allocated_mw_dc), 0) FROM planning_allocations WHERE scenario_id = ?),
            total_planned_mw = (SELECT COALESCE(SUM(required_mw_dc), 0) FROM planning_projects WHERE scenario_id = ?),
            total_contracted_mw = (SELECT COALESCE(SUM(total_mw_dc), 0) FROM planning_framework_contracts WHERE scenario_id = ?)
        WHERE id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $scenarioId, $scenarioId, $scenarioId, $scenarioId);
    $stmt->execute();
    $stmt->close();
}

// Fetch manufacturers for this scenario
$manufacturers = [];
$stmt = $conn->prepare("SELECT * FROM planning_manufacturers WHERE scenario_id = ? ORDER BY name");
$stmt->bind_param("i", $scenario_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $manufacturers[] = $row;
}
$stmt->close();

// Fetch contracts for this scenario with allocation totals
$contracts = [];
$stmt = $conn->prepare("
    SELECT pfc.*, pm.name as manufacturer_name,
           (SELECT COALESCE(SUM(allocated_mw_dc), 0) FROM planning_allocations pa WHERE pa.contract_id = pfc.id) as allocated_mw
    FROM planning_framework_contracts pfc
    LEFT JOIN planning_manufacturers pm ON pfc.planning_manufacturer_id = pm.id
    WHERE pfc.scenario_id = ?
    ORDER BY pfc.name
");
$stmt->bind_param("i", $scenario_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $contracts[] = $row;
}
$stmt->close();

// Fetch planned projects for this scenario
$plannedProjects = [];
$stmt = $conn->prepare("
    SELECT pp.*,
           (SELECT COALESCE(SUM(allocated_mw_dc), 0) FROM planning_allocations pa WHERE pa.planning_project_id = pp.id) as allocated_mw
    FROM planning_projects pp
    WHERE pp.scenario_id = ?
    ORDER BY pp.name
");
$stmt->bind_param("i", $scenario_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $plannedProjects[] = $row;
}
$stmt->close();

// Fetch allocations for this scenario
$allocations = [];
$stmt = $conn->prepare("
    SELECT pa.*, pp.name as project_name, pfc.name as contract_name, pm.name as manufacturer_name
    FROM planning_allocations pa
    JOIN planning_projects pp ON pa.planning_project_id = pp.id
    JOIN planning_framework_contracts pfc ON pa.contract_id = pfc.id
    LEFT JOIN planning_manufacturers pm ON pfc.planning_manufacturer_id = pm.id
    WHERE pa.scenario_id = ?
    ORDER BY pp.name, pa.period_start
");
$stmt->bind_param("i", $scenario_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $allocations[] = $row;
}
$stmt->close();

// Fetch active projects for reference
$activeProjects = [];
if (!empty($accountIds)) {
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $sql = "SELECT id, project_name, project_size FROM projects WHERE account_id IN ($placeholders) ORDER BY project_name";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($accountIds));
    $stmt->bind_param($types, ...$accountIds);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $activeProjects[] = $row;
    }
    $stmt->close();
}

// Check for pending activation request
$pendingActivation = null;
$stmt = $conn->prepare("SELECT * FROM planning_activation_requests WHERE scenario_id = ? AND status = 'pending' LIMIT 1");
$stmt->bind_param("i", $scenario_id);
$stmt->execute();
$result = $stmt->get_result();
$pendingActivation = $result->fetch_assoc();
$stmt->close();

// Calculate summary stats
$totalPlannedMw = array_sum(array_column($plannedProjects, 'required_mw_dc'));
$totalAllocatedMw = array_sum(array_column($allocations, 'allocated_mw_dc'));
$totalContractedMw = array_sum(array_column($contracts, 'total_mw_dc'));

// Calculate what's still needed
// Projects still need: total required by projects - what's been allocated to them
$projectsUnallocatedMw = $totalPlannedMw - $totalAllocatedMw;

// Contracts still available: total contracted - what's been allocated from them
$contractsUnallocatedMw = $totalContractedMw - $totalAllocatedMw;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($scenario['name']); ?> - Project Planning</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background: #f7f9fb; }

        /* Page Header - Freight Style */
        .planning-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 22px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .planning-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #fbb040 0%, #f7931e 100%);
        }
        .planning-header__content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        .planning-header__left {
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .planning-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #fbb040 0%, #f7931e 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 34px;
            box-shadow: 0 12px 24px rgba(251, 176, 64, 0.3);
        }
        .planning-title {
            margin: 0;
            font-size: 2em;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .planning-sub {
            margin: 6px 0 0;
            color: #556;
            max-width: 520px;
        }
        .planning-actions {
            display: flex;
            gap: 10px;
        }

        .scenario-header-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.9em;
        }

        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4);
        }

        .btn-activate {
            background: linear-gradient(135deg, #fbb040 0%, #f7931e 100%);
            color: white;
        }

        .btn-activate:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(251, 176, 64, 0.4);
        }

        .btn-secondary {
            background: #f1f3f4;
            color: #495057;
        }

        .btn-secondary:hover {
            background: #e9ecef;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-sm {
            padding: 8px 14px;
            font-size: 0.85em;
        }

        /* Stats Summary */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: #ffffff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            border: 1px solid #e9ecef;
            text-align: center;
        }

        .stat-box .value {
            font-size: 1.8em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
        }

        .stat-box .label {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 5px;
        }

        .stat-box.warning .value {
            color: #f7931e;
        }

        .stat-box.danger .value {
            color: #dc3545;
        }

        .stat-box.success .value {
            color: #28a745;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 5px;
            background: #f1f3f4;
            padding: 5px;
            border-radius: 12px;
            margin-bottom: 25px;
            overflow-x: auto;
        }

        .tab {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .tab:hover {
            color: #293E4C;
            background: rgba(255, 255, 255, 0.5);
        }

        .tab.active {
            background: #ffffff;
            color: #488C9A;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        /* Content Cards */
        .content-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            overflow: hidden;
            margin-bottom: 25px;
        }

        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }

        .card-header h2 {
            margin: 0;
            font-size: 1.2em;
            color: #293E4C;
        }

        .card-header .header-subtitle {
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 400;
        }

        .card-body {
            padding: 25px;
        }

        /* Text color utilities */
        .text-success {
            color: #28a745 !important;
        }

        .text-warning {
            color: #f7931e !important;
        }

        .text-danger {
            color: #dc3545 !important;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 12px 15px;
            background: #f8f9fa;
            font-weight: 600;
            color: #293E4C;
            font-size: 0.9em;
            border-bottom: 2px solid #e9ecef;
        }

        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f3f4;
            color: #495057;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .data-table .actions {
            text-align: right;
        }

        /* Forms */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .planning-form-group {
            margin-bottom: 15px;
        }

        .planning-form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #293E4C;
            font-size: 0.9em;
        }

        .planning-form-group input,
        .planning-form-group select,
        .planning-form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.95em;
            font-family: inherit;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }

        .planning-form-group input:focus,
        .planning-form-group select:focus,
        .planning-form-group textarea:focus {
            outline: none;
            border-color: #488C9A;
        }

        /* Inline Forms */
        .inline-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }

        .inline-form .planning-form-group {
            margin-bottom: 0;
            flex: 1;
            min-width: 150px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
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
            border: 1px solid #ffeeba;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        /* Badge */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-draft {
            background: #f1f3f4;
            color: #6c757d;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state-icon {
            font-size: 3em;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        /* Progress Bar for Allocations */
        .allocation-progress {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .progress-bar-container {
            flex: 1;
            background: #e9ecef;
            border-radius: 8px;
            height: 8px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #488C9A, #5AA8B7);
            border-radius: 8px;
            transition: width 0.5s ease;
        }

        .progress-bar-fill.warning {
            background: linear-gradient(90deg, #fbb040, #f7931e);
        }

        .progress-bar-fill.over {
            background: linear-gradient(90deg, #dc3545, #c82333);
        }

        .progress-percentage {
            font-weight: 600;
            font-size: 0.9em;
            color: #495057;
            min-width: 50px;
            text-align: right;
        }

        /* Planning Modal Styles - scoped to avoid portal.css conflicts */
        .planning-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .planning-modal-overlay.active {
            display: flex;
        }

        .planning-modal {
            background: #ffffff !important;
            border-radius: 16px !important;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.2) !important;
            max-width: 500px !important;
            width: 90% !important;
            max-height: 90vh !important;
            z-index: 2001 !important;
            display: block !important;
            position: relative !important;
            margin: 0 !important;
            padding: 0 !important;
            left: auto !important;
            top: auto !important;
            height: auto !important;
            overflow: visible !important;
        }

        .planning-modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .planning-modal-header h2 {
            margin: 0;
            font-size: 1.3em;
            color: #293E4C;
        }

        .planning-modal-close {
            background: none;
            border: none;
            font-size: 1.5em;
            color: #6c757d;
            cursor: pointer;
        }

        .planning-modal-body {
            padding: 25px;
        }

        .planning-modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .scenario-header {
                flex-direction: column;
                gap: 20px;
            }

            .scenario-header-actions {
                width: 100%;
            }

            .scenario-header-actions .btn {
                flex: 1;
            }

            .tabs {
                flex-wrap: nowrap;
            }

            .tab {
                padding: 10px 16px;
                font-size: 0.85em;
            }

            .inline-form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
    require_once 'components/breadcrumbs.php';
    echo slp_render_breadcrumbs([
        'current_label' => htmlspecialchars($scenario['name']),
        'extra' => [
            ['label' => 'Project Planning', 'url' => 'project_planning.php']
        ]
    ]);
    ?>

    <!-- Alert Messages -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($pendingActivation): ?>
        <div class="alert alert-info">
            <strong>Activation Pending:</strong> Your activation request is being reviewed by an administrator.
            Submitted <?php echo date('M j, Y', strtotime($pendingActivation['created_at'])); ?>
        </div>
    <?php endif; ?>

    <!-- Page Header - Freight Style -->
    <section class="planning-header">
        <div class="planning-header__content">
            <div class="planning-header__left">
                <div class="planning-icon">📋</div>
                <div>
                    <h1 class="planning-title"><?php echo htmlspecialchars($scenario['name']); ?></h1>
                    <p class="planning-sub">
                        <?php echo htmlspecialchars($scenario['description'] ?? 'Plan your module allocations, manage contracts, and optimize your project pipeline.'); ?>
                        <span class="badge <?php echo $scenario['is_active'] ? 'badge-active' : 'badge-draft'; ?>" style="margin-left: 10px;">
                            <?php echo $scenario['is_active'] ? 'Active' : 'Draft'; ?>
                        </span>
                    </p>
                </div>
            </div>
            <div class="planning-actions">
                <?php if (!$pendingActivation && !$scenario['is_active']): ?>
                    <button class="btn btn-activate" onclick="openActivationModal()">Request Activation</button>
                <?php endif; ?>
                <a href="?id=<?php echo $scenario_id; ?>&tab=settings" class="btn btn-secondary">Settings</a>
            </div>
        </div>
    </section>

    <!-- Stats Summary -->
    <div class="stats-row">
        <div class="stat-box">
            <p class="value"><?php echo number_format($totalContractedMw, 1); ?></p>
            <p class="label">Contracted MW</p>
        </div>
        <div class="stat-box">
            <p class="value"><?php echo number_format($totalPlannedMw, 1); ?></p>
            <p class="label">Projects MW</p>
        </div>
        <div class="stat-box <?php echo $contractsUnallocatedMw != 0 ? 'danger' : 'success'; ?>">
            <p class="value"><?php echo number_format($contractsUnallocatedMw, 1); ?></p>
            <p class="label">Modules Unallocated</p>
        </div>
        <div class="stat-box <?php echo $projectsUnallocatedMw > 0 ? 'danger' : 'success'; ?>">
            <p class="value"><?php echo number_format($projectsUnallocatedMw, 1); ?></p>
            <p class="label">Projects Unallocated</p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?id=<?php echo $scenario_id; ?>&tab=overview" class="tab <?php echo $activeTab === 'overview' ? 'active' : ''; ?>">Overview</a>
        <a href="?id=<?php echo $scenario_id; ?>&tab=contracts" class="tab <?php echo $activeTab === 'contracts' ? 'active' : ''; ?>">Contracts</a>
        <a href="?id=<?php echo $scenario_id; ?>&tab=projects" class="tab <?php echo $activeTab === 'projects' ? 'active' : ''; ?>">Projects</a>
        <a href="?id=<?php echo $scenario_id; ?>&tab=allocations" class="tab <?php echo $activeTab === 'allocations' ? 'active' : ''; ?>">Allocations</a>
        <a href="?id=<?php echo $scenario_id; ?>&tab=settings" class="tab <?php echo $activeTab === 'settings' ? 'active' : ''; ?>">Settings</a>
    </div>

    <!-- Tab Content -->
    <?php if ($activeTab === 'overview'): ?>
        <!-- Overview Tab -->

        <!-- Contract Overview - Shows contract commitments vs allocations -->
        <div class="content-card">
            <div class="card-header">
                <h2>Contract Commitments</h2>
                <span class="header-subtitle">Manufacturer commitments that need to be fulfilled</span>
            </div>
            <div class="card-body">
                <?php if (empty($contracts)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📝</div>
                        <p>No contracts added yet. Go to the Contracts tab to add framework contracts.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Contract</th>
                                <th>Manufacturer</th>
                                <th>Committed MW</th>
                                <th>Allocated MW</th>
                                <th>Remaining MW</th>
                                <th>Utilization</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contracts as $contract):
                                $contractAllocated = (float)($contract['allocated_mw'] ?? 0);
                                $contractTotal = (float)$contract['total_mw_dc'];
                                $contractRemaining = $contractTotal - $contractAllocated;
                                $contractPct = $contractTotal > 0 ? ($contractAllocated / $contractTotal) * 100 : 0;
                                $progressClass = $contractPct >= 100 ? '' : ($contractPct >= 50 ? 'warning' : 'warning');
                                if ($contractPct > 100) $progressClass = 'over';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($contract['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($contract['manufacturer_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($contractTotal, 2); ?> MW</td>
                                    <td><?php echo number_format($contractAllocated, 2); ?> MW</td>
                                    <td class="<?php echo $contractRemaining > 0 ? 'text-warning' : 'text-success'; ?>">
                                        <strong><?php echo number_format($contractRemaining, 2); ?> MW</strong>
                                    </td>
                                    <td>
                                        <div class="allocation-progress">
                                            <div class="progress-bar-container">
                                                <div class="progress-bar-fill <?php echo $progressClass; ?>" style="width: <?php echo min(100, $contractPct); ?>%"></div>
                                            </div>
                                            <span class="progress-percentage"><?php echo number_format($contractPct, 0); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8f9fa; font-weight: 600;">
                                <td colspan="2">Total</td>
                                <td><?php echo number_format($totalContractedMw, 2); ?> MW</td>
                                <td><?php echo number_format($totalAllocatedMw, 2); ?> MW</td>
                                <td class="<?php echo $contractsUnallocatedMw > 0 ? 'text-warning' : 'text-success'; ?>">
                                    <strong><?php echo number_format($contractsUnallocatedMw, 2); ?> MW</strong>
                                </td>
                                <td>
                                    <?php $totalContractPct = $totalContractedMw > 0 ? ($totalAllocatedMw / $totalContractedMw) * 100 : 0; ?>
                                    <strong><?php echo number_format($totalContractPct, 0); ?>%</strong>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Projects Overview - Shows project needs vs allocations -->
        <div class="content-card">
            <div class="card-header">
                <h2>Project Requirements</h2>
                <span class="header-subtitle">Projects that need modules allocated</span>
            </div>
            <div class="card-body">
                <?php if (empty($plannedProjects)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <p>No projects added yet. Go to the Projects tab to add your first project.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Required MW</th>
                                <th>Allocated MW</th>
                                <th>Still Needed</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plannedProjects as $project):
                                $projectRequired = (float)$project['required_mw_dc'];
                                $projectAllocated = (float)$project['allocated_mw'];
                                $projectRemaining = $projectRequired - $projectAllocated;
                                $percentage = $projectRequired > 0
                                    ? min(100, ($projectAllocated / $projectRequired) * 100)
                                    : 0;
                                $progressClass = $percentage >= 100 ? '' : ($percentage >= 50 ? 'warning' : 'warning');
                                if ($percentage > 100) $progressClass = 'over';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($project['name']); ?></strong></td>
                                    <td><?php echo number_format($projectRequired, 2); ?> MW</td>
                                    <td><?php echo number_format($projectAllocated, 2); ?> MW</td>
                                    <td class="<?php echo $projectRemaining > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <strong><?php echo number_format($projectRemaining, 2); ?> MW</strong>
                                    </td>
                                    <td>
                                        <div class="allocation-progress">
                                            <div class="progress-bar-container">
                                                <div class="progress-bar-fill <?php echo $progressClass; ?>" style="width: <?php echo min(100, $percentage); ?>%"></div>
                                            </div>
                                            <span class="progress-percentage"><?php echo number_format($percentage, 0); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8f9fa; font-weight: 600;">
                                <td>Total</td>
                                <td><?php echo number_format($totalPlannedMw, 2); ?> MW</td>
                                <td><?php echo number_format($totalAllocatedMw, 2); ?> MW</td>
                                <td class="<?php echo $projectsUnallocatedMw > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <strong><?php echo number_format($projectsUnallocatedMw, 2); ?> MW</strong>
                                </td>
                                <td>
                                    <?php $totalProjectPct = $totalPlannedMw > 0 ? ($totalAllocatedMw / $totalPlannedMw) * 100 : 0; ?>
                                    <strong><?php echo number_format($totalProjectPct, 0); ?>%</strong>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Active Projects Reference -->
        <?php if (!empty($activeProjects)): ?>
            <div class="content-card">
                <div class="card-header">
                    <h2>Active Projects (Reference)</h2>
                </div>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project Name</th>
                                <th>Size (MW)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeProjects as $project): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($project['project_name']); ?></td>
                                    <td><?php echo number_format($project['project_size'], 2); ?> MW</td>
                                    <td><span class="badge badge-active">Active</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    <?php elseif ($activeTab === 'contracts'): ?>
        <!-- Contracts Tab -->

        <!-- Manufacturers Section -->
        <div class="content-card">
            <div class="card-header">
                <h2>Manufacturers</h2>
            </div>
            <?php if (!empty($manufacturers)): ?>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Manufacturer Name</th>
                                <th>Contracts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manufacturers as $mfr):
                                $mfrContractCount = count(array_filter($contracts, fn($c) => $c['planning_manufacturer_id'] == $mfr['id']));
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($mfr['name']); ?></strong></td>
                                    <td><?php echo $mfrContractCount; ?> contract(s)</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <form method="POST" class="inline-form">
                <input type="hidden" name="action" value="add_manufacturer">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="planning-form-group">
                    <label>Add Manufacturer</label>
                    <input type="text" name="manufacturer_name" placeholder="e.g., JA Solar" required>
                </div>
                <button type="submit" class="btn btn-primary">Add</button>
            </form>
        </div>

        <!-- Contracts Section -->
        <div class="content-card">
            <div class="card-header">
                <h2>Framework Contracts</h2>
            </div>
            <?php if (!empty($contracts)): ?>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Contract Name</th>
                                <th>Manufacturer</th>
                                <th>Committed MW</th>
                                <th>Allocated MW</th>
                                <th>Utilization</th>
                                <th>Period</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contracts as $contract):
                                $contractAllocated = (float)($contract['allocated_mw'] ?? 0);
                                $contractTotal = (float)$contract['total_mw_dc'];
                                $contractPct = $contractTotal > 0 ? ($contractAllocated / $contractTotal) * 100 : 0;
                                $contractProgressClass = $contractPct >= 100 ? '' : ($contractPct >= 50 ? 'warning' : 'warning');
                                if ($contractPct > 100) $contractProgressClass = 'over';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($contract['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($contract['manufacturer_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($contract['total_mw_dc'], 2); ?> MW</td>
                                    <td><?php echo number_format($contractAllocated, 2); ?> MW</td>
                                    <td>
                                        <div class="allocation-progress">
                                            <div class="progress-bar-container">
                                                <div class="progress-bar-fill <?php echo $contractProgressClass; ?>" style="width: <?php echo min(100, $contractPct); ?>%"></div>
                                            </div>
                                            <span class="progress-percentage"><?php echo number_format($contractPct, 0); ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $start = $contract['contract_start_date'] ? date('M Y', strtotime($contract['contract_start_date'])) : 'N/A';
                                        $end = $contract['contract_end_date'] ? date('M Y', strtotime($contract['contract_end_date'])) : 'N/A';
                                        echo "$start - $end";
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form method="POST" class="inline-form">
                <input type="hidden" name="action" value="add_contract">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="planning-form-group">
                    <label>Contract Name</label>
                    <input type="text" name="contract_name" placeholder="e.g., 2025 Supply Agreement" required>
                </div>
                <div class="planning-form-group">
                    <label>Manufacturer</label>
                    <select name="manufacturer_id">
                        <option value="">Select (optional)...</option>
                        <?php foreach ($manufacturers as $mfr): ?>
                            <option value="<?php echo $mfr['id']; ?>"><?php echo htmlspecialchars($mfr['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="planning-form-group">
                    <label>Total MW</label>
                    <input type="number" name="total_mw" step="0.01" placeholder="100.00" required>
                </div>
                <div class="planning-form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date">
                </div>
                <div class="planning-form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date">
                </div>
                <button type="submit" class="btn btn-primary">Add Contract</button>
            </form>
        </div>

    <?php elseif ($activeTab === 'projects'): ?>
        <!-- Projects Tab -->
        <div class="content-card">
            <div class="card-header">
                <h2>Planned Projects</h2>
            </div>
            <?php if (!empty($plannedProjects)): ?>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project Name</th>
                                <th>Required MW</th>
                                <th>Location</th>
                                <th>Delivery Window</th>
                                <th>Allocated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plannedProjects as $project): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($project['name']); ?></strong></td>
                                    <td><?php echo number_format($project['required_mw_dc'], 2); ?> MW</td>
                                    <td><?php echo htmlspecialchars($project['location_region'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                        $start = $project['primary_delivery_start'] ? date('M Y', strtotime($project['primary_delivery_start'])) : 'TBD';
                                        $end = $project['primary_delivery_end'] ? date('M Y', strtotime($project['primary_delivery_end'])) : 'TBD';
                                        echo "$start - $end";
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $pct = $project['required_mw_dc'] > 0
                                            ? ($project['allocated_mw'] / $project['required_mw_dc']) * 100
                                            : 0;
                                        ?>
                                        <span class="badge <?php echo $pct >= 100 ? 'badge-active' : 'badge-pending'; ?>">
                                            <?php echo number_format($pct, 0); ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <form method="POST" class="inline-form">
                <input type="hidden" name="action" value="add_project">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="planning-form-group">
                    <label>Project Name</label>
                    <input type="text" name="project_name" placeholder="e.g., Solar Farm Alpha" required>
                </div>
                <div class="planning-form-group">
                    <label>Required MW</label>
                    <input type="number" name="required_mw" step="0.01" placeholder="50.00" required>
                </div>
                <div class="planning-form-group">
                    <label>Location</label>
                    <input type="text" name="location" id="project_location" placeholder="City, State" autocomplete="off">
                </div>
                <div class="planning-form-group">
                    <label>Delivery Start</label>
                    <input type="date" name="delivery_start">
                </div>
                <div class="planning-form-group">
                    <label>Delivery End</label>
                    <input type="date" name="delivery_end">
                </div>
                <button type="submit" class="btn btn-primary">Add Project</button>
            </form>
        </div>

    <?php elseif ($activeTab === 'allocations'): ?>
        <!-- Allocations Tab -->
        <div class="content-card">
            <div class="card-header">
                <h2>Module Allocations</h2>
            </div>
            <?php if (!empty($allocations)): ?>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Contract</th>
                                <th>Period</th>
                                <th>Allocated MW</th>
                                <th class="actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allocations as $alloc): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($alloc['project_name']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($alloc['contract_name']); ?>
                                        <br><small style="color: #6c757d;"><?php echo htmlspecialchars($alloc['manufacturer_name'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $start = $alloc['period_start'] ? date('M Y', strtotime($alloc['period_start'])) : 'N/A';
                                        $end = $alloc['period_end'] ? date('M Y', strtotime($alloc['period_end'])) : 'N/A';
                                        echo "$start - $end";
                                        ?>
                                    </td>
                                    <td><?php echo number_format($alloc['allocated_mw_dc'], 2); ?> MW</td>
                                    <td class="actions">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this allocation?')">
                                            <input type="hidden" name="action" value="delete_allocation">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="allocation_id" value="<?php echo $alloc['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($plannedProjects) && !empty($contracts)): ?>
                <form method="POST" class="inline-form">
                    <input type="hidden" name="action" value="add_allocation">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="planning-form-group">
                        <label>Project</label>
                        <select name="project_id" required>
                            <option value="">Select project...</option>
                            <?php foreach ($plannedProjects as $project): ?>
                                <option value="<?php echo $project['id']; ?>">
                                    <?php echo htmlspecialchars($project['name']); ?>
                                    (<?php echo number_format($project['required_mw_dc'], 1); ?> MW)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="planning-form-group">
                        <label>Contract</label>
                        <select name="contract_id" required>
                            <option value="">Select contract...</option>
                            <?php foreach ($contracts as $contract): ?>
                                <option value="<?php echo $contract['id']; ?>">
                                    <?php echo htmlspecialchars($contract['name']); ?>
                                    (<?php echo number_format($contract['total_mw_dc'], 1); ?> MW)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="planning-form-group">
                        <label>Allocated MW</label>
                        <input type="number" name="allocated_mw" step="0.01" placeholder="25.00" required>
                    </div>
                    <div class="planning-form-group">
                        <label>Period Start</label>
                        <input type="date" name="period_start" required>
                    </div>
                    <div class="planning-form-group">
                        <label>Period End</label>
                        <input type="date" name="period_end" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Allocation</button>
                </form>
            <?php else: ?>
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-state-icon">📊</div>
                        <p>Add projects and contracts first before creating allocations.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($activeTab === 'settings'): ?>
        <!-- Settings Tab -->
        <div class="content-card">
            <div class="card-header">
                <h2>Scenario Settings</h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_scenario">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="planning-form-group">
                        <label>Scenario Name</label>
                        <input type="text" name="scenario_name" value="<?php echo htmlspecialchars($scenario['name']); ?>" required>
                    </div>
                    <div class="planning-form-group">
                        <label>Description</label>
                        <textarea name="scenario_description" rows="4"><?php echo htmlspecialchars($scenario['description'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>

        <div class="content-card">
            <div class="card-header">
                <h2>Scenario Information</h2>
            </div>
            <div class="card-body">
                <p><strong>Account:</strong> <?php echo htmlspecialchars($scenario['account_name']); ?></p>
                <p><strong>Created:</strong> <?php echo date('F j, Y g:i A', strtotime($scenario['created_at'])); ?></p>
                <p><strong>Last Updated:</strong> <?php echo date('F j, Y g:i A', strtotime($scenario['updated_at'])); ?></p>
                <p><strong>Created By:</strong> <?php echo htmlspecialchars($scenario['first_name'] ?? $scenario['username']); ?></p>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- Activation Request Modal -->
<div class="planning-modal-overlay" id="activationModal">
    <div class="planning-modal">
        <div class="planning-modal-header">
            <h2>Request Activation</h2>
            <button class="planning-modal-close" onclick="closeActivationModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="request_activation">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="planning-modal-body">
                <p style="margin-bottom: 20px; color: #6c757d;">
                    Submitting an activation request will notify Solterra administrators to review your scenario and convert it to active projects.
                </p>
                <div class="planning-form-group">
                    <label>Notes for Admin (Optional)</label>
                    <textarea name="activation_notes" rows="4" placeholder="Any additional notes or context for the review..."></textarea>
                </div>
            </div>
            <div class="planning-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeActivationModal()">Cancel</button>
                <button type="submit" class="btn btn-activate">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<!-- Google Maps API for location autocomplete -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places"></script>

<script>
function openActivationModal() {
    document.getElementById('activationModal').classList.add('active');
}

function closeActivationModal() {
    document.getElementById('activationModal').classList.remove('active');
}

document.getElementById('activationModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeActivationModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeActivationModal();
    }
});

// Initialize Google Places Autocomplete for location fields
function initLocationAutocomplete() {
    const locationInput = document.getElementById('project_location');
    if (locationInput && typeof google !== 'undefined' && google.maps && google.maps.places) {
        const options = {
            types: ['geocode'],
            componentRestrictions: { country: 'us' }
        };
        new google.maps.places.Autocomplete(locationInput, options);
    }
}

// Initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLocationAutocomplete);
} else {
    initLocationAutocomplete();
}
</script>
</body>
</html>
