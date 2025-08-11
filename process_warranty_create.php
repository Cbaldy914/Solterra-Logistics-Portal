<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { header('Location: login'); exit(); }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';
require_once __DIR__ . '/notifications.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');
if (!in_array($role, ['admin','global_admin'], true)) { http_response_code(403); die('Unauthorized'); }

// CSRF
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    http_response_code(400);
    die('Invalid CSRF token');
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$bolNumber = trim((string)($_POST['bol_number'] ?? ''));
$deliveryDate = trim((string)($_POST['delivery_date'] ?? ''));
$issueType = trim((string)($_POST['issue_type'] ?? 'damaged'));
$responsible = trim((string)($_POST['responsible_party'] ?? 'Manufacturer'));
$publicNotes = trim((string)($_POST['public_notes'] ?? ''));
$internalNotes = trim((string)($_POST['internal_notes'] ?? ''));

if ($projectId <= 0) { die('Project required'); }
if ($appointmentId <= 0 && $bolNumber === '') { die('BOL number required when not linking to an appointment'); }
if (!in_array($issueType, ['damaged','quantity_discrepancy','both'], true)) { die('Invalid issue type'); }
if (!in_array($responsible, ['Manufacturer','EPC','Carrier','Other'], true)) { die('Invalid responsible party'); }

$conn = getDBConnection();

// Authorization: ensure project scope
$allowed = getAllowedProjectIds($conn, $userId, $role);
if (is_array($allowed) && !in_array($projectId, $allowed, true)) { $conn->close(); die('Unauthorized project'); }

// Validate project exists and get account_id
$stmt = $conn->prepare('SELECT account_id, project_name FROM projects WHERE id = ?');
$stmt->bind_param('i', $projectId);
$stmt->execute();
$stmt->bind_result($accountId, $projectName);
if (!$stmt->fetch()) { $stmt->close(); $conn->close(); die('Project not found'); }
$stmt->close();

// If linking to existing appointment, validate it belongs to project
if ($appointmentId > 0) {
    $chk = $conn->prepare('SELECT bol_number FROM site_scheduling WHERE id = ? AND project_id = ?');
    $chk->bind_param('ii', $appointmentId, $projectId);
    $chk->execute();
    $chk->bind_result($apBol);
    if (!$chk->fetch()) { $chk->close(); $conn->close(); die('Appointment not found for project'); }
    $chk->close();
    if ($bolNumber === '') { $bolNumber = (string)$apBol; }
}

$conn->begin_transaction();

try {
    // Create placeholder appointment if needed
    if ($appointmentId <= 0) {
        $startUtc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $palletQty = 0; $modulesPerPallet = 0;
        $desc = 'Manual warranty ticket placeholder';
        $stmtS = $conn->prepare('INSERT INTO site_scheduling (site_id, project_id, account_id, start_time, bol_number, wattage, pallet_quantity, modules_per_pallet, reference_numbers, description, arrival_time, departure_time, is_closed, supplier, status, quantity, owner_id, bol_file, delivery_id) VALUES (NULL, ?, ?, ?, ?, NULL, ?, ?, NULL, ?, NULL, NULL, 0, NULL, NULL, NULL, ?, NULL, NULL)');
        $stmtS->bind_param('iissiisi', $projectId, $accountId, $startUtc, $bolNumber, $palletQty, $modulesPerPallet, $desc, $userId);
        if (!$stmtS->execute()) { throw new Exception('Failed to create placeholder appointment: ' . $stmtS->error); }
        $appointmentId = $conn->insert_id;
        $stmtS->close();
    }

    // Build pallet details if provided (only when linking an appointment)
    $notesJson = null;
    $expected_total = null;
    $actual_total = null;
    $damaged_total = null;
    $accepted_total = null;

    $pallet_details = [];
    if ($appointmentId > 0 && isset($_POST['pallet_actuals'])) {
        $pallet_actuals = isset($_POST['pallet_actuals']) ? array_map('intval', (array)$_POST['pallet_actuals']) : [];
        $pallet_damages = isset($_POST['pallet_damages']) ? array_map('intval', (array)$_POST['pallet_damages']) : [];
        $pallet_accepted = isset($_POST['pallet_accepted']) ? array_map('intval', (array)$_POST['pallet_accepted']) : [];
        $pallet_notes = isset($_POST['pallet_notes']) ? (array)$_POST['pallet_notes'] : [];

        $all_ids = array_unique(array_map('intval', array_keys($pallet_actuals + $pallet_damages + $pallet_accepted)));
        if (!empty($all_ids)) {
            // Load base pallet info
            $ph = implode(',', array_fill(0, count($all_ids), '?'));
            $typesP = str_repeat('i', count($all_ids));
            $q = $conn->prepare("SELECT id, wattage, quantity FROM inventory_pallets WHERE id IN ($ph)");
            $q->bind_param($typesP, ...$all_ids);
            $q->execute();
            $rs = $q->get_result();
            $baseMap = [];
            while ($r = $rs->fetch_assoc()) { $baseMap[(int)$r['id']] = $r; }
            $q->close();

            $expected_total = 0; $actual_total = 0; $damaged_total = 0; $accepted_total = 0;
            foreach ($all_ids as $pid) {
                $base = $baseMap[$pid] ?? null;
                if (!$base) continue;
                $expected = (int)$base['quantity'];
                $actual = isset($pallet_actuals[$pid]) ? max(0, (int)$pallet_actuals[$pid]) : $expected;
                $damaged = isset($pallet_damages[$pid]) ? max(0, (int)$pallet_damages[$pid]) : 0;
                $accepted = isset($pallet_accepted[$pid]) ? max(0, (int)$pallet_accepted[$pid]) : max(0, $actual - $damaged);
                $note = trim((string)($pallet_notes[$pid] ?? ''));

                if (($damaged > 0) || ($actual !== $expected)) {
                    $pallet_details[] = [
                        'pallet_id' => (int)$pid,
                        'wattage' => (int)$base['wattage'],
                        'expected' => $expected,
                        'actual' => $actual,
                        'damaged' => $damaged,
                        'accepted' => $accepted,
                        'notes' => $note,
                    ];

                    // Update pallet status if damaged
                    if ($damaged > 0) {
                        $new_status = ($damaged >= $actual) ? 'Damaged - Total Loss' : 'Partially Damaged';
                        $upP = $conn->prepare('UPDATE inventory_pallets SET status = ? WHERE id = ?');
                        $upP->bind_param('si', $new_status, $pid);
                        $upP->execute();
                        $upP->close();
                    }

                    $expected_total += $expected; $actual_total += $actual; $damaged_total += $damaged; $accepted_total += $accepted;
                }
            }

            if (!empty($pallet_details)) {
                $notesJson = json_encode(['pallets' => $pallet_details], JSON_UNESCAPED_SLASHES);
            }
        }
    }

    // Insert warranty claim (with pallets summary if present)
    $status = 'Submitted';
    $deliveryDt = ($deliveryDate !== '') ? ($deliveryDate . ' 00:00:00') : null;

    $sql = 'INSERT INTO warranty_claims (scheduling_id, bol_number, delivery_date, status, notes, pictures, responsible_party, issue_type, expected_quantity, actual_quantity, damaged_quantity, accepted_quantity, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
    $stmtW = $conn->prepare($sql);
    $emptyPics = null;
    // Bind considering totals may be null
    $exp = isset($expected_total) ? (int)$expected_total : 0;
    $act = isset($actual_total) ? (int)$actual_total : 0;
    $dam = isset($damaged_total) ? (int)$damaged_total : 0;
    $acc = isset($accepted_total) ? (int)$accepted_total : 0;
    $stmtW->bind_param('isssssssiiii', $appointmentId, $bolNumber, $deliveryDt, $status, $notesJson, $emptyPics, $responsible, $issueType, $exp, $act, $dam, $acc);
    if (!$stmtW->execute()) { throw new Exception('Failed to create warranty claim: ' . $stmtW->error); }
    $claimId = (int)$conn->insert_id;
    $stmtW->close();

    // Internal notes
    if ($internalNotes !== '') {
        $up = $conn->prepare('UPDATE warranty_claims SET internal_notes = ? WHERE id = ?');
        $up->bind_param('si', $internalNotes, $claimId);
        $up->execute();
        $up->close();
    }

    // Handle file uploads
    $newUploads = [];
    if (!empty($_FILES['proof_files']) && is_array($_FILES['proof_files']['name'])) {
        $newUploads = storeUploadedFiles($_FILES['proof_files'], $claimId);
        if (!empty($newUploads)) {
            $picsJson = json_encode(array_values($newUploads), JSON_UNESCAPED_SLASHES);
            $up2 = $conn->prepare('UPDATE warranty_claims SET pictures = ? WHERE id = ?');
            $up2->bind_param('si', $picsJson, $claimId);
            $up2->execute();
            $up2->close();
        }
    }

    // Events: creation + optional public note + uploads summary
    insertEvent($conn, $claimId, $userId, 'Warranty claim created manually', 1);
    setLastPublicUpdateNow($conn, $claimId);
    if ($publicNotes !== '') {
        insertEvent($conn, $claimId, $userId, $publicNotes, 1);
        setLastPublicUpdateNow($conn, $claimId);
    }
    if (!empty($newUploads)) {
        $files = array_map(function($p){ return basename($p); }, $newUploads);
        insertEvent($conn, $claimId, $userId, 'Files added: ' . implode(', ', $files), 1);
        setLastPublicUpdateNow($conn, $claimId);
    }

    $conn->commit();
    // Notify subscribers
    notifyUsers($claimId);
    $conn->close();
    header('Location: warranty_detail.php?id=' . $claimId);
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
    exit();
}

?>
