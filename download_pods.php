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

// Verify project ownership
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    die("You do not have access to this project.");
}

// Fetch selected PODs
$placeholders = implode(',', array_fill(0, count($selected_pods), '?'));
$types = str_repeat('i', count($selected_pods) + 1);
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

// Create a ZIP archive of selected PODs
$zip = new ZipArchive();
$zip_filename = tempnam(sys_get_temp_dir(), 'pods_') . '.zip';

if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
    die("Could not create ZIP archive.");
}

while ($row = $result->fetch_assoc()) {
    $file_path = $row['proof_of_delivery'];
    if (file_exists($file_path)) {
        $zip->addFile($file_path, basename($file_path));
    }
}

$zip->close();

// Send the ZIP file to the user
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="PODs_' . $project_name . '.zip"');
header('Content-Length: ' . filesize($zip_filename));
readfile($zip_filename);

// Delete the temporary ZIP file
unlink($zip_filename);

$conn->close();
exit();
?>
