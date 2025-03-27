<?php
session_name("logistics_session");
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Retrieve project_id either from GET or POST
$project_id = null;
if (isset($_GET['project_id']) && !empty($_GET['project_id'])) {
    $project_id = intval($_GET['project_id']);
} elseif (isset($_POST['project_id']) && !empty($_POST['project_id'])) {
    $project_id = intval($_POST['project_id']);
}

if (!$project_id) {
    die("Project ID is missing.");
}

// Include configuration file
require_once '../config.php';

// Get database connection using the new function
$conn = getDBConnection();
if (!$conn) {
    die("Unable to connect to database. Please try again later.");
}

// Fetch the project name for the header
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($project_name);
if (!$stmt->fetch()) {
    die("Project not found.");
}
$stmt->close();

// Process form submission (combined add_delivery & process_add_delivery)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_delivery'])) {
    // Retrieve and sanitize input
    $supplier = mysqli_real_escape_string($conn, $_POST['supplier']);
    $wattage = mysqli_real_escape_string($conn, $_POST['wattage']);
    $status_of_delivery = mysqli_real_escape_string($conn, $_POST['status_of_delivery']);
    $quantity = intval($_POST['quantity']);
    $bol_number = mysqli_real_escape_string($conn, $_POST['bol_number']);
    $anticipated_delivery_date = $_POST['anticipated_delivery_date'];
    $warehouse_arrival_date = !empty($_POST['warehouse_arrival_date']) ? $_POST['warehouse_arrival_date'] : NULL;
    $actual_delivery_date = !empty($_POST['actual_delivery_date']) ? $_POST['actual_delivery_date'] : NULL;
    $left_warehouse_date = !empty($_POST['left_warehouse_date']) ? $_POST['left_warehouse_date'] : NULL;
    $freight_cost = (isset($_POST['freight_cost']) && $_POST['freight_cost'] !== '') ? floatval($_POST['freight_cost']) : NULL;
    $accessorial_costs = (isset($_POST['accessorial_costs']) && $_POST['accessorial_costs'] !== '') ? floatval($_POST['accessorial_costs']) : NULL;
    $miles = (isset($_POST['miles']) && $_POST['miles'] !== '') ? floatval($_POST['miles']) : NULL;
    
    // Handle Proof of Delivery (POD) file upload
    $proof_of_delivery = NULL;
    if (isset($_FILES['proof_of_delivery']) && $_FILES['proof_of_delivery']['error'] == UPLOAD_ERR_OK) {
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $file_tmp_path = $_FILES['proof_of_delivery']['tmp_name'];
        $file_name = $_FILES['proof_of_delivery']['name'];
        $file_size = $_FILES['proof_of_delivery']['size'];
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Validate file extension
        if (!in_array($file_extension, $allowed_extensions)) {
            echo "<p>Invalid file type. Only PDF, JPG, JPEG, and PNG files are allowed.</p>";
            exit();
        }

        // Validate file size (max 5MB)
        if ($file_size > 5 * 1024 * 1024) {
            echo "<p>File size exceeds the maximum limit of 5MB.</p>";
            exit();
        }

        // Define the upload directory (ensure it exists and is writable)
        $upload_dir = 'uploads/pods/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Generate a unique file name to avoid collisions
        $new_file_name = 'pod_' . time() . '_' . uniqid() . '.' . $file_extension;
        $dest_path = $upload_dir . $new_file_name;

        // Move the uploaded file
        if (move_uploaded_file($file_tmp_path, $dest_path)) {
            $proof_of_delivery = $dest_path;
        } else {
            echo "<p>Error uploading the file. Please try again.</p>";
            exit();
        }
    }

    // Prepare and execute the INSERT statement
    // The fields are: project_id, supplier, wattage, status_of_delivery, quantity, bol_number, anticipated_delivery_date,
    // warehouse_arrival_date, actual_delivery_date, left_warehouse_date, freight_cost, accessorial_costs, proof_of_delivery, miles
    $stmt = $conn->prepare("INSERT INTO deliveries 
        (project_id, supplier, wattage, status_of_delivery, quantity, bol_number, anticipated_delivery_date, warehouse_arrival_date, actual_delivery_date, left_warehouse_date, freight_cost, accessorial_costs, proof_of_delivery, miles)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    // Bind parameters:
    // "i" for integer, "s" for string, "d" for double.
    // The type string below corresponds to: i, s, s, s, i, s, s, s, s, s, d, d, s, d
    $stmt->bind_param("isssisssssddsd", 
        $project_id, 
        $supplier, 
        $wattage, 
        $status_of_delivery, 
        $quantity, 
        $bol_number, 
        $anticipated_delivery_date, 
        $warehouse_arrival_date, 
        $actual_delivery_date, 
        $left_warehouse_date, 
        $freight_cost, 
        $accessorial_costs, 
        $proof_of_delivery, 
        $miles
    );

    if ($stmt->execute()) {
        header("Location: manage_deliveries?project_id=" . $project_id);
        exit();
    } else {
        echo "<p>Error adding delivery: " . htmlspecialchars($stmt->error) . "</p>";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Delivery for <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <!-- Include Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        form {
            max-width: 600px;
        }
        form fieldset {
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        form fieldset legend {
            font-weight: bold;
            padding: 0 10px;
        }
        form label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        form input[type="text"],
        form input[type="number"],
        form input[type="date"],
        form select,
        form input[type="file"] {
            width: 95%;
            padding: 8px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        form input[type="checkbox"] {
            margin-right: 5px;
        }
        form button,
        form input[type="submit"] {
            background-color: #488C9A;
            color: #fff;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        form button:hover,
        form input[type="submit"]:hover {
            background-color: #3A6E7F;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Add Delivery for <?php echo htmlspecialchars($project_name); ?></h1>
    <form action="add_delivery.php?project_id=<?php echo $project_id; ?>" method="post" enctype="multipart/form-data">
        <!-- Include a hidden field so project_id is preserved in POST -->
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
        <fieldset>
            <legend>Delivery Details</legend>
            <label for="supplier">Supplier:</label>
            <input type="text" name="supplier" required>

            <label for="wattage">Wattage:</label>
            <input type="text" name="wattage" required>

            <label for="status_of_delivery">Status of Delivery:</label>
            <select name="status_of_delivery" required>
                <option value="Produced">Produced</option>
                <option value="In Warehouse">In Warehouse</option>
                <option value="Delivered">Delivered</option>
                <option value="Canceled">Canceled</option>
            </select>

            <label for="quantity">Quantity:</label>
            <input type="number" name="quantity" required>

            <label for="bol_number">BOL Number:</label>
            <input type="text" name="bol_number" required>
        </fieldset>

        <fieldset>
            <legend>Dates</legend>
            <label for="anticipated_delivery_date">Anticipated Delivery Date:</label>
            <input type="date" name="anticipated_delivery_date" required>

            <label for="warehouse_arrival_date">Warehouse Arrival Date:</label>
            <input type="date" name="warehouse_arrival_date">

            <label for="actual_delivery_date">Actual Delivery Date:</label>
            <input type="date" name="actual_delivery_date">

            <label for="left_warehouse_date">Left Warehouse Date:</label>
            <input type="date" name="left_warehouse_date">
        </fieldset>

        <fieldset>
            <legend>Costs</legend>
            <label for="freight_cost">Freight Cost:</label>
            <input type="number" step="0.01" name="freight_cost">

            <label for="accessorial_costs">Accessorial Costs:</label>
            <input type="number" step="0.01" name="accessorial_costs">

            <label for="miles">Miles:</label>
            <input type="number" step="0.01" name="miles">
        </fieldset>

        <fieldset>
            <legend>Proof of Delivery (POD)</legend>
            <label for="proof_of_delivery">Upload POD:</label>
            <input type="file" name="proof_of_delivery" accept=".pdf,.jpg,.jpeg,.png">
        </fieldset>

        <input type="submit" name="add_delivery" value="Add Delivery Entry">
    </form>
    <div class="back-link">
        <a href="manage_deliveries?project_id=<?php echo $project_id; ?>">Back to Manage Deliveries</a>
    </div>
</main>
</body>
</html>
<?php
$conn->close();
?>
