<?php
// Check if admin
session_name("logistics_session");
session_start();
if ($_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions"; // Your database username
$db_password = "CompanyAdmin!";     // Your database password
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get delivery ID
$delivery_id = intval($_GET['delivery_id']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_FILES['pod_file']) && $_FILES['pod_file']['error'] == 0) {
        // Validate file type and size
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
        $fileType = $_FILES['pod_file']['type'];
        $fileSize = $_FILES['pod_file']['size'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB limit

        if (!in_array($fileType, $allowedTypes)) {
            die("Invalid file type. Only PDF, JPG, and PNG files are allowed.");
        }

        if ($fileSize > $maxFileSize) {
            die("File size exceeds the maximum limit of 5MB.");
        }

        // Fetch project and customer info based on delivery_id
        $stmt = $conn->prepare("
            SELECT d.project_id, p.user_id, u.username 
            FROM deliveries d 
            JOIN projects p ON d.project_id = p.id 
            JOIN users u ON p.user_id = u.id 
            WHERE d.id = ?
        ");
        $stmt->bind_param("i", $delivery_id);
        $stmt->execute();
        $stmt->bind_result($project_id, $user_id, $username);
        $stmt->fetch();
        $stmt->close();

        // Define upload directory
        $upload_dir = "customers/$username/projects/$project_id/documents/pods/";

        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Sanitize file name to prevent directory traversal attacks
        $file_name = basename($_FILES['pod_file']['name']);
        $file_name = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $file_name);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['pod_file']['tmp_name'], $target_file)) {
            // Update the database
            $stmt = $conn->prepare("UPDATE deliveries SET proof_of_delivery = ? WHERE id = ?");
            $stmt->bind_param("si", $target_file, $delivery_id);
            $stmt->execute();
            $stmt->close();

            // Redirect back to manage_deliveries
            header("Location: manage_deliveries?project_id=$project_id");
            exit();
        } else {
            echo "Failed to upload file.";
        }
    } else {
        echo "No file uploaded or an error occurred.";
    }
}
$conn->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logistics Dashboard</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Upload Proof of Delivery</h1>
    <form action="upload_pod?delivery_id=<?php echo $delivery_id; ?>" method="post" enctype="multipart/form-data">
        <input type="file" name="pod_file" accept=".pdf, .jpg, .jpeg, .png" required>
        <button type="submit" name="upload_pod">Upload POD</button>
    </form>
</main>
</body>
</html>