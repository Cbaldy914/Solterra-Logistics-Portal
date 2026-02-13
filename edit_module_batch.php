<?php
session_name("logistics_session");
session_start();

// Only admins/global_admins/customer_admins
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header('Location: unauthorized.php');
    exit();
}

require_once '../config.php';
require_once 'milestone_helpers.php';
require_once __DIR__ . '/module_reconciliation_audit_helpers.php';
$conn = getDBConnection();
if (!$conn) { die('Database connection failed.'); }

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$account_id = null;

if ($role !== 'global_admin') {
    $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    if ($stmtAccount) {
        $stmtAccount->bind_param('i', $user_id);
        $stmtAccount->execute();
        $stmtAccount->bind_result($account_id);
        $stmtAccount->fetch();
        $stmtAccount->close();
    }
}

$batch_id = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
if ($batch_id <= 0 && isset($_POST['batch_id'])) {
    $batch_id = (int)$_POST['batch_id'];
}
if ($batch_id <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Missing module batch id',
        ]);
        $conn->close();
        exit();
    }
    header('Location: modules.php?error=missing_batch');
    exit();
}

// Project capacity tracking variables
$project_size_mw = 0;
$current_ordered_mw = 0;
$this_batch_mw = 0;
$remaining_mw = 0;

// Load module batch and access control
if (in_array($role, ['admin', 'customer_admin'], true)) {
    $stmt = $conn->prepare("SELECT m.*, p.project_name FROM modules m LEFT JOIN projects p ON m.project_id = p.id JOIN customer_account_users cau ON m.account_id = cau.account_id AND cau.role IN ('admin', 'customer_admin') WHERE m.id=? AND cau.user_id=?");
    $stmt->bind_param('ii', $batch_id, $user_id);
} else {
    $stmt = $conn->prepare("SELECT m.*, p.project_name FROM modules m LEFT JOIN projects p ON m.project_id = p.id WHERE m.id=?");
    $stmt->bind_param('i', $batch_id);
}
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) { header('Location: modules.php?error=access_denied'); exit(); }
$module = $res->fetch_assoc();
$stmt->close();

$project_id = (int)($module['project_id'] ?? 0);

// Load project capacity data if this batch is assigned to a project
if ($project_id > 0) {
    // Get project size
    $stmtProj = $conn->prepare("SELECT project_size FROM projects WHERE id = ?");
    $stmtProj->bind_param("i", $project_id);
    $stmtProj->execute();
    $projResult = $stmtProj->get_result()->fetch_assoc();
    $project_size_mw = floatval($projResult['project_size'] ?? 0);
    $stmtProj->close();

    // Calculate total ordered MW for the project (all batches)
    $stmtTotal = $conn->prepare("
        SELECT SUM(umi.wattage * umi.quantity) / 1000000 as total_mw
        FROM unassigned_module_items umi
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ?
    ");
    $stmtTotal->bind_param("i", $project_id);
    $stmtTotal->execute();
    $totalResult = $stmtTotal->get_result()->fetch_assoc();
    $current_ordered_mw = floatval($totalResult['total_mw'] ?? 0);
    $stmtTotal->close();

    // Calculate MW from THIS batch specifically
    $stmtBatch = $conn->prepare("
        SELECT SUM(wattage * quantity) / 1000000 as batch_mw
        FROM unassigned_module_items
        WHERE unassigned_module_id = ?
    ");
    $stmtBatch->bind_param("i", $batch_id);
    $stmtBatch->execute();
    $batchResult = $stmtBatch->get_result()->fetch_assoc();
    $this_batch_mw = floatval($batchResult['batch_mw'] ?? 0);
    $stmtBatch->close();

    $remaining_mw = max(0, $project_size_mw - $current_ordered_mw);
}

// Load manufacturers
$manufacturers = [];
if ($stmtMfgs = $conn->prepare("SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC")) {
    $stmtMfgs->execute();
    $r = $stmtMfgs->get_result();
    while ($row = $r->fetch_assoc()) { $manufacturers[] = $row; }
    $stmtMfgs->close();
}

// Load current wattages with pallet counts
$current_wattages = [];
if ($stmtW = $conn->prepare('SELECT id, wattage, quantity, domestic_content_pct FROM unassigned_module_items WHERE unassigned_module_id = ? ORDER BY wattage ASC')) {
    $stmtW->bind_param('i', $batch_id);
    $stmtW->execute();
    $r = $stmtW->get_result();
    while ($row = $r->fetch_assoc()) {
        // Count pallets for this wattage item
        $pallet_count = 0;
        $pallet_modules = 0;
        if ($stmtP = $conn->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(quantity), 0) as modules FROM inventory_pallets WHERE unassigned_module_item_id = ?')) {
            $stmtP->bind_param('i', $row['id']);
            $stmtP->execute();
            $pRes = $stmtP->get_result()->fetch_assoc();
            $pallet_count = (int)($pRes['cnt'] ?? 0);
            $pallet_modules = (int)($pRes['modules'] ?? 0);
            $stmtP->close();
        }
        $row['pallet_count'] = $pallet_count;
        $row['pallet_modules'] = $pallet_modules;
        $current_wattages[] = $row;
    }
    $stmtW->close();
}

$errors = [];

/**
 * Find pallets for the given module item IDs that are linked downstream
 * (deliveries or warranty replacements) and therefore should not be deleted
 * by a simple batch edit operation.
 */
function getLockedPalletsForItemIds($conn, $itemIds) {
    $locked = [];
    if (empty($itemIds) || !is_array($itemIds)) {
        return $locked;
    }

    $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
    if (empty($itemIds)) {
        return $locked;
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $types = str_repeat('i', count($itemIds));
    $sql = "
        SELECT
            ip.id,
            MAX(CASE WHEN dp.inventory_pallet_id IS NOT NULL THEN 1 ELSE 0 END) AS has_delivery_link,
            MAX(CASE WHEN wcr.pallet_id IS NOT NULL THEN 1 ELSE 0 END) AS has_warranty_link
        FROM inventory_pallets ip
        LEFT JOIN delivery_pallets dp ON dp.inventory_pallet_id = ip.id
        LEFT JOIN warranty_claim_replacements wcr ON wcr.pallet_id = ip.id
        WHERE ip.unassigned_module_item_id IN ($placeholders)
        GROUP BY ip.id
        HAVING has_delivery_link = 1 OR has_warranty_link = 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed preparing locked pallet check: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$itemIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) { continue; }
        $locked[] = [
            'id' => $id,
            'delivery' => !empty($row['has_delivery_link']),
            'warranty' => !empty($row['has_warranty_link']),
        ];
    }
    $stmt->close();

    return $locked;
}

/**
 * Normalize/validate posted wattage rows for both preview and save paths.
 */
function normalizeSubmittedModuleRows($posted_watts, $posted_qtys, $posted_domestic_pcts, $track_domestic_content, &$errors) {
    $rows = [];

    if (!is_array($posted_watts) || !is_array($posted_qtys) || count($posted_watts) !== count($posted_qtys)) {
        $errors[] = 'Wattage and quantity arrays must match.';
        return $rows;
    }

    $seenWattages = [];
    for ($i = 0; $i < count($posted_watts); $i++) {
        $w = (int)trim((string)$posted_watts[$i]);
        $q = (int)trim((string)$posted_qtys[$i]);
        if ($w <= 0 && $q <= 0) { continue; }

        if ($w <= 0 || $q <= 0) {
            $errors[] = 'All wattages and quantities must be positive integers.';
            return [];
        }
        if (isset($seenWattages[$w])) {
            $errors[] = 'Duplicate wattage entries are not allowed. Please keep only one row per wattage.';
            return [];
        }
        $seenWattages[$w] = true;

        $domesticContentPct = null;
        if ($track_domestic_content) {
            $pctRaw = trim((string)($posted_domestic_pcts[$i] ?? ''));
            if ($pctRaw === '' || !is_numeric($pctRaw)) {
                $errors[] = 'Domestic Content % is required and must be numeric when tracking is enabled.';
                return [];
            }
            $domesticContentPct = (float)$pctRaw;
            if ($domesticContentPct < 0 || $domesticContentPct > 100) {
                $errors[] = 'Domestic Content % must be between 0 and 100.';
                return [];
            }
        }

        $rows[] = [
            'wattage' => $w,
            'quantity' => $q,
            'domestic_content_pct' => $domesticContentPct
        ];
    }

    if (empty($rows)) {
        $errors[] = 'At least one wattage and quantity is required.';
    }

    return $rows;
}

function calculateDomesticMetricsFromRows($rows) {
    $totalWatts = 0.0;
    $trackedWatts = 0.0;
    $weightedWatts = 0.0;

    foreach ($rows as $row) {
        $w = (float)($row['wattage'] ?? 0);
        $q = (float)($row['quantity'] ?? 0);
        $orderedWatts = $w * $q;
        if ($orderedWatts <= 0) { continue; }

        $totalWatts += $orderedWatts;
        if (array_key_exists('domestic_content_pct', $row) && $row['domestic_content_pct'] !== null) {
            $pct = (float)$row['domestic_content_pct'];
            $trackedWatts += $orderedWatts;
            $weightedWatts += ($orderedWatts * $pct);
        }
    }

    return [
        'tracked_watts' => $trackedWatts,
        'total_watts' => $totalWatts,
        'coverage_pct' => $totalWatts > 0 ? (($trackedWatts / $totalWatts) * 100) : 0.0,
        'domestic_content_pct' => $trackedWatts > 0 ? ($weightedWatts / $trackedWatts) : null
    ];
}

function normalizeReconciliationMode($modeRaw) {
    $mode = trim((string)$modeRaw);
    if (!in_array($mode, ['reassign_unlocked', 'rebuild_unlocked'], true)) {
        return 'reassign_unlocked';
    }
    return $mode;
}

function getReconciliationModeLabel($modeRaw) {
    $mode = normalizeReconciliationMode($modeRaw);
    if ($mode === 'rebuild_unlocked') {
        return 'Delete unlocked pallets and rebuild';
    }
    return 'Keep existing unlocked pallets';
}

function getReconciliationModeDescription($modeRaw) {
    $mode = normalizeReconciliationMode($modeRaw);
    if ($mode === 'rebuild_unlocked') {
        return 'For reduced/removed wattages, all unlocked pallets are removed so you can repalletize cleanly.';
    }
    return 'For reduced/removed wattages, keep as many unlocked pallets as possible within the new quantities.';
}

function buildReconciliationSignature($batchId, $rows, $trackDomestic, $confirmDeletePallets, $reconciliationMode = 'reassign_unlocked') {
    $normalized = [];
    foreach ($rows as $row) {
        $normalized[] = [
            'wattage' => (int)$row['wattage'],
            'quantity' => (int)$row['quantity'],
            'domestic_content_pct' => $row['domestic_content_pct'] === null ? null : round((float)$row['domestic_content_pct'], 6),
        ];
    }
    usort($normalized, function ($a, $b) {
        return (int)$a['wattage'] <=> (int)$b['wattage'];
    });

    $payload = [
        'batch_id' => (int)$batchId,
        'track_domestic_content' => (bool)$trackDomestic,
        'confirm_delete_pallets' => (bool)$confirmDeletePallets,
        'reconciliation_mode' => normalizeReconciliationMode($reconciliationMode),
        'rows' => $normalized
    ];

    return hash('sha256', json_encode($payload));
}

function planUnlockedPalletReconciliation($mode, $unlockedPallets, $targetUnlockedModulesToKeep) {
    $mode = normalizeReconciliationMode($mode);
    $target = max(0, (int)$targetUnlockedModulesToKeep);
    $rows = is_array($unlockedPallets) ? $unlockedPallets : [];
    usort($rows, function ($a, $b) {
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    $result = [
        'keep_ids' => [],
        'delete_ids' => [],
        'kept_modules' => 0,
        'deleted_modules' => 0
    ];

    if ($mode === 'rebuild_unlocked') {
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $qty = (int)($row['quantity'] ?? 0);
            if ($id <= 0) { continue; }
            $result['delete_ids'][] = $id;
            $result['deleted_modules'] += $qty;
        }
        return $result;
    }

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $qty = (int)($row['quantity'] ?? 0);
        if ($id <= 0) { continue; }
        if ($qty <= 0) {
            $result['delete_ids'][] = $id;
            continue;
        }

        if (($result['kept_modules'] + $qty) <= $target) {
            $result['keep_ids'][] = $id;
            $result['kept_modules'] += $qty;
        } else {
            $result['delete_ids'][] = $id;
            $result['deleted_modules'] += $qty;
        }
    }

    return $result;
}

function getPalletImpactForItemIds($conn, $itemIds, $forUpdate = false) {
    $impact = [
        'by_item' => [],
        'all_pallet_ids' => [],
        'locked_pallet_ids' => []
    ];

    if (empty($itemIds) || !is_array($itemIds)) {
        return $impact;
    }

    $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
    if (empty($itemIds)) {
        return $impact;
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $types = str_repeat('i', count($itemIds));
    $sql = "
        SELECT
            ip.id,
            ip.unassigned_module_item_id,
            COALESCE(ip.quantity, 0) AS quantity,
            MAX(CASE WHEN dp.inventory_pallet_id IS NOT NULL THEN 1 ELSE 0 END) AS has_delivery_link,
            MAX(CASE WHEN wcr.pallet_id IS NOT NULL THEN 1 ELSE 0 END) AS has_warranty_link
        FROM inventory_pallets ip
        LEFT JOIN delivery_pallets dp ON dp.inventory_pallet_id = ip.id
        LEFT JOIN warranty_claim_replacements wcr ON wcr.pallet_id = ip.id
        WHERE ip.unassigned_module_item_id IN ($placeholders)
        GROUP BY ip.id, ip.unassigned_module_item_id, ip.quantity
    " . ($forUpdate ? " FOR UPDATE" : "");

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed preparing pallet impact check: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$itemIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $palletId = (int)($row['id'] ?? 0);
        $itemId = (int)($row['unassigned_module_item_id'] ?? 0);
        if ($palletId <= 0 || $itemId <= 0) { continue; }

        if (!isset($impact['by_item'][$itemId])) {
            $impact['by_item'][$itemId] = [
                'count' => 0,
                'modules' => 0,
                'locked_modules' => 0,
                'unlocked_count' => 0,
                'unlocked_modules' => 0,
                'unlocked_pallets' => [],
                'locked_count' => 0,
                'locked_ids' => [],
                'pallet_ids' => []
            ];
        }

        $qty = (int)($row['quantity'] ?? 0);
        $hasDelivery = !empty($row['has_delivery_link']);
        $hasWarranty = !empty($row['has_warranty_link']);
        $isLocked = $hasDelivery || $hasWarranty;

        $impact['by_item'][$itemId]['count'] += 1;
        $impact['by_item'][$itemId]['modules'] += $qty;
        $impact['by_item'][$itemId]['pallet_ids'][] = $palletId;
        $impact['all_pallet_ids'][] = $palletId;

        if ($isLocked) {
            $impact['by_item'][$itemId]['locked_count'] += 1;
            $impact['by_item'][$itemId]['locked_modules'] += $qty;
            $impact['by_item'][$itemId]['locked_ids'][] = $palletId;
            $impact['locked_pallet_ids'][] = $palletId;
        } else {
            $impact['by_item'][$itemId]['unlocked_count'] += 1;
            $impact['by_item'][$itemId]['unlocked_modules'] += $qty;
            $impact['by_item'][$itemId]['unlocked_pallets'][] = [
                'id' => $palletId,
                'quantity' => $qty
            ];
        }
    }
    $stmt->close();

    $impact['all_pallet_ids'] = array_values(array_unique($impact['all_pallet_ids']));
    $impact['locked_pallet_ids'] = array_values(array_unique($impact['locked_pallet_ids']));

    return $impact;
}

function deleteInventoryPalletsByIds($conn, $palletIds) {
    if (empty($palletIds) || !is_array($palletIds)) {
        return 0;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $palletIds), function ($v) {
        return $v > 0;
    })));
    if (empty($ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("DELETE FROM inventory_pallets WHERE id IN ($placeholders)");
    if (!$stmt) {
        throw new Exception('Failed preparing pallet delete: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $deleted = (int)$stmt->affected_rows;
    $stmt->close();
    return $deleted;
}

function syncProjectWattageOrdersCanonical($conn, $projectId) {
    $projectId = (int)$projectId;
    if ($projectId <= 0) {
        return;
    }

    $totals = [];
    $stmtTotals = $conn->prepare("
        SELECT CAST(umi.wattage AS CHAR) AS wattage_key, SUM(umi.quantity) AS total_qty
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ? AND umi.wattage > 0 AND umi.quantity > 0
        GROUP BY umi.wattage
    ");
    if (!$stmtTotals) {
        throw new Exception('Failed preparing canonical totals query: ' . $conn->error);
    }
    $stmtTotals->bind_param('i', $projectId);
    $stmtTotals->execute();
    $resTotals = $stmtTotals->get_result();
    while ($row = $resTotals->fetch_assoc()) {
        $wattage = (string)($row['wattage_key'] ?? '');
        $qty = (int)($row['total_qty'] ?? 0);
        if ($wattage === '' || $qty <= 0) { continue; }
        $totals[$wattage] = $qty;
    }
    $stmtTotals->close();

    $stmtDelete = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ?");
    if (!$stmtDelete) {
        throw new Exception('Failed preparing project_wattage_orders cleanup: ' . $conn->error);
    }
    $stmtDelete->bind_param('i', $projectId);
    $stmtDelete->execute();
    $stmtDelete->close();

    if (!empty($totals)) {
        $stmtInsert = $conn->prepare("INSERT INTO project_wattage_orders (project_id, wattage, total_order) VALUES (?, ?, ?)");
        if (!$stmtInsert) {
            throw new Exception('Failed preparing project_wattage_orders insert: ' . $conn->error);
        }
        foreach ($totals as $wattage => $qty) {
            $stmtInsert->bind_param('isi', $projectId, $wattage, $qty);
            $stmtInsert->execute();
        }
        $stmtInsert->close();
    }
}

function buildReconciliationPreview($conn, $batchId, $currentWattages, $newRows, $trackDomesticContent, $confirmDeletePallets, $projectSizeMw, $currentOrderedMw, $thisBatchMw, $reconciliationMode = 'reassign_unlocked') {
    $reconciliationMode = normalizeReconciliationMode($reconciliationMode);
    $reconciliationModeLabel = getReconciliationModeLabel($reconciliationMode);
    $reconciliationModeDescription = getReconciliationModeDescription($reconciliationMode);
    $existingByWattage = [];
    $existingRowsForDomestic = [];
    $itemIds = [];
    foreach ($currentWattages as $row) {
        $w = (int)($row['wattage'] ?? 0);
        if ($w <= 0) { continue; }
        $itemId = (int)($row['id'] ?? 0);
        $itemIds[] = $itemId;
        $existingByWattage[$w] = [
            'id' => $itemId,
            'wattage' => $w,
            'quantity' => (int)($row['quantity'] ?? 0),
            'domestic_content_pct' => (isset($row['domestic_content_pct']) && $row['domestic_content_pct'] !== null) ? (float)$row['domestic_content_pct'] : null,
        ];
        $existingRowsForDomestic[] = [
            'wattage' => $w,
            'quantity' => (int)($row['quantity'] ?? 0),
            'domestic_content_pct' => (isset($row['domestic_content_pct']) && $row['domestic_content_pct'] !== null) ? (float)$row['domestic_content_pct'] : null,
        ];
    }

    $newByWattage = [];
    foreach ($newRows as $row) {
        $w = (int)($row['wattage'] ?? 0);
        if ($w <= 0) { continue; }
        $newByWattage[$w] = [
            'wattage' => $w,
            'quantity' => (int)($row['quantity'] ?? 0),
            'domestic_content_pct' => (isset($row['domestic_content_pct']) && $row['domestic_content_pct'] !== null) ? (float)$row['domestic_content_pct'] : null,
        ];
    }

    $palletImpact = getPalletImpactForItemIds($conn, $itemIds);
    foreach ($existingByWattage as $w => $existing) {
        $itemId = (int)$existing['id'];
        $agg = $palletImpact['by_item'][$itemId] ?? [
            'count' => 0,
            'modules' => 0,
            'locked_count' => 0,
            'locked_modules' => 0,
            'unlocked_count' => 0,
            'unlocked_modules' => 0,
            'unlocked_pallets' => [],
            'locked_ids' => [],
            'pallet_ids' => []
        ];
        $existingByWattage[$w]['pallet_count'] = (int)$agg['count'];
        $existingByWattage[$w]['pallet_modules'] = (int)$agg['modules'];
        $existingByWattage[$w]['locked_pallet_count'] = (int)$agg['locked_count'];
        $existingByWattage[$w]['locked_modules'] = (int)($agg['locked_modules'] ?? 0);
        $existingByWattage[$w]['unlocked_pallet_count'] = (int)($agg['unlocked_count'] ?? 0);
        $existingByWattage[$w]['unlocked_modules'] = (int)($agg['unlocked_modules'] ?? 0);
        $existingByWattage[$w]['unlocked_pallets'] = $agg['unlocked_pallets'] ?? [];
        $existingByWattage[$w]['locked_pallet_ids'] = array_values(array_unique(array_map('intval', $agg['locked_ids'] ?? [])));
        $existingByWattage[$w]['pallet_ids'] = array_values(array_unique(array_map('intval', $agg['pallet_ids'] ?? [])));
    }

    $allWattages = array_values(array_unique(array_merge(array_keys($existingByWattage), array_keys($newByWattage))));
    sort($allWattages, SORT_NUMERIC);

    $changes = [];
    $allocationByWattage = [];
    $affectedPallets = [];
    $warnings = [];
    $blockers = [];
    $blockedLinkedIds = [];
    $changedCount = 0;

    $currentTotalModules = 0;
    $newTotalModules = 0;
    $currentTotalWatts = 0.0;
    $newTotalWatts = 0.0;

    foreach ($allWattages as $wattage) {
        $existing = $existingByWattage[$wattage] ?? null;
        $proposed = $newByWattage[$wattage] ?? null;

        $currentQty = (int)($existing['quantity'] ?? 0);
        $newQty = (int)($proposed['quantity'] ?? 0);
        $delta = $newQty - $currentQty;
        $currentTotalModules += $currentQty;
        $newTotalModules += $newQty;
        $currentTotalWatts += ((float)$wattage * (float)$currentQty);
        $newTotalWatts += ((float)$wattage * (float)$newQty);

        $currentDomestic = $existing['domestic_content_pct'] ?? null;
        $newDomestic = $proposed['domestic_content_pct'] ?? null;
        $domesticChanged = (($currentDomestic === null) xor ($newDomestic === null))
            || ($currentDomestic !== null && $newDomestic !== null && abs((float)$currentDomestic - (float)$newDomestic) > 0.0001);

        $palletCount = (int)($existing['pallet_count'] ?? 0);
        $palletModules = (int)($existing['pallet_modules'] ?? 0);
        $lockedPalletCount = (int)($existing['locked_pallet_count'] ?? 0);
        $lockedModules = (int)($existing['locked_modules'] ?? 0);
        $unlockedPalletCount = (int)($existing['unlocked_pallet_count'] ?? 0);
        $unlockedModules = (int)($existing['unlocked_modules'] ?? 0);
        $unlockedPallets = is_array($existing['unlocked_pallets'] ?? null) ? $existing['unlocked_pallets'] : [];
        $lockedPalletIds = $existing['locked_pallet_ids'] ?? [];
        $palletIds = $existing['pallet_ids'] ?? [];

        $action = 'unchanged';
        if ($existing === null && $proposed !== null) {
            $action = 'add';
        } elseif ($existing !== null && $proposed === null) {
            $action = 'remove';
        } elseif ($delta !== 0 || $domesticChanged) {
            $action = 'update';
        }

        $isChanged = ($action !== 'unchanged');
        if ($isChanged) {
            $changedCount++;
            if ($palletCount > 0) {
                $affectedPallets[] = [
                    'wattage' => $wattage,
                    'pallet_count' => $palletCount,
                    'pallet_modules' => $palletModules,
                    'locked_pallet_count' => $lockedPalletCount,
                    'pallet_ids_sample' => array_slice($palletIds, 0, 10),
                    'locked_pallet_ids_sample' => array_slice($lockedPalletIds, 0, 10)
                ];
            }
            $blockedLinkedIds = array_merge($blockedLinkedIds, $lockedPalletIds);
        }

        $quantityReduced = ($existing !== null && $newQty < $currentQty);
        $reconcilePlan = ['keep_ids' => [], 'delete_ids' => [], 'kept_modules' => 0, 'deleted_modules' => 0];
        if ($action === 'remove') {
            $reconcilePlan = planUnlockedPalletReconciliation($reconciliationMode, $unlockedPallets, 0);
        } elseif ($quantityReduced) {
            $targetUnlockedToKeep = max(0, $newQty - $lockedModules);
            $reconcilePlan = planUnlockedPalletReconciliation($reconciliationMode, $unlockedPallets, $targetUnlockedToKeep);
        } else {
            $reconcilePlan['keep_ids'] = array_values(array_map(function ($p) { return (int)($p['id'] ?? 0); }, $unlockedPallets));
            $reconcilePlan['kept_modules'] = $unlockedModules;
        }

        $projectedPalletizedModules = ($action === 'remove')
            ? 0
            : ($lockedModules + (int)$reconcilePlan['kept_modules']);
        $overAllocatedModules = max(0, $projectedPalletizedModules - $newQty);
        $remainingToPalletize = max(0, $newQty - $projectedPalletizedModules);
        $requiresDeleteConfirmation = ($action === 'remove' && $palletCount > 0 && !$confirmDeletePallets);

        if ($existing !== null && $newQty < $lockedModules) {
            $blockers[] = "{$wattage}W cannot be reduced to {$newQty}; {$lockedModules} modules are locked downstream.";
        }
        if ($action === 'remove' && $confirmDeletePallets && $lockedPalletCount > 0) {
            $blockers[] = "{$wattage}W cannot be removed; {$lockedPalletCount} pallet(s) are linked downstream.";
        }
        if ($action === 'remove' && $requiresDeleteConfirmation) {
            $warnings[] = "{$wattage}W removal will delete {$palletCount} pallet(s) and {$palletModules} modules after confirmation.";
        } elseif ($action === 'remove' && $confirmDeletePallets && $palletCount > 0 && $lockedPalletCount === 0) {
            $warnings[] = "{$wattage}W removal will delete {$palletCount} unlocked pallet(s).";
        } elseif ($quantityReduced && (int)$reconcilePlan['deleted_modules'] > 0) {
            $warnings[] = "{$wattage}W quantity reduction using \"{$reconciliationModeLabel}\" will remove {$reconcilePlan['deleted_modules']} module(s) across " . count($reconcilePlan['delete_ids']) . " unlocked pallet(s).";
        }
        if ($action === 'add' && $newQty > 0) {
            $warnings[] = "{$wattage}W adds {$newQty} modules that will need palletization.";
        }
        if ($action === 'update' && $remainingToPalletize > 0) {
            $warnings[] = "{$wattage}W will have {$remainingToPalletize} module(s) still needing palletization.";
        }

        $changes[] = [
            'wattage' => $wattage,
            'action' => $action,
            'current_quantity' => $currentQty,
            'new_quantity' => $newQty,
            'delta_quantity' => $delta,
            'current_domestic_content_pct' => $currentDomestic,
            'new_domestic_content_pct' => $newDomestic,
            'domestic_changed' => $domesticChanged,
            'pallet_count' => $palletCount,
            'pallet_modules' => $palletModules,
            'locked_pallet_count' => $lockedPalletCount,
            'locked_modules' => $lockedModules,
            'unlocked_pallet_count' => $unlockedPalletCount,
            'unlocked_modules' => $unlockedModules,
            'projected_palletized_modules' => $projectedPalletizedModules,
            'reconciliation_delete_pallet_count' => count($reconcilePlan['delete_ids']),
            'reconciliation_delete_modules' => (int)$reconcilePlan['deleted_modules'],
            'requires_delete_confirmation' => $requiresDeleteConfirmation
        ];

        $allocationByWattage[] = [
            'wattage' => $wattage,
            'ordered_current' => $currentQty,
            'ordered_new' => $newQty,
            'palletized_modules_current' => $palletModules,
            'palletized_modules_projected' => $projectedPalletizedModules,
            'over_allocated_modules' => $overAllocatedModules,
            'remaining_to_palletize' => $remainingToPalletize
        ];
    }

    $blockedLinkedIds = array_values(array_unique(array_map('intval', $blockedLinkedIds)));
    $warnings = array_values(array_unique($warnings));
    $blockers = array_values(array_unique($blockers));

    $currentDomestic = calculateDomesticMetricsFromRows($existingRowsForDomestic);
    $newDomestic = calculateDomesticMetricsFromRows($newRows);

    $currentBatchMw = $currentTotalWatts / 1000000;
    $newBatchMw = $newTotalWatts / 1000000;

    $projectImpact = null;
    if ((float)$projectSizeMw > 0) {
        $afterProjectTotalMw = ((float)$currentOrderedMw - (float)$thisBatchMw + (float)$newBatchMw);
        $projectImpact = [
            'project_size_mw' => (float)$projectSizeMw,
            'current_total_mw' => (float)$currentOrderedMw,
            'current_batch_mw' => (float)$thisBatchMw,
            'new_batch_mw' => (float)$newBatchMw,
            'after_total_mw' => (float)$afterProjectTotalMw,
            'is_over_capacity' => ((float)$afterProjectTotalMw > (float)$projectSizeMw),
            'over_by_mw' => max(0, ((float)$afterProjectTotalMw - (float)$projectSizeMw))
        ];
    }

    return [
        'signature' => buildReconciliationSignature($batchId, $newRows, $trackDomesticContent, $confirmDeletePallets, $reconciliationMode),
        'reconciliation_mode' => $reconciliationMode,
        'reconciliation_mode_label' => $reconciliationModeLabel,
        'reconciliation_mode_description' => $reconciliationModeDescription,
        'can_apply' => empty($blockers),
        'changed_count' => $changedCount,
        'summary' => [
            'current_total_modules' => $currentTotalModules,
            'new_total_modules' => $newTotalModules,
            'delta_modules' => ($newTotalModules - $currentTotalModules),
            'current_batch_mw' => $currentBatchMw,
            'new_batch_mw' => $newBatchMw,
            'delta_batch_mw' => ($newBatchMw - $currentBatchMw)
        ],
        'changes' => $changes,
        'allocation' => $allocationByWattage,
        'affected_pallets' => [
            'count' => array_sum(array_map(function ($a) { return (int)$a['pallet_count']; }, $affectedPallets)),
            'modules' => array_sum(array_map(function ($a) { return (int)$a['pallet_modules']; }, $affectedPallets)),
            'wattages' => $affectedPallets
        ],
        'blocked_linked_pallets' => [
            'count' => count($blockedLinkedIds),
            'ids_sample' => array_slice($blockedLinkedIds, 0, 20)
        ],
        'domestic_impact' => [
            'before' => $currentDomestic,
            'after' => $newDomestic
        ],
        'project_impact' => $projectImpact,
        'warnings' => $warnings,
        'blockers' => $blockers
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dry-run impact preview (Phase 2)
    if (isset($_POST['action']) && $_POST['action'] === 'preview_reconciliation') {
        $previewErrors = [];
        $posted_watts = $_POST['wattages'] ?? [];
        $posted_qtys = $_POST['quantities'] ?? [];
        $posted_domestic_pcts = $_POST['domestic_content_pcts'] ?? [];
        $track_domestic_content = !empty($_POST['track_domestic_content']);
        $confirm_delete_pallets = isset($_POST['confirm_delete_pallets']) && $_POST['confirm_delete_pallets'] === 'yes';
        $reconciliation_mode = normalizeReconciliationMode($_POST['reconciliation_mode'] ?? 'reassign_unlocked');

        $new_rows = normalizeSubmittedModuleRows(
            $posted_watts,
            $posted_qtys,
            $posted_domestic_pcts,
            $track_domestic_content,
            $previewErrors
        );

        header('Content-Type: application/json');
        if (!empty($previewErrors)) {
            echo json_encode([
                'success' => false,
                'message' => implode(' ', array_map('strval', $previewErrors)),
                'errors' => $previewErrors
            ]);
            $conn->close();
            exit();
        }

        try {
            $preview = buildReconciliationPreview(
                $conn,
                $batch_id,
                $current_wattages,
                $new_rows,
                $track_domestic_content,
                $confirm_delete_pallets,
                $project_size_mw,
                $current_ordered_mw,
                $this_batch_mw,
                $reconciliation_mode
            );

            echo json_encode([
                'success' => true,
                'message' => 'Impact preview generated.',
                'preview' => $preview
            ]);
        } catch (Exception $previewEx) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to generate preview: ' . $previewEx->getMessage()
            ]);
        }
        $conn->close();
        exit();
    }

    // Handle delete action
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        try {
            $conn->begin_transaction();
            $auditMetaBeforeDelete = mr_get_module_batch_meta($conn, $batch_id);
            $auditRowsBeforeDelete = mr_get_module_batch_rows($conn, $batch_id);

            // Get all unassigned_module_items for this batch to delete related pallets
            $stmtGetItems = $conn->prepare("SELECT id FROM unassigned_module_items WHERE unassigned_module_id = ?");
            $stmtGetItems->bind_param("i", $batch_id);
            $stmtGetItems->execute();
            $itemsResult = $stmtGetItems->get_result();
            $itemIds = [];
            while ($row = $itemsResult->fetch_assoc()) {
                $itemIds[] = $row['id'];
            }
            $stmtGetItems->close();

            // Delete inventory_pallets associated with these items
            if (!empty($itemIds)) {
                $lockedPallets = getLockedPalletsForItemIds($conn, $itemIds);
                if (!empty($lockedPallets)) {
                    $lockedSummary = [];
                    foreach ($lockedPallets as $lp) {
                        $flags = [];
                        if (!empty($lp['delivery'])) { $flags[] = 'delivery'; }
                        if (!empty($lp['warranty'])) { $flags[] = 'warranty'; }
                        $lockedSummary[] = 'P' . $lp['id'] . ' (' . implode('/', $flags) . ')';
                    }
                    throw new Exception(
                        'Cannot delete batch because some pallets are linked downstream: ' .
                        implode(', ', $lockedSummary) .
                        '. Remove downstream links first.'
                    );
                }

                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $stmtDelPallets = $conn->prepare("DELETE FROM inventory_pallets WHERE unassigned_module_item_id IN ($placeholders)");
                $types = str_repeat('i', count($itemIds));
                $stmtDelPallets->bind_param($types, ...$itemIds);
                $stmtDelPallets->execute();
                $stmtDelPallets->close();
            }

            // Delete unassigned_module_items
            $stmtDelItems = $conn->prepare("DELETE FROM unassigned_module_items WHERE unassigned_module_id = ?");
            $stmtDelItems->bind_param("i", $batch_id);
            $stmtDelItems->execute();
            $stmtDelItems->close();

            // Delete the module batch itself
            $stmtDelModule = $conn->prepare("DELETE FROM modules WHERE id = ?");
            $stmtDelModule->bind_param("i", $batch_id);
            $stmtDelModule->execute();
            $stmtDelModule->close();

            if ($project_id > 0) {
                syncProjectWattageOrdersCanonical($conn, $project_id);
            }

            mr_insert_reconciliation_audit($conn, [
                'module_batch_id' => $batch_id,
                'project_id' => (int)($auditMetaBeforeDelete['project_id'] ?? 0),
                'account_id' => (int)($auditMetaBeforeDelete['account_id'] ?? 0),
                'action_type' => 'batch_delete',
                'reason' => 'Batch deleted from edit_module_batch.php',
                'reconciliation_mode' => 'n/a',
                'preview_signature' => '',
                'actor_user_id' => (int)$user_id,
                'actor_role' => (string)$role,
                'source_page' => 'edit_module_batch.php',
                'before_state' => [
                    'module' => $auditMetaBeforeDelete,
                    'rows' => $auditRowsBeforeDelete
                ],
                'after_state' => null,
                'impact' => [
                    'confirm_delete_pallets' => true
                ]
            ]);

            $conn->commit();

            // Return JSON for AJAX requests
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Module batch deleted successfully!',
                    'action' => 'delete',
                    'project_id' => $project_id,
                    'redirect_url' => ($project_id > 0) ? "project_overview.php?project_id={$project_id}" : 'modules.php'
                ]);
                $conn->close();
                exit();
            }

            // Redirect to appropriate page (non-AJAX fallback)
            $redir = ($project_id > 0) ? ('project_overview.php?project_id=' . $project_id . '&success=batch_deleted') : 'modules.php?success=batch_deleted';
            header('Location: ' . $redir);
            exit();
        } catch (Exception $ex) {
            $conn->rollback();
            $error_message = 'Error deleting module batch: ' . $ex->getMessage();

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
    }

    // Read posted values
    $batch_name = isset($_POST['batch_name']) && trim($_POST['batch_name']) !== '' ? trim($_POST['batch_name']) : null;
    $manufacturer_id = isset($_POST['manufacturer_id']) && $_POST['manufacturer_id'] !== '' ? (int)$_POST['manufacturer_id'] : null;
    $location_id = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;

    $modules_per_pallet = isset($_POST['modules_per_pallet']) && $_POST['modules_per_pallet'] !== '' ? (int)$_POST['modules_per_pallet'] : null;
    $pallets_per_truck = isset($_POST['pallets_per_truck']) && $_POST['pallets_per_truck'] !== '' ? (int)$_POST['pallets_per_truck'] : null;
    $modules_per_truck = isset($_POST['modules_per_truck']) && $_POST['modules_per_truck'] !== '' ? (int)$_POST['modules_per_truck'] : null;
    $pallet_length_mm = isset($_POST['pallet_length_mm']) && $_POST['pallet_length_mm'] !== '' ? (int)$_POST['pallet_length_mm'] : null;
    $pallet_depth_mm = isset($_POST['pallet_depth_mm']) && $_POST['pallet_depth_mm'] !== '' ? (int)$_POST['pallet_depth_mm'] : null;
    $pallet_double_stacked_height_mm = isset($_POST['pallet_double_stacked_height_mm']) && $_POST['pallet_double_stacked_height_mm'] !== '' ? (int)$_POST['pallet_double_stacked_height_mm'] : null;
    $pallet_total_weight_kg = isset($_POST['pallet_total_weight_kg']) && $_POST['pallet_total_weight_kg'] !== '' ? (int)$_POST['pallet_total_weight_kg'] : null;
    $forklift_truck_long_side_mm = isset($_POST['forklift_truck_long_side_mm']) && $_POST['forklift_truck_long_side_mm'] !== '' ? (int)$_POST['forklift_truck_long_side_mm'] : null;
    $forklift_truck_short_side_mm = isset($_POST['forklift_truck_short_side_mm']) && $_POST['forklift_truck_short_side_mm'] !== '' ? (int)$_POST['forklift_truck_short_side_mm'] : null;
    $pallet_jack_long_side_mm = isset($_POST['pallet_jack_long_side_mm']) && $_POST['pallet_jack_long_side_mm'] !== '' ? (int)$_POST['pallet_jack_long_side_mm'] : null;
    $pallet_jack_short_side_mm = isset($_POST['pallet_jack_short_side_mm']) && $_POST['pallet_jack_short_side_mm'] !== '' ? (int)$_POST['pallet_jack_short_side_mm'] : null;
    $stacking_in_warehouse = trim($_POST['stacking_in_warehouse'] ?? '');
    $stacking_during_transport = trim($_POST['stacking_during_transport'] ?? '');
    $module_notes = trim($_POST['module_notes'] ?? '');
    $cost_per_watt = isset($_POST['cost_per_watt']) && $_POST['cost_per_watt'] !== '' ? floatval($_POST['cost_per_watt']) : null;
    $po_execution_date = isset($_POST['po_execution_date']) && $_POST['po_execution_date'] !== '' ? trim($_POST['po_execution_date']) : null;

    $posted_watts = $_POST['wattages'] ?? [];
    $posted_qtys = $_POST['quantities'] ?? [];
    $posted_domestic_pcts = $_POST['domestic_content_pcts'] ?? [];
    $track_domestic_content = !empty($_POST['track_domestic_content']);
    $reconciliation_mode = normalizeReconciliationMode($_POST['reconciliation_mode'] ?? 'reassign_unlocked');
    $posted_milestones = $_POST['milestones'] ?? [];

    if (milestone_requires_po_execution_date($posted_milestones) && empty($po_execution_date)) {
        $errors[] = 'PO Execution date is required when a PO Execution milestone is configured.';
    }

    // Build vendor/location
    $vendor_name = $module['vendor_name'] ?: 'Unknown Manufacturer';
    $initial_location = $module['initial_location'] ?: '';
    if ($manufacturer_id) {
        if ($stmtV = $conn->prepare("SELECT name FROM manufacturers WHERE id = ?")) {
            $stmtV->bind_param('i', $manufacturer_id);
            $stmtV->execute();
            $stmtV->bind_result($v);
            if ($stmtV->fetch()) { $vendor_name = $v; }
            $stmtV->close();
        }
        if ($location_id && ($stmtL = $conn->prepare("SELECT ml.street_address, ml.city, ml.state, ml.zip_code FROM manufacturer_locations ml JOIN manufacturers m ON m.id = ml.manufacturer_id WHERE ml.id = ?"))) {
            $stmtL->bind_param('i', $location_id);
            $stmtL->execute();
            $stmtL->bind_result($st, $ci, $stt, $zp);
            if ($stmtL->fetch()) { $initial_location = implode(', ', array_filter([$st, $ci, $stt, $zp])); }
            $stmtL->close();
        }
    }
    if ($initial_location === '') { $initial_location = 'Unassigned'; }

    // Validate wattages
    $new_rows = normalizeSubmittedModuleRows(
        $posted_watts,
        $posted_qtys,
        $posted_domestic_pcts,
        $track_domestic_content,
        $errors
    );

    // Guardrail: do not allow reducing ordered quantity below locked palletized modules.
    if (empty($errors) && !empty($new_rows)) {
        $itemIdsForImpact = [];
        foreach ($current_wattages as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) { $itemIdsForImpact[] = $id; }
        }
        $impactByItem = getPalletImpactForItemIds($conn, $itemIdsForImpact);

        $existingByWattage = [];
        foreach ($current_wattages as $row) {
            $itemId = (int)($row['id'] ?? 0);
            $agg = $impactByItem['by_item'][$itemId] ?? [];
            $row['locked_modules'] = (int)($agg['locked_modules'] ?? 0);
            $existingByWattage[(int)$row['wattage']] = $row;
        }
        foreach ($new_rows as $row) {
            $w = (int)$row['wattage'];
            $q = (int)$row['quantity'];
            if (isset($existingByWattage[$w])) {
                $lockedModules = (int)($existingByWattage[$w]['locked_modules'] ?? 0);
                if ($q < $lockedModules) {
                    $errors[] = "Cannot set {$w}W quantity to {$q}: {$lockedModules} modules are locked downstream.";
                    break;
                }
            }
        }
    }

    $confirm_delete_pallets = isset($_POST['confirm_delete_pallets']) && $_POST['confirm_delete_pallets'] === 'yes';

    // Require client-side preview confirmation for structural edits.
    if (empty($errors)) {
        $previewConfirmed = isset($_POST['preview_confirmed']) && $_POST['preview_confirmed'] === 'yes';
        $postedPreviewSignature = trim((string)($_POST['preview_signature'] ?? ''));
        $expectedPreviewSignature = buildReconciliationSignature($batch_id, $new_rows, $track_domestic_content, $confirm_delete_pallets, $reconciliation_mode);

        if (!$previewConfirmed || $postedPreviewSignature === '' || !hash_equals($expectedPreviewSignature, $postedPreviewSignature)) {
            $errors[] = 'Please review and confirm the reconciliation impact preview before saving.';
        }
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();
            $auditMetaBeforeSave = mr_get_module_batch_meta($conn, $batch_id);
            $auditRowsBeforeSave = mr_get_module_batch_rows($conn, $batch_id);
            $auditBeforeFingerprint = mr_module_rows_fingerprint($auditRowsBeforeSave);

            // Update modules
            $stmtU = $conn->prepare('UPDATE modules SET batch_name=?, vendor_name=?, initial_location=?, modules_per_pallet=?, pallets_per_truck=?, modules_per_truck=?, pallet_length_mm=?, pallet_depth_mm=?, pallet_double_stacked_height_mm=?, pallet_total_weight_kg=?, stacking_in_warehouse=?, stacking_during_transport=?, forklift_truck_long_side_mm=?, forklift_truck_short_side_mm=?, pallet_jack_long_side_mm=?, pallet_jack_short_side_mm=?, module_notes=?, cost_per_watt=?, po_execution_date=?, last_updated_at=NOW() WHERE id=?');
            $stmtU->bind_param('sssiiiiiiissiiiisdsi',
                $batch_name, $vendor_name, $initial_location, $modules_per_pallet, $pallets_per_truck, $modules_per_truck,
                $pallet_length_mm, $pallet_depth_mm, $pallet_double_stacked_height_mm, $pallet_total_weight_kg,
                $stacking_in_warehouse, $stacking_during_transport,
                $forklift_truck_long_side_mm, $forklift_truck_short_side_mm, $pallet_jack_long_side_mm, $pallet_jack_short_side_mm,
                $module_notes, $cost_per_watt, $po_execution_date, $batch_id
            );
            if (!$stmtU->execute()) { throw new Exception('Failed updating module: '.$stmtU->error); }
            $stmtU->close();

            // Lock current module items for this batch to avoid concurrent structural edits.
            $current_items_tx = [];
            $stmtLockItems = $conn->prepare('SELECT id, wattage, quantity, domestic_content_pct FROM unassigned_module_items WHERE unassigned_module_id = ? ORDER BY wattage ASC FOR UPDATE');
            if (!$stmtLockItems) { throw new Exception('Failed locking module items: ' . $conn->error); }
            $stmtLockItems->bind_param('i', $batch_id);
            $stmtLockItems->execute();
            $resLockItems = $stmtLockItems->get_result();
            while ($row = $resLockItems->fetch_assoc()) {
                $current_items_tx[] = $row;
            }
            $stmtLockItems->close();

            // Map existing items by wattage
            $existing_items = [];
            $itemIdsTx = [];
            $duplicateWattagesTx = [];
            foreach ($current_items_tx as $row) {
                $wattageTx = (int)($row['wattage'] ?? 0);
                if (isset($existing_items[$wattageTx])) {
                    $duplicateWattagesTx[$wattageTx] = true;
                    continue;
                }
                $existing_items[$wattageTx] = $row;
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) { $itemIdsTx[] = $id; }
            }
            if (!empty($duplicateWattagesTx)) {
                throw new Exception(
                    'Data integrity issue: duplicate module rows exist for wattage(s): ' .
                    implode(', ', array_keys($duplicateWattagesTx)) .
                    '. Apply Phase 4 module reconciliation migration, then retry.'
                );
            }

            // Lock pallet rows for these items and compute lock/unlock impact.
            $palletImpactTx = getPalletImpactForItemIds($conn, $itemIdsTx, true);
            foreach ($existing_items as $w => $item) {
                $itemId = (int)$item['id'];
                $agg = $palletImpactTx['by_item'][$itemId] ?? [
                    'count' => 0,
                    'modules' => 0,
                    'locked_count' => 0,
                    'locked_modules' => 0,
                    'unlocked_count' => 0,
                    'unlocked_modules' => 0,
                    'unlocked_pallets' => [],
                    'locked_ids' => [],
                    'pallet_ids' => []
                ];
                $existing_items[$w]['pallet_count'] = (int)$agg['count'];
                $existing_items[$w]['pallet_modules'] = (int)$agg['modules'];
                $existing_items[$w]['locked_pallet_count'] = (int)$agg['locked_count'];
                $existing_items[$w]['locked_modules'] = (int)$agg['locked_modules'];
                $existing_items[$w]['unlocked_pallet_count'] = (int)$agg['unlocked_count'];
                $existing_items[$w]['unlocked_modules'] = (int)$agg['unlocked_modules'];
                $existing_items[$w]['unlocked_pallets'] = $agg['unlocked_pallets'] ?? [];
                $existing_items[$w]['locked_pallet_ids'] = array_values(array_unique(array_map('intval', $agg['locked_ids'] ?? [])));
            }

            // Build posted map.
            $newByWattage = [];
            foreach ($new_rows as $newRow) {
                $newByWattage[(int)$newRow['wattage']] = $newRow;
            }

            // Phase 3 reconciliation step: for reductions/removals, reconcile unlocked pallets
            // according to selected mode while never touching downstream-locked pallets.
            $posted_watts_set = [];
            $new_totals = [];
            foreach ($new_rows as $newRow) {
                $w = (int)$newRow['wattage'];
                $posted_watts_set[$w] = true;
                $new_totals[$w] = ($new_totals[$w] ?? 0) + (int)$newRow['quantity'];
            }

            foreach ($existing_items as $w => $item) {
                $currentQty = (int)($item['quantity'] ?? 0);
                $newQty = isset($newByWattage[$w]) ? (int)$newByWattage[$w]['quantity'] : 0;
                $isRemoved = !isset($newByWattage[$w]);
                $lockedModules = (int)($item['locked_modules'] ?? 0);
                $lockedPalletCount = (int)($item['locked_pallet_count'] ?? 0);
                $palletCount = (int)($item['pallet_count'] ?? 0);
                $palletModules = (int)($item['pallet_modules'] ?? 0);
                $unlockedPallets = is_array($item['unlocked_pallets'] ?? null) ? $item['unlocked_pallets'] : [];
                $lockedPalletIds = $item['locked_pallet_ids'] ?? [];

                if ($newQty < $lockedModules) {
                    throw new Exception("Cannot set {$w}W quantity to {$newQty}: {$lockedModules} modules are locked downstream.");
                }

                if ($isRemoved) {
                    if ($palletCount > 0 && !$confirm_delete_pallets) {
                        throw new Exception("Cannot remove {$w}W wattage: {$palletCount} pallet(s) with {$palletModules} modules exist. Please confirm deletion or keep this wattage.");
                    }
                    if ($lockedPalletCount > 0) {
                        throw new Exception(
                            "Cannot remove {$w}W wattage: {$lockedPalletCount} pallet(s) are linked downstream (" .
                            implode(', ', array_map(function ($id) { return 'P' . (int)$id; }, $lockedPalletIds)) .
                            "). Remove downstream links first."
                        );
                    }
                    $deleteIds = array_map(function ($p) { return (int)($p['id'] ?? 0); }, $unlockedPallets);
                    deleteInventoryPalletsByIds($conn, $deleteIds);
                    continue;
                }

                if ($newQty < $currentQty) {
                    $targetUnlockedToKeep = max(0, $newQty - $lockedModules);
                    $plan = planUnlockedPalletReconciliation($reconciliation_mode, $unlockedPallets, $targetUnlockedToKeep);
                    if (!empty($plan['delete_ids'])) {
                        deleteInventoryPalletsByIds($conn, $plan['delete_ids']);
                    }
                }
            }

            // Apply new pairs to canonical module-item rows.
            foreach ($new_rows as $newRow) {
                $w = (int)$newRow['wattage'];
                $q = (int)$newRow['quantity'];
                $domesticContentPct = $track_domestic_content ? $newRow['domestic_content_pct'] : null;
                if (isset($existing_items[$w])) {
                    if ($track_domestic_content) {
                        $stmtE = $conn->prepare('UPDATE unassigned_module_items SET quantity = ?, domestic_content_pct = ? WHERE id = ?');
                        $stmtE->bind_param('idi', $q, $domesticContentPct, $existing_items[$w]['id']);
                    } else {
                        $stmtE = $conn->prepare('UPDATE unassigned_module_items SET quantity = ?, domestic_content_pct = NULL WHERE id = ?');
                        $stmtE->bind_param('ii', $q, $existing_items[$w]['id']);
                    }
                    if (!$stmtE->execute()) { throw new Exception('Failed updating item: '.$stmtE->error); }
                    $stmtE->close();
                } else {
                    if ($track_domestic_content) {
                        $stmtI = $conn->prepare('INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity, domestic_content_pct) VALUES (?, ?, ?, ?)');
                        $stmtI->bind_param('iiid', $batch_id, $w, $q, $domesticContentPct);
                    } else {
                        $stmtI = $conn->prepare('INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)');
                        $stmtI->bind_param('iii', $batch_id, $w, $q);
                    }
                    if (!$stmtI->execute()) { throw new Exception('Failed inserting item: '.$stmtI->error); }
                    $stmtI->close();
                }
            }

            // Remove items not present in posted wattages after pallet reconciliation.
            foreach ($existing_items as $w => $item) {
                if (!isset($posted_watts_set[$w])) {
                    $stmtC = $conn->prepare('SELECT COUNT(*) AS c FROM inventory_pallets WHERE unassigned_module_item_id = ?');
                    $stmtC->bind_param('i', $item['id']);
                    $stmtC->execute();
                    $palletInfo = $stmtC->get_result()->fetch_assoc();
                    $pallet_count = (int)($palletInfo['c'] ?? 0);
                    $stmtC->close();
                    if ($pallet_count > 0) {
                        throw new Exception("Cannot remove {$w}W wattage: {$pallet_count} pallet(s) still exist after reconciliation.");
                    }

                    $stmtD = $conn->prepare('DELETE FROM unassigned_module_items WHERE id = ?');
                    $stmtD->bind_param('i', $item['id']);
                    if (!$stmtD->execute()) { throw new Exception('Failed deleting item: '.$stmtD->error); }
                    $stmtD->close();
                }
            }

            // Phase 3 canonical roll-up: rebuild project wattage totals from source rows.
            if ($project_id > 0) {
                syncProjectWattageOrdersCanonical($conn, $project_id);
            }

            $auditMetaAfterSave = mr_get_module_batch_meta($conn, $batch_id);
            $auditRowsAfterSave = mr_get_module_batch_rows($conn, $batch_id);
            $auditAfterFingerprint = mr_module_rows_fingerprint($auditRowsAfterSave);
            if (!hash_equals($auditBeforeFingerprint, $auditAfterFingerprint)) {
                mr_insert_reconciliation_audit($conn, [
                    'module_batch_id' => $batch_id,
                    'project_id' => (int)($auditMetaAfterSave['project_id'] ?? 0),
                    'account_id' => (int)($auditMetaAfterSave['account_id'] ?? 0),
                    'action_type' => 'batch_structural_update',
                    'reason' => 'Structural rows edited in batch editor',
                    'reconciliation_mode' => $reconciliation_mode,
                    'preview_signature' => $postedPreviewSignature,
                    'actor_user_id' => (int)$user_id,
                    'actor_role' => (string)$role,
                    'source_page' => 'edit_module_batch.php',
                    'before_state' => [
                        'module' => $auditMetaBeforeSave,
                        'rows' => $auditRowsBeforeSave
                    ],
                    'after_state' => [
                        'module' => $auditMetaAfterSave,
                        'rows' => $auditRowsAfterSave
                    ],
                    'impact' => [
                        'confirm_delete_pallets' => (bool)$confirm_delete_pallets,
                        'track_domestic_content' => (bool)$track_domestic_content,
                        'submitted_rows' => $new_rows
                    ]
                ]);
            }

            $conn->commit();

            // Save milestones
            save_module_milestones($batch_id, $posted_milestones, $conn, $user_id);

            // Calculate total modules for response
            $total_modules = 0;
            foreach ($posted_watts as $i => $w) {
                $q = intval(trim($posted_qtys[$i] ?? 0));
                if ($q > 0) $total_modules += $q;
            }

            // Return JSON for AJAX requests
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Module batch updated successfully!',
                    'action' => 'update',
                    'batch_id' => $batch_id,
                    'total_modules' => $total_modules,
                    'project_id' => $project_id,
                    'redirect_url' => ($project_id > 0) ? "project_overview.php?project_id={$project_id}" : 'modules.php'
                ]);
                $conn->close();
                exit();
            }

            // Non-AJAX fallback
            $redir = ($project_id > 0) ? ('project_overview.php?project_id='.$project_id.'&success=batch_updated') : 'modules.php?success=batch_updated';
            header('Location: '.$redir);
            exit();
        } catch (Exception $ex) {
            $conn->rollback();
            $error_message = 'Error updating module batch: '.$ex->getMessage();

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
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    $message = !empty($errors) ? implode(' ', array_map('strval', $errors)) : 'An unexpected error occurred. Please try again.';

    header('Content-Type: application/json');
    echo json_encode([
        'success' => empty($errors),
        'message' => $message,
        'errors' => $errors,
        'redirect_url' => ($project_id > 0) ? 'project_overview.php?project_id=' . $project_id : 'modules.php'
    ]);
    $conn->close();
    exit();
}

// Preselects for component
$prefManufacturerId = null; $prefLocationId = null; $existingWattages = [];
// Manufacturer by matching vendor name (trim/case-insensitive), preferring account-owned record.
if (!empty($module['vendor_name'])) {
    $moduleVendorName = trim((string)$module['vendor_name']);
    $moduleAccountId = (int)($module['account_id'] ?? 0);
    $manufacturerHasAccountScope = false;
    $manufacturerHasIsActive = false;
    if ($stmtCol = $conn->prepare("
        SELECT
            SUM(CASE WHEN COLUMN_NAME = 'account_id' THEN 1 ELSE 0 END) AS has_account_id,
            SUM(CASE WHEN COLUMN_NAME = 'is_active' THEN 1 ELSE 0 END) AS has_is_active
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'manufacturers'
          AND COLUMN_NAME IN ('account_id', 'is_active')
    ")) {
        $stmtCol->execute();
        $resCol = $stmtCol->get_result()->fetch_assoc();
        $manufacturerHasAccountScope = ((int)($resCol['has_account_id'] ?? 0) > 0);
        $manufacturerHasIsActive = ((int)($resCol['has_is_active'] ?? 0) > 0);
        $stmtCol->close();
    }

    $activeFilter = $manufacturerHasIsActive ? ' AND is_active = 1' : '';

    if ($manufacturerHasAccountScope) {
        $sqlPM = "
            SELECT id
            FROM manufacturers
            WHERE TRIM(LOWER(name)) = TRIM(LOWER(?))
              {$activeFilter}
            ORDER BY
                CASE
                    WHEN account_id = ? THEN 0
                    WHEN account_id IS NULL THEN 1
                    ELSE 2
                END ASC,
                id ASC
            LIMIT 1
        ";
        if ($stmtPM = $conn->prepare($sqlPM)) {
            $stmtPM->bind_param('si', $moduleVendorName, $moduleAccountId);
            $stmtPM->execute();
            $stmtPM->bind_result($pmid);
            if ($stmtPM->fetch()) { $prefManufacturerId = (int)$pmid; }
            $stmtPM->close();
        }
    } else {
        $sqlPM = "
            SELECT id
            FROM manufacturers
            WHERE TRIM(LOWER(name)) = TRIM(LOWER(?))
              {$activeFilter}
            ORDER BY id ASC
            LIMIT 1
        ";
        if ($stmtPM = $conn->prepare($sqlPM)) {
            $stmtPM->bind_param('s', $moduleVendorName);
            $stmtPM->execute();
            $stmtPM->bind_result($pmid);
            if ($stmtPM->fetch()) { $prefManufacturerId = (int)$pmid; }
            $stmtPM->close();
        }
    }
}

// Location by matching stored initial_location to location name/address.
if ($prefManufacturerId) {
    if ($stmtL = $conn->prepare("
        SELECT ml.id, ml.location_name, ml.street_address, ml.city, ml.state, ml.zip_code
        FROM manufacturer_locations ml
        JOIN manufacturers m ON m.id = ml.manufacturer_id
        WHERE ml.manufacturer_id = ?
    ")) {
        $stmtL->bind_param('i', $prefManufacturerId);
        $stmtL->execute();
        $resL = $stmtL->get_result();
        $targetRaw = trim((string)$module['initial_location']);
        $normalizeLocation = function ($s) {
            $s = strtolower(trim((string)$s));
            $s = preg_replace('/\s+/', ' ', $s);
            return trim((string)$s);
        };
        $target = $normalizeLocation($targetRaw);

        $fallbackLocationId = 0;
        while ($lr = $resL->fetch_assoc()) {
            $locationName = $normalizeLocation($lr['location_name'] ?? '');
            $addr = implode(', ', array_filter([$lr['street_address'], $lr['city'], $lr['state'], $lr['zip_code']]));
            $addrNorm = $normalizeLocation($addr);

            if ($target !== '' && ($locationName === $target || $addrNorm === $target)) {
                $prefLocationId = (int)$lr['id'];
                break;
            }

            if ($target !== '' && ($locationName !== '' || $addrNorm !== '')) {
                if (strpos($locationName, $target) !== false || strpos($target, $locationName) !== false ||
                    strpos($addrNorm, $target) !== false || strpos($target, $addrNorm) !== false) {
                    $fallbackLocationId = (int)$lr['id'];
                }
            }
        }
        if (!$prefLocationId && $fallbackLocationId > 0) {
            $prefLocationId = $fallbackLocationId;
        }
        $stmtL->close();
    }
}
foreach ($current_wattages as $w) {
    if ((int)$w['wattage']>0 && (int)$w['quantity']>0) {
        $existingWattages[] = [
            'wattage'=>(int)$w['wattage'],
            'quantity'=>(int)$w['quantity'],
            'domestic_content_pct'=>isset($w['domestic_content_pct']) && $w['domestic_content_pct'] !== null ? (float)$w['domestic_content_pct'] : null,
            'pallet_count'=>(int)($w['pallet_count'] ?? 0),
            'pallet_modules'=>(int)($w['pallet_modules'] ?? 0)
        ];
    }
}

// Load existing milestones for this batch
$existingMilestones = [];
if ($stmtM = $conn->prepare('SELECT trigger_event, percentage FROM module_batch_milestones WHERE module_id = ? AND is_active = 1 ORDER BY display_order, id')) {
    $stmtM->bind_param('i', $batch_id);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    while ($row = $resM->fetch_assoc()) {
        $existingMilestones[] = [
            'trigger_event' => $row['trigger_event'],
            'percentage' => $row['percentage']
        ];
    }
    $stmtM->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Module Batch<?php echo $module['project_name'] ? (' - '.htmlspecialchars($module['project_name'])) : ''; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <?php include __DIR__ . '/components/module_batch_styles.php'; ?>
    <style>
        .form-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px; padding: 32px; margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative; overflow: hidden;
        }
        .form-header::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .header-content { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 24px; }
        .header-left { display: flex; align-items: center; gap: 24px; }
        .header-info h1 {
            font-size: 2.5em; font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; margin: 0 0 8px 0; line-height: 1.2;
        }
        .header-subtitle { color: #6c757d; font-size: 1.1em; font-weight: 500; margin: 0; }
        .error-message { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        .btn-delete {
            background: #dc3545; color: white; padding: 14px 24px; border: none; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: 0.95rem; cursor: pointer;
            transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-delete:hover { background: #c82333; transform: translateY(-1px); }
        @media (max-width: 768px) {
            .form-header { padding: 20px; margin-bottom: 20px; }
            .header-content { gap: 16px; }
            .header-left { gap: 16px; }
            .header-info h1 { font-size: 2em; }
            .header-subtitle { font-size: 1em; }
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
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        $extra = [];
        if (!$project_id) { $extra[] = ['label' => 'Modules', 'url' => 'modules.php']; }
        echo slp_render_breadcrumbs([
            'current_label' => 'Edit Module Batch',
            'project_id' => (int)$project_id,
            'extra' => $extra
        ]);
    ?>

    <div class="form-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-info">
                    <h1>Edit Module Batch</h1>
                    <p class="header-subtitle">
                        <?php if ($project_id): ?>
                            Editing modules for <?php echo htmlspecialchars($module['project_name']); ?>
                        <?php else: ?>
                            Edit an unassigned module batch
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
                    <input type="radio" name="entry_method" value="import" style="display: none;">
                    <i class="fas fa-file-import" style="color: #6c757d;"></i>
                    <span style="font-weight: 500; color: #6c757d; font-size: 0.9rem;">Import Pallets</span>
                </label>
            </div>
        </div>
    </div>

    <?php if ($project_id > 0 && $project_size_mw > 0): ?>
    <div id="capacityBanner" style="background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%); border: 1px solid #b8daff; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
        <h3 style="margin: 0 0 16px 0; color: #0056b3; font-size: 1.1em; display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 1.2em;">&#9889;</span> Project Capacity Status
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                <div style="font-size: 1.6rem; font-weight: 700; color: #488C9A;"><?php echo number_format($current_ordered_mw, 2); ?> MW</div>
                <div style="font-size: 0.85rem; color: #6c757d;">Total Ordered</div>
            </div>
            <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                <div style="font-size: 1.6rem; font-weight: 700; color: #6c757d;"><?php echo number_format($this_batch_mw, 2); ?> MW</div>
                <div style="font-size: 0.85rem; color: #6c757d;">This Batch (current)</div>
            </div>
            <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                <div style="font-size: 1.6rem; font-weight: 700; color: #293E4C;"><?php echo number_format($project_size_mw, 2); ?> MW</div>
                <div style="font-size: 0.85rem; color: #6c757d;">Project Target</div>
            </div>
            <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                <div style="font-size: 1.6rem; font-weight: 700; color: <?php echo $remaining_mw > 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($remaining_mw, 2); ?> MW</div>
                <div style="font-size: 0.85rem; color: #6c757d;">Remaining</div>
            </div>
        </div>
        <?php $capacity_pct = $project_size_mw > 0 ? min(100, ($current_ordered_mw / $project_size_mw) * 100) : 0; ?>
        <div style="background: #e9ecef; border-radius: 8px; height: 20px; overflow: hidden;">
            <div style="background: linear-gradient(90deg, #488C9A 0%, #3a7086 100%); height: 100%; width: <?php echo $capacity_pct; ?>%; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 11px; font-weight: 600;">
                <?php echo number_format($capacity_pct, 1); ?>%
            </div>
        </div>
        <div id="newMwPreview" style="display: none; margin-top: 16px; padding: 12px; background: white; border-radius: 8px; border: 2px solid #ffc107;"></div>
    </div>
    <?php endif; ?>

    <div id="manualEntryContainer">
        <?php if (!empty($errors)): ?>
            <div class="error-message"><ul style="margin:0; padding-left:20px;">
                <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
            </ul></div>
        <?php endif; ?>

        <?php $formAction = 'edit_module_batch.php?batch_id='.(int)$batch_id; ?>
        <form method="POST" id="editBatchForm" action="<?php echo $formAction; ?>" enctype="multipart/form-data">
            <input type="hidden" name="confirm_delete_pallets" id="confirmDeletePallets" value="no">
            <input type="hidden" name="reconciliation_mode" id="reconciliationMode" value="reassign_unlocked">
            <input type="hidden" name="preview_confirmed" id="previewConfirmed" value="no">
            <input type="hidden" name="preview_signature" id="previewSignature" value="">
            <input type="hidden" name="batch_id" value="<?php echo (int)$batch_id; ?>">

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
                            <div class="section-description">Pallet configuration and dimensions</div>
                        </div>
                    </div>
                    <span class="accordion-tag optional-tag">Optional</span>
                    <span class="accordion-chevron"><i class="fas fa-chevron-down"></i></span>
                </div>
                <div class="accordion-content">
                    <div class="accordion-body">
                        <?php include __DIR__ . '/components/module_batch_step4.php'; ?>
                        <div style="margin: 0 0 14px 0; padding: 12px; border: 1px solid #e9ecef; border-radius: 10px; background: #fafbfc;">
                            <label for="reconciliationModeSelect" style="display: block; font-weight: 600; color: #293E4C; margin-bottom: 6px;">When Quantities Are Reduced or Wattages Are Removed</label>
                            <select id="reconciliationModeSelect" style="min-width: 260px; max-width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid #d0d7de;">
                                <option value="reassign_unlocked" selected>Keep Existing Unlocked Pallets (Recommended)</option>
                                <option value="rebuild_unlocked">Delete Unlocked Pallets and Rebuild</option>
                            </select>
                            <div style="font-size: 0.82rem; color: #6c757d; margin-top: 6px;">
                                <strong>Unlocked pallets</strong> are not tied to deliveries or warranty replacements.
                                <br>
                                <code>Keep Existing</code> minimizes pallet deletions. <code>Delete and Rebuild</code> clears unlocked pallets in changed wattages so you can repalletize from scratch.
                            </div>
                        </div>
                        <div class="section-actions">
                            <button type="button" class="btn-back-step" onclick="goToStep(3)"><i class="fas fa-arrow-left"></i> Back</button>
                            <button type="submit" class="btn-submit"><i class="fas fa-check"></i> Save Module Batch</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delete Batch (footer) -->
            <div style="margin-top: 24px; padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <button type="button" id="deleteBatchBtn" class="btn-delete">
                    <i class="fas fa-trash-alt"></i> Delete Batch
                </button>
                <a href="<?php echo $project_id ? ('project_overview.php?project_id='.(int)$project_id) : 'modules.php'; ?>" style="color: #6c757d; text-decoration: none; font-weight: 500;">Cancel &amp; Go Back</a>
            </div>
        </form>

        <!-- Delete Batch Form (hidden) -->
        <form id="deleteBatchForm" method="POST" action="<?php echo $formAction; ?>" style="display: none;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="batch_id" value="<?php echo (int)$batch_id; ?>">
        </form>

        <!-- Delete Batch Confirmation Modal -->
        <div id="deleteBatchModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
            <div style="background: #fff; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                <h3 style="margin: 0 0 15px; color: #dc3545; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 24px;">&#128465;</span> Delete Module Batch?
                </h3>
                <p style="color: #495057; margin-bottom: 15px;">You are about to permanently delete this module batch:</p>
                <div style="background: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="margin-bottom: 8px;"><strong>Manufacturer:</strong> <?php echo htmlspecialchars($module['vendor_name'] ?? 'Unknown'); ?></div>
                    <div style="margin-bottom: 8px;"><strong>Location:</strong> <?php echo htmlspecialchars($module['initial_location'] ?? 'Unknown'); ?></div>
                    <div style="margin-bottom: 8px;"><strong>Total MW:</strong> <?php echo number_format($this_batch_mw, 2); ?> MW</div>
                    <?php
                    $total_pallets = 0;
                    $total_pallet_modules = 0;
                    foreach ($current_wattages as $cw) {
                        $total_pallets += $cw['pallet_count'];
                        $total_pallet_modules += $cw['pallet_modules'];
                    }
                    if ($total_pallets > 0): ?>
                    <div style="color: #dc3545; font-weight: 500; margin-top: 10px; padding-top: 10px; border-top: 1px solid #dee2e6;">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo $total_pallets; ?> pallet(s) with <?php echo number_format($total_pallet_modules); ?> modules will also be deleted!
                    </div>
                    <?php endif; ?>
                </div>
                <p style="color: #dc3545; font-weight: 500; margin-bottom: 20px;">This action cannot be undone.</p>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" id="cancelDeleteBatch" style="padding: 10px 20px; border: 1px solid #dee2e6; background: #fff; color: #495057; border-radius: 6px; cursor: pointer; font-size: 14px;">Cancel</button>
                    <button type="button" id="confirmDeleteBatch" style="padding: 10px 20px; border: none; background: #dc3545; color: #fff; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">Delete Permanently</button>
                </div>
            </div>
        </div>

        <!-- Pallet Deletion Confirmation Modal -->
        <div id="palletDeleteModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
            <div style="background: #fff; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                <h3 style="margin: 0 0 15px; color: #dc3545; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 24px;">&#9888;&#65039;</span> Warning: Pallets Will Be Deleted
                </h3>
                <p style="color: #495057; margin-bottom: 15px;">You are about to remove the following wattage(s) that have existing pallets:</p>
                <div id="palletDeleteList" style="background: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 20px; max-height: 200px; overflow-y: auto;"></div>
                <p style="color: #dc3545; font-weight: 500; margin-bottom: 20px;">This action cannot be undone. All associated pallets and their modules will be permanently deleted.</p>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" id="cancelPalletDelete" style="padding: 10px 20px; border: 1px solid #dee2e6; background: #fff; color: #495057; border-radius: 6px; cursor: pointer; font-size: 14px;">Cancel</button>
                    <button type="button" id="confirmPalletDelete" style="padding: 10px 20px; border: none; background: #dc3545; color: #fff; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">Delete Pallets &amp; Save</button>
                </div>
            </div>
        </div>

        <!-- Reconciliation Impact Preview Modal -->
        <div id="reconciliationPreviewModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.55); z-index: 11000; align-items: center; justify-content: center;">
            <div style="background: #fff; border-radius: 12px; max-width: 880px; width: 92%; max-height: 85vh; overflow: hidden; box-shadow: 0 14px 44px rgba(0,0,0,0.25); display: flex; flex-direction: column;">
                <div style="padding: 20px 24px; border-bottom: 1px solid #e9ecef; display: flex; align-items: center; justify-content: space-between;">
                    <h3 style="margin: 0; color: #293E4C; font-size: 1.15rem; display: flex; align-items: center; gap: 10px;">
                        <span style="color: #488C9A;">&#128202;</span> Reconciliation Impact Preview
                    </h3>
                    <button type="button" id="closeReconciliationPreview" style="border: none; background: transparent; font-size: 24px; line-height: 1; color: #6c757d; cursor: pointer;">&times;</button>
                </div>
                <div id="reconciliationPreviewBody" style="padding: 20px 24px; overflow-y: auto; color: #293E4C;"></div>
                <div style="padding: 16px 24px; border-top: 1px solid #e9ecef; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" id="cancelReconciliationPreview" style="padding: 10px 18px; border-radius: 8px; border: 1px solid #dee2e6; background: #fff; color: #495057; cursor: pointer;">Cancel</button>
                    <button type="button" id="confirmReconciliationPreview" style="padding: 10px 18px; border-radius: 8px; border: none; background: #488C9A; color: #fff; font-weight: 600; cursor: pointer;">Confirm &amp; Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Container (hidden by default) -->
    <div id="importContainer" style="display: none; background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 40px; margin-bottom: 20px;">
        <div style="text-align: center; padding: 40px 20px;">
            <div style="font-size: 48px; color: #488C9A; margin-bottom: 16px;">&#128230;</div>
            <h2 style="color: #293E4C; margin-bottom: 12px;">Import Pallets</h2>
            <p style="color: #6c757d; margin-bottom: 24px; max-width: 500px; margin-left: auto; margin-right: auto;">
                Upload a CSV or Excel file with pallet data from your manufacturer to add pallets to inventory.
            </p>
            <a href="upload_pallets.php<?php echo $project_id ? '?project_id='.$project_id : ''; ?>"
               class="btn-submit" style="display: inline-block; text-decoration: none; padding: 16px 32px;">
                Import Pallets &rarr;
            </a>
        </div>
    </div>

    <!-- Loading Modal -->
    <div id="loadingModal" class="loading-modal">
        <div class="loading-content">
            <div class="spinner"></div>
            <h3 id="loadingTitle">Saving Changes...</h3>
            <p id="loadingSubtitle">Please wait while we process your request.</p>
        </div>
    </div>

    <!-- Result Modal -->
    <div id="resultModal" class="loading-modal" style="display: none;">
        <div class="loading-content" style="max-width: 480px; padding: 32px 40px;">
            <div id="resultIcon" style="font-size: 56px; margin-bottom: 16px;"></div>
            <h3 id="resultTitle" style="margin: 0 0 12px 0; font-size: 1.4em;"></h3>
            <p id="resultMessage" style="margin: 0 0 8px 0; color: #6c757d;"></p>
            <div id="resultDetails" style="margin: 16px 0; padding: 16px; background: #f8f9fa; border-radius: 8px; text-align: center; display: none;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #488C9A;" id="resultModules">0</div>
                <div style="font-size: 0.85rem; color: #6c757d;">Total Modules</div>
            </div>
            <div style="display: flex; gap: 12px; justify-content: center; margin-top: 24px; flex-wrap: wrap;">
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

<?php
    // Determine which steps have data (for completed indicators)
    $step1_complete = !empty($current_wattages); // has wattages
    $step2_complete = !empty($module['cost_per_watt']) || !empty($existingMilestones);
    $step3_complete = !empty($module['forklift_truck_long_side_mm'])
        || !empty($module['stacking_in_warehouse'])
        || !empty($module['stacking_during_transport'])
        || !empty($module['module_notes'])
        || !empty($module['module_docs_url']);
    $step4_complete = !empty($module['modules_per_pallet']) || !empty($module['pallet_length_mm']);
    $completedSteps = [];
    if ($step1_complete) $completedSteps[] = 1;
    if ($step2_complete) $completedSteps[] = 2;
    if ($step3_complete) $completedSteps[] = 3;
    if ($step4_complete) $completedSteps[] = 4;
?>
<?php include __DIR__ . '/components/module_batch_scripts.php'; ?>
<script>
    // ========== Wizard Navigation ==========
    var stepNames = { 1: 'Manufacturer & Modules', 2: 'Pricing & Milestones', 3: 'Logistics & Docs', 4: 'Palletization' };
    var currentStep = 1;
    var completedSteps = <?php echo json_encode($completedSteps); ?>;

    function goToStep(step) {
        if (step < 1 || step > 4) return;
        if (step > currentStep && !validateStep(currentStep)) return;
        var currentSection = document.querySelector('.accordion-section[data-section="' + currentStep + '"]');
        if (currentSection) {
            currentSection.classList.remove('active');
            var content = currentSection.querySelector('.accordion-content');
            if (content) content.style.maxHeight = '0';
        }
        var targetSection = document.querySelector('.accordion-section[data-section="' + step + '"]');
        if (targetSection) {
            targetSection.classList.add('active');
            var content = targetSection.querySelector('.accordion-content');
            if (content) content.style.maxHeight = (content.scrollHeight + 200) + 'px';
        }
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
        var label = document.querySelector('.current-step-label');
        if (label) label.textContent = 'Step ' + step + ': ' + stepNames[step];
        currentStep = step;
        if (targetSection) {
            setTimeout(function() { targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 100);
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
            if (!hasValid) { alert('Please enter at least one wattage and quantity pair.'); return false; }
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
                } else {
                    manualContainer.style.display = 'none';
                    importContainer.style.display = 'block';
                }
            });
        });
    });

    // ========== MW Capacity Tracking (Edit Mode) ==========
    var capacityData = {
        projectSizeMw: <?php echo json_encode($project_size_mw); ?>,
        currentOrderedMw: <?php echo json_encode($current_ordered_mw); ?>,
        thisBatchMw: <?php echo json_encode($this_batch_mw); ?>
    };

    var originalWattages = <?php echo json_encode($existingWattages); ?>;

    function calculateNewBatchMw() {
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
        var newBatchMw = calculateNewBatchMw();
        var newTotalMw = capacityData.currentOrderedMw - capacityData.thisBatchMw + newBatchMw;
        var previewDiv = document.getElementById('newMwPreview');
        if (previewDiv) {
            var difference = newBatchMw - capacityData.thisBatchMw;
            if (Math.abs(difference) > 0.001) {
                previewDiv.style.display = 'block';
                if (newTotalMw > capacityData.projectSizeMw) {
                    var excessMw = newTotalMw - capacityData.projectSizeMw;
                    var excessPct = ((newTotalMw / capacityData.projectSizeMw) - 1) * 100;
                    previewDiv.style.borderColor = '#dc3545';
                    previewDiv.style.background = '#f8d7da';
                    previewDiv.innerHTML =
                        '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">' +
                        '<span style="color: #721c24; font-weight: 600;">&#9888; Over Capacity Warning</span>' +
                        '<span style="font-weight: 700; color: #721c24;">' + newTotalMw.toFixed(2) + ' MW total</span></div>' +
                        '<div style="font-size: 0.9rem; color: #721c24;">This change will exceed the project target by ' +
                        excessMw.toFixed(2) + ' MW (' + excessPct.toFixed(1) + '% over).</div>';
                } else {
                    var changeSign = difference > 0 ? '+' : '';
                    var changeColor = difference > 0 ? '#856404' : '#155724';
                    previewDiv.style.borderColor = difference > 0 ? '#ffc107' : '#28a745';
                    previewDiv.style.background = difference > 0 ? '#fff3cd' : '#d4edda';
                    var remainingAfter = capacityData.projectSizeMw - newTotalMw;
                    previewDiv.innerHTML =
                        '<div style="display: flex; justify-content: space-between; align-items: center;">' +
                        '<span style="color: ' + changeColor + '; font-weight: 500;">After Changes:</span>' +
                        '<span style="font-weight: 700; color: ' + changeColor + ';">' + newTotalMw.toFixed(2) + ' MW total (' + changeSign + difference.toFixed(2) + ' MW)</span></div>' +
                        '<div style="font-size: 0.85rem; color: ' + changeColor + '; margin-top: 4px;">' +
                        remainingAfter.toFixed(2) + ' MW remaining capacity</div>';
                }
            } else {
                previewDiv.style.display = 'none';
            }
        }
    }

    function confirmCapacityOverageIfNeeded() {
        if (capacityData.projectSizeMw <= 0) return true;
        var newBatchMw = calculateNewBatchMw();
        var newTotalMw = capacityData.currentOrderedMw - capacityData.thisBatchMw + newBatchMw;
        if (newTotalMw <= capacityData.projectSizeMw) return true;

        var excessMw = newTotalMw - capacityData.projectSizeMw;
        var excessPct = ((newTotalMw / capacityData.projectSizeMw) - 1) * 100;
        var confirmMsg = 'WARNING: This change will exceed the project capacity!\n\n' +
            'New Total: ' + newTotalMw.toFixed(2) + ' MW\nTarget: ' + capacityData.projectSizeMw.toFixed(2) + ' MW\n\n' +
            'This is ' + excessMw.toFixed(2) + ' MW (' + excessPct.toFixed(1) + '%) over the target.\n\nAre you sure you want to proceed?';
        return confirm(confirmMsg);
    }

    document.addEventListener('DOMContentLoaded', function() {
        var wattageContainer = document.getElementById('wattage-container');
        if (wattageContainer) {
            wattageContainer.addEventListener('input', updateMwPreview);
            var observer = new MutationObserver(updateMwPreview);
            observer.observe(wattageContainer, { childList: true, subtree: true });
        }
    });

    // ========== Pallet Deletion Check ==========
    function getRemovedWattagesWithPallets() {
        var currentWattages = new Set();
        document.querySelectorAll('input[name="wattages[]"]').forEach(function(input) {
            var val = parseInt(input.value);
            if (val > 0) currentWattages.add(val);
        });
        var removedWithPallets = [];
        originalWattages.forEach(function(orig) {
            if (!currentWattages.has(orig.wattage) && orig.pallet_count > 0) {
                removedWithPallets.push(orig);
            }
        });
        return removedWithPallets;
    }

    function escapeHtml(value) {
        var str = String(value === undefined || value === null ? '' : value);
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function clearPreviewConfirmation() {
        var previewConfirmed = document.getElementById('previewConfirmed');
        var previewSignature = document.getElementById('previewSignature');
        if (previewConfirmed) previewConfirmed.value = 'no';
        if (previewSignature) previewSignature.value = '';
    }

    function getReconciliationModeUi(mode) {
        var normalized = String(mode || 'reassign_unlocked').toLowerCase();
        if (normalized === 'rebuild_unlocked') {
            return {
                label: 'Delete unlocked pallets and rebuild',
                description: 'All unlocked pallets in reduced/removed wattages are removed so you can repalletize cleanly.'
            };
        }
        return {
            label: 'Keep existing unlocked pallets',
            description: 'Keep as many unlocked pallets as possible while matching the new quantities.'
        };
    }

    function renderReconciliationPreview(preview) {
        var body = document.getElementById('reconciliationPreviewBody');
        if (!body) return;

        var summary = preview.summary || {};
        var projectImpact = preview.project_impact || null;
        var domestic = preview.domestic_impact || {};
        var beforeDomestic = domestic.before || {};
        var afterDomestic = domestic.after || {};
        var blockers = Array.isArray(preview.blockers) ? preview.blockers : [];
        var warnings = Array.isArray(preview.warnings) ? preview.warnings : [];
        var changes = Array.isArray(preview.changes) ? preview.changes : [];
        var affected = preview.affected_pallets || {};
        var blockedLinked = preview.blocked_linked_pallets || {};
        var reconciliationMode = preview.reconciliation_mode || 'reassign_unlocked';
        var modeUiFallback = getReconciliationModeUi(reconciliationMode);
        var reconciliationModeLabel = preview.reconciliation_mode_label || modeUiFallback.label;
        var reconciliationModeDescription = preview.reconciliation_mode_description || modeUiFallback.description;

        var html = '';
        html += '<div style="margin-bottom:12px; padding:12px; border-radius:8px; background:#eef6fa; border:1px solid #d7e9f0;">';
        html += '<div style="font-weight:700; margin-bottom:6px; color:#24495a;">How This Reconciliation Works</div>';
        html += '<div style="font-size:0.9rem; color:#24495a; margin-bottom:4px;"><strong>Unlocked pallets</strong> = not linked to deliveries/warranty. These can be auto-adjusted.</div>';
        html += '<div style="font-size:0.9rem; color:#24495a; margin-bottom:4px;"><strong>Locked pallets</strong> = linked downstream. These are never auto-deleted.</div>';
        html += '<div style="font-size:0.9rem; color:#24495a;">Selected behavior: <strong>' + escapeHtml(reconciliationModeLabel) + '</strong>. ' + escapeHtml(reconciliationModeDescription) + '</div>';
        html += '</div>';

        html += '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 14px;">';
        html += '<div style="background:#f8f9fa; border-radius:8px; padding:12px;"><div style="font-size:0.75rem; color:#6c757d;">Current Modules</div><div style="font-size:1.2rem; font-weight:700;">' + Number(summary.current_total_modules || 0).toLocaleString() + '</div></div>';
        html += '<div style="background:#f8f9fa; border-radius:8px; padding:12px;"><div style="font-size:0.75rem; color:#6c757d;">New Modules</div><div style="font-size:1.2rem; font-weight:700;">' + Number(summary.new_total_modules || 0).toLocaleString() + '</div></div>';
        html += '<div style="background:#f8f9fa; border-radius:8px; padding:12px;"><div style="font-size:0.75rem; color:#6c757d;">Delta Modules</div><div style="font-size:1.2rem; font-weight:700;">' + (summary.delta_modules >= 0 ? '+' : '') + Number(summary.delta_modules || 0).toLocaleString() + '</div></div>';
        html += '<div style="background:#f8f9fa; border-radius:8px; padding:12px;"><div style="font-size:0.75rem; color:#6c757d;">Changed Wattages</div><div style="font-size:1.2rem; font-weight:700;">' + Number(preview.changed_count || 0).toLocaleString() + '</div></div>';
        html += '</div>';

        if (projectImpact) {
            var overStyle = projectImpact.is_over_capacity ? 'color:#dc3545;' : 'color:#155724;';
            html += '<div style="margin-bottom:12px; padding:12px; border-radius:8px; background:#f8f9fa;">';
            html += '<div style="font-weight:600; margin-bottom:4px;">Project Capacity Impact</div>';
            html += '<div style="font-size:0.92rem;">After update: <strong>' + Number(projectImpact.after_total_mw || 0).toFixed(2) + ' MW</strong> / ' + Number(projectImpact.project_size_mw || 0).toFixed(2) + ' MW</div>';
            html += '<div style="font-size:0.88rem; ' + overStyle + '">' + (projectImpact.is_over_capacity ? ('Over by ' + Number(projectImpact.over_by_mw || 0).toFixed(2) + ' MW') : 'Within project capacity') + '</div>';
            html += '</div>';
        }

        html += '<div style="margin-bottom:12px; padding:12px; border-radius:8px; background:#f8f9fa;">';
        html += '<div style="font-weight:600; margin-bottom:6px;">Domestic Content Impact</div>';
        html += '<div style="font-size:0.9rem;">Before: ' + (beforeDomestic.domestic_content_pct === null || beforeDomestic.domestic_content_pct === undefined ? 'Not tracked' : Number(beforeDomestic.domestic_content_pct).toFixed(1) + '%') + ' (Coverage ' + Number(beforeDomestic.coverage_pct || 0).toFixed(1) + '%)</div>';
        html += '<div style="font-size:0.9rem;">After: ' + (afterDomestic.domestic_content_pct === null || afterDomestic.domestic_content_pct === undefined ? 'Not tracked' : Number(afterDomestic.domestic_content_pct).toFixed(1) + '%') + ' (Coverage ' + Number(afterDomestic.coverage_pct || 0).toFixed(1) + '%)</div>';
        html += '</div>';

        if (changes.length > 0) {
            html += '<div style="margin-bottom:12px;"><div style="font-weight:600; margin-bottom:6px;">Wattage Changes</div>';
            html += '<div style="border:1px solid #e9ecef; border-radius:8px; overflow:auto;"><table style="width:100%; border-collapse:collapse; font-size:0.9rem;">';
            html += '<thead><tr style="background:#f8f9fa;"><th style="text-align:left; padding:8px;">Wattage</th><th style="text-align:left; padding:8px;">Action</th><th style="text-align:right; padding:8px;">Current</th><th style="text-align:right; padding:8px;">New</th><th style="text-align:right; padding:8px;">Palletized (Current)</th><th style="text-align:right; padding:8px;">Palletized (Projected)</th><th style="text-align:right; padding:8px;">Locked Pallets</th></tr></thead><tbody>';
            changes.forEach(function(c) {
                html += '<tr style="border-top:1px solid #eef2f5;">' +
                    '<td style="padding:8px;">' + Number(c.wattage || 0) + 'W</td>' +
                    '<td style="padding:8px; text-transform:capitalize;">' + escapeHtml(c.action || 'unchanged') + '</td>' +
                    '<td style="padding:8px; text-align:right;">' + Number(c.current_quantity || 0).toLocaleString() + '</td>' +
                    '<td style="padding:8px; text-align:right;">' + Number(c.new_quantity || 0).toLocaleString() + '</td>' +
                    '<td style="padding:8px; text-align:right;">' + Number(c.pallet_modules || 0).toLocaleString() + '</td>' +
                    '<td style="padding:8px; text-align:right;">' + Number(c.projected_palletized_modules || 0).toLocaleString() + '</td>' +
                    '<td style="padding:8px; text-align:right;">' + Number(c.locked_pallet_count || 0).toLocaleString() + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div></div>';
        }

        html += '<div style="margin-bottom:12px; padding:12px; border-radius:8px; background:#f8f9fa;">';
        html += '<div style="font-weight:600; margin-bottom:4px;">Affected Pallets</div>';
        html += '<div style="font-size:0.9rem;">Selected behavior: <strong>' + escapeHtml(reconciliationModeLabel) + '</strong></div>';
        html += '<div style="font-size:0.9rem;">' + Number(affected.count || 0).toLocaleString() + ' pallet(s), ' + Number(affected.modules || 0).toLocaleString() + ' palletized module(s) in changed wattages.</div>';
        html += '<div style="font-size:0.9rem;">Locked (linked) pallets in affected wattages: ' + Number(blockedLinked.count || 0).toLocaleString();
        if (Array.isArray(blockedLinked.ids_sample) && blockedLinked.ids_sample.length > 0) {
            html += ' (sample IDs: ' + blockedLinked.ids_sample.join(', ') + ')';
        }
        html += '</div></div>';

        if (warnings.length > 0) {
            html += '<div style="margin-bottom:12px; padding:12px; border-radius:8px; background:#fff3cd; border:1px solid #ffe69c;">';
            html += '<div style="font-weight:600; margin-bottom:4px; color:#664d03;">Warnings</div><ul style="margin:6px 0 0 18px; color:#664d03;">';
            warnings.forEach(function(msg) { html += '<li style="margin:3px 0;">' + escapeHtml(msg) + '</li>'; });
            html += '</ul></div>';
        }

        if (blockers.length > 0) {
            html += '<div style="padding:12px; border-radius:8px; background:#f8d7da; border:1px solid #f1aeb5;">';
            html += '<div style="font-weight:600; margin-bottom:4px; color:#842029;">Blocked</div><ul style="margin:6px 0 0 18px; color:#842029;">';
            blockers.forEach(function(msg) { html += '<li style="margin:3px 0;">' + escapeHtml(msg) + '</li>'; });
            html += '</ul></div>';
        }

        body.innerHTML = html;
    }

    function showReconciliationPreviewModal(preview, onConfirm) {
        var modal = document.getElementById('reconciliationPreviewModal');
        var confirmBtn = document.getElementById('confirmReconciliationPreview');
        var cancelBtn = document.getElementById('cancelReconciliationPreview');
        var closeBtn = document.getElementById('closeReconciliationPreview');
        if (!modal || !confirmBtn || !cancelBtn || !closeBtn) {
            showResultModal(false, { message: 'Preview modal is not available.' });
            return;
        }

        renderReconciliationPreview(preview || {});
        confirmBtn.style.display = preview && preview.can_apply ? 'inline-block' : 'none';

        var close = function() {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        };
        var cancelHandler = function() {
            close();
        };
        var confirmHandler = function() {
            close();
            if (typeof onConfirm === 'function') onConfirm();
        };

        cancelBtn.onclick = cancelHandler;
        closeBtn.onclick = cancelHandler;
        confirmBtn.onclick = confirmHandler;
        modal.onclick = function(e) { if (e.target === modal) close(); };

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function requestReconciliationPreview(form) {
        var previewFormData = new FormData(form);
        previewFormData.set('action', 'preview_reconciliation');
        var actionUrl = form.getAttribute('action') || window.location.href;
        return fetch(actionUrl, {
            method: 'POST',
            body: previewFormData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(parseJsonResponse);
    }

    function runPreviewFlow(form) {
        showLoadingModal('Analyzing Changes...', 'Running dry-run reconciliation to preview downstream impact.');
        requestReconciliationPreview(form)
            .then(function(data) {
                hideLoadingModal();
                if (!data || !data.success) {
                    showResultModal(false, data || { message: 'Failed to generate reconciliation preview.' });
                    return;
                }
                if (!data.preview) {
                    showResultModal(false, { message: 'Reconciliation preview response was incomplete.' });
                    return;
                }

                showReconciliationPreviewModal(data.preview, function() {
                    var previewConfirmed = document.getElementById('previewConfirmed');
                    var previewSignature = document.getElementById('previewSignature');
                    if (previewConfirmed) previewConfirmed.value = 'yes';
                    if (previewSignature) previewSignature.value = data.preview.signature || '';
                    submitFormViaAjax(form);
                });
            })
            .catch(function(error) {
                hideLoadingModal();
                showResultModal(false, { message: buildFriendlyErrorMessage(error) });
            });
    }

    // ========== Form Submission with Pallet Confirmation ==========
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('editBatchForm');
        var modal = document.getElementById('palletDeleteModal');
        var confirmBtn = document.getElementById('confirmPalletDelete');
        var cancelBtn = document.getElementById('cancelPalletDelete');
        var palletList = document.getElementById('palletDeleteList');
        var confirmInput = document.getElementById('confirmDeletePallets');
        var reconModeHidden = document.getElementById('reconciliationMode');
        var reconModeSelect = document.getElementById('reconciliationModeSelect');
        if (!form) return;

        if (reconModeSelect && reconModeHidden) {
            reconModeHidden.value = reconModeSelect.value || 'reassign_unlocked';
            reconModeSelect.addEventListener('change', function() {
                reconModeHidden.value = reconModeSelect.value || 'reassign_unlocked';
                clearPreviewConfirmation();
            });
        }

        form.addEventListener('input', function() {
            clearPreviewConfirmation();
            confirmInput.value = 'no';
        }, true);
        form.addEventListener('change', function() {
            clearPreviewConfirmation();
            confirmInput.value = 'no';
        }, true);

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                modal.style.display = 'none';
                clearPreviewConfirmation();
            });
        }
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                confirmInput.value = 'yes';
                modal.style.display = 'none';
                clearPreviewConfirmation();
                if (!confirmCapacityOverageIfNeeded()) return;
                runPreviewFlow(form);
            });
        }
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                    clearPreviewConfirmation();
                }
            });
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            clearPreviewConfirmation();
            var removedWithPallets = getRemovedWattagesWithPallets();
            if (removedWithPallets.length > 0 && confirmInput.value !== 'yes') {
                var listHtml = '';
                var totalPallets = 0;
                var totalModules = 0;
                removedWithPallets.forEach(function(item) {
                    totalPallets += item.pallet_count;
                    totalModules += item.pallet_modules;
                    listHtml += '<div style="padding: 8px 0; border-bottom: 1px solid #e9ecef;">' +
                        '<strong>' + item.wattage + 'W</strong>: ' + item.pallet_count + ' pallet(s) containing ' + item.pallet_modules.toLocaleString() + ' modules</div>';
                });
                listHtml += '<div style="padding: 10px 0 0; font-weight: 600; color: #dc3545;">Total: ' + totalPallets + ' pallet(s), ' + totalModules.toLocaleString() + ' modules</div>';
                palletList.innerHTML = listHtml;
                modal.style.display = 'flex';
                return false;
            }
            if (!confirmCapacityOverageIfNeeded()) return false;
            runPreviewFlow(form);
        });
    });

    // ========== Loading Modal Functions ==========
    function showLoadingModal(title, subtitle) {
        var modal = document.getElementById('loadingModal');
        var titleEl = document.getElementById('loadingTitle');
        var subtitleEl = document.getElementById('loadingSubtitle');
        if (modal) {
            if (titleEl) titleEl.textContent = title || 'Saving Changes...';
            if (subtitleEl) subtitleEl.textContent = subtitle || 'Please wait while we process your request.';
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
        var modulesEl = document.getElementById('resultModules');
        var goBackBtn = document.getElementById('resultGoBackBtn');
        var closeBtn = document.getElementById('resultCloseBtn');
        var redirectTarget = data.redirect_url || (data.project_id ? 'project_overview.php?project_id=' + data.project_id : 'modules.php');

        if (success) {
            icon.innerHTML = '&#10004;'; icon.style.color = '#28a745';
            title.textContent = data.action === 'delete' ? 'Module Batch Deleted!' : 'Module Batch Updated!';
            title.style.color = '#28a745';
            message.textContent = data.message || 'Your changes have been saved successfully.';
            if (data.action === 'update' && data.total_modules) {
                details.style.display = 'block';
                modulesEl.textContent = (data.total_modules || 0).toLocaleString();
            } else { details.style.display = 'none'; }
            if (redirectTarget) {
                goBackBtn.href = redirectTarget;
                goBackBtn.innerHTML = data.project_id
                    ? '<i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Project Overview'
                    : '<i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Modules';
                goBackBtn.style.display = 'inline-flex';
            }
            closeBtn.style.display = 'none';
        } else {
            icon.innerHTML = '&#10006;'; icon.style.color = '#dc3545';
            title.textContent = 'Error'; title.style.color = '#dc3545';
            message.textContent = data.message || 'An error occurred. Please try again.';
            details.style.display = 'none'; goBackBtn.style.display = 'none';
            closeBtn.style.display = 'inline-flex';
        }
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeResultModal() {
        var modal = document.getElementById('resultModal');
        if (modal) { modal.style.display = 'none'; document.body.style.overflow = ''; }
    }

    // ========== AJAX Form Submission ==========
    function parseJsonResponse(response) {
        var contentType = response.headers.get('content-type') || '';
        if (contentType.indexOf('application/json') !== -1) { return response.json(); }
        return response.text().then(function(text) {
            var error = new Error('Non-JSON response');
            error.rawText = text;
            throw error;
        });
    }

    function buildFriendlyErrorMessage(error) {
        if (error && error.rawText) {
            var temp = document.createElement('div');
            temp.innerHTML = error.rawText;
            var plain = (temp.textContent || temp.innerText || '').trim();
            if (plain) return plain.substring(0, 500);
        }
        return (error && error.message) ? error.message : 'An unexpected error occurred. Please try again.';
    }

    function submitFormViaAjax(form) {
        showLoadingModal('Saving Changes...', 'Please wait while we update your module batch.');
        var formData = new FormData(form);
        var actionUrl = form.getAttribute('action') || window.location.href;
        fetch(actionUrl, {
            method: 'POST', body: formData, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(parseJsonResponse)
        .then(function(data) { showResultModal(data.success, data); })
        .catch(function(error) { console.error('Error:', error); showResultModal(false, { message: buildFriendlyErrorMessage(error) }); });
    }

    function submitDeleteViaAjax(form) {
        showLoadingModal('Deleting Module Batch...', 'Please wait while we remove the batch and associated pallets.');
        var formData = new FormData(form);
        var actionUrl = form.getAttribute('action') || window.location.href;
        fetch(actionUrl, {
            method: 'POST', body: formData, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(parseJsonResponse)
        .then(function(data) { showResultModal(data.success, data); })
        .catch(function(error) { console.error('Error:', error); showResultModal(false, { message: buildFriendlyErrorMessage(error) }); });
    }

    // ========== Delete Batch Modal ==========
    document.addEventListener('DOMContentLoaded', function() {
        var deleteBatchBtn = document.getElementById('deleteBatchBtn');
        var deleteBatchModal = document.getElementById('deleteBatchModal');
        var cancelDeleteBatch = document.getElementById('cancelDeleteBatch');
        var confirmDeleteBatch = document.getElementById('confirmDeleteBatch');
        var deleteBatchForm = document.getElementById('deleteBatchForm');

        hideLoadingModal();
        closeResultModal();

        // Mark steps that already have data as completed (visual indicator only)
        if (completedSteps && completedSteps.length > 0) {
            completedSteps.forEach(function(stepNum) {
                if (stepNum === currentStep) return; // Don't mark active step as completed
                var stepEl = document.querySelector('.step-indicator .step[data-step="' + stepNum + '"]');
                if (stepEl && !stepEl.classList.contains('current')) stepEl.classList.add('completed');
                var accSection = document.querySelector('.accordion-section[data-section="' + stepNum + '"]');
                if (accSection && !accSection.classList.contains('active')) accSection.classList.add('completed');
            });
        }

        if (deleteBatchBtn) { deleteBatchBtn.addEventListener('click', function() { deleteBatchModal.style.display = 'flex'; }); }
        if (cancelDeleteBatch) { cancelDeleteBatch.addEventListener('click', function() { deleteBatchModal.style.display = 'none'; }); }
        if (confirmDeleteBatch) {
            confirmDeleteBatch.addEventListener('click', function() {
                deleteBatchModal.style.display = 'none';
                submitDeleteViaAjax(deleteBatchForm);
            });
        }
        if (deleteBatchModal) {
            deleteBatchModal.addEventListener('click', function(e) {
                if (e.target === deleteBatchModal) deleteBatchModal.style.display = 'none';
            });
        }
    });
</script>
</body>
</html>
