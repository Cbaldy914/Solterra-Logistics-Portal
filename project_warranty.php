<?php
session_name("logistics_session");
session_start();

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Check for a project_id parameter; if missing, exit.
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}
$project_id = (int)$_GET['project_id'];

// --------------------------------------------------------------------
// Database connection
// --------------------------------------------------------------------
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// --------------------------------------------------------------------
// Fetch project name
// --------------------------------------------------------------------
$stmt_proj = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
$stmt_proj->bind_param("i", $project_id);
$stmt_proj->execute();
$stmt_proj->bind_result($project_name);
$stmt_proj->fetch();
$stmt_proj->close();

if (!$project_name) {
    die("Project not found.");
}

// --------------------------------------------------------------------
// TIME FILTER LOGIC (all/day/week/month)
// We'll filter by COALESCE(w.new_delivery_date, w.delivery_date)
// --------------------------------------------------------------------
$filterColumn = "COALESCE(w.new_delivery_date, w.delivery_date)";
$time_filter  = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date     = isset($_GET['ref_date'])    ? $_GET['ref_date']    : date('Y-m-d');

$dateCondition = "";
$paramTypes    = "i"; // we always bind $project_id first
$params        = [$project_id];

// For date navigation labeling
$dateLabel = "All Warranties";
$prev_date = $ref_date;
$next_date = $ref_date;

if ($time_filter === 'day') {
    $dateCondition = " AND DATE($filterColumn) = ?";
    $paramTypes   .= "s";
    $params[]      = $ref_date;

    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($ref_date . " -1 day"));
    $next_date = date('Y-m-d', strtotime($ref_date . " +1 day"));
}
elseif ($time_filter === 'week') {
    $timestamp   = strtotime($ref_date);
    $dayOfWeek   = date('w', $timestamp);
    $startOfWeek = date('Y-m-d', strtotime("-{$dayOfWeek} days", $timestamp));
    $endOfWeek   = date('Y-m-d', strtotime("+" . (6 - $dayOfWeek) . " days", $timestamp));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfWeek;
    $params[]      = $endOfWeek;

    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime($startOfWeek . " -7 days"));
    $next_date = date('Y-m-d', strtotime($startOfWeek . " +7 days"));
}
elseif ($time_filter === 'month') {
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth   = date('Y-m-t', strtotime($ref_date));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfMonth;
    $params[]      = $endOfMonth;

    $dateLabel = date('F Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($startOfMonth . " -1 month"));
    $next_date = date('Y-m-d', strtotime($startOfMonth . " +1 month"));
}

// --------------------------------------------------------------------
// STATUS FILTER
// We'll assume w.status in the DB is "Pending", "Rejected", "Resolved", etc.
// If user chooses something else (like "In Progress"), adapt as needed.
// --------------------------------------------------------------------
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$statusCondition = "";
if (!empty($status_filter)) {
    $statusCondition = " AND w.status = ?";
    $paramTypes     .= "s";
    $params[]        = $status_filter;
}

// --------------------------------------------------------------------
// CSV EXPORT
// If ?action=export_csv => apply same time/status filters
// --------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="warranty_claims_export.csv"');

    $out = fopen('php://output', 'w');
    // CSV header
    fputcsv($out, [
        'BOL#',
        'Status',
        'DamageOrDiscrepancy',
        'OriginalDeliveryDate',
        'NewDeliveryDate',
        'SiteNotes',
        'ManufacturerNotes'
    ]);

    // Build export query with the same dateCondition + statusCondition
    $sql_export = "
      SELECT
        w.bol_number,
        w.status,
        w.modules_rejected,
        w.quantity_discrepancy,
        w.delivery_date AS original_delivery_date,
        w.new_delivery_date,
        w.manufacturer_notes,
        s.additional_details AS site_notes
      FROM warranty_claims w
      JOIN site_scheduling s ON w.scheduling_id = s.id
      JOIN sites si ON s.site_id = si.id
      WHERE si.project_id = ?
            $dateCondition
            $statusCondition
      ORDER BY w.id DESC
    ";
    $stmt_exp = $conn->prepare($sql_export);
    $stmt_exp->bind_param($paramTypes, ...$params);
    $stmt_exp->execute();
    $res_exp = $stmt_exp->get_result();

    // Functions reused from below
    function formatDate($dtStr) {
        if (empty($dtStr) || $dtStr === '0000-00-00') return '';
        $dt = new DateTime($dtStr);
        return $dt->format('m-d-Y');
    }
    function buildDamageOrDiscrepancy($modulesRejectedJson, $quantityJson) {
        $descLines = [];
        if (!empty($modulesRejectedJson)) {
            $rejArr = json_decode($modulesRejectedJson, true);
            if (is_array($rejArr)) {
                foreach ($rejArr as $damageItem) {
                    $modWatt = preg_replace('/[^0-9]/', '', $damageItem['module'] ?? '');
                    $qty     = (int)($damageItem['qty'] ?? 0);
                    if ($modWatt && $qty > 0) {
                        $descLines[] = "$qty modules rejected ({$modWatt}w)";
                    }
                }
            }
        }
        if (!empty($quantityJson)) {
            $qtyArr = json_decode($quantityJson, true);
            if (is_array($qtyArr)) {
                foreach ($qtyArr as $qd) {
                    $modWatt = preg_replace('/[^0-9]/', '', $qd['module'] ?? '');
                    $exp     = (int)($qd['expected'] ?? 0);
                    $act     = (int)($qd['actual'] ?? 0);
                    $diff    = $act - $exp;
                    if ($diff < 0) {
                        $short = abs($diff);
                        $descLines[] = "$short modules short ({$modWatt}w)";
                    } elseif ($diff > 0) {
                        $descLines[] = "$diff modules overage ({$modWatt}w)";
                    }
                }
            }
        }
        return implode("; ", $descLines);
    }

    if ($res_exp) {
        while ($row_exp = $res_exp->fetch_assoc()) {
            $bol      = $row_exp['bol_number'] ?? '';
            $status   = $row_exp['status'] ?? '';
            $origDate = formatDate($row_exp['original_delivery_date']);
            $newDate  = formatDate($row_exp['new_delivery_date']);
            $siteNotes= $row_exp['site_notes'] ?? '';
            $manNotes = $row_exp['manufacturer_notes'] ?? '';
            $damageOrDisc = buildDamageOrDiscrepancy(
                $row_exp['modules_rejected'] ?? '',
                $row_exp['quantity_discrepancy'] ?? ''
            );

            fputcsv($out, [
                $bol,
                $status,
                $damageOrDisc,
                $origDate,
                $newDate,
                $siteNotes,
                $manNotes
            ]);
        }
    }
    fclose($out);
    $stmt_exp->close();
    $conn->close();
    exit();
}

// --------------------------------------------------------------------
// Normal page view: retrieve warranty claims with time/status filters
// --------------------------------------------------------------------
$sql = "
SELECT
  w.id AS warranty_id,
  w.bol_number,
  w.status,
  w.modules_rejected,
  w.modules_accepted,
  w.quantity_discrepancy,
  w.delivery_date AS original_delivery_date,
  w.new_delivery_date,
  w.manufacturer_notes,
  s.additional_details AS site_notes,
  s.wattage,
  w.pictures
FROM warranty_claims w
JOIN site_scheduling s ON w.scheduling_id = s.id
JOIN sites si ON s.site_id = si.id
WHERE si.project_id = ?
      $dateCondition
      $statusCondition
ORDER BY w.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}
$stmt->close();
$conn->close();

// --------------------------------------------------------------------
// HELPER FUNCTIONS
// --------------------------------------------------------------------
function formatDate($dtStr) {
    if (empty($dtStr) || $dtStr === '0000-00-00') {
        return '';
    }
    $dt = new DateTime($dtStr);
    return $dt->format('m-d-Y');
}
function buildDamageOrDiscrepancy($modulesRejectedJson, $quantityJson) {
    $descLines = [];
    // modulesRejected
    if (!empty($modulesRejectedJson)) {
        $rejArr = json_decode($modulesRejectedJson, true);
        if (is_array($rejArr)) {
            foreach ($rejArr as $damageItem) {
                $modWatt = preg_replace('/[^0-9]/', '', $damageItem['module'] ?? '');
                $qty     = (int)($damageItem['qty'] ?? 0);
                if ($modWatt && $qty > 0) {
                    $descLines[] = "$qty modules rejected ({$modWatt}w)";
                }
            }
        }
    }
    // quantityDiscrepancy
    if (!empty($quantityJson)) {
        $qtyArr = json_decode($quantityJson, true);
        if (is_array($qtyArr)) {
            foreach ($qtyArr as $qd) {
                $modWatt = preg_replace('/[^0-9]/', '', $qd['module'] ?? '');
                $exp     = (int)($qd['expected'] ?? 0);
                $act     = (int)($qd['actual'] ?? 0);
                $diff    = $act - $exp;
                if ($diff < 0) {
                    $short = abs($diff);
                    $descLines[] = "$short modules short ({$modWatt}w)";
                } elseif ($diff > 0) {
                    $descLines[] = "$diff modules overage ({$modWatt}w)";
                }
            }
        }
    }
    return implode("; ", $descLines);
}
function getModulesRejectedCount($json) {
    $sum = 0;
    if (!empty($json)) {
        $arr = json_decode($json, true);
        if (is_array($arr)) {
            foreach ($arr as $item) {
                if (isset($item['qty'])) {
                    $sum += (int)$item['qty'];
                }
            }
        }
    }
    return $sum;
}
function getMWFromModulesRejected($json) {
    $mw = 0.0;
    if (!empty($json)) {
        $damageItems = json_decode($json, true);
        if (is_array($damageItems)) {
            foreach ($damageItems as $item) {
                $qty = (int)($item['qty'] ?? 0);
                $modWattStr = $item['module'] ?? '';
                $modWatt = (float)preg_replace('/[^0-9.]/', '', $modWattStr);
                if ($qty > 0 && $modWatt > 0) {
                    $mw += ($qty * $modWatt) / 1000000;
                }
            }
        }
    }
    return $mw;
}

// --------------------------------------------------------------------
// Claim vs MW vs Module filter (metrics) logic
// --------------------------------------------------------------------
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'claim';

// Metric counters
$total_warranty_claims = 0;
$total_pending_claims  = 0;
$total_resolved_claims = 0;
$total_rejected_claims = 0;

$total_warranty_mw      = 0.0;
$total_pending_mw       = 0.0;
$total_resolved_mw      = 0.0;
$total_rejected_mw      = 0.0;

$total_warranty_modules = 0;
$total_pending_modules  = 0;
$total_resolved_modules = 0;
$total_rejected_modules = 0;

foreach ($rows as $row) {
    $status = strtolower($row['status'] ?? '');
    if ($filter === 'claim') {
        $value = 1; // each row = 1 claim
    }
    elseif ($filter === 'mw') {
        $value = getMWFromModulesRejected($row['modules_rejected'] ?? '');
    }
    elseif ($filter === 'module') {
        $modulesAccepted = (int)($row['modules_accepted'] ?? 0);
        $modulesRejected= getModulesRejectedCount($row['modules_rejected'] ?? '');
        $value = $modulesAccepted + $modulesRejected;
    } else {
        $value = 1;
    }

    // Overall
    if ($filter === 'claim') {
        $total_warranty_claims += $value;
    }
    elseif ($filter === 'mw') {
        $total_warranty_mw += $value;
    }
    elseif ($filter === 'module') {
        $total_warranty_modules += $value;
    }

    // Status-based
    if ($status === 'pending') {
        if ($filter === 'claim') $total_pending_claims += $value;
        elseif ($filter === 'mw') $total_pending_mw += $value;
        elseif ($filter === 'module') $total_pending_modules += $value;
    }
    if ($status === 'resolved') {
        if ($filter === 'claim') $total_resolved_claims += $value;
        elseif ($filter === 'mw') $total_resolved_mw += $value;
        elseif ($filter === 'module') $total_resolved_modules += $value;
    }
    if ($status === 'rejected') {
        if ($filter === 'claim') $total_rejected_claims += $value;
        elseif ($filter === 'mw') $total_rejected_mw += $value;
        elseif ($filter === 'module') $total_rejected_modules += $value;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project_name); ?> Warranty Claims</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Metrics Filter (claim/mw/module) */
        .metrics-filters {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .metrics-filters label {
            font-weight: bold;
        }
        .metrics-filters input[type="radio"] {
            margin-right: 5px;
        }
        .metrics-overview {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 20px;
            gap: 20px;
        }
        .metric-box {
            flex: 1;
            min-width: 200px;
            background-color: #f2f2f2;
            padding: 20px;
            text-align: center;
            border-radius: 8px;
        }
        .metric-box h3 {
            margin-bottom: 10px;
        }
        /* Time Filter Header (like cost_details) */
        .time-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 30px 20px 10px 20px;
            flex-wrap: wrap;
        }
        .time-filters {
            display: flex;
            gap: 10px;
        }
        .time-filters a {
            text-decoration: none;
            padding: 6px 12px;
            background: #eee;
            border-radius: 4px;
            color: #333;
        }
        .time-filters a.active {
            background: #488C9A;
            color: #fff;
        }
        .date-navigation {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .nav-arrow {
            font-weight: bold;
            cursor: pointer;
            background: #eee;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
        }
        .nav-arrow:hover {
            background: #ccc;
        }
        .date-label {
            font-weight: bold;
            font-size: 1.1em;
        }
        .right-filters {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }
        @media screen and (max-width: 768px) {
            .mobile-hide {
                display: none !important;
            }
        }
        /* Table styling */
        table {
            border-collapse: collapse;
            width: 100%;
        }
        table, th, td {
            border: 1px solid #ccc;
        }
        th, td {
            padding: 8px;
        }
        tr:hover {
            background: #f1f1f1;
        }
        .docs-dropdown {
            position: relative;
            display: inline-block;
        }
        .docs-dropdown-content {
            display: none;
            position: absolute;
            background: #fff;
            border: 1px solid #ccc;
            padding: 8px;
            min-width: 200px;
            z-index: 999;
            right: 0;
        }
        .docs-dropdown-content a {
            display: block;
            margin-bottom: 5px;
        }
        .docs-dropdown-content a:last-child {
            margin-bottom: 0;
        }
        .docs-dropdown-content.show {
            display: block;
        }
        .docs-icon img {
            width: 16px;
            height: 16px;
            vertical-align: middle;
        }

        .docs-icon {
            cursor: pointer;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Back Link -->
    <a href="DDPm_overview?id=<?php echo $project_id; ?>" class="back-icon" style="margin:20px;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:24px;height:24px;">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>

    <h1><?php echo htmlspecialchars($project_name); ?> Warranty Claims</h1>

    <!-- Metrics Filter Form (claim / mw / module) -->
    <form method="GET" id="filter-form" class="metrics-filters">
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
        <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter); ?>">
        <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">

        <label>
            <input type="radio" name="filter" value="claim"
                   <?php if ($filter === 'claim') echo 'checked'; ?>
                   onchange="this.form.submit();">
            Claim Count
        </label>
        <label>
            <input type="radio" name="filter" value="mw"
                   <?php if ($filter === 'mw') echo 'checked'; ?>
                   onchange="this.form.submit();">
            MW
        </label>
        <label>
            <input type="radio" name="filter" value="module"
                   <?php if ($filter === 'module') echo 'checked'; ?>
                   onchange="this.form.submit();">
            Module Count
        </label>
    </form>

    <!-- Metrics Overview Boxes -->
    <div class="metrics-overview">
        <div class="metric-box">
            <h3>Warranty Claims</h3>
            <p>
                <?php 
                if ($filter === 'claim') {
                    echo $total_warranty_claims;
                } elseif ($filter === 'mw') {
                    echo number_format($total_warranty_mw, 2) . " MW";
                } elseif ($filter === 'module') {
                    echo $total_warranty_modules;
                }
                ?>
            </p>
        </div>
        <div class="metric-box">
            <h3>Pending Claims</h3>
            <p>
                <?php 
                if ($filter === 'claim') {
                    echo $total_pending_claims;
                } elseif ($filter === 'mw') {
                    echo number_format($total_pending_mw, 2) . " MW";
                } elseif ($filter === 'module') {
                    echo $total_pending_modules;
                }
                ?>
            </p>
        </div>
        <div class="metric-box">
            <h3>Resolved Claims</h3>
            <p>
                <?php 
                if ($filter === 'claim') {
                    echo $total_resolved_claims;
                } elseif ($filter === 'mw') {
                    echo number_format($total_resolved_mw, 2) . " MW";
                } elseif ($filter === 'module') {
                    echo $total_resolved_modules;
                }
                ?>
            </p>
        </div>
        <div class="metric-box">
            <h3>Rejected Claims</h3>
            <p>
                <?php 
                if ($filter === 'claim') {
                    echo $total_rejected_claims;
                } elseif ($filter === 'mw') {
                    echo number_format($total_rejected_mw, 2) . " MW";
                } elseif ($filter === 'module') {
                    echo $total_rejected_modules;
                }
                ?>
            </p>
        </div>
    </div>

    <!-- TIME FILTER HEADER (like cost_details) -->
    <div class="time-filter-header">
        <!-- Left: All/Day/Week/Month -->
        <div class="time-filters">
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=all&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='all')?'active':''; ?>">
               All
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=day&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='day')?'active':''; ?>">
               Day
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=week&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='week')?'active':''; ?>">
               Week
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=month&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='month')?'active':''; ?>">
               Month
            </a>
        </div>

        <!-- Center: date nav -->
        <div class="date-navigation">
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>'">
                    &larr;
                </button>
            <?php endif; ?>
            <span class="date-label"><?php echo $dateLabel; ?></span>
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>'">
                    &rarr;
                </button>
            <?php endif; ?>
        </div>

        <!-- Right: search + status filter + export -->
        <div class="right-filters">
            <div style="display: flex; gap: 10px;" class="mobile-hide">
                <label for="searchInput" style="align-self: center;">Search in Table:</label>
                <input type="text" id="searchInput" placeholder="Type to filter..." onkeyup="searchTable()">
            </div>

            <form method="get" action="" style="display: flex; gap: 10px;">
                <!-- Keep existing filters in hidden fields -->
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
                <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">

                <label for="status_filter" style="align-self: center;">Filter by Status:</label>
                <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="pending"   <?php if ($status_filter==='pending')   echo 'selected'; ?>>Pending</option>
                    <option value="resolved"  <?php if ($status_filter==='resolved')  echo 'selected'; ?>>Resolved</option>
                    <option value="rejected"  <?php if ($status_filter==='rejected')  echo 'selected'; ?>>Rejected</option>
                    <option value="in progress" <?php if ($status_filter==='in progress') echo 'selected'; ?>>In Progress</option>
                    <!-- Add others if needed -->
                </select>

                <!-- Export to CSV (hidden on mobile) -->
                <span class="mobile-hide">
                    <button type="submit" name="action" value="export_csv">Export to CSV</button>
                </span>
            </form>
        </div>
    </div>

    <!-- Warranty Table -->
    <table id="warrantyTable">
        <thead>
            <tr>
                <th>BOL#</th>
                <th>Status</th>
                <th>Damage or Discrepancy</th>
                <th>Original Delivery Date</th>
                <th>New Delivery Date</th>
                <th>Site Notes</th>
                <th>Manufacturer Notes</th>
                <th>Documents</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $r):
                    $bol         = $r['bol_number'] ?? '';
                    $status      = $r['status'] ?? '';
                    $manNotes    = $r['manufacturer_notes'] ?? '';
                    $originalDate= formatDate($r['original_delivery_date']);
                    $newDate     = formatDate($r['new_delivery_date']);
                    $siteNotes   = $r['site_notes'] ?? '';
                    $damageOrDisc= buildDamageOrDiscrepancy(
                        $r['modules_rejected'] ?? '',
                        $r['quantity_discrepancy'] ?? ''
                    );
                    // Parse pictures (JSON)
                    $picsJson = $r['pictures'] ?? '';
                    $picsArr  = [];
                    if (!empty($picsJson)) {
                        $tmp = json_decode($picsJson, true);
                        if (is_array($tmp)) {
                            $picsArr = $tmp;
                        }
                    }
                    $warrantyId = (int)($r['warranty_id'] ?? 0);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($bol); ?></td>
                    <td><?php echo htmlspecialchars($status); ?></td>
                    <td><?php echo htmlspecialchars($damageOrDisc); ?></td>
                    <td><?php echo htmlspecialchars($originalDate); ?></td>
                    <td><?php echo htmlspecialchars($newDate); ?></td>
                    <td><?php echo htmlspecialchars($siteNotes); ?></td>
                    <td><?php echo htmlspecialchars($manNotes); ?></td>
                    <td>
                        <?php if (!empty($picsArr)): ?>
                            <div class="docs-dropdown">
                                <span class="docs-icon" onclick="toggleDropdown(<?php echo $warrantyId; ?>)">
                                    <img src="pictures/folder_icon.jpg" alt="folder"> (<?php echo count($picsArr); ?>)
                                </span>
                                <div class="docs-dropdown-content" id="dropdown_<?php echo $warrantyId; ?>">
                                    <?php foreach ($picsArr as $imgPath):
                                        $fileName = basename($imgPath);
                                    ?>
                                        <a href="<?php echo htmlspecialchars($imgPath); ?>" target="_blank">
                                            <?php echo htmlspecialchars($fileName); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <span style="color:#999;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">No warranty claims found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</main>

<script>
// Client-side table search
function searchTable() {
    var input = document.getElementById("searchInput");
    if (!input) return;
    var filter = input.value.toLowerCase();
    var table  = document.getElementById("warrantyTable");
    var trs    = table.getElementsByTagName("tr");
    // Skip header row
    for (var i = 1; i < trs.length; i++) {
        var tds = trs[i].getElementsByTagName("td");
        var show= false;
        for (var j=0; j<tds.length; j++){
            var txtValue = tds[j].textContent || tds[j].innerText;
            if (txtValue.toLowerCase().indexOf(filter) > -1) {
                show = true;
                break;
            }
        }
        trs[i].style.display = show ? "" : "none";
    }
}

// Toggle docs dropdown
function toggleDropdown(warrantyId) {
    var dd = document.getElementById('dropdown_'+warrantyId);
    if (!dd) return;
    // close all others
    var allDDs = document.querySelectorAll('.docs-dropdown-content');
    allDDs.forEach(function(d){
        if (d !== dd) d.classList.remove('show');
    });
    dd.classList.toggle('show');
}

// Hide dropdown if clicked outside
window.onclick = function(e) {
    if (!e.target.classList.contains('docs-icon')) {
        var allDDs = document.querySelectorAll('.docs-dropdown-content');
        allDDs.forEach(function(d){
            d.classList.remove('show');
        });
    }
};
</script>
</body>
</html>
