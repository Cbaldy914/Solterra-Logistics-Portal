<?php
session_name("logistics_session");
session_start();

// Check if user is logged in & is global_admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
    header("Location: ../login");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Feedback messages
$success_message = '';
$error_message   = '';

// (A) Handle "delete" action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
    $payable_id = (int)$_GET['id'];

    // 1) Fetch to see if there's a receipt file
    $stmtSel = $conn->prepare("SELECT receipt_file FROM accounts_payable WHERE id = ?");
    $stmtSel->bind_param("i", $payable_id);
    $stmtSel->execute();
    $stmtSel->bind_result($receipt_file);
    if ($stmtSel->fetch() && $receipt_file) {
        $stmtSel->close();

        // 2) Delete the record
        $stmtDel = $conn->prepare("DELETE FROM accounts_payable WHERE id=?");
        $stmtDel->bind_param("i", $payable_id);
        if ($stmtDel->execute()) {
            // Remove physical file if it exists
            if (file_exists($receipt_file)) {
                @unlink($receipt_file);
            }
            $success_message = "Vendor Payment record deleted.";
        } else {
            $error_message = "Error deleting record: " . $stmtDel->error;
        }
        $stmtDel->close();
    } else {
        $error_message = "Payable record not found or no receipt file.";
        $stmtSel->close();
    }
}

// (B) We no longer handle "Add Payable" here. The user goes to `accounts_payable.php` instead.

// (C) Fetch all payables to display
//     Join with projects to get project_name
$sql = "
  SELECT ap.*,
         p.project_name
    FROM accounts_payable ap
    LEFT JOIN projects p ON ap.project_id = p.id
  ORDER BY ap.paid_date DESC
";
$res = $conn->query($sql);

$payables = [];
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $payables[] = $row;
    }
}

// We'll close after we gather the data
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Total Payables</title>
  <link rel="stylesheet" href="portal.css">
  <link rel="icon" href="pictures/favicon.png">
  <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Poppins',sans-serif; }
    .success-message, .error-message {
      text-align:center; margin-top:15px;
    }
    .success-message { color:green; }
    .error-message { color:red; }
    .filter-bar {
      margin:20px 0; text-align:right;
    }
    .filter-bar input[type="text"] {
      width:200px; padding:8px; border-radius:4px; border:1px solid #ccc; font:inherit;
    }
    table {
      width:100%; border-collapse:collapse; margin-top:20px;
    }
    table,th,td {
      border:1px solid #ccc; padding:8px;
    }
    th{ background:#f2f2f2; text-align:left; }
    .actions a {
      margin-right:8px; text-decoration:none; color:#488C9A; font-weight:500;
    }
    .actions a:hover {
      text-decoration:underline;
    }
    #record-payment-button {
      display:inline-block; background:#488C9A; color:#fff;
      padding:10px 20px; margin:20px 0; border:none; border-radius:4px;
      font-size:1em; cursor:pointer; font-weight:bold; text-decoration:none;
    }
    #record-payment-button:hover { background-color:#293E4C; }
    @media(max-width:768px){
      .filter-bar { text-align:center; }
    }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<main style="margin:20px;">
  <h1>Total Payables</h1>

  <?php if ($error_message): ?>
    <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
  <?php endif; ?>
  <?php if ($success_message): ?>
    <p class="success-message"><?php echo htmlspecialchars($success_message); ?></p>
  <?php endif; ?>

  <!-- (1) Button to go to accounts_payable.php -->
  <a href="accounts_payable.php" id="record-payment-button">Record New Vendor Payment</a>

  <!-- (2) Filter bar (basic text search) -->
  <div class="filter-bar">
    <input type="text" id="searchInput" placeholder="Search..." onkeyup="searchTable()">
  </div>

  <!-- (3) Table of payables -->
  <table id="payablesTable">
    <thead>
      <tr>
        <th>Vendor Name</th>
        <th>Project Name</th>
        <th>Paid Date</th>
        <th>Amount</th>
        <th>Notes</th>
        <th>Receipt</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if(!empty($payables)): ?>
        <?php foreach($payables as $ap): ?>
          <tr>
            <td><?php echo htmlspecialchars($ap['vendor_name']); ?></td>
            <td><?php echo htmlspecialchars($ap['project_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($ap['paid_date']); ?></td>
            <td>$<?php echo number_format($ap['amount'],2); ?></td>
            <td><?php echo nl2br(htmlspecialchars($ap['notes'])); ?></td>
            <td>
              <?php if(!empty($ap['receipt_file'])): ?>
                <a href="<?php echo htmlspecialchars($ap['receipt_file']); ?>" target="_blank">View Receipt</a>
              <?php else: ?>
                No Receipt
              <?php endif; ?>
            </td>
            <td class="actions">
              <!-- Link to your edit page (not implemented, just placeholder) -->
              <a href="edit_payable.php?id=<?php echo $ap['id']; ?>">Edit</a>
              |
              <a href="total_payables.php?action=delete&id=<?php echo $ap['id']; ?>"
                 onclick="return confirm('Are you sure you want to delete this payment?');">
                 Delete
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="7">No payables found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</main>

<script>
// Simple text search
function searchTable(){
  var input = document.getElementById("searchInput");
  var filter= input.value.toLowerCase();
  var table = document.getElementById("payablesTable");
  var tr    = table.getElementsByTagName("tr");
  for(var i=1; i<tr.length; i++){
    var rowText = tr[i].textContent.toLowerCase();
    tr[i].style.display = (rowText.indexOf(filter)>-1) ? "" : "none";
  }
}
</script>
</body>
</html>
