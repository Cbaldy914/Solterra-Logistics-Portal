<?php
session_name("logistics_session");
session_start();

// Ensure the user is either an admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
    header('Location: unauthorized.php');
    exit();
}

require_once '../config.php';
require_once 'document_helpers.php';
require_once 'milestone_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$errors = [];

// Project capacity tracking variables
$project_size_mw = 0;
$current_ordered_mw = 0;
$remaining_mw = 0;

// If a project is provided, verify access and load it; otherwise enable unassigned flow
$project = null;
if ($project_id) {
    if (in_array($role, ['admin', 'customer_admin'], true)) {
        $stmtAccess = $conn->prepare("
            SELECT p.*, ca.name as account_name
            FROM projects p 
            JOIN customer_accounts ca ON p.account_id = ca.id
            JOIN customer_account_users cau ON p.account_id = cau.account_id 
            WHERE p.id = ? AND cau.user_id = ? AND cau.role IN ('admin', 'customer_admin')
        ");
        $stmtAccess->bind_param("ii", $project_id, $user_id);
    } else {
        $stmtAccess = $conn->prepare("
            SELECT p.*, ca.name as account_name
            FROM projects p 
            JOIN customer_accounts ca ON p.account_id = ca.id
            WHERE p.id = ?
        ");
        $stmtAccess->bind_param("i", $project_id);
    }
    $stmtAccess->execute();
    $result = $stmtAccess->get_result();
    if ($result->num_rows === 0) {
        header('Location: dashboard.php?error=access_denied');
        exit();
    }
    $project = $result->fetch_assoc();
    $stmtAccess->close();

    // Get project size and current MW ordered
    $project_size_mw = floatval($project['project_size'] ?? 0);

    // Calculate current ordered MW from existing modules
    $stmtMw = $conn->prepare("
        SELECT SUM(umi.wattage * umi.quantity) / 1000000 as ordered_mw
        FROM unassigned_module_items umi
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ?
    ");
    $stmtMw->bind_param("i", $project_id);
    $stmtMw->execute();
    $mwResult = $stmtMw->get_result()->fetch_assoc();
    $current_ordered_mw = floatval($mwResult['ordered_mw'] ?? 0);
    $stmtMw->close();

    $remaining_mw = max(0, $project_size_mw - $current_ordered_mw);
}

// For admin/global_admin with no project selected, prepare account/projects for selection
$accounts = [];
$projects_for_account = [];
$account_id_for_admin = null;
if (in_array($role, ['admin', 'customer_admin'], true)) {
    $stmtAdmin = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    $stmtAdmin->bind_param("i", $user_id);
    $stmtAdmin->execute();
    $stmtAdmin->bind_result($account_id_for_admin);
    $stmtAdmin->fetch();
    $stmtAdmin->close();
}
if (!$project && in_array($role, ['admin', 'customer_admin'], true)) {
    if ($account_id_for_admin) {
        $stmtProj = $conn->prepare("SELECT id, project_name, account_id, project_size FROM projects WHERE account_id = ? AND (status IS NULL OR status = 'active') ORDER BY project_name ASC");
        $stmtProj->bind_param("i", $account_id_for_admin);
        $stmtProj->execute();
        $resProj = $stmtProj->get_result();
        while ($row = $resProj->fetch_assoc()) { $projects_for_account[] = $row; }
        $stmtProj->close();
    }
}
if (!$project && $role === 'global_admin') {
    $resAcc = $conn->query("SELECT id, name FROM customer_accounts ORDER BY name ASC");
    if ($resAcc) { while ($r = $resAcc->fetch_assoc()) { $accounts[] = $r; } }
    $resProj = $conn->query("SELECT id, project_name, account_id, project_size FROM projects WHERE (status IS NULL OR status = 'active') ORDER BY account_id, project_name ASC");
    if ($resProj) { while ($r = $resProj->fetch_assoc()) { $projects_for_account[] = $r; } }
}

// Get manufacturers for dropdown
$manufacturers = [];
$stmtManufacturers = $conn->prepare("SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC");
if ($stmtManufacturers) {
    $stmtManufacturers->execute();
    $manufacturersResult = $stmtManufacturers->get_result();
    while ($manufacturer = $manufacturersResult->fetch_assoc()) {
        $manufacturers[] = $manufacturer;
    }
    $stmtManufacturers->close();
}

function syncProjectWattageOrdersForAssignment($conn, $project_id) {
    $project_id = (int)$project_id;
    if ($project_id <= 0) return;

    $conn->begin_transaction();
    try {
        $stmtDelete = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ?");
        if (!$stmtDelete) throw new Exception("Failed preparing project order cleanup: " . $conn->error);
        $stmtDelete->bind_param("i", $project_id);
        $stmtDelete->execute();
        $stmtDelete->close();

        $stmtActual = $conn->prepare("
            SELECT umi.wattage, SUM(umi.quantity) AS total_quantity
            FROM modules m
            JOIN unassigned_module_items umi ON m.id = umi.unassigned_module_id
            WHERE m.project_id = ?
            GROUP BY umi.wattage
        ");
        if (!$stmtActual) throw new Exception("Failed preparing project totals query: " . $conn->error);
        $stmtActual->bind_param("i", $project_id);
        $stmtActual->execute();
        $resultActual = $stmtActual->get_result();

        $stmtInsert = $conn->prepare("INSERT INTO project_wattage_orders (project_id, wattage, total_order) VALUES (?, ?, ?)");
        if (!$stmtInsert) throw new Exception("Failed preparing project order insert: " . $conn->error);

        while ($row = $resultActual->fetch_assoc()) {
            $wattage = (int)$row['wattage'];
            $totalQuantity = (int)$row['total_quantity'];
            $stmtInsert->bind_param("iii", $project_id, $wattage, $totalQuantity);
            $stmtInsert->execute();
        }

        $stmtInsert->close();
        $stmtActual->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

function assignUnassignedBatchToProjectFromAddPage($conn, $batch_id, $target_project_id, $role, $account_id_for_admin) {
    $batch_id = (int)$batch_id;
    $target_project_id = (int)$target_project_id;
    if ($batch_id <= 0 || $target_project_id <= 0) {
        throw new Exception('Please select a valid batch and project.');
    }

    if ($role === 'global_admin') {
        $stmtBatch = $conn->prepare("SELECT id, account_id, project_id, batch_name FROM modules WHERE id = ? LIMIT 1");
        if (!$stmtBatch) throw new Exception("Failed preparing batch lookup: " . $conn->error);
        $stmtBatch->bind_param("i", $batch_id);
    } else {
        if (!$account_id_for_admin) {
            throw new Exception('No valid account found for this admin user.');
        }
        $stmtBatch = $conn->prepare("SELECT id, account_id, project_id, batch_name FROM modules WHERE id = ? AND account_id = ? LIMIT 1");
        if (!$stmtBatch) throw new Exception("Failed preparing batch lookup: " . $conn->error);
        $stmtBatch->bind_param("ii", $batch_id, $account_id_for_admin);
    }
    $stmtBatch->execute();
    $batchRow = $stmtBatch->get_result()->fetch_assoc();
    $stmtBatch->close();

    if (!$batchRow) {
        throw new Exception("Module batch #{$batch_id} not found or access denied.");
    }
    if (!empty($batchRow['project_id'])) {
        throw new Exception("Batch #{$batch_id} is already assigned to a project.");
    }

    $stmtProject = $conn->prepare("SELECT id, account_id, project_name, status FROM projects WHERE id = ? LIMIT 1");
    if (!$stmtProject) throw new Exception("Failed preparing project lookup: " . $conn->error);
    $stmtProject->bind_param("i", $target_project_id);
    $stmtProject->execute();
    $projectRow = $stmtProject->get_result()->fetch_assoc();
    $stmtProject->close();

    if (!$projectRow) {
        throw new Exception('Target project not found.');
    }
    if ((int)$projectRow['account_id'] !== (int)$batchRow['account_id']) {
        throw new Exception("Batch #{$batch_id} does not belong to the selected project's account.");
    }
    if (isset($projectRow['status']) && strtolower((string)$projectRow['status']) === 'closed') {
        throw new Exception('Cannot assign modules to a closed project.');
    }

    $stmtConflicts = $conn->prepare("
        SELECT COUNT(*) AS conflict_count
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN delivery_pallets dp ON dp.inventory_pallet_id = ip.id
        LEFT JOIN deliveries d ON d.id = dp.delivery_id
        WHERE umi.unassigned_module_id = ?
          AND (
              (ip.current_project_id IS NOT NULL AND ip.current_project_id <> ?)
              OR (ip.assigned_project_id IS NOT NULL AND ip.assigned_project_id <> ?)
              OR (d.project_id IS NOT NULL AND d.project_id <> ?)
          )
    ");
    if (!$stmtConflicts) throw new Exception("Failed preparing conflict check: " . $conn->error);
    $stmtConflicts->bind_param("iiii", $batch_id, $target_project_id, $target_project_id, $target_project_id);
    $stmtConflicts->execute();
    $conflictRow = $stmtConflicts->get_result()->fetch_assoc();
    $stmtConflicts->close();
    if ((int)($conflictRow['conflict_count'] ?? 0) > 0) {
        throw new Exception("Batch #{$batch_id} has pallets tied to a different project context.");
    }

    $conn->begin_transaction();
    try {
        $stmtAssignBatch = $conn->prepare("UPDATE modules SET project_id = ?, last_updated_at = NOW() WHERE id = ?");
        if (!$stmtAssignBatch) throw new Exception("Failed preparing module update: " . $conn->error);
        $stmtAssignBatch->bind_param("ii", $target_project_id, $batch_id);
        if (!$stmtAssignBatch->execute()) {
            throw new Exception("Failed assigning module batch #{$batch_id}: " . $stmtAssignBatch->error);
        }
        $stmtAssignBatch->close();

        $stmtAssignPallets = $conn->prepare("
            UPDATE inventory_pallets ip
            JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            SET ip.assigned_project_id = ?
            WHERE umi.unassigned_module_id = ?
        ");
        if (!$stmtAssignPallets) throw new Exception("Failed preparing pallet update: " . $conn->error);
        $stmtAssignPallets->bind_param("ii", $target_project_id, $batch_id);
        if (!$stmtAssignPallets->execute()) {
            throw new Exception("Failed updating pallet assignments for batch #{$batch_id}: " . $stmtAssignPallets->error);
        }
        $updatedPalletRows = (int)$stmtAssignPallets->affected_rows;
        $stmtAssignPallets->close();

        $conn->commit();
        syncProjectWattageOrdersForAssignment($conn, $target_project_id);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'batch_id' => $batch_id,
        'batch_name' => (string)($batchRow['batch_name'] ?? ('Batch #' . $batch_id)),
        'updated_pallet_rows' => $updatedPalletRows
    ];
}

$assign_project_options = [];
if ($project) {
    $assign_project_options[] = [
        'id' => (int)$project['id'],
        'project_name' => (string)$project['project_name'],
        'account_id' => (int)$project['account_id'],
        'project_size' => (float)($project['project_size'] ?? 0)
    ];
} else {
    foreach ($projects_for_account as $proj) {
        $assign_project_options[] = [
            'id' => (int)($proj['id'] ?? 0),
            'project_name' => (string)($proj['project_name'] ?? ''),
            'account_id' => (int)($proj['account_id'] ?? 0),
            'project_size' => (float)($proj['project_size'] ?? 0)
        ];
    }
}

$assign_project_impact_data = [];
foreach ($assign_project_options as $proj) {
    $pid = (int)($proj['id'] ?? 0);
    if ($pid <= 0) continue;
    $projectSizeMw = (float)($proj['project_size'] ?? 0);
    $assign_project_impact_data[$pid] = [
        'project_name' => (string)($proj['project_name'] ?? ('Project #' . $pid)),
        'target_watts' => $projectSizeMw > 0 ? ($projectSizeMw * 1000000) : 0.0,
        'current_modules' => 0,
        'current_watts' => 0.0
    ];
}
if (!empty($assign_project_impact_data)) {
    $impactProjectIds = array_keys($assign_project_impact_data);
    $placeholdersImpact = implode(',', array_fill(0, count($impactProjectIds), '?'));
    $typesImpact = str_repeat('i', count($impactProjectIds));
    $stmtCurrentImpact = $conn->prepare("
        SELECT
            m.project_id,
            COALESCE(SUM(umi.quantity), 0) AS current_modules,
            COALESCE(SUM(umi.wattage * umi.quantity), 0) AS current_watts
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.project_id IN ($placeholdersImpact)
        GROUP BY m.project_id
    ");
    if ($stmtCurrentImpact) {
        $stmtCurrentImpact->bind_param($typesImpact, ...$impactProjectIds);
        $stmtCurrentImpact->execute();
        $resCurrentImpact = $stmtCurrentImpact->get_result();
        while ($row = $resCurrentImpact->fetch_assoc()) {
            $pid = (int)($row['project_id'] ?? 0);
            if (!isset($assign_project_impact_data[$pid])) continue;
            $assign_project_impact_data[$pid]['current_modules'] = (int)($row['current_modules'] ?? 0);
            $assign_project_impact_data[$pid]['current_watts'] = (float)($row['current_watts'] ?? 0);
        }
        $stmtCurrentImpact->close();
    }
}

$assignable_unassigned_batches = [];
$assignAccountFilterId = 0;
if ($project) {
    $assignAccountFilterId = (int)($project['account_id'] ?? 0);
} elseif (in_array($role, ['admin', 'customer_admin'], true)) {
    $assignAccountFilterId = (int)($account_id_for_admin ?? 0);
}

$sqlAssignable = "
    SELECT
        m.id,
        m.account_id,
        m.batch_name,
        m.vendor_name,
        m.initial_location,
        ca.name AS account_name,
        COALESCE(SUM(umi.quantity), 0) AS total_quantity,
        COALESCE(SUM(umi.wattage * umi.quantity), 0) AS total_watts
    FROM modules m
    JOIN customer_accounts ca ON ca.id = m.account_id
    LEFT JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
    WHERE m.project_id IS NULL
";
if ($assignAccountFilterId > 0) {
    $sqlAssignable .= " AND m.account_id = ?";
}
$sqlAssignable .= " GROUP BY m.id ORDER BY ca.name ASC, m.vendor_name ASC, m.id DESC";

$stmtAssignable = $conn->prepare($sqlAssignable);
if ($stmtAssignable) {
    if ($assignAccountFilterId > 0) {
        $stmtAssignable->bind_param("i", $assignAccountFilterId);
    }
    $stmtAssignable->execute();
    $resAssignable = $stmtAssignable->get_result();
    while ($row = $resAssignable->fetch_assoc()) {
        $assignable_unassigned_batches[] = $row;
    }
    $stmtAssignable->close();
}

if (!empty($assignable_unassigned_batches)) {
    $assignBatchIds = array_map(function($b) { return (int)$b['id']; }, $assignable_unassigned_batches);
    $assignBatchIds = array_values(array_filter($assignBatchIds, function($id) { return $id > 0; }));
    if (!empty($assignBatchIds)) {
        $itemsByBatchId = [];
        $placeholders = implode(',', array_fill(0, count($assignBatchIds), '?'));
        $types = str_repeat('i', count($assignBatchIds));
        $stmtAssignItems = $conn->prepare("
            SELECT
                unassigned_module_id,
                wattage,
                quantity,
                domestic_content_pct
            FROM unassigned_module_items
            WHERE unassigned_module_id IN ($placeholders)
            ORDER BY unassigned_module_id ASC, wattage ASC
        ");
        if ($stmtAssignItems) {
            $stmtAssignItems->bind_param($types, ...$assignBatchIds);
            $stmtAssignItems->execute();
            $resAssignItems = $stmtAssignItems->get_result();
            while ($itemRow = $resAssignItems->fetch_assoc()) {
                $bid = (int)($itemRow['unassigned_module_id'] ?? 0);
                if ($bid <= 0) continue;
                if (!isset($itemsByBatchId[$bid])) $itemsByBatchId[$bid] = [];
                $itemsByBatchId[$bid][] = $itemRow;
            }
            $stmtAssignItems->close();
        }

        foreach ($assignable_unassigned_batches as $idx => $batch) {
            $bid = (int)($batch['id'] ?? 0);
            $assignable_unassigned_batches[$idx]['items'] = $itemsByBatchId[$bid] ?? [];
        }
    }
}

// Handle in-page assignment of existing unassigned batches
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'assign_unassigned_batches')) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $target_project_id = isset($_POST['assign_project_id']) ? (int)$_POST['assign_project_id'] : 0;
    if ($target_project_id <= 0 && $project_id > 0) {
        $target_project_id = (int)$project_id;
    }

    $rawBatchIds = trim((string)($_POST['assign_batch_ids'] ?? ''));
    $batchIds = [];
    if ($rawBatchIds !== '') {
        foreach (explode(',', $rawBatchIds) as $token) {
            $bid = (int)trim($token);
            if ($bid > 0) $batchIds[] = $bid;
        }
    }
    $batchIds = array_values(array_unique($batchIds));

    try {
        if ($target_project_id <= 0) {
            throw new Exception('Please select a project for assignment.');
        }
        if (empty($batchIds)) {
            throw new Exception('Select at least one unassigned batch.');
        }

        $assignedCount = 0;
        $updatedPalletRows = 0;
        $assignedBatchNames = [];

        foreach ($batchIds as $bid) {
            $result = assignUnassignedBatchToProjectFromAddPage($conn, $bid, $target_project_id, $role, $account_id_for_admin);
            $assignedCount++;
            $updatedPalletRows += (int)($result['updated_pallet_rows'] ?? 0);
            $assignedBatchNames[] = (string)($result['batch_name'] ?? ('Batch #' . $bid));
        }

        $response = [
            'success' => true,
            'message' => 'Assigned ' . $assignedCount . ' unassigned batch(es) to the selected project.',
            'assigned_count' => $assignedCount,
            'updated_pallet_rows' => $updatedPalletRows,
            'assigned_batches' => $assignedBatchNames
        ];

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            $conn->close();
            exit();
        }
    } catch (Exception $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
            $conn->close();
            exit();
        }
        $errors = [$e->getMessage()];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') !== 'assign_unassigned_batches')) {
    // Detect AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // Allow selecting project on page
    if (!$project_id) {
        $project_id = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? intval($_POST['project_id']) : 0;
        if ($project_id) {
            // Load for account id
            $stmtP = $conn->prepare("SELECT account_id, project_name FROM projects WHERE id = ?");
            $stmtP->bind_param("i", $project_id);
            $stmtP->execute();
            $stmtP->bind_result($proj_acc_id, $proj_name);
            if ($stmtP->fetch()) {
                $project = ['id'=>$project_id, 'account_id'=>$proj_acc_id, 'project_name'=>$proj_name];
            }
            $stmtP->close();
        }
    }
    // Derive vendor/location from manufacturer + location selection (align with add_project Initial Module Batch)
    $batch_name = isset($_POST['batch_name']) && trim($_POST['batch_name']) !== '' ? trim($_POST['batch_name']) : null;
    $manufacturer_id = isset($_POST['manufacturer_id']) && $_POST['manufacturer_id'] !== '' ? (int)$_POST['manufacturer_id'] : null;
    $location_id = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;
    $vendor_name = '';
    $initial_location = '';
    $modules_per_pallet = isset($_POST['modules_per_pallet']) && $_POST['modules_per_pallet'] !== '' ? intval($_POST['modules_per_pallet']) : null;
    $pallets_per_truck = isset($_POST['pallets_per_truck']) && $_POST['pallets_per_truck'] !== '' ? intval($_POST['pallets_per_truck']) : null;
    $modules_per_truck = isset($_POST['modules_per_truck']) && $_POST['modules_per_truck'] !== '' ? intval($_POST['modules_per_truck']) : null;
    $pallet_length_mm = isset($_POST['pallet_length_mm']) && $_POST['pallet_length_mm'] !== '' ? intval($_POST['pallet_length_mm']) : null;
    $pallet_depth_mm = isset($_POST['pallet_depth_mm']) && $_POST['pallet_depth_mm'] !== '' ? intval($_POST['pallet_depth_mm']) : null;
    $pallet_double_stacked_height_mm = isset($_POST['pallet_double_stacked_height_mm']) && $_POST['pallet_double_stacked_height_mm'] !== '' ? intval($_POST['pallet_double_stacked_height_mm']) : null;
    $pallet_total_weight_kg = isset($_POST['pallet_total_weight_kg']) && $_POST['pallet_total_weight_kg'] !== '' ? intval($_POST['pallet_total_weight_kg']) : null;
    $stacking_in_warehouse = trim($_POST['stacking_in_warehouse'] ?? '');
    $stacking_during_transport = trim($_POST['stacking_during_transport'] ?? '');
    $forklift_truck_long_side_mm = isset($_POST['forklift_truck_long_side_mm']) && $_POST['forklift_truck_long_side_mm'] !== '' ? intval($_POST['forklift_truck_long_side_mm']) : null;
    $forklift_truck_short_side_mm = isset($_POST['forklift_truck_short_side_mm']) && $_POST['forklift_truck_short_side_mm'] !== '' ? intval($_POST['forklift_truck_short_side_mm']) : null;
    $pallet_jack_long_side_mm = isset($_POST['pallet_jack_long_side_mm']) && $_POST['pallet_jack_long_side_mm'] !== '' ? intval($_POST['pallet_jack_long_side_mm']) : null;
    $pallet_jack_short_side_mm = isset($_POST['pallet_jack_short_side_mm']) && $_POST['pallet_jack_short_side_mm'] !== '' ? intval($_POST['pallet_jack_short_side_mm']) : null;
    $module_notes = trim($_POST['module_notes'] ?? '');
    $cost_per_watt = isset($_POST['cost_per_watt']) && $_POST['cost_per_watt'] !== '' ? floatval($_POST['cost_per_watt']) : null;
    $po_execution_date = isset($_POST['po_execution_date']) && $_POST['po_execution_date'] !== '' ? trim($_POST['po_execution_date']) : null;

    // Build vendor/location defaults
    if ($manufacturer_id) {
        if ($stmtM = $conn->prepare("SELECT name FROM manufacturers WHERE id = ?")) {
            $stmtM->bind_param("i", $manufacturer_id);
            $stmtM->execute();
            $stmtM->bind_result($mname);
            if ($stmtM->fetch()) { $vendor_name = $mname; }
            $stmtM->close();
        }
        if ($location_id && ($stmtL = $conn->prepare("SELECT ml.street_address, ml.city, ml.state, ml.zip_code FROM manufacturer_locations ml JOIN manufacturers m ON m.id = ml.manufacturer_id WHERE ml.id = ?"))) {
            $stmtL->bind_param("i", $location_id);
            $stmtL->execute();
            $stmtL->bind_result($st, $ci, $stt, $zip);
            if ($stmtL->fetch()) { $initial_location = implode(', ', array_filter([$st, $ci, $stt, $zip])); }
            $stmtL->close();
        } else {
            // Fallback to primary location
            if ($stmtL2 = $conn->prepare("SELECT ml.street_address, ml.city, ml.state, ml.zip_code FROM manufacturer_locations ml JOIN manufacturers m ON m.id = ml.manufacturer_id WHERE ml.manufacturer_id = ? AND ml.is_primary = TRUE LIMIT 1")) {
                $stmtL2->bind_param("i", $manufacturer_id);
                $stmtL2->execute();
                $stmtL2->bind_result($st2, $ci2, $stt2, $zip2);
                if ($stmtL2->fetch()) { $initial_location = implode(', ', array_filter([$st2, $ci2, $stt2, $zip2])); }
                $stmtL2->close();
            }
        }
    }
    if ($vendor_name === '') { $vendor_name = 'Unknown Manufacturer'; }
    if ($initial_location === '') {
        $initial_location = implode(', ', array_filter([$project['street_address'] ?? '', $project['city'] ?? '', $project['state'] ?? '', $project['zip_code'] ?? '']));
    }
    if ($initial_location === '') { $initial_location = 'Unassigned'; }

    // Validate required fields
    $errors = [];
    $posted_milestones = $_POST['milestones'] ?? [];
    if (milestone_requires_po_execution_date($posted_milestones) && empty($po_execution_date)) {
        $errors[] = 'PO Execution date is required when a PO Execution milestone is configured.';
    }
    
    // Validate wattages and quantities
    if (!isset($_POST['wattages']) || !isset($_POST['quantities']) || empty($_POST['wattages'])) {
        $errors[] = 'At least one wattage and quantity is required';
    } else {
        $wattages = $_POST['wattages'];
        $quantities = $_POST['quantities'];
        $domestic_content_pcts = $_POST['domestic_content_pcts'] ?? [];
        $track_domestic_content = !empty($_POST['track_domestic_content']);
        
        if (count($wattages) !== count($quantities)) {
            $errors[] = 'Wattage and quantity arrays must match';
        } else {
            for ($i = 0; $i < count($wattages); $i++) {
                $wattage_str = trim($wattages[$i]);
                $quantity_str = trim($quantities[$i]);
                
                // Skip empty entries
                if (empty($wattage_str) && empty($quantity_str)) {
                    continue;
                }
                
                // Check if values are numeric and positive
                if (!is_numeric($wattage_str) || !is_numeric($quantity_str)) {
                    $errors[] = 'All wattages and quantities must be positive integers';
                    break;
                }
                
                $wattage = intval($wattage_str);
                $quantity = intval($quantity_str);
                
                if ($wattage <= 0 || $quantity <= 0) {
                    $errors[] = 'All wattages and quantities must be positive integers';
                    break;
                }

                if ($track_domestic_content) {
                    $pctRaw = trim((string)($domestic_content_pcts[$i] ?? ''));
                    if ($pctRaw === '' || !is_numeric($pctRaw)) {
                        $errors[] = 'Domestic Content % is required and must be numeric when tracking is enabled.';
                        break;
                    }
                    $pctVal = (float)$pctRaw;
                    if ($pctVal < 0 || $pctVal > 100) {
                        $errors[] = 'Domestic Content % must be between 0 and 100.';
                        break;
                    }
                }
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->begin_transaction();
            
            // Insert new module batch
            $stmtInsert = $conn->prepare("
                INSERT INTO modules (
                    batch_name, account_id, vendor_name, initial_location, project_id, modules_per_pallet,
                    pallets_per_truck, modules_per_truck, pallet_length_mm, pallet_depth_mm,
                    pallet_double_stacked_height_mm, pallet_total_weight_kg, stacking_in_warehouse,
                    stacking_during_transport, forklift_truck_long_side_mm, forklift_truck_short_side_mm,
                    pallet_jack_long_side_mm, pallet_jack_short_side_mm, module_notes, module_docs_url, cost_per_watt, po_execution_date
                ) VALUES (?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)
            ");
            // Determine account for insert
            $insert_account_id = 0;
            if (!empty($project['account_id'])) {
                $insert_account_id = (int)$project['account_id'];
            } elseif (in_array($role, ['admin', 'customer_admin'], true) && $account_id_for_admin) {
                $insert_account_id = (int)$account_id_for_admin;
            } elseif ($role === 'global_admin') {
                $insert_account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
            }
            if ($insert_account_id <= 0) {
                throw new Exception('Please select a valid account (or project).');
            }

            $stmtInsert->bind_param("sissiiiiiiiissiiiisds",
                $batch_name, $insert_account_id, $vendor_name, $initial_location, $project_id, $modules_per_pallet,
                $pallets_per_truck, $modules_per_truck, $pallet_length_mm, $pallet_depth_mm,
                $pallet_double_stacked_height_mm, $pallet_total_weight_kg, $stacking_in_warehouse,
                $stacking_during_transport, $forklift_truck_long_side_mm, $forklift_truck_short_side_mm,
                $pallet_jack_long_side_mm, $pallet_jack_short_side_mm, $module_notes, $cost_per_watt, $po_execution_date
            );
            
            if (!$stmtInsert->execute()) {
                throw new Exception("Error creating module batch: " . $stmtInsert->error);
            }
            
            $module_id = $conn->insert_id;
            $stmtInsert->close();

            // Ensure logistics specs are stored (including derived values)
            $mpp_val = $modules_per_pallet ?? 0;
            $ppt_val = $pallets_per_truck ?? 0;
            $mpt_val = $modules_per_truck ?? 0;
            $stmtLog = $conn->prepare("UPDATE modules SET modules_per_pallet = ?, pallets_per_truck = ?, modules_per_truck = ? WHERE id = ?");
            if ($stmtLog) {
                $stmtLog->bind_param("iiii", $mpp_val, $ppt_val, $mpt_val, $module_id);
                $stmtLog->execute();
                $stmtLog->close();
            }
            
            // Insert wattage items
            $stmtWattagesWithDomestic = $conn->prepare("
                INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity, domestic_content_pct) 
                VALUES (?, ?, ?, ?)
            ");
            $stmtWattagesNoDomestic = $conn->prepare("
                INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) 
                VALUES (?, ?, ?)
            ");
            
            for ($i = 0; $i < count($wattages); $i++) {
                $wattage = intval(trim($wattages[$i]));
                $quantity = intval(trim($quantities[$i]));
                $domesticContentPct = null;
                if (!empty($track_domestic_content)) {
                    $pctRaw = trim((string)($domestic_content_pcts[$i] ?? ''));
                    if ($pctRaw !== '' && is_numeric($pctRaw)) {
                        $domesticContentPct = max(0, min(100, (float)$pctRaw));
                    }
                }
                // Skip empty or zero entries defensively
                if ($wattage <= 0 || $quantity <= 0) { continue; }
                
                if (!empty($track_domestic_content)) {
                    $stmtWattagesWithDomestic->bind_param("iiid", $module_id, $wattage, $quantity, $domesticContentPct);
                    if (!$stmtWattagesWithDomestic->execute()) {
                        throw new Exception("Error adding wattage item: " . $stmtWattagesWithDomestic->error);
                    }
                } else {
                    $stmtWattagesNoDomestic->bind_param("iii", $module_id, $wattage, $quantity);
                    if (!$stmtWattagesNoDomestic->execute()) {
                        throw new Exception("Error adding wattage item: " . $stmtWattagesNoDomestic->error);
                    }
                }
            }
            $stmtWattagesWithDomestic->close();
            $stmtWattagesNoDomestic->close();
            
            // Update project_wattage_orders for project size calculation (only if assigned to a project)
            if ($project_id > 0) {
                $stmtOrders = $conn->prepare("
                    INSERT INTO project_wattage_orders (project_id, wattage, total_order) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE total_order = total_order + VALUES(total_order)
                ");
                
                for ($i = 0; $i < count($wattages); $i++) {
                    $wattage = intval(trim($wattages[$i]));
                    $quantity = intval(trim($quantities[$i]));
                    // Skip empty or zero entries defensively
                    if ($wattage <= 0 || $quantity <= 0) { continue; }
                    
                    $stmtOrders->bind_param("iii", $project_id, $wattage, $quantity);
                    if (!$stmtOrders->execute()) {
                        throw new Exception("Error updating project wattage orders: " . $stmtOrders->error);
                    }
                }
                $stmtOrders->close();
                // Clean up zero or negative entries for this project, if any existed
                $stmtCleanup = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ? AND total_order <= 0");
                if ($stmtCleanup) {
                    $stmtCleanup->bind_param("i", $project_id);
                    $stmtCleanup->execute();
                    $stmtCleanup->close();
                }
            }

            // Auto-palletization - create pallets if enabled
            $enable_palletization = isset($_POST['enable_palletization']) && $_POST['enable_palletization'] === '1';
            $auto_modules_per_pallet = isset($_POST['auto_modules_per_pallet']) && $_POST['auto_modules_per_pallet'] !== '' ? intval($_POST['auto_modules_per_pallet']) : 0;

            // Harmonize pallet sizing values
            if ($enable_palletization && $auto_modules_per_pallet <= 0 && !empty($modules_per_pallet)) {
                $auto_modules_per_pallet = (int)$modules_per_pallet;
            }
            // If neither auto nor logistics pallet count was supplied, treat as 0 for validation and later update
            if ($modules_per_pallet === null && $auto_modules_per_pallet > 0) {
                $modules_per_pallet = $auto_modules_per_pallet;
            }

            // Derive modules_per_truck when possible
            if ((int)$modules_per_truck === 0 && (int)$pallets_per_truck > 0 && (int)$modules_per_pallet > 0) {
                $modules_per_truck = (int)$pallets_per_truck * (int)$modules_per_pallet;
            }

            $pallets_created = 0;
            if ($enable_palletization) {
                if ($auto_modules_per_pallet <= 0) {
                    throw new Exception('Auto-palletization is enabled but Modules per Pallet is missing or zero.');
                }
            }

            if ($enable_palletization && $auto_modules_per_pallet > 0) {
                // Get the unassigned_module_items we just created
                $stmtItems = $conn->prepare("
                    SELECT id, wattage, quantity
                    FROM unassigned_module_items
                    WHERE unassigned_module_id = ?
                ");
                $stmtItems->bind_param("i", $module_id);
                $stmtItems->execute();
                $itemsResult = $stmtItems->get_result();

                // Also update modules_per_pallet in the modules table if not already set
                if ($modules_per_pallet === null || $modules_per_pallet === 0) {
                    $stmtUpdateMpp = $conn->prepare("UPDATE modules SET modules_per_pallet = ? WHERE id = ?");
                    $stmtUpdateMpp->bind_param("ii", $auto_modules_per_pallet, $module_id);
                    $stmtUpdateMpp->execute();
                    $stmtUpdateMpp->close();
                }

                // Get the next pallet number for auto-generated IDs
                $stmtMaxPallet = $conn->query("SELECT MAX(CAST(SUBSTRING(pallet_identifier, 5) AS UNSIGNED)) as max_num FROM inventory_pallets WHERE pallet_identifier LIKE 'PAL-%'");
                $maxRow = $stmtMaxPallet->fetch_assoc();
                $nextPalletNum = ($maxRow['max_num'] ?? 0) + 1;

                $defaultStatus = 'At Manufacturer';

                // Handle NULL location_id properly
                $loc_id_for_insert = $location_id !== null ? $location_id : null;
                $assigned_project_id_for_insert = $project_id > 0 ? $project_id : null;

                while ($item = $itemsResult->fetch_assoc()) {
                    $item_id = $item['id'];
                    $item_wattage = $item['wattage'];
                    $item_quantity = $item['quantity'];

                    // Calculate how many full pallets and partial pallet
                    $full_pallets = floor($item_quantity / $auto_modules_per_pallet);
                    $remaining_modules = $item_quantity % $auto_modules_per_pallet;

                    // Create full pallets
                    for ($p = 0; $p < $full_pallets; $p++) {
                        $pallet_id = 'PAL-' . str_pad($nextPalletNum++, 4, '0', STR_PAD_LEFT);

                        // Use direct query to handle NULL properly
                        $stmtPallet = $conn->prepare("
                            INSERT INTO inventory_pallets (
                                pallet_identifier, unassigned_module_item_id, wattage, quantity,
                                status, manufacturer, manufacturer_location_id, assigned_project_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmtPallet->bind_param("siiissii",
                            $pallet_id, $item_id, $item_wattage, $auto_modules_per_pallet,
                            $defaultStatus, $vendor_name, $loc_id_for_insert, $assigned_project_id_for_insert
                        );
                        if (!$stmtPallet->execute()) {
                            throw new Exception("Error creating pallet: " . $stmtPallet->error);
                        }
                        $stmtPallet->close();
                        $pallets_created++;
                    }

                    // Create partial pallet if there are remaining modules
                    if ($remaining_modules > 0) {
                        $pallet_id = 'PAL-' . str_pad($nextPalletNum++, 4, '0', STR_PAD_LEFT);

                        $stmtPallet = $conn->prepare("
                            INSERT INTO inventory_pallets (
                                pallet_identifier, unassigned_module_item_id, wattage, quantity,
                                status, manufacturer, manufacturer_location_id, assigned_project_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmtPallet->bind_param("siiissii",
                            $pallet_id, $item_id, $item_wattage, $remaining_modules,
                            $defaultStatus, $vendor_name, $loc_id_for_insert, $assigned_project_id_for_insert
                        );
                        if (!$stmtPallet->execute()) {
                            throw new Exception("Error creating partial pallet: " . $stmtPallet->error);
                        }
                        $stmtPallet->close();
                        $pallets_created++;
                    }
                }

                $stmtItems->close();
            }

            // If documentation files are provided and a project is selected, save them to project_documents
            $hasDocs = isset($_FILES['module_docs']) && (
                (is_array($_FILES['module_docs']['error']) && count(array_filter($_FILES['module_docs']['error'], fn($e)=>$e!==UPLOAD_ERR_NO_FILE))>0) ||
                (!is_array($_FILES['module_docs']['error']) && $_FILES['module_docs']['error'] !== UPLOAD_ERR_NO_FILE)
            );
            if ($hasDocs) {
                $module_docs_sub_type = trim($_POST['module_docs_sub_type'] ?? '');
                $module_docs_description = trim($_POST['module_docs_description'] ?? '');
                $names = (array)$_FILES['module_docs']['name'];
                $tmps  = (array)$_FILES['module_docs']['tmp_name'];
                $errs  = (array)$_FILES['module_docs']['error'];
                $sizes = (array)$_FILES['module_docs']['size'];
                $firstSaved = '';
                foreach ($names as $i => $orig) {
                    if (!isset($errs[$i]) || $errs[$i] === UPLOAD_ERR_NO_FILE) continue;
                    if ($errs[$i] !== UPLOAD_ERR_OK) {
                        throw new Exception('Module document upload error for file: ' . $orig);
                    }
                    $tmpName = $tmps[$i];
                    $mime    = mime_content_type($tmpName);
                    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed_ext = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','txt','csv'];
                    if (!in_array($ext, $allowed_ext)) {
                        throw new Exception('Invalid documentation file type: ' . $ext);
                    }
                    if ($project_id > 0) {
                        if ($module_docs_sub_type === '') {
                            throw new Exception('Please choose a Module Documentation Sub-Type when uploading files.');
                        }
                        $doc = [
                            'project_id' => $project_id,
                            'document_type' => 'modules',
                            'document_sub_type' => $module_docs_sub_type,
                            'original_name' => $orig,
                            'file_size' => $sizes[$i],
                            'mime_type' => $mime,
                            'uploaded_by' => $user_id,
                            'tmp_name' => $tmpName,
                            'description' => ($module_docs_description !== '' ? $module_docs_description : 'Module Documentation'),
                            'module_id' => $module_id
                        ];
                        saveDocumentToProjectDocuments($conn, $doc);
                    } else {
                        // Save under batch folder and link first file to modules.module_docs_url
                        $baseDir = 'uploads/module_batches/' . $module_id . '/';
                        if (!is_dir($baseDir)) { @mkdir($baseDir, 0755, true); }
                        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
                        $server = time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeName . '.' . $ext;
                        $dest = $baseDir . $server;
                        if (!move_uploaded_file($tmpName, $dest)) {
                            throw new Exception('Failed to store documentation file: ' . $orig);
                        }
                        if ($firstSaved === '') { $firstSaved = $dest; }
                    }
                }
                if ($project_id <= 0 && $firstSaved !== '') {
                    $stmtUpd = $conn->prepare("UPDATE modules SET module_docs_url = ? WHERE id = ?");
                    if ($stmtUpd) {
                        $stmtUpd->bind_param("si", $firstSaved, $module_id);
                        $stmtUpd->execute();
                        $stmtUpd->close();
                    }
                }
            }

            $conn->commit();

            // Save milestones
            save_module_milestones($module_id, $posted_milestones, $conn, $user_id);

            // Calculate total modules for the response
            $total_modules = 0;
            for ($i = 0; $i < count($wattages); $i++) {
                $qty = intval(trim($quantities[$i]));
                if ($qty > 0) $total_modules += $qty;
            }

            // Return JSON for AJAX requests
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Module batch created successfully!',
                    'module_id' => $module_id,
                    'pallets_created' => $pallets_created,
                    'total_modules' => $total_modules,
                    'project_id' => $project_id,
                    'redirect_url' => $project_id ? "project_overview.php?project_id={$project_id}" : 'modules.php'
                ]);
                $conn->close();
                exit();
            }

            // Build redirect URL with success message including pallets count (non-AJAX fallback)
            $success_msg = 'batch_added';
            if ($pallets_created > 0) {
                $success_msg = 'batch_added&pallets_created=' . $pallets_created;
            }
            $redirect = $project_id ? ("project_overview.php?project_id={$project_id}&success={$success_msg}") : 'modules.php?success=batch_added';
            header("Location: $redirect");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error creating module batch: " . $e->getMessage();

            // Return JSON error for AJAX requests
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => $error_message
                ]);
                $conn->close();
                exit();
            }

            $errors[] = $error_message;
        }
    } else {
        // Validation errors - return JSON for AJAX
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => implode(', ', $errors)
            ]);
            $conn->close();
            exit();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Module Batch<?php echo $project ? (' - ' . htmlspecialchars($project['project_name'])) : ''; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <?php include __DIR__ . '/components/module_batch_styles.php'; ?>
    <style>
        .form-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .form-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .header-content {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 24px;
        }
        .header-left { display: flex; align-items: center; gap: 24px; }
        .header-info h1 {
            font-size: 2.5em; font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; margin: 0 0 8px 0; line-height: 1.2;
        }
        .header-subtitle { color: #6c757d; font-size: 1.1em; font-weight: 500; margin: 0; }
        .error-message {
            background: #f8d7da; color: #721c24; padding: 15px;
            border-radius: 8px; margin-bottom: 20px; border: 1px solid #f5c6cb;
        }
        @media (max-width: 768px) {
            .form-header { padding: 20px; margin-bottom: 20px; }
            .header-content { gap: 16px; }
            .header-left { gap: 16px; }
            .header-info h1 { font-size: 2em; }
            .header-subtitle { font-size: 1em; }
        }
        .assign-batch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 14px;
        }
        .assign-batch-card {
            display: block;
            border: 1px solid #dbe6ec;
            border-radius: 12px;
            padding: 13px;
            background: #fff;
            cursor: pointer;
            box-shadow: 0 1px 6px rgba(0,0,0,0.04);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
        }
        .assign-batch-card:hover {
            border-color: #a9c8d2;
            box-shadow: 0 4px 14px rgba(58,112,134,0.12);
            transform: translateY(-1px);
        }
        .assign-batch-card.is-selected {
            border-color: #488C9A;
            box-shadow: 0 0 0 2px rgba(72,140,154,0.2), 0 6px 18px rgba(72,140,154,0.18);
            background: #f8fcfe;
        }
        .assign-batch-card-inner {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .assign-batch-content {
            flex: 1;
            min-width: 0;
        }
        .assign-batch-top {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .assign-batch-name {
            font-weight: 700;
            color: #1f2937;
        }
        .assign-batch-meta {
            font-size: 0.82rem;
            color: #64748b;
        }
        .assign-batch-totals {
            text-align: right;
        }
        .assign-batch-modules {
            font-size: 0.86rem;
            color: #334155;
            font-weight: 600;
        }
        .assign-batch-mw {
            font-size: 0.82rem;
            color: #64748b;
        }
        .assign-batch-mix-title {
            font-size: 0.78rem;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .assign-batch-mix {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .assign-batch-chip {
            display: inline-block;
            background: #f8fbfd;
            border: 1px solid #d6e5ea;
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 0.78rem;
            color: #334155;
        }
        .assign-batch-chip-empty {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .assign-footer-row {
            display: flex;
            justify-content: flex-end;
            margin-top: 14px;
        }
        .loading-modal {
            display: none; position: fixed; z-index: 2000;
            left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        .loading-content {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%); background: white;
            padding: 40px 50px; border-radius: 16px; text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        .loading-content h3 { margin: 0 0 8px 0; color: #293E4C; font-size: 1.3em; }
        .loading-content p { margin: 0; color: #6c757d; font-size: 0.95em; }
        .spinner {
            border: 4px solid #f3f3f3; border-top: 4px solid #488C9A;
            border-radius: 50%; width: 50px; height: 50px;
            animation: spin 1s linear infinite; margin: 0 auto 20px auto;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <?php require_once 'components/breadcrumbs.php'; ?>

    <main>
        <?php
            $extra = [];
            if (!$project_id) { $extra[] = ['label' => 'Modules', 'url' => 'modules.php']; }
            echo slp_render_breadcrumbs(['current_label' => 'Add Module Batch', 'extra' => $extra]);
        ?>

        <div class="form-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-info">
                        <h1>Add Module Batch</h1>
                        <p class="header-subtitle">
                            <?php if ($project): ?>
                                Adding modules to <?php echo htmlspecialchars($project['project_name']); ?>
                            <?php else: ?>
                                Create a new module batch for assignment
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="entry-method-toggle" style="display: flex; background: #f0f0f0; border-radius: 10px; padding: 4px; gap: 4px;">
                    <label class="method-option" style="padding: 10px 20px; border-radius: 8px; cursor: pointer; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); transition: all 0.2s ease; display: flex; align-items: center; gap: 8px; white-space: nowrap;">
                        <input type="radio" name="entry_method" value="manual" checked style="display: none;">
                        <i class="fas fa-edit" style="color: #488C9A;"></i>
                        <span style="font-weight: 600; color: #293E4C; font-size: 0.9rem;">Manual Entry</span>
                    </label>
                    <label class="method-option" style="padding: 10px 20px; border-radius: 8px; cursor: pointer; background: transparent; transition: all 0.2s ease; display: flex; align-items: center; gap: 8px; white-space: nowrap;">
                        <input type="radio" name="entry_method" value="assign" style="display: none;">
                        <i class="fas fa-link" style="color: #6c757d;"></i>
                        <span style="font-weight: 500; color: #6c757d; font-size: 0.9rem;">Assign Unassigned</span>
                    </label>
                    <label class="method-option" style="padding: 10px 20px; border-radius: 8px; cursor: pointer; background: transparent; transition: all 0.2s ease; display: flex; align-items: center; gap: 8px; white-space: nowrap;">
                        <input type="radio" name="entry_method" value="import" style="display: none;">
                        <i class="fas fa-file-import" style="color: #6c757d;"></i>
                        <span style="font-weight: 500; color: #6c757d; font-size: 0.9rem;">Import Pallets</span>
                    </label>
                </div>
            </div>
        </div>

        <?php if ($project && $project_size_mw > 0): ?>
        <div id="capacityBanner" style="background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%); border: 1px solid #b8daff; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
            <h3 style="margin: 0 0 16px 0; color: #0056b3; font-size: 1.1em; display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.2em;">&#9889;</span> Project Capacity Status
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                    <div style="font-size: 1.6rem; font-weight: 700; color: #488C9A;"><?php echo number_format($current_ordered_mw, 2); ?> MW</div>
                    <div style="font-size: 0.85rem; color: #6c757d;">Currently Ordered</div>
                </div>
                <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                    <div style="font-size: 1.6rem; font-weight: 700; color: #293E4C;"><?php echo number_format($project_size_mw, 2); ?> MW</div>
                    <div style="font-size: 0.85rem; color: #6c757d;">Project Target</div>
                </div>
                <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                    <div style="font-size: 1.6rem; font-weight: 700; color: <?php echo $remaining_mw > 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($remaining_mw, 2); ?> MW</div>
                    <div style="font-size: 0.85rem; color: #6c757d;">Remaining Capacity</div>
                </div>
            </div>
            <?php $capacity_pct = $project_size_mw > 0 ? min(100, ($current_ordered_mw / $project_size_mw) * 100) : 0; ?>
            <div style="background: #e9ecef; border-radius: 8px; height: 20px; overflow: hidden;">
                <div style="background: linear-gradient(90deg, #488C9A 0%, #3a7086 100%); height: 100%; width: <?php echo $capacity_pct; ?>%; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 11px; font-weight: 600;">
                    <?php echo number_format($capacity_pct, 1); ?>%
                </div>
            </div>
            <div id="newMwPreview" style="display: none; margin-top: 16px; padding: 12px; background: white; border-radius: 8px; border: 2px solid #ffc107;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: #856404; font-weight: 500;">New Total After This Batch:</span>
                    <span id="newTotalMw" style="font-weight: 700; color: #856404;">0.00 MW</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div id="manualEntryContainer">
            <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="addBatchForm" enctype="multipart/form-data">
                <?php if (!$project): ?>
                <div style="background: #fff; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
                    <h3 style="margin: 0 0 16px 0; color: #293E4C; font-size: 1.1em;">Assignment</h3>
                    <div class="form-row">
                        <?php if ($role === 'global_admin'): ?>
                        <div class="form-group">
                            <label for="account_id">Account (required if no project)</label>
                            <select name="account_id" id="account_id" required>
                                <option value="">Select Account</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?php echo (int)$acc['id']; ?>"><?php echo htmlspecialchars($acc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label for="project_id">Assign to Project (optional)</label>
                            <select name="project_id" id="project_id">
                                <option value="">-- None (Unassigned) --</option>
                                <?php foreach ($projects_for_account as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['project_name']); ?><?php if (!empty($p['account_id'])) echo ' (Acct '.$p['account_id'].')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php $prefManufacturerId = null; $prefLocationId = null; $existingWattages = []; $module = []; $existingMilestones = []; ?>

                <!-- Step Indicator -->
                <div class="step-indicator-wrapper">
                    <div class="step-indicator">
                        <div class="step current" data-step="1" onclick="goToStep(1)">
                            <div class="step-number">1</div>
                            <div class="step-title">Manufacturer &amp; Modules</div>
                        </div>
                        <div class="step-connector"></div>
                        <div class="step" data-step="2" onclick="goToStep(2)">
                            <div class="step-number">2</div>
                            <div class="step-title">Pricing &amp; Milestones</div>
                        </div>
                        <div class="step-connector"></div>
                        <div class="step" data-step="3" onclick="goToStep(3)">
                            <div class="step-number">3</div>
                            <div class="step-title">Logistics &amp; Docs</div>
                        </div>
                        <div class="step-connector"></div>
                        <div class="step" data-step="4" onclick="goToStep(4)">
                            <div class="step-number">4</div>
                            <div class="step-title">Palletization</div>
                        </div>
                    </div>
                    <div class="current-step-label">Step 1: Manufacturer &amp; Modules</div>
                </div>

                <!-- Step 1: Manufacturer & Modules -->
                <div class="accordion-section active" data-section="1">
                    <div class="accordion-header" onclick="toggleAccordion(1)">
                        <div class="accordion-header-left">
                            <span class="accordion-number">1</span>
                            <div>
                                <div class="accordion-title">Manufacturer &amp; Modules</div>
                                <div class="section-description">Batch name, manufacturer, wattage and quantity</div>
                            </div>
                        </div>
                        <span class="accordion-tag required-tag">Required</span>
                        <span class="accordion-chevron"><i class="fas fa-chevron-down"></i></span>
                    </div>
                    <div class="accordion-content" style="max-height: 2000px;">
                        <div class="accordion-body">
                            <?php include __DIR__ . '/components/module_batch_step1.php'; ?>
                            <div class="section-actions">
                                <button type="button" class="btn-continue" onclick="goToStep(2)">Continue <i class="fas fa-arrow-right"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Pricing & Milestones -->
                <div class="accordion-section" data-section="2">
                    <div class="accordion-header" onclick="toggleAccordion(2)">
                        <div class="accordion-header-left">
                            <span class="accordion-number">2</span>
                            <div>
                                <div class="accordion-title">Pricing &amp; Milestones</div>
                                <div class="section-description">Cost per watt and payment milestone configuration</div>
                            </div>
                        </div>
                        <span class="accordion-tag recommended-tag">Recommended</span>
                        <span class="accordion-chevron"><i class="fas fa-chevron-down"></i></span>
                    </div>
                    <div class="accordion-content">
                        <div class="accordion-body">
                            <?php include __DIR__ . '/components/module_batch_step2.php'; ?>
                            <div class="section-actions">
                                <button type="button" class="btn-back-step" onclick="goToStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
                                <button type="button" class="btn-continue" onclick="goToStep(3)">Continue <i class="fas fa-arrow-right"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Logistics & Documentation -->
                <div class="accordion-section" data-section="3">
                    <div class="accordion-header" onclick="toggleAccordion(3)">
                        <div class="accordion-header-left">
                            <span class="accordion-number">3</span>
                            <div>
                                <div class="accordion-title">Logistics &amp; Documentation</div>
                                <div class="section-description">Handling, stacking, notes, and document upload</div>
                            </div>
                        </div>
                        <span class="accordion-tag optional-tag">Optional</span>
                        <span class="accordion-chevron"><i class="fas fa-chevron-down"></i></span>
                    </div>
                    <div class="accordion-content">
                        <div class="accordion-body">
                            <?php include __DIR__ . '/components/module_batch_step3.php'; ?>
                            <div class="section-actions">
                                <button type="button" class="btn-back-step" onclick="goToStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
                                <button type="button" class="btn-continue" onclick="goToStep(4)">Continue <i class="fas fa-arrow-right"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Palletization -->
                <div class="accordion-section" data-section="4">
                    <div class="accordion-header" onclick="toggleAccordion(4)">
                        <div class="accordion-header-left">
                            <span class="accordion-number">4</span>
                            <div>
                                <div class="accordion-title">Palletization</div>
                                <div class="section-description">Pallet configuration and auto-palletization</div>
                            </div>
                        </div>
                        <span class="accordion-tag optional-tag">Optional</span>
                        <span class="accordion-chevron"><i class="fas fa-chevron-down"></i></span>
                    </div>
                    <div class="accordion-content">
                        <div class="accordion-body">
                            <?php include __DIR__ . '/components/module_batch_step4.php'; ?>

                            <div class="form-subsection-title" style="margin-top: 24px;">Auto-Palletization <span style="font-weight: 400; font-size: 0.85rem; color: #6c757d;">(optional)</span></div>
                            <div style="background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%); border: 1px solid #ffc107; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                                <div style="display: flex; align-items: flex-start; gap: 12px;">
                                    <span style="font-size: 1.2rem;">&#128161;</span>
                                    <div>
                                        <div style="font-weight: 600; color: #856404; margin-bottom: 6px;">How Auto-Palletization Works</div>
                                        <ul style="margin: 0; padding-left: 18px; color: #856404; font-size: 0.9rem; line-height: 1.6;">
                                            <li>Pallets will be created with <strong>system-generated IDs</strong> (e.g., PAL-0001)</li>
                                            <li>You can link actual <strong>manufacturer pallet IDs</strong> later when shipments arrive</li>
                                            <li>Ideal for planning and tracking before real pallet data is available</li>
                                            <li>Works for both <strong>project-assigned</strong> and <strong>unassigned</strong> batches</li>
                                            <li>For importing real manufacturer pallet data, use the <strong>Import Pallets</strong> option instead</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: stretch; gap: 16px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 16px 20px; background: #fff; border-radius: 10px; border: 2px solid #e9ecef; transition: all 0.2s ease;" id="enablePalletizationLabel">
                                    <input type="checkbox" name="enable_palletization" id="enable_palletization" value="1" style="width: 22px; height: 22px; cursor: pointer; accent-color: #488C9A;">
                                    <div>
                                        <div style="font-weight: 600; color: #293E4C; font-size: 1rem;">Enable Auto-Palletization</div>
                                        <div style="font-size: 0.85rem; color: #6c757d;">Create pallets automatically on save</div>
                                    </div>
                                </label>
                                <div id="palletizationConfig" style="display: none; padding: 16px 20px; background: #fff; border-radius: 10px; border: 2px solid #488C9A; box-shadow: 0 4px 12px rgba(72,140,154,0.15);">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <label for="auto_modules_per_pallet" style="font-weight: 600; color: #293E4C; font-size: 0.95rem; white-space: nowrap;">Modules per Pallet:</label>
                                        <input type="number" name="auto_modules_per_pallet" id="auto_modules_per_pallet" min="1" value="<?php echo htmlspecialchars($_POST['auto_modules_per_pallet'] ?? ''); ?>" placeholder="30" style="width: 80px; padding: 8px 12px; border: 2px solid #e9ecef; border-radius: 6px; font-size: 1rem; font-weight: 600; text-align: center;">
                                        <div id="palletCalculation" style="display: none; padding: 6px 12px; background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%); border-radius: 6px; border: 1px solid #c8e6c9;">
                                            <span style="font-size: 0.9rem; font-weight: 600; color: #28a745;" id="calcTotalPallets">0 pallets</span>
                                            <span id="calcPartialPallet" style="color: #856404; font-size: 0.85rem; margin-left: 4px;"></span>
                                        </div>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #6c757d; margin-top: 8px;">Leave blank to use the "Modules per Pallet" value above.</div>
                                </div>
                            </div>
                            <div id="palletCalcDetails" style="display: none; margin-top: 16px; padding: 16px; background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%); border-radius: 10px; border: 1px solid #c8e6c9;">
                                <div style="display: flex; gap: 24px; flex-wrap: wrap; justify-content: center;">
                                    <div style="text-align: center;">
                                        <div style="font-size: 1.3rem; font-weight: 700; color: #293E4C;" id="calcTotalModules">0</div>
                                        <div style="font-size: 0.75rem; color: #6c757d;">Total Modules</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 1.3rem; font-weight: 700; color: #28a745;" id="calcFullPallets">0</div>
                                        <div style="font-size: 0.75rem; color: #6c757d;">Full Pallets</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 1.3rem; font-weight: 700; color: #ffc107;" id="calcPartialModules">0</div>
                                        <div style="font-size: 0.75rem; color: #6c757d;">Partial Pallet</div>
                                    </div>
                                </div>
                            </div>

                            <div class="section-actions">
                                <button type="button" class="btn-back-step" onclick="goToStep(3)"><i class="fas fa-arrow-left"></i> Back</button>
                                <button type="submit" class="btn-submit"><i class="fas fa-check"></i> Create Module Batch</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Import Container (hidden by default) -->
        <div id="importContainer" style="display: none; background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 40px; margin-bottom: 20px;">
            <div style="text-align: center; padding: 40px 20px;">
                <div style="font-size: 48px; color: #488C9A; margin-bottom: 16px;">&#128230;</div>
                <h2 style="color: #293E4C; margin-bottom: 12px;">Import Pallets</h2>
                <p style="color: #6c757d; margin-bottom: 24px; max-width: 500px; margin-left: auto; margin-right: auto;">
                    Upload a CSV or Excel file with pallet data from your manufacturer to add pallets to inventory. Each row = one pallet.
                </p>
                <a href="upload_pallets.php<?php echo $project_id ? '?project_id='.$project_id : ''; ?>"
                   class="btn-submit" style="display: inline-block; text-decoration: none; padding: 16px 32px;">
                    Import Pallets &rarr;
                </a>
            </div>
        </div>

        <!-- Assign Unassigned Container (hidden by default) -->
        <div id="assignContainer" style="display: none; background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 28px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 18px; flex-wrap: wrap; margin-bottom: 14px;">
                <div>
                    <h2 style="color: #293E4C; margin: 0 0 8px 0;">Assign Existing Unassigned Batches</h2>
                    <p style="color: #6c757d; margin: 0; max-width: 760px;">
                        Select one or more unassigned stock batches, choose the destination project, and use the Project Capacity Status below to preview the updated capacity before assigning.
                    </p>
                </div>
                <a href="modules.php" style="text-decoration: none; color: #488C9A; font-weight: 600;">Open Manage Modules</a>
            </div>

            <?php if ($project): ?>
                <div style="background: #f8fbfd; border: 1px solid #d6e5ea; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px;">
                    <div style="font-size: 0.92rem; color: #475569;">
                        Assigning into fixed project:
                        <strong style="color: #293E4C;"><?php echo htmlspecialchars($project['project_name']); ?></strong>
                    </div>
                    <input type="hidden" id="assign_project_id_fixed" value="<?php echo (int)$project['id']; ?>">
                </div>
            <?php else: ?>
                <div style="margin-bottom: 14px;">
                    <label for="assign_project_id" style="display: block; margin-bottom: 8px; font-weight: 600; color: #293E4C;">Assign to Project</label>
                    <select id="assign_project_id" style="width: 100%; max-width: 520px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px;">
                        <option value="">Select project</option>
                        <?php foreach ($assign_project_options as $assignProj): ?>
                            <option
                                value="<?php echo (int)$assignProj['id']; ?>"
                                data-account-id="<?php echo (int)$assignProj['account_id']; ?>"
                                data-target-watts="<?php echo (float)$assignProj['project_size'] * 1000000; ?>"
                            >
                                <?php echo htmlspecialchars($assignProj['project_name']); ?>
                                <?php if ($role === 'global_admin'): ?> (Acct <?php echo (int)$assignProj['account_id']; ?>)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div id="assignCapacityBanner" style="display:none; background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%); border: 1px solid #b8daff; border-radius: 16px; padding: 18px; margin-bottom: 16px;">
                <h3 style="margin: 0 0 12px 0; color: #0056b3; font-size: 1.02em; display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 1.1em;">&#9889;</span> Project Capacity Status
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 12px;">
                    <div style="background: #fff; border-radius: 10px; padding: 12px; text-align: center;">
                        <div id="assign_cap_current" style="font-size: 1.35rem; font-weight: 700; color: #488C9A;">0.000 MW</div>
                        <div style="font-size: 0.8rem; color: #6c757d;">Currently Ordered</div>
                    </div>
                    <div style="background: #fff; border-radius: 10px; padding: 12px; text-align: center;">
                        <div id="assign_cap_target" style="font-size: 1.35rem; font-weight: 700; color: #293E4C;">0.000 MW</div>
                        <div style="font-size: 0.8rem; color: #6c757d;">Project Target</div>
                    </div>
                    <div style="background: #fff; border-radius: 10px; padding: 12px; text-align: center;">
                        <div id="assign_cap_remaining" style="font-size: 1.35rem; font-weight: 700; color: #28a745;">0.000 MW</div>
                        <div style="font-size: 0.8rem; color: #6c757d;">Remaining Capacity</div>
                    </div>
                </div>
                <div style="background: #e9ecef; border-radius: 8px; height: 18px; overflow: hidden;">
                    <div id="assign_cap_progress" style="background: linear-gradient(90deg, #488C9A 0%, #3a7086 100%); height: 100%; width: 0%; color: #fff; font-size: 11px; font-weight: 600; display: flex; align-items: center; justify-content: center;">0.0%</div>
                </div>
                <div id="assign_cap_preview" style="display:none; margin-top: 10px; padding: 10px 12px; border-radius: 8px; border: 1px solid #ffc107; background: #fff3cd; color: #856404; font-size: 0.9rem;"></div>
            </div>

            <div id="assignFeedback" style="display:none; margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; font-size: 0.92rem;"></div>

            <?php if (empty($assignable_unassigned_batches)): ?>
                <div style="padding: 26px; text-align: center; color: #6c757d; background: #f8f9fa; border-radius: 10px;">
                    No unassigned module batches are available in this scope.
                </div>
            <?php else: ?>
                <form id="assignBatchesForm">
                    <input type="hidden" name="action" value="assign_unassigned_batches">
                    <input type="hidden" name="assign_batch_ids" id="assign_batch_ids">
                    <div style="display: flex; justify-content: flex-end; margin-bottom: 10px;">
                        <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; color: #475569;">
                            <input type="checkbox" id="assign_select_all">
                            Select all
                        </label>
                    </div>
                    <div class="assign-batch-grid">
                        <?php foreach ($assignable_unassigned_batches as $ab): ?>
                            <label class="assign-batch-card" onclick="if(event.target.tagName !== 'INPUT'){event.preventDefault(); const cb=this.querySelector('.assign-batch-checkbox'); cb.checked=!cb.checked; cb.dispatchEvent(new Event('change'));}">
                                <div class="assign-batch-card-inner">
                                    <input
                                        type="checkbox"
                                        class="assign-batch-checkbox"
                                        value="<?php echo (int)$ab['id']; ?>"
                                        data-account-id="<?php echo (int)$ab['account_id']; ?>"
                                        data-total-modules="<?php echo (int)$ab['total_quantity']; ?>"
                                        data-total-watts="<?php echo (float)$ab['total_watts']; ?>"
                                        style="margin-top:4px;"
                                    >
                                    <div class="assign-batch-content">
                                        <div class="assign-batch-top">
                                            <div>
                                                <div class="assign-batch-name">
                                                    <?php echo htmlspecialchars(!empty($ab['batch_name']) ? $ab['batch_name'] : ('Batch #' . (int)$ab['id'])); ?>
                                                </div>
                                                <div class="assign-batch-meta">
                                                    <?php echo htmlspecialchars($ab['vendor_name'] ?? 'Unknown Vendor'); ?>
                                                    <?php if ($role === 'global_admin'): ?> | <?php echo htmlspecialchars($ab['account_name'] ?? ''); ?><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="assign-batch-totals">
                                                <div class="assign-batch-modules"><?php echo number_format((int)$ab['total_quantity']); ?> modules</div>
                                                <div class="assign-batch-mw"><?php echo number_format(((float)$ab['total_watts']) / 1000000, 3); ?> MW</div>
                                            </div>
                                        </div>
                                        <div style="margin-top:8px;">
                                            <div class="assign-batch-mix-title">Module Mix (Wattage, Qty, Domestic %)</div>
                                            <div class="assign-batch-mix">
                                                <?php if (!empty($ab['items'])): ?>
                                                    <?php foreach ($ab['items'] as $mixItem): ?>
                                                        <span class="assign-batch-chip">
                                                            <?php echo (int)$mixItem['wattage']; ?>W x <?php echo number_format((int)$mixItem['quantity']); ?>
                                                            <?php if ($mixItem['domestic_content_pct'] !== null && $mixItem['domestic_content_pct'] !== ''): ?>
                                                                | <?php echo number_format((float)$mixItem['domestic_content_pct'], 2); ?>% domestic
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="assign-batch-chip-empty">No module item details.</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="assign-footer-row">
                        <button type="submit" id="assignSubmitBtn" class="btn-submit" style="padding: 12px 24px;" disabled>
                            Assign Selected Batches
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Loading Modal -->
        <div id="loadingModal" class="loading-modal">
            <div class="loading-content">
                <div class="spinner"></div>
                <h3 id="loadingTitle">Creating Module Batch...</h3>
                <p id="loadingSubtitle">Please wait while we process your request.</p>
            </div>
        </div>

        <!-- Result Modal -->
        <div id="resultModal" class="loading-modal" style="display: none;">
            <div class="loading-content" style="max-width: 480px; padding: 32px 40px;">
                <div id="resultIcon" style="font-size: 56px; margin-bottom: 16px;"></div>
                <h3 id="resultTitle" style="margin: 0 0 12px 0; font-size: 1.4em;"></h3>
                <p id="resultMessage" style="margin: 0 0 8px 0; color: #6c757d;"></p>
                <div id="resultDetails" style="margin: 16px 0; padding: 16px; background: #f8f9fa; border-radius: 8px; text-align: left; display: none;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: #488C9A;" id="resultModules">0</div>
                            <div style="font-size: 0.85rem; color: #6c757d;">Modules Added</div>
                        </div>
                        <div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: #28a745;" id="resultPallets">0</div>
                            <div style="font-size: 0.85rem; color: #6c757d;">Pallets Created</div>
                        </div>
                    </div>
                </div>
                <div id="resultWarning" style="display: none; margin: 16px 0; padding: 12px 16px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; text-align: left;">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <span style="font-size: 1.1rem;">&#9888;&#65039;</span>
                        <div style="font-size: 0.9rem; color: #856404;" id="resultWarningText"></div>
                    </div>
                </div>
                <div style="display: flex; gap: 12px; justify-content: center; margin-top: 24px; flex-wrap: wrap;">
                    <button type="button" id="resultAddAnotherBtn" onclick="resetFormForAnother()" style="background: #6c757d; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 500; display: none;">
                        <i class="fas fa-plus" style="margin-right: 6px;"></i> Add Another Batch
                    </button>
                    <a href="#" id="resultGoBackBtn" style="background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%); color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center;">
                        <i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Project Overview
                    </a>
                    <button type="button" id="resultCloseBtn" onclick="closeResultModal()" style="background: #e9ecef; color: #293E4C; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 500; display: none;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/components/module_batch_scripts.php'; ?>
    <script>
    // ========== Wizard Navigation ==========
    const stepNames = { 1: 'Manufacturer & Modules', 2: 'Pricing & Milestones', 3: 'Logistics & Docs', 4: 'Palletization' };
    let currentStep = 1;

    function goToStep(step) {
        if (step < 1 || step > 4) return;
        if (step > currentStep && !validateStep(currentStep)) return;

        // Close current accordion
        const currentSection = document.querySelector('.accordion-section[data-section="' + currentStep + '"]');
        if (currentSection) {
            currentSection.classList.remove('active');
            const content = currentSection.querySelector('.accordion-content');
            if (content) content.style.maxHeight = '0';
        }

        // Open target accordion
        const targetSection = document.querySelector('.accordion-section[data-section="' + step + '"]');
        if (targetSection) {
            targetSection.classList.add('active');
            const content = targetSection.querySelector('.accordion-content');
            if (content) content.style.maxHeight = (content.scrollHeight + 200) + 'px';
        }

        // Update step indicator
        document.querySelectorAll('.step-indicator .step').forEach(function(s) {
            var sNum = parseInt(s.dataset.step);
            s.classList.remove('current', 'completed');
            if (sNum === step) s.classList.add('current');
            else if (sNum < step) s.classList.add('completed');
        });
        document.querySelectorAll('.step-connector').forEach(function(c, i) {
            if (i < step - 1) c.classList.add('completed');
            else c.classList.remove('completed');
        });

        // Update step label
        var label = document.querySelector('.current-step-label');
        if (label) label.textContent = 'Step ' + step + ': ' + stepNames[step];

        currentStep = step;

        // Scroll to target section
        if (targetSection) {
            setTimeout(function() {
                targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }
    }

    function validateStep(step) {
        if (step === 1) {
            var wattages = document.querySelectorAll('input[name="wattages[]"]');
            var quantities = document.querySelectorAll('input[name="quantities[]"]');
            var hasValid = false;
            wattages.forEach(function(w, i) {
                var wVal = parseInt(w.value) || 0;
                var qVal = parseInt(quantities[i] ? quantities[i].value : '') || 0;
                if (wVal > 0 && qVal > 0) hasValid = true;
            });
            if (!hasValid) {
                alert('Please enter at least one wattage and quantity pair.');
                return false;
            }
        }
        if (step === 2) {
            if (!mb_validateMilestoneTotal()) return false;
            if (!mb_validatePoExecutionDate()) return false;
        }
        return true;
    }

    function toggleAccordion(section) {
        var accordion = document.querySelector('.accordion-section[data-section="' + section + '"]');
        if (!accordion) return;
        if (accordion.classList.contains('active')) {
            accordion.classList.remove('active');
            var content = accordion.querySelector('.accordion-content');
            if (content) content.style.maxHeight = '0';
        } else {
            goToStep(section);
        }
    }
    // Sticky step indicator (IntersectionObserver)
    (function() {
        var wrapper = document.querySelector('.step-indicator-wrapper');
        if (!wrapper) return;
        var sentinel = document.createElement('div');
        sentinel.style.height = '1px';
        sentinel.style.visibility = 'hidden';
        wrapper.parentNode.insertBefore(sentinel, wrapper);
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (!entry.isIntersecting) {
                    wrapper.classList.add('is-fixed');
                } else {
                    wrapper.classList.remove('is-fixed');
                }
            });
        }, { threshold: 0 });
        observer.observe(sentinel);
    })();
    // ========== Entry Method Toggle ==========
    document.addEventListener('DOMContentLoaded', function() {
        var methodRadios = document.querySelectorAll('input[name="entry_method"]');
        var manualContainer = document.getElementById('manualEntryContainer');
        var importContainer = document.getElementById('importContainer');
        var assignContainer = document.getElementById('assignContainer');
        var methodOptions = document.querySelectorAll('.method-option');

        methodRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                methodOptions.forEach(function(opt) {
                    var isActive = opt.querySelector('input').checked;
                    opt.style.background = isActive ? '#fff' : 'transparent';
                    opt.style.boxShadow = isActive ? '0 1px 3px rgba(0,0,0,0.1)' : 'none';
                    var icon = opt.querySelector('i');
                    var label = opt.querySelector('span');
                    if (icon) icon.style.color = isActive ? '#488C9A' : '#6c757d';
                    if (label) { label.style.color = isActive ? '#293E4C' : '#6c757d'; label.style.fontWeight = isActive ? '600' : '500'; }
                });
                if (this.value === 'manual') {
                    manualContainer.style.display = 'block';
                    importContainer.style.display = 'none';
                    if (assignContainer) assignContainer.style.display = 'none';
                } else if (this.value === 'import') {
                    manualContainer.style.display = 'none';
                    importContainer.style.display = 'block';
                    if (assignContainer) assignContainer.style.display = 'none';
                } else {
                    manualContainer.style.display = 'none';
                    importContainer.style.display = 'none';
                    if (assignContainer) assignContainer.style.display = 'block';
                }
            });
        });

        var requestedMethod = new URLSearchParams(window.location.search).get('entry_method');
        if (requestedMethod && ['manual', 'assign', 'import'].indexOf(requestedMethod) !== -1) {
            var requestedRadio = document.querySelector('input[name="entry_method"][value="' + requestedMethod + '"]');
            if (requestedRadio) {
                requestedRadio.checked = true;
                requestedRadio.dispatchEvent(new Event('change'));
            }
        }
    });

    // ========== Assign Existing Unassigned Batches ==========
    document.addEventListener('DOMContentLoaded', function() {
        var assignForm = document.getElementById('assignBatchesForm');
        if (!assignForm) return;

        var assignImpactByProject = <?php echo json_encode($assign_project_impact_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var isGlobalAdmin = <?php echo $role === 'global_admin' ? 'true' : 'false'; ?>;
        var fixedProjectId = parseInt(document.getElementById('assign_project_id_fixed')?.value || '0', 10);
        var projectSelect = document.getElementById('assign_project_id');
        var projectOptions = projectSelect ? Array.from(projectSelect.options).slice(1).map(function(opt) { return opt.cloneNode(true); }) : [];

        var selectAll = document.getElementById('assign_select_all');
        var batchCheckboxes = Array.from(document.querySelectorAll('.assign-batch-checkbox'));
        var assignBatchIdsInput = document.getElementById('assign_batch_ids');
        var assignSubmitBtn = document.getElementById('assignSubmitBtn');
        var feedback = document.getElementById('assignFeedback');

        var capBanner = document.getElementById('assignCapacityBanner');
        var capCurrentEl = document.getElementById('assign_cap_current');
        var capTargetEl = document.getElementById('assign_cap_target');
        var capRemainingEl = document.getElementById('assign_cap_remaining');
        var capProgressEl = document.getElementById('assign_cap_progress');
        var capPreviewEl = document.getElementById('assign_cap_preview');

        function formatModulesAndMw(modules, watts) {
            var safeModules = Number(modules || 0);
            var safeWatts = Number(watts || 0);
            return safeModules.toLocaleString() + ' modules (' + (safeWatts / 1000000).toFixed(3) + ' MW)';
        }

        function getSelectedBatches() {
            return batchCheckboxes.filter(function(cb) { return cb.checked; });
        }

        function getSelectedAccountIds() {
            return Array.from(new Set(getSelectedBatches().map(function(cb) {
                return parseInt(cb.getAttribute('data-account-id') || '0', 10);
            }).filter(function(id) { return id > 0; })));
        }

        function getActiveProjectId() {
            if (fixedProjectId > 0) return fixedProjectId;
            return parseInt(projectSelect?.value || '0', 10);
        }

        function refreshProjectOptionsByAccount(accountIdForFilter) {
            if (!projectSelect || !isGlobalAdmin) return;
            while (projectSelect.options.length > 1) projectSelect.remove(1);
            projectOptions.forEach(function(optTemplate) {
                var cloned = optTemplate.cloneNode(true);
                var optionAccountId = parseInt(cloned.getAttribute('data-account-id') || '0', 10);
                if (accountIdForFilter > 0 && optionAccountId !== accountIdForFilter) return;
                projectSelect.add(cloned);
            });
        }

        function renderImpact() {
            if (!capBanner) return;
            var selectedBatches = getSelectedBatches();
            var selectedModules = selectedBatches.reduce(function(sum, cb) { return sum + Number(cb.getAttribute('data-total-modules') || 0); }, 0);
            var selectedWatts = selectedBatches.reduce(function(sum, cb) { return sum + Number(cb.getAttribute('data-total-watts') || 0); }, 0);
            var activeProjectId = getActiveProjectId();

            if (!activeProjectId || !assignImpactByProject[activeProjectId]) {
                capBanner.style.display = selectedModules > 0 ? 'block' : 'none';
                if (capCurrentEl) capCurrentEl.textContent = '-';
                if (capTargetEl) capTargetEl.textContent = '-';
                if (capRemainingEl) capRemainingEl.textContent = '-';
                if (capProgressEl) {
                    capProgressEl.style.width = '0%';
                    capProgressEl.textContent = '0.0%';
                }
                if (capPreviewEl) {
                    capPreviewEl.style.display = selectedModules > 0 ? 'block' : 'none';
                    capPreviewEl.style.background = '#fff3cd';
                    capPreviewEl.style.borderColor = '#ffc107';
                    capPreviewEl.style.color = '#856404';
                    if (selectedModules > 0) {
                        capPreviewEl.textContent = 'Select a project to preview capacity after assigning ' + formatModulesAndMw(selectedModules, selectedWatts) + '.';
                    } else {
                        capPreviewEl.textContent = '';
                    }
                }
                return;
            }

            var stats = assignImpactByProject[activeProjectId];
            var targetWatts = Number(stats.target_watts || 0);
            var currentModules = Number(stats.current_modules || 0);
            var currentWatts = Number(stats.current_watts || 0);

            var projectedModules = currentModules + selectedModules;
            var projectedWatts = currentWatts + selectedWatts;
            var remainingNowWatts = targetWatts - currentWatts;
            var remainingProjectedWatts = targetWatts - projectedWatts;
            var currentPct = (targetWatts > 0) ? Math.min(100, (currentWatts / targetWatts) * 100) : 0;
            var projectedPct = (targetWatts > 0) ? Math.min(100, (projectedWatts / targetWatts) * 100) : 0;

            if (capCurrentEl) capCurrentEl.textContent = (currentWatts / 1000000).toFixed(3) + ' MW';
            if (capTargetEl) capTargetEl.textContent = targetWatts > 0 ? ((targetWatts / 1000000).toFixed(3) + ' MW') : '-';
            if (capRemainingEl) {
                if (targetWatts > 0) {
                    capRemainingEl.textContent = (Math.max(0, remainingNowWatts) / 1000000).toFixed(3) + ' MW';
                    capRemainingEl.style.color = remainingNowWatts >= 0 ? '#28a745' : '#dc3545';
                } else {
                    capRemainingEl.textContent = '-';
                    capRemainingEl.style.color = '#6c757d';
                }
            }

            if (capProgressEl) {
                if (targetWatts > 0) {
                    capProgressEl.style.width = projectedPct.toFixed(1) + '%';
                    capProgressEl.textContent = projectedPct.toFixed(1) + '%';
                    capProgressEl.style.background = projectedWatts > targetWatts
                        ? 'linear-gradient(90deg, #dc3545 0%, #b91c1c 100%)'
                        : 'linear-gradient(90deg, #488C9A 0%, #3a7086 100%)';
                } else {
                    capProgressEl.style.width = '0%';
                    capProgressEl.textContent = '0.0%';
                    capProgressEl.style.background = 'linear-gradient(90deg, #488C9A 0%, #3a7086 100%)';
                }
            }

            if (capPreviewEl) {
                if (selectedModules <= 0) {
                    capPreviewEl.style.display = 'none';
                } else if (targetWatts <= 0) {
                    capPreviewEl.style.display = 'block';
                    capPreviewEl.style.background = '#fff3cd';
                    capPreviewEl.style.borderColor = '#ffc107';
                    capPreviewEl.style.color = '#856404';
                    capPreviewEl.textContent = 'No project size target is defined. Selected batches add ' + formatModulesAndMw(selectedModules, selectedWatts) + '.';
                } else if (remainingProjectedWatts < 0) {
                    capPreviewEl.style.display = 'block';
                    capPreviewEl.style.background = '#f8d7da';
                    capPreviewEl.style.borderColor = '#f5c6cb';
                    capPreviewEl.style.color = '#721c24';
                    capPreviewEl.textContent = 'After assigning selected batches (' + formatModulesAndMw(selectedModules, selectedWatts) + '), projected total is ' +
                        (projectedWatts / 1000000).toFixed(3) + ' MW, which is over target by ' + (Math.abs(remainingProjectedWatts) / 1000000).toFixed(3) + ' MW.';
                } else {
                    capPreviewEl.style.display = 'block';
                    capPreviewEl.style.background = '#d4edda';
                    capPreviewEl.style.borderColor = '#c3e6cb';
                    capPreviewEl.style.color = '#155724';
                    capPreviewEl.textContent = 'After assigning selected batches (' + formatModulesAndMw(selectedModules, selectedWatts) + '), projected total is ' +
                        (projectedWatts / 1000000).toFixed(3) + ' MW with ' + (remainingProjectedWatts / 1000000).toFixed(3) + ' MW remaining.';
                }
            }

            capBanner.style.display = 'block';
        }

        function updateAssignState() {
            var selected = getSelectedBatches();
            assignBatchIdsInput.value = selected.map(function(cb) { return cb.value; }).join(',');
            batchCheckboxes.forEach(function(cb) {
                var card = cb.closest('.assign-batch-card');
                if (card) {
                    card.classList.toggle('is-selected', cb.checked);
                }
            });

            if (selectAll) {
                selectAll.checked = (batchCheckboxes.length > 0 && selected.length === batchCheckboxes.length);
            }

            if (feedback) {
                feedback.style.display = 'none';
                feedback.textContent = '';
            }

            if (isGlobalAdmin && fixedProjectId <= 0) {
                var accountIds = getSelectedAccountIds();
                var singleAccountId = accountIds.length === 1 ? accountIds[0] : 0;
                refreshProjectOptionsByAccount(singleAccountId);
                if (projectSelect && projectSelect.value && singleAccountId > 0) {
                    var selectedOption = projectSelect.options[projectSelect.selectedIndex];
                    var selectedOptionAccountId = parseInt(selectedOption?.getAttribute('data-account-id') || '0', 10);
                    if (selectedOptionAccountId !== singleAccountId) {
                        projectSelect.value = '';
                    }
                }
                if (accountIds.length > 1) {
                    if (feedback) {
                        feedback.style.display = 'block';
                        feedback.style.background = '#fff3cd';
                        feedback.style.border = '1px solid #ffc107';
                        feedback.style.color = '#856404';
                        feedback.textContent = 'For global admin: select batches from only one account at a time.';
                    }
                }
            }

            var hasSelection = selected.length > 0;
            var hasProject = getActiveProjectId() > 0;
            var mixedAccounts = isGlobalAdmin && getSelectedAccountIds().length > 1;
            if (assignSubmitBtn) assignSubmitBtn.disabled = !(hasSelection && hasProject && !mixedAccounts);

            renderImpact();
        }

        if (selectAll) {
            selectAll.addEventListener('change', function() {
                var checked = !!selectAll.checked;
                batchCheckboxes.forEach(function(cb) { cb.checked = checked; });
                updateAssignState();
            });
        }

        batchCheckboxes.forEach(function(cb) {
            cb.addEventListener('change', updateAssignState);
        });

        if (projectSelect) {
            projectSelect.addEventListener('change', updateAssignState);
        }

        assignForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var batchIds = assignBatchIdsInput.value;
            var activeProjectId = getActiveProjectId();
            if (!batchIds) {
                alert('Select at least one unassigned batch.');
                return;
            }
            if (!activeProjectId) {
                alert('Select a target project.');
                return;
            }

            var formData = new FormData(assignForm);
            formData.set('assign_project_id', String(activeProjectId));
            formData.set('assign_batch_ids', batchIds);

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (!feedback) return;
                if (data.success) {
                    feedback.style.display = 'block';
                    feedback.style.background = '#d4edda';
                    feedback.style.border = '1px solid #c3e6cb';
                    feedback.style.color = '#155724';
                    feedback.textContent = data.message || 'Assignment completed.';
                    setTimeout(function() {
                        var nextUrl = new URL(window.location.href);
                        nextUrl.searchParams.set('entry_method', 'assign');
                        window.location.href = nextUrl.toString();
                    }, 900);
                } else {
                    feedback.style.display = 'block';
                    feedback.style.background = '#f8d7da';
                    feedback.style.border = '1px solid #f5c6cb';
                    feedback.style.color = '#721c24';
                    feedback.textContent = data.message || 'Assignment failed.';
                }
            })
            .catch(function() {
                if (!feedback) return;
                feedback.style.display = 'block';
                feedback.style.background = '#f8d7da';
                feedback.style.border = '1px solid #f5c6cb';
                feedback.style.color = '#721c24';
                feedback.textContent = 'Assignment failed due to a network or server error.';
            });
        });

        updateAssignState();
    });

    // ========== MW Capacity Tracking ==========
    var capacityData = {
        projectSizeMw: <?php echo json_encode($project_size_mw); ?>,
        currentOrderedMw: <?php echo json_encode($current_ordered_mw); ?>
    };

    function calculateBatchMw() {
        var batchMw = 0;
        var wattageInputs = document.querySelectorAll('input[name="wattages[]"]');
        var quantityInputs = document.querySelectorAll('input[name="quantities[]"]');
        wattageInputs.forEach(function(wInput, i) {
            var wattage = parseFloat(wInput.value) || 0;
            var quantity = parseInt(quantityInputs[i] ? quantityInputs[i].value : '') || 0;
            batchMw += (wattage * quantity) / 1000000;
        });
        return batchMw;
    }

    function updateMwPreview() {
        if (capacityData.projectSizeMw <= 0) return;
        var batchMw = calculateBatchMw();
        var newTotalMw = capacityData.currentOrderedMw + batchMw;
        var previewDiv = document.getElementById('newMwPreview');

        if (batchMw > 0 && previewDiv) {
            previewDiv.style.display = 'block';
            if (newTotalMw > capacityData.projectSizeMw) {
                var excessMw = newTotalMw - capacityData.projectSizeMw;
                var excessPct = ((newTotalMw / capacityData.projectSizeMw) - 1) * 100;
                previewDiv.style.borderColor = '#dc3545';
                previewDiv.style.background = '#f8d7da';
                previewDiv.innerHTML =
                    '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">' +
                    '<span style="color: #721c24; font-weight: 600;">&#9888; Over Capacity Warning</span>' +
                    '<span style="font-weight: 700; color: #721c24;">' + newTotalMw.toFixed(2) + ' MW</span></div>' +
                    '<div style="font-size: 0.9rem; color: #721c24;">This batch will exceed the project target by ' +
                    excessMw.toFixed(2) + ' MW (' + excessPct.toFixed(1) + '% over). You will be asked to confirm before saving.</div>' +
                    '<div style="font-size: 0.85rem; color: #856404; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.1);">' +
                    'This batch adds: <strong>+' + batchMw.toFixed(4) + ' MW</strong></div>';
            } else {
                previewDiv.style.borderColor = '#28a745';
                previewDiv.style.background = '#d4edda';
                var remainingAfter = capacityData.projectSizeMw - newTotalMw;
                previewDiv.innerHTML =
                    '<div style="display: flex; justify-content: space-between; align-items: center;">' +
                    '<span style="color: #155724; font-weight: 500;">New Total After This Batch:</span>' +
                    '<span style="font-weight: 700; color: #155724;">' + newTotalMw.toFixed(2) + ' MW</span></div>' +
                    '<div style="font-size: 0.85rem; color: #155724; margin-top: 4px;">' +
                    remainingAfter.toFixed(2) + ' MW remaining capacity</div>' +
                    '<div style="font-size: 0.85rem; color: #666; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.1);">' +
                    'This batch adds: <strong>+' + batchMw.toFixed(4) + ' MW</strong></div>';
            }
        } else if (previewDiv) {
            previewDiv.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        var wattageContainer = document.getElementById('wattage-container');
        if (wattageContainer) {
            wattageContainer.addEventListener('input', updateMwPreview);
            var observer = new MutationObserver(updateMwPreview);
            observer.observe(wattageContainer, { childList: true, subtree: true });
        }
    });

    // ========== Palletization Section ==========
    document.addEventListener('DOMContentLoaded', function() {
        var enableCheckbox = document.getElementById('enable_palletization');
        var configDiv = document.getElementById('palletizationConfig');
        var modulesPerPalletInput = document.getElementById('auto_modules_per_pallet');
        var logisticsModulesPerPalletInput = document.getElementById('modules_per_pallet');
        var calcDiv = document.getElementById('palletCalculation');
        var enableLabel = document.getElementById('enablePalletizationLabel');

        if (!enableCheckbox || !configDiv) return;

        function getEffectiveModulesPerPallet() {
            var autoValue = parseInt(modulesPerPalletInput && modulesPerPalletInput.value ? modulesPerPalletInput.value : '', 10);
            if (autoValue > 0) return autoValue;
            var logisticsValue = parseInt(logisticsModulesPerPalletInput && logisticsModulesPerPalletInput.value ? logisticsModulesPerPalletInput.value : '', 10);
            return logisticsValue > 0 ? logisticsValue : 0;
        }

        function syncAutoModulesPerPalletFromLogistics() {
            if (!modulesPerPalletInput || !logisticsModulesPerPalletInput) return;
            var autoValue = parseInt(modulesPerPalletInput.value || '', 10);
            var logisticsValue = parseInt(logisticsModulesPerPalletInput.value || '', 10);
            if (!(autoValue > 0) && logisticsValue > 0) {
                modulesPerPalletInput.value = String(logisticsValue);
            }
        }

        enableCheckbox.addEventListener('change', function() {
            configDiv.style.display = this.checked ? 'block' : 'none';
            if (this.checked) {
                syncAutoModulesPerPalletFromLogistics();
                enableLabel.style.borderColor = '#28a745';
                enableLabel.style.background = '#d4edda';
                updatePalletCalculation();
            } else {
                enableLabel.style.borderColor = '#e9ecef';
                enableLabel.style.background = '#f8f9fa';
            }
        });

        if (modulesPerPalletInput) {
            modulesPerPalletInput.addEventListener('input', updatePalletCalculation);
        }
        if (logisticsModulesPerPalletInput) {
            logisticsModulesPerPalletInput.addEventListener('input', function() {
                if (enableCheckbox.checked) {
                    syncAutoModulesPerPalletFromLogistics();
                    updatePalletCalculation();
                }
            });
        }

        var form = document.getElementById('addBatchForm');
        if (form) {
            form.addEventListener('input', function(e) {
                if (e.target.name === 'wattages[]' || e.target.name === 'quantities[]') {
                    updatePalletCalculation();
                }
            });
        }

        function updatePalletCalculation() {
            if (!enableCheckbox.checked) return;
            var modulesPerPallet = getEffectiveModulesPerPallet();
            var quantityInputs = document.querySelectorAll('input[name="quantities[]"]');
            var totalModules = 0;
            quantityInputs.forEach(function(input) { totalModules += parseInt(input.value) || 0; });
            var detailsDiv = document.getElementById('palletCalcDetails');

            if (modulesPerPallet > 0 && totalModules > 0) {
                var fullPallets = Math.floor(totalModules / modulesPerPallet);
                var partialModules = totalModules % modulesPerPallet;
                var totalPallets = fullPallets + (partialModules > 0 ? 1 : 0);

                document.getElementById('calcTotalPallets').textContent = totalPallets + ' pallet' + (totalPallets !== 1 ? 's' : '');
                var partialSpan = document.getElementById('calcPartialPallet');
                partialSpan.textContent = partialModules > 0 ? '(+' + partialModules + ' partial)' : '';

                document.getElementById('calcTotalModules').textContent = totalModules.toLocaleString();
                document.getElementById('calcFullPallets').textContent = fullPallets;
                document.getElementById('calcPartialModules').textContent = partialModules > 0 ? partialModules : '0';

                calcDiv.style.display = 'block';
                if (detailsDiv) detailsDiv.style.display = 'block';
            } else {
                calcDiv.style.display = 'none';
                if (detailsDiv) detailsDiv.style.display = 'none';
            }
        }

        var wattageContainer = document.getElementById('wattage-container');
        if (wattageContainer) {
            var observer = new MutationObserver(updatePalletCalculation);
            observer.observe(wattageContainer, { childList: true, subtree: true });
        }

        if (enableCheckbox.checked) {
            syncAutoModulesPerPalletFromLogistics();
            updatePalletCalculation();
        }
    });

    // ========== Loading Modal Functions ==========
    function showLoadingModal(withPalletization) {
        var modal = document.getElementById('loadingModal');
        var title = document.getElementById('loadingTitle');
        var subtitle = document.getElementById('loadingSubtitle');
        if (modal) {
            if (withPalletization) {
                title.textContent = 'Creating Module Batch & Pallets...';
                subtitle.textContent = 'Please wait while we create your modules and pallets.';
            } else {
                title.textContent = 'Creating Module Batch...';
                subtitle.textContent = 'Please wait while we process your request.';
            }
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    }

    function hideLoadingModal() {
        var modal = document.getElementById('loadingModal');
        if (modal) { modal.style.display = 'none'; document.body.style.overflow = ''; }
    }

    // ========== Result Modal Functions ==========
    function showResultModal(success, data) {
        hideLoadingModal();
        var modal = document.getElementById('resultModal');
        var icon = document.getElementById('resultIcon');
        var title = document.getElementById('resultTitle');
        var message = document.getElementById('resultMessage');
        var details = document.getElementById('resultDetails');
        var warning = document.getElementById('resultWarning');
        var warningText = document.getElementById('resultWarningText');
        var modulesEl = document.getElementById('resultModules');
        var palletsEl = document.getElementById('resultPallets');
        var addAnotherBtn = document.getElementById('resultAddAnotherBtn');
        var goBackBtn = document.getElementById('resultGoBackBtn');
        var closeBtn = document.getElementById('resultCloseBtn');

        if (success) {
            icon.innerHTML = '&#10004;';
            icon.style.color = '#28a745';
            title.textContent = 'Module Batch Created!';
            title.style.color = '#28a745';
            message.textContent = data.message || 'Your module batch has been created successfully.';
            details.style.display = 'block';
            modulesEl.textContent = (data.total_modules || 0).toLocaleString();
            palletsEl.textContent = data.pallets_created || 0;

            var enablePalletization = document.getElementById('enable_palletization');
            var isPalletizing = enablePalletization && enablePalletization.checked;
            if (isPalletizing && data.pallets_created === 0) {
                warning.style.display = 'block';
                warningText.textContent = 'Auto-palletization was enabled but no pallets were created. Please check your modules per pallet setting.';
            } else {
                warning.style.display = 'none';
            }

            addAnotherBtn.style.display = 'inline-flex';
            closeBtn.style.display = 'none';

            if (data.project_id) {
                goBackBtn.href = 'project_overview.php?project_id=' + data.project_id;
                goBackBtn.innerHTML = '<i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Project Overview';
                goBackBtn.style.display = 'inline-flex';
            } else {
                goBackBtn.href = 'modules.php';
                goBackBtn.innerHTML = '<i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Modules';
                goBackBtn.style.display = 'inline-flex';
            }
        } else {
            icon.innerHTML = '&#10006;';
            icon.style.color = '#dc3545';
            title.textContent = 'Error Creating Batch';
            title.style.color = '#dc3545';
            message.textContent = data.message || 'An error occurred while creating the module batch.';
            details.style.display = 'none';
            warning.style.display = 'none';
            addAnotherBtn.style.display = 'none';
            goBackBtn.style.display = 'none';
            closeBtn.style.display = 'inline-flex';
        }

        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeResultModal() {
        var modal = document.getElementById('resultModal');
        if (modal) { modal.style.display = 'none'; document.body.style.overflow = ''; }
    }

    function resetFormForAnother() {
        closeResultModal();
        var wattageContainer = document.getElementById('wattage-container');
        if (wattageContainer) {
            var entries = wattageContainer.querySelectorAll('.wattage-entry');
            entries.forEach(function(entry, index) {
                if (index === 0) { entry.querySelectorAll('input').forEach(function(input) { input.value = ''; }); }
                else { entry.remove(); }
            });
        }
        var enablePalletization = document.getElementById('enable_palletization');
        if (enablePalletization) {
            enablePalletization.checked = false;
            var configDiv = document.getElementById('palletizationConfig');
            if (configDiv) configDiv.style.display = 'none';
            var enableLabel = document.getElementById('enablePalletizationLabel');
            if (enableLabel) { enableLabel.style.borderColor = '#e9ecef'; enableLabel.style.background = '#f8f9fa'; }
        }
        var mpp = document.getElementById('auto_modules_per_pallet');
        if (mpp) mpp.value = '';
        var cd = document.getElementById('palletCalculation');
        var dd = document.getElementById('palletCalcDetails');
        if (cd) cd.style.display = 'none';
        if (dd) dd.style.display = 'none';

        goToStep(1);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ========== AJAX Form Submission ==========
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('addBatchForm');
        hideLoadingModal();
        closeResultModal();

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                var enablePalletization = document.getElementById('enable_palletization');
                var isPalletizing = enablePalletization && enablePalletization.checked;
                var modulesPerPalletInput = document.getElementById('auto_modules_per_pallet');
                var logisticsModulesPerPalletInput = document.getElementById('modules_per_pallet');
                var hasModulesPerPallet = modulesPerPalletInput && parseInt(modulesPerPalletInput.value, 10) > 0;
                if (!hasModulesPerPallet && logisticsModulesPerPalletInput && parseInt(logisticsModulesPerPalletInput.value, 10) > 0) {
                    hasModulesPerPallet = true;
                    if (modulesPerPalletInput) modulesPerPalletInput.value = logisticsModulesPerPalletInput.value;
                }

                if (capacityData.projectSizeMw > 0) {
                    var batchMw = calculateBatchMw();
                    var newTotalMw = capacityData.currentOrderedMw + batchMw;
                    if (newTotalMw > capacityData.projectSizeMw) {
                        var excessMw = newTotalMw - capacityData.projectSizeMw;
                        var excessPct = ((newTotalMw / capacityData.projectSizeMw) - 1) * 100;
                        var confirmMsg = 'WARNING: This batch will exceed the project capacity!\n\n' +
                            'Current: ' + capacityData.currentOrderedMw.toFixed(2) + ' MW\n' +
                            'This Batch: +' + batchMw.toFixed(2) + ' MW\n' +
                            'New Total: ' + newTotalMw.toFixed(2) + ' MW\n' +
                            'Target: ' + capacityData.projectSizeMw.toFixed(2) + ' MW\n\n' +
                            'This is ' + excessMw.toFixed(2) + ' MW (' + excessPct.toFixed(1) + '%) over the target.\n\n' +
                            'Are you sure you want to proceed?';
                        if (!confirm(confirmMsg)) return;
                    }
                }

                showLoadingModal(isPalletizing && hasModulesPerPallet);
                var formData = new FormData(form);

                fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) { showResultModal(data.success, data); })
                .catch(function(error) {
                    console.error('Error:', error);
                    showResultModal(false, { message: 'An unexpected error occurred. Please try again.' });
                });
            });
        }
    });
    </script>
</body>
</html>
