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

$successMessage = "";

/* ------------------------------------------------------------------
   A) overheadFilterFactor() function
   This is used to compute the overhead in the overhead table
   (monthly/quarterly/yearly view).
   ------------------------------------------------------------------ */
function overheadFilterFactor(float $amount, int $recurring, ?string $freq, string $targetView): float
{
    // If nonrecurring => just return amount
    if ($recurring !== 1) {
        return $amount;
    }
    // If recurring, transform based on targetView
    switch ($targetView) {
        case 'monthly':
            // monthly => monthly=1, quarterly => /3, yearly => /12
            if ($freq === 'Monthly')   return $amount;
            if ($freq === 'Quarterly') return $amount / 3;
            if ($freq === 'Yearly')    return $amount / 12;
            return $amount; // 'Other'
        case 'quarterly':
            // monthly => *3, quarterly => *1, yearly => /4
            if ($freq === 'Monthly')   return $amount * 3;
            if ($freq === 'Quarterly') return $amount;
            if ($freq === 'Yearly')    return $amount / 4;
            return $amount; // 'Other'
        case 'yearly':
        default:
            // monthly => *12, quarterly => *4, yearly => *1
            if ($freq === 'Monthly')   return $amount * 12;
            if ($freq === 'Quarterly') return $amount * 4;
            return $amount; // 'Yearly' or 'Other' => *1
    }
}

/* ------------------------------------------------------------------
   B) Handle 'delete_overhead' action
   ------------------------------------------------------------------ */
if (isset($_GET['action']) && $_GET['action'] === 'delete_overhead' && !empty($_GET['overhead_id'])) {
    $delId = (int)$_GET['overhead_id'];

    // Attempt to delete from overheads table
    $sqlDel = "DELETE FROM overheads WHERE id=? LIMIT 1";
    $stmtDel = $conn->prepare($sqlDel);
    if ($stmtDel) {
        $stmtDel->bind_param("i", $delId);
        if ($stmtDel->execute()) {
            $successMessage = "Overhead ID #{$delId} has been removed.";
        } else {
            $successMessage = "Error removing overhead: " . $stmtDel->error;
        }
        $stmtDel->close();
    }
}

/* ------------------------------------------------------------------
   C) Overhead Insert Logic (with "Recurring?" and "Frequency")
   ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_overhead'])) {
    $overheadAmount    = isset($_POST['overhead_amount']) ? (float)$_POST['overhead_amount'] : 0;
    $description       = trim($_POST['description'] ?? '');
    $assignedProject   = trim($_POST['assigned_project'] ?? '');
    $overheadRecurring = isset($_POST['overhead_recurring']) ? 1 : 0;
    
    // Only grab frequency if recurring is checked
    $overheadFrequency = null;
    if ($overheadRecurring === 1 && !empty($_POST['overhead_frequency'])) {
        $overheadFrequency = trim($_POST['overhead_frequency']);
    }

    // If assignedProject is numeric, we assume a project_id
    $projectId = null;
    if (!empty($assignedProject) && is_numeric($assignedProject)) {
        $projectId = (int)$assignedProject;
    }

    // Insert into overheads table
    $sqlOver = "
        INSERT INTO overheads (
            overhead_amount, 
            description, 
            project_id, 
            overhead_recurring,
            overhead_frequency,
            created_at
        ) VALUES (?,?,?,?,?, NOW())
    ";
    $stmtOver = $conn->prepare($sqlOver);
    if ($stmtOver) {
        $stmtOver->bind_param(
            "dsiss",
            $overheadAmount,
            $description,
            $projectId,
            $overheadRecurring,
            $overheadFrequency
        );
        if ($stmtOver->execute()) {
            $successMessage = "Overhead cost has been added!";
        } else {
            $successMessage = "Error adding overhead: " . $stmtOver->error;
        }
        $stmtOver->close();
    }
}

/* ------------------------------------------------------------------
   D) FETCH SUMMARY (Revenue, Expenses, Net Profit) - ANNUAL Overhead
   ------------------------------------------------------------------ */

// (1) Basic Revenue
$sqlRev = "SELECT IFNULL(SUM(amount),0) AS total_revenue
             FROM project_invoices
            WHERE status='Paid'";
$resRev = $conn->query($sqlRev);
$rowRev = $resRev->fetch_assoc();
$totalRevenue = (float)$rowRev['total_revenue'];
$resRev->close();

// (2) Basic expenses from accounts_payable
$sqlExp = "SELECT IFNULL(SUM(amount),0) AS total_expenses
             FROM accounts_payable";
$resExp = $conn->query($sqlExp);
$rowExp = $resExp->fetch_assoc();
$totalExpenses = (float)$rowExp['total_expenses'];
$resExp->close();

/* Overheads => interpret them annually if recurring. */
function annualizeOverhead(float $amount, int $recurring, ?string $freq): float
{
    if ($recurring !== 1) {
        // nonrecurring => treat it as 1-time
        return $amount;
    }
    switch ($freq) {
        case 'Monthly':
            return $amount * 12;
        case 'Quarterly':
            return $amount * 4;
        case 'Yearly':
        case 'Other':
        default:
            return $amount; 
    }
}

// Load overheads
$overheads = [];
$sqlOH = "SELECT * FROM overheads";
$resOH = $conn->query($sqlOH);
while ($r = $resOH->fetch_assoc()) {
    $overheads[] = $r;
}
$resOH->close();

// sum overhead on annual basis
$annualOverheadTotal = 0.0;
foreach ($overheads as $oh) {
    $amtAnnual = annualizeOverhead(
        (float)$oh['overhead_amount'],
        (int)$oh['overhead_recurring'],
        $oh['overhead_frequency'] ?? null
    );
    $annualOverheadTotal += $amtAnnual;
}
$totalExpenses += $annualOverheadTotal;

$netProfit = $totalRevenue - $totalExpenses;

/* ------------------------------------------------------------------
   E) PER-PROJECT STATS - overhead is raw amounts
   ------------------------------------------------------------------ */
// 1) Load projects
$projects = [];
$sqlProj = "
    SELECT id, project_name
      FROM projects
     ORDER BY project_name
";
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

$idxMap = [];
foreach ($projects as $i => $pr) {
    $idxMap[$pr['id']] = $i;
}

// 2) total_order
$sqlOrd = "
    SELECT project_id, SUM(total_order) AS sum_order
      FROM project_wattage_orders
     GROUP BY project_id
";
$resOrd = $conn->query($sqlOrd);
while ($o = $resOrd->fetch_assoc()) {
    $pid = (int)$o['project_id'];
    if (isset($idxMap[$pid])) {
        $projects[$idxMap[$pid]]['total_order'] = (float)$o['sum_order'];
    }
}
$resOrd->close();

// 3) delivered
$sqlDel = "
    SELECT project_id, SUM(quantity) AS sum_delivered
      FROM deliveries
     WHERE status_of_delivery='Delivered'
     GROUP BY project_id
";
$resDel = $conn->query($sqlDel);
while ($d = $resDel->fetch_assoc()) {
    $pid = (int)$d['project_id'];
    if (isset($idxMap[$pid])) {
        $projects[$idxMap[$pid]]['delivered_qty'] = (float)$d['sum_delivered'];
    }
}
$resDel->close();

// compute status
foreach ($projects as &$pr) {
    $ord = $pr['total_order'];
    $del = $pr['delivered_qty'];
    if ($ord > 0) {
        $pct = ($del/$ord)*100;
        $pr['delivery_pct'] = $pct;
        if ($pct>=100) {
            $pr['status'] = 'Complete';
        }
    } else {
        $pr['delivery_pct'] = 0;
    }
}
unset($pr);

// 4) sum paid invoices for each project
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

// 5) sum payables
$sqlPay = "
    SELECT project_id, IFNULL(SUM(amount),0) AS sum_expenses
      FROM accounts_payable
     GROUP BY project_id
";
$resPay = $conn->query($sqlPay);
while ($rp = $resPay->fetch_assoc()) {
    $pid = (int)$rp['project_id'];
    if (isset($idxMap[$pid])) {
        $projects[$idxMap[$pid]]['expenses'] += (float)$rp['sum_expenses'];
    }
}
$resPay->close();

// overhead for projects (raw overhead amounts, no annualization)
foreach ($overheads as $oh) {
    $projId = (int)($oh['project_id'] ?? 0);
    if ($projId && isset($idxMap[$projId])) {
        $projects[$idxMap[$projId]]['expenses'] += (float)$oh['overhead_amount'];
    }
}

// compute net profit
$totalNetProfitAllProjects = 0.0;
foreach ($projects as &$pr) {
    $pr['net_profit'] = $pr['revenue'] - $pr['expenses'];
    $totalNetProfitAllProjects += $pr['net_profit'];
}
unset($pr);

$numProjects = count($projects);
$averageNetProfitPerProject = ($numProjects>0)
    ? ($totalNetProfitAllProjects/$numProjects)
    : 0.0;

/* ------------------------------------------------------------------
   F) Overhead Filter (monthly/quarterly/yearly) for overhead summary card
   ------------------------------------------------------------------ */
$overheadView = $_GET['overhead_view'] ?? 'yearly';

$filteredOverheadTotal = 0.0;
foreach ($overheads as $oh) {
    $filteredOverheadTotal += overheadFilterFactor(
        (float)$oh['overhead_amount'],
        (int)$oh['overhead_recurring'],
        $oh['overhead_frequency'] ?? null,
        $overheadView
    );
}
$overheadLabel = '';
switch ($overheadView) {
    case 'monthly':   $overheadLabel='Monthly Overhead';   break;
    case 'quarterly': $overheadLabel='Quarterly Overhead'; break;
    default:          $overheadLabel='Yearly Overhead';    break;
}
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
    .summary-card h3 {
      margin:0; margin-bottom:10px; font-size:1.1em;
    }
    .summary-card p {
      font-size:1.2em; font-weight:bold; margin:0;
    }

    .links-bar {
      display:flex; gap:20px; margin-bottom:20px;
    }
    .links-bar a {
      background:#488C9A; color:#fff; padding:8px 16px;
      border-radius:4px; text-decoration:none;
    }
    .links-bar a:hover {
      background:#293E4C;
    }

    .success-message {
      background:#d4edda; color:#155724;
      padding:10px; border-radius:4px;
      margin-bottom:15px;
    }

    /* Overhead Section */
    .overhead-section {
      background:#f9f9f9; border:1px solid #ccc; border-radius:6px;
      padding:15px; margin-bottom:20px; position: relative;
    }
    .overhead-section h2 {
      margin-top:0; margin-bottom:15px; font-size:1.2em;
    }
    .overhead-section p { margin-bottom:15px; }

    .overhead-form {
      display:flex; flex-wrap:wrap; gap:10px; align-items:center;
      margin-bottom:15px;
    }
    .overhead-form label {
      font-weight:500;
    }
    .overhead-form input[type="number"],
    .overhead-form input[type="text"],
    .overhead-form select {
      padding:6px; border:1px solid #ccc; border-radius:4px; font:inherit;
    }
    .overhead-form .recurring-checkbox {
      display:inline-flex; align-items:center; gap:5px;
      margin-left:10px; 
    }
    .overhead-form button {
      background:#488C9A; color:#fff; border:none; padding:8px 16px; border-radius:4px;
      cursor:pointer; font-weight:500;
    }
    .overhead-form button:hover { background:#33707b; }

    /* Overhead filter form */
    .overhead-filter-form {
      margin-bottom:15px;
    }
    .overhead-filter-form label {
      font-weight:500;
      margin-right:8px;
    }
    .overhead-filter-form select {
      padding:6px; border:1px solid #ccc; border-radius:4px; font:inherit;
      margin-right:8px;
    }

    .overhead-controls { /* New container for filter and summary card */
      float: right;
      display: flex;
      flex-direction: column;
      align-items: center; /* Align items to the right */
      min-width: 200px; /* Ensure it takes some width */
      margin-right: 20px;
      margin-bottom: 20px;
    }

    .overhead-summary-card {
      background:#fff; border:1px solid #ccc; border-radius:4px;
      padding:10px; text-align:center; display:inline-block;
      min-width:180px;
      width: 100%; /* Make card take full width of container */
    }
    .overhead-summary-card h4 {
      margin:0; margin-bottom:5px; font-size:1em; color:#333;
    }
    .overhead-summary-card p {
      margin:0; font-weight:bold; color:#ff6600; /* orangeish */
      font-size:1.1em;
    }

    .overhead-table {
      width:100%; border-collapse:collapse; margin-top:10px;
    }
    .overhead-table th, .overhead-table td {
      border:1px solid #ccc; padding:8px; text-align:left;
    }
    .overhead-table th {
      background:#293E4C;
    }
    .overhead-table tbody tr:hover {
      background:#fafafa;
    }

    /* Extra col for 'Remove' button */
    .overhead-table .actions-cell {
      text-align:center;
      white-space:nowrap;
    }
    .remove-button {
      background:#c9302c; color:#fff; padding:5px 10px; border:none; border-radius:4px;
      cursor:pointer; font-weight:500; 
    }
    .remove-button:hover {
      background:#a82a26;
    }

    .heading-spacer {
      margin-top:40px; margin-bottom:10px;
    }

    /* Project financials table */
    table {
      width:100%; border-collapse:collapse; margin-top:10px;
    }
    table, th, td {
      border:1px solid #ccc; padding:8px;
    }
    th { background:#f2f2f2; text-align:left; }
    .status-complete { color:green; font-weight:bold; }
    .status-active   { color:orange; font-weight:bold; }

    .actions button {
      background:#488C9A; color:#fff; border:none; padding:6px 12px; margin-right:5px;
      border-radius:4px; cursor:pointer; font-size:0.9rem; font-weight:500;
    }
    .actions button:hover {
      background:#33707b;
    }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
  <h1>Accounting Overview</h1>

  <?php if (!empty($successMessage)): ?>
    <div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>
  <?php endif; ?>

  <!-- Quick links -->
  <div class="links-bar">
    <a href="total_payables.php">View Accounts Payable</a>
    <a href="add_invoice.php">View Invoices</a>
  </div>

  <!-- Summary cards (ANNUAL overhead) -->
  <div class="summary-cards">
    <div class="summary-card">
      <h3>Total Revenue</h3>
      <p style="color:green;"><?php echo '$'.number_format($totalRevenue,2);?></p>
    </div>
    <div class="summary-card">
      <h3>Total Expenses</h3>
      <p style="color:red;"><?php echo '$'.number_format($totalExpenses,2);?></p>
    </div>
    <div class="summary-card">
      <h3>Net Profit</h3>
      <p><?php echo '$'.number_format($netProfit,2);?></p>
    </div>
    <div class="summary-card">
      <h3>Avg Net Profit/Project</h3>
      <p><?php echo '$'.number_format($averageNetProfitPerProject,2);?></p>
    </div>
  </div>

  <!-- Overhead Section (with overheadView filter) -->
  <div class="overhead-section">
    <h2>Overhead Costs</h2>

    <!-- New container for filter and summary card -->
    <div class="overhead-controls">
      <!-- Overhead Filter (Monthly/Quarterly/Yearly) -->
      <form class="overhead-filter-form" method="GET">
        <label for="overhead_view">Overhead View:</label>
        <select name="overhead_view" id="overhead_view" onchange="this.form.submit()">
          <option value="monthly"   <?php if($overheadView==='monthly') echo 'selected';?>>Monthly</option>
          <option value="quarterly" <?php if($overheadView==='quarterly') echo 'selected';?>>Quarterly</option>
          <option value="yearly"    <?php if($overheadView==='yearly') echo 'selected';?>>Yearly</option>
        </select>
      </form>

      <div class="overhead-summary-card">
        <h4><?php echo htmlspecialchars($overheadLabel); ?></h4>
        <p><?php echo '$'.number_format($filteredOverheadTotal,2); ?></p>
      </div>
    </div> <!-- End of overhead-controls -->

    <p>
      Overhead items are shown here. <br>
      (Note: the summary cards above always treat recurring overhead as **annual**.)
    </p>

    <!-- Overhead Form to Add -->
    <form method="POST" class="overhead-form">
      <label for="overhead_amount">Amount:</label>
      <input type="number" step="0.01" name="overhead_amount" id="overhead_amount" required>

      <label for="description">Description:</label>
      <input type="text" name="description" id="description" placeholder="e.g. Salary, Rent, etc." required>

      <label for="assigned_project">Project:</label>
      <select name="assigned_project" id="assigned_project">
        <option value="">-- General Overhead --</option>
        <?php 
        // Populate a dropdown of existing projects
        foreach ($projects as $p) {
            echo '<option value="'.(int)$p['id'].'">'.htmlspecialchars($p['project_name']).'</option>';
        }
        ?>
      </select>

      <div class="recurring-checkbox">
        <input type="checkbox" id="overhead_recurring" name="overhead_recurring" value="1">
        <label for="overhead_recurring" style="margin:0;">Recurring?</label>
      </div>

      <label for="overhead_frequency">Frequency:</label>
      <select name="overhead_frequency" id="overhead_frequency">
        <option value="">-- None --</option>
        <option value="Monthly">Monthly</option>
        <option value="Quarterly">Quarterly</option>
        <option value="Yearly">Yearly</option>
        <option value="Other">Other</option>
      </select>

      <button type="submit" name="add_overhead" value="1">Add Overhead</button>
    </form>

    <!-- Overhead Items Table -->
    <?php if (!empty($overheads)): ?>
      <table class="overhead-table">
        <thead>
          <tr>
            <th>Amount</th>
            <th>Description</th>
            <th>Recurring?</th>
            <th>Frequency</th>
            <th>Project</th>
            <th>Created At</th>
            <th style="width:80px;">Remove</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($overheads as $oh): ?>
          <?php 
            $ohId       = (int)$oh['id']; // primary key of overhead line
            $isRecurring= (!empty($oh['overhead_recurring']) && $oh['overhead_recurring']==1) ? 'Yes' : 'No';
            $freq       = (!empty($oh['overhead_frequency']) && $isRecurring==='Yes')
                            ? htmlspecialchars($oh['overhead_frequency'])
                            : 'N/A';

            $projLabel  = 'General Overhead';
            if (!empty($oh['project_id'])) {
                if (isset($idxMap[$oh['project_id']])) {
                    $projLabel = htmlspecialchars($projects[$idxMap[$oh['project_id']]]['project_name']);
                } else {
                    $projLabel = 'Unknown Project ID '.$oh['project_id'];
                }
            }
          ?>
          <tr>
            <td><?php echo '$'.number_format($oh['overhead_amount'], 2); ?></td>
            <td><?php echo htmlspecialchars($oh['description']); ?></td>
            <td><?php echo $isRecurring; ?></td>
            <td><?php echo $freq; ?></td>
            <td><?php echo $projLabel; ?></td>
            <td><?php echo htmlspecialchars($oh['created_at']); ?></td>
            <td class="actions-cell">
              <!-- Remove Overhead Button -->
              <form method="GET" onsubmit="return confirm('Are you sure you want to remove this overhead line?');">
                <!-- Keep overhead_view so we don't lose the current filter -->
                <input type="hidden" name="overhead_view" value="<?php echo htmlspecialchars($overheadView); ?>">
                <input type="hidden" name="action" value="delete_overhead">
                <input type="hidden" name="overhead_id" value="<?php echo $ohId; ?>">
                <button type="submit" class="remove-button">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p>No overhead costs recorded yet.</p>
    <?php endif; ?>

  </div>

  <!-- Project Financials heading -->
  <h2 class="heading-spacer">Project Financials</h2>

  <!-- Per-Project Table (overhead amounts are raw, not annualized) -->
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
          $statusCss = ($prj['status'] === 'Complete') ? 'status-complete' : 'status-active';
          $pct       = (float)$prj['delivery_pct'];
          $rev       = (float)$prj['revenue'];
          $exp       = (float)$prj['expenses'];
          $net       = (float)$prj['net_profit'];
        ?>
        <tr>
          <td><?php echo htmlspecialchars($prj['project_name']);?></td>
          <td class="<?php echo $statusCss;?>"><?php echo $prj['status'];?></td>
          <td><?php echo number_format($pct,2);?>%</td>
          <td><?php echo '$'.number_format($rev,2);?></td>
          <td><?php echo '$'.number_format($exp,2);?></td>
          <td><?php echo '$'.number_format($net,2);?></td>
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
