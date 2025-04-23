<?php
session_name("logistics_session");
session_start();

// 1) Ensure user is global_admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("DB Connection failed.");
}

// Optional: Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ------------------------------------------------------------------
   A) "Filter" Logic (All Time / YTD / Date Range)
   BUT we won't apply it to queries since no date column exists
   ------------------------------------------------------------------ */
$time_filter = $_GET['time_filter'] ?? 'all'; // 'all' or 'ytd'
$start_date  = trim($_GET['start_date'] ?? '');
$end_date    = trim($_GET['end_date']   ?? '');

/* Because there's no date column, we won't build a WHERE condition at all. 
   We'll keep the radio + date pickers in the UI so you can re-enable them later. */

/* ------------------------------------------------------------------
   B) FETCH SUMMARY (Revenue, Expenses, Net Profit)
   We do not filter by date, because there's no date column to filter.
   ------------------------------------------------------------------ */

// (B1) Total Revenue => sum of paid invoices
$sqlRev = "SELECT IFNULL(SUM(amount),0) AS total_revenue
             FROM project_invoices
            WHERE status='Paid'";
$resRev = $conn->query($sqlRev);
$rowRev = $resRev->fetch_assoc();
$totalRevenue = (float)$rowRev['total_revenue'];
$resRev->close();

// (B2) Total Expenses => sum of paid vendor amounts
$sqlExp = "SELECT IFNULL(SUM(amount),0) AS total_expenses
             FROM accounts_payable";
$resExp = $conn->query($sqlExp);
$rowExp = $resExp->fetch_assoc();
$totalExpenses = (float)$rowExp['total_expenses'];
$resExp->close();

// (B3) Net Profit
$netProfit = $totalRevenue - $totalExpenses;

/* ------------------------------------------------------------------
   C) PER-PROJECT STATS
   ------------------------------------------------------------------ */
/*
   For each project:
   - Delivery Percentage = (delivered_qty / total_order) * 100
   - Summed "Paid Invoices" => revenue (no date filter)
   - Summed vendor payables => expenses (no date filter)
   - net_profit = revenue - expenses
   - status = "Complete" if delivery_pct >= 100, else "Active"
*/

// 1) Load all projects
$projects = [];
$sqlProj = "SELECT id, project_name 
              FROM projects
             ORDER BY project_name";
$resProj = $conn->query($sqlProj);
while ($p = $resProj->fetch_assoc()) {
    $projects[] = [
        'id'            => (int)$p['id'],
        'project_name'  => $p['project_name'],
        'delivered_qty' => 0,
        'total_order'   => 0,
        'delivery_pct'  => 0.0,
        'status'        => 'Active',
        'revenue'       => 0.0,
        'expenses'      => 0.0,
        'net_profit'    => 0.0
    ];
}
$resProj->close();

// Create a map project_id -> index
$idxMap = [];
foreach ($projects as $i => $pr) {
    $idxMap[$pr['id']] = $i;
}

// 2) total_order from project_wattage_orders
$sqlOrd = "SELECT project_id, SUM(total_order) AS sum_order
             FROM project_wattage_orders
            GROUP BY project_id";
$resOrd = $conn->query($sqlOrd);
while ($o = $resOrd->fetch_assoc()) {
    $pid = (int)$o['project_id'];
    if (isset($idxMap[$pid])) {
        $projects[$idxMap[$pid]]['total_order'] = (float)$o['sum_order'];
    }
}
$resOrd->close();

// 3) sum how many modules delivered
$sqlDel = "SELECT project_id, SUM(quantity) as sum_delivered
             FROM deliveries
            WHERE status_of_delivery='Delivered'
            GROUP BY project_id";
$resDel = $conn->query($sqlDel);
while ($d = $resDel->fetch_assoc()) {
    $pid = (int)$d['project_id'];
    if (isset($idxMap[$pid])) {
        $projects[$idxMap[$pid]]['delivered_qty'] = (float)$d['sum_delivered'];
    }
}
$resDel->close();

// 4) Compute delivery pct & status
foreach ($projects as &$pr) {
    $ord = $pr['total_order'];
    $del = $pr['delivered_qty'];
    if ($ord > 0) {
        $pct = ($del / $ord) * 100;
        $pr['delivery_pct'] = $pct;
        if ($pct >= 100) {
            $pr['status'] = 'Complete';
        }
    } else {
        $pr['delivery_pct'] = 0;
    }
}
unset($pr);

// 5) sum paid invoices for each project
//    We do not filter by date
$sqlInv = "
  SELECT project_id, IFNULL(SUM(amount),0) AS sum_revenue
    FROM project_invoices
   WHERE status='Paid'
   GROUP BY project_id
";
$resInv = $conn->query($sqlInv);
while ($ri = $resInv->fetch_assoc()) {
    $pid = (int)$ri['project_id'];
    if (isset($idxMap[$pid])) {
        $projects[$idxMap[$pid]]['revenue'] = (float)$ri['sum_revenue'];
    }
}
$resInv->close();

// 6) sum payables for each project
//    We do not filter by date
$sqlPay = "
  SELECT project_id, IFNULL(SUM(amount),0) AS sum_expenses
    FROM accounts_payable
   GROUP BY project_id
";
$resPay = $conn->query($sqlPay);
while ($rp = $resPay->fetch_assoc()) {
    $pid = (int)$rp['project_id'];
    if (isset($idxMap[$pid])) {
        $projects[$idxMap[$pid]]['expenses'] = (float)$rp['sum_expenses'];
    }
}
$resPay->close();

// 7) net profit
$totalNetProfitAllProjects = 0.0;
$numProjects = count($projects);
foreach ($projects as &$pr) {
    $pr['net_profit'] = $pr['revenue'] - $pr['expenses'];
    $totalNetProfitAllProjects += $pr['net_profit'];
}
unset($pr);

// 8) average net profit per project
$averageNetProfitPerProject = ($numProjects>0)
    ? ($totalNetProfitAllProjects / $numProjects)
    : 0.0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Accounting Overview</title>
  <link rel="stylesheet" href="portal.css">
  <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
  <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Poppins',sans-serif; margin:20px; }
    .summary-cards { display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px; }
    .summary-card {
      flex:1; min-width:200px; background:#f9f9f9; border:1px solid #ccc; border-radius:6px; 
      padding:15px; text-align:center;
    }
    .summary-card h3 { margin:0; margin-bottom:10px; font-size:1.1em; }
    table {
      width:100%; border-collapse:collapse; margin-top:20px;
    }
    table,th,td {
      border:1px solid #ccc; padding:8px;
    }
    th { background:#f2f2f2; text-align:left; }
    .status-complete { color:green; font-weight:bold; }
    .status-active   { color:orange; font-weight:bold; }
    .links-bar { display:flex; gap:20px; margin-bottom:20px; }
    .links-bar a {
      background:#488C9A; color:#fff; padding:8px 16px; border-radius:4px; text-decoration:none;
    }
    .links-bar a:hover { background:#293E4C; }

    /* Filter bar: left = radio, right = date range (not used) */
    .filter-bar {
      display:flex; justify-content:space-between; align-items:center;
      margin-bottom:20px; 
    }
    .radio-group label {
      margin-right:20px;
    }
    .radio-group input[type="radio"] {
      margin-right:5px;
    }
    .date-range-form {
      display:inline-flex; align-items:center; gap:10px;
    }
    .date-range-form input[type="date"] {
      padding:8px; border:1px solid #ccc; border-radius:4px; font:inherit;
    }
    .date-range-form button {
      background:#488C9A; color:#fff; padding:8px 16px; border:none; border-radius:4px;
      cursor:pointer; font-weight:bold;
    }
    .date-range-form button:hover { background:#293E4C; }

    /* Buttons in Actions col */
    .actions button {
      background:#488C9A; color:#fff; border:none; padding:6px 12px; margin-right:5px;
      border-radius:4px; cursor:pointer; font-size:0.9rem; font-weight:500;
    }
    .actions button:hover { background:#33707b; }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
  <h1>Accounting Overview</h1>

  <!-- 1) Quick links to payables / invoices -->
  <div class="links-bar">
    <a href="total_payables.php" target="_blank">View Accounts Payable</a>
    <a href="add_invoice.php" target="_blank">View Invoices</a>
  </div>

  <!-- 2) Filter Bar: no date columns exist, so these won't do anything -->
  <div class="filter-bar">
    <!-- Left side: radio for All / YTD -->
    <form method="GET" style="margin:0;">
      <div class="radio-group">
        <label>
          <input type="radio" name="time_filter" value="all"
                 onchange="this.form.submit()"
                 <?php if($time_filter==='all') echo 'checked';?>>
          All Time
        </label>
        <label>
          <input type="radio" name="time_filter" value="ytd"
                 onchange="this.form.submit()"
                 <?php if($time_filter==='ytd') echo 'checked';?>>
          YTD
        </label>
      </div>
    </form>

    <!-- Right side: date range pickers (not actually used) -->
    <form method="GET" class="date-range-form">
      <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter);?>">
      <label>Filter by Date Range:</label>
      <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date);?>">
      <input type="date" name="end_date"   value="<?php echo htmlspecialchars($end_date);?>">
      <button type="submit">Apply</button>
    </form>
  </div>

  <!-- 3) Summary cards (Revenue, Expenses, Net Profit, Avg Net Profit/Project) -->
  <div class="summary-cards">
    <div class="summary-card">
      <h3>Total Revenue</h3>
      <p style="font-size:1.2em; font-weight:bold; color:green;">
        $<?php echo number_format($totalRevenue,2);?>
      </p>
    </div>
    <div class="summary-card">
      <h3>Total Expenses</h3>
      <p style="font-size:1.2em; font-weight:bold; color:red;">
        $<?php echo number_format($totalExpenses,2);?>
      </p>
    </div>
    <div class="summary-card">
      <h3>Net Profit</h3>
      <p style="font-size:1.2em; font-weight:bold;">
        $<?php echo number_format($netProfit,2);?>
      </p>
    </div>
    <div class="summary-card">
      <h3>Avg Net Profit/Project</h3>
      <p style="font-size:1.2em; font-weight:bold;">
        $<?php echo number_format($averageNetProfitPerProject,2);?>
      </p>
    </div>
  </div>

  <!-- 4) Per-Project Table -->
  <table>
    <thead>
      <tr>
        <th>Project Name</th>
        <th>Status</th>
        <th>Delivery Percentage</th>
        <th>Revenue (Paid)</th>
        <th>Expenses (Paid)</th>
        <th>Net Profit</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if(!empty($projects)): ?>
      <?php foreach($projects as $prj): ?>
        <?php 
          $statusCss = ($prj['status']==='Complete') ? 'status-complete' : 'status-active';
          $pct  = (float)$prj['delivery_pct'];
          $rev  = (float)$prj['revenue'];
          $exp  = (float)$prj['expenses'];
          $net  = (float)$prj['net_profit'];
        ?>
        <tr>
          <td><?php echo htmlspecialchars($prj['project_name']);?></td>
          <td class="<?php echo $statusCss;?>"><?php echo $prj['status'];?></td>
          <td><?php echo number_format($pct,2);?>%</td>
          <td>$<?php echo number_format($rev,2);?></td>
          <td>$<?php echo number_format($exp,2);?></td>
          <td>$<?php echo number_format($net,2);?></td>
          <td class="actions">
            <button onclick="alert('Details for <?php echo addslashes($prj['project_name']);?> (Placeholder)')">
              Details
            </button>
            <button onclick="window.location.href='generate_invoice.php?project_id=<?php echo (int)$prj['id'];?>'">
              Add Invoice
            </button>
            <button onclick="window.location.href='accounts_payable.php?project_id=<?php echo (int)$prj['id'];?>'">
              Add Payment
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="7">No projects found.</td></tr>
    <?php endif;?>
    </tbody>
  </table>
</main>
</body>
</html>
