<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if (!isset($_POST['download_selected'])) {
    die("Invalid access.");
}

$project_id = intval($_POST['project_id']);
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Make sure at least one POD was selected
if (!isset($_POST['selected_pods']) || empty($_POST['selected_pods'])) {
    die("No PODs selected for download.");
}
$selected_pods = array_map('intval', $_POST['selected_pods']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Account access control
$account_id_for_admin = null;
$is_global_admin = ($role === 'global_admin');

if ($role === 'admin') {
    // Get the account_id for this admin user
    $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $user_id);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($account_id_for_admin);
        $stmtAdminAcc->fetch();
        $stmtAdminAcc->close();
    }
}

// Verify that this user has access to the project
$project_access_sql = "SELECT project_name FROM projects WHERE id = ?";
if ($role === 'admin' && $account_id_for_admin) {
    $project_access_sql .= " AND account_id = ?";
}

$stmt = $conn->prepare($project_access_sql);
if ($role === 'admin' && $account_id_for_admin) {
    $stmt->bind_param("ii", $project_id, $account_id_for_admin);
} else {
    $stmt->bind_param("i", $project_id);
}
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    die("You do not have access to this project.");
}

// Fetch the proof_of_delivery paths for the selected deliveries
$placeholders = implode(',', array_fill(0, count($selected_pods), '?'));
$types = str_repeat('i', count($selected_pods));
$params = $selected_pods;

$stmt = $conn->prepare("
    SELECT id, proof_of_delivery
    FROM deliveries
    WHERE id IN ($placeholders) AND proof_of_delivery IS NOT NULL
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

error_log("Database query returned " . $result->num_rows . " POD records for IDs: " . implode(', ', $selected_pods));

if ($result->num_rows == 0) {
    die("No PODs found for download.");
}

// Prepare a ZIP archive in the system temp directory
$zip = new ZipArchive();
$tmp_zip = tempnam(sys_get_temp_dir(), 'pods_'); // something like /tmp/pods_abcd
$zip_filename = $tmp_zip . '.zip';               // rename with .zip extension

if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
    die("Could not create ZIP archive.");
}

// -- IMPORTANT: Adjust these to match your view_pod logic --
// If your actual PODs are in /public_html/Solterra-Logistics-Portal/customers/... 
// then:
$web_root  = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$subfolder = '/Solterra-Logistics-Portal'; // Adjust/remove if needed

// Add each valid file to the ZIP
$files_found = 0;
$files_missing = 0;
while ($row = $result->fetch_assoc()) {
    $pod_path = $row['proof_of_delivery']; // e.g. "customers/DESRI/projects/24/documents/pods/..."
    
    // Use the same path construction as view_pod.php
    $full_path = $web_root . $subfolder . '/' . ltrim($pod_path, '/');
    
    // Debug: Log the paths being checked
    error_log("Checking POD file: " . $full_path);
    
    // Only add the file if it physically exists AND is actually a file (not a directory)
    if (file_exists($full_path) && is_file($full_path)) {
        // The second parameter to addFile() is the name inside the ZIP
        $zip->addFile($full_path, basename($full_path));
        $files_found++;
        error_log("Added to ZIP: " . $full_path);
    } else {
        // Log missing files or directories for debugging
        if (file_exists($full_path) && is_dir($full_path)) {
            error_log("POD path is a directory (skipped): " . $full_path);
        } else {
            error_log("POD file not found for download: " . $full_path);
        }
        $files_missing++;
    }
}

$zip->close();

// Log summary
error_log("ZIP creation summary: $files_found files found, $files_missing files missing");

// Double-check if the ZIP is actually non-empty
if (!filesize($zip_filename)) {
    // If it's 0 bytes, that means no files were successfully added
    unlink($zip_filename);
    die("None of the selected files could be found on the server. Found: $files_found, Missing: $files_missing. Check server error logs for detailed paths.");
}

// Send the ZIP to the browser
// Tip: remove any existing output buffering just in case
if (ob_get_level()) {
    ob_end_clean();
}

// Set a cookie to signal download completion (expires in 1 minute)
setcookie('download_complete', '1', time() + 60, '/');

header('Content-Type: application/zip');
// e.g. "PODs_ProjectName.zip"
header('Content-Disposition: attachment; filename="PODs_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $project_name) . '.zip"');
// We skip Content-Length to avoid potential truncation
// header('Content-Length: ' . filesize($zip_filename));

readfile($zip_filename);

// Clean up
unlink($zip_filename);
$conn->close();
exit();
