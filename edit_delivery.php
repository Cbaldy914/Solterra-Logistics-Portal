<?php
session_name("logistics_session");
session_start();

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Security Constants
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_MIME_TYPES', [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png'
]);

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'global_admin' && $_SESSION['role'] != 'admin')) {
    header("Location: unauthorized");
    exit();
}

// Validate the delivery ID and project ID
if (!isset($_GET['delivery_id']) || empty($_GET['delivery_id']) || !isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Delivery ID or Project ID is missing.");
}

$delivery_id = intval($_GET['delivery_id']);
$project_id = intval($_GET['project_id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Error handling function
function handleError($message) {
    error_log("File upload error: " . $message);
    die("An error occurred while processing your request. Please try again later.");
}

// Fetch delivery details
$stmt = $conn->prepare("SELECT * FROM deliveries WHERE id = ?");
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$delivery = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$delivery) {
    die("Delivery not found.");
}

$current_status = $delivery['status_of_delivery'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_delivery'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        handleError("Invalid request. Please try again.");
    }

    // Retrieve and sanitize input
    $supplier = $_POST['supplier'];
    $wattage = $_POST['wattage'];
    $status_of_delivery = $_POST['status_of_delivery'];
    $quantity = intval($_POST['quantity']);
    $bol_number = $_POST['bol_number'];
    $anticipated_delivery_date = $_POST['anticipated_delivery_date'];
    
    // Handle empty dates
    $warehouse_arrival_date = !empty($_POST['warehouse_arrival_date']) ? $_POST['warehouse_arrival_date'] : null;
    $actual_delivery_date = !empty($_POST['actual_delivery_date']) ? $_POST['actual_delivery_date'] : null;
    $left_warehouse_date = !empty($_POST['left_warehouse_date']) ? $_POST['left_warehouse_date'] : null;
    
    $freight_cost = floatval($_POST['freight_cost']);
    $accessorial_costs = floatval($_POST['accessorial_costs']);
    $proof_of_delivery = $delivery['proof_of_delivery']; // Keep existing POD if no new file uploaded
    $miles = floatval($_POST['miles']);

    // Handle POD file upload if provided
    if (isset($_FILES['pod_file']) && $_FILES['pod_file']['error'] == 0) {
        try {
            // Validate file size
            if ($_FILES['pod_file']['size'] > MAX_FILE_SIZE) {
                throw new Exception("File size exceeds the maximum limit of 5MB.");
            }

            // Validate MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $_FILES['pod_file']['tmp_name']);
            finfo_close($finfo);

            if (!array_key_exists($mime_type, ALLOWED_MIME_TYPES)) {
                throw new Exception("Invalid file type. Only PDF, JPG, JPEG, and PNG files are allowed.");
            }

            // Validate file extension matches MIME type
            $extension = strtolower(pathinfo($_FILES['pod_file']['name'], PATHINFO_EXTENSION));
            if ($extension !== ALLOWED_MIME_TYPES[$mime_type]) {
                throw new Exception("File extension does not match file type.");
            }

            // Get project and user info for directory structure
            $stmt = $conn->prepare("
                SELECT p.user_id, u.username 
                FROM projects p 
                JOIN users u ON p.user_id = u.id 
                WHERE p.id = ?
            ");
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $stmt->bind_result($user_id, $username);
            $stmt->fetch();
            $stmt->close();

            // Define upload directory with proper permissions
            $upload_dir = "customers/$username/projects/$project_id/documents/pods/";
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }

            // Generate secure filename
            $file_extension = ALLOWED_MIME_TYPES[$mime_type];
            $new_filename = $delivery_id . '_' . time() . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['pod_file']['tmp_name'], $target_file)) {
                $proof_of_delivery = $target_file;
            } else {
                throw new Exception("Failed to move uploaded file.");
            }
        } catch (Exception $e) {
            handleError($e->getMessage());
        }
    }

    // Prepare the SQL statement
    $stmt = $conn->prepare("
        UPDATE deliveries SET 
            supplier = ?, 
            wattage = ?, 
            status_of_delivery = ?, 
            quantity = ?, 
            bol_number = ?, 
            anticipated_delivery_date = ?, 
            warehouse_arrival_date = ?, 
            actual_delivery_date = ?, 
            left_warehouse_date = ?,
            freight_cost = ?,
            accessorial_costs = ?,
            proof_of_delivery = ?,
            miles = ?
        WHERE id = ?
    ");

    // Bind parameters
    $stmt->bind_param(
        "sssisssssddssd",
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
        $miles,
        $delivery_id
    );

    if ($stmt->execute()) {
        echo "<p>Delivery updated successfully.</p>";
        // Redirect back to manage_deliveries
        header("Location: manage_deliveries?project_id=" . $project_id);
        exit();
    } else {
        echo "<p>Error updating delivery: " . htmlspecialchars($stmt->error) . "</p>";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Delivery</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <!-- Include Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Additional styling for better form readability */
        form {
            max-width: 600px;
        }
        form fieldset {
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 20px;
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
            width: 100%;
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
            background-color: #488C9A; /* Secondary blue color */
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
            display: block;
            text-align: center;
            margin-top: 20px;
        }
        .current-pod {
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Edit Delivery for Project ID <?php echo $project_id; ?></h1>
    <form action="edit_delivery.php?delivery_id=<?php echo htmlspecialchars($delivery_id); ?>&project_id=<?php echo htmlspecialchars($project_id); ?>" method="post" enctype="multipart/form-data">
        <!-- CSRF Protection -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        
        <fieldset>
            <legend>Delivery Information</legend>
            <label for="supplier">Supplier:</label>
            <input type="text" name="supplier" value="<?php echo htmlspecialchars($delivery['supplier']); ?>" required>

            <label for="wattage">Wattage:</label>
            <input type="text" name="wattage" value="<?php echo htmlspecialchars($delivery['wattage']); ?>" required>

            <label for="status_of_delivery">Status of Delivery:</label>
            <select name="status_of_delivery" required>
                <option value="Produced" <?php if ($delivery['status_of_delivery'] == 'Produced') echo 'selected'; ?>>Produced</option>
                <option value="In Warehouse" <?php if ($delivery['status_of_delivery'] == 'In Warehouse') echo 'selected'; ?>>In Warehouse</option>
                <option value="Delivered" <?php if ($delivery['status_of_delivery'] == 'Delivered') echo 'selected'; ?>>Delivered</option>
                <option value="Canceled" <?php if ($delivery['status_of_delivery'] == 'Canceled') echo 'selected'; ?>>Canceled</option>
            </select>

            <label for="quantity">Quantity:</label>
            <input type="number" name="quantity" value="<?php echo htmlspecialchars($delivery['quantity']); ?>" required>

            <label for="bol_number">BOL Number:</label>
            <input type="text" name="bol_number" value="<?php echo htmlspecialchars($delivery['bol_number']); ?>" required>
        </fieldset>

        <fieldset>
            <legend>Dates</legend>
            <label for="anticipated_delivery_date">Anticipated Delivery Date:</label>
            <input type="date" name="anticipated_delivery_date" value="<?php echo htmlspecialchars($delivery['anticipated_delivery_date']); ?>" required>

            <label for="warehouse_arrival_date">Warehouse Arrival Date:</label>
            <input type="date" name="warehouse_arrival_date" value="<?php echo htmlspecialchars($delivery['warehouse_arrival_date']); ?>">

            <label for="actual_delivery_date">Actual Delivery Date:</label>
            <input type="date" name="actual_delivery_date" value="<?php echo htmlspecialchars($delivery['actual_delivery_date']); ?>">

            <label for="left_warehouse_date">Left Warehouse Date:</label>
            <input type="date" name="left_warehouse_date" value="<?php echo htmlspecialchars($delivery['left_warehouse_date']); ?>">
        </fieldset>

        <fieldset>
            <legend>Costs</legend>
            <label for="freight_cost">Freight Cost:</label>
            <input type="number" step="0.01" name="freight_cost" value="<?php echo htmlspecialchars($delivery['freight_cost']); ?>">

            <label for="accessorial_costs">Accessorial Costs:</label>
            <input type="number" step="0.01" name="accessorial_costs" value="<?php echo htmlspecialchars($delivery['accessorial_costs']); ?>">

            <label for="miles">Miles:</label>
            <input type="number" step="0.01" name="miles" value="<?php echo htmlspecialchars($delivery['miles']); ?>">
        </fieldset>

        <fieldset>
            <legend>Proof of Delivery (POD)</legend>
            <?php if (!empty($delivery['proof_of_delivery'])): ?>
                <div class="current-pod">
                    <p>Current POD: <a href="view_pod?delivery_id=<?php echo $delivery['id']; ?>" target="_blank">View POD</a></p>
                    <label><input type="checkbox" name="remove_pod" value="1"> Remove current POD</label>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="pod_file">Upload New POD:</label>
                <input type="file" 
                       id="pod_file" 
                       name="pod_file" 
                       accept=".pdf,.jpg,.jpeg,.png">
                <small style="display: block; margin: 10px 0; color: #666;">
                    Allowed file types: PDF, JPG, JPEG, PNG. Maximum file size: 5MB
                </small>
            </div>
        </fieldset>

        <button type="submit" name="update_delivery">Update Delivery</button>
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
