<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Validate the invoice ID
if (!isset($_GET['invoice_id']) || empty($_GET['invoice_id'])) {
    die("Invoice ID is missing.");
}

$invoice_id = intval($_GET['invoice_id']);
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role']; // Assuming you have role information stored in the session

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch the invoice details
$stmt = $conn->prepare("
    SELECT pi.invoice_file, p.user_id
    FROM project_invoices pi
    JOIN projects p ON pi.project_id = p.id
    WHERE pi.id = ?
");
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$stmt->bind_result($invoice_file, $project_user_id);
$stmt->fetch();
$stmt->close();

// Check if the invoice exists
if (empty($invoice_file)) {
    die("Invoice not found.");
}

// Verify that the user has access to the invoice
if ($role !== 'admin' && $project_user_id != $user_id) {
    die("You do not have access to this invoice.");
}

// Ensure the file exists on the server
if (!file_exists($invoice_file)) {
    die("File not found on the server.");
}

// Get the file's MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $invoice_file);
finfo_close($finfo);

// Serve the file for download or inline display
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime_type);
header('Content-Disposition: inline; filename="' . basename($invoice_file) . '"');
header('Content-Length: ' . filesize($invoice_file));
header('Cache-Control: public, must-revalidate, max-age=0');
header('Pragma: public');
header('Expires: 0');

readfile($invoice_file);
exit();
?>
