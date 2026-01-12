<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and is an admin
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

// Fetch all warehouses with their cost structures
$stmt = $conn->prepare("
    SELECT w.*, 
           GROUP_CONCAT(
               CONCAT(wci.label, ':', wci.amount, ':', wci.trigger_event) 
               ORDER BY wci.trigger_event, wci.label 
               SEPARATOR '|'
           ) as cost_items
    FROM warehouses w
    LEFT JOIN warehouse_cost_items wci ON w.id = wci.warehouse_id AND wci.is_active = 1
    GROUP BY w.id
    ORDER BY w.name
");
$stmt->execute();
$warehouses_result = $stmt->get_result();

// Process warehouse data to parse cost items
$warehouses_data = [];
while ($warehouse = $warehouses_result->fetch_assoc()) {
    $warehouse['parsed_costs'] = [];
    if (!empty($warehouse['cost_items'])) {
        $cost_items = explode('|', $warehouse['cost_items']);
        foreach ($cost_items as $item) {
            if (!empty($item)) {
                list($label, $amount, $trigger) = explode(':', $item);
                $warehouse['parsed_costs'][$trigger][] = [
                    'label' => $label,
                    'amount' => floatval($amount),
                    'trigger_event' => $trigger
                ];
            }
        }
    }
    $warehouses_data[] = $warehouse;
}
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .warehouse-image-placeholder {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }
        .warehouse-image-placeholder i {
            font-size: 4rem;
            color: #488C9A;
            opacity: 0.7;
        }
    </style>
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
        <?php if (!empty($warehouses_data)): ?>
            <?php foreach ($warehouses_data as $warehouse): ?>
                <div class="warehouse-card">
                    <div class="warehouse-image">
                        <?php if (!empty($warehouse['image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($warehouse['image_url']); ?>" alt="<?php echo htmlspecialchars($warehouse['name']); ?>">
                        <?php else: ?>
                            <div class="warehouse-image-placeholder">
                                <i class="fas fa-warehouse"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="warehouse-info">
                        <h2><?php echo htmlspecialchars($warehouse['name']); ?>
                            <?php if (!empty($warehouse['is_port']) && $warehouse['is_port'] == 1): ?>
                                <span style="background: #007cba; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; margin-left: 8px;">PORT</span>
                            <?php endif; ?>
                        </h2>
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($warehouse['address']); ?></p>
                        
                        <?php if (!empty($warehouse['parsed_costs'])): ?>
                            <div class="cost-structure">
                                <strong>Cost Structure:</strong>
                                <?php foreach ($warehouse['parsed_costs'] as $trigger => $costs): ?>
                                    <?php foreach ($costs as $cost): ?>
                                        <p style="margin: 5px 0 5px 15px;">
                                            <strong><?php echo htmlspecialchars($cost['label']); ?>:</strong> 
                                            $<?php echo number_format($cost['amount'], 2); ?>
                                            <?php if ($trigger === 'monthly'): ?> per pallet/month<?php endif; ?>
                                            <?php if ($trigger === 'entry' || $trigger === 'exit'): ?> per pallet<?php endif; ?>
                                        </p>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p><em>No cost structure defined</em></p>
                        <?php endif; ?>
                        
                        <p style="margin-top: 15px;">
                            <a href="edit_warehouse?warehouse_id=<?php echo $warehouse['id']; ?>" class="button">Edit</a>
                            <a href="delete_warehouse?warehouse_id=<?php echo $warehouse['id']; ?>" class="button delete-button" onclick="return confirm('Are you sure you want to delete this warehouse?');">Delete</a>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
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
