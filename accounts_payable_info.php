<?php
session_name("logistics_session");
session_start();

// (A) Security: user must be global_admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
        header("Location: unauthorized");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Gather POST data
$project_id        = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$selected_ids_json = $_POST['selected_ids']      ?? '[]';
$selectedRows      = json_decode($selected_ids_json, true);
if (!is_array($selectedRows)) {
    $selectedRows = [];
}

// We'll fetch deliveries to display them and compute total cost
$deliveries = [];
$totalAmount = 0.0;

// Success message after saving
$successMessage = "";

/* ───────────────────────────────────────────────────────────
   1) Handle "Save Payment" when user submits the form
   ─────────────────────────────────────────────────────────── */
if (isset($_POST['save_payment'])) {

    // 1A) Grab the posted vendor name, paid date, notes, etc.
    $vendorName = trim($_POST['vendor_name'] ?? '');
    // If user doesn't choose a date, default to "today"
    $paidDate   = $_POST['paid_date'] ?? date('Y-m-d');
    $notes      = trim($_POST['notes'] ?? '');

    // 1B) If user uploaded a receipt file
    $receiptPath = '';
    if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/receipts/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $fileName = $_FILES['receipt_file']['name'];
        $fileTmp  = $_FILES['receipt_file']['tmp_name'];
        $finalName= time() . '_' . preg_replace('/\s+/', '_', $fileName);
        $receiptPath = $uploadDir . $finalName;
        if (!move_uploaded_file($fileTmp, $receiptPath)) {
            // If it fails, handle accordingly
            $receiptPath = '';
        }
    }

    // 1C) Summation logic: we already computed $totalAmount below, but let's confirm we have it
    // Actually we compute after we fetch deliveries (next block).
    // So let's do the insertion after we fetch the deliveries (like an approach).
    // We'll do it in the bottom, once we have $totalAmount.

    // We'll set a flag that we want to "SAVE" after we gather $deliveries
    $doInsertAccountsPayable = true;
}

/* ───────────────────────────────────────────────────────────
   2) Attempt to get project name
   ─────────────────────────────────────────────────────────── */
$project_name = "";
if ($project_id > 0) {
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id=?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($project_name);
    $stmt->fetch();
    $stmt->close();
}

/* ───────────────────────────────────────────────────────────
   3) Fetch the selected deliveries for display
   ─────────────────────────────────────────────────────────── */
if (!empty($selectedRows)) {
    $ph = rtrim(str_repeat('?,', count($selectedRows)), ',');
    $pt = str_repeat('i', count($selectedRows));
    $sql = "SELECT * FROM deliveries WHERE id IN ($ph)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($pt, ...$selectedRows);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($d = $res->fetch_assoc()) {
        $deliveries[] = $d;
    }
    $stmt->close();
}

// 3A) Now compute total cost for these deliveries => sum of freight_cost + accessorial_costs_paid
foreach ($deliveries as $dlv) {
    $freight = (float)($dlv['freight_cost']           ?? 0.0);
    $accPaid = (float)($dlv['accessorial_costs_paid'] ?? 0.0);
    $totalAmount += ($freight + $accPaid);
}

/* ───────────────────────────────────────────────────────────
   4) If user clicked "Save Payment," we now have the $totalAmount
      so let's create a row in accounts_payable, then update deliveries.
   ─────────────────────────────────────────────────────────── */
if (!empty($doInsertAccountsPayable)) {
    // Re-use the posted vendor_name, paid_date, notes, receiptPath, totalAmount
    // that we captured above
    $vendorName = trim($_POST['vendor_name'] ?? '');
    $paidDate   = $_POST['paid_date'] ?? date('Y-m-d');
    $notes      = trim($_POST['notes'] ?? '');
    $receiptPath= $receiptPath ?? ''; // from block above

    // Insert into accounts_payable
    $stmtAP = $conn->prepare("
      INSERT INTO accounts_payable
        (vendor_name, project_id, paid_date, amount, notes, receipt_file)
      VALUES (?,?,?,?,?,?)
    ");
    $stmtAP->bind_param("sisdss",
        $vendorName,
        $project_id,
        $paidDate,
        $totalAmount,
        $notes,
        $receiptPath
    );
    if ($stmtAP->execute()) {
        $apId = $stmtAP->insert_id;

        // Now update each delivery: pay_status='Paid', pay_date=NOW()
        // accounts_payable_id=$apId
        // Also handle "Charge Customer?"
        $sqlUp = "
          UPDATE deliveries
             SET pay_status='Paid',
                 pay_date=NOW(),
                 accounts_payable_id=?,
                 accessorial_costs=?
           WHERE id=?
           LIMIT 1
        ";
        $stmtU = $conn->prepare($sqlUp);

        // We need each row's accessorial_costs_paid
        $sel2 = "SELECT id, accessorial_costs_paid FROM deliveries WHERE id=?";
        $stmtS2= $conn->prepare($sel2);

        foreach ($deliveries as $dlv) {
            $did  = (int)$dlv['id'];
            $stmtS2->bind_param("i", $did);
            $stmtS2->execute();
            $r2 = $stmtS2->get_result();
            $row2= $r2->fetch_assoc();
            $accPaid = (float)($row2['accessorial_costs_paid'] ?? 0.0);

            // If user checked "charge_customer[did]", set accessorial_costs=accPaid else 0
            $charged = 0.0;
            if (isset($_POST['charge_customer'][$did])) {
                $charged = $accPaid;
            }

            $stmtU->bind_param("idi", $apId, $charged, $did);
            $stmtU->execute();
        }
        $stmtS2->close();
        $stmtU->close();

        $successMessage = "Payment saved! A/P Record #$apId created for $$totalAmount. Vendor: $vendorName";
    } else {
        $successMessage = "Error creating accounts_payable row: " . $stmtAP->error;
    }
    $stmtAP->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payable Info - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<div style="margin:40px;">
    <h1>Carrier Payment Info - <?php echo htmlspecialchars($project_name); ?></h1>

    <?php if (!empty($successMessage)): ?>
        <p style="background:#d4edda; padding:15px; color:#155724;">
          <?php echo htmlspecialchars($successMessage); ?>
        </p>
    <?php endif; ?>

    <?php
      $countSel = count($deliveries);
      echo "<p>You have selected <strong>$countSel</strong> deliveries.</p>";
      $totalFormatted = '$' . number_format($totalAmount, 2);
      echo "<p>The total payable amount for these deliveries is <strong>{$totalFormatted}</strong>.</p>";
    ?>

    <!-- Payment Form -->
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="project_id" value="<?php echo (int)$project_id; ?>">
      <input type="hidden" name="selected_ids" value='<?php echo htmlspecialchars($selected_ids_json, ENT_QUOTES); ?>'>

      <div style="margin-bottom:20px;">
        <label for="vendor_name"><strong>Vendor Name:</strong></label><br>
        <input type="text" id="vendor_name" name="vendor_name" style="width:400px;" required>
      </div>

      <div style="margin-bottom:20px;">
        <label for="paid_date"><strong>Paid Date:</strong></label><br>
        <input type="date" id="paid_date" name="paid_date" value="<?php echo date('Y-m-d'); ?>" required>
      </div>

      <div style="margin-bottom:20px;">
        <label for="notes"><strong>Notes (optional):</strong></label><br>
        <textarea id="notes" name="notes" rows="3" style="width: 400px;"></textarea>
      </div>

      <div style="margin-bottom:20px;">
        <label for="receipt_file"><strong>Upload Receipt (optional):</strong></label><br>
        <input type="file" id="receipt_file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png">
      </div>

      <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="8" cellspacing="0">
        <thead>
          <tr>
            <th>Delivery&nbsp;ID</th>
            <th>BOL&nbsp;#</th>
            <th>Freight</th>
            <th>Accessorial (Paid to Carrier)</th>
            <th>Charge Customer?</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($deliveries as $d): ?>
          <?php
            $did   = (int)$d['id'];
            $bol   = htmlspecialchars($d['bol_number'] ?? '');
            $frVal = (float)$d['freight_cost'];
            $apVal = (float)$d['accessorial_costs_paid'];
            $acVal = (float)$d['accessorial_costs'];
            // If $acVal>0, presumably "Charge?" was set previously
            $checked = ($acVal>0) ? 'checked' : '';
          ?>
          <tr>
            <td><?php echo $did; ?></td>
            <td><?php echo $bol; ?></td>
            <td>$<?php echo number_format($frVal,2); ?></td>
            <td>$<?php echo number_format($apVal,2); ?></td>
            <td>
              <!-- If user checks this, we set deliveries.accessorial_costs = accessorial_costs_paid -->
              <label style="display:inline-flex;align-items:center;gap:6px;">
                <input type="checkbox" name="charge_customer[<?php echo $did;?>]" <?php echo $checked;?>>
                Charge?
              </label>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <button type="submit" name="save_payment" value="1" style="margin-top:20px;">
        Save Payment
      </button>
    </form>

    <p style="margin-top:20px;">
      <a href="accounts_payable.php?project_id=<?php echo $project_id; ?>">&larr; Back to Payable List</a>
    </p>
</div>
</body>
</html>
