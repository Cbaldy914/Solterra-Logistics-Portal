<?php
session_name("logistics_session");
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$pageSize = isset($_GET['pageSize']) ? min(500, max(1, intval($_GET['pageSize']))) : 100;
$offset = ($page - 1) * $pageSize;

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$projectFilter = isset($_GET['project']) ? trim($_GET['project']) : '';
$wattageFilter = isset($_GET['wattage']) ? trim($_GET['wattage']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$projectIdFromUrl = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

// Get account_id for admin role
$account_id_for_admin = null;
if ($role === 'admin') {
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $account_id_for_admin = $row['account_id'];
    }
    $stmt->close();
}

// Allowed statuses
$allowed_statuses = [
    'At Manufacturer',
    'In Warehouse',
    'Delivered to Project',
    'Allocated to Project',
    'In Transit to Warehouse',
    'In Transit to Project',
    'On Water'
];

try {
    // Build base query
    $baseSelect = "SELECT
        ip.id AS pallet_id,
        ip.pallet_identifier,
        ip.wattage,
        ip.quantity,
        ip.status,
        ip.arrival_date,
        ip.unassigned_module_item_id,
        ip.current_warehouse_id,
        ip.current_project_id,
        ip.assigned_project_id,
        ip.manufacturer_location_id,
        m.vendor_name AS origin_vendor,
        m.pallets_per_truck AS module_pallets_per_truck,
        COALESCE(
            CONCAT(ml.street_address, ', ', ml.city, ', ', ml.state, ' ', ml.zip_code),
            m.initial_location
        ) AS origin_vendor_address,
        COALESCE(mfg.name,
            CASE
                WHEN m.vendor_name LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(m.vendor_name, '-', 1))
                ELSE m.vendor_name
            END
        ) AS origin_vendor_name,
        COALESCE(ml.location_name, '') AS origin_location_name,
        COALESCE(ml.city, '') AS origin_vendor_city,
        COALESCE(ml.state, '') AS origin_vendor_state,
        COALESCE(ml.country, 'USA') AS origin_vendor_country,
        m.account_id AS pallet_account_id,
        w.name AS current_warehouse_name,
        w.street_address as warehouse_street, w.city as warehouse_city, w.state as warehouse_state, w.zip_code as warehouse_zip,
        p_current.project_name AS current_project_name,
        p_current.account_id AS current_project_account_id,
        p_current.street_address as project_street, p_current.city as project_city, p_current.state as project_state, p_current.zip_code as project_zip,
        p_assigned.project_name AS assigned_project_name,
        p_assigned.account_id AS assigned_project_account_id,
        COALESCE(p_current.project_name, p_assigned.project_name, 'Unassigned') AS display_project_name,
        GROUP_CONCAT(DISTINCT CONCAT(d.id, ':', COALESCE(d.bol_number, 'No BOL')) ORDER BY d.id SEPARATOR '|') as delivery_info";

    $baseFrom = " FROM inventory_pallets ip
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN manufacturer_locations ml ON ip.manufacturer_location_id = ml.id
        LEFT JOIN manufacturers mfg ON ml.manufacturer_id = mfg.id
        LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
        LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
        LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
        LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
        LEFT JOIN deliveries d ON dp.delivery_id = d.id";

    $params = [];
    $types = '';

    // Build WHERE clause
    $whereConditions = [];
    $status_placeholders = str_repeat('?,', count($allowed_statuses) - 1) . '?';
    $whereConditions[] = "ip.status IN ($status_placeholders)";
    foreach ($allowed_statuses as $status) {
        $params[] = $status;
        $types .= 's';
    }

    // Account filtering for admin role
    if ($role === 'admin' && $account_id_for_admin) {
        $whereConditions[] = "(p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?)";
        $params[] = $account_id_for_admin;
        $params[] = $account_id_for_admin;
        $params[] = $account_id_for_admin;
        $types .= 'iii';
    }

    // Project filter from URL
    if ($projectIdFromUrl > 0) {
        $whereConditions[] = "(p_current.id = ? OR p_assigned.id = ?)";
        $params[] = $projectIdFromUrl;
        $params[] = $projectIdFromUrl;
        $types .= 'ii';
    }

    // User filters
    if ($projectFilter !== '') {
        if ($projectFilter === 'Unassigned') {
            $whereConditions[] = "COALESCE(p_current.project_name, p_assigned.project_name, 'Unassigned') = 'Unassigned'";
        } else {
            $whereConditions[] = "COALESCE(p_current.project_name, p_assigned.project_name, 'Unassigned') = ?";
            $params[] = $projectFilter;
            $types .= 's';
        }
    }

    if ($wattageFilter !== '') {
        $whereConditions[] = "ip.wattage = ?";
        $params[] = $wattageFilter;
        $types .= 's';
    }

    if ($statusFilter !== '') {
        $whereConditions[] = "ip.status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }

    if ($search !== '') {
        $searchParam = "%$search%";
        $whereConditions[] = "(ip.pallet_identifier LIKE ? OR ip.status LIKE ? OR COALESCE(p_current.project_name, p_assigned.project_name, '') LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'sss';
    }

    $whereClause = " WHERE " . implode(" AND ", $whereConditions);
    $groupBy = " GROUP BY ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status, ip.arrival_date,
                 ip.unassigned_module_item_id, ip.current_warehouse_id, ip.current_project_id, ip.assigned_project_id,
                 ip.manufacturer_location_id, m.vendor_name, m.pallets_per_truck, m.account_id, ml.street_address,
                 ml.city, ml.state, ml.zip_code, ml.country, ml.location_name, mfg.name, w.name, w.street_address,
                 w.city, w.state, w.zip_code, p_current.project_name, p_current.account_id, p_current.street_address,
                 p_current.city, p_current.state, p_current.zip_code, p_assigned.project_name, p_assigned.account_id";

    // Get total count first
    $countSql = "SELECT COUNT(DISTINCT ip.id) as total" . $baseFrom . $whereClause;
    $countStmt = $conn->prepare($countSql);
    if (!empty($types)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Get paginated results
    $sql = $baseSelect . $baseFrom . $whereClause . $groupBy . " ORDER BY ip.id ASC LIMIT ? OFFSET ?";
    $params[] = $pageSize;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $pallets = [];
    while ($row = $result->fetch_assoc()) {
        $pallets[] = $row;
    }
    $stmt->close();

    // Get filter options for dropdowns (only on first page load or when needed)
    $filterOptions = [];
    if ($page === 1) {
        // Get distinct projects
        $projectsSql = "SELECT DISTINCT COALESCE(p_current.project_name, p_assigned.project_name, 'Unassigned') as project_name
                        FROM inventory_pallets ip
                        LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
                        LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
                        WHERE ip.status IN ($status_placeholders)";
        if ($role === 'admin' && $account_id_for_admin) {
            $projectsSql .= " AND (p_current.account_id = ? OR p_assigned.account_id = ?)";
        }
        $projectsSql .= " ORDER BY project_name";

        $pStmt = $conn->prepare($projectsSql);
        if ($role === 'admin' && $account_id_for_admin) {
            $pParams = array_merge($allowed_statuses, [$account_id_for_admin, $account_id_for_admin]);
            $pTypes = str_repeat('s', count($allowed_statuses)) . 'ii';
            $pStmt->bind_param($pTypes, ...$pParams);
        } else {
            $pTypes = str_repeat('s', count($allowed_statuses));
            $pStmt->bind_param($pTypes, ...$allowed_statuses);
        }
        $pStmt->execute();
        $pResult = $pStmt->get_result();
        $filterOptions['projects'] = [];
        while ($pRow = $pResult->fetch_assoc()) {
            $filterOptions['projects'][] = $pRow['project_name'];
        }
        $pStmt->close();

        // Get distinct wattages
        $wattagesSql = "SELECT DISTINCT ip.wattage FROM inventory_pallets ip WHERE ip.status IN ($status_placeholders) ORDER BY ip.wattage";
        $wStmt = $conn->prepare($wattagesSql);
        $wStmt->bind_param(str_repeat('s', count($allowed_statuses)), ...$allowed_statuses);
        $wStmt->execute();
        $wResult = $wStmt->get_result();
        $filterOptions['wattages'] = [];
        while ($wRow = $wResult->fetch_assoc()) {
            $filterOptions['wattages'][] = $wRow['wattage'];
        }
        $wStmt->close();

        // Statuses are fixed
        $filterOptions['statuses'] = $allowed_statuses;
    }

    echo json_encode([
        'success' => true,
        'pallets' => $pallets,
        'totalCount' => $totalCount,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalPages' => ceil($totalCount / $pageSize),
        'filterOptions' => $filterOptions
    ]);

} catch (Exception $e) {
    error_log("Error in get_shipment_pallets.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();
