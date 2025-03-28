<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch all projects including calculated project_size
$sql = "
SELECT p.id, p.project_name, u.username, 
       SUM(pwo.wattage * pwo.total_order) AS project_size
FROM projects p
INNER JOIN users u ON p.user_id = u.id
LEFT JOIN project_wattage_orders pwo ON p.id = pwo.project_id
GROUP BY p.id, p.project_name, u.username
ORDER BY p.id ASC
";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Projects</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .action-button {
            background-color: #488C9A;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            padding: 7px 15px;
            margin: 5px;
            font-weight: bold;
            cursor: pointer;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <main>
        <h1>Manage Projects</h1>
        <table border="1" cellpadding="10" cellspacing="0">
            <tr>
                <th>Customer</th>
                <th>Project Name</th>
                <th>Project Size (MW)</th>
                <th>Actions</th>
            </tr>
            <?php if ($result->num_rows > 0): ?>
                <?php while($project = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($project['username']); ?></td>
                        <td><?php echo htmlspecialchars($project['project_name']); ?></td>
                        <td>
                            <?php 
                            $project_size = $project['project_size'];
                            if ($project_size !== null) {
                                // Convert to MW if wattage is in Watts
                                $project_size_mw = $project_size / 1000000;
                                echo number_format($project_size_mw, 2) . ' MW';
                            } else {
                                echo '0.00 MW';
                            }
                            ?>
                        </td>
                        <td>
                        <form style="display: inline-block; margin: 0 2px;" action="edit_project" method="GET">
                                <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                <button type="submit" class="action-button">Edit</button>
                            </form>

                            <form style="display: inline-block; margin: 0 2px;" action="delete_project" method="GET" onsubmit="return confirm('Are you sure you want to delete this project?');">
                                <input type="hidden" name="id" value="<?php echo $project['id']; ?>">
                                <button type="submit" class="action-button">Delete</button>
                            </form>

                            <form style="display: inline-block; margin: 0 2px;" action="manage_deliveries" method="GET">
                                <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                <button type="submit" class="action-button">Deliveries</button>
                            </form>

                            <form style="display: inline-block; margin: 0 2px;" action="warehouse_info" method="GET">
                                <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                <button type="submit" class="action-button">Warehouse</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">No projects found.</td>
                </tr>
            <?php endif; ?>
        </table>
        <br>
    </main>
</body>
</html>

<?php
$conn->close();
?>
