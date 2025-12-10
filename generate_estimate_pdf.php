<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

require __DIR__ . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Get data from POST
if (!isset($_POST['calculator_data']) || !isset($_POST['results_data'])) {
    die("Missing required data");
}

$calculator_data = json_decode($_POST['calculator_data'], true);
$warehouse_results = json_decode($_POST['results_data'], true);

if (!$calculator_data || !$warehouse_results) {
    die("Invalid data format");
}

// Find best value warehouse
$best_warehouse_index = 0;
$lowest_cost = PHP_FLOAT_MAX;
foreach ($warehouse_results as $index => $wr) {
    if ($wr['results']['totals']['total'] < $lowest_cost) {
        $lowest_cost = $wr['results']['totals']['total'];
        $best_warehouse_index = $index;
    }
}

// Generate PDF HTML
function generateEstimatePdfHtml($calculator_data, $warehouse_results, $best_warehouse_index) {
    // Convert logo to base64
    $logoPath = __DIR__ . '/pictures/header_logo.png';
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $logoData = file_get_contents($logoPath);
        $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
    }

    $start = DateTime::createFromFormat('Y-m', $calculator_data['schedule']['startMonth']);
    $end = DateTime::createFromFormat('Y-m', $calculator_data['schedule']['endMonth']);
    $period = $start->format('M Y') . ' - ' . $end->format('M Y');
    $numMonths = count($calculator_data['schedule']['months']);

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page {
                margin: 0.5in;
                size: letter;
            }
            body {
                font-family: Arial, sans-serif;
                font-size: 10px;
                line-height: 1.4;
                color: #333;
                margin: 0;
                padding: 0;
            }

            .header {
                display: table;
                width: 100%;
                margin-bottom: 20px;
                border-bottom: 3px solid #488C9A;
                padding-bottom: 15px;
            }
            .header-left {
                display: table-cell;
                vertical-align: middle;
                width: 200px;
            }
            .header-right {
                display: table-cell;
                vertical-align: middle;
                text-align: right;
            }
            .logo {
                max-height: 60px;
                max-width: 180px;
            }
            .report-title {
                font-size: 24px;
                font-weight: bold;
                color: #293E4C;
                margin-bottom: 5px;
            }
            .report-subtitle {
                font-size: 12px;
                color: #666;
            }

            .summary-section {
                margin-bottom: 25px;
            }
            .section-title {
                font-size: 14px;
                font-weight: bold;
                color: #293E4C;
                margin-bottom: 12px;
                padding-bottom: 5px;
                border-bottom: 2px solid #488C9A;
            }

            .comparison-grid {
                display: table;
                width: 100%;
                border-collapse: separate;
                border-spacing: 10px 0;
            }
            .comparison-card {
                display: table-cell;
                vertical-align: top;
                background: #f8f9fa;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 15px;
                text-align: center;
            }
            .comparison-card.best-value {
                border: 2px solid #28a745;
                background: #f0fff4;
            }
            .best-badge {
                background: #28a745;
                color: white;
                padding: 3px 10px;
                border-radius: 10px;
                font-size: 9px;
                font-weight: bold;
                display: inline-block;
                margin-bottom: 10px;
            }
            .warehouse-name {
                font-size: 14px;
                font-weight: bold;
                color: #293E4C;
                margin-bottom: 5px;
            }
            .total-amount {
                font-size: 24px;
                font-weight: bold;
                color: #293E4C;
                margin: 10px 0;
            }
            .savings-text {
                color: #28a745;
                font-size: 11px;
                font-weight: bold;
            }
            .cost-breakdown {
                margin-top: 12px;
                text-align: left;
                font-size: 9px;
            }
            .cost-row {
                display: table;
                width: 100%;
                margin-bottom: 4px;
            }
            .cost-label {
                display: table-cell;
                color: #666;
            }
            .cost-value {
                display: table-cell;
                text-align: right;
                font-weight: bold;
            }

            .details-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
                font-size: 9px;
            }
            .details-table th {
                background: #488C9A;
                color: white;
                padding: 8px 6px;
                text-align: center;
                font-weight: bold;
            }
            .details-table th:first-child {
                text-align: left;
            }
            .details-table td {
                padding: 6px;
                text-align: center;
                border-bottom: 1px solid #e0e0e0;
            }
            .details-table td:first-child {
                text-align: left;
                font-weight: bold;
            }
            .details-table .totals-row {
                background: #f0f0f0;
                font-weight: bold;
            }
            .details-table .totals-row td {
                border-top: 2px solid #488C9A;
            }

            .warehouse-section {
                margin-bottom: 25px;
                page-break-inside: avoid;
            }
            .warehouse-header {
                background: #293E4C;
                color: white;
                padding: 10px 15px;
                font-size: 12px;
                font-weight: bold;
                border-radius: 5px 5px 0 0;
            }
            .warehouse-details {
                border: 1px solid #e0e0e0;
                border-top: none;
                padding: 15px;
                border-radius: 0 0 5px 5px;
            }

            .fee-summary {
                margin-bottom: 15px;
            }
            .fee-grid {
                display: table;
                width: 100%;
            }
            .fee-item {
                display: table-cell;
                width: 25%;
                text-align: center;
                padding: 10px;
                background: #f8f9fa;
                border-right: 1px solid #e0e0e0;
            }
            .fee-item:last-child {
                border-right: none;
            }
            .fee-item .label {
                font-size: 9px;
                color: #666;
                margin-bottom: 3px;
            }
            .fee-item .value {
                font-size: 14px;
                font-weight: bold;
                color: #293E4C;
            }

            .footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 9px;
                color: #999;
                padding: 10px;
                border-top: 1px solid #e0e0e0;
            }

            .page-break {
                page-break-before: always;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="header-left">';

    if ($logoBase64) {
        $html .= '<img src="' . $logoBase64 . '" alt="Solterra Solutions" class="logo">';
    } else {
        $html .= '<div style="font-size: 18px; font-weight: bold; color: #488C9A;">SOLTERRA SOLUTIONS</div>';
    }

    $html .= '</div>
            <div class="header-right">
                <div class="report-title">Warehouse Cost Estimate</div>
                <div class="report-subtitle">' . htmlspecialchars($period) . ' (' . $numMonths . ' months)</div>
                <div class="report-subtitle">Generated: ' . date('F j, Y') . '</div>
            </div>
        </div>';

    // Summary Comparison Section
    if (count($warehouse_results) > 1) {
        $html .= '<div class="summary-section">
            <div class="section-title">Warehouse Comparison Summary</div>
            <div class="comparison-grid">';

        $max_cost = max(array_map(function($r) { return $r['results']['totals']['total']; }, $warehouse_results));

        foreach ($warehouse_results as $index => $wr) {
            $is_best = ($index === $best_warehouse_index);
            $savings = $max_cost - $wr['results']['totals']['total'];

            $html .= '<div class="comparison-card' . ($is_best ? ' best-value' : '') . '">';
            if ($is_best) {
                $html .= '<span class="best-badge">★ BEST VALUE</span>';
            }
            $html .= '<div class="warehouse-name">' . htmlspecialchars($wr['warehouse']['name']) . '</div>
                <div class="total-amount">$' . number_format($wr['results']['totals']['total'], 2) . '</div>';
            if ($is_best && $savings > 0) {
                $html .= '<div class="savings-text">Save $' . number_format($savings, 2) . '</div>';
            }
            $html .= '<div class="cost-breakdown">
                    <div class="cost-row"><span class="cost-label">In Fees:</span><span class="cost-value">$' . number_format($wr['results']['totals']['in_fees'], 2) . '</span></div>
                    <div class="cost-row"><span class="cost-label">Out Fees:</span><span class="cost-value">$' . number_format($wr['results']['totals']['out_fees'], 2) . '</span></div>
                    <div class="cost-row"><span class="cost-label">Storage:</span><span class="cost-value">$' . number_format($wr['results']['totals']['storage_fees'], 2) . '</span></div>
                    <div class="cost-row"><span class="cost-label">Peak Inventory:</span><span class="cost-value">' . number_format($wr['results']['summary']['peak_inventory']) . ' pallets</span></div>
                </div>
            </div>';
        }

        $html .= '</div></div>';
    }

    // Detailed Breakdown for Each Warehouse
    foreach ($warehouse_results as $index => $wr) {
        if ($index > 0 && count($warehouse_results) > 1) {
            $html .= '<div class="page-break"></div>';
        }

        $html .= '<div class="warehouse-section">
            <div class="warehouse-header">
                ' . htmlspecialchars($wr['warehouse']['name']) . ' - Detailed Breakdown
            </div>
            <div class="warehouse-details">
                <div class="fee-summary">
                    <div class="fee-grid">
                        <div class="fee-item">
                            <div class="label">Total Cost</div>
                            <div class="value">$' . number_format($wr['results']['totals']['total'], 2) . '</div>
                        </div>
                        <div class="fee-item">
                            <div class="label">In Fees</div>
                            <div class="value">$' . number_format($wr['results']['totals']['in_fees'], 2) . '</div>
                        </div>
                        <div class="fee-item">
                            <div class="label">Out Fees</div>
                            <div class="value">$' . number_format($wr['results']['totals']['out_fees'], 2) . '</div>
                        </div>
                        <div class="fee-item">
                            <div class="label">Storage Fees</div>
                            <div class="value">$' . number_format($wr['results']['totals']['storage_fees'], 2) . '</div>
                        </div>
                    </div>
                </div>

                <div class="section-title" style="font-size: 11px;">Fee Structure</div>
                <table class="details-table" style="margin-bottom: 15px;">
                    <thead>
                        <tr>
                            <th>Fee Name</th>
                            <th>Amount</th>
                            <th>Unit</th>
                            <th>When Charged</th>
                        </tr>
                    </thead>
                    <tbody>';

        foreach ($wr['warehouse']['fees'] as $fee) {
            $unitLabels = [
                'per_pallet' => 'Per Pallet',
                'per_sqft' => 'Per Sq. Ft.',
                'per_module' => 'Per Module',
                'flat' => 'Flat Rate'
            ];
            $triggerLabels = [
                'on_entry' => 'On Entry',
                'on_exit' => 'On Exit',
                'monthly' => 'Monthly',
                'one_time' => 'One-Time'
            ];

            $html .= '<tr>
                <td>' . htmlspecialchars($fee['name']) . '</td>
                <td>$' . number_format($fee['amount'], 2) . '</td>
                <td>' . ($unitLabels[$fee['unit']] ?? $fee['unit']) . '</td>
                <td>' . ($triggerLabels[$fee['trigger']] ?? $fee['trigger']) . '</td>
            </tr>';
        }

        $html .= '</tbody></table>

                <div class="section-title" style="font-size: 11px;">Monthly Breakdown</div>
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Pallets In</th>
                            <th>Pallets Out</th>
                            <th>End Inventory</th>
                            <th>In Fees</th>
                            <th>Out Fees</th>
                            <th>Storage</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>';

        foreach ($wr['results']['monthly'] as $monthly) {
            $date = DateTime::createFromFormat('Y-m', $monthly['month']);
            $monthDisplay = $date->format('M Y');

            $html .= '<tr>
                <td>' . $monthDisplay . '</td>
                <td>' . number_format($monthly['pallets_in']) . '</td>
                <td>' . number_format($monthly['pallets_out']) . '</td>
                <td>' . number_format($monthly['end_inventory']) . '</td>
                <td>$' . number_format($monthly['in_fees'], 2) . '</td>
                <td>$' . number_format($monthly['out_fees'], 2) . '</td>
                <td>$' . number_format($monthly['storage_fees'], 2) . '</td>
                <td>$' . number_format($monthly['total'], 2) . '</td>
            </tr>';
        }

        $html .= '<tr class="totals-row">
                <td>TOTALS</td>
                <td>' . number_format($wr['results']['summary']['total_pallets_in']) . '</td>
                <td>' . number_format($wr['results']['summary']['total_pallets_out']) . '</td>
                <td>Avg: ' . number_format($wr['results']['summary']['avg_inventory'], 1) . '</td>
                <td>$' . number_format($wr['results']['totals']['in_fees'], 2) . '</td>
                <td>$' . number_format($wr['results']['totals']['out_fees'], 2) . '</td>
                <td>$' . number_format($wr['results']['totals']['storage_fees'], 2) . '</td>
                <td>$' . number_format($wr['results']['totals']['total'], 2) . '</td>
            </tr>
            </tbody>
                </table>
            </div>
        </div>';
    }

    $html .= '
        <div class="footer">
            This estimate is for planning purposes only. Actual costs may vary. | Generated by Solterra Logistics Portal
        </div>
    </body>
    </html>';

    return $html;
}

// Generate PDF
$html = generateEstimatePdfHtml($calculator_data, $warehouse_results, $best_warehouse_index);

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('Letter', 'portrait');
$dompdf->render();

$filename = "Warehouse_Cost_Estimate_" . date('Y-m-d') . ".pdf";
$dompdf->stream($filename, ["Attachment" => false]);
exit;
