<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['download_selected'])) {
    $user_id = $_SESSION['user_id'];
    $project_id = intval($_POST['project_id']);
    $selected_invoices = $_POST['selected_invoices'];

    if (empty($selected_invoices)) {
        die("No invoices selected.");
    }

    // Verify that the project belongs to the user
    $servername = "localhost";
    $db_username = "SolterraSolutions";
    $db_password = "CompanyAdmin!";
    $dbname = "solterra_portal";

    $conn = new mysqli($servername, $db_username, $db_password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($project_exists);
    $stmt->fetch();
    $stmt->close();

    if (!$project_exists) {
        die("You do not have access to this project.");
    }

    // Fetch the invoice files
    $placeholders = implode(',', array_fill(0, count($selected_invoices), '?'));
    $types = str_repeat('i', count($selected_invoices) + 1);
    $params = array_merge([$types, $project_id], $selected_invoices);

    $stmt = $conn->prepare("SELECT invoice_file FROM project_invoices WHERE project_id = ? AND id IN ($placeholders)");
    $stmt->bind_param(...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    // Create a zip file of the selected invoices
    $zip = new ZipArchive();
    $zip_filename = 'invoices_' . time() . '.zip';

    if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
        exit("Cannot open <$zip_filename>\n");
    }

    while ($row = $result->fetch_assoc()) {
        $file_path = $row['invoice_file'];
        if (file_exists($file_path)) {
            $zip->addFile($file_path, basename($file_path));
        }
    }

    $zip->close();

    // Send the zip file to the browser for download
    header('Content-Type: application/zip');
    header('Content-disposition: attachment; filename=' . basename($zip_filename));
    header('Content-Length: ' . filesize($zip_filename));
    readfile($zip_filename);

    // Delete the zip file after sending it to the browser
    unlink($zip_filename);
    exit();
}
?>
