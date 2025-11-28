<?php
session_name("logistics_session");
session_start();

// Admin-only page
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header('Location: unauthorized');
    exit();
}

require_once '../config.php';
require_once 'document_helpers.php';
require_once 'anticipated_schedule_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    die('Database connection failed.');
}

$user_id = intval($_SESSION['user_id']);
$user_role = $_SESSION['role'];
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_id'])) {
    $project_id = intval($_POST['project_id']);
}
$action = $_POST['action'] ?? '';

// ---------- Helpers ----------
function sanitizeFileName($name) {
    $name = preg_replace('/[\\\\\/:*?"<>|]/', '_', $name);
    $name = preg_replace('/\s+/', '_', $name);
    return trim($name, '_');
}

function ensureDir($path) {
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}

function writeCsv($path, $headers, $rows) {
    $f = fopen($path, 'w');
    if (!$f) { return; }
    fputcsv($f, $headers);
    foreach ($rows as $row) {
        fputcsv($f, $row);
    }
    fclose($f);
}

function addDirToZip($zip, $dir, $baseLength) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($files as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, $baseLength + 1);
        if ($file->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($filePath, $relativePath);
        }
    }
}

function fetchProjectsForUser($conn, $user_id, $role) {
    if ($role === 'global_admin') {
        $sql = "SELECT id, project_name FROM projects ORDER BY project_name ASC";
        $res = $conn->query($sql);
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    $sql = "
        SELECT p.id, p.project_name
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE cau.user_id = ? AND cau.role = 'admin'
        ORDER BY p.project_name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function fetchProjectRow($conn, $project_id) {
    $sql = "
        SELECT p.*, ca.name AS account_name
        FROM projects p
        LEFT JOIN customer_accounts ca ON p.account_id = ca.id
        WHERE p.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

function fetchUserRow($conn, $user_id) {
    $stmt = $conn->prepare('SELECT username, email, first_name, last_name FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

function fetchTotals($conn, $project_id) {
    $total_order = 0;
    $stmt = $conn->prepare('SELECT COALESCE(SUM(total_order),0) AS total_order FROM project_wattage_orders WHERE project_id = ?');
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $stmt->bind_result($total_order);
    $stmt->fetch();
    $stmt->close();

    $delivered = 0;
    $sql = "
        SELECT COALESCE(SUM(ip.quantity),0) AS delivered
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ? AND ip.status = 'Delivered to Project'
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $stmt->bind_result($delivered);
    $stmt->fetch();
    $stmt->close();

    $percent = ($total_order > 0) ? round(($delivered / $total_order) * 100, 2) : 0;

    return [$total_order, $delivered, $percent];
}

function collectDataAndBuildArchive($conn, $project_id, $user_row, $project_row, $totals) {
    [$total_order, $delivered, $percent] = $totals;
    $rootName = 'Project_' . $project_id . '_' . sanitizeFileName($project_row['project_name'] ?? 'project');
    $tempBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'project_close_' . uniqid();
    $rootPath = $tempBase . DIRECTORY_SEPARATOR . $rootName;
    ensureDir($rootPath);

    // 00 Project Summary
    $summaryDir = $rootPath . '/00_Project_Summary';
    ensureDir($summaryDir);
    $summaryData = [
        'project_id' => $project_id,
        'project_name' => $project_row['project_name'] ?? '',
        'account_name' => $project_row['account_name'] ?? '',
        'generated_at' => date('c'),
        'generated_by' => $user_row['email'] ?? ($user_row['username'] ?? ''),
        'delivered_modules' => $delivered,
        'total_ordered_modules' => $total_order,
        'delivered_percent' => $percent,
    ];
    file_put_contents($summaryDir . '/summary.json', json_encode($summaryData, JSON_PRETTY_PRINT));

    // 01 Project Info
    $infoDir = $rootPath . '/01_Project_Info';
    ensureDir($infoDir);
    $projectHeaders = ['Project ID','Project Name','Account','Address','City','State','Zip','Phone1','Phone2','Timezone','Reference Numbers','Instructions','Standard Operating Hours','Additional Notes'];
    $projectRows = [[
        $project_row['id'] ?? '',
        $project_row['project_name'] ?? '',
        $project_row['account_name'] ?? '',
        $project_row['street_address'] ?? $project_row['project_address'] ?? '',
        $project_row['city'] ?? '',
        $project_row['state'] ?? '',
        $project_row['zip_code'] ?? '',
        $project_row['phone1'] ?? '',
        $project_row['phone2'] ?? '',
        $project_row['timezone'] ?? '',
        $project_row['reference_numbers'] ?? '',
        $project_row['instructions'] ?? '',
        $project_row['standard_operating_hours'] ?? '',
        $project_row['additional_notes'] ?? ''
    ]];
    writeCsv($infoDir . '/project_info.csv', $projectHeaders, $projectRows);

    // 02 Documents
    $docsDir = $rootPath . '/02_Documents';
    ensureDir($docsDir);
    $stmtDocs = $conn->prepare('SELECT document_type, document_sub_type, original_file_name, file_path FROM project_documents WHERE project_id = ? AND is_active = 1');
    $stmtDocs->bind_param('i', $project_id);
    $stmtDocs->execute();
    $resDocs = $stmtDocs->get_result();
    while ($doc = $resDocs->fetch_assoc()) {
        $type = $doc['document_type'] ?: 'uncategorized';
        $sub = $doc['document_sub_type'] ?: 'General';
        $folder = $docsDir . '/' . sanitizeFileName($type) . '/' . sanitizeFileName($sub);
        ensureDir($folder);
        $dest = $folder . '/' . sanitizeFileName($doc['original_file_name']);
        if (!empty($doc['file_path']) && is_file($doc['file_path'])) {
            @copy($doc['file_path'], $dest);
        }
    }
    $stmtDocs->close();

    // 03 Modules
    $modulesDir = $rootPath . '/03_Modules';
    ensureDir($modulesDir);
    $sqlBatches = "
        SELECT m.*, COALESCE(SUM(umi.quantity),0) AS total_modules,
               GROUP_CONCAT(CONCAT(umi.wattage,'W=',umi.quantity) SEPARATOR '; ') AS wattage_breakdown
        FROM modules m
        LEFT JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ?
        GROUP BY m.id
        ORDER BY m.id ASC
    ";
    $stmtB = $conn->prepare($sqlBatches);
    $stmtB->bind_param('i', $project_id);
    $stmtB->execute();
    $resB = $stmtB->get_result();
    $batchRows = [];
    while ($b = $resB->fetch_assoc()) {
        $batchRows[] = [
            $b['id'], $b['vendor_name'], $b['initial_location'], $b['total_modules'], $b['wattage_breakdown'],
            $b['modules_per_pallet'], $b['pallets_per_truck'], $b['modules_per_truck'],
            $b['pallet_length_mm'], $b['pallet_depth_mm'], $b['pallet_double_stacked_height_mm'],
            $b['pallet_total_weight_kg'], $b['stacking_in_warehouse'], $b['stacking_during_transport'],
            $b['forklift_truck_long_side_mm'], $b['forklift_truck_short_side_mm'],
            $b['pallet_jack_long_side_mm'], $b['pallet_jack_short_side_mm'],
            $b['module_notes'], $b['module_docs_url'], $b['module_additional_notes']
        ];
    }
    $stmtB->close();
    $batchHeaders = ['Batch ID','Vendor','Initial Location','Total Modules','Wattage Breakdown','Modules per Pallet','Pallets per Truck','Modules per Truck','Pallet Length (mm)','Pallet Depth (mm)','Pallet Height (mm)','Pallet Weight (kg)','Stacking in Warehouse','Stacking during Transport','Forklift Long (mm)','Forklift Short (mm)','Pallet Jack Long (mm)','Pallet Jack Short (mm)','Module Notes','Module Docs URL','Module Additional Notes'];
    writeCsv($modulesDir . '/batches.csv', $batchHeaders, $batchRows);

    // Pallets
    $sqlPallets = "
        SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status,
               ip.current_warehouse_id, w.name AS warehouse_name,
               ip.current_project_id, cp.project_name AS current_project_name,
               ip.assigned_project_id, ap.project_name AS assigned_project_name,
               ip.arrival_date, ip.created_at, ip.updated_at, ip.manufacturer, ip.manufacturer_location_id
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
        LEFT JOIN projects cp ON ip.current_project_id = cp.id
        LEFT JOIN projects ap ON ip.assigned_project_id = ap.id
        WHERE m.project_id = ?
        ORDER BY ip.id ASC
    ";
    $stmtP = $conn->prepare($sqlPallets);
    $stmtP->bind_param('i', $project_id);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    $palletRows = [];
    while ($p = $resP->fetch_assoc()) {
        $palletRows[] = [
            $p['id'], $p['pallet_identifier'], $p['wattage'], $p['quantity'], $p['status'],
            $p['warehouse_name'], $p['current_project_name'], $p['assigned_project_name'],
            $p['arrival_date'], $p['created_at'], $p['updated_at'], $p['manufacturer'], $p['manufacturer_location_id']
        ];
    }
    $stmtP->close();
    $palletHeaders = ['Pallet ID','Identifier','Wattage','Quantity','Status','Warehouse','Current Project','Assigned Project','Arrival Date','Created At','Updated At','Manufacturer','Manufacturer Location ID'];
    writeCsv($modulesDir . '/pallets.csv', $palletHeaders, $palletRows);

    // Movements (module_allocation_moves)
    $sqlMoves = "
        SELECT mam.*, ip.pallet_identifier
        FROM module_allocation_moves mam
        JOIN inventory_pallets ip ON mam.inventory_pallet_id = ip.id
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ?
        ORDER BY mam.created_at ASC
    ";
    if ($stmtM = $conn->prepare($sqlMoves)) {
        $stmtM->bind_param('i', $project_id);
        $stmtM->execute();
        $resM = $stmtM->get_result();
        $moveRows = [];
        while ($mv = $resM->fetch_assoc()) {
            $moveRows[] = [
                $mv['id'], $mv['inventory_pallet_id'], $mv['pallet_identifier'],
                $mv['from_project_id'], $mv['to_project_id'], $mv['from_status'], $mv['to_status'],
                $mv['from_warehouse_id'], $mv['to_warehouse_id'], $mv['quantity'], $mv['notes'], $mv['created_at']
            ];
        }
        $stmtM->close();
        $moveHeaders = ['Move ID','Pallet ID','Pallet Identifier','From Project','To Project','From Status','To Status','From Warehouse','To Warehouse','Quantity','Notes','Created At'];
        writeCsv($modulesDir . '/pallet_movements.csv', $moveHeaders, $moveRows);
    }

    // 05 Deliveries
    $deliveriesDir = $rootPath . '/05_Deliveries';
    ensureDir($deliveriesDir);
    $stmtD = $conn->prepare('SELECT * FROM deliveries WHERE project_id = ? ORDER BY COALESCE(actual_delivery_date, anticipated_delivery_date, created_at)');
    $stmtD->bind_param('i', $project_id);
    $stmtD->execute();
    $resD = $stmtD->get_result();
    $delRows = [];
    while ($d = $resD->fetch_assoc()) {
        $delRows[] = $d;
    }
    $stmtD->close();
    if (!empty($delRows)) {
        $headers = array_keys($delRows[0]);
        $rows = [];
        foreach ($delRows as $row) { $rows[] = array_values($row); }
        writeCsv($deliveriesDir . '/deliveries.csv', $headers, $rows);
    } else {
        writeCsv($deliveriesDir . '/deliveries.csv', ['message'], [['No deliveries found']]);
    }

    // Appointments / scheduling
    $stmtSched = $conn->prepare('SELECT * FROM site_scheduling WHERE project_id = ? ORDER BY start_time');
    $stmtSched->bind_param('i', $project_id);
    $stmtSched->execute();
    $resSched = $stmtSched->get_result();
    $schedRows = [];
    while ($s = $resSched->fetch_assoc()) { $schedRows[] = $s; }
    $stmtSched->close();
    if (!empty($schedRows)) {
        $headers = array_keys($schedRows[0]);
        $rows = [];
        foreach ($schedRows as $row) { $rows[] = array_values($row); }
        writeCsv($deliveriesDir . '/appointments.csv', $headers, $rows);
    }

    // Anticipated schedule snapshot (dates + cumulative MW from helper)
    $schedule = generateAnticipatedDeliveriesFromSchedule($conn, $project_id);
    if (!empty($schedule['dates'])) {
        $rows = [];
        foreach ($schedule['dates'] as $idx => $date) {
            $rows[] = [$date, $schedule['cumulative_mw'][$idx] ?? null, $schedule['notes'][$idx] ?? ''];
        }
        writeCsv($deliveriesDir . '/anticipated_schedule.csv', ['Date','Cumulative MW','Notes'], $rows);
    }

    // 06 Warehousing
    $whDir = $rootPath . '/06_Warehousing';
    ensureDir($whDir);
    $sqlWarehouses = "
        SELECT DISTINCT w.*
        FROM warehouses w
        LEFT JOIN inventory_pallets ip ON ip.current_warehouse_id = w.id
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN deliveries d ON d.warehouse_id = w.id
        WHERE m.project_id = ? OR d.project_id = ?
    ";
    $stmtW = $conn->prepare($sqlWarehouses);
    $stmtW->bind_param('ii', $project_id, $project_id);
    $stmtW->execute();
    $resW = $stmtW->get_result();
    $whRows = [];
    $warehouseIds = [];
    while ($w = $resW->fetch_assoc()) {
        $whRows[] = $w;
        $warehouseIds[] = intval($w['id']);
    }
    $stmtW->close();
    if (!empty($whRows)) {
        $headers = array_keys($whRows[0]);
        $rows = [];
        foreach ($whRows as $row) { $rows[] = array_values($row); }
        writeCsv($whDir . '/warehouses.csv', $headers, $rows);
    }

    // Inventory by warehouse
    $sqlInv = "
        SELECT w.name AS warehouse_name, w.id AS warehouse_id, ip.status, COUNT(*) AS pallets, SUM(ip.quantity) AS modules
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
        WHERE m.project_id = ?
        GROUP BY w.id, ip.status
        ORDER BY w.name
    ";
    $stmtInv = $conn->prepare($sqlInv);
    $stmtInv->bind_param('i', $project_id);
    $stmtInv->execute();
    $resInv = $stmtInv->get_result();
    $invRows = [];
    while ($ir = $resInv->fetch_assoc()) { $invRows[] = $ir; }
    $stmtInv->close();
    if (!empty($invRows)) {
        $headers = array_keys($invRows[0]);
        $rows = [];
        foreach ($invRows as $row) { $rows[] = array_values($row); }
        writeCsv($whDir . '/inventory_by_warehouse.csv', $headers, $rows);
    }

    // Warehouse cost items
    if (!empty($warehouseIds)) {
        $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
        $types = str_repeat('i', count($warehouseIds));
        $sqlCost = "SELECT * FROM warehouse_cost_items WHERE warehouse_id IN ($placeholders)";
        $stmtCost = $conn->prepare($sqlCost);
        $stmtCost->bind_param($types, ...$warehouseIds);
        $stmtCost->execute();
        $resCost = $stmtCost->get_result();
        $costRows = [];
        while ($cr = $resCost->fetch_assoc()) { $costRows[] = $cr; }
        $stmtCost->close();
        if (!empty($costRows)) {
            $headers = array_keys($costRows[0]);
            $rows = [];
            foreach ($costRows as $row) { $rows[] = array_values($row); }
            writeCsv($whDir . '/warehouse_cost_items.csv', $headers, $rows);
        }
    }

    // 07 Financials (reuse deliveries cost fields)
    $finDir = $rootPath . '/07_Financials';
    ensureDir($finDir);
    if (!empty($delRows)) {
        writeCsv($finDir . '/cost_details.csv', array_keys($delRows[0]), array_map('array_values', $delRows));
        $totals = [
            'freight_cost' => 0,
            'accessorial_costs' => 0,
            'customer_cost' => 0,
            'warehousing_cost' => 0,
            'count' => count($delRows)
        ];
        foreach ($delRows as $row) {
            $totals['freight_cost'] += floatval($row['freight_cost'] ?? 0);
            $totals['accessorial_costs'] += floatval($row['accessorial_costs'] ?? 0);
            $totals['customer_cost'] += floatval($row['customer_cost'] ?? 0);
        }
        file_put_contents($finDir . '/cost_summary.json', json_encode($totals, JSON_PRETTY_PRINT));
    }

    // 08 Sustainability (placeholder sourced from sustainability details page if available)
    $susDir = $rootPath . '/08_Sustainability';
    ensureDir($susDir);
    writeCsv($susDir . '/sustainability.csv', ['message'], [['No sustainability records available in current export scope.']]);

    // 09 Warranty / Exceptions
    $warrantyDir = $rootPath . '/09_Warranty_Exceptions';
    ensureDir($warrantyDir);
    $sqlWar = "
        SELECT wc.*
        FROM warranty_claims wc
        LEFT JOIN site_scheduling ss ON wc.scheduling_id = ss.id
        WHERE ss.project_id = ?
    ";
    $stmtWar = $conn->prepare($sqlWar);
    $stmtWar->bind_param('i', $project_id);
    $stmtWar->execute();
    $resWar = $stmtWar->get_result();
    $warRows = [];
    while ($wr = $resWar->fetch_assoc()) { $warRows[] = $wr; }
    $stmtWar->close();
    if (!empty($warRows)) {
        $headers = array_keys($warRows[0]);
        $rows = [];
        foreach ($warRows as $row) { $rows[] = array_values($row); }
        writeCsv($warrantyDir . '/exceptions.csv', $headers, $rows);
    } else {
        writeCsv($warrantyDir . '/exceptions.csv', ['message'], [['No warranty/exception records found.']]);
    }

    // 10 Photos (documents already include pictures, also export ordering if exists)
    $photosDir = $rootPath . '/10_Photos';
    ensureDir($photosDir);
    $orderPath = __DIR__ . "/uploads/project_documents/{$project_id}/pictures/.order.json";
    if (is_file($orderPath)) {
        @copy($orderPath, $photosDir . '/photos_manifest.json');
    }
    // Copy photo files (document_type = pictures)
    $stmtPhoto = $conn->prepare('SELECT original_file_name, file_path FROM project_documents WHERE project_id = ? AND document_type = "pictures" AND is_active = 1');
    $stmtPhoto->bind_param('i', $project_id);
    $stmtPhoto->execute();
    $resPhoto = $stmtPhoto->get_result();
    while ($ph = $resPhoto->fetch_assoc()) {
        $dest = $photosDir . '/' . sanitizeFileName($ph['original_file_name']);
        if (!empty($ph['file_path']) && is_file($ph['file_path'])) {
            @copy($ph['file_path'], $dest);
        }
    }
    $stmtPhoto->close();

    // README
    $readme = "Project Closure Package\n" .
        "Project: " . ($project_row['project_name'] ?? '') . " (ID: {$project_id})\n" .
        "Account: " . ($project_row['account_name'] ?? 'N/A') . "\n" .
        "Generated: " . date('c') . "\n" .
        "Generated by: " . ($user_row['email'] ?? ($user_row['username'] ?? '')) . "\n" .
        "Delivered %: {$percent}%\n" .
        "Structure:\n" .
        "00_Project_Summary – summary.json\n" .
        "01_Project_Info – project_info.csv\n" .
        "02_Documents – all project documents grouped by type/sub-type\n" .
        "03_Modules – batches.csv, pallets.csv, pallet_movements.csv\n" .
        "05_Deliveries – deliveries.csv, appointments.csv, anticipated_schedule.csv\n" .
        "06_Warehousing – warehouses.csv, inventory_by_warehouse.csv, warehouse_cost_items.csv\n" .
        "07_Financials – cost_details.csv, cost_summary.json\n" .
        "08_Sustainability – placeholder data\n" .
        "09_Warranty_Exceptions – exceptions.csv\n" .
        "10_Photos – pictures and manifest\n";
    file_put_contents($rootPath . '/README.txt', $readme);

    // Zip
    $zipPath = $tempBase . '/' . $rootName . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
        throw new Exception('Could not create zip');
    }
    addDirToZip($zip, $rootPath, strlen($rootPath));
    $zip->close();

    return [$tempBase, $zipPath];
}

// ---------- Access Check & Data ----------
$availableProjects = fetchProjectsForUser($conn, $user_id, $user_role);
if ($project_id > 0) {
    // Verify access to selected project
    $allowed = array_filter($availableProjects, fn($p) => intval($p['id']) === $project_id);
    if (empty($allowed) && $user_role !== 'global_admin') {
        die('You do not have access to this project.');
    }
    // For global admin, still verify project exists
    if ($user_role === 'global_admin' && empty($allowed)) {
        $row = fetchProjectRow($conn, $project_id);
        if (!$row) { die('Project not found.'); }
        $availableProjects[] = ['id' => $project_id, 'project_name' => $row['project_name']];
    }
}

$project_row = $project_id ? fetchProjectRow($conn, $project_id) : null;
$totals = $project_id ? fetchTotals($conn, $project_id) : [0,0,0];

if ($action === 'export' && $project_id > 0) {
    [$total_order, $delivered, $percent] = $totals;
    if ($total_order > 0 && $percent < 100) {
        $_SESSION['project_close_error'] = 'Project must be 100% delivered before closing.';
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }
    try {
        $user_row = fetchUserRow($conn, $user_id) ?? ['username' => ''];
        [$tmpDir, $zipPath] = collectDataAndBuildArchive($conn, $project_id, $user_row, $project_row, $totals);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);

        // Cleanup
        @unlink($zipPath);
        $it = new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($tmpDir);
        exit();
    } catch (Exception $e) {
        $_SESSION['project_close_error'] = 'Export failed: ' . $e->getMessage();
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }
}

$flash_error = $_SESSION['project_close_error'] ?? '';
unset($_SESSION['project_close_error']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Closure</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #f7f9fb 0%, #eef3f7 100%); }
        .page-wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); padding: 24px; margin-bottom: 18px; border: 1px solid #e9ecef; }
        .card h2 { margin-top: 0; color: #1f3b4d; font-weight: 700; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
        .pill { display: inline-flex; align-items: center; padding: 10px 14px; border-radius: 12px; background: #f1f6f9; color: #1f3b4d; font-weight: 600; font-size: 14px; }
        .pill strong { margin-right: 8px; color: #488C9A; }
        .cta { display: inline-flex; align-items: center; gap: 10px; padding: 14px 18px; border: none; border-radius: 12px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color: #fff; font-weight: 700; cursor: pointer; box-shadow: 0 10px 20px rgba(72,140,154,0.25); transition: transform 0.1s ease, box-shadow 0.2s ease; }
        .cta:disabled { opacity: 0.55; cursor: not-allowed; box-shadow: none; }
        .cta:not(:disabled):hover { transform: translateY(-1px); box-shadow: 0 12px 26px rgba(72,140,154,0.28); }
        .warning { color: #c0392b; font-weight: 600; }
        .select-group { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        select { padding: 10px 12px; border-radius: 10px; border: 1px solid #d9e2ec; min-width: 260px; }
        .badge { display: inline-block; padding: 6px 10px; border-radius: 10px; background: #e8f4f7; color: #2c6070; font-weight: 600; }
        .hero { background: linear-gradient(135deg, rgba(72,140,154,0.12), rgba(58,110,127,0.08)); border-radius: 18px; padding: 18px; display: flex; flex-direction: column; gap: 8px; }
        .hero h1 { margin: 0; font-size: 26px; color: #1a2c38; }
        .progress { display: flex; align-items: center; gap: 12px; }
        .progress-bar { flex: 1; height: 12px; border-radius: 999px; background: #e9ecef; position: relative; overflow: hidden; }
        .progress-bar span { position: absolute; left: 0; top: 0; bottom: 0; width: var(--val, 0%); background: linear-gradient(90deg, #4db6ac, #2c98a0); border-radius: 999px; }
        .stat { padding: 14px; border-radius: 14px; background: #f9fbfd; border: 1px solid #e7eef4; }
        .stat .label { color: #5f6f7d; font-size: 13px; text-transform: uppercase; letter-spacing: 0.6px; }
        .stat .value { font-size: 22px; font-weight: 700; color: #1f3b4d; }
        .flash { padding: 12px 14px; border-radius: 10px; background: #ffecec; color: #c0392b; border: 1px solid #f5c2c7; margin-bottom: 12px; }
        .two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main class="page-wrap">
    <div class="hero card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
            <div>
                <div class="badge">Project Closure</div>
                <h1>Deliver the final package</h1>
                <p style="margin:6px 0 0;color:#4a5b6a;max-width:720px;">Generate a full, customer-ready archive of project documents, deliveries, warehousing, and costs. Export is enabled when the project is 100% delivered.</p>
            </div>
            <div>
                <?php if ($project_id): ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="project_id" value="<?php echo (int)$project_id; ?>">
                        <input type="hidden" name="action" value="export">
                        <button type="submit" class="cta" <?php echo ($totals[0] > 0 && $totals[2] >= 100) ? '' : 'disabled'; ?>>
                            📦 Export Closure Package
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($flash_error)): ?>
        <div class="flash"><?php echo htmlspecialchars($flash_error); ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Select Project</h2>
        <form method="get">
            <div class="select-group">
                <select name="project_id" required>
                    <option value="">Choose a project...</option>
                    <?php foreach ($availableProjects as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$p['id'] === $project_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['project_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="cta">Load Project</button>
            </div>
        </form>
    </div>

    <?php if ($project_id && $project_row): ?>
    <div class="card">
        <h2>Project Snapshot</h2>
        <div class="grid">
            <div class="stat">
                <div class="label">Project</div>
                <div class="value"><?php echo htmlspecialchars($project_row['project_name']); ?></div>
                <div class="pill"><strong>Account</strong> <?php echo htmlspecialchars($project_row['account_name'] ?? ''); ?></div>
            </div>
            <div class="stat">
                <div class="label">Delivery Progress</div>
                <div class="value"><?php echo number_format($totals[2],2); ?>%</div>
                <div class="progress" style="margin-top:10px;">
                    <div class="progress-bar"><span style="--val: <?php echo min(100,$totals[2]); ?>%;"></span></div>
                </div>
                <?php if ($totals[0] > 0): ?>
                    <small style="color:#5f6f7d;display:block;margin-top:6px;">Delivered <?php echo number_format($totals[1]); ?> / <?php echo number_format($totals[0]); ?> modules</small>
                <?php else: ?>
                    <small class="warning">No wattage orders found for this project.</small>
                <?php endif; ?>
            </div>
            <div class="stat">
                <div class="label">Address</div>
                <div class="value" style="font-size:16px;line-height:1.4;">
                    <?php echo htmlspecialchars(($project_row['street_address'] ?: $project_row['project_address'] ?: '')); ?><br>
                    <?php echo htmlspecialchars($project_row['city'] . ' ' . ($project_row['state'] ?? '') . ' ' . ($project_row['zip_code'] ?? '')); ?>
                </div>
            </div>
        </div>
        <?php if ($totals[0] > 0 && $totals[2] < 100): ?>
            <p class="warning" style="margin-top:12px;">Closure export is locked until delivery reaches 100%.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Included in Export</h2>
        <div class="two-col">
            <div class="pill"><strong>00</strong> Project Summary (JSON)</div>
            <div class="pill"><strong>01</strong> Project Info (CSV)</div>
            <div class="pill"><strong>02</strong> Documents grouped by type</div>
            <div class="pill"><strong>03</strong> Module batches, pallets, movements</div>
            <div class="pill"><strong>05</strong> Deliveries, appointments, anticipated schedule</div>
            <div class="pill"><strong>06</strong> Warehouses, inventory, cost items</div>
            <div class="pill"><strong>07</strong> Financial cost summary</div>
            <div class="pill"><strong>09</strong> Warranty / Exceptions</div>
            <div class="pill"><strong>10</strong> Photos & ordering</div>
        </div>
    </div>
    <?php endif; ?>
</main>
</body>
</html>
