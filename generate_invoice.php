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

// 3) Connect to DB
$servername   = "localhost";
$db_username  = "SolterraSolutions";
$db_password  = "CompanyAdmin!";
$dbname       = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
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

// TIME FILTER LOGIC
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";
$time_filter  = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date     = isset($_GET['ref_date']) ? $_GET['ref_date'] : date('Y-m-d');

// Filter by invoice number
$invoiceNumberFilter = isset($_GET['invoice_number_filter']) ? trim($_GET['invoice_number_filter']) : '';
$invoiceCondition    = "";

$dateCondition = "";
$paramTypes    = "i"; // for project_id
$params        = [$project_id];

$dateLabel = "All Deliveries";
$prev_date = "";
$next_date = "";

// Build time filter
if ($time_filter === 'day') {
    $dateCondition = " AND DATE($filterColumn) = ?";
    $paramTypes   .= "s";
    $params[]      = $ref_date;
    $dateLabel     = date('F j, Y', strtotime($ref_date));
    $prev_date     = date('Y-m-d', strtotime($ref_date . " -1 day"));
    $next_date     = date('Y-m-d', strtotime($ref_date . " +1 day"));

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

// If user is filtering by invoice number
if (!empty($invoiceNumberFilter)) {
    $invoiceCondition = " AND invoice_number LIKE ?";
    $paramTypes      .= "s";
    $params[]         = "%" . $invoiceNumberFilter . "%";
}

// Build final deliveries query
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
        /* Basic styles to position elements as requested */
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

        .invoice-number-filter {
            display: flex;
            align-items: center;
            gap: 8px;
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
    </style>

    <script>
        let selectedIds = [];

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

        function goToInvoice() {
            if (selectedIds.length === 0) {
                alert('No line items selected to invoice.');
                return;
            }
            // Submit them to invoice_info.php
            const form = document.getElementById('invoiceForm');
            document.getElementById('selectedIds').value = JSON.stringify(selectedIds);
            form.submit();
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
            <!-- Keep track of project_id so we know which project this is -->
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
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=all&ref_date=<?php echo urlencode($ref_date); ?>&invoice_number_filter=<?php echo urlencode($invoiceNumberFilter); ?>"
               class="<?php echo ($time_filter === 'all') ? 'active' : ''; ?>">
                All
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=day&ref_date=<?php echo urlencode($ref_date); ?>&invoice_number_filter=<?php echo urlencode($invoiceNumberFilter); ?>"
               class="<?php echo ($time_filter === 'day') ? 'active' : ''; ?>">
                Day
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=week&ref_date=<?php echo urlencode($ref_date); ?>&invoice_number_filter=<?php echo urlencode($invoiceNumberFilter); ?>"
               class="<?php echo ($time_filter === 'week') ? 'active' : ''; ?>">
                Week
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=month&ref_date=<?php echo urlencode($ref_date); ?>&invoice_number_filter=<?php echo urlencode($invoiceNumberFilter); ?>"
               class="<?php echo ($time_filter === 'month') ? 'active' : ''; ?>">
                Month
            </a>
        </div>

        <!-- Date Navigation in the MIDDLE -->
        <div class="date-navigation">
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>&invoice_number_filter=<?php echo urlencode($invoiceNumberFilter); ?>'">
                    &larr;
                </button>
            <?php endif; ?>
            <span class="date-label"><?php echo $dateLabel; ?></span>
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>&invoice_number_filter=<?php echo urlencode($invoiceNumberFilter); ?>'">
                    &rarr;
                </button>
            <?php endif; ?>
        </div>

        <!-- Invoice # filter on the RIGHT -->
        <div class="invoice-number-filter">
            <form method="GET" action="generate_invoice.php">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
                <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
                <label for="invoice_number_filter">Invoice #:</label>
                <input type="text" id="invoice_number_filter" name="invoice_number_filter"
                       value="<?php echo htmlspecialchars($invoiceNumberFilter); ?>">
                <button type="submit">Filter</button>
            </form>
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
                <!-- Remove Warehouse Arrival Date -->
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
