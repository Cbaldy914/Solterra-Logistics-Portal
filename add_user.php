<?php
session_name("logistics_session");
session_start();

// Ensure the user is a global admin
if ($_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Initialize feedback messages
$success_message = '';
$error_message = '';

// 1) Fetch all accounts for the dropdown
$accounts = [];
$stmtAcc = $conn->prepare("SELECT id, name FROM customer_accounts");
$stmtAcc->execute();
$resultAcc = $stmtAcc->get_result();
if ($resultAcc && $resultAcc->num_rows > 0) {
    while ($row = $resultAcc->fetch_assoc()) {
        $accounts[] = $row;
    }
}
$stmtAcc->close();

// 2) If the form is submitted, handle the POST to create a user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = $_POST['username'];
    $password   = $_POST['password'];
    $role       = $_POST['role'];             // e.g. 'user', 'admin', 'DDPm'
    $account_id = intval($_POST['account_id']);

    // Basic validation
    if (empty($username) || empty($password) || empty($role) || empty($account_id)) {
        $error_message = "All fields (username, password, role, account) are required.";
    } else {
        // Insert into `users`
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmtUser = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmtUser->bind_param("ss", $username, $hashed_password);

        if (!$stmtUser->execute()) {
            $error_message = "Error creating user: " . $stmtUser->error;
        } else {
            // Get new user ID
            $user_id = $stmtUser->insert_id;

            // Insert bridging row (customer_account_users)
            $stmtCAU = $conn->prepare("
                INSERT INTO customer_account_users (user_id, account_id, role)
                VALUES (?, ?, ?)
            ");
            $stmtCAU->bind_param("iis", $user_id, $account_id, $role);

            if (!$stmtCAU->execute()) {
                $error_message = "Error assigning user to account: " . $stmtCAU->error;
                // (Optional) Could also delete the user row if bridging fails, to keep DB consistent
            } else {
                $success_message = "New user '{$username}' added and assigned to account successfully.";
            }
            $stmtCAU->close();
        }
        $stmtUser->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add User</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<?php include 'header.php'; ?>
<body>
    <h1>Add New User</h1>

    <!-- Display success or error messages -->
    <?php if (!empty($error_message)): ?>
        <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <p class="success-message"><?php echo htmlspecialchars($success_message); ?></p>
    <?php endif; ?>

    <!-- The form remains visible so you can add more users if desired -->
    <form action="" method="POST">
        <label for="username">Username:</label><br>
        <input type="text" name="username" required><br><br>

        <label for="password">Password:</label><br>
        <input type="password" name="password" required><br><br>

        <label for="role">Role:</label><br>
        <select name="role">
            <option value="user">User</option>
            <option value="admin">Admin</option>
            <option value="DDPm">DDPm</option>
        </select><br><br>

        <label for="account_id">Assign to Account:</label><br>
        <select name="account_id" required>
            <option value="">--Select an Account--</option>
            <?php foreach ($accounts as $acc): ?>
                <option value="<?php echo $acc['id']; ?>">
                    <?php echo htmlspecialchars($acc['name']); ?>
                </option>
            <?php endforeach; ?>
        </select><br><br>

        <input type="submit" value="Add User">
    </form>
</body>
</html>
