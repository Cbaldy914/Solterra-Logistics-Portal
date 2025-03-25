<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Optional: get the user's role from the session if needed
$role = $_SESSION['role'] ?? 'user';

// Database connection parameters
$servername = "localhost";
$db_username = "SolterraSolutions"; // Replace with your actual database username
$db_password = "CompanyAdmin!";     // Replace with your actual database password
$dbname     = "solterra_portal";    // Replace with your actual database name

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Create a new database connection
$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch the current user's record
$user_id = $_SESSION['user_id'];
$sqlSelect = "SELECT username, email, password FROM users WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sqlSelect);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$stmt->close();

if (!$userData) {
    // If no user found, redirect or log out
    header("Location: logout");
    exit();
}

// Pre-fill from database
$existingUsername = $userData['username'] ?? '';
$existingEmail    = $userData['email'] ?? '';    // may be NULL in DB
$dbPass           = $userData['password'] ?? '';

$errors = [];
$successMessage = "";

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form inputs
    $username       = trim($_POST['username'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $currentPass    = $_POST['current_password'] ?? '';
    $newPass        = $_POST['new_password'] ?? '';
    $confirmNewPass = $_POST['confirm_new_password'] ?? '';

    // Validate username
    if (empty($username)) {
        $errors[] = "Username cannot be empty.";
    }

    // Handle email (optional)
    // If provided, validate format; if left blank, we set it to NULL.
    $finalEmail = null;
    if ($email !== "") {
        // Validate the email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        } else {
            $finalEmail = $email; // valid email
        }
    }
    // If blank, $finalEmail remains NULL

    // Check if user is attempting a password change
    $changePassword = (!empty($newPass) || !empty($confirmNewPass));
    if ($changePassword) {
        // Must provide current password
        if (empty($currentPass)) {
            $errors[] = "You must enter your current password to change it.";
        }
        // Check that new password and confirmation match
        if ($newPass !== $confirmNewPass) {
            $errors[] = "New password and confirmation do not match.";
        }
    }

    // If no validation errors, proceed
    if (empty($errors)) {
        // If changing password, verify current password
        if ($changePassword) {
            if (!password_verify($currentPass, $dbPass)) {
                $errors[] = "Current password is incorrect.";
            }
        }

        // If still no errors, update the record
        if (empty($errors)) {
            // Build update query
            if ($changePassword && !empty($newPass)) {
                // Hash new password
                $hashedNewPass = password_hash($newPass, PASSWORD_DEFAULT);

                // UPDATE with new password
                $sqlUpdate = "UPDATE users
                              SET username = ?, email = ?, password = ?
                              WHERE id = ?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                // 'sssi' => string, string, string, integer
                $stmtUpdate->bind_param("sssi", 
                    $username, 
                    $finalEmail, 
                    $hashedNewPass, 
                    $user_id
                );
            } else {
                // UPDATE without changing password
                $sqlUpdate = "UPDATE users
                              SET username = ?, email = ?
                              WHERE id = ?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                // 'ssi' => string, string, integer
                $stmtUpdate->bind_param("ssi", 
                    $username, 
                    $finalEmail, 
                    $user_id
                );
            }

            // Execute update
            if ($stmtUpdate->execute()) {
                $successMessage = "Account settings have been updated!";
                // Update the local variables so the form is updated on refresh
                $existingUsername = $username;
                $existingEmail    = $finalEmail;
            } else {
                $errors[] = "Update failed: " . $stmtUpdate->error;
            }
            $stmtUpdate->close();
        }
    }
}

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>

<div class="settings-container">
    <h1>Account Settings</h1>
    
    <?php if (!empty($errors)): ?>
        <div class="error-messages">
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message">
            <?php echo htmlspecialchars($successMessage); ?>
        </div>
    <?php endif; ?>

    <!-- The Form -->
    <form action="" method="post">
        <div class="form-group">
            <label for="username">Username:</label>
            <input 
                type="text" 
                name="username" 
                id="username" 
                value="<?php echo htmlspecialchars($existingUsername); ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="email">Email (Optional):</label>
            <input 
                type="email" 
                name="email" 
                id="email"
                value="<?php echo htmlspecialchars($existingEmail ?? ''); ?>"
                placeholder="Enter email or leave blank"
            >
        </div>

        <hr>

        <div class="form-group">
            <label for="current_password">Current Password (required if changing):</label>
            <input 
                type="password" 
                name="current_password" 
                id="current_password"
                placeholder="Enter current password to change it"
            >
        </div>

        <div class="form-group">
            <label for="new_password">New Password (optional):</label>
            <input 
                type="password" 
                name="new_password" 
                id="new_password"
                placeholder="Leave blank to keep existing password"
            >
        </div>

        <div class="form-group">
            <label for="confirm_new_password">Confirm New Password:</label>
            <input 
                type="password" 
                name="confirm_new_password" 
                id="confirm_new_password"
                placeholder="Re-enter new password if changing"
            >
        </div>

        <div class="form-group">
            <button type="submit">Save Changes</button>
        </div>
    </form>

    <div>
        <p>
            Need to see your invoices?
            <a href="invoices.php">View Invoices</a>
        </p>
    </div>
</div>
</body>
</html>
