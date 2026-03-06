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
$csrf_token = (string) ($_POST['csrf_token'] ?? '');

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    die("Invalid request token.");
}

// Make sure at least one POD was selected
if (!isset($_POST['selected_pods']) || empty($_POST['selected_pods'])) {
    die("No PODs selected for download.");
}
$selected_document_ids = [];
$selected_legacy_delivery_ids = [];

foreach ((array) $_POST['selected_pods'] as $selected_pod) {
    $selected_pod = trim((string) $selected_pod);

    if (preg_match('/^document_(\d+)$/', $selected_pod, $matches)) {
        $selected_document_ids[] = (int) $matches[1];
        continue;
    }

    if (preg_match('/^legacy_(\d+)$/', $selected_pod, $matches)) {
        $selected_legacy_delivery_ids[] = (int) $matches[1];
    }
}

$selected_document_ids = array_values(array_unique(array_filter($selected_document_ids)));
$selected_legacy_delivery_ids = array_values(array_unique(array_filter($selected_legacy_delivery_ids)));

if (empty($selected_document_ids) && empty($selected_legacy_delivery_ids)) {
    http_response_code(400);
    die("No valid POD selections were provided.");
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Verify that this user has access to the project
if ($role === 'global_admin') {
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
} else {
    $stmt = $conn->prepare("
        SELECT p.project_name
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
}
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    $conn->close();
    die("You do not have access to this project.");
}

// Prepare a ZIP archive in the system temp directory
$zip = new ZipArchive();
$tmp_zip = tempnam(sys_get_temp_dir(), 'pods_'); // something like /tmp/pods_abcd
$zip_filename = $tmp_zip . '.zip';               // rename with .zip extension

if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
    die("Could not create ZIP archive.");
}

$web_root  = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$subfolder = '/Solterra-Logistics-Portal';

$files_found = 0;
$files_missing = 0;

if (!empty($selected_document_ids)) {
    $placeholders = implode(',', array_fill(0, count($selected_document_ids), '?'));
    $types = str_repeat('i', count($selected_document_ids) + 1);
    $params = array_merge([$project_id], $selected_document_ids);

    $stmt = $conn->prepare("
        SELECT id, file_path, original_file_name
        FROM project_documents
        WHERE project_id = ?
          AND document_type = 'pods'
          AND is_active = 1
          AND id IN ($placeholders)
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $full_path = $row['file_path'];
        if (!file_exists($full_path) || !is_file($full_path)) {
            $files_missing++;
            continue;
        }

        $archive_name = basename($row['original_file_name'] ?: $full_path);
        if ($zip->addFile($full_path, $archive_name)) {
            $files_found++;
        } else {
            $files_missing++;
        }
    }
    $stmt->close();
}

if (!empty($selected_legacy_delivery_ids)) {
    $placeholders = implode(',', array_fill(0, count($selected_legacy_delivery_ids), '?'));
    $types = str_repeat('i', count($selected_legacy_delivery_ids) + 1);
    $params = array_merge([$project_id], $selected_legacy_delivery_ids);

    $stmt = $conn->prepare("
        SELECT id, proof_of_delivery
        FROM deliveries
        WHERE project_id = ?
          AND proof_of_delivery IS NOT NULL
          AND proof_of_delivery != ''
          AND id IN ($placeholders)
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $full_path = $web_root . $subfolder . '/' . ltrim($row['proof_of_delivery'], '/');
        if (!file_exists($full_path) || !is_file($full_path)) {
            $files_missing++;
            continue;
        }

        if ($zip->addFile($full_path, basename($full_path))) {
            $files_found++;
        } else {
            $files_missing++;
        }
    }
    $stmt->close();
}

$zip->close();

if (!filesize($zip_filename)) {
    unlink($zip_filename);
    $conn->close();
    die("None of the selected POD files could be found on the server.");
}

// Send the ZIP to the browser
// Tip: remove any existing output buffering just in case
if (ob_get_level()) {
    ob_end_clean();
}

// Set a cookie to signal download completion for the current page JS.
setcookie('download_complete', '1', [
    'expires' => time() + 60,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => false,
    'samesite' => 'Lax',
]);

header('Content-Type: application/zip');
// e.g. "PODs_ProjectName.zip"
header('Content-Disposition: attachment; filename="PODs_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $project_name) . '.zip"');
readfile($zip_filename);

// Clean up
unlink($zip_filename);
$conn->close();
exit();
