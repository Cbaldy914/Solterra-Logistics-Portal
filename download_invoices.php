<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['download_selected'])) {
    http_response_code(400);
    die('Invalid request.');
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$project_id = (int) ($_POST['project_id'] ?? 0);
$account_id = (int) ($_POST['account_id'] ?? 0);
$csrf_token = (string) ($_POST['csrf_token'] ?? '');
$selected_invoices = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['selected_invoices'] ?? [])))));

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    die('Invalid request token.');
}

if (empty($selected_invoices)) {
    http_response_code(400);
    die('No invoices selected.');
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    die('Connection failed');
}

$accessible_project_id = 0;
$accessible_account_id = 0;
$archive_label = 'invoices_' . date('Y-m-d_H-i-s') . '.zip';
$whereClauses = [];
$queryTypes = '';
$queryParams = [];

if ($project_id > 0) {
    if ($role === 'global_admin') {
        $stmt = $conn->prepare("SELECT id FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
    } else {
        $stmt = $conn->prepare("
            SELECT p.id
            FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE p.id = ? AND cau.user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $project_id, $user_id);
    }
    $stmt->execute();
    $stmt->bind_result($accessible_project_id);
    $stmt->fetch();
    $stmt->close();

    if (!$accessible_project_id) {
        $conn->close();
        http_response_code(403);
        die('You do not have access to this project.');
    }

    $whereClauses[] = 'i.project_id = ?';
    $queryTypes .= 'i';
    $queryParams[] = $accessible_project_id;
    $archive_label = 'project_invoices_' . $accessible_project_id . '_' . date('Y-m-d_H-i-s') . '.zip';
} elseif ($account_id > 0) {
    if ($role !== 'global_admin') {
        $stmt = $conn->prepare("
            SELECT account_id
            FROM customer_account_users
            WHERE account_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $account_id, $user_id);
        $stmt->execute();
        $stmt->bind_result($accessible_account_id);
        $stmt->fetch();
        $stmt->close();

        if ((int) $accessible_account_id !== $account_id) {
            $conn->close();
            http_response_code(403);
            die('You do not have access to this account.');
        }
    }

    $whereClauses[] = 'p.account_id = ?';
    $queryTypes .= 'i';
    $queryParams[] = $account_id;
    $archive_label = 'account_invoices_' . $account_id . '_' . date('Y-m-d_H-i-s') . '.zip';
} else {
    $conn->close();
    http_response_code(400);
    die('Project or account scope is required.');
}

$invoicePlaceholders = implode(',', array_fill(0, count($selected_invoices), '?'));
$whereClauses[] = 'i.id IN (' . $invoicePlaceholders . ')';
$queryTypes .= str_repeat('i', count($selected_invoices));
$queryParams = array_merge($queryParams, $selected_invoices);

$sql = "
    SELECT i.invoice_file
    FROM project_invoices i
    JOIN projects p ON i.project_id = p.id
    WHERE " . implode(' AND ', $whereClauses);

$stmt = $conn->prepare($sql);
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$result = $stmt->get_result();

$zip = new ZipArchive();
$temp_zip = tempnam(sys_get_temp_dir(), 'invoices_');
$zip_filename = $temp_zip . '.zip';

if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
    $stmt->close();
    $conn->close();
    exit("Cannot open <$zip_filename>\n");
}

$added_files = 0;
while ($row = $result->fetch_assoc()) {
    $file_path = $row['invoice_file'];
    if (file_exists($file_path) && is_file($file_path)) {
        if ($zip->addFile($file_path, basename($file_path))) {
            $added_files++;
        }
    }
}

$zip->close();
$stmt->close();
$conn->close();

if ($added_files === 0 || !file_exists($zip_filename) || !filesize($zip_filename)) {
    @unlink($zip_filename);
    http_response_code(404);
    die('No accessible invoice files were available for download.');
}

header('Content-Type: application/zip');
header('Content-disposition: attachment; filename=' . basename($archive_label));
header('Content-Length: ' . filesize($zip_filename));
readfile($zip_filename);

unlink($zip_filename);
exit();
?>
