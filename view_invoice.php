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
$user_id    = $_SESSION['user_id'];
$role       = $_SESSION['role'] ?? 'customer'; // fallback role if not set

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

/**
 * 1) Fetch the invoice details, joining through projects to get account_id
 *    Instead of p.user_id, we now rely on p.account_id and the related account.
 */
$stmt = $conn->prepare("
    SELECT pi.invoice_file,
           p.account_id         AS project_account_id
    FROM project_invoices pi
    JOIN projects p ON pi.project_id = p.id
    WHERE pi.id = ?
");
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$stmt->bind_result($invoice_file, $project_account_id);
$stmt->fetch();
$stmt->close();

// Check if the invoice exists in DB
if (empty($invoice_file)) {
    die("Invoice not found.");
}

// Now we must verify that the current user has access:

// If user is global_admin or admin, we allow access unconditionally
if ($role === 'global_admin' || $role === 'admin' || $role === 'customer_admin') {
    $hasAccess = true;
} else {
    // Otherwise, we check if this user belongs to the same account via customer_account_users
    // We look for a row matching (account_id = $project_account_id, user_id = $user_id)
    // If found, user has access; otherwise, deny.
    $sqlCheck = "
        SELECT role
        FROM customer_account_users
        WHERE account_id = ?
          AND user_id    = ?
        LIMIT 1
    ";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bind_param("ii", $project_account_id, $user_id);
    $stmtCheck->execute();
    $stmtCheck->store_result();

    // hasAccess is true only if we got a row
    $hasAccess = ($stmtCheck->num_rows > 0);
    $stmtCheck->close();
}

// If no access, deny
if (!$hasAccess) {
    die("You do not have access to this invoice.");
}

// Ensure the file exists on the server
if (!file_exists($invoice_file)) {
    die("Invoice file not found on the server.");
}

// Get the file's MIME type
$finfo     = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $invoice_file);
finfo_close($finfo);

// Output the file inline (if PDF/image) or as a download
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime_type);
// Using inline content disposition for PDFs/images in the browser:
header('Content-Disposition: inline; filename="' . basename($invoice_file) . '"');
header('Content-Length: ' . filesize($invoice_file));
header('Cache-Control: public, must-revalidate, max-age=0');
header('Pragma: public');
header('Expires: 0');

// Finally, read out the file contents
readfile($invoice_file);
exit();
