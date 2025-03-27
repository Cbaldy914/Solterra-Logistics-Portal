<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: login");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch all warehouse estimates
$sql = "SELECT id, user_id, name, estimate_data, created_at FROM warehouse_quotes ORDER BY created_at DESC";
$result = $conn->query($sql);
$estimates = [];
while ($row = $result->fetch_assoc()) {
    $estimates[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ... existing head content ... -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Warehouse Estimates</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
    <main>
        <h1>Warehouse Estimates</h1>

        <table>
            <tr>
                <th>Name</th>
                <th>User ID</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($estimates as $estimate): ?>
                <tr>
                    <td><?php echo htmlspecialchars($estimate['name']); ?></td>
                    <td><?php echo htmlspecialchars($estimate['user_id']); ?></td>
                    <td><?php echo htmlspecialchars($estimate['created_at']); ?></td>
                    <td>
                        <a href="admin_warehouse_estimate_view?id=<?php echo $estimate['id']; ?>">View / Add Quotes</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </main>
</body>
</html>
