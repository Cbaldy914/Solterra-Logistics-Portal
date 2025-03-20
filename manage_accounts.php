<?php
session_name("logistics_session");
session_start();

// Only admin/global_admin can manage
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname     = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle account delete (optional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    $account_id = intval($_POST['account_id']);

    // Basic safety check: maybe you can’t delete an account with active users, etc.
    // For now, let’s just do it:
    $stmtDel = $conn->prepare("DELETE FROM customer_accounts WHERE id = ?");
    $stmtDel->bind_param("i", $account_id);
    $stmtDel->execute();
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

// We’ll also fetch the user‐list for each account
// Alternatively, you could do a single JOIN query, but let's keep it simple.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Accounts</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>

<h1>Manage Customer Accounts</h1>

<p><a href="add_account">Create a New Account</a></p>

<table border="1" cellpadding="8" cellspacing="0">
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
                        echo "<div>";
                        echo "User ID: " . $userRow['user_id'] 
                            . " | Username: " . htmlspecialchars($userRow['username']) 
                            . " | Role: " . htmlspecialchars($userRow['role']);
                        echo "</div>";
                    }
                } else {
                    echo "<i>No users assigned</i>";
                }
                $stmtUsers->close();
                ?>
            </td>
            <td>
                <!-- Example: Delete the account. 
                     (In real life, you’d confirm or handle the bridging rows first.) -->
                <form method="POST" action="manage_accounts" 
                      onsubmit="return confirm('Are you sure you want to delete this account?');">
                    <input type="hidden" name="account_id" value="<?php echo $acc['account_id']; ?>">
                    <button type="submit" name="delete_account">Delete Account</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
<?php
$conn->close();
