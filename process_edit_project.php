<?php
session_name("logistics_session");
session_start();

// Enable error reporting for debugging (remove or comment out in production)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions"; // Replace with your database username
$db_password = "CompanyAdmin!";     // Replace with your database password
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Retrieve and sanitize form data
$project_id = intval($_POST['project_id']);
$user_id = intval($_POST['user_id']);
$project_name = $_POST['project_name'];
$project_address = $_POST['project_address'];
$estimated_completion_date = $_POST['estimated_completion_date'];

// Retrieve updated Solterra Fee (per watt) with up to 4 decimals
$solterra_fee = isset($_POST['solterra_fee']) ? floatval($_POST['solterra_fee']) : 0.0000;

// Fetch existing image URL
$stmt = $conn->prepare("SELECT image_url FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($existing_image_url);
$stmt->fetch();
$stmt->close();

// Handle image upload
$image_url = $existing_image_url; // Default to existing image URL
if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] != UPLOAD_ERR_NO_FILE) {
    // Check for upload errors
    if ($_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        // Define allowed file types
        $allowed_types = array('jpg', 'jpeg', 'png', 'gif');
        // Get file info
        $file_name = $_FILES['image_file']['name'];
        $file_tmp = $_FILES['image_file']['tmp_name'];
        $file_size = $_FILES['image_file']['size'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Validate file type
        if (in_array($file_ext, $allowed_types)) {
            // Validate file size (e.g., max 5MB)
            if ($file_size <= 5 * 1024 * 1024) {
                // Generate a unique file name to avoid overwriting
                $new_file_name = uniqid('project_', true) . '.' . $file_ext;
                // Set upload directory
                $upload_dir = 'uploads/'; // Ensure this directory exists and is writable
                // Create the directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                // Move the uploaded file to the upload directory
                if (move_uploaded_file($file_tmp, $upload_dir . $new_file_name)) {
                    // File uploaded successfully
                    $image_url = $upload_dir . $new_file_name;
                    // Optionally, delete the old image file if it exists
                    if (!empty($existing_image_url) && file_exists($existing_image_url)) {
                        unlink($existing_image_url);
                    }
                } else {
                    die("Error uploading the image file.");
                }
            } else {
                die("File size exceeds the maximum limit of 5MB.");
            }
        } else {
            die("Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed.");
        }
    } else {
        die("Error uploading the file. Error code: " . $_FILES['image_file']['error']);
    }
}

// Update project in the database (include solterra_fee)
$stmt = $conn->prepare("
    UPDATE projects
    SET 
        user_id = ?,
        project_name = ?,
        project_address = ?,
        estimated_completion_date = ?,
        image_url = ?,
        solterra_fee = ?
    WHERE id = ?
");
$stmt->bind_param("issssdi",
    $user_id,
    $project_name,
    $project_address,
    $estimated_completion_date,
    $image_url,
    $solterra_fee,
    $project_id
);
if (!$stmt->execute()) {
    die("Error updating project: " . $stmt->error);
}
$stmt->close();

// Handle wattage and total order updates

// Remove wattages
if (isset($_POST['remove_wattages'])) {
    foreach ($_POST['remove_wattages'] as $id) {
        $id = intval($id);
        $stmt_delete = $conn->prepare("DELETE FROM project_wattage_orders WHERE id = ?");
        $stmt_delete->bind_param("i", $id);
        if (!$stmt_delete->execute()) {
            die("Error deleting wattage: " . $stmt_delete->error);
        }
        $stmt_delete->close();
    }
}

// Update existing wattages
if (isset($_POST['wattages']) && isset($_POST['total_orders'])) {
    foreach ($_POST['wattages'] as $id => $wattage) {
        $id = intval($id);
        $wattage = floatval($wattage);
        $total_order = intval($_POST['total_orders'][$id]);

        $stmt_update = $conn->prepare("UPDATE project_wattage_orders SET wattage = ?, total_order = ? WHERE id = ?");
        $stmt_update->bind_param("dii", $wattage, $total_order, $id);
        if (!$stmt_update->execute()) {
            die("Error updating wattage: " . $stmt_update->error);
        }
        $stmt_update->close();
    }
}

// Add new wattages
if (isset($_POST['new_wattages']) && isset($_POST['new_total_orders'])) {
    $new_wattages = $_POST['new_wattages'];
    $new_total_orders = $_POST['new_total_orders'];

    for ($i = 0; $i < count($new_wattages); $i++) {
        $wattage = floatval($new_wattages[$i]);
        $total_order = intval($new_total_orders[$i]);

        $stmt_insert = $conn->prepare("INSERT INTO project_wattage_orders (project_id, wattage, total_order) VALUES (?, ?, ?)");
        $stmt_insert->bind_param("idi", $project_id, $wattage, $total_order);
        if (!$stmt_insert->execute()) {
            die("Error adding new wattage: " . $stmt_insert->error);
        }
        $stmt_insert->close();
    }
}

// Close the database connection
$conn->close();

// Redirect back to the edit project page or admin dashboard
header("Location: admin_dashboard");
exit();
?>
