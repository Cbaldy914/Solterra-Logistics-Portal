<?php
// Initialize session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) { die("Connection failed"); }

$user_id = $_SESSION['user_id'];
$accountIds = [];

$displayName = $_SESSION['username'];
$stmtUser = $conn->prepare("SELECT first_name FROM users WHERE id = ?");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$stmtUser->bind_result($firstName);
$stmtUser->fetch();
$stmtUser->close();
if (!empty($firstName)) { $displayName = $firstName; }

if ($role === 'global_admin') {
    $resultAccts = $conn->query("SELECT id FROM customer_accounts");
    if ($resultAccts) { while ($row = $resultAccts->fetch_assoc()) { $accountIds[] = (int)$row['id']; } }
} else {
    $stmtAccts = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ?");
    $stmtAccts->bind_param("i", $user_id);
    $stmtAccts->execute();
    $resultAccts = $stmtAccts->get_result();
    while ($row = $resultAccts->fetch_assoc()) { $accountIds[] = (int)$row['account_id']; }
    $stmtAccts->close();
}

$accountIds = array_values(array_unique($accountIds));

$unassigned_modules_count = 0;
$unassigned_ordered_mw = 0.0;
if (count($accountIds) > 0) {
    $placeholders_unassigned = implode(',', array_fill(0, count($accountIds), '?'));
    $sqlUnassigned = "
        SELECT
            COALESCE(SUM(umi.quantity), 0) AS unassigned_count,
            COALESCE(SUM(umi.wattage * umi.quantity) / 1000000, 0) AS unassigned_mw
        FROM unassigned_module_items umi
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE m.account_id IN ($placeholders_unassigned)
          AND (m.project_id IS NULL OR m.project_id = 0)
    ";
    $stmtUnassigned = $conn->prepare($sqlUnassigned);
    $stmtUnassigned->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmtUnassigned->execute();
    $stmtUnassigned->bind_result($unassigned_modules_count, $unassigned_ordered_mw);
    $stmtUnassigned->fetch();
    $stmtUnassigned->close();
    $unassigned_modules_count = $unassigned_modules_count ?: 0;
    $unassigned_ordered_mw = $unassigned_ordered_mw ?: 0;
}

$distribution_module_breakdown = [];
if (count($accountIds) > 0) {
    $placeholders_distribution = implode(',', array_fill(0, count($accountIds), '?'));
    $distribution_key = static function ($manufacturer, $scope) {
        return $manufacturer . "\x1F" . $scope;
    };
    $distribution_rows_by_key = [];

    $sqlDistribution = "
        SELECT
            COALESCE(NULLIF(TRIM(m.vendor_name), ''), 'Unknown Manufacturer') AS manufacturer,
            CASE WHEN m.project_id IS NULL OR m.project_id = 0 THEN 'Unassigned' ELSE 'Assigned' END AS assignment_scope,
            COUNT(DISTINCT m.id) AS batch_count,
            COUNT(DISTINCT CASE WHEN m.project_id IS NOT NULL AND m.project_id <> 0 THEN m.project_id END) AS project_count,
            COALESCE(SUM(umi.quantity), 0) AS module_count,
            COALESCE(SUM(umi.wattage * umi.quantity) / 1000000, 0) AS mw_total,
            COALESCE(SUM(CASE WHEN umi.domestic_content_pct IS NOT NULL THEN umi.wattage * umi.quantity END), 0) AS domestic_tracked_watts,
            COALESCE(SUM(CASE WHEN umi.domestic_content_pct IS NOT NULL THEN (umi.wattage * umi.quantity * umi.domestic_content_pct / 100) END), 0) AS domestic_weighted_watts
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.account_id IN ($placeholders_distribution)
        GROUP BY manufacturer, assignment_scope
        ORDER BY manufacturer ASC, assignment_scope DESC
    ";
    $stmtDistribution = $conn->prepare($sqlDistribution);
    $stmtDistribution->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmtDistribution->execute();
    $resultDistribution = $stmtDistribution->get_result();
    while ($row = $resultDistribution->fetch_assoc()) {
        $domesticTrackedWatts = (float)($row['domestic_tracked_watts'] ?? 0);
        $domesticWeightedWatts = (float)($row['domestic_weighted_watts'] ?? 0);
        $domesticPct = null;
        if ($domesticTrackedWatts > 0) {
            $domesticPct = ($domesticWeightedWatts / $domesticTrackedWatts) * 100;
        }

        $manufacturer = $row['manufacturer'] ?? 'Unknown Manufacturer';
        $assignmentScope = $row['assignment_scope'] ?? 'Unassigned';
        $distribution_rows_by_key[$distribution_key($manufacturer, $assignmentScope)] = [
            'manufacturer' => $manufacturer,
            'assignment_scope' => $assignmentScope,
            'batch_count' => (int)($row['batch_count'] ?? 0),
            'project_count' => (int)($row['project_count'] ?? 0),
            'module_count' => (int)($row['module_count'] ?? 0),
            'mw_total' => (float)($row['mw_total'] ?? 0),
            'domestic_content_pct' => $domesticPct,
            'wattage_details' => []
        ];
    }
    $stmtDistribution->close();

    $sqlDistributionByWattage = "
        SELECT
            COALESCE(NULLIF(TRIM(m.vendor_name), ''), 'Unknown Manufacturer') AS manufacturer,
            CASE WHEN m.project_id IS NULL OR m.project_id = 0 THEN 'Unassigned' ELSE 'Assigned' END AS assignment_scope,
            umi.wattage AS wattage,
            COUNT(DISTINCT m.id) AS batch_count,
            COUNT(DISTINCT CASE WHEN m.project_id IS NOT NULL AND m.project_id <> 0 THEN m.project_id END) AS project_count,
            COALESCE(SUM(umi.quantity), 0) AS module_count,
            COALESCE(SUM(umi.wattage * umi.quantity) / 1000000, 0) AS mw_total,
            COALESCE(SUM(CASE WHEN umi.domestic_content_pct IS NOT NULL THEN umi.wattage * umi.quantity END), 0) AS domestic_tracked_watts,
            COALESCE(SUM(CASE WHEN umi.domestic_content_pct IS NOT NULL THEN (umi.wattage * umi.quantity * umi.domestic_content_pct / 100) END), 0) AS domestic_weighted_watts
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.account_id IN ($placeholders_distribution)
        GROUP BY manufacturer, assignment_scope, umi.wattage
        ORDER BY manufacturer ASC, assignment_scope DESC, umi.wattage ASC
    ";
    $stmtDistributionWattage = $conn->prepare($sqlDistributionByWattage);
    $stmtDistributionWattage->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmtDistributionWattage->execute();
    $resultDistributionWattage = $stmtDistributionWattage->get_result();
    while ($rowW = $resultDistributionWattage->fetch_assoc()) {
        $manufacturer = $rowW['manufacturer'] ?? 'Unknown Manufacturer';
        $assignmentScope = $rowW['assignment_scope'] ?? 'Unassigned';
        $key = $distribution_key($manufacturer, $assignmentScope);
        if (!isset($distribution_rows_by_key[$key])) {
            continue;
        }

        $domesticTrackedWatts = (float)($rowW['domestic_tracked_watts'] ?? 0);
        $domesticWeightedWatts = (float)($rowW['domestic_weighted_watts'] ?? 0);
        $domesticPct = null;
        if ($domesticTrackedWatts > 0) {
            $domesticPct = ($domesticWeightedWatts / $domesticTrackedWatts) * 100;
        }

        $distribution_rows_by_key[$key]['wattage_details'][] = [
            'wattage' => (int)($rowW['wattage'] ?? 0),
            'batch_count' => (int)($rowW['batch_count'] ?? 0),
            'project_count' => (int)($rowW['project_count'] ?? 0),
            'module_count' => (int)($rowW['module_count'] ?? 0),
            'mw_total' => (float)($rowW['mw_total'] ?? 0),
            'domestic_content_pct' => $domesticPct
        ];
    }
    $stmtDistributionWattage->close();

    foreach ($distribution_rows_by_key as &$distributionRow) {
        if (!empty($distributionRow['wattage_details'])) {
            usort($distributionRow['wattage_details'], static function ($a, $b) {
                return ($a['wattage'] ?? 0) <=> ($b['wattage'] ?? 0);
            });
        }
    }
    unset($distributionRow);

    $distribution_module_breakdown = array_values($distribution_rows_by_key);
}

// Portfolio cost: sum module cost + freight + accessorial (+ customs hold) across
// all active projects for this account. Previously this was module-cost-only and
// silently fell back to "N/A" when modules.cost_per_watt was unset, even on
// projects that had logistics costs logged. Now we aggregate every cost source
// and let any non-zero subtotal contribute.
$portfolio_module_cost   = 0.0;
$portfolio_logistics_cost = 0.0;
$portfolio_total_cost    = 0.0;
$portfolio_cost_per_watt = null;
$portfolio_total_watts_with_cost = 0.0;
$portfolio_total_watts_overall   = 0.0;
if (count($accountIds) > 0) {
    $placeholders_cost = implode(',', array_fill(0, count($accountIds), '?'));
    $types_cost = str_repeat('i', count($accountIds));

    // Module costs — drop the cost_per_watt IS NOT NULL filter and use COALESCE
    // so batches with a price contribute even when others don't.
    $sqlPortfolioCost = "
        SELECT
            COALESCE(SUM(COALESCE(m.cost_per_watt, 0) * umi.wattage * umi.quantity), 0) AS total_cost,
            COALESCE(SUM(CASE WHEN m.cost_per_watt IS NOT NULL THEN umi.wattage * umi.quantity ELSE 0 END), 0) AS total_watts_with_cost,
            COALESCE(SUM(umi.wattage * umi.quantity), 0) AS total_watts_overall
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        JOIN projects p ON p.id = m.project_id
        WHERE p.account_id IN ($placeholders_cost)
          AND (p.status IS NULL OR p.status = 'active')
    ";
    $stmtPortfolioCost = $conn->prepare($sqlPortfolioCost);
    $stmtPortfolioCost->bind_param($types_cost, ...$accountIds);
    $stmtPortfolioCost->execute();
    $stmtPortfolioCost->bind_result($portfolio_module_cost, $portfolio_total_watts_with_cost, $portfolio_total_watts_overall);
    $stmtPortfolioCost->fetch();
    $stmtPortfolioCost->close();

    // Logistics costs — freight + accessorial from deliveries. The `customer_cost`
    // column is the customer-billable freight rate; fall back to `freight_cost`
    // when it isn't populated. Customs-hold and warehousing are handled per-pallet
    // elsewhere and intentionally excluded from this portfolio rollup to keep
    // the query cheap; module + freight + accessorial is the bulk of any project.
    $sqlPortfolioLogistics = "
        SELECT COALESCE(SUM(
                   COALESCE(NULLIF(d.customer_cost, 0), d.freight_cost, 0)
                 + COALESCE(d.accessorial_costs, 0)
               ), 0) AS logistics_cost
          FROM deliveries d
          JOIN projects p ON p.id = d.project_id
          WHERE p.account_id IN ($placeholders_cost)
            AND (p.status IS NULL OR p.status = 'active')
    ";
    $stmtLogistics = $conn->prepare($sqlPortfolioLogistics);
    if ($stmtLogistics) {
        $stmtLogistics->bind_param($types_cost, ...$accountIds);
        $stmtLogistics->execute();
        $stmtLogistics->bind_result($portfolio_logistics_cost);
        $stmtLogistics->fetch();
        $stmtLogistics->close();
    }

    $portfolio_total_cost = (float)$portfolio_module_cost + (float)$portfolio_logistics_cost;

    // $/W uses watts that actually had a cost_per_watt set (so we don't dilute
    // the per-watt with un-priced modules).
    if (!empty($portfolio_total_watts_with_cost)) {
        $portfolio_cost_per_watt = (float)$portfolio_module_cost / (float)$portfolio_total_watts_with_cost;
    }
}

// --------------- Per-project Module Flow buckets ---------------
// One row per project on the dashboard, each showing a compact 4-bucket
// stacked bar. Single grouped query then post-processed in PHP so we don't
// run N queries inside the project loop.
$project_flow_buckets = []; // project_id => ['at_manufacturer' => int, 'in_transit' => int, 'staged' => int, 'delivered' => int, 'total' => int]
if (count($accountIds) > 0) {
    $sqlProjectFlow = "
        SELECT p.id AS project_id, ip.status, SUM(ip.quantity) AS total
          FROM inventory_pallets ip
          LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
          LEFT JOIN modules m ON umi.unassigned_module_id = m.id
          LEFT JOIN projects p ON (p.id = m.project_id OR p.id = ip.assigned_project_id OR p.id = ip.current_project_id)
          WHERE p.account_id IN ($placeholders_cost)
            AND (p.status IS NULL OR p.status = 'active')
            AND ip.status IS NOT NULL
            AND p.id IS NOT NULL
          GROUP BY p.id, ip.status
    ";
    $stmtPF = $conn->prepare($sqlProjectFlow);
    if ($stmtPF) {
        $stmtPF->bind_param($types_cost, ...$accountIds);
        $stmtPF->execute();
        $rpf = $stmtPF->get_result();
        while ($row = $rpf->fetch_assoc()) {
            $pid = (int)$row['project_id'];
            $qty = (int)$row['total'];
            if (!isset($project_flow_buckets[$pid])) {
                $project_flow_buckets[$pid] = [
                    'at_manufacturer' => 0,
                    'in_transit'      => 0,
                    'staged'          => 0,
                    'delivered'       => 0,
                    'total'           => 0,
                ];
            }
            switch ($row['status']) {
                case 'At Manufacturer':
                case 'On Water':
                case 'Customs Hold':
                case 'Cleared Customs':
                    $project_flow_buckets[$pid]['at_manufacturer'] += $qty;
                    break;
                case 'In Transit to Warehouse':
                case 'In Transit to Project':
                    $project_flow_buckets[$pid]['in_transit'] += $qty;
                    break;
                case 'In Warehouse':
                    $project_flow_buckets[$pid]['staged'] += $qty;
                    break;
                case 'Delivered to Project':
                    $project_flow_buckets[$pid]['delivered'] += $qty;
                    break;
            }
        }
        foreach ($project_flow_buckets as $pid => &$buckets) {
            $buckets['total'] = $buckets['at_manufacturer'] + $buckets['in_transit'] + $buckets['staged'] + $buckets['delivered'];
        }
        unset($buckets);
        $stmtPF->close();
    }
}

// --------------- Recent activity across the portfolio ---------------
// Last 5 completed events (delivered / arrived / departed) across every active
// project for this account. Same shape and grouping as the project_overview
// Recent Activity card so customers see consistent data wherever they look.
$recent_activity = [];
if (count($accountIds) > 0) {
    $sqlRecent = "
        SELECT MAX(sub.event_date) AS event_date,
               sub.event_type,
               sub.project_id,
               sub.project_name,
               sub.status_of_delivery,
               sub.origin_type,
               sub.origin_id,
               sub.dest_warehouse_id,
               w_dest.name   AS dest_warehouse_name,
               w_origin.name AS origin_warehouse_name,
               SUM(sub.qty)            AS qty,
               SUM(sub.pallet_count)   AS pallet_count,
               COUNT(*)                AS shipment_count
          FROM (
              SELECT
                  d.id,
                  d.project_id,
                  p.project_name,
                  d.status_of_delivery,
                  d.origin_type,
                  d.origin_id,
                  d.warehouse_id AS dest_warehouse_id,
                  d.quantity AS qty,
                  IFNULL(pc.cnt, 0) AS pallet_count,
                  CASE
                      WHEN d.status_of_delivery = 'Delivered to Project' THEN 'delivered'
                      WHEN d.warehouse_arrival_date IS NOT NULL THEN 'arrived'
                      WHEN d.left_warehouse_date IS NOT NULL THEN 'departed'
                      ELSE 'pending'
                  END AS event_type,
                  DATE(COALESCE(
                      CASE WHEN d.status_of_delivery = 'Delivered to Project' THEN d.actual_delivery_date END,
                      d.warehouse_arrival_date,
                      d.left_warehouse_date
                  )) AS event_date
                FROM deliveries d
                JOIN projects p ON p.id = d.project_id
                LEFT JOIN (SELECT delivery_id, COUNT(*) AS cnt FROM delivery_pallets GROUP BY delivery_id) pc
                       ON pc.delivery_id = d.id
                WHERE p.account_id IN ($placeholders_cost)
                  AND (p.status IS NULL OR p.status = 'active')
                  AND (d.actual_delivery_date IS NOT NULL
                       OR d.warehouse_arrival_date IS NOT NULL
                       OR d.left_warehouse_date IS NOT NULL)
          ) sub
          LEFT JOIN warehouses w_dest   ON sub.dest_warehouse_id = w_dest.id
          LEFT JOIN warehouses w_origin ON (sub.origin_type = 'warehouse' AND sub.origin_id = w_origin.id)
          WHERE sub.event_date IS NOT NULL
          GROUP BY sub.event_date, sub.event_type, sub.project_id, sub.status_of_delivery,
                   sub.origin_type, sub.origin_id, sub.dest_warehouse_id
          ORDER BY event_date DESC
          LIMIT 5
    ";
    $stmtRecent = $conn->prepare($sqlRecent);
    if ($stmtRecent) {
        $stmtRecent->bind_param($types_cost, ...$accountIds);
        $stmtRecent->execute();
        $rrec = $stmtRecent->get_result();
        while ($row = $rrec->fetch_assoc()) {
            $recent_activity[] = $row;
        }
        $stmtRecent->close();
    }
}

$projects = [];
$dashboard_totals = [
    'total_modules' => 0, 'total_delivered' => 0, 'total_in_storage' => 0,
    'total_project_size_mw' => 0, 'total_ordered_mw' => 0, 'delivered_mw' => 0, 'storage_mw' => 0,
    'health_counts' => ['on_track' => 0, 'at_risk' => 0, 'behind' => 0, 'completed' => 0]
];

$archived_count = 0;
if (count($accountIds) > 0) {
    $placeholders_archived = implode(',', array_fill(0, count($accountIds), '?'));
    $stmtArchived = $conn->prepare("SELECT COUNT(*) FROM archived_projects WHERE account_id IN ($placeholders_archived)");
    $stmtArchived->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmtArchived->execute();
    $stmtArchived->bind_result($archived_count);
    $stmtArchived->fetch();
    $stmtArchived->close();
}

if (count($accountIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $stmt = $conn->prepare("SELECT id, project_name, project_address, image_url, estimated_completion_date, project_size FROM projects WHERE account_id IN ($placeholders) AND (status IS NULL OR status = 'active') ORDER BY id ASC");
    $stmt->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $project_id = $row['id'];
        $project = $row;

        $stmt_wattage = $conn->prepare("SELECT wattage, total_order FROM project_wattage_orders WHERE project_id = ? ORDER BY wattage ASC");
        $stmt_wattage->bind_param("i", $project_id);
        $stmt_wattage->execute();
        $wattage_result = $stmt_wattage->get_result();
        $stmt_wattage->close();

        $total_order_quantity = 0;
        $weighted_wattage_sum = 0;
        $wattage_breakdown = [];
        while ($wattage_row = $wattage_result->fetch_assoc()) {
            $qty = (int)$wattage_row['total_order'];
            $watt = (float)$wattage_row['wattage'];
            $total_order_quantity += $qty;
            $weighted_wattage_sum += ($qty * $watt);
            if ($qty > 0) { $wattage_breakdown[] = ['wattage' => $watt, 'quantity' => $qty, 'mw' => ($qty * $watt) / 1000000]; }
        }

        $avg_wattage = $total_order_quantity > 0 ? $weighted_wattage_sum / $total_order_quantity : 0;
        $project['project_size'] = isset($project['project_size']) ? (float)$project['project_size'] : 0;
        $project['total_modules'] = $total_order_quantity;
        $project['wattage_breakdown'] = $wattage_breakdown;
        $project['ordered_mw'] = ($total_order_quantity * $avg_wattage) / 1000000;
        $project['order_progress'] = $project['project_size'] > 0 ? min(100, ($project['ordered_mw'] / $project['project_size']) * 100) : 0;

        $stmt_delivered = $conn->prepare("SELECT d.wattage, SUM(d.quantity) as delivered_qty FROM deliveries d WHERE d.project_id = ? AND d.status_of_delivery = 'Delivered to Project' GROUP BY d.wattage");
        $stmt_delivered->bind_param("i", $project_id);
        $stmt_delivered->execute();
        $delivered_result = $stmt_delivered->get_result();
        $stmt_delivered->close();

        $total_delivered = 0;
        $delivered_mw = 0;
        $delivered_breakdown = [];
        while ($del_row = $delivered_result->fetch_assoc()) {
            $del_qty = (int)$del_row['delivered_qty'];
            $del_watt = (float)$del_row['wattage'];
            $total_delivered += $del_qty;
            $del_mw = ($del_qty * $del_watt) / 1000000;
            $delivered_mw += $del_mw;
            if ($del_qty > 0) { $delivered_breakdown[] = ['wattage' => $del_watt, 'quantity' => $del_qty, 'mw' => $del_mw]; }
        }
        $project['delivered_modules'] = $total_delivered;
        $project['delivered_mw'] = $delivered_mw;
        $project['delivered_breakdown'] = $delivered_breakdown;
        $project['delivery_progress'] = $project['project_size'] > 0 ? min(100, ($delivered_mw / $project['project_size']) * 100) : 0;

        $stmt_storage = $conn->prepare("SELECT SUM(ip.quantity) AS total_in_storage FROM inventory_pallets ip WHERE ip.assigned_project_id = ? AND ip.status = 'In Warehouse'");
        $stmt_storage->bind_param("i", $project_id);
        $stmt_storage->execute();
        $stmt_storage->bind_result($total_in_storage);
        $stmt_storage->fetch();
        $stmt_storage->close();
        $total_in_storage = $total_in_storage ?: 0;
        $project['storage_modules'] = $total_in_storage;
        $project['storage_mw'] = ($total_in_storage * $avg_wattage) / 1000000;

        $stmt_pallets = $conn->prepare("SELECT COUNT(*) FROM inventory_pallets WHERE assigned_project_id = ?");
        $stmt_pallets->bind_param("i", $project_id);
        $stmt_pallets->execute();
        $stmt_pallets->bind_result($pallet_count);
        $stmt_pallets->fetch();
        $stmt_pallets->close();

        $stmt_transit = $conn->prepare("SELECT SUM(quantity) FROM deliveries WHERE project_id = ? AND status_of_delivery IN ('On Water', 'In Transit to Warehouse', 'In Transit to Project')");
        $stmt_transit->bind_param("i", $project_id);
        $stmt_transit->execute();
        $stmt_transit->bind_result($in_transit);
        $stmt_transit->fetch();
        $stmt_transit->close();
        $project['in_transit'] = $in_transit ?: 0;

        $timeline_step = 1;
        if ($total_order_quantity > 0) $timeline_step = 2;
        if ($pallet_count > 0) $timeline_step = 3;
        if ($total_delivered > 0 || ($in_transit ?: 0) > 0) $timeline_step = 4;
        if ($project['delivery_progress'] >= 100) $timeline_step = 5;
        $project['timeline_step'] = $timeline_step;
        $timeline_labels = [1 => 'Planning', 2 => 'Ordered', 3 => 'Palletized', 4 => 'Shipping', 5 => 'Completed'];
        $project['timeline_label'] = $timeline_labels[$timeline_step];

        $today = new DateTime();
        $health = 'on_track';
        $health_text = 'On Track';
        $health_reason = 'Project is progressing normally';

        if ($project['delivery_progress'] >= 100) {
            $health = 'completed';
            $health_text = 'Completed';
            $health_reason = 'All modules have been delivered to the project site';
        } elseif (!empty($project['estimated_completion_date'])) {
            $est_date = new DateTime($project['estimated_completion_date']);
            $diff = $today->diff($est_date);
            $days_remaining = $diff->days;
            $is_past = $est_date < $today;

            if ($is_past && $project['delivery_progress'] < 100) {
                $health = 'behind';
                $health_text = 'Behind';
                $health_reason = 'Past completion date (' . $est_date->format('M j, Y') . ') with ' . round($project['delivery_progress']) . '% delivered';
            } elseif (!$is_past && $days_remaining <= 30 && $project['delivery_progress'] < 80) {
                $health = 'at_risk';
                $health_text = 'At Risk';
                $health_reason = $days_remaining . ' days until deadline with only ' . round($project['delivery_progress']) . '% delivered';
            } else {
                $health_reason = $is_past ? 'Completed on schedule' : $days_remaining . ' days remaining with ' . round($project['delivery_progress']) . '% delivered';
            }
        }
        $project['health'] = $health;
        $project['health_text'] = $health_text;
        $project['health_reason'] = $health_reason;
        $dashboard_totals['health_counts'][$health]++;

        $dashboard_totals['total_modules'] += $total_order_quantity;
        $dashboard_totals['total_delivered'] += $total_delivered;
        $dashboard_totals['total_in_storage'] += $total_in_storage;
        $dashboard_totals['total_project_size_mw'] += $project['project_size'];
        $dashboard_totals['total_ordered_mw'] += $project['ordered_mw'];
        $dashboard_totals['delivered_mw'] += $delivered_mw;
        $dashboard_totals['storage_mw'] += $project['storage_mw'];

        $projects[] = $project;
    }
    $stmt->close();
}

$assigned_ordered_mw = (float)$dashboard_totals['total_ordered_mw'];
$total_ordered_with_unassigned_mw = $assigned_ordered_mw + (float)$unassigned_ordered_mw;
$mw_gap_total = (float)$dashboard_totals['total_project_size_mw'] - $total_ordered_with_unassigned_mw;

$coverage_target_mw = max(0, (float)$dashboard_totals['total_project_size_mw']);
$coverage_total_mw = $coverage_target_mw > 0 ? min($coverage_target_mw, $total_ordered_with_unassigned_mw) : $total_ordered_with_unassigned_mw;
$coverage_gap_mw = max(0, $coverage_target_mw - $coverage_total_mw);

// Get open warranty claims count per project
$warranty_counts = [];
$total_open_claims = 0;
if (count($accountIds) > 0) {
    $project_ids = array_column($projects, 'id');
    if (!empty($project_ids)) {
        $placeholders_warranty = implode(',', array_fill(0, count($project_ids), '?'));
        $sqlWarranty = "SELECT ss.project_id, COUNT(*) as open_claims
                        FROM warranty_claims w
                        JOIN site_scheduling ss ON ss.id = w.scheduling_id
                        WHERE ss.project_id IN ($placeholders_warranty) AND w.status <> 'Closed'
                        GROUP BY ss.project_id";
        $stmtWarranty = $conn->prepare($sqlWarranty);
        $stmtWarranty->bind_param(str_repeat('i', count($project_ids)), ...$project_ids);
        $stmtWarranty->execute();
        $resultWarranty = $stmtWarranty->get_result();
        while ($row = $resultWarranty->fetch_assoc()) {
            $warranty_counts[(int)$row['project_id']] = (int)$row['open_claims'];
            $total_open_claims += (int)$row['open_claims'];
        }
        $stmtWarranty->close();
    }
}

// Add warranty counts to projects array
foreach ($projects as &$project) {
    $project['open_claims'] = $warranty_counts[$project['id']] ?? 0;
}
unset($project);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-header {
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .dashboard-header h1 {
            margin: 0;
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .dashboard-header p { margin: 8px 0 0; font-size: 1.1em; color: #6c757d; }
        .unit-toggle { display: inline-flex; background: #f1f3f4; border-radius: 8px; padding: 3px; gap: 3px; }
        .unit-toggle button { padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 0.85em; border: none; cursor: pointer; color: #6c757d; background: transparent; transition: all 0.2s; }
        .unit-toggle button:hover { color: #293E4C; background: rgba(255,255,255,0.5); }
        .unit-toggle button.active { background: #fff; color: #293E4C; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }

        .stats-charts-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; align-items: stretch; }
        .stats-section { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .stat-card { background: #fff; padding: 12px; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; text-align: center; transition: transform 0.2s, box-shadow 0.2s; text-decoration: none; color: inherit; display: block; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); text-decoration: none; color: inherit; }
        .stat-card.clickable { cursor: pointer; }
        .stat-icon { font-size: 1.5em; margin-bottom: 4px; opacity: 0.8; }
        .stat-number { font-size: 1.5em; font-weight: 700; color: #488C9A; margin: 0; }
        .stat-label { font-size: 0.8em; color: #6c757d; margin: 2px 0 0; font-weight: 500; }

        .charts-section { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .chart-card { background: #fff; padding: 16px; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; }
        .chart-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
        .chart-card h3 { margin: 0 0 12px; color: #293E4C; font-size: 1em; font-weight: 600; }
        .chart-content { display: flex; align-items: center; gap: 16px; flex: 1; }
        .chart-container { position: relative; width: 110px; height: 110px; flex-shrink: 0; }
        .chart-legend { flex: 1; font-size: 0.9em; }
        .legend-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f1f3f4; }
        .legend-item:last-child { border-bottom: none; }
        .legend-item.clickable-filter:hover { background: #f0f8fa !important; }
        .legend-label { display: flex; align-items: center; gap: 8px; color: #495057; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; }
        .legend-value { font-weight: 600; color: #293E4C; }
        .coverage-summary { padding: 10px; background: #f8f9fa; border-radius: 6px; font-size: 0.9em; }
        .coverage-row { display: flex; justify-content: space-between; padding: 5px 0; }
        .coverage-row span:first-child { color: #6c757d; }
        .coverage-row span:last-child { font-weight: 600; color: #293E4C; }
        .coverage-row.highlight span:last-child { color: #28a745; }
        .coverage-row.warning span:last-child { color: #dc3545; }

        .section-header-row { display: flex; justify-content: space-between; align-items: center; margin: 20px 0 15px; flex-wrap: wrap; gap: 12px; padding: 20px 24px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 16px; border: 1px solid #e9ecef; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .section-header { font-size: 1.4em; font-weight: 700; background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0; letter-spacing: -0.3px; }
        .section-controls { display: flex; align-items: center; gap: 12px; }
        .section-title-row { display: flex; align-items: center; gap: 12px; }
        .view-archived-link { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 20px; color: #6c757d; font-size: 0.8em; font-weight: 500; text-decoration: none; transition: all 0.2s; }
        .view-archived-link:hover { background: #e8f4f7; border-color: #488C9A; color: #488C9A; }
        .view-archived-link .archived-count { background: #488C9A; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 0.9em; font-weight: 600; }
        .active-filter-banner { display: none; align-items: center; gap: 12px; padding: 12px 16px; background: linear-gradient(135deg, #fff3cd 0%, #fff9e6 100%); border: 1px solid #ffc107; border-radius: 10px; margin-bottom: 16px; }
        .active-filter-banner.visible { display: flex; }
        .active-filter-banner .filter-text { flex: 1; font-size: 0.9em; color: #856404; font-weight: 500; }
        .active-filter-banner .filter-status { font-weight: 700; }
        .active-filter-banner .clear-filter-btn { padding: 6px 12px; background: #fff; border: 1px solid #ffc107; border-radius: 6px; color: #856404; font-size: 0.8em; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .active-filter-banner .clear-filter-btn:hover { background: #ffc107; color: #fff; }
        .view-toggle { display: inline-flex; background: #f1f3f4; border-radius: 8px; padding: 3px; gap: 3px; }
        .view-toggle button { padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 1em; border: none; cursor: pointer; color: #6c757d; background: transparent; transition: all 0.2s; }
        .view-toggle button:hover { color: #293E4C; background: rgba(255,255,255,0.5); }
        .view-toggle button.active { background: #fff; color: #293E4C; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }

        .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 28px; justify-content: start; margin-top: 20px; }
        @media (min-width: 1400px) { .projects-grid { grid-template-columns: repeat(5, 1fr); } }
        .project-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; overflow: hidden; transition: all 0.2s; cursor: pointer; }
        .project-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,0.12); }
        .project-card-image { width: 100%; height: 160px; background: #f0f2f4; position: relative; overflow: hidden; }
        .project-card-image img { width: 100%; height: 100%; object-fit: cover; }
        .project-card-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(45deg, rgba(72,140,154,0.9), rgba(58,110,127,0.9)); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
        .project-card:hover .project-card-overlay { opacity: 1; }
        .project-card-overlay span { color: #fff; font-size: 1em; font-weight: 600; }
        .project-card-content { padding: 14px; }
        .project-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; gap: 8px; }
        .project-card-title { margin: 0; font-size: 1.05em; color: #293E4C; font-weight: 600; flex: 1; line-height: 1.3; }
        .health-badge { padding: 3px 8px; border-radius: 10px; font-size: 0.65em; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; cursor: help; position: relative; }
        .health-badge:hover .health-tooltip { opacity: 1; visibility: visible; }
        .health-tooltip { position: absolute; bottom: calc(100% + 6px); right: 0; background: #293E4C; color: #fff; padding: 8px 10px; border-radius: 6px; font-size: 10px; font-weight: 400; text-transform: none; letter-spacing: 0; white-space: normal; width: 180px; text-align: left; opacity: 0; visibility: hidden; transition: all 0.2s; z-index: 100; line-height: 1.4; }
        .health-tooltip::after { content: ''; position: absolute; top: 100%; right: 10px; border: 5px solid transparent; border-top-color: #293E4C; }
        .health-on_track { background: #d4edda; color: #155724; }
        .health-at_risk { background: #fff3cd; color: #856404; }
        .health-behind { background: #f8d7da; color: #721c24; }
        .health-completed { background: #cce7ff; color: #004085; }
        .project-card-location { color: #6c757d; font-size: 0.75em; margin-bottom: 12px; }

        .progress-rings-row { display: flex; justify-content: center; gap: 24px; margin-bottom: 12px; }
        .progress-ring-item { text-align: center; cursor: pointer; padding: 6px; border-radius: 8px; transition: background 0.2s; }
        .progress-ring-item:hover { background: #f8f9fa; }
        .progress-ring { width: 64px; height: 64px; position: relative; margin: 0 auto 4px; }
        .progress-ring svg { transform: rotate(-90deg); }
        .progress-ring-bg { fill: none; stroke: #e9ecef; stroke-width: 5; }
        .progress-ring-fill { fill: none; stroke-width: 5; stroke-linecap: round; transition: stroke-dashoffset 0.8s; }
        .progress-ring-fill.order { stroke: #488C9A; }
        .progress-ring-fill.delivery { stroke: #28a745; }
        .progress-ring-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
        .progress-ring-value { font-size: 0.95em; font-weight: 700; color: #293E4C; line-height: 1; }
        .progress-ring-label { font-size: 0.55em; color: #6c757d; }
        .ring-title { font-size: 0.7em; color: #495057; font-weight: 500; }
        .ring-subtitle { font-size: 0.65em; color: #999; }

        .project-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 10px; }
        .info-item { padding: 6px 8px; background: #f8f9fa; border-radius: 6px; }
        .info-label { font-size: 0.65em; color: #6c757d; }
        .info-value { font-size: 0.85em; font-weight: 600; color: #293E4C; }

        .storage-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #fff3cd; border-radius: 6px; font-size: 0.7em; color: #856404; margin-top: 8px; }
        .storage-badge strong { color: #664d03; }
        .warranty-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #f8d7da; border-radius: 6px; font-size: 0.7em; color: #721c24; margin-top: 8px; margin-left: 6px; }
        .warranty-badge strong { color: #491217; }
        .project-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 0; }

        .add-project-card { display: flex; align-items: center; justify-content: center; flex-direction: column; border: 2px dashed #d0d0d0; background: #f9f9f9; color: #6c757d; min-height: 360px; border-radius: 12px; cursor: pointer; transition: all 0.2s; }
        .add-project-card:hover { border-color: #488C9A; background: #f0f8fa; color: #488C9A; transform: translateY(-4px); }
        .add-project-icon { font-size: 3em; margin-bottom: 10px; }
        .add-project-text { font-size: 1.1em; font-weight: 600; }

        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; visibility: hidden; transition: all 0.3s; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-content { background: #fff; border-radius: 16px; padding: 24px; max-width: 420px; width: 90%; max-height: 80vh; overflow-y: auto; transform: scale(0.9); transition: transform 0.3s; }
        .modal-overlay.active .modal-content { transform: scale(1); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-header h3 { margin: 0; color: #293E4C; font-size: 1.1em; }
        .modal-close { background: none; border: none; font-size: 1.5em; cursor: pointer; color: #6c757d; padding: 0; line-height: 1; }
        .modal-close:hover { color: #293E4C; }
        .modal-summary { display: flex; align-items: center; gap: 14px; padding: 14px; background: #f8f9fa; border-radius: 10px; margin-bottom: 16px; }
        .modal-ring { width: 70px; height: 70px; position: relative; flex-shrink: 0; }
        .modal-ring svg { transform: rotate(-90deg); }
        .modal-ring-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
        .modal-ring-value { font-size: 0.9em; font-weight: 700; color: #293E4C; }
        .modal-ring-label { font-size: 0.6em; color: #6c757d; }
        .modal-summary-info { flex: 1; }
        .modal-summary-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 0.85em; }
        .modal-summary-row span:first-child { color: #6c757d; }
        .modal-summary-row span:last-child { font-weight: 600; color: #293E4C; }
        .modal-breakdown h4 { margin: 0 0 10px; font-size: 0.9em; color: #293E4C; }
        .breakdown-table { width: 100%; border-collapse: collapse; }
        .breakdown-table th { text-align: left; padding: 8px 10px; background: #f8f9fa; font-size: 0.75em; font-weight: 600; color: #6c757d; border-bottom: 2px solid #e9ecef; }
        .breakdown-table td { padding: 8px 10px; border-bottom: 1px solid #f1f3f4; font-size: 0.85em; }
        .breakdown-table tr:last-child td { border-bottom: none; }

        .projects-table-container { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; overflow: hidden; display: none; }
        .projects-table-container.active { display: block; }
        .projects-grid.active { display: grid; }
        .projects-grid:not(.active) { display: none; }
        .projects-table { width: 100%; border-collapse: collapse; }
        .projects-table th { background: #488C9A; padding: 10px 12px; text-align: left; font-weight: 600; color: #fff; font-size: 0.75em; border-bottom: none; cursor: pointer; white-space: nowrap; }
        .projects-table th:hover { background: #3A6E7F; }
        .projects-table th .sort-icon { margin-left: 4px; opacity: 0.5; }
        .projects-table td { padding: 10px 12px; border-bottom: 1px solid #f1f3f4; color: #495057; font-size: 0.8em; vertical-align: middle; }
        .projects-table tr { cursor: pointer; transition: background 0.2s; }
        .projects-table tbody tr:hover { background: #f8f9fa; }
        .table-project-name { font-weight: 600; color: #293E4C; }
        .table-progress { display: flex; align-items: center; gap: 6px; }
        .table-progress-bar { flex: 1; height: 5px; background: #e9ecef; border-radius: 3px; overflow: hidden; min-width: 50px; }
        .table-progress-fill { height: 100%; border-radius: 3px; }
        .table-progress-fill.order { background: linear-gradient(90deg, #488C9A, #5AA8B7); }
        .table-progress-fill.delivery { background: linear-gradient(90deg, #28a745, #34ce57); }
        .table-progress-text { font-weight: 600; min-width: 35px; font-size: 0.9em; }
        .table-progress-text.order { color: #488C9A; }
        .table-progress-text.delivery { color: #28a745; }
        .table-wattages { display: flex; flex-direction: column; gap: 1px; }
        .table-wattage-row { font-size: 0.9em; color: #495057; }
        .table-wattage-row strong { color: #293E4C; }

        .no-projects { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; text-align: center; }
        .no-projects h2 { color: #293E4C; margin-bottom: 8px; }
        .no-projects p { color: #6c757d; }

        /* % Delivered stat card with inline progress bar */
        .stat-card-progress { position: relative; }
        .stat-progress-track { width: 100%; height: 6px; background: #f1f3f4; border-radius: 3px; overflow: hidden; margin-top: 10px; }
        .stat-progress-fill { height: 100%; background: linear-gradient(90deg, #488C9A 0%, #28a745 100%); border-radius: 3px; transition: width 0.4s ease; }

        /* Portfolio Module Flow card — per-project rows */
        .portfolio-flow-card { display: flex; flex-direction: column; }
        .portfolio-flow-header { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
        .portfolio-flow-header h3 { margin: 0; color: #293E4C; font-size: 1em; font-weight: 600; }
        .portfolio-flow-content { flex: 1; display: flex; flex-direction: column; gap: 6px; padding: 4px 0; }

        .portfolio-flow-legend { display: flex; flex-wrap: wrap; gap: 6px 12px; }
        .portfolio-flow-legend-item { display: inline-flex; align-items: center; gap: 5px; font-size: 0.7em; color: #6c757d; font-weight: 500; }
        .portfolio-flow-legend-dot { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }
        .portfolio-flow-legend-dot.pf-mfr       { background: #293E4C; }
        .portfolio-flow-legend-dot.pf-transit   { background: #9370DB; }
        .portfolio-flow-legend-dot.pf-staged    { background: #E07F3A; }
        .portfolio-flow-legend-dot.pf-delivered { background: #488C9A; }

        .project-flow-list { display: flex; flex-direction: column; gap: 4px; }
        .project-flow-row { display: grid; grid-template-columns: minmax(80px, 1fr) 2fr auto; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: inherit; transition: background 0.15s ease, border-color 0.15s ease; border: 1px solid transparent; }
        .project-flow-row:hover { background: #f8f9fa; border-color: #e9ecef; text-decoration: none; }
        .project-flow-name { font-weight: 600; color: #293E4C; font-size: 0.85em; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .project-flow-bar { display: flex; height: 14px; border-radius: 4px; overflow: hidden; background: #f1f3f4; min-width: 80px; }
        .project-flow-seg { transition: filter 0.15s ease; min-width: 0; }
        .project-flow-seg:hover { filter: brightness(1.08); }
        .project-flow-seg.pf-mfr       { background: #293E4C; }
        .project-flow-seg.pf-transit   { background: #9370DB; }
        .project-flow-seg.pf-staged    { background: #E07F3A; }
        .project-flow-seg.pf-delivered { background: #488C9A; }
        .project-flow-summary { font-size: 0.75em; color: #488C9A; font-weight: 700; flex-shrink: 0; min-width: 56px; text-align: right; }

        .project-flow-viewall { background: none; border: 1px dashed #cbd5e0; color: #488C9A; font-size: 0.78em; font-weight: 600; padding: 6px 10px; border-radius: 6px; cursor: pointer; transition: background 0.15s ease; margin-top: 4px; }
        .project-flow-viewall:hover { background: rgba(72,140,154,0.06); }

        .portfolio-flow-empty { text-align: center; padding: 20px 12px; color: #9ca3af; flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; }
        .portfolio-flow-empty i { font-size: 1.6rem; opacity: 0.5; }
        .portfolio-flow-empty p { margin: 0; font-size: 0.85em; }

        /* Recent Activity card (compact, replaces Active Shipments strip) */
        .recent-activity-card { background: #fff; padding: 14px 18px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; margin-bottom: 20px; }
        .recent-activity-header { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 8px; }
        .recent-activity-header h3 { margin: 0; color: #293E4C; font-size: 1em; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .recent-activity-header h3 i { color: #488C9A; }
        .recent-activity-count { font-size: 0.72em; color: #6c757d; font-weight: 500; }
        .recent-activity-empty { color: #9ca3af; font-style: italic; font-size: 0.85em; padding: 4px 0 6px; }

        .recent-activity-list { list-style: none; margin: 0; padding: 0; }
        .recent-activity-list li { border-bottom: 1px dashed #f1f3f4; }
        .recent-activity-list li:last-child { border-bottom: none; }
        .recent-activity-row { display: flex; align-items: center; gap: 10px; padding: 8px 4px; text-decoration: none; color: inherit; font-size: 0.85em; transition: background 0.15s ease; border-radius: 4px; }
        .recent-activity-row:hover { background: #f8f9fa; text-decoration: none; }
        .recent-activity-icon { width: 26px; height: 26px; border-radius: 50%; background: #f8f9fa; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75em; flex-shrink: 0; }
        .recent-activity-row.rev-delivered .recent-activity-icon { background: rgba(40,167,69,0.12);  color: #1e7e34; }
        .recent-activity-row.rev-arrived   .recent-activity-icon { background: rgba(72,140,154,0.12); color: #3A6E7F; }
        .recent-activity-row.rev-departed  .recent-activity-icon { background: rgba(147,112,219,0.12); color: #6d28d9; }
        .recent-activity-text { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #293E4C; }
        .recent-activity-verb { color: #6c757d; font-weight: 500; }
        .recent-activity-where { font-weight: 700; }
        .recent-activity-from { color: #6c757d; font-size: 0.92em; }
        .recent-activity-project { color: #6c757d; font-size: 0.82em; flex-shrink: 0; max-width: 25%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .recent-activity-pill { background: rgba(147,112,219,0.12); color: #6d28d9; padding: 1px 7px; border-radius: 999px; font-size: 0.7em; font-weight: 700; flex-shrink: 0; }
        .recent-activity-when { color: #488C9A; font-weight: 600; font-size: 0.8em; flex-shrink: 0; min-width: 60px; text-align: right; }

        @media (max-width: 768px) {
            .recent-activity-project { display: none; }
            .recent-activity-from { display: none; }
        }

        @media (max-width: 992px) { .stats-charts-row { grid-template-columns: 1fr; } .charts-section { grid-template-columns: 1fr 1fr; } .chart-content { flex-direction: row; } .chart-container { width: 100px; height: 100px; } }
        @media (max-width: 768px) { .dashboard-header { flex-direction: column; align-items: flex-start; } .dashboard-header h1 { font-size: 1.5em; } .stats-section { grid-template-columns: repeat(2, 1fr); } .stat-number { font-size: 1.3em; } .charts-section { grid-template-columns: 1fr; } .chart-container { width: 110px; height: 110px; } .projects-grid { grid-template-columns: 1fr; } .projects-table-container { overflow-x: auto; } .projects-table { min-width: 980px; } }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="dashboard-header">
        <div>
            <h1>Welcome back, <?php echo htmlspecialchars($displayName); ?>!</h1>
            <p>Here's an overview of your projects and modules</p>
        </div>
        <div class="unit-toggle">
            <button class="active" onclick="setUnit('modules')" id="btn-modules">Modules</button>
            <button onclick="setUnit('mw')" id="btn-mw">MW</button>
        </div>
    </div>
    <?php
    // Headline portfolio KPIs used by the redesigned hero stat row.
    $portfolio_total_modules_tracked = (int)$dashboard_totals['total_modules'] + (int)$unassigned_modules_count;
    $portfolio_pct_delivered = ($dashboard_totals['total_project_size_mw'] > 0)
        ? max(0, min(100, ($dashboard_totals['delivered_mw'] / $dashboard_totals['total_project_size_mw']) * 100))
        : 0;
    ?>

    <div class="stats-charts-row">
        <div class="stats-section">
            <div class="stat-card clickable" onclick="document.getElementById('projects-section').scrollIntoView({behavior:'smooth'})">
                <div class="stat-icon">📊</div>
                <h3 class="stat-number"><?php echo count($projects); ?></h3>
                <p class="stat-label">Active Projects</p>
            </div>
            <a href="modules.php" class="stat-card clickable">
                <div class="stat-icon">📦</div>
                <h3 class="stat-number">
                    <span class="unit-modules"><?php echo number_format($portfolio_total_modules_tracked); ?></span>
                    <span class="unit-mw" style="display:none"><?php echo number_format($total_ordered_with_unassigned_mw, 2); ?></span>
                </h3>
                <p class="stat-label">Modules <span class="unit-label-modules">Tracked</span><span class="unit-label-mw" style="display:none">MW</span></p>
            </a>
            <div class="stat-card stat-card-progress">
                <div class="stat-icon">🚚</div>
                <h3 class="stat-number"><?php echo number_format($portfolio_pct_delivered, 1); ?>%</h3>
                <p class="stat-label">Delivered to Site</p>
                <div class="stat-progress-track">
                    <div class="stat-progress-fill" style="width: <?php echo number_format($portfolio_pct_delivered, 2); ?>%;"></div>
                </div>
            </div>
            <a href="module_cost_analysis.php" class="stat-card clickable">
                <div class="stat-icon">💵</div>
                <h3 class="stat-number">
                    <span class="unit-modules"><?php echo $portfolio_total_cost > 0 ? '$' . number_format($portfolio_total_cost, 0) : 'N/A'; ?></span>
                    <span class="unit-mw" style="display:none"><?php echo $portfolio_cost_per_watt !== null ? '$' . number_format($portfolio_cost_per_watt, 4) . '/W' : 'N/A'; ?></span>
                </h3>
                <p class="stat-label">Portfolio Cost</p>
            </a>
        </div>
        <div class="charts-section">
            <div class="chart-card" style="cursor:default">
                <h3>Project Pipeline <span style="font-size:0.7em;font-weight:400;color:#6c757d">(click to filter)</span> <span onclick="event.stopPropagation();openChartModal('pipeline')" style="cursor:pointer;font-size:0.8em;color:#6c757d;margin-left:4px" title="What do these statuses mean?">ⓘ</span></h3>
                <div class="chart-content">
                    <div class="chart-container"><canvas id="pipelineChart"></canvas></div>
                    <div class="chart-legend">
                        <div class="legend-item clickable-filter" data-health="on_track" onclick="filterByHealth('on_track')" style="cursor:pointer;border-radius:6px;padding:6px 8px;margin:-2px -4px;transition:background 0.2s"><span class="legend-label"><span class="legend-dot" style="background:#28a745"></span>On Track</span><span class="legend-value"><?php echo $dashboard_totals['health_counts']['on_track']; ?></span></div>
                        <div class="legend-item clickable-filter" data-health="at_risk" onclick="filterByHealth('at_risk')" style="cursor:pointer;border-radius:6px;padding:6px 8px;margin:-2px -4px;transition:background 0.2s"><span class="legend-label"><span class="legend-dot" style="background:#ffc107"></span>At Risk</span><span class="legend-value"><?php echo $dashboard_totals['health_counts']['at_risk']; ?></span></div>
                        <div class="legend-item clickable-filter" data-health="behind" onclick="filterByHealth('behind')" style="cursor:pointer;border-radius:6px;padding:6px 8px;margin:-2px -4px;transition:background 0.2s"><span class="legend-label"><span class="legend-dot" style="background:#dc3545"></span>Behind</span><span class="legend-value"><?php echo $dashboard_totals['health_counts']['behind']; ?></span></div>
                        <div class="legend-item" onclick="window.location.href='archived_projects.php'" style="cursor:pointer;border-radius:6px;padding:6px 8px;margin:-2px -4px;transition:background 0.2s"><span class="legend-label"><span class="legend-dot" style="background:#488C9A"></span>Completed</span><span class="legend-value"><?php echo $archived_count; ?></span></div>
                    </div>
                </div>
            </div>
            <div class="chart-card portfolio-flow-card" style="cursor:default">
                <div class="portfolio-flow-header">
                    <h3>Module Flow</h3>
                    <div class="portfolio-flow-legend">
                        <span class="portfolio-flow-legend-item"><span class="portfolio-flow-legend-dot pf-mfr"></span>At Mfr</span>
                        <span class="portfolio-flow-legend-item"><span class="portfolio-flow-legend-dot pf-transit"></span>In Transit</span>
                        <span class="portfolio-flow-legend-item"><span class="portfolio-flow-legend-dot pf-staged"></span>At Warehouses</span>
                        <span class="portfolio-flow-legend-item"><span class="portfolio-flow-legend-dot pf-delivered"></span>Delivered</span>
                    </div>
                </div>
                <div class="chart-content portfolio-flow-content">
                    <?php
                    $flow_visible_limit = 4;
                    $projects_with_flow = array_filter($projects, function($p) use ($project_flow_buckets) {
                        $b = $project_flow_buckets[(int)$p['id']] ?? null;
                        return $b && $b['total'] > 0;
                    });
                    $flow_overflow_count = max(0, count($projects_with_flow) - $flow_visible_limit);
                    $flow_visible_projects = array_slice($projects_with_flow, 0, $flow_visible_limit);
                    ?>
                    <?php if (empty($projects_with_flow)): ?>
                        <div class="portfolio-flow-empty">
                            <i class="fas fa-boxes"></i>
                            <p>No module flow data yet for your portfolio.</p>
                        </div>
                    <?php else: ?>
                        <div class="project-flow-list">
                            <?php
                            $project_flow_meta = [
                                'at_manufacturer' => ['label' => 'At Mfr',         'cls' => 'pf-mfr'],
                                'in_transit'      => ['label' => 'In Transit',     'cls' => 'pf-transit'],
                                'staged'          => ['label' => 'At Warehouses',  'cls' => 'pf-staged'],
                                'delivered'       => ['label' => 'Delivered',      'cls' => 'pf-delivered'],
                            ];
                            foreach ($flow_visible_projects as $pp):
                                $pid = (int)$pp['id'];
                                $b = $project_flow_buckets[$pid];
                                $total = $b['total'];
                                $delivered_pct = $total > 0 ? ($b['delivered'] / $total) * 100 : 0;
                            ?>
                                <a class="project-flow-row" href="project_overview.php?project_id=<?php echo $pid; ?>#tab-deliveries"
                                   title="<?php echo htmlspecialchars($pp['project_name']); ?> — click for the full flow">
                                    <div class="project-flow-name"><?php echo htmlspecialchars($pp['project_name']); ?></div>
                                    <div class="project-flow-bar">
                                        <?php foreach ($project_flow_meta as $bk => $meta):
                                            $val = $b[$bk];
                                            if ($val <= 0) continue;
                                            $pct = ($val / $total) * 100;
                                        ?>
                                            <div class="project-flow-seg <?php echo $meta['cls']; ?>"
                                                 style="width: <?php echo number_format($pct, 4); ?>%;"
                                                 title="<?php echo htmlspecialchars($meta['label']); ?>: <?php echo number_format($val); ?> modules (<?php echo number_format($pct, 1); ?>%)"></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="project-flow-summary"><?php echo number_format($delivered_pct, 0); ?>% del</div>
                                </a>
                            <?php endforeach; ?>
                            <?php if ($flow_overflow_count > 0): ?>
                                <button type="button" class="project-flow-viewall"
                                        onclick="document.getElementById('projects-section').scrollIntoView({behavior:'smooth'})">
                                    View all <?php echo (int)$flow_overflow_count; ?> more
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($projects) || in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
    <div class="section-header-row" id="projects-section">
        <div class="section-title-row">
            <h2 class="section-header">Your Active Projects</h2>
            <a href="archived_projects.php" class="view-archived-link">
                Archived
                <span class="archived-count"><?php echo $archived_count; ?></span>
            </a>
        </div>
        <div class="section-controls">
            <div class="view-toggle">
                <button class="active" onclick="setView('grid')" id="btn-grid" title="Grid View">▦</button>
                <button onclick="setView('table')" id="btn-table" title="Table View">☰</button>
            </div>
        </div>
    </div>

    <div class="active-filter-banner" id="filter-banner">
        <span class="filter-text">Showing <span class="filter-status" id="filter-status-text"></span> projects</span>
        <button class="clear-filter-btn" onclick="clearHealthFilter()">Show All Projects</button>
    </div>

    <div class="projects-grid active" id="projects-grid">
        <?php
        $target_page = ($role === 'DDPm') ? 'DDPm_overview' : 'project_overview';
        foreach ($projects as $idx => $project):
            $est_date_display = !empty($project['estimated_completion_date']) ? (new DateTime($project['estimated_completion_date']))->format('M j, Y') : 'N/A';
            $circ = 2 * 3.14159 * 26;
            $order_offset = $circ - ($project['order_progress'] / 100) * $circ;
            $delivery_offset = $circ - ($project['delivery_progress'] / 100) * $circ;
        ?>
        <div class="project-card" data-health="<?php echo $project['health']; ?>" onclick="window.location.href='<?php echo $target_page; ?>.php?project_id=<?php echo $project['id']; ?>'">
            <div class="project-card-image">
                <img src="<?php echo htmlspecialchars(!empty($project['image_url']) ? $project['image_url'] : 'pictures/project_default.png'); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>" onerror="this.src='pictures/project_default.png'">
                <div class="project-card-overlay"><span>View Details</span></div>
            </div>
            <div class="project-card-content">
                <div class="project-card-header">
                    <h3 class="project-card-title"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                    <?php
                    $health_definitions = [
                        'on_track' => 'On schedule relative to completion date.',
                        'at_risk' => 'Less than 30 days to deadline with <80% delivered.',
                        'behind' => 'Past completion date, not fully delivered.',
                        'completed' => 'All modules delivered to site.'
                    ];
                    ?>
                    <span class="health-badge health-<?php echo $project['health']; ?>" onclick="event.stopPropagation()">
                        <?php echo $project['health_text']; ?>
                        <span class="health-tooltip"><strong><?php echo $health_definitions[$project['health']]; ?></strong><br><br><?php echo htmlspecialchars($project['health_reason']); ?></span>
                    </span>
                </div>
                <div class="project-card-location">📍 <?php echo htmlspecialchars($project['project_address']); ?></div>

                <div class="progress-rings-row">
                    <div class="progress-ring-item" onclick="event.stopPropagation();openModal('order',<?php echo $idx; ?>)">
                        <div class="progress-ring">
                            <svg width="64" height="64"><circle class="progress-ring-bg" cx="32" cy="32" r="26"></circle><circle class="progress-ring-fill order" cx="32" cy="32" r="26" stroke-dasharray="<?php echo $circ; ?>" stroke-dashoffset="<?php echo $order_offset; ?>"></circle></svg>
                            <div class="progress-ring-center"><div class="progress-ring-value"><?php echo round($project['order_progress']); ?>%</div><div class="progress-ring-label">ordered</div></div>
                        </div>
                        <div class="ring-title">Order Progress</div>
                        <div class="ring-subtitle"><?php echo number_format($project['ordered_mw'], 2); ?>/<?php echo number_format($project['project_size'], 2); ?> MW</div>
                    </div>
                    <div class="progress-ring-item" onclick="event.stopPropagation();openModal('delivery',<?php echo $idx; ?>)">
                        <div class="progress-ring">
                            <svg width="64" height="64"><circle class="progress-ring-bg" cx="32" cy="32" r="26"></circle><circle class="progress-ring-fill delivery" cx="32" cy="32" r="26" stroke-dasharray="<?php echo $circ; ?>" stroke-dashoffset="<?php echo $delivery_offset; ?>"></circle></svg>
                            <div class="progress-ring-center"><div class="progress-ring-value"><?php echo round($project['delivery_progress']); ?>%</div><div class="progress-ring-label">delivered</div></div>
                        </div>
                        <div class="ring-title">Delivery Progress</div>
                        <div class="ring-subtitle"><?php echo number_format($project['delivered_mw'], 2); ?>/<?php echo number_format($project['project_size'], 2); ?> MW</div>
                    </div>
                </div>

                <div class="project-info-grid">
                    <div class="info-item"><div class="info-label">Project Size</div><div class="info-value"><?php echo number_format($project['project_size'], 2); ?> MW</div></div>
                    <div class="info-item"><div class="info-label">Est. Completion</div><div class="info-value"><?php echo $est_date_display; ?></div></div>
                </div>

                <?php if ($project['storage_modules'] > 0 || $project['open_claims'] > 0): ?>
                <div class="project-badges">
                    <?php if ($project['storage_modules'] > 0): ?>
                    <div class="storage-badge">🏭 <strong><?php echo number_format($project['storage_modules']); ?></strong> in storage</div>
                    <?php endif; ?>
                    <?php if ($project['open_claims'] > 0): ?>
                    <div class="warranty-badge">⚠️ <strong><?php echo $project['open_claims']; ?></strong> open claim<?php echo $project['open_claims'] > 1 ? 's' : ''; ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
        <div class="add-project-card" onclick="window.location.href='add_project.php'"><div class="add-project-icon">+</div><div class="add-project-text">Add New Project</div></div>
        <?php endif; ?>
    </div>

    <div class="projects-table-container" id="projects-table">
        <table class="projects-table">
            <thead><tr>
                <th onclick="sortTable(0)">Project <span class="sort-icon">↕</span></th>
                <th onclick="sortTable(1)">Size <span class="sort-icon">↕</span></th>
                <th>Wattages</th>
                <th onclick="sortTable(3)">Ordered <span class="sort-icon">↕</span></th>
                <th onclick="sortTable(4)">Delivered <span class="sort-icon">↕</span></th>
                <th>Stage</th>
                <th>Health</th>
                <th onclick="sortTable(7)">Claims <span class="sort-icon">↕</span></th>
                <th onclick="sortTable(8)">Est. Complete <span class="sort-icon">↕</span></th>
            </tr></thead>
            <tbody>
            <?php
            $health_definitions = [
                'on_track' => 'On schedule relative to completion date.',
                'at_risk' => 'Less than 30 days to deadline with <80% delivered.',
                'behind' => 'Past completion date, not fully delivered.',
                'completed' => 'All modules delivered to site.'
            ];
            foreach ($projects as $project):
                $est_date_display = !empty($project['estimated_completion_date']) ? (new DateTime($project['estimated_completion_date']))->format('M j, Y') : 'N/A';
            ?>
            <tr data-health="<?php echo $project['health']; ?>" onclick="window.location.href='<?php echo $target_page; ?>.php?project_id=<?php echo $project['id']; ?>'">
                <td class="table-project-name"><?php echo htmlspecialchars($project['project_name']); ?></td>
                <td><?php echo number_format($project['project_size'], 2); ?> MW</td>
                <td><div class="table-wattages"><?php if (!empty($project['wattage_breakdown'])): foreach ($project['wattage_breakdown'] as $wb): ?><div class="table-wattage-row"><strong><?php echo number_format($wb['quantity']); ?></strong> × <?php echo (int)$wb['wattage']; ?>W</div><?php endforeach; else: ?><span style="color:#999">—</span><?php endif; ?></div></td>
                <td><div class="table-progress"><div class="table-progress-bar"><div class="table-progress-fill order" style="width:<?php echo min($project['order_progress'], 100); ?>%"></div></div><span class="table-progress-text order"><?php echo round($project['order_progress']); ?>%</span></div></td>
                <td><div class="table-progress"><div class="table-progress-bar"><div class="table-progress-fill delivery" style="width:<?php echo min($project['delivery_progress'], 100); ?>%"></div></div><span class="table-progress-text delivery"><?php echo round($project['delivery_progress']); ?>%</span></div></td>
                <td><?php echo $project['timeline_label']; ?></td>
                <td><span class="health-badge health-<?php echo $project['health']; ?>" title="<?php echo $health_definitions[$project['health']] . ' — ' . htmlspecialchars($project['health_reason']); ?>"><?php echo $project['health_text']; ?></span></td>
                <td><?php if ($project['open_claims'] > 0): ?><span style="color:#dc3545;font-weight:600"><?php echo $project['open_claims']; ?></span><?php else: ?><span style="color:#999">—</span><?php endif; ?></td>
                <td><?php echo $est_date_display; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <div class="no-projects"><h2>No Active Projects</h2><p>Contact your administrator to get started.</p></div>
    <?php endif; ?>

    <!-- Recent Activity (compact) — last 5 completed events across all projects -->
    <div class="recent-activity-card">
        <div class="recent-activity-header">
            <h3><i class="fas fa-stream"></i> Recent Activity</h3>
            <?php if (!empty($recent_activity)): ?>
                <span class="recent-activity-count">last <?php echo count($recent_activity); ?></span>
            <?php endif; ?>
        </div>
        <?php if (empty($recent_activity)): ?>
            <div class="recent-activity-empty">No recent shipment activity.</div>
        <?php else: ?>
            <ul class="recent-activity-list">
                <?php
                $today_dt_dash = new DateTime('today');
                $event_meta_dash = [
                    'delivered' => ['icon' => 'fa-flag-checkered', 'verb' => 'Delivered to', 'cls' => 'rev-delivered'],
                    'arrived'   => ['icon' => 'fa-warehouse',      'verb' => 'Arrived at',   'cls' => 'rev-arrived'],
                    'departed'  => ['icon' => 'fa-truck-moving',   'verb' => 'Departed for', 'cls' => 'rev-departed'],
                ];
                foreach ($recent_activity as $rev):
                    $type = $rev['event_type'] ?? 'arrived';
                    $meta = $event_meta_dash[$type] ?? $event_meta_dash['arrived'];
                    $is_final = ($type === 'delivered');
                    $dest_name = $is_final
                        ? ($rev['project_name'] ?? 'Project Site')
                        : ($rev['dest_warehouse_name'] ?: 'Warehouse');
                    $origin_name = ($rev['origin_type'] === 'warehouse' && $rev['origin_warehouse_name'])
                        ? $rev['origin_warehouse_name']
                        : 'Manufacturer';
                    $event_date = new DateTime($rev['event_date']);
                    $diff = (int)$event_date->diff($today_dt_dash)->format('%r%a');
                    if ($diff <= 0)      $rel = 'today';
                    elseif ($diff === 1) $rel = 'yesterday';
                    elseif ($diff < 7)   $rel = $diff . 'd ago';
                    elseif ($diff < 30)  $rel = floor($diff/7) . 'w ago';
                    else                 $rel = $event_date->format('M j');
                    $pcount = (int)$rev['pallet_count'];
                    $scount = (int)($rev['shipment_count'] ?? 1);
                    $link = 'project_overview.php?project_id=' . (int)$rev['project_id'] . '#tab-timeline';
                ?>
                    <li>
                        <a class="recent-activity-row <?php echo $meta['cls']; ?>" href="<?php echo $link; ?>"
                           title="<?php echo htmlspecialchars($rev['project_name'] . ' — ' . $origin_name . ' → ' . $dest_name); ?>">
                            <span class="recent-activity-icon"><i class="fas <?php echo $meta['icon']; ?>"></i></span>
                            <span class="recent-activity-text">
                                <span class="recent-activity-verb"><?php echo $meta['verb']; ?></span>
                                <span class="recent-activity-where"><?php echo htmlspecialchars($dest_name); ?></span>
                                <span class="recent-activity-from">from <?php echo htmlspecialchars($origin_name); ?></span>
                            </span>
                            <span class="recent-activity-project"><?php echo htmlspecialchars($rev['project_name']); ?></span>
                            <?php if ($scount > 1): ?>
                                <span class="recent-activity-pill"><?php echo $scount; ?>×</span>
                            <?php endif; ?>
                            <span class="recent-activity-when"><?php echo $rel; ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</main>

<div class="modal-overlay" id="modal-overlay" onclick="closeModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
        <div class="modal-header"><h3 id="modal-title">Breakdown</h3><button class="modal-close" onclick="closeModal()">&times;</button></div>
        <div class="modal-summary" id="modal-summary"></div>
        <div class="modal-breakdown"><h4 id="breakdown-title">Wattage Breakdown</h4><table class="breakdown-table"><thead><tr><th>Wattage</th><th>Quantity</th><th>MW</th></tr></thead><tbody id="breakdown-body"></tbody></table></div>
    </div>
</div>

<div class="modal-overlay" id="chart-modal-overlay" onclick="closeChartModal()">
    <div class="modal-content" onclick="event.stopPropagation()" style="width:min(980px, 96vw);max-width:980px">
        <div class="modal-header"><h3 id="chart-modal-title">Chart Details</h3><button class="modal-close" onclick="closeChartModal()">&times;</button></div>
        <div id="chart-modal-body"></div>
    </div>
</div>

<script>
const projectsData = <?php echo json_encode(array_map(function($p) {
    return ['name'=>$p['project_name'],'project_size'=>$p['project_size'],'ordered_mw'=>$p['ordered_mw'],'order_progress'=>$p['order_progress'],'delivered_mw'=>$p['delivered_mw'],'delivery_progress'=>$p['delivery_progress'],'total_modules'=>$p['total_modules'],'delivered_modules'=>$p['delivered_modules'],'wattage_breakdown'=>$p['wattage_breakdown'],'delivered_breakdown'=>$p['delivered_breakdown']];
}, $projects)); ?>;
function openModal(type, idx) {
    const p = projectsData[idx], modal = document.getElementById('modal-overlay'), circ = 2 * 3.14159 * 28;
    document.getElementById('modal-title').textContent = (type === 'order' ? 'Order Progress - ' : 'Delivery Progress - ') + p.name;
    document.getElementById('breakdown-title').textContent = type === 'order' ? 'Ordered by Wattage' : 'Delivered by Wattage';
    const progress = type === 'order' ? p.order_progress : p.delivery_progress;
    const mw = type === 'order' ? p.ordered_mw : p.delivered_mw;
    const modules = type === 'order' ? p.total_modules : p.delivered_modules;
    const breakdown = type === 'order' ? p.wattage_breakdown : p.delivered_breakdown;
    const colorClass = type === 'order' ? 'order' : 'delivery';
    const dashoffset = circ - (progress / 100) * circ;
    document.getElementById('modal-summary').innerHTML = `<div class="modal-ring"><svg width="70" height="70"><circle class="progress-ring-bg" cx="35" cy="35" r="28" style="stroke-width:6"></circle><circle class="progress-ring-fill ${colorClass}" cx="35" cy="35" r="28" style="stroke-width:6;stroke-dasharray:${circ};stroke-dashoffset:${dashoffset}"></circle></svg><div class="modal-ring-center"><div class="modal-ring-value">${Math.round(progress)}%</div><div class="modal-ring-label">${type === 'order' ? 'Ordered' : 'Delivered'}</div></div></div><div class="modal-summary-info"><div class="modal-summary-row"><span>Project Size</span><span>${p.project_size.toFixed(2)} MW</span></div><div class="modal-summary-row"><span>MW ${type === 'order' ? 'Ordered' : 'Delivered'}</span><span>${mw.toFixed(2)} MW</span></div><div class="modal-summary-row"><span>Modules</span><span>${modules.toLocaleString()}</span></div></div>`;
    document.getElementById('breakdown-body').innerHTML = breakdown.length ? breakdown.map(w => `<tr><td><strong>${Math.round(w.wattage)}W</strong></td><td>${w.quantity.toLocaleString()}</td><td>${w.mw.toFixed(3)} MW</td></tr>`).join('') : '<tr><td colspan="3" style="text-align:center;color:#999">No data</td></tr>';
    modal.classList.add('active');
}
function closeModal() { document.getElementById('modal-overlay').classList.remove('active'); }

function openChartModal(type) {
    const modal = document.getElementById('chart-modal-overlay');
    const title = document.getElementById('chart-modal-title');
    const body = document.getElementById('chart-modal-body');

    if (type === 'pipeline') {
        title.textContent = 'Project Pipeline - Health Status Guide';
        body.innerHTML = `
            <div style="margin-bottom:16px;padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #28a745">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#28a745"></span>
                    <strong style="color:#155724">On Track</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057">Project is progressing normally. Delivery progress is on schedule relative to the estimated completion date.</p>
            </div>
            <div style="margin-bottom:16px;padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #ffc107">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#ffc107"></span>
                    <strong style="color:#856404">At Risk</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057">Project has less than 30 days until the deadline AND delivery progress is below 80%. Immediate attention recommended.</p>
            </div>
            <div style="margin-bottom:16px;padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #dc3545">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#dc3545"></span>
                    <strong style="color:#721c24">Behind</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057">Project has passed its estimated completion date and delivery is not yet 100% complete. Requires urgent action.</p>
            </div>
            <div style="padding:14px;background:#f8f9fa;border-radius:10px;border-left:4px solid #488C9A">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span style="width:12px;height:12px;border-radius:50%;background:#488C9A"></span>
                    <strong style="color:#004085">Completed</strong>
                </div>
                <p style="margin:0;font-size:0.85em;color:#495057">Projects that have been archived. Click to view the archived projects page.</p>
            </div>
        `;
    }

    modal.classList.add('active');
}

function closeChartModal() { document.getElementById('chart-modal-overlay').classList.remove('active'); }

document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); closeChartModal(); } });

function setUnit(unit) {
    document.querySelectorAll('.unit-modules,.unit-label-modules').forEach(el => el.style.display = unit === 'modules' ? '' : 'none');
    document.querySelectorAll('.unit-mw,.unit-label-mw').forEach(el => el.style.display = unit === 'mw' ? '' : 'none');
    document.getElementById('btn-modules').classList.toggle('active', unit === 'modules');
    document.getElementById('btn-mw').classList.toggle('active', unit === 'mw');
    localStorage.setItem('dashboardUnit', unit);
}
function setView(view) {
    document.getElementById('projects-grid').classList.toggle('active', view === 'grid');
    document.getElementById('projects-table').classList.toggle('active', view === 'table');
    document.getElementById('btn-grid').classList.toggle('active', view === 'grid');
    document.getElementById('btn-table').classList.toggle('active', view === 'table');
    localStorage.setItem('dashboardView', view);
}

let currentHealthFilter = null;
const healthLabels = { on_track: 'On Track', at_risk: 'At Risk', behind: 'Behind', completed: 'Completed' };

function filterByHealth(health) {
    currentHealthFilter = health;

    // Update filter banner
    document.getElementById('filter-banner').classList.add('visible');
    document.getElementById('filter-status-text').textContent = healthLabels[health];

    // Filter project cards
    document.querySelectorAll('.project-card[data-health]').forEach(card => {
        card.style.display = card.dataset.health === health ? '' : 'none';
    });

    // Filter table rows
    document.querySelectorAll('.projects-table tbody tr[data-health]').forEach(row => {
        row.style.display = row.dataset.health === health ? '' : 'none';
    });

    // Highlight active filter in legend
    document.querySelectorAll('.clickable-filter').forEach(item => {
        item.style.background = item.dataset.health === health ? '#e8f4f7' : '';
    });

    // Scroll to projects section
    document.getElementById('projects-section').scrollIntoView({ behavior: 'smooth' });
}

function clearHealthFilter() {
    currentHealthFilter = null;

    // Hide filter banner
    document.getElementById('filter-banner').classList.remove('visible');

    // Show all project cards
    document.querySelectorAll('.project-card[data-health]').forEach(card => {
        card.style.display = '';
    });

    // Show all table rows
    document.querySelectorAll('.projects-table tbody tr[data-health]').forEach(row => {
        row.style.display = '';
    });

    // Remove highlight from legend
    document.querySelectorAll('.clickable-filter').forEach(item => {
        item.style.background = '';
    });
}

let sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('.projects-table tbody'), rows = Array.from(tbody.querySelectorAll('tr'));
    sortDir[col] = !sortDir[col];
    const dir = sortDir[col] ? 1 : -1;
    rows.sort((a, b) => {
        let av = a.cells[col].textContent.trim(), bv = b.cells[col].textContent.trim();
        if ([1,3,4].includes(col)) { av = parseFloat(av.replace(/[^0-9.-]/g,''))||0; bv = parseFloat(bv.replace(/[^0-9.-]/g,''))||0; return (av-bv)*dir; }
        return av.localeCompare(bv)*dir;
    });
    rows.forEach(r => tbody.appendChild(r));
}

const pipelineChart = new Chart(document.getElementById('pipelineChart'), {
    type: 'doughnut',
    data: { labels: ['On Track','At Risk','Behind','Completed'], datasets: [{ data: [<?php echo $dashboard_totals['health_counts']['on_track'].','.$dashboard_totals['health_counts']['at_risk'].','.$dashboard_totals['health_counts']['behind'].','.$archived_count; ?>], backgroundColor: ['#28a745','#ffc107','#dc3545','#488C9A'], borderWidth: 0 }] },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        cutout: '60%',
        onClick: (evt, elements) => {
            if (elements.length > 0) {
                const idx = elements[0].index;
                if (idx === 3) {
                    window.location.href = 'archived_projects.php';
                } else {
                    const healthMap = ['on_track', 'at_risk', 'behind'];
                    filterByHealth(healthMap[idx]);
                }
            }
        }
    }
});
document.getElementById('pipelineChart').style.cursor = 'pointer';

document.addEventListener('DOMContentLoaded', () => {
    setUnit(localStorage.getItem('dashboardUnit')||'modules');
    setView(localStorage.getItem('dashboardView')||'grid');
});
</script>
</body>
</html>
