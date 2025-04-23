<?php
session_name("logistics_session");
session_start();

// 1) Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: unauthorized");
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

// If no project_id, show project selector
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    $sqlProj = "SELECT id, project_name FROM projects ORDER BY project_name ASC";
    $resultP = $conn->query($sqlProj);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Accounts Payable - Select Project</title>
        <link rel="stylesheet" href="portal.css">
        <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </head>
    <body>
    <?php include 'header.php'; ?>
    <main style="margin: 40px;">
        <h1>Accounts Payable - Select Project</h1>
        <?php if ($resultP && $resultP->num_rows > 0): ?>
            <form method="GET" action="accounts_payable.php">
                <label>Project:</label>
                <select name="project_id" required>
                    <option value="">-- choose --</option>
                    <?php while($row = $resultP->fetch_assoc()): ?>
                        <option value="<?php echo $row['id']; ?>">
                            <?php echo htmlspecialchars($row['project_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button type="submit">Go</button>
            </form>
        <?php else: ?>
            <p>No projects found.</p>
        <?php endif; ?>
    </main>
    </body>
    </html>
    <?php
    $conn->close();
    exit;
}

// We DO have a project_id
$project_id = (int)$_GET['project_id'];

// Validate the project actually exists
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id=?");
$stmt->bind_param("i",$project_id);
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    $conn->close();
    die("Project not found.");
}

/*
    --- TIME & ADVANCED FILTERS ---
*/
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";
$time_filter  = $_GET['time_filter'] ?? 'all';
$ref_date     = $_GET['ref_date']    ?? date('Y-m-d');

$startDate     = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate       = isset($_GET['end_date'])   ? $_GET['end_date']   : '';
$statusFilter  = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

$dateLabel = "All Deliveries";
$prev_date = "";
$next_date = "";

// We'll build conditions for the WHERE clause
$conditions = ["project_id = ?"];
$params     = [$project_id];
$paramTypes = "i";

// Basic time filters
if ($time_filter === 'day') {
    $conditions[] = "DATE($filterColumn) = ?";
    $params[]     = $ref_date;
    $paramTypes  .= "s";
    
    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($ref_date." -1 day"));
    $next_date = date('Y-m-d', strtotime($ref_date." +1 day"));

} elseif ($time_filter === 'week') {
    $ts   = strtotime($ref_date);
    $dow  = date('w', $ts);
    $startOfWeek = date('Y-m-d', strtotime("-{$dow} days", $ts));
    $endOfWeek   = date('Y-m-d', strtotime("+".(6 - $dow)." days", $ts));
    
    $conditions[] = "DATE($filterColumn) BETWEEN ? AND ?";
    $params[]     = $startOfWeek;
    $params[]     = $endOfWeek;
    $paramTypes  .= "ss";
    
    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime($startOfWeek." -7 days"));
    $next_date = date('Y-m-d', strtotime($startOfWeek." +7 days"));

} elseif ($time_filter === 'month') {
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth   = date('Y-m-t', strtotime($ref_date));
    
    $conditions[] = "DATE($filterColumn) BETWEEN ? AND ?";
    $params[]     = $startOfMonth;
    $params[]     = $endOfMonth;
    $paramTypes  .= "ss";
    
    $dateLabel = date('F Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($startOfMonth." -1 month"));
    $next_date = date('Y-m-d', strtotime($startOfMonth." +1 month"));
}

// Advanced range override
if (!empty($startDate) && !empty($endDate)) {
    // We'll treat it as a custom range
    $time_filter = 'custom';
    $dateLabel = "Custom: " . date('m/d/Y', strtotime($startDate)) 
               . " - " . date('m/d/Y', strtotime($endDate));
    $prev_date = "";
    $next_date = "";
    
    $conditions[] = "DATE($filterColumn) BETWEEN ? AND ?";
    $params[]     = $startDate;
    $params[]     = $endDate;
    $paramTypes  .= "ss";
}

// Status filter
if (!empty($statusFilter)) {
    $conditions[] = "status_of_delivery LIKE ?";
    $params[]     = "%".$statusFilter."%";
    $paramTypes  .= "s";
}

// Build final query
$whereClause = implode(" AND ", $conditions);
$sql = "SELECT * FROM deliveries
        WHERE $whereClause
        ORDER BY $filterColumn DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// Gather deliveries
$deliveries = [];
while ($row = $result->fetch_assoc()) {
    $deliveries[] = $row;
}
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Accounts Payable - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .pay-controls {
            margin: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px;
        }
        table, th, td {
            border: 1px solid #ccc;
            padding: 8px;
        }
        tr.selected { background: #cce5ff; }
        tr:hover { background: #f7f7f7; cursor: pointer; }
        
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
            background: #eee;
            padding: 6px 12px;
            border-radius: 4px;
            text-decoration: none;
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
        .date-label {
            font-weight: bold;
        }
        .nav-arrow {
            background: #eee;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }
        .nav-arrow:hover {
            background: #ccc;
        }
        
        /* Advanced Filters Dropdown Styles */
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
    </style>
    <script>
    let selectedIds = [];

    function toggleRow(tr, deliveryId) {
        if (tr.classList.contains('selected')) {
            tr.classList.remove('selected');
            selectedIds = selectedIds.filter(x => x !== deliveryId);
        } else {
            tr.classList.add('selected');
            selectedIds.push(deliveryId);
        }
        document.getElementById('selectedCount').textContent = selectedIds.length;
    }

    function markAsPaid() {
        if (selectedIds.length === 0) {
            alert('No deliveries selected to mark as paid.');
            return;
        }
        const form = document.getElementById('payForm');
        document.getElementById('paySelectedIds').value = JSON.stringify(selectedIds);
        form.submit();
    }

    // Show/hide the Advanced Filters dropdown
    function toggleDropdown() {
        const menu = document.getElementById('dropdownMenu');
        menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    }

    // Close dropdown if clicked outside
    window.onclick = function(event) {
        // If user didn't click the "Advanced Filters" button, close the menu
        if (!event.target.matches('.advanced-filters button')) {
            var menu = document.getElementById('dropdownMenu');
            if (menu && menu.style.display === 'block' && !menu.contains(event.target)) {
                menu.style.display = 'none';
            }
        }
    }
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Accounts Payable - <?php echo htmlspecialchars($project_name); ?></h1>

    <!-- Payment controls (like "Create Invoice" but for Mark as Paid) -->
    <div class="pay-controls">
        <form id="payForm" method="POST" action="accounts_payable_info.php">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            <input type="hidden" id="paySelectedIds" name="selected_ids" value="">
        </form>
        <button onclick="markAsPaid()">Mark as Paid</button>
        <span>Selected Count: <strong id="selectedCount">0</strong></span>
    </div>

    <!-- Time Filter header (like generate_invoice) -->
    <div class="time-filter-header">
        <!-- Left: Time Filters -->
        <div class="time-filters"> 
             <a href="?project_id=<?php echo $project_id; ?>&time_filter=all&ref_date=<?php echo urlencode($ref_date); ?>"
                class="<?php echo ($time_filter === 'all') ? 'active' : ''; ?>">All</a>
             <a href="?project_id=<?php echo $project_id; ?>&time_filter=day&ref_date=<?php echo urlencode($ref_date); ?>"
                class="<?php echo ($time_filter === 'day') ? 'active' : ''; ?>">Day</a>
             <a href="?project_id=<?php echo $project_id; ?>&time_filter=week&ref_date=<?php echo urlencode($ref_date); ?>"
                class="<?php echo ($time_filter === 'week') ? 'active' : ''; ?>">Week</a>
             <a href="?project_id=<?php echo $project_id; ?>&time_filter=month&ref_date=<?php echo urlencode($ref_date); ?>"
                class="<?php echo ($time_filter === 'month') ? 'active' : ''; ?>">Month</a>
        </div>
        
        <!-- Middle: Date Navigation -->
        <div class="date-navigation">
             <?php if ($time_filter !== 'all' && $time_filter !== 'custom'): ?>
                 <?php if ($prev_date) { ?>
                     <button class="nav-arrow" 
                             onclick="window.location='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>'">
                         &larr;
                     </button>
                 <?php } ?>
                 <span class="date-label"><?php echo $dateLabel; ?></span>
                 <?php if ($next_date) { ?>
                     <button class="nav-arrow" 
                             onclick="window.location='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>'">
                         &rarr;
                     </button>
                 <?php } ?>
             <?php else: ?>
                 <span class="date-label"><?php echo $dateLabel; ?></span>
             <?php endif; ?>
        </div>

        <!-- Right: Advanced Filters Dropdown -->
        <div class="advanced-filters">
            <button type="button" onclick="toggleDropdown()">Advanced Filters</button>
            <div id="dropdownMenu">
                <form method="GET" action="accounts_payable.php">
                    <!-- Hidden fields to persist current state -->
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
                    <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">

                    <label for="start_date">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">

                    <label for="end_date">End Date:</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">

                    <label for="status_filter">Status of Delivery:</label>
                    <input type="text" id="status_filter" name="status_filter" 
                           value="<?php echo htmlspecialchars($statusFilter); ?>">

                    <button type="submit" 
                            style="background: #488C9A; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer;">
                        Apply Filters
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Table of Deliveries -->
    <table>
        <thead>
            <tr>
                <th>BOL#</th>
                <th>Wattage</th>
                <th>Quantity</th>
                <th>Status</th> <!-- NEW column for status_of_delivery -->
                <th>Delivery Date</th> <!-- NEW column for actual_delivery_date -->
                <th>Freight Cost</th>
                <th>Accessorial Cost (charged)</th>
                <th>Accessorial Cost (paid)</th>
                <th>Pay Status</th>
                <th>Pay Date</th>
            </tr>
        </thead>
        <tbody>
        <?php if(!empty($deliveries)): ?>
            <?php foreach($deliveries as $d): ?>
            <tr onclick="toggleRow(this, <?php echo (int)$d['id']; ?>)">
                <td><?php echo htmlspecialchars($d['bol_number'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($d['wattage'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($d['quantity'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($d['status_of_delivery'] ?? ''); ?></td>
                <td>
                    <?php
                    if (!empty($d['actual_delivery_date'])) {
                        echo date('m-d-Y', strtotime($d['actual_delivery_date']));
                    }
                    ?>
                </td>
                <td>$<?php echo number_format($d['freight_cost'] ?? 0,2); ?></td>
                <td>$<?php echo number_format($d['accessorial_costs'] ?? 0,2); ?></td>
                <td>$<?php echo number_format($d['accessorial_costs_paid'] ?? 0,2); ?></td>
                <td><?php echo htmlspecialchars($d['pay_status'] ?? 'Unpaid'); ?></td>
                <td>
                    <?php
                    if (!empty($d['pay_date'])) {
                        echo date('m-d-Y', strtotime($d['pay_date']));
                    }
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="10">No deliveries found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</main>
</body>
</html>
