<?php
// Ensure only admins (or global_admin) can access
session_name("logistics_session");
session_start();

// If you’ve already started storing 'global_admin', you could allow that too
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection parameters
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname     = "solterra_portal";

// Initialize any feedback messages
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) Grab form inputs
    $account_name = trim($_POST['account_name']);
    $username     = trim($_POST['username']);
    $password     = trim($_POST['password']);
    $role         = trim($_POST['role']);  // 'admin' or 'user'

    // Basic validation
    if (empty($account_name) || empty($username) || empty($password) || empty($role)) {
        $error_message = "All fields are required.";
    } else {
        // 2) Connect to the database
        $conn = new mysqli($servername, $db_username, $db_password, $dbname);
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        // Start a transaction so we can roll back if something fails
        $conn->begin_transaction();

        try {
            // 3) Create a new account row
            //    (You might check if $account_name already exists if you don’t want duplicates)
            $stmtAcc = $conn->prepare("
                INSERT INTO customer_accounts (name)
                VALUES (?)
            ");
            $stmtAcc->bind_param("s", $account_name);
            $stmtAcc->execute();
            $account_id = $stmtAcc->insert_id; 
            $stmtAcc->close();

            // 4) Check if user already exists in `users`
            $stmtUserCheck = $conn->prepare("
                SELECT id, password FROM users WHERE username = ?
            ");
            $stmtUserCheck->bind_param("s", $username);
            $stmtUserCheck->execute();
            $result = $stmtUserCheck->get_result();
            
            if ($result->num_rows > 0) {
                // User already exists
                $row = $result->fetch_assoc();
                $user_id = $row['id'];
                // You could decide whether to ignore the submitted password or update it
                // For now, let’s RE‐hash (overwrite) if they provided a new password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmtUpdateUser = $conn->prepare("
                    UPDATE users SET password = ? WHERE id = ?
                ");
                $stmtUpdateUser->bind_param("si", $hashed_password, $user_id);
                $stmtUpdateUser->execute();
                $stmtUpdateUser->close();
            } else {
                // Create a brand new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmtNewUser = $conn->prepare("
                    INSERT INTO users (username, password) 
                    VALUES (?, ?)
                ");
                $stmtNewUser->bind_param("ss", $username, $hashed_password);
                $stmtNewUser->execute();
                $user_id = $stmtNewUser->insert_id;
                $stmtNewUser->close();
            }
            $stmtUserCheck->close();

            // 5) Insert into `customer_account_users`
            //    This ties the user to the newly created account with the chosen role
            $stmtCAU = $conn->prepare("
                INSERT INTO customer_account_users (user_id, account_id, role)
                VALUES (?, ?, ?)
            ");
            $stmtCAU->bind_param("iis", $user_id, $account_id, $role);
            $stmtCAU->execute();
            $stmtCAU->close();

            // Commit transaction
            $conn->commit();

            $success_message = "New account '$account_name' created, and user '$username' assigned as '$role'.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error creating account/user: " . $e->getMessage();
        }

        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Account</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>

<h1>Add New Customer Account</h1>

<?php if (!empty($success_message)): ?>
    <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<form action="add_account" method="POST">
    <label for="account_name">Account Name:</label><br>
    <input type="text" name="account_name" required><br><br>

    <label for="username">User to Assign:</label><br>
    <input type="text" name="username" required><br><br>

    <label for="password">User's Password:</label><br>
    <input type="password" name="password" required><br><br>

    <label for="role">Account Role:</label><br>
    <select name="role">
        <option value="admin">Admin</option>
        <option value="user">User</option>
        <option value="DDPm">DDPm</option>
    </select><br><br>

    <input type="submit" value="Create Account & Assign User">
</form>
</body>
</html>
