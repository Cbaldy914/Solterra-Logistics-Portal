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

/* ───────────────────────────────── GET TRUCKLOAD DATA ────────────────────────────────── */
$delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : 0;
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;

if ($delivery_id <= 0 || $warehouse_id <= 0) {
    $_SESSION['move_pallet_message'] = "Error: Invalid delivery or warehouse ID.";
    header("Location: manage_warehouses.php");
    exit();
}

// Fetch truckload details
$truckload_data = null;
$pallets_data = [];
$origin_warehouse_name = '';

try {
    // Get truckload and origin warehouse info
    $stmtTruckload = $conn->prepare("
        SELECT 
            d.id AS delivery_id,
            d.bol_number,
            d.supplier AS origin_vendor,
            d.warehouse_arrival_date AS received_date,
            w.name AS origin_warehouse_name,
            COUNT(ip.id) AS total_pallets,
            SUM(ip.quantity) AS total_modules
        FROM deliveries d
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        JOIN warehouses w ON ip.current_warehouse_id = w.id
        WHERE d.id = ? 
        AND ip.current_warehouse_id = ? 
        AND ip.status = 'In Warehouse'
        AND d.status_of_delivery = 'Delivered to Warehouse'
        GROUP BY d.id, d.bol_number, d.supplier, d.warehouse_arrival_date, w.name
    ");
    
    if ($stmtTruckload) {
        $stmtTruckload->bind_param("ii", $delivery_id, $warehouse_id);
        $stmtTruckload->execute();
        $result = $stmtTruckload->get_result();
        if ($result->num_rows > 0) {
            $truckload_data = $result->fetch_assoc();
            $origin_warehouse_name = $truckload_data['origin_warehouse_name'];
        }
        $stmtTruckload->close();
    }
    
    if (!$truckload_data) {
        throw new Exception("Truckload not found or not available for moving.");
    }
    
    // Get pallet details for the truckload (for wattage breakdown)
    $stmtPallets = $conn->prepare("
        SELECT 
            ip.wattage,
            COUNT(ip.id) AS pallet_count,
            SUM(ip.quantity) AS module_count
        FROM delivery_pallets dp
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        WHERE dp.delivery_id = ? AND ip.status = 'In Warehouse'
        GROUP BY ip.wattage
        ORDER BY ip.wattage
    ");
    
    if ($stmtPallets) {
        $stmtPallets->bind_param("i", $delivery_id);
        $stmtPallets->execute();
        $result = $stmtPallets->get_result();
        while ($pallet = $result->fetch_assoc()) {
            $pallets_data[] = $pallet;
        }
        $stmtPallets->close();
    }
    
} catch (Exception $e) {
    $_SESSION['move_pallet_message'] = "Error: " . $e->getMessage();
    header("Location: manage_warehouse_inventory.php?warehouse_id=" . $warehouse_id);
    exit();
}

/* ───────────────────────────────── FETCH DATA FOR DROPDOWNS ────────────────────────────────── */
$all_projects = [];
$all_warehouses = [];

// Fetch all projects
$stmtP = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
if ($stmtP) {
    $stmtP->execute();
    $resultP = $stmtP->get_result();
    while ($proj = $resultP->fetch_assoc()) {
        $all_projects[] = $proj;
    }
    $stmtP->close();
}

// Fetch other warehouses (exclude current warehouse)
$stmtW = $conn->prepare("SELECT id, name FROM warehouses WHERE id != ? ORDER BY name ASC");
if ($stmtW) {
    $stmtW->bind_param("i", $warehouse_id);
    $stmtW->execute();
    $resultW = $stmtW->get_result();
    while ($wh = $resultW->fetch_assoc()) {
        $all_warehouses[] = $wh;
    }
    $stmtW->close();
}

/* ───────────────────────────────  FORM SUBMISSION  ──────────────────────── */
$formMessage = '';
$messageIsError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_truckload'])) {
    
    // Get form data
    $bol_number = trim($_POST['bol_number'] ?? '');
    $departure_date = $_POST['departure_date'] ?? '';
    $est_arrival_date = $_POST['est_arrival_date'] ?? '';
    $assign_type = $_POST['assign_type'] ?? 'project';
    $target_id = intval($_POST['target_id'] ?? 0);
    
    // Cost data
    $total_freight_cost = ($_POST['freight_cost'] !== '') ? (float)$_POST['freight_cost'] : 0.0;
    $total_accessorial_paid = ($_POST['accessorial_costs_paid'] !== '') ? (float)$_POST['accessorial_costs_paid'] : 0.0;
    $total_accessorial_charged = ($_POST['accessorial_costs'] !== '') ? (float)$_POST['accessorial_costs'] : 0.0;
    $total_customer_cost = ($_POST['customer_cost'] !== '') ? (float)$_POST['customer_cost'] : 0.0;
    $total_miles = ($_POST['miles'] !== '') ? (float)$_POST['miles'] : null;
    
    // POD Upload
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
    
    $conn->begin_transaction();
    try {
        // Validation
        if (!empty($pod_upload_error)) {
            throw new Exception($pod_upload_error);
        }
        if ($target_id <= 0 || empty($departure_date) || empty($est_arrival_date)) {
            throw new Exception("Missing required fields: Destination, Departure Date, or Estimated Arrival Date.");
        }
        
        // Get all pallets from this truckload
        $stmtGetPallets = $conn->prepare("
            SELECT ip.id, ip.wattage, ip.quantity
            FROM delivery_pallets dp
            JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
            WHERE dp.delivery_id = ? AND ip.status = 'In Warehouse'
        ");
        $stmtGetPallets->bind_param("i", $delivery_id);
        $stmtGetPallets->execute();
        $palletResult = $stmtGetPallets->get_result();
        
        $palletsByWattage = [];
        $allPalletIds = [];
        $totalQuantity = 0;
        
        while ($pallet = $palletResult->fetch_assoc()) {
            $allPalletIds[] = $pallet['id'];
            $wattage = $pallet['wattage'];
            $totalQuantity += $pallet['quantity'];
            
            if (!isset($palletsByWattage[$wattage])) {
                $palletsByWattage[$wattage] = ['quantity' => 0, 'ids' => []];
            }
            $palletsByWattage[$wattage]['quantity'] += $pallet['quantity'];
            $palletsByWattage[$wattage]['ids'][] = $pallet['id'];
        }
        $stmtGetPallets->close();
        
        if (empty($allPalletIds)) {
            throw new Exception("No pallets found for this truckload.");
        }
        
        // Determine target status and locations based on destination
        $today_date = date('Y-m-d');
        $is_same_day_warehouse_arrival = ($assign_type === 'warehouse' && $est_arrival_date === $today_date);
        
        $new_pallet_status = '';
        $target_warehouse_id = null;
        $target_project_id = null;
        $delivery_status = '';
        $add_arrival_date_to_delivery = false;
        $arrival_date_for_delivery = null;
        
        if ($assign_type === 'project') {
            $new_pallet_status = 'In Transit to Project';
            $target_warehouse_id = null; 
            $target_project_id = $target_id;
            $delivery_status = 'In Transit to Project';
        } else { // Destination is warehouse
            if ($is_same_day_warehouse_arrival) {
                $new_pallet_status = 'In Warehouse';
                $target_warehouse_id = $target_id;
                $target_project_id = null;
                $delivery_status = 'Delivered to Warehouse'; 
                $add_arrival_date_to_delivery = true;
                $arrival_date_for_delivery = date('Y-m-d H:i:s');
            } else {
                $new_pallet_status = 'In Transit to Warehouse';
                $target_warehouse_id = null;
                $target_project_id = null;
                $delivery_status = 'In Transit to Warehouse';
            }
        }
        
        // Prepare statements
        $stmtLink = $conn->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
        if (!$stmtLink) throw new Exception("Failed to prepare pallet link insert: " . $conn->error);
        
        $sqlPalletUpdate = "UPDATE inventory_pallets SET status = ?, current_warehouse_id = ?, current_project_id = ? WHERE id = ?";
        $stmtPalletUpdate = $conn->prepare($sqlPalletUpdate);
        if (!$stmtPalletUpdate) throw new Exception("Failed to prepare pallet update: " . $conn->error);
        
        // Create deliveries for each wattage group
        $createdDeliveryIds = [];
        foreach ($palletsByWattage as $wattage => $group) {
            $groupQty = $group['quantity'];
            $groupPalletIds = $group['ids'];
            $proportion = $groupQty / $totalQuantity;
            
            // Calculate proportional costs
            $proportional_freight = round($total_freight_cost * $proportion, 2);
            $proportional_acc_paid = round($total_accessorial_paid * $proportion, 2);
            $proportional_acc_charged = round($total_accessorial_charged * $proportion, 2);
            $proportional_customer_cost = round($total_customer_cost * $proportion, 2);
            $proportional_miles = ($total_miles !== null) ? round($total_miles * $proportion, 2) : null;
            
            // Build delivery insert dynamically
            $deliveryColumns = ["supplier", "origin_type", "origin_id", "wattage", "quantity", "bol_number", "left_warehouse_date", "anticipated_delivery_date", "status_of_delivery", "freight_cost", "accessorial_costs_paid", "accessorial_costs", "customer_cost", "miles"];
            $deliveryParams = [$origin_warehouse_name, 'warehouse', $warehouse_id, $wattage, $groupQty, $bol_number, $departure_date, $est_arrival_date, $delivery_status, $proportional_freight, $proportional_acc_paid, $proportional_acc_charged, $proportional_customer_cost, $proportional_miles];
            $deliveryTypes = "ssissssssddddd";
            
            if ($proof_of_delivery_path) {
                $deliveryColumns[] = "proof_of_delivery";
                $deliveryParams[] = $proof_of_delivery_path;
                $deliveryTypes .= "s";
            }
            
            if ($assign_type === 'project') {
                $deliveryColumns[] = "project_id";
                $deliveryParams[] = $target_id;
                $deliveryTypes .= "i";
            } else {
                $deliveryColumns[] = "warehouse_id";
                $deliveryParams[] = $target_id;
                $deliveryTypes .= "i";
                if ($add_arrival_date_to_delivery) {
                    $deliveryColumns[] = "warehouse_arrival_date";
                    $deliveryParams[] = $arrival_date_for_delivery;
                    $deliveryTypes .= "s";
                }
            }
            
            $sqlPlaceholders = implode(", ", array_fill(0, count($deliveryParams), "?"));
            $sqlDeliveryInsert = "INSERT INTO deliveries (" . implode(", ", $deliveryColumns) . ") VALUES (" . $sqlPlaceholders . ")";
            
            $stmtDelivery = $conn->prepare($sqlDeliveryInsert);
            if (!$stmtDelivery) throw new Exception("Failed to prepare delivery insert: " . $conn->error);
            
            $stmtDelivery->bind_param($deliveryTypes, ...$deliveryParams);
            if (!$stmtDelivery->execute()) {
                throw new Exception("Failed to execute delivery insert for {$wattage}W: " . $stmtDelivery->error);
            }
            $newDeliveryId = $conn->insert_id;
            $createdDeliveryIds[] = $newDeliveryId;
            $stmtDelivery->close();
            
            // Update pallets and link to new delivery
            foreach ($groupPalletIds as $pid) {
                $stmtPalletUpdate->bind_param("siii", $new_pallet_status, $target_warehouse_id, $target_project_id, $pid);
                if (!$stmtPalletUpdate->execute()) throw new Exception("Failed to update pallet ID {$pid}: " . $stmtPalletUpdate->error);
                
                $stmtLink->bind_param("ii", $newDeliveryId, $pid);
                if (!$stmtLink->execute()) throw new Exception("Failed to link pallet ID {$pid} to delivery {$newDeliveryId}: " . $stmtLink->error);
            }
        }
        
        $conn->commit();
        $_SESSION['move_pallet_message'] = "Successfully moved truckload! Created " . count($createdDeliveryIds) . " new delivery entries.";
        
        $stmtLink->close();
        $stmtPalletUpdate->close();
        
        header("Location: manage_warehouse_inventory.php?warehouse_id=" . $warehouse_id);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $formMessage = $e->getMessage();
        $messageIsError = true;
        
        // Delete uploaded POD if transaction failed
        if ($proof_of_delivery_path && file_exists($proof_of_delivery_path)) {
            @unlink($proof_of_delivery_path);
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Move Truckload - BOL #<?php echo htmlspecialchars($truckload_data['bol_number'] ?? 'N/A'); ?></title>
<link rel="stylesheet" href="portal.css">
<link rel="icon" href="pictures/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    form { max-width: 800px;}
    fieldset { border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
    legend { font-weight: bold; padding: 0 10px; margin-left: 10px; }
    label { display: block; margin-bottom: 5px; font-weight: 500; }
    input[type=text], input[type=number], input[type=date], select, input[type=file], textarea {
        width: 100%;
        padding: 8px;
        margin-bottom: 15px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
    }

    button, input[type=submit] { background: #488C9A; color: #fff; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
    button:hover, input[type=submit]:hover { background: #3A6E7F; }

    .form-message {
        padding: 10px;
        margin-bottom: 20px;
        border-radius: 4px;
        border: 1px solid;
    }
    .form-message.success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
    .form-message.error { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }

    label.radio-label { display: inline-block; margin-right: 15px; margin-bottom: 15px; font-weight: normal; }
    label.radio-label input[type=radio] { width: auto; margin-right: 5px; vertical-align: middle; }

    .truckload-summary {
        background-color: #f9f9f9;
        padding: 15px;
        border: 1px solid #e0e0e0;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    .truckload-summary h3 {
        margin-top: 0;
        color: #293E4C;
    }
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="admin_dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manage_warehouses.php" style="color: #488C9A; text-decoration: none;">Manage Warehouses</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo $warehouse_id; ?>" style="color: #488C9A; text-decoration: none;">Warehouse Inventory</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Move Truckload</span>
    </div>

<h1>Move Truckload - BOL #<?php echo htmlspecialchars($truckload_data['bol_number'] ?? 'N/A'); ?></h1>

<?php if (!empty($formMessage)): ?>
    <div class="form-message <?php echo $messageIsError ? 'error' : 'success'; ?>">
        <?php echo htmlspecialchars($formMessage); ?>
    </div>
<?php endif; ?>

<div class="truckload-summary">
    <h3>Truckload Summary</h3>
    <p><strong>Original BOL:</strong> <?php echo htmlspecialchars($truckload_data['bol_number'] ?? 'N/A'); ?></p>
    <p><strong>Origin:</strong> <?php echo htmlspecialchars($origin_warehouse_name); ?></p>
    <p><strong>Total Pallets:</strong> <?php echo number_format($truckload_data['total_pallets']); ?></p>
    <p><strong>Total Modules:</strong> <?php echo number_format($truckload_data['total_modules']); ?></p>
    <p><strong>Wattage Breakdown:</strong></p>
    <ul style="margin-left: 20px;">
        <?php foreach ($pallets_data as $pallet): ?>
            <li><?php echo htmlspecialchars($pallet['wattage']); ?>W: <?php echo number_format($pallet['pallet_count']); ?> pallets (<?php echo number_format($pallet['module_count']); ?> modules)</li>
        <?php endforeach; ?>
    </ul>
</div>

<form action="move_truckload.php?delivery_id=<?php echo $delivery_id; ?>&warehouse_id=<?php echo $warehouse_id; ?>" method="post" enctype="multipart/form-data">

<fieldset>
    <legend>Movement Details</legend>
    
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
    
    <label>New BOL Number (Optional):<input type="text" name="bol_number" value="<?php echo htmlspecialchars($_POST['bol_number'] ?? ''); ?>"></label>
</fieldset>

<fieldset>
    <legend>Dates</legend>
    <label>Departure Date:<input type="date" name="departure_date" required value="<?php echo htmlspecialchars($_POST['departure_date'] ?? ''); ?>"></label>
    <label>Estimated Arrival Date:<input type="date" name="est_arrival_date" required value="<?php echo htmlspecialchars($_POST['est_arrival_date'] ?? ''); ?>"></label>
</fieldset>

<fieldset>
    <legend>Costs & Logistics</legend>
    <label>Total Freight Cost (for entire shipment):<input type="number" step="0.01" name="freight_cost" value="<?php echo htmlspecialchars($_POST['freight_cost'] ?? ''); ?>"></label>
    <label>Total Accessorial Cost (what we pay carrier):<input type="number" step="0.01" id="accessorial_costs_paid" name="accessorial_costs_paid" value="<?php echo htmlspecialchars($_POST['accessorial_costs_paid'] ?? ''); ?>"></label>
    <label style="display:flex; align-items:center; margin-top: 10px;">
       <input type="checkbox" id="charge_customer_ckb" style="width:auto; margin-right: 8px;" <?php echo !empty($_POST['accessorial_costs']) ? 'checked' : ''; ?>>
       Charge Customer Accessorials?
    </label>
    <input type="hidden" id="accessorial_costs" name="accessorial_costs" value="<?php echo htmlspecialchars($_POST['accessorial_costs'] ?? '0'); ?>">
    <label>Total Customer Cost:<input type="number" step="0.01" id="customer_cost" name="customer_cost" value="<?php echo htmlspecialchars($_POST['customer_cost'] ?? ''); ?>"></label>
    <label>Total Miles:<input type="number" step="0.01" name="miles" value="<?php echo htmlspecialchars($_POST['miles'] ?? ''); ?>"></label>
</fieldset>

<fieldset>
    <legend>Proof of Delivery (POD)</legend>
    <label>Upload POD:<input type="file" name="proof_of_delivery" accept=".pdf,.jpg,.jpeg,.png"></label>
</fieldset>

<input type="submit" name="move_truckload" value="Move Truckload">
</form>
</main>

<script>
    const projectsData = <?php echo json_encode($all_projects); ?>;
    const warehousesData = <?php echo json_encode($all_warehouses); ?>;

// Toggle Target Select Dropdown
function toggleTargetSelect() {
    var assignType = document.querySelector('input[name="assign_type"]:checked').value;
    var targetLabel = document.getElementById('targetLabel');
    var targetSelect = document.getElementById('target_id');
    
    targetLabel.textContent = (assignType === 'project') ? 'Project:' : 'Warehouse:';
    targetSelect.innerHTML = '';

    var data = (assignType === 'project') ? projectsData : warehousesData;
    var placeholder = (assignType === 'project') ? '-- Select Project --' : '-- Select Warehouse --';
    var nameField = (assignType === 'project') ? 'project_name' : 'name';

    if (data.length === 0) {
        targetSelect.innerHTML = `<option value="">No ${assignType}s found</option>`;
        targetSelect.disabled = true;
    } else {
        targetSelect.innerHTML = `<option value="">${placeholder}</option>`;
        data.forEach(function(item) {
            var option = document.createElement('option');
            option.value = item.id;
            option.textContent = item[nameField];
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
    if (chargeCustomerCheckbox && chargeCustomerCheckbox.checked) {
         syncCustomerAccessorialField();
    }
}

if (freightCostInput) {
    freightCostInput.addEventListener('input', calculateCustomerCost);
}

document.addEventListener('DOMContentLoaded', function() {
    toggleTargetSelect();
    calculateCustomerCost();
});
</script>
</body>
</html> 