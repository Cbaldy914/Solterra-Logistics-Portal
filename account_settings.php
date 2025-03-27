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
    <style>
        .settings-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 30px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .settings-container h1 {
            color: #293E4C;
            margin-bottom: 30px;
            font-size: 24px;
        }

        .info-group {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 6px;
            border: 1px solid #eee;
        }

        .info-group label {
            flex: 1;
            font-weight: 500;
            color: #293E4C;
        }

        .info-group .value {
            flex: 2;
            color: #666;
        }

        .info-group .edit-icon {
            color: #488C9A;
            cursor: pointer;
            padding: 5px;
            margin-left: 10px;
            transition: color 0.3s;
        }

        .info-group .edit-icon:hover {
            color: #293E4C;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #293E4C;
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group input:focus {
            border-color: #488C9A;
            outline: none;
            box-shadow: 0 0 0 2px rgba(72, 140, 154, 0.1);
        }

        .password-section {
            display: none;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .password-section.show {
            display: block;
        }

        .error-messages {
            background-color: #fff3f3;
            border: 1px solid #ffcdd2;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .error-messages ul {
            margin: 0;
            padding-left: 20px;
            color: #d32f2f;
        }

        .success-message {
            background-color: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            color: #2e7d32;
        }

        button[type="submit"] {
            background-color: #488C9A;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background-color 0.3s;
        }

        button[type="submit"]:hover {
            background-color: #367480;
        }

        .cancel-edit {
            background-color: #f5f5f5;
            color: #666;
            padding: 12px 24px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            margin-left: 10px;
            transition: all 0.3s;
        }

        .cancel-edit:hover {
            background-color: #e0e0e0;
        }

        .add-email-btn {
            background-color: #488C9A;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background-color 0.3s;
            margin-left: 10px;
        }

        .add-email-btn:hover {
            background-color: #367480;
        }
    </style>
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

    <form action="" method="post" id="accountForm">
        <div class="info-group">
            <label for="username">Username</label>
            <div class="value" id="usernameDisplay"><?php echo htmlspecialchars($existingUsername); ?></div>
            <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($existingUsername); ?>" style="display: none;" required>
            <span class="edit-icon" onclick="toggleEdit('username')">✎</span>
        </div>

        <div class="info-group">
            <label>Password</label>
            <div class="value">••••••••</div>
            <span class="edit-icon" onclick="togglePasswordSection()">✎</span>
        </div>

        <div class="info-group">
            <label for="email">Email</label>
            <div class="value" id="emailDisplay"><?php echo htmlspecialchars($existingEmail ?? 'Not set'); ?></div>
            <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($existingEmail ?? ''); ?>" style="display: none;" placeholder="Enter email or leave blank">
            <?php if (empty($existingEmail)): ?>
                <button type="button" class="add-email-btn" onclick="toggleEdit('email')">Add Email</button>
            <?php else: ?>
                <span class="edit-icon" onclick="toggleEdit('email')">✎</span>
            <?php endif; ?>
        </div>

        <div class="password-section" id="passwordSection">
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" name="current_password" id="current_password" required>
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" name="new_password" id="new_password" required>
            </div>

            <div class="form-group">
                <label for="confirm_new_password">Confirm New Password</label>
                <input type="password" name="confirm_new_password" id="confirm_new_password" required>
            </div>
        </div>

        <div class="form-group" style="margin-top: 30px;">
            <button type="submit">Save Changes</button>
            <button type="button" class="cancel-edit" onclick="cancelEdit()" style="display: none;">Cancel</button>
        </div>
    </form>
</div>

<script>
function toggleEdit(field) {
    const display = document.getElementById(field + 'Display');
    const input = document.getElementById(field);
    const cancelButton = document.querySelector('.cancel-edit');
    
    if (display.style.display !== 'none') {
        display.style.display = 'none';
        input.style.display = 'block';
        cancelButton.style.display = 'inline-block';
    } else {
        display.style.display = 'block';
        input.style.display = 'none';
        cancelButton.style.display = 'none';
    }
}

function togglePasswordSection() {
    const section = document.getElementById('passwordSection');
    const cancelButton = document.querySelector('.cancel-edit');
    
    if (section.classList.contains('show')) {
        section.classList.remove('show');
        cancelButton.style.display = 'none';
    } else {
        section.classList.add('show');
        cancelButton.style.display = 'inline-block';
    }
}

function cancelEdit() {
    // Reset all fields to display mode
    document.querySelectorAll('.info-group').forEach(group => {
        const display = group.querySelector('.value');
        const input = group.querySelector('input');
        if (display && input) {
            display.style.display = 'block';
            input.style.display = 'none';
        }
    });
    
    // Hide password section
    document.getElementById('passwordSection').classList.remove('show');
    
    // Hide cancel button
    document.querySelector('.cancel-edit').style.display = 'none';
    
    // Reset form
    document.getElementById('accountForm').reset();
}
</script>
</body>
</html>
