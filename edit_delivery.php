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

/* ───────────────────────── CURRENT DELIVERY ──────────────────────────── */
$stmt = $conn->prepare("SELECT * FROM deliveries WHERE id = ?");
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$delivery = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$delivery) die("Delivery not found.");

$context_project_id = $project_id_from_url ?? $delivery['project_id'] ?? null;
$is_unassigned_delivery = is_null($delivery['project_id']);

// NEW: Fetch project name if a project_id is associated with the delivery
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
        // Optional: Log an error if preparing the statement fails
        error_log("Failed to prepare statement to fetch project name: " . $conn->error);
    }
}

/* ────────────────────────── UPDATE HANDLER ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_delivery'])) {

    /* CSRF */
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid request (CSRF).");
    }

    /* Collect inputs */
    $supplier           = $_POST['supplier']          ?? '';
    $wattage            = $_POST['wattage']           ?? '';
    $status             = $_POST['status_of_delivery']?? '';
    $quantity           = (int)($_POST['quantity']    ?? 0);
    $bol_number         = $_POST['bol_number']        ?? '';

    $anticipated_date   = $_POST['anticipated_delivery_date'] ?: null;
    $warehouse_arrival  = $_POST['warehouse_arrival_date']    ?: null;
    $actual_date        = $_POST['actual_delivery_date']      ?: null;
    $left_wh_date       = $_POST['left_warehouse_date']       ?: null;

    $freight_cost       = (float)($_POST['freight_cost']           ?? 0);
    $access_paid        = (float)($_POST['accessorial_costs_paid'] ?? 0);
    $access_charged     = (float)($_POST['accessorial_costs']      ?? 0);
    $miles              = (float)($_POST['miles']                  ?? 0);

    /* keep / replace POD */
    $pod = $delivery['proof_of_delivery'];
    if (!empty($_POST['remove_pod']) && $_POST['remove_pod']=='1') {
        if ($pod && file_exists($pod)) @unlink($pod);
        $pod = '';
    }
    /* optional new POD upload – omitted here for brevity (retain your previous code) */

    /* UPDATE */
    $sql = "
      UPDATE deliveries SET
        supplier                = ? ,
        wattage                 = ? ,
        status_of_delivery      = ? ,
        quantity                = ? ,
        bol_number              = ? ,
        anticipated_delivery_date = ? ,
        warehouse_arrival_date  = ? ,
        actual_delivery_date    = ? ,
        left_warehouse_date     = ? ,
        freight_cost            = ? ,
        accessorial_costs_paid  = ? ,
        accessorial_costs       = ? ,
        proof_of_delivery       = ? ,
        miles                   = ?
      WHERE id = ?
    ";
    $stmt = $conn->prepare($sql);
    /* types: s s s i s s s s s d d d s d i => "sssisssssdddsdi" */
    $stmt->bind_param(
        "sssisssssdddsdi",
        $supplier,
        $wattage,
        $status,
        $quantity,
        $bol_number,
        $anticipated_date,
        $warehouse_arrival,
        $actual_date,
        $left_wh_date,
        $freight_cost,
        $access_paid,
        $access_charged,
        $pod,
        $miles,
        $delivery_id
    );
    if ($stmt->execute()) {
        $redirect_url = "manage_deliveries.php";
        if ($context_project_id) {
            $redirect_url .= "?filter_project_id=" . $context_project_id;
        } elseif ($is_unassigned_delivery) {
            $redirect_url .= "?filter_project_id=unassigned";
        }
        header("Location: " . $redirect_url);
        exit;
    }
    echo "<p>Error: ".htmlspecialchars($stmt->error)."</p>";
    $stmt->close();
}

/* ─────────────────────────── VIEW  (FORM) ───────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Delivery<?php 
    if ($project_name_for_title) {
        echo " - For " . htmlspecialchars($project_name_for_title);
    } elseif ($is_unassigned_delivery) {
        echo " - Unassigned";
    } 
?></title>
<link rel="stylesheet" href="portal.css">
<link rel="icon" href="pictures/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;600;700&display=swap" rel="stylesheet">
<style>
  form{max-width:600px}
  fieldset{border:1px solid #ddd;padding:15px;margin-bottom:20px}
  legend{font-weight:bold}
  label{display:block;margin-bottom:5px;font-weight:500}
  input[type=text],input[type=number],input[type=date],select,input[type=file]{width:100%;padding:8px;margin-bottom:15px;border:1px solid #ccc;border-radius:4px}
  button{background:#488C9A;color:#fff;padding:10px 15px;border:none;border-radius:4px;cursor:pointer}
  button:hover{background:#3A6E7F}
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
<p style="margin-top:20px;">
  <?php 
  $back_link_url = "manage_deliveries.php";
  if ($context_project_id) {
      $back_link_url .= "?filter_project_id=" . $context_project_id;
  } elseif ($is_unassigned_delivery) {
      $back_link_url .= "?filter_project_id=unassigned";
  }
  ?>
  <a href="<?php echo $back_link_url; ?>">&larr; Back to Manage Deliveries</a>
</p>
<h1>Edit Delivery
    <?php 
    if ($project_name_for_title) {
        echo "– For " . htmlspecialchars($project_name_for_title);
    } elseif ($is_unassigned_delivery) {
        echo "– Unassigned Delivery";
    } elseif ($delivery['project_id']) { // Fallback if name fetch failed but ID exists
        echo "– For Project ID: " . htmlspecialchars($delivery['project_id']); 
    }
    ?>
</h1>

<form action="edit_delivery.php?delivery_id=<?php echo $delivery_id; ?><?php if ($project_id_from_url) { echo '&project_id=' . $project_id_from_url; } ?>"
      method="post" enctype="multipart/form-data">
 <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'];?>">

 <!-- Delivery -->
 <fieldset><legend>Delivery Details</legend>
  <label>Supplier:<input type="text" name="supplier" value="<?php echo htmlspecialchars($delivery['supplier']);?>" required></label>
  <label>Wattage:<input type="text" name="wattage" value="<?php echo htmlspecialchars($delivery['wattage']);?>" required></label>
  <label>Status:
    <select name="status_of_delivery">
      <?php foreach(['Produced','In Warehouse','Delivered','Canceled'] as $st):?>
        <option value="<?php echo $st;?>" <?php if($delivery['status_of_delivery']===$st) echo 'selected';?>><?php echo $st;?></option>
      <?php endforeach;?>
    </select>
  </label>
  <label>Quantity:<input type="number" name="quantity" value="<?php echo (int)$delivery['quantity'];?>" required></label>
  <label>BOL Number:<input type="text" name="bol_number" value="<?php echo htmlspecialchars($delivery['bol_number']);?>" required></label>
 </fieldset>

 <!-- Dates -->
 <fieldset><legend>Dates</legend>
  <label>Anticipated Delivery Date:<input type="date" name="anticipated_delivery_date" value="<?php echo htmlspecialchars($delivery['anticipated_delivery_date']);?>"></label>
  <label>Warehouse Arrival Date:<input type="date" name="warehouse_arrival_date" value="<?php echo htmlspecialchars($delivery['warehouse_arrival_date']);?>"></label>
  <label>Actual Delivery Date:<input type="date" name="actual_delivery_date" value="<?php echo htmlspecialchars($delivery['actual_delivery_date']);?>"></label>
  <label>Left Warehouse Date:<input type="date" name="left_warehouse_date" value="<?php echo htmlspecialchars($delivery['left_warehouse_date']);?>"></label>
 </fieldset>

 <!-- Costs -->
 <fieldset><legend>Costs</legend>
  <label>Freight Cost:<input type="number" step="0.01" name="freight_cost" value="<?php echo (float)$delivery['freight_cost'];?>"></label>

  <?php
    $paidVal    = (float)$delivery['accessorial_costs_paid'];
    $chargedVal = (float)$delivery['accessorial_costs'];
    $checked    = $chargedVal > 0 ? 'checked' : '';
  ?>
  <label>Accessorial Cost (what we pay carrier):
    <input type="number" step="0.01" id="accessorial_costs_paid" name="accessorial_costs_paid"
           value="<?php echo $paidVal;?>">
  </label>

  <label style="display:flex;align-items:center;margin-bottom:15px">
    <input type="checkbox" id="charge_customer_ckb" <?php echo $checked;?>>
    Charge Customer?
  </label>

  <input type="hidden" id="accessorial_costs" name="accessorial_costs" value="<?php echo $chargedVal;?>">

  <label>Miles:<input type="number" step="0.01" name="miles" value="<?php echo (float)$delivery['miles'];?>"></label>
 </fieldset>

 <!-- POD (kept identical to your previous logic) -->
 <fieldset><legend>Proof of Delivery (POD)</legend>
  <?php if(!empty($delivery['proof_of_delivery'])):?>
    <p>Current POD: <a href="view_pod?delivery_id=<?php echo $delivery['id'];?>" target="_blank">view</a></p>
    <label><input type="checkbox" name="remove_pod" value="1"> Remove current POD</label>
  <?php endif;?>
  <label>Upload new POD:<input type="file" name="pod_file" accept=".pdf,.jpg,.jpeg,.png"></label>
 </fieldset>

 <button type="submit" name="update_delivery">Update Delivery</button>
</form>


</main>

<script>
/* keep hidden customer field synced */
const ckb   = document.getElementById('charge_customer_ckb');
const paid  = document.getElementById('accessorial_costs_paid');
const cust  = document.getElementById('accessorial_costs');
function sync(){ cust.value = ckb.checked ? (parseFloat(paid.value)||0) : 0; }
ckb.addEventListener('change', sync);
paid.addEventListener('input', ()=>{ if(ckb.checked) sync(); });
</script>
</body>
</html>
<?php $conn->close(); ?>
