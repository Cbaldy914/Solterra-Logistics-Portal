<?php
/**
 * Sunny Report Generator
 * Streams CSV or PDF for selected report using SunnyTools.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Authentication required';
    exit;
}

// Load config (best-effort, similar to chat-stream)
$configPaths = [
    __DIR__ . '/../../../config.php',
    __DIR__ . '/../../config.php',
    dirname(__DIR__, 3) . '/config.php'
];
foreach ($configPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

require_once __DIR__ . '/sunny-tools.php';

// Params
$report = strtolower($_GET['report'] ?? $_POST['report'] ?? '');
$format = strtolower($_GET['format'] ?? $_POST['format'] ?? 'csv');
$days   = intval($_GET['days'] ?? $_POST['days'] ?? 30);
$groupBy = $_GET['groupBy'] ?? $_POST['groupBy'] ?? 'manufacturer';
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : (isset($_POST['project_id']) ? intval($_POST['project_id']) : null);

$userRole = $_SESSION['role'] ?? 'user';
$accountId = $_SESSION['account_id'] ?? null;

$tools = new SunnyTools($userRole, $accountId);

// Fetch data according to report
$title = 'Report';
$rows = [];
switch ($report) {
    case 'delivery_performance':
        $title = 'Delivery Performance';
        $res = $tools->getDeliveryPerformance($days, $groupBy);
        if (!$res['success']) { http_response_code(400); echo $res['error']; exit; }
        $rows = $res['data'];
        break;
    case 'kpi':
    case 'kpidashboard':
        $title = 'KPI Dashboard';
        $res = $tools->getKPIDashboard($days);
        if (!$res['success']) { http_response_code(400); echo $res['error']; exit; }
        $rows = [$res['data']];
        break;
    case 'warehouse_inventory':
        $title = 'Warehouse Inventory';
        $res = $tools->getWarehouseInventory($projectId);
        if (!$res['success']) { http_response_code(400); echo $res['error']; exit; }
        $rows = $res['data'];
        break;
    case 'documents':
        $title = 'Documents';
        $res = $projectId ? $tools->getProjectDocuments($projectId, []) : $tools->getGlobalDocuments([]);
        if (!$res['success']) { http_response_code(400); echo $res['error']; exit; }
        $rows = $res['data'];
        break;
    default:
        http_response_code(400);
        echo 'Unknown report type';
        exit;
}

// Normalize to array of associative arrays
if (!is_array($rows)) { $rows = []; }

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . preg_replace('/\s+/', '_', strtolower($title)) . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    // headers
    $headers = [];
    foreach ($rows as $r) { if (is_array($r)) { $headers = array_keys($r); break; } }
    if (empty($headers)) { $headers = ['message']; fputcsv($out, $headers); fputcsv($out, ['No data']); fclose($out); exit; }
    fputcsv($out, $headers);
    foreach ($rows as $r) { fputcsv($out, array_map(function($v){ return is_scalar($v) ? $v : json_encode($v); }, $r)); }
    fclose($out);
    exit;
}

// default PDF via Dompdf
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$html = '<html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;font-size:12px}h1{font-size:18px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:6px}th{background:#f2f2f2;text-align:left}</style></head><body>';
$html .= '<h1>' . htmlspecialchars($title) . ' (' . intval($days) . ' days)</h1>';
if (empty($rows)) {
    $html .= '<p>No data.</p>';
} else {
    $headers = array_keys($rows[0]);
    $html .= '<table><thead><tr>';
    foreach ($headers as $h) { $html .= '<th>' . htmlspecialchars($h) . '</th>'; }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ($headers as $h) {
            $v = $r[$h] ?? '';
            if (is_array($v) || is_object($v)) { $v = json_encode($v); }
            $html .= '<td>' . htmlspecialchars((string)$v) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
}
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$filename = preg_replace('/\s+/', '_', strtolower($title)) . '_' . date('Ymd_His') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $dompdf->output();
exit;
?>

