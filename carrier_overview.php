<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

require_once '../config.php';
require_once __DIR__ . '/carrier_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$isAdmin = is_admin_role($role);
$isCustomerRole = is_customer_role($role);

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
// Also fetch compliance columns for badge rendering
$carrier_sql = "
    SELECT
        c.id as carrier_id,
        c.name as carrier_name,
        c.short_name,
        c.carrier_type,
        c.is_solterra_managed,
        c.account_id as carrier_account_id,
        c.coi_on_file,
        c.coi_expiration_date,
        c.insurance_minimum_met,
        c.authority_status,
        c.fmcsa_safety_rating,
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
        'carrier_account_id' => $row['carrier_account_id'],
        'coi_on_file' => $row['coi_on_file'],
        'coi_expiration_date' => $row['coi_expiration_date'],
        'insurance_minimum_met' => $row['insurance_minimum_met'],
        'authority_status' => $row['authority_status'],
        'fmcsa_safety_rating' => $row['fmcsa_safety_rating'],
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

// === CARRIER ABSTRACTION FOR CUSTOMER ROLES ===
// For customer roles: aggregate all Solterra sub-carriers into one "Solterra Solutions" card
$display_carriers = [];
$solterra_carrier_id = get_solterra_carrier_id($conn);

if ($isCustomerRole) {
    $solterra_aggregate = null;

    foreach ($carriers_data as $cid => $data) {
        if ($data['is_solterra_managed']) {
            // Aggregate into single Solterra card
            if ($solterra_aggregate === null) {
                $solterra_aggregate = $data;
                $solterra_aggregate['carrier_id'] = $solterra_carrier_id ?: $cid;
                $solterra_aggregate['carrier_name'] = 'Solterra Solutions';
                $solterra_aggregate['short_name'] = 'Solterra';
                $solterra_aggregate['is_solterra_managed'] = 0; // Hide badge for customers
                // Null out compliance fields - aggregate compliance is meaningless
                $solterra_aggregate['coi_on_file'] = 0;
                $solterra_aggregate['coi_expiration_date'] = null;
                $solterra_aggregate['insurance_minimum_met'] = 0;
                $solterra_aggregate['authority_status'] = null;
                $solterra_aggregate['fmcsa_safety_rating'] = null;
                $solterra_aggregate['avg_days_late'] = null;
            } else {
                $solterra_aggregate['total_deliveries'] += $data['total_deliveries'];
                $solterra_aggregate['total_freight_cost'] += $data['total_freight_cost'];
                $solterra_aggregate['total_miles'] += $data['total_miles'];
                $solterra_aggregate['project_count'] += $data['project_count'];
                $solterra_aggregate['deliveries_on_time'] += $data['deliveries_on_time'];
                $solterra_aggregate['deliveries_late'] += $data['deliveries_late'];
                $solterra_aggregate['safety_incidents'] += $data['safety_incidents'];
                $solterra_aggregate['drivers_reported'] += $data['drivers_reported'];
                $solterra_aggregate['warranty_claims'] += $data['warranty_claims'];
            }
        } else {
            // Customer's own carriers show normally
            $display_carriers[$cid] = $data;
        }
    }

    if ($solterra_aggregate !== null) {
        // Insert Solterra at beginning
        $display_carriers = [$solterra_aggregate['carrier_id'] => $solterra_aggregate] + $display_carriers;
    }
} else {
    // Admin/global_admin: show all real operational carriers
    // Hide any "Solterra Solutions" abstraction record(s) — admins see actual sub-carriers instead
    foreach ($carriers_data as $cid => $data) {
        if ($data['carrier_name'] === 'Solterra Solutions') {
            continue; // Skip the abstraction carrier(s)
        }
        $display_carriers[$cid] = $data;
    }
}

// Calculate totals from display carriers
$total_freight_cost = array_sum(array_column($display_carriers, 'total_freight_cost'));
$total_deliveries = array_sum(array_column($display_carriers, 'total_deliveries'));
$active_carrier_count = count($display_carriers);
$total_drivers_reported = array_sum(array_column($display_carriers, 'drivers_reported'));
$total_warranty_claims = array_sum(array_column($display_carriers, 'warranty_claims'));

// Overall on-time rate
$total_on_time = array_sum(array_column($display_carriers, 'deliveries_on_time'));
$total_late = array_sum(array_column($display_carriers, 'deliveries_late'));
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

        /* Filter Toggles (admin only) */
        .filter-toggles {
            display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap;
        }
        .filter-btn {
            padding: 8px 18px; border-radius: 20px; border: 2px solid #e9ecef;
            background: #fff; color: #6c757d; font-weight: 600; font-size: 0.85em;
            cursor: pointer; transition: all 0.2s; font-family: inherit;
        }
        .filter-btn:hover { border-color: #488C9A; color: #488C9A; }
        .filter-btn.active { background: linear-gradient(135deg, #488C9A, #293E4C); color: #fff; border-color: transparent; }

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
            position: absolute; bottom: 8px; left: 50%; transform: translateX(-50%);
            font-size: 0.65em; color: #488C9A; opacity: 0; transition: opacity 0.2s; white-space: nowrap;
        }
        .stat-card-clickable:hover::after { opacity: 1; }
        .stat-card.primary.stat-card-clickable::after { color: rgba(255,255,255,0.8); }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px; font-size: 1.5em;
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
            display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 40px;
        }
        .chart-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px; padding: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06); border: 1px solid rgba(72, 140, 154, 0.08);
        }
        .chart-card h3 { font-size: 1.2em; font-weight: 600; color: #293E4C; margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px; }
        .chart-card h3 i { color: #488C9A; }
        .chart-container { position: relative; height: 280px; }

        /* Carrier Table */
        .table-card {
            background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            overflow: hidden; border: 1px solid #e9ecef; margin-bottom: 40px;
        }
        .table-card-header {
            display: flex; align-items: center; gap: 12px; padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-bottom: 1px solid #e9ecef;
        }
        .table-card-header .icon-badge {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%); color: #fff;
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 1rem;
        }
        .table-card-header h2 { margin: 0; font-size: 1.15rem; font-weight: 600; color: #293E4C; }
        .table-card table { width: 100%; border-collapse: collapse; }
        .table-card thead th {
            padding: 14px 16px; text-align: left; font-size: 0.8rem; font-weight: 600;
            color: #fff; text-transform: uppercase; letter-spacing: 0.5px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
        }
        .table-card tbody tr { cursor: pointer; transition: background 0.15s, border-color 0.15s; border-left: 3px solid transparent; }
        .table-card tbody tr:hover { background: #f0f7f8; border-left-color: #488C9A; }
        .table-card tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f3f4; font-size: 0.9rem; color: #333; vertical-align: middle; }
        .table-card tbody tr:last-child td { border-bottom: none; }
        .carrier-badge {
            font-size: 0.72em; font-weight: 600; padding: 3px 10px; border-radius: 20px; white-space: nowrap;
            display: inline-block; vertical-align: middle; margin-left: 5px;
        }
        .carrier-badge.solterra { background: linear-gradient(135deg, #488C9A, #293E4C); color: #fff; }
        .type-badge {
            display: inline-block; background: linear-gradient(135deg, #e8f4f6, #d0e8ec); color: #293E4C;
            padding: 4px 12px; border-radius: 20px; font-size: 0.8em; font-weight: 600;
        }
        .rate-badge {
            display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 0.8em; font-weight: 600;
        }
        .rate-badge.good { background: #d1fae5; color: #059669; }
        .rate-badge.warning { background: #fef3c7; color: #92400e; }
        .rate-badge.poor { background: #fee2e2; color: #dc2626; }
        .rate-badge.neutral { background: #f3f4f6; color: #6c757d; }
        .safety-count { font-weight: 600; }
        .safety-count.good { color: #059669; }
        .safety-count.poor { color: #dc2626; }

        /* Modal Styles */
        .breakdown-modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background: rgba(0,0,0,0.5); backdrop-filter: blur(5px); }
        .breakdown-modal-content { background: white; margin: 8% auto; padding: 0; width: 90%; max-width: 600px; border-radius: 20px; box-shadow: 0 25px 50px rgba(0,0,0,0.25); animation: modalSlideIn 0.3s ease; max-height: 80vh; display: flex; flex-direction: column; }
        @keyframes modalSlideIn { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .breakdown-modal-header { background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%); color: white; padding: 24px; border-radius: 20px 20px 0 0; position: relative; flex-shrink: 0; }
        .breakdown-modal-header h2 { margin: 0; font-size: 1.4em; font-weight: 600; display: flex; align-items: center; gap: 12px; }
        .breakdown-modal-close { position: absolute; top: 20px; right: 24px; font-size: 28px; font-weight: bold; color: white; cursor: pointer; transition: transform 0.2s ease; border: none; background: transparent; }
        .breakdown-modal-close:hover { transform: scale(1.1); }
        .breakdown-modal-body { padding: 24px; overflow-y: auto; }
        .breakdown-list { display: flex; flex-direction: column; gap: 8px; }
        .breakdown-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f8f9fa; border-radius: 10px; transition: background 0.2s; }
        .breakdown-item:hover { background: #e9ecef; }
        .breakdown-item-name { font-weight: 500; color: #293E4C; }
        .breakdown-item-value { font-weight: 700; color: #488C9A; }
        .breakdown-item-value.good { color: #059669; }
        .breakdown-item-value.poor { color: #E4572E; }
        .breakdown-total { display: flex; justify-content: space-between; align-items: center; padding: 16px; background: linear-gradient(135deg, #e8f4f6 0%, #d1e8ec 100%); border-radius: 12px; margin-top: 16px; border: 1px solid rgba(72,140,154,0.2); }
        .breakdown-total-label { font-weight: 600; color: #293E4C; }
        .breakdown-total-value { font-size: 1.3em; font-weight: 700; color: #488C9A; }

        .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
        .empty-state h3 { color: #293E4C; margin-bottom: 8px; }

        /* Table hint */
        .table-hint {
            display: flex; align-items: center; gap: 8px;
            background: linear-gradient(135deg, #e8f4f6, #d0e8ec); padding: 8px 16px;
            border-radius: 10px; font-size: 0.82em; color: #3a6d78; font-weight: 500;
        }
        .table-hint i { color: #488C9A; font-size: 1em; flex-shrink: 0; }

        /* Compare checkbox column */
        .compare-col { width: 40px; text-align: center; }
        .compare-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: #488C9A; }

        /* Floating compare bar */
        .compare-bar { position: fixed; bottom: -80px; left: 50%; transform: translateX(-50%);
            background: rgba(41, 62, 76, 0.95); backdrop-filter: blur(10px);
            padding: 12px 24px; border-radius: 16px; display: flex; align-items: center; gap: 16px;
            z-index: 9999; transition: bottom 0.3s ease; box-shadow: 0 8px 32px rgba(0,0,0,0.3); }
        .compare-bar.visible { bottom: 24px; }
        .compare-bar-chips { display: flex; gap: 8px; flex-wrap: wrap; }
        .compare-chip { background: rgba(72,140,154,0.3); color: #fff; padding: 4px 12px;
            border-radius: 20px; font-size: 0.8em; display: flex; align-items: center; gap: 6px; }
        .compare-chip-remove { cursor: pointer; opacity: 0.7; font-size: 1.1em; }
        .compare-chip-remove:hover { opacity: 1; }
        .compare-btn { background: linear-gradient(135deg, #488C9A, #3a7a87); color: #fff;
            border: none; padding: 10px 24px; border-radius: 12px; font-weight: 600;
            cursor: pointer; font-family: inherit; transition: transform 0.2s; white-space: nowrap; }
        .compare-btn:hover { transform: scale(1.05); }
        .compare-count { color: rgba(255,255,255,0.8); font-size: 0.85em; white-space: nowrap; }

        /* Comparison section */
        .comparison-section { margin-top: 40px; margin-bottom: 40px; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .comparison-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .comparison-header h2 { font-size: 1.5em; color: #293E4C; margin: 0; }
        .comparison-close-btn { background: #f3f4f6; border: 1px solid #e9ecef; padding: 8px 16px;
            border-radius: 8px; cursor: pointer; font-family: inherit; font-weight: 500; transition: background 0.2s; }
        .comparison-close-btn:hover { background: #e9ecef; }

        /* Stat card columns */
        .comparison-stats { display: grid; gap: 20px; margin-bottom: 32px; }
        .comparison-stat-col { background: #fff; border-radius: 16px; padding: 20px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; }
        .comparison-stat-col h3 { font-size: 1.1em; color: #293E4C; margin: 0 0 16px 0;
            padding-bottom: 12px; border-bottom: 2px solid #488C9A; }
        .comparison-stat-item { display: flex; justify-content: space-between; padding: 8px 0;
            border-bottom: 1px solid #f1f3f4; }
        .comparison-stat-item:last-child { border-bottom: none; }
        .comparison-stat-item.best { background: rgba(5,150,105,0.05); border-left: 3px solid #059669; padding-left: 8px; border-radius: 4px; }

        /* Comparison charts */
        .comparison-charts { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px; }
        .comparison-chart-card { background: #fff; border-radius: 16px; padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; }
        .comparison-chart-card h3 { font-size: 1.1em; font-weight: 600; color: #293E4C; margin: 0 0 16px 0; }
        .comparison-chart-card.full-width { grid-column: 1 / -1; }

        /* Comparison matrix */
        .comparison-matrix { background: #fff; border-radius: 16px; overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; }
        .comparison-matrix table { width: 100%; border-collapse: collapse; }
        .comparison-matrix thead th { padding: 14px 16px; background: linear-gradient(135deg, #488C9A, #3a7a87);
            color: #fff; font-size: 0.85em; font-weight: 600; text-align: center; }
        .comparison-matrix thead th:first-child { text-align: left; }
        .comparison-matrix tbody td { padding: 12px 16px; border-bottom: 1px solid #f1f3f4;
            text-align: center; font-size: 0.9em; }
        .comparison-matrix tbody td:first-child { text-align: left; font-weight: 500; color: #293E4C; }
        .comparison-matrix .group-header td { background: #f8f9fa; font-weight: 600; color: #488C9A;
            font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; }
        .matrix-best { color: #059669; font-weight: 700; }
        .matrix-worst { color: #dc2626; font-weight: 600; }

        @media (max-width: 1400px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-section { grid-template-columns: 1fr; }
            .comparison-charts { grid-template-columns: 1fr; }
            .comparison-charts .comparison-chart-card.full-width { grid-column: auto; }
        }
        @media (max-width: 768px) {
            .page-header { padding: 24px; }
            .page-header h1 { font-size: 1.8em; }
            .stats-grid { grid-template-columns: 1fr; }
            .comparison-stats { grid-template-columns: 1fr !important; }
            .comparison-matrix { overflow-x: auto; }
            .table-card-header { flex-wrap: wrap; }
            .table-hint { margin-left: 0; margin-top: 8px; font-size: 0.78em; }
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

    <!-- Filter Toggles (admin/global_admin only) -->
    <?php if ($isAdmin): ?>
    <div class="filter-toggles">
        <button class="filter-btn active" data-filter="all">All Carriers</button>
        <button class="filter-btn" data-filter="solterra">Solterra Managed</button>
        <button class="filter-btn" data-filter="account">Account Carriers</button>
    </div>
    <?php endif; ?>

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
                    <?php foreach ($display_carriers as $data): if ($data['total_freight_cost'] <= 0) continue; ?>
                    <div class="breakdown-item">
                        <span class="breakdown-item-name"><?php echo htmlspecialchars($data['carrier_name']); ?></span>
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
                    <?php foreach ($display_carriers as $data): if ($data['total_deliveries'] <= 0) continue; ?>
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
                    <?php foreach ($display_carriers as $data):
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
                    <?php foreach ($display_carriers as $data): ?>
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
                <div class="breakdown-list">
                    <?php foreach ($display_carriers as $data): if ($data['warranty_claims'] <= 0) continue; ?>
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

    <!-- Carrier Table -->
    <?php if (!empty($display_carriers)): ?>
    <?php
        $type_labels = ['ftl' => 'FTL', 'ltl' => 'LTL', 'drayage' => 'Drayage', 'intermodal' => 'Intermodal', 'ocean' => 'Ocean', 'other' => 'Other'];
    ?>
    <div class="table-card">
        <div class="table-card-header">
            <div class="icon-badge"><i class="fas fa-truck"></i></div>
            <h2>All Carriers</h2>
            <div class="table-hint">
                <i class="fas fa-info-circle"></i>
                <span>Click a row to view carrier details, or select up to 4 carriers to compare side-by-side</span>
            </div>
        </div>
        <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th class="compare-col"></th>
                    <th>Carrier</th>
                    <th>Type</th>
                    <th>Freight Cost</th>
                    <th>Deliveries</th>
                    <th>On-Time Rate</th>
                    <th>Safety</th>
                    <th>Compliance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($display_carriers as $data):
                    $completed = $data['deliveries_on_time'] + $data['deliveries_late'];
                    $on_time_pct = $completed > 0 ? round(($data['deliveries_on_time'] / $completed) * 100) : null;
                    $row_filter = $data['is_solterra_managed'] ? 'solterra' : 'account';
                    $compliance = get_carrier_compliance_status($data);
                    $show_compliance = $isAdmin || ($role === 'customer_admin' && !$data['is_solterra_managed']);
                ?>
                <tr data-filter-type="<?php echo $row_filter; ?>" data-carrier-id="<?php echo $data['carrier_id']; ?>" onclick="window.location.href='carrier_details.php?carrier_id=<?php echo $data['carrier_id']; ?>'">
                    <td class="compare-col" onclick="event.stopPropagation()">
                        <input type="checkbox" class="compare-checkbox" data-carrier-id="<?php echo $data['carrier_id']; ?>" onclick="toggleCompare(<?php echo $data['carrier_id']; ?>, event)">
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($data['carrier_name']); ?></strong>
                        <?php if ($isAdmin && $data['is_solterra_managed']): ?>
                            <span class="carrier-badge solterra">Solterra</span>
                        <?php endif; ?>
                        <?php if (!empty($data['short_name'])): ?>
                            <br><small style="color:#6c757d;">(<?php echo htmlspecialchars($data['short_name']); ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td><span class="type-badge"><?php echo $type_labels[$data['carrier_type']] ?? ucfirst($data['carrier_type']); ?></span></td>
                    <td><strong>$<?php echo number_format($data['total_freight_cost'], 0); ?></strong></td>
                    <td><?php echo number_format($data['total_deliveries']); ?></td>
                    <td>
                        <?php if ($on_time_pct !== null): ?>
                            <span class="rate-badge <?php echo $on_time_pct >= 90 ? 'good' : ($on_time_pct >= 70 ? 'warning' : 'poor'); ?>">
                                <?php echo $on_time_pct; ?>%
                            </span>
                            <small style="color:#6c757d; margin-left:4px;">(<?php echo $data['deliveries_on_time']; ?>/<?php echo $completed; ?>)</small>
                        <?php else: ?>
                            <span class="rate-badge neutral">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="safety-count <?php echo $data['safety_incidents'] === 0 ? 'good' : 'poor'; ?>">
                            <?php echo number_format($data['safety_incidents']); ?>
                        </span>
                        <?php if ($data['warranty_claims'] > 0): ?>
                            <br><small style="color:#dc2626;"><?php echo $data['warranty_claims']; ?> warranty claim<?php echo $data['warranty_claims'] !== 1 ? 's' : ''; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($show_compliance && $compliance !== null): ?>
                            <?php echo get_compliance_badge_html($data); ?>
                        <?php else: ?>
                            <span style="color:#999;">--</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <!-- Comparison Section (rendered by JS) -->
    <div id="comparison-section" class="comparison-section" style="display:none;"></div>

    <?php else: ?>
    <div class="empty-state">
        <h3>No Carriers Found</h3>
        <p>No carrier data is available. <a href="add_carrier.php" style="color:#488C9A;">Add a carrier</a> to get started.</p>
    </div>
    <?php endif; ?>

    <!-- Floating Compare Bar -->
    <div id="compare-bar" class="compare-bar">
        <span class="compare-count" id="compare-count"></span>
        <div class="compare-bar-chips" id="compare-chips"></div>
        <button class="compare-btn" onclick="renderComparison()"><i class="fas fa-columns" style="margin-right:6px;"></i>Compare</button>
    </div>
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
    var table = document.querySelector('.table-card');
    if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

window.onclick = function(event) {
    if (event.target.classList.contains('breakdown-modal')) {
        event.target.style.display = 'none';
    }
}

// Filter toggles (admin only)
document.querySelectorAll('.filter-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-btn').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        var filter = btn.dataset.filter;
        document.querySelectorAll('tr[data-filter-type]').forEach(function(row) {
            if (filter === 'all') {
                row.style.display = '';
            } else {
                row.style.display = row.dataset.filterType === filter ? '' : 'none';
            }
        });
    });
});

// === Carrier Comparison Feature ===
const allCarrierData = <?php echo json_encode(array_values($display_carriers)); ?>;
const isAdminRole = <?php echo json_encode($isAdmin); ?>;
const selectedCarriers = new Set();
const MAX_COMPARE = 4;
let comparisonCharts = [];

function getCarrierById(id) {
    return allCarrierData.find(c => c.carrier_id == id);
}

function toggleCompare(carrierId, event) {
    if (event) event.stopPropagation();
    if (selectedCarriers.has(carrierId)) {
        selectedCarriers.delete(carrierId);
    } else {
        if (selectedCarriers.size >= MAX_COMPARE) return;
        selectedCarriers.add(carrierId);
    }
    updateCompareBar();
}

function removeFromCompare(carrierId) {
    selectedCarriers.delete(carrierId);
    const cb = document.querySelector('.compare-checkbox[data-carrier-id="' + carrierId + '"]');
    if (cb) cb.checked = false;
    updateCompareBar();
}

function updateCompareBar() {
    const bar = document.getElementById('compare-bar');
    const countEl = document.getElementById('compare-count');
    const chipsEl = document.getElementById('compare-chips');

    // Sync checkboxes
    document.querySelectorAll('.compare-checkbox').forEach(function(cb) {
        const cid = parseInt(cb.dataset.carrierId);
        cb.checked = selectedCarriers.has(cid);
        // Disable unchecked boxes if max reached
        if (selectedCarriers.size >= MAX_COMPARE && !selectedCarriers.has(cid)) {
            cb.disabled = true;
            cb.style.opacity = '0.4';
        } else {
            cb.disabled = false;
            cb.style.opacity = '1';
        }
    });

    if (selectedCarriers.size >= 2) {
        bar.classList.add('visible');
        countEl.textContent = selectedCarriers.size + ' selected';
        chipsEl.innerHTML = '';
        selectedCarriers.forEach(function(cid) {
            const carrier = getCarrierById(cid);
            if (!carrier) return;
            const chip = document.createElement('span');
            chip.className = 'compare-chip';
            chip.innerHTML = (carrier.short_name || carrier.carrier_name) +
                ' <span class="compare-chip-remove" onclick="removeFromCompare(' + cid + ')">&times;</span>';
            chipsEl.appendChild(chip);
        });
    } else {
        bar.classList.remove('visible');
        // If comparison section is visible but we deselected below 2, hide it
        var section = document.getElementById('comparison-section');
        if (section && section.style.display !== 'none') {
            section.style.display = 'none';
        }
    }
}

function closeComparison() {
    document.getElementById('comparison-section').style.display = 'none';
    selectedCarriers.clear();
    document.querySelectorAll('.compare-checkbox').forEach(function(cb) {
        cb.checked = false;
        cb.disabled = false;
        cb.style.opacity = '1';
    });
    updateCompareBar();
    // Destroy charts
    comparisonCharts.forEach(function(c) { c.destroy(); });
    comparisonCharts = [];
}

function getBestWorst(values, higherIsBetter) {
    // Filter out null/undefined values
    var validIndices = [];
    values.forEach(function(v, i) { if (v !== null && v !== undefined && !isNaN(v)) validIndices.push(i); });
    if (validIndices.length < 2) return { best: -1, worst: -1 };

    var bestIdx = validIndices[0], worstIdx = validIndices[0];
    validIndices.forEach(function(i) {
        if (higherIsBetter) {
            if (values[i] > values[bestIdx]) bestIdx = i;
            if (values[i] < values[worstIdx]) worstIdx = i;
        } else {
            if (values[i] < values[bestIdx]) bestIdx = i;
            if (values[i] > values[worstIdx]) worstIdx = i;
        }
    });
    // Only mark best/worst if they differ
    if (values[bestIdx] === values[worstIdx]) return { best: -1, worst: -1 };
    return { best: bestIdx, worst: worstIdx };
}

function renderComparison() {
    var carriers = [];
    selectedCarriers.forEach(function(cid) {
        var c = getCarrierById(cid);
        if (c) carriers.push(c);
    });
    if (carriers.length < 2) return;

    // Destroy old charts
    comparisonCharts.forEach(function(c) { c.destroy(); });
    comparisonCharts = [];

    var section = document.getElementById('comparison-section');
    section.style.display = 'block';

    var html = '<div class="comparison-header">' +
        '<h2><i class="fas fa-columns" style="color:#488C9A;margin-right:8px;"></i>Carrier Comparison</h2>' +
        '<button class="comparison-close-btn" onclick="closeComparison()"><i class="fas fa-times" style="margin-right:6px;"></i>Close Comparison</button>' +
        '</div>';

    html += renderStatCards(carriers);
    html += '<div class="comparison-charts">' +
        '<div class="comparison-chart-card"><h3><i class="fas fa-chart-bar" style="color:#488C9A;margin-right:8px;"></i>Freight Cost</h3><canvas id="cmp-cost-chart"></canvas></div>' +
        '<div class="comparison-chart-card"><h3><i class="fas fa-chart-bar" style="color:#488C9A;margin-right:8px;"></i>Deliveries Breakdown</h3><canvas id="cmp-deliveries-chart"></canvas></div>' +
        '<div class="comparison-chart-card full-width"><h3><i class="fas fa-chart-radar" style="color:#488C9A;margin-right:8px;"></i>Performance Radar</h3><div style="max-width:500px;margin:0 auto;"><canvas id="cmp-radar-chart"></canvas></div></div>' +
        '</div>';

    html += renderMatrix(carriers);
    section.innerHTML = html;

    // Scroll to section
    section.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Render charts after DOM update
    setTimeout(function() { renderCharts(carriers); }, 50);
}

function renderStatCards(carriers) {
    var cols = carriers.length;
    var html = '<div class="comparison-stats" style="grid-template-columns:repeat(' + cols + ',1fr);">';

    // Compute values for best-highlighting
    var costs = carriers.map(function(c) { return c.total_freight_cost; });
    var deliveries = carriers.map(function(c) { return c.total_deliveries; });
    var onTimeRates = carriers.map(function(c) {
        var completed = c.deliveries_on_time + c.deliveries_late;
        return completed > 0 ? Math.round((c.deliveries_on_time / completed) * 100) : null;
    });
    var incidents = carriers.map(function(c) { return c.safety_incidents; });
    var costPerMile = carriers.map(function(c) { return c.total_miles > 0 ? c.total_freight_cost / c.total_miles : null; });

    var bestCost = getBestWorst(costs, false);
    var bestDeliveries = getBestWorst(deliveries, true);
    var bestOnTime = getBestWorst(onTimeRates, true);
    var bestIncidents = getBestWorst(incidents, false);
    var bestCPM = getBestWorst(costPerMile, false);

    carriers.forEach(function(c, i) {
        var completed = c.deliveries_on_time + c.deliveries_late;
        var otRate = completed > 0 ? Math.round((c.deliveries_on_time / completed) * 100) : null;
        var cpm = c.total_miles > 0 ? (c.total_freight_cost / c.total_miles) : null;

        html += '<div class="comparison-stat-col">';
        html += '<h3>' + escapeHtml(c.carrier_name) + '</h3>';

        // Freight Cost
        html += '<div class="comparison-stat-item' + (bestCost.best === i ? ' best' : '') + '">' +
            '<span style="color:#6c757d;">Freight Cost</span>' +
            '<span style="font-weight:600;">$' + Number(c.total_freight_cost).toLocaleString() + '</span></div>';

        // Deliveries
        html += '<div class="comparison-stat-item' + (bestDeliveries.best === i ? ' best' : '') + '">' +
            '<span style="color:#6c757d;">Deliveries</span>' +
            '<span style="font-weight:600;">' + Number(c.total_deliveries).toLocaleString() + '</span></div>';

        // On-Time Rate
        var otClass = otRate === null ? '' : (otRate >= 90 ? 'color:#059669;' : (otRate >= 70 ? 'color:#fbb040;' : 'color:#dc2626;'));
        html += '<div class="comparison-stat-item' + (bestOnTime.best === i ? ' best' : '') + '">' +
            '<span style="color:#6c757d;">On-Time Rate</span>' +
            '<span style="font-weight:600;' + otClass + '">' + (otRate !== null ? otRate + '%' : 'N/A') + '</span></div>';

        // Safety Incidents
        html += '<div class="comparison-stat-item' + (bestIncidents.best === i ? ' best' : '') + '">' +
            '<span style="color:#6c757d;">Safety Incidents</span>' +
            '<span style="font-weight:600;' + (c.safety_incidents === 0 ? 'color:#059669;' : 'color:#dc2626;') + '">' + c.safety_incidents + '</span></div>';

        // Cost per Mile
        html += '<div class="comparison-stat-item' + (bestCPM.best === i ? ' best' : '') + '">' +
            '<span style="color:#6c757d;">Cost per Mile</span>' +
            '<span style="font-weight:600;">' + (cpm !== null ? '$' + cpm.toFixed(2) : 'N/A') + '</span></div>';

        html += '</div>';
    });

    html += '</div>';
    return html;
}

function renderCharts(carriers) {
    var chartColors = ['#488C9A', '#293E4C', '#fbb040', '#E4572E'];
    var names = carriers.map(function(c) { return c.short_name || c.carrier_name; });

    // 1. Freight Cost Bar Chart
    var costCtx = document.getElementById('cmp-cost-chart');
    if (costCtx) {
        comparisonCharts.push(new Chart(costCtx, {
            type: 'bar',
            data: {
                labels: names,
                datasets: [{
                    label: 'Freight Cost ($)',
                    data: carriers.map(function(c) { return c.total_freight_cost; }),
                    backgroundColor: chartColors.slice(0, carriers.length),
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return '$' + ctx.raw.toLocaleString(); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return '$' + v.toLocaleString(); } } }, x: { grid: { display: false } } }
            }
        }));
    }

    // 2. Deliveries Breakdown (on-time vs late)
    var delCtx = document.getElementById('cmp-deliveries-chart');
    if (delCtx) {
        comparisonCharts.push(new Chart(delCtx, {
            type: 'bar',
            data: {
                labels: names,
                datasets: [
                    { label: 'On-Time', data: carriers.map(function(c) { return c.deliveries_on_time; }), backgroundColor: '#059669', borderRadius: 6 },
                    { label: 'Late', data: carriers.map(function(c) { return c.deliveries_late; }), backgroundColor: '#dc2626', borderRadius: 6 }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' } },
                scales: { y: { beginAtZero: true, stacked: false, ticks: { stepSize: 1 } }, x: { grid: { display: false } } }
            }
        }));
    }

    // 3. Radar Chart
    var radarCtx = document.getElementById('cmp-radar-chart');
    if (radarCtx) {
        // Normalize metrics to 0-100
        var maxDeliveries = Math.max.apply(null, carriers.map(function(c) { return c.total_deliveries; })) || 1;
        var maxCPM = Math.max.apply(null, carriers.map(function(c) { return c.total_miles > 0 ? c.total_freight_cost / c.total_miles : 0; })) || 1;

        var labels = ['On-Time Rate', 'Cost Efficiency', 'Safety Score', 'Volume'];
        if (isAdminRole) labels.push('Compliance');

        var datasets = carriers.map(function(c, i) {
            var completed = c.deliveries_on_time + c.deliveries_late;
            var otRate = completed > 0 ? Math.round((c.deliveries_on_time / completed) * 100) : 0;
            var cpm = c.total_miles > 0 ? c.total_freight_cost / c.total_miles : maxCPM;
            var costEff = Math.round(Math.max(0, (1 - cpm / maxCPM) * 100));
            var safetyScore = Math.max(0, 100 - c.safety_incidents * 20);
            var volume = Math.round((c.total_deliveries / maxDeliveries) * 100);

            var dataPoints = [otRate, costEff, safetyScore, volume];

            if (isAdminRole) {
                var compScore = 50; // default
                if (c.authority_status === 'Active' && c.coi_on_file && c.insurance_minimum_met) compScore = 100;
                else if (!c.coi_on_file || c.authority_status === 'Inactive') compScore = 0;
                dataPoints.push(compScore);
            }

            var color = chartColors[i % chartColors.length];
            return {
                label: c.short_name || c.carrier_name,
                data: dataPoints,
                borderColor: color,
                backgroundColor: color + '20',
                pointBackgroundColor: color,
                borderWidth: 2
            };
        });

        comparisonCharts.push(new Chart(radarCtx, {
            type: 'radar',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                scales: { r: { beginAtZero: true, max: 100, ticks: { stepSize: 25 } } },
                plugins: { legend: { position: 'top' } }
            }
        }));
    }
}

function renderMatrix(carriers) {
    var cols = carriers.length;
    var html = '<div class="comparison-matrix"><div style="overflow-x:auto;"><table><thead><tr><th>Metric</th>';
    carriers.forEach(function(c) {
        html += '<th>' + escapeHtml(c.short_name || c.carrier_name) + '</th>';
    });
    html += '</tr></thead><tbody>';

    function addGroupHeader(label) {
        html += '<tr class="group-header"><td colspan="' + (cols + 1) + '">' + label + '</td></tr>';
    }

    function addRow(label, values, higherIsBetter, formatter) {
        var bw = getBestWorst(values, higherIsBetter);
        html += '<tr><td>' + label + '</td>';
        values.forEach(function(v, i) {
            var cls = '';
            if (i === bw.best) cls = 'matrix-best';
            else if (i === bw.worst) cls = 'matrix-worst';
            html += '<td class="' + cls + '">' + (formatter ? formatter(v) : v) + '</td>';
        });
        html += '</tr>';
    }

    var fmtDollar = function(v) { return v !== null && v !== undefined ? '$' + Number(v).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}) : 'N/A'; };
    var fmtDollar2 = function(v) { return v !== null && v !== undefined ? '$' + Number(v).toFixed(2) : 'N/A'; };
    var fmtNum = function(v) { return v !== null && v !== undefined ? Number(v).toLocaleString() : 'N/A'; };
    var fmtPct = function(v) { return v !== null && v !== undefined ? v + '%' : 'N/A'; };
    var fmtDays = function(v) { return v !== null && v !== undefined && v > 0 ? Number(v).toFixed(1) + ' days' : '0'; };

    // Volume & Cost
    addGroupHeader('Volume & Cost');
    addRow('Freight Cost', carriers.map(function(c) { return c.total_freight_cost; }), false, fmtDollar);
    addRow('Deliveries', carriers.map(function(c) { return c.total_deliveries; }), true, fmtNum);
    addRow('Projects', carriers.map(function(c) { return c.project_count; }), true, fmtNum);
    addRow('Total Miles', carriers.map(function(c) { return c.total_miles; }), true, fmtNum);
    addRow('Cost per Mile', carriers.map(function(c) { return c.total_miles > 0 ? c.total_freight_cost / c.total_miles : null; }), false, fmtDollar2);

    // Performance
    addGroupHeader('Performance');
    addRow('On-Time Rate', carriers.map(function(c) {
        var completed = c.deliveries_on_time + c.deliveries_late;
        return completed > 0 ? Math.round((c.deliveries_on_time / completed) * 100) : null;
    }), true, fmtPct);
    addRow('On-Time Count', carriers.map(function(c) { return c.deliveries_on_time; }), true, fmtNum);
    addRow('Late Count', carriers.map(function(c) { return c.deliveries_late; }), false, fmtNum);
    addRow('Avg Days Late', carriers.map(function(c) { return c.avg_days_late; }), false, fmtDays);

    // Risk & Safety
    addGroupHeader('Risk & Safety');
    addRow('Safety Incidents', carriers.map(function(c) { return c.safety_incidents; }), false, fmtNum);
    addRow('Drivers Reported', carriers.map(function(c) { return c.drivers_reported; }), false, fmtNum);
    addRow('Warranty Claims', carriers.map(function(c) { return c.warranty_claims; }), false, fmtNum);

    // Compliance (admin only)
    if (isAdminRole) {
        addGroupHeader('Compliance');
        addRow('COI on File', carriers.map(function(c) { return c.coi_on_file ? 'Yes' : 'No'; }), null, null);
        addRow('Authority Status', carriers.map(function(c) { return c.authority_status || '--'; }), null, null);
        addRow('FMCSA Rating', carriers.map(function(c) { return c.fmcsa_safety_rating || '--'; }), null, null);
    }

    html += '</tbody></table></div></div>';
    return html;
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// Integration with filter toggles: deselect hidden carriers
document.querySelectorAll('.filter-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        setTimeout(function() {
            document.querySelectorAll('tr[data-filter-type]').forEach(function(row) {
                if (row.style.display === 'none') {
                    var cid = parseInt(row.dataset.carrierId);
                    if (selectedCarriers.has(cid)) {
                        selectedCarriers.delete(cid);
                        var cb = row.querySelector('.compare-checkbox');
                        if (cb) cb.checked = false;
                    }
                }
            });
            updateCompareBar();
        }, 10);
    });
});

document.addEventListener('DOMContentLoaded', function() {
    <?php
    $chart_carriers = array_filter($display_carriers, function($d) { return $d['total_deliveries'] > 0 || $d['total_freight_cost'] > 0; });
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
            data: { labels: names, datasets: [{ data: costs, backgroundColor: colors.slice(0, names.length), borderWidth: 0 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { padding: 15, usePointStyle: true } },
                    tooltip: { callbacks: { label: function(ctx) { const val = ctx.raw; const total = ctx.dataset.data.reduce((a,b) => a+b, 0); const pct = total > 0 ? ((val/total)*100).toFixed(1) : 0; return `${ctx.label}: $${val.toLocaleString()} (${pct}%)`; } } }
                },
                cutout: '55%'
            }
        });
    }

    const delCtx = document.getElementById('carrierDeliveriesChart');
    if (delCtx) {
        new Chart(delCtx, {
            type: 'bar',
            data: { labels: names, datasets: [{ label: 'Deliveries', data: deliveries, backgroundColor: colors.slice(0, names.length), borderRadius: 6 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return `${ctx.raw} deliveries`; } } } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { grid: { display: false } } }
            }
        });
    }
    <?php endif; ?>
});
</script>
</body>
</html>
