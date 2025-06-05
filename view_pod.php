<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Basic role check: If user is "global_admin" or "admin," they can see all
$allowed_admin_roles = ['global_admin', 'admin'];
$current_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'unknown';

$delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : 0;

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

/**
 * We'll fetch:
 *  d.proof_of_delivery,
 *  p.account_id,
 *  c.name as account_name,
 *  p.id as project_id,
 *  d.warehouse_id
 */
$sql = "
    SELECT d.proof_of_delivery,
           p.account_id,
           c.name       AS account_name,
           p.id         AS project_id,
           d.warehouse_id
      FROM deliveries d
      LEFT JOIN projects p            ON d.project_id = p.id
      LEFT JOIN customer_accounts c   ON p.account_id = c.id
     WHERE d.id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$stmt->bind_result($pod_path, $account_id, $account_name, $project_id, $warehouse_id);
$stmt->fetch();
$stmt->close();

// If there's no record or no POD, bail out
if (empty($pod_path)) {
    die("POD file not found or invalid delivery ID.");
}

/**
 * If user is NOT admin/global_admin, we must verify they belong to this same account
 * so that they have permission to view the POD.
 * Skip this check for warehouse deliveries (no account association)
 */
if (!in_array($current_role, $allowed_admin_roles) && $account_id) {
    // Check if user is in the same account in the bridging table
    $checkSql = "
        SELECT COUNT(*)
          FROM customer_account_users
         WHERE account_id = ?
           AND user_id    = ?
         LIMIT 1
    ";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ii", $account_id, $_SESSION['user_id']);
    $checkStmt->execute();
    $checkStmt->bind_result($countAccounts);
    $checkStmt->fetch();
    $checkStmt->close();

    if ($countAccounts < 1) {
        // The user does not belong to this account => no access
        die("Access denied: You do not belong to this account.");
    }
} elseif (!in_array($current_role, $allowed_admin_roles) && $warehouse_id) {
    // For warehouse deliveries, only admin/global_admin can view PODs
    die("Access denied: Only administrators can view warehouse PODs.");
}

// Now serve the file if it exists
// The code below is the same as you had before, adjusted for $pod_path
$conn->close();

// For the local path, you used "web_root + subfolder + pod_path"
$web_root  = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$subfolder = '/Solterra-Logistics-Portal'; // Adjust if needed
$full_path = $web_root . $subfolder . '/' . ltrim($pod_path, '/');

// Serve the file
if (file_exists($full_path)) {
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Determine Content-Type from extension
    $file_extension = strtolower(pathinfo($pod_path, PATHINFO_EXTENSION));
    switch ($file_extension) {
        case 'pdf':
            $content_type = 'application/pdf';
            break;
        case 'jpg':
        case 'jpeg':
            $content_type = 'image/jpeg';
            break;
        case 'png':
            $content_type = 'image/png';
            break;
        default:
            die("Unsupported file type: " . htmlspecialchars($file_extension));
    }

    header('Content-Type: ' . $content_type);
    header('Content-Disposition: inline; filename="' . basename($pod_path) . '"');
    // Optionally skip Content-Length for dynamic or partial content issues
    readfile($full_path);
    exit();
} else {
    echo "File not found at path: " . htmlspecialchars($full_path);
    // Log errors if needed
    error_log("POD file not found: " . $full_path);
    exit();
}
?>
