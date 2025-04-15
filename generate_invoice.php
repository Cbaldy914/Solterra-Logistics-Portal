<?php
session_name("logistics_session");
session_start();

// 1) Verify login
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// 2) Verify user is 'global_admin'
if ($_SESSION['role'] !== 'global_admin') {
    die("Access denied. You must be a global admin to view this page.");
}

// Database connection
require_once '../config.php'; 
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}


// -----------------------------------------------------------
// Check if we have project_id in GET. If not, show a selector.
// -----------------------------------------------------------
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    // We do not have a project_id, so let's display a selection form.
    $sql    = "SELECT id, project_name FROM projects ORDER BY project_name ASC";
    $result = $conn->query($sql);

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Select a Project</title>
        <link rel="stylesheet" href="portal.css">
        <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </head>
    <body>
    <?php include 'header.php'; ?>

    <main style="margin: 40px;">
        <h1>Select a Project to Generate Invoice</h1>
        <?php if ($result && $result->num_rows > 0): ?>
            <form method="GET" action="generate_invoice.php">
                <label for="projectSelect">Project:</label>
                <select name="project_id" id="projectSelect" required>
                    <option value="">-- Choose Project --</option>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <option value="<?php echo (int)$row['id']; ?>">
                            <?php echo htmlspecialchars($row['project_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button type="submit">Go</button>
            </form>
        <?php else: ?>
            <p>No projects found or available.</p>
        <?php endif; ?>
    </main>
    </body>
    </html>
    <?php
    $conn->close();
    exit(); // stop here, since we only displayed the project-selection form
}

// -----------------------------------------------------------
// We DO have a project_id, so let's proceed to show deliveries
// -----------------------------------------------------------
$project_id = (int) $_GET['project_id'];

// Optional: Validate that this project actually exists
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    $conn->close();
    die("Project not found.");
}

// Define the main date column used for filtering
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";

// ----------------------------------------------------------
// 1) Existing Time Filter logic (all, day, week, month)
// ----------------------------------------------------------
$time_filter  = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date     = isset($_GET['ref_date']) ? $_GET['ref_date'] : date('Y-m-d');

// We'll store partial WHERE clauses in these variables
$dateCondition    = "";
$invoiceCondition = "";

$paramTypes = "i"; // We at least have project_id (integer)
$params     = [$project_id];

// Time-filter label, plus prev/next date logic
$dateLabel = "All Deliveries";
$prev_date = "";
$next_date = "";

// DAY
if ($time_filter === 'day') {
    $dateCondition = " AND DATE($filterColumn) = ?";
    $paramTypes   .= "s";
    $params[]      = $ref_date;
    $dateLabel     = date('F j, Y', strtotime($ref_date));
    $prev_date     = date('Y-m-d', strtotime($ref_date . " -1 day"));
    $next_date     = date('Y-m-d', strtotime($ref_date . " +1 day"));

// WEEK
} elseif ($time_filter === 'week') {
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

// MONTH
} elseif ($time_filter === 'month') {
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

// ----------------------------------------------------------
// 2) "Advanced Filters" (date range, status, invoice #, no invoice)
// ----------------------------------------------------------
$startDate     = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate       = isset($_GET['end_date'])   ? $_GET['end_date']   : '';
$statusFilter  = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$invoiceNumberFilter = isset($_GET['invoice_number_filter']) ? trim($_GET['invoice_number_filter']) : '';
$noInvoiceOnly = isset($_GET['no_invoice_only']) ? true : false;

// If user selected a custom date range, override the time filter
if (!empty($startDate) && !empty($endDate)) {
    // Force time_filter to "custom"
    $time_filter = 'custom';

    // Adjust the label to show Custom Range
    $dateLabel = "Custom Range: "
        . date('m/d/Y', strtotime($startDate))
        . " to "
        . date('m/d/Y', strtotime($endDate));

    // Remove any prev/next
    $prev_date = "";
    $next_date = "";

    // Add our custom date condition
    $dateCondition .= " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes    .= "ss";
    $params[]       = $startDate;
    $params[]       = $endDate;
}

// If user provided a status filter
if (!empty($statusFilter)) {
    $invoiceCondition .= " AND status_of_delivery LIKE ?";
    $paramTypes       .= "s";
    $params[]          = "%".$statusFilter."%";
}

// If user provided an invoice # filter
if (!empty($invoiceNumberFilter)) {
    $invoiceCondition .= " AND invoice_number LIKE ?";
    $paramTypes       .= "s";
    $params[]          = "%".$invoiceNumberFilter."%";
}

// If user only wants deliveries with NO invoice number
if ($noInvoiceOnly) {
    $invoiceCondition .= " AND (invoice_number IS NULL OR invoice_number = '')";
}

// ----------------------------------------------------------
// Build final deliveries query
// ----------------------------------------------------------
$sql_deliveries = "
    SELECT *
    FROM deliveries
    WHERE project_id = ?
          $dateCondition
          $invoiceCondition
    ORDER BY $filterColumn DESC
";

$stmt_deliveries = $conn->prepare($sql_deliveries);
$stmt_deliveries->bind_param($paramTypes, ...$params);
$stmt_deliveries->execute();
$deliveries_result = $stmt_deliveries->get_result();
$stmt_deliveries->close();

$deliveries = [];
while ($row = $deliveries_result->fetch_assoc()) {
    $row['actual_delivery_date_formatted'] = !empty($row['actual_delivery_date'])
        ? htmlspecialchars($row['actual_delivery_date'])
        : 'N/A';
    $deliveries[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Generate Invoice - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Basic styling */
        .invoice-controls {
            margin: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .invoice-controls button {
            padding: 8px 16px;
            cursor: pointer;
        }

        .time-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px;
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

        /* Advanced Filters Dropdown */
        .advanced-filters {
            position: relative;
            display: inline-block;
        }
        .advanced-filters button {
            background: #eee;
            border: none;
            padding: 8px 12px;
            cursor: pointer;
            border-radius: 4px;
        }
        .advanced-filters button:hover {
            background: #ccc;
        }
        #dropdownMenu {
            display: none;
            position: absolute;
            right: 0;
            background-color: #f9f9f9;
            min-width: 250px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            padding: 10px;
            z-index: 1;
            border-radius: 4px;
        }
        #dropdownMenu form {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        #dropdownMenu label {
            font-weight: 500;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px;
        }
        table, th, td {
            border: 1px solid #ccc;
            padding: 8px;
            white-space: nowrap;
        }
        tr.selected {
            background-color: #cce5ff; /* highlight color */
        }
        tr:hover {
            background-color: #f0f0f0;
            cursor: pointer;
        }
    </style>

    <script>
        let selectedIds = [];

        // Toggle highlight on row click
        function toggleRowSelection(row, deliveryId) {
            if (row.classList.contains('selected')) {
                row.classList.remove('selected');
                selectedIds = selectedIds.filter(id => id !== deliveryId);
            } else {
                row.classList.add('selected');
                selectedIds.push(deliveryId);
            }
            document.getElementById('selectedCount').textContent = selectedIds.length;
        }

        // Create Invoice (submit to invoice_info.php)
        function goToInvoice() {
            if (selectedIds.length === 0) {
                alert('No line items selected to invoice.');
                return;
            }
            const form = document.getElementById('invoiceForm');
            document.getElementById('selectedIds').value = JSON.stringify(selectedIds);
            form.submit();
        }

        // Show/hide the Advanced Filters dropdown
        function toggleDropdown() {
            const menu = document.getElementById('dropdownMenu');
            if (menu.style.display === 'block') {
                menu.style.display = 'none';
            } else {
                menu.style.display = 'block';
            }
        }
    </script>
</head>
<body>
<?php include 'header.php'; ?>

<main>
    <!-- 1) CREATE INVOICE CONTROLS (Above time filter) -->
    <div class="invoice-controls">
        <!-- This form leads to invoice_info.php -->
        <form id="invoiceForm" method="POST" action="invoice_info.php">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            <input type="hidden" id="selectedIds" name="selected_ids" value="">
        </form>
        <button onclick="goToInvoice()">Create Invoice</button>
        <span>Selected Count: <strong id="selectedCount">0</strong></span>
    </div>

    <!-- 2) TIME FILTER HEADER -->
    <div class="time-filter-header">
        <!-- Time filters on the LEFT -->
        <div class="time-filters">
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=all&ref_date=<?php echo urlencode($ref_date); ?>"
               class="<?php echo ($time_filter === 'all') ? 'active' : ''; ?>">
                All
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=day&ref_date=<?php echo urlencode($ref_date); ?>"
               class="<?php echo ($time_filter === 'day') ? 'active' : ''; ?>">
                Day
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=week&ref_date=<?php echo urlencode($ref_date); ?>"
               class="<?php echo ($time_filter === 'week') ? 'active' : ''; ?>">
                Week
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=month&ref_date=<?php echo urlencode($ref_date); ?>"
               class="<?php echo ($time_filter === 'month') ? 'active' : ''; ?>">
                Month
            </a>
        </div>

        <!-- Date Navigation in the MIDDLE -->
        <div class="date-navigation">
            <?php 
            // Only show prev/next if time_filter is NOT 'all' or 'custom'
            if ($time_filter !== 'all' && $time_filter !== 'custom'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>'">
                    &larr;
                </button>
            <?php endif; ?>

            <span class="date-label"><?php echo $dateLabel; ?></span>

            <?php if ($time_filter !== 'all' && $time_filter !== 'custom'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>'">
                    &rarr;
                </button>
            <?php endif; ?>
        </div>

        <!-- Advanced Filters on the RIGHT -->
        <div class="advanced-filters">
            <button type="button" onclick="toggleDropdown()">Advanced Filters</button>
            <div id="dropdownMenu">
                <form method="GET" action="generate_invoice.php">
                    <!-- Keep project_id, time_filter, ref_date so they persist -->
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
                    <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">

                    <label for="start_date">Start Date:</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?php echo htmlspecialchars($startDate); ?>">

                    <label for="end_date">End Date:</label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?php echo htmlspecialchars($endDate); ?>">

                    <label for="status_filter">Status of Delivery:</label>
                    <input type="text" id="status_filter" name="status_filter"
                           value="<?php echo htmlspecialchars($statusFilter); ?>">

                    <label for="invoice_number_filter">Invoice #:</label>
                    <input type="text" id="invoice_number_filter" name="invoice_number_filter"
                           value="<?php echo htmlspecialchars($invoiceNumberFilter); ?>">

                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="no_invoice_only" name="no_invoice_only"
                            <?php if ($noInvoiceOnly) echo 'checked'; ?>>
                        <label for="no_invoice_only" style="margin:0;">Deliveries w/o Invoice #</label>
                    </div>

                    <!-- Apply button styled to match time-filters color -->
                    <button type="submit" 
                            style="background: #488C9A; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer;">
                        Apply
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- 3) TABLE of Deliveries -->
    <table>
        <thead>
            <tr>
                <th>BOL#</th>
                <th>Wattage</th>
                <th>Quantity</th>
                <th>Status of Delivery</th>
                <th>Delivered to Site Date</th>
                <th>Freight Cost</th>
                <th>Accessorial Cost</th>
                <th>Invoice #</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($deliveries)): ?>
            <?php foreach ($deliveries as $d): ?>
                <tr onclick="toggleRowSelection(this, <?php echo (int)$d['id']; ?>)">
                    <td><?php echo htmlspecialchars($d['bol_number'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($d['wattage'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($d['quantity'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($d['status_of_delivery'] ?? ''); ?></td>
                    <td><?php echo $d['actual_delivery_date_formatted']; ?></td>
                    <td>$<?php echo number_format($d['freight_cost'] ?? 0, 2); ?></td>
                    <td>$<?php echo number_format($d['accessorial_costs'] ?? 0, 2); ?></td>
                    <td><?php echo htmlspecialchars($d['invoice_number'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="8">No deliveries found.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</main>
</body>
</html>
