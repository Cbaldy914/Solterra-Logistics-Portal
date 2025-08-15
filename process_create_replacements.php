<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { header('Location: login'); exit(); }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!isAdminRole()) { header('Location: unauthorized.php'); exit(); }

// CSRF
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    http_response_code(400);
    die('Invalid CSRF token');
}

$claimId = isset($_POST['claim_id']) ? (int)$_POST['claim_id'] : 0;
if ($claimId <= 0) { die('Invalid claim'); }

$mode = $_POST['mode'] ?? 'quick';

$conn = getDBConnection();

// Resolve claim and project
$stmt = $conn->prepare('SELECT w.*, ss.project_id, p.account_id FROM warranty_claims w JOIN site_scheduling ss ON ss.id = w.scheduling_id JOIN projects p ON p.id = ss.project_id WHERE w.id = ?');
$stmt->bind_param('i', $claimId);
$stmt->execute();
$res = $stmt->get_result();
$claim = $res->fetch_assoc();
$stmt->close();
if (!$claim) { $conn->close(); die('Claim not found'); }
$projectId = (int)$claim['project_id'];
$accountId = (int)$claim['account_id'];

$conn->begin_transaction();

try {
    $createdPalletIds = [];
    if ($mode === 'quick') {
        $planJson = $_POST['plan_json'] ?? '[]';
        $plan = json_decode($planJson, true);
        if (!is_array($plan)) { throw new Exception('Invalid plan'); }

        foreach ($plan as $item) {
            $watt = (int)($item['wattage'] ?? 0);
            $mpp = max(1, (int)($item['modules_per_pallet'] ?? 0));
            $full = max(0, (int)($item['full_pallets'] ?? 0));
            $part = max(0, (int)($item['partial_modules'] ?? 0));
            $manufacturer = trim((string)($item['manufacturer'] ?? ''));
            $locationId = isset($item['manufacturer_location_id']) && $item['manufacturer_location_id'] !== '' ? (int)$item['manufacturer_location_id'] : null;

            if ($watt <= 0) continue;

            // Create minimal modules batch row for this group to satisfy FK
            $vendorName = ($manufacturer !== '') ? $manufacturer : 'Replacement';
            $initialLocation = 'Manufacturer';
            $stmtMod = $conn->prepare('INSERT INTO modules (account_id, vendor_name, initial_location, project_id) VALUES (?, ?, ?, ?)');
            $stmtMod->bind_param('issi', $accountId, $vendorName, $initialLocation, $projectId);
            $stmtMod->execute();
            $moduleId = (int)$conn->insert_id;
            $stmtMod->close();

            // Create a UMI entry tied to the modules batch
            $stmtUmi = $conn->prepare('INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)');
            $umiQty = $mpp; // template modules-per-pallet
            $stmtUmi->bind_param('iii', $moduleId, $watt, $umiQty);
            $stmtUmi->execute();
            $umiId = (int)$conn->insert_id;
            $stmtUmi->close();

            // Helper to insert one pallet row with status At Manufacturer and optional location
            $ins = $conn->prepare('INSERT INTO inventory_pallets (pallet_identifier, unassigned_module_item_id, assigned_project_id, wattage, quantity, status, manufacturer, manufacturer_location_id) VALUES (?, ?, ?, ?, ?, "At Manufacturer", ?, ?)');
            // Full pallets
            for ($i=0; $i<$full; $i++) {
                $empty = '';
                $q = $mpp;
                $ins->bind_param('siiidsi', $empty, $umiId, $projectId, $watt, $q, $manufacturer, $locationId);
                $ins->execute();
                $newId = (int)$conn->insert_id;
                // Set pallet_identifier
                $pid = 'P' . $newId;
                $u = $conn->prepare('UPDATE inventory_pallets SET pallet_identifier = ? WHERE id = ?');
                $u->bind_param('si', $pid, $newId);
                $u->execute();
                $u->close();
                $createdPalletIds[] = $newId;
            }
            // Partial pallet
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
                $createdPalletIds[] = $newId;
            }
            $ins->close();
        }
    } else if ($mode === 'manual') {
        // New manual mode: wattage + comma-separated list of modules-per-pallet; manufacturer and location required
        $wattages = isset($_POST['wattage']) ? (array)$_POST['wattage'] : [];
        $quantities = isset($_POST['quantity']) ? (array)$_POST['quantity'] : [];
        $mpps = isset($_POST['modules_per_pallet']) ? (array)$_POST['modules_per_pallet'] : [];
        $manuIds = isset($_POST['manufacturer_id']) ? (array)$_POST['manufacturer_id'] : [];
        $locs = isset($_POST['location_id']) ? (array)$_POST['location_id'] : [];

        $rowCount = max(count($wattages), count($quantities));
        for ($i=0; $i<$rowCount; $i++) {
            $watt = (int)($wattages[$i] ?? 0);
            $qtyTotal = (int)($quantities[$i] ?? 0);
            $mpp = (int)($mpps[$i] ?? 0);
            $manufacturerId = isset($manuIds[$i]) ? (int)$manuIds[$i] : 0;
            $locationIdRaw = $locs[$i] ?? '';
            $locationId = ($locationIdRaw === '' || $locationIdRaw === null) ? null : (int)$locationIdRaw;
            if ($watt <= 0 || $qtyTotal <= 0 || $mpp <= 0 || $manufacturerId <= 0 || $locationId === null) continue;

            // Resolve manufacturer name for pallets
            $manufacturer = '';
            $resName = $conn->prepare('SELECT name FROM manufacturers WHERE id = ?');
            $resName->bind_param('i', $manufacturerId);
            $resName->execute();
            $resName->bind_result($manufacturer);
            $resName->fetch();
            $resName->close();

            // Create a module batch and UMI for this row
            $vendorName = $manufacturer;
            $initialLocation = 'Manufacturer';
            $stmtMod = $conn->prepare('INSERT INTO modules (account_id, vendor_name, initial_location, project_id) VALUES (?, ?, ?, ?)');
            $stmtMod->bind_param('issi', $accountId, $vendorName, $initialLocation, $projectId);
            $stmtMod->execute();
            $moduleId = (int)$conn->insert_id;
            $stmtMod->close();

            $stmtUmi = $conn->prepare('INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)');
            $umiQty = 0; // template; real quantity is per pallet
            $stmtUmi->bind_param('iii', $moduleId, $watt, $umiQty);
            $stmtUmi->execute();
            $umiId = (int)$conn->insert_id;
            $stmtUmi->close();

            $ins = $conn->prepare('INSERT INTO inventory_pallets (pallet_identifier, unassigned_module_item_id, assigned_project_id, wattage, quantity, status, manufacturer, manufacturer_location_id) VALUES (?, ?, ?, ?, ?, "At Manufacturer", ?, ?)');
            $full = intdiv($qtyTotal, $mpp);
            $rem = $qtyTotal - ($full * $mpp);
            for ($j=0; $j<$full; $j++) {
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
                $createdPalletIds[] = $newId;
            }
            if ($rem > 0) {
                $empty = '';
                $q = $rem;
                $ins->bind_param('siiidsi', $empty, $umiId, $projectId, $watt, $q, $manufacturer, $locationId);
                $ins->execute();
                $newId = (int)$conn->insert_id;
                $pid = 'P' . $newId;
                $u = $conn->prepare('UPDATE inventory_pallets SET pallet_identifier = ? WHERE id = ?');
                $u->bind_param('si', $pid, $newId);
                $u->execute();
                $u->close();
                $createdPalletIds[] = $newId;
            }
            $ins->close();
        }
    } else {
        throw new Exception('Unsupported mode');
    }

    // Link created pallets to the claim
    if (!empty($createdPalletIds)) {
        $insL = $conn->prepare('INSERT INTO warranty_claim_replacements (claim_id, pallet_id) VALUES (?, ?)');
        foreach ($createdPalletIds as $pid) {
            $insL->bind_param('ii', $claimId, $pid);
            $insL->execute();
        }
        $insL->close();
    }

    $conn->commit();

    // Do NOT post to public timeline now. Instead, set a flash message and
    // pre-fill approve state on the claim page via query string flags.
    $createdCount = count($createdPalletIds);
    $_SESSION['flash_success'] = $createdCount . ' replacement pallet' . ($createdCount===1?'':'s') . ' created and linked to this claim. To officially create the pallets, select "Approve" and "Replacement", then click "Apply Decision".';

    $conn->close();
    header('Location: warranty_detail.php?id=' . $claimId . '&prefill=approve_replacement');
    exit();
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
    exit();
}

?>


