<?php
session_name("logistics_session");
session_start();

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Include document helpers
require_once 'document_helpers.php';

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
    error_log("POD upload error: " . $message);
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
            // Get delivery and project information
            $sql = "
                SELECT d.project_id, d.warehouse_id, d.status_of_delivery, p.project_name
                FROM deliveries d
                LEFT JOIN projects p ON d.project_id = p.id
                WHERE d.id = ?
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $delivery_id);
            $stmt->execute();
            $stmt->bind_result($project_id, $warehouse_id, $delivery_status, $project_name);
            $stmt->fetch();
            $stmt->close();

            if (!$project_id && !$warehouse_id) {
                throw new \Exception("Could not find delivery information.");
            }

            // For warehouse deliveries, we need to get the project_id from the warehouse context
            // or create a default project association - for now, require project_id
            if (!$project_id) {
                throw new \Exception("POD upload requires a project association. Please assign delivery to a project first.");
            }

            // Upload POD using new helper function
            $result = uploadPODDocument(
                $conn, 
                $project_id, 
                $delivery_id, 
                $_FILES['pod_file'], 
                $delivery_status, 
                $warehouse_id
            );

            // Still update the deliveries table for backward compatibility (optional)
            $stmtUpd = $conn->prepare("UPDATE deliveries SET proof_of_delivery = ? WHERE id = ?");
            $stmtUpd->bind_param("si", $result['file_path'], $delivery_id);
            $stmtUpd->execute();
            $stmtUpd->close();

            // Set success message
            $_SESSION['pod_upload_success'] = "POD uploaded successfully!";

            // Redirect based on delivery type
            if ($project_id) {
                header("Location: manage_deliveries?project_id=$project_id");
            } elseif ($warehouse_id) {
                header("Location: manage_warehouse_inventory?warehouse_id=$warehouse_id");
            } else {
                header("Location: dashboard");
            }
            exit();

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

        <input type="file" name="pod_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt,.csv" required>
        <small style="display: block; margin: 10px 0; color: #666;">
            Allowed file types: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX, TXT, CSV. Maximum file size: 50MB.
        </small>
        <button type="submit" name="upload_pod">Upload POD</button>
    </form>
</main>
</body>
</html>
