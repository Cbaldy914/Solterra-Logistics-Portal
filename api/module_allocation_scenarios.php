<?php
session_name("logistics_session");
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','global_admin','user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'];

function resolveAccountId(mysqli $conn, string $role, int $userId, ?int $provided): ?int {
    if ($role === 'global_admin') {
        return $provided ?: null;
    }
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($accountId);
    $stmt->fetch();
    $stmt->close();
    return $accountId ? (int)$accountId : null;
}

function userCanAccessScenario(mysqli $conn, int $scenarioId, int $accountId): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM module_allocation_scenarios WHERE id = ? AND account_id = ?");
    $stmt->bind_param("ii", $scenarioId, $accountId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

function buildDiffSummary(array $moves): string {
    $moveCount = count($moves);
    if ($moveCount === 0) {
        return 'No moves in scenario';
    }
    $modulesToProject = [];
    $palletsToProject = [];
    foreach ($moves as $m) {
        $to = $m['to_project_id'] ?? null;
        $qty = isset($m['quantity']) ? (int)$m['quantity'] : 0;
        $modulesToProject[$to] = ($modulesToProject[$to] ?? 0) + $qty;
        $palletsToProject[$to] = ($palletsToProject[$to] ?? 0) + 1;
    }
    arsort($palletsToProject);
    $topProjects = array_slice($palletsToProject, 0, 2, true);
    $fragments = [];
    foreach ($topProjects as $projId => $count) {
        $label = $projId ? "Project {$projId}" : 'Unassigned';
        $mods  = $modulesToProject[$projId] ?? 0;
        $fragments[] = "$count pallets ($mods modules) to $label";
    }
    $main = implode('; ', $fragments);
    return $main ?: "$moveCount pallet moves";
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $providedAccount = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
    $accountId = resolveAccountId($conn, $role, $userId, $providedAccount);
    if (!$accountId) {
        http_response_code(400);
        echo json_encode(['error' => 'Account not found for user']);
        exit();
    }

    $scenarioId = isset($_GET['scenario_id']) ? (int)$_GET['scenario_id'] : 0;
    $statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    if ($scenarioId > 0) {
        if (!userCanAccessScenario($conn, $scenarioId, $accountId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit();
        }
        $stmt = $conn->prepare("SELECT * FROM module_allocation_scenarios WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $scenarioId);
        $stmt->execute();
        $scenario = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $movesStmt = $conn->prepare("SELECT * FROM module_allocation_moves WHERE scenario_id = ?");
        $movesStmt->bind_param("i", $scenarioId);
        $movesStmt->execute();
        $movesRes = $movesStmt->get_result();
        $moves = [];
        while ($row = $movesRes->fetch_assoc()) {
            $moves[] = $row;
        }
        $movesStmt->close();

        echo json_encode(['scenario' => $scenario, 'moves' => $moves], JSON_INVALID_UTF8_SUBSTITUTE);
        exit();
    }

    $conditions = ['account_id = ?'];
    $types = 'i';
    $params = [$accountId];
    if ($statusFilter) {
        $conditions[] = 'status = ?';
        $types .= 's';
        $params[] = $statusFilter;
    }
    $where = implode(' AND ', $conditions);

    $countSql = "SELECT COUNT(*) AS total FROM module_allocation_scenarios WHERE $where";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $listSql = "SELECT * FROM module_allocation_scenarios WHERE $where ORDER BY updated_at DESC LIMIT ? OFFSET ?";
    $typesList = $types . 'ii';
    $paramsList = array_merge($params, [$limit, $offset]);
    $listStmt = $conn->prepare($listSql);
    $listStmt->bind_param($typesList, ...$paramsList);
    $listStmt->execute();
    $res = $listStmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $listStmt->close();

    echo json_encode([
        'items' => $items,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total
        ]
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

// POST/PUT for save/request
$payload = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $payload['action'] ?? 'save';
$scenarioId = isset($payload['scenario_id']) ? (int)$payload['scenario_id'] : 0;
$providedAccount = isset($payload['account_id']) ? (int)$payload['account_id'] : null;
$name = trim($payload['name'] ?? '');
$description = trim($payload['description'] ?? '');
$moves = $payload['moves'] ?? [];
$baseSnapshot = isset($payload['base_snapshot_hash']) ? substr($payload['base_snapshot_hash'], 0, 128) : null;

$accountId = resolveAccountId($conn, $role, $userId, $providedAccount);
if (!$accountId) {
    http_response_code(400);
    echo json_encode(['error' => 'Account not found for user']);
    exit();
}

if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Name is required']);
    exit();
}

$status = ($action === 'request') ? 'requested' : 'saved';

$conn->begin_transaction();
try {
    if ($scenarioId > 0) {
        if (!userCanAccessScenario($conn, $scenarioId, $accountId)) {
            throw new Exception('Forbidden');
        }
        $stmt = $conn->prepare("UPDATE module_allocation_scenarios SET name = ?, description = ?, status = ?, base_snapshot_hash = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssssi", $name, $description, $status, $baseSnapshot, $scenarioId);
        $stmt->execute();
        $stmt->close();

        // Refresh moves
        $del = $conn->prepare("DELETE FROM module_allocation_moves WHERE scenario_id = ?");
        $del->bind_param("i", $scenarioId);
        $del->execute();
        $del->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO module_allocation_scenarios (account_id, created_by_user_id, name, description, status, base_snapshot_hash) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissss", $accountId, $userId, $name, $description, $status, $baseSnapshot);
        $stmt->execute();
        $scenarioId = (int)$stmt->insert_id;
        $stmt->close();
    }

    // Insert moves
    if (!empty($moves)) {
        $ins = $conn->prepare("INSERT INTO module_allocation_moves (scenario_id, inventory_pallet_id, from_project_id, to_project_id, from_status, to_status, from_warehouse_id, to_warehouse_id, quantity, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($moves as $m) {
            $inventoryId = (int)($m['inventory_pallet_id'] ?? 0);
            if ($inventoryId <= 0) { continue; }
            $fromProject = isset($m['from_project_id']) ? (int)$m['from_project_id'] : null;
            $toProject   = isset($m['to_project_id']) ? (int)$m['to_project_id'] : null;
            $fromStatus  = $m['from_status'] ?? null;
            $toStatus    = $m['to_status'] ?? null;
            $fromWarehouse = isset($m['from_warehouse_id']) ? (int)$m['from_warehouse_id'] : null;
            $toWarehouse   = isset($m['to_warehouse_id']) ? (int)$m['to_warehouse_id'] : null;
            $qty         = isset($m['quantity']) ? (int)$m['quantity'] : null;
            $notes       = isset($m['notes']) ? substr($m['notes'], 0, 255) : null;
            $ins->bind_param(
                "iiiissiiis",
                $scenarioId,
                $inventoryId,
                $fromProject,
                $toProject,
                $fromStatus,
                $toStatus,
                $fromWarehouse,
                $toWarehouse,
                $qty,
                $notes
            );
            $ins->execute();
        }
        $ins->close();
    }

    $diffSummary = buildDiffSummary($moves);
    $stmtUpd = $conn->prepare("UPDATE module_allocation_scenarios SET diff_summary = ? WHERE id = ?");
    $stmtUpd->bind_param("si", $diffSummary, $scenarioId);
    $stmtUpd->execute();
    $stmtUpd->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    http_response_code($e->getMessage() === 'Forbidden' ? 403 : 500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}

// Send notification on request
if ($status === 'requested') {
    $stmtUsers = $conn->prepare("SELECT user_id FROM customer_account_users WHERE account_id = ? AND role IN ('admin','global_admin')");
    $stmtUsers->bind_param("i", $accountId);
    $stmtUsers->execute();
    $resUsers = $stmtUsers->get_result();
    $recipientIds = [];
    while ($row = $resUsers->fetch_assoc()) { $recipientIds[] = (int)$row['user_id']; }
    $stmtUsers->close();

    if (!empty($recipientIds)) {
        $notif = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?, 'module_allocation_request', ?, ?, ?, NOW())");
        $title = 'Allocation request: ' . $name;
        $message = $diffSummary;
        $link = 'module_allocations.php?scenario_id=' . $scenarioId;
        foreach ($recipientIds as $rid) {
            $notif->bind_param("isss", $rid, $title, $message, $link);
            $notif->execute();
        }
        $notif->close();
    }
}

echo json_encode([
    'scenario_id' => $scenarioId,
    'status' => $status,
    'message' => ($status === 'requested') ? 'Scenario requested and admins notified.' : 'Scenario saved.',
    'diff_summary' => $diffSummary
], JSON_INVALID_UTF8_SUBSTITUTE);
