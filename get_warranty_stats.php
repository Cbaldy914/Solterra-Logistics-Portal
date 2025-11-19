<?php
// Returns JSON stats for warranty claims filtered like the table

session_name('logistics_session');
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';
require_once __DIR__ . '/warranty_filters.php';

$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['error' => 'db']);
    exit;
}

// Merge persisted + incoming filters
$filters = loadPersistedWarrantyFilters();
$incoming = getWarrantyFiltersFromRequest();
foreach ($incoming as $k => $v) {
    if ($k === 'issue_types' || $k === 'statuses') {
        $filters[$k] = (array)$v;
    } elseif ($k === 'hide_closed' || $k === 'project_id') {
        $filters[$k] = (int)$v;
    } else {
        $filters[$k] = $v;
    }
}
persistWarrantyFilters($filters);

// Authorization
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');
$allowedProjectIds = getAllowedProjectIds($conn, $userId, $role);

$where = [];
$types = '';
$params = [];

if (!empty($filters['project_id'])) {
    $where[] = 'ss.project_id = ?';
    $types  .= 'i';
    $params[] = (int)$filters['project_id'];
} elseif (is_array($allowedProjectIds)) {
    if (empty($allowedProjectIds)) {
        echo json_encode([
            'total_tickets' => 0,
            'closed_tickets' => 0,
            'pending_tickets' => 0,
            'approved_tickets' => 0,
        ]);
        exit;
    }
    $place = implode(',', array_fill(0, count($allowedProjectIds), '?'));
    $where[] = 'ss.project_id IN (' . $place . ')';
    $types  .= str_repeat('i', count($allowedProjectIds));
    foreach ($allowedProjectIds as $pid) $params[] = (int)$pid;
}

if ((int)($filters['hide_closed'] ?? 0) === 1) {
    $where[] = "w.status <> 'Closed'";
}
if (!empty($filters['issue_types'])) {
    $place = implode(',', array_fill(0, count($filters['issue_types']), '?'));
    $where[] = 'w.issue_type IN (' . $place . ')';
    $types  .= str_repeat('s', count($filters['issue_types']));
    foreach ($filters['issue_types'] as $it) $params[] = (string)$it;
}
if (!empty($filters['responsible_party'])) {
    $where[] = 'w.responsible_party = ?';
    $types  .= 's';
    $params[] = (string)$filters['responsible_party'];
}
if (!empty($filters['statuses'])) {
    $place = implode(',', array_fill(0, count($filters['statuses']), '?'));
    $where[] = 'w.status IN (' . $place . ')';
    $types  .= str_repeat('s', count($filters['statuses']));
    foreach ($filters['statuses'] as $st) $params[] = (string)$st;
}
if (!empty($filters['date_from'])) {
    $where[] = 'DATE(w.created_at) >= ?';
    $types  .= 's';
    $params[] = (string)$filters['date_from'];
}
if (!empty($filters['date_to'])) {
    $where[] = 'DATE(w.created_at) <= ?';
    $types  .= 's';
    $params[] = (string)$filters['date_to'];
}

$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
$sql = "SELECT 
            COUNT(*) as total_tickets,
            SUM(CASE WHEN w.status = 'Closed' THEN 1 ELSE 0 END) as closed_tickets,
            SUM(CASE WHEN w.status LIKE '%Pending%' THEN 1 ELSE 0 END) as pending_tickets,
            SUM(CASE WHEN w.status LIKE '%Approved%' THEN 1 ELSE 0 END) as approved_tickets
        FROM warranty_claims w
        JOIN site_scheduling ss ON ss.id = w.scheduling_id
        $whereSql";
$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$stats = $res->fetch_assoc() ?: [
    'total_tickets' => 0,
    'closed_tickets' => 0,
    'pending_tickets' => 0,
    'approved_tickets' => 0,
];
$stmt->close();
$conn->close();

echo json_encode($stats);
exit;

?>

