<?php
session_name("logistics_session");
session_start();

// Ensure the user is either an admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json');

if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    exit(json_encode(['error' => 'Invalid project ID']));
}

$project_id = intval($_GET['project_id']);
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    exit(json_encode(['error' => 'Database connection failed']));
}

try {
    // First, get project details and verify access
    $stmtProject = $conn->prepare("
        SELECT p.project_name, p.account_id, c.name as account_name 
        FROM projects p 
        JOIN customer_accounts c ON p.account_id = c.id 
        WHERE p.id = ?
    ");
    $stmtProject->bind_param("i", $project_id);
    $stmtProject->execute();
    $projectResult = $stmtProject->get_result();
    
    if ($projectResult->num_rows === 0) {
        exit(json_encode(['error' => 'Project not found']));
    }
    
    $project = $projectResult->fetch_assoc();
    $stmtProject->close();
    
    // Check admin access
    if ($role === 'admin') {
        $stmtAccess = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin'");
        $stmtAccess->bind_param("i", $user_id);
        $stmtAccess->execute();
        $stmtAccess->bind_result($admin_account_id);
        $stmtAccess->fetch();
        $stmtAccess->close();
        
        if ($admin_account_id != $project['account_id']) {
            exit(json_encode(['error' => 'Access denied to this project']));
        }
    }
    
    // Get detailed counts of what will be deleted
    
    // 1. Count deliveries
    $stmtDeliveries = $conn->prepare("SELECT COUNT(*) as count FROM deliveries WHERE project_id = ?");
    $stmtDeliveries->bind_param("i", $project_id);
    $stmtDeliveries->execute();
    $stmtDeliveries->bind_result($delivery_count);
    $stmtDeliveries->fetch();
    $stmtDeliveries->close();
    
    // 2. Count delivery_pallets (linking records)
    $stmtDeliveryPallets = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM delivery_pallets dp 
        JOIN deliveries d ON dp.delivery_id = d.id 
        WHERE d.project_id = ?
    ");
    $stmtDeliveryPallets->bind_param("i", $project_id);
    $stmtDeliveryPallets->execute();
    $stmtDeliveryPallets->bind_result($delivery_pallet_count);
    $stmtDeliveryPallets->fetch();
    $stmtDeliveryPallets->close();
    
    // 3. Count module batches assigned to this project
    $stmtModules = $conn->prepare("SELECT COUNT(*) as count FROM modules WHERE project_id = ?");
    $stmtModules->bind_param("i", $project_id);
    $stmtModules->execute();
    $stmtModules->bind_result($module_count);
    $stmtModules->fetch();
    $stmtModules->close();
    
    // 4. Count module items
    $stmtModuleItems = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM unassigned_module_items umi 
        JOIN modules m ON umi.unassigned_module_id = m.id 
        WHERE m.project_id = ?
    ");
    $stmtModuleItems->bind_param("i", $project_id);
    $stmtModuleItems->execute();
    $stmtModuleItems->bind_result($module_item_count);
    $stmtModuleItems->fetch();
    $stmtModuleItems->close();
    
    // 5. Count inventory pallets from these modules
    $stmtPallets = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM inventory_pallets ip 
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id 
        JOIN modules m ON umi.unassigned_module_id = m.id 
        WHERE m.project_id = ?
    ");
    $stmtPallets->bind_param("i", $project_id);
    $stmtPallets->execute();
    $stmtPallets->bind_result($pallet_count);
    $stmtPallets->fetch();
    $stmtPallets->close();
    
    // 6. Count project_wattage_orders
    $stmtWattageOrders = $conn->prepare("SELECT COUNT(*) as count FROM project_wattage_orders WHERE project_id = ?");
    $stmtWattageOrders->bind_param("i", $project_id);
    $stmtWattageOrders->execute();
    $stmtWattageOrders->bind_result($wattage_order_count);
    $stmtWattageOrders->fetch();
    $stmtWattageOrders->close();
    
    // Get total module quantity for additional context
    $stmtModuleQty = $conn->prepare("
        SELECT COALESCE(SUM(umi.quantity), 0) as total_modules 
        FROM unassigned_module_items umi 
        JOIN modules m ON umi.unassigned_module_id = m.id 
        WHERE m.project_id = ?
    ");
    $stmtModuleQty->bind_param("i", $project_id);
    $stmtModuleQty->execute();
    $stmtModuleQty->bind_result($total_modules);
    $stmtModuleQty->fetch();
    $stmtModuleQty->close();
    
    $conn->close();
    
    // Return detailed information
    echo json_encode([
        'success' => true,
        'project' => [
            'id' => $project_id,
            'name' => $project['project_name'],
            'account_name' => $project['account_name']
        ],
        'counts' => [
            'deliveries' => $delivery_count,
            'delivery_pallets' => $delivery_pallet_count,
            'module_batches' => $module_count,
            'module_items' => $module_item_count,
            'pallets' => $pallet_count,
            'wattage_orders' => $wattage_order_count,
            'total_modules' => $total_modules
        ]
    ]);
    
} catch (Exception $e) {
    $conn->close();
    exit(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
}
?> 