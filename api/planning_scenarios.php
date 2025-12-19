<?php
/**
 * Planning Scenarios API
 *
 * Handles CRUD operations for planning scenarios and related data.
 *
 * Methods:
 * - GET: Fetch scenarios, contracts, projects, allocations
 * - POST: Create new items
 * - PUT: Update items
 * - DELETE: Remove items
 */

header('Content-Type: application/json');

session_name("logistics_session");
session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../../config.php';

$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Get user's account IDs
$accountIds = [];
if ($role === 'global_admin') {
    $result = $conn->query("SELECT id FROM customer_accounts");
    while ($row = $result->fetch_assoc()) {
        $accountIds[] = (int)$row['id'];
    }
} else {
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accountIds[] = (int)$row['account_id'];
    }
    $stmt->close();
}

if (empty($accountIds)) {
    http_response_code(403);
    echo json_encode(['error' => 'No account access']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $accountIds, $action);
            break;
        case 'POST':
            handlePost($conn, $accountIds, $user_id);
            break;
        case 'PUT':
            handlePut($conn, $accountIds, $user_id);
            break;
        case 'DELETE':
            handleDelete($conn, $accountIds);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();

function handleGet($conn, $accountIds, $action) {
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $types = str_repeat('i', count($accountIds));

    switch ($action) {
        case 'scenarios':
            $sql = "
                SELECT ps.*, u.first_name, u.last_name,
                       (SELECT COUNT(*) FROM planning_projects pp WHERE pp.scenario_id = ps.id) as project_count,
                       (SELECT COUNT(*) FROM planning_framework_contracts pfc WHERE pfc.scenario_id = ps.id) as contract_count
                FROM planning_scenarios ps
                LEFT JOIN users u ON ps.created_by = u.id
                WHERE ps.account_id IN ($placeholders)
                ORDER BY ps.updated_at DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$accountIds);
            $stmt->execute();
            $result = $stmt->get_result();
            $scenarios = [];
            while ($row = $result->fetch_assoc()) {
                $scenarios[] = $row;
            }
            echo json_encode(['scenarios' => $scenarios]);
            break;

        case 'scenario':
            $scenarioId = (int)($_GET['id'] ?? 0);
            $stmt = $conn->prepare("
                SELECT ps.*, ca.name as account_name
                FROM planning_scenarios ps
                LEFT JOIN customer_accounts ca ON ps.account_id = ca.id
                WHERE ps.id = ? AND ps.account_id IN ($placeholders)
            ");
            $params = array_merge([$scenarioId], $accountIds);
            $stmt->bind_param('i' . $types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $scenario = $result->fetch_assoc();
            if ($scenario) {
                echo json_encode(['scenario' => $scenario]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Scenario not found']);
            }
            break;

        case 'contracts':
            $scenarioId = (int)($_GET['scenario_id'] ?? 0);
            $stmt = $conn->prepare("
                SELECT pfc.*, pm.name as manufacturer_name
                FROM planning_framework_contracts pfc
                LEFT JOIN planning_manufacturers pm ON pfc.manufacturer_id = pm.id
                WHERE pfc.scenario_id = ? AND pfc.account_id IN ($placeholders)
                ORDER BY pfc.contract_name
            ");
            $params = array_merge([$scenarioId], $accountIds);
            $stmt->bind_param('i' . $types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $contracts = [];
            while ($row = $result->fetch_assoc()) {
                $contracts[] = $row;
            }
            echo json_encode(['contracts' => $contracts]);
            break;

        case 'projects':
            $scenarioId = (int)($_GET['scenario_id'] ?? 0);
            $stmt = $conn->prepare("
                SELECT pp.*,
                       (SELECT COALESCE(SUM(allocated_mw_dc), 0) FROM planning_allocations pa WHERE pa.project_id = pp.id) as allocated_mw
                FROM planning_projects pp
                WHERE pp.scenario_id = ? AND pp.account_id IN ($placeholders)
                ORDER BY pp.project_name
            ");
            $params = array_merge([$scenarioId], $accountIds);
            $stmt->bind_param('i' . $types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $projects = [];
            while ($row = $result->fetch_assoc()) {
                $projects[] = $row;
            }
            echo json_encode(['projects' => $projects]);
            break;

        case 'allocations':
            $scenarioId = (int)($_GET['scenario_id'] ?? 0);
            $stmt = $conn->prepare("
                SELECT pa.*, pp.project_name, pfc.contract_name, pm.name as manufacturer_name
                FROM planning_allocations pa
                JOIN planning_projects pp ON pa.project_id = pp.id
                JOIN planning_framework_contracts pfc ON pa.contract_id = pfc.id
                LEFT JOIN planning_manufacturers pm ON pfc.manufacturer_id = pm.id
                WHERE pa.scenario_id = ?
                ORDER BY pp.project_name, pa.period_start
            ");
            $stmt->bind_param('i', $scenarioId);
            $stmt->execute();
            $result = $stmt->get_result();
            $allocations = [];
            while ($row = $result->fetch_assoc()) {
                $allocations[] = $row;
            }
            echo json_encode(['allocations' => $allocations]);
            break;

        case 'manufacturers':
            $scenarioId = (int)($_GET['scenario_id'] ?? 0);
            $stmt = $conn->prepare("
                SELECT * FROM planning_manufacturers
                WHERE scenario_id = ? AND account_id IN ($placeholders)
                ORDER BY name
            ");
            $params = array_merge([$scenarioId], $accountIds);
            $stmt->bind_param('i' . $types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $manufacturers = [];
            while ($row = $result->fetch_assoc()) {
                $manufacturers[] = $row;
            }
            echo json_encode(['manufacturers' => $manufacturers]);
            break;

        case 'summary':
            $scenarioId = (int)($_GET['scenario_id'] ?? 0);
            // Get planned MW
            $stmt = $conn->prepare("SELECT COALESCE(SUM(required_mw_dc), 0) as total FROM planning_projects WHERE scenario_id = ?");
            $stmt->bind_param('i', $scenarioId);
            $stmt->execute();
            $stmt->bind_result($plannedMw);
            $stmt->fetch();
            $stmt->close();

            // Get contracted MW
            $stmt = $conn->prepare("SELECT COALESCE(SUM(total_mw_dc), 0) as total FROM planning_framework_contracts WHERE scenario_id = ?");
            $stmt->bind_param('i', $scenarioId);
            $stmt->execute();
            $stmt->bind_result($contractedMw);
            $stmt->fetch();
            $stmt->close();

            // Get allocated MW
            $stmt = $conn->prepare("SELECT COALESCE(SUM(allocated_mw_dc), 0) as total FROM planning_allocations WHERE scenario_id = ?");
            $stmt->bind_param('i', $scenarioId);
            $stmt->execute();
            $stmt->bind_result($allocatedMw);
            $stmt->fetch();
            $stmt->close();

            echo json_encode([
                'summary' => [
                    'planned_mw' => (float)$plannedMw,
                    'contracted_mw' => (float)$contractedMw,
                    'allocated_mw' => (float)$allocatedMw,
                    'unallocated_mw' => (float)$plannedMw - (float)$allocatedMw
                ]
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
}

function handlePost($conn, $accountIds, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? '';
    $accountId = $accountIds[0]; // Use primary account

    switch ($type) {
        case 'scenario':
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'Name is required']);
                return;
            }
            $stmt = $conn->prepare("INSERT INTO planning_scenarios (account_id, name, description, created_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issi", $accountId, $name, $description, $user_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'id' => $conn->insert_id]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create scenario']);
            }
            break;

        case 'manufacturer':
            $scenarioId = (int)($input['scenario_id'] ?? 0);
            $name = trim($input['name'] ?? '');
            if (empty($name) || !$scenarioId) {
                http_response_code(400);
                echo json_encode(['error' => 'Name and scenario_id are required']);
                return;
            }
            $stmt = $conn->prepare("INSERT INTO planning_manufacturers (scenario_id, account_id, name) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $scenarioId, $accountId, $name);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'id' => $conn->insert_id]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create manufacturer']);
            }
            break;

        case 'contract':
            $scenarioId = (int)($input['scenario_id'] ?? 0);
            $manufacturerId = (int)($input['manufacturer_id'] ?? 0);
            $contractName = trim($input['contract_name'] ?? '');
            $totalMw = (float)($input['total_mw'] ?? 0);
            $startDate = $input['start_date'] ?? null;
            $endDate = $input['end_date'] ?? null;

            if (empty($contractName) || !$scenarioId) {
                http_response_code(400);
                echo json_encode(['error' => 'Contract name and scenario_id are required']);
                return;
            }

            $stmt = $conn->prepare("
                INSERT INTO planning_framework_contracts
                (scenario_id, account_id, manufacturer_id, contract_name, total_mw_dc, start_date, end_date)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiisdss", $scenarioId, $accountId, $manufacturerId, $contractName, $totalMw, $startDate, $endDate);
            if ($stmt->execute()) {
                updateScenarioTotals($conn, $scenarioId);
                echo json_encode(['success' => true, 'id' => $conn->insert_id]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create contract']);
            }
            break;

        case 'project':
            $scenarioId = (int)($input['scenario_id'] ?? 0);
            $projectName = trim($input['project_name'] ?? '');
            $requiredMw = (float)($input['required_mw'] ?? 0);
            $deliveryStart = $input['delivery_start'] ?? null;
            $deliveryEnd = $input['delivery_end'] ?? null;
            $location = trim($input['location'] ?? '');

            if (empty($projectName) || !$scenarioId) {
                http_response_code(400);
                echo json_encode(['error' => 'Project name and scenario_id are required']);
                return;
            }

            $stmt = $conn->prepare("
                INSERT INTO planning_projects
                (scenario_id, account_id, project_name, required_mw_dc, primary_delivery_start, primary_delivery_end, location)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iisdsss", $scenarioId, $accountId, $projectName, $requiredMw, $deliveryStart, $deliveryEnd, $location);
            if ($stmt->execute()) {
                updateScenarioTotals($conn, $scenarioId);
                echo json_encode(['success' => true, 'id' => $conn->insert_id]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create project']);
            }
            break;

        case 'allocation':
            $scenarioId = (int)($input['scenario_id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);
            $contractId = (int)($input['contract_id'] ?? 0);
            $allocatedMw = (float)($input['allocated_mw'] ?? 0);
            $periodStart = $input['period_start'] ?? null;
            $periodEnd = $input['period_end'] ?? null;

            if (!$projectId || !$contractId || $allocatedMw <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Project, contract, and allocated MW are required']);
                return;
            }

            $stmt = $conn->prepare("
                INSERT INTO planning_allocations
                (scenario_id, project_id, contract_id, period_start, period_end, allocated_mw_dc, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiissdi", $scenarioId, $projectId, $contractId, $periodStart, $periodEnd, $allocatedMw, $user_id);
            if ($stmt->execute()) {
                updateScenarioTotals($conn, $scenarioId);
                echo json_encode(['success' => true, 'id' => $conn->insert_id]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create allocation']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid type']);
    }
}

function handlePut($conn, $accountIds, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? '';
    $id = (int)($input['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID is required']);
        return;
    }

    switch ($type) {
        case 'scenario':
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'Name is required']);
                return;
            }
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $types = str_repeat('i', count($accountIds));
            $stmt = $conn->prepare("UPDATE planning_scenarios SET name = ?, description = ? WHERE id = ? AND account_id IN ($placeholders)");
            $params = array_merge([$name, $description, $id], $accountIds);
            $stmt->bind_param('ssi' . $types, ...$params);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update scenario']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid type']);
    }
}

function handleDelete($conn, $accountIds) {
    $type = $_GET['type'] ?? '';
    $id = (int)($_GET['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID is required']);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $types = str_repeat('i', count($accountIds));

    switch ($type) {
        case 'scenario':
            $stmt = $conn->prepare("DELETE FROM planning_scenarios WHERE id = ? AND account_id IN ($placeholders)");
            $params = array_merge([$id], $accountIds);
            $stmt->bind_param('i' . $types, ...$params);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete scenario']);
            }
            break;

        case 'allocation':
            // First get the scenario_id for updating totals
            $stmt = $conn->prepare("SELECT scenario_id FROM planning_allocations WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->bind_result($scenarioId);
            $stmt->fetch();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM planning_allocations WHERE id = ?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                if ($scenarioId) {
                    updateScenarioTotals($conn, $scenarioId);
                }
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete allocation']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid type']);
    }
}

function updateScenarioTotals($conn, $scenarioId) {
    $sql = "
        UPDATE planning_scenarios
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
