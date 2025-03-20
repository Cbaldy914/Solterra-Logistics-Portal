<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
    header("Location: login");
    exit();
}

// Check if the invoice ID is provided
if (!isset($_GET['id'])) {
    echo "Invoice ID not provided.";
    exit();
}

$invoice_id = intval($_GET['id']);

// Initialize variables
$success_message = '';
$error_message = '';

// Database connection parameters
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

// Create database connection
$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch invoice details
$stmt = $conn->prepare("SELECT pi.*, p.project_name FROM project_invoices pi JOIN projects p ON pi.project_id = p.id WHERE pi.id = ?");
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "Invoice not found.";
    exit();
}

$invoice = $result->fetch_assoc();
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $amount = floatval($_POST['amount']);
    $status = $_POST['status'] === 'Paid' ? 'Paid' : 'Open'; // Ensure only 'Paid' or 'Open'
    $due_date = $_POST['due_date'];

    // Validate due date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        $error_message = "Invalid due date format.";
    } else {
        // Handle invoice file upload
        $update_file = false;
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/invoices/';

            // Ensure the upload directory exists
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $invoice_name = basename($_FILES['invoice_file']['name']);
            $invoice_path = $upload_dir . time() . '_' . $invoice_name;

            if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $invoice_path)) {
                $update_file = true;
            } else {
                $error_message = "Failed to upload the invoice file.";
            }
        }

        // If no errors, update the invoice
        if (empty($error_message)) {
            if ($update_file) {
                // Update all fields including invoice file
                $stmt = $conn->prepare("UPDATE project_invoices SET amount = ?, status = ?, due_date = ?, invoice_file = ? WHERE id = ?");
                $stmt->bind_param("dsssi", $amount, $status, $due_date, $invoice_path, $invoice_id);
            } else {
                // Update fields without changing the invoice file
                $stmt = $conn->prepare("UPDATE project_invoices SET amount = ?, status = ?, due_date = ? WHERE id = ?");
                $stmt->bind_param("dssi", $amount, $status, $due_date, $invoice_id);
            }

            if ($stmt->execute()) {
                $success_message = "Invoice updated successfully.";
                // Refresh invoice data
                $stmt->close();
                $stmt = $conn->prepare("SELECT pi.*, p.project_name FROM project_invoices pi JOIN projects p ON pi.project_id = p.id WHERE pi.id = ?");
                $stmt->bind_param("i", $invoice_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $invoice = $result->fetch_assoc();
                $stmt->close();
            } else {
                $error_message = "Error updating invoice: " . $stmt->error;
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- existing head content -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Invoice - Admin Dashboard</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Add any additional styles here */
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="number"],
        input[type="date"],
        select {
            width: 95%;
            padding: 8px;
            margin-top: 5px;
        }
        .success-message {
            color: green;
            margin-top: 15px;
        }
        .error-message {
            color: red;
            margin-top: 15px;
        }
        button {
            background-color: #488C9A;
            color: white;
            padding: 10px 20px;
            margin: 20px 0;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-weight: bold;
        }
        button:hover {
            background-color: #293E4C;
        }
        a.back-link {
            display: inline-block;
            margin-top: 20px;
            text-decoration: none;
            color: #488C9A;
        }
        a.back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="container">
        <h1>Edit Invoice</h1>
        <?php
        if (!empty($error_message)) {
            echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
        }
        if (!empty($success_message)) {
            echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
        }
        ?>
        <form action="" method="post" enctype="multipart/form-data">
            <label>Project Name:</label>
            <input type="text" value="<?php echo htmlspecialchars($invoice['project_name']); ?>" disabled>

            <label for="amount">Amount:</label>
            <input type="number" name="amount" id="amount" step="0.01" required value="<?php echo htmlspecialchars($invoice['amount']); ?>">

            <label for="status">Status:</label>
            <select name="status" id="status" required>
                <option value="Open" <?php if ($invoice['status'] == 'Open') echo 'selected'; ?>>Open</option>
                <option value="Paid" <?php if ($invoice['status'] == 'Paid') echo 'selected'; ?>>Paid</option>
            </select>

            <label for="due_date">Due Date:</label>
            <input type="date" name="due_date" id="due_date" required value="<?php echo htmlspecialchars($invoice['due_date']); ?>">

            <label>Current Invoice File:</label>
            <?php if (!empty($invoice['invoice_file'])): ?>
                <a href="<?php echo htmlspecialchars($invoice['invoice_file']); ?>" target="_blank">View Current Invoice</a>
            <?php else: ?>
                No file uploaded.
            <?php endif; ?>

            <label for="invoice_file">Replace Invoice File (optional):</label>
            <input type="file" name="invoice_file" id="invoice_file" accept=".pdf,.doc,.docx,.xls,.xlsx">

            <button type="submit">Update Invoice</button>
        </form>
        <a href="add_invoice" class="back-link">Back to Invoices</a>
    </div>
</main>
</body>
</html>
