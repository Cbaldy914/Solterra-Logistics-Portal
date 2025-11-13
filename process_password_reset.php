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

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit();
}

// Validate session contains reset data
if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_token'])) {
    $_SESSION['reset_error'] = 'Invalid session. Please request a new password reset.';
    header("Location: request_password_reset.php");
    exit();
}

$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$user_id = $_SESSION['reset_user_id'];
$token = $_SESSION['reset_token'];

// Validate passwords
if (empty($password) || empty($confirm_password)) {
    $_SESSION['reset_error'] = 'Please fill in all fields.';
    header("Location: reset_password.php?token=" . urlencode($token));
    exit();
}

if ($password !== $confirm_password) {
    $_SESSION['reset_error'] = 'Passwords do not match.';
    header("Location: reset_password.php?token=" . urlencode($token));
    exit();
}

if (strlen($password) < 8) {
    $_SESSION['reset_error'] = 'Password must be at least 8 characters long.';
    header("Location: reset_password.php?token=" . urlencode($token));
    exit();
}

$conn = getDBConnection();
if (!$conn) {
    $_SESSION['reset_error'] = 'System error. Please try again later.';
    header("Location: reset_password.php?token=" . urlencode($token));
    exit();
}

// Verify token is still valid and unused
$stmt = $conn->prepare("
    SELECT id, expires_at, used 
    FROM password_reset_tokens 
    WHERE user_id = ? AND token = ? 
    LIMIT 1
");
$stmt->bind_param("is", $user_id, $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['reset_error'] = 'Invalid reset token.';
    $stmt->close();
    $conn->close();
    header("Location: request_password_reset.php");
    exit();
}

$token_data = $result->fetch_assoc();

if ($token_data['used'] == 1) {
    $_SESSION['reset_error'] = 'This reset link has already been used.';
    $stmt->close();
    $conn->close();
    header("Location: request_password_reset.php");
    exit();
}

if (strtotime($token_data['expires_at']) < time()) {
    $_SESSION['reset_error'] = 'This reset link has expired.';
    $stmt->close();
    $conn->close();
    header("Location: request_password_reset.php");
    exit();
}

$stmt->close();

// Hash the new password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Update user's password
$update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$update_stmt->bind_param("si", $hashed_password, $user_id);

if (!$update_stmt->execute()) {
    $_SESSION['reset_error'] = 'Failed to update password. Please try again.';
    $update_stmt->close();
    $conn->close();
    header("Location: reset_password.php?token=" . urlencode($token));
    exit();
}

$update_stmt->close();

// Mark token as used
$mark_used_stmt = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?");
$mark_used_stmt->bind_param("i", $token_data['id']);
$mark_used_stmt->execute();
$mark_used_stmt->close();

$conn->close();

// Clear reset session data
unset($_SESSION['reset_user_id']);
unset($_SESSION['reset_username']);
unset($_SESSION['reset_token']);

// Set success message for login page
$_SESSION['login_success_message'] = 'Password successfully reset! You can now log in with your new password.';

header("Location: login.php");
exit();
?>

