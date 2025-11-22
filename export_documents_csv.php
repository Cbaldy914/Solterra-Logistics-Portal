<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../config.php';
require_once 'document_helpers.php';

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

// Get the filters from the request
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$project_id = isset($input['project']) ? intval($input['project']) : 0;
$document_type = isset($input['document_type']) ? trim($input['document_type']) : '';
$search_term = isset($input['search']) ? trim($input['search']) : '';
$start_date = isset($input['start_date']) ? trim($input['start_date']) : '';
$end_date = isset($input['end_date']) ? trim($input['end_date']) : '';
$warehouse_id = isset($input['warehouse']) ? intval($input['warehouse']) : 0;
$manufacturer = isset($input['manufacturer']) ? trim($input['manufacturer']) : '';
$bol_number = isset($input['bol_number']) ? trim($input['bol_number']) : '';

$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Connection failed']);
    exit();
}

// Build the query to fetch documents
$sql = "SELECT 
    pd.id,
    pd.original_file_name,
    pd.document_type,
    pd.document_sub_type,
    pd.file_size,
    pd.uploaded_at,
    u.username as uploaded_by,
    p.project_name,
    w.name as warehouse_name
FROM project_documents pd
LEFT JOIN users u ON pd.uploaded_by = u.id
LEFT JOIN projects p ON pd.project_id = p.id
LEFT JOIN warehouses w ON pd.warehouse_id = w.id
WHERE pd.is_active = 1";

$params = [];
$types = '';

// Filter by project if specified
if ($project_id > 0) {
    // Check project access
    if ($role === 'admin') {
        $admin_accounts = account_ids_for_user($user_id);
        if (!empty($admin_accounts)) {
            $placeholders = implode(',', array_fill(0, count($admin_accounts), '?'));
            $sql .= " AND p.account_id IN ($placeholders)";
            foreach ($admin_accounts as $acc_id) {
                $params[] = $acc_id;
                $types .= 'i';
            }
        }
    } elseif ($role === 'global_admin') {
        // Global admin can see all
    } else {
        // Regular users - no access to this function
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        $conn->close();
        exit();
    }
}

// Filter by document type
if (!empty($document_type)) {
    $sql .= " AND pd.document_type = ?";
    $params[] = $document_type;
    $types .= 's';
}

// Filter by search term
if (!empty($search_term)) {
    $sql .= " AND (pd.original_file_name LIKE ? OR pd.description LIKE ?)";
    $search_pattern = "%$search_term%";
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $types .= 'ss';
}

// Filter by date range
if (!empty($start_date)) {
    $sql .= " AND DATE(pd.uploaded_at) >= ?";
    $params[] = $start_date;
    $types .= 's';
}
if (!empty($end_date)) {
    $sql .= " AND DATE(pd.uploaded_at) <= ?";
    $params[] = $end_date;
    $types .= 's';
}

// Filter by warehouse
if ($warehouse_id > 0) {
    $sql .= " AND pd.warehouse_id = ?";
    $params[] = $warehouse_id;
    $types .= 'i';
}

// Filter by BOL number (stored in description or entity_context)
if (!empty($bol_number)) {
    $sql .= " AND (pd.description LIKE ? OR pd.entity_context LIKE ?)";
    $bol_pattern = "%$bol_number%";
    $params[] = $bol_pattern;
    $params[] = $bol_pattern;
    $types .= 'ss';
}

$sql .= " ORDER BY pd.uploaded_at DESC";

// Execute query
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Build CSV data
$csv_data = array();
$headers = array('Document Name', 'Type', 'Sub-Type', 'Project', 'Warehouse', 'Size (KB)', 'Uploaded Date', 'Uploaded By');
$csv_data[] = $headers;

while ($row = $result->fetch_assoc()) {
    $size_kb = round($row['file_size'] / 1024, 2);
    $csv_data[] = array(
        $row['original_file_name'] ?? '',
        $row['document_type'] ?? '',
        $row['document_sub_type'] ?? '',
        $row['project_name'] ?? 'N/A',
        $row['warehouse_name'] ?? 'N/A',
        $size_kb,
        $row['uploaded_at'] ?? '',
        $row['uploaded_by'] ?? 'Unknown'
    );
}

$stmt->close();
$conn->close();

// Generate CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="documents_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
foreach ($csv_data as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit();
?>

