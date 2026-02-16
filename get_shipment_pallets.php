<?php
session_name("logistics_session");
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin', 'customer_admin', 'user'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$is_global_admin = ($role === 'global_admin');

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pageSize = isset($_GET['pageSize']) ? min(500, max(1, (int)$_GET['pageSize'])) : 100;
$offset = ($page - 1) * $pageSize;

// Get filter parameters
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$projectFilter = isset($_GET['project']) ? trim((string)$_GET['project']) : '';
$wattageFilter = isset($_GET['wattage']) ? trim((string)$_GET['wattage']) : '';
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$projectIdFromUrl = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$account_id_for_user = null;
if (!$is_global_admin) {
    if (in_array($role, ['admin', 'customer_admin'], true)) {
        $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    } else {
        $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    }

    if ($stmtAccount) {
        $stmtAccount->bind_param('i', $user_id);
        $stmtAccount->execute();
        $stmtAccount->bind_result($acct);
        if ($stmtAccount->fetch()) {
            $account_id_for_user = (int)$acct;
        }
        $stmtAccount->close();
    }

    if (!$account_id_for_user) {
        echo json_encode(['success' => false, 'message' => 'User is not associated with an account']);
        $conn->close();
        exit();
    }
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
    $baseSelect = "SELECT
        ip.id AS pallet_id,
        ip.pallet_identifier,
        ip.manufacturer_pallet_id,
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
        w.street_address AS warehouse_street,
        w.city AS warehouse_city,
        w.state AS warehouse_state,
        w.zip_code AS warehouse_zip,
        p_current.project_name AS current_project_name,
        p_current.account_id AS current_project_account_id,
        p_current.street_address AS project_street,
        p_current.city AS project_city,
        p_current.state AS project_state,
        p_current.zip_code AS project_zip,
        p_assigned.project_name AS assigned_project_name,
        p_assigned.account_id AS assigned_project_account_id,
        COALESCE(p_current.project_name, p_assigned.project_name, 'Unassigned') AS display_project_name,
        GROUP_CONCAT(DISTINCT CONCAT(d.id, ':', COALESCE(d.bol_number, 'No BOL')) ORDER BY d.id SEPARATOR '|') AS delivery_info";

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
    $whereConditions = [];

    $status_placeholders = implode(',', array_fill(0, count($allowed_statuses), '?'));
    $whereConditions[] = "ip.status IN ($status_placeholders)";
    foreach ($allowed_statuses as $status) {
        $params[] = $status;
        $types .= 's';
    }

    if (!$is_global_admin && $account_id_for_user) {
        $whereConditions[] = "(p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?)";
        $params[] = $account_id_for_user;
        $params[] = $account_id_for_user;
        $params[] = $account_id_for_user;
        $types .= 'iii';
    }

    if ($projectIdFromUrl > 0) {
        $whereConditions[] = "(p_current.id = ? OR p_assigned.id = ?)";
        $params[] = $projectIdFromUrl;
        $params[] = $projectIdFromUrl;
        $types .= 'ii';
    }

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
        $whereConditions[] = 'ip.wattage = ?';
        $params[] = $wattageFilter;
        $types .= 's';
    }

    if ($statusFilter !== '') {
        $whereConditions[] = 'ip.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    if ($search !== '') {
        $searchParam = "%{$search}%";
        $whereConditions[] = "(ip.pallet_identifier LIKE ? OR ip.manufacturer_pallet_id LIKE ? OR ip.status LIKE ? OR COALESCE(p_current.project_name, p_assigned.project_name, '') LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'ssss';
    }

    $whereClause = ' WHERE ' . implode(' AND ', $whereConditions);

    $groupBy = " GROUP BY
        ip.id,
        ip.pallet_identifier,
        ip.manufacturer_pallet_id,
        ip.wattage,
        ip.quantity,
        ip.status,
        ip.arrival_date,
        ip.unassigned_module_item_id,
        ip.current_warehouse_id,
        ip.current_project_id,
        ip.assigned_project_id,
        ip.manufacturer_location_id,
        m.vendor_name,
        m.pallets_per_truck,
        m.account_id,
        ml.street_address,
        ml.city,
        ml.state,
        ml.zip_code,
        ml.country,
        ml.location_name,
        mfg.name,
        w.name,
        w.street_address,
        w.city,
        w.state,
        w.zip_code,
        p_current.project_name,
        p_current.account_id,
        p_current.street_address,
        p_current.city,
        p_current.state,
        p_current.zip_code,
        p_assigned.project_name,
        p_assigned.account_id";

    $countSql = 'SELECT COUNT(DISTINCT ip.id) AS total' . $baseFrom . $whereClause;
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        throw new Exception('Failed to prepare count query');
    }
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = (int)($countResult->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $statusCounts = [];
    $statusCountsSql = 'SELECT ip.status, COUNT(DISTINCT ip.id) AS total' . $baseFrom . $whereClause . ' GROUP BY ip.status';
    $statusStmt = $conn->prepare($statusCountsSql);
    if (!$statusStmt) {
        throw new Exception('Failed to prepare status counts query');
    }
    if ($types !== '') {
        $statusStmt->bind_param($types, ...$params);
    }
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();
    while ($statusRow = $statusResult->fetch_assoc()) {
        $statusKey = (string)($statusRow['status'] ?? '');
        if ($statusKey === '') {
            continue;
        }
        $statusCounts[$statusKey] = (int)($statusRow['total'] ?? 0);
    }
    $statusStmt->close();

    $sql = $baseSelect . $baseFrom . $whereClause . $groupBy . ' ORDER BY ip.id ASC LIMIT ? OFFSET ?';
    $queryParams = $params;
    $queryTypes = $types . 'ii';
    $queryParams[] = $pageSize;
    $queryParams[] = $offset;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare pallet query');
    }
    $stmt->bind_param($queryTypes, ...$queryParams);
    $stmt->execute();
    $result = $stmt->get_result();

    $pallets = [];
    while ($row = $result->fetch_assoc()) {
        $pallets[] = $row;
    }
    $stmt->close();

    $filterOptions = [];
    if ($page === 1) {
        $projectsSql = "SELECT DISTINCT COALESCE(p_current.project_name, p_assigned.project_name, 'Unassigned') AS project_name
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
            WHERE ip.status IN ($status_placeholders)";

        if (!$is_global_admin && $account_id_for_user) {
            $projectsSql .= ' AND (p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?)';
        }
        $projectsSql .= ' ORDER BY project_name';

        $pStmt = $conn->prepare($projectsSql);
        if ($pStmt) {
            if (!$is_global_admin && $account_id_for_user) {
                $pParams = array_merge($allowed_statuses, [$account_id_for_user, $account_id_for_user, $account_id_for_user]);
                $pTypes = str_repeat('s', count($allowed_statuses)) . 'iii';
            } else {
                $pParams = $allowed_statuses;
                $pTypes = str_repeat('s', count($allowed_statuses));
            }
            $pStmt->bind_param($pTypes, ...$pParams);
            $pStmt->execute();
            $pResult = $pStmt->get_result();
            $filterOptions['projects'] = [];
            while ($pRow = $pResult->fetch_assoc()) {
                $filterOptions['projects'][] = $pRow['project_name'];
            }
            $pStmt->close();
        }

        $wattagesSql = "SELECT DISTINCT ip.wattage
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
            WHERE ip.status IN ($status_placeholders)";

        if (!$is_global_admin && $account_id_for_user) {
            $wattagesSql .= ' AND (p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?)';
        }
        $wattagesSql .= ' ORDER BY ip.wattage';

        $wStmt = $conn->prepare($wattagesSql);
        if ($wStmt) {
            if (!$is_global_admin && $account_id_for_user) {
                $wParams = array_merge($allowed_statuses, [$account_id_for_user, $account_id_for_user, $account_id_for_user]);
                $wTypes = str_repeat('s', count($allowed_statuses)) . 'iii';
            } else {
                $wParams = $allowed_statuses;
                $wTypes = str_repeat('s', count($allowed_statuses));
            }
            $wStmt->bind_param($wTypes, ...$wParams);
            $wStmt->execute();
            $wResult = $wStmt->get_result();
            $filterOptions['wattages'] = [];
            while ($wRow = $wResult->fetch_assoc()) {
                $filterOptions['wattages'][] = $wRow['wattage'];
            }
            $wStmt->close();
        }

        $filterOptions['statuses'] = $allowed_statuses;
    }

    echo json_encode([
        'success' => true,
        'pallets' => $pallets,
        'totalCount' => $totalCount,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalPages' => (int)ceil($totalCount / max(1, $pageSize)),
        'filterOptions' => $filterOptions,
        'statusCounts' => $statusCounts
    ]);
} catch (Exception $e) {
    error_log('Error in get_shipment_pallets.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();
