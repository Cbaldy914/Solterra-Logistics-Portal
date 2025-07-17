<?php
session_name("logistics_session");
session_start();

// Only admin/global_admin can manage
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Initialize messages
$success_message = '';
$error_message = '';

// Handle account delete (optional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    $account_id = intval($_POST['account_id']);

    // Basic safety check: maybe you can't delete an account with active users, etc.
    // For now, let's just do it:
    $stmtDel = $conn->prepare("DELETE FROM customer_accounts WHERE id = ?");
    $stmtDel->bind_param("i", $account_id);
    if ($stmtDel->execute()) {
        $success_message = "Account deleted successfully.";
    } else {
        $error_message = "Error deleting account: " . $stmtDel->error;
    }
    $stmtDel->close();
}

// Fetch all accounts
$sqlAccounts = "
    SELECT ca.id AS account_id, ca.name AS account_name, ca.created_at
    FROM customer_accounts ca
    ORDER BY ca.id ASC
";
$resultAccounts = $conn->query($sqlAccounts);

$accounts = [];
while ($row = $resultAccounts->fetch_assoc()) {
    $accounts[] = $row;
}
$resultAccounts->close();

// We'll also fetch the user-list for each account
// Alternatively, you could do a single JOIN query, but let's keep it simple.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accounts</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .container {
            padding: 20px;
        }
        
        .page-header {
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .add-account-btn {
            display: inline-block;
            background-color: #488C9A;
            color: white;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 4px;
            font-weight: 500;
            margin: 10px 0;
        }
        
        .add-account-btn:hover {
            background-color: #3A7A87;
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
        
        .user-list {
            margin: 0;
            padding: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .user-item {
            background-color: #f0f0f0;
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            margin-bottom: 0;
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
        
        /* Fix for action column */
        td form {
            display: inline-block;
            width: auto;
            background: transparent;
        }
        
        /* Fix for badge alignment */
        .role-badge {
            display: inline-block;
            background-color: #488C9A;
            color: white;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 12px;
            margin-left: 5px;
            line-height: 1;
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
        
        .no-users {
            font-style: italic;
            color: #666;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <span class="separator">&raquo;</span>
        <span>Manage Accounts</span>
    </div>

    <div class="page-header">
        <h1>Manage Customer Accounts</h1>
        <a href="add_account.php" class="add-account-btn">Create a New Account</a>
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
                    <th>Account ID</th>
                    <th>Account Name</th>
                    <th>Created At</th>
                    <th>Users in Account</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $acc): ?>
                <tr>
                    <td><?php echo $acc['account_id']; ?></td>
                    <td><?php echo htmlspecialchars($acc['account_name']); ?></td>
                    <td><?php echo $acc['created_at']; ?></td>
                    <td>
                        <div class="user-list">
                        <?php
                        // For each account, fetch the assigned users
                        $stmtUsers = $conn->prepare("
                            SELECT u.id as user_id, u.username, cau.role
                            FROM customer_account_users cau
                            JOIN users u ON cau.user_id = u.id
                            WHERE cau.account_id = ?
                        ");
                        $stmtUsers->bind_param("i", $acc['account_id']);
                        $stmtUsers->execute();
                        $resUsers = $stmtUsers->get_result();

                        if ($resUsers->num_rows > 0) {
                            while ($userRow = $resUsers->fetch_assoc()) {
                                echo "<div class='user-item'>";
                                echo htmlspecialchars($userRow['username']) 
                                    . " <span class='role-badge'>" . htmlspecialchars($userRow['role']) . "</span>";
                                echo "</div>";
                            }
                        } else {
                            echo "<div class='no-users'>No users assigned</div>";
                        }
                        $stmtUsers->close();
                        ?>
                        </div>
                    </td>
                    <td>
                        <!-- Example: Delete the account. 
                            (In real life, you'd confirm or handle the bridging rows first.) -->
                        <form method="POST" action="manage_accounts.php" 
                            onsubmit="return confirm('Are you sure you want to delete this account? This will NOT delete the users associated with this account, but will remove their association.');">
                            <input type="hidden" name="account_id" value="<?php echo $acc['account_id']; ?>">
                            <button type="submit" name="delete_account" class="delete-button">Delete Account</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

</body>
</html>
<?php
$conn->close();
