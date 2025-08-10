<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { header('Location: login'); exit(); }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';
require_once __DIR__ . '/notifications.php';

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

if ($status === 'Rejected' && $rejectionReason === '') {
    $conn->close();
    die('Rejection reason is required');
}

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

// Replacement rules: require at least one linked pallet once approved; require tracking when shipping/closing
if ($resolution === 'Replacement') {
    if ($status === 'Approved - Replacement' && empty($finalLinked)) {
        $conn->rollback();
        $conn->close();
        die('Replacement requires at least one linked pallet');
    }
    if (in_array($status, ['Replacement Shipped', 'Closed'], true) && (empty($finalLinked) || $replacementTracking === '')) {
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
             SET status = ?, responsible_party = ?, resolution_type = ?, credit_amount = NULL, replacement_tracking = ?, proof_of_completion_path = ?, pictures = ?, updated_at = NOW()
             WHERE id = ?";
    $stmtU = $conn->prepare($sqlU);
    $repTrackParam = $after['replacement_tracking'];
    $proofParam = $after['proof_of_completion_path'] ?? $before['proof_of_completion_path'];
    $stmtU->bind_param(
        'ssssssi',
        $after['status'],
        $after['responsible_party'],
        $after['resolution_type'],
        $repTrackParam,
        $proofParam,
        $after['pictures'],
        $claimId
    );
    $stmtU->execute();
    $stmtU->close();
} else {
    $sqlU = "UPDATE warranty_claims
             SET status = ?, responsible_party = ?, resolution_type = ?, credit_amount = ?, replacement_tracking = ?, proof_of_completion_path = ?, pictures = ?, updated_at = NOW()
             WHERE id = ?";
    $stmtU = $conn->prepare($sqlU);
    $creditParam = (float)$after['credit_amount'];
    $repTrackParam = $after['replacement_tracking'];
    $proofParam = $after['proof_of_completion_path'] ?? $before['proof_of_completion_path'];
    $stmtU->bind_param(
        'sssdsssi',
        $after['status'],
        $after['responsible_party'],
        $after['resolution_type'],
        $creditParam,
        $repTrackParam,
        $proofParam,
        $after['pictures'],
        $claimId
    );
    $stmtU->execute();
    $stmtU->close();
}

// Replacement link sync: only modify links if explicit input was provided
if (isset($_POST['replacement_pallets'])) {
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


