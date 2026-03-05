<?php
session_name("logistics_session");
session_start();

// Allow access for all roles - export is available to everyone
// Close-out functionality will be restricted to admin roles within the page
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin', 'user'])) {
    header('Location: unauthorized');
    exit();
}

// Determine if user can close out projects (admin/global_admin only)
$canCloseProject = in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin']);

// Determine if user can create/edit project summaries (admin/global_admin only)
$canEditSummary = in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin']);

require_once '../config.php';
require_once 'document_helpers.php';
require_once 'anticipated_schedule_helpers.php';
require_once 'cost_helpers.php';
require __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$posted_summary_text = isset($_POST['summary_text']) ? trim($_POST['summary_text']) : null;

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

function summaryStorageDir($project_id) {
    return __DIR__ . "/uploads/project_summaries/{$project_id}";
}

function loadStoredSummaryText($project_id) {
    $path = summaryStorageDir($project_id) . '/summary.txt';
    if (is_file($path)) {
        return file_get_contents($path) ?: '';
    }
    return '';
}

function saveStoredSummaryText($project_id, $text) {
    $dir = summaryStorageDir($project_id);
    ensureDir($dir);
    file_put_contents($dir . '/summary.txt', $text);
}

function buildDefaultSummaryText($project_row, $totals, $conn = null, $project_id = null) {
    [$total_order, $delivered, $percent] = $totals;
    $percentDisplay = number_format($percent, 0);
    $deliveredDisplay = number_format($delivered);

    $projectName = $project_row['project_name'] ?? 'N/A';
    $accountName = $project_row['account_name'] ?? 'N/A';
    $address = trim(($project_row['street_address'] ?? $project_row['project_address'] ?? '') . ', ' .
               ($project_row['city'] ?? '') . ', ' . ($project_row['state'] ?? '') . ' ' . ($project_row['zip_code'] ?? ''), ', ');

    // Initialize additional stats
    $deliveryCount = 0;
    $palletCount = 0;
    $warehouseCount = 0;
    $totalMiles = 0;
    $fuelConsumption = 0;
    $emissions = 0;
    $exceptionCount = 0;
    $wattageInfo = '';
    $mwDelivered = 0;

    if ($conn && $project_id) {
        // Get delivery and mileage stats
        $stmtCost = $conn->prepare('SELECT
            COUNT(DISTINCT id) as delivery_count,
            COALESCE(SUM(miles), 0) as total_miles
            FROM deliveries WHERE project_id = ?');
        $stmtCost->bind_param('i', $project_id);
        $stmtCost->execute();
        $costStats = $stmtCost->get_result()->fetch_assoc();
        $stmtCost->close();

        $deliveryCount = intval($costStats['delivery_count'] ?? 0);
        $totalMiles = floatval($costStats['total_miles'] ?? 0);
        $fuelConsumption = $totalMiles * 0.1667;
        $emissions = $fuelConsumption * 10.21;

        // Get pallet count
        $stmtPallets = $conn->prepare('SELECT COUNT(DISTINCT ip.id) as pallet_count
            FROM inventory_pallets ip
            JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            JOIN modules m ON umi.unassigned_module_id = m.id
            WHERE m.project_id = ?');
        $stmtPallets->bind_param('i', $project_id);
        $stmtPallets->execute();
        $palletStats = $stmtPallets->get_result()->fetch_assoc();
        $stmtPallets->close();
        $palletCount = intval($palletStats['pallet_count'] ?? 0);

        // Get warehouse count
        $stmtWh = $conn->prepare('SELECT COUNT(DISTINCT warehouse_id) as wh_count FROM deliveries WHERE project_id = ? AND warehouse_id IS NOT NULL');
        $stmtWh->bind_param('i', $project_id);
        $stmtWh->execute();
        $whStats = $stmtWh->get_result()->fetch_assoc();
        $stmtWh->close();
        $warehouseCount = intval($whStats['wh_count'] ?? 0);

        // Get exception/warranty count
        $stmtExc = $conn->prepare('SELECT COUNT(*) as exc_count FROM warranty_claims wc
            LEFT JOIN site_scheduling ss ON wc.scheduling_id = ss.id
            WHERE ss.project_id = ?');
        $stmtExc->bind_param('i', $project_id);
        $stmtExc->execute();
        $excStats = $stmtExc->get_result()->fetch_assoc();
        $stmtExc->close();
        $exceptionCount = intval($excStats['exc_count'] ?? 0);

        // Get wattage breakdown
        $stmtWattage = $conn->prepare('SELECT ip.wattage, SUM(ip.quantity) as qty
            FROM inventory_pallets ip
            JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            JOIN modules m ON umi.unassigned_module_id = m.id
            WHERE m.project_id = ? AND ip.status = "Delivered to Project"
            GROUP BY ip.wattage ORDER BY qty DESC LIMIT 1');
        $stmtWattage->bind_param('i', $project_id);
        $stmtWattage->execute();
        $wattageStats = $stmtWattage->get_result()->fetch_assoc();
        $stmtWattage->close();
        if ($wattageStats) {
            $wattageInfo = $wattageStats['wattage'] . ' W';
            $mwDelivered = ($wattageStats['wattage'] * $wattageStats['qty']) / 1000000;
        }
    }

    $lines = [];

    // Opening paragraph
    $lines[] = "Solterra Solutions has completed logistics support for the {$projectName} project (Project ID {$project_id}) for {$accountName}. ";
    if ($percent >= 100) {
        $lines[] = "All module deliveries associated with this project have been received, reconciled, and closed out. ";
    } else {
        $lines[] = "Module deliveries for this project are currently at {$percentDisplay}% completion. ";
    }

    if ($delivered > 0) {
        $lines[] = "A total of " . number_format($delivered) . " modules";
        if ($wattageInfo) {
            $lines[] = " ({$wattageInfo} each, ~" . number_format($mwDelivered, 2) . " MWdc)";
        }
        $lines[] = " were delivered to the project site at {$address}, achieving {$percentDisplay}% delivery against the ordered quantity.";
    }
    $lines[] = "\n\n";

    // Project logistics overview
    $lines[] = "Over the course of the project, Solterra coordinated inbound shipments from the manufacturer";
    if ($warehouseCount > 0) {
        $lines[] = ", managed intermediate warehousing at {$warehouseCount} " . ($warehouseCount == 1 ? 'facility' : 'facilities');
    }
    $lines[] = ", and scheduled final deliveries into the site. ";

    $lines[] = "The final logistics profile for {$projectName} includes {$deliveryCount} " . ($deliveryCount == 1 ? 'delivery' : 'deliveries');
    if ($palletCount > 0) {
        $lines[] = " and " . number_format($palletCount) . " pallets delivered to the project";
    }
    $lines[] = ". All deliveries have been matched to bills of lading and proof-of-delivery documents";
    if ($percent >= 100) {
        $lines[] = ", with no outstanding shortages at the time of closeout";
    }
    $lines[] = ".\n\n";

    // Exception paragraph
    if ($exceptionCount > 0) {
        $lines[] = number_format($exceptionCount) . " damage/exception " . ($exceptionCount == 1 ? 'event was' : 'events were') . " recorded during the project. ";
        $lines[] = ($exceptionCount == 1 ? 'This issue was' : 'These issues were') . " handled through our warranty/exception process, with the manufacturer providing replacement material and supporting documentation. ";
        $lines[] = "Details are included in the warranty and exception log.\n\n";
    } else {
        $lines[] = "No damage or exception events were recorded during this project.\n\n";
    }

    // Package contents
    $lines[] = "As part of this data export package, we are providing:\n\n";
    $lines[] = "Delivery & Scheduling Records - Final delivery schedule, appointment log, and BOL/POD documentation.\n\n";
    $lines[] = "Inventory & Warehousing Records - Pallet and module inventory history, including movements through any staging warehouses.\n\n";
    $lines[] = "Financial Summary - Logistics cost summary and pallet-level cost allocation for your internal reconciliation.\n\n";
    $lines[] = "Sustainability Summary - An estimate of truckloads, miles driven (" . number_format($totalMiles, 0) . " mi), fuel usage (" . number_format($fuelConsumption, 0) . " gal), and associated CO2 emissions (" . number_format($emissions, 0) . " kg) for module transport supporting this project.\n\n";
    $lines[] = "Warranty & Exception Log - Details of any recorded damage claims, including photos and proof of completion.\n\n";

    // Closing paragraph
    $lines[] = "With this package, {$projectName} data export is complete in our system. Please retain these files for your construction, finance, and asset-management records. If you have any questions about the data provided or need additional documentation, reach out to your Solterra Solutions account team and we'll be happy to help.";

    return implode('', $lines);
}

function buildSummaryHtml($project_row, $summary_text, $user_row, $totals) {
    [$total_order, $delivered, $percent] = $totals;
    $percentDisplay = number_format($percent, 2);
    $deliveredDisplay = number_format($delivered);
    $totalDisplay = number_format($total_order);
    $summarySafe = nl2br(htmlspecialchars($summary_text));
    $addressParts = array_filter([
        $project_row['street_address'] ?? $project_row['project_address'] ?? '',
        trim(($project_row['city'] ?? '') . ' ' . ($project_row['state'] ?? '') . ' ' . ($project_row['zip_code'] ?? ''))
    ]);
    $address = implode('<br>', array_map('htmlspecialchars', $addressParts));
    $logoPath = __DIR__ . '/pictures/header_logo.png';
    $logoBase64 = '';
    if (is_file($logoPath)) {
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    }

    $projectInfo = [
        'Project' => htmlspecialchars($project_row['project_name'] ?? 'N/A'),
        'Project ID' => htmlspecialchars($project_row['id'] ?? $project_row['project_id'] ?? 'N/A'),
        'Account' => htmlspecialchars($project_row['account_name'] ?? 'N/A'),
        'Address' => $address ?: 'N/A',
        'Phone' => htmlspecialchars(trim(($project_row['phone1'] ?? '') . ' ' . ($project_row['phone2'] ?? '')) ?: 'N/A'),
        'Timezone' => htmlspecialchars($project_row['timezone'] ?? 'N/A'),
        'Reference Numbers' => htmlspecialchars($project_row['reference_numbers'] ?? 'N/A'),
        'Instructions' => nl2br(htmlspecialchars($project_row['instructions'] ?? 'N/A')),
    ];

    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 0.75in; }
            body { font-family: Arial, sans-serif; color: #1f2a30; }
            .header { margin-bottom:14px; display:flex; flex-direction:column; align-items:flex-start; gap:10px; }
            .logo { height:75px; margin-bottom:35px; }
            .brand { font-size:22px; font-weight:700; color:#1f3b4d; }
            .meta { font-size:12px; color:#4a5b6a; }
            .card { border:1px solid #d9e2ec; border-radius:10px; padding:14px 16px; margin-bottom:12px; }
            .card h3 { margin:0 0 8px 0; font-size:16px; color:#1f3b4d; }
            .info-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:8px 14px; font-size:12px; }
            .info-item { padding:6px 8px; background:#f7fafc; border-radius:8px; border:1px solid #e7eef4; }
            .info-item strong { display:block; color:#2d4857; margin-bottom:4px; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; }
            .summary-text { font-size:13px; line-height:1.5; color:#1f2a30; white-space:normal; }
            .badge { display:inline-block; padding:6px 10px; border-radius:999px; background:#e8f4f7; color:#2c6070; font-weight:600; font-size:11px; margin-right:6px; }
            .metrics { display:grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap:10px; }
            .metric { background:#f9fbfd; border:1px solid #e7eef4; border-radius:10px; padding:10px 12px; }
            .metric .label { font-size:11px; color:#5f6f7d; text-transform:uppercase; letter-spacing:0.6px; margin-bottom:4px; }
            .metric .value { font-size:18px; font-weight:700; color:#1f3b4d; }
        </style>
    </head>
    <body>
        <div class="header">';
    if ($logoBase64) {
        $html .= '<img class="logo" src="' . $logoBase64 . '" alt="Logo">';
    }
    $html .= '<div>
                <div class="brand">Project Summary</div>
                <div class="meta">Generated by ' . htmlspecialchars($user_row['email'] ?? ($user_row['username'] ?? '')) . ' on ' . date('M j, Y g:i A') . '</div>
            </div>
        </div>
        <div class="card">
            <h3>Project Info</h3>
            <div class="info-grid">';
    foreach ($projectInfo as $label => $val) {
        $html .= '<div class="info-item"><strong>' . htmlspecialchars($label) . '</strong><div>' . $val . '</div></div>';
    }
    $html .= '</div>
        </div>
        <div class="card">
            <h3>Status</h3>
            <div class="metrics">
                <div class="metric"><div class="label">Delivery Progress</div><div class="value">' . $percentDisplay . '%</div></div>
                <div class="metric"><div class="label">Delivered Modules</div><div class="value">' . $deliveredDisplay . '</div></div>
                <div class="metric"><div class="label">Total Ordered</div><div class="value">' . $totalDisplay . '</div></div>
            </div>
        </div>
        <div class="card">
            <h3>Project Summary Notes</h3>
            <div class="summary-text">' . $summarySafe . '</div>
        </div>
    </body>
    </html>';
    return $html;
}

function generateSummaryPdf($project_id, $project_row, $summary_text, $user_row, $totals) {
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options);
    $html = buildSummaryHtml($project_row, $summary_text, $user_row, $totals);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();
    $output = $dompdf->output();

    $dir = summaryStorageDir($project_id);
    ensureDir($dir);
    $pdfPath = $dir . '/project_summary.pdf';
    file_put_contents($pdfPath, $output);
    saveStoredSummaryText($project_id, $summary_text);

    return [$pdfPath, $output];
}

// Borrowed logic from project_cost_details: computes warehousing cost per pallet across warehouse stays
function calculatePalletWarehousingCostFallback($pallet_id, $conn) {
    if (!$conn || !$pallet_id) {
        return 0.0;
    }

    $sql = "SELECT DISTINCT
                w.id AS warehouse_id,
                (SELECT MIN(d_arr.warehouse_arrival_date)
                 FROM deliveries d_arr
                 JOIN delivery_pallets dp_arr ON d_arr.id = dp_arr.delivery_id
                 WHERE dp_arr.inventory_pallet_id = ?
                   AND d_arr.warehouse_id = w.id
                   AND d_arr.warehouse_arrival_date IS NOT NULL) AS arrival_date,
                (SELECT MIN(d_dep.left_warehouse_date)
                 FROM deliveries d_dep
                 JOIN delivery_pallets dp_dep ON d_dep.id = dp_dep.delivery_id
                 WHERE dp_dep.inventory_pallet_id = ?
                   AND d_dep.origin_type = 'warehouse'
                   AND d_dep.origin_id = w.id
                   AND d_dep.left_warehouse_date IS NOT NULL) AS departure_date,
                (SELECT COUNT(DISTINCT d_in.id)
                 FROM deliveries d_in
                 JOIN delivery_pallets dp_in ON d_in.id = dp_in.delivery_id
                 WHERE dp_in.inventory_pallet_id = ?
                   AND d_in.warehouse_id = w.id
                   AND d_in.warehouse_arrival_date IS NOT NULL) AS inbound_deliveries,
                (SELECT COUNT(DISTINCT d_out.id)
                 FROM deliveries d_out
                 JOIN delivery_pallets dp_out ON d_out.id = dp_out.delivery_id
                 WHERE dp_out.inventory_pallet_id = ?
                   AND d_out.origin_type = 'warehouse'
                   AND d_out.origin_id = w.id
                   AND d_out.left_warehouse_date IS NOT NULL) AS outbound_deliveries
            FROM warehouses w
            WHERE w.id IN (
                SELECT DISTINCT COALESCE(d.warehouse_id, d.origin_id)
                FROM deliveries d
                JOIN delivery_pallets dp ON d.id = dp.delivery_id
                WHERE dp.inventory_pallet_id = ?
                  AND (d.warehouse_id IS NOT NULL OR (d.origin_type = 'warehouse' AND d.origin_id IS NOT NULL))
            )
            ORDER BY arrival_date ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0.0;
    }

    $stmt->bind_param("iiiii", $pallet_id, $pallet_id, $pallet_id, $pallet_id, $pallet_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        return 0.0;
    }

    $warehouse_rows = [];
    $warehouse_ids  = [];
    while ($row = $result->fetch_assoc()) {
        $warehouse_rows[] = $row;
        $warehouse_ids[]  = (int)$row['warehouse_id'];
    }
    $stmt->close();

    $warehouse_costs = [];
    if (!empty($warehouse_ids)) {
        $warehouse_ids_str = implode(',', array_map('intval', $warehouse_ids));
        $cost_sql = "SELECT warehouse_id, trigger_event, amount
                     FROM warehouse_cost_items
                     WHERE warehouse_id IN ({$warehouse_ids_str}) AND is_active = 1";
        $cost_result = $conn->query($cost_sql);
        if ($cost_result) {
            while ($cost = $cost_result->fetch_assoc()) {
                $wid = (int)$cost['warehouse_id'];
                if (!isset($warehouse_costs[$wid])) {
                    $warehouse_costs[$wid] = ['in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];
                }
                switch ($cost['trigger_event']) {
                    case 'entry':
                        $warehouse_costs[$wid]['in_fee'] = (float)$cost['amount'];
                        break;
                    case 'exit':
                        $warehouse_costs[$wid]['out_fee'] = (float)$cost['amount'];
                        break;
                    case 'monthly':
                        $warehouse_costs[$wid]['monthly_storage_fee'] = (float)$cost['amount'];
                        break;
                }
            }
        }
    }

    $total_warehouse_cost = 0.0;
    foreach ($warehouse_rows as $row) {
        $wid   = (int)$row['warehouse_id'];
        $costs = $warehouse_costs[$wid] ?? ['in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];

        $in_fee_cost  = $costs['in_fee'] * (int)($row['inbound_deliveries'] ?? 0);
        $out_fee_cost = $costs['out_fee'] * (int)($row['outbound_deliveries'] ?? 0);

        $storage_cost = 0.0;
        if (!empty($row['arrival_date'])) {
            $arrival   = new DateTime($row['arrival_date']);
            $departure = !empty($row['departure_date']) ? new DateTime($row['departure_date']) : new DateTime();
            $days      = max(0, $arrival->diff($departure)->days);
            $daily_fee = ($costs['monthly_storage_fee'] ?? 0) / 30;
            $storage_cost = $days * $daily_fee;
        }

        $total_warehouse_cost += $in_fee_cost + $out_fee_cost + $storage_cost;
    }

    return $total_warehouse_cost;
}

function fetchPalletCosts($conn, $project_id) {
    // Pre-fetch project solterra fee
    $solterra_fee = 0.0;
    $stmtFee = $conn->prepare('SELECT solterra_fee FROM projects WHERE id = ?');
    if ($stmtFee) {
        $stmtFee->bind_param('i', $project_id);
        $stmtFee->execute();
        $stmtFee->bind_result($sf);
        if ($stmtFee->fetch()) { $solterra_fee = floatval($sf); }
        $stmtFee->close();
    }

    // Build BOL-level costs and pallet counts
    $bolCost = [];
    $stmtCost = $conn->prepare('SELECT bol_number, COALESCE(freight_cost,0) AS freight_cost, COALESCE(accessorial_costs,0) AS accessorial_costs FROM deliveries WHERE project_id = ?');
    $stmtCost->bind_param('i', $project_id);
    $stmtCost->execute();
    $resCost = $stmtCost->get_result();
    while ($row = $resCost->fetch_assoc()) {
        $bol = $row['bol_number'] ?? '';
        if (!isset($bolCost[$bol])) { $bolCost[$bol] = 0; }
        $bolCost[$bol] += floatval($row['freight_cost']) + floatval($row['accessorial_costs']);
    }
    $stmtCost->close();

    $bolCounts = [];
    $stmtCnt = $conn->prepare('SELECT d.bol_number, COUNT(DISTINCT dp.inventory_pallet_id) AS pallets FROM deliveries d JOIN delivery_pallets dp ON dp.delivery_id = d.id WHERE d.project_id = ? GROUP BY d.bol_number');
    $stmtCnt->bind_param('i', $project_id);
    $stmtCnt->execute();
    $resCnt = $stmtCnt->get_result();
    while ($row = $resCnt->fetch_assoc()) {
        $bol = $row['bol_number'] ?? '';
        $bolCounts[$bol] = intval($row['pallets']);
    }
    $stmtCnt->close();

    $sql = "
        SELECT d.id AS delivery_id, d.bol_number, d.supplier, d.status_of_delivery, d.freight_cost, d.accessorial_costs,
               d.warehouse_arrival_date, d.left_warehouse_date, d.actual_delivery_date, d.anticipated_delivery_date,
               d.warehouse_id, d.origin_type, d.origin_id,
               dp.inventory_pallet_id, ip.pallet_identifier, ip.wattage, ip.quantity,
               ip.current_warehouse_id, ip.arrival_date, COALESCE(ip.customs_hold_cost, 0) AS customs_hold_cost,
               w.name AS warehouse_name, p.project_name
        FROM deliveries d
        JOIN delivery_pallets dp ON dp.delivery_id = d.id
        LEFT JOIN inventory_pallets ip ON ip.id = dp.inventory_pallet_id
        LEFT JOIN warehouses w ON d.warehouse_id = w.id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE d.project_id = ?
        ORDER BY COALESCE(d.actual_delivery_date, d.anticipated_delivery_date, d.created_at)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    $summary = [
        'pallet_count' => 0,
        'total_load_cost' => 0,
        'allocated_pallet_cost' => 0,
        'freight_total' => 0,
        'accessorial_total' => 0,
        'customs_hold_total' => 0,
        'warehousing_total' => 0,
        'solterra_total' => 0,
    ];
    $seenBol = [];
    $warehouseCostCache = [];
    $palletMap = [];

    while ($r = $res->fetch_assoc()) {
        $bol = $r['bol_number'] ?? '';
        $count = max(1, $bolCounts[$bol] ?? 1);
        $loadCost = $bolCost[$bol] ?? (floatval($r['freight_cost'] ?? 0) + floatval($r['accessorial_costs'] ?? 0));
        $allocated = $loadCost / $count;

        $pallet_id = $r['inventory_pallet_id'];
        if (!isset($warehouseCostCache[$pallet_id])) {
            $warehouseCostCache[$pallet_id] = calculatePalletWarehousingCostFallback($pallet_id, $conn);
        }
        $warehouse_cost = $warehouseCostCache[$pallet_id];

        $destination = $r['project_name'] ? ('Project: ' . $r['project_name']) : '';
        if (!empty($r['warehouse_name'])) { $destination = 'Warehouse: ' . $r['warehouse_name']; }

        // Aggregate per pallet
        if (!isset($palletMap[$pallet_id])) {
            $palletMap[$pallet_id] = [
                'pallet_id' => $pallet_id,
                'pallet_identifier' => $r['pallet_identifier'],
                'delivery_id' => $r['delivery_id'],
                'bol_number' => $bol,
                'supplier' => $r['supplier'] ?? '',
                'destination' => $destination,
                'status' => $r['status_of_delivery'] ?? '',
                'wattage' => $r['wattage'] ?? '',
                'quantity' => $r['quantity'] ?? '',
                'truckload_cost' => 0.0,
                'accessorial_costs' => 0.0,
                'customs_hold_cost' => floatval($r['customs_hold_cost'] ?? 0),
                'warehouse_cost' => $warehouse_cost,
                'total_load_cost' => 0.0,
                'allocated_pallet_cost' => 0.0,
                'warehouse_arrival_date' => $r['warehouse_arrival_date'] ?? '',
                'left_warehouse_date' => $r['left_warehouse_date'] ?? '',
            ];
        }

        $palletMap[$pallet_id]['truckload_cost'] += floatval($r['freight_cost'] ?? 0);
        $palletMap[$pallet_id]['accessorial_costs'] += floatval($r['accessorial_costs'] ?? 0);
        $palletMap[$pallet_id]['total_load_cost'] += $loadCost;
        $palletMap[$pallet_id]['allocated_pallet_cost'] += $allocated;
        // Update dates/status to the latest available
        if (!empty($r['warehouse_arrival_date'])) {
            $palletMap[$pallet_id]['warehouse_arrival_date'] = $r['warehouse_arrival_date'];
        }
        if (!empty($r['left_warehouse_date'])) {
            $palletMap[$pallet_id]['left_warehouse_date'] = $r['left_warehouse_date'];
        }

        // Delivery-level totals for freight/accessorial/solterra
        $summary['freight_total'] += floatval($r['freight_cost'] ?? 0);
        $summary['accessorial_total'] += floatval($r['accessorial_costs'] ?? 0);
        if (!empty($r['actual_delivery_date']) && $solterra_fee > 0 && !empty($r['wattage']) && !empty($r['quantity'])) {
            $summary['solterra_total'] += $solterra_fee * floatval($r['wattage']) * floatval($r['quantity']);
        }
        if (!in_array($bol, $seenBol, true)) {
            $summary['total_load_cost'] += $loadCost;
            $seenBol[] = $bol;
        }
    }
    $stmt->close();

    // Build final rows and pallet-level summaries without duplicates
    $rows = [];
    foreach ($palletMap as $p) {
        $rows[] = $p;
        $summary['pallet_count'] += 1;
        $summary['allocated_pallet_cost'] += $p['allocated_pallet_cost'];
        $summary['customs_hold_total'] += floatval($p['customs_hold_cost'] ?? 0);
        $summary['warehousing_total'] += $p['warehouse_cost'];
    }

    $summary['accessorial_total'] += $summary['customs_hold_total'];
    $summary['total_cost'] = $summary['freight_total'] + $summary['accessorial_total'] + $summary['warehousing_total'] + $summary['solterra_total'];
    return ['rows' => array_values($rows), 'summary' => $summary];
}

function fetchPalletJourney($conn, $project_id) {
    $sql = "
        SELECT d.id AS delivery_id, d.bol_number, d.origin_type, d.origin_id, d.supplier, d.status_of_delivery,
               d.project_id, d.warehouse_id, d.warehouse_arrival_date, d.left_warehouse_date, d.actual_delivery_date, d.anticipated_delivery_date,
               dp.inventory_pallet_id, ip.pallet_identifier, ip.wattage, ip.quantity,
               w_dest.name AS dest_warehouse_name, p_dest.project_name AS dest_project_name,
               w_origin.name AS origin_warehouse_name, p_origin.project_name AS origin_project_name
        FROM deliveries d
        JOIN delivery_pallets dp ON dp.delivery_id = d.id
        LEFT JOIN inventory_pallets ip ON ip.id = dp.inventory_pallet_id
        LEFT JOIN warehouses w_dest ON d.warehouse_id = w_dest.id
        LEFT JOIN projects p_dest ON d.project_id = p_dest.id
        LEFT JOIN warehouses w_origin ON d.origin_type = 'warehouse' AND d.origin_id = w_origin.id
        LEFT JOIN projects p_origin ON d.origin_type = 'project' AND d.origin_id = p_origin.id
        WHERE d.project_id = ?
        ORDER BY dp.inventory_pallet_id, COALESCE(d.actual_delivery_date, d.anticipated_delivery_date, d.created_at)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $origin = $r['supplier'] ?? '';
        if ($r['origin_type'] === 'warehouse' && !empty($r['origin_warehouse_name'])) { $origin = 'Warehouse: ' . $r['origin_warehouse_name']; }
        elseif ($r['origin_type'] === 'project' && !empty($r['origin_project_name'])) { $origin = 'Project: ' . $r['origin_project_name']; }

        $destination = '';
        if (!empty($r['dest_warehouse_name'])) { $destination = 'Warehouse: ' . $r['dest_warehouse_name']; }
        elseif (!empty($r['dest_project_name'])) { $destination = 'Project: ' . $r['dest_project_name']; }
        elseif (!empty($r['supplier'])) { $destination = $r['supplier']; }

        $rows[] = [
            'pallet_id' => $r['inventory_pallet_id'],
            'pallet_identifier' => $r['pallet_identifier'],
            'delivery_id' => $r['delivery_id'],
            'bol_number' => $r['bol_number'],
            'origin' => $origin,
            'destination' => $destination,
            'status' => $r['status_of_delivery'] ?? '',
            'warehouse_arrival_date' => $r['warehouse_arrival_date'] ?? '',
            'delivered_date' => (!empty($r['dest_project_name'])) ? ($r['actual_delivery_date'] ?? $r['anticipated_delivery_date'] ?? '') : '',
            'left_warehouse_date' => $r['left_warehouse_date'] ?? '',
            'wattage' => $r['wattage'] ?? '',
            'quantity' => $r['quantity'] ?? '',
        ];
    }
    $stmt->close();
    return $rows;
}

function fetchSustainabilityReport($conn, $project_id) {
    $sql = 'SELECT supplier, wattage, quantity, bol_number, status_of_delivery, miles FROM deliveries WHERE project_id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $deliveries = [];
    $totals = [
        'total_emissions' => 0.0,
        'total_truckloads' => 0,
        'total_miles_driven' => 0.0,
        'total_fuel_consumption' => 0.0,
        'total_mws_delivered' => 0.0,
    ];

    while ($row = $res->fetch_assoc()) {
        $quantity = (int)($row['quantity'] ?? 0);
        $wattage = (float)($row['wattage'] ?? 0);
        $miles = (float)($row['miles'] ?? 0);

        if (in_array($row['status_of_delivery'] ?? '', ['Delivered to Project', 'Delivered to Warehouse']) && $miles > 0) {
            $totals['total_truckloads'] += 1;
        }

        $fuel = $miles * 0.1667;
        $emissions = $fuel * 10.21;
        $mws = ($quantity * $wattage) / 1000000;

        $totals['total_miles_driven'] += $miles;
        $totals['total_fuel_consumption'] += $fuel;
        $totals['total_emissions'] += $emissions;
        $totals['total_mws_delivered'] += $mws;

        $row['miles_driven'] = $miles;
        $row['fuel_consumption'] = $fuel;
        $row['emissions'] = $emissions;
        $row['mw_delivered'] = $mws;
        $deliveries[] = $row;
    }
    $stmt->close();

    return ['deliveries' => $deliveries, 'totals' => $totals];
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
    // For admin and customer_admin, show projects where they have admin access
    if (in_array($role, ['admin', 'customer_admin'])) {
        $sql = "
            SELECT p.id, p.project_name
            FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE cau.user_id = ? AND cau.role IN ('admin', 'customer_admin')
            ORDER BY p.project_name ASC
        ";
    } else {
        // For regular users, show projects they have access to (any role in customer_account_users)
        $sql = "
            SELECT DISTINCT p.id, p.project_name
            FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE cau.user_id = ?
            ORDER BY p.project_name ASC
        ";
    }
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

function collectDataAndBuildArchive($conn, $project_id, $user_row, $project_row, $totals, $summaryPdfPath, $sustainabilityReport, callable $progressCallback = null) {
    $notify = static function ($progress, $message) use ($progressCallback) {
        if ($progressCallback) {
            $progressCallback((int)$progress, (string)$message);
        }
    };

    $notify(2, 'Initializing archive workspace');
    [$total_order, $delivered, $percent] = $totals;
    $projectLabel = sanitizeFileName($project_row['project_name'] ?? 'project');
    $rootName = 'Project_' . ($projectLabel !== '' ? $projectLabel : $project_id);
    $tempBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'project_close_' . uniqid();
    $rootPath = $tempBase . DIRECTORY_SEPARATOR . $rootName;
    ensureDir($rootPath);

    // 00 Project Summary (PDF + metadata) - only if summary PDF was generated
    if ($summaryPdfPath && is_file($summaryPdfPath)) {
        $notify(6, 'Adding summary files');
        $summaryDir = $rootPath . '/00_Project_Summary';
        ensureDir($summaryDir);
        @copy($summaryPdfPath, $summaryDir . '/project_summary.pdf');
        $summaryMeta = [
            'project_id' => $project_id,
            'project_name' => $project_row['project_name'] ?? '',
            'account_name' => $project_row['account_name'] ?? '',
            'generated_at' => date('c'),
            'generated_by' => $user_row['email'] ?? ($user_row['username'] ?? ''),
            'delivered_modules' => $delivered,
            'total_ordered_modules' => $total_order,
            'delivered_percent' => $percent,
        ];
        file_put_contents($summaryDir . '/summary_meta.json', json_encode($summaryMeta, JSON_PRETTY_PRINT));
    }

    // 02 Documents
    $notify(12, 'Exporting project documents');
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

    $notify(28, 'Exporting module, pallet, and movement data');
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

    // Movements (journey per pallet derived from deliveries)
    $journeyRows = fetchPalletJourney($conn, $project_id);
    if (!empty($journeyRows)) {
        $moveHeaders = ['Pallet ID','Pallet Identifier','Delivery ID','BOL Number','Origin','Destination','Status','Warehouse Arrival Date','Delivered to Project Date','Left Warehouse Date','Wattage','Quantity'];
        $out = [];
        foreach ($journeyRows as $jr) {
            $out[] = [
                $jr['pallet_id'], $jr['pallet_identifier'], $jr['delivery_id'], $jr['bol_number'],
                $jr['origin'], $jr['destination'], $jr['status'],
                $jr['warehouse_arrival_date'], $jr['delivered_date'], $jr['left_warehouse_date'], $jr['wattage'], $jr['quantity']
            ];
        }
        writeCsv($modulesDir . '/pallet_movements.csv', $moveHeaders, $out);
    } else {
        writeCsv($modulesDir . '/pallet_movements.csv', ['message'], [['No pallet journey records found']]);
    }

    $notify(42, 'Exporting deliveries and scheduling data');
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
        $omitCols = ['invoice_number','invoice_id','accessorial_costs_paid','pay_status','pay_date','accounts_payable_id'];
        $allHeaders = array_keys($delRows[0]);
        $headers = [];
        foreach ($allHeaders as $h) {
            if (!in_array($h, $omitCols, true)) { $headers[] = $h; }
        }
        $rows = [];
        foreach ($delRows as $row) {
            $filtered = [];
            foreach ($headers as $h) { $filtered[] = $row[$h] ?? null; }
            $rows[] = $filtered;
        }
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

    $notify(58, 'Exporting warehousing records');
    // 06 Warehousing
    $whDir = $rootPath . '/06_Warehousing';
    ensureDir($whDir);
    $sqlWhPallets = "
        SELECT d.id AS delivery_id, d.warehouse_id, w.name AS warehouse_name, d.warehouse_arrival_date, d.left_warehouse_date,
               d.bol_number, d.status_of_delivery, dp.inventory_pallet_id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status AS pallet_status
        FROM deliveries d
        LEFT JOIN warehouses w ON d.warehouse_id = w.id
        LEFT JOIN delivery_pallets dp ON dp.delivery_id = d.id
        LEFT JOIN inventory_pallets ip ON ip.id = dp.inventory_pallet_id
        WHERE d.project_id = ? AND d.warehouse_id IS NOT NULL
        ORDER BY w.name, d.warehouse_arrival_date, d.id
    ";
    $stmtWh = $conn->prepare($sqlWhPallets);
    $stmtWh->bind_param('i', $project_id);
    $stmtWh->execute();
    $resWh = $stmtWh->get_result();
    $byWarehouse = [];
    while ($row = $resWh->fetch_assoc()) {
        $wid = $row['warehouse_id'] ?? 0;
        if (!isset($byWarehouse[$wid])) { $byWarehouse[$wid] = ['name' => $row['warehouse_name'] ?? 'Warehouse ' . $wid, 'pallets' => []]; }
        $pid = $row['inventory_pallet_id'] ?? 0;
        if (!isset($byWarehouse[$wid]['pallets'][$pid])) {
            $byWarehouse[$wid]['pallets'][$pid] = [
                'delivery_id' => $row['delivery_id'] ?? '',
                'bol_number' => $row['bol_number'] ?? '',
                'pallet_id' => $pid,
                'pallet_identifier' => $row['pallet_identifier'] ?? '',
                'wattage' => $row['wattage'] ?? '',
                'quantity' => $row['quantity'] ?? '',
                'pallet_status' => $row['pallet_status'] ?? '',
                'arrival' => '',
                'left' => ''
            ];
        }
        if (empty($byWarehouse[$wid]['pallets'][$pid]['arrival']) && !empty($row['warehouse_arrival_date'])) {
            $byWarehouse[$wid]['pallets'][$pid]['arrival'] = $row['warehouse_arrival_date'];
        }
        if (!empty($row['left_warehouse_date'])) {
            $byWarehouse[$wid]['pallets'][$pid]['left'] = $row['left_warehouse_date'];
        }
        if (!empty($row['bol_number'])) { $byWarehouse[$wid]['pallets'][$pid]['bol_number'] = $row['bol_number']; }
    }
    $stmtWh->close();

    if (!empty($byWarehouse)) {
        foreach ($byWarehouse as $wid => $bundle) {
            $fileName = 'warehouse_' . sanitizeFileName($bundle['name'] ?? ('warehouse_' . $wid)) . '.csv';
            $outRows = [];
            $headers = ['Warehouse','Delivery ID','BOL Number','Pallet ID','Pallet Identifier','Wattage','Quantity','Pallet Status','Arrival Date','Left Warehouse Date','Days In Warehouse'];
            foreach ($bundle['pallets'] as $p) {
                $arrive = $p['arrival'];
                $left = $p['left'];
                $days = '';
                if ($arrive && $left) { $days = (int)floor((strtotime($left) - strtotime($arrive)) / 86400); }
                $outRows[] = [
                    $bundle['name'] ?? '',
                    $p['delivery_id'],
                    $p['bol_number'],
                    $p['pallet_id'],
                    $p['pallet_identifier'],
                    $p['wattage'],
                    $p['quantity'],
                    $p['pallet_status'],
                    $arrive,
                    $left,
                    $days,
                ];
            }
            if (empty($outRows)) {
                $outRows[] = array_merge([$bundle['name'] ?? 'No warehouse pallet records found'], array_fill(0, count($headers) - 1, ''));
            }
            writeCsv($whDir . '/' . $fileName, $headers, $outRows);
        }
        $indexHeaders = ['Warehouse','File'];
        $indexRows = [];
        foreach ($byWarehouse as $wid => $bundle) {
            $fileName = 'warehouse_' . sanitizeFileName($bundle['name'] ?? ('warehouse_' . $wid)) . '.csv';
            $indexRows[] = [$bundle['name'] ?? '', $fileName];
        }
        writeCsv($whDir . '/inventory_by_warehouse_index.csv', $indexHeaders, $indexRows);
    } else {
        writeCsv($whDir . '/inventory_by_warehouse_index.csv', ['message'], [['No warehouse pallet records found for this project']]);
    }

    $notify(70, 'Exporting financial summaries');
    // 07 Financials (pallet-level costs)
    $finDir = $rootPath . '/07_Financials';
    ensureDir($finDir);
    $costData = fetchPalletCosts($conn, $project_id);
    $costRows = $costData['rows'];
    if (!empty($costRows)) {
        $headers = ['Pallet ID','Pallet Identifier','Delivery ID','BOL Number','Supplier','Destination','Status','Wattage','Quantity','Warehousing Cost','Truckload Freight','Accessorial Costs','Customs Hold Cost','Total Load Cost','Total Freight Cost (pallet)','Total Logistics Cost (pallet)','Warehouse Arrival','Left Warehouse'];
        $rows = [];
        foreach ($costRows as $r) {
            $pallet_freight_total = $r['allocated_pallet_cost'];
            $customs_hold_cost = floatval($r['customs_hold_cost'] ?? 0);
            $pallet_logistics_total = $pallet_freight_total + ($r['warehouse_cost'] ?? 0) + $customs_hold_cost;
            $rows[] = [
                $r['pallet_id'], $r['pallet_identifier'], $r['delivery_id'], $r['bol_number'],
                $r['supplier'], $r['destination'], $r['status'], $r['wattage'], $r['quantity'],
                $r['warehouse_cost'], $r['truckload_cost'], $r['accessorial_costs'], $customs_hold_cost, $r['total_load_cost'],
                $pallet_freight_total, $pallet_logistics_total,
                $r['warehouse_arrival_date'], $r['left_warehouse_date']
            ];
        }
        writeCsv($finDir . '/cost_details.csv', $headers, $rows);
        $totals = $costData['summary'];
        $totals['average_cost_per_pallet'] = $totals['pallet_count'] > 0 ? $totals['total_cost'] / $totals['pallet_count'] : 0;
        file_put_contents($finDir . '/cost_summary.json', json_encode($totals, JSON_PRETTY_PRINT));
    } else {
        writeCsv($finDir . '/cost_details.csv', ['message'], [['No pallet costs found']]);
    }

    $notify(78, 'Exporting sustainability metrics');
    // 08 Sustainability (export real data)
    $susDir = $rootPath . '/08_Sustainability';
    ensureDir($susDir);
    $susRows = $sustainabilityReport['deliveries'] ?? [];
    if (!empty($susRows)) {
        $susHeaders = ['Supplier','Wattage','Quantity','BOL Number','Status','Miles','Fuel Consumption (gal)','Emissions (kg CO2)','MW Delivered'];
        $rows = [];
        foreach ($susRows as $sr) {
            $rows[] = [
                $sr['supplier'] ?? '',
                $sr['wattage'] ?? '',
                $sr['quantity'] ?? '',
                $sr['bol_number'] ?? '',
                $sr['status_of_delivery'] ?? '',
                round($sr['miles_driven'] ?? ($sr['miles'] ?? 0), 2),
                round($sr['fuel_consumption'] ?? 0, 2),
                round($sr['emissions'] ?? 0, 2),
                round($sr['mw_delivered'] ?? 0, 4)
            ];
        }
        writeCsv($susDir . '/sustainability_details.csv', $susHeaders, $rows);
    } else {
        writeCsv($susDir . '/sustainability_details.csv', ['message'], [['No sustainability records found for this project']]);
    }
    $susTotals = array_merge([
        'total_emissions' => 0,
        'total_truckloads' => 0,
        'total_miles_driven' => 0,
        'total_fuel_consumption' => 0,
        'total_mws_delivered' => 0,
    ], $sustainabilityReport['totals'] ?? []);
    $susTotals['generated_at'] = date('c');
    file_put_contents($susDir . '/sustainability_summary.json', json_encode($susTotals, JSON_PRETTY_PRINT));

    $notify(86, 'Exporting warranty and exception records');
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

    $notify(92, 'Exporting project photos and manifest');
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

    $notify(94, 'Writing archive manifest');
    // README
    $readme = "Project Data Export Package
" .
        "Project: " . ($project_row['project_name'] ?? '') . " (ID: {$project_id})
" .
        "Account: " . ($project_row['account_name'] ?? 'N/A') . "
" .
        "Generated: " . date('c') . "
" .
        "Generated by: " . ($user_row['email'] ?? ($user_row['username'] ?? '')) . "
" .
        "Delivered %: {$percent}%
" .
        "Structure:
";
    if ($summaryPdfPath && is_file($summaryPdfPath)) {
        $readme .= "00_Project_Summary – project_summary.pdf, summary_meta.json
";
    }
    $readme .= "02_Documents – all project documents grouped by type/sub-type
" .
        "03_Modules – batches.csv, pallets.csv, pallet_movements.csv
" .
        "05_Deliveries – deliveries.csv, appointments.csv, anticipated_schedule.csv
" .
        "06_Warehousing – per-warehouse CSVs (see inventory_by_warehouse_index.csv)
" .
        "07_Financials – cost_details.csv (pallet-level), cost_summary.json
" .
        "08_Sustainability – sustainability_details.csv, sustainability_summary.json
" .
        "09_Warranty_Exceptions – exceptions.csv
" .
        "10_Photos – pictures and manifest
";
    file_put_contents($rootPath . '/README.txt', $readme);

    $notify(96, 'Compressing archive ZIP');
    // Zip
    $zipPath = $tempBase . '/' . $rootName . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
        throw new Exception('Could not create zip');
    }
    addDirToZip($zip, $rootPath, strlen($rootPath));
    $zip->close();

    $notify(100, 'Archive ZIP ready');
    return [$tempBase, $zipPath];
}

function cleanupProjectCloseTempArtifacts($tmpDir, $zipPath) {
    if ($zipPath && is_file($zipPath)) {
        @unlink($zipPath);
    }
    if ($tmpDir && is_dir($tmpDir)) {
        $it = new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($tmpDir);
    }
}

function executeProjectCloseout($conn, $project_id, $user_id, $summary_text, $user_row, $project_row, $totals, callable $progressCallback = null) {
    $notify = static function ($progress, $message) use ($progressCallback) {
        if ($progressCallback) {
            $progressCallback((int)$progress, (string)$message);
        }
    };

    $tmpDir = null;
    $zipPath = null;

    try {
        $notify(5, 'Generating summary PDF');
        [$summaryPdfPath, ] = generateSummaryPdf($project_id, $project_row, $summary_text, $user_row, $totals);

        $notify(12, 'Preparing sustainability report');
        $sustainabilityReport = fetchSustainabilityReport($conn, $project_id);

        $notify(20, 'Building archive package');
        [$tmpDir, $zipPath] = collectDataAndBuildArchive(
            $conn,
            $project_id,
            $user_row,
            $project_row,
            $totals,
            $summaryPdfPath,
            $sustainabilityReport,
            static function ($archiveProgress, $archiveMessage) use ($notify) {
                $archiveProgress = max(0, min(100, (int)$archiveProgress));
                $mappedProgress = 20 + (int)round($archiveProgress * 0.35); // 20..55
                $notify($mappedProgress, $archiveMessage);
            }
        );

        // Create permanent archive directory
        $notify(56, 'Saving archive to permanent storage');
        $archiveDir = __DIR__ . '/uploads/archived_projects/' . $project_id;
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        // Move zip to permanent location
        $archiveFilename = 'Project_' . sanitizeFileName($project_row['project_name'] ?? 'project') . '_' . date('Y-m-d_His') . '.zip';
        $permanentPath = $archiveDir . '/' . $archiveFilename;
        if (!@copy($zipPath, $permanentPath)) {
            throw new Exception('Failed to save archive ZIP to permanent storage.');
        }
        $fileSize = @filesize($permanentPath);
        if ($fileSize === false) {
            $fileSize = 0;
        }

        // Calculate comprehensive metrics for archiving
        $notify(60, 'Calculating archive metrics');
        $archivePath = 'uploads/archived_projects/' . $project_id . '/' . $archiveFilename;
        $accountId = $project_row['account_id'] ?? null;
        $projectName = $project_row['project_name'] ?? '';
        [$totalModules, $deliveredModules, $deliveryPercent] = $totals;

        // Cost metrics
        $moduleCostData = calculate_project_module_cost($project_id, $conn);
        $notify(62, 'Calculating project cost totals');
        $totalModuleCost = $moduleCostData['total_cost'] ?? 0;
        $totalWattage = $moduleCostData['total_watts'] ?? 0;

        $stmtCosts = $conn->prepare('SELECT
            COALESCE(SUM(freight_cost), 0) as freight,
            COALESCE(SUM(accessorial_costs), 0) as accessorial
            FROM deliveries WHERE project_id = ?');
        $stmtCosts->bind_param('i', $project_id);
        $stmtCosts->execute();
        $costRow = $stmtCosts->get_result()->fetch_assoc();
        $stmtCosts->close();
        $totalFreightCost = (float)($costRow['freight'] ?? 0);
        $totalAccessorialCost = (float)($costRow['accessorial'] ?? 0);
        $totalCustomsHoldCost = calculate_project_customs_hold_cost($project_id, $conn);
        $totalAccessorialCost += $totalCustomsHoldCost;
        $totalWarehousingCost = calculate_project_warehousing_cost($project_id, $conn);

        $notify(64, 'Calculating sustainability totals');
        // Sustainability metrics (from sustainability report)
        $totalMiles = (float)($sustainabilityReport['total_miles_driven'] ?? 0);
        $totalFuelGallons = (float)($sustainabilityReport['total_fuel_consumption'] ?? ($totalMiles * 0.1667));
        $totalCo2Kg = (float)($sustainabilityReport['total_emissions'] ?? ($totalFuelGallons * 10.21));
        $totalTruckloads = (int)($sustainabilityReport['total_truckloads'] ?? 0);

        $notify(66, 'Calculating manufacturer metrics');
        // Manufacturer metrics
        $stmtMfr = $conn->prepare('SELECT m.vendor_name, COUNT(DISTINCT ip.id) as pallet_count
            FROM modules m
            JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
            JOIN inventory_pallets ip ON ip.unassigned_module_item_id = umi.id
            WHERE m.project_id = ?
            GROUP BY m.vendor_name
            ORDER BY pallet_count DESC');
        $stmtMfr->bind_param('i', $project_id);
        $stmtMfr->execute();
        $mfrResult = $stmtMfr->get_result();
        $primaryManufacturer = null;
        $manufacturerCount = 0;
        while ($mfrRow = $mfrResult->fetch_assoc()) {
            $manufacturerCount++;
            if ($primaryManufacturer === null) {
                $primaryManufacturer = $mfrRow['vendor_name'];
            }
        }
        $stmtMfr->close();

        // Pallet count
        $stmtPallets = $conn->prepare('SELECT COUNT(DISTINCT ip.id) as total_pallets
            FROM inventory_pallets ip
            JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            JOIN modules m ON umi.unassigned_module_id = m.id
            WHERE m.project_id = ?');
        $stmtPallets->bind_param('i', $project_id);
        $stmtPallets->execute();
        $palletRow = $stmtPallets->get_result()->fetch_assoc();
        $stmtPallets->close();
        $totalPallets = (int)($palletRow['total_pallets'] ?? 0);

        $notify(68, 'Calculating delivery performance');
        // Delivery performance metrics
        $stmtDelPerf = $conn->prepare('SELECT
            COUNT(DISTINCT id) as total_deliveries,
            COUNT(DISTINCT CASE WHEN actual_delivery_date IS NOT NULL AND anticipated_delivery_date IS NOT NULL
                AND actual_delivery_date <= anticipated_delivery_date THEN id END) as on_time,
            COUNT(DISTINCT CASE WHEN actual_delivery_date IS NOT NULL AND anticipated_delivery_date IS NOT NULL
                AND actual_delivery_date > anticipated_delivery_date THEN id END) as late,
            AVG(CASE WHEN actual_delivery_date IS NOT NULL AND anticipated_delivery_date IS NOT NULL
                AND actual_delivery_date > anticipated_delivery_date
                THEN DATEDIFF(actual_delivery_date, anticipated_delivery_date) END) as avg_days_late,
            MAX(actual_delivery_date) as last_delivery_date
            FROM deliveries WHERE project_id = ?');
        $stmtDelPerf->bind_param('i', $project_id);
        $stmtDelPerf->execute();
        $delPerfRow = $stmtDelPerf->get_result()->fetch_assoc();
        $stmtDelPerf->close();
        $totalDeliveries = (int)($delPerfRow['total_deliveries'] ?? 0);
        $onTimeDeliveries = (int)($delPerfRow['on_time'] ?? 0);
        $lateDeliveries = (int)($delPerfRow['late'] ?? 0);
        $avgDaysLate = $delPerfRow['avg_days_late'] !== null ? round((float)$delPerfRow['avg_days_late'], 1) : null;

        // Check if project completed on time (last delivery before estimated completion date)
        $projectCompletedOnTime = null;
        $projectEndDate = $project_row['estimated_completion_date'] ?? null;
        $lastDeliveryDate = $delPerfRow['last_delivery_date'] ?? null;
        if ($projectEndDate && $lastDeliveryDate) {
            $projectCompletedOnTime = strtotime($lastDeliveryDate) <= strtotime($projectEndDate) ? 1 : 0;
        }

        $notify(70, 'Calculating warranty and damage metrics');
        // Damage metrics
        $stmtDamage = $conn->prepare('SELECT
            COALESCE(SUM(ip.quantity), 0) as damaged_modules,
            COUNT(DISTINCT wc.id) as warranty_claims
            FROM warranty_claims wc
            JOIN site_scheduling ss ON wc.scheduling_id = ss.id
            LEFT JOIN warranty_claim_replacements wcr ON wcr.claim_id = wc.id
            LEFT JOIN inventory_pallets ip ON wcr.pallet_id = ip.id
            WHERE ss.project_id = ?');
        $stmtDamage->bind_param('i', $project_id);
        $stmtDamage->execute();
        $damageRow = $stmtDamage->get_result()->fetch_assoc();
        $stmtDamage->close();
        $damagedModulesCount = (int)($damageRow['damaged_modules'] ?? 0);
        $warrantyClaimsCount = (int)($damageRow['warranty_claims'] ?? 0);

        // Insert archive record with all metrics
        $notify(74, 'Writing archived project record');
        $stmtArchive = $conn->prepare('INSERT INTO archived_projects
            (project_id, account_id, project_name, archive_path, archive_filename, file_size_bytes,
             closed_by, summary_text, delivery_percent, total_modules, delivered_modules,
             total_module_cost, total_freight_cost, total_warehousing_cost, total_accessorial_cost,
             total_miles, total_fuel_gallons, total_co2_kg, total_truckloads,
             primary_manufacturer, manufacturer_count, total_wattage, total_pallets,
             total_deliveries, on_time_deliveries, late_deliveries, avg_days_late, project_completed_on_time,
             damaged_modules_count, warranty_claims_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmtArchive->bind_param('iisssiisdiidddddddisiiiiiidiii',
            $project_id, $accountId, $projectName, $archivePath, $archiveFilename,
            $fileSize, $user_id, $summary_text, $deliveryPercent, $totalModules, $deliveredModules,
            $totalModuleCost, $totalFreightCost, $totalWarehousingCost, $totalAccessorialCost,
            $totalMiles, $totalFuelGallons, $totalCo2Kg, $totalTruckloads,
            $primaryManufacturer, $manufacturerCount, $totalWattage, $totalPallets,
            $totalDeliveries, $onTimeDeliveries, $lateDeliveries, $avgDaysLate, $projectCompletedOnTime,
            $damagedModulesCount, $warrantyClaimsCount);
        $stmtArchive->execute();
        $stmtArchive->close();

        // Mark project as closed
        $notify(78, 'Marking project as closed');
        $stmtClose = $conn->prepare('UPDATE projects SET status = "closed" WHERE id = ?');
        $stmtClose->bind_param('i', $project_id);
        $stmtClose->execute();
        $stmtClose->close();

        // Delete bulk data from database (data is preserved in the ZIP archive)
        // Order matters due to foreign key constraints
        $notify(82, 'Cleaning operational data');

        // 1. Delete warranty_claims linked to site_scheduling for this project
        $stmtDelWarranty = $conn->prepare('DELETE wc FROM warranty_claims wc
            JOIN site_scheduling ss ON wc.scheduling_id = ss.id
            WHERE ss.project_id = ?');
        $stmtDelWarranty->bind_param('i', $project_id);
        $stmtDelWarranty->execute();
        $stmtDelWarranty->close();
        $notify(84, 'Removing warranty claims');

        // 2. Delete site_safety for this project
        $stmtDelSiteSafety = $conn->prepare('DELETE FROM site_safety WHERE project_id = ?');
        $stmtDelSiteSafety->bind_param('i', $project_id);
        $stmtDelSiteSafety->execute();
        $stmtDelSiteSafety->close();

        // 3. Delete site_scheduling for this project
        $stmtDelScheduling = $conn->prepare('DELETE FROM site_scheduling WHERE project_id = ?');
        $stmtDelScheduling->bind_param('i', $project_id);
        $stmtDelScheduling->execute();
        $stmtDelScheduling->close();

        // 4. Delete site_operating_hours for this project
        $stmtDelSiteHours = $conn->prepare('DELETE FROM site_operating_hours WHERE project_id = ?');
        $stmtDelSiteHours->bind_param('i', $project_id);
        $stmtDelSiteHours->execute();
        $stmtDelSiteHours->close();
        $notify(86, 'Removing site scheduling records');

        // 5. Delete delivery_pallets (links between deliveries and pallets)
        $stmtDelDP = $conn->prepare('DELETE dp FROM delivery_pallets dp
            INNER JOIN deliveries d ON dp.delivery_id = d.id
            WHERE d.project_id = ?');
        $stmtDelDP->bind_param('i', $project_id);
        $stmtDelDP->execute();
        $stmtDelDP->close();
        $notify(88, 'Removing delivery-to-pallet links');

        // 5b. Delete container tracking records for this project so archived/closed projects leave no tracking trails.
        $waypointsTableCheck = $conn->query("SHOW TABLES LIKE 'container_tracking_waypoints'");
        $hasWaypointsTable = $waypointsTableCheck && $waypointsTableCheck->num_rows > 0;
        if ($waypointsTableCheck) {
            $waypointsTableCheck->close();
        }
        if ($hasWaypointsTable) {
            $stmtDelTrackingWaypoints = $conn->prepare('DELETE FROM container_tracking_waypoints WHERE project_id = ?');
            if ($stmtDelTrackingWaypoints) {
                $stmtDelTrackingWaypoints->bind_param('i', $project_id);
                $stmtDelTrackingWaypoints->execute();
                $stmtDelTrackingWaypoints->close();
            }
        }

        $positionsTableCheck = $conn->query("SHOW TABLES LIKE 'container_tracking_positions'");
        $hasPositionsTable = $positionsTableCheck && $positionsTableCheck->num_rows > 0;
        if ($positionsTableCheck) {
            $positionsTableCheck->close();
        }
        if ($hasPositionsTable) {
            $stmtDelTrackingPositions = $conn->prepare('DELETE FROM container_tracking_positions WHERE project_id = ?');
            if ($stmtDelTrackingPositions) {
                $stmtDelTrackingPositions->bind_param('i', $project_id);
                $stmtDelTrackingPositions->execute();
                $stmtDelTrackingPositions->close();
            }
        }
        $notify(90, 'Removing container tracking records');

        // 6. Delete inventory_pallets tied to modules for this project
        $stmtDelPallets = $conn->prepare('DELETE ip FROM inventory_pallets ip
            JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            JOIN modules m ON umi.unassigned_module_id = m.id
            WHERE m.project_id = ?');
        $stmtDelPallets->bind_param('i', $project_id);
        $stmtDelPallets->execute();
        $stmtDelPallets->close();
        $notify(92, 'Removing pallets and module items');

        // 7. Delete unassigned_module_items tied to modules for this project
        $stmtDelModuleItems = $conn->prepare('DELETE umi FROM unassigned_module_items umi
            JOIN modules m ON umi.unassigned_module_id = m.id
            WHERE m.project_id = ?');
        $stmtDelModuleItems->bind_param('i', $project_id);
        $stmtDelModuleItems->execute();
        $stmtDelModuleItems->close();

        // 8. Delete modules (batches) for this project
        $stmtDelModules = $conn->prepare('DELETE FROM modules WHERE project_id = ?');
        $stmtDelModules->bind_param('i', $project_id);
        $stmtDelModules->execute();
        $stmtDelModules->close();
        $notify(94, 'Removing module batches');

        // 9. Delete deliveries for this project
        $stmtDelDeliveries = $conn->prepare('DELETE FROM deliveries WHERE project_id = ?');
        $stmtDelDeliveries->bind_param('i', $project_id);
        $stmtDelDeliveries->execute();
        $stmtDelDeliveries->close();
        $notify(96, 'Removing deliveries');

        // 10. Delete project_wattage_orders for this project
        $stmtDelWattageOrders = $conn->prepare('DELETE FROM project_wattage_orders WHERE project_id = ?');
        $stmtDelWattageOrders->bind_param('i', $project_id);
        $stmtDelWattageOrders->execute();
        $stmtDelWattageOrders->close();
        $notify(98, 'Finalizing close-out');

        $notify(100, 'Close-out complete');

        return [
            'project_name' => $projectName,
            'archive_path' => $archivePath,
            'archive_filename' => $archiveFilename,
        ];
    } finally {
        cleanupProjectCloseTempArtifacts($tmpDir, $zipPath);
    }
}

function projectCloseJobDir() {
    return __DIR__ . '/uploads/project_close_jobs';
}

function projectCloseJobPath($project_id) {
    return projectCloseJobDir() . '/project_' . (int)$project_id . '.json';
}

function readProjectCloseJob($project_id) {
    $path = projectCloseJobPath($project_id);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $job = json_decode($raw, true);
    return is_array($job) ? $job : null;
}

function writeProjectCloseJob($project_id, array $job) {
    ensureDir(projectCloseJobDir());
    $path = projectCloseJobPath($project_id);
    $tmpPath = $path . '.tmp';
    $job['project_id'] = (int)$project_id;
    $job['updated_at'] = date('c');
    @file_put_contents($tmpPath, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @rename($tmpPath, $path);
}

function updateProjectCloseJob($project_id, array $changes) {
    $job = readProjectCloseJob($project_id);
    if (!$job) {
        $job = ['project_id' => (int)$project_id];
    }
    foreach ($changes as $key => $value) {
        $job[$key] = $value;
    }
    writeProjectCloseJob($project_id, $job);
    return $job;
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
$default_summary_text = ($project_id && $project_row) ? buildDefaultSummaryText($project_row, $totals, $conn, $project_id) : '';

if ($action === 'preview_summary' && $project_id > 0) {
    $summary_text = trim($posted_summary_text ?? '');
    if ($summary_text === '') {
        $_SESSION['project_close_error'] = 'Please enter a project summary before previewing.';
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }
    try {
        $user_row = fetchUserRow($conn, $user_id) ?? ['username' => ''];
        $_SESSION['project_close_summary'][$project_id] = $summary_text;
        [$summaryPdfPath, $pdfOutput] = generateSummaryPdf($project_id, $project_row, $summary_text, $user_row, $totals);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Project_' . $project_id . '_Summary.pdf"');
        echo $pdfOutput;
        exit();
    } catch (Throwable $e) {
        $_SESSION['project_close_error'] = 'Summary preview failed: ' . $e->getMessage();
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }
}

if ($action === 'export' && $project_id > 0) {
    $summary_text = trim($posted_summary_text ?? '');
    if ($summary_text === '') {
        $_SESSION['project_close_error'] = 'Please provide a written project summary to include in the export.';
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }
    try {
        $user_row = fetchUserRow($conn, $user_id) ?? ['username' => ''];
        $_SESSION['project_close_summary'][$project_id] = $summary_text;
        [$summaryPdfPath, ] = generateSummaryPdf($project_id, $project_row, $summary_text, $user_row, $totals);
        $sustainabilityReport = fetchSustainabilityReport($conn, $project_id);
        [$tmpDir, $zipPath] = collectDataAndBuildArchive($conn, $project_id, $user_row, $project_row, $totals, $summaryPdfPath, $sustainabilityReport);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
        header('Content-Length: ' . filesize($zipPath));

        // Use chunked reading to avoid memory exhaustion on large files
        $handle = fopen($zipPath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 8192); // Read 8KB at a time
                flush();
            }
            fclose($handle);
        }

        // Cleanup
        @unlink($zipPath);
        $it = new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($tmpDir);
        exit();
    } catch (Throwable $e) {
        $_SESSION['project_close_error'] = 'Export failed: ' . $e->getMessage();
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }
}

// Export data only (no summary PDF) - for non-admin users
if ($action === 'export_data_only' && $project_id > 0) {
    try {
        $user_row = fetchUserRow($conn, $user_id) ?? ['username' => ''];
        $sustainabilityReport = fetchSustainabilityReport($conn, $project_id);
        // Pass null for summaryPdfPath to skip summary in archive
        [$tmpDir, $zipPath] = collectDataAndBuildArchive($conn, $project_id, $user_row, $project_row, $totals, null, $sustainabilityReport);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
        header('Content-Length: ' . filesize($zipPath));

        $handle = fopen($zipPath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        }

        // Cleanup
        @unlink($zipPath);
        $it = new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($tmpDir);
        exit();
    } catch (Throwable $e) {
        $_SESSION['project_close_error'] = 'Export failed: ' . $e->getMessage();
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }
}

if ($action === 'close_project_status' && $project_id > 0) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (!$canCloseProject) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit();
    }
    $job = readProjectCloseJob($project_id);
    echo json_encode([
        'ok' => true,
        'job' => $job,
    ]);
    exit();
}

if ($action === 'start_close_project' && $project_id > 0) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (!$canCloseProject) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit();
    }

    if (!$project_row || (($project_row['status'] ?? '') === 'closed')) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'This project is already closed.']);
        exit();
    }

    $summary_text = trim($posted_summary_text ?? '');
    if ($summary_text === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please provide a project summary before closing out.']);
        exit();
    }

    $existingJob = readProjectCloseJob($project_id);
    if (is_array($existingJob) && in_array($existingJob['status'] ?? '', ['queued', 'running'], true)) {
        echo json_encode(['ok' => true, 'job' => $existingJob]);
        exit();
    }

    $_SESSION['project_close_summary'][$project_id] = $summary_text;

    $job = [
        'job_id' => uniqid('pc_', true),
        'project_id' => $project_id,
        'project_name' => $project_row['project_name'] ?? '',
        'requested_by' => $user_id,
        'status' => 'queued',
        'progress' => 0,
        'message' => 'Close-out queued',
        'error' => null,
        'started_at' => null,
        'finished_at' => null,
        'created_at' => date('c'),
    ];
    writeProjectCloseJob($project_id, $job);

    $startPayload = json_encode(['ok' => true, 'job' => $job]);
    if ($startPayload === false) {
        $startPayload = '{"ok":true}';
    }

    if (!function_exists('fastcgi_finish_request')) {
        header('Connection: close');
        header('Content-Length: ' . strlen($startPayload));
    }
    echo $startPayload;

    // Release the session lock so polling requests are not blocked.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    @ini_set('max_execution_time', '0');
    @ignore_user_abort(true);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    try {
        updateProjectCloseJob($project_id, [
            'status' => 'running',
            'progress' => 1,
            'message' => 'Starting close-out',
            'started_at' => date('c'),
            'error' => null,
            'finished_at' => null,
        ]);

        $user_row = fetchUserRow($conn, $user_id) ?? ['username' => ''];
        $result = executeProjectCloseout(
            $conn,
            $project_id,
            $user_id,
            $summary_text,
            $user_row,
            $project_row,
            $totals,
            static function ($progress, $message) use ($project_id) {
                updateProjectCloseJob($project_id, [
                    'status' => 'running',
                    'progress' => (int)$progress,
                    'message' => $message,
                ]);
            }
        );

        updateProjectCloseJob($project_id, [
            'status' => 'completed',
            'progress' => 100,
            'message' => 'Close-out completed successfully',
            'result' => $result,
            'finished_at' => date('c'),
            'error' => null,
        ]);
    } catch (Throwable $e) {
        updateProjectCloseJob($project_id, [
            'status' => 'failed',
            'progress' => 100,
            'message' => 'Close-out failed',
            'error' => $e->getMessage(),
            'finished_at' => date('c'),
        ]);
    }
    exit();
}

// Synchronous fallback close-out project (admin only)
if ($action === 'close_project' && $project_id > 0 && $canCloseProject) {
    if (!$project_row || (($project_row['status'] ?? '') === 'closed')) {
        $_SESSION['project_close_error'] = 'This project is already closed.';
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }

    $summary_text = trim($posted_summary_text ?? '');
    if ($summary_text === '') {
        $_SESSION['project_close_error'] = 'Please provide a project summary before closing out.';
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    @ini_set('max_execution_time', '0');
    @ignore_user_abort(true);

    try {
        $user_row = fetchUserRow($conn, $user_id) ?? ['username' => ''];
        $_SESSION['project_close_summary'][$project_id] = $summary_text;
        $result = executeProjectCloseout($conn, $project_id, $user_id, $summary_text, $user_row, $project_row, $totals);

        $_SESSION['archive_success'] = 'Project "' . htmlspecialchars($result['project_name'] ?? ($project_row['project_name'] ?? '')) . '" has been successfully closed out and archived.';
        header('Location: archived_projects.php');
        exit();
    } catch (Throwable $e) {
        $_SESSION['project_close_error'] = 'Close-out failed: ' . $e->getMessage();
        header('Location: project_close.php?project_id=' . $project_id);
        exit();
    }
}

$active_close_job = ($canCloseProject && $project_id > 0) ? readProjectCloseJob($project_id) : null;
$active_close_job_json = json_encode($active_close_job, JSON_UNESCAPED_SLASHES);
if ($active_close_job_json === false) {
    $active_close_job_json = 'null';
}

$flash_error = $_SESSION['project_close_error'] ?? '';
unset($_SESSION['project_close_error']);

$summary_text = $default_summary_text;
if ($posted_summary_text !== null) {
    $summary_text = $posted_summary_text;
} elseif ($project_id && isset($_SESSION['project_close_summary'][$project_id])) {
    $summary_text = $_SESSION['project_close_summary'][$project_id];
} elseif ($project_id) {
    $storedDraft = loadStoredSummaryText($project_id);
    if ($storedDraft !== '') {
        $summary_text = $storedDraft;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Data Export<?php echo $project_row ? ' - ' . htmlspecialchars($project_row['project_name']) : ''; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .card { background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); padding: 24px; margin-bottom: 18px; border: 1px solid #e9ecef; }
        .card h2 { margin-top: 0; color: #1f3b4d; font-weight: 700; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
        .pill { display: inline-flex; align-items: center; padding: 10px 14px; border-radius: 12px; background: #f1f6f9; color: #1f3b4d; font-weight: 600; font-size: 14px; }
        .pill strong { margin-right: 8px; color: #488C9A; }
        .cta { display: inline-flex; align-items: center; gap: 10px; padding: 14px 18px; border: none; border-radius: 12px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color: #fff; font-weight: 700; cursor: pointer; box-shadow: 0 10px 20px rgba(72,140,154,0.25); transition: transform 0.1s ease, box-shadow 0.2s ease; text-decoration: none; }
        .cta:disabled { opacity: 0.55; cursor: not-allowed; box-shadow: none; }
        .cta:not(:disabled):hover { transform: translateY(-1px); box-shadow: 0 12px 26px rgba(72,140,154,0.28); }
        .cta-secondary { background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%); box-shadow: 0 10px 20px rgba(108,117,125,0.25); }
        .cta-secondary:not(:disabled):hover { box-shadow: 0 12x 26px rgba(108,117,125,0.28); }
        .warning { color: #c0392b; font-weight: 600; }
        .select-group { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        select { padding: 10px 12px; border-radius: 10px; border: 1px solid #d9e2ec; min-width: 260px; font-family: inherit; font-size: 14px; }
        .hero { position: relative; background: #fff; border-radius: 18px; padding: 22px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border:1px solid #e9ecef; overflow:hidden; margin: 0 20px 18px; }
        .hero::before { content:''; position:absolute; top:0; left:0; right:0; height:5px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); }
        .hero h1 { margin: 0; font-size: 26px; color: #1a2c38; }
        .hero .header-top { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
        .header-actions { display:flex; gap:10px; flex-wrap:wrap; }
        .progress { display: flex; align-items: center; gap: 12px; }
        .progress-bar { flex: 1; height: 12px; border-radius: 999px; background: #e9ecef; position: relative; overflow: hidden; }
        .progress-bar span { position: absolute; left: 0; top: 0; bottom: 0; width: var(--val, 0%); background: linear-gradient(90deg, #4db6ac, #2c98a0); border-radius: 999px; }
        .stat { padding: 14px; border-radius: 14px; background: #f9fbfd; border: 1px solid #e7eef4; }
        .stat .label { color: #5f6f7d; font-size: 13px; text-transform: uppercase; letter-spacing: 0.6px; }
        .stat .value { font-size: 22px; font-weight: 700; color: #1f3b4d; }
        .flash { padding: 12px 14px; border-radius: 10px; background: #ffecec; color: #c0392b; border: 1px solid #f5c2c7; margin-bottom: 12px; }
        .two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
        .export-cards { padding: 0 20px; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
    require_once 'components/breadcrumbs.php';
    echo slp_render_breadcrumbs([
        'project_id' => $project_id,
        'current_label' => 'Export Data',
    ]);
    ?>
    <div class="hero card">
        <div class="header-top">
            <div>
                <h1>Export Project Data</h1>
                <p style="margin:6px 0 0;color:#4a5b6a;max-width:720px;">Generate a comprehensive archive of project documents, deliveries, sustainability reporting, and costs.</p>
            </div>
            <div class="header-actions">
                <?php if ($project_id): ?>
                    <?php if ($canEditSummary): ?>
                    <button type="submit" class="cta cta-secondary" form="projectSummaryForm" name="action" value="preview_summary" formtarget="_blank">
                        Preview Summary PDF
                    </button>
                    <button type="submit" class="cta" form="projectSummaryForm" name="action" value="export">
                        Export All Data
                    </button>
                    <?php else: ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="project_id" value="<?php echo (int)$project_id; ?>">
                        <input type="hidden" name="action" value="export_data_only">
                        <button type="submit" class="cta">Export Data</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="export-cards">

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
    </div>

    <?php if ($canEditSummary): ?>
    <form id="projectSummaryForm" method="post" style="margin:0;">
        <input type="hidden" name="project_id" value="<?php echo (int)$project_id; ?>">
        <div class="card">
            <h2>Project Summary PDF</h2>
            <p style="margin:6px 0 12px;color:#4a5b6a;">This summary is pre-filled with project data and will be saved as a PDF in the export package. Edit the text below to customize, then preview or export.</p>
            <textarea name="summary_text" rows="16" style="width:100%;padding:12px;border-radius:12px;border:1px solid #d9e2ec;font-family:inherit;font-size:14px;line-height:1.5;" required><?php echo htmlspecialchars($summary_text ?? ''); ?></textarea>
            <div class="select-group" style="margin-top:12px;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="cta cta-secondary" name="action" value="preview_summary" formtarget="_blank">Preview Summary PDF</button>
                <button type="submit" class="cta" name="action" value="export">Save Summary & Export All</button>
            </div>
            <p style="margin-top:8px;color:#5f6f7d;font-size:13px;">Includes project info, delivery stats, and your notes.</p>
        </div>
    </form>
    <?php endif; ?>

    <div class="card">
        <h2>Included in Export</h2>
        <div class="two-col">
            <?php if ($canEditSummary): ?>
            <div class="pill"><strong>00</strong> Project Summary (PDF + meta)</div>
            <?php endif; ?>
            <div class="pill"><strong>02</strong> Documents grouped by type</div>
            <div class="pill"><strong>03</strong> Module batches, pallets, movements</div>
            <div class="pill"><strong>05</strong> Deliveries, appointments, anticipated schedule</div>
            <div class="pill"><strong>06</strong> Warehouses, inventory, cost items</div>
            <div class="pill"><strong>07</strong> Financial cost summary</div>
            <div class="pill"><strong>08</strong> Sustainability report & emissions</div>
            <div class="pill"><strong>09</strong> Warranty / Exceptions</div>
            <div class="pill"><strong>10</strong> Photos & ordering</div>
        </div>
    </div>

    <?php if ($canCloseProject): ?>
    <div class="card" style="border: 2px solid #ffc107; background: linear-gradient(135deg, #fffef5 0%, #fff9e6 100%);">
        <h2 style="color: #856404;">Project Close-Out (Admin Only)</h2>
        <p style="margin:6px 0 12px;color:#856404;">
            As an administrator, you can formally close out this project. This will archive all project data and mark the project as closed.
        </p>
        <div style="background: #fff3cd; padding: 14px; border-radius: 10px; margin-bottom: 14px; border: 1px solid #ffc107;">
            <strong style="color: #856404;">Before closing out:</strong>
            <ul style="margin: 8px 0 0 20px; color: #664d03;">
                <li>Ensure all deliveries are marked as complete</li>
                <li>Verify all warranty/exception claims are resolved</li>
                <li>Confirm all documents have been uploaded</li>
                <li>Review and finalize the project summary above</li>
            </ul>
        </div>
        <button type="button" class="cta" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); color: #664d03;" onclick="showCloseoutConfirm()">
            Close Out Project
        </button>
        <p style="color:#5f6f7d;font-size:13px;margin-top:12px;">
            <em>Note: Closing out archives all data and hides the project from active views. The archive will be available for download.</em>
        </p>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    </div><!-- /.export-cards -->
</main>

<!-- Loading Modal -->
<div id="loadingModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="text-align:center; padding: 40px;">
        <div class="spinner"></div>
        <h3 style="margin: 20px 0 10px; color: #1f3b4d;">Processing Export...</h3>
        <p style="color: #5f6f7d; margin: 0;">Please wait while we gather and package your project data.</p>
    </div>
</div>

<!-- Close-out Confirmation Modal -->
<?php if ($canCloseProject && $project_id): ?>
<div id="closeoutModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width: 500px;">
        <h3 style="margin: 0 0 15px; color: #856404;">Confirm Project Close-Out</h3>
        <p style="color: #664d03; margin-bottom: 15px;">
            You are about to close out <strong><?php echo htmlspecialchars($project_row['project_name'] ?? ''); ?></strong>.
        </p>
        <div style="background: #fff3cd; padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #ffc107;">
            <p style="margin: 0; color: #664d03; font-size: 14px;">
                <strong>This action will:</strong>
            </p>
            <ul style="margin: 8px 0 0 20px; color: #664d03; font-size: 14px;">
                <li>Generate a complete archive of all project data (ZIP file)</li>
                <li>Mark the project as closed in the system</li>
                <li>Hide the project from active project views</li>
            </ul>
        </div>
        <div style="background: #f8d7da; padding: 12px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
            <p style="margin: 0; color: #721c24; font-size: 14px;">
                <strong>Warning - Permanent Data Deletion:</strong>
            </p>
            <ul style="margin: 8px 0 0 20px; color: #721c24; font-size: 14px;">
                <li>All inventory pallets for this project will be deleted</li>
                <li>All deliveries and delivery records will be deleted</li>
                <li>All module batches will be deleted</li>
                <li>All site scheduling appointments will be deleted</li>
                <li>All warranty claims will be deleted</li>
            </ul>
            <p style="margin: 10px 0 0 0; color: #721c24; font-size: 13px;">
                This data will only exist in the archived ZIP file after close-out.
            </p>
        </div>
        <p style="color: #5f6f7d; font-size: 13px; margin-bottom: 20px;">
            The archived ZIP file will remain accessible from the Archived Projects page.
        </p>
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <button type="button" class="cta cta-secondary" onclick="hideCloseoutConfirm()">Cancel</button>
            <form method="post" id="closeoutForm" style="margin: 0;">
                <input type="hidden" name="project_id" value="<?php echo (int)$project_id; ?>">
                <input type="hidden" name="action" value="close_project">
                <input type="hidden" name="summary_text" value="">
                <button type="submit" class="cta" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); color: #664d03;">
                    Close Out Project
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canCloseProject && $project_id): ?>
<div id="closeoutProgressModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width: 560px;">
        <h3 style="margin: 0 0 10px; color: #1f3b4d;">Closing Out Project</h3>
        <p id="closeoutProgressMessage" style="color: #4a5b6a; margin: 0 0 14px;">Preparing close-out...</p>
        <div class="closeout-progress-track">
            <div id="closeoutProgressBar" class="closeout-progress-bar" style="width: 0%;"></div>
        </div>
        <p id="closeoutProgressPercent" style="margin: 8px 0 12px; color: #1f3b4d; font-weight: 600;">0%</p>
        <div id="closeoutProgressError" class="flash" style="display:none; margin: 0 0 12px;"></div>
        <p style="margin: 0 0 14px; color: #5f6f7d; font-size: 13px;">
            Large projects can take several minutes. You can keep this page open while processing continues.
        </p>
        <div style="display:flex; justify-content:flex-end;">
            <button id="closeoutProgressCloseBtn" type="button" class="cta cta-secondary" style="display:none;" onclick="hideCloseoutProgressModal()">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }
    .modal-content {
        background: #fff;
        padding: 24px;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        max-width: 90%;
        max-height: 90vh;
        overflow-y: auto;
    }
    .spinner {
        width: 50px;
        height: 50px;
        border: 4px solid #e9ecef;
        border-top-color: #488C9A;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto;
    }
    .closeout-progress-track {
        width: 100%;
        height: 12px;
        border-radius: 999px;
        background: #e9ecef;
        overflow: hidden;
    }
    .closeout-progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #4db6ac, #2c98a0);
        transition: width 0.25s ease;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
</style>

<script>
let closeoutStatusPollTimer = null;
const closeoutProjectId = <?php echo (int)$project_id; ?>;
const initialCloseoutJob = <?php echo $active_close_job_json; ?>;

// Show loading modal on export
document.addEventListener('DOMContentLoaded', function() {
    // For admin export form
    const summaryForm = document.getElementById('projectSummaryForm');
    if (summaryForm) {
        summaryForm.addEventListener('submit', function(e) {
            const action = e.submitter ? e.submitter.value : '';
            if (action === 'export') {
                showLoadingModal();
            }
        });
    }

    // For non-admin export form (simple export button)
    const exportForms = document.querySelectorAll('form[method="post"]');
    exportForms.forEach(form => {
        const actionInput = form.querySelector('input[name="action"]');
        if (actionInput && actionInput.value === 'export_data_only') {
            form.addEventListener('submit', function() {
                showLoadingModal();
            });
        }
    });

    const closeoutForm = document.getElementById('closeoutForm');
    if (closeoutForm) {
        closeoutForm.addEventListener('submit', startAsyncCloseout);
    }

    if (initialCloseoutJob && (initialCloseoutJob.status === 'queued' || initialCloseoutJob.status === 'running')) {
        showCloseoutProgressModal();
        updateCloseoutProgress(initialCloseoutJob.progress || 0, initialCloseoutJob.message || 'Close-out is in progress...');
        scheduleCloseoutStatusPoll(800);
    } else if (initialCloseoutJob && initialCloseoutJob.status === 'completed') {
        window.location.href = 'archived_projects.php';
    } else if (initialCloseoutJob && initialCloseoutJob.status === 'failed') {
        showCloseoutProgressModal();
        updateCloseoutProgress(100, initialCloseoutJob.message || 'Close-out failed.');
        showCloseoutError(initialCloseoutJob.error || 'Close-out failed. Please try again or check logs.');
    }
});

function showLoadingModal() {
    document.getElementById('loadingModal').style.display = 'flex';
    // Auto-hide after download starts (browser will handle the file)
    setTimeout(function() {
        document.getElementById('loadingModal').style.display = 'none';
    }, 30000); // 30 second timeout
}

function showCloseoutConfirm() {
    // Copy summary text to the closeout form
    const summaryTextarea = document.querySelector('textarea[name="summary_text"]');
    const closeoutForm = document.getElementById('closeoutForm');
    if (summaryTextarea && closeoutForm) {
        closeoutForm.querySelector('input[name="summary_text"]').value = summaryTextarea.value;
    }
    document.getElementById('closeoutModal').style.display = 'flex';
}

function hideCloseoutConfirm() {
    document.getElementById('closeoutModal').style.display = 'none';
}

function showCloseoutProgressModal() {
    const modal = document.getElementById('closeoutProgressModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function hideCloseoutProgressModal() {
    const modal = document.getElementById('closeoutProgressModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function updateCloseoutProgress(progress, message) {
    const safeProgress = Math.max(0, Math.min(100, parseInt(progress, 10) || 0));
    const bar = document.getElementById('closeoutProgressBar');
    const percent = document.getElementById('closeoutProgressPercent');
    const text = document.getElementById('closeoutProgressMessage');
    if (bar) {
        bar.style.width = safeProgress + '%';
    }
    if (percent) {
        percent.textContent = safeProgress + '%';
    }
    if (text && message) {
        text.textContent = message;
    }
}

function showCloseoutError(message) {
    const errorEl = document.getElementById('closeoutProgressError');
    const closeBtn = document.getElementById('closeoutProgressCloseBtn');
    if (errorEl) {
        errorEl.textContent = message;
        errorEl.style.display = 'block';
    }
    if (closeBtn) {
        closeBtn.style.display = 'inline-flex';
    }
}

function clearCloseoutError() {
    const errorEl = document.getElementById('closeoutProgressError');
    const closeBtn = document.getElementById('closeoutProgressCloseBtn');
    if (errorEl) {
        errorEl.textContent = '';
        errorEl.style.display = 'none';
    }
    if (closeBtn) {
        closeBtn.style.display = 'none';
    }
}

function scheduleCloseoutStatusPoll(delayMs) {
    if (closeoutStatusPollTimer) {
        clearTimeout(closeoutStatusPollTimer);
    }
    closeoutStatusPollTimer = setTimeout(fetchCloseoutStatus, delayMs || 2000);
}

async function startAsyncCloseout(e) {
    e.preventDefault();

    const closeoutForm = document.getElementById('closeoutForm');
    if (!closeoutForm) {
        return;
    }

    const summaryTextarea = document.querySelector('textarea[name="summary_text"]');
    const summaryText = summaryTextarea ? summaryTextarea.value.trim() : '';
    if (!summaryText) {
        alert('Please provide a project summary before closing out.');
        return;
    }

    showCloseoutProgressModal();
    clearCloseoutError();
    updateCloseoutProgress(1, 'Submitting close-out request...');

    const formData = new FormData(closeoutForm);
    formData.set('summary_text', summaryText);
    formData.set('action', 'start_close_project');

    try {
        const response = await fetch('project_close.php?project_id=' + encodeURIComponent(closeoutProjectId), {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Failed to start close-out job.');
        }

        hideCloseoutConfirm();
        const queuedJob = payload.job || {};
        updateCloseoutProgress(queuedJob.progress || 2, queuedJob.message || 'Close-out started');
        scheduleCloseoutStatusPoll(600);
    } catch (err) {
        showCloseoutError(err.message || 'Failed to start close-out job.');
    }
}

async function fetchCloseoutStatus() {
    if (!closeoutProjectId) {
        return;
    }

    try {
        const response = await fetch(
            'project_close.php?action=close_project_status&project_id=' + encodeURIComponent(closeoutProjectId) + '&_ts=' + Date.now(),
            {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store'
            }
        );
        const payload = await response.json();
        if (!response.ok || !payload.ok || !payload.job) {
            scheduleCloseoutStatusPoll(2500);
            return;
        }

        const job = payload.job;
        updateCloseoutProgress(job.progress || 0, job.message || 'Processing...');

        if (job.status === 'completed') {
            clearCloseoutError();
            updateCloseoutProgress(100, 'Close-out completed. Redirecting to archived projects...');
            setTimeout(function() {
                window.location.href = 'archived_projects.php';
            }, 1200);
            return;
        }

        if (job.status === 'failed') {
            showCloseoutError(job.error || 'Close-out failed. Please check the server logs.');
            return;
        }

        scheduleCloseoutStatusPoll(1800);
    } catch (err) {
        scheduleCloseoutStatusPoll(2500);
    }
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const closeoutModal = document.getElementById('closeoutModal');
        if (closeoutModal) closeoutModal.style.display = 'none';
    }
});

// Close modal on backdrop click
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this && this.id !== 'loadingModal') {
            this.style.display = 'none';
        }
    });
});
</script>
</body>
</html>
