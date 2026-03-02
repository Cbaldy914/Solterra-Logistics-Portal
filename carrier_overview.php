<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// If a specific carrier is requested, redirect to details page
if (isset($_GET['carrier_id']) && !empty($_GET['carrier_id'])) {
    header("Location: carrier_details.php?carrier_id=" . intval($_GET['carrier_id']));
    exit();
}

// Account-scoped access
$account_id = null;
if ($role !== 'global_admin') {
    $stmtAcc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    if ($stmtAcc) {
        $stmtAcc->bind_param("i", $user_id);
        $stmtAcc->execute();
        $stmtAcc->bind_result($account_id);
        $stmtAcc->fetch();
        $stmtAcc->close();
    }
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

$carriers_data = [];

// Query 1: Carrier aggregates - deliveries, freight cost, miles, projects
$carrier_sql = "
    SELECT
        c.id as carrier_id,
        c.name as carrier_name,
        c.short_name,
        c.carrier_type,
        c.is_solterra_managed,
        COUNT(DISTINCT d.id) as total_deliveries,
        COALESCE(SUM(d.freight_cost), 0) as total_freight_cost,
        COALESCE(SUM(d.miles), 0) as total_miles,
        COUNT(DISTINCT d.project_id) as project_count
    FROM carriers c
    LEFT JOIN deliveries d ON d.carrier_id = c.id AND d.project_id IN ($project_access_sql)
    WHERE c.is_active = 1
";
if ($role !== 'global_admin' && $account_id) {
    $carrier_sql .= " AND (c.account_id IS NULL OR c.account_id = ?)";
}
$carrier_sql .= "
    GROUP BY c.id, c.name, c.short_name, c.carrier_type, c.is_solterra_managed
    ORDER BY total_freight_cost DESC
";
$stmt = $conn->prepare($carrier_sql);
if ($role !== 'global_admin' && $account_id) {
    if (!empty($access_types)) {
        $stmt->bind_param($access_types . "i", ...[...$access_params, $account_id]);
    } else {
        $stmt->bind_param("i", $account_id);
    }
} elseif (!empty($access_types)) {
    $stmt->bind_param($access_types, ...$access_params);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $cid = (int)$row['carrier_id'];
    $carriers_data[$cid] = [
        'carrier_id' => $cid,
        'carrier_name' => $row['carrier_name'],
        'short_name' => $row['short_name'],
        'carrier_type' => $row['carrier_type'],
        'is_solterra_managed' => (int)$row['is_solterra_managed'],
        'total_deliveries' => (int)$row['total_deliveries'],
        'total_freight_cost' => (float)$row['total_freight_cost'],
        'total_miles' => (float)$row['total_miles'],
        'project_count' => (int)$row['project_count'],
        'deliveries_on_time' => 0,
        'deliveries_late' => 0,
        'avg_days_late' => 0,
        'safety_incidents' => 0,
        'drivers_reported' => 0,
        'warranty_claims' => 0
    ];
}
$stmt->close();

// Query 2: On-time performance per carrier
$ontime_sql = "
    SELECT
        d.carrier_id,
        COUNT(DISTINCT d.id) as total_completed,
        COUNT(DISTINCT CASE
            WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
            AND d.actual_delivery_date <= d.anticipated_delivery_date THEN d.id END) as on_time_count,
        COUNT(DISTINCT CASE
            WHEN d.actual_delivery_date IS NOT NULL AND d.anticipated_delivery_date IS NOT NULL
            AND d.actual_delivery_date > d.anticipated_delivery_date THEN d.id END) as late_count,
        AVG(CASE
            WHEN d.actual_delivery_date > d.anticipated_delivery_date
            THEN DATEDIFF(d.actual_delivery_date, d.anticipated_delivery_date) END) as avg_days_late
    FROM deliveries d
    WHERE d.carrier_id IS NOT NULL
    AND d.actual_delivery_date IS NOT NULL
    AND d.project_id IN ($project_access_sql)
    GROUP BY d.carrier_id
";
$stmt_ontime = $conn->prepare($ontime_sql);
if (!empty($access_types)) {
    $stmt_ontime->bind_param($access_types, ...$access_params);
}
$stmt_ontime->execute();
$ontime_result = $stmt_ontime->get_result();
while ($row = $ontime_result->fetch_assoc()) {
    $cid = (int)$row['carrier_id'];
    if (isset($carriers_data[$cid])) {
        $carriers_data[$cid]['deliveries_on_time'] = (int)$row['on_time_count'];
        $carriers_data[$cid]['deliveries_late'] = (int)$row['late_count'];
        $carriers_data[$cid]['avg_days_late'] = round((float)$row['avg_days_late'], 1);
    }
}
$stmt_ontime->close();

// Query 3: Safety incidents per carrier
$safety_sql = "
    SELECT
        d.carrier_id,
        COUNT(DISTINCT ss_safety.id) as total_incidents,
        COUNT(DISTINCT CASE WHEN ss_safety.report_driver = 'Yes' THEN ss_safety.id END) as drivers_reported
    FROM deliveries d
    JOIN site_scheduling sched ON sched.delivery_id = d.id
    JOIN site_safety ss_safety ON ss_safety.scheduling_id = sched.id
    WHERE d.carrier_id IS NOT NULL
    AND d.project_id IN ($project_access_sql)
    GROUP BY d.carrier_id
";
$stmt_safety = $conn->prepare($safety_sql);
if (!empty($access_types)) {
    $stmt_safety->bind_param($access_types, ...$access_params);
}
$stmt_safety->execute();
$safety_result = $stmt_safety->get_result();
while ($row = $safety_result->fetch_assoc()) {
    $cid = (int)$row['carrier_id'];
    if (isset($carriers_data[$cid])) {
        $carriers_data[$cid]['safety_incidents'] = (int)$row['total_incidents'];
        $carriers_data[$cid]['drivers_reported'] = (int)$row['drivers_reported'];
    }
}
$stmt_safety->close();

// Query 4: Warranty claims where carrier is responsible party
$warranty_sql = "
    SELECT
        d.carrier_id,
        COUNT(DISTINCT w.id) as claim_count
    FROM warranty_claims w
    JOIN site_scheduling ss ON ss.id = w.scheduling_id
    JOIN deliveries d ON d.id = ss.delivery_id
    WHERE d.carrier_id IS NOT NULL
    AND w.responsible_party = 'Carrier'
    AND d.project_id IN ($project_access_sql)
    GROUP BY d.carrier_id
";
$stmt_warranty = $conn->prepare($warranty_sql);
if (!empty($access_types)) {
    $stmt_warranty->bind_param($access_types, ...$access_params);
}
$stmt_warranty->execute();
$warranty_result = $stmt_warranty->get_result();
while ($row = $warranty_result->fetch_assoc()) {
    $cid = (int)$row['carrier_id'];
    if (isset($carriers_data[$cid])) {
        $carriers_data[$cid]['warranty_claims'] = (int)$row['claim_count'];
    }
}
$stmt_warranty->close();

// Calculate totals
$total_freight_cost = array_sum(array_column($carriers_data, 'total_freight_cost'));
$total_deliveries = array_sum(array_column($carriers_data, 'total_deliveries'));
$active_carrier_count = count($carriers_data);
$total_drivers_reported = array_sum(array_column($carriers_data, 'drivers_reported'));
$total_warranty_claims = array_sum(array_column($carriers_data, 'warranty_claims'));

// Overall on-time rate
$total_on_time = array_sum(array_column($carriers_data, 'deliveries_on_time'));
$total_late = array_sum(array_column($carriers_data, 'deliveries_late'));
$total_completed_deliveries = $total_on_time + $total_late;
$overall_on_time_rate = $total_completed_deliveries > 0 ? round(($total_on_time / $total_completed_deliveries) * 100) : null;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrier Overview</title>
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

        .carrier-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }
        .carrier-card {
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
        .carrier-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(72,140,154,0.15);
        }
        .carrier-card-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(45deg, rgba(72,140,154,0.95), rgba(41,62,76,0.95));
            display: flex; align-items: center; justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            border-radius: 16px;
        }
        .carrier-card:hover .carrier-card-overlay { opacity: 1; }
        .carrier-card-overlay span { color: #fff; font-size: 1em; font-weight: 600; }
        .carrier-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f3f4;
            gap: 12px;
        }
        .carrier-name {
            font-size: 1.2em;
            font-weight: 600;
            color: #293E4C;
            flex: 1;
            min-width: 0;
        }
        .carrier-badge {
            font-size: 0.75em;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .carrier-badge.solterra {
            background: linear-gradient(135deg, #488C9A, #293E4C);
            color: #fff;
        }
        .carrier-badge.type {
            background: #e8f4f6;
            color: #293E4C;
        }
        .carrier-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .carrier-stat {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .carrier-stat .value {
            font-size: 1.1em;
            font-weight: 700;
            color: #293E4C;
        }
        .carrier-stat .value.good { color: #059669; }
        .carrier-stat .value.warning { color: #fbb040; }
        .carrier-stat .value.poor { color: #ea580c; }
        .carrier-stat .label {
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
        .breakdown-item-name { font-weight: 500; color: #293E4C; }
        .breakdown-item-value { font-weight: 700; color: #488C9A; }
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
        .breakdown-total-label { font-weight: 600; color: #293E4C; }
        .breakdown-total-value { font-size: 1.3em; font-weight: 700; color: #488C9A; }

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
            .carrier-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
    require_once 'components/breadcrumbs.php';
    echo slp_render_breadcrumbs(['current_label' => 'Carrier Overview']);
    ?>

    <div class="page-header">
        <h1>Carrier Overview</h1>
        <p>View all freight carriers across your portfolio with delivery performance and safety metrics</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card primary stat-card-clickable" onclick="openCostModal()">
            <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-value">$<?php echo number_format($total_freight_cost, 0); ?></div>
            <div class="stat-label">Total Freight Cost</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openDeliveriesModal()">
            <div class="stat-icon blue"><i class="fas fa-truck"></i></div>
            <div class="stat-value"><?php echo number_format($total_deliveries); ?></div>
            <div class="stat-label">Total Deliveries</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="scrollToCarriers()">
            <div class="stat-icon purple"><i class="fas fa-shipping-fast"></i></div>
            <div class="stat-value"><?php echo $active_carrier_count; ?></div>
            <div class="stat-label">Active Carriers</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openOnTimeModal()">
            <div class="stat-icon <?php echo $overall_on_time_rate === null ? 'orange' : ($overall_on_time_rate >= 90 ? 'green' : ($overall_on_time_rate >= 70 ? 'orange' : 'red-orange')); ?>"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-value <?php echo $overall_on_time_rate === null ? '' : ($overall_on_time_rate >= 90 ? 'good' : ($overall_on_time_rate >= 70 ? 'warning' : 'poor')); ?>">
                <?php echo $overall_on_time_rate !== null ? $overall_on_time_rate . '%' : 'N/A'; ?>
            </div>
            <div class="stat-label">Overall On-Time Rate</div>
        </div>
        <div class="stat-card stat-card-clickable" onclick="openDriversModal()">
            <div class="stat-icon <?php echo $total_drivers_reported === 0 ? 'green' : 'red'; ?>"><i class="fas fa-user-shield"></i></div>
            <div class="stat-value <?php echo $total_drivers_reported === 0 ? 'good' : 'poor'; ?>"><?php echo number_format($total_drivers_reported); ?></div>
            <div class="stat-label">Total Drivers Reported</div>
        </div>
        <?php if ($total_warranty_claims > 0): ?>
        <div class="stat-card stat-card-clickable" onclick="openWarrantyModal()">
            <div class="stat-icon red-orange"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-value poor"><?php echo number_format($total_warranty_claims); ?></div>
            <div class="stat-label">Warranty Claims (Carrier)</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Cost Breakdown Modal -->
    <div id="costModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header">
                <h2><i class="fas fa-dollar-sign"></i> Freight Cost Breakdown</h2>
                <button class="breakdown-modal-close" onclick="closeCostModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <div class="breakdown-list">
                    <?php foreach ($carriers_data as $data): if ($data['total_freight_cost'] <= 0) continue; ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($data['carrier_name']); ?><?php echo $data['is_solterra_managed'] ? ' (Solterra)' : ''; ?></span>
                        <span class="breakdown-item-value">$<?php echo number_format($data['total_freight_cost'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Freight Cost</span>
                    <span class="breakdown-total-value">$<?php echo number_format($total_freight_cost, 2); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Deliveries Breakdown Modal -->
    <div id="deliveriesModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);">
                <h2><i class="fas fa-truck"></i> Deliveries Breakdown</h2>
                <button class="breakdown-modal-close" onclick="closeDeliveriesModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <div class="breakdown-list">
                    <?php foreach ($carriers_data as $data): if ($data['total_deliveries'] <= 0) continue; ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($data['carrier_name']); ?></span>
                        <span class="breakdown-item-value"><?php echo number_format($data['total_deliveries']); ?> deliveries</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Deliveries</span>
                    <span class="breakdown-total-value"><?php echo number_format($total_deliveries); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- On-Time Rate Modal -->
    <div id="onTimeModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header" style="background: linear-gradient(135deg, #059669 0%, #047857 100%);">
                <h2><i class="fas fa-calendar-check"></i> On-Time Delivery by Carrier</h2>
                <button class="breakdown-modal-close" onclick="closeOnTimeModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <p style="color:#6c757d; margin-bottom:16px; font-size:0.9em;">Percentage of deliveries that arrived on or before the anticipated delivery date.</p>
                <div class="breakdown-list">
                    <?php foreach ($carriers_data as $data):
                        $completed = $data['deliveries_on_time'] + $data['deliveries_late'];
                        $on_time_pct = $completed > 0 ? round(($data['deliveries_on_time'] / $completed) * 100) : null;
                    ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($data['carrier_name']); ?></span>
                        <span class="breakdown-item-value <?php echo $on_time_pct === null ? '' : ($on_time_pct >= 90 ? 'good' : ($on_time_pct < 70 ? 'poor' : '')); ?>">
                            <?php if ($on_time_pct !== null): ?>
                                <?php echo $on_time_pct; ?>%
                                <small style="color:#6c757d;font-weight:400;"> (<?php echo $data['deliveries_on_time']; ?>/<?php echo $completed; ?>)</small>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Overall On-Time Rate</span>
                    <span class="breakdown-total-value"><?php echo $overall_on_time_rate !== null ? $overall_on_time_rate . '%' : 'N/A'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Drivers Reported Modal -->
    <div id="driversModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header" style="background: linear-gradient(135deg, #E4572E 0%, #dc2626 100%);">
                <h2><i class="fas fa-user-shield"></i> Drivers Reported by Carrier</h2>
                <button class="breakdown-modal-close" onclick="closeDriversModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <div class="breakdown-list">
                    <?php foreach ($carriers_data as $data): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($data['carrier_name']); ?></span>
                        <span class="breakdown-item-value <?php echo $data['drivers_reported'] === 0 ? 'good' : 'poor'; ?>">
                            <?php echo number_format($data['drivers_reported']); ?> reported
                            <?php if ($data['safety_incidents'] > 0): ?>
                                <small style="color:#6c757d;font-weight:400;"> (<?php echo $data['safety_incidents']; ?> incidents)</small>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Drivers Reported</span>
                    <span class="breakdown-total-value"><?php echo number_format($total_drivers_reported); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Warranty Claims Modal -->
    <?php if ($total_warranty_claims > 0): ?>
    <div id="warrantyModal" class="breakdown-modal">
        <div class="breakdown-modal-content">
            <div class="breakdown-modal-header" style="background: linear-gradient(135deg, #E4572E 0%, #b91c1c 100%);">
                <h2><i class="fas fa-exclamation-triangle"></i> Warranty Claims (Carrier Responsible)</h2>
                <button class="breakdown-modal-close" onclick="closeWarrantyModal()">&times;</button>
            </div>
            <div class="breakdown-modal-body">
                <p style="color:#6c757d; margin-bottom:16px; font-size:0.9em;">Warranty claims where the carrier was identified as the responsible party.</p>
                <div class="breakdown-list">
                    <?php foreach ($carriers_data as $data): if ($data['warranty_claims'] <= 0) continue; ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($data['carrier_name']); ?></span>
                        <span class="breakdown-item-value poor"><?php echo number_format($data['warranty_claims']); ?> claim<?php echo $data['warranty_claims'] !== 1 ? 's' : ''; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="breakdown-total">
                    <span class="breakdown-total-label">Total Claims</span>
                    <span class="breakdown-total-value"><?php echo number_format($total_warranty_claims); ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="chart-card">
            <h3><i class="fas fa-chart-pie"></i> Freight Cost by Carrier</h3>
            <div class="chart-container">
                <canvas id="carrierCostChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3><i class="fas fa-chart-bar"></i> Deliveries by Carrier</h3>
            <div class="chart-container">
                <canvas id="carrierDeliveriesChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Carrier Cards -->
    <?php if (!empty($carriers_data)): ?>
    <div class="carrier-grid">
        <?php foreach ($carriers_data as $data):
            $completed = $data['deliveries_on_time'] + $data['deliveries_late'];
            $on_time_pct = $completed > 0 ? round(($data['deliveries_on_time'] / $completed) * 100) : null;
            $type_labels = ['ftl' => 'FTL', 'ltl' => 'LTL', 'drayage' => 'Drayage', 'intermodal' => 'Intermodal', 'ocean' => 'Ocean', 'other' => 'Other'];
        ?>
        <div class="carrier-card" onclick="window.location.href='carrier_details.php?carrier_id=<?php echo $data['carrier_id']; ?>'">
            <div class="carrier-card-header">
                <span class="carrier-name"><?php echo htmlspecialchars($data['carrier_name']); ?></span>
                <span>
                    <?php if ($data['is_solterra_managed']): ?>
                        <span class="carrier-badge solterra">Solterra</span>
                    <?php endif; ?>
                    <span class="carrier-badge type"><?php echo $type_labels[$data['carrier_type']] ?? ucfirst($data['carrier_type']); ?></span>
                </span>
            </div>
            <div class="carrier-stats">
                <div class="carrier-stat">
                    <span class="value">$<?php echo number_format($data['total_freight_cost'], 0); ?></span>
                    <span class="label">Freight Cost</span>
                </div>
                <div class="carrier-stat">
                    <span class="value"><?php echo number_format($data['total_deliveries']); ?></span>
                    <span class="label">Deliveries</span>
                </div>
                <div class="carrier-stat">
                    <span class="value <?php echo $on_time_pct === null ? '' : ($on_time_pct >= 90 ? 'good' : ($on_time_pct >= 70 ? 'warning' : 'poor')); ?>">
                        <?php echo $on_time_pct !== null ? $on_time_pct . '%' : 'N/A'; ?>
                    </span>
                    <span class="label">On-Time</span>
                </div>
                <div class="carrier-stat">
                    <span class="value <?php echo $data['safety_incidents'] === 0 ? 'good' : 'poor'; ?>"><?php echo number_format($data['safety_incidents']); ?></span>
                    <span class="label">Safety Incidents</span>
                </div>
                <?php if ($data['warranty_claims'] > 0): ?>
                <div class="carrier-stat" style="grid-column: 1 / -1;">
                    <span class="value poor"><?php echo number_format($data['warranty_claims']); ?></span>
                    <span class="label">Warranty Claims (Carrier Responsible)</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="carrier-card-overlay">
                <span>View Details</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <h3>No Carriers Found</h3>
        <p>No carrier data is available. <a href="add_carrier.php" style="color:#488C9A;">Add a carrier</a> to get started.</p>
    </div>
    <?php endif; ?>
</main>

<script>
function openCostModal() { document.getElementById('costModal').style.display = 'block'; }
function closeCostModal() { document.getElementById('costModal').style.display = 'none'; }
function openDeliveriesModal() { document.getElementById('deliveriesModal').style.display = 'block'; }
function closeDeliveriesModal() { document.getElementById('deliveriesModal').style.display = 'none'; }
function openOnTimeModal() { document.getElementById('onTimeModal').style.display = 'block'; }
function closeOnTimeModal() { document.getElementById('onTimeModal').style.display = 'none'; }
function openDriversModal() { document.getElementById('driversModal').style.display = 'block'; }
function closeDriversModal() { document.getElementById('driversModal').style.display = 'none'; }
function openWarrantyModal() { var m = document.getElementById('warrantyModal'); if (m) m.style.display = 'block'; }
function closeWarrantyModal() { var m = document.getElementById('warrantyModal'); if (m) m.style.display = 'none'; }

function scrollToCarriers() {
    var grid = document.querySelector('.carrier-grid');
    if (grid) {
        grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

window.onclick = function(event) {
    const modals = ['costModal', 'deliveriesModal', 'onTimeModal', 'driversModal', 'warrantyModal'];
    modals.forEach(function(id) {
        const modal = document.getElementById(id);
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    <?php
    // Build chart data from carriers with deliveries
    $chart_carriers = array_filter($carriers_data, function($d) { return $d['total_deliveries'] > 0 || $d['total_freight_cost'] > 0; });
    ?>
    <?php if (!empty($chart_carriers)): ?>
    const carrierData = <?php echo json_encode(array_values($chart_carriers)); ?>;
    const names = carrierData.map(c => c.carrier_name);
    const costs = carrierData.map(c => c.total_freight_cost);
    const deliveries = carrierData.map(c => c.total_deliveries);

    const colors = [
        '#488C9A', '#293E4C', '#fbb040', '#059669', '#E4572E',
        '#3b82f6', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316'
    ];

    const costCtx = document.getElementById('carrierCostChart');
    if (costCtx) {
        new Chart(costCtx, {
            type: 'doughnut',
            data: {
                labels: names,
                datasets: [{
                    data: costs,
                    backgroundColor: colors.slice(0, names.length),
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

    const delCtx = document.getElementById('carrierDeliveriesChart');
    if (delCtx) {
        new Chart(delCtx, {
            type: 'bar',
            data: {
                labels: names,
                datasets: [{
                    label: 'Deliveries',
                    data: deliveries,
                    backgroundColor: colors.slice(0, names.length),
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
                            label: function(ctx) { return `${ctx.raw} deliveries`; }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } },
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
