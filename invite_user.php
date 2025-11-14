<?php
session_name("logistics_session");
session_start();

// Ensure the user is a global admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
require_once 'Mailer.php';

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$success_message = '';
$error_message = '';
$form_data = [
    'username' => '',
    'email' => '',
    'account_id' => ''
];

// Fetch accounts for dropdown
$accounts = [];
$stmtAcc = $conn->prepare("SELECT id, name FROM customer_accounts ORDER BY name ASC");
$stmtAcc->execute();
$resAcc = $stmtAcc->get_result();
while ($row = $resAcc->fetch_assoc()) { $accounts[] = $row; }
$stmtAcc->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data['username']   = trim($_POST['username'] ?? '');
    $form_data['email']      = trim($_POST['email'] ?? '');
    $form_data['account_id'] = intval($_POST['account_id'] ?? 0);

    $username   = $form_data['username'];
    $email      = $form_data['email'];
    $account_id = $form_data['account_id'];

    if (empty($username) || empty($email) || empty($account_id)) {
        $error_message = 'Username, email, and account are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        try {
            // Ensure username is unique
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chk->bind_param("s", $username);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                throw new Exception("Username already exists. Please choose a different username.");
            }
            $chk->close();

            $conn->begin_transaction();

            // Create user with temporary random password; role will be mapped via customer_account_users
            $tempPassword = bin2hex(random_bytes(8));
            $hashed = password_hash($tempPassword, PASSWORD_DEFAULT);
            $insUser = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $insUser->bind_param("sss", $username, $email, $hashed);
            if (!$insUser->execute()) {
                throw new Exception($insUser->error, $insUser->errno);
            }
            $user_id = $insUser->insert_id;
            $insUser->close();

            // Map to account as 'user' (customer-level access only)
            $role = 'user';
            $insMap = $conn->prepare("INSERT INTO customer_account_users (user_id, account_id, role) VALUES (?, ?, ?)");
            $insMap->bind_param("iis", $user_id, $account_id, $role);
            if (!$insMap->execute()) {
                throw new Exception($insMap->error, $insMap->errno);
            }
            $insMap->close();

            // Create a password set token (reuse reset flow)
            $token = bin2hex(random_bytes(32)); // 64 chars
            $expires_at = date('Y-m-d H:i:s', strtotime('+72 hours'));
            // Clean old tokens just in case
            $delTok = $conn->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND used = 0");
            $delTok->bind_param("i", $user_id);
            $delTok->execute();
            $delTok->close();
            $insTok = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
            $insTok->bind_param("iss", $user_id, $token, $expires_at);
            if (!$insTok->execute()) {
                throw new Exception($insTok->error, $insTok->errno);
            }
            $insTok->close();

            $conn->commit();

            // Send invite email
            $reset_link = "https://" . $_SERVER['HTTP_HOST'] . "/reset_password?token=" . $token;
            $subject = "You\'re invited to Solterra Solutions Portal";
            $body = "Hello,\n\n" .
                "You have been invited to the Solterra Solutions Logistics Portal.\n\n" .
                "Username: " . $username . "\n" .
                "To set your password and activate your account, click the link below:\n" .
                $reset_link . "\n\n" .
                "This link expires in 72 hours and can be used once.\n\n" .
                "If you weren\'t expecting this invitation, please ignore this email.\n\n" .
                "— Solterra Solutions Team";

            try {
                Mailer::send($email, $subject, $body);
            } catch (Exception $e) {
                error_log('Invite email failed: ' . $e->getMessage());
                // We still consider the invite created successfully
            }

            $success_message = 'Invitation created. An email was sent to ' . htmlspecialchars($email) . ' with a password setup link.';
            $form_data = ['username' => '', 'email' => '', 'account_id' => ''];

        } catch (Exception $ex) {
            if ($conn->errno) { $conn->rollback(); }
            $error_message = 'Error creating invite: ' . $ex->getMessage();
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invite User</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .container { padding: 20px; }
        .form-container { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 25px; margin-top: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display:block; margin-bottom:8px; font-weight:500; color:#333; }
        .form-group input[type="text"], .form-group input[type="email"], .form-group select { width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-size:16px; }
        .btn-primary { background:#488C9A; color:#fff; border:none; padding:12px 24px; border-radius:4px; font-size:16px; cursor:pointer; font-weight:500; }
        .btn-primary:hover { background:#3A7A87; }
        .error-message { background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:20px; border-left:4px solid #dc3545; font-weight:500; }
        .success-message { background:#d4edda; color:#155724; padding:12px; border-radius:4px; margin-bottom:20px; border-left:4px solid #28a745; font-weight:500; }
        .info-note { font-size: 0.9rem; color: #555; margin-top: 6px; }
        .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 12px; }
        .link { color:#488C9A; text-decoration:none; }
        .link:hover { text-decoration:underline; }
    </style>
    <script>
        function suggestUsername() {
            const email = document.getElementById('email').value.trim();
            const usernameInput = document.getElementById('username');
            if (email && !usernameInput.value) {
                const local = email.split('@')[0];
                usernameInput.value = local.replace(/[^a-zA-Z0-9_.-]/g, '_');
            }
        }
    </script>
    </head>
<body>
<?php include 'header.php'; ?>
<main class="container">
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Invite User', 'extra' => [ ['label' => 'Manage Users', 'url' => 'manage_users.php'] ]]); ?>

    <div class="page-header">
        <h1>Invite User</h1>
        <a class="link" href="manage_users.php">Back to Manage Users</a>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="success-message"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($form_data['email']); ?>" onblur="suggestUsername()" required>
                <div class="info-note">We will send the invite and password setup link here.</div>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($form_data['username']); ?>" required>
                <div class="info-note">Used to log in. You can auto-fill from the email's local part.</div>
            </div>

            <div class="form-group">
                <label for="account_id">Assign to Account</label>
                <select id="account_id" name="account_id" required>
                    <option value="">--Select an Account--</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($form_data['account_id'] == $acc['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($acc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Role</label>
                <input type="text" value="user" disabled>
                <div class="info-note">Invites create customer-level access (role: user). Admin access is not granted.</div>
            </div>

            <button type="submit" class="btn-primary">Send Invite</button>
        </form>
    </div>
</main>
</body>
</html>

