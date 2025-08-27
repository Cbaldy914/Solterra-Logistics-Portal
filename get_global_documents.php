<?php
// Clean output buffer and suppress any warnings
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Set content type header
header('Content-Type: application/json');

session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get filter parameters
$project_id = intval($_GET['project_id'] ?? 0);
$document_type = trim($_GET['document_type'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$search = trim($_GET['search'] ?? '');
$sub_filters = $_GET['sub_filters'] ?? [];
$page = max(1, intval($_GET['page'] ?? 1));
$page_size = max(1, min(1000, intval($_GET['page_size'] ?? 100))); // Max 1000 per page

// PODs-specific filters (optional)
$pods_delivery_start = trim($_GET['pods_delivery_start'] ?? '');
$pods_delivery_end = trim($_GET['pods_delivery_end'] ?? '');
$pods_bol = trim($_GET['pods_bol'] ?? '');
$pods_manufacturer = trim($_GET['pods_manufacturer'] ?? '');
$pods_manufacturer_id = isset($_GET['pods_manufacturer_id']) ? intval($_GET['pods_manufacturer_id']) : 0;
$pods_supplier = trim($_GET['pods_supplier'] ?? '');
$pods_wattages = isset($_GET['pods_wattages']) ? (array)$_GET['pods_wattages'] : [];
$pods_wattage_min = isset($_GET['pods_wattage_min']) && $_GET['pods_wattage_min'] !== '' ? intval($_GET['pods_wattage_min']) : null;
$pods_wattage_max = isset($_GET['pods_wattage_max']) && $_GET['pods_wattage_max'] !== '' ? intval($_GET['pods_wattage_max']) : null;

// Shipments-specific filters (optional)
$ship_cleared_start = trim($_GET['ship_cleared_start'] ?? '');
$ship_cleared_end = trim($_GET['ship_cleared_end'] ?? '');
$ship_container = trim($_GET['ship_container'] ?? '');
$ship_supplier = trim($_GET['ship_supplier'] ?? '');
$ship_wattages = isset($_GET['ship_wattages']) ? (array)$_GET['ship_wattages'] : [];

// Invoices-specific filters (optional)
$invoice_total_min = isset($_GET['invoice_total_min']) && $_GET['invoice_total_min'] !== '' ? floatval($_GET['invoice_total_min']) : null;
$invoice_total_max = isset($_GET['invoice_total_max']) && $_GET['invoice_total_max'] !== '' ? floatval($_GET['invoice_total_max']) : null;
$invoice_date_start = trim($_GET['invoice_date_start'] ?? '');
$invoice_date_end = trim($_GET['invoice_date_end'] ?? '');
$invoice_due_start = trim($_GET['invoice_due_start'] ?? '');
$invoice_due_end = trim($_GET['invoice_due_end'] ?? '');
$invoice_bol = trim($_GET['invoice_bol'] ?? '');
$invoice_manufacturer = trim($_GET['invoice_manufacturer'] ?? '');

// Warehousing-specific filters
$wh_delivery_start = trim($_GET['wh_delivery_start'] ?? '');
$wh_delivery_end = trim($_GET['wh_delivery_end'] ?? '');
$wh_bol = trim($_GET['wh_bol'] ?? '');
$wh_supplier = trim($_GET['wh_supplier'] ?? '');
$wh_warehouse_id = isset($_GET['wh_warehouse_id']) ? intval($_GET['wh_warehouse_id']) : 0;
$wh_wattages = isset($_GET['wh_wattages']) ? (array)$_GET['wh_wattages'] : [];
// Build the base query with proper permission checking
$base_sql = "
    SELECT 
        pd.id,
        pd.original_file_name as filename,
        pd.file_size,
        pd.mime_type,
        pd.uploaded_at,
        pd.description,
        pd.file_path,
        pd.document_type,
        pd.document_sub_type,
        pd.delivery_id,
        pd.warehouse_id,
        pd.project_invoice_id,
        pd.manufacturer_id,
        pd.is_safe_harbor,
        pd.project_id,
        p.project_name,
        u.username as uploaded_by_name,
        d.bol_number,
        d.supplier as supplier_name,
        d.actual_delivery_date,
        d.wattage as delivery_wattage,
        d.container_number,
        d.customs_cleared_date,
        w.name as warehouse_name,
        pi.invoice_number,
        pi.amount as invoice_amount,
        pi.issued_date as invoice_issued_date,
        pi.due_date as invoice_due_date,
        m.name as manufacturer_name
    FROM project_documents pd
    LEFT JOIN users u ON pd.uploaded_by = u.id
    LEFT JOIN deliveries d ON pd.delivery_id = d.id
    LEFT JOIN warehouses w ON pd.warehouse_id = w.id
    LEFT JOIN project_invoices pi ON pd.project_invoice_id = pi.id
    LEFT JOIN manufacturers m ON pd.manufacturer_id = m.id
    JOIN projects p ON pd.project_id = p.id
";

// Add permission-based WHERE clause
$where_conditions = ["pd.is_active = 1"];
$params = [];
$param_types = "";

if ($user_role === 'global_admin') {
    // Global admin can access all projects - no additional WHERE needed
} else {
    // Admin and regular users can only access their account's projects
    $where_conditions[] = "p.account_id IN (
        SELECT account_id 
        FROM customer_account_users 
        WHERE user_id = ?
    )";
    $params[] = $user_id;
    $param_types .= "i";
}

// Add filter conditions
if ($project_id > 0) {
    $where_conditions[] = "pd.project_id = ?";
    $params[] = $project_id;
    $param_types .= "i";
}

if (!empty($document_type)) {
    $where_conditions[] = "pd.document_type = ?";
    $params[] = $document_type;
    $param_types .= "s";
}

if (!empty($start_date)) {
    $where_conditions[] = "DATE(pd.uploaded_at) >= ?";
    $params[] = $start_date;
    $param_types .= "s";
}

if (!empty($end_date)) {
    $where_conditions[] = "DATE(pd.uploaded_at) <= ?";
    $params[] = $end_date;
    $param_types .= "s";
}

if (!empty($search)) {
    $where_conditions[] = "(pd.original_file_name LIKE ? OR pd.description LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ss";
}

// Handle sub-filters using the new document_sub_type field
if (!empty($sub_filters) && is_array($sub_filters)) {
    $sub_filter_conditions = [];
    foreach ($sub_filters as $sub_filter) {
        $sub_filter_conditions[] = "pd.document_sub_type = ?";
        $params[] = trim($sub_filter);
        $param_types .= "s";
    }
    if (!empty($sub_filter_conditions)) {
        $where_conditions[] = "(" . implode(" OR ", $sub_filter_conditions) . ")";
    }
}

// PODs-specific filter conditions (apply when values present)
if (!empty($pods_bol)) {
    $where_conditions[] = "d.bol_number LIKE ?";
    $params[] = '%' . $pods_bol . '%';
    $param_types .= "s";
}
if (!empty($pods_delivery_start)) {
    $where_conditions[] = "DATE(d.actual_delivery_date) >= ?";
    $params[] = $pods_delivery_start;
    $param_types .= "s";
}
if (!empty($pods_delivery_end)) {
    $where_conditions[] = "DATE(d.actual_delivery_date) <= ?";
    $params[] = $pods_delivery_end;
    $param_types .= "s";
}
if (!empty($pods_manufacturer)) {
    // Match by manufacturer name when joined; keep LEFT JOIN safe
    $where_conditions[] = "(m.name LIKE ? OR pd.manufacturer_id IN (SELECT id FROM manufacturers WHERE name LIKE ?))";
    $like = '%' . $pods_manufacturer . '%';
    $params[] = $like;
    $params[] = $like;
    $param_types .= "ss";
}
if (!empty($pods_supplier)) {
    $where_conditions[] = "d.supplier = ?";
    $params[] = $pods_supplier;
    $param_types .= "s";
}
if ($pods_manufacturer_id > 0) {
    $where_conditions[] = "(m.id = ? OR pd.manufacturer_id = ?)";
    $params[] = $pods_manufacturer_id;
    $params[] = $pods_manufacturer_id;
    $param_types .= "ii";
}
if ($pods_wattage_min !== null) {
    $where_conditions[] = "d.wattage >= ?";
    $params[] = $pods_wattage_min;
    $param_types .= "i";
}
if ($pods_wattage_max !== null) {
    $where_conditions[] = "d.wattage <= ?";
    $params[] = $pods_wattage_max;
    $param_types .= "i";
}
if (!empty($pods_wattages)) {
    // Build IN clause safely
    $wlist = array_values(array_filter(array_map('intval', $pods_wattages), function($v){ return $v !== 0 || $v === 0; }));
    if (!empty($wlist)) {
        $placeholders = implode(',', array_fill(0, count($wlist), '?'));
        $where_conditions[] = "d.wattage IN ($placeholders)";
        foreach ($wlist as $w) { $params[] = $w; $param_types .= 'i'; }
    }
}

// Shipments-specific filter conditions
if (!empty($ship_container)) {
    $where_conditions[] = "d.container_number LIKE ?";
    $params[] = '%' . $ship_container . '%';
    $param_types .= 's';
}
if (!empty($ship_cleared_start)) {
    $where_conditions[] = "DATE(d.customs_cleared_date) >= ?";
    $params[] = $ship_cleared_start;
    $param_types .= 's';
}
if (!empty($ship_cleared_end)) {
    $where_conditions[] = "DATE(d.customs_cleared_date) <= ?";
    $params[] = $ship_cleared_end;
    $param_types .= 's';
}
if (!empty($ship_supplier)) {
    $where_conditions[] = "(d.supplier = ? OR m.name = ?)";
    $params[] = $ship_supplier;
    $params[] = $ship_supplier;
    $param_types .= 'ss';
}
if (!empty($ship_wattages)) {
    $wlist = array_values(array_filter(array_map('intval', $ship_wattages), function($v){ return $v !== 0 || $v === 0; }));
    if (!empty($wlist)) {
        $placeholders = implode(',', array_fill(0, count($wlist), '?'));
        $where_conditions[] = "d.wattage IN ($placeholders)";
        foreach ($wlist as $w) { $params[] = $w; $param_types .= 'i'; }
    }
}

// Combine WHERE conditions
$where_clause = "";
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Invoices-specific filter conditions
if ($invoice_total_min !== null) {
    $where_conditions[] = "pi.amount >= ?";
    $params[] = $invoice_total_min;
    $param_types .= 'd';
}
if ($invoice_total_max !== null) {
    $where_conditions[] = "pi.amount <= ?";
    $params[] = $invoice_total_max;
    $param_types .= 'd';
}
if (!empty($invoice_date_start)) {
    $where_conditions[] = "DATE(pi.issued_date) >= ?";
    $params[] = $invoice_date_start;
    $param_types .= 's';
}
if (!empty($invoice_date_end)) {
    $where_conditions[] = "DATE(pi.issued_date) <= ?";
    $params[] = $invoice_date_end;
    $param_types .= 's';
}
if (!empty($invoice_bol)) {
    $where_conditions[] = "d.bol_number LIKE ?";
    $params[] = '%' . $invoice_bol . '%';
    $param_types .= 's';
}
if (!empty($invoice_manufacturer)) {
    $where_conditions[] = "(m.name = ? OR d.supplier = ?)";
    $params[] = $invoice_manufacturer;
    $params[] = $invoice_manufacturer;
    $param_types .= 'ss';
}

// Warehousing-specific filter conditions
if ($document_type === 'warehousing') {
    // Determine selected warehousing subtypes
    $selected_wh_pod = false; $selected_wh_basic = false;
    if (!empty($sub_filters) && is_array($sub_filters)) {
        foreach ($sub_filters as $sf) {
            if (trim($sf) === 'Warehouse POD') $selected_wh_pod = true;
            if (trim($sf) === 'Inventory Report' || trim($sf) === 'Photos') $selected_wh_basic = true;
        }
    }
    if ($selected_wh_pod) {
        if (!empty($wh_bol)) {
            $where_conditions[] = "d.bol_number LIKE ?";
            $params[] = '%' . $wh_bol . '%';
            $param_types .= 's';
        }
        if (!empty($wh_delivery_start)) {
            $where_conditions[] = "DATE(d.actual_delivery_date) >= ?";
            $params[] = $wh_delivery_start;
            $param_types .= 's';
        }
        if (!empty($wh_delivery_end)) {
            $where_conditions[] = "DATE(d.actual_delivery_date) <= ?";
            $params[] = $wh_delivery_end;
            $param_types .= 's';
        }
        if (!empty($wh_supplier)) {
            $where_conditions[] = "(m.name LIKE ? OR d.supplier LIKE ?)";
            $like = '%' . $wh_supplier . '%';
            $params[] = $like; $params[] = $like; $param_types .= 'ss';
        }
        if (!empty($wh_wattages)) {
            $wlist = array_values(array_filter(array_map('intval', $wh_wattages), function($v){ return $v !== 0 || $v === 0; }));
            if (!empty($wlist)) {
                $placeholders = implode(',', array_fill(0, count($wlist), '?'));
                $where_conditions[] = "d.wattage IN ($placeholders)";
                foreach ($wlist as $w) { $params[] = $w; $param_types .= 'i'; }
            }
        }
    }
    if ($selected_wh_basic) {
        if ($wh_warehouse_id > 0) {
            $where_conditions[] = "pd.warehouse_id = ?";
            $params[] = $wh_warehouse_id;
            $param_types .= 'i';
        }
        if (!empty($wh_supplier)) {
            // If doc linked to delivery, use d.supplier/m.name; otherwise, check any delivery at same warehouse+project
            $where_conditions[] = "(m.name = ? OR d.supplier = ? OR EXISTS (SELECT 1 FROM deliveries dx WHERE dx.project_id = pd.project_id AND dx.warehouse_id = pd.warehouse_id AND dx.supplier = ?))";
            $params[] = $wh_supplier; $params[] = $wh_supplier; $params[] = $wh_supplier;
            $param_types .= 'sss';
        }
        if (!empty($wh_wattages)) {
            $wlist = array_values(array_filter(array_map('intval', $wh_wattages), function($v){ return $v !== 0 || $v === 0; }));
            if (!empty($wlist)) {
                // Match if doc delivery has wattage OR any delivery at same warehouse+project has wattage
                $placeholders = implode(',', array_fill(0, count($wlist), '?'));
                $where_conditions[] = "(d.wattage IN ($placeholders) OR EXISTS (SELECT 1 FROM deliveries dx WHERE dx.project_id = pd.project_id AND dx.warehouse_id = pd.warehouse_id AND dx.wattage IN ($placeholders)))";
                foreach ($wlist as $w) { $params[] = $w; $param_types .= 'i'; }
                foreach ($wlist as $w) { $params[] = $w; $param_types .= 'i'; }
            }
        }
    }
}

// Invoice due date filters
if (!empty($invoice_due_start)) {
    $where_conditions[] = "DATE(pi.due_date) >= ?";
    $params[] = $invoice_due_start;
    $param_types .= 's';
}
if (!empty($invoice_due_end)) {
    $where_conditions[] = "DATE(pi.due_date) <= ?";
    $params[] = $invoice_due_end;
    $param_types .= 's';
}

// Get total count first
$count_sql = "
    SELECT COUNT(*) as total
    FROM project_documents pd
    JOIN projects p ON pd.project_id = p.id
    LEFT JOIN deliveries d ON pd.delivery_id = d.id
    LEFT JOIN warehouses w ON pd.warehouse_id = w.id
    LEFT JOIN project_invoices pi ON pd.project_invoice_id = pi.id
    LEFT JOIN manufacturers m ON pd.manufacturer_id = m.id
    " . $where_clause;

try {
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->bind_param($param_types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_count = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
    
    // Calculate pagination
    $total_pages = ceil($total_count / $page_size);
    $offset = ($page - 1) * $page_size;
    
    // Get paginated results
    $data_sql = $base_sql . $where_clause . " ORDER BY pd.uploaded_at DESC LIMIT ? OFFSET ?";
    
    // Add pagination parameters
    $params[] = $page_size;
    $params[] = $offset;
    $param_types .= "ii";
    
    $data_stmt = $conn->prepare($data_sql);
    if (!empty($params)) {
        $data_stmt->bind_param($param_types, ...$params);
    }
    $data_stmt->execute();
    $data_result = $data_stmt->get_result();
    
    $documents = [];
    while ($row = $data_result->fetch_assoc()) {
        // Format file size
        $size = $row['file_size'];
        if ($size < 1024) {
            $formatted_size = $size . ' B';
        } elseif ($size < 1024 * 1024) {
            $formatted_size = round($size / 1024, 1) . ' KB';
        } else {
            $formatted_size = round($size / (1024 * 1024), 1) . ' MB';
        }
        
        $documents[] = [
            'id' => $row['id'],
            'filename' => $row['filename'],
            'size' => $formatted_size,
            'size_bytes' => $row['file_size'],
            'mime_type' => $row['mime_type'],
            'uploaded_at' => $row['uploaded_at'],
            'uploaded_by' => $row['uploaded_by_name'],
            'description' => $row['description'],
            'document_type' => $row['document_type'],
            'document_sub_type' => $row['document_sub_type'],
            'delivery_id' => $row['delivery_id'],
            'warehouse_id' => $row['warehouse_id'],
            'project_invoice_id' => $row['project_invoice_id'],
            'manufacturer_id' => $row['manufacturer_id'],
            'is_safe_harbor' => (bool)$row['is_safe_harbor'],
            'project_id' => $row['project_id'],
            'project_name' => $row['project_name'],
            'bol_number' => $row['bol_number'],
            'actual_delivery_date' => $row['actual_delivery_date'],
            'delivery_wattage' => $row['delivery_wattage'],
            'warehouse_name' => $row['warehouse_name'],
            'invoice_number' => $row['invoice_number'],
            'invoice_amount' => $row['invoice_amount'],
            'invoice_issued_date' => $row['invoice_issued_date'],
            'invoice_due_date' => $row['invoice_due_date'],
            'manufacturer_name' => ($row['manufacturer_name'] ?: $row['supplier_name']),
            'container_number' => $row['container_number'],
            'customs_cleared_date' => $row['customs_cleared_date']
        ];
    }
    $data_stmt->close();
    
    // Clean any output that might have been generated
    ob_end_clean();
    
    // Send clean JSON response
    echo json_encode([
        'success' => true,
        'documents' => $documents,
        'total_count' => $total_count,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'page_size' => $page_size,
        'showing_count' => count($documents)
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Error in get_global_documents.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}

$conn->close();
exit();
?>
$invoice_due_start = trim($_GET['invoice_due_start'] ?? '');
$invoice_due_end = trim($_GET['invoice_due_end'] ?? '');
