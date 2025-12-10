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

// Handle saving estimate
if (isset($_POST['save_estimate']) && $_POST['save_estimate'] == '1') {
    $estimate_name = trim($_POST['estimate_name']);
    $calculator_data = $_POST['calculator_data_json'];
    $results_data = $_POST['results_data_json'];

    // Combine calculator data with results
    $full_data = json_decode($calculator_data, true);
    $full_data['results'] = json_decode($results_data, true);
    $full_data_json = json_encode($full_data);

    // Check if updating existing estimate
    if (isset($_POST['edit_id']) && $_POST['edit_id'] > 0) {
        $edit_id = intval($_POST['edit_id']);
        $stmt = $conn->prepare("UPDATE warehouse_estimates SET name = ?, estimate_data = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ssii", $estimate_name, $full_data_json, $edit_id, $user_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO warehouse_estimates (user_id, name, estimate_data, app_type, created_at) VALUES (?, ?, ?, 'calculator', NOW())");
        $stmt->bind_param("iss", $user_id, $estimate_name, $full_data_json);
    }

    if ($stmt->execute()) {
        $success_message = "Estimate saved successfully!";
        $saved_estimate_id = isset($edit_id) ? $edit_id : $conn->insert_id;
    } else {
        $error_message = "Error saving estimate: " . $stmt->error;
    }
    $stmt->close();
}

// Get calculator data from POST
$calculator_data = null;
$results = null;

if (isset($_POST['calculator_data'])) {
    $calculator_data = json_decode($_POST['calculator_data'], true);
} elseif (isset($_POST['calculator_data_json'])) {
    // Re-submitted from save form
    $calculator_data = json_decode($_POST['calculator_data_json'], true);
}

if (!$calculator_data || empty($calculator_data['warehouses'])) {
    // Redirect back if no data
    header("Location: cost_estimate_calculator.php");
    exit();
}

// Calculate costs for each warehouse
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

    $current_inventory = 0; // Inventory starts at 0, pallets are added via schedule
    $inventory_sum = 0;

    // Categorize fees by trigger, storing full fee info for unit calculations
    $in_fees_config = [];
    $out_fees_config = [];
    $storage_fees_config = [];
    $other_fees = [];

    foreach ($warehouse['fees'] as $fee) {
        if ($fee['trigger'] === 'on_entry') {
            $in_fees_config[] = $fee;
        } elseif ($fee['trigger'] === 'on_exit') {
            $out_fees_config[] = $fee;
        } elseif ($fee['trigger'] === 'monthly') {
            $storage_fees_config[] = $fee;
        } else {
            $other_fees[] = $fee;
        }
    }

    // Helper function to calculate fee based on unit type
    $calculateFeeAmount = function($fee, $pallets) {
        $unit = $fee['unit'] ?? 'per_pallet';
        $amount = $fee['amount'] ?? 0;

        switch ($unit) {
            case 'per_pallet':
                return $amount * $pallets;

            case 'per_truck':
                $palletsPerTruck = $fee['palletsPerTruck'] ?? 26;
                if ($palletsPerTruck > 0 && $pallets > 0) {
                    $trucks = ceil($pallets / $palletsPerTruck);
                    return $amount * $trucks;
                }
                return 0;

            case 'per_sqft':
                $sqftPerPallet = $fee['sqftPerPallet'] ?? 13.33;
                $totalSqft = $pallets * $sqftPerPallet;
                return $amount * $totalSqft;

            case 'flat':
                return $pallets > 0 ? $amount : 0; // Only charge if there's activity

            default:
                return $amount * $pallets;
        }
    };

    // Calculate for each month
    foreach ($months as $index => $month) {
        $pallets_in = 0;
        $pallets_out = 0;

        if ($mode === 'simple') {
            // Distribute evenly across months
            $total_in = $warehouse['simpleTotals']['in'] ?? 0;
            $total_out = $warehouse['simpleTotals']['out'] ?? 0;
            $num_months = count($months);

            // Put all in at start, take out evenly at end
            if ($index === 0) {
                $pallets_in = $total_in;
            }
            // Distribute out evenly across last half of months
            $out_start = floor($num_months / 2);
            if ($index >= $out_start && $total_out > 0) {
                $remaining_months = $num_months - $out_start;
                $pallets_out = ceil($total_out / $remaining_months);
                // Don't exceed total
                $already_out = 0;
                for ($i = $out_start; $i < $index; $i++) {
                    $already_out += ceil($total_out / $remaining_months);
                }
                $pallets_out = min($pallets_out, $total_out - $already_out);
            }
        } else {
            // Detailed mode - use specific monthly data
            $pallets_in = $warehouse['movements'][$month]['in'] ?? 0;
            $pallets_out = $warehouse['movements'][$month]['out'] ?? 0;
        }

        // Calculate inventory
        $start_inventory = $current_inventory;
        $current_inventory = max(0, $current_inventory + $pallets_in - $pallets_out);
        $end_inventory = $current_inventory;

        // Calculate fees using unit-aware calculation
        $in_fees = 0;
        foreach ($in_fees_config as $fee) {
            $in_fees += $calculateFeeAmount($fee, $pallets_in);
        }

        $out_fees = 0;
        foreach ($out_fees_config as $fee) {
            $out_fees += $calculateFeeAmount($fee, $pallets_out);
        }

        $storage_fees = 0;
        foreach ($storage_fees_config as $fee) {
            $storage_fees += $calculateFeeAmount($fee, $end_inventory);
        }

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

        // Update totals
        $results['totals']['in_fees'] += $in_fees;
        $results['totals']['out_fees'] += $out_fees;
        $results['totals']['storage_fees'] += $storage_fees;
        $results['totals']['total'] += $monthly_total;

        // Update summary
        $results['summary']['total_pallets_in'] += $pallets_in;
        $results['summary']['total_pallets_out'] += $pallets_out;
        $results['summary']['peak_inventory'] = max($results['summary']['peak_inventory'], $end_inventory);
        $inventory_sum += $end_inventory;
    }

    // Calculate one-time fees
    foreach ($other_fees as $fee) {
        if ($fee['trigger'] === 'one_time') {
            $unit = $fee['unit'] ?? 'flat';
            if ($unit === 'flat') {
                $results['totals']['other_fees'] += $fee['amount'];
                $results['totals']['total'] += $fee['amount'];
            }
        }
    }

    // Average inventory
    if (count($months) > 0) {
        $results['summary']['avg_inventory'] = round($inventory_sum / count($months), 1);
    }

    return $results;
}

// Calculate results for all warehouses
$warehouse_results = [];
$overall_totals = [
    'in_fees' => 0,
    'out_fees' => 0,
    'storage_fees' => 0,
    'other_fees' => 0,
    'total' => 0
];

foreach ($calculator_data['warehouses'] as $index => $warehouse) {
    $wh_results = calculateWarehouseCosts($warehouse, $calculator_data['schedule']);
    $warehouse_results[] = [
        'warehouse' => $warehouse,
        'results' => $wh_results
    ];

    $overall_totals['in_fees'] += $wh_results['totals']['in_fees'];
    $overall_totals['out_fees'] += $wh_results['totals']['out_fees'];
    $overall_totals['storage_fees'] += $wh_results['totals']['storage_fees'];
    $overall_totals['other_fees'] += $wh_results['totals']['other_fees'];
    $overall_totals['total'] += $wh_results['totals']['total'];
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

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Estimate Results</title>
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

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
            display: flex;
            align-items: center;
            gap: 6px;
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
        .view-details-btn {
            width: 100%;
            margin-top: 16px;
            padding: 12px;
            background: none;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            color: #488C9A;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .view-details-btn:hover {
            background: #488C9A;
            color: #fff;
            border-color: #488C9A;
        }

        /* Charts Section - prefixed to avoid portal.css conflicts */
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
        .calc-chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            max-width: none;
            margin: 0 auto 24px auto;
        }
        .calc-chart-container canvas {
            width: 100% !important;
            height: 100% !important;
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

        /* Delay Simulator */
        .simulator-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 1px solid #e9ecef;
            margin-bottom: 32px;
        }
        .simulator-section h2 {
            margin: 0 0 8px 0;
            font-size: 1.4rem;
            color: #293E4C;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .simulator-section .subtitle {
            color: #6c757d;
            font-size: 0.95rem;
            margin-bottom: 24px;
        }
        .simulator-controls {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .slider-container {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #e9ecef;
        }
        .slider-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .slider-label {
            font-weight: 500;
            color: #293E4C;
            font-size: 1rem;
        }
        .delay-value-display {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .delay-value-display span {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .delay-input {
            width: 80px;
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            text-align: center;
            color: #293E4C;
            transition: border-color 0.2s;
        }
        .delay-input:focus {
            outline: none;
            border-color: #488C9A;
        }
        .delay-input.pull-in {
            border-color: #28a745;
            color: #28a745;
        }
        .delay-input.delay {
            border-color: #dc3545;
            color: #dc3545;
        }
        .slider-track-container {
            position: relative;
            padding: 20px 0;
        }
        .slider-labels {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.8rem;
            color: #6c757d;
        }
        .slider-track {
            position: relative;
            height: 12px;
            background: linear-gradient(90deg, #28a745 0%, #28a745 50%, #dc3545 50%, #dc3545 100%);
            border-radius: 6px;
            cursor: pointer;
        }
        .slider-track::before {
            content: '';
            position: absolute;
            left: 50%;
            top: -6px;
            bottom: -6px;
            width: 2px;
            background: #293E4C;
            transform: translateX(-50%);
            z-index: 1;
        }
        .slider-thumb {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            cursor: grab;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4);
            transition: box-shadow 0.2s, transform 0.1s;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .slider-thumb:hover {
            box-shadow: 0 6px 16px rgba(72, 140, 154, 0.5);
        }
        .slider-thumb:active {
            cursor: grabbing;
            transform: translate(-50%, -50%) scale(1.1);
        }
        .slider-thumb::after {
            content: '⟷';
            color: #fff;
            font-size: 14px;
            font-weight: bold;
        }
        .slider-ticks {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            padding: 0 14px;
        }
        .slider-tick {
            font-size: 0.75rem;
            color: #999;
            cursor: pointer;
            transition: color 0.2s;
        }
        .slider-tick:hover {
            color: #488C9A;
        }
        .simulator-results {
            margin-top: 24px;
            display: none;
        }
        .simulator-results.active {
            display: block;
        }
        .sim-comparison-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        .sim-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .sim-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        .sim-card h4 {
            margin: 0 0 8px 0;
            font-size: 0.95rem;
            color: #6c757d;
        }
        .sim-card .sim-original {
            font-size: 0.85rem;
            color: #999;
            text-decoration: line-through;
            margin-bottom: 4px;
        }
        .sim-card .sim-total {
            font-size: 1.6rem;
            font-weight: 700;
            color: #293E4C;
        }
        .sim-card .sim-diff {
            font-size: 0.9rem;
            margin-top: 6px;
            font-weight: 500;
        }
        .sim-card .sim-diff.positive {
            color: #dc3545;
        }
        .sim-card .sim-diff.negative {
            color: #28a745;
        }

        /* Save Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: #fff;
            border-radius: 20px;
            width: 90%;
            max-width: 450px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .modal-header {
            padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: #293E4C;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #6c757d;
            cursor: pointer;
        }
        .modal-body {
            padding: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 500;
            color: #293E4C;
            margin-bottom: 8px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
        }
        .form-group input:focus {
            outline: none;
            border-color: #488C9A;
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
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
        'current_label' => 'Cost Estimate Results',
        'extra' => [['label' => 'Warehouse Cost Calculator', 'url' => 'cost_estimate_calculator.php']]
    ]);
    ?>

    <!-- Modern Page Header -->
    <div class="results-header">
        <div class="results-header-content">
            <div>
                <h1>Cost Estimate Results</h1>
                <p class="subtitle">
                    <?php echo count($warehouse_results); ?> warehouse<?php echo count($warehouse_results) > 1 ? 's' : ''; ?> compared
                    &bull; <?php echo count($calculator_data['schedule']['months']); ?> months
                </p>
            </div>
            <div class="header-actions">
                <form method="POST" action="cost_estimate_calculator.php" style="display: inline;">
                    <input type="hidden" name="edit_data" value='<?php echo htmlspecialchars(json_encode($calculator_data)); ?>'>
                    <button type="submit" class="btn-action btn-secondary">
                        ← Edit Estimate
                    </button>
                </form>
                <button type="button" class="btn-action btn-primary" onclick="openSaveModal()">
                    💾 Save Estimate
                </button>
                <form method="POST" action="generate_estimate_pdf.php" style="display: inline;" target="_blank">
                    <input type="hidden" name="calculator_data" value='<?php echo htmlspecialchars(json_encode($calculator_data)); ?>'>
                    <input type="hidden" name="results_data" value='<?php echo htmlspecialchars(json_encode($warehouse_results)); ?>'>
                    <button type="submit" class="btn-action btn-success">
                        📄 Export PDF
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            ✓ <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($error_message)): ?>
        <div class="alert alert-error">
            ✕ <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- Comparison Cards -->
    <div class="comparison-grid">
        <?php foreach ($warehouse_results as $index => $wr): ?>
            <?php
            $is_best = ($index === $best_warehouse_index && count($warehouse_results) > 1);
            $savings = $overall_totals['total'] > 0 ?
                ($warehouse_results[$best_warehouse_index !== $index ? $best_warehouse_index : 0]['results']['totals']['total'] - $wr['results']['totals']['total']) : 0;
            ?>
            <div class="warehouse-result-card <?php echo $is_best ? 'best-value' : ''; ?>">
                <?php if ($is_best): ?>
                    <div class="best-value-badge">⭐ Best Value</div>
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

                <button type="button" class="view-details-btn" onclick="showWarehouseDetails(<?php echo $index; ?>)">
                    View Monthly Details
                </button>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <h2>📈 Cost Analysis</h2>
        <div class="chart-row">
            <div>
                <h4 style="margin: 0 0 16px 0; color: #6c757d; font-weight: 500;">Monthly Cost Trend</h4>
                <div class="calc-chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            <div>
                <h4 style="margin: 0 0 16px 0; color: #6c757d; font-weight: 500;">Cost Breakdown</h4>
                <div class="calc-chart-container">
                    <canvas id="breakdownChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Breakdown -->
    <div class="details-section">
        <h2>📋 Detailed Monthly Breakdown</h2>

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

    <!-- Delay Simulator -->
    <div class="simulator-section">
        <h2>🔄 Schedule Delay Simulator</h2>
        <p class="subtitle">Drag the slider or enter a value to see how schedule changes affect your warehouse costs</p>

        <div class="simulator-controls">
            <div class="slider-container">
                <div class="slider-header">
                    <span class="slider-label">Schedule Adjustment</span>
                    <div class="delay-value-display">
                        <input type="number" id="delayInput" class="delay-input" value="0" min="-12" max="12" onchange="updateFromInput()">
                        <span>months</span>
                    </div>
                </div>
                <div class="slider-labels">
                    <span>← Pull In (Earlier)</span>
                    <span>Original</span>
                    <span>Delay (Later) →</span>
                </div>
                <div class="slider-track-container">
                    <div class="slider-track" id="sliderTrack">
                        <div class="slider-thumb" id="sliderThumb"></div>
                    </div>
                </div>
                <div class="slider-ticks">
                    <span class="slider-tick" onclick="setDelay(-12)">-12</span>
                    <span class="slider-tick" onclick="setDelay(-9)">-9</span>
                    <span class="slider-tick" onclick="setDelay(-6)">-6</span>
                    <span class="slider-tick" onclick="setDelay(-3)">-3</span>
                    <span class="slider-tick" onclick="setDelay(0)" style="font-weight: bold; color: #293E4C;">0</span>
                    <span class="slider-tick" onclick="setDelay(3)">+3</span>
                    <span class="slider-tick" onclick="setDelay(6)">+6</span>
                    <span class="slider-tick" onclick="setDelay(9)">+9</span>
                    <span class="slider-tick" onclick="setDelay(12)">+12</span>
                </div>
            </div>
        </div>

        <div class="simulator-results" id="simulatorResults">
            <div class="sim-comparison-grid" id="simComparisonGrid">
                <!-- Populated by JavaScript -->
            </div>
        </div>
    </div>
</main>

<!-- Save Modal -->
<div class="modal-overlay" id="saveModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>💾 Save Estimate</h3>
            <button type="button" class="modal-close" onclick="closeSaveModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="calculator_results.php" id="saveForm">
                <input type="hidden" name="save_estimate" value="1">
                <input type="hidden" name="calculator_data_json" value='<?php echo htmlspecialchars(json_encode($calculator_data)); ?>'>
                <input type="hidden" name="results_data_json" value='<?php echo htmlspecialchars(json_encode($warehouse_results)); ?>'>
                <?php if (isset($_POST['edit_id'])): ?>
                    <input type="hidden" name="edit_id" value="<?php echo intval($_POST['edit_id']); ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="estimate_name">Estimate Name</label>
                    <input type="text" name="estimate_name" id="estimate_name" required
                           placeholder="e.g., Phoenix vs Dallas Comparison"
                           value="<?php echo isset($_POST['edit_name']) ? htmlspecialchars($_POST['edit_name']) : ''; ?>">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-action btn-secondary" onclick="closeSaveModal()">Cancel</button>
                    <button type="submit" class="btn-action btn-success">Save Estimate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Chart data from PHP
const chartLabels = <?php echo json_encode($chart_labels); ?>;
const chartDatasets = <?php echo json_encode($chart_datasets); ?>;
const warehouseResults = <?php echo json_encode($warehouse_results); ?>;
const calculatorData = <?php echo json_encode($calculator_data); ?>;

// Initialize charts
document.addEventListener('DOMContentLoaded', function() {
    initTrendChart();
    initBreakdownChart();
    initDelaySimulator();
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

    // Prepare data for stacked bar chart
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

// Detail tabs
function switchDetailTab(index) {
    document.querySelectorAll('.warehouse-tab').forEach((tab, i) => {
        tab.classList.toggle('active', i === index);
    });
    document.querySelectorAll('.detail-content').forEach((content, i) => {
        content.style.display = i === index ? 'block' : 'none';
    });
}

function showWarehouseDetails(index) {
    switchDetailTab(index);
    document.querySelector('.details-section').scrollIntoView({ behavior: 'smooth' });
}

// Delay simulator with draggable slider
let currentDelay = 0;
let isDragging = false;

function initDelaySimulator() {
    const track = document.getElementById('sliderTrack');
    const thumb = document.getElementById('sliderThumb');

    // Mouse events
    thumb.addEventListener('mousedown', startDrag);
    document.addEventListener('mousemove', drag);
    document.addEventListener('mouseup', endDrag);

    // Touch events
    thumb.addEventListener('touchstart', startDrag);
    document.addEventListener('touchmove', drag);
    document.addEventListener('touchend', endDrag);

    // Click on track to set position
    track.addEventListener('click', function(e) {
        if (e.target === thumb) return;
        const rect = track.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const percentage = x / rect.width;
        const delay = Math.round((percentage - 0.5) * 24); // -12 to +12 range
        setDelay(Math.max(-12, Math.min(12, delay)));
    });
}

function startDrag(e) {
    isDragging = true;
    e.preventDefault();
}

function drag(e) {
    if (!isDragging) return;

    const track = document.getElementById('sliderTrack');
    const rect = track.getBoundingClientRect();
    const clientX = e.touches ? e.touches[0].clientX : e.clientX;
    let x = clientX - rect.left;

    // Clamp to track bounds
    x = Math.max(0, Math.min(rect.width, x));

    const percentage = x / rect.width;
    const delay = Math.round((percentage - 0.5) * 24); // -12 to +12 range
    setDelay(Math.max(-12, Math.min(12, delay)));
}

function endDrag() {
    isDragging = false;
}

function setDelay(months) {
    currentDelay = months;

    // Update thumb position
    const thumb = document.getElementById('sliderThumb');
    const percentage = ((months + 12) / 24) * 100;
    thumb.style.left = `${percentage}%`;

    // Update input field
    const input = document.getElementById('delayInput');
    input.value = months;

    // Update input styling
    input.classList.remove('pull-in', 'delay');
    if (months < 0) {
        input.classList.add('pull-in');
    } else if (months > 0) {
        input.classList.add('delay');
    }

    // Show/hide results
    if (months === 0) {
        document.getElementById('simulatorResults').classList.remove('active');
    } else {
        simulateDelay(months);
    }
}

function updateFromInput() {
    const input = document.getElementById('delayInput');
    let value = parseInt(input.value) || 0;

    // Allow values beyond slider range but still show results
    setDelay(Math.max(-12, Math.min(12, value)));

    // If manually entered value is beyond slider range, still simulate
    if (Math.abs(value) > 12) {
        simulateDelay(value);
        input.value = value;
    }
}

function simulateDelay(delayMonths) {
    const resultsContainer = document.getElementById('simComparisonGrid');
    const originalTotals = warehouseResults.map(wr => wr.results.totals.total);

    // Simple simulation: add storage costs for delay months
    let html = '';
    warehouseResults.forEach((wr, index) => {
        const originalTotal = originalTotals[index];

        // Calculate additional storage cost per month
        const avgInventory = wr.results.summary.avg_inventory;
        let monthlyStorageRate = 0;
        wr.warehouse.fees.forEach(fee => {
            if (fee.trigger === 'monthly') {
                monthlyStorageRate += fee.amount;
            }
        });

        const additionalCost = monthlyStorageRate * avgInventory * Math.abs(delayMonths);
        const newTotal = delayMonths > 0 ? originalTotal + additionalCost : Math.max(0, originalTotal - additionalCost);
        const diff = newTotal - originalTotal;

        const delayLabel = delayMonths > 0 ? `+${delayMonths} month delay` : `${delayMonths} month pull-in`;

        html += `
            <div class="sim-card">
                <h4>${escapeHtml(wr.warehouse.name)}</h4>
                <div class="sim-original">Was: $${originalTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                <div class="sim-total">$${newTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                <div class="sim-diff ${diff >= 0 ? 'positive' : 'negative'}">
                    ${diff >= 0 ? '+' : ''}$${diff.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                </div>
            </div>
        `;
    });

    resultsContainer.innerHTML = html;
    document.getElementById('simulatorResults').classList.add('active');
}

// Modal functions
function openSaveModal() {
    document.getElementById('saveModal').classList.add('active');
    document.getElementById('estimate_name').focus();
}

function closeSaveModal() {
    document.getElementById('saveModal').classList.remove('active');
}

document.getElementById('saveModal').addEventListener('click', function(e) {
    if (e.target === this) closeSaveModal();
});

// Utility
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>
