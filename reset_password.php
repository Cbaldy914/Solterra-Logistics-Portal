<?php
// Initialize session
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_set_cookie_params([
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once '../config.php';

$error_message = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error_message = 'Invalid or missing reset token. Please request a new password reset.';
} else {
    // Verify token exists and hasn't expired
    $conn = getDBConnection();
    if ($conn) {
        $stmt = $conn->prepare("
            SELECT prt.id, prt.user_id, prt.expires_at, prt.used, u.username 
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ? 
            LIMIT 1
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error_message = 'Invalid reset token. Please request a new password reset.';
        } else {
            $token_data = $result->fetch_assoc();
            
            if ($token_data['used'] == 1) {
                $error_message = 'This reset link has already been used. Please request a new password reset.';
            } elseif (strtotime($token_data['expires_at']) < time()) {
                $error_message = 'This reset link has expired. Please request a new password reset.';
            }
            // Token is valid, store user info in session for form submission
            $_SESSION['reset_user_id'] = $token_data['user_id'];
            $_SESSION['reset_username'] = $token_data['username'];
            $_SESSION['reset_token'] = $token;
        }
        
        $stmt->close();
        $conn->close();
    } else {
        $error_message = 'System error. Please try again later.';
    }
}

// Check for messages from processing script
if (isset($_SESSION['reset_error'])) {
    $error_message = $_SESSION['reset_error'];
    unset($_SESSION['reset_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - Solterra Solutions</title>
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #293E4C 0%, #1f2f3a 50%, #488C9A 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .reset-container {
            width: 100%;
            max-width: 550px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.3),
                0 0 100px rgba(107, 184, 199, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            padding: 3rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.6s ease-out;
        }

        .reset-container::before {
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

        .reset-logo {
            width: 100px;
            height: 100px;
            margin: 0 auto 1.5rem;
            display: block;
            filter: drop-shadow(0 4px 12px rgba(72, 140, 154, 0.3));
        }

        h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #293E4C;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
            text-align: center;
        }

        .subtitle {
            font-size: 0.95rem;
            color: #6b7280;
            margin-bottom: 2rem;
            text-align: center;
            line-height: 1.6;
        }

        .username-display {
            background: #eff6ff;
            border-left: 4px solid #488C9A;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            color: #1e40af;
        }

        .username-display strong {
            color: #293E4C;
            font-size: 1rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.875rem 1.125rem;
            font-size: 1rem;
            border: 2px solid #e5e8eb;
            border-radius: 12px;
            background: #ffffff;
            color: #1f2937;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-group input:focus {
            outline: none;
            border-color: #488C9A;
            background: #ffffff;
            box-shadow: 
                0 0 0 4px rgba(72, 140, 154, 0.1),
                0 1px 2px rgba(0, 0, 0, 0.05);
            transform: translateY(-1px);
        }

        .password-requirements {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 0.5rem;
            line-height: 1.5;
        }

        .password-requirements ul {
            margin: 0.5rem 0 0 1.25rem;
        }

        .password-requirements li {
            margin-bottom: 0.25rem;
        }

        .submit-btn {
            width: 100%;
            padding: 1rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #488C9A 0%, #6BB8C7 100%);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 
                0 4px 12px rgba(72, 140, 154, 0.3),
                0 1px 2px rgba(0, 0, 0, 0.1);
            margin-top: 0.5rem;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 
                0 8px 20px rgba(72, 140, 154, 0.4),
                0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: #488C9A;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .back-link:hover {
            color: #3A6E7F;
            text-decoration: underline;
        }

        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 0.875rem 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #ef4444;
            font-size: 0.9375rem;
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

        .error-container {
            text-align: center;
        }

        .error-container p {
            margin-bottom: 1.5rem;
        }

        @media (max-width: 575.98px) {
            .reset-container {
                padding: 2rem 1.5rem;
            }
            
            h1 {
                font-size: 1.75rem;
            }
        }
    </style>
    <script>
        function validatePassword() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password.length < 8) {
                alert('Password must be at least 8 characters long.');
                return false;
            }
            
            if (password !== confirmPassword) {
                alert('Passwords do not match. Please try again.');
                return false;
            }
            
            return true;
        }
    </script>
</head>
<body>
    <div class="reset-container">
        <img src="pictures/main_logo.png" alt="Solterra Solutions Logo" class="reset-logo">
        <h1>Set New Password</h1>
        
        <?php if (!empty($error_message)): ?>
            <div class="error-container">
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
                <a href="request_password_reset.php" class="back-link">← Request New Reset Link</a>
            </div>
        <?php else: ?>
            <p class="subtitle">Create a strong new password for your account</p>
            
            <div class="username-display">
                Resetting password for: <strong><?php echo htmlspecialchars($_SESSION['reset_username'] ?? ''); ?></strong>
            </div>
            
            <form action="process_password_reset.php" method="POST" onsubmit="return validatePassword()">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter new password" required minlength="8">
                    <div class="password-requirements">
                        Password must:
                        <ul>
                            <li>Be at least 8 characters long</li>
                            <li>Include letters and numbers (recommended)</li>
                            <li>Not be easily guessable</li>
                        </ul>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required minlength="8">
                </div>
                
                <button type="submit" class="submit-btn">
                    <span>Reset Password</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M8 3a5 5 0 1 1-4.546 2.914.5.5 0 0 0-.908-.417A6 6 0 1 0 8 2v1z"/>
                        <path d="M8 4.466V.534a.25.25 0 0 0-.41-.192L5.23 2.308a.25.25 0 0 0 0 .384l2.36 1.966A.25.25 0 0 0 8 4.466z"/>
                    </svg>
                </button>
            </form>
            
            <a href="login.php" class="back-link">← Back to Login</a>
        <?php endif; ?>
    </div>
</body>
</html>

