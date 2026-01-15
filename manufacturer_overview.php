<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

require_once '../config.php';
require_once 'cost_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// If a specific manufacturer is requested, redirect to details page
if (isset($_GET['manufacturer']) && !empty(trim($_GET['manufacturer']))) {
    header("Location: manufacturer_details?manufacturer=" . urlencode(trim($_GET['manufacturer'])));
    exit();
}

// Get projects based on user role
if ($role === 'global_admin') {
    $project_access_sql = "SELECT id FROM projects WHERE status IS NULL OR status = 'active'";
    $access_params = [];
    $access_types = "";
} else {
    $project_access_sql = "SELECT p.id FROM projects p JOIN customer_account_users cau ON p.account_id = cau.account_id WHERE cau.user_id = ? AND (p.status IS NULL OR p.status = 'active')";
    $access_params = [$user_id];
    $access_types = "i";
}

// Get all manufacturers data
$manufacturers_data = [];

// Query to get manufacturer aggregates
$mfr_sql = "
    SELECT
        m.vendor_name,
        SUM(umi.wattage * umi.quantity) as total_watts,
        SUM(CASE WHEN m.cost_per_watt IS NOT NULL THEN m.cost_per_watt * umi.wattage * umi.quantity ELSE 0 END) as total_cost,
        COUNT(DISTINCT m.project_id) as project_count
    FROM modules m
    JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
    WHERE m.project_id IN ($project_access_sql)
    GROUP BY m.vendor_name
    ORDER BY total_cost DESC
";

$stmt = $conn->prepare($mfr_sql);
if (!empty($access_types)) {
    $stmt->bind_param($access_types, ...$access_params);
}
$stmt->execute();
$mfr_result = $stmt->get_result();

while ($row = $mfr_result->fetch_assoc()) {
    $vendor = $row['vendor_name'] ?? 'Unknown';
    if (empty($vendor)) $vendor = 'Unknown';

    $manufacturers_data[$vendor] = [
        'vendor_name' => $vendor,
        'total_watts' => (float)$row['total_watts'],
        'total_cost' => (float)$row['total_cost'],
        'project_count' => (int)$row['project_count'],
        'avg_cost_per_watt' => $row['total_watts'] > 0 ? $row['total_cost'] / $row['total_watts'] : 0,
        'projects_on_time' => 0,
        'projects_late' => 0,
        'projects_pending' => 0,
        'damaged_modules' => 0
    ];
}
$stmt->close();

// Calculate project completion per manufacturer (projects where all deliveries completed before estimated_completion_date)
$completion_sql = "
    SELECT
        m.vendor_name,
        p.id as project_id,
        p.project_name,
        p.estimated_completion_date,
        MAX(d.actual_delivery_date) as last_delivery_date,
        COUNT(DISTINCT d.id) as total_deliveries,
        COUNT(DISTINCT CASE WHEN d.actual_delivery_date IS NOT NULL THEN d.id END) as completed_deliveries
    FROM modules m
    JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
    JOIN inventory_pallets ip ON ip.unassigned_module_item_id = umi.id
    JOIN delivery_pallets dp ON dp.inventory_pallet_id = ip.id
    JOIN deliveries d ON d.id = dp.delivery_id
    JOIN projects p ON p.id = m.project_id
    WHERE m.vendor_name IS NOT NULL AND m.vendor_name != ''
    AND p.id IN ($project_access_sql)
    GROUP BY m.vendor_name, p.id, p.project_name, p.estimated_completion_date
";
$stmt_completion = $conn->prepare($completion_sql);
if (!empty($access_types)) {
    $stmt_completion->bind_param($access_types, ...$access_params);
}
$stmt_completion->execute();
$completion_result = $stmt_completion->get_result();
while ($row = $completion_result->fetch_assoc()) {
    $mfr = $row['vendor_name'];
    if (isset($manufacturers_data[$mfr])) {
        // Check if all deliveries are completed
        if ($row['completed_deliveries'] == $row['total_deliveries'] && $row['total_deliveries'] > 0) {
            // Check if completed before deadline
            if (!empty($row['estimated_completion_date']) && !empty($row['last_delivery_date'])) {
                if (strtotime($row['last_delivery_date']) <= strtotime($row['estimated_completion_date'])) {
                    $manufacturers_data[$mfr]['projects_on_time']++;
                } else {
                    $manufacturers_data[$mfr]['projects_late']++;
                }
            } else {
                // No deadline set, count as on-time if completed
                $manufacturers_data[$mfr]['projects_on_time']++;
            }
        } else {
            $manufacturers_data[$mfr]['projects_pending']++;
        }
    }
}
$stmt_completion->close();

// Calculate damaged modules per manufacturer from warranty claims (sum reported damaged quantity)
$damage_sql = "
    SELECT
        TRIM(
            CASE
                WHEN d.supplier LIKE '%-%' THEN SUBSTRING_INDEX(d.supplier, '-', 1)
                ELSE d.supplier
            END
        ) as manufacturer,
        COALESCE(SUM(COALESCE(w.damaged_quantity, 0)), 0) as damaged_count
    FROM warranty_claims w
    JOIN site_scheduling ss ON ss.id = w.scheduling_id
    JOIN deliveries d ON d.id = ss.delivery_id
    WHERE d.supplier IS NOT NULL AND d.supplier != ''
    AND w.issue_type IN ('damaged', 'both')
    AND ss.project_id IN ($project_access_sql)
    GROUP BY manufacturer
";
$stmt_damage = $conn->prepare($damage_sql);
if (!empty($access_types)) {
    $stmt_damage->bind_param($access_types, ...$access_params);
}
$stmt_damage->execute();
$damage_result = $stmt_damage->get_result();
while ($row = $damage_result->fetch_assoc()) {
    $mfr = $row['manufacturer'];
    if (isset($manufacturers_data[$mfr])) {
        $manufacturers_data[$mfr]['damaged_modules'] = (int)$row['damaged_count'];
    }
}
$stmt_damage->close();

// Calculate totals across all manufacturers
$total_all_cost = array_sum(array_column($manufacturers_data, 'total_cost'));
$total_all_watts = array_sum(array_column($manufacturers_data, 'total_watts'));
$manufacturer_count = count($manufacturers_data);

// Calculate overall project completion performance
$total_projects_on_time = array_sum(array_column($manufacturers_data, 'projects_on_time'));
$total_projects_late = array_sum(array_column($manufacturers_data, 'projects_late'));
$total_projects_pending = array_sum(array_column($manufacturers_data, 'projects_pending'));
$total_projects_completed = $total_projects_on_time + $total_projects_late;
$total_damaged = array_sum(array_column($manufacturers_data, 'damaged_modules'));
$overall_on_time_rate = $total_projects_completed > 0 ? round(($total_projects_on_time / $total_projects_completed) * 100) : null;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manufacturer Overview</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }

        .page-header {
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
        }
        .page-header p { color: #6c757d; font-size: 1.1em; margin: 0; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
        .stat-card.primary { background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%); border: none; }
        .stat-card.primary .stat-label, .stat-card.primary .stat-value { color: #fff; }
        .stat-card-clickable { cursor: pointer; position: relative; }
        .stat-card-clickable::after {
            content: 'Click for breakdown';
            position: absolute;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.65em;
            color: #488C9A;
            opacity: 0;
            transition: opacity 0.2s;
            white-space: nowrap;
        }
        .stat-card-clickable:hover::after { opacity: 1; }
        .stat-card.primary.stat-card-clickable::after { color: rgba(255,255,255,0.8); }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.5em;
        }
        .stat-card.primary .stat-icon { background: rgba(255,255,255,0.2); color: #fff; }
        .stat-icon.blue { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #2563eb; }
        .stat-icon.purple { background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); color: #7c3aed; }
        .stat-icon.green { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #059669; }
        .stat-icon.orange { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #fbb040; }
        .stat-icon.red { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #E4572E; }
        .stat-icon.red-orange { background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%); color: #ea580c; }
        .stat-value { font-size: 1.8em; font-weight: 700; color: #293E4C; margin-bottom: 4px; }
        .stat-label { color: #6c757d; font-size: 0.85em; font-weight: 500; }
        .stat-value.good { color: #059669; }
        .stat-value.warning { color: #fbb040; }
        .stat-value.poor { color: #ea580c; }

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 40px;
        }
        .chart-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }
        .chart-card h3 {
            font-size: 1.2em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chart-card h3 i { color: #488C9A; }
        .chart-container {
            position: relative;
            height: 280px;
        }

        /* Manufacturer Grid */
        .manufacturer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }
        .manufacturer-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }
        .manufacturer-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(72,140,154,0.15);
        }
        .manufacturer-card-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(45deg, rgba(72,140,154,0.95), rgba(41,62,76,0.95));
            display: flex; align-items: center; justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            border-radius: 16px;
        }
        .manufacturer-card:hover .manufacturer-card-overlay { opacity: 1; }
        .manufacturer-card-overlay span { color: #fff; font-size: 1em; font-weight: 600; }
        .manufacturer-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f3f4;
            gap: 12px;
        }
        .manufacturer-name {
            font-size: 1.2em;
            font-weight: 600;
            color: #293E4C;
            flex: 1;
            min-width: 0;
        }
        .manufacturer-badge {
            background: #488C9A;
            color: #fff;
            font-size: 0.75em;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .manufacturer-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .manufacturer-stat {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .manufacturer-stat .value {
            font-size: 1.1em;
            font-weight: 700;
            color: #293E4C;
        }
        .manufacturer-stat .value.good { color: #059669; }
        .manufacturer-stat .value.warning { color: #fbb040; }
        .manufacturer-stat .value.poor { color: #ea580c; }
        .manufacturer-stat .label {
            font-size: 0.75em;
            color: #6c757d;
            margin-top: 2px;
        }

        /* Modal Styles */
        .breakdown-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            overflow: auto;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }
        .breakdown-modal-content {
            background: white;
            margin: 8% auto;
            padding: 0;
            width: 90%;
            max-width: 600px;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            animation: modalSlideIn 0.3s ease;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .breakdown-modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            color: white;
            padding: 24px;
            border-radius: 20px 20px 0 0;
            position: relative;
            flex-shrink: 0;
        }
        .breakdown-modal-header h2 {
            margin: 0;
            font-size: 1.4em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .breakdown-modal-close {
            position: absolute;
            top: 20px;
            right: 24px;
            font-size: 28px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            transition: transform 0.2s ease;
            border: none;
            background: transparent;
        }
        .breakdown-modal-close:hover { transform: scale(1.1); }
        .breakdown-modal-body {
            padding: 24px;
            overflow-y: auto;
        }
        .breakdown-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: background 0.2s;
        }
        .breakdown-item:hover { background: #e9ecef; }
        .breakdown-item-name {
            font-weight: 500;
            color: #293E4C;
        }
        .breakdown-item-value {
            font-weight: 700;
            color: #488C9A;
        }
        .breakdown-item-value.good { color: #059669; }
        .breakdown-item-value.poor { color: #E4572E; }
        .breakdown-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: linear-gradient(135deg, #e8f4f6 0%, #d1e8ec 100%);
            border-radius: 12px;
            margin-top: 16px;
            border: 1px solid rgba(72, 140, 154, 0.2);
        }
        .breakdown-total-label {
            font-weight: 600;
            color: #293E4C;
        }
        .breakdown-total-value {
            font-size: 1.3em;
            font-weight: 700;
            color: #488C9A;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state h3 { color: #293E4C; margin-bottom: 8px; }

        @media (max-width: 1400px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-section { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-header { padding: 24px; }
            .page-header h1 { font-size: 1.8em; }
            .stats-grid { grid-template-columns: 1fr; }
            .manufacturer-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
    require_once 'components/breadcrumbs.php';
    $breadcrumb_ref_map = [
        'module_cost_analysis.php' => ['label' => 'Module Cost Analysis', 'url' => 'module_cost_analysis.php'],
        'module_cost_analysis' => ['label' => 'Module Cost Analysis', 'url' => 'module_cost_analysis.php']
    ];
    echo slp_render_breadcrumbs([
        'current_label' => 'Manufacturer Overview',
        'ref_map' => $breadcrumb_ref_map
    ]);
    ?>

    <div class="page-header">
        <h1>Manufacturer Overview</h1>
        <p>View all module manufacturers across your portfolio with delivery performance metrics</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card primary stat-card-clickable" onclick="openCostModal()">
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-value">$<?php echo number_format($total_all_cost, 0); ?></div>
            <div class="stat-label">Total Module Cost</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openWattageModal()">
            <div class="stat-icon blue"><i class="fas fa-bolt"></i></div>
            <div class="stat-value"><?php echo number_format($total_all_watts / 1000000, 2); ?> MW</div>
            <div class="stat-label">Total Wattage</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="scrollToManufacturers()">
            <div class="stat-icon purple"><i class="fas fa-industry"></i></div>
            <div class="stat-value"><?php echo $manufacturer_count; ?></div>
            <div class="stat-label">Manufacturers</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openCompletionModal()">
            <div class="stat-icon <?php echo $overall_on_time_rate === null ? 'orange' : ($overall_on_time_rate >= 90 ? 'green' : ($overall_on_time_rate >= 70 ? 'orange' : 'red-orange')); ?>"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-value <?php echo $overall_on_time_rate === null ? '' : ($overall_on_time_rate >= 90 ? 'good' : ($overall_on_time_rate >= 70 ? 'warning' : 'poor')); ?>">
                <?php echo $overall_on_time_rate !== null ? $overall_on_time_rate . '%' : 'N/A'; ?>
            </div>
            <div class="stat-label">Projects On-Time</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openDamagedModal()">
            <div class="stat-icon <?php echo $total_damaged === 0 ? 'green' : 'red'; ?>"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-value <?php echo $total_damaged === 0 ? 'good' : 'poor'; ?>"><?php echo number_format($total_damaged); ?></div>
            <div class="stat-label">Damaged Modules</div>
        </div>
    </div>

    <!-- Cost Breakdown Modal -->
    <div id="costModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header">
                <h2><i class="fas fa-dollar-sign"></i> Module Cost Breakdown</h2>
                <button class="breakdown-modal-close" onclick="closeCostModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <div class="breakdown-list">
                    <?php foreach ($manufacturers_data as $mfr => $data): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($mfr); ?></span>
                        <span class="breakdown-item-value">$<?php echo number_format($data['total_cost'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Module Cost</span>
                    <span class="breakdown-total-value">$<?php echo number_format($total_all_cost, 2); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Wattage Breakdown Modal -->
    <div id="wattageModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);">
                <h2><i class="fas fa-bolt"></i> Wattage Breakdown</h2>
                <button class="breakdown-modal-close" onclick="closeWattageModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <div class="breakdown-list">
                    <?php foreach ($manufacturers_data as $mfr => $data): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($mfr); ?></span>
                        <span class="breakdown-item-value"><?php echo number_format($data['total_watts'] / 1000000, 2); ?> MW</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Wattage</span>
                    <span class="breakdown-total-value"><?php echo number_format($total_all_watts / 1000000, 2); ?> MW</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Project Completion Modal -->
    <div id="completionModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header" style="background: linear-gradient(135deg, #059669 0%, #047857 100%);">
                <h2><i class="fas fa-calendar-check"></i> Project Completion by Manufacturer</h2>
                <button class="breakdown-modal-close" onclick="closeCompletionModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <p style="color:#6c757d; margin-bottom:16px; font-size:0.9em;">Shows the percentage of completed projects that finished on or before the estimated completion date.</p>
                <div class="breakdown-list">
                    <?php foreach ($manufacturers_data as $mfr => $data):
                        $completed = $data['projects_on_time'] + $data['projects_late'];
                        $on_time_pct = $completed > 0 ? round(($data['projects_on_time'] / $completed) * 100) : null;
                    ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($mfr); ?></span>
                        <span class="breakdown-item-value <?php echo $on_time_pct === null ? '' : ($on_time_pct >= 90 ? 'good' : ($on_time_pct < 70 ? 'poor' : '')); ?>">
                            <?php if ($on_time_pct !== null): ?>
                                <?php echo $on_time_pct; ?>%
                                <small style="color:#6c757d;font-weight:400;"> (<?php echo $data['projects_on_time']; ?>/<?php echo $completed; ?> on-time)</small>
                            <?php elseif ($data['projects_pending'] > 0): ?>
                                <small style="color:#fbb040;font-weight:500;"><?php echo $data['projects_pending']; ?> in progress</small>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Overall Projects On-Time</span>
                    <span class="breakdown-total-value"><?php echo $overall_on_time_rate !== null ? $overall_on_time_rate . '%' : 'N/A'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Damaged Modules Modal -->
    <div id="damagedModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header" style="background: linear-gradient(135deg, #E4572E 0%, #dc2626 100%);">
                <h2><i class="fas fa-exclamation-triangle"></i> Damaged Modules</h2>
                <button class="breakdown-modal-close" onclick="closeDamagedModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <div class="breakdown-list">
                    <?php foreach ($manufacturers_data as $mfr => $data): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($mfr); ?></span>
                        <span class="breakdown-item-value <?php echo $data['damaged_modules'] === 0 ? 'good' : 'poor'; ?>">
                            <?php echo number_format($data['damaged_modules']); ?> modules
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Damaged Modules</span>
                    <span class="breakdown-total-value"><?php echo number_format($total_damaged); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="chart-card">
            <h3><i class="fas fa-chart-pie"></i> Cost by Manufacturer</h3>
            <div class="chart-container">
                <canvas id="manufacturerCostChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3><i class="fas fa-chart-bar"></i> Wattage by Manufacturer</h3>
            <div class="chart-container">
                <canvas id="manufacturerWattageChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Manufacturer Cards -->
    <?php if (!empty($manufacturers_data)): ?>
    <div class="manufacturer-grid">
        <?php foreach ($manufacturers_data as $mfr => $data):
            $completed = $data['projects_on_time'] + $data['projects_late'];
            $on_time_pct = $completed > 0 ? round(($data['projects_on_time'] / $completed) * 100) : null;
        ?>
        <div class="manufacturer-card" onclick="window.location.href='manufacturer_details?manufacturer=<?php echo urlencode($mfr); ?>'">
            <div class="manufacturer-card-header">
                <span class="manufacturer-name"><?php echo htmlspecialchars($mfr); ?></span>
                <span class="manufacturer-badge"><?php echo $data['project_count']; ?> project<?php echo $data['project_count'] !== 1 ? 's' : ''; ?></span>
            </div>
            <div class="manufacturer-stats">
                <div class="manufacturer-stat">
                    <span class="value">$<?php echo number_format($data['total_cost'], 0); ?></span>
                    <span class="label">Total Cost</span>
                </div>
                <div class="manufacturer-stat">
                    <span class="value"><?php echo number_format($data['total_watts'] / 1000000, 2); ?> MW</span>
                    <span class="label">Wattage</span>
                </div>
                <div class="manufacturer-stat">
                    <span class="value <?php echo $on_time_pct === null ? '' : ($on_time_pct >= 90 ? 'good' : ($on_time_pct >= 70 ? 'warning' : 'poor')); ?>">
                        <?php echo $on_time_pct !== null ? $on_time_pct . '%' : ($data['projects_pending'] > 0 ? '-' : 'N/A'); ?>
                    </span>
                    <span class="label">On-Time</span>
                </div>
                <div class="manufacturer-stat">
                    <span class="value <?php echo $data['damaged_modules'] === 0 ? 'good' : 'poor'; ?>"><?php echo number_format($data['damaged_modules']); ?></span>
                    <span class="label">Damaged</span>
                </div>
            </div>
            <div class="manufacturer-card-overlay">
                <span>View Details</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <h3>No Manufacturers Found</h3>
        <p>No module manufacturer data is available.</p>
    </div>
    <?php endif; ?>
</main>

<script>
// Modal Functions
function openCostModal() { document.getElementById('costModal').style.display = 'block'; }
function closeCostModal() { document.getElementById('costModal').style.display = 'none'; }
function openWattageModal() { document.getElementById('wattageModal').style.display = 'block'; }
function closeWattageModal() { document.getElementById('wattageModal').style.display = 'none'; }
function openCompletionModal() { document.getElementById('completionModal').style.display = 'block'; }
function closeCompletionModal() { document.getElementById('completionModal').style.display = 'none'; }
function openDamagedModal() { document.getElementById('damagedModal').style.display = 'block'; }
function closeDamagedModal() { document.getElementById('damagedModal').style.display = 'none'; }

function scrollToManufacturers() {
    var grid = document.querySelector('.manufacturer-grid');
    if (grid) {
        grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = ['costModal', 'wattageModal', 'completionModal', 'damagedModal'];
    modals.forEach(function(id) {
        const modal = document.getElementById(id);
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($manufacturers_data)): ?>
    const manufacturerData = <?php echo json_encode($manufacturers_data); ?>;
    const manufacturers = Object.keys(manufacturerData);
    const costs = manufacturers.map(m => manufacturerData[m].total_cost);
    const watts = manufacturers.map(m => manufacturerData[m].total_watts / 1000000);

    const colors = [
        '#488C9A', '#293E4C', '#fbb040', '#059669', '#E4572E',
        '#3b82f6', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316'
    ];

    // Cost Pie Chart
    const costCtx = document.getElementById('manufacturerCostChart');
    if (costCtx) {
        new Chart(costCtx, {
            type: 'doughnut',
            data: {
                labels: manufacturers,
                datasets: [{
                    data: costs,
                    backgroundColor: colors.slice(0, manufacturers.length),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { padding: 15, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.raw;
                                const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                                const pct = total > 0 ? ((val/total)*100).toFixed(1) : 0;
                                return `${ctx.label}: $${val.toLocaleString()} (${pct}%)`;
                            }
                        }
                    }
                },
                cutout: '55%'
            }
        });
    }

    // Wattage Bar Chart
    const wattCtx = document.getElementById('manufacturerWattageChart');
    if (wattCtx) {
        new Chart(wattCtx, {
            type: 'bar',
            data: {
                labels: manufacturers,
                datasets: [{
                    label: 'MW',
                    data: watts,
                    backgroundColor: colors.slice(0, manufacturers.length),
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return `${ctx.raw.toFixed(2)} MW`; }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => v + ' MW' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>
</body>
</html>
