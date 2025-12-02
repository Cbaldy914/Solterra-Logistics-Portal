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

$notesArr = jsonToArray($claim['notes'] ?? '');

$planItems = [];

try {
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
            $ppt = isset($item['pallets_per_truck']) && $item['pallets_per_truck'] !== '' ? (int)$item['pallets_per_truck'] : null;
            $mpt = isset($item['modules_per_truck']) && $item['modules_per_truck'] !== '' ? (int)$item['modules_per_truck'] : null;

            if ($watt <= 0) { continue; }

            $totalModules = ($full * $mpp) + $part;
            if ($totalModules <= 0) { continue; }

            $planItems[] = [
                'mode' => 'quick',
                'wattage' => $watt,
                'modules_per_pallet' => $mpp,
                'full_pallets' => $full,
                'partial_modules' => $part,
                'manufacturer' => $manufacturer,
                'manufacturer_location_id' => $locationId,
                'pallets_per_truck' => $ppt,
                'modules_per_truck' => $mpt,
                'total_modules' => $totalModules,
            ];
        }
    } elseif ($mode === 'manual') {
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

            $full = intdiv($qtyTotal, $mpp);
            $rem = $qtyTotal - ($full * $mpp);

            $planItems[] = [
                'mode' => 'manual',
                'wattage' => $watt,
                'modules_per_pallet' => $mpp,
                'full_pallets' => $full,
                'partial_modules' => $rem,
                'manufacturer' => $manufacturer,
                'manufacturer_id' => $manufacturerId,
                'manufacturer_location_id' => $locationId,
                'pallets_per_truck' => null,
                'modules_per_truck' => null,
                'total_modules' => $qtyTotal,
            ];
        }
    } else {
        throw new Exception('Unsupported mode');
    }

    if (empty($planItems)) {
        throw new Exception('No replacement entries provided');
    }

    $notesArr['replacement_plan'] = [
        'items' => array_values($planItems),
        'created_by' => $userId,
        'created_at' => date('c'),
    ];

    $notesJson = json_encode($notesArr, JSON_UNESCAPED_SLASHES);
    $stmtSave = $conn->prepare('UPDATE warranty_claims SET notes = ?, updated_at = NOW() WHERE id = ?');
    $stmtSave->bind_param('si', $notesJson, $claimId);
    $stmtSave->execute();
    $stmtSave->close();

    $totalModules = 0; $totalPallets = 0;
    foreach ($planItems as $pi) {
        $totalModules += (int)($pi['total_modules'] ?? 0);
        $totalPallets += (int)($pi['full_pallets'] ?? 0);
        if (!empty($pi['partial_modules'])) { $totalPallets += 1; }
    }

    $_SESSION['flash_success'] = 'Replacement plan staged: ' . $totalPallets . ' pallet' . ($totalPallets === 1 ? '' : 's') . ' / ' . $totalModules . ' modules. Choose "Approve" → "Replacement" and click Apply Decision to create the pallets at Manufacturer.';

    $conn->close();
    header('Location: warranty_detail.php?id=' . $claimId . '&prefill=approve_replacement');
    exit();
} catch (Exception $e) {
    $conn->close();
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
    exit();
}

?>
