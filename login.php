<?php
// Set a unique session name for the logistics portal
session_name("logistics_session");
session_start();

// Initialize variables
$error_message = '';

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Create a new database connection
    require_once '../config.php';
    $conn = getDBConnection();
    if (!$conn) {
        die("Connection failed");
    }

    // Retrieve and sanitize form data
    $username = $_POST['username'];
    $password = $_POST['password']; // password_verify() handles hashing internally

    // 1) Find the user in the `users` table to verify password
    $stmt = $conn->prepare("
        SELECT id, password
        FROM users
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // User exists, bind results
        $stmt->bind_result($user_id, $hashed_password);
        $stmt->fetch();

        // Verify password
        if (password_verify($password, $hashed_password)) {
            // Password match -> successful authentication

            // 2) Now fetch the user's role from `customer_account_users`
            //    (Assuming each user belongs to one main customer account.)
            $stmt->close();

            $stmt2 = $conn->prepare("
                SELECT role
                FROM customer_account_users
                WHERE user_id = ?
                LIMIT 1
            ");
            $stmt2->bind_param("i", $user_id);
            $stmt2->execute();
            $stmt2->store_result();

            if ($stmt2->num_rows > 0) {
                // The user has an entry in the customer_account_users table
                $stmt2->bind_result($dbRole);
                $stmt2->fetch();
                $role = $dbRole;
            } else {
                // No entry found in the bridging table, fallback to 'user' or your old `users.role`
                // (You could also fetch it from `users.role` if you haven't removed that column.)
                $role = 'user'; 
            }
            $stmt2->close();

            // Set session variables
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $role;

            // 3) Redirect based on the role
            if ($role === 'global_admin') {
                header("Location: admin_dashboard");
            } else {
                header("Location: dashboard");
            }
            exit();

        } else {
            // Incorrect password
            $error_message = "Incorrect username or password.";
        }
    } else {
        // Username not found
        $error_message = "Incorrect username or password.";
    }

    // Close statement and connection
    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Existing head content -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solterra Solutions Portal Login</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" sizes="180x180" href="pictures/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="152x152" href="pictures/apple-touch-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="120x120" href="pictures/apple-touch-icon-120x120.png">
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('service-worker.js')
        .then(function() {
            console.log('Service Worker Registered');
        })
        .catch(function(error) {
            console.error('Service Worker Registration Failed:', error);
        });
    }
    </script>
</head>
<body>
    <!-- Navbar (optional) -->
    <header>
        <nav class="navbar">
            <div class="logo">
                <img src="pictures/header_logo.png" alt="Solterra Solutions Logo">
            </div>
        </nav>
    </header>

    <!-- Main Login Section -->
    <section class="login-section">
        <div class="login-container">
            <img src="pictures/logo.png" alt="Solterra Solutions Logo" class="login-logo">
            <h1>Customer Portal Login</h1>
            <!-- Display error message if any -->
            <?php if (!empty($error_message)): ?>
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <?php endif; ?>
            <form action="login" method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="login-btn">Login</button>
            </form>
        </div>
    </section>

    <!-- Footer (optional) -->
    <footer>
        <p>© Solterra Solutions. All Rights Reserved.</p>
    </footer>
</body>
</html>
