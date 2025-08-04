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
$highlight_delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : null;

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
    $breadcrumbs[] = ['href' => 'dashboard.php', 'text' => 'Dashboard'];
    $breadcrumbs[] = ['href' => "project_overview.php?project_id={$project_id}", 'text' => 'Project Overview'];
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

// Show all deliveries by default - no delivery type filtering needed

// Build WHERE clause like in manage_deliveries.php
$whereClause = "WHERE 1=1";
if (!empty($baseQueryConditions)) {
    $whereClause .= " AND " . implode(" AND ", $baseQueryConditions);
}
if (!empty($dateCondition)) {
    $whereClause .= $dateCondition;
}
if (!empty($statusCondition)) {
    $whereClause .= $statusCondition;
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
    if ($stmt_export) {
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
        $stmt_export->close();
    }
    fclose($output);
    if ($conn && !$conn->connect_errno) {
        $conn->close();
    }
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
$deliveries = [];
if ($stmt) {
    if (!empty($paramTypes)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    $stmt->execute();
    $deliveries_result = $stmt->get_result();

    while ($delivery = $deliveries_result->fetch_assoc()) {
        $deliveries[] = $delivery;
    }
    $stmt->close();
}

// No need for delivery counts since we removed the tabs

// Don't close connection yet - we need it for pallet fetching in the HTML section
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
        
        /* Delivery highlighting styles */
        .highlighted-delivery {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%) !important;
            border: 3px solid #488C9A !important;
            box-shadow: 0 0 15px rgba(72, 140, 154, 0.3) !important;
            animation: highlightPulse 2s ease-in-out;
        }
        
        @keyframes highlightPulse {
            0%, 100% { 
                transform: scale(1); 
                box-shadow: 0 0 15px rgba(72, 140, 154, 0.3);
            }
            50% { 
                transform: scale(1.02); 
                box-shadow: 0 0 25px rgba(72, 140, 154, 0.5);
            }
        }
        
        /* Filters Dropdown Styling */
        .filters-dropdown-container {
            position: relative;
            display: inline-block;
        }
        
        .filters-dropdown-btn {
            background: linear-gradient(135deg, #488C9A 0%, #3a6e7f 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.95em;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        
        .filters-dropdown-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(72, 140, 154, 0.4);
        }
        
        .filters-dropdown-content {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #e1e5e9;
            border-radius: 12px;
            box-shadow: 0px 12px 24px 0px rgba(0,0,0,0.15);
            z-index: 1000;
            min-width: 400px;
            max-width: 500px;
            max-height: 500px;
            overflow-y: auto;
            backdrop-filter: blur(10px);
        }
        
        .filters-dropdown-header {
            padding: 16px 20px;
            background: linear-gradient(135deg, #488C9A 0%, #3a6e7f 100%);
            color: white;
            border-bottom: none;
            font-weight: 700;
            font-size: 1.1em;
            text-align: center;
            border-radius: 12px 12px 0 0;
        }
        
        .filter-item {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .filter-item:last-child {
            border-bottom: none;
            border-radius: 0 0 12px 12px;
        }
        
        .filter-item label {
            display: block;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 8px;
            font-size: 0.95em;
        }
        
        .filter-item input, .filter-item select {
            width: 95%;
            padding: 10px 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background-color: white;
        }
        
        .filter-item input:focus, .filter-item select:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }

        /* Column Chooser Styling */
        .column-chooser-container {
            position: relative;
            display: inline-block;
        }
        
        .column-chooser-btn {
            background-color: #488C9A;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background-color 0.3s ease;
        }
        
        .column-chooser-btn:hover {
            background-color: #3A6E7F;
        }
        
        .column-chooser-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            min-width: 250px;
            max-width: 300px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .column-chooser-header {
            padding: 12px 16px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #ddd;
            font-weight: 600;
            color: #293E4C;
        }
        
        .column-chooser-options {
            padding: 8px 0;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .column-option {
            display: flex;
            align-items: center;
            padding: 6px 16px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .column-option:hover {
            background-color: #f8f9fa;
        }
        
        .column-option input[type="checkbox"] {
            margin-right: 8px;
            cursor: pointer;
        }
        
        .column-chooser-footer {
            padding: 8px 16px;
            border-top: 1px solid #ddd;
            background-color: #f8f9fa;
        }
        
        .reset-columns-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8em;
            transition: background-color 0.3s ease;
        }
        
        .reset-columns-btn:hover {
            background-color: #5a6268;
        }
        
        /* Hidden column styling */
        .column-hidden {
            display: none !important;
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

            <!-- Right: Filters Dropdown and Column Chooser -->
            <div class="right-filters">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <!-- Filters Dropdown -->
                    <div class="filters-dropdown-container">
                        <button type="button" id="filtersDropdownBtn" class="filters-dropdown-btn" onclick="toggleFiltersDropdown()">
                            🔽 Filters
                        </button>
                        <div id="filtersDropdown" class="filters-dropdown-content" style="display: none;" onclick="preventDropdownClose(event)">
                            <div class="filters-dropdown-header">Filter Options:</div>
                            
                            <!-- Search Input -->
                            <div class="filter-item">
                                <label for="searchInput">Search in Table:</label>
                                <input type="text" id="searchInput" placeholder="Type to filter..." onkeyup="searchTable()">
                            </div>
                            
                            <!-- Filter Form -->
                            <form method="get" action="" id="filterForm">
                                <?php if ($project_id): ?>
                                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                <?php elseif ($origin_batch_id): ?>
                                <input type="hidden" name="origin_batch_id" value="<?php echo $origin_batch_id; ?>">
                                <?php endif; ?>
                                <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter ?? ''); ?>">
                                <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date ?? ''); ?>">
                                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter ?? ''); ?>">
                                
                                <div class="filter-item">
                                    <label for="status_filter">Filter by Status:</label>
                                    <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                                        <option value="">All</option>
                                        <option value="Pending" <?php if($status_filter === 'Pending') echo 'selected'; ?>>Pending</option>
                                        <option value="In Transit to Warehouse" <?php if($status_filter === 'In Transit to Warehouse') echo 'selected'; ?>>In Transit to Warehouse</option>
                                        <option value="Delivered to Warehouse" <?php if($status_filter === 'Delivered to Warehouse') echo 'selected'; ?>>Delivered to Warehouse</option>
                                        <option value="In Transit to Project" <?php if($status_filter === 'In Transit to Project') echo 'selected'; ?>>In Transit to Project</option>
                                        <option value="Delivered to Project" <?php if($status_filter === 'Delivered to Project') echo 'selected'; ?>>Delivered to Project</option>
                                        <option value="Canceled" <?php if($status_filter === 'Canceled') echo 'selected'; ?>>Canceled</option>
                                    </select>
                                </div>
                                
                                <div class="filter-item">
                                    <span class="mobile-hide">
                                        <button type="submit" name="export" value="1" style="background-color: #488C9A; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">Export to CSV</button>
                                    </span>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Column Chooser -->
                    <div class="column-chooser-container">
                        <button type="button" id="columnChooserBtn" class="column-chooser-btn" onclick="toggleColumnChooser()">
                            📋 Choose Columns
                        </button>
                        <div id="columnChooserDropdown" class="column-chooser-dropdown" style="display: none;">
                            <div class="column-chooser-header">Select Columns to Show:</div>
                            <div class="column-chooser-options">
                                <label class="column-option">
                                    <input type="checkbox" class="column-toggle" data-column="supplier-column" checked>
                                    Supplier
                                </label>
                                <label class="column-option">
                                    <input type="checkbox" class="column-toggle" data-column="wattage-column" checked>
                                    Wattage
                                </label>
                                <label class="column-option">
                                    <input type="checkbox" class="column-toggle" data-column="status-column" checked>
                                    Status of Delivery
                                </label>
                                <label class="column-option">
                                    <input type="checkbox" class="column-toggle" data-column="quantity-column" checked>
                                    Quantity
                                </label>
                                <label class="column-option">
                                    <input type="checkbox" class="column-toggle" data-column="bol-column" checked>
                                    BOL Number
                                </label>
                                <label class="column-option">
                                    <input type="checkbox" class="column-toggle" data-column="anticipated-column" checked>
                                    Anticipated Delivery Date
                                </label>
                                <label class="column-option">
                                    <input type="checkbox" class="column-toggle" data-column="actual-column" checked>
                                    Actual Delivery Date
                                </label>
                                <label class="column-option">
                                    <input type="checkbox" class="column-toggle" data-column="pallets-column" checked>
                                    Associated Pallets
                                </label>
                                <label class="column-option">
                                    <input type="checkbox" class="column-toggle" data-column="pod-column" checked>
                                    Proof of Delivery
                                </label>
                            </div>
                            <div class="column-chooser-footer">
                                <button type="button" onclick="resetColumns()" class="reset-columns-btn">Reset to Default</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Deliveries Table -->
        <div class="table-responsive">
            <table id="deliveriesTable">
                <thead>
                    <tr>
                        <th class="supplier-column">Supplier</th>
                        <th class="wattage-column">Wattage</th>
                        <th class="status-column">Status of Delivery</th>
                        <th class="quantity-column">Quantity</th>
                        <th class="bol-column">BOL Number</th>
                        <th class="anticipated-column">Anticipated Delivery Date</th>
                        <th class="actual-column">Actual Delivery Date</th>
                        <th class="pallets-column">Associated Pallets</th>
                        <th class="pod-column">Proof of Delivery</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($deliveries)): ?>
                        <?php 
                        // Create a fresh connection for pallet fetching to avoid connection issues
                        $palletConn = getDBConnection();
                        $stmtPallets = null;
                        if ($palletConn && !$palletConn->connect_errno) {
                            $stmtPallets = $palletConn->prepare("SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity FROM delivery_pallets dp JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id WHERE dp.delivery_id = ? ORDER BY ip.id");
                            if (!$stmtPallets) {
                                // Handle error appropriately - maybe log it or display a generic error
                                echo "Error preparing pallet statement: " . $palletConn->error;
                                // Optionally exit or skip the loop
                            }
                        } else {
                            echo "Database connection is not available for pallet fetching.";
                        }
                        ?>
                        <?php foreach ($deliveries as $delivery): ?>
                            <?php
                            // Fetch associated pallets for this delivery
                            $associatedPallets = [];
                            $palletDataJson = '[]'; // Default to empty JSON array
                            $palletCount = 0;
                            if ($stmtPallets) { // Check if statement was prepared successfully
                                $stmtPallets->bind_param("i", $delivery['id']);
                                $stmtPallets->execute();
                                $palletsResult = $stmtPallets->get_result();
                                while ($palletRow = $palletsResult->fetch_assoc()) {
                                    $associatedPallets[] = $palletRow;
                                }
                                $palletCount = count($associatedPallets);
                                if ($palletCount > 0) {
                                    $palletDataJson = htmlspecialchars(json_encode($associatedPallets), ENT_QUOTES, 'UTF-8');
                                }
                            }
                            ?>
                            <tr data-delivery-id="<?php echo $delivery['id']; ?>" 
                                <?php if ($highlight_delivery_id && $delivery['id'] == $highlight_delivery_id): ?>
                                    class="highlighted-delivery" id="highlighted-delivery"
                                <?php endif; ?>>
                                <td class="supplier-column"><?php echo htmlspecialchars($delivery['supplier'] ?? ''); ?></td>
                                <td class="wattage-column"><?php echo htmlspecialchars($delivery['wattage'] ?? ''); ?></td>
                                <td class="status-column"><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? ''); ?></td>
                                <td class="quantity-column"><?php echo htmlspecialchars($delivery['quantity'] ?? ''); ?></td>
                                <td class="bol-column"><?php echo htmlspecialchars($delivery['bol_number'] ?? ''); ?></td>
                                <td class="anticipated-column"><?php echo !empty($delivery['anticipated_delivery_date']) ? date('m-d-Y', strtotime($delivery['anticipated_delivery_date'])) : ''; ?></td>
                                <td class="actual-column"><?php echo !empty($delivery['actual_delivery_date']) ? date('m-d-Y', strtotime($delivery['actual_delivery_date'])) : ''; ?></td>
                                <td class="pallets-column">
                                    <?php if ($stmtPallets && $palletCount > 0): ?>
                                        <button type="button" class="action-buttons" 
                                                onclick="showPalletModal(this)" 
                                                data-pallets='<?php echo $palletDataJson; ?>'
                                                style="background-color: #488C9A; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer;">
                                            View Pallets (<?php echo $palletCount; ?>)
                                        </button>
                                    <?php elseif ($stmtPallets): ?>
                                        N/A
                                    <?php else: ?>
                                        Error
                                    <?php endif; ?>
                                </td>
                                <td class="pod-column">
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
                        <?php 
                        // Close the prepared statement after the loop
                        if ($stmtPallets) $stmtPallets->close(); 
                        // Close the pallet connection
                        if ($palletConn && !$palletConn->connect_errno) {
                            $palletConn->close();
                        }
                        // Close the main connection if it's still open
                        if ($conn && !$conn->connect_errno) {
                            $conn->close();
                        }
                        ?>
                    <?php else: ?>
                        <tr id="no-entries-row">
                            <td colspan="9">No delivery entries found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Associated Pallets Modal -->
    <div id="associatedPalletsModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.5);">
        <div style="background-color: #fff; margin: 5% auto; padding: 20px; width: 500px; max-width: 90%; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); position: relative;">
            <span onclick="closeAssociatedPalletModal()" style="position: absolute; top: 15px; right: 20px; font-size: 24px; font-weight: bold; color: #aaa; cursor: pointer; border: none; background: transparent;">&times;</span>
            <h2>Associated Pallets</h2>
            <div id="palletList" style="max-height: 300px; overflow-y: auto; border: 1px solid #eee;"> 
                <!-- Pallet details will be loaded here by JS -->
            </div>
        </div>
    </div>
</main>

<script>
// Define all global variables first
var associatedPalletsModal;
var palletListDiv;

// Define all functions first to ensure they're available when HTML loads
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

// Filters Dropdown Functions
function toggleFiltersDropdown() {
    var dropdown = document.getElementById('filtersDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    
    // Close column chooser if open
    var columnDropdown = document.getElementById('columnChooserDropdown');
    if (columnDropdown) {
        columnDropdown.style.display = 'none';
    }
}

// Column Chooser Functions
function toggleColumnChooser() {
    var dropdown = document.getElementById('columnChooserDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    
    // Close filters dropdown if open
    var filtersDropdown = document.getElementById('filtersDropdown');
    if (filtersDropdown) {
        filtersDropdown.style.display = 'none';
    }
}

function toggleColumn(columnClass, isVisible) {
    var elements = document.querySelectorAll('.' + columnClass);
    elements.forEach(function(element) {
        if (isVisible) {
            element.classList.remove('column-hidden');
        } else {
            element.classList.add('column-hidden');
        }
    });
    
    // Update the no-entries row colspan if it exists
    updateNoEntriesColspan();
}

function updateNoEntriesColspan() {
    var noEntriesRow = document.getElementById('no-entries-row');
    if (noEntriesRow) {
        var visibleColumns = document.querySelectorAll('th:not(.column-hidden)').length;
        noEntriesRow.querySelector('td').setAttribute('colspan', visibleColumns);
    }
}

function resetColumns() {
    // Reset all checkboxes to checked
    var checkboxes = document.querySelectorAll('.column-toggle');
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = true;
        toggleColumn(checkbox.getAttribute('data-column'), true);
    });
    
    // Save to localStorage
    saveColumnPreferences();
}

function saveColumnPreferences() {
    var preferences = {};
    var checkboxes = document.querySelectorAll('.column-toggle');
    checkboxes.forEach(function(checkbox) {
        preferences[checkbox.getAttribute('data-column')] = checkbox.checked;
    });
    localStorage.setItem('viewProjectColumnPreferences', JSON.stringify(preferences));
}

function loadColumnPreferences() {
    var saved = localStorage.getItem('viewProjectColumnPreferences');
    if (saved) {
        var preferences = JSON.parse(saved);
        var checkboxes = document.querySelectorAll('.column-toggle');
        checkboxes.forEach(function(checkbox) {
            var columnClass = checkbox.getAttribute('data-column');
            if (preferences.hasOwnProperty(columnClass)) {
                checkbox.checked = preferences[columnClass];
                toggleColumn(columnClass, preferences[columnClass]);
            }
        });
    }
}

// Prevent dropdown from closing when clicking inside
function preventDropdownClose(event) {
    event.stopPropagation();
}

// --- Associated Pallets Modal Functions ---

function showPalletModal(buttonElement) {
    // Initialize modal elements if not already done
    if (!associatedPalletsModal) {
        associatedPalletsModal = document.getElementById('associatedPalletsModal');
        palletListDiv = document.getElementById('palletList');
    }
    var palletsJson = buttonElement.getAttribute('data-pallets');
    try {
        var pallets = JSON.parse(palletsJson);
        palletListDiv.innerHTML = ''; // Clear previous content

        if (pallets.length > 0) {
            var table = document.createElement('table');
            table.style.width = '100%';
            table.style.borderCollapse = 'collapse';

            var thead = table.createTHead();
            var headerRow = thead.insertRow();
            var headers = ['Identifier', 'Wattage', 'Quantity', 'Actions'];
            headers.forEach(function(headerText) {
                var th = document.createElement('th');
                th.textContent = headerText;
                th.style.border = '1px solid #ddd';
                th.style.padding = '8px';
                th.style.textAlign = 'center';
                th.style.backgroundColor = '#293E4C';
                th.style.color = 'white';
                headerRow.appendChild(th);
            });

            var tbody = table.createTBody();
            pallets.forEach(function(pallet) {
                var row = tbody.insertRow();
                
                var cellIdentifier = row.insertCell();
                cellIdentifier.textContent = pallet.pallet_identifier ? pallet.pallet_identifier : `ID: ${pallet.id}`;
                cellIdentifier.style.border = '1px solid #ddd';
                cellIdentifier.style.padding = '8px';

                var cellWattage = row.insertCell();
                cellWattage.textContent = pallet.wattage ? `${pallet.wattage}W` : 'N/A';
                cellWattage.style.border = '1px solid #ddd';
                cellWattage.style.padding = '8px';

                var cellQuantity = row.insertCell();
                cellQuantity.textContent = pallet.quantity ? pallet.quantity : 'N/A';
                cellQuantity.style.border = '1px solid #ddd';
                cellQuantity.style.padding = '8px';

                var cellActions = row.insertCell();
                cellActions.style.border = '1px solid #ddd';
                cellActions.style.padding = '8px';
                cellActions.style.textAlign = 'center';

                var viewDetailsBtn = document.createElement('a');
                viewDetailsBtn.href = `pallet_details.php?pallet_id=${pallet.id}`;
                viewDetailsBtn.textContent = 'View Details';
                viewDetailsBtn.style.color = '#488C9A';
                viewDetailsBtn.style.textDecoration = 'none';
                cellActions.appendChild(viewDetailsBtn);
            });

            palletListDiv.appendChild(table);
        } else {
             var p = document.createElement('p');
             p.textContent = 'No pallets found.';
             palletListDiv.appendChild(p);
        }

        associatedPalletsModal.style.display = 'block';
    } catch (e) {
        console.error("Error parsing pallet data or creating table:", e);
        palletListDiv.innerHTML = '<p>Error loading pallet data.</p>';
        associatedPalletsModal.style.display = 'block';
    }
}

function closeAssociatedPalletModal() {
    // Initialize modal elements if not already done
    if (!associatedPalletsModal) {
        associatedPalletsModal = document.getElementById('associatedPalletsModal');
        palletListDiv = document.getElementById('palletList');
    }
    associatedPalletsModal.style.display = 'none';
    palletListDiv.innerHTML = ''; // Clear list on close
}

// Initialize everything when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Add fade out animation styles
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeOutHighlight {
            0% { 
                background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
                border: 3px solid #488C9A;
                box-shadow: 0 0 15px rgba(72, 140, 154, 0.3);
            }
            100% { 
                background: #f1f1f1;
                border: 1px solid #ccc;
                box-shadow: none;
                transform: scale(1);
            }
        }
    `;
    document.head.appendChild(style);
    
    // Initialize column chooser functionality
    loadColumnPreferences();
    
    // Add event listeners to column toggles
    var checkboxes = document.querySelectorAll('.column-toggle');
    checkboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var columnClass = this.getAttribute('data-column');
            toggleColumn(columnClass, this.checked);
            saveColumnPreferences();
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(event) {
        var columnContainer = document.querySelector('.column-chooser-container');
        var columnDropdown = document.getElementById('columnChooserDropdown');
        var filtersContainer = document.querySelector('.filters-dropdown-container');
        var filtersDropdown = document.getElementById('filtersDropdown');
        
        if (columnContainer && !columnContainer.contains(event.target)) {
            if (columnDropdown) columnDropdown.style.display = 'none';
        }
        
        if (filtersContainer && !filtersContainer.contains(event.target)) {
            if (filtersDropdown) filtersDropdown.style.display = 'none';
        }
    });
    
    // Add event listener for clicks outside the associated pallets modal
    window.addEventListener('click', function(event) {
        // Initialize modal elements if not already done
        if (!associatedPalletsModal) {
            associatedPalletsModal = document.getElementById('associatedPalletsModal');
            palletListDiv = document.getElementById('palletList');
        }
        if (event.target == associatedPalletsModal) {
            closeAssociatedPalletModal();
        }
    });

    // Auto-scroll to highlighted delivery
    const highlightedDelivery = document.getElementById('highlighted-delivery');
    if (highlightedDelivery) {
        // Wait a bit for the page to fully render, then scroll to the highlighted delivery
        setTimeout(function() {
            highlightedDelivery.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
                inline: 'nearest'
            });
            
            // Add extra pulsing to make it obvious
            highlightedDelivery.style.animation = 'highlightPulse 3s ease-in-out 2';
            
            // Optional: Remove the highlight after 15 seconds for better UX
            setTimeout(function() {
                highlightedDelivery.style.animation = 'fadeOutHighlight 2s ease-in-out forwards';
            }, 12000);
        }, 800);
    }
});
</script>
</body>
</html>
