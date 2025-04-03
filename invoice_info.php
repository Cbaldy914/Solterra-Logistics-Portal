<?php
session_name("logistics_session");
session_start();

// 1) Check login / role
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login"); // or wherever your login is
    exit();
}
if ($_SESSION['role'] !== 'global_admin') {
    die("Access denied. You must be a global admin to view this page.");
}

require __DIR__ . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Database connection
require_once '../config.php'; // you mentioned ../config.php for getDBConnection()
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

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

// Editable fields
$invoice_number      = trim($_POST['invoice_number']       ?? '');
$invoice_issued_date = trim($_POST['invoice_issued_date']  ?? '');
$invoice_due_date    = trim($_POST['invoice_due_date']     ?? '');
$bill_to             = trim($_POST['bill_to']              ?? "DESRI\n575 Fifth Avenue, 24th Floor\nNew York, NY 10017");
$sow_text            = trim($_POST['sow_text']             ?? "Statement of Work, dated August 14, 2024");
$msa_text            = trim($_POST['msa_text']             ?? "Professional Service Agreement, dated August 7, 2024");
$deposit_credit      = isset($_POST['deposit_credit'])      ? floatval($_POST['deposit_credit']) : 0.0;
$notes               = trim($_POST['notes']                ?? '');

$successMessage = "";
$invoiceId      = null;

// =============================
// (A) SAVE INVOICE TO DATABASE
// =============================
if (isset($_POST['save_invoice'])) {
    // We need at least one delivery selected and an invoice number
    if (!empty($selectedRows) && !empty($invoice_number)) {
        // If no date provided, fallback to "today"
        $issued_date = !empty($invoice_issued_date) ? $invoice_issued_date : date('Y-m-d');
        $due_date    = $invoice_due_date;
        $status      = 'Open';
        $invoice_file= '';

        // Basic calc for finalAmount
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
        // We'll have 12 placeholders, so 12 param types:
        // project_id (i), invoice_number (s), amount (d), status (s),
        // issued_date (s), due_date (s), deposit_credit (d), notes (s),
        // invoice_file (s), bill_to (s), sow_text (s), msa_text (s)
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
        $stmtPi->execute();
        $invoiceId = $stmtPi->insert_id;
        $stmtPi->close();

        if ($invoiceId) {
            // Also update deliveries -> set invoice_id AND invoice_number
            // This ensures each row references the correct invoice
            $ph2       = rtrim(str_repeat('?,', count($selectedRows)), ',');
            $pt2       = str_repeat('i', count($selectedRows));
            $sqlUpdate = "
               UPDATE deliveries
               SET invoice_id = ?, invoice_number = ?
               WHERE id IN ($ph2)
            ";
            // We'll need 2 + count($selectedRows) params
            // The param types: 'is' + all 'i' for each row
            // e.g. 'isiii...' if there are 3 row IDs
            $bindTypes = 'is' . $pt2;
            // Merge invoiceId + invoice_number with the row IDs
            $allParams = array_merge([$invoiceId, $invoice_number], $selectedRows);

            $stmtUp = $conn->prepare($sqlUpdate);
            $stmtUp->bind_param($bindTypes, ...$allParams);
            $stmtUp->execute();
            $stmtUp->close();
        }

        $successMessage = "Invoice #{$invoice_number} has been saved (ID: $invoiceId).";
    } else {
        $successMessage = "Please provide an invoice number and select at least one delivery.";
    }
}

// ================================
// (B) GENERATE PDF (no DB insert)
// ================================
if (isset($_POST['generate_pdf'])) {

    // 1) Format dates as mm-dd-yyyy
    $displayIssuedDate = (!empty($invoice_issued_date))
        ? date('m-d-Y', strtotime($invoice_issued_date))
        : 'N/A';
    $displayDueDate = (!empty($invoice_due_date))
        ? date('m-d-Y', strtotime($invoice_due_date))
        : 'N/A';

    // 2) For the itemized table (second page), group deliveries by BOL
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

    // 3) Summary Table
    // - quantity = # unique BOL (truckloads)
    // - rate = (total freight+access) / # trucks
    // - amount = total freight+access
    $numTruckloads   = count($uniqueBOLs);
    $freightPlusAcc  = $totalFreight + $totalAccess;
    $ratePerTruck    = ($numTruckloads > 0) ? ($freightPlusAcc / $numTruckloads) : 0.0;

    // Build the single row
    $summaryRow = "
    <tr>
      <td>Deliveries - Babacomari Project Inland Freight</td>
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

    // Deposit row
    $depositLine = "
    <tr>
      <td>Pro-Rated Credit for Deposit</td>
      <td colspan='2'></td>
      <td>(\$" . number_format($deposit_credit, 2) . ")</td>
    </tr>";

    // Calculate invoice totals
    $subTotal   = $freightPlusAcc + $totalSolterra;
    $grandTotal = $subTotal - $deposit_credit;
    if ($grandTotal < 0) {
        $grandTotal = 0.0;
    }

    // 4) Itemized table (page 2)
    $itemizedRows = "";
    foreach ($groupedByBOL as $info) {
        $bolNum = $info['bol'];
        $desc   = "Deliveries - Babacomari Project Inland Freight (BOL: {$bolNum})";
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

    // 5) Construct the HTML
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

    <!-- SUMMARY TABLE -->
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

    // DOMPDF
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();

    // Stream inline in a new tab
    $filename = "Invoice_" . ($invoice_number ?: 'NoNumber') . ".pdf";
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
  </style>
</head>
<body>
<?php include '../header.php'; // or correct path ?>
<div class="container">
  <h1>Invoice Info for <?php echo htmlspecialchars($project_name); ?></h1>

  <?php if (!empty($successMessage)): ?>
    <div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>
  <?php endif; ?>

  <p>You have selected <strong><?php echo count($selectedRows); ?></strong> deliveries.</p>

  <form method="POST">
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
      <!-- Save Invoice -->
      <button type="submit" name="save_invoice" value="1">Save Invoice</button>
      <!-- Generate PDF (new tab) -->
      <button type="submit" name="generate_pdf" value="1" formtarget="_blank">
        Generate Invoice
      </button>
    </div>
  </form>

  <a class="back-link" href="generate_invoice.php?project_id=<?php echo $project_id; ?>">
    &#8592; Back to Deliveries
  </a>
</div>
</body>
</html>
