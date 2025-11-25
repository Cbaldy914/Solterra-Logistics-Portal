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

function resolveAccountId(mysqli $conn, string $role, int $userId): ?int {
    if ($role === 'global_admin') {
        return isset($_GET['account_id']) ? max(0, (int)$_GET['account_id']) : null;
    }
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($accountId);
    $stmt->fetch();
    $stmt->close();
    return $accountId ? (int)$accountId : null;
}

$accountId = resolveAccountId($conn, $role, $userId);
if (!$accountId) {
    http_response_code(400);
    echo json_encode(['error' => 'Account not found for user']);
    exit();
}

$limit  = isset($_GET['limit']) ? min(500, max(1, (int)$_GET['limit'])) : 80;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$projectFilter = isset($_GET['project_id']) ? trim($_GET['project_id']) : '';
$wattage = isset($_GET['wattage']) ? (int)$_GET['wattage'] : 0;
$search  = isset($_GET['search']) ? trim($_GET['search']) : '';
$includeTotals = isset($_GET['include_totals']) && $_GET['include_totals'] === '1';
$hideDelivered = isset($_GET['hide_delivered']) && $_GET['hide_delivered'] === '1';
$hideDamaged   = isset($_GET['hide_damaged']) && $_GET['hide_damaged'] === '1';

$allowedStatuses = [
    'At Manufacturer','On Water','Cleared Customs','In Transit to Warehouse','In Warehouse',
    'In Transit to Project','Delivered to Project','Damaged'
];
if ($status && !in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status filter']);
    exit();
}

$conditions = ["m.account_id = ?"];
$types      = "i";
$params     = [$accountId];

if ($projectFilter !== '') {
    if ($projectFilter === 'unassigned') {
        $conditions[] = "ip.assigned_project_id IS NULL";
    } else {
        $projectId = (int)$projectFilter;
        $conditions[] = "ip.assigned_project_id = ?";
        $types      .= "i";
        $params[]    = $projectId;
    }
}

if ($status) {
    $conditions[] = "ip.status = ?";
    $types      .= "s";
    $params[]    = $status;
}

if ($warehouseId) {
    $conditions[] = "ip.current_warehouse_id = ?";
    $types      .= "i";
    $params[]    = $warehouseId;
}

if ($wattage) {
    $conditions[] = "ip.wattage = ?";
    $types      .= "i";
    $params[]    = $wattage;
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $conditions[] = "(ip.pallet_identifier LIKE ? OR ip.manufacturer LIKE ? OR p.project_name LIKE ? OR w.name LIKE ? )";
    $types      .= "ssss";
    $params[]    = $like;
    $params[]    = $like;
    $params[]    = $like;
    $params[]    = $like;
}

if ($hideDelivered) {
    $conditions[] = "ip.status != 'Delivered to Project'";
}
if ($hideDamaged) {
    $conditions[] = "ip.status != 'Damaged'";
}

$whereSql = implode(' AND ', $conditions);

// Total count for pagination
$countSql = "
    SELECT COUNT(*) AS total
    FROM inventory_pallets ip
    JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
    JOIN modules m ON umi.unassigned_module_id = m.id
    LEFT JOIN projects p ON ip.assigned_project_id = p.id
    LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
    WHERE $whereSql
";
$stmtCount = $conn->prepare($countSql);
$stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$countRes = $stmtCount->get_result()->fetch_assoc();
$totalRows = (int)($countRes['total'] ?? 0);
$stmtCount->close();

// Main data fetch
$dataSql = "
    SELECT 
        ip.id,
        ip.pallet_identifier,
        ip.assigned_project_id,
        ap.project_name AS assigned_project_name,
        ip.current_project_id,
        cp.project_name AS current_project_name,
        ip.status,
        ip.quantity,
        ip.wattage,
        ip.manufacturer,
        ip.current_warehouse_id,
        w.name AS warehouse_name,
        ip.updated_at,
        ip.created_at
    FROM inventory_pallets ip
    JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
    JOIN modules m ON umi.unassigned_module_id = m.id
    LEFT JOIN projects ap ON ip.assigned_project_id = ap.id
    LEFT JOIN projects cp ON ip.current_project_id = cp.id
    LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
    WHERE $whereSql
    ORDER BY ip.updated_at DESC
    LIMIT ? OFFSET ?
";

$typesData = $types . 'ii';
$paramsData = array_merge($params, [$limit, $offset]);
$stmtData = $conn->prepare($dataSql);
$stmtData->bind_param($typesData, ...$paramsData);
$stmtData->execute();
$result = $stmtData->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmtData->close();

$response = [
    'items' => $rows,
    'pagination' => [
        'limit' => $limit,
        'offset' => $offset,
        'total' => $totalRows
    ]
];

if ($includeTotals) {
    $totals = [
        'by_status' => [],
        'by_project' => []
    ];

    $totalsStatusSql = "
        SELECT ip.status, COUNT(*) AS pallets, SUM(ip.quantity) AS modules
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE $whereSql
        GROUP BY ip.status
    ";
    $stmtTS = $conn->prepare($totalsStatusSql);
    $stmtTS->bind_param($types, ...$params);
    $stmtTS->execute();
    $resTS = $stmtTS->get_result();
    while ($row = $resTS->fetch_assoc()) {
        $totals['by_status'][] = $row;
    }
    $stmtTS->close();

    $totalsProjectSql = "
        SELECT 
            COALESCE(ip.assigned_project_id, 0) AS project_id,
            COALESCE(ap.project_name, 'Unassigned') AS project_name,
            COUNT(*) AS pallets,
            SUM(ip.quantity) AS modules
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN projects ap ON ip.assigned_project_id = ap.id
        WHERE $whereSql
        GROUP BY COALESCE(ip.assigned_project_id, 0), COALESCE(ap.project_name, 'Unassigned')
        ORDER BY project_name ASC
    ";
    $stmtTP = $conn->prepare($totalsProjectSql);
    $stmtTP->bind_param($types, ...$params);
    $stmtTP->execute();
    $resTP = $stmtTP->get_result();
    while ($row = $resTP->fetch_assoc()) {
        $totals['by_project'][] = $row;
    }
    $stmtTP->close();

    $response['totals'] = $totals;
}

echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
