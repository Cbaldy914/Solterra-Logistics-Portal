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
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png'
]);

// Ensure only global_admin can upload a POD
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized");
     exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Delivery ID from query
$delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : 0;

// Graceful error handler
function handleError($message)
{
    error_log("File upload error: " . $message);
    die("An error occurred while processing your request. Please try again later.");
}

// Process upload if POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        handleError("Invalid request. Please try again (CSRF mismatch).");
    }

    // Check file
    if (isset($_FILES['pod_file']) && $_FILES['pod_file']['error'] === 0) {
        try {
            // 1) Validate file size
            if ($_FILES['pod_file']['size'] > MAX_FILE_SIZE) {
                throw new \Exception("File size exceeds the maximum limit of 5MB.");
            }

            // 2) Validate MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $_FILES['pod_file']['tmp_name']);
            finfo_close($finfo);

            if (!array_key_exists($mime_type, ALLOWED_MIME_TYPES)) {
                throw new \Exception("Invalid file type. Only PDF, JPG, and PNG files are allowed.");
            }

            // 3) Validate file extension matches MIME
            $extension = strtolower(pathinfo($_FILES['pod_file']['name'], PATHINFO_EXTENSION));
            if ($extension !== ALLOWED_MIME_TYPES[$mime_type]) {
                throw new \Exception("File extension does not match file type.");
            }

            // 4) Retrieve account name from the project -> account
            //    Handle both project deliveries and warehouse deliveries
            $sql = "
                SELECT d.project_id, c.name AS account_name, d.warehouse_id
                  FROM deliveries d
                  LEFT JOIN projects p ON d.project_id = p.id
                  LEFT JOIN customer_accounts c ON p.account_id = c.id
                 WHERE d.id = ?
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $delivery_id);
            $stmt->execute();
            $stmt->bind_result($project_id, $account_name, $warehouse_id);
            $stmt->fetch();
            $stmt->close();

            // 5) Build the upload directory 
            if ($project_id && $account_name) {
                // Project delivery - use existing structure
                $account_dir = preg_replace('/[^A-Za-z0-9_-]/', '_', $account_name);
                $upload_dir  = "customers/{$account_dir}/projects/{$project_id}/documents/pods/";
            } elseif ($warehouse_id) {
                // Warehouse delivery - use warehouse structure
                $upload_dir = "warehouse_documents/pods/";
            } else {
                throw new \Exception("Could not determine delivery type (no project or warehouse found).");
            }

            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new \Exception("Failed to create upload directory.");
                }
            }

            // 6) Create secure file name
            $original_filename = pathinfo($_FILES['pod_file']['name'], PATHINFO_FILENAME);
            $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '_', $original_filename);
            $sanitized = substr($sanitized, 0, 100);

            $final_filename = $delivery_id . '_' . $sanitized . '.' . $extension;
            $target_path = $upload_dir . $final_filename;

            // 7) Move and update DB
            if (move_uploaded_file($_FILES['pod_file']['tmp_name'], $target_path)) {
                // Update the deliveries table
                $stmtUpd = $conn->prepare("UPDATE deliveries SET proof_of_delivery = ? WHERE id = ?");
                $stmtUpd->bind_param("si", $target_path, $delivery_id);
                $stmtUpd->execute();
                $stmtUpd->close();

                // Redirect based on delivery type
                if ($project_id) {
                    header("Location: manage_deliveries?project_id=$project_id");
                } elseif ($warehouse_id) {
                    header("Location: manage_warehouse_inventory?warehouse_id=$warehouse_id");
                } else {
                    header("Location: dashboard");
                }
                exit();
            } else {
                throw new \Exception("Failed to move uploaded file.");
            }

        } catch (\Exception $e) {
            handleError($e->getMessage());
        }
    } else {
        handleError("No file uploaded or an error occurred (empty file?).");
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Proof of Delivery</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Upload Proof of Delivery</h1>
    <form action="upload_pod?delivery_id=<?php echo htmlspecialchars($delivery_id); ?>" 
          method="post" 
          enctype="multipart/form-data">
        <!-- CSRF Protection -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <input type="file" name="pod_file" accept=".pdf,.jpg,.jpeg,.png" required>
        <small style="display: block; margin: 10px 0; color: #666;">
            Allowed file types: PDF, JPG, PNG. Maximum file size: 5MB.
        </small>
        <button type="submit" name="upload_pod">Upload POD</button>
    </form>
</main>
</body>
</html>
