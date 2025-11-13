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

// Initialize variables
$message = '';
$message_type = '';

// Check for messages from processing script
if (isset($_SESSION['reset_message'])) {
    $message = $_SESSION['reset_message'];
    $message_type = $_SESSION['reset_message_type'] ?? 'success';
    unset($_SESSION['reset_message']);
    unset($_SESSION['reset_message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Solterra Solutions</title>
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

        .form-group input::placeholder {
            color: #9ca3af;
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
            position: relative;
            overflow: hidden;
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

        .message {
            padding: 0.875rem 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.9375rem;
            animation: slideDown 0.3s ease-out;
        }

        .message.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
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

        .info-box {
            background: #eff6ff;
            border-left: 4px solid #488C9A;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            color: #1e40af;
            line-height: 1.6;
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
</head>
<body>
    <div class="reset-container">
        <img src="pictures/main_logo.png" alt="Solterra Solutions Logo" class="reset-logo">
        <h1>Reset Password</h1>
        <p class="subtitle">Enter your username to receive password reset instructions</p>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <strong>For security:</strong> Enter your username exactly as you use it to log in. If the username exists, you'll receive an email with reset instructions at your registered email address.
        </div>

        <div class="info-box" style="background: #fef3c7; border-left-color: #f59e0b;">
            <strong>Need help?</strong> If you're having trouble accessing your account, please contact us at <a href="mailto:info@solterrasol.com" style="color: #d97706; font-weight: 600; text-decoration: none;">info@solterrasol.com</a>
        </div>
        
        <form action="process_password_reset_request.php" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
            </div>
            
            <button type="submit" class="submit-btn">
                <span>Send Reset Instructions</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M.05 3.555A2 2 0 0 1 2 2h12a2 2 0 0 1 1.95 1.555L8 8.414.05 3.555ZM0 4.697v7.104l5.803-3.558L0 4.697ZM6.761 8.83l-6.57 4.027A2 2 0 0 0 2 14h12a2 2 0 0 0 1.808-1.144l-6.57-4.027L8 9.586l-1.239-.757Zm3.436-.586L16 11.801V4.697l-5.803 3.546Z"/>
                </svg>
            </button>
        </form>
        
        <a href="login.php" class="back-link">← Back to Login</a>
    </div>
</body>
</html>

