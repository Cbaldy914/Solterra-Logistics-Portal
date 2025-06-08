<?php
session_name("logistics_session");
session_start();
require_once '../config.php';

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'global_admin' && $_SESSION['role'] != 'admin')) {
    header("Location: unauthorized");
    exit();
}

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rebalance_555w'])) {
    try {
        $conn->begin_transaction();
        
        // Step 1: Get ALL 555W deliveries (with or without pallets)
        $stmt_deliveries = $conn->prepare("
            SELECT d.id, d.quantity as required_modules, 
                   COUNT(dp.inventory_pallet_id) as current_pallets,
                   COALESCE(SUM(ip.quantity), 0) as current_modules
            FROM deliveries d
            LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id  
            LEFT JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
            WHERE d.wattage = '555'
            GROUP BY d.id, d.quantity
            ORDER BY d.id
        ");
        $stmt_deliveries->execute();
        $result_deliveries = $stmt_deliveries->get_result();
        $deliveries_data = $result_deliveries->fetch_all(MYSQLI_ASSOC);
        $stmt_deliveries->close();
        
        // Step 2: Collect all over-allocated pallets
        $over_allocated_pallets = [];
        $deliveries_needing_pallets = [];
        
        foreach ($deliveries_data as $delivery) {
            $required = $delivery['required_modules'];
            $current = $delivery['current_modules'];
            
            if ($current > $required) {
                // Over-allocated - collect excess pallets
                $excess_modules = $current - $required;
                
                // Only process if this delivery actually has more modules than required
                if ($excess_modules > 0) {
                    // Get pallets for this delivery, ordered by ID (take latest first for removal)
                    $stmt_pallets = $conn->prepare("
                        SELECT ip.id, ip.quantity 
                        FROM delivery_pallets dp 
                        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id 
                        WHERE dp.delivery_id = ? AND ip.id IS NOT NULL
                        ORDER BY ip.id DESC
                    ");
                    $stmt_pallets->bind_param("i", $delivery['id']);
                    $stmt_pallets->execute();
                    $pallets_result = $stmt_pallets->get_result();
                    
                    $modules_to_remove = $excess_modules;
                    while (($pallet = $pallets_result->fetch_assoc()) && $modules_to_remove > 0) {
                        if ($pallet && $pallet['id'] && $pallet['quantity'] <= $modules_to_remove) {
                            // Remove this entire pallet
                            $over_allocated_pallets[] = [
                                'pallet_id' => $pallet['id'],
                                'quantity' => $pallet['quantity'],
                                'from_delivery' => $delivery['id']
                            ];
                            $modules_to_remove -= $pallet['quantity'];
                        }
                    }
                    $stmt_pallets->close();
                }
            } elseif ($current < $required) {
                // Under-allocated
                $needed_modules = $required - $current;
                $deliveries_needing_pallets[] = [
                    'delivery_id' => $delivery['id'],
                    'needed_modules' => $needed_modules
                ];
            }
        }
        
        // Step 3: Remove over-allocated pallets from their deliveries
        $stmt_unlink = $conn->prepare("DELETE FROM delivery_pallets WHERE delivery_id = ? AND inventory_pallet_id = ?");
        $stmt_update_status = $conn->prepare("UPDATE inventory_pallets SET status = 'In Warehouse' WHERE id = ?");
        
        $removed_count = 0;
        foreach ($over_allocated_pallets as $pallet_info) {
            // Validate pallet_id is not null
            if ($pallet_info['pallet_id'] && is_numeric($pallet_info['pallet_id'])) {
                $stmt_unlink->bind_param("ii", $pallet_info['from_delivery'], $pallet_info['pallet_id']);
                if ($stmt_unlink->execute()) {
                    $stmt_update_status->bind_param("i", $pallet_info['pallet_id']);
                    $stmt_update_status->execute();
                    $removed_count++;
                }
            }
        }
        $stmt_unlink->close();
        $stmt_update_status->close();
        
        // Step 4: Redistribute freed pallets to deliveries that need them
        $stmt_link = $conn->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
        $stmt_update_assigned = $conn->prepare("UPDATE inventory_pallets SET status = 'Assigned to Delivery' WHERE id = ?");
        
        $pallet_index = 0;
        $redistributed_count = 0;
        
        foreach ($deliveries_needing_pallets as $delivery_need) {
            $modules_assigned = 0;
            
            while ($modules_assigned < $delivery_need['needed_modules'] && $pallet_index < count($over_allocated_pallets)) {
                $pallet = $over_allocated_pallets[$pallet_index];
                
                // Validate both delivery_id and pallet_id are valid
                if ($pallet && $pallet['pallet_id'] && is_numeric($pallet['pallet_id']) && 
                    $delivery_need['delivery_id'] && is_numeric($delivery_need['delivery_id'])) {
                    
                    // Link this pallet to the delivery that needs it
                    $stmt_link->bind_param("ii", $delivery_need['delivery_id'], $pallet['pallet_id']);
                    if ($stmt_link->execute()) {
                        $stmt_update_assigned->bind_param("i", $pallet['pallet_id']);
                        $stmt_update_assigned->execute();
                        
                        $modules_assigned += $pallet['quantity'];
                        $redistributed_count++;
                    }
                }
                $pallet_index++;
            }
        }
        
        $stmt_link->close();
        $stmt_update_assigned->close();
        
        $conn->commit();
        
        $messages[] = "<p class='success-message'>Rebalancing completed! Redistributed $redistributed_count pallets. " . 
                     (count($over_allocated_pallets) - $redistributed_count) . " pallets returned to available inventory.</p>";
        
    } catch (Exception $e) {
        $conn->rollback();
        $messages[] = "<p class='error-message'>Rebalancing failed: " . $e->getMessage() . "</p>";
    }
}

// Display current status
$stmt_status = $conn->prepare("
    SELECT d.id, d.quantity as required_modules, 
           COUNT(dp.inventory_pallet_id) as current_pallets,
           COALESCE(SUM(ip.quantity), 0) as current_modules,
           CASE 
               WHEN COALESCE(SUM(ip.quantity), 0) > d.quantity THEN 'Over-allocated'
               WHEN COALESCE(SUM(ip.quantity), 0) < d.quantity THEN 'Under-allocated' 
               ELSE 'Balanced'
           END as status
    FROM deliveries d
    LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id  
    LEFT JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
    WHERE d.wattage = '555'
    GROUP BY d.id, d.quantity
    ORDER BY d.id
");
$stmt_status->execute();
$status_result = $stmt_status->get_result();
$status_data = $status_result->fetch_all(MYSQLI_ASSOC);
$stmt_status->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rebalance 555W Pallets</title>
    <link rel="stylesheet" href="portal.css">
    <style>
        .over-allocated { background-color: #fee; }
        .under-allocated { background-color: #ffe; }
        .balanced { background-color: #efe; }
        .status-summary { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            gap: 15px; 
            margin-bottom: 20px; 
        }
        .status-card {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>555W Pallet Rebalancing Tool</h1>
    
    <?php foreach ($messages as $message): ?>
        <?php echo $message; ?>
    <?php endforeach; ?>
    
    <?php
    $over_count = 0;
    $under_count = 0;
    $balanced_count = 0;
    foreach ($status_data as $row) {
        if ($row['status'] === 'Over-allocated') $over_count++;
        elseif ($row['status'] === 'Under-allocated') $under_count++;
        else $balanced_count++;
    }
    ?>
    
    <div class="status-summary">
        <div class="status-card over-allocated">
            <h3><?php echo $over_count; ?></h3>
            <p>Over-allocated Deliveries</p>
        </div>
        <div class="status-card under-allocated">
            <h3><?php echo $under_count; ?></h3>
            <p>Under-allocated Deliveries</p>
        </div>
        <div class="status-card balanced">
            <h3><?php echo $balanced_count; ?></h3>
            <p>Balanced Deliveries</p>
        </div>
    </div>
    
    <?php if ($over_count > 0 || $under_count > 0): ?>
        <form method="POST" style="margin-bottom: 20px;">
            <button type="submit" name="rebalance_555w" class="action-button" 
                    onclick="return confirm('This will redistribute pallets to balance deliveries. Continue?')">
                🔄 Rebalance 555W Pallets
            </button>
        </form>
    <?php endif; ?>
    
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Delivery ID</th>
                    <th>Required Modules</th>
                    <th>Current Pallets</th>
                    <th>Current Modules</th>
                    <th>Status</th>
                    <th>Difference</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($status_data as $row): ?>
                    <tr class="<?php echo strtolower(str_replace('-', '-', $row['status'])); ?>">
                        <td><?php echo $row['id']; ?></td>
                        <td><?php echo number_format($row['required_modules']); ?></td>
                        <td><?php echo $row['current_pallets'] ?? 0; ?></td>
                        <td><?php echo number_format($row['current_modules'] ?? 0); ?></td>
                        <td><?php echo $row['status']; ?></td>
                        <td><?php echo number_format(($row['current_modules'] ?? 0) - $row['required_modules']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>

<?php $conn->close(); ?> 