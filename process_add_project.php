<?php
session_name("logistics_session");
session_start();
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

// Retrieve form data
$user_id = $_POST['user_id'];
$project_name = $_POST['project_name'];
$project_address = $_POST['project_address'];
$estimated_completion_date = $_POST['estimated_completion_date'];

// New: Retrieve solterra_fee (with up to 4 decimal places)
$solterra_fee = isset($_POST['solterra_fee']) ? floatval($_POST['solterra_fee']) : 0.0000;

// Handle image upload
$image_url = null; // Default to null if no image is uploaded
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
                } else {
                    echo "Error uploading the image file.";
                    exit();
                }
            } else {
                echo "File size exceeds the maximum limit of 5MB.";
                exit();
            }
        } else {
            echo "Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed.";
            exit();
        }
    } else {
        echo "Error uploading the file. Error code: " . $_FILES['image_file']['error'];
        exit();
    }
}

// Insert project into the database, now with solterra_fee
$stmt = $conn->prepare("
    INSERT INTO projects (
        user_id,
        project_name,
        project_address,
        estimated_completion_date,
        image_url,
        solterra_fee
    ) VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("issssd",
    $user_id,
    $project_name,
    $project_address,
    $estimated_completion_date,
    $image_url,
    $solterra_fee
);

if ($stmt->execute()) {
    // Get the inserted project ID
    $project_id = $stmt->insert_id;

    // Insert wattage and total order quantities
    if (isset($_POST['wattages']) && isset($_POST['total_orders'])) {
        $wattages = $_POST['wattages'];
        $total_orders = $_POST['total_orders'];

        for ($i = 0; $i < count($wattages); $i++) {
            $wattage = $wattages[$i];
            $total_order = $total_orders[$i];

            $stmt_order = $conn->prepare("
                INSERT INTO project_wattage_orders (project_id, wattage, total_order)
                VALUES (?, ?, ?)
            ");
            $stmt_order->bind_param("idi", $project_id, $wattage, $total_order);
            $stmt_order->execute();
            $stmt_order->close();
        }
    }

    echo "Project added successfully!";
    echo '<br><a href="admin_dashboard">Back to Admin Dashboard</a>';
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
