<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}


// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch all warehouses
$stmt = $conn->prepare("SELECT * FROM warehouses");
$stmt->execute();
$warehouses_result = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Management</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Warehouse Management</h1>

    <div class="warehouse-actions">
        <a href="add_warehouse" class="button">Add New Warehouse</a>
        <a href="assign_warehouse" class="button">Assign Warehouse to Project</a>
    </div>

    <div class="warehouses-list">
        <?php if ($warehouses_result->num_rows > 0): ?>
            <?php while ($warehouse = $warehouses_result->fetch_assoc()): ?>
                <div class="warehouse-card">
                    <div class="warehouse-image">
                        <?php if (!empty($warehouse['image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($warehouse['image_url']); ?>" alt="<?php echo htmlspecialchars($warehouse['name']); ?>">
                        <?php else: ?>
                            <img src="default_warehouse.png" alt="Default Warehouse Image">
                        <?php endif; ?>
                    </div>
                    <div class="warehouse-info">
                        <h2><?php echo htmlspecialchars($warehouse['name']); ?></h2>
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($warehouse['address']); ?></p>
                        <p><strong>In Fee:</strong> $<?php echo number_format($warehouse['in_fee'], 2); ?></p>
                        <p><strong>Out Fee:</strong> $<?php echo number_format($warehouse['out_fee'], 2); ?></p>
                        <p><strong>Monthly Storage Fee:</strong> $<?php echo number_format($warehouse['monthly_storage_fee'], 2); ?></p>
                        <p>
                            <a href="edit_warehouse?warehouse_id=<?php echo $warehouse['id']; ?>" class="button">Edit</a>
                            <a href="delete_warehouse?warehouse_id=<?php echo $warehouse['id']; ?>" class="button delete-button" onclick="return confirm('Are you sure you want to delete this warehouse?');">Delete</a>
                        </p>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>No warehouses found.</p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
<?php
$conn->close();
?>
