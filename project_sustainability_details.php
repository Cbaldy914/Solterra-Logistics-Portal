<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Validate project ID
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);
$user_id    = $_SESSION['user_id'];
$role       = $_SESSION['role'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch project details and ensure the user has access
if ($role === 'admin') {
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($project_name);
    $stmt->fetch();
    $stmt->close();

    if (!$project_name) {
        die("Project not found.");
    }
} else {
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($project_name);
    $stmt->fetch();
    $stmt->close();

    if (!$project_name) {
        die("You do not have access to this project.");
    }
}

// --------------------------------------------------------------------------
// Additional filters: (time_filter => all/day/week/month) & (status_filter)
// Mirroring project_cost_details logic
// --------------------------------------------------------------------------
$filterColumn  = "COALESCE(actual_delivery_date, anticipated_delivery_date)";
$time_filter   = isset($_GET['time_filter'])   ? $_GET['time_filter']   : 'all';
$ref_date      = isset($_GET['ref_date'])      ? $_GET['ref_date']      : date('Y-m-d');
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';

$dateCondition = "";
$paramTypes    = "i"; // we'll always bind project_id first
$params        = [$project_id];

$dateLabel = "All Deliveries";
$prev_date = "";
$next_date = "";

// Build time range conditions
if ($time_filter === 'day') {
    $dateCondition = " AND DATE($filterColumn) = ?";
    $paramTypes   .= "s";
    $params[]      = $ref_date;

    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime("$ref_date -1 day"));
    $next_date = date('Y-m-d', strtotime("$ref_date +1 day"));
}
elseif ($time_filter === 'week') {
    $timestamp   = strtotime($ref_date);
    $dayOfWeek   = date('w', $timestamp);
    $startOfWeek = date('Y-m-d', strtotime("-{$dayOfWeek} days", $timestamp));
    $endOfWeek   = date('Y-m-d', strtotime("+".(6-$dayOfWeek)." days", $timestamp));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfWeek;
    $params[]      = $endOfWeek;

    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime("$startOfWeek -7 days"));
    $next_date = date('Y-m-d', strtotime("$startOfWeek +7 days"));
}
elseif ($time_filter === 'month') {
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth   = date('Y-m-t', strtotime($ref_date));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfMonth;
    $params[]      = $endOfMonth;

    $dateLabel = date('F Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime("$startOfMonth -1 month"));
    $next_date = date('Y-m-d', strtotime("$startOfMonth +1 month"));
}

// Build status condition
$statusCondition = "";
if (!empty($status_filter)) {
    $statusCondition = " AND status_of_delivery = ?";
    $paramTypes     .= "s";
    $params[]        = $status_filter;
}

// --------------------------------------------------------------------------
// Handle "filter" selection from original code (total, ytd, etc.)
// This adds a "ytdCondition" if filter=ytd
// --------------------------------------------------------------------------
$filter       = $_GET['filter'] ?? 'total';
$current_year = date('Y');
$ytdCondition = "";

if ($filter === 'ytd') {
    // Filter by YEAR(created_at) = $current_year
    $ytdCondition = " AND YEAR(created_at) = ?";
    $paramTypes  .= "i";
    $params[]     = $current_year;
}

// --------------------------------------------------------------------------
// Build Final Deliveries Query with time range + status + ytd
// --------------------------------------------------------------------------
$sql_deliveries = "
    SELECT *
    FROM deliveries
    WHERE project_id = ?
          $ytdCondition
          $dateCondition
          $statusCondition
    ORDER BY $filterColumn DESC
";

$stmt_deliveries = $conn->prepare($sql_deliveries);
$stmt_deliveries->bind_param($paramTypes, ...$params);
$stmt_deliveries->execute();
$deliveries_result = $stmt_deliveries->get_result();
$stmt_deliveries->close();

// --------------------------------------------------------------------------
// Summations for sustainability
// --------------------------------------------------------------------------
$total_emissions       = 0;
$total_truckloads      = 0;
$total_miles_driven    = 0;
$total_fuel_consumption= 0;
$total_mws_delivered   = 0;

$deliveries = [];

// Arrays for unique filter values (if you want to add table-level sorting/filters)
$supplier_values = [];
$wattage_values  = [];
$status_values   = [];

// Process each delivery
while ($delivery = $deliveries_result->fetch_assoc()) {
    $quantity     = (int)($delivery['quantity'] ?? 0);
    $wattage      = (float)($delivery['wattage'] ?? 0);
    $miles_driven = (float)($delivery['miles'] ?? 0);

    // Count truckload if "Delivered" and miles>0
    if ($delivery['status_of_delivery'] === 'Delivered' && $miles_driven > 0) {
        $total_truckloads += 1;
    }

    // Summations
    $total_miles_driven += $miles_driven;
    $fuel_consumption    = $miles_driven * 0.1667; // example factor
    $total_fuel_consumption += $fuel_consumption;

    $emissions = $fuel_consumption * 10.21; // example factor
    $total_emissions += $emissions;

    $mws_delivered = ($quantity * $wattage) / 1_000_000; // convert to MW
    $total_mws_delivered += $mws_delivered;

    // Store in array
    $delivery['miles_driven']     = $miles_driven;
    $delivery['fuel_consumption'] = $fuel_consumption;
    $delivery['emissions']        = $emissions;

    // Format date columns
    $delivery['warehouse_arrival_date_formatted'] = !empty($delivery['warehouse_arrival_date'])
        ? htmlspecialchars($delivery['warehouse_arrival_date'])
        : 'N/A';
    $delivery['actual_delivery_date_formatted'] = !empty($delivery['actual_delivery_date'])
        ? htmlspecialchars($delivery['actual_delivery_date'])
        : 'N/A';

    // For table-level unique filters
    $supplier_values[] = $delivery['supplier'];
    $wattage_values[]  = $delivery['wattage'];
    $status_values[]   = $delivery['status_of_delivery'];

    $deliveries[] = $delivery;
}

// --------------------------------------------------------------------------
// Additional filter logic from your code
// --------------------------------------------------------------------------
if ($filter === 'emissions_per_mw') {
    if ($total_mws_delivered > 0) {
        $emissions_per_mw = $total_emissions / $total_mws_delivered;
    } else {
        $emissions_per_mw = 0;
    }
}
elseif ($filter === 'emissions_vs_average') {
    // Compare project emissions to user's average
    if ($role === 'admin') {
        $sql_projects = "SELECT id FROM projects";
        $stmt_proj    = $conn->prepare($sql_projects);
        $stmt_proj->execute();
        $projects_result = $stmt_proj->get_result();
        $stmt_proj->close();
    } else {
        $sql_projects = "SELECT id FROM projects WHERE user_id = ?";
        $stmt_proj    = $conn->prepare($sql_projects);
        $stmt_proj->bind_param("i", $user_id);
        $stmt_proj->execute();
        $projects_result = $stmt_proj->get_result();
        $stmt_proj->close();
    }

    $total_user_emissions = 0;
    $total_user_mws       = 0;

    while ($proj = $projects_result->fetch_assoc()) {
        $proj_id = $proj['id'];
        $sql_del = "SELECT * FROM deliveries WHERE project_id = ?";
        $stmt_del = $conn->prepare($sql_del);
        $stmt_del->bind_param("i", $proj_id);
        $stmt_del->execute();
        $del_res = $stmt_del->get_result();
        $stmt_del->close();

        $proj_total_emissions = 0;
        $proj_total_mws       = 0;
        while ($d = $del_res->fetch_assoc()) {
            $qty    = (int)$d['quantity'];
            $watt   = (float)$d['wattage'];
            $miles  = (float)($d['miles'] ?? 0);

            $fuel   = $miles * 0.1667;
            $e      = $fuel * 10.21;
            $proj_total_emissions += $e;
            $proj_total_mws       += ($qty * $watt)/1_000_000;
        }
        $total_user_emissions += $proj_total_emissions;
        $total_user_mws       += $proj_total_mws;
    }

    if ($total_user_mws > 0) {
        $average_emissions_per_mw = $total_user_emissions / $total_user_mws;
    } else {
        $average_emissions_per_mw = 0;
    }

    if ($total_mws_delivered > 0) {
        $project_emissions_per_mw = $total_emissions / $total_mws_delivered;
    } else {
        $project_emissions_per_mw = 0;
    }

    $difference = $project_emissions_per_mw - $average_emissions_per_mw;
}

// --------------------------------------------------------------------------
// Handle CSV Export (includes same date/time/status filters)
// --------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] == 1) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=sustainability_details.csv');
    $output = fopen('php://output', 'w');

    // Reordered so BOL Number is after Quantity, before Status
    fputcsv($output, array(
        'Supplier',
        'Wattage',
        'Quantity',
        'BOL Number',
        'Status',
        'Miles',
        'Fuel Consumption',
        'Emissions'
    ));

    foreach ($deliveries as $del) {
        $row = [
            $del['supplier'],
            $del['wattage'],
            $del['quantity'],
            $del['bol_number'] ?? '',
            $del['status_of_delivery'],
            number_format($del['miles_driven'], 2),
            number_format($del['fuel_consumption'], 2),
            number_format($del['emissions'], 2),
        ];
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sustainability Details for <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Key metrics row */
        .cost-overview {
            display: flex;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .cost-metric {
            flex: 1;
            min-width: 220px;
            margin: 10px;
            padding: 20px;
            background-color: #f2f2f2;
            text-align: center;
            border-radius: 8px;
        }
        .cost-metric h3 {
            margin-bottom: 10px;
        }

        /* Positioning for the "radio" filter form for (total, ytd, etc.) */
        .legacy-filter-form {
            margin-left: 20px;
            margin-top: 10px;
        }

        /* Time Filter Header (like project_cost_details) */
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

        /* Hiding search + export on mobile */
        @media screen and (max-width: 768px) {
            .mobile-hide {
                display: none !important;
            }
        }

        /* Table container */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            box-sizing: border-box;
        }
        .table-responsive table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px; 
        }
        table, th, td {
            border: 1px solid #ccc;
        }
        th, td {
            padding: 8px;
            white-space: nowrap;
        }
        tr:hover {
            background: #f1f1f1;
        }

        /* Back icon style */
        .back-icon {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            color: #000;
            margin: 20px;
        }
        .back-icon svg {
            width: 24px;
            height: 24px;
            margin-right: 5px;
        }
    </style>
    <script>
    (function() {
        var referrer = document.referrer;
        if (!referrer) return;

        var refAnchor = document.createElement('a');
        refAnchor.href = referrer;

        var curAnchor = document.createElement('a');
        curAnchor.href = window.location.href;

        var refPath = refAnchor.protocol + '//' + refAnchor.host + refAnchor.pathname;
        var curPath = curAnchor.protocol + '//' + curAnchor.host + curAnchor.pathname;

        if (refPath !== curPath) {
            sessionStorage.setItem('backButtonURL', referrer);
        }
    })();

    function goBack() {
        var backURL = sessionStorage.getItem('backButtonURL');
        if (backURL && backURL !== window.location.href) {
            window.location.href = backURL;
        } else {
            window.history.back();
        }
    }

    // Simple client-side table search
    function searchTable() {
        var input = document.getElementById("searchInput");
        if (!input) return;

        var filter = input.value.toLowerCase();
        var table = document.getElementById("deliveriesTable");
        var trs   = table.getElementsByTagName("tr");
        for (var i = 1; i < trs.length; i++) {
            var tds = trs[i].getElementsByTagName("td");
            var show = false;
            for (var j = 0; j < tds.length; j++) {
                var txtValue = tds[j].textContent || tds[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    show = true;
                    break;
                }
            }
            trs[i].style.display = show ? "" : "none";
        }
    }
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Back link -->
    <a href="#" onclick="goBack()" class="back-icon">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>

    <h1>Sustainability Details for <?php echo htmlspecialchars($project_name); ?></h1>

    <!-- The original radio-button filter form (total, ytd, etc.) -->
    <form method="GET" class="legacy-filter-form">
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
        <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter); ?>">
        <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">

        <label>
            <input type="radio" name="filter" value="total"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'total') echo 'checked'; ?>>
            Total
        </label>
        <label>
            <input type="radio" name="filter" value="ytd"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'ytd') echo 'checked'; ?>>
            YTD
        </label>
        <label>
            <input type="radio" name="filter" value="emissions_per_mw"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'emissions_per_mw') echo 'checked'; ?>>
            Emissions per MW
        </label>
        <label>
            <input type="radio" name="filter" value="emissions_vs_average"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'emissions_vs_average') echo 'checked'; ?>>
            Project Emissions vs Average
        </label>
    </form>

    <!-- Key metrics row -->
    <div class="cost-overview">
        <?php if ($filter === 'emissions_per_mw'): ?>
            <div class="cost-metric">
                <h3>Emissions per MW</h3>
                <p><?php echo number_format($emissions_per_mw ?? 0, 2); ?> kg CO₂ / MW</p>
            </div>
        <?php elseif ($filter === 'emissions_vs_average'): ?>
            <div class="cost-metric">
                <h3>Project Emissions vs Average</h3>
                <p>
                  <?php 
                    // $difference = project_emissions_per_mw - average_emissions_per_mw
                    echo number_format($difference ?? 0, 2); 
                  ?> kg CO₂ / MW
                </p>
            </div>
        <?php else: ?>
            <div class="cost-metric">
                <h3>Total Emissions<?php echo ($filter==='ytd'?' (YTD)':''); ?></h3>
                <p><?php echo number_format($total_emissions, 2); ?> kg CO₂</p>
            </div>
            <div class="cost-metric">
                <h3>Total Truckloads<?php echo ($filter==='ytd'?' (YTD)':''); ?></h3>
                <p><?php echo number_format($total_truckloads); ?></p>
            </div>
            <div class="cost-metric">
                <h3>Miles Driven<?php echo ($filter==='ytd'?' (YTD)':''); ?></h3>
                <p><?php echo number_format($total_miles_driven, 2); ?></p>
            </div>
            <div class="cost-metric">
                <h3>Fuel Consumption<?php echo ($filter==='ytd'?' (YTD)':''); ?></h3>
                <p><?php echo number_format($total_fuel_consumption, 2); ?> gallons</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- TIME FILTER HEADER (below cost overview, above table) -->
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
                    <option value="Pending"     <?php if ($status_filter==='Pending') echo 'selected'; ?>>Pending</option>
                    <option value="In Transit"  <?php if ($status_filter==='In Transit') echo 'selected'; ?>>In Transit</option>
                    <option value="Delivered"   <?php if ($status_filter==='Delivered') echo 'selected'; ?>>Delivered</option>
                    <option value="Complete"    <?php if ($status_filter==='Complete') echo 'selected'; ?>>Complete</option>
                    <!-- Add others as needed -->
                </select>

                <!-- Export to CSV (mobile-hide) -->
                <span class="mobile-hide">
                    <button type="submit" name="export" value="1">Export to CSV</button>
                </span>
            </form>
        </div>
    </div>

    <!-- Deliveries Table with BOL Number after "Quantity" and before "Status" -->
    <div class="table-responsive">
        <table id="deliveriesTable">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Wattage</th>
                    <th>Quantity</th>
                    <th>BOL Number</th>
                    <th>Status of Delivery</th>
                    <th>Miles Driven</th>
                    <th>Fuel Consumption</th>
                    <th>Emissions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($deliveries) > 0): ?>
                <?php foreach ($deliveries as $del): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($del['supplier'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($del['wattage'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($del['quantity'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($del['bol_number'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($del['status_of_delivery'] ?? ''); ?></td>
                        <td><?php echo number_format($del['miles_driven'], 2); ?></td>
                        <td><?php echo number_format($del['fuel_consumption'], 2); ?> gal</td>
                        <td><?php echo number_format($del['emissions'], 2); ?> kg CO₂</td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">No deliveries found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
