<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { header('Location: login'); exit(); }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';
require_once __DIR__ . '/warranty_notification_helpers.php';

function createReplacementPalletsFromPlan(mysqli $conn, array $planItems, int $projectId, int $accountId): array {
    $created = [];
    foreach ($planItems as $item) {
        $watt = (int)($item['wattage'] ?? 0);
        $mpp = max(1, (int)($item['modules_per_pallet'] ?? 0));
        $full = max(0, (int)($item['full_pallets'] ?? 0));
        $part = max(0, (int)($item['partial_modules'] ?? 0));
        $manufacturer = trim((string)($item['manufacturer'] ?? ''));
        $locationId = isset($item['manufacturer_location_id']) && $item['manufacturer_location_id'] !== '' ? (int)$item['manufacturer_location_id'] : null;
        $ppt = isset($item['pallets_per_truck']) && $item['pallets_per_truck'] !== '' ? (int)$item['pallets_per_truck'] : null;
        $mpt = isset($item['modules_per_truck']) && $item['modules_per_truck'] !== '' ? (int)$item['modules_per_truck'] : null;

        if ($watt <= 0) { continue; }

        $totalModules = isset($item['total_modules']) ? (int)$item['total_modules'] : (($full * $mpp) + $part);
        if ($totalModules <= 0) { continue; }

        $vendorName = ($manufacturer !== '') ? $manufacturer : 'Replacement';

        // Look up the actual manufacturer location address
        $initialLocation = '';
        if ($locationId) {
            $stmtLoc = $conn->prepare("SELECT street_address, city, state, zip_code FROM manufacturer_locations WHERE id = ?");
            $stmtLoc->bind_param("i", $locationId);
            $stmtLoc->execute();
            $stmtLoc->bind_result($street, $city, $state, $zip);
            if ($stmtLoc->fetch()) {
                $initialLocation = implode(', ', array_filter([$street, $city, $state, $zip]));
            }
            $stmtLoc->close();
        }
        // Fallback to manufacturer name if no location found
        if ($initialLocation === '') {
            $initialLocation = ($manufacturer !== '') ? $manufacturer : 'Manufacturer';
        }
        $stmtMod = $conn->prepare('INSERT INTO modules (account_id, vendor_name, initial_location, project_id, modules_per_pallet, pallets_per_truck, modules_per_truck) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $mppParam = $mpp;
        $pptParam = $ppt;
        $mptParam = $mpt;
        $stmtMod->bind_param('issiiii', $accountId, $vendorName, $initialLocation, $projectId, $mppParam, $pptParam, $mptParam);
        $stmtMod->execute();
        $moduleId = (int)$conn->insert_id;
        $stmtMod->close();

        $stmtUmi = $conn->prepare('INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)');
        $stmtUmi->bind_param('iii', $moduleId, $watt, $totalModules);
        $stmtUmi->execute();
        $umiId = (int)$conn->insert_id;
        $stmtUmi->close();

        $ins = $conn->prepare("INSERT INTO inventory_pallets (pallet_identifier, unassigned_module_item_id, assigned_project_id, wattage, quantity, status, manufacturer, manufacturer_location_id) VALUES (?, ?, ?, ?, ?, 'At Manufacturer', ?, ?)");
        for ($i=0; $i<$full; $i++) {
            $empty = '';
            $q = $mpp;
            $ins->bind_param('siiidsi', $empty, $umiId, $projectId, $watt, $q, $manufacturer, $locationId);
            $ins->execute();
            $newId = (int)$conn->insert_id;
            $pid = 'P' . $newId;
            $u = $conn->prepare('UPDATE inventory_pallets SET pallet_identifier = ? WHERE id = ?');
            $u->bind_param('si', $pid, $newId);
            $u->execute();
            $u->close();
            $created[] = $newId;
        }
        if ($part > 0) {
            $empty = '';
            $q = $part;
            $ins->bind_param('siiidsi', $empty, $umiId, $projectId, $watt, $q, $manufacturer, $locationId);
            $ins->execute();
            $newId = (int)$conn->insert_id;
            $pid = 'P' . $newId;
            $u = $conn->prepare('UPDATE inventory_pallets SET pallet_identifier = ? WHERE id = ?');
            $u->bind_param('si', $pid, $newId);
            $u->execute();
            $u->close();
            $created[] = $newId;
        }
        $ins->close();
    }
    return $created;
}



$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin'], true)) { http_response_code(403); die('Unauthorized'); }

// CSRF check
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    http_response_code(400);
    die('Invalid CSRF token');
}

$claimId = isset($_POST['claim_id']) ? (int)$_POST['claim_id'] : 0;
if ($claimId <= 0) { die('Invalid claim'); }

$conn = getDBConnection();

// Access check
$projectId = getClaimProjectId($conn, $claimId);
if ($projectId === null) { $conn->close(); die('Invalid claim'); }
$allowed = getAllowedProjectIds($conn, $userId, $role);
if (is_array($allowed) && !in_array($projectId, $allowed, true)) { $conn->close(); die('Unauthorized'); }

// Load before state
$before = loadClaimRow($conn, $claimId);
if (!$before) { $conn->close(); die('Claim not found'); }
$linkedBefore = listLinkedReplacementPalletIds($conn, $claimId);

$accountId = null;
$acctStmt = $conn->prepare('SELECT account_id FROM projects WHERE id = ?');
$acctStmt->bind_param('i', $projectId);
$acctStmt->execute();
$acctStmt->bind_result($accountId);
$acctStmt->fetch();
$acctStmt->close();
$accountId = (int)$accountId;

// Collect inputs
$status = trim((string)($_POST['status'] ?? $before['status']));
$responsible = trim((string)($_POST['responsible_party'] ?? $before['responsible_party']));
$resolution = trim((string)($_POST['resolution_type'] ?? $before['resolution_type']));
$creditAmount = isset($_POST['credit_amount']) && $_POST['credit_amount'] !== '' ? (float)$_POST['credit_amount'] : null;
$replacementTracking = trim((string)($_POST['replacement_tracking'] ?? $before['replacement_tracking']));
$internalNotes = trim((string)($_POST['internal_notes'] ?? ''));
$rejectionReason = trim((string)($_POST['rejection_reason'] ?? ''));
$overrideCross = isset($_POST['override_cross_project']) ? (int)$_POST['override_cross_project'] : 0;
$replacementPallets = isset($_POST['replacement_pallets']) ? array_map('intval', (array)$_POST['replacement_pallets']) : [];
// Public note may be used for validation when approving
$publicNotes = trim((string)($_POST['public_notes'] ?? ''));

$notesArr = jsonToArray($before['notes'] ?? '');
$replacementPlan = isset($notesArr['replacement_plan']) && is_array($notesArr['replacement_plan']) ? $notesArr['replacement_plan'] : [];
$replacementPlanItems = (isset($replacementPlan['items']) && is_array($replacementPlan['items'])) ? $replacementPlan['items'] : [];

// Map UI-friendly status "Approved" to backend-specific statuses based on resolution type
if ($status === 'Approved') {
    if ($resolution === 'Credit') {
        $status = 'Approved - Credit';
    } elseif ($resolution === 'Replacement') {
        $status = 'Approved - Replacement';
    } else {
        $conn->close();
        die('Select a Resolution Type (Credit or Replacement) before approving.');
    }
}

// Validate transition
$fromStatus = (string)$before['status'];
if ($status !== $fromStatus && !isValidWarrantyTransition($fromStatus, $status)) {
    $conn->close();
    die('Invalid status transition');
}

// Enforce Pending {Party} matches selected responsible party
if (strpos($status, 'Pending ') === 0) {
    $expectedPending = 'Pending ' . $responsible;
    // Map responsible party to exact labels for EPC/Carrier/Manufacturer only
    if (!in_array($expectedPending, ['Pending Manufacturer','Pending EPC','Pending Carrier'], true)) {
        $conn->close();
        die('Invalid responsible party for pending state');
    }
    if ($status !== $expectedPending) {
        $conn->close();
        die('Pending status must match Responsible Party');
    }
}

// Business rules
if ($status === 'Approved - Credit' && ($creditAmount === null || $creditAmount <= 0)) {
    $conn->close();
    die('Credit amount required for Approved - Credit');
}

// For rejection, require either a text reason OR at least one uploaded file
if ($status === 'Rejected' && $rejectionReason === '' && empty($_FILES['proof_files']['name'][0])) {
    $conn->close();
    die('Provide a rejection reason or upload at least one file.');
}

// For approval, require either a public note OR at least one uploaded file
if ((strpos($status, 'Approved - ') === 0) && ($publicNotes === '') && empty($_FILES['proof_files']['name'][0])) {
    $conn->close();
    die('Add a public update or upload at least one file to proceed.');
}

// Determine if a staged replacement plan should be applied now
$applyPlanNow = ($resolution === 'Replacement' && in_array($status, ['Approved - Replacement','Replacement Shipped','Closed'], true) && !empty($replacementPlanItems));

// Begin transaction for atomic update
$conn->begin_transaction();

// Handle file uploads (proofs)
$newUploads = [];
if (!empty($_FILES['proof_files']) && is_array($_FILES['proof_files']['name'])) {
    $newUploads = storeUploadedFiles($_FILES['proof_files'], $claimId);
}

// Handle deletions of pictures (only paths present in current pictures list)
$deletePictures = isset($_POST['delete_pictures']) ? (array)$_POST['delete_pictures'] : [];

// Replacement pallets: restrict to same project unless override
if (!empty($replacementPallets) && !$overrideCross) {
    if (!empty($replacementPallets)) {
        $ph = implode(',', array_fill(0, count($replacementPallets), '?'));
        $types = str_repeat('i', count($replacementPallets) + 1);
        $sql = "SELECT COUNT(*) c FROM inventory_pallets WHERE assigned_project_id = ? AND id IN ($ph)";
        $stmt = $conn->prepare($sql);
        $bind = array_merge([$projectId], $replacementPallets);
        $stmt->bind_param($types, ...$bind);
        $stmt->execute();
        $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        if ($c !== count($replacementPallets)) {
        $conn->rollback();
        $conn->close();
        die('One or more pallets not in the same project. Enable override to proceed.');
        }
    }
}

// Determine final set of replacement pallets (use existing links if none posted)
$linkedExisting = listLinkedReplacementPalletIds($conn, $claimId);
$finalLinked = !empty($replacementPallets) ? array_unique(array_map('intval', $replacementPallets)) : $linkedExisting;

$createdFromPlan = [];
if ($applyPlanNow) {
    $createdFromPlan = createReplacementPalletsFromPlan($conn, $replacementPlanItems, $projectId, $accountId);
    if (!empty($createdFromPlan)) {
        $finalLinked = array_values(array_unique(array_merge($finalLinked, $createdFromPlan)));
        unset($notesArr['replacement_plan']);
    }
}

// Replacement rules: require at least one linked pallet once approved; require tracking when shipping/closing
if ($resolution === 'Replacement') {
    if ($status === 'Approved - Replacement' && empty($finalLinked)) {
        $conn->rollback();
        $conn->close();
        die('Replacement requires at least one linked pallet');
    }
    
    // For closing replacement claims, check if we're coming from delivered status or need tracking
    if ($status === 'Closed' && $fromStatus === 'Approved - Replacement') {
        // If closing directly from Approved - Replacement, check if replacement is delivered to project
        if (!empty($finalLinked)) {
            $placeholders = implode(',', array_fill(0, count($finalLinked), '?'));
            $types = str_repeat('i', count($finalLinked));
            $statusCheck = $conn->prepare("SELECT COUNT(*) as delivered_count FROM inventory_pallets WHERE id IN ($placeholders) AND status = 'Delivered to Project'");
            $statusCheck->bind_param($types, ...$finalLinked);
            $statusCheck->execute();
            $statusResult = $statusCheck->get_result();
            $deliveredCount = (int)($statusResult->fetch_assoc()['delivered_count'] ?? 0);
            $statusCheck->close();
            
            // Allow closure if at least one pallet is delivered to project, or if tracking number is provided
            if ($deliveredCount === 0 && $replacementTracking === '') {
                $conn->rollback();
                $conn->close();
                die('Cannot close ticket: Replacement must be delivered to project or tracking number must be provided');
            }
        } else {
            $conn->rollback();
            $conn->close();
            die('Replacement requires at least one linked pallet to close');
        }
    } elseif ($status === 'Replacement Shipped' && (empty($finalLinked) || $replacementTracking === '')) {
        $conn->rollback();
        $conn->close();
        die('Replacement shipment requires linked pallet(s) and tracking number');
    }
}

// If closing, require proof present
if ($status === 'Closed') {
    $picturesArr = jsonToArray($before['pictures'] ?? '');
    $anyProof = !empty($before['proof_of_completion_path']) || !empty($newUploads);
    if (!$anyProof) {
        $conn->rollback();
        $conn->close();
        die('Proof of completion required to close');
    }
}

// Compute after state
$after = $before;
$after['status'] = $status;
$after['responsible_party'] = $responsible;
$after['resolution_type'] = $resolution !== '' ? $resolution : null;
$after['credit_amount'] = $creditAmount;
$after['replacement_tracking'] = $replacementTracking !== '' ? $replacementTracking : null;

// Merge pictures with uploads and deletions
$picturesArr = jsonToArray($before['pictures'] ?? '');
if (!empty($deletePictures)) {
    $picturesArr = array_values(array_filter($picturesArr, function($p) use ($deletePictures){ return !in_array($p, $deletePictures, true); }));
}
if (!empty($newUploads)) {
    $picturesArr = array_values(array_unique(array_merge($picturesArr, $newUploads)));
}
$after['pictures'] = arrayToJson($picturesArr);
$notesJsonOut = $before['notes'];
if ($applyPlanNow) {
    $notesJsonOut = empty($notesArr) ? null : json_encode($notesArr, JSON_UNESCAPED_SLASHES);
}

// Primary proof: if not set and uploads include at least one, set the first upload as primary
if (empty($before['proof_of_completion_path']) && !empty($newUploads)) {
    $after['proof_of_completion_path'] = $newUploads[0];
}

// Handle auto-advance when first setting replacement tracking while on Approved - Replacement
if (!empty($replacementTracking) && $fromStatus === 'Approved - Replacement' && $status === 'Approved - Replacement') {
    $after['status'] = 'Replacement Shipped';
}

// Write main update with NULL-friendly credit handling
if ($after['credit_amount'] === null) {
    $sqlU = "UPDATE warranty_claims
             SET status = ?, responsible_party = ?, resolution_type = ?, credit_amount = NULL, replacement_tracking = ?, proof_of_completion_path = ?, pictures = ?, notes = ?, updated_at = NOW()
             WHERE id = ?";
    $stmtU = $conn->prepare($sqlU);
    $repTrackParam = $after['replacement_tracking'];
    $proofParam = $after['proof_of_completion_path'] ?? $before['proof_of_completion_path'];
    $stmtU->bind_param(
        'sssssssi',
        $after['status'],
        $after['responsible_party'],
        $after['resolution_type'],
        $repTrackParam,
        $proofParam,
        $after['pictures'],
        $notesJsonOut,
        $claimId
    );
    $stmtU->execute();
    $stmtU->close();
} else {
    $sqlU = "UPDATE warranty_claims
             SET status = ?, responsible_party = ?, resolution_type = ?, credit_amount = ?, replacement_tracking = ?, proof_of_completion_path = ?, pictures = ?, notes = ?, updated_at = NOW()
             WHERE id = ?";
    $stmtU = $conn->prepare($sqlU);
    $creditParam = (float)$after['credit_amount'];
    $repTrackParam = $after['replacement_tracking'];
    $proofParam = $after['proof_of_completion_path'] ?? $before['proof_of_completion_path'];
    $stmtU->bind_param(
        'sssdssssi',
        $after['status'],
        $after['responsible_party'],
        $after['resolution_type'],
        $creditParam,
        $repTrackParam,
        $proofParam,
        $after['pictures'],
        $notesJsonOut,
        $claimId
    );
    $stmtU->execute();
    $stmtU->close();
}

// Replacement link sync: only modify links if explicit input was provided or plan created pallets
$shouldSyncLinks = isset($_POST['replacement_pallets']) || !empty($createdFromPlan);
if ($shouldSyncLinks) {
    $linkedAfter = $finalLinked;
    $conn->query("DELETE FROM warranty_claim_replacements WHERE claim_id = " . (int)$claimId);
    if (!empty($linkedAfter)) {
        $ins = $conn->prepare('INSERT INTO warranty_claim_replacements (claim_id, pallet_id) VALUES (?, ?)');
        foreach ($linkedAfter as $pid) { $ins->bind_param('ii', $claimId, $pid); $ins->execute(); }
        $ins->close();
    }
} else {
    // No changes submitted; retain existing links
    $linkedAfter = $linkedExisting;
}

// Events
if ($internalNotes !== '') {
    insertEvent($conn, $claimId, $userId, $internalNotes, 0);
}

// Save Public Update text (from admin controls) as a public event with current status context
$publicNotes = trim((string)($_POST['public_notes'] ?? ''));
if ($publicNotes !== '') {
    $statusContext = (string)$after['status'];
    $noteText = '[' . $statusContext . '] ' . $publicNotes;
    insertEvent($conn, $claimId, $userId, $noteText, 1);
    setLastPublicUpdateNow($conn, $claimId);
}

if ($status === 'Rejected' && $rejectionReason !== '') {
    // Log both internal and public reasons so customers can see why
    insertEvent($conn, $claimId, $userId, 'Rejected: ' . $rejectionReason, 0);
    insertEvent($conn, $claimId, $userId, 'Rejected: ' . $rejectionReason, 1);
}

$publicChanges = detectPublicChanges($before, $after, $linkedBefore, $linkedAfter, $newUploads);
if (!empty($publicChanges)) {
    $summary = summarizePublicChanges($conn, $publicChanges);
    if ($overrideCross && !empty($linkedAfter)) {
        $summary .= ' | Override: cross-project replacement linked';
    }
    insertEvent($conn, $claimId, $userId, $summary, 1);
    setLastPublicUpdateNow($conn, $claimId);
    // notify after commit
}

$conn->commit();
notifyUsers($claimId);
$conn->close();
header('Location: warranty_detail.php?id=' . $claimId);
exit();
?>


