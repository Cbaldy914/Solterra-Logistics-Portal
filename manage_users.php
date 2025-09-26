<?php
session_name("logistics_session");
session_start();

// Only global admin
if ($_SESSION['role'] !== 'global_admin') {
    header("Location: dashboard");
    exit();
}

// DB Connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Messages
$success_message = '';
$error_message   = '';

// Handle delete user request
if (isset($_POST['delete_user'])) {
    $user_id_to_delete = intval($_POST['user_id']);

    // Prevent deleting your own account
    if ($user_id_to_delete == $_SESSION['user_id']) {
        $error_message = "You cannot delete your own account.";
    } else {
        // Begin transaction for safe deletion
        $conn->begin_transaction();
        
        try {
            // 1) Delete from forecast_projects table first (remove dependency)
            $stmtDelProjects = $conn->prepare("DELETE FROM forecast_projects WHERE user_id = ?");
            $stmtDelProjects->bind_param("i", $user_id_to_delete);
            $stmtDelProjects->execute();
            $stmtDelProjects->close();
            
            // 2) Delete from bridging table
            $stmtDelBridge = $conn->prepare("DELETE FROM customer_account_users WHERE user_id = ?");
            $stmtDelBridge->bind_param("i", $user_id_to_delete);
            $stmtDelBridge->execute();
            $stmtDelBridge->close();

            // 3) Delete from users table
            $stmtDelUser = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmtDelUser->bind_param("i", $user_id_to_delete);
            $stmtDelUser->execute();
            $stmtDelUser->close();
            
            // Commit all changes if successful
            $conn->commit();
            $success_message = "User (and related data) deleted successfully.";
            
        } catch (Exception $e) {
            // Rollback changes if any step fails
            $conn->rollback();
            $error_message = "Error deleting user: " . $e->getMessage();
        }
    }
}

// Handle edit role request
if (isset($_POST['edit_role'])) {
    $user_id_to_edit = intval($_POST['user_id']);
    $new_role = $_POST['new_role'];

    // Basic validation
    if (!in_array($new_role, ['admin','user','DDPm','global_admin'])) {
        $error_message = "Invalid role selected.";
    } else {
        if ($user_id_to_edit == $_SESSION['user_id']) {
            $error_message = "You cannot change your own role here.";
        } else {
            // Update bridging table role
            $stmtEdit = $conn->prepare("UPDATE customer_account_users SET role = ? WHERE user_id = ?");
            $stmtEdit->bind_param("si", $new_role, $user_id_to_edit);
            if ($stmtEdit->execute()) {
                $success_message = "User role updated successfully.";
            } else {
                $error_message = "Error updating role: " . $stmtEdit->error;
            }
            $stmtEdit->close();
        }
    }
}

// Fetch all users & their account/role from the bridging table
$sql = "
    SELECT 
        u.id AS user_id,
        u.username,
        u.created_at,
        cau.role AS account_role,
        ca.name AS account_name
    FROM users u
    LEFT JOIN customer_account_users cau ON u.id = cau.user_id
    LEFT JOIN customer_accounts ca ON cau.account_id = ca.id
    ORDER BY u.id ASC
";
$result = $conn->query($sql);

$users = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}
$result->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .container {
            padding: 20px;
        }
        
        .page-header {
            margin-bottom: 25px;
        }
        
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            font-weight: 500;
        }
        
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            font-weight: 500;
        }
        
        .table-container {
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        table th,
        table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        table th {
            background-color: #293E4C;
            color: white;
            font-weight: 500;
        }
        
        table tr:hover {
            background-color: #f5f5f5;
        }
        
        .role-select {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-right: 5px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .submit-button {
            background-color: #488C9A;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }
        
        .submit-button:hover {
            background-color: #3A7A87;
        }
        
        .delete-button {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }
        
        .delete-button:hover {
            background-color: #bd2130;
        }
        
        /* Fix for actions column */
        td .action-buttons {
            background: transparent;
            width: auto;
        }
        
        td .action-buttons form {
            display: inline-block;
            margin-right: 5px;
            background: transparent;
        }
        
        .add-user-btn {
            display: inline-block;
            background-color: #488C9A;
            color: white;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .add-user-btn:hover {
            background-color: #3A7A87;
        }
        
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
        }
        
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="container">
        <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Manage Users']); ?>
        
        <div class="page-header">
            <h1>Manage Users</h1>
            <a href="add_user.php" class="add-user-btn">Add New User</a>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Username</th>
                        <th>Account Name</th>
                        <th>Role (within Account)</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($users)): ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['account_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['account_role'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <!-- Edit Role Form -->
                                    <form method="POST" action="manage_users">
                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                        <select name="new_role" class="role-select">
                                            <option value="user"  <?php if ($user['account_role'] == 'user') echo 'selected'; ?>>User</option>
                                            <option value="admin" <?php if ($user['account_role'] == 'admin') echo 'selected'; ?>>Admin</option>
                                            <option value="DDPm"  <?php if ($user['account_role'] == 'DDPm') echo 'selected'; ?>>DDPm</option>
                                            <option value="global_admin" <?php if ($user['account_role'] == 'global_admin') echo 'selected'; ?>>Global Admin</option>
                                        </select>
                                        <input type="submit" name="edit_role" value="Update Role" class="submit-button">
                                    </form>

                                    <!-- Delete User Form -->
                                    <form method="POST" action="manage_users"
                                        onsubmit="return confirm('Are you sure you want to delete this user?');">
                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                        <input type="submit" name="delete_user" value="Delete User" class="delete-button">
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
