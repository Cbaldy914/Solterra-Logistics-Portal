<?php
session_name("logistics_session");
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Validate the project ID
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role']; // Assuming you have role information stored in the session

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Verify that the user has access to this project
if ($role === 'admin') {
    // Admins have access to all projects
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
} else {
    // Regular users can only access their own projects
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $project_id, $user_id);
}
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("You do not have access to this project.");
}
$project = $result->fetch_assoc();
$stmt->close();

/**
 * TIME FILTER LOGIC
 * We use COALESCE(actual_delivery_date, anticipated_delivery_date) so that if
 * actual_delivery_date is missing, we fall back to anticipated_delivery_date.
 */
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)"; // The date column we'll filter by
$time_filter = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date = isset($_GET['ref_date']) ? $_GET['ref_date'] : date('Y-m-d');

$dateCondition = "";
$paramTypes = "i";
$params = [$project_id];
$dateLabel = "All Deliveries";
$prev_date = "";
$next_date = "";

if ($time_filter === 'day') {
    $dateCondition = " AND DATE($filterColumn) = ?";
    $paramTypes .= "s";
    $params[] = $ref_date;

    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($ref_date . " -1 day"));
    $next_date = date('Y-m-d', strtotime($ref_date . " +1 day"));
} elseif ($time_filter === 'week') {
    $timestamp = strtotime($ref_date);
    // 0 (Sunday) to 6 (Saturday)
    $dayOfWeek = date('w', $timestamp);
    $startOfWeek = date('Y-m-d', strtotime("-{$dayOfWeek} days", $timestamp));
    $endOfWeek   = date('Y-m-d', strtotime("+" . (6 - $dayOfWeek) . " days", $timestamp));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    $params[] = $startOfWeek;
    $params[] = $endOfWeek;

    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime($startOfWeek . " -7 days"));
    $next_date = date('Y-m-d', strtotime($startOfWeek . " +7 days"));
} elseif ($time_filter === 'month') {
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth   = date('Y-m-t', strtotime($ref_date));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    $params[] = $startOfMonth;
    $params[] = $endOfMonth;

    $dateLabel = date('F Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($startOfMonth . " -1 month"));
    $next_date = date('Y-m-d', strtotime($startOfMonth . " +1 month"));
}

// STATUS FILTER
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$statusCondition = "";
if (!empty($status_filter)) {
    $statusCondition = " AND status_of_delivery = ?";
    $paramTypes .= "s";
    $params[] = $status_filter;
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] == 1) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=deliveries.csv');
    $output = fopen('php://output', 'w');
    // Output the column headings
    fputcsv($output, [
        'Supplier',
        'Wattage',
        'Status of Delivery',
        'Quantity',
        'BOL Number',
        'Anticipated Delivery Date',
        'Warehouse Arrival Date',
        'Actual Delivery Date',
        'Proof of Delivery'
    ]);

    // Same filtering logic for CSV
    $sql_export = "
        SELECT *
        FROM deliveries
        WHERE project_id = ? 
          $dateCondition
          $statusCondition
        ORDER BY $filterColumn DESC
    ";
    $stmt_export = $conn->prepare($sql_export);
    $stmt_export->bind_param($paramTypes, ...$params);
    $stmt_export->execute();
    $res_export = $stmt_export->get_result();

    while ($row = $res_export->fetch_assoc()) {
        fputcsv($output, [
            $row['supplier'] ?? '',
            $row['wattage'] ?? '',
            $row['status_of_delivery'] ?? '',
            $row['quantity'] ?? '',
            $row['bol_number'] ?? '',
            $row['anticipated_delivery_date'] ?? '',
            $row['warehouse_arrival_date'] ?? '',
            $row['actual_delivery_date'] ?? '',
            !empty($row['proof_of_delivery']) ? 'Yes' : 'No'
        ]);
    }
    fclose($output);
    $stmt_export->close();
    $conn->close();
    exit();
}

// Retrieve deliveries with the chosen filters, ordering by fallback date
$sql = "
    SELECT *
    FROM deliveries
    WHERE project_id = ?
      $dateCondition
      $statusCondition
    ORDER BY $filterColumn DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$deliveries_result = $stmt->get_result();

$deliveries = [];
while ($delivery = $deliveries_result->fetch_assoc()) {
    $deliveries[] = $delivery;
}
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project['project_name']); ?> - Delivery Tracker</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>

        .container {
            margin: 20px;
        }

        /* Time Filter Header */
        .time-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            margin-bottom: 10px;
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
            margin: 20px;
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
            flex-direction: column; /* So the search input is above the status/export row */
            gap: 10px;
            align-items: flex-start; /* Left-align items in this column layout */
        }

        /* Back icon */
        .back-icon {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            margin: 10px;
            color: #333;
        }
        .back-icon svg {
            width: 24px;
            height: 24px;
            margin-right: 5px;
        }

        /* Table styling */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table, th, td {
            border: 1px solid #ccc;
        }
        th, td {
            padding: 10px;
        }
        tr:hover {
            background: #f1f1f1;
        }

        /* Responsive table container */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        /* Hide Search & Export on screens <= 768px */
        @media screen and (max-width: 768px) {
            .mobile-hide {
                display: none !important;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <a href="project_overview?id=<?php echo $project_id; ?>" class="back-icon" style="margin:20px;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:24px;height:24px;">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>

        <div class="container">
            <h1>Delivery Tracker for <?php echo htmlspecialchars($project['project_name']); ?></h1>

            <!-- Time Filter Header (left: All/Day/Week/Month, center: date nav, right: search above status/export) -->
            <div class="time-filter-header">
                <!-- Left side: All/Day/Week/Month -->
                <div class="time-filters">
                    <a href="?project_id=<?php echo $project_id; ?>&time_filter=all"
                       class="<?php echo ($time_filter === 'all') ? 'active' : ''; ?>">
                       All
                    </a>
                    <a href="?project_id=<?php echo $project_id; ?>&time_filter=day&ref_date=<?php echo $ref_date; ?>"
                       class="<?php echo ($time_filter === 'day') ? 'active' : ''; ?>">
                       Day
                    </a>
                    <a href="?project_id=<?php echo $project_id; ?>&time_filter=week&ref_date=<?php echo $ref_date; ?>"
                       class="<?php echo ($time_filter === 'week') ? 'active' : ''; ?>">
                       Week
                    </a>
                    <a href="?project_id=<?php echo $project_id; ?>&time_filter=month&ref_date=<?php echo $ref_date; ?>"
                       class="<?php echo ($time_filter === 'month') ? 'active' : ''; ?>">
                       Month
                    </a>
                </div>

                <!-- Center: Date Label and Prev/Next arrows -->
                <div class="date-navigation">
                    <?php if ($time_filter !== 'all'): ?>
                        <button type="button" class="nav-arrow"
                                onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>'">
                            &larr;
                        </button>
                    <?php endif; ?>
                    <span class="date-label"><?php echo $dateLabel; ?></span>
                    <?php if ($time_filter !== 'all'): ?>
                        <button type="button" class="nav-arrow"
                                onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>'">
                            &rarr;
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Right side: Search in Table ABOVE Filter by Status & Export -->
                <div class="right-filters">
                    <!-- Client-side search (hidden on mobile) -->
                    <div style="display: flex; gap: 10px;" class="mobile-hide">
                        <label for="searchInput" style="align-self: center;">Search in Table:</label>
                        <input type="text" id="searchInput" placeholder="Type to filter..." onkeyup="searchTable()">
                    </div>

                    <!-- Filter by Status + Export form -->
                    <form method="get" action="" style="display: flex; gap: 10px;">
                        <!-- Keep project/time/ref_date so we don't lose them on status change -->
                        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                        <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
                        <input type="hidden" name="ref_date" value="<?php echo $ref_date; ?>">

                        <!-- Filter by Status -->
                        <label for="status_filter" style="align-self: center;">Filter by Status:</label>
                        <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="Pending"    <?php if($status_filter === 'Pending')    echo 'selected'; ?>>Pending</option>
                            <option value="Produced" <?php if($status_filter === 'Produced') echo 'selected'; ?>>Produced</option>
                            <option value="In Warehouse"  <?php if($status_filter === 'In Warehouse')  echo 'selected'; ?>>In Warehouse</option>
                            <option value="Delivered"   <?php if($status_filter === 'Delivered')   echo 'selected'; ?>>Delivered</option>
                        </select>

                        <!-- Export to CSV (hidden on mobile) -->
                        <span class="mobile-hide">
                            <button type="submit" name="export" value="1">Export to CSV</button>
                        </span>
                    </form>
                </div>
            </div>

            <!-- Deliveries Table -->
            <div class="table-responsive">
                <table id="deliveriesTable">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Wattage</th>
                            <th>Status of Delivery</th>
                            <th>Quantity</th>
                            <th>BOL Number</th>
                            <th>Anticipated Delivery Date</th>
                            <th>Warehouse Arrival Date</th>
                            <th>Actual Delivery Date</th>
                            <th>Proof of Delivery</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($deliveries)): ?>
                        <?php foreach ($deliveries as $delivery): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($delivery['supplier'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($delivery['wattage'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($delivery['quantity'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($delivery['bol_number'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($delivery['anticipated_delivery_date'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($delivery['actual_delivery_date'] ?? ''); ?></td>
                                <td>
                                    <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                        <a href="view_pod?delivery_id=<?php echo $delivery['id']; ?>" target="_blank">
                                            View POD
                                        </a>
                                    <?php else: ?>
                                        <?php if ($_SESSION['role'] === 'admin'): ?>
                                            <a href="upload_pod?delivery_id=<?php echo $delivery['id']; ?>">
                                                Upload POD
                                            </a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9">No delivery entries found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Simple client-side search -->
    <script>
    function searchTable() {
        var input = document.getElementById("searchInput");
        var filter = input.value.toLowerCase();
        var table = document.getElementById("deliveriesTable");
        var trs = table.getElementsByTagName("tr");

        // Start from i=1 to skip the table header
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
</body>
</html>
