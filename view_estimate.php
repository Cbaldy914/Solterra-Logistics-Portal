<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Get estimate ID
if (!isset($_GET['id'])) {
    header("Location: cost_estimate_calculator.php");
    exit();
}

$estimate_id = intval($_GET['id']);

// Fetch the estimate
$stmt = $conn->prepare("SELECT * FROM warehouse_estimates WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $estimate_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$estimate = $result->fetch_assoc();
$stmt->close();

if (!$estimate) {
    $_SESSION['calc_message'] = ['type' => 'error', 'text' => 'Estimate not found or access denied.'];
    header("Location: cost_estimate_calculator.php");
    exit();
}

$estimate_data = json_decode($estimate['estimate_data'], true);

// Check if this is the new format (has 'warehouses' array) or old format
$is_new_format = isset($estimate_data['warehouses']);

if ($is_new_format) {
    // New format processing
    $calculator_data = $estimate_data;
    $warehouse_results = $estimate_data['results'] ?? [];

    // If no pre-calculated results, recalculate them
    if (empty($warehouse_results)) {
        function calculateWarehouseCosts($warehouse, $schedule) {
            $months = $schedule['months'];
            $mode = $schedule['mode'];
            $results = [
                'monthly' => [],
                'totals' => [
                    'in_fees' => 0,
                    'out_fees' => 0,
                    'storage_fees' => 0,
                    'other_fees' => 0,
                    'total' => 0
                ],
                'summary' => [
                    'total_pallets_in' => 0,
                    'total_pallets_out' => 0,
                    'peak_inventory' => 0,
                    'avg_inventory' => 0
                ]
            ];

            $current_inventory = $warehouse['currentInventory'] ?? 0;
            $inventory_sum = 0;

            $in_fee_rate = 0;
            $out_fee_rate = 0;
            $storage_fee_rate = 0;
            $other_fees = [];

            foreach ($warehouse['fees'] as $fee) {
                if ($fee['trigger'] === 'on_entry') {
                    $in_fee_rate += $fee['amount'];
                } elseif ($fee['trigger'] === 'on_exit') {
                    $out_fee_rate += $fee['amount'];
                } elseif ($fee['trigger'] === 'monthly') {
                    $storage_fee_rate += $fee['amount'];
                } else {
                    $other_fees[] = $fee;
                }
            }

            foreach ($months as $index => $month) {
                $pallets_in = 0;
                $pallets_out = 0;

                if ($mode === 'simple') {
                    $total_in = $warehouse['simpleTotals']['in'] ?? 0;
                    $total_out = $warehouse['simpleTotals']['out'] ?? 0;
                    $num_months = count($months);

                    if ($index === 0) {
                        $pallets_in = $total_in;
                    }
                    $out_start = floor($num_months / 2);
                    if ($index >= $out_start && $total_out > 0) {
                        $remaining_months = $num_months - $out_start;
                        $pallets_out = ceil($total_out / $remaining_months);
                        $already_out = 0;
                        for ($i = $out_start; $i < $index; $i++) {
                            $already_out += ceil($total_out / $remaining_months);
                        }
                        $pallets_out = min($pallets_out, $total_out - $already_out);
                    }
                } else {
                    $pallets_in = $warehouse['movements'][$month]['in'] ?? 0;
                    $pallets_out = $warehouse['movements'][$month]['out'] ?? 0;
                }

                $start_inventory = $current_inventory;
                $current_inventory = max(0, $current_inventory + $pallets_in - $pallets_out);
                $end_inventory = $current_inventory;

                $in_fees = $in_fee_rate * $pallets_in;
                $out_fees = $out_fee_rate * $pallets_out;
                $storage_fees = $storage_fee_rate * $end_inventory;
                $monthly_total = $in_fees + $out_fees + $storage_fees;

                $results['monthly'][] = [
                    'month' => $month,
                    'pallets_in' => $pallets_in,
                    'pallets_out' => $pallets_out,
                    'start_inventory' => $start_inventory,
                    'end_inventory' => $end_inventory,
                    'in_fees' => $in_fees,
                    'out_fees' => $out_fees,
                    'storage_fees' => $storage_fees,
                    'total' => $monthly_total
                ];

                $results['totals']['in_fees'] += $in_fees;
                $results['totals']['out_fees'] += $out_fees;
                $results['totals']['storage_fees'] += $storage_fees;
                $results['totals']['total'] += $monthly_total;

                $results['summary']['total_pallets_in'] += $pallets_in;
                $results['summary']['total_pallets_out'] += $pallets_out;
                $results['summary']['peak_inventory'] = max($results['summary']['peak_inventory'], $end_inventory);
                $inventory_sum += $end_inventory;
            }

            foreach ($other_fees as $fee) {
                if ($fee['trigger'] === 'one_time') {
                    $results['totals']['other_fees'] += $fee['amount'];
                    $results['totals']['total'] += $fee['amount'];
                }
            }

            if (count($months) > 0) {
                $results['summary']['avg_inventory'] = round($inventory_sum / count($months), 1);
            }

            return $results;
        }

        foreach ($calculator_data['warehouses'] as $warehouse) {
            $wh_results = calculateWarehouseCosts($warehouse, $calculator_data['schedule']);
            $warehouse_results[] = [
                'warehouse' => $warehouse,
                'results' => $wh_results
            ];
        }
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

    // Prepare data for charts
    $chart_labels = [];
    foreach ($calculator_data['schedule']['months'] as $month) {
        $date = DateTime::createFromFormat('Y-m', $month);
        $chart_labels[] = $date->format('M Y');
    }

    $chart_datasets = [];
    $colors = ['#488C9A', '#293E4C', '#fbb040', '#28a745', '#dc3545'];
    foreach ($warehouse_results as $index => $wr) {
        $monthly_totals = array_map(function($m) { return $m['total']; }, $wr['results']['monthly']);
        $chart_datasets[] = [
            'label' => $wr['warehouse']['name'],
            'data' => $monthly_totals,
            'borderColor' => $colors[$index % count($colors)],
            'backgroundColor' => $colors[$index % count($colors)] . '20',
            'fill' => true,
            'tension' => 0.3
        ];
    }
} else {
    // Old format - redirect to calculator to create new estimate
    // or convert on the fly
    $_SESSION['calc_message'] = ['type' => 'info', 'text' => 'This estimate uses an older format. Please create a new estimate with the updated calculator.'];
    header("Location: cost_estimate_calculator.php");
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($estimate['name']); ?> - Saved Estimate</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Modern Page Header */
        .results-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .results-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .results-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .results-header h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .results-header .subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }
        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .btn-secondary {
            background: #fff;
            color: #488C9A;
            border: 2px solid #488C9A;
        }
        .btn-secondary:hover {
            background: #488C9A;
            color: #fff;
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20923c 100%);
            color: #fff;
        }
        .btn-success:hover {
            background: linear-gradient(135deg, #20923c 0%, #1a7a32 100%);
        }

        .saved-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #e8f4f8;
            color: #488C9A;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-top: 8px;
        }

        /* Comparison Cards */
        .comparison-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        .warehouse-result-card {
            background: #fff;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 2px solid #e9ecef;
            position: relative;
            transition: all 0.3s ease;
        }
        .warehouse-result-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        }
        .warehouse-result-card.best-value {
            border-color: #28a745;
            box-shadow: 0 4px 20px rgba(40, 167, 69, 0.15);
        }
        .best-value-badge {
            position: absolute;
            top: -12px;
            right: 20px;
            background: linear-gradient(135deg, #28a745 0%, #20923c 100%);
            color: #fff;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .warehouse-card-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        .warehouse-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }
        .warehouse-card-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: #293E4C;
        }
        .warehouse-card-header .warehouse-period {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 4px;
        }
        .total-cost {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .total-cost .amount {
            font-size: 2.5rem;
            font-weight: 700;
            color: #293E4C;
        }
        .total-cost .label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 4px;
        }
        .savings-badge {
            display: inline-block;
            background: #d4edda;
            color: #155724;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-top: 8px;
        }
        .cost-breakdown {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .cost-item {
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .cost-item .label {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 4px;
        }
        .cost-item .value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #293E4C;
        }

        /* Charts Section */
        .charts-section {
            background: #fff;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 32px;
        }
        .charts-section h2 {
            margin: 0 0 24px 0;
            font-size: 1.4rem;
            color: #293E4C;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 24px;
        }
        .chart-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        @media (max-width: 992px) {
            .chart-row {
                grid-template-columns: 1fr;
            }
        }

        /* Detailed Breakdown */
        .details-section {
            background: #fff;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 32px;
        }
        .details-section h2 {
            margin: 0 0 24px 0;
            font-size: 1.4rem;
            color: #293E4C;
        }
        .warehouse-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 12px;
        }
        .warehouse-tab {
            padding: 10px 20px;
            background: transparent;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            color: #6c757d;
            transition: all 0.2s;
        }
        .warehouse-tab:hover {
            background: #f8f9fa;
        }
        .warehouse-tab.active {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
        }
        .details-table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }
        .details-table th {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            padding: 14px 16px;
            text-align: center;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .details-table th:first-child {
            text-align: left;
        }
        .details-table td {
            padding: 12px 16px;
            text-align: center;
            border-bottom: 1px solid #f0f0f0;
        }
        .details-table td:first-child {
            text-align: left;
            font-weight: 500;
            color: #293E4C;
        }
        .details-table tr:hover {
            background: #f8f9fa;
        }
        .details-table .totals-row {
            background: #f8f9fa;
            font-weight: 600;
        }
        .details-table .totals-row td {
            border-top: 2px solid #e0e0e0;
            color: #293E4C;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .results-header {
                padding: 24px;
            }
            .results-header h1 {
                font-size: 1.75rem;
            }
            .comparison-grid {
                grid-template-columns: 1fr;
            }
            .header-actions {
                width: 100%;
                justify-content: stretch;
            }
            .header-actions .btn-action {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
    require_once 'components/breadcrumbs.php';
    echo slp_render_breadcrumbs([
        'current_label' => 'View Estimate',
        'extra' => [['label' => 'Warehouse Cost Calculator', 'url' => 'cost_estimate_calculator.php']]
    ]);
    ?>

    <!-- Modern Page Header -->
    <div class="results-header">
        <div class="results-header-content">
            <div>
                <h1><?php echo htmlspecialchars($estimate['name']); ?></h1>
                <p class="subtitle">
                    <?php echo count($warehouse_results); ?> warehouse<?php echo count($warehouse_results) > 1 ? 's' : ''; ?> compared
                    &bull; <?php echo count($calculator_data['schedule']['months']); ?> months
                </p>
                <div class="saved-badge">
                    Saved on <?php echo date('M j, Y', strtotime($estimate['created_at'])); ?>
                </div>
            </div>
            <div class="header-actions">
                <a href="cost_estimate_calculator.php" class="btn-action btn-secondary">
                    Back to Calculator
                </a>
                <a href="cost_estimate_calculator.php?edit_id=<?php echo $estimate_id; ?>" class="btn-action btn-primary">
                    Edit Estimate
                </a>
                <form method="POST" action="generate_estimate_pdf.php" style="display: inline;" target="_blank">
                    <input type="hidden" name="calculator_data" value='<?php echo htmlspecialchars(json_encode($calculator_data)); ?>'>
                    <input type="hidden" name="results_data" value='<?php echo htmlspecialchars(json_encode($warehouse_results)); ?>'>
                    <button type="submit" class="btn-action btn-success">
                        Export PDF
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Comparison Cards -->
    <div class="comparison-grid">
        <?php foreach ($warehouse_results as $index => $wr): ?>
            <?php
            $is_best = ($index === $best_warehouse_index && count($warehouse_results) > 1);
            ?>
            <div class="warehouse-result-card <?php echo $is_best ? 'best-value' : ''; ?>">
                <?php if ($is_best): ?>
                    <div class="best-value-badge">Best Value</div>
                <?php endif; ?>

                <div class="warehouse-card-header">
                    <div class="warehouse-icon">🏭</div>
                    <div>
                        <h3><?php echo htmlspecialchars($wr['warehouse']['name']); ?></h3>
                        <div class="warehouse-period">
                            <?php
                            $start = DateTime::createFromFormat('Y-m', $calculator_data['schedule']['startMonth']);
                            $end = DateTime::createFromFormat('Y-m', $calculator_data['schedule']['endMonth']);
                            echo $start->format('M Y') . ' - ' . $end->format('M Y');
                            ?>
                        </div>
                    </div>
                </div>

                <div class="total-cost">
                    <div class="amount">$<?php echo number_format($wr['results']['totals']['total'], 2); ?></div>
                    <div class="label">Total Estimated Cost</div>
                    <?php if ($is_best && count($warehouse_results) > 1): ?>
                        <?php
                        $max_cost = max(array_map(function($r) { return $r['results']['totals']['total']; }, $warehouse_results));
                        $actual_savings = $max_cost - $wr['results']['totals']['total'];
                        ?>
                        <?php if ($actual_savings > 0): ?>
                            <div class="savings-badge">Save $<?php echo number_format($actual_savings, 2); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="cost-breakdown">
                    <div class="cost-item">
                        <div class="label">In Fees</div>
                        <div class="value">$<?php echo number_format($wr['results']['totals']['in_fees'], 2); ?></div>
                    </div>
                    <div class="cost-item">
                        <div class="label">Out Fees</div>
                        <div class="value">$<?php echo number_format($wr['results']['totals']['out_fees'], 2); ?></div>
                    </div>
                    <div class="cost-item">
                        <div class="label">Storage Fees</div>
                        <div class="value">$<?php echo number_format($wr['results']['totals']['storage_fees'], 2); ?></div>
                    </div>
                    <div class="cost-item">
                        <div class="label">Peak Inventory</div>
                        <div class="value"><?php echo number_format($wr['results']['summary']['peak_inventory']); ?> pallets</div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <h2>Cost Analysis</h2>
        <div class="chart-row">
            <div>
                <h4 style="margin: 0 0 16px 0; color: #6c757d; font-weight: 500;">Monthly Cost Trend</h4>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            <div>
                <h4 style="margin: 0 0 16px 0; color: #6c757d; font-weight: 500;">Cost Breakdown</h4>
                <div class="chart-container">
                    <canvas id="breakdownChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Breakdown -->
    <div class="details-section">
        <h2>Detailed Monthly Breakdown</h2>

        <div class="warehouse-tabs" id="detailTabs">
            <?php foreach ($warehouse_results as $index => $wr): ?>
                <button type="button" class="warehouse-tab <?php echo $index === 0 ? 'active' : ''; ?>"
                        onclick="switchDetailTab(<?php echo $index; ?>)">
                    <?php echo htmlspecialchars($wr['warehouse']['name']); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($warehouse_results as $index => $wr): ?>
            <div class="details-table-container detail-content" id="detailContent<?php echo $index; ?>"
                 style="<?php echo $index !== 0 ? 'display: none;' : ''; ?>">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Pallets In</th>
                            <th>Pallets Out</th>
                            <th>End Inventory</th>
                            <th>In Fees</th>
                            <th>Out Fees</th>
                            <th>Storage Fees</th>
                            <th>Monthly Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($wr['results']['monthly'] as $monthly): ?>
                            <?php
                            $date = DateTime::createFromFormat('Y-m', $monthly['month']);
                            $monthDisplay = $date->format('M Y');
                            ?>
                            <tr>
                                <td><?php echo $monthDisplay; ?></td>
                                <td><?php echo number_format($monthly['pallets_in']); ?></td>
                                <td><?php echo number_format($monthly['pallets_out']); ?></td>
                                <td><?php echo number_format($monthly['end_inventory']); ?></td>
                                <td>$<?php echo number_format($monthly['in_fees'], 2); ?></td>
                                <td>$<?php echo number_format($monthly['out_fees'], 2); ?></td>
                                <td>$<?php echo number_format($monthly['storage_fees'], 2); ?></td>
                                <td><strong>$<?php echo number_format($monthly['total'], 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="totals-row">
                            <td>Totals</td>
                            <td><?php echo number_format($wr['results']['summary']['total_pallets_in']); ?></td>
                            <td><?php echo number_format($wr['results']['summary']['total_pallets_out']); ?></td>
                            <td>Avg: <?php echo number_format($wr['results']['summary']['avg_inventory'], 1); ?></td>
                            <td>$<?php echo number_format($wr['results']['totals']['in_fees'], 2); ?></td>
                            <td>$<?php echo number_format($wr['results']['totals']['out_fees'], 2); ?></td>
                            <td>$<?php echo number_format($wr['results']['totals']['storage_fees'], 2); ?></td>
                            <td><strong>$<?php echo number_format($wr['results']['totals']['total'], 2); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<script>
// Chart data from PHP
const chartLabels = <?php echo json_encode($chart_labels); ?>;
const chartDatasets = <?php echo json_encode($chart_datasets); ?>;
const warehouseResults = <?php echo json_encode($warehouse_results); ?>;

// Initialize charts
document.addEventListener('DOMContentLoaded', function() {
    initTrendChart();
    initBreakdownChart();
});

function initTrendChart() {
    const ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: chartDatasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

function initBreakdownChart() {
    const ctx = document.getElementById('breakdownChart').getContext('2d');

    const labels = warehouseResults.map(wr => wr.warehouse.name);
    const inFees = warehouseResults.map(wr => wr.results.totals.in_fees);
    const outFees = warehouseResults.map(wr => wr.results.totals.out_fees);
    const storageFees = warehouseResults.map(wr => wr.results.totals.storage_fees);

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'In Fees',
                    data: inFees,
                    backgroundColor: '#488C9A'
                },
                {
                    label: 'Out Fees',
                    data: outFees,
                    backgroundColor: '#293E4C'
                },
                {
                    label: 'Storage Fees',
                    data: storageFees,
                    backgroundColor: '#fbb040'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                }
            },
            scales: {
                x: {
                    stacked: true
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

function switchDetailTab(index) {
    document.querySelectorAll('.warehouse-tab').forEach((tab, i) => {
        tab.classList.toggle('active', i === index);
    });
    document.querySelectorAll('.detail-content').forEach((content, i) => {
        content.style.display = i === index ? 'block' : 'none';
    });
}
</script>
</body>
</html>
