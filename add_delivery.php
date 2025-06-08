<?php
session_name("logistics_session");
session_start();

/* ─────────────────────────────────────────  SECURITY  ───────────────────────────────────────── */
if (!isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['global_admin', 'admin'])) {
    header("Location: unauthorized");
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

/* ─────────────────────────────────────  DB CONNECTION  ─────────────────────────────────────── */
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) die("Unable to connect to database.");

/* ───────────────────────────────── FETCH DATA FOR DROPDOWNS ────────────────────────────────── */
$all_projects = [];
$all_warehouses = [];
$pageTitle = "Add Delivery"; // Default title
$project_id = null; // For context
$project_name = ''; // For title

// Get project ID from GET or POST for context
if (!empty($_GET['project_id'])) {
    $project_id = (int)$_GET['project_id'];
} elseif (!empty($_POST['form_project_id'])) { // Use a specific hidden field to avoid conflict
    $project_id = (int)$_POST['form_project_id'];
}

// If we have a project context, fetch its name for the title
if ($project_id) {
    $stmtPN = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    if ($stmtPN) {
        $stmtPN->bind_param("i", $project_id);
        $stmtPN->execute();
        $stmtPN->bind_result($pName);
        if ($stmtPN->fetch()) {
            $project_name = $pName;
            $pageTitle = "Add Delivery for Project: " . htmlspecialchars($project_name);
        }
        $stmtPN->close();
    }
}

// Fetch all projects (Global Admins see all, Admins might see filtered later if needed)
$stmtP = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
if ($stmtP) {
    $stmtP->execute();
    $resultP = $stmtP->get_result();
    while ($proj = $resultP->fetch_assoc()) {
        $all_projects[] = $proj;
    }
    $stmtP->close();
} else {
    error_log("Error preparing project fetch: " . $conn->error); // Log error
}

// Fetch Warehouses
$stmtW = $conn->prepare("SELECT id, name FROM warehouses ORDER BY name ASC");
if ($stmtW) {
    $stmtW->execute();
    $resultW = $stmtW->get_result();
    while ($wh = $resultW->fetch_assoc()) {
        $all_warehouses[] = $wh;
    }
    $stmtW->close();
} else {
    error_log("Error preparing warehouse fetch: " . $conn->error); // Log error
}

/* ───────────────────────────────  FORM SUBMISSION (MULTI-WATTAGE ADD)  ──────────────────────── */
$formMessage = '';
$messageIsError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_delivery'])) {

    // --- Get Common Data ---
    $supplier           = $conn->real_escape_string($_POST['manufacturer']          ?? ''); // Map manufacturer to supplier for backward compatibility
    $bol_number         = $conn->real_escape_string($_POST['bol_number']        ?? '');
    $status_of_delivery = $conn->real_escape_string($_POST['status_of_delivery'] ?? 'Pending'); // Get from form, default to Pending
    
    // Handle assign_type and target_id based on context
    if ($project_id) {
        // If we're in a project context, automatically assign to that project
        $assignType = 'project';
        $targetId = $project_id;
    } else {
        // Use form values when not in project context
        $assignType = $_POST['assign_type'] ?? 'project';
        $targetId = intval($_POST['target_id'] ?? 0);
    }

    // Simplified date handling for project deliveries
    $anticipated_delivery_date = $_POST['anticipated_delivery_date'] ?: null;
    $actual_delivery_date = $_POST['actual_delivery_date'] ?: null;

    // Total costs for the *entire* shipment (entered by user)
    $total_freight_cost        = ($_POST['freight_cost']        !== '') ? (float)$_POST['freight_cost']        : 0.0;
    $total_accessorial_paid    = ($_POST['accessorial_costs_paid'] !== '') ? (float)$_POST['accessorial_costs_paid'] : 0.0;
    $total_accessorial_charged = ($_POST['accessorial_costs']     !== '') ? (float)$_POST['accessorial_costs']     : 0.0; // From hidden field synced by JS
    $total_customer_cost       = ($_POST['customer_cost']       !== '') ? (float)$_POST['customer_cost']       : 0.0;
    $total_miles               = ($_POST['miles']               !== '') ? (float)$_POST['miles']               : null;

    // --- POD Upload --- 
    $proof_of_delivery_path = null;
    $pod_upload_error = null;
    if (isset($_FILES['proof_of_delivery']) && $_FILES['proof_of_delivery']['error'] === UPLOAD_ERR_OK) {
        $allowed_ext = ['pdf','jpg','jpeg','png'];
        $file = $_FILES['proof_of_delivery'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) {
            $pod_upload_error = "Invalid POD file type.";
        } elseif ($file['size'] > 5 * 1024 * 1024) {
             $pod_upload_error = "POD File larger than 5 MB.";
        } else {
        $upload_dir = 'uploads/pods/';
            if (!is_dir($upload_dir)) {@mkdir($upload_dir, 0755, true);}
            $new_name = 'pod_' . time() . '_' . uniqid() . '.' . $ext;
            $dest = $upload_dir . $new_name;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $proof_of_delivery_path = $dest;
            } else {
                $pod_upload_error = "POD File upload failed.";
            }
        }
    } elseif (isset($_FILES['proof_of_delivery']) && $_FILES['proof_of_delivery']['error'] !== UPLOAD_ERR_NO_FILE) {
         $pod_upload_error = "POD upload error code: " . $_FILES['proof_of_delivery']['error'];
    }

    // --- Wattage/Quantity Data --- 
    $wattages   = $_POST['wattages']   ?? [];
    $quantities = $_POST['quantities'] ?? [];

    $conn->begin_transaction();
    try {
        // Validation Checks
        if (!empty($pod_upload_error)) {
             throw new Exception($pod_upload_error);
    }
        if (empty($supplier) || $targetId <= 0 || empty($wattages) || count($wattages) !== count($quantities)) {
            throw new Exception("Missing required fields: Manufacturer, Destination, or valid Wattage/Quantity pairs.");
        }

        // Calculate total quantity
        $total_quantity = 0;
        for ($i = 0; $i < count($quantities); $i++) {
             $q = intval($quantities[$i]);
             if ($q <= 0 || empty($wattages[$i])) {
                 throw new Exception("Invalid wattage or quantity entered for item #" . ($i+1) . ". Please ensure all quantities are positive and wattages are provided.");
             }
             $total_quantity += $q;
        }

        // Prepare INSERT statement once based on destination
        if ($assignType === 'project') {
    $sql = "INSERT INTO deliveries
            (project_id, supplier, wattage, status_of_delivery, quantity, bol_number,
                     anticipated_delivery_date, actual_delivery_date,
                     freight_cost, accessorial_costs_paid, accessorial_costs, customer_cost, proof_of_delivery, miles)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; // 14 placeholders
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Prepare project delivery failed: " . $conn->error);
            $bind_types = "isssisssddddsd"; // 14 characters: i,s,s,s,i,s,s,s,d,d,d,d,s,d
        } else { // Warehouse
            $sql = "INSERT INTO deliveries
                    (warehouse_id, supplier, wattage, status_of_delivery, quantity, bol_number,
                     freight_cost, accessorial_costs_paid, accessorial_costs, customer_cost, proof_of_delivery, miles)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; // 12 placeholders
            $stmt = $conn->prepare($sql);
             if (!$stmt) throw new Exception("Prepare warehouse delivery failed: " . $conn->error);
             $bind_types = "isssisddddsd"; // 12 characters: i,s,s,s,i,s,d,d,d,d,s,d
        }

        // Loop through each wattage entry and insert
        $created_count = 0;
        for ($i = 0; $i < count($wattages); $i++) {
            $w = $conn->real_escape_string($wattages[$i]);
            $q = intval($quantities[$i]);
            $proportion = $q / $total_quantity;

            // Calculate proportional costs (handle potential division by zero implicitly via proportion)
            $proportional_freight = round($total_freight_cost * $proportion, 2);
            $proportional_acc_paid = round($total_accessorial_paid * $proportion, 2);
            $proportional_acc_charged = round($total_accessorial_charged * $proportion, 2);
            $proportional_customer_cost = round($total_customer_cost * $proportion, 2);
            $proportional_miles = ($total_miles !== null) ? round($total_miles * $proportion, 2) : null;

            // Bind parameters dynamically based on type
            if ($assignType === 'project') {
                $stmt->bind_param($bind_types,
                    $targetId, $supplier, $w, $status_of_delivery, $q, $bol_number,
                    $anticipated_delivery_date, $actual_delivery_date,
                    $proportional_freight, $proportional_acc_paid, $proportional_acc_charged,
                    $proportional_customer_cost, $proof_of_delivery_path, $proportional_miles
                );
            } else { // Warehouse
                 $stmt->bind_param($bind_types,
                    $targetId, $supplier, $w, $status_of_delivery, $q, $bol_number,
                    $proportional_freight, $proportional_acc_paid, $proportional_acc_charged,
                    $proportional_customer_cost, $proof_of_delivery_path, $proportional_miles
                );
            }

            if (!$stmt->execute()) {
                // Attempt to get specific error
                $exec_error = $stmt->error;
                $stmt->close(); // Close statement before throwing
                throw new Exception("Error executing insert for wattage {$w}: " . $exec_error);
            }
            $created_count++;
        }

        $stmt->close();
        $conn->commit();

        $_SESSION['manage_deliveries_message'] = "Successfully added {$created_count} delivery entries for BOL #{$bol_number}.";
        // Redirect back to manage_deliveries for the original project context if it exists
        $redirect_url = "manage_projects.php"; // Default fallback
        if ($project_id) {
             $redirect_url = "manage_deliveries.php?project_id=" . $project_id;
        } elseif ($assignType === 'project') {
            // If added to a project but no original context, go to that project's overview
             $redirect_url = "project_overview.php?id=" . $targetId;
        } else {
            // If added to warehouse, maybe go to warehouse list or dashboard
             $redirect_url = "admin_dashboard.php"; // Placeholder
        }
        header("Location: " . $redirect_url);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $formMessage = $e->getMessage();
        $messageIsError = true;
        // Delete uploaded POD if transaction failed AND it exists
        if ($proof_of_delivery_path && file_exists($proof_of_delivery_path)) {
            @unlink($proof_of_delivery_path);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $pageTitle; ?></title>
<link rel="stylesheet" href="portal.css">
<link rel="icon" href="pictures/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    form { max-width: 800px;}
    fieldset { border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
    legend { font-weight: bold; padding: 0 10px; margin-left: 10px; }
    label { display: block; margin-bottom: 5px; font-weight: 500; }
    input[type=text], input[type=number], input[type=date], select, input[type=file], textarea {
        width: 100%; /* Use 100% for responsiveness */
        padding: 8px;
        margin-bottom: 15px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box; /* Include padding and border in element's total width and height */
    }
    /* Adjust specific inputs if needed */
    .wattage-entry input[type=text],
    .wattage-entry input[type=number] {
        width: calc(100% - 16px); /* Example adjustment if labels are inline */
    }

    button, input[type=submit] { background: #488C9A; color: #fff; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
    button:hover, input[type=submit]:hover { background: #3A6E7F; }

    /* Styles for dynamic wattage fields */
    .wattage-entry {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: flex-end; /* Align items to bottom */
        padding: 10px;
        border: 1px dashed #eee;
        margin-bottom: 10px;
        border-radius: 4px;
    }
    .wattage-entry > div { /* Input field containers */
        flex: 1; /* Allow fields to grow */
        min-width: 150px; /* Minimum width */
    }
     .wattage-entry label { margin-bottom: 3px; font-size: 0.9em; }
    .remove-btn-container {
         flex: 0 0 auto; /* Don't grow, don't shrink, initial size */
    }
    .remove-wattage-btn {
        background: #dc3545 !important; /* Red background */
        padding: 8px 12px !important; /* Adjust padding */
    }
    .btn-add-wattage {
         background: #28a745; /* Green background */
         display: inline-block; /* Align with other buttons */
         margin-top: 5px;
    }

    /* Message Styling */
    .form-message {
        padding: 10px;
        margin-bottom: 20px;
        border-radius: 4px;
        border: 1px solid;
    }
    .form-message.success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
    .form-message.error { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }

    /* Make radios inline */
     label.radio-label { display: inline-block; margin-right: 15px; margin-bottom: 15px; font-weight: normal; }
     label.radio-label input[type=radio] { width: auto; margin-right: 5px; vertical-align: middle; }

</style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Breadcrumb Navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="admin_dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manage_deliveries.php" style="color: #488C9A; text-decoration: none;">Manage Deliveries</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Add Delivery</span>
    </div>

<h1><?php echo $pageTitle; ?></h1>

<?php if (!empty($formMessage)): ?>
    <div class="form-message <?php echo $messageIsError ? 'error' : 'success'; ?>">
        <?php echo htmlspecialchars($formMessage); ?>
    </div>
<?php endif; ?>

<form action="add_delivery.php" method="post" enctype="multipart/form-data">
    <?php if ($project_id): ?>
        <input type="hidden" name="form_project_id" value="<?php echo $project_id; ?>">
    <?php endif; ?>

<fieldset>
        <legend>Shipment Details</legend>
        
        <?php if (!$project_id): ?>
        <div>
            <label style="margin-bottom: 10px; display:block;">Destination:</label>
            <label class="radio-label">
                <input type="radio" name="assign_type" value="project" <?php echo (!isset($_POST['assign_type']) || $_POST['assign_type'] === 'project') ? 'checked' : ''; ?> onchange="toggleTargetSelect()"> Project
            </label>
            <label class="radio-label">
                <input type="radio" name="assign_type" value="warehouse" <?php echo (isset($_POST['assign_type']) && $_POST['assign_type'] === 'warehouse') ? 'checked' : ''; ?> onchange="toggleTargetSelect()"> Warehouse
            </label>
        </div>

        <div id="targetSelectContainer">
            <label for="target_id" id="targetLabel">Project:</label>
            <select name="target_id" id="target_id" required>
                <!-- Options loaded by JS -->
            </select>
        </div>
        <?php endif; ?>
        
        <label>Manufacturer:<input type="text" name="manufacturer" required value="<?php echo htmlspecialchars($_POST['manufacturer'] ?? ''); ?>"></label>
        <label>BOL Number:<input type="text" name="bol_number" value="<?php echo htmlspecialchars($_POST['bol_number'] ?? ''); ?>"></label>
 <label>Status of Delivery:
   <select name="status_of_delivery" required>
                <option value="Pending" <?php echo (($_POST['status_of_delivery'] ?? '') === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                <option value="In Transit to Warehouse" <?php echo (($_POST['status_of_delivery'] ?? '') === 'In Transit to Warehouse') ? 'selected' : ''; ?>>In Transit to Warehouse</option>
                <option value="Delivered to Warehouse" <?php echo (($_POST['status_of_delivery'] ?? '') === 'Delivered to Warehouse') ? 'selected' : ''; ?>>Delivered to Warehouse</option>
                <option value="In Transit to Project" <?php echo (($_POST['status_of_delivery'] ?? '') === 'In Transit to Project') ? 'selected' : ''; ?>>In Transit to Project</option>
                <option value="Delivered to Project" <?php echo (($_POST['status_of_delivery'] ?? '') === 'Delivered to Project') ? 'selected' : ''; ?>>Delivered to Project</option>
                <option value="Canceled" <?php echo (($_POST['status_of_delivery'] ?? '') === 'Canceled') ? 'selected' : ''; ?>>Canceled</option>
   </select>
 </label>
</fieldset>

<fieldset>
        <legend>Wattage & Quantity</legend>
        <div id="wattage-container">
            <!-- Dynamically added fields will go here -->
            <?php
            // If form submitted with errors, repopulate fields
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($wattages)) {
                for ($i = 0; $i < count($wattages); $i++) {
                    $w_val = htmlspecialchars($wattages[$i] ?? '');
                    $q_val = htmlspecialchars($quantities[$i] ?? '');
                    echo '<div class="wattage-entry">';
                    echo '<div><label>Wattage:</label><input type="text" name="wattages[]" value="' . $w_val . '" required></div>';
                    echo '<div><label>Quantity:</label><input type="number" name="quantities[]" value="' . $q_val . '" min="1" required></div>';
                    echo '<div class="remove-btn-container"><button type="button" class="remove-wattage-btn" onclick="this.closest(\'.wattage-entry\').remove()">Remove</button></div>';
                    echo '</div>';
                }
            }
            ?>
        </div>
        <button type="button" class="btn-add-wattage" onclick="addWattageField()">+ Add Wattage/Quantity</button>
</fieldset>

<fieldset>
 <legend>Dates</legend>
         <label>Anticipated Delivery Date:<input type="date" name="anticipated_delivery_date" value="<?php echo htmlspecialchars($_POST['anticipated_delivery_date'] ?? ''); ?>"></label>
         <label>Actual Delivery Date:<input type="date" name="actual_delivery_date" value="<?php echo htmlspecialchars($_POST['actual_delivery_date'] ?? ''); ?>"></label>
</fieldset>

<fieldset>
         <legend>Costs & Logistics</legend>
         <label>Total Freight Cost (for entire shipment):<input type="number" step="0.01" name="freight_cost" value="<?php echo htmlspecialchars($_POST['freight_cost'] ?? ''); ?>"></label>
         <label>Total Accessorial Cost (what we pay carrier):<input type="number" step="0.01" id="accessorial_costs_paid" name="accessorial_costs_paid" value="<?php echo htmlspecialchars($_POST['accessorial_costs_paid'] ?? ''); ?>"></label>
         <label style="display:flex; align-items:center; margin-top: 10px;">
           <input type="checkbox" id="charge_customer_ckb" style="width:auto; margin-right: 8px;" <?php echo !empty($_POST['accessorial_costs']) ? 'checked' : ''; ?>>
           Charge Customer Accessorials?
 </label>
         <!-- Hidden field for customer-facing amount -->
         <input type="hidden" id="accessorial_costs" name="accessorial_costs" value="<?php echo htmlspecialchars($_POST['accessorial_costs'] ?? '0'); ?>">
         <label>Total Customer Cost:<input type="number" step="0.01" id="customer_cost" name="customer_cost" value="<?php echo htmlspecialchars($_POST['customer_cost'] ?? ''); ?>"></label>
         <label>Total Miles:<input type="number" step="0.01" name="miles" value="<?php echo htmlspecialchars($_POST['miles'] ?? ''); ?>"></label>
</fieldset>

<fieldset>
 <legend>Proof of Delivery (POD)</legend>
 <label>Upload POD:<input type="file" name="proof_of_delivery" accept=".pdf,.jpg,.jpeg,.png"></label>
</fieldset>

<input type="submit" name="add_delivery" value="Add Delivery Entry">
</form>

</main>

<!-- Embed PHP data for JS -->
<script>
    const projectsData = <?php echo json_encode($all_projects); ?>;
    const warehousesData = <?php echo json_encode($all_warehouses); ?>;
    // Pass relevant IDs for JS: originating project ID (for default selection) and potential POST target ID (for repopulating)
    const contextProjectId = <?php echo json_encode($project_id); ?>; // ID from URL
    const postedTargetId = <?php echo json_encode(isset($_POST['target_id']) ? (int)$_POST['target_id'] : null); ?>; // ID from POST on error
</script>

<!-- JavaScript for Dynamic Fields and Dropdowns -->
<script>
// Add Wattage/Quantity Field Pair
function addWattageField() {
    var container = document.getElementById('wattage-container');
    var div = document.createElement('div');
    div.className = 'wattage-entry';
    div.innerHTML = `
        <div>
            <label>Wattage:</label>
            <input type="text" name="wattages[]" required>
        </div>
        <div>
            <label>Quantity:</label>
            <input type="number" name="quantities[]" min="1" required>
        </div>
        <div class="remove-btn-container">
            <button type="button" class="remove-wattage-btn" onclick="this.closest('.wattage-entry').remove()">Remove</button>
        </div>
    `;
    container.appendChild(div);
}

// Toggle Target Select Dropdown Label and Content (only if not in project context)
function toggleTargetSelect() {
    var assignTypeElement = document.querySelector('input[name="assign_type"]:checked');
    var targetLabel = document.getElementById('targetLabel');
    var targetSelect = document.getElementById('target_id');
    
    // Return early if elements don't exist (project context)
    if (!assignTypeElement || !targetLabel || !targetSelect) {
        return;
    }

    var assignType = assignTypeElement.value;
    targetLabel.textContent = (assignType === 'project') ? 'Project:' : 'Warehouse:';
    targetSelect.innerHTML = ''; // Clear existing options

    var data = (assignType === 'project') ? projectsData : warehousesData;
    var placeholder = (assignType === 'project') ? '-- Select Project --' : '-- Select Warehouse --';
    var nameField = (assignType === 'project') ? 'project_name' : 'name';

    // Determine which ID to pre-select: POSTed value (on error) > Context ID > None
    let idToSelect = postedTargetId;
    if (assignType === 'project' && idToSelect === null && contextProjectId !== null) {
        idToSelect = contextProjectId;
    }

    if (data.length === 0) {
        targetSelect.innerHTML = `<option value="">No ${assignType}s found</option>`;
        targetSelect.disabled = true;
    } else {
        targetSelect.innerHTML = `<option value="">${placeholder}</option>`;
        data.forEach(function(item) {
            var option = document.createElement('option');
            option.value = item.id;
            option.textContent = item[nameField];
            // Select based on determined ID
            if (idToSelect !== null && parseInt(item.id) === idToSelect) {
                 option.selected = true;
            }
            targetSelect.appendChild(option);
        });
        targetSelect.disabled = false;
    }
}

// Sync hidden customer accessorial field
const chargeCustomerCheckbox = document.getElementById('charge_customer_ckb');
const accessorialPaidInput = document.getElementById('accessorial_costs_paid');
const accessorialChargedHidden = document.getElementById('accessorial_costs');
const freightCostInput = document.querySelector('input[name="freight_cost"]');
const customerCostInput = document.getElementById('customer_cost');

function syncCustomerAccessorialField() {
    accessorialChargedHidden.value = chargeCustomerCheckbox.checked ? (parseFloat(accessorialPaidInput.value) || 0) : 0;
    calculateCustomerCost();
}

function calculateCustomerCost() {
    const freightCost = parseFloat(freightCostInput.value) || 0;
    const accessorialCharged = parseFloat(accessorialChargedHidden.value) || 0;
    const totalCustomerCost = freightCost + accessorialCharged;
    customerCostInput.value = totalCustomerCost.toFixed(2);
}

if (chargeCustomerCheckbox) {
    chargeCustomerCheckbox.addEventListener('change', syncCustomerAccessorialField);
}
if (accessorialPaidInput) {
    accessorialPaidInput.addEventListener('input', () => {
        if (chargeCustomerCheckbox && chargeCustomerCheckbox.checked) {
            syncCustomerAccessorialField();
        }
    });
    // Initial sync in case the checkbox is checked on load (e.g., after POST error)
    if (chargeCustomerCheckbox && chargeCustomerCheckbox.checked) {
         syncCustomerAccessorialField();
    }
}

// Add event listener for freight cost changes
if (freightCostInput) {
    freightCostInput.addEventListener('input', calculateCustomerCost);
}

// Initial call to set the dropdown correctly on page load
document.addEventListener('DOMContentLoaded', function() {
    // Only set up target selection if we're not in project context
    if (document.getElementById('targetSelectContainer')) {
        toggleTargetSelect();
    }
    
    // Add at least one wattage field if container is empty on load
    if (document.getElementById('wattage-container').children.length === 0) {
         addWattageField();
    }
    
    // Initialize customer cost calculation
    calculateCustomerCost();
});

</script>
</body>
</html>
<?php if ($conn) $conn->close(); ?>
