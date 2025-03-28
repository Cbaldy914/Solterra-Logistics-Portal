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
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// -----------------------------------------------------------
// 4) Check if we have project_id in GET
//    If not, display a simple "Select a Project" form
// -----------------------------------------------------------
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    // We do not have a project_id, so let's display a selection form.

    // Fetch all projects for the dropdown
    $sql    = "SELECT id, project_name FROM projects ORDER BY project_name ASC";
    $result = $conn->query($sql);

    // Close body if we won't continue
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
    exit(); // stop here, since we only displayed the project selection form
}

// -----------------------------------------------------------
// 5) We do have a project_id. Let's proceed with invoice logic
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

$dateCondition = "";
$paramTypes    = "i"; // for project_id
$params        = [$project_id];

$dateLabel = "All Deliveries";
$prev_date = "";
$next_date = "";

if ($time_filter === 'day') {
    $dateCondition = " AND DATE($filterColumn) = ?";
    $paramTypes   .= "s";
    $params[]      = $ref_date;

    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($ref_date . " -1 day"));
    $next_date = date('Y-m-d', strtotime($ref_date . " +1 day"));

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

// Build final deliveries query (including invoice_number)
$sql_deliveries = "
    SELECT *
    FROM deliveries
    WHERE project_id = ?
          $dateCondition
    ORDER BY $filterColumn DESC
";

$stmt_deliveries = $conn->prepare($sql_deliveries);
$stmt_deliveries->bind_param($paramTypes, ...$params);
$stmt_deliveries->execute();
$deliveries_result = $stmt_deliveries->get_result();
$stmt_deliveries->close();

// Collect deliveries in array
$deliveries = [];
while ($row = $deliveries_result->fetch_assoc()) {
    $row['actual_delivery_date_formatted'] = !empty($row['actual_delivery_date'])
        ? htmlspecialchars($row['actual_delivery_date'])
        : 'N/A';
    $deliveries[] = $row;
}
$conn->close();

// =====================================
// Handle Creating an Invoice
// =====================================
$invoiceCreated   = false;
$newInvoiceNumber = "";
$selectedCount    = 0;
$selectedRows     = [];

if (isset($_POST['create_invoice']) && !empty($_POST['selected_ids'])) {
    // Re-open connection for the update
    $conn = getDBConnection();
    if (!$conn) {
        die("Connection failed");
    }

    // Typically, generate a unique invoice number
    $newInvoiceNumber = 'INV_' . date('Ymd_His');

    // selected_ids is a JSON-encoded array
    $selectedRows = json_decode($_POST['selected_ids'], true);
    if (!is_array($selectedRows)) {
        $selectedRows = [];
    }
    $selectedCount = count($selectedRows);

    if ($selectedCount > 0) {
        // Build placeholders for the IN clause
        $placeholders = rtrim(str_repeat('?,', $selectedCount), ',');
        // Create param type string
        $paramTypesIn = str_repeat('i', $selectedCount);

        // Prepare update
        $sqlUpdate = "UPDATE deliveries
                      SET invoice_number = ?
                      WHERE id IN ($placeholders)";

        $stmt = $conn->prepare($sqlUpdate);

        // Merge the invoice_number param + selected IDs
        $allParams = array_merge([$newInvoiceNumber], $selectedRows);
        // So total param types = 1 's' + N 'i'
        $bindTypes = 's' . $paramTypesIn;

        $stmt->bind_param($bindTypes, ...$allParams);
        $stmt->execute();
        $stmt->close();

        $invoiceCreated = true;
    }
    $conn->close();
}

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
        .nav-arrow:hover { background: #ccc; }
        .date-label {
            font-weight: bold;
            font-size: 1.1em;
        }
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        table, th, td {
            border: 1px solid #ccc;
            padding: 8px;
            white-space: nowrap;
        }
        tr.selected {
            background-color: #cce5ff; /* highlight color */
        }
        .invoice-preview {
            border: 1px solid #ccc;
            padding: 20px;
            margin: 20px;
            background: #fefefe;
        }
        .invoice-header {
            display: flex;
            justify-content: space-between;
        }
        .invoice-header img {
            max-height: 60px;
        }
        .invoice-title {
            margin-top: 0;
            text-align: right;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .invoice-table th, .invoice-table td {
            border: 1px solid #ccc;
            padding: 8px;
        }
    </style>

    <script>
        // Keep track of selected row IDs
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

        function createInvoice() {
            if (selectedIds.length === 0) {
                alert('No line items selected to invoice.');
                return;
            }
            const form = document.getElementById('invoiceForm');
            const hiddenInput = document.getElementById('selectedIds');
            hiddenInput.value = JSON.stringify(selectedIds);
            form.submit();
        }
    </script>

</head>
<body>
<?php include 'header.php'; ?>

<main>
    <h1>Generate Invoice - <?php echo htmlspecialchars($project_name); ?></h1>

    <!-- TIME FILTER HEADER -->
    <div class="time-filter-header">
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

        <div class="date-navigation">
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>'">
                    &larr;
                </button>
            <?php endif; ?>
            <span class="date-label"><?php echo $dateLabel; ?></span>
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>'">
                    &rarr;
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- "Create Invoice" controls -->
    <div class="invoice-controls">
        <form id="invoiceForm" method="POST">
            <input type="hidden" name="create_invoice" value="1">
            <input type="hidden" name="selected_ids" id="selectedIds" value="">
        </form>
        <button onclick="createInvoice()">Create Invoice</button>
        <span>Selected Count: <strong id="selectedCount">0</strong></span>
    </div>

    <!-- TABLE of Deliveries -->
    <table>
        <thead>
            <tr>
                <th>BOL#</th>
                <th>Wattage</th>
                <th>Quantity</th>
                <th>Status of Delivery</th>
                <!-- Removed Warehouse Arrival Date -->
                <th>Delivered to Site Date</th>
                <th>Freight Cost</th>
                <th>Accessorial Cost</th>
                <!-- New: Invoice # column -->
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

    <!-- If an invoice was created, show a preview -->
    <?php if ($invoiceCreated && $selectedCount > 0): ?>
        <div class="invoice-preview">
            <div class="invoice-header">
                <img src="pictures/solterra_logo.png" alt="Solterra Solutions Logo">
                <div>
                    <h2 class="invoice-title">Invoice #<?php echo $newInvoiceNumber; ?></h2>
                    <p>Date: <?php echo date('F j, Y'); ?></p>
                    <p>Due Date: [Due Date Here]</p>
                </div>
            </div>
            <hr>

            <p><strong>Solterra Solutions</strong><br>
               8801 Fast Park Drive<br>
               Suite 301 PMB1073<br>
               Raleigh, NC 27617
            </p>

            <p><strong>Bill To:</strong><br>
               [Client Name / Company]<br>
               [Client Address]</p>

            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <th>BOL#</th>
                        <th>Wattage</th>
                        <th>Quantity</th>
                        <th>Freight</th>
                        <th>Accessorial</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Show a basic table of the deliveries that were invoiced
                foreach ($selectedRows as $rowId) {
                    foreach ($deliveries as $del) {
                        if ((int)$del['id'] === (int)$rowId) {
                            echo "<tr>";
                            echo "<td>" . (int)$del['id'] . "</td>";
                            echo "<td>" . htmlspecialchars($del['bol_number'] ?? '') . "</td>";
                            echo "<td>" . htmlspecialchars($del['wattage'] ?? '') . "</td>";
                            echo "<td>" . htmlspecialchars($del['quantity'] ?? '') . "</td>";
                            echo "<td>$" . number_format($del['freight_cost'] ?? 0, 2) . "</td>";
                            echo "<td>$" . number_format($del['accessorial_costs'] ?? 0, 2) . "</td>";
                            echo "</tr>";
                        }
                    }
                }
                ?>
                </tbody>
            </table>

            <p><em>Additional invoice details, totals, etc. go here.</em></p>
            <hr>

            <p>
                <strong>Payment Instructions:</strong><br>
                Please make payments to: Solterra Solutions, LLC<br>
                Routing Number: 083000137<br>
                Account Number: 605665101
            </p>

            <p>
                For any questions regarding this invoice, contact us at
                <a href="mailto:info@solterrasol.com">info@solterrasol.com</a>.
            </p>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
