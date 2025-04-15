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

$errorMessage   = "";
$successMessage = "";

// Gather POST data
$project_id        = isset($_POST['project_id'])        ? (int)$_POST['project_id']        : 0;
$selected_ids_json = isset($_POST['selected_ids'])       ? $_POST['selected_ids']           : '';
$selectedRows      = json_decode($selected_ids_json, true);
if (!is_array($selectedRows)) {
    $selectedRows = [];
}

// Fetch project data (for solterra_fee, project_name)
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

// Fetch selected deliveries
$deliveries = [];
if (!empty($selectedRows)) {
    $placeholders = rtrim(str_repeat('?,', count($selectedRows)), ',');
    $paramTypes   = str_repeat('i', count($selectedRows));
    $sql_del      = "SELECT * FROM deliveries WHERE id IN ($placeholders)";

    $stmtDel = $conn->prepare($sql_del);
    $stmtDel->bind_param($paramTypes, ...$selectedRows);
    $stmtDel->execute();
    $resultDel = $stmtDel->get_result();
    while ($row = $resultDel->fetch_assoc()) {
        $deliveries[] = $row;
    }
    $stmtDel->close();
}

// =========================
// 1) Compute truckloads & MW
// =========================
$distinctBOLs = [];
$totalWatts   = 0.0;
foreach ($deliveries as $d) {
    $bol = trim($d['bol_number'] ?? '');
    if (!in_array($bol, $distinctBOLs, true) && $bol !== '') {
        $distinctBOLs[] = $bol;
    }
    $w = floatval($d['wattage'] ?? 0);
    $q = floatval($d['quantity'] ?? 0);
    $totalWatts += ($w * $q);
}
$truckloads = count($distinctBOLs);
// Convert total watts => MW
$totalMW = $totalWatts / 1000000;

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

// =============================
// (A) SAVE INVOICE TO DATABASE
// =============================
if (isset($_POST['save_invoice'])) {

    // Must have selected deliveries + invoice #
    if (empty($selectedRows) || empty($invoice_number)) {
        $errorMessage = "Please provide an invoice number and select at least one delivery.";
    } else {
        // Upload approach from add_invoice.php
        $upload_dir = 'uploads/invoices/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Check if file was uploaded
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
            $invoice_name = basename($_FILES['invoice_file']['name']);
            $invoice_path = $upload_dir . time() . '_' . $invoice_name;

            if (!move_uploaded_file($_FILES['invoice_file']['tmp_name'], $invoice_path)) {
                $errorMessage = "Failed to upload the invoice file.";
            }
        } else {
            $errorMessage = "No file uploaded or there was an upload error.";
        }

        // If no error, proceed with DB insert
        if (empty($errorMessage)) {
            $invoice_file = $invoice_path;

            // Default date => today if none specified
            $issued_date = (!empty($invoice_issued_date)) ? $invoice_issued_date : date('Y-m-d');
            $due_date    = $invoice_due_date;
            $status      = 'Open';

            // Calculate finalAmount
            $totalFreight     = 0.0;
            $totalAccessorial = 0.0;
            $totalSolterraFee = 0.0;

            foreach ($deliveries as $d) {
                $f  = floatval($d['freight_cost'] ?? 0);
                $a  = floatval($d['accessorial_costs'] ?? 0);
                $q  = floatval($d['quantity'] ?? 0);
                $w  = floatval($d['wattage']  ?? 0);
                $sf = $solterra_fee * ($w * $q);

                $totalFreight     += $f;
                $totalAccessorial += $a;
                $totalSolterraFee += $sf;
            }

            $combined    = $totalFreight + $totalAccessorial + $totalSolterraFee;
            $finalAmount = $combined - $deposit_credit;
            if ($finalAmount < 0) {
                $finalAmount = 0.0;
            }

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
                // Update deliveries -> set invoice_id and invoice_number
                $ph2       = rtrim(str_repeat('?,', count($selectedRows)), ',');
                $pt2       = str_repeat('i', count($selectedRows));
                $sqlUpdate = "
                   UPDATE deliveries
                   SET invoice_id = ?, invoice_number = ?
                   WHERE id IN ($ph2)
                ";
                $bindTypes = 'is' . $pt2;
                $allParams = array_merge([$invoiceId, $invoice_number], $selectedRows);

                $stmtUp = $conn->prepare($sqlUpdate);
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

// ================================
// (B) GENERATE PDF (no DB insert)
// ================================
if (isset($_POST['generate_pdf'])) {

    // 1) Format dates
    $displayIssuedDate = (!empty($invoice_issued_date))
        ? date('m-d-Y', strtotime($invoice_issued_date))
        : 'N/A';
    $displayDueDate = (!empty($invoice_due_date))
        ? date('m-d-Y', strtotime($invoice_due_date))
        : 'N/A';

    // 2) Group by BOL
    $groupedByBOL   = [];
    $uniqueBOLs     = [];
    $totalFreight   = 0.0;
    $totalAccess    = 0.0;
    $totalSolterra  = 0.0;

    foreach ($deliveries as $d) {
        $bol       = $d['bol_number'] ?? '';
        $freight   = floatval($d['freight_cost'] ?? 0);
        $access    = floatval($d['accessorial_costs'] ?? 0);
        $q         = floatval($d['quantity'] ?? 0);
        $w         = floatval($d['wattage']  ?? 0);
        $sf        = $solterra_fee * ($w * $q);

        $totalFreight   += $freight;
        $totalAccess    += $access;
        $totalSolterra  += $sf;

        if (!in_array($bol, $uniqueBOLs)) {
            $uniqueBOLs[] = $bol;
        }
        if (!isset($groupedByBOL[$bol])) {
            $groupedByBOL[$bol] = [
                'bol'         => $bol,
                'quantity'    => 0,
                'freight'     => 0.0,
                'accessorial' => 0.0,
            ];
        }
        $groupedByBOL[$bol]['quantity']    += $q;
        $groupedByBOL[$bol]['freight']     += $freight;
        $groupedByBOL[$bol]['accessorial'] += $access;
    }

    $numTruckloads  = count($uniqueBOLs);
    $freightPlusAcc = $totalFreight + $totalAccess;
    $ratePerTruck   = ($numTruckloads > 0) ? ($freightPlusAcc / $numTruckloads) : 0.0;

    $summaryRow = "
    <tr>
      <td>Deliveries - {$project_name} Inland Freight</td>
      <td>{$numTruckloads}</td>
      <td>" . number_format($ratePerTruck, 2) . "</td>
      <td>" . number_format($freightPlusAcc, 2) . "</td>
    </tr>";

    // Solterra fee row
    $solterraFeeLine = "
    <tr>
      <td>Solterra Solution's Base Fee</td>
      <td>--</td>
      <td>" . number_format($solterra_fee, 4) . "/w</td>
      <td>" . number_format($totalSolterra, 2) . "</td>
    </tr>";

    $depositLine = "
    <tr>
      <td>Pro-Rated Credit for Deposit</td>
      <td colspan='2'></td>
      <td>(\$" . number_format($deposit_credit, 2) . ")</td>
    </tr>";

    $subTotal   = $freightPlusAcc + $totalSolterra;
    $grandTotal = $subTotal - $deposit_credit;
    if ($grandTotal < 0) $grandTotal = 0.0;

    // Build itemized
    $itemizedRows = "";
    foreach ($groupedByBOL as $info) {
        $bolNum = $info['bol'];
        $desc   = "Deliveries - {$project_name} Inland Freight (BOL: {$bolNum})";
        $qty    = (int)$info['quantity'];
        $fVal   = number_format($info['freight'], 2);
        $aVal   = number_format($info['accessorial'], 2);
        $itemizedRows .= "
        <tr>
          <td>{$desc}</td>
          <td>{$qty}</td>
          <td>\${$fVal}</td>
          <td>\${$aVal}</td>
        </tr>";
    }

    // 5) DOMPDF
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
        {$summaryRow}
        {$solterraFeeLine}
        {$depositLine}
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
        {$itemizedRows}
      </tbody>
    </table>

    </body>
    </html>
    ";

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();

    $filename = "Invoice_" . ($invoice_number ?: 'NoNumber') . ".pdf";
    // Open in a new tab in the browser
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
    .back-link {
      margin-top: 10px;
      display: inline-block;
    }
    .modal-buttons {
      margin-top:20px; 
      display:flex; 
      gap:10px;
    }
    #saveModal {
      display: none;
      position: fixed;
      z-index: 9999;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4);
    }
    #modalContent {
      background-color: #fff;
      margin: 10% auto;
      padding: 20px;
      border-radius: 8px;
      max-width: 400px;
      position: relative;
    }
    .closeBtn {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    .closeBtn:hover {
      color: #000;
    }
    form {
      max-width: 600px;
    }
    form label {
      font-weight: 500;
    }
    form input[type="text"],
    form input[type="number"],
    form input[type="date"],
    form input[type="file"],
    form textarea {
      padding: 6px;
      border-radius: 4px;
      border: 1px solid #ccc;
      font: inherit;
      width: 100%;
    }
    form button {
      padding: 8px 16px;
      border: none;
      background: #488C9A;
      color: #fff;
      border-radius: 4px;
      cursor: pointer;
      font-size: 1rem;
    }
    form button:hover {
      background: #33707b;
    }
  </style>
  <script>
    function openSaveModal() {
      document.getElementById('saveModal').style.display = 'block';
    }
    function closeSaveModal() {
      document.getElementById('saveModal').style.display = 'none';
    }
    function confirmSave() {
      document.getElementById('hiddenSaveBtn').click();
    }

    // Key fix: remove "required" if user clicks "Generate Invoice"
    function allowGenerateWithoutFile() {
      const fileInput = document.getElementById('invoice_file');
      if (fileInput) {
        fileInput.removeAttribute('required');
      }
    }
  </script>
</head>
<body>
<?php include 'header.php'; ?>

<div class="container">
  <!-- Show error or success messages -->
  <?php if (!empty($errorMessage)): ?>
    <div class="error-message">
      <?php echo htmlspecialchars($errorMessage); ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($successMessage)): ?>
    <div class="success-message">
      <?php echo htmlspecialchars($successMessage); ?>
      <div style="margin-top:10px;">
        <a href="add_invoice.php">View Invoices</a>
      </div>
    </div>
  <?php endif; ?>

  <h1>Invoice Info for <?php echo htmlspecialchars($project_name); ?></h1>
  <p>
    You have selected <strong><?php echo count($selectedRows); ?></strong> deliveries,
    <strong><?php echo $truckloads; ?></strong> truckloads,
    and <strong><?php echo number_format($totalMW, 3); ?></strong> MW.
  </p>

  <!-- Single form for both saving and generating PDF -->
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

    <!-- Buttons -->
    <div style="display:flex; gap:10px;">
      <!-- 1) SAVE INVOICE triggers modal -->
      <button type="button" onclick="openSaveModal()">Save Invoice</button>

      <!-- 2) GENERATE PDF: remove 'required' from file input so it won't block submission -->
      <button type="submit" name="generate_pdf" value="1" formtarget="_blank" onclick="allowGenerateWithoutFile()">
        Generate Invoice
      </button>
    </div>

    <!-- Hidden submit for final "Save Invoice" -->
    <button id="hiddenSaveBtn" type="submit" name="save_invoice" value="1" style="display:none;"></button>

    <!-- The Modal (inside the same form) -->
    <div id="saveModal">
      <div id="modalContent">
        <span class="closeBtn" onclick="closeSaveModal()">&times;</span>
        <h2>Select Invoice File:</h2>
        <!-- 'required' will force user to pick a file if actually saving -->
        <label for="invoice_file">Invoice PDF (required):</label>
        <input type="file" id="invoice_file" name="invoice_file" accept=".pdf,.doc,.docx,.xls,.xlsx" required>

        <div class="modal-buttons">
          <button type="button" onclick="confirmSave()">Save</button>
          <button type="button" onclick="closeSaveModal()">Cancel</button>
        </div>
      </div>
    </div>
  </form>

  <a class="back-link" href="generate_invoice.php?project_id=<?php echo $project_id; ?>">
    &larr; Back to Deliveries
  </a>
</div>
</body>
</html>
