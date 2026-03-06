<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------- AUTH & INPUT --------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}
$user_id  = $_SESSION['user_id'];
$role     = $_SESSION['role'] ?? 'user';

$project_id           = isset($_GET['project_id'])    ? intval($_GET['project_id'])    : null;
$origin_batch_id      = isset($_GET['origin_batch_id']) ? intval($_GET['origin_batch_id']) : null;
$highlight_delivery_id= isset($_GET['delivery_id'])   ? intval($_GET['delivery_id'])   : null;
if (!$project_id && !$origin_batch_id) die("Project ID or Origin Batch ID is missing.");

// ---------- DB ------------------------------------------------------------
require_once '../config.php';
require_once 'milestone_helpers.php';
require_once 'document_helpers.php';
$conn = getDBConnection();
if (!$conn) die("Connection failed");

$page_title_info  = "Delivery Tracker";
// (Use shared breadcrumbs in template)
$source_vendor_name_for_batch = null;
$batch_account_id = null;
$carrier_scope_account_id = null;
$is_global_admin = ($role === 'global_admin');
$is_account_admin = in_array($role, ['admin', 'customer_admin'], true);
$can_manage_deliveries = in_array($role, ['admin', 'customer_admin', 'global_admin'], true);
$account_id_for_admin = null;

if ($is_account_admin) {
    $stmtAdminAcc = $conn->prepare("
        SELECT account_id
        FROM customer_account_users
        WHERE user_id = ?
          AND role IN ('admin', 'customer_admin')
        LIMIT 1
    ");
    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $user_id);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($acctID);
        if ($stmtAdminAcc->fetch()) {
            $account_id_for_admin = (int)$acctID;
        }
        $stmtAdminAcc->close();
    }
}

function summarizeGroupedDate(array $dates): string
{
    $clean = array_values(array_unique(array_filter($dates)));
    if (empty($clean)) {
        return '—';
    }
    sort($clean);
    $first = $clean[0];
    $last = $clean[count($clean) - 1];
    if ($first === $last) {
        return date('m/d/Y', strtotime($first));
    }
    return date('m/d/Y', strtotime($first)) . ' - ' . date('m/d/Y', strtotime($last));
}

function getAuthorizedDeliveryIds(
    mysqli $conn,
    array $selected_ids,
    ?int $project_id,
    ?string $source_vendor_name_for_batch,
    bool $is_global_admin,
    ?int $account_id_for_admin
): array {
    $selected_ids = array_values(array_unique(array_filter(array_map('intval', $selected_ids), static fn($id) => $id > 0)));
    if (empty($selected_ids)) {
        return [];
    }
    if (!$is_global_admin && $account_id_for_admin === null) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
    $sql = "SELECT d.id FROM deliveries d LEFT JOIN projects p ON d.project_id = p.id WHERE d.id IN ($placeholders)";
    $types = str_repeat('i', count($selected_ids));
    $params = $selected_ids;

    if ($project_id) {
        $sql .= " AND d.project_id = ?";
        $types .= "i";
        $params[] = $project_id;
        if (!$is_global_admin && $account_id_for_admin !== null) {
            $sql .= " AND p.account_id = ?";
            $types .= "i";
            $params[] = $account_id_for_admin;
        }
    } else {
        $sql .= " AND d.project_id IS NULL AND d.supplier = ?";
        $types .= "s";
        $params[] = (string)$source_vendor_name_for_batch;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $authorized = [];
    while ($row = $res->fetch_assoc()) {
        $authorized[] = (int)$row['id'];
    }
    $stmt->close();

    return $authorized;
}

function returnPalletsToOrigin(mysqli $conn, array $delivery_ids): array
{
    if (empty($delivery_ids)) {
        return ['success' => false, 'message' => 'No delivery IDs provided', 'returned_count' => 0];
    }

    $returned_pallets = 0;
    try {
        $placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));
        $types = str_repeat('i', count($delivery_ids));

        $stmt = $conn->prepare("
            SELECT dp.inventory_pallet_id, d.origin_type, d.origin_id
            FROM delivery_pallets dp
            JOIN deliveries d ON dp.delivery_id = d.id
            WHERE dp.delivery_id IN ($placeholders)
        ");
        if (!$stmt) {
            throw new Exception("Failed to prepare origin query: " . $conn->error);
        }
        $stmt->bind_param($types, ...$delivery_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        $pallets_by_origin = [];
        while ($row = $result->fetch_assoc()) {
            $origin_key = ($row['origin_type'] ?? 'manufacturer') . '_' . ($row['origin_id'] ?? 'null');
            if (!isset($pallets_by_origin[$origin_key])) {
                $pallets_by_origin[$origin_key] = [
                    'type' => $row['origin_type'] ?? 'manufacturer',
                    'id' => $row['origin_id'],
                    'pallets' => []
                ];
            }
            $pallets_by_origin[$origin_key]['pallets'][] = (int)$row['inventory_pallet_id'];
        }

        foreach ($pallets_by_origin as $origin_data) {
            $pallet_ids = array_values(array_unique(array_filter($origin_data['pallets'])));
            if (empty($pallet_ids)) {
                continue;
            }

            $status = 'At Manufacturer';
            $warehouse_id = null;
            $project_ref_id = null;
            if ($origin_data['type'] === 'warehouse') {
                $status = 'In Warehouse';
                $warehouse_id = $origin_data['id'];
            } elseif ($origin_data['type'] === 'project') {
                $status = 'Delivered to Project';
                $project_ref_id = $origin_data['id'];
            }

            $pallet_placeholders = implode(',', array_fill(0, count($pallet_ids), '?'));
            $update_sql = "UPDATE inventory_pallets SET status = ?";
            $update_params = [$status];
            $update_types = 's';

            if ($warehouse_id) {
                $update_sql .= ", current_warehouse_id = ?";
                $update_types .= 'i';
                $update_params[] = (int)$warehouse_id;
            } else {
                $update_sql .= ", current_warehouse_id = NULL";
            }

            if ($project_ref_id) {
                $update_sql .= ", current_project_id = ?";
                $update_types .= 'i';
                $update_params[] = (int)$project_ref_id;
            } else {
                $update_sql .= ", current_project_id = NULL";
            }

            $update_sql .= " WHERE id IN ($pallet_placeholders)";
            $update_types .= str_repeat('i', count($pallet_ids));
            $update_params = array_merge($update_params, $pallet_ids);

            $stmt_update = $conn->prepare($update_sql);
            if (!$stmt_update) {
                continue;
            }
            $stmt_update->bind_param($update_types, ...$update_params);
            if ($stmt_update->execute()) {
                $returned_pallets += (int)$stmt_update->affected_rows;
            }
            $stmt_update->close();
        }

        return [
            'success' => true,
            'message' => "Successfully returned {$returned_pallets} pallet(s) to origin.",
            'returned_count' => $returned_pallets
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage(), 'returned_count' => 0];
    }
}

/* -------------------- context by project_id -----------------------------*/
if ($project_id) {
    if ($is_global_admin) {
        $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
    } else {
        $stmt = $conn->prepare("
            SELECT p.* FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE p.id = ? AND cau.user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $project_id, $user_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) die("You do not have access to this project.");
    $project = $res->fetch_assoc();
    $stmt->close();

    $page_title_info = htmlspecialchars($project['project_name']);
    $carrier_scope_account_id = isset($project['account_id']) ? (int)$project['account_id'] : null;
    // For project context, template will render: Dashboard » Project Overview » Delivery Tracker

/* -------------------- context by origin_batch_id ------------------------*/
} elseif ($origin_batch_id) {
    $stmt = $conn->prepare("SELECT vendor_name, account_id FROM modules WHERE id = ?");
    $stmt->bind_param("i", $origin_batch_id);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$batch) die("Batch not found.");

    $source_vendor_name_for_batch = $batch['vendor_name'];
    $batch_account_id             = $batch['account_id'];
    $carrier_scope_account_id     = (int)$batch_account_id;

    if (!$is_global_admin) {
        $stmt = $conn->prepare("SELECT 1 FROM customer_account_users WHERE user_id=? AND account_id=? LIMIT 1");
        $stmt->bind_param("ii", $user_id, $batch_account_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) die("Forbidden.");
        $stmt->close();
    }

    $page_title_info = "Unassigned Deliveries from Batch: "
                     . htmlspecialchars($source_vendor_name_for_batch)
                     . " (ID: $origin_batch_id)";
    // For batch context, template will render: Dashboard » Modules » Batch Details » Unassigned Deliveries from Batch
}

/* ---------- FILTER LOGIC -------------------------------------------------*/
$baseWhere = [];  $paramTypes = '';  $params = [];
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";

/* context filters */
$selectClause = "SELECT d.*, ss.id as appointment_id,
       carr.name AS carrier_name,
       (SELECT COUNT(*) FROM project_documents pd
        WHERE pd.delivery_id = d.id
        AND pd.document_type = 'pods'
        AND (pd.document_sub_type = 'Project POD' OR pd.document_sub_type = 'Warehouse POD')
       ) AS has_pod_in_documents
FROM deliveries d";
$joinClause   = " LEFT JOIN site_scheduling ss ON d.id = ss.delivery_id LEFT JOIN carriers carr ON carr.id = d.carrier_id";
if ($project_id) {
    $baseWhere[] = "d.project_id = ?";
    $paramTypes .= "i";
    $params[]    = $project_id;
} else {
    $joinClause  .= " LEFT JOIN projects p ON d.project_id = p.id";
    $baseWhere[]  = "d.supplier = ?";
    $paramTypes  .= "s";
    $params[]     = $source_vendor_name_for_batch;
    $baseWhere[]  = "d.project_id IS NULL";
}

/* NEW: Advanced filters from query params */
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$wattage_filter = $_GET['wattage'] ?? '';
$status_filter = $_GET['status'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$search_query = $_GET['search'] ?? '';

// Date range filter
if ($start_date && $end_date) {
    $baseWhere[] = "DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    array_push($params, $start_date, $end_date);
} elseif ($start_date) {
    $baseWhere[] = "DATE($filterColumn) >= ?";
    $paramTypes .= "s";
    $params[] = $start_date;
} elseif ($end_date) {
    $baseWhere[] = "DATE($filterColumn) <= ?";
    $paramTypes .= "s";
    $params[] = $end_date;
}

// Wattage filter
if ($wattage_filter !== '') {
    $baseWhere[] = "d.wattage = ?";
    $paramTypes .= "s";
    $params[] = $wattage_filter;
}

// Status filter
if ($status_filter !== '') {
    $baseWhere[] = "d.status_of_delivery = ?";
    $paramTypes .= "s";
    $params[] = $status_filter;
}

// Supplier filter
if ($supplier_filter !== '') {
    $baseWhere[] = "d.supplier = ?";
    $paramTypes .= "s";
    $params[] = $supplier_filter;
}

// Search filter
if ($search_query !== '') {
    $baseWhere[] = "(d.bol_number LIKE ? OR d.supplier LIKE ? OR d.status_of_delivery LIKE ?)";
    $paramTypes .= "sss";
    $search_param = "%$search_query%";
    array_push($params, $search_param, $search_param, $search_param);
}
$whereClause="WHERE 1=1".($baseWhere?" AND ".implode(" AND ",$baseWhere):"");

if (!isset($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

$redirect_params = [];
if ($project_id) {
    $redirect_params['project_id'] = $project_id;
} elseif ($origin_batch_id) {
    $redirect_params['origin_batch_id'] = $origin_batch_id;
}
if ($highlight_delivery_id) {
    $redirect_params['delivery_id'] = $highlight_delivery_id;
}
if ($start_date !== '') {
    $redirect_params['start_date'] = $start_date;
}
if ($end_date !== '') {
    $redirect_params['end_date'] = $end_date;
}
if ($wattage_filter !== '') {
    $redirect_params['wattage'] = $wattage_filter;
}
if ($status_filter !== '') {
    $redirect_params['status'] = $status_filter;
}
if ($supplier_filter !== '') {
    $redirect_params['supplier'] = $supplier_filter;
}
if ($search_query !== '') {
    $redirect_params['search'] = $search_query;
}
$redirect_url = 'view_project.php' . (empty($redirect_params) ? '' : ('?' . http_build_query($redirect_params)));

if ($can_manage_deliveries && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'upload_pod') {
        header('Content-Type: application/json');

        try {
            if (
                !isset($_POST['csrf_token']) ||
                !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])
            ) {
                throw new Exception('Invalid upload request.');
            }

            $delivery_id = isset($_POST['delivery_id']) ? (int)$_POST['delivery_id'] : 0;
            if ($delivery_id <= 0) {
                throw new Exception('Invalid delivery ID.');
            }

            $authorized_ids = getAuthorizedDeliveryIds(
                $conn,
                [$delivery_id],
                $project_id,
                $source_vendor_name_for_batch,
                $is_global_admin,
                $account_id_for_admin
            );
            if (empty($authorized_ids)) {
                throw new Exception('You are not authorized to upload POD for this delivery.');
            }

            if (!isset($_FILES['pod_file']) || $_FILES['pod_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please select a POD file to upload.');
            }

            $stmtDeliveryMeta = $conn->prepare("
                SELECT project_id, warehouse_id, status_of_delivery
                FROM deliveries
                WHERE id = ?
                LIMIT 1
            ");
            if (!$stmtDeliveryMeta) {
                throw new Exception('Failed to fetch delivery context.');
            }
            $stmtDeliveryMeta->bind_param("i", $delivery_id);
            $stmtDeliveryMeta->execute();
            $stmtDeliveryMeta->bind_result($pod_project_id, $pod_warehouse_id, $pod_delivery_status);
            if (!$stmtDeliveryMeta->fetch()) {
                $stmtDeliveryMeta->close();
                throw new Exception('Delivery not found.');
            }
            $stmtDeliveryMeta->close();

            $processed_file = processDocumentUpload($_FILES['pod_file'], 'pods');
            $pod_sub_type = determineDocumentSubType('pods', [
                'delivery_status' => $pod_delivery_status,
                'warehouse_id' => $pod_warehouse_id
            ]);

            $doc_result = saveDocumentToProjectDocuments($conn, [
                'project_id' => $pod_project_id ?: null,
                'document_type' => 'pods',
                'document_sub_type' => $pod_sub_type,
                'delivery_id' => $delivery_id,
                'warehouse_id' => $pod_warehouse_id ?: null,
                'original_name' => $processed_file['original_name'],
                'file_size' => $processed_file['size'],
                'mime_type' => $processed_file['mime_type'],
                'uploaded_by' => $user_id,
                'tmp_name' => $processed_file['tmp_name'],
                'entity_context' => "POD uploaded from view_project for delivery ID: {$delivery_id}"
            ]);

            $stmtUpdatePodPath = $conn->prepare("UPDATE deliveries SET proof_of_delivery = ? WHERE id = ?");
            if ($stmtUpdatePodPath) {
                $stmtUpdatePodPath->bind_param("si", $doc_result['file_path'], $delivery_id);
                $stmtUpdatePodPath->execute();
                $stmtUpdatePodPath->close();
            }

            echo json_encode(['success' => true, 'message' => 'POD uploaded successfully.']);
            exit();
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit();
        }
    }

    if (isset($_POST['bulk_edit_submit'])) {
        $selected_ids = $_POST['selected_deliveries'] ?? [];
        $selected_ids = getAuthorizedDeliveryIds(
            $conn,
            $selected_ids,
            $project_id,
            $source_vendor_name_for_batch,
            $is_global_admin,
            $account_id_for_admin
        );

        if (empty($selected_ids)) {
            $_SESSION['messages'][] = "<p class='error-message'>Bulk edit failed: no valid deliveries were selected.</p>";
            header("Location: $redirect_url");
            exit();
        }

        $updates = [];
        $types = '';
        $values = [];

        $editable_fields = [
            'anticipated_delivery_date' => 's',
            'warehouse_arrival_date' => 's',
            'actual_delivery_date' => 's',
            'left_warehouse_date' => 's',
            'carrier_id' => 'i',
            'miles' => 'd',
            'freight_cost' => 'd',
            'accessorial_costs_paid' => 'd',
            'accessorial_costs' => 'd',
            'customer_cost' => 'd'
        ];

        foreach ($editable_fields as $field => $bind_type) {
            if (!isset($_POST[$field]) || $_POST[$field] === '') {
                continue;
            }

            $updates[] = "$field = ?";
            $types .= $bind_type;
            if ($bind_type === 'd') {
                $values[] = (float)$_POST[$field];
            } elseif ($bind_type === 'i') {
                $values[] = (int)$_POST[$field];
            } else {
                $values[] = trim((string)$_POST[$field]);
            }
        }

        if (empty($updates)) {
            $_SESSION['messages'][] = "<p>No fields were provided for bulk edit.</p>";
            header("Location: $redirect_url");
            exit();
        }

        $conn->begin_transaction();
        try {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $sql = "UPDATE deliveries SET " . implode(', ', $updates) . " WHERE id IN ($placeholders)";
            $stmt = $conn->prepare($sql);
            $all_types = $types . str_repeat('i', count($selected_ids));
            $all_values = array_merge($values, $selected_ids);
            $stmt->bind_param($all_types, ...$all_values);
            if (!$stmt->execute()) {
                throw new Exception("Bulk update failed: " . $stmt->error);
            }
            $stmt->close();

            $conn->commit();
            $_SESSION['messages'][] = "<p>Bulk update successful.</p>";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['messages'][] = "<p class='error-message'>Error during bulk update: " . htmlspecialchars($e->getMessage()) . "</p>";
        }

        header("Location: $redirect_url");
        exit();
    }

    if (isset($_POST['receive_to_project_submit'])) {
        $selected_ids = $_POST['selected_deliveries'] ?? [];
        $selected_ids = getAuthorizedDeliveryIds(
            $conn,
            $selected_ids,
            $project_id,
            $source_vendor_name_for_batch,
            $is_global_admin,
            $account_id_for_admin
        );
        $actual_delivery_date = trim((string)($_POST['actual_delivery_date'] ?? date('Y-m-d')));

        if (empty($selected_ids)) {
            $_SESSION['messages'][] = "<p class='error-message'>Receive to project failed: no valid deliveries were selected.</p>";
            header("Location: $redirect_url");
            exit();
        }

        $conn->begin_transaction();
        try {
            $pod_path = null;
            if (isset($_FILES['proof_of_delivery']) && $_FILES['proof_of_delivery']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/pods/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_ext = strtolower(pathinfo($_FILES['proof_of_delivery']['name'], PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
                if (in_array($file_ext, $allowed_exts, true)) {
                    $filename = 'bulk_pod_' . time() . '_' . mt_rand(1000, 9999) . '.' . $file_ext;
                    $pod_path = $upload_dir . $filename;
                    if (!move_uploaded_file($_FILES['proof_of_delivery']['tmp_name'], $pod_path)) {
                        $pod_path = null;
                    }
                }
            }

            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $update_sql = "UPDATE deliveries SET status_of_delivery = 'Delivered to Project', actual_delivery_date = ?";
            $update_types = 's';
            $update_values = [$actual_delivery_date];
            if ($pod_path) {
                $update_sql .= ", proof_of_delivery = ?";
                $update_types .= 's';
                $update_values[] = $pod_path;
            }
            $update_sql .= " WHERE id IN ($placeholders)";
            $update_types .= str_repeat('i', count($selected_ids));
            $update_values = array_merge($update_values, $selected_ids);

            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param($update_types, ...$update_values);
            if (!$stmt->execute()) {
                throw new Exception("Delivery update failed: " . $stmt->error);
            }
            $stmt->close();

            $dp_placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $stmtPalletIds = $conn->prepare("
                SELECT DISTINCT inventory_pallet_id
                FROM delivery_pallets
                WHERE delivery_id IN ($dp_placeholders)
            ");
            $stmtPalletIds->bind_param(str_repeat('i', count($selected_ids)), ...$selected_ids);
            $stmtPalletIds->execute();
            $resPalletIds = $stmtPalletIds->get_result();
            $pallet_ids = [];
            while ($row = $resPalletIds->fetch_assoc()) {
                $pallet_ids[] = (int)$row['inventory_pallet_id'];
            }
            $stmtPalletIds->close();

            if (!empty($pallet_ids)) {
                $pallet_placeholders = implode(',', array_fill(0, count($pallet_ids), '?'));
                $stmtPalletUpdate = $conn->prepare("
                    UPDATE inventory_pallets
                    SET status = 'Delivered to Project'
                    WHERE id IN ($pallet_placeholders)
                ");
                $stmtPalletUpdate->bind_param(str_repeat('i', count($pallet_ids)), ...$pallet_ids);
                $stmtPalletUpdate->execute();
                $stmtPalletUpdate->close();
            }

            $arrival_time = $actual_delivery_date . ' 08:00:00';
            $departure_time = $actual_delivery_date . ' 16:00:00';
            $sched_placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $stmtSched = $conn->prepare("
                UPDATE site_scheduling
                SET arrival_time = ?, departure_time = ?, is_closed = 1
                WHERE delivery_id IN ($sched_placeholders)
            ");
            $sched_types = 'ss' . str_repeat('i', count($selected_ids));
            $sched_values = array_merge([$arrival_time, $departure_time], $selected_ids);
            $stmtSched->bind_param($sched_types, ...$sched_values);
            $stmtSched->execute();
            $stmtSched->close();

            if (function_exists('trigger_delivery_milestones_for_status')) {
                foreach ($selected_ids as $delivery_id) {
                    trigger_delivery_milestones_for_status(
                        $delivery_id,
                        'Delivered to Project',
                        $conn,
                        $user_id,
                        null,
                        $actual_delivery_date
                    );
                }
            }

            $conn->commit();
            $_SESSION['messages'][] = "<p>Successfully marked selected deliveries as Delivered to Project.</p>";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['messages'][] = "<p class='error-message'>Receive to project failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        }

        header("Location: $redirect_url");
        exit();
    }

    if (isset($_POST['delete_selected'])) {
        $selected_ids = $_POST['selected_deliveries'] ?? [];
        $selected_ids = getAuthorizedDeliveryIds(
            $conn,
            $selected_ids,
            $project_id,
            $source_vendor_name_for_batch,
            $is_global_admin,
            $account_id_for_admin
        );

        if (empty($selected_ids)) {
            $_SESSION['messages'][] = "<p class='error-message'>Bulk delete failed: no valid deliveries were selected.</p>";
            header("Location: $redirect_url");
            exit();
        }

        $conn->begin_transaction();
        try {
            returnPalletsToOrigin($conn, $selected_ids);

            $tracking_pairs = [];
            $d_placeholders_for_pairs = implode(',', array_fill(0, count($selected_ids), '?'));
            $stmtTrackingPairs = $conn->prepare("
                SELECT DISTINCT container_number, project_id
                FROM deliveries
                WHERE id IN ($d_placeholders_for_pairs)
                  AND project_id IS NOT NULL
                  AND container_number IS NOT NULL
                  AND TRIM(container_number) <> ''
            ");
            if ($stmtTrackingPairs) {
                $stmtTrackingPairs->bind_param(str_repeat('i', count($selected_ids)), ...$selected_ids);
                $stmtTrackingPairs->execute();
                $resTrackingPairs = $stmtTrackingPairs->get_result();
                while ($pair = $resTrackingPairs->fetch_assoc()) {
                    $tracking_pairs[] = [
                        'container_number' => trim((string)$pair['container_number']),
                        'project_id' => (int)$pair['project_id']
                    ];
                }
                $stmtTrackingPairs->close();
            }

            $dp_placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $stmtLinks = $conn->prepare("DELETE FROM delivery_pallets WHERE delivery_id IN ($dp_placeholders)");
            $stmtLinks->bind_param(str_repeat('i', count($selected_ids)), ...$selected_ids);
            $stmtLinks->execute();
            $stmtLinks->close();

            $d_placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $stmtDelete = $conn->prepare("DELETE FROM deliveries WHERE id IN ($d_placeholders)");
            $stmtDelete->bind_param(str_repeat('i', count($selected_ids)), ...$selected_ids);
            if (!$stmtDelete->execute()) {
                throw new Exception("Delete failed: " . $stmtDelete->error);
            }
            $deleted = (int)$stmtDelete->affected_rows;
            $stmtDelete->close();

            if (!empty($tracking_pairs)) {
                $has_waypoints_table = false;
                $has_positions_table = false;
                $tableCheckWaypoints = $conn->query("SHOW TABLES LIKE 'container_tracking_waypoints'");
                if ($tableCheckWaypoints) {
                    $has_waypoints_table = $tableCheckWaypoints->num_rows > 0;
                    $tableCheckWaypoints->close();
                }
                $tableCheckPositions = $conn->query("SHOW TABLES LIKE 'container_tracking_positions'");
                if ($tableCheckPositions) {
                    $has_positions_table = $tableCheckPositions->num_rows > 0;
                    $tableCheckPositions->close();
                }

                $stmtRemainingDeliveries = $conn->prepare("
                    SELECT COUNT(*) AS cnt
                    FROM deliveries
                    WHERE project_id = ? AND container_number = ?
                ");
                $stmtDeleteWaypoints = $has_waypoints_table
                    ? $conn->prepare("DELETE FROM container_tracking_waypoints WHERE project_id = ? AND container_number = ?")
                    : null;
                $stmtDeletePositions = $has_positions_table
                    ? $conn->prepare("DELETE FROM container_tracking_positions WHERE project_id = ? AND container_number = ?")
                    : null;

                if ($stmtRemainingDeliveries) {
                    foreach ($tracking_pairs as $pair) {
                        $project_id_pair = (int)$pair['project_id'];
                        $container_number_pair = (string)$pair['container_number'];
                        if ($project_id_pair <= 0 || $container_number_pair === '') {
                            continue;
                        }

                        $remaining_count = 0;
                        $stmtRemainingDeliveries->bind_param("is", $project_id_pair, $container_number_pair);
                        $stmtRemainingDeliveries->execute();
                        $stmtRemainingDeliveries->bind_result($remaining_count_raw);
                        if ($stmtRemainingDeliveries->fetch()) {
                            $remaining_count = (int)$remaining_count_raw;
                        }
                        $stmtRemainingDeliveries->free_result();

                        if ($remaining_count === 0) {
                            if ($stmtDeleteWaypoints) {
                                $stmtDeleteWaypoints->bind_param("is", $project_id_pair, $container_number_pair);
                                $stmtDeleteWaypoints->execute();
                            }
                            if ($stmtDeletePositions) {
                                $stmtDeletePositions->bind_param("is", $project_id_pair, $container_number_pair);
                                $stmtDeletePositions->execute();
                            }
                        }
                    }
                    $stmtRemainingDeliveries->close();
                }
                if ($stmtDeleteWaypoints) {
                    $stmtDeleteWaypoints->close();
                }
                if ($stmtDeletePositions) {
                    $stmtDeletePositions->close();
                }
            }

            $conn->commit();
            $_SESSION['messages'][] = "<p>Successfully deleted {$deleted} delivery record(s).</p>";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['messages'][] = "<p class='error-message'>Bulk delete failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        }

        header("Location: $redirect_url");
        exit();
    }
}

/* ---------- CSV EXPORT ---------------------------------------------------*/
if(isset($_GET['export']) && $_GET['export']==1){
    header('Content-Type:text/csv; charset=utf-8');
    header('Content-Disposition:attachment; filename=deliveries.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['Supplier','Wattage','Status of Delivery','Quantity','BOL Number',
                   'Anticipated Delivery Date','Actual Delivery Date',
                   'Associated Pallets','Scheduled','Proof of Delivery']);
    $sql="$selectClause $joinClause $whereClause ORDER BY $filterColumn DESC";
    $stmt=$conn->prepare($sql);
    if($paramTypes) $stmt->bind_param($paramTypes,...$params);
    $stmt->execute();
    $r=$stmt->get_result();
    while($row=$r->fetch_assoc()){
        fputcsv($out,[
            $row['supplier'],$row['wattage'],$row['status_of_delivery'],$row['quantity'],
            $row['bol_number'],
            $row['anticipated_delivery_date']?date('m-d-Y',strtotime($row['anticipated_delivery_date'])):'',
            $row['actual_delivery_date']?date('m-d-Y',strtotime($row['actual_delivery_date'])):'',
            $row['associated_pallets'],
            $row['scheduled'] ? 'Yes' : 'No',
            $row['proof_of_delivery']?'Yes':'No'
        ]);
    }
    fclose($out); $stmt->close(); $conn->close(); exit();
}

/* ---------- FETCH DELIVERIES --------------------------------------------*/
$sql="$selectClause $joinClause $whereClause ORDER BY $filterColumn DESC";
$stmt=$conn->prepare($sql);
if($paramTypes) $stmt->bind_param($paramTypes,...$params);
$stmt->execute();
$deliveries=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate stats for each actual status
$total_deliveries = count($deliveries);
$status_counts = [
    'Pending' => 0,
    'On Water' => 0,
    'Customs Hold' => 0,
    'Cleared Customs' => 0,
    'In Transit to Warehouse' => 0,
    'Delivered to Warehouse' => 0,
    'In Transit to Project' => 0,
    'Delivered to Project' => 0,
    'Canceled' => 0
];

foreach ($deliveries as $delivery) {
    $status = $delivery['status_of_delivery'];
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    }
}

// Filter out zero counts
$active_status_counts = array_filter($status_counts, fn($count) => $count > 0);

// Group deliveries by BOL so mixed wattages appear on a single line
$grouped_deliveries = [];
$bol_groups = [];
foreach ($deliveries as $delivery) {
    $bol_number = trim((string)($delivery['bol_number'] ?? ''));
    $group_key = ($bol_number !== '') ? ('bol:' . $bol_number) : ('single:' . (int)$delivery['id']);
    if (!isset($bol_groups[$group_key])) {
        $bol_groups[$group_key] = [];
    }
    $bol_groups[$group_key][] = $delivery;
}

foreach ($bol_groups as $group_key => $group_rows) {
    $master = $group_rows[0];
    $group_ids = array_map('intval', array_column($group_rows, 'id'));
    $wattage_values = [];
    $supplier_values = [];
    $status_values = [];

    foreach ($group_rows as $row) {
        $wattage_label = trim((string)($row['wattage'] ?? ''));
        if ($wattage_label !== '') {
            $wattage_values[] = $wattage_label;
        }

        $supplier_values[] = (string)($row['supplier'] ?? '');
        $status_values[] = (string)($row['status_of_delivery'] ?? '');
    }

    $supplier_values = array_values(array_filter(array_unique($supplier_values)));
    $status_values = array_values(array_filter(array_unique($status_values)));
    $unique_wattages_for_group = array_values(array_unique($wattage_values));

    $master['is_grouped'] = (count($group_rows) > 1);
    $master['group_key'] = $group_key;
    $master['group_count'] = count($group_rows);
    $master['group_delivery_ids'] = $group_ids;
    $master['grouped_deliveries'] = $group_rows;
    $master['total_quantity'] = array_sum(array_map(static fn($r) => (int)($r['quantity'] ?? 0), $group_rows));
    $master['is_mixed_wattage'] = (count($unique_wattages_for_group) > 1);
    $master['display_wattage'] = '—';
    if ($master['is_mixed_wattage']) {
        $master['display_wattage'] = 'Mixed';
    } elseif (!empty($unique_wattages_for_group)) {
        $master['display_wattage'] = $unique_wattages_for_group[0] . 'W';
    }
    $master['supplier_display'] = (count($supplier_values) <= 1) ? ($supplier_values[0] ?? '—') : ('Multiple (' . count($supplier_values) . ')');
    $master['supplier_list'] = $supplier_values;
    $master['status_values'] = $status_values;
    $master['status_of_delivery'] = (count($status_values) <= 1) ? ($status_values[0] ?? '—') : 'Mixed Status';
    $master['anticipated_display'] = summarizeGroupedDate(array_column($group_rows, 'anticipated_delivery_date'));
    $master['actual_display'] = summarizeGroupedDate(array_column($group_rows, 'actual_delivery_date'));
    $master['contains_highlight'] = $highlight_delivery_id ? in_array($highlight_delivery_id, $group_ids, true) : false;

    $grouped_deliveries[] = $master;
}

// Get unique wattages and suppliers for filters
$unique_wattages = array_values(array_filter(array_unique(array_column($deliveries, 'wattage')), static fn($w) => $w !== null && $w !== ''));
sort($unique_wattages);
$unique_suppliers = array_values(array_filter(array_unique(array_column($deliveries, 'supplier')), static fn($s) => $s !== null && $s !== ''));
sort($unique_suppliers);

// Fetch active carriers for bulk edit dropdown
$all_carriers = [];
if ($can_manage_deliveries) {
    $carrier_account_clause = $is_global_admin ? '' : ' AND (account_id IS NULL OR account_id = ?)';
    $stmtC = $conn->prepare("SELECT id, name, short_name, carrier_type, is_solterra_managed FROM carriers WHERE is_active = 1{$carrier_account_clause} ORDER BY is_solterra_managed DESC, name ASC");
    if ($stmtC) {
        if (!$is_global_admin) {
            $carrierScopeId = $account_id_for_admin ?: $carrier_scope_account_id;
            if ($carrierScopeId === null) {
                $stmtC->close();
                $stmtC = null;
            } else {
                $stmtC->bind_param("i", $carrierScopeId);
            }
        }
    }
    if ($stmtC) {
        $stmtC->execute();
        $resultC = $stmtC->get_result();
        while ($c = $resultC->fetch_assoc()) {
            $all_carriers[] = $c;
        }
        $stmtC->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title_info; ?> - Delivery Tracker</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }


        .column-hidden{display:none !important;}

        /* Header Section */
        .delivery-tracker-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .delivery-tracker-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        .header-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            background: rgba(72, 140, 154, 0.08);
            padding: 16px 20px;
            border-radius: 16px;
            min-width: 100px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.2);
        }

        /* Different stat item colors based on status */
        .stat-item-total {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(72, 140, 154, 0.2) 100%);
        }

        .stat-item-pending {
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.15) 0%, rgba(251, 191, 36, 0.2) 100%);
        }

        .stat-item-pending .stat-number {
            color: #d97706;
        }

        .stat-item-transit {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(59, 130, 246, 0.2) 100%);
        }

        .stat-item-transit .stat-number {
            color: #2563eb;
        }

        .stat-item-delivered {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15) 0%, rgba(34, 197, 94, 0.2) 100%);
        }

        .stat-item-delivered .stat-number {
            color: #16a34a;
        }

        .stat-item-canceled {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(239, 68, 68, 0.2) 100%);
        }

        .stat-item-canceled .stat-number {
            color: #dc2626;
        }

        .stat-number {
            font-size: 2em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.85em;
            color: #6c757d;
            margin: 4px 0 0 0;
            font-weight: 500;
        }

        /* Filter Section */
        .filter-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .filter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .filter-title {
            font-size: 1.4em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .filter-title i {
            color: #488C9A;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-clear, .btn-apply, .btn-export, .btn-calendar, .btn-columns {
            padding: 10px 18px;
            border-radius: 12px;
            font-size: 0.9em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
        }

        .btn-clear {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.15) 100%);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .btn-clear:hover {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(220, 38, 38, 0.2) 100%);
            transform: translateY(-1px);
        }

        .btn-apply {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.3);
        }

        .btn-apply:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }

        .btn-export {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }

        .btn-export:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }

        .btn-calendar {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }

        .btn-calendar:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }

        .btn-columns {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }

        .btn-columns:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            align-items: start;
        }

        .filter-group {
            position: relative;
            display: flex;
            flex-direction: column;
        }

        /* Make date range span two columns to prevent overlap */
        .filter-group:has(.date-range-group) {
            grid-column: span 2;
        }

        .filter-label {
            font-weight: 600;
            color: #293E4C;
            font-size: 0.95em;
            margin-bottom: 8px;
        }

        .filter-select, .filter-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 12px;
            background: white;
            font-size: 0.95em;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }

        .date-range-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* Table Container */
        .deliveries-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .table-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .table-header-main {
            display: flex;
            align-items: center;
            flex: 1 1 220px;
            min-width: 220px;
        }

        .table-title {
            font-size: 1.7em;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
        }

        .table-header-admin {
            flex: 1 1 300px;
            min-width: 260px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            background: rgba(255, 255, 255, 0.12);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.12);
        }

        .admin-tools-title {
            margin: 0;
            font-size: 0.9em;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.95);
            text-align: center;
        }

        .admin-tools-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .table-header-admin .bulk-btn {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.22);
        }

        .table-header-admin .bulk-btn:disabled {
            opacity: 0.55;
            box-shadow: none;
        }

        .table-header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex: 1 1 220px;
            min-width: 220px;
        }

        .btn-export-header, .btn-calendar-header, .btn-columns-header {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.85em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }

        .btn-export-header {
            background: rgba(255, 255, 255, 0.95);
            color: #16a34a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-export-header:hover {
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btn-calendar-header {
            background: rgba(255, 255, 255, 0.95);
            color: #7c3aed;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-calendar-header:hover {
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btn-columns-header {
            background: rgba(255, 255, 255, 0.95);
            color: #d97706;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-columns-header:hover {
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        table thead {
            background: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid rgba(72, 140, 154, 0.1);
            border: none;
            background: white;
        }

        table td {
            padding: 16px;
            border-bottom: 1px solid rgba(72, 140, 154, 0.08);
            vertical-align: middle;
            border: none;
        }

        table tbody tr {
            transition: background 0.2s ease, box-shadow 0.2s ease;
        }

        /* Avoid horizontal translate that can trigger scrollbar flicker */
        table tbody tr:hover {
            background: rgba(72, 140, 154, 0.05);
            box-shadow: inset 4px 0 0 rgba(72, 140, 154, 0.25);
        }

        /* Action Buttons - NEW UNIFIED STYLE */
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.85em;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }

        .action-btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.25);
        }

        .action-btn-primary:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.35);
            color: white;
        }

        .action-btn-success {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(34, 197, 94, 0.25);
        }

        .action-btn-success:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.35);
            color: white;
        }

        .action-btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.25);
        }

        .action-btn-warning:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);
            color: white;
        }

        .action-btn-outline {
            background: white;
            color: #488C9A;
            border: 2px solid #488C9A;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.15);
        }

        .action-btn-outline:hover {
            background: #488C9A;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.25);
        }

        .action-btn i {
            font-size: 0.9em;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 500;
        }

        .status-pending {
            background: rgba(251, 191, 36, 0.15);
            color: #d97706;
        }

        .status-transit {
            background: rgba(59, 130, 246, 0.15);
            color: #2563eb;
        }

        .status-delivered {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
        }

        .status-canceled {
            background: rgba(239, 68, 68, 0.15);
            color: #dc2626;
        }

        /* Highlighted Delivery */
        .highlighted-delivery {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%) !important;
            border-left: 4px solid #488C9A !important;
            animation: highlightPulse 2s ease-in-out;
        }

        @keyframes highlightPulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 rgba(72, 140, 154, 0);
            }
            50% {
                transform: scale(1.01);
                box-shadow: 0 0 20px rgba(72, 140, 154, 0.3);
            }
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            width: 90%;
            max-width: 600px;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 24px;
            position: relative;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 600;
            color: white;
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 24px;
            font-size: 28px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .modal-close:hover {
            transform: scale(1.1);
        }

        .modal-body {
            padding: 0;
            max-height: 400px;
            overflow-y: auto;
            position: relative;
        }

        .pallet-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .pallet-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f8f9fa;
        }

        .pallet-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #293E4C;
            border-bottom: 2px solid rgba(72, 140, 154, 0.2);
        }

        .pallet-table td {
            padding: 12px;
            border-bottom: 1px solid rgba(72, 140, 154, 0.1);
        }

        .pallet-table tr:hover {
            background: rgba(72, 140, 154, 0.05);
        }

        /* Column Chooser Dropdown */
        .column-chooser-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: white;
            border: 1px solid rgba(72, 140, 154, 0.2);
            border-radius: 12px;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            min-width: 250px;
            max-height: 400px;
            overflow-y: auto;
        }

        .column-chooser-header {
            padding: 16px;
            background: #f8f9fa;
            border-bottom: 1px solid rgba(72, 140, 154, 0.2);
            font-weight: 600;
            color: #293E4C;
        }

        .column-chooser-options {
            padding: 8px 0;
        }

        .column-option {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            cursor: pointer;
            transition: background-color 0.2s ease;
            color: #293E4C;
            font-size: 0.9em;
        }

        .column-option:hover {
            background-color: rgba(72, 140, 154, 0.08);
        }

        .column-option input[type=checkbox] {
            margin-right: 10px;
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
        }

        .column-chooser-footer {
            padding: 12px 16px;
            border-top: 1px solid rgba(72, 140, 154, 0.2);
            background: #f8f9fa;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 4em;
            color: #d1d5db;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 1.5em;
            color: #6c757d;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .empty-state p {
            color: #9ca3af;
            margin: 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }

            .header-stats {
                justify-content: space-between;
            }

            .stat-item {
                flex: 1;
                min-width: 80px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            /* Reset date range span on mobile */
            .filter-group:has(.date-range-group) {
                grid-column: span 1;
            }

            .date-range-group {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                width: 100%;
                flex-direction: column;
            }

            .filter-actions button {
                width: 100%;
                justify-content: center;
            }

            .table-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .table-header-main,
            .table-header-admin,
            .table-header-actions {
                width: 100%;
            }

            .table-header-main {
                justify-content: flex-start;
            }

            .table-header-admin {
                align-items: flex-start;
            }

            .admin-tools-title,
            .admin-tools-buttons {
                text-align: left;
                justify-content: flex-start;
            }

            .btn-export-header, .btn-calendar-header, .btn-columns-header {
                flex: 1;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .delivery-tracker-header {
                padding: 20px;
            }

            .header-info h1 {
                font-size: 1.8em;
            }

            .stat-number {
                font-size: 1.5em;
            }

            .filter-section {
                padding: 20px;
            }
        }

        .time-filter-header{display:flex;justify-content:space-between;align-items:center;margin-top:30px;margin-bottom:10px;flex-wrap:wrap;}
        .time-filters{display:flex;gap:10px;}
        .time-filters a{text-decoration:none;padding:6px 12px;background:#eee;border-radius:4px;color:#333;}
        .time-filters a.active{background:#488C9A;color:#fff;}
        .date-navigation{display:flex;align-items:center;gap:10px;margin:20px;}
        .nav-arrow{font-weight:bold;cursor:pointer;background:#eee;border:none;padding:5px 10px;border-radius:4px;}
        .nav-arrow:hover{background:#ccc;}
        .date-label{font-weight:bold;font-size:1.1em;}
        .right-filters{display:flex;flex-direction:column;gap:10px;align-items:flex-start;}
        .back-icon{display:inline-flex;align-items:center;text-decoration:none;margin:10px;color:#333;}
        .back-icon svg{width:24px;height:24px;margin-right:5px;}
        .breadcrumb{display:flex;margin-bottom:20px;margin-top:10px;margin-left:20px;}
        .breadcrumb a{color:#488C9A;text-decoration:none;}
        .breadcrumb .separator{margin:0 8px;color:#6c757d;}
        table{width:100%;border-collapse:collapse;margin-bottom:20px;}
        table,th,td{border:1px solid #ccc;}
        th,td{padding:10px;}
        tr:hover{background:#f1f1f1;}
        .table-responsive{width:100%;overflow-x:auto;}
        @media screen and (max-width:768px){.mobile-hide{display:none !important;}}
        .highlighted-delivery{background:linear-gradient(135deg,#fff3cd 0%,#ffeaa7 100%) !important;border:3px solid #488C9A !important;box-shadow:0 0 15px rgba(72,140,154,0.3) !important;animation:highlightPulse 2s ease-in-out;}
        @keyframes highlightPulse{0%,100%{transform:scale(1);box-shadow:0 0 15px rgba(72,140,154,0.3);}50%{transform:scale(1.02);box-shadow:0 0 25px rgba(72,140,154,0.5);}}
        /* Filters dropdown */
        .filters-dropdown-container{position:relative;display:inline-block;}
        .filters-dropdown-btn{background:linear-gradient(135deg,#488C9A 0%,#3a6e7f 100%);color:white;border:none;padding:12px 20px;border-radius:10px;cursor:pointer;font-size:.95em;font-weight:600;transition:all .3s cubic-bezier(.25,.46,.45,.94);display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(72,140,154,.3);}
        .filters-dropdown-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(72,140,154,.4);}
        .filters-dropdown-content{position:absolute;top:100%;right:0;background:white;border:1px solid #e1e5e9;border-radius:12px;box-shadow:0 12px 24px rgba(0,0,0,.15);z-index:1000;min-width:400px;max-width:500px;max-height:500px;overflow-y:auto;backdrop-filter:blur(10px);}
        .filters-dropdown-header{padding:16px 20px;background:linear-gradient(135deg,#488C9A 0%,#3a6e7f 100%);color:white;border-bottom:none;font-weight:700;font-size:1.1em;text-align:center;border-radius:12px 12px 0 0;}
        .filter-item{padding:16px 20px;border-bottom:1px solid #f1f3f4;}
        .filter-item:last-child{border-bottom:none;border-radius:0 0 12px 12px;}
        .filter-item label{display:block;font-weight:600;color:#293E4C;margin-bottom:8px;font-size:.95em;}
        .filter-item input,.filter-item select{width:95%;padding:10px 12px;border:2px solid #e1e5e9;border-radius:8px;font-size:14px;transition:border-color .2s ease,box-shadow .2s ease;background-color:white;}
        .filter-item input:focus,.filter-item select:focus{outline:none;border-color:#488C9A;box-shadow:0 0 0 3px rgba(72,140,154,.1);}
        /* Column chooser */
        .column-chooser-container{position:relative;display:inline-block;}
        .column-chooser-btn{background-color:#488C9A;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:.9em;transition:background-color .3s ease;}
        .column-chooser-btn:hover{background-color:#3A6E7F;}
        .calendar-view-btn{background-color:#488C9A;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:.9em;transition:background-color .3s ease;}
        .calendar-view-btn:hover{background-color:#3A6E7F;}
        .column-chooser-dropdown{position:absolute;top:100%;right:0;background:white;border:1px solid #ddd;border-radius:4px;box-shadow:0 4px 8px rgba(0,0,0,.1);z-index:1000;min-width:250px;max-width:300px;max-height:400px;overflow-y:auto;}
        .column-chooser-header{padding:12px 16px;background-color:#f8f9fa;border-bottom:1px solid #ddd;font-weight:600;color:#293E4C;}
        .column-chooser-options{padding:8px 0;max-height:300px;overflow-y:auto;}
        .column-option{display:flex;align-items:center;padding:6px 16px;cursor:pointer;transition:background-color .2s ease;}
        .column-option:hover{background-color:#f8f9fa;}
        .column-option input[type=checkbox]{margin-right:8px;cursor:pointer;}
        .column-chooser-footer{padding:8px 16px;border-top:1px solid #ddd;background-color:#f8f9fa;}
        .reset-columns-btn{background-color:#6c757d;color:white;border:none;padding:4px 12px;border-radius:3px;cursor:pointer;font-size:.8em;transition:background-color .3s ease;}
        .reset-columns-btn:hover{background-color:#5a6268;}
        .column-hidden{display:none !important;}

        /* Pagination styles - matching manage_pallets.php */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .pagination-info {
            font-size: 0.9em;
            color: #6c757d;
            font-weight: 500;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pagination-controls label {
            font-size: 0.9em;
            margin-right: 5px;
            color: #293E4C;
            font-weight: 500;
        }

        .pagination-controls input,
        .pagination-controls select {
            padding: 8px 12px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
        }

        .pagination-controls input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }

        .pagination-controls button {
            padding: 8px 16px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }

        .pagination-controls button:hover:not(:disabled) {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-1px);
        }

        .pagination-controls button:disabled {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
            transform: none;
        }

        #pageInfo {
            color: #293E4C;
            font-weight: 600;
            padding: 0 8px;
        }

        .flash-messages {
            margin-bottom: 20px;
        }

        .flash-messages p {
            margin: 0 0 10px 0;
            padding: 12px 16px;
            border-radius: 10px;
            background: #e8f7ec;
            color: #146c2e;
            border: 1px solid #c9e9d3;
            font-weight: 500;
        }

        .flash-messages .error-message {
            background: #ffeaea;
            color: #9f1d1d;
            border-color: #f7c8c8;
        }

        .bulk-btn {
            border: none;
            border-radius: 10px;
            padding: 8px 14px;
            font-weight: 600;
            cursor: pointer;
            color: white;
            font-family: 'Poppins', sans-serif;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .bulk-btn:disabled {
            cursor: not-allowed;
            opacity: 0.45;
            transform: none;
            box-shadow: none;
        }

        .bulk-btn:hover:not(:disabled) {
            transform: translateY(-1px);
        }

        .bulk-btn-edit {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
        }

        .bulk-btn-receive {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
        }

        .bulk-btn-delete {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }

        .bulk-btn-import {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }

        .select-column {
            width: 52px;
            text-align: center;
        }

        .actions-column {
            min-width: 140px;
        }

        .select-column input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: #488C9A;
            cursor: pointer;
        }

        tr.grouped-main-row {
            cursor: pointer;
        }

        tr.grouped-main-row td {
            background: rgba(72, 140, 154, 0.04);
        }

        tr.delivery-detail-row td {
            background: #fcfeff;
            border-bottom: 1px dashed rgba(72, 140, 154, 0.2);
        }

        .delivery-detail-indent {
            padding-left: 28px;
            color: #4b5563;
        }

        .wattage-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .wattage-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.8em;
            font-weight: 600;
            color: #293E4C;
            background: rgba(72, 140, 154, 0.12);
            border: 1px solid rgba(72, 140, 154, 0.2);
        }

        .group-helper-text {
            display: block;
            margin-top: 6px;
            color: #6b7280;
            font-size: 0.78em;
            font-weight: 500;
        }

        .modal-body form {
            padding: 20px;
        }

        .modal-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .modal-form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .modal-form-group label {
            color: #293E4C;
            font-weight: 600;
            font-size: 0.9em;
        }

        .modal-form-group input,
        .modal-form-group select {
            border: 2px solid rgba(72, 140, 154, 0.14);
            border-radius: 10px;
            padding: 10px 12px;
            font-family: 'Poppins', sans-serif;
        }

        .modal-form-actions {
            margin-top: 18px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .modal-form-actions button {
            border: none;
            border-radius: 10px;
            padding: 9px 14px;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
        }

        .modal-btn-secondary {
            background: #e5e7eb;
            color: #1f2937;
        }

        .modal-btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
        }

        @media (max-width: 768px) {
            .modal-form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        $extra = [];
        if ($project_id) {
            $extra[] = ['label' => 'Project Overview', 'url' => 'project_overview.php?project_id='.(int)$project_id];
            echo slp_render_breadcrumbs(['current_label' => 'Delivery Tracker', 'extra' => $extra]);
        } else {
            $extra[] = ['label' => 'Modules', 'url' => 'modules.php'];
            $extra[] = ['label' => 'Batch Details', 'url' => 'module_overview.php?batch_id='.(int)$origin_batch_id];
            echo slp_render_breadcrumbs(['current_label' => 'Unassigned Deliveries from Batch', 'extra' => $extra]);
        }
    ?>

    <?php if (!empty($_SESSION['messages'])): ?>
    <div class="flash-messages">
        <?php foreach ($_SESSION['messages'] as $message): ?>
            <?php echo $message; ?>
        <?php endforeach; ?>
    </div>
    <?php $_SESSION['messages'] = []; ?>
    <?php endif; ?>

    <!-- Header Section -->
    <div class="delivery-tracker-header">
        <div class="header-content">
            <div class="header-info">
        <h1><?php echo $page_title_info; ?></h1>
                <p class="header-subtitle">Track and manage all deliveries for this project</p>
            </div>
            <div class="header-stats">
                <div class="stat-item stat-item-total">
                    <p class="stat-number"><?php echo $total_deliveries; ?></p>
                    <p class="stat-label">Total Deliveries</p>
                </div>
                <?php foreach ($active_status_counts as $status => $count): ?>
                    <?php
                    // Determine status badge color class
                    $status_badge_class = 'stat-item-default';
                    if ($status === 'On Water') {
                        $status_badge_class = 'stat-item-transit';
                    } elseif ($status === 'Customs Hold') {
                        $status_badge_class = 'stat-item-canceled';
                    } elseif ($status === 'Cleared Customs') {
                        $status_badge_class = 'stat-item-pending';
                    } elseif (strpos($status, 'In Transit') !== false) {
                        $status_badge_class = 'stat-item-transit';
                    } elseif (strpos($status, 'Delivered') !== false) {
                        $status_badge_class = 'stat-item-delivered';
                    } elseif ($status === 'Canceled') {
                        $status_badge_class = 'stat-item-canceled';
                    }
                    ?>
                    <div class="stat-item <?php echo $status_badge_class; ?>">
                        <p class="stat-number"><?php echo $count; ?></p>
                        <p class="stat-label"><?php echo htmlspecialchars($status); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
                            </div>

    <!-- Filter Section -->
    <div class="filter-section">
                            <form id="filterForm" method="get">
            <?php if ($project_id): ?>
                                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            <?php elseif ($origin_batch_id): ?>
                                    <input type="hidden" name="origin_batch_id" value="<?php echo $origin_batch_id; ?>">
                                <?php endif; ?>
            
        <div class="filter-header">
            <h2 class="filter-title">
                <i class="fas fa-filter"></i>
                Filter Deliveries
            </h2>
            <div class="filter-actions">
                <button type="button" class="btn-clear" onclick="clearFilters()">
                    <i class="fas fa-times"></i>
                    Clear
                </button>
                <button type="submit" class="btn-apply">
                    <i class="fas fa-search"></i>
                    Apply Filters
                </button>
            </div>
        </div>

        <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Delivery Date Range</label>
                    <div class="date-range-group">
                        <input type="date" name="start_date" class="filter-input" placeholder="Start Date" value="<?php echo htmlspecialchars($start_date); ?>">
                        <input type="date" name="end_date" class="filter-input" placeholder="End Date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="wattageFilter">Wattage</label>
                    <select name="wattage" id="wattageFilter" class="filter-select">
                        <option value="">All Wattages</option>
                        <?php foreach ($unique_wattages as $wattage): ?>
                            <option value="<?php echo htmlspecialchars($wattage); ?>" <?php echo $wattage_filter == $wattage ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($wattage); ?>W
                            </option>
                        <?php endforeach; ?>
                                    </select>
                                </div>

                <div class="filter-group">
                    <label class="filter-label" for="statusFilter">Status</label>
                    <select name="status" id="statusFilter" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="On Water" <?php echo $status_filter == 'On Water' ? 'selected' : ''; ?>>On Water</option>
                        <option value="Customs Hold" <?php echo $status_filter == 'Customs Hold' ? 'selected' : ''; ?>>Customs Hold</option>
                        <option value="Cleared Customs" <?php echo $status_filter == 'Cleared Customs' ? 'selected' : ''; ?>>Cleared Customs</option>
                        <option value="In Transit to Warehouse" <?php echo $status_filter == 'In Transit to Warehouse' ? 'selected' : ''; ?>>In Transit to Warehouse</option>
                        <option value="Delivered to Warehouse" <?php echo $status_filter == 'Delivered to Warehouse' ? 'selected' : ''; ?>>Delivered to Warehouse</option>
                        <option value="In Transit to Project" <?php echo $status_filter == 'In Transit to Project' ? 'selected' : ''; ?>>In Transit to Project</option>
                        <option value="Delivered to Project" <?php echo $status_filter == 'Delivered to Project' ? 'selected' : ''; ?>>Delivered to Project</option>
                        <option value="Canceled" <?php echo $status_filter == 'Canceled' ? 'selected' : ''; ?>>Canceled</option>
                    </select>
                                </div>

                <?php if ($project_id): ?>
                <div class="filter-group">
                    <label class="filter-label" for="supplierFilter">Supplier</label>
                    <select name="supplier" id="supplierFilter" class="filter-select">
                        <option value="">All Suppliers</option>
                        <?php foreach ($unique_suppliers as $supplier): ?>
                            <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo $supplier_filter == $supplier ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                        </div>
                <?php endif; ?>

            <div class="filter-group">
                <label class="filter-label" for="searchFilter">Search</label>
                <input type="text" name="search" id="searchFilter" class="filter-input" placeholder="Search BOL, supplier, status..." value="<?php echo htmlspecialchars($search_query); ?>">
            </div>
        </div>
    </form>
</div>

    <!-- Pagination Controls -->
    <?php if ($grouped_deliveries): ?>
    <div class="pagination-container">
        <div class="pagination-info">
            <span id="paginationInfo">Showing 0 of 0 deliveries</span>
        </div>
        <div class="pagination-controls">
            <label for="itemsPerPage">Show:</label>
            <input type="number" id="itemsPerPage" value="25" min="1" max="500" style="width: 80px;">
            <label>per page</label>
            <button type="button" id="prevPage" disabled>Previous</button>
            <span id="pageInfo">Page 1 of 1</span>
            <button type="button" id="nextPage" disabled>Next</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Deliveries Table -->
    <div class="deliveries-container">
        <div class="table-header">
            <div class="table-header-main">
                <h3 class="table-title">
                    <i class="fas fa-truck"></i>
                    Deliveries
                </h3>
            </div>
            <?php if ($can_manage_deliveries): ?>
            <div class="table-header-admin">
                <p class="admin-tools-title">Admin Tools: <span id="selectedDeliveryCount">0 selected</span></p>
                <div class="admin-tools-buttons">
                    <button type="button" class="bulk-btn bulk-btn-import" onclick="window.location.href='upload_shipments.php<?php echo $project_id ? '?project_id=' . $project_id : ''; ?>'">Import Shipments</button>
                    <button type="button" class="bulk-btn bulk-btn-edit" id="bulkEditBtn" onclick="openBulkEditModal()" disabled>Bulk Edit</button>
                    <button type="button" class="bulk-btn bulk-btn-receive" id="receiveToProjectBtn" onclick="openReceiveToProjectModal()" disabled>Receive to Project</button>
                    <button type="submit" form="deliveriesForm" name="delete_selected" class="bulk-btn bulk-btn-delete" id="bulkDeleteBtn" disabled onclick="return confirm('Delete selected deliveries? This cannot be undone.');">Bulk Delete</button>
                </div>
            </div>
            <?php endif; ?>
            <div class="table-header-actions">
                <button type="submit" form="filterForm" name="export" value="1" class="btn-export-header">
                    <i class="fas fa-download"></i>
                    Export CSV
                </button>
                <?php if ($project_id): ?>
                <button type="button" class="btn-calendar-header" onclick="window.location.href='scheduling.php?project_id=<?php echo $project_id; ?>'">
                    <i class="fas fa-calendar"></i>
                    Calendar View
                </button>
                <?php endif; ?>
                <div style="position: relative;">
                    <button type="button" class="btn-columns-header" onclick="toggleColumnChooser()">
                        <i class="fas fa-columns"></i>
                        Columns
                    </button>
                    <div id="columnChooserDropdown" class="column-chooser-dropdown" style="display:none;">
                        <div class="column-chooser-header">Select Columns to Show:</div>
                        <div class="column-chooser-options">
                            <?php if ($can_manage_deliveries): ?>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="select-column" checked>
                                Select
                            </label>
                            <?php endif; ?>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="supplier-column" checked>
                                Supplier
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="carrier-column" checked>
                                Carrier
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="wattage-column" checked>
                                Wattage Summary
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="status-column" checked>
                                Status
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="quantity-column" checked>
                                Quantity
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="bol-column" checked>
                                BOL Number
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="anticipated-column" checked>
                                Anticipated Date
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="actual-column" checked>
                                Actual Date
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="pallets-column" checked>
                                Pallets
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="scheduled-column" checked>
                                Scheduled
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="pod-column" checked>
                                Proof of Delivery
                            </label>
                            <?php if ($can_manage_deliveries): ?>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="actions-column" checked>
                                Actions
                            </label>
                            <?php endif; ?>
                        </div>
                        <div class="column-chooser-footer">
                            <button type="button" onclick="resetColumns()" class="btn-clear" style="width: 100%;">Reset to Default</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form id="deliveriesForm" method="post">
            <div class="table-responsive">
                <?php if ($grouped_deliveries): ?>
                <table id="deliveriesTable">
                    <thead>
                        <tr>
                            <?php if ($can_manage_deliveries): ?>
                            <th class="select-column"><input type="checkbox" id="selectAllDeliveries"></th>
                            <?php endif; ?>
                            <th class="supplier-column">Supplier</th>
                            <th class="carrier-column">Carrier</th>
                            <th class="wattage-column">Wattage</th>
                            <th class="status-column">Status</th>
                            <th class="quantity-column">Quantity</th>
                            <th class="bol-column">BOL Number</th>
                            <th class="anticipated-column">Anticipated Date</th>
                            <th class="actual-column">Actual Date</th>
                            <th class="pallets-column">Pallets</th>
                            <th class="scheduled-column">Scheduled</th>
                            <th class="pod-column">Proof of Delivery</th>
                            <?php if ($can_manage_deliveries): ?>
                            <th class="actions-column">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $palletConn = getDBConnection();
                        $stmtPallets = null;
                        if ($palletConn && !$palletConn->connect_errno) {
                            $stmtPallets = $palletConn->prepare("
                                SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity
                                FROM delivery_pallets dp
                                JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
                                WHERE dp.delivery_id = ?
                                ORDER BY ip.id
                            ");
                        }
                        ?>
                        <?php foreach ($grouped_deliveries as $groupIndex => $delivery): ?>
                            <?php
                            $groupedRows = $delivery['grouped_deliveries'];
                            $deliveryIdsForPallets = $delivery['is_grouped'] ? $delivery['group_delivery_ids'] : [(int)$delivery['id']];
                            $associatedPallets = [];
                            if ($stmtPallets) {
                                foreach ($deliveryIdsForPallets as $deliveryForPallet) {
                                    $stmtPallets->bind_param("i", $deliveryForPallet);
                                    $stmtPallets->execute();
                                    $palletRes = $stmtPallets->get_result();
                                    while ($pRow = $palletRes->fetch_assoc()) {
                                        $associatedPallets[(int)$pRow['id']] = $pRow;
                                    }
                                }
                            }
                            $associatedPallets = array_values($associatedPallets);
                            $palletCount = count($associatedPallets);
                            $palletData = json_encode($associatedPallets, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);

                            $statusLabel = $delivery['status_of_delivery'];
                            $statusClass = 'status-badge ';
                            if ($statusLabel === 'On Water' || strpos((string)$statusLabel, 'In Transit') !== false) {
                                $statusClass .= 'status-transit';
                            } elseif ($statusLabel === 'Customs Hold') {
                                $statusClass .= 'status-canceled';
                            } elseif ($statusLabel === 'Cleared Customs' || $statusLabel === 'Mixed Status' || $statusLabel === 'Pending') {
                                $statusClass .= 'status-pending';
                            } elseif (strpos((string)$statusLabel, 'Delivered') !== false) {
                                $statusClass .= 'status-delivered';
                            } elseif ($statusLabel === 'Canceled') {
                                $statusClass .= 'status-canceled';
                            } else {
                                $statusClass .= 'status-pending';
                            }

                            $rowClasses = ['delivery-main-row'];
                            if ($delivery['is_grouped']) {
                                $rowClasses[] = 'grouped-main-row';
                            }
                            if (!empty($delivery['contains_highlight'])) {
                                $rowClasses[] = 'highlighted-delivery';
                            }

                            $hasPodInGroup = false;
                            foreach ($groupedRows as $rowInGroup) {
                                if (!empty($rowInGroup['proof_of_delivery']) || !empty($rowInGroup['has_pod_in_documents'])) {
                                    $hasPodInGroup = true;
                                    break;
                                }
                            }
                            ?>
                            <tr class="<?php echo implode(' ', $rowClasses); ?>" data-group-index="<?php echo $groupIndex; ?>" <?php if (!empty($delivery['contains_highlight'])) echo 'id="highlighted-delivery"'; ?> <?php if ($delivery['is_grouped']): ?>onclick="toggleDeliveryDetails(<?php echo $groupIndex; ?>, event)"<?php endif; ?>>
                                <?php if ($can_manage_deliveries): ?>
                                <td class="select-column">
                                    <?php if ($delivery['is_grouped']): ?>
                                        <?php foreach ($groupedRows as $groupRow): ?>
                                            <input type="checkbox" name="selected_deliveries[]" class="group-hidden-checkbox grouped-checkbox-<?php echo $groupIndex; ?>" value="<?php echo (int)$groupRow['id']; ?>" data-status="<?php echo htmlspecialchars((string)($groupRow['status_of_delivery'] ?? '')); ?>" style="display:none;">
                                        <?php endforeach; ?>
                                        <input type="checkbox" class="group-master-checkbox" data-group-index="<?php echo $groupIndex; ?>">
                                    <?php else: ?>
                                        <input type="checkbox" name="selected_deliveries[]" value="<?php echo (int)$delivery['id']; ?>" data-status="<?php echo htmlspecialchars((string)($delivery['status_of_delivery'] ?? '')); ?>">
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td class="supplier-column" title="<?php echo htmlspecialchars(implode(', ', $delivery['supplier_list'])); ?>">
                                    <?php echo htmlspecialchars($delivery['supplier_display']); ?>
                                    <?php if ($delivery['is_grouped']): ?>
                                    <span class="group-helper-text"><?php echo (int)$delivery['group_count']; ?> lines grouped under this BOL</span>
                                    <?php endif; ?>
                                </td>
                                <td class="carrier-column">
                                    <?php if (!empty($delivery['carrier_id']) && !empty($delivery['carrier_name'])): ?>
                                        <a href="carrier_details?carrier_id=<?php echo (int)$delivery['carrier_id']; ?>" style="color:#488C9A; text-decoration:none; font-weight:500;"><?php echo htmlspecialchars($delivery['carrier_name']); ?></a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="wattage-column">
                                    <span class="wattage-pill"><?php echo htmlspecialchars((string)$delivery['display_wattage']); ?></span>
                                    <?php if ($delivery['is_grouped']): ?>
                                    <span class="group-helper-text">Click row to expand details</span>
                                    <?php endif; ?>
                                </td>
                                <td class="status-column">
                                    <span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars((string)$statusLabel); ?></span>
                                </td>
                                <td class="quantity-column"><?php echo (int)($delivery['is_grouped'] ? $delivery['total_quantity'] : $delivery['quantity']); ?></td>
                                <td class="bol-column"><?php echo htmlspecialchars((string)($delivery['bol_number'] ?: '—')); ?></td>
                                <td class="anticipated-column"><?php echo htmlspecialchars((string)($delivery['is_grouped'] ? $delivery['anticipated_display'] : ($delivery['anticipated_delivery_date'] ? date('m/d/Y', strtotime($delivery['anticipated_delivery_date'])) : '—'))); ?></td>
                                <td class="actual-column"><?php echo htmlspecialchars((string)($delivery['is_grouped'] ? $delivery['actual_display'] : ($delivery['actual_delivery_date'] ? date('m/d/Y', strtotime($delivery['actual_delivery_date'])) : '—'))); ?></td>
                                <td class="pallets-column">
                                    <?php if ($palletCount): ?>
                                    <button type="button" class="action-btn action-btn-primary view-pallets-btn" data-pallets='<?php echo htmlspecialchars($palletData, ENT_QUOTES); ?>'>
                                        <i class="fas fa-boxes"></i>
                                        View Pallets (<?php echo $palletCount; ?>)
                                    </button>
                                    <?php else: ?>
                                    —
                                    <?php endif; ?>
                                </td>
                                <td class="scheduled-column">
                                    <?php if ($delivery['is_grouped']): ?>
                                    <span class="status-badge status-pending">See detail rows</span>
                                    <?php else: ?>
                                        <?php if ($delivery['scheduled'] == 1): ?>
                                            <?php if (!empty($delivery['project_id']) && !empty($delivery['appointment_id'])): ?>
                                            <a href="scheduling.php?project_id=<?php echo $delivery['project_id']; ?>&delivery_id=<?php echo $delivery['id']; ?>&appointment_id=<?php echo $delivery['appointment_id']; ?>&auto_edit=1" class="action-btn action-btn-success">
                                                <i class="fas fa-calendar-check"></i>
                                                View Appointment
                                            </a>
                                            <?php else: ?>
                                            <span class="status-badge status-delivered"><i class="fas fa-check-circle"></i> Scheduled</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if (!empty($delivery['project_id']) && $delivery['status_of_delivery'] === 'In Transit to Project'): ?>
                                            <a href="scheduling.php?project_id=<?php echo $delivery['project_id']; ?>&delivery_id=<?php echo $delivery['id']; ?>" class="action-btn action-btn-warning">
                                                <i class="fas fa-calendar-plus"></i>
                                                Schedule
                                            </a>
                                            <?php else: ?>
                                            —
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="pod-column">
                                    <?php if ($delivery['is_grouped']): ?>
                                        <?php if ($hasPodInGroup): ?>
                                        <span class="status-badge status-delivered">POD available in details</span>
                                        <?php else: ?>
                                        <span class="group-helper-text">Use detail rows for POD actions</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (!empty($delivery['proof_of_delivery']) || !empty($delivery['has_pod_in_documents'])): ?>
                                        <a href="view_pod?delivery_id=<?php echo $delivery['id']; ?>" target="_blank" class="action-btn action-btn-primary">
                                            <i class="fas fa-file-pdf"></i>
                                            View POD
                                        </a>
                                        <?php else: ?>
                                            <?php if (in_array($_SESSION['role'], ['global_admin', 'admin', 'customer_admin'], true)): ?>
                                            <button type="button" class="action-btn action-btn-outline" onclick="event.stopPropagation(); openUploadPodModal(<?php echo (int)$delivery['id']; ?>);">
                                                <i class="fas fa-upload"></i>
                                                Upload POD
                                            </button>
                                            <?php else: ?>
                                            —
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <?php if ($can_manage_deliveries): ?>
                                <td class="actions-column">
                                    <?php if ($delivery['is_grouped']): ?>
                                    <button type="button" class="action-btn action-btn-outline" onclick="toggleDeliveryDetails(<?php echo $groupIndex; ?>, event)">Toggle Details</button>
                                    <?php else: ?>
                                        <?php if ((string)($delivery['status_of_delivery'] ?? '') === 'On Water' && !empty($delivery['project_id'])): ?>
                                        <a href="container_tracking.php?project_id=<?php echo (int)$delivery['project_id']; ?>" class="action-btn action-btn-primary">Track Container</a>
                                        <?php else: ?>
                                        <a href="edit_delivery.php?delivery_id=<?php echo (int)$delivery['id']; ?>&project_id=<?php echo (int)$delivery['project_id']; ?>" class="action-btn action-btn-primary">Edit</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>

                            <?php if ($delivery['is_grouped']): ?>
                                <?php foreach ($groupedRows as $detailRow): ?>
                                <tr class="delivery-detail-row" data-parent-group="<?php echo $groupIndex; ?>" style="display:none;">
                                    <?php if ($can_manage_deliveries): ?>
                                    <td class="select-column"></td>
                                    <?php endif; ?>
                                    <td class="supplier-column delivery-detail-indent">↳ <?php echo htmlspecialchars((string)$detailRow['supplier']); ?></td>
                                    <td class="carrier-column">
                                        <?php if (!empty($detailRow['carrier_id']) && !empty($detailRow['carrier_name'])): ?>
                                            <a href="carrier_details?carrier_id=<?php echo (int)$detailRow['carrier_id']; ?>" style="color:#488C9A; text-decoration:none; font-weight:500;"><?php echo htmlspecialchars($detailRow['carrier_name']); ?></a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="wattage-column">
                                        <?php $detail_wattage = trim((string)($detailRow['wattage'] ?? '')); ?>
                                        <span class="wattage-pill"><?php echo htmlspecialchars($detail_wattage !== '' ? $detail_wattage . 'W' : 'Unknown'); ?> x <?php echo (int)$detailRow['quantity']; ?></span>
                                    </td>
                                    <td class="status-column">
                                        <span class="status-badge"><?php echo htmlspecialchars((string)$detailRow['status_of_delivery']); ?></span>
                                    </td>
                                    <td class="quantity-column"><?php echo (int)$detailRow['quantity']; ?></td>
                                    <td class="bol-column">—</td>
                                    <td class="anticipated-column"><?php echo $detailRow['anticipated_delivery_date'] ? date('m/d/Y', strtotime($detailRow['anticipated_delivery_date'])) : '—'; ?></td>
                                    <td class="actual-column"><?php echo $detailRow['actual_delivery_date'] ? date('m/d/Y', strtotime($detailRow['actual_delivery_date'])) : '—'; ?></td>
                                    <td class="pallets-column">—</td>
                                    <td class="scheduled-column">
                                        <?php if ($detailRow['scheduled'] == 1 && !empty($detailRow['project_id']) && !empty($detailRow['appointment_id'])): ?>
                                        <a href="scheduling.php?project_id=<?php echo (int)$detailRow['project_id']; ?>&delivery_id=<?php echo (int)$detailRow['id']; ?>&appointment_id=<?php echo (int)$detailRow['appointment_id']; ?>&auto_edit=1" class="action-btn action-btn-success">View</a>
                                        <?php elseif (!empty($detailRow['project_id']) && $detailRow['status_of_delivery'] === 'In Transit to Project'): ?>
                                        <a href="scheduling.php?project_id=<?php echo (int)$detailRow['project_id']; ?>&delivery_id=<?php echo (int)$detailRow['id']; ?>" class="action-btn action-btn-warning">Schedule</a>
                                        <?php else: ?>
                                        —
                                        <?php endif; ?>
                                    </td>
                                    <td class="pod-column">
                                        <?php if (!empty($detailRow['proof_of_delivery']) || !empty($detailRow['has_pod_in_documents'])): ?>
                                        <a href="view_pod?delivery_id=<?php echo (int)$detailRow['id']; ?>" target="_blank" class="action-btn action-btn-primary">View POD</a>
                                        <?php elseif (in_array($_SESSION['role'], ['global_admin', 'admin', 'customer_admin'], true)): ?>
                                        <button type="button" class="action-btn action-btn-outline" onclick="event.stopPropagation(); openUploadPodModal(<?php echo (int)$detailRow['id']; ?>);">Upload POD</button>
                                        <?php else: ?>
                                        —
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($can_manage_deliveries): ?>
                                    <td class="actions-column">
                                        <?php if ((string)($detailRow['status_of_delivery'] ?? '') === 'On Water' && !empty($detailRow['project_id'])): ?>
                                        <a href="container_tracking.php?project_id=<?php echo (int)$detailRow['project_id']; ?>" class="action-btn action-btn-primary">Track Container</a>
                                        <?php else: ?>
                                        <a href="edit_delivery.php?delivery_id=<?php echo (int)$detailRow['id']; ?>&project_id=<?php echo (int)($detailRow['project_id'] ?? 0); ?>" class="action-btn action-btn-primary">Edit</a>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php
                        if ($stmtPallets) {
                            $stmtPallets->close();
                        }
                        if ($palletConn && !$palletConn->connect_errno) {
                            $palletConn->close();
                        }
                        ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Deliveries Found</h3>
                    <p>No deliveries match your current filter criteria. Try adjusting your filters.</p>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Pallets Modal -->
    <div id="associatedPalletsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
            <h2>Associated Pallets</h2>
                <span class="modal-close" id="closePalletModalBtn">&times;</span>
            </div>
            <div class="modal-body">
                <div id="palletList"></div>
            </div>
        </div>
    </div>

    <?php if ($can_manage_deliveries): ?>
    <div id="uploadPodModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Upload POD</h2>
                <span class="modal-close" onclick="closeUploadPodModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="uploadPodForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_pod">
                    <input type="hidden" name="delivery_id" id="uploadPodDeliveryId">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="modal-form-grid">
                        <div class="modal-form-group" style="grid-column: 1 / -1;">
                            <label for="uploadPodFile">POD File</label>
                            <input type="file" id="uploadPodFile" name="pod_file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt,.csv" required>
                            <small style="color:#6b7280;font-size:0.82em;">Allowed: PDF, image, Word, Excel, TXT, CSV (max 50MB)</small>
                        </div>
                    </div>
                    <div class="modal-form-actions">
                        <button type="button" class="modal-btn-secondary" onclick="closeUploadPodModal()">Cancel</button>
                        <button type="submit" class="modal-btn-primary" id="uploadPodSubmitBtn">Upload POD</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="bulkEditModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Bulk Edit Deliveries</h2>
                <span class="modal-close" onclick="closeBulkEditModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="post" id="bulkEditForm">
                    <div id="bulkEditSelectedInputs"></div>
                    <div class="modal-form-grid">
                        <div class="modal-form-group">
                            <label for="bulk_anticipated_date">Anticipated Date</label>
                            <input type="date" id="bulk_anticipated_date" name="anticipated_delivery_date">
                        </div>
                        <div class="modal-form-group">
                            <label for="bulk_warehouse_arrival_date">Warehouse Arrival</label>
                            <input type="date" id="bulk_warehouse_arrival_date" name="warehouse_arrival_date">
                        </div>
                        <div class="modal-form-group">
                            <label for="bulk_actual_date">Actual Date</label>
                            <input type="date" id="bulk_actual_date" name="actual_delivery_date">
                        </div>
                        <div class="modal-form-group">
                            <label for="bulk_left_warehouse_date">Left Warehouse</label>
                            <input type="date" id="bulk_left_warehouse_date" name="left_warehouse_date">
                        </div>
                        <div class="modal-form-group">
                            <label for="bulk_miles">Miles</label>
                            <input type="number" step="0.01" id="bulk_miles" name="miles" placeholder="Optional">
                        </div>
                        <div class="modal-form-group">
                            <label for="bulk_carrier_id">Carrier</label>
                            <select id="bulk_carrier_id" name="carrier_id" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; font-family:inherit;">
                                <option value="">— No change —</option>
                                <?php foreach ($all_carriers as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?><?php echo $c['is_solterra_managed'] ? ' (Solterra)' : ''; ?> — <?php echo strtoupper($c['carrier_type']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="modal-form-group">
                            <label for="bulk_freight_cost">Freight Cost</label>
                            <input type="number" step="0.01" id="bulk_freight_cost" name="freight_cost" placeholder="Optional">
                        </div>
                        <div class="modal-form-group">
                            <label for="bulk_accessorial_paid">Accessorial Paid</label>
                            <input type="number" step="0.01" id="bulk_accessorial_paid" name="accessorial_costs_paid" placeholder="Optional">
                        </div>
                        <div class="modal-form-group">
                            <label for="bulk_accessorial_charged">Accessorial Charged</label>
                            <input type="number" step="0.01" id="bulk_accessorial_charged" name="accessorial_costs" placeholder="Optional">
                        </div>
                        <?php if ($role !== 'customer_admin'): ?>
                        <div class="modal-form-group">
                            <label for="bulk_customer_cost">Customer Cost</label>
                            <input type="number" step="0.01" id="bulk_customer_cost" name="customer_cost" placeholder="Optional">
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-form-actions">
                        <button type="button" class="modal-btn-secondary" onclick="closeBulkEditModal()">Cancel</button>
                        <button type="submit" class="modal-btn-primary" name="bulk_edit_submit">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="receiveToProjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Receive to Project</h2>
                <span class="modal-close" onclick="closeReceiveToProjectModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="post" enctype="multipart/form-data" id="receiveToProjectForm">
                    <div id="receiveSelectedInputs"></div>
                    <div class="modal-form-grid">
                        <div class="modal-form-group">
                            <label for="receive_actual_date">Actual Delivery Date</label>
                            <input type="date" id="receive_actual_date" name="actual_delivery_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="modal-form-group">
                            <label for="receive_pod_file">Proof of Delivery (optional)</label>
                            <input type="file" id="receive_pod_file" name="proof_of_delivery" accept="image/*,.pdf">
                        </div>
                    </div>
                    <div class="modal-form-actions">
                        <button type="button" class="modal-btn-secondary" onclick="closeReceiveToProjectModal()">Cancel</button>
                        <button type="submit" class="modal-btn-primary" name="receive_to_project_submit">Mark Delivered</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
const canManageDeliveries = <?php echo $can_manage_deliveries ? 'true' : 'false'; ?>;
let associatedPalletsModal;
let palletListDiv;
let uploadPodModal;
let bulkEditModal;
let receiveToProjectModal;
let currentPage = 1;
let itemsPerPage = 25;
let mainDeliveryRows = [];
const expandedGroups = new Set();

function clearFilters() {
    document.getElementById('filterForm').reset();
    const url = new URL(window.location.href);
    <?php if ($project_id): ?>
    url.search = '?project_id=<?php echo $project_id; ?>';
    <?php elseif ($origin_batch_id): ?>
    url.search = '?origin_batch_id=<?php echo $origin_batch_id; ?>';
    <?php endif; ?>
    window.location.href = url.toString();
}

function toggleColumnChooser() {
    const dropdown = document.getElementById('columnChooserDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

function toggleColumn(col, show) {
    document.querySelectorAll('.' + col).forEach((el) => {
        if (show) {
            el.classList.remove('column-hidden');
        } else {
            el.classList.add('column-hidden');
        }
    });
}

function resetColumns() {
    document.querySelectorAll('.column-toggle').forEach((cb) => {
        cb.checked = true;
        toggleColumn(cb.dataset.column, true);
    });
    saveColumnPreferences();
}

function saveColumnPreferences() {
    const prefs = {};
    document.querySelectorAll('.column-toggle').forEach((cb) => {
        prefs[cb.dataset.column] = cb.checked;
    });
    localStorage.setItem('viewProjectColumnPreferences', JSON.stringify(prefs));
}

function loadColumnPreferences() {
    let prefs = localStorage.getItem('viewProjectColumnPreferences');
    if (!prefs) {
        return;
    }
    prefs = JSON.parse(prefs);
    document.querySelectorAll('.column-toggle').forEach((cb) => {
        if (Object.prototype.hasOwnProperty.call(prefs, cb.dataset.column)) {
            cb.checked = !!prefs[cb.dataset.column];
            toggleColumn(cb.dataset.column, cb.checked);
        }
    });
}

function showPalletModal(btn) {
    if (!associatedPalletsModal) {
        associatedPalletsModal = document.getElementById('associatedPalletsModal');
        palletListDiv = document.getElementById('palletList');
    }
    palletListDiv.innerHTML = '';
    const pallets = JSON.parse(btn.dataset.pallets || '[]');

    if (!pallets.length) {
        palletListDiv.innerHTML = '<p style="text-align:center;color:#6c757d;">No pallets found.</p>';
    } else {
        const tbl = document.createElement('table');
        tbl.className = 'pallet-table';
        const head = tbl.createTHead().insertRow();
        ['Identifier', 'Wattage', 'Quantity', 'Actions'].forEach((h) => {
            const th = document.createElement('th');
            th.textContent = h;
            head.appendChild(th);
        });
        const body = tbl.createTBody();
        pallets.forEach((p) => {
            const r = body.insertRow();
            r.insertCell().textContent = p.pallet_identifier || `ID: ${p.id}`;
            r.insertCell().textContent = p.wattage ? `${p.wattage}W` : '—';
            r.insertCell().textContent = p.quantity || '—';
            const act = r.insertCell();
            const a = document.createElement('a');
            a.href = `pallet_details.php?pallet_id=${p.id}`;
            a.className = 'action-btn action-btn-primary';
            a.innerHTML = '<i class="fas fa-eye"></i> View Details';
            act.appendChild(a);
        });
        palletListDiv.appendChild(tbl);
    }
    associatedPalletsModal.style.display = 'block';
}

function closeAssociatedPalletModal() {
    if (!associatedPalletsModal) {
        return;
    }
    associatedPalletsModal.style.display = 'none';
    if (palletListDiv) {
        palletListDiv.innerHTML = '';
    }
}

function openUploadPodModal(deliveryId) {
    if (!canManageDeliveries) {
        return;
    }
    uploadPodModal = document.getElementById('uploadPodModal');
    const deliveryInput = document.getElementById('uploadPodDeliveryId');
    const fileInput = document.getElementById('uploadPodFile');
    if (!uploadPodModal || !deliveryInput) {
        return;
    }
    deliveryInput.value = String(deliveryId || '');
    if (fileInput) {
        fileInput.value = '';
    }
    uploadPodModal.style.display = 'block';
}

function closeUploadPodModal() {
    uploadPodModal = document.getElementById('uploadPodModal');
    if (!uploadPodModal) {
        return;
    }
    uploadPodModal.style.display = 'none';
}

function isInteractiveTarget(target) {
    return !!target.closest('a,button,input,select,textarea,label');
}

function toggleDeliveryDetails(index, event) {
    if (event && isInteractiveTarget(event.target)) {
        return;
    }
    const key = String(index);
    if (expandedGroups.has(key)) {
        expandedGroups.delete(key);
    } else {
        expandedGroups.add(key);
    }
    applyGroupVisibility(key);
}

function applyGroupVisibility(groupKey) {
    const mainRow = document.querySelector(`tr.delivery-main-row[data-group-index="${groupKey}"]`);
    const shouldShow = !!(mainRow && mainRow.style.display !== 'none' && expandedGroups.has(String(groupKey)));
    document.querySelectorAll(`tr.delivery-detail-row[data-parent-group="${groupKey}"]`).forEach((row) => {
        row.style.display = shouldShow ? '' : 'none';
    });
}

function initializePagination() {
    const table = document.getElementById('deliveriesTable');
    if (!table) {
        return;
    }
    const tbody = table.querySelector('tbody');
    if (!tbody) {
        return;
    }
    mainDeliveryRows = Array.from(tbody.querySelectorAll('tr.delivery-main-row'));
    const itemsPerPageInput = document.getElementById('itemsPerPage');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');

    if (itemsPerPageInput) {
        itemsPerPageInput.addEventListener('change', function onItemsPerPageChange() {
            itemsPerPage = Math.min(Math.max(1, parseInt(this.value, 10) || 25), 500);
            this.value = itemsPerPage;
            currentPage = 1;
            updatePagination();
        });
    }
    if (prevButton) {
        prevButton.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage -= 1;
                updatePagination();
            }
        });
    }
    if (nextButton) {
        nextButton.addEventListener('click', () => {
            const maxPages = Math.max(1, Math.ceil(mainDeliveryRows.length / itemsPerPage));
            if (currentPage < maxPages) {
                currentPage += 1;
                updatePagination();
            }
        });
    }
    updatePagination();
}

function updatePagination() {
    const totalItems = mainDeliveryRows.length;
    const maxPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));
    if (currentPage > maxPages) {
        currentPage = maxPages;
    }
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;

    mainDeliveryRows.forEach((row) => {
        row.style.display = 'none';
    });
    document.querySelectorAll('tr.delivery-detail-row').forEach((row) => {
        row.style.display = 'none';
    });

    const visibleRows = mainDeliveryRows.slice(startIndex, endIndex);
    visibleRows.forEach((row) => {
        row.style.display = '';
        const groupKey = row.dataset.groupIndex;
        if (expandedGroups.has(String(groupKey))) {
            applyGroupVisibility(groupKey);
        }
    });

    const paginationInfo = document.getElementById('paginationInfo');
    const pageInfo = document.getElementById('pageInfo');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');

    if (paginationInfo) {
        const showing = Math.min(endIndex, totalItems);
        const displayStart = totalItems > 0 ? startIndex + 1 : 0;
        paginationInfo.textContent = `Showing ${displayStart}-${showing} of ${totalItems} deliveries`;
    }
    if (pageInfo) {
        pageInfo.textContent = `Page ${currentPage} of ${maxPages}`;
    }
    if (prevButton) {
        prevButton.disabled = currentPage <= 1;
    }
    if (nextButton) {
        nextButton.disabled = currentPage >= maxPages || totalItems === 0;
    }
}

function toggleGroupedCheckboxes(groupIndex, checked) {
    document.querySelectorAll(`.grouped-checkbox-${groupIndex}`).forEach((hiddenCb) => {
        hiddenCb.checked = checked;
    });
}

function getSelectedDeliveryInputs() {
    return Array.from(document.querySelectorAll('input[name="selected_deliveries[]"]:checked'));
}

function updateBulkActionButtons() {
    if (!canManageDeliveries) {
        return;
    }
    const selected = getSelectedDeliveryInputs();
    const count = selected.length;
    const countLabel = document.getElementById('selectedDeliveryCount');
    if (countLabel) {
        countLabel.textContent = `${count} selected`;
    }

    ['bulkEditBtn', 'receiveToProjectBtn', 'bulkDeleteBtn'].forEach((id) => {
        const btn = document.getElementById(id);
        if (btn) {
            btn.disabled = count === 0;
        }
    });

    const allToggleCandidates = Array.from(
        document.querySelectorAll('tbody tr.delivery-main-row input.group-master-checkbox, tbody tr.delivery-main-row input[name="selected_deliveries[]"]:not(.group-hidden-checkbox)')
    );
    const checkedMain = allToggleCandidates.filter((cb) => cb.checked).length;
    const selectAll = document.getElementById('selectAllDeliveries');
    if (selectAll) {
        selectAll.checked = allToggleCandidates.length > 0 && checkedMain === allToggleCandidates.length;
        selectAll.indeterminate = checkedMain > 0 && checkedMain < allToggleCandidates.length;
    }
}

function populateSelectedInputs(containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        return 0;
    }
    container.innerHTML = '';
    const selected = getSelectedDeliveryInputs();
    selected.forEach((cb) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_deliveries[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    return selected.length;
}

function openBulkEditModal() {
    if (!canManageDeliveries) {
        return;
    }
    const selectedCount = populateSelectedInputs('bulkEditSelectedInputs');
    if (selectedCount === 0) {
        return;
    }
    bulkEditModal = document.getElementById('bulkEditModal');
    if (bulkEditModal) {
        bulkEditModal.style.display = 'block';
    }
}

function closeBulkEditModal() {
    bulkEditModal = document.getElementById('bulkEditModal');
    if (bulkEditModal) {
        bulkEditModal.style.display = 'none';
    }
}

function openReceiveToProjectModal() {
    if (!canManageDeliveries) {
        return;
    }
    const selectedCount = populateSelectedInputs('receiveSelectedInputs');
    if (selectedCount === 0) {
        return;
    }
    receiveToProjectModal = document.getElementById('receiveToProjectModal');
    if (receiveToProjectModal) {
        receiveToProjectModal.style.display = 'block';
    }
}

function closeReceiveToProjectModal() {
    receiveToProjectModal = document.getElementById('receiveToProjectModal');
    if (receiveToProjectModal) {
        receiveToProjectModal.style.display = 'none';
    }
}

function initializeBulkSelection() {
    if (!canManageDeliveries) {
        return;
    }

    document.querySelectorAll('.group-master-checkbox').forEach((groupCb) => {
        groupCb.addEventListener('change', function onGroupChange() {
            toggleGroupedCheckboxes(this.dataset.groupIndex, this.checked);
            updateBulkActionButtons();
        });
    });

    document.querySelectorAll('input[name="selected_deliveries[]"]').forEach((cb) => {
        cb.addEventListener('change', updateBulkActionButtons);
    });

    const selectAll = document.getElementById('selectAllDeliveries');
    if (selectAll) {
        selectAll.addEventListener('change', function onSelectAll() {
            document.querySelectorAll('tbody tr.delivery-main-row input.group-master-checkbox').forEach((groupCb) => {
                groupCb.checked = this.checked;
                toggleGroupedCheckboxes(groupCb.dataset.groupIndex, this.checked);
            });
            document.querySelectorAll('tbody tr.delivery-main-row input[name="selected_deliveries[]"]:not(.group-hidden-checkbox)').forEach((singleCb) => {
                singleCb.checked = this.checked;
            });
            updateBulkActionButtons();
        });
    }

    updateBulkActionButtons();
}

document.addEventListener('DOMContentLoaded', () => {
    associatedPalletsModal = document.getElementById('associatedPalletsModal');
    palletListDiv = document.getElementById('palletList');
    uploadPodModal = document.getElementById('uploadPodModal');
    bulkEditModal = document.getElementById('bulkEditModal');
    receiveToProjectModal = document.getElementById('receiveToProjectModal');

    loadColumnPreferences();

    document.querySelectorAll('.view-pallets-btn').forEach((btn) => {
        btn.addEventListener('click', function onPalletClick() {
            showPalletModal(this);
        });
    });

    document.getElementById('closePalletModalBtn')?.addEventListener('click', closeAssociatedPalletModal);

    document.querySelectorAll('.column-toggle').forEach((cb) => {
        cb.addEventListener('change', () => {
            toggleColumn(cb.dataset.column, cb.checked);
            saveColumnPreferences();
        });
    });

    document.addEventListener('click', (e) => {
        const columnChooser = document.getElementById('columnChooserDropdown');
        if (columnChooser && !e.target.closest('.btn-columns-header') && !columnChooser.contains(e.target)) {
            columnChooser.style.display = 'none';
        }
    });

    window.addEventListener('click', (e) => {
        if (e.target === associatedPalletsModal) {
            closeAssociatedPalletModal();
        }
        if (e.target === uploadPodModal) {
            closeUploadPodModal();
        }
        if (e.target === bulkEditModal) {
            closeBulkEditModal();
        }
        if (e.target === receiveToProjectModal) {
            closeReceiveToProjectModal();
        }
    });

    const uploadPodForm = document.getElementById('uploadPodForm');
    if (uploadPodForm) {
        uploadPodForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitBtn = document.getElementById('uploadPodSubmitBtn');
            const originalLabel = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Uploading...';
            }

            try {
                const formData = new FormData(uploadPodForm);
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (!result || !result.success) {
                    throw new Error((result && result.message) ? result.message : 'Upload failed.');
                }
                closeUploadPodModal();
                window.location.reload();
            } catch (error) {
                alert(error.message || 'Failed to upload POD.');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalLabel || 'Upload POD';
                }
            }
        });
    }

    const hi = document.getElementById('highlighted-delivery');
    if (hi) {
        setTimeout(() => {
            hi.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 500);
    }

    initializeBulkSelection();
    initializePagination();
});
</script>
</body>
</html>
