<?php
// Set a unique session name for the logistics portal
session_name("logistics_session");
// Ensure session cookies are sent only over HTTPS and are not accessible via JavaScript
session_set_cookie_params([
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
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

            // 3) Redirect all users to the unified dashboard
            header("Location: dashboard");
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solterra Solutions Portal Login</title>
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" sizes="180x180" href="pictures/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="152x152" href="pictures/apple-touch-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="120x120" href="pictures/apple-touch-icon-120x120.png">
    <style>
        /* Reset and Base Styles - Override any existing portal.css */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            overflow-x: hidden !important;
            width: 100%;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif !important;
            min-height: 100vh;
            background: linear-gradient(135deg, #293E4C 0%, #1f2f3a 50%, #488C9A 100%) !important;
            position: relative;
            overflow-x: hidden !important;
            width: 100%;
            margin: 0 !important;
            padding: 0 !important;
        }

        .login-section {
            min-height: 100vh;
            display: flex !important;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            z-index: 1;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            animation: fadeInUp 0.6s ease-out;
            background: rgba(255, 255, 255, 0.98) !important;
            backdrop-filter: blur(20px);
            border-radius: 24px !important;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.3),
                0 0 100px rgba(107, 184, 199, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.9) !important;
            padding: 3rem !important;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #6BB8C7 50%, #488C9A 100%);
            background-size: 200% 100%;
            animation: shimmer 3s linear infinite;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -200% 0;
            }
            100% {
                background-position: 200% 0;
            }
        }

        .login-logo {
            width: 150px !important;
            height: 150px !important;
            margin: 0 auto 1.5rem !important;
            display: block !important;
            filter: drop-shadow(0 4px 12px rgba(72, 140, 154, 0.3)) !important;
        }

        .login-container h1 {
            font-size: 2rem !important;
            font-weight: 700 !important;
            color: #293E4C !important;
            margin-bottom: 0.5rem !important;
            letter-spacing: -0.02em;
            text-align: center;
        }

        .login-container p {
            font-size: 1rem;
            color: #6b7280;
            margin: 0 0 2.5rem 0 !important;
            font-weight: 400;
            text-align: center;
        }

        .form-group {
            margin-bottom: 1.5rem !important;
        }

        .form-group label {
            display: block !important;
            font-size: 0.875rem !important;
            font-weight: 600 !important;
            color: #1f2937 !important;
            margin-bottom: 0.5rem !important;
        }

        .form-group input {
            width: 100% !important;
            padding: 0.875rem 1.125rem !important;
            font-size: 1rem !important;
            border: 2px solid #e5e8eb !important;
            border-radius: 12px !important;
            background: #ffffff !important;
            color: #1f2937 !important;
            transition: all 0.2s ease !important;
            font-family: inherit !important;
        }

        .form-group input:focus {
            outline: none !important;
            border-color: #488C9A !important;
            background: #ffffff !important;
            box-shadow: 
                0 0 0 4px rgba(72, 140, 154, 0.1),
                0 1px 2px rgba(0, 0, 0, 0.05) !important;
            transform: translateY(-1px);
        }

        .form-group input::placeholder {
            color: #9ca3af !important;
        }

        .login-btn {
            width: 100% !important;
            padding: 1rem 1.5rem !important;
            font-size: 1rem !important;
            font-weight: 600 !important;
            color: white !important;
            background: linear-gradient(135deg, #488C9A 0%, #6BB8C7 100%) !important;
            border: none !important;
            border-radius: 12px !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            display: flex !important;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 
                0 4px 12px rgba(72, 140, 154, 0.3),
                0 1px 2px rgba(0, 0, 0, 0.1) !important;
            position: relative;
            overflow: hidden;
            margin-top: 1rem !important;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 
                0 8px 20px rgba(72, 140, 154, 0.4),
                0 2px 4px rgba(0, 0, 0, 0.1) !important;
        }

        .login-btn:active {
            transform: translateY(0) !important;
            box-shadow: 
                0 2px 8px rgba(72, 140, 154, 0.3),
                0 1px 2px rgba(0, 0, 0, 0.1) !important;
        }

        .error-message {
            background: #fee2e2 !important;
            color: #991b1b !important;
            padding: 0.875rem 1rem !important;
            border-radius: 12px !important;
            margin-bottom: 1.5rem !important;
            border-left: 4px solid #ef4444 !important;
            font-size: 0.9375rem !important;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Hide any existing header/nav/footer from portal.css */
        header, nav.navbar, footer {
            display: none !important;
        }

        /* Responsive adjustments */
        @media (max-width: 575.98px) {
            .login-container {
                padding: 2rem 1.5rem !important;
                border-radius: 20px !important;
            }
            
            .login-container h1 {
                font-size: 1.75rem !important;
            }
            
            .login-logo {
                width: 125px !important;
                height: 125px !important;
            }
            
            body::before,
            body::after {
                width: 400px;
                height: 400px;
            }
        }

        @media (max-width: 374.98px) {
            .login-container {
                padding: 1.5rem 1rem !important;
            }
            
            .login-container h1 {
                font-size: 1.5rem !important;
            }
        }
    </style>
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
    <section class="login-section">
        <div class="login-container">
            <img src="pictures/main_logo.png" alt="Solterra Solutions Logo" class="login-logo">
            <h1>Welcome Back</h1>
            <p>Sign in to your Solterra Solutions account</p>
            
            <?php if (!empty($error_message)): ?>
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <?php endif; ?>
            
            <form action="login" method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="login-btn">
                    <span>Sign In</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8z"/>
                    </svg>
                </button>
            </form>
        </div>
    </section>
</body>
</html>