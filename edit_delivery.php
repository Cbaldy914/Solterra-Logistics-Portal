<?php
session_name("logistics_session");
session_start();

/* ───────────────────────────── CSRF TOKEN ────────────────────────────── */
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ─────────────────────────── SECURITY / AUTH ─────────────────────────── */
if (!isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['global_admin', 'admin'])) {
    header("Location: unauthorized");
    exit();
}

/* ───────────────────────── PARAMS & CONNECTION ───────────────────────── */
if (empty($_GET['delivery_id'])) {
    die("Delivery ID missing.");
}
$delivery_id = (int)$_GET['delivery_id'];
$project_id_from_url = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) die("Connection failed");

// Initialize messages
$success_message = $_SESSION['edit_delivery_success'] ?? null;
$error_message = $_SESSION['edit_delivery_error'] ?? null;
unset($_SESSION['edit_delivery_success'], $_SESSION['edit_delivery_error']);

/* ───────────────────────── CURRENT DELIVERY ──────────────────────────── */
$stmt = $conn->prepare("SELECT * FROM deliveries WHERE id = ?");
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$result = $stmt->get_result();
$delivery = $result->fetch_assoc();
$stmt->close();
if (!$delivery) {
    die("Delivery not found.");
}

$context_project_id = $project_id_from_url ?? $delivery['project_id'] ?? null;
$is_unassigned_delivery = is_null($delivery['project_id']);

// Fetch project name if a project_id is associated with the delivery
$project_name_for_title = null;
if ($delivery['project_id']) {
    $stmt_project_name = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    if ($stmt_project_name) {
        $stmt_project_name->bind_param("i", $delivery['project_id']);
        $stmt_project_name->execute();
        $stmt_project_name->bind_result($fetched_project_name);
        if ($stmt_project_name->fetch()) {
            $project_name_for_title = $fetched_project_name;
        }
        $stmt_project_name->close();
    } else {
        error_log("Failed to prepare statement to fetch project name: " . $conn->error);
    }
}

/* ──────────────── FETCH ASSOCIATED PALLETS ──────────────── */
// Fetch ALREADY associated pallets
$associated_pallets = [];
$calculated_quantity = 0;
$stmt_assoc = $conn->prepare("
    SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity 
    FROM delivery_pallets dp 
    JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id 
    WHERE dp.delivery_id = ? 
    ORDER BY ip.id
");
if ($stmt_assoc) {
    $stmt_assoc->bind_param("i", $delivery_id);
    $stmt_assoc->execute();
    $result_assoc = $stmt_assoc->get_result();
    while ($row = $result_assoc->fetch_assoc()) {
        $associated_pallets[] = $row;
        $calculated_quantity += (int)$row['quantity']; // Sum up quantities from all pallets
    }
    $stmt_assoc->close();
} else {
    $error_message .= " Error fetching associated pallets: " . $conn->error;
}

/* ────────────────────────── UPDATE HANDLER ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_delivery'])) {

    /* CSRF */
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid request (CSRF). Refresh the page and try again.");
    }

    $conn->begin_transaction();
    try {
        /* Collect inputs */
        $supplier           = $_POST['manufacturer']          ?? ''; // Map manufacturer to supplier for backward compatibility
        $wattage            = $delivery['wattage']; // Keep original wattage, not editable here
        $status             = $_POST['status_of_delivery'] ?? '';
        $quantity           = $calculated_quantity; // Use calculated quantity from pallets, not form input
        $bol_number         = $_POST['bol_number']         ?? '';

        $anticipated_date   = $_POST['anticipated_delivery_date'] ?: null;
        $warehouse_arrival  = $_POST['warehouse_arrival_date']    ?: null;
        $actual_date        = $_POST['actual_delivery_date']      ?: null;
        $left_wh_date       = $_POST['left_warehouse_date']       ?: null;

        $freight_cost       = isset($_POST['freight_cost']) ? (float)$_POST['freight_cost'] : $delivery['freight_cost'];
        $access_paid        = isset($_POST['accessorial_costs_paid']) ? (float)$_POST['accessorial_costs_paid'] : $delivery['accessorial_costs_paid'];
        $access_charged     = isset($_POST['accessorial_costs']) ? (float)$_POST['accessorial_costs'] : $delivery['accessorial_costs'];
        $customer_cost      = isset($_POST['customer_cost']) ? (float)$_POST['customer_cost'] : $delivery['customer_cost'];
        $miles              = isset($_POST['miles']) ? (float)$_POST['miles'] : $delivery['miles'];

        // Handle existing POD removal
        $pod = $delivery['proof_of_delivery'];
        if (!empty($_POST['remove_pod']) && $_POST['remove_pod'] == '1') {
            if ($pod && file_exists($pod)) {
                @unlink($pod);
            }
            $pod = null;
        }
        
        // Handle file upload for new POD
        if (isset($_FILES['pod_file']) && $_FILES['pod_file']['error'] === UPLOAD_ERR_OK) {
            // Get delivery info to determine storage path
            $project_id = $delivery['project_id'];
            $warehouse_id = $delivery['warehouse_id'];
            
            // Get account name if this is a project delivery
            $account_name = null;
            if ($project_id) {
                $stmt_account = $conn->prepare("
                    SELECT c.name 
                    FROM projects p 
                    JOIN customer_accounts c ON p.account_id = c.id 
                    WHERE p.id = ?
                ");
                if ($stmt_account) {
                    $stmt_account->bind_param("i", $project_id);
                    $stmt_account->execute();
                    $stmt_account->bind_result($account_name);
                    $stmt_account->fetch();
                    $stmt_account->close();
                }
            }
            
            $original_name = $_FILES['pod_file']['name'];
            $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Invalid file type for POD. Only PDF, JPG, PNG allowed.");
            }
            
            if ($_FILES['pod_file']['size'] > 5 * 1024 * 1024) { // 5MB limit
                throw new Exception("POD file exceeds 5MB limit.");
            }
            
            // Use existing directory structure
            if ($project_id && $account_name) {
                // Project delivery - use project-based structure
                $account_dir = preg_replace('/[^A-Za-z0-9_-]/', '_', $account_name);
                $upload_dir = "customers/{$account_dir}/projects/{$project_id}/documents/pods/";
            } else {
                // Warehouse delivery - use warehouse-based structure
                $upload_dir = "warehouse_documents/pods/";
            }
            
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }
            
            // Create filename using existing pattern
            $original_filename = pathinfo($original_name, PATHINFO_FILENAME);
            $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '_', $original_filename);
            $sanitized = substr($sanitized, 0, 100);
            
            $final_filename = $delivery_id . '_' . $sanitized . '.' . $file_extension;
            $new_pod_path = $upload_dir . $final_filename;
            
            if (!move_uploaded_file($_FILES['pod_file']['tmp_name'], $new_pod_path)) {
                throw new Exception("Failed to upload POD file.");
            }
            
            // Remove old POD file if exists
            if ($pod && file_exists($pod)) {
                @unlink($pod);
            }
            
            $pod = $new_pod_path;
        }

        /* Update Delivery */
        $sql = "
            UPDATE deliveries SET
                supplier               = ?,
                wattage                = ?,
                status_of_delivery     = ?,
                quantity               = ?,
                bol_number             = ?,
                anticipated_delivery_date = ?,
                warehouse_arrival_date = ?,
                actual_delivery_date   = ?,
                left_warehouse_date    = ?,
                freight_cost           = ?,
                accessorial_costs_paid = ?,
                accessorial_costs      = ?,
                customer_cost          = ?,
                proof_of_delivery      = ?,
                miles                  = ?
            WHERE id = ?
        ";
        $stmt_update = $conn->prepare($sql);
        if (!$stmt_update) {
            throw new Exception("Prepare update failed: " . $conn->error);
        }

        $stmt_update->bind_param(
            "sssisssssddddsdi",
            $supplier, $wattage, $status, $quantity, $bol_number,
            $anticipated_date, $warehouse_arrival, $actual_date, $left_wh_date,
            $freight_cost, $access_paid, $access_charged, $customer_cost, $pod, $miles,
            $delivery_id
        );
        if (!$stmt_update->execute()) {
            throw new Exception("Update delivery failed: " . $stmt_update->error);
        }
        $stmt_update->close();

        /* Update Associated Pallet Statuses to Match Delivery Status */
        $pallet_status_update_count = 0;
        
        // Map delivery statuses to corresponding pallet statuses
        $status_mapping = [
            'Delivered to Project' => 'Delivered to Project',
            'Delivered to Warehouse' => 'In Warehouse',
            'In Transit to Project' => 'In Transit to Project', 
            'In Transit to Warehouse' => 'In Transit to Warehouse',
            'Pending' => 'At Manufacturer' // Assume pallets go back to manufacturer if delivery is pending
        ];
        
        if (isset($status_mapping[$status])) {
            $new_pallet_status = $status_mapping[$status];
            
            // Get all pallets associated with this delivery
            $stmt_get_pallets = $conn->prepare("
                SELECT dp.inventory_pallet_id 
                FROM delivery_pallets dp 
                WHERE dp.delivery_id = ?
            ");
            
            if ($stmt_get_pallets) {
                $stmt_get_pallets->bind_param("i", $delivery_id);
                $stmt_get_pallets->execute();
                $result_pallets = $stmt_get_pallets->get_result();
                $pallet_ids = [];
                
                while ($row = $result_pallets->fetch_assoc()) {
                    $pallet_ids[] = $row['inventory_pallet_id'];
                }
                $stmt_get_pallets->close();
                
                // Update pallet statuses if we found any pallets
                if (!empty($pallet_ids)) {
                    $placeholders = implode(',', array_fill(0, count($pallet_ids), '?'));
                    $types = 's' . str_repeat('i', count($pallet_ids)); // status + pallet IDs
                    
                    $stmt_update_pallets = $conn->prepare("
                        UPDATE inventory_pallets 
                        SET status = ? 
                        WHERE id IN ($placeholders)
                    ");
                    
                    if ($stmt_update_pallets) {
                        $stmt_update_pallets->bind_param($types, $new_pallet_status, ...$pallet_ids);
                        if ($stmt_update_pallets->execute()) {
                            $pallet_status_update_count = $stmt_update_pallets->affected_rows;
                        }
                        $stmt_update_pallets->close();
                    }
                }
            }
        }

        $conn->commit();
        
        $success_msg = "Delivery details updated successfully.";
        if ($pallet_status_update_count > 0) {
            $success_msg .= " Also updated status for $pallet_status_update_count associated pallet(s) to '$new_pallet_status'.";
        }
        $_SESSION['edit_delivery_success'] = $success_msg;

        // Redirect back to THIS edit page to remain in context
        header(
            "Location: edit_delivery.php?delivery_id={$delivery_id}" .
            ($project_id_from_url ? "&project_id={$project_id_from_url}" : "")
        );
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['edit_delivery_error'] = "Error updating delivery: " . $e->getMessage();
        header(
            "Location: edit_delivery.php?delivery_id={$delivery_id}" .
            ($project_id_from_url ? "&project_id={$project_id_from_url}" : "")
        );
        exit;
    }
}

/* ─────────────────────────── VIEW (FORM) ─────────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Delivery
<?php 
    if ($project_name_for_title) {
        echo " – For " . htmlspecialchars($project_name_for_title);
    } elseif ($is_unassigned_delivery) {
        echo " – Unassigned";
    } 
?>
</title>
<link rel="stylesheet" href="portal.css">
<link rel="icon" href="pictures/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;600;700&display=swap" rel="stylesheet">

<style>
  .form-columns {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 30px;
      margin-top: 20px;
  }
  @media (max-width: 900px) {
      .form-columns {
          grid-template-columns: 1fr;
          gap: 20px;
      }
  }
  fieldset {
      border: 1px solid #ddd;
      padding: 15px;
      margin-bottom: 20px;
      background-color: #fdfdfd;
      position: relative;
      height: fit-content;
  }
  legend {
      font-weight: bold; 
      padding: 0 10px;
      margin-left: 10px;
      color: #333;
      display: inline-block;
      margin-bottom: 10px;
  }
  label {
      display: block;
      margin-bottom: 5px;
      font-weight: 500;
  }
  input[type=text],
  input[type=number],
  input[type=date],
  select,
  input[type=file] {
      width: 100%;
      padding: 8px;
      margin-bottom: 15px;
      border: 1px solid #ccc;
      border-radius: 4px;
      box-sizing: border-box;
  }
  input[readonly] {
      background-color: #f8f9fa;
      color: #6c757d;
      cursor: not-allowed;
  }
  button.form-submit-button {
      background: #488C9A;
      color: #fff;
      padding: 12px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer; 
      font-size: 1em;
      display: block;
      width: fit-content;
      margin: 30px auto 20px auto;
      grid-column: 1 / -1;
  }
  button.form-submit-button:hover {
      background: #3A6E7F;
  }
  .table-responsive { width: 100%; overflow-x: auto; }

  .success-message {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
  }
  .error-message {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
  }
  .manage-pallets-button {
      background-color: #488C9A;
      color: white;
      padding: 5px 10px;
      font-size: 0.9em;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      text-decoration: none; 
      margin-left: 10px;
  }
  .manage-pallets-button:hover {
      background-color: #28606C;
  }
  .quantity-note {
      font-size: 0.85em;
      color: #666;
      font-style: italic;
      margin-top: -10px;
      margin-bottom: 15px;
  }
</style>
</head>
<body>

<?php include 'header.php'; ?>
<main>

    <!-- Breadcrumb Navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="admin_dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <?php
        // Build manage deliveries link with proper context
        $manage_deliveries_url = "manage_deliveries.php";
        if ($context_project_id) {
            $manage_deliveries_url .= "?filter_project_id=" . $context_project_id;
        } elseif ($is_unassigned_delivery) {
            $manage_deliveries_url .= "?filter_project_id=unassigned";
        }
        ?>
        <a href="<?php echo $manage_deliveries_url; ?>" style="color: #488C9A; text-decoration: none;">Manage Deliveries</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Edit Delivery</span>
    </div>

<h1>
  Edit Delivery
  <?php 
    if ($project_name_for_title) {
        echo " – For " . htmlspecialchars($project_name_for_title);
    } elseif ($is_unassigned_delivery) {
        echo " – Unassigned Delivery";
    } elseif ($delivery['project_id']) {
        echo " – For Project ID: " . htmlspecialchars($delivery['project_id']); 
    }
  ?>
</h1>

<!-- Display messages -->
<?php if ($success_message): ?>
    <p class="message success-message"><?php echo htmlspecialchars($success_message); ?></p>
<?php endif; ?>
<?php if ($error_message): ?>
    <p class="message error-message"><?php echo htmlspecialchars($error_message); ?></p>
<?php endif; ?>

<!-- Associated Pallets as a table -->
<fieldset>
  <legend>Associated Pallets</legend>
  <div class="table-responsive">
    <table id="associatedPalletsTable">
      <thead>
        <tr>
          <th>Number of Pallets</th>
          <th>Wattage</th>
          <th>Qty Per Pallet</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!empty($associated_pallets)): ?>
        <tr>
          <!-- 1) Number of Pallets -->
          <td><?php echo count($associated_pallets); ?></td>

          <!-- 2) Wattage -->
          <td><?php echo htmlspecialchars($delivery['wattage'] ?? 'N/A'); ?>W</td>

          <!-- 3) Qty Per Pallet (assumes all pallets have the same quantity) -->
          <td>
            <?php
              // Show quantity from the first pallet (if they differ, adjust logic as needed)
              echo !empty($associated_pallets)
                ? htmlspecialchars($associated_pallets[0]['quantity'] ?? 'N/A')
                : 'N/A';
            ?>
          </td>

          <!-- 4) Actions -->
          <td>
            <a 
              href="manage_delivery_pallets.php?delivery_id=<?php echo $delivery_id; ?>&wattage=<?php echo urlencode($delivery['wattage']); ?>" 
              class="manage-pallets-button"
            >
              Add / Edit Associated Pallets
            </a>
          </td>
        </tr>
      <?php else: ?>
        <tr>
          <td colspan="4" style="text-align:center;">
            No pallets are currently associated with this delivery. 
            <a href="manage_delivery_pallets.php?delivery_id=<?php echo $delivery_id; ?>&wattage=<?php echo urlencode($delivery['wattage']); ?>" 
               class="manage-pallets-button" 
               style="margin-left: 15px;">
              Add Pallets to Delivery
            </a>
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</fieldset>

<!-- Main form for editing Delivery details -->
<div class="form-container">
<form 
  action="edit_delivery.php?delivery_id=<?php echo $delivery_id; ?><?php if ($project_id_from_url) { echo '&project_id=' . $project_id_from_url; } ?>" 
  method="post" 
  enctype="multipart/form-data"
>
  <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'];?>">

  <div class="form-columns">
    <!-- Left Column -->
    <div class="column-left">
      <!-- Delivery Details -->
      <fieldset>
        <legend>Delivery Details</legend>
        <label>Manufacturer:
          <input type="text" name="manufacturer" value="<?php echo htmlspecialchars($delivery['supplier']);?>" required>
        </label>
        <label>Wattage:
          <input 
            type="text" 
            name="wattage" 
            value="<?php echo htmlspecialchars($delivery['wattage']);?>" 
            required 
            readonly 
            title="Wattage cannot be changed here. Manage associated pallets via the table above."
          >
        </label>
        <label>Status:
          <select name="status_of_delivery">
            <?php
            $statuses = ['Pending', 'In Transit to Warehouse', 'Delivered to Warehouse', 'In Transit to Project', 'Delivered to Project', 'Canceled'];
            foreach ($statuses as $st):
            ?>
              <option value="<?php echo $st;?>" 
                      <?php if ($delivery['status_of_delivery'] === $st) echo 'selected';?>>
                <?php echo $st;?>
              </option>
            <?php endforeach;?>
          </select>
        </label>
        <label>Quantity:
          <input type="number" name="quantity" value="<?php echo $calculated_quantity;?>" readonly title="Quantity is automatically calculated from associated pallets">
        </label>
        <div class="quantity-note">Calculated from associated pallets (<?php echo count($associated_pallets);?> pallets)</div>
        <label>BOL Number:
          <input type="text" name="bol_number" value="<?php echo htmlspecialchars($delivery['bol_number']);?>">
        </label>
      </fieldset>

      <!-- Dates -->
      <fieldset>
        <legend>Dates</legend>
        <label>Anticipated Delivery Date:
          <input type="date" name="anticipated_delivery_date" value="<?php echo htmlspecialchars($delivery['anticipated_delivery_date']);?>">
        </label>
        <label>Warehouse Arrival Date:
          <input type="date" name="warehouse_arrival_date" value="<?php echo htmlspecialchars($delivery['warehouse_arrival_date']);?>">
        </label>
        <label>Actual Delivery Date:
          <input type="date" name="actual_delivery_date" value="<?php echo htmlspecialchars($delivery['actual_delivery_date']);?>">
        </label>
        <label>Left Warehouse Date:
          <input type="date" name="left_warehouse_date" value="<?php echo htmlspecialchars($delivery['left_warehouse_date']);?>">
        </label>
      </fieldset>
    </div>

    <!-- Right Column -->
    <div class="column-right">
      <!-- Costs -->
      <fieldset>
        <legend>Costs</legend>
        <label>Freight Cost:
          <input 
            type="number" 
            step="0.01" 
            name="freight_cost" 
            value="<?php echo number_format((float)($delivery['freight_cost'] ?? 0), 2, '.', '');?>"
          >
        </label>

        <?php
          $paidVal    = number_format((float)($delivery['accessorial_costs_paid'] ?? 0), 2, '.', '');
          $chargedVal = number_format((float)($delivery['accessorial_costs']       ?? 0), 2, '.', '');
          $checked    = ((float)$delivery['accessorial_costs'] > 0) ? 'checked' : '';
        ?>
        <label>Accessorial Cost (Paid to Carrier):
          <input 
            type="number" 
            step="0.01" 
            id="accessorial_costs_paid" 
            name="accessorial_costs_paid" 
            value="<?php echo $paidVal;?>"
          >
        </label>

        <label style="display:flex;align-items:center;margin-bottom:15px">
          <input 
            type="checkbox" 
            id="charge_customer_ckb" 
            <?php echo $checked;?> 
            style="width: auto; margin-right: 10px;"
          >
          Charge Customer This Amount?
        </label>

        <!-- Hidden field for accessorial_costs -->
        <input 
          type="hidden" 
          id="accessorial_costs" 
          name="accessorial_costs" 
          value="<?php echo $chargedVal;?>"
        >

        <label>Customer Cost:
          <input 
            type="number" 
            step="0.01" 
            id="customer_cost"
            name="customer_cost" 
            value="<?php echo number_format((float)($delivery['customer_cost'] ?? 0), 2, '.', '');?>"
          >
        </label>

        <label>Miles:
          <input 
            type="number" 
            step="0.01" 
            name="miles" 
            value="<?php echo number_format((float)($delivery['miles'] ?? 0), 2, '.', '');?>"
          >
        </label>
      </fieldset>

      <!-- POD -->
      <fieldset>
        <legend>Proof of Delivery (POD)</legend>
        <?php if (!empty($delivery['proof_of_delivery'])): ?>
          <p>
            Current POD: 
            <a href="view_pod?delivery_id=<?php echo $delivery['id'];?>" target="_blank">
              view
            </a>
          </p>
          <label style="display: flex; align-items: center;">
            <input 
              type="checkbox" 
              name="remove_pod" 
              value="1" 
              style="width: auto; margin-right: 10px;"
            >
            Remove current POD
          </label>
        <?php endif;?>
        <label>Upload new POD:
          <input type="file" name="pod_file" accept=".pdf,.jpg,.jpeg,.png">
        </label>
      </fieldset>
    </div>

    <button type="submit" name="update_delivery" class="form-submit-button">
      Update Delivery Details
    </button>
  </div>
</form>
</div>

</main>

<script>
/**
 * Keep the hidden customer charge field in sync 
 * with the 'Charge Customer' checkbox and the paid amount,
 * and calculate customer cost automatically only if blank or user hasn't manually set it
 */
const ckb       = document.getElementById('charge_customer_ckb');
const paidInput = document.getElementById('accessorial_costs_paid');
const hiddenCst = document.getElementById('accessorial_costs');
const freightCostInput = document.querySelector('input[name="freight_cost"]');
const customerCostInput = document.getElementById('customer_cost');

// Track whether user has manually entered customer cost
let userHasSetCustomerCost = false;
const originalCustomerCost = customerCostInput ? parseFloat(customerCostInput.value) || 0 : 0;

function syncAccessorialCharge() {
  if (!ckb || !paidInput || !hiddenCst) return;
  const paidValue = parseFloat(paidInput.value) || 0;
  hiddenCst.value = ckb.checked ? paidValue.toFixed(2) : '0.00';
  calculateCustomerCost();
}

function calculateCustomerCost() {
  if (!freightCostInput || !customerCostInput || !hiddenCst) return;
  
  // Only auto-calculate if user hasn't manually set the customer cost
  // or if the customer cost field is empty/zero
  const currentCustomerCost = parseFloat(customerCostInput.value) || 0;
  
  if (!userHasSetCustomerCost || currentCustomerCost === 0) {
    const freightCost = parseFloat(freightCostInput.value) || 0;
    const accessorialCharged = parseFloat(hiddenCst.value) || 0;
    const totalCustomerCost = freightCost + accessorialCharged;
    customerCostInput.value = totalCustomerCost.toFixed(2);
  }
}

// Track when user manually enters customer cost
if (customerCostInput) {
  customerCostInput.addEventListener('input', () => {
    userHasSetCustomerCost = true;
  });
  
  // Also track focus/blur to detect manual changes
  customerCostInput.addEventListener('focus', () => {
    customerCostInput.dataset.previousValue = customerCostInput.value;
  });
  
  customerCostInput.addEventListener('blur', () => {
    if (customerCostInput.value !== customerCostInput.dataset.previousValue) {
      userHasSetCustomerCost = true;
    }
  });
}

if (ckb) {
  ckb.addEventListener('change', syncAccessorialCharge);
}
if (paidInput) {
  paidInput.addEventListener('input', () => {
    if (ckb && ckb.checked) {
      syncAccessorialCharge();
    }
  });
}

// Add event listener for freight cost changes
if (freightCostInput) {
  freightCostInput.addEventListener('input', calculateCustomerCost);
}

// Initial sync on page load - only if customer cost is currently 0 or matches calculated value
document.addEventListener('DOMContentLoaded', () => {
  // Check if the current customer cost matches what would be auto-calculated
  if (originalCustomerCost > 0) {
    const freightCost = parseFloat(freightCostInput.value) || 0;
    const accessorialCharged = parseFloat(hiddenCst.value) || 0;
    const calculatedCost = freightCost + accessorialCharged;
    
    // If current value doesn't match calculated, assume user set it manually
    if (Math.abs(originalCustomerCost - calculatedCost) > 0.01) {
      userHasSetCustomerCost = true;
    }
  }
  
  syncAccessorialCharge();
  // Only calculate on initial load if customer cost is 0 or user hasn't set it
  if (!userHasSetCustomerCost) {
    calculateCustomerCost();
  }
});
</script>

</body>
</html>
<?php $conn->close(); ?>
