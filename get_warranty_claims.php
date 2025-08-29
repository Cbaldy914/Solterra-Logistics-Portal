<?php
// DataTables server-side endpoint for warranty claims list

session_name('logistics_session');
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';
require_once __DIR__ . '/warranty_filters.php';

$conn = getDBConnection();

$draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 0;
$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
$length = isset($_GET['length']) ? (int)$_GET['length'] : 25;

// Default order by last_public_update_at DESC  
$orderColIdx = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 8;
$orderDir = isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === 'asc' ? 'ASC' : 'DESC';

// Map columns from DataTables to SQL columns
$columns = [
    'w.id',                                       // 0 Ticket #
    'p.project_name',                             // 1 Project
    'w.issue_type',                               // 2 Issue Type
    'w.responsible_party',                        // 3 Responsible Party
    'w.status',                                   // 4 Status
    'w.resolution_type',                          // 5 Replacement Details
    'w.created_at',                               // 6 Estimated Delivery (fallback to created_at for sorting)
    'w.created_at',                               // 7 Actual Delivery (fallback)
    'w.created_at',                               // 8 Opened
    'COALESCE(w.last_public_update_at, w.created_at)', // 9 Last Update
];
$orderBy = $columns[$orderColIdx] ?? $columns[9];

// Filters
$filters = loadPersistedWarrantyFilters();
// Merge with incoming GET (so deep links work) — always honor current request, including
// empty arrays or zeros so users can clear filters (e.g., show closed items).
$incoming = getWarrantyFiltersFromRequest();
foreach ($incoming as $k => $v) {
    if ($k === 'issue_types' || $k === 'statuses') {
        $filters[$k] = (array)$v; // allow clearing to []
    } elseif ($k === 'hide_closed' || $k === 'project_id') {
        $filters[$k] = (int)$v;   // allow 0 to mean "All Projects" or show closed
    } else {
        // date_from/date_to/responsible_party/search can be empty strings to clear
        $filters[$k] = $v;
    }
}
persistWarrantyFilters($filters);

// Global search
$globalSearch = $_GET['search']['value'] ?? $filters['search'] ?? '';

// Authorization scoping
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');
$allowedProjectIds = getAllowedProjectIds($conn, $userId, $role); // null => all

// Build WHERE clause
$where = [];
$params = [];
$types = '';

// Project scope
if (!empty($filters['project_id'])) {
    $where[] = 'ss.project_id = ?';
    $types .= 'i';
    $params[] = (int)$filters['project_id'];
} elseif (is_array($allowedProjectIds)) {
    if (empty($allowedProjectIds)) {
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($allowedProjectIds), '?'));
    $where[] = 'ss.project_id IN (' . $placeholders . ')';
    $types .= str_repeat('i', count($allowedProjectIds));
    $params = array_merge($params, $allowedProjectIds);
}

// Hide Closed default
if ((int)$filters['hide_closed'] === 1) {
    $where[] = "w.status <> 'Closed'";
}

// Issue types
if (!empty($filters['issue_types'])) {
    $placeholders = implode(',', array_fill(0, count($filters['issue_types']), '?'));
    $where[] = 'w.issue_type IN (' . $placeholders . ')';
    $types .= str_repeat('s', count($filters['issue_types']));
    foreach ($filters['issue_types'] as $it) $params[] = (string)$it;
}

// Responsible party
if (!empty($filters['responsible_party'])) {
    $where[] = 'w.responsible_party = ?';
    $types .= 's';
    $params[] = (string)$filters['responsible_party'];
}

// Statuses
if (!empty($filters['statuses'])) {
    $placeholders = implode(',', array_fill(0, count($filters['statuses']), '?'));
    $where[] = 'w.status IN (' . $placeholders . ')';
    $types .= str_repeat('s', count($filters['statuses']));
    foreach ($filters['statuses'] as $st) $params[] = (string)$st;
}

// Date range (Opened)
if (!empty($filters['date_from'])) {
    $where[] = 'DATE(w.created_at) >= ?';
    $types .= 's';
    $params[] = $filters['date_from'];
}
if (!empty($filters['date_to'])) {
    $where[] = 'DATE(w.created_at) <= ?';
    $types .= 's';
    $params[] = $filters['date_to'];
}

// Global search across ticket #, project, status, responsible, issue type
if (!empty($globalSearch)) {
    $where[] = '(w.id LIKE ? OR p.project_name LIKE ? OR w.status LIKE ? OR w.responsible_party LIKE ? OR w.issue_type LIKE ?)';
    $types .= 'sssss';
    $needle = '%' . $globalSearch . '%';
    array_push($params, $needle, $needle, $needle, $needle, $needle);
}

$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

// Count total and filtered
$sqlBase = "FROM warranty_claims w
            JOIN site_scheduling ss ON ss.id = w.scheduling_id
            JOIN projects p ON p.id = ss.project_id";

// Total authorized (ignore search/status/etc., but respect project scoping)
$whereTotal = [];
$typesTotal = '';
$paramsTotal = [];
if (!empty($filters['project_id'])) {
    $whereTotal[] = 'ss.project_id = ?';
    $typesTotal .= 'i';
    $paramsTotal[] = (int)$filters['project_id'];
} elseif (is_array($allowedProjectIds)) {
    if (empty($allowedProjectIds)) {
        echo json_encode(['draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[]]);
        exit;
    }
    $place = implode(',', array_fill(0, count($allowedProjectIds), '?'));
    $whereTotal[] = 'ss.project_id IN (' . $place . ')';
    $typesTotal .= str_repeat('i', count($allowedProjectIds));
    $paramsTotal = array_merge($paramsTotal, $allowedProjectIds);
}
$whereTotalSql = empty($whereTotal) ? '' : ('WHERE ' . implode(' AND ', $whereTotal));
$sqlTotal = "SELECT COUNT(*) AS c $sqlBase $whereTotalSql";
$stmtTotal = $conn->prepare($sqlTotal);
if ($typesTotal !== '') { $stmtTotal->bind_param($typesTotal, ...$paramsTotal); }
$stmtTotal->execute();
$resTotal = $stmtTotal->get_result();
$recordsTotal = (int)($resTotal->fetch_assoc()['c'] ?? 0);
$stmtTotal->close();

$sqlCount = "SELECT COUNT(*) AS c $sqlBase $whereSql";
$stmtCount = $conn->prepare($sqlCount);
if ($types !== '') $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$resCount = $stmtCount->get_result();
$recordsFiltered = (int)($resCount->fetch_assoc()['c'] ?? 0);
$stmtCount->close();

// Helper function to get replacement status and delivery date for approved replacement claims
function getReplacementStatus($conn, $claimId) {
    // Use listLinkedReplacementPalletIds function and then get status totals
    require_once __DIR__ . '/warranty_helpers.php';
    $linkedIds = listLinkedReplacementPalletIds($conn, $claimId);
    if (empty($linkedIds)) {
        return ['status' => null, 'delivery_date' => null];
    }
    
    $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
    $types = str_repeat('i', count($linkedIds));
    $stmt = $conn->prepare("SELECT status, COUNT(*) AS count, MAX(arrival_date) as max_arrival FROM inventory_pallets WHERE id IN ($placeholders) GROUP BY status ORDER BY FIELD(status, 'Delivered to Project', 'In Transit to Project', 'In Warehouse', 'In Transit to Warehouse', 'Cleared Customs', 'On Water', 'At Manufacturer') LIMIT 1");
    if ($stmt) {
        $stmt->bind_param($types, ...$linkedIds);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $status = $row['status'];
            $deliveryDate = null;
            
            // If delivered to project, get the actual delivery date
            if ($status === 'Delivered to Project') {
                $deliveryStmt = $conn->prepare("SELECT MAX(arrival_date) as delivery_date FROM inventory_pallets WHERE id IN ($placeholders) AND status = 'Delivered to Project'");
                $deliveryStmt->bind_param($types, ...$linkedIds);
                $deliveryStmt->execute();
                $deliveryResult = $deliveryStmt->get_result();
                if ($deliveryRow = $deliveryResult->fetch_assoc()) {
                    $deliveryDate = $deliveryRow['delivery_date'];
                }
                $deliveryStmt->close();
            }
            
            $stmt->close();
            return ['status' => $status, 'delivery_date' => $deliveryDate];
        }
        $stmt->close();
    }
    return ['status' => null, 'delivery_date' => null];
}

// Check if estimated_delivery_date column exists
$columnExists = false;
$checkColumn = $conn->query("SHOW COLUMNS FROM warranty_claims LIKE 'estimated_delivery_date'");
if ($checkColumn && $checkColumn->num_rows > 0) {
    $columnExists = true;
}

// Data query - including resolution details, with conditional estimated_delivery_date
$estimatedDeliveryField = $columnExists ? 'w.estimated_delivery_date' : 'NULL as estimated_delivery_date';
$sqlData = "SELECT w.id, p.project_name, w.issue_type, w.responsible_party, w.status, w.created_at,
                   COALESCE(w.last_public_update_at, w.created_at) AS last_update,
                   w.resolution_type, w.credit_amount, $estimatedDeliveryField
            $sqlBase
            $whereSql
            ORDER BY $orderBy $orderDir
            LIMIT ?, ?";

// Bind with pagination
$typesData = $types . 'ii';
$paramsData = $params;
$paramsData[] = $start;
$paramsData[] = $length;

$stmtData = $conn->prepare($sqlData);
if ($types !== '') {
    $stmtData->bind_param($typesData, ...$paramsData);
} else {
    // Only pagination
    $stmtData->bind_param('ii', $start, $length);
}
$stmtData->execute();
$resData = $stmtData->get_result();

$data = [];
while ($row = $resData->fetch_assoc()) {
    // Get replacement status and delivery date if this is an approved replacement
    $replacementInfo = ['status' => null, 'delivery_date' => null];
    if ($row['status'] === 'Approved - Replacement' || strpos($row['status'], 'Replacement') !== false) {
        $replacementInfo = getReplacementStatus($conn, (int)$row['id']);
    }
    
    $data[] = [
        (int)$row['id'],                           // 0
        (string)$row['project_name'],              // 1  
        (string)$row['issue_type'],                // 2
        (string)$row['responsible_party'],         // 3
        (string)$row['status'],                    // 4
        (string)($row['resolution_type'] ?? ''),   // 5 - for replacement details column logic
        (string)($row['estimated_delivery_date'] ?? ''), // 6 - estimated delivery
        (string)($replacementInfo['delivery_date'] ?? ''), // 7 - actual delivery
        (string)$row['created_at'],                // 8 - opened
        (string)$row['last_update'],               // 9 - last update
        '',                                        // 10 - Action column rendered client-side
        (string)($row['resolution_type'] ?? ''),   // 11 - resolution_type for JS
        (float)($row['credit_amount'] ?? 0),       // 12 - credit_amount for JS
        (string)($replacementInfo['status'] ?? ''), // 13 - replacement_status for JS
        (string)($replacementInfo['delivery_date'] ?? '') // 14 - actual delivery date for JS
    ];
}
$stmtData->close();
$conn->close();

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data' => $data,
]);
exit;

?>


