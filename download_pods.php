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

// Verify that this user owns the project
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    die("You do not have access to this project.");
}

// Fetch the proof_of_delivery paths for the selected deliveries
$placeholders = implode(',', array_fill(0, count($selected_pods), '?'));
$types = str_repeat('i', count($selected_pods) + 1); // all 'i' plus 1 extra for project_id
$params = array_merge($selected_pods, [$project_id]);

$stmt = $conn->prepare("
    SELECT proof_of_delivery
    FROM deliveries
    WHERE id IN ($placeholders) AND project_id = ?
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

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
while ($row = $result->fetch_assoc()) {
    $relative_path = $row['proof_of_delivery']; // e.g. "customers/DESRI/projects/24/documents/pods/..."
    $full_path = $web_root . $subfolder . '/' . ltrim($relative_path, '/');
    
    // Only add the file if it physically exists
    if (file_exists($full_path)) {
        // The second parameter to addFile() is the name inside the ZIP
        $zip->addFile($full_path, basename($full_path));
    }
}

$zip->close();

// Double-check if the ZIP is actually non-empty
if (!filesize($zip_filename)) {
    // If it's 0 bytes, that means no files were successfully added
    unlink($zip_filename);
    die("None of the selected files could be found on the server.");
}

// Send the ZIP to the browser
// Tip: remove any existing output buffering just in case
if (ob_get_level()) {
    ob_end_clean();
}

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
