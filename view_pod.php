<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$current_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'unknown';

$delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : 0;

require_once '../config.php';
require_once 'document_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

/**
 * First check for PODs in the new project_documents table,
 * then fall back to legacy deliveries.proof_of_delivery field
 */

// Check for POD in new project_documents table first
$sql_new = "
    SELECT pd.file_path,
           pd.original_file_name,
           p.account_id,
           w.account_id AS warehouse_account_id,
           c.name       AS account_name,
           p.id         AS project_id,
           d.warehouse_id
      FROM project_documents pd
      JOIN deliveries d           ON pd.delivery_id = d.id
      LEFT JOIN projects p        ON pd.project_id = p.id
      LEFT JOIN warehouses w      ON d.warehouse_id = w.id
      LEFT JOIN customer_accounts c ON p.account_id = c.id
     WHERE pd.delivery_id = ?
       AND pd.document_type = 'pods'
       AND (pd.document_sub_type = 'Warehouse POD' OR pd.document_sub_type = 'Project POD')
     ORDER BY pd.uploaded_at DESC
     LIMIT 1
";
$stmt_new = $conn->prepare($sql_new);
$stmt_new->bind_param("i", $delivery_id);
$stmt_new->execute();
$stmt_new->bind_result($pod_path, $original_filename, $account_id, $warehouse_account_id, $account_name, $project_id, $warehouse_id);
$found_new_pod = $stmt_new->fetch();
$stmt_new->close();

// If no POD found in new system, check legacy system
if (!$found_new_pod) {
    $sql_legacy = "
        SELECT d.proof_of_delivery,
               '',                  -- no original filename in legacy
               p.account_id,
               w.account_id AS warehouse_account_id,
               c.name       AS account_name,
               p.id         AS project_id,
               d.warehouse_id
          FROM deliveries d
          LEFT JOIN projects p            ON d.project_id = p.id
          LEFT JOIN warehouses w          ON d.warehouse_id = w.id
          LEFT JOIN customer_accounts c   ON p.account_id = c.id
         WHERE d.id = ?
           AND d.proof_of_delivery IS NOT NULL
           AND d.proof_of_delivery != ''
    ";
    $stmt_legacy = $conn->prepare($sql_legacy);
    $stmt_legacy->bind_param("i", $delivery_id);
    $stmt_legacy->execute();
    $stmt_legacy->bind_result($pod_path, $original_filename, $account_id, $warehouse_account_id, $account_name, $project_id, $warehouse_id);
    $found_legacy_pod = $stmt_legacy->fetch();
    $stmt_legacy->close();
    
    if (!$found_legacy_pod) {
        die("POD file not found or invalid delivery ID.");
    }
} else {
    // If we found a new POD, set the original filename for better display
    if (empty($original_filename)) {
        $original_filename = basename($pod_path);
    }
}

// If there's no POD path at this point, bail out
if (empty($pod_path)) {
    die("POD file not found or invalid delivery ID.");
}

$access_account_id = (int) ($account_id ?: $warehouse_account_id);

if ($current_role !== 'global_admin' && $access_account_id > 0) {
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
        die("Access denied: You do not belong to this account.");
    }
} elseif ($current_role !== 'global_admin' && $warehouse_id) {
    die("Access denied: This POD is not associated with an accessible account.");
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
    // Use original filename if available, otherwise use basename of path
    $display_filename = !empty($original_filename) ? $original_filename : basename($pod_path);
    header('Content-Disposition: inline; filename="' . $display_filename . '"');
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
