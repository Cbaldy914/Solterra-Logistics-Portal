<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'user';

// Validate the project ID or origin_batch_id
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$origin_batch_id = isset($_GET['origin_batch_id']) ? intval($_GET['origin_batch_id']) : null;

if (empty($project_id) && empty($origin_batch_id)) {
    die("Project ID or Origin Batch ID is missing.");
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$project = null;
$page_title_info = "Delivery Tracker";
$breadcrumbs = [];
$source_vendor_name_for_batch = null;

if ($project_id) {
    // Existing project-based logic
    /**
     * If role is 'admin' or 'global_admin', user can see any project, 
     * so we just check if the project exists:
     */
    if ($role === 'admin' || $role === 'global_admin') {
        $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
    } else {
        /**
         * Otherwise (regular user role), we check if this user's account 
         * matches the project's account_id by joining projects.account_id 
         * to customer_account_users.account_id for the same user_id.
         */
        $sql_project_access = "
           SELECT p.* 
           FROM projects p
           JOIN customer_account_users cau ON p.account_id = cau.account_id
           WHERE p.id = ? 
             AND cau.user_id = ?
           LIMIT 1
        ";
        $stmt = $conn->prepare($sql_project_access);
        $stmt->bind_param("ii", $project_id, $user_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        die("You do not have access to this project or it does not exist.");
    }
    $project = $result->fetch_assoc();
    $stmt->close();
    $page_title_info = htmlspecialchars($project['project_name']);
    $breadcrumbs[] = ['href' => ($role === 'admin' || $role === 'global_admin') ? 'admin_dashboard.php' : 'dashboard.php', 'text' => 'Dashboard'];
    $breadcrumbs[] = ['href' => "project_overview.php?id={$project_id}", 'text' => 'Project Overview'];
    $breadcrumbs[] = ['text' => 'Delivery Tracker'];

} elseif ($origin_batch_id) {
    // New: Logic for origin_batch_id
    $stmtBatch = $conn->prepare("SELECT vendor_name, account_id FROM modules WHERE id = ?");
    if (!$stmtBatch) die("Failed to prepare batch query: ".$conn->error);
    $stmtBatch->bind_param("i", $origin_batch_id);
    $stmtBatch->execute();
    $resultBatch = $stmtBatch->get_result();
    if ($batchDetails = $resultBatch->fetch_assoc()) {
        $source_vendor_name_for_batch = $batchDetails['vendor_name'];
        $batch_account_id = $batchDetails['account_id'];

        // Security check for user role: can they see items from this account?
        if ($role === 'user') {
            $stmtAccess = $conn->prepare("SELECT 1 FROM customer_account_users WHERE user_id = ? AND account_id = ? LIMIT 1");
            if ($stmtAccess) {
                $stmtAccess->bind_param("ii", $user_id, $batch_account_id);
                $stmtAccess->execute();
                if ($stmtAccess->get_result()->num_rows === 0) {
                    die("You do not have access to view deliveries for this batch.");
                }
                $stmtAccess->close();
            }
        }
        $page_title_info = "Unassigned Deliveries from Batch: " . htmlspecialchars($source_vendor_name_for_batch) . " (ID: {$origin_batch_id})";
        $breadcrumbs[] = ['href' => 'modules.php', 'text' => 'Modules'];
        $breadcrumbs[] = ['href' => "module_overview.php?batch_id={$origin_batch_id}", 'text' => "Batch Details"];
        $breadcrumbs[] = ['text' => 'Unassigned Deliveries from Batch'];
    } else {
        die("Origin batch with ID {$origin_batch_id} not found.");
    }
    $stmtBatch->close();
}

/**
 * TIME FILTER LOGIC 
 * (We use COALESCE(actual_delivery_date, anticipated_delivery_date) 
 *  so if actual_delivery_date is missing, we fall back to anticipated_delivery_date.)
 */
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";
$time_filter  = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date     = isset($_GET['ref_date']) ? $_GET['ref_date'] : date('Y-m-d');

$dateCondition = "";
$baseQueryConditions = [];
$paramTypes    = "";
$params        = [];
$dateLabel     = "All Deliveries";
$prev_date     = "";
$next_date     = "";

// SELECT and FROM clauses (FROM might change based on context)
$selectClause = "SELECT d.* FROM deliveries d"; // Alias deliveries table as 'd'
$joinClause = ""; // Default, will be overridden if needed

// Determine base query conditions based on project_id or origin_batch_id
if ($project_id) {
    $baseQueryConditions[] = "d.project_id = ?"; // Use alias
    $paramTypes .= "i";
    $params[] = $project_id;
    // For project_id based view, an INNER JOIN might be appropriate if we only want deliveries linked to valid projects
    // However, to be consistent with how we might handle non-existent projects gracefully,
    // a LEFT JOIN and checking p.id IS NOT NULL could also be used, but current logic implies project must exist.
    // For now, keeping it simple for project_id case, assuming project context implies project must be valid.
    // No explicit join needed here if only filtering by d.project_id and not using p. table fields in WHERE for this simple case.
    // If other project details were needed in WHERE, a JOIN would be added.

} elseif ($origin_batch_id && $source_vendor_name_for_batch && isset($batch_account_id)) {
    // When viewing deliveries by origin_batch_id, we are looking for deliveries sourced
    // from this batch's supplier that are currently unassigned (project_id IS NULL).
    // The $batch_account_id is used for the initial access check.
    
    $joinClause = " LEFT JOIN projects p ON d.project_id = p.id"; // Kept for potential use in SELECT (e.g. p.project_name)
                                                              // though for project_id IS NULL, p fields will be NULL.

    // Filter by the batch's supplier
    $baseQueryConditions[] = "d.supplier = ?";
    $paramTypes .= "s";
    $params[] = $source_vendor_name_for_batch;
    
    // Filter for deliveries that are not assigned to any project
    $baseQueryConditions[] = "d.project_id IS NULL";
    // No additional parameters needed for "IS NULL"
}

// Day/Week/Month filter logic
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
    $dayOfWeek   = date('w', $timestamp); // Sunday=0
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

// STATUS FILTER
$status_filter   = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$statusCondition = "";
if (!empty($status_filter)) {
    $statusCondition = " AND status_of_delivery = ?";
    $paramTypes     .= "s";
    $params[]        = $status_filter;
}

// DELIVERY TYPE FILTER
$delivery_type = isset($_GET['delivery_type']) ? $_GET['delivery_type'] : 'all';
$deliveryTypeCondition = "";
if ($delivery_type === 'project') {
    $deliveryTypeCondition = " AND (d.status_of_delivery = 'In Transit to Project' OR d.status_of_delivery = 'Delivered to Project')";
} elseif ($delivery_type === 'warehouse') {
    $deliveryTypeCondition = " AND d.warehouse_id IS NOT NULL";
}
// For 'all', no additional condition needed

$whereClause = "";
if (!empty($baseQueryConditions) || !empty($dateCondition) || !empty($statusCondition) || !empty($deliveryTypeCondition)) {
    $whereClause = "WHERE " . implode(" AND ", array_filter(array_merge($baseQueryConditions, [trim(ltrim($dateCondition, ' AND '))], [trim(ltrim($statusCondition, ' AND '))], [trim(ltrim($deliveryTypeCondition, ' AND '))])));
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] == 1) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=deliveries.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Supplier',
        'Wattage',
        'Status of Delivery',
        'Quantity',
        'BOL Number',
        'Anticipated Delivery Date',
        'Actual Delivery Date',
        'Associated Pallets',
        'Proof of Delivery'
    ]);

    // Construct the SQL query for export
    $sql_export = "
        $selectClause
        $joinClause 
        $whereClause
        ORDER BY $filterColumn DESC
    ";
    $stmt_export = $conn->prepare($sql_export);
    if ($stmt_export && !empty($paramTypes)) {
        $stmt_export->bind_param($paramTypes, ...$params);
    }
    $stmt_export->execute();
    $res_export = $stmt_export->get_result();

    while ($row = $res_export->fetch_assoc()) {
        fputcsv($output, [
            $row['supplier'] ?? '',
            $row['wattage'] ?? '',
            $row['status_of_delivery'] ?? '',
            $row['quantity'] ?? '',
            $row['bol_number'] ?? '',
            !empty($row['anticipated_delivery_date']) ? date('m-d-Y', strtotime($row['anticipated_delivery_date'])) : '',
            !empty($row['actual_delivery_date']) ? date('m-d-Y', strtotime($row['actual_delivery_date'])) : '',
            $row['associated_pallets'] ?? '',
            !empty($row['proof_of_delivery']) ? 'Yes' : 'No'
        ]);
    }
    fclose($output);
    $stmt_export->close();
    $conn->close();
    exit();
}

// Retrieve deliveries with the chosen filters
$sql = "
    $selectClause
    $joinClause
    $whereClause
    ORDER BY $filterColumn DESC
";
$stmt = $conn->prepare($sql);
if ($stmt && !empty($paramTypes)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$deliveries_result = $stmt->get_result();

$deliveries = [];
while ($delivery = $deliveries_result->fetch_assoc()) {
    $deliveries[] = $delivery;
}
$stmt->close();

// Get delivery counts for tabs
$delivery_counts = ['project' => 0, 'warehouse' => 0, 'all' => 0];

// Build base query for counts (without delivery type filter)
$count_base_query = "
    SELECT 
        SUM(CASE WHEN (d.status_of_delivery = 'In Transit to Project' OR d.status_of_delivery = 'Delivered to Project') THEN 1 ELSE 0 END) as project_count,
        SUM(CASE WHEN d.warehouse_id IS NOT NULL THEN 1 ELSE 0 END) as warehouse_count,
        COUNT(d.id) as total_count
    FROM deliveries d
    $joinClause
";

// Build count query WHERE clause (excluding delivery type condition)
$count_where_conditions = array_filter(array_merge($baseQueryConditions, [trim(ltrim($dateCondition, ' AND '))], [trim(ltrim($statusCondition, ' AND '))]));
$count_where_clause = "";
if (!empty($count_where_conditions)) {
    $count_where_clause = "WHERE " . implode(" AND ", $count_where_conditions);
}

$count_sql = $count_base_query . " " . $count_where_clause;
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt && !empty($paramTypes)) {
    // Remove delivery type parameters since we're not using delivery type filter for counts
    $count_stmt->bind_param($paramTypes, ...$params);
}
$count_stmt->execute();
$count_stmt->bind_result($project_count, $warehouse_count, $total_count);
$count_stmt->fetch();
$count_stmt->close();

$delivery_counts['project'] = $project_count ?: 0;
$delivery_counts['warehouse'] = $warehouse_count ?: 0;
$delivery_counts['all'] = $total_count ?: 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title_info; ?> - Delivery Tracker</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .container {
            margin: 20px;
        }
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
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }
        .back-icon {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            margin: 10px;
            color: #333;
        }
        .back-icon svg {
            width: 24px; height:24px;
            margin-right: 5px;
        }
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
            margin-top: 10px;
            margin-left: 20px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table, th, td {
            border: 1px solid #ccc;
        }
        th, td { padding: 10px; }
        tr:hover { background: #f1f1f1; }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
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
    <div class="breadcrumb">
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <?php if (isset($crumb['href'])): ?>
                <a href="<?php echo $crumb['href']; ?>"><?php echo htmlspecialchars($crumb['text']); ?></a>
            <?php else: ?>
                <span><?php echo htmlspecialchars($crumb['text']); ?></span>
            <?php endif; ?>
            <?php if ($index < count($breadcrumbs) - 1): ?>
                <span class="separator">&raquo;</span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="container">
        <h1><?php echo $page_title_info; ?></h1>

        <!-- Delivery Type Tabs -->
        <div class="delivery-type-tabs" style="margin: 20px 0; border-bottom: 2px solid #eee;">
            <div style="display: flex; gap: 0;">
                <?php
                // Build base parameters for tab links
                $base_params = $_GET;
                $base_params['delivery_type'] = 'project';
                $project_link = '?' . http_build_query($base_params);
                
                $base_params['delivery_type'] = 'warehouse'; 
                $warehouse_link = '?' . http_build_query($base_params);
                
                $base_params['delivery_type'] = 'all';
                $all_link = '?' . http_build_query($base_params);
                ?>
                <a href="<?php echo $project_link; ?>" 
                   class="delivery-tab <?php echo ($delivery_type === 'project') ? 'active' : ''; ?>"
                   style="padding: 12px 24px; background: <?php echo ($delivery_type === 'project') ? '#488C9A' : '#f8f9fa'; ?>; color: <?php echo ($delivery_type === 'project') ? '#fff' : '#333'; ?>; text-decoration: none; border: 1px solid #ddd; border-bottom: none; border-radius: 8px 8px 0 0; margin-right: 2px;">
                    🏗️ Project Deliveries (<?php echo $delivery_counts['project']; ?>)
                </a>
                <a href="<?php echo $warehouse_link; ?>" 
                   class="delivery-tab <?php echo ($delivery_type === 'warehouse') ? 'active' : ''; ?>"
                   style="padding: 12px 24px; background: <?php echo ($delivery_type === 'warehouse') ? '#488C9A' : '#f8f9fa'; ?>; color: <?php echo ($delivery_type === 'warehouse') ? '#fff' : '#333'; ?>; text-decoration: none; border: 1px solid #ddd; border-bottom: none; border-radius: 8px 8px 0 0; margin-right: 2px;">
                    🏢 Warehouse Deliveries (<?php echo $delivery_counts['warehouse']; ?>)
                </a>
                <a href="<?php echo $all_link; ?>" 
                   class="delivery-tab <?php echo ($delivery_type === 'all') ? 'active' : ''; ?>"
                   style="padding: 12px 24px; background: <?php echo ($delivery_type === 'all') ? '#488C9A' : '#f8f9fa'; ?>; color: <?php echo ($delivery_type === 'all') ? '#fff' : '#333'; ?>; text-decoration: none; border: 1px solid #ddd; border-bottom: none; border-radius: 8px 8px 0 0;">
                    📦 All Deliveries (<?php echo $delivery_counts['all']; ?>)
                </a>
            </div>
        </div>

        <!-- Time Filter Header -->
        <div class="time-filter-header">
            <div class="time-filters">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['time_filter' => 'all'])); ?>"
                   class="<?php echo ($time_filter === 'all') ? 'active' : ''; ?>">All</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['time_filter' => 'day', 'ref_date' => $ref_date])); ?>"
                   class="<?php echo ($time_filter === 'day') ? 'active' : ''; ?>">Day</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['time_filter' => 'week', 'ref_date' => $ref_date])); ?>"
                   class="<?php echo ($time_filter === 'week') ? 'active' : ''; ?>">Week</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['time_filter' => 'month', 'ref_date' => $ref_date])); ?>"
                   class="<?php echo ($time_filter === 'month') ? 'active' : ''; ?>">Month</a>
            </div>

            <div class="date-navigation">
                <?php if ($time_filter !== 'all'): ?>
                    <button type="button" class="nav-arrow"
                            onclick="window.location.href='?<?php echo http_build_query(array_merge($_GET, ['ref_date' => $prev_date])); ?>'">
                        &larr;
                    </button>
                <?php endif; ?>
                <span class="date-label"><?php echo $dateLabel; ?></span>
                <?php if ($time_filter !== 'all'): ?>
                    <button type="button" class="nav-arrow"
                            onclick="window.location.href='?<?php echo http_build_query(array_merge($_GET, ['ref_date' => $next_date])); ?>'">
                        &rarr;
                    </button>
                <?php endif; ?>
            </div>

            <div class="right-filters">
                <div style="display: flex; gap: 10px;" class="mobile-hide">
                    <label for="searchInput" style="align-self: center;">Search in Table:</label>
                    <input type="text" id="searchInput" placeholder="Type to filter..." onkeyup="searchTable()">
                </div>
                <form method="get" action="" style="display: flex; gap: 10px;">
                    <?php if ($project_id): ?>
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <?php elseif ($origin_batch_id): ?>
                    <input type="hidden" name="origin_batch_id" value="<?php echo $origin_batch_id; ?>">
                    <?php endif; ?>
                    <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
                    <input type="hidden" name="ref_date" value="<?php echo $ref_date; ?>">
                    <input type="hidden" name="delivery_type" value="<?php echo $delivery_type; ?>">

                    <label for="status_filter" style="align-self: center;">Filter by Status:</label>
                    <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="Pending" <?php if($status_filter === 'Pending') echo 'selected'; ?>>Pending</option>
                        <option value="In Transit to Warehouse" <?php if($status_filter === 'In Transit to Warehouse') echo 'selected'; ?>>In Transit to Warehouse</option>
                        <option value="Delivered to Warehouse" <?php if($status_filter === 'Delivered to Warehouse') echo 'selected'; ?>>Delivered to Warehouse</option>
                        <option value="In Transit to Project" <?php if($status_filter === 'In Transit to Project') echo 'selected'; ?>>In Transit to Project</option>
                        <option value="Delivered to Project" <?php if($status_filter === 'Delivered to Project') echo 'selected'; ?>>Delivered to Project</option>
                        <option value="Canceled" <?php if($status_filter === 'Canceled') echo 'selected'; ?>>Canceled</option>
                    </select>

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
                        <th>Actual Delivery Date</th>
                        <th>Associated Pallets</th>
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
                                <td><?php echo !empty($delivery['anticipated_delivery_date']) ? date('m-d-Y', strtotime($delivery['anticipated_delivery_date'])) : ''; ?></td>
                                <td><?php echo !empty($delivery['actual_delivery_date']) ? date('m-d-Y', strtotime($delivery['actual_delivery_date'])) : ''; ?></td>
                                <td><?php echo htmlspecialchars($delivery['associated_pallets'] ?? ''); ?></td>
                                <td>
                                    <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                        <a href="view_pod?delivery_id=<?php echo $delivery['id']; ?>" target="_blank">
                                            View POD
                                        </a>
                                    <?php else: ?>
                                        <?php if ($role === 'global_admin'): ?>
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

<script>
function searchTable() {
    var input = document.getElementById("searchInput");
    if (!input) return;
    var filter = input.value.toLowerCase();
    var table = document.getElementById("deliveriesTable");
    var trs = table.getElementsByTagName("tr");

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
