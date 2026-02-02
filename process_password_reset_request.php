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
require_once 'Mailer.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: request_password_reset.php");
    exit();
}

$username = trim($_POST['username'] ?? '');

if (empty($username)) {
    $_SESSION['reset_message'] = 'Please enter your username.';
    $_SESSION['reset_message_type'] = 'error';
    header("Location: request_password_reset.php");
    exit();
}

$conn = getDBConnection();
if (!$conn) {
    $_SESSION['reset_message'] = 'System error. Please try again later.';
    $_SESSION['reset_message_type'] = 'error';
    header("Location: request_password_reset.php");
    exit();
}

// Look up user by username
$stmt = $conn->prepare("SELECT id, email, username FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

// SECURITY: Always show the same message whether user exists or not
// This prevents account enumeration attacks
$generic_message = "If an account exists with that username, you will receive an email with password reset instructions within a few minutes.";

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Check if user has an email address
    if (empty($user['email'])) {
        // Still show generic message, but don't send email
        $_SESSION['reset_message'] = $generic_message;
        $_SESSION['reset_message_type'] = 'success';
        $stmt->close();
        $conn->close();
        header("Location: request_password_reset.php");
        exit();
    }
    
    // Generate secure random token
    $token = bin2hex(random_bytes(32)); // 64 character hex string
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // Delete any existing unused tokens for this user
    $delete_stmt = $conn->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND used = 0");
    $delete_stmt->bind_param("i", $user['id']);
    $delete_stmt->execute();
    $delete_stmt->close();
    
    // Insert new token
    $insert_stmt = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
    $insert_stmt->bind_param("iss", $user['id'], $token, $expires_at);
    
    if ($insert_stmt->execute()) {
        // Send email with reset link
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        $basePath = preg_replace('#/api$#', '', $scriptDir);
        $reset_link = $scheme . '://' . $host . ($basePath !== '' ? $basePath : '') . '/reset_password.php?token=' . $token;
        
        $subject = "Password Reset Request - Solterra Solutions Portal";
        $message = "Hello " . $user['username'] . ",

We received a request to reset your password for your Solterra Solutions Portal account.

To reset your password, click the link below or copy and paste it into your browser:

" . $reset_link . "

⚠️ IMPORTANT:
- This link will expire in 1 hour
- This link can only be used once
- If you didn't request this reset, please ignore this email

For security reasons, passwords cannot be retrieved. You must create a new password.

If you need assistance, please contact your account administrator at info@solterrasol.com

Best regards,
Solterra Solutions Team

---
This is an automated message from the Solterra Solutions Logistics Portal.
If you didn't request a password reset, please ignore this email.";
        
        try {
            Mailer::send($user['email'], $subject, $message);
        } catch (Exception $e) {
            // Log error but still show generic message for security
            error_log("Password reset email failed: " . $e->getMessage());
        }
    }
    
    $insert_stmt->close();
}

$stmt->close();
$conn->close();

// Always show the same message for security
$_SESSION['reset_message'] = $generic_message;
$_SESSION['reset_message_type'] = 'success';
header("Location: request_password_reset.php");
exit();
?>

