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
   1) Handle "Update Delivery" when user edits financial info
   ─────────────────────────────────────────────────────────── */
if (isset($_POST['update_delivery'])) {
    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    
    $conn->begin_transaction();
    try {
        $freight_cost = isset($_POST['freight_cost']) ? (float)$_POST['freight_cost'] : 0;
        $access_paid = isset($_POST['accessorial_costs_paid']) ? (float)$_POST['accessorial_costs_paid'] : 0;
        $access_charged = isset($_POST['accessorial_costs']) ? (float)$_POST['accessorial_costs'] : 0;
        $customer_cost = isset($_POST['customer_cost']) ? (float)$_POST['customer_cost'] : 0;
        $bol_number = trim($_POST['bol_number'] ?? '');
        
        $sql = "UPDATE deliveries SET 
                freight_cost = ?, 
                accessorial_costs_paid = ?, 
                accessorial_costs = ?, 
                customer_cost = ?,
                bol_number = ?
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ddddsi", $freight_cost, $access_paid, $access_charged, $customer_cost, $bol_number, $delivery_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update delivery: " . $stmt->error);
        }
        $stmt->close();
        
        $conn->commit();
        $successMessage = "Delivery #$delivery_id financial details updated successfully.";
        
    } catch (Exception $e) {
        $conn->rollback();
        $successMessage = "Error updating delivery: " . $e->getMessage();
    }
}

/* ───────────────────────────────────────────────────────────
   2) Handle "Save Payment" when user submits the form
   ─────────────────────────────────────────────────────────── */
if (isset($_POST['save_payment'])) {

    // 2A) Grab the posted vendor name, paid date, notes, etc.
    $vendorName = trim($_POST['vendor_name'] ?? '');
    // If user doesn't choose a date, default to "today"
    $paidDate   = $_POST['paid_date'] ?? date('Y-m-d');
    $notes      = trim($_POST['notes'] ?? '');

    // 2B) If user uploaded a receipt file
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

    // 2C) Summation logic: we already computed $totalAmount below, but let's confirm we have it
    // Actually we compute after we fetch deliveries (next block).
    // So let's do the insertion after we fetch the deliveries (like an approach).
    // We'll do it in the bottom, once we have $totalAmount.

    // We'll set a flag that we want to "SAVE" after we gather $deliveries
    $doInsertAccountsPayable = true;
}

/* ───────────────────────────────────────────────────────────
   3) Attempt to get project name
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
   4) Fetch the selected deliveries for display
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

// 4A) Now compute total cost for these deliveries => sum of freight_cost + accessorial_costs_paid
foreach ($deliveries as $dlv) {
    $freight = (float)($dlv['freight_cost']           ?? 0.0);
    $accPaid = (float)($dlv['accessorial_costs_paid'] ?? 0.0);
    $totalAmount += ($freight + $accPaid);
}

/* ───────────────────────────────────────────────────────────
   5) If user clicked "Save Payment," we now have the $totalAmount
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
            <th>Customer Pay</th>
            <th>Charge Customer?</th>
            <th>Actions</th>
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
            $custVal = (float)$d['customer_cost'];
            // If $acVal>0, presumably "Charge?" was set previously
            $checked = ($acVal>0) ? 'checked' : '';
          ?>
          <tr id="row_<?php echo $did; ?>">
            <!-- View Mode -->
            <td class="view-mode">
              <?php echo $did; ?>
              <input type="hidden" class="delivery-id" value="<?php echo $did; ?>">
            </td>
            <td class="view-mode">
              <span class="display-value"><?php echo $bol; ?></span>
              <input type="text" class="edit-input" name="bol_number" value="<?php echo $bol; ?>" style="display: none; width: 100px;">
            </td>
            <td class="view-mode">
              <span class="display-value">$<?php echo number_format($frVal,2); ?></span>
              <input type="number" class="edit-input" name="freight_cost" value="<?php echo number_format($frVal,2,'.',''); ?>" step="0.01" style="display: none; width: 80px;">
            </td>
            <td class="view-mode">
              <span class="display-value">$<?php echo number_format($apVal,2); ?></span>
              <input type="number" class="edit-input" name="accessorial_costs_paid" value="<?php echo number_format($apVal,2,'.',''); ?>" step="0.01" style="display: none; width: 80px;">
            </td>
            <td class="view-mode">
              <span class="display-value">$<?php echo number_format($custVal,2); ?></span>
              <input type="number" class="edit-input" name="customer_cost" value="<?php echo number_format($custVal,2,'.',''); ?>" step="0.01" style="display: none; width: 80px;">
            </td>
            <td>
              <!-- If user checks this, we set deliveries.accessorial_costs = accessorial_costs_paid -->
              <label style="display:inline-flex;align-items:center;gap:6px;">
                <input type="checkbox" name="charge_customer[<?php echo $did;?>]" class="charge-customer-checkbox" data-delivery-id="<?php echo $did; ?>" <?php echo $checked;?>>
                Charge?
              </label>
              <input type="hidden" class="edit-input" name="accessorial_costs" value="<?php echo number_format($acVal,2,'.',''); ?>">
            </td>
            <td>
              <button type="button" class="edit-btn" onclick="toggleEdit(<?php echo $did; ?>)" style="background: #488C9A; color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 0.8em; margin-right: 5px;">Edit</button>
              <button type="button" class="save-btn" onclick="saveDelivery(<?php echo $did; ?>)" style="background: #28a745; color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 0.8em; margin-right: 5px; display: none;">Save</button>
              <button type="button" class="cancel-btn" onclick="cancelEdit(<?php echo $did; ?>)" style="background: #dc3545; color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 0.8em; display: none;">Cancel</button>
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
      
    </p>
</div>

<script>
// Store original values for cancel functionality
let originalValues = {};

function toggleEdit(deliveryId) {
    const row = document.getElementById(`row_${deliveryId}`);
    const displayValues = row.querySelectorAll('.display-value');
    const editInputs = row.querySelectorAll('.edit-input');
    const editBtn = row.querySelector('.edit-btn');
    const saveBtn = row.querySelector('.save-btn');
    const cancelBtn = row.querySelector('.cancel-btn');
    
    // Store original values
    originalValues[deliveryId] = {};
    editInputs.forEach(input => {
        if (input.name !== 'accessorial_costs') { // Skip hidden field
            originalValues[deliveryId][input.name] = input.value;
        }
    });
    
    // Hide display values, show edit inputs
    displayValues.forEach(span => span.style.display = 'none');
    editInputs.forEach(input => {
        if (input.name !== 'accessorial_costs') { // Skip hidden field
            input.style.display = 'inline-block';
        }
    });
    
    // Toggle buttons
    editBtn.style.display = 'none';
    saveBtn.style.display = 'inline-block';
    cancelBtn.style.display = 'inline-block';
}

function cancelEdit(deliveryId) {
    const row = document.getElementById(`row_${deliveryId}`);
    const displayValues = row.querySelectorAll('.display-value');
    const editInputs = row.querySelectorAll('.edit-input');
    const editBtn = row.querySelector('.edit-btn');
    const saveBtn = row.querySelector('.save-btn');
    const cancelBtn = row.querySelector('.cancel-btn');
    
    // Restore original values
    if (originalValues[deliveryId]) {
        editInputs.forEach(input => {
            if (originalValues[deliveryId][input.name] !== undefined) {
                input.value = originalValues[deliveryId][input.name];
            }
        });
    }
    
    // Show display values, hide edit inputs
    displayValues.forEach(span => span.style.display = 'inline');
    editInputs.forEach(input => {
        if (input.name !== 'accessorial_costs') { // Skip hidden field
            input.style.display = 'none';
        }
    });
    
    // Toggle buttons
    editBtn.style.display = 'inline-block';
    saveBtn.style.display = 'none';
    cancelBtn.style.display = 'none';
}

function saveDelivery(deliveryId) {
    const row = document.getElementById(`row_${deliveryId}`);
    const editInputs = row.querySelectorAll('.edit-input');
    const chargeCheckbox = row.querySelector('.charge-customer-checkbox');
    
    // Create form data
    const formData = new FormData();
    formData.append('update_delivery', '1');
    formData.append('delivery_id', deliveryId);
    
    // Get values from edit inputs
    editInputs.forEach(input => {
        formData.append(input.name, input.value);
    });
    
    // Handle charge customer checkbox
    const accessorialPaid = parseFloat(row.querySelector('input[name="accessorial_costs_paid"]').value) || 0;
    const accessorialCharged = chargeCheckbox.checked ? accessorialPaid : 0;
    formData.set('accessorial_costs', accessorialCharged.toFixed(2));
    
    // Submit via fetch
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        // Reload the page to show updated values
        window.location.reload();
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating delivery. Please try again.');
    });
}

// Handle charge customer checkbox changes during editing
document.addEventListener('DOMContentLoaded', function() {
    const chargeCheckboxes = document.querySelectorAll('.charge-customer-checkbox');
    
    chargeCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const deliveryId = this.getAttribute('data-delivery-id');
            const row = document.getElementById(`row_${deliveryId}`);
            const accessorialPaidInput = row.querySelector('input[name="accessorial_costs_paid"]');
            const accessorialChargedInput = row.querySelector('input[name="accessorial_costs"]');
            const customerCostInput = row.querySelector('input[name="customer_cost"]');
            const freightCostInput = row.querySelector('input[name="freight_cost"]');
            
            if (accessorialPaidInput && accessorialChargedInput && customerCostInput && freightCostInput) {
                const accessorialPaid = parseFloat(accessorialPaidInput.value) || 0;
                const accessorialCharged = this.checked ? accessorialPaid : 0;
                const freightCost = parseFloat(freightCostInput.value) || 0;
                const customerCost = freightCost + accessorialCharged;
                
                accessorialChargedInput.value = accessorialCharged.toFixed(2);
                customerCostInput.value = customerCost.toFixed(2);
                
                // Update display value if in view mode
                const customerCostDisplay = row.querySelector('td.view-mode:nth-child(5) .display-value');
                if (customerCostDisplay && customerCostDisplay.style.display !== 'none') {
                    customerCostDisplay.textContent = '$' + customerCost.toFixed(2);
                }
            }
        });
    });
    
    // Handle input changes for automatic customer cost calculation
    document.addEventListener('input', function(e) {
        if (e.target.name === 'freight_cost' || e.target.name === 'accessorial_costs_paid') {
            const row = e.target.closest('tr');
            const deliveryId = row.querySelector('.delivery-id').value;
            const checkbox = row.querySelector('.charge-customer-checkbox');
            const freightCostInput = row.querySelector('input[name="freight_cost"]');
            const accessorialPaidInput = row.querySelector('input[name="accessorial_costs_paid"]');
            const customerCostInput = row.querySelector('input[name="customer_cost"]');
            const accessorialChargedInput = row.querySelector('input[name="accessorial_costs"]');
            
            if (freightCostInput && accessorialPaidInput && customerCostInput && accessorialChargedInput) {
                const freightCost = parseFloat(freightCostInput.value) || 0;
                const accessorialPaid = parseFloat(accessorialPaidInput.value) || 0;
                const accessorialCharged = checkbox.checked ? accessorialPaid : 0;
                const customerCost = freightCost + accessorialCharged;
                
                customerCostInput.value = customerCost.toFixed(2);
                accessorialChargedInput.value = accessorialCharged.toFixed(2);
            }
        }
    });
});
</script>

</body>
</html>
