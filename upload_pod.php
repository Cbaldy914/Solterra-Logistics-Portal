<?php
// Check if admin
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

if ($_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Get delivery ID
$delivery_id = intval($_GET['delivery_id']);

// Error handling function
function handleError($message) {
    error_log("File upload error: " . $message);
    die("An error occurred while processing your request. Please try again later.");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        handleError("Invalid request. Please try again.");
    }

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

            // Define upload directory with proper permissions
            $upload_dir = "customers/$username/projects/$project_id/documents/pods/";
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }

            // Get original filename and sanitize it
            $original_filename = pathinfo($_FILES['pod_file']['name'], PATHINFO_FILENAME);
            // Remove any potentially dangerous characters
            $sanitized_filename = preg_replace('/[^A-Za-z0-9\-_\.]/', '_', $original_filename);
            // Limit filename length to prevent issues
            $sanitized_filename = substr($sanitized_filename, 0, 100);
            
            // Generate secure filename with original name
            $file_extension = ALLOWED_MIME_TYPES[$mime_type];
            $new_filename = $delivery_id . '_' . $sanitized_filename . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;

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
                throw new Exception("Failed to move uploaded file.");
            }
        } catch (Exception $e) {
            handleError($e->getMessage());
        }
    } else {
        handleError("No file uploaded or an error occurred.");
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
    <form action="upload_pod?delivery_id=<?php echo htmlspecialchars($delivery_id); ?>" method="post" enctype="multipart/form-data">
        <!-- CSRF Protection -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        
        <input type="file" 
               name="pod_file" 
               accept=".pdf,.jpg,.jpeg,.png" 
               required>
        <small style="display: block; margin: 10px 0; color: #666;">
            Allowed file types: PDF, JPG, JPEG, PNG. Maximum file size: 5MB
        </small>
        <button type="submit" name="upload_pod">Upload POD</button>
    </form>
</main>
</body>
</html>