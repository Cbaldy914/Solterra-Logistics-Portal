<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized.php"); // Redirect to an unauthorized page
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

$warehouses = [];
$errorMessage = '';

try {
    // Fetch warehouses along with a count of pallets currently stored
    $sql = "SELECT 
                w.id, 
                w.name, 
                w.address, 
                COUNT(ip.id) AS current_pallet_count
            FROM warehouses w
            LEFT JOIN inventory_pallets ip ON w.id = ip.current_warehouse_id AND ip.status = 'In Warehouse'
            GROUP BY w.id, w.name, w.address
            ORDER BY w.name ASC";
            
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $warehouses[] = $row;
        }
    } else {
        throw new Exception("Error fetching warehouses: " . $conn->error);
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Warehouses</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        .action-buttons.add-new {
            display: inline-block;
            padding: 8px 15px;
            background-color: #488C9A; /* Example color */
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .error-message {
            color: red;
            background-color: #ffe6e6;
            padding: 10px;
            border: 1px solid red;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="header-container">
        <h1>Manage Warehouses</h1>
        <a href="add_warehouse.php" class="action-buttons add-new">Add New Warehouse</a>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Current Pallets Stored</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($warehouses)): ?>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($warehouse['name']); ?></td>
                            <td><?php echo htmlspecialchars($warehouse['address']); ?></td>
                            <td><?php echo number_format($warehouse['current_pallet_count']); ?></td>
                            <td>
                                <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo $warehouse['id']; ?>" class="action-buttons">View Inventory</a>
                                <!-- Optional: Add Edit link if needed -->
                                <!-- <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse['id']; ?>" class="action-button">Edit</a> -->
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">No warehouses found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div class="back-link" style="margin-top: 20px;">
        <a href="admin_dashboard.php" class="action-buttons">&larr; Back to Admin Dashboard</a>
    </div>
</main>
</body>
</html> 