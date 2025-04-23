<?php
session_name("logistics_session");
session_start();

/* ─────────────────────────────────────────  SECURITY  ───────────────────────────────────────── */
if (!isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['global_admin', 'admin'])) {
    header("Location: unauthorized");
    exit();
}

/* ──────────────────────────────────────  PROJECT CONTEXT  ───────────────────────────────────── */
$project_id = null;
if (!empty($_GET['project_id']))      $project_id = (int)$_GET['project_id'];
if (!$project_id && !empty($_POST['project_id'])) $project_id = (int)$_POST['project_id'];
if (!$project_id)  die("Project ID is missing.");

/* ─────────────────────────────────────  DB CONNECTION  ─────────────────────────────────────── */
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) die("Unable to connect to database.");

/* Fetch project name for heading */
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($project_name);
if (!$stmt->fetch()) die("Project not found.");
$stmt->close();

/* ───────────────────────────────  FORM SUBMISSION (ADD)  ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_delivery'])) {

    /* ── sanitize basics ─────────────────────────────────────────────────────────────────────── */
    $supplier           = $conn->real_escape_string($_POST['supplier']          ?? '');
    $wattage            = $conn->real_escape_string($_POST['wattage']           ?? '');
    $status_of_delivery = $conn->real_escape_string($_POST['status_of_delivery']?? '');
    $quantity           = (int)$_POST['quantity'];
    $bol_number         = $conn->real_escape_string($_POST['bol_number']        ?? '');

    $anticipated_delivery_date = $_POST['anticipated_delivery_date']            ?? null;
    $warehouse_arrival_date    = $_POST['warehouse_arrival_date']               ?: null;
    $actual_delivery_date      = $_POST['actual_delivery_date']                 ?: null;
    $left_warehouse_date       = $_POST['left_warehouse_date']                  ?: null;

    $freight_cost        = ($_POST['freight_cost']        !== '') ? (float)$_POST['freight_cost']        : null;
    $accessorial_paid    = ($_POST['accessorial_costs_paid'] !== '') ? (float)$_POST['accessorial_costs_paid'] : 0.0;
    $accessorial_charged = ($_POST['accessorial_costs']     !== '') ? (float)$_POST['accessorial_costs']     : 0.0;
    $miles               = ($_POST['miles']               !== '') ? (float)$_POST['miles']               : null;

    /* ── POD upload (unchanged) ─────────────────────────────────────────────────────────────── */
    $proof_of_delivery = null;
    if (isset($_FILES['proof_of_delivery']) && $_FILES['proof_of_delivery']['error'] === UPLOAD_ERR_OK) {

        $allowed_ext = ['pdf','jpg','jpeg','png'];
        $tmp   = $_FILES['proof_of_delivery']['tmp_name'];
        $name  = $_FILES['proof_of_delivery']['name'];
        $size  = $_FILES['proof_of_delivery']['size'];
        $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext,$allowed_ext))  die("Invalid file type.");
        if ($size > 5*1024*1024)           die("File larger than 5 MB.");

        $upload_dir = 'uploads/pods/';
        if (!is_dir($upload_dir)) mkdir($upload_dir,0755,true);

        $new_name = 'pod_'.time().'_'.uniqid().'.'.$ext;
        $dest     = $upload_dir.$new_name;
        if (!move_uploaded_file($tmp,$dest)) die("File upload failed.");

        $proof_of_delivery = $dest;
    }

    /* ── insert row ─────────────────────────────────────────────────────────────────────────── */
    $sql = "INSERT INTO deliveries
            (project_id, supplier, wattage, status_of_delivery, quantity, bol_number,
             anticipated_delivery_date, warehouse_arrival_date, actual_delivery_date,
             left_warehouse_date, freight_cost, accessorial_costs_paid,
             accessorial_costs, proof_of_delivery, miles)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $stmt = $conn->prepare($sql) or die("Prepare failed: ".$conn->error);
    /* type string: i s s s i s s s s s d d d s d  →  iss s i s s s s s d d d s d */
    $stmt->bind_param(
        "isssisssssdddsd",
        $project_id,               // i
        $supplier,                 // s
        $wattage,                  // s
        $status_of_delivery,       // s
        $quantity,                 // i
        $bol_number,               // s
        $anticipated_delivery_date,// s
        $warehouse_arrival_date,   // s
        $actual_delivery_date,     // s
        $left_warehouse_date,      // s
        $freight_cost,             // d
        $accessorial_paid,         // d
        $accessorial_charged,      // d
        $proof_of_delivery,        // s
        $miles                     // d
    );

    if ($stmt->execute()) {
        header("Location: manage_deliveries?project_id=".$project_id);
        exit();
    }
    echo "<p>Error: ".htmlspecialchars($stmt->error)."</p>";
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Delivery – <?php echo htmlspecialchars($project_name);?></title>
<link rel="stylesheet" href="portal.css">
<link rel="icon" href="pictures/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    form{max-width:600px}
    fieldset{border:1px solid #ddd;padding:15px;margin-bottom:20px;border-radius:5px}
    legend{font-weight:bold}
    label{display:block;margin-bottom:5px;font-weight:500}
    input[type=text],input[type=number],input[type=date],select,input[type=file]
        {width:95%;padding:8px;margin-bottom:15px;border:1px solid #ccc;border-radius:4px}
    button,input[type=submit]{background:#488C9A;color:#fff;padding:10px 15px;border:none;border-radius:4px;cursor:pointer}
    button:hover,input[type=submit]:hover{background:#3A6E7F}
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
<h1>Add Delivery – <?php echo htmlspecialchars($project_name);?></h1>

<form action="add_delivery.php?project_id=<?php echo $project_id;?>" method="post" enctype="multipart/form-data">
<input type="hidden" name="project_id" value="<?php echo $project_id;?>">

<!-- Delivery details -->
<fieldset>
 <legend>Delivery Details</legend>
 <label>Supplier:<input type="text" name="supplier" required></label>
 <label>Wattage:<input type="text" name="wattage" required></label>
 <label>Status of Delivery:
   <select name="status_of_delivery" required>
     <option value="Produced">Produced</option>
     <option value="In Warehouse">In Warehouse</option>
     <option value="Delivered">Delivered</option>
     <option value="Canceled">Canceled</option>
   </select>
 </label>
 <label>Quantity:<input type="number" name="quantity" required></label>
 <label>BOL Number:<input type="text" name="bol_number" required></label>
</fieldset>

<!-- Dates -->
<fieldset>
 <legend>Dates</legend>
 <label>Anticipated Delivery Date:<input type="date" name="anticipated_delivery_date" required></label>
 <label>Warehouse Arrival Date:<input type="date" name="warehouse_arrival_date"></label>
 <label>Actual Delivery Date:<input type="date" name="actual_delivery_date"></label>
 <label>Left Warehouse Date:<input type="date" name="left_warehouse_date"></label>
</fieldset>

<!-- Costs -->
<fieldset>
 <legend>Costs</legend>

 <label>Freight Cost:<input type="number" step="0.01" name="freight_cost"></label>

 <label>Accessorial Cost (what we pay carrier):
   <input type="number" step="0.01" id="accessorial_costs_paid" name="accessorial_costs_paid">
 </label>

 <label style="display:flex;align-items:center;margin-bottom:15px">
   <input type="checkbox" id="charge_customer_ckb">
   Charge Customer?
 </label>

 <!-- hidden customer‑facing amount -->
 <input type="hidden" id="accessorial_costs" name="accessorial_costs" value="0">

 <label>Miles:<input type="number" step="0.01" name="miles"></label>
</fieldset>

<!-- POD -->
<fieldset>
 <legend>Proof of Delivery (POD)</legend>
 <label>Upload POD:<input type="file" name="proof_of_delivery" accept=".pdf,.jpg,.jpeg,.png"></label>
</fieldset>

<input type="submit" name="add_delivery" value="Add Delivery Entry">
</form>

<div class="back-link">
    <a href="manage_deliveries?project_id=<?php echo $project_id;?>">&larr; Back to Manage Deliveries</a>
</div>
</main>

<script>
/* Copy paid amount to customer field when “Charge Customer?” is toggled */
const ckb = document.getElementById('charge_customer_ckb');
const paid = document.getElementById('accessorial_costs_paid');
const charged = document.getElementById('accessorial_costs');

function syncCustomerField(){
    charged.value = ckb.checked ? (parseFloat(paid.value)||0) : 0;
}

ckb.addEventListener('change', syncCustomerField);
paid.addEventListener('input', ()=>{ if(ckb.checked) syncCustomerField(); });
</script>
</body>
</html>
<?php $conn->close(); ?>
