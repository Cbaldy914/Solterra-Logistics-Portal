<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header("Location: unauthorized.php"); // Redirect to an unauthorized page
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

$warehouses = [];
$errorMessage = '';
$successMessage = '';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $warehouse_id = intval($_GET['id']);
        
        // Check if warehouse is being used anywhere (optional - you can add checks later)
        // For now, we'll allow deletion
        
        $stmt = $conn->prepare("DELETE FROM warehouses WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing delete statement: " . $conn->error);
        }
        
        $stmt->bind_param("i", $warehouse_id);
        if ($stmt->execute()) {
            $successMessage = "Warehouse deleted successfully.";
        } else {
            throw new Exception("Error deleting warehouse: " . $stmt->error);
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

try {
    // Fetch warehouses along with a count of pallets currently stored and in transit
    $sql = "SELECT
                w.id,
                w.name,
                w.address,
                w.is_port,
                COUNT(DISTINCT CASE WHEN ip_stored.status = 'In Warehouse' THEN ip_stored.id ELSE NULL END) AS current_pallet_count,
                COUNT(DISTINCT CASE WHEN ip_transit.status = 'In Transit to Warehouse' THEN ip_transit.id ELSE NULL END) AS in_transit_pallet_count,
                -- Get cost information from warehouse_cost_items using label and unit_type
                GROUP_CONCAT(
                    DISTINCT CONCAT(
                        COALESCE(CONVERT(wci.label USING utf8mb4), ''),
                        ': $', FORMAT(wci.amount, 2),
                        CASE wci.unit_type
                            WHEN 'per_pallet' THEN '/pallet'
                            WHEN 'per_truck' THEN '/truck'
                            WHEN 'per_sqft' THEN '/sqft'
                            WHEN 'flat' THEN ' flat'
                            ELSE '/pallet'
                        END
                    )
                    ORDER BY wci.display_order, wci.trigger_event
                    SEPARATOR '<br>'
                ) as cost_summary,
                COUNT(DISTINCT wci.id) as fee_count
            FROM warehouses w
            LEFT JOIN inventory_pallets ip_stored ON w.id = ip_stored.current_warehouse_id AND ip_stored.status = 'In Warehouse'
            LEFT JOIN deliveries d_transit ON w.id = d_transit.warehouse_id
            LEFT JOIN delivery_pallets dp_transit ON d_transit.id = dp_transit.delivery_id
            LEFT JOIN inventory_pallets ip_transit ON dp_transit.inventory_pallet_id = ip_transit.id AND ip_transit.status = 'In Transit to Warehouse'
            LEFT JOIN warehouse_cost_items wci ON w.id = wci.warehouse_id AND wci.is_active = 1
            GROUP BY w.id, w.name, w.address, w.is_port
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
            background-color: #488C9A;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .action-buttons.add-new:hover {
            background-color: #293E4C;
        }
        .action-buttons.edit {
            background-color: #488C9A;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .action-buttons.edit:hover {
            background-color: #293E4C;
        }
        .action-buttons.delete {
            background-color: #dc3545;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .action-buttons.delete:hover {
            background-color: #c82333;
        }
        .action-buttons.view {
            background-color: #488C9A;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .action-buttons.view:hover {
            background-color: #293E4C;
        }
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success-message {
            color: #155724;
            background-color: #d4edda;
            padding: 15px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .fee-info {
            font-size: 0.9em;
            color: #666;
        }
        .pallet-count {
            font-weight: 500;
        }
        .pallet-count.high {
            color: #dc3545;
        }
        .pallet-count.medium {
            color: #ffc107;
        }
        .pallet-count.low {
            color: #28a745;
        }
        
        /* Dropdown menu styling */
        .dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-toggle {
            background: none;
            border: none;
            color: #488C9A;
            padding: 4px;
            cursor: pointer;
            font-size: 1.1em;
            border-radius: 3px;
            transition: all 0.2s ease;
        }
        .dropdown-toggle:hover {
            background-color: #f8f9fa;
            color: #293E4C;
        }
        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            min-width: 120px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            border-radius: 4px;
            z-index: 1000;
            border: 1px solid #ddd;
        }
        .dropdown-menu.show {
            display: block;
        }
        .dropdown-item {
            display: block;
            width: 100%;
            padding: 8px 12px;
            text-decoration: none;
            color: #333;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            font-size: 0.9em;
        }
        .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        .dropdown-item.edit {
            color: #488C9A;
        }
        .dropdown-item.delete {
            color: #dc3545;
        }
        .dropdown-item:first-child {
            border-radius: 4px 4px 0 0;
        }
        .dropdown-item:last-child {
            border-radius: 0 0 4px 4px;
        }
        
        /* Actions cell styling */
        .actions-cell {
            position: relative;
        }
        .actions-cell .dropdown {
            position: absolute;
            top: 4px;
            right: 4px;
        }
    </style>
    <script>
        function confirmDelete(warehouseName, warehouseId) {
            if (confirm(`Are you sure you want to delete the warehouse "${warehouseName}"? This action cannot be undone.`)) {
                window.location.href = `manage_warehouses.php?action=delete&id=${warehouseId}`;
            }
        }
        
        // Dropdown functionality
        function toggleDropdown(event, dropdownId) {
            event.stopPropagation();
            
            // Close all other dropdowns
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== dropdownId) {
                    menu.classList.remove('show');
                }
            });
            
            // Toggle the clicked dropdown
            const dropdown = document.getElementById(dropdownId);
            dropdown.classList.toggle('show');
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                menu.classList.remove('show');
            });
        });
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Manage Warehouses']); ?>
    
    <div class="header-container">
        <h1>Manage Warehouses</h1>
        <a href="add_warehouse.php" class="action-buttons add-new">Add New Warehouse</a>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message">
            <strong><?php echo htmlspecialchars($successMessage); ?></strong>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Warehouse</th>
                    <th>Fee Information</th>
                    <th>Address</th>
                    <th>Current Inventory</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($warehouses)): ?>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($warehouse['name']); ?></strong>
                                <?php if (!empty($warehouse['is_port']) && $warehouse['is_port'] == 1): ?>
                                    <br><span style="background: #007cba; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em;">🚢 PORT</span>
                                <?php endif; ?>
                            </td>
                            <td class="fee-info">
                                <?php if (!empty($warehouse['cost_summary'])): ?>
                                    <?php echo $warehouse['cost_summary']; ?>
                                <?php else: ?>
                                    <em style="color: #999;">No cost structure defined</em><br>
                                    <small><a href="edit_warehouse.php?id=<?php echo $warehouse['id']; ?>">Set up costs →</a></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($warehouse['address']); ?></td>
                            <td>
                                <?php 
                                    $total_pallets = $warehouse['current_pallet_count'];
                                    $in_transit = $warehouse['in_transit_pallet_count'];
                                    $pallet_class = 'low';
                                    if ($total_pallets > 100) $pallet_class = 'high';
                                    elseif ($total_pallets > 50) $pallet_class = 'medium';
                                ?>
                                <div class="pallet-count <?php echo $pallet_class; ?>">
                                    📦 <?php echo number_format($total_pallets); ?> stored
                                </div>
                                <?php if ($in_transit > 0): ?>
                                    <div style="font-size: 0.85em; color: #666;">
                                        🚛 <?php echo number_format($in_transit); ?> in transit
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo $warehouse['id']; ?>" class="action-buttons view">View Inventory</a>
                                <div class="dropdown">
                                    <button class="dropdown-toggle" onclick="toggleDropdown(event, 'dropdown-menu-<?php echo $warehouse['id']; ?>')" title="More actions">✏️</button>
                                    <div id="dropdown-menu-<?php echo $warehouse['id']; ?>" class="dropdown-menu">
                                        <a href="edit_warehouse.php?id=<?php echo $warehouse['id']; ?>" class="dropdown-item edit">Edit</a>
                                        <a href="javascript:void(0);" onclick="confirmDelete('<?php echo htmlspecialchars($warehouse['name'], ENT_QUOTES); ?>', <?php echo $warehouse['id']; ?>)" class="dropdown-item delete">Delete</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 20px; color: #666;">
                            No warehouses found. <a href="add_warehouse.php">Add the first warehouse</a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    

</main>
</body>
</html> 
