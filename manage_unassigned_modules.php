<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch unassigned modules
$sql = "
    SELECT um.*, u.username
    FROM unassigned_modules um
    INNER JOIN users u ON um.user_id = u.id
    ORDER BY um.id ASC
";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Unassigned Modules</h1>
    <a href="add_unassigned_module">Add Unassigned Module</a><br><br>
    <table class="styled-table">
        <thead>
            <tr>
                <th>Module ID</th>
                <th>User ID</th>
                <th>Username</th>
                <th>Vendor</th>
                <th>Wattage</th>
                <th>Quantity</th>
                <th>Current Location</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while($module = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($module['id']); ?></td>
                    <td><?php echo htmlspecialchars($module['user_id']); ?></td>
                    <td><?php echo htmlspecialchars($module['username']); ?></td>
                    <td><?php echo htmlspecialchars($module['vendor']); ?></td>
                    <td><?php echo htmlspecialchars(number_format($module['wattage'])); ?> W</td>
                    <td><?php echo htmlspecialchars(number_format($module['quantity'])); ?></td>
                    <td><?php echo htmlspecialchars($module['current_location']); ?></td>
                    <td>
                        <a href="edit_unassigned_module?id=<?php echo $module['id']; ?>">Edit</a> |
                        <a href="delete_unassigned_module?id=<?php echo $module['id']; ?>" onclick="return confirm('Are you sure you want to delete this module?');">Delete</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="8">No unassigned modules found.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
    <br>
    <a href="admin_dashboard">Back to Admin Dashboard</a>
</main>
</body>
</html>
<?php
$conn->close();
?>
