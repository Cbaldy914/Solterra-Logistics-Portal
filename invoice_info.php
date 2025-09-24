<?php
session_name("logistics_session");
session_start();

// 1) Check login / role
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login");
    exit();
}
if ($_SESSION['role'] !== 'global_admin') {
    die("Access denied. You must be a global admin to view this page.");
}

require __DIR__ . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// OPTIONAL constant: cost per canceled truck
define('CANCELED_TRUCK_COST', 250.0);

$errorMessage   = "";
$successMessage = "";

// Gather POST data
$project_id        = isset($_POST['project_id'])        ? (int)$_POST['project_id']        : 0;
$selected_ids_json = isset($_POST['selected_ids'])       ? $_POST['selected_ids']           : '';
$selectedRows      = json_decode($selected_ids_json, true);
if (!is_array($selectedRows)) {
    $selectedRows = [];
}

// Fetch project data
$project_name = "";
$solterra_fee = 0.0;
if ($project_id > 0) {
    $stmt = $conn->prepare("SELECT project_name, solterra_fee FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->bind_result($project_name, $solterra_fee);
    $stmt->fetch();
    $stmt->close();
}

// Fetch the selected deliveries
$allDeliveries = [];
if (!empty($selectedRows)) {
    $ph = rtrim(str_repeat('?,', count($selectedRows)), ',');
    $pt = str_repeat('i', count($selectedRows));
    $sql = "SELECT * FROM deliveries WHERE id IN ($ph)";
    $stmtDel = $conn->prepare($sql);
    $stmtDel->bind_param($pt, ...$selectedRows);
    $stmtDel->execute();
    $resDel = $stmtDel->get_result();
    while ($row = $resDel->fetch_assoc()) {
        $allDeliveries[] = $row;
    }
    $stmtDel->close();
}

// Separate normal vs canceled
$normalDeliveries   = [];
$canceledDeliveries = [];
foreach ($allDeliveries as $d) {
    $status = trim($d['status_of_delivery'] ?? '');
    if (strcasecmp($status, 'canceled') === 0) {
        $canceledDeliveries[] = $d;
    } else {
        $normalDeliveries[] = $d;
    }
}

// Summaries for top line in invoice_info.php page:
$countAll    = count($allDeliveries);
$countCancel = count($canceledDeliveries);
$countNormal = count($normalDeliveries);

// Distinct BOL & MW only for normal deliveries
$distinctBOLs = [];
$totalWatts   = 0.0;
foreach ($normalDeliveries as $d) {
    $bol = trim($d['bol_number'] ?? '');
    if ($bol !== '' && !in_array($bol, $distinctBOLs, true)) {
        $distinctBOLs[] = $bol;
    }
    $w = floatval($d['wattage'] ?? 0);
    $q = floatval($d['quantity'] ?? 0);
    $totalWatts += ($w * $q);
}
$truckloads = count($distinctBOLs);
$totalMW    = $totalWatts / 1_000_000;

// Editable fields
$invoice_number      = trim($_POST['invoice_number']       ?? '');
$invoice_issued_date = trim($_POST['invoice_issued_date']  ?? '');
$invoice_due_date    = trim($_POST['invoice_due_date']     ?? '');
$bill_to             = trim($_POST['bill_to']              ?? "DESRI\n575 Fifth Avenue, 24th Floor\nNew York, NY 10017");
$sow_text            = trim($_POST['sow_text']             ?? "Statement of Work, dated August 14, 2024");
$msa_text            = trim($_POST['msa_text']             ?? "Professional Service Agreement, dated August 7, 2024");
$deposit_credit      = isset($_POST['deposit_credit'])      ? floatval($_POST['deposit_credit']) : 0.0;
$notes               = trim($_POST['notes']                ?? '');

$invoiceId = null;

/* =============================================
   (A) SAVE INVOICE (DB insert)
   ============================================= */
if (isset($_POST['save_invoice'])) {
    if (empty($selectedRows) || empty($invoice_number)) {
        $errorMessage = "Please provide an invoice number and select at least one delivery.";
    } else {
        // Optional file upload
        $upload_dir = 'uploads/invoices/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
            $invoice_name = basename($_FILES['invoice_file']['name']);
            $invoice_path = $upload_dir . time() . '_' . $invoice_name;
            if (!move_uploaded_file($_FILES['invoice_file']['tmp_name'], $invoice_path)) {
                $errorMessage = "Failed to upload the invoice file.";
            }
        } else {
            $errorMessage = "No file uploaded or there was an upload error.";
        }

        if (empty($errorMessage)) {
            $invoice_file = $invoice_path;
            // Default date => today
            $issued_date = (!empty($invoice_issued_date)) ? $invoice_issued_date : date('Y-m-d');
            $due_date    = $invoice_due_date;
            $status      = 'Open';

            // 1) Calculate from normal deliveries
            $totalFreight     = 0.0;
            $totalAccessorial = 0.0;
            $totalSolterraFee = 0.0;
            foreach ($normalDeliveries as $d) {
                $f  = floatval($d['freight_cost'] ?? 0);
                $a  = floatval($d['accessorial_costs'] ?? 0);
                $q  = floatval($d['quantity'] ?? 0);
                $w  = floatval($d['wattage']  ?? 0);
                $sf = $solterra_fee * ($w * $q);

                $totalFreight     += $f;
                $totalAccessorial += $a;
                $totalSolterraFee += $sf;
            }

            // 2) Also add the cancellations cost (CANCELED_TRUCK_COST * # canceled truckloads)
            // We'll count distinct BOL for cancellations
            $distinctCanceledBOLs = [];
            foreach ($canceledDeliveries as $cd) {
                $bolC = trim($cd['bol_number'] ?? '');
                if ($bolC !== '' && !in_array($bolC, $distinctCanceledBOLs, true)) {
                    $distinctCanceledBOLs[] = $bolC;
                }
            }
            $numCanceledTrucks = count($distinctCanceledBOLs);
            $canceledCostTotal = CANCELED_TRUCK_COST * $numCanceledTrucks;

            $combined    = $totalFreight + $totalAccessorial + $totalSolterraFee + $canceledCostTotal;
            $finalAmount = $combined - $deposit_credit;
            if ($finalAmount < 0) $finalAmount = 0.0;

            // Insert into project_invoices
            $sqlPi = "
              INSERT INTO project_invoices
                (project_id, invoice_number, amount, status, issued_date, due_date,
                 deposit_credit, notes, invoice_file, uploaded_at, bill_to, sow_text, msa_text)
              VALUES
                (?,?,?,?,?,?,?,?,?,NOW(),?,?,?)
            ";
            $stmtPi = $conn->prepare($sqlPi);
            $stmtPi->bind_param(
                "isdsssdsssss",
                $project_id,
                $invoice_number,
                $finalAmount,
                $status,
                $issued_date,
                $due_date,
                $deposit_credit,
                $notes,
                $invoice_file,
                $bill_to,
                $sow_text,
                $msa_text
            );
            if ($stmtPi->execute()) {
                $invoiceId = $stmtPi->insert_id;
            } else {
                $errorMessage = "Error saving invoice: " . $stmtPi->error;
            }
            $stmtPi->close();

            if ($invoiceId && empty($errorMessage)) {
                // Update all selected deliveries
                $ph2 = rtrim(str_repeat('?,', count($selectedRows)), ',');
                $pt2 = str_repeat('i', count($selectedRows));
                $sqlUp = "UPDATE deliveries SET invoice_id = ?, invoice_number=? WHERE id IN ($ph2)";
                $bindTypes = 'is' . $pt2;
                $allParams = array_merge([$invoiceId, $invoice_number], $selectedRows);

                $stmtUp = $conn->prepare($sqlUp);
                $stmtUp->bind_param($bindTypes, ...$allParams);
                if ($stmtUp->execute()) {
                    $successMessage = "Invoice #{$invoice_number} has been saved (ID: $invoiceId).";
                } else {
                    $errorMessage = "Error updating deliveries: " . $stmtUp->error;
                }
                $stmtUp->close();
            }
        }
    }
}

/* =============================================
   (B) GENERATE PDF (no DB insert)
   ============================================= */
if (isset($_POST['generate_pdf'])) {

    // Format dates
    $displayIssuedDate = (!empty($invoice_issued_date))
        ? date('m-d-Y', strtotime($invoice_issued_date))
        : 'N/A';
    $displayDueDate = (!empty($invoice_due_date))
        ? date('m-d-Y', strtotime($invoice_due_date))
        : 'N/A';

    // ----- Normal deliveries -----
    $groupedByBOL_normal  = [];
    $uniqueBOLs_normal    = [];
    $totalFreight_normal  = 0.0;
    $totalAccess_normal   = 0.0;
    $totalSolterra_normal = 0.0;

    foreach ($normalDeliveries as $d) {
        $bol     = $d['bol_number'] ?? '';
        $freight = floatval($d['freight_cost'] ?? 0);
        $access  = floatval($d['accessorial_costs'] ?? 0);
        $q       = floatval($d['quantity'] ?? 0);
        $w       = floatval($d['wattage']  ?? 0);
        $sf      = $solterra_fee * ($w * $q);

        $totalFreight_normal  += $freight;
        $totalAccess_normal   += $access;
        $totalSolterra_normal += $sf;

        if ($bol !== '' && !in_array($bol, $uniqueBOLs_normal, true)) {
            $uniqueBOLs_normal[] = $bol;
        }
        if (!isset($groupedByBOL_normal[$bol])) {
            $groupedByBOL_normal[$bol] = [
                'bol'         => $bol,
                'quantity'    => 0,
                'freight'     => 0.0,
                'accessorial' => 0.0,
            ];
        }
        $groupedByBOL_normal[$bol]['quantity']    += $q;
        $groupedByBOL_normal[$bol]['freight']     += $freight;
        $groupedByBOL_normal[$bol]['accessorial'] += $access;
    }
    $numTruckloads_normal = count($uniqueBOLs_normal);
    $freightPlusAcc_normal = $totalFreight_normal + $totalAccess_normal;
    $ratePerTruck_normal   = ($numTruckloads_normal > 0)
                                ? ($freightPlusAcc_normal / $numTruckloads_normal)
                                : 0.0;

    // ----- Canceled deliveries -----
    $canceledBOLs = [];
    $groupedCanceled = [];
    foreach ($canceledDeliveries as $cd) {
        $bolC = trim($cd['bol_number'] ?? '');
        if ($bolC !== '' && !in_array($bolC, $canceledBOLs, true)) {
            $canceledBOLs[] = $bolC;
        }
        // We'll store a fixed $250 cost per canceled load
        // plus we might track the quantity if needed
        $qC = floatval($cd['quantity'] ?? 0);

        if (!isset($groupedCanceled[$bolC])) {
            $groupedCanceled[$bolC] = [
                'quantity' => 0, // sum of canceled quantities
            ];
        }
        $groupedCanceled[$bolC]['quantity'] += $qC;
    }
    $numTruckloads_canceled = count($canceledBOLs);
    $canceledTotalCost = $numTruckloads_canceled * CANCELED_TRUCK_COST;

    // Build top summary table rows
    // 1) Normal deliveries row
    $normalSummaryRow = "
    <tr>
      <td>Deliveries - {$project_name} Inland Freight</td>
      <td>{$numTruckloads_normal}</td>
      <td>" . number_format($ratePerTruck_normal, 2) . "</td>
      <td>" . number_format($freightPlusAcc_normal, 2) . "</td>
    </tr>";

    // 2) Cancellations row (if any)
    $canceledSummaryRow = "";
    if (!empty($canceledDeliveries)) {
        $canceledSummaryRow = "
        <tr>
          <td>Cancellations - {$project_name}</td>
          <td>{$numTruckloads_canceled}</td>
          <td>" . number_format(CANCELED_TRUCK_COST, 2) . "</td>
          <td>" . number_format($canceledTotalCost, 2) . "</td>
        </tr>";
    }

    // 3) Solterra fee row
    $solterraFeeRow = "
    <tr>
      <td>Solterra Solution's Base Fee</td>
      <td>--</td>
      <td>" . number_format($solterra_fee, 4) . "/w</td>
      <td>" . number_format($totalSolterra_normal, 2) . "</td>
    </tr>";

    // 4) Deposit row
    $depositRow = "
    <tr>
      <td>Pro-Rated Credit for Deposit</td>
      <td colspan='2'></td>
      <td>(\$" . number_format($deposit_credit, 2) . ")</td>
    </tr>";

    // 5) final amounts
    $subTotal   = $freightPlusAcc_normal + $totalSolterra_normal + $canceledTotalCost;
    $grandTotal = $subTotal - $deposit_credit;
    if ($grandTotal < 0) $grandTotal = 0.0;

    // ----- Itemized normal deliveries
    $itemizedRows_normal = "";
    foreach ($groupedByBOL_normal as $bolX => $info) {
        $desc = "Deliveries - {$project_name} Inland Freight (BOL: {$bolX})";
        $qty  = (int)($info['quantity']);
        $fVal = number_format($info['freight'], 2);
        $aVal = number_format($info['accessorial'], 2);
        $itemizedRows_normal .= "
        <tr>
          <td>{$desc}</td>
          <td>{$qty}</td>
          <td>\${$fVal}</td>
          <td>\${$aVal}</td>
        </tr>";
    }

    // ----- Itemized canceled deliveries
    $itemizedRows_canceled = "";
    if (!empty($canceledDeliveries)) {
        foreach ($groupedCanceled as $bolC => $cdData) {
            $descC = "Canceled Load (BOL: {$bolC})";
            $qtyC  = (int)($cdData['quantity']);
            // We show a fixed $250 under accessorial
            // (One charge per canceled BOL, so total is $250 each)
            $itemizedRows_canceled .= "
            <tr>
              <td>{$descC}</td>
              <td>{$qtyC}</td>
              <td>--</td>
              <td>\$" . number_format(CANCELED_TRUCK_COST, 2) . "</td>
            </tr>";
        }
    }

    // Construct HTML
    $html = "
    <html>
    <head>
      <meta charset='UTF-8'>
      <style>
        body {
          font-family: Arial, sans-serif;
          font-size: 12px;
          margin: 20px;
        }
        .table-services {
          width: 100%;
          border-collapse: collapse;
          margin-top: 15px;
        }
        .table-services th, .table-services td {
          border: 1px solid #ccc;
          padding: 8px;
        }
        .bold { font-weight: bold; }
        .mt-20 { margin-top: 20px; }
      </style>
    </head>
    <body>

    <div style='text-align: center; margin-bottom: 20px;'>
      <img src='https://www.solterrasol.com/Solterra-Logistics-Portal/pictures/header_logo.png'
           alt='Logo' style='max-height:60px;'>
    </div>

    <h3>Solterra Solutions</h3>
    <p>
      8801 Fast Park Drive<br>
      Raleigh, NC 27615<br>
      (401) 749-2800<br>
      info@solterrasol.com<br>
      www.solterrasol.com
    </p>

    <hr>

    <p><strong>Invoice #:</strong> " . htmlspecialchars($invoice_number) . "<br>
    <strong>Date:</strong> {$displayIssuedDate}<br>
    <strong>Due Date:</strong> {$displayDueDate}</p>

    <p><strong>Bill To:</strong><br>" . nl2br(htmlspecialchars($bill_to)) . "</p>

    <hr>

    <h4>Description of Services</h4>
    <p><strong>Project Reference:</strong><br>
       SOW: " . htmlspecialchars($sow_text) . "<br>
       MSA: " . htmlspecialchars($msa_text) . "</p>

    <table class='table-services'>
      <thead>
        <tr>
          <th>Description</th>
          <th>Quantity</th>
          <th>Rate</th>
          <th>Amount</th>
        </tr>
      </thead>
      <tbody>
        {$normalSummaryRow}
        {$canceledSummaryRow}
        {$solterraFeeRow}
        {$depositRow}
      </tbody>
    </table>

    <p class='bold'>
      Subtotal: \$" . number_format($subTotal, 2) . "<br>
      Tax (if applicable): Exempt Certificate Provided
    </p>
    <h3>Total: \$" . number_format($grandTotal, 2) . "</h3>

    <p class='mt-20'>
      Payment is due on {$displayDueDate}<br>
      Please make payments to: Solterra Solutions, LLC<br>
      Routing Number: 083000137<br>
      Account Number: 605665101
    </p>
    <p>
      For any questions regarding this invoice, contact us at
      <a href='mailto:info@solterrasol.com'>info@solterrasol.com</a>.
    </p>

    <pagebreak></pagebreak>

    <h3>Itemized Deliveries</h3>
    <table class='table-services'>
      <thead>
        <tr>
          <th>Description</th>
          <th>Quantity</th>
          <th>Freight</th>
          <th>Accessorial</th>
        </tr>
      </thead>
      <tbody>
        {$itemizedRows_normal}
      </tbody>
    </table>
    ";

    if (!empty($canceledDeliveries)) {
        $html .= "
        <h3 class='mt-20'>Cancellations</h3>
        <table class='table-services'>
          <thead>
            <tr>
              <th>Description</th>
              <th>Quantity</th>
              <th>Freight</th>
              <th>Accessorial</th>
            </tr>
          </thead>
          <tbody>
            {$itemizedRows_canceled}
          </tbody>
        </table>";
    }

    $html .= "</body></html>";

    // DOMPDF
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();

    $filename = "Invoice_" . ($invoice_number ?: 'NoNumber') . ".pdf";
    // open inline
    $dompdf->stream($filename, ["Attachment" => false]);
    exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Invoice Info - <?php echo htmlspecialchars($project_name); ?></title>
  <link rel="stylesheet" href="portal.css">
  <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    .container {
      margin: 40px;
    }
    .success-message {
      background: #d4edda;
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 6px;
      color: #155724;
    }
    .error-message {
      background: #f8d7da;
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 6px;
      color: #721c24;
    }
    form {
      display: flex;
      flex-direction: column;
      gap: 15px;
      max-width: 600px;
    }
    label {
      font-weight: 500;
    }
    input[type="text"],
    input[type="number"],
    input[type="date"],
    textarea {
      padding: 6px;
      border-radius: 4px;
      border: 1px solid #ccc;
      font: inherit;
      width: 100%;
    }
    button {
      padding: 8px 16px;
      border: none;
      background: #488C9A;
      color: #fff;
      border-radius: 4px;
      cursor: pointer;
      font-size: 1rem;
    }
    button:hover {
      background: #33707b;
    }
    .back-link {
      margin-top: 10px;
      display: inline-block;
    }
    .file-input {
      margin-top:10px;
    }
  </style>

  <script>
    function removeRequiredFile() {
      const invFile = document.getElementById('invoice_file');
      if (invFile) {
        invFile.removeAttribute('required');
      }
    }
  </script>
</head>
<body>
<?php include 'header.php'; ?>

<div class="container">
  <!-- error / success messages -->
  <?php if (!empty($errorMessage)): ?>
    <div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
  <?php endif; ?>
  <?php if (!empty($successMessage)): ?>
    <div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>
  <?php endif; ?>

  <h1>Invoice Info for <?php echo htmlspecialchars($project_name); ?></h1>
  <?php
    $countAll    = count($allDeliveries);
    $countCancel = count($canceledDeliveries);
    $countNormal = count($normalDeliveries);
  ?>
  <p>
    You have selected <strong><?php echo $countAll; ?></strong> total deliveries,
    of which <strong><?php echo $countCancel; ?></strong> are cancellations,
    so <strong><?php echo $countNormal; ?></strong> normal deliveries,
    <strong><?php echo $truckloads; ?></strong> truckloads,
    and <strong><?php echo number_format($totalMW, 3); ?></strong> MW.
  </p>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
    <input type="hidden" name="selected_ids" value='<?php echo htmlspecialchars($selected_ids_json, ENT_QUOTES); ?>'>

    <label for="invoice_number">Invoice Number:</label>
    <input type="text" id="invoice_number" name="invoice_number"
           placeholder="e.g. DESRI_0009" value="<?php echo htmlspecialchars($invoice_number); ?>" required>

    <label for="invoice_issued_date">Issued Date:</label>
    <input type="date" id="invoice_issued_date" name="invoice_issued_date"
           value="<?php echo htmlspecialchars($invoice_issued_date); ?>">

    <label for="invoice_due_date">Due Date:</label>
    <input type="date" id="invoice_due_date" name="invoice_due_date"
           value="<?php echo htmlspecialchars($invoice_due_date); ?>">

    <label for="bill_to">Bill To:</label>
    <textarea id="bill_to" name="bill_to" rows="3"><?php echo htmlspecialchars($bill_to); ?></textarea>

    <label for="sow_text">SOW Reference:</label>
    <textarea id="sow_text" name="sow_text" rows="2"><?php echo htmlspecialchars($sow_text); ?></textarea>

    <label for="msa_text">MSA Reference:</label>
    <textarea id="msa_text" name="msa_text" rows="2"><?php echo htmlspecialchars($msa_text); ?></textarea>

    <label for="deposit_credit">Pro-Rated Deposit Credit:</label>
    <input type="number" step="0.01" id="deposit_credit" name="deposit_credit"
           value="<?php echo htmlspecialchars($deposit_credit); ?>">

    <label for="notes">Additional Notes:</label>
    <textarea id="notes" name="notes" rows="4"><?php echo htmlspecialchars($notes); ?></textarea>

    <!-- file required only for saving -->
    <div class="file-input">
      <label for="invoice_file">Attach Invoice File (for Save):</label>
      <input type="file" name="invoice_file" id="invoice_file" accept=".pdf,.doc,.docx,.xls,.xlsx" required>
    </div>

    <div style="display:flex; gap:10px; margin-top:15px;">
      <!-- Save Invoice => DB Insert -->
      <button type="submit" name="save_invoice" value="1">Save Invoice</button>

      <!-- Generate PDF => No DB Insert -->
      <button type="submit" name="generate_pdf" value="1" formtarget="_blank" onclick="removeRequiredFile()">
        Generate Invoice
      </button>
    </div>
  </form>

  <a class="back-link" href="generate_invoice.php?project_id=<?php echo $project_id; ?>">
    
  </a>
</div>
</body>
</html>
