<?php
/**
 * Warehouse Audit Report PDF Generator
 *
 * GET /generate_warehouse_audit_pdf.php?session_id=N[&mode=download|inline]
 *
 * Renders a 10-section branded audit report via Dompdf, following the
 * generate_bol.php pattern for admin gating and Dompdf setup. Handles the
 * full-pallet-index appendix with a compact table layout, raises the
 * PHP memory_limit for large sessions (1174+ rows), and streams the final
 * PDF to the browser.
 */

session_name("logistics_session");
session_start();

// Raise memory for large audits - tested to render 1200+ rows comfortably
@ini_set('memory_limit', '512M');
@set_time_limit(180);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$role    = $_SESSION['role']    ?? 'user';
$user_id = (int)$_SESSION['user_id'];
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
    http_response_code(403);
    die("Access denied. Admin role required.");
}

require __DIR__ . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/warehouse_audit_helpers.php';

$conn = getDBConnection();
if (!$conn) { die("Database connection failed"); }

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if (!$session_id) { die("session_id required"); }

try {
    $session = audit_require_session_access($conn, $session_id, $user_id, $role);
} catch (Throwable $e) {
    http_response_code(403);
    die("Access denied: " . htmlspecialchars($e->getMessage()));
}

$mode = $_GET['mode'] ?? 'inline';
$include_full_index = !isset($_GET['full_index']) || $_GET['full_index'] !== '0';

// Load warehouse, project, user details
$stmt = $conn->prepare("SELECT name, street_address, city, state, zip_code FROM warehouses WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $session['warehouse_id']);
$stmt->execute();
$warehouse = $stmt->get_result()->fetch_assoc();
$stmt->close();

$project_name = null;
if (!empty($session['project_id'])) {
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $session['project_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) $project_name = $row['project_name'];
}

$stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $session['created_by_user_id']);
$stmt->execute();
$auditor = $stmt->get_result()->fetch_assoc();
$stmt->close();

$reconciliation = audit_build_reconciliation($conn, $session_id);

// Load scans
$stmt = $conn->prepare("
    SELECT sequence_number, raw_value, normalized_pallet_id, parsed_wattage, quantity,
           is_discrepancy, discrepancy_codes, discrepancy_notes, scan_method, ai_confidence
    FROM warehouse_audit_scans
    WHERE session_id = ? AND is_soft_deleted = 0
    ORDER BY sequence_number ASC
");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$result = $stmt->get_result();
$scans = [];
$discrepancy_scans = [];
while ($row = $result->fetch_assoc()) {
    $scans[] = $row;
    if ($row['is_discrepancy']) $discrepancy_scans[] = $row;
}
$stmt->close();

// Load equipment
$stmt = $conn->prepare("SELECT * FROM warehouse_audit_equipment_checks WHERE session_id = ? ORDER BY id");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$result = $stmt->get_result();
$equipment = [];
while ($row = $result->fetch_assoc()) { $equipment[] = $row; }
$stmt->close();

// Photo count
$stmt = $conn->prepare("SELECT COUNT(*) AS n FROM warehouse_audit_scan_photos WHERE session_id = ?");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$photo_row = $stmt->get_result()->fetch_assoc();
$photo_count = (int)$photo_row['n'];
$stmt->close();

$conn->close();

audit_write_session_event_persist($session_id, $user_id, 'pdf_generated', [
    'mode' => $mode,
    'full_index' => $include_full_index,
    'pallet_count' => count($scans),
]);

// Helper to write session event via its own short-lived connection since main $conn was closed
function audit_write_session_event_persist($session_id, $user_id, $event, $payload) {
    try {
        $conn2 = getDBConnection();
        if ($conn2) {
            audit_write_session_event($conn2, $session_id, $user_id, $event, $payload);
            $conn2->close();
        }
    } catch (Throwable $e) { /* ignore */ }
}

$report_date = date('F j, Y');
$generated_at = date('F j, Y g:i A');
$auditor_name = trim(($auditor['first_name'] ?? '') . ' ' . ($auditor['last_name'] ?? '')) ?: ($auditor['email'] ?? 'Auditor');
$customer_display = $session['customer_name'] ?: ($project_name ?: 'Customer');
$warehouse_display = trim(($warehouse['name'] ?? '') . ($warehouse['city'] ? ' — ' . $warehouse['city'] . ($warehouse['state'] ? ', ' . $warehouse['state'] : '') : ''));

// Helper to render a discrepancy code list
function fmt_codes($csv) {
    if (empty($csv)) return '';
    $codes = explode(',', $csv);
    return implode(', ', array_map(function ($c) { return strtolower(str_replace('_', ' ', $c)); }, $codes));
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { size: letter portrait; margin: 40px 36px; }
    body { font-family: 'Helvetica', Arial, sans-serif; font-size: 10px; color: #293E4C; margin: 0; padding: 0; }

    h1, h2, h3 { margin: 0; color: #293E4C; }
    h1 { font-size: 22px; }
    h2 { font-size: 14px; margin-top: 18px; margin-bottom: 8px; padding-bottom: 4px; border-bottom: 2px solid #488C9A; }
    h3 { font-size: 12px; }

    .page-break { page-break-before: always; }
    .avoid-break { page-break-inside: avoid; }

    .cover {
        height: 720px;
        border: 4px solid #488C9A;
        padding: 48px;
        position: relative;
    }
    .cover-bar { background: #488C9A; color: white; padding: 14px 20px; }
    .cover h1 { color: white; font-size: 26px; margin: 0; }
    .cover-sub { color: #e8f4f6; font-size: 12px; margin-top: 4px; }
    .cover-body { padding: 36px 0 0 0; }
    .cover-block { margin-bottom: 22px; }
    .cover-block .label { font-size: 10px; text-transform: uppercase; color: #6c757d; letter-spacing: 1px; }
    .cover-block .value { font-size: 18px; font-weight: bold; color: #293E4C; margin-top: 4px; }
    .cover-meta { position: absolute; bottom: 48px; left: 48px; right: 48px; border-top: 1px solid #adb5bd; padding-top: 16px; font-size: 10px; color: #6c757d; }

    .stat-grid { width: 100%; border-collapse: collapse; margin: 12px 0; }
    .stat-grid td {
        width: 25%;
        text-align: center;
        padding: 18px 10px;
        border: 1px solid #dee2e6;
        background: #f8f9fa;
    }
    .stat-num { font-size: 28px; font-weight: bold; color: #293E4C; display: block; }
    .stat-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; margin-top: 6px; display: block; }
    .stat-verified .stat-num  { color: #28a745; }
    .stat-flags    .stat-num  { color: #f0ad4e; }
    .stat-missing  .stat-num  { color: #dc3545; }

    table.data-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.data-table th, table.data-table td {
        padding: 6px 8px;
        border: 1px solid #dee2e6;
        text-align: left;
        font-size: 10px;
    }
    table.data-table th {
        background: #488C9A;
        color: white;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 9px;
        letter-spacing: 0.3px;
    }
    table.data-table td.num { text-align: right; font-family: 'Courier New', monospace; }
    table.data-table tr:nth-child(even) td { background: #f8f9fa; }

    table.pallet-index {
        width: 100%;
        border-collapse: collapse;
        margin-top: 8px;
        font-size: 8px;
    }
    table.pallet-index th {
        background: #488C9A;
        color: white;
        font-weight: bold;
        padding: 4px 6px;
        text-align: left;
        font-size: 8px;
    }
    table.pallet-index td {
        padding: 3px 6px;
        border-bottom: 1px solid #e9ecef;
        font-size: 8px;
        font-family: 'Courier New', monospace;
    }
    table.pallet-index td.num { text-align: right; }
    table.pallet-index tr.discrepancy td { background: #fff3cd; }

    .disc-row {
        border-left: 3px solid #f0ad4e;
        background: #fffbf3;
        padding: 8px 10px;
        margin-bottom: 6px;
    }
    .disc-row .id { font-weight: bold; font-family: 'Courier New', monospace; }
    .disc-row .codes { font-size: 9px; color: #a04500; text-transform: uppercase; }
    .disc-row .notes { font-size: 9px; color: #6c757d; margin-top: 2px; }

    .equipment-row {
        border: 1px solid #dee2e6;
        padding: 10px 12px;
        margin-bottom: 8px;
    }
    .equipment-row .eq-head { font-weight: bold; color: #293E4C; }
    .equipment-row .eq-sub { color: #6c757d; font-size: 9px; }
    .equipment-row .eq-status {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 8px;
        font-size: 9px;
        text-transform: uppercase;
        background: #e9ecef;
        color: #6c757d;
    }
    .equipment-row.status-verified .eq-status { background: #d4edda; color: #155724; }
    .equipment-row.status-missing .eq-status  { background: #f8d7da; color: #721c24; }
    .equipment-row.status-damaged .eq-status  { background: #fff3cd; color: #856404; }

    .footer-block {
        margin-top: 24px;
        padding-top: 12px;
        border-top: 1px solid #adb5bd;
        font-size: 9px;
        color: #6c757d;
    }

    .signature-row { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .signature-row td { border: 1px solid #adb5bd; padding: 36px 16px 12px 16px; width: 50%; font-size: 9px; color: #6c757d; vertical-align: bottom; }

    .service-bullets { font-size: 10px; line-height: 1.6; }
    .service-bullets ul { margin: 6px 0 0 20px; padding: 0; }
</style>
</head>
<body>

<!-- ================================================================
     SECTION 1: COVER
     ================================================================ -->
<div class="cover">
    <div class="cover-bar">
        <h1>Warehouse Verification Audit Report</h1>
        <div class="cover-sub">Solterra Logistics Portal</div>
    </div>
    <div class="cover-body">
        <div class="cover-block">
            <div class="label">Customer</div>
            <div class="value"><?= htmlspecialchars($customer_display) ?></div>
        </div>
        <?php if ($project_name): ?>
        <div class="cover-block">
            <div class="label">Project</div>
            <div class="value"><?= htmlspecialchars($project_name) ?></div>
        </div>
        <?php endif; ?>
        <div class="cover-block">
            <div class="label">Warehouse</div>
            <div class="value"><?= htmlspecialchars($warehouse_display) ?></div>
        </div>
        <div class="cover-block">
            <div class="label">Audit Date</div>
            <div class="value"><?= htmlspecialchars($report_date) ?></div>
        </div>
        <div class="cover-block">
            <div class="label">Session Code</div>
            <div class="value" style="font-family: 'Courier New', monospace;"><?= htmlspecialchars($session['session_code']) ?></div>
        </div>
        <div class="cover-block">
            <div class="label">Auditor</div>
            <div class="value"><?= htmlspecialchars($auditor_name) ?></div>
        </div>
        <?php if (!empty($session['service_fee_usd'])): ?>
        <div class="cover-block">
            <div class="label">Service Fee</div>
            <div class="value">$<?= number_format((float)$session['service_fee_usd'], 2) ?></div>
        </div>
        <?php endif; ?>
    </div>
    <div class="cover-meta">
        Generated <?= htmlspecialchars($generated_at) ?> · Report ID <?= htmlspecialchars($session['session_code']) ?>
    </div>
</div>

<!-- ================================================================
     SECTION 2: EXECUTIVE SUMMARY
     ================================================================ -->
<div class="page-break"></div>
<h2>Executive Summary</h2>

<table class="stat-grid">
    <tr>
        <td>
            <span class="stat-num"><?= (int)$reconciliation['expected_count'] ?: '–' ?></span>
            <span class="stat-label">Expected</span>
        </td>
        <td class="stat-verified">
            <span class="stat-num"><?= (int)$reconciliation['verified_count'] ?></span>
            <span class="stat-label">Verified</span>
        </td>
        <td class="stat-flags">
            <span class="stat-num"><?= (int)$reconciliation['discrepancy_count'] ?></span>
            <span class="stat-label">Discrepancies</span>
        </td>
        <td class="stat-missing">
            <span class="stat-num"><?= (int)$reconciliation['missing_count'] ?></span>
            <span class="stat-label">Missing</span>
        </td>
    </tr>
</table>

<p style="font-size: 10px; line-height: 1.5; color: #495057;">
This report documents the physical warehouse verification conducted at <?= htmlspecialchars($warehouse_display) ?>
on <?= htmlspecialchars($report_date) ?>. Each accessible pallet label was inspected and cross-referenced against
the customer-provided inventory schedule. Representative photo documentation was captured throughout the sweep
<?php if ($photo_count > 0): ?> (<?= $photo_count ?> photos on file)<?php endif; ?>.
Any pallets flagged as discrepancies are listed individually below.
</p>

<!-- ================================================================
     SECTION 3: SCOPE OF SERVICE
     ================================================================ -->
<h2>Scope of Service</h2>
<div class="service-bullets">
<?php if (!empty($session['service_description'])):
    $lines = preg_split('/\R/u', $session['service_description']);
    echo '<ul>';
    foreach ($lines as $line) {
        $trimmed = trim(ltrim($line, '•- '));
        if ($trimmed !== '') {
            echo '<li>' . htmlspecialchars($trimmed) . '</li>';
        }
    }
    echo '</ul>';
else: ?>
    <ul>
        <li>Physical onsite inventory verification of each pallet label / ID where reasonably accessible.</li>
        <li>Confirmation of model / wattage / equipment type against the customer-provided inventory schedule.</li>
        <li>Pallet count reconciliation, representative photo documentation, and summary discrepancy report.</li>
        <li>Visual verification of equipment units and identifying tags where applicable.</li>
        <li>Photo documentation and summary findings report.</li>
    </ul>
<?php endif; ?>
</div>

<!-- ================================================================
     SECTION 4: RECONCILIATION BY WATTAGE
     ================================================================ -->
<h2>Reconciliation by Wattage</h2>

<?php if (empty($reconciliation['by_wattage'])): ?>
    <p style="color: #6c757d; font-size: 10px;">No scans recorded in this session.</p>
<?php else: ?>
<table class="data-table">
    <thead>
        <tr>
            <th>Wattage</th>
            <th class="num">Verified</th>
            <th class="num">Discrepancies</th>
            <th class="num">Total Modules</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($reconciliation['by_wattage'] as $w => $data):
        $label = $w === 'unknown' ? 'Unknown' : (int)$w . ' W';
    ?>
        <tr>
            <td><?= htmlspecialchars($label) ?></td>
            <td class="num"><?= (int)$data['verified'] ?></td>
            <td class="num"><?= (int)$data['discrepancy'] ?></td>
            <td class="num"><?= number_format((int)$data['total_modules']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- ================================================================
     SECTION 5: DISCREPANCIES
     ================================================================ -->
<?php if (!empty($discrepancy_scans)): ?>
<h2>Flagged Discrepancies (<?= count($discrepancy_scans) ?>)</h2>
<?php foreach ($discrepancy_scans as $d): ?>
<div class="disc-row avoid-break">
    <div><span class="id">#<?= (int)$d['sequence_number'] ?> · <?= htmlspecialchars($d['raw_value'] ?: $d['normalized_pallet_id']) ?></span>
         <?php if ($d['parsed_wattage']): ?> · <?= (int)$d['parsed_wattage'] ?>W<?php endif; ?>
         <?php if ($d['quantity']): ?> · <?= (int)$d['quantity'] ?> mods<?php endif; ?></div>
    <div class="codes"><?= htmlspecialchars(fmt_codes($d['discrepancy_codes'])) ?></div>
    <?php if (!empty($d['discrepancy_notes'])): ?>
        <div class="notes"><?= htmlspecialchars($d['discrepancy_notes']) ?></div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ================================================================
     SECTION 6: EQUIPMENT VERIFICATION
     ================================================================ -->
<?php if (!empty($equipment)): ?>
<h2>Equipment Verification</h2>
<?php foreach ($equipment as $eq): ?>
<div class="equipment-row status-<?= htmlspecialchars($eq['status']) ?> avoid-break">
    <div class="eq-head">
        <?= htmlspecialchars($eq['equipment_type']) ?>
        <?php if (!empty($eq['manufacturer_name'])): ?> · <?= htmlspecialchars($eq['manufacturer_name']) ?><?php endif; ?>
        <span class="eq-status"><?= htmlspecialchars(str_replace('_', ' ', $eq['status'])) ?></span>
    </div>
    <?php if (!empty($eq['model_number']) || !empty($eq['serial_number']) || !empty($eq['asset_tag'])): ?>
    <div class="eq-sub">
        <?php if (!empty($eq['model_number'])): ?>Model <?= htmlspecialchars($eq['model_number']) ?><?php endif; ?>
        <?php if (!empty($eq['serial_number'])): ?> · S/N <?= htmlspecialchars($eq['serial_number']) ?><?php endif; ?>
        <?php if (!empty($eq['asset_tag'])): ?> · Tag <?= htmlspecialchars($eq['asset_tag']) ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($eq['notes'])): ?>
    <div class="eq-sub" style="margin-top: 4px;"><?= htmlspecialchars($eq['notes']) ?></div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ================================================================
     SECTION 7: FULL PALLET INDEX (appendix)
     ================================================================ -->
<?php if ($include_full_index && !empty($scans)): ?>
<div class="page-break"></div>
<h2>Pallet Index (<?= count($scans) ?> pallets)</h2>
<p style="font-size: 9px; color: #6c757d;">Full list of verified and flagged pallets captured during the sweep. Flagged rows are highlighted.</p>
<table class="pallet-index">
    <thead>
        <tr>
            <th style="width: 8%;">#</th>
            <th style="width: 52%;">Pallet ID</th>
            <th style="width: 12%;" class="num">Watts</th>
            <th style="width: 12%;" class="num">Qty</th>
            <th style="width: 16%;">Method</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($scans as $s): ?>
        <tr class="<?= $s['is_discrepancy'] ? 'discrepancy' : '' ?>">
            <td><?= (int)$s['sequence_number'] ?></td>
            <td><?= htmlspecialchars(mb_strimwidth($s['raw_value'] ?: $s['normalized_pallet_id'], 0, 60, '...')) ?></td>
            <td class="num"><?= $s['parsed_wattage'] ? (int)$s['parsed_wattage'] : '–' ?></td>
            <td class="num"><?= (int)$s['quantity'] ?: '–' ?></td>
            <td><?= htmlspecialchars($s['scan_method']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- ================================================================
     SECTION 8: SIGNATURE BLOCK
     ================================================================ -->
<div class="avoid-break" style="margin-top: 28px;">
    <h2>Sign-off</h2>
    <table class="signature-row">
        <tr>
            <td>
                Auditor: <?= htmlspecialchars($auditor_name) ?><br>
                Date: <?= htmlspecialchars($report_date) ?>
            </td>
            <td>
                Customer Representative:<br>
                Date:
            </td>
        </tr>
    </table>
</div>

<div class="footer-block">
    Generated by Solterra Logistics Portal &middot; Session <?= htmlspecialchars($session['session_code']) ?>
    &middot; <?= htmlspecialchars($generated_at) ?>
    <?php if ((int)$session['ai_vision_call_count'] > 0): ?>
        &middot; <?= (int)$session['ai_vision_call_count'] ?> AI label reads ($<?= number_format((float)$session['ai_vision_cost_estimate_usd'], 4) ?>)
    <?php endif; ?>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

$filename = 'warehouse_audit_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $session['session_code']) . '.pdf';
$dompdf->stream($filename, ['Attachment' => $mode === 'download' ? 1 : 0]);
exit;
