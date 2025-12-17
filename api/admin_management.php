<?php
/**
 * Admin Management API
 * Handles CRUD operations for users, accounts, and invitations
 * Only accessible by global_admin role
 */

session_name("logistics_session");
session_start();

header('Content-Type: application/json');

// Only global admin can access this API
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../../config.php';

$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$action = $_POST['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

try {
    switch ($action) {
        // ==================== USER OPERATIONS ====================
        case 'add_user':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $account_id = intval($_POST['account_id'] ?? 0);
            $role = trim($_POST['role'] ?? 'user');

            // Validation
            if (empty($username) || empty($password) || empty($account_id) || empty($role)) {
                $response = ['success' => false, 'message' => 'All fields are required'];
                break;
            }

            if (!in_array($role, ['user', 'customer_admin', 'admin', 'DDPm', 'global_admin'])) {
                $response = ['success' => false, 'message' => 'Invalid role selected'];
                break;
            }

            if (strlen($password) < 8) {
                $response = ['success' => false, 'message' => 'Password must be at least 8 characters'];
                break;
            }

            $conn->begin_transaction();

            // Check if username exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                $response = ['success' => false, 'message' => "Username '$username' already exists"];
                break;
            }
            $stmt->close();

            // Insert user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->bind_param("ss", $username, $hashed_password);
            $stmt->execute();
            $user_id = $stmt->insert_id;
            $stmt->close();

            // Insert bridging row
            $stmt = $conn->prepare("INSERT INTO customer_account_users (user_id, account_id, role) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $user_id, $account_id, $role);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $response = ['success' => true, 'message' => "User '$username' created successfully", 'user_id' => $user_id];
            break;

        case 'update_user':
            $user_id = intval($_POST['user_id'] ?? 0);
            $password = $_POST['password'] ?? '';
            $account_id = intval($_POST['account_id'] ?? 0);
            $role = trim($_POST['role'] ?? '');

            if (empty($user_id) || empty($account_id) || empty($role)) {
                $response = ['success' => false, 'message' => 'User ID, account, and role are required'];
                break;
            }

            if (!in_array($role, ['user', 'customer_admin', 'admin', 'DDPm', 'global_admin'])) {
                $response = ['success' => false, 'message' => 'Invalid role selected'];
                break;
            }

            $conn->begin_transaction();

            // Update password if provided
            if (!empty($password)) {
                if (strlen($password) < 8) {
                    $response = ['success' => false, 'message' => 'Password must be at least 8 characters'];
                    break;
                }
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $user_id);
                $stmt->execute();
                $stmt->close();
            }

            // Check if bridging row exists
            $stmt = $conn->prepare("SELECT id FROM customer_account_users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();

            if ($result->num_rows > 0) {
                // Update existing bridging row
                $stmt = $conn->prepare("UPDATE customer_account_users SET account_id = ?, role = ? WHERE user_id = ?");
                $stmt->bind_param("isi", $account_id, $role, $user_id);
            } else {
                // Insert new bridging row
                $stmt = $conn->prepare("INSERT INTO customer_account_users (user_id, account_id, role) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $user_id, $account_id, $role);
            }
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $response = ['success' => true, 'message' => 'User updated successfully'];
            break;

        case 'delete_user':
            $user_id = intval($_POST['user_id'] ?? 0);

            if (empty($user_id)) {
                $response = ['success' => false, 'message' => 'User ID is required'];
                break;
            }

            // Prevent deleting yourself
            if ($user_id == $_SESSION['user_id']) {
                $response = ['success' => false, 'message' => 'You cannot delete your own account'];
                break;
            }

            $conn->begin_transaction();

            // Delete from forecast_projects
            $stmt = $conn->prepare("DELETE FROM forecast_projects WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            // Delete from bridging table
            $stmt = $conn->prepare("DELETE FROM customer_account_users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            // Delete from users
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $response = ['success' => true, 'message' => 'User deleted successfully'];
            break;

        // ==================== ACCOUNT OPERATIONS ====================
        case 'add_account':
            $name = trim($_POST['name'] ?? '');

            if (empty($name)) {
                $response = ['success' => false, 'message' => 'Account name is required'];
                break;
            }

            // Check if account name exists
            $stmt = $conn->prepare("SELECT id FROM customer_accounts WHERE name = ?");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                $response = ['success' => false, 'message' => "Account '$name' already exists"];
                break;
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO customer_accounts (name) VALUES (?)");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $account_id = $stmt->insert_id;
            $stmt->close();

            $response = ['success' => true, 'message' => "Account '$name' created successfully", 'account_id' => $account_id];
            break;

        case 'update_account':
            $account_id = intval($_POST['account_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');

            if (empty($account_id) || empty($name)) {
                $response = ['success' => false, 'message' => 'Account ID and name are required'];
                break;
            }

            // Check if name is taken by another account
            $stmt = $conn->prepare("SELECT id FROM customer_accounts WHERE name = ? AND id != ?");
            $stmt->bind_param("si", $name, $account_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                $response = ['success' => false, 'message' => "Account name '$name' is already taken"];
                break;
            }
            $stmt->close();

            $stmt = $conn->prepare("UPDATE customer_accounts SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $account_id);
            $stmt->execute();
            $stmt->close();

            $response = ['success' => true, 'message' => 'Account updated successfully'];
            break;

        case 'delete_account':
            $account_id = intval($_POST['account_id'] ?? 0);

            if (empty($account_id)) {
                $response = ['success' => false, 'message' => 'Account ID is required'];
                break;
            }

            $conn->begin_transaction();

            // Delete bridging rows
            $stmt = $conn->prepare("DELETE FROM customer_account_users WHERE account_id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $stmt->close();

            // Delete invitations for this account
            $stmt = $conn->prepare("DELETE FROM user_invitations WHERE account_id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $stmt->close();

            // Delete the account
            $stmt = $conn->prepare("DELETE FROM customer_accounts WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $response = ['success' => true, 'message' => 'Account deleted successfully'];
            break;

        // ==================== INVITATION OPERATIONS ====================
        case 'send_invitation':
            $email = trim($_POST['email'] ?? '');
            $account_id = intval($_POST['account_id'] ?? 0);
            $role = trim($_POST['role'] ?? 'user');

            if (empty($email) || empty($account_id) || empty($role)) {
                $response = ['success' => false, 'message' => 'Email, account, and role are required'];
                break;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response = ['success' => false, 'message' => 'Invalid email format'];
                break;
            }

            if (!in_array($role, ['user', 'customer_admin', 'admin', 'DDPm', 'global_admin'])) {
                $response = ['success' => false, 'message' => 'Invalid role selected'];
                break;
            }

            // Check if email already has a pending invitation for this account
            $stmt = $conn->prepare("SELECT id FROM user_invitations WHERE email = ? AND account_id = ? AND used_at IS NULL AND expires_at > NOW()");
            $stmt->bind_param("si", $email, $account_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                $response = ['success' => false, 'message' => 'A pending invitation already exists for this email and account'];
                break;
            }
            $stmt->close();

            // Generate token and expiry
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
            $invited_by = $_SESSION['user_id'];

            $stmt = $conn->prepare("INSERT INTO user_invitations (email, token, account_id, role, invited_by, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssisss", $email, $token, $account_id, $role, $invited_by, $expires_at);
            $stmt->execute();
            $invite_id = $stmt->insert_id;
            $stmt->close();

            // TODO: Send actual email with invitation link
            // For now, just log it
            error_log("Invitation sent to $email with token $token");

            $response = ['success' => true, 'message' => "Invitation sent to $email", 'invite_id' => $invite_id];
            break;

        case 'resend_invitation':
            $invite_id = intval($_POST['invite_id'] ?? 0);

            if (empty($invite_id)) {
                $response = ['success' => false, 'message' => 'Invitation ID is required'];
                break;
            }

            // Get the invitation
            $stmt = $conn->prepare("SELECT email, token FROM user_invitations WHERE id = ? AND used_at IS NULL");
            $stmt->bind_param("i", $invite_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $invite = $result->fetch_assoc();
            $stmt->close();

            if (!$invite) {
                $response = ['success' => false, 'message' => 'Invitation not found or already used'];
                break;
            }

            // Update expiry
            $new_expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
            $stmt = $conn->prepare("UPDATE user_invitations SET expires_at = ? WHERE id = ?");
            $stmt->bind_param("si", $new_expires_at, $invite_id);
            $stmt->execute();
            $stmt->close();

            // TODO: Send actual email
            error_log("Invitation resent to {$invite['email']} with token {$invite['token']}");

            $response = ['success' => true, 'message' => "Invitation resent to {$invite['email']}"];
            break;

        case 'cancel_invitation':
            $invite_id = intval($_POST['invite_id'] ?? 0);

            if (empty($invite_id)) {
                $response = ['success' => false, 'message' => 'Invitation ID is required'];
                break;
            }

            $stmt = $conn->prepare("DELETE FROM user_invitations WHERE id = ? AND used_at IS NULL");
            $stmt->bind_param("i", $invite_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $response = ['success' => true, 'message' => 'Invitation cancelled'];
            } else {
                $response = ['success' => false, 'message' => 'Invitation not found or already used'];
            }
            $stmt->close();
            break;

        // ==================== GET OPERATIONS ====================
        case 'get_users':
            $sql = "
                SELECT
                    u.id AS user_id,
                    u.username,
                    u.email,
                    u.first_name,
                    u.last_name,
                    u.created_at,
                    cau.role AS account_role,
                    cau.account_id,
                    ca.name AS account_name
                FROM users u
                LEFT JOIN customer_account_users cau ON u.id = cau.user_id
                LEFT JOIN customer_accounts ca ON cau.account_id = ca.id
                ORDER BY u.id ASC
            ";
            $result = $conn->query($sql);
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $response = ['success' => true, 'data' => $users];
            break;

        case 'get_accounts':
            $sql = "
                SELECT
                    ca.id,
                    ca.name,
                    ca.created_at,
                    COUNT(cau.user_id) AS user_count
                FROM customer_accounts ca
                LEFT JOIN customer_account_users cau ON ca.id = cau.account_id
                GROUP BY ca.id
                ORDER BY ca.name ASC
            ";
            $result = $conn->query($sql);
            $accounts = [];
            while ($row = $result->fetch_assoc()) {
                $accounts[] = $row;
            }
            $response = ['success' => true, 'data' => $accounts];
            break;

        case 'get_invitations':
            $sql = "
                SELECT
                    ui.id,
                    ui.email,
                    ui.account_id,
                    ui.role,
                    ui.invited_by,
                    ui.created_at,
                    ui.expires_at,
                    ca.name AS account_name,
                    inv_user.username AS invited_by_username
                FROM user_invitations ui
                LEFT JOIN customer_accounts ca ON ui.account_id = ca.id
                LEFT JOIN users inv_user ON ui.invited_by = inv_user.id
                WHERE ui.used_at IS NULL AND ui.expires_at > NOW()
                ORDER BY ui.created_at DESC
            ";
            $result = $conn->query($sql);
            $invitations = [];
            while ($row = $result->fetch_assoc()) {
                $invitations[] = $row;
            }
            $response = ['success' => true, 'data' => $invitations];
            break;

        default:
            $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
    }
} catch (Exception $e) {
    if ($conn->inTransaction ?? false) {
        $conn->rollback();
    }
    error_log("Admin Management API Error: " . $e->getMessage());
    $response = ['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()];
}

$conn->close();
echo json_encode($response);
