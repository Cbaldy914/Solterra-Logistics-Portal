<?php
session_name("logistics_session");
session_start();

// Ensure the user is either an admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['error' => 'Only POST method allowed']));
}

if (!isset($_POST['project_id']) || !is_numeric($_POST['project_id'])) {
    exit(json_encode(['error' => 'Invalid project ID']));
}

$project_id = intval($_POST['project_id']);
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    exit(json_encode(['error' => 'Database connection failed']));
}

try {
    // Verify project exists and user has access
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
    
    // Start transaction for atomic deletion
    $conn->begin_transaction();
    
    $deleted_counts = [
        'warranty_claims' => 0,
        'site_safety' => 0,
        'site_scheduling' => 0,
        'site_operating_hours' => 0,
        'delivery_pallets' => 0,
        'deliveries' => 0,
        'pallets' => 0,
        'module_items' => 0,
        'modules' => 0,
        'wattage_orders' => 0
    ];
    
    // SITE DELETION STEPS (simplified after migration - direct project_id references)
    
    // Site Step 1: Delete warranty_claims linked to site_scheduling for this project
    $stmtDelWarrantyClaims = $conn->prepare("
        DELETE wc FROM warranty_claims wc 
        JOIN site_scheduling ss ON wc.scheduling_id = ss.id 
        WHERE ss.project_id = ?
    ");
    $stmtDelWarrantyClaims->bind_param("i", $project_id);
    $stmtDelWarrantyClaims->execute();
    $deleted_counts['warranty_claims'] = $stmtDelWarrantyClaims->affected_rows;
    $stmtDelWarrantyClaims->close();
    
    // Site Step 2: Delete site_safety for this project
    $stmtDelSiteSafety = $conn->prepare("DELETE FROM site_safety WHERE project_id = ?");
    $stmtDelSiteSafety->bind_param("i", $project_id);
    $stmtDelSiteSafety->execute();
    $deleted_counts['site_safety'] = $stmtDelSiteSafety->affected_rows;
    $stmtDelSiteSafety->close();
    
    // Site Step 3: Delete site_scheduling for this project
    $stmtDelSiteScheduling = $conn->prepare("DELETE FROM site_scheduling WHERE project_id = ?");
    $stmtDelSiteScheduling->bind_param("i", $project_id);
    $stmtDelSiteScheduling->execute();
    $deleted_counts['site_scheduling'] = $stmtDelSiteScheduling->affected_rows;
    $stmtDelSiteScheduling->close();
    
    // Site Step 4: Delete site_operating_hours for this project
    $stmtDelSiteOpHours = $conn->prepare("DELETE FROM site_operating_hours WHERE project_id = ?");
    $stmtDelSiteOpHours->bind_param("i", $project_id);
    $stmtDelSiteOpHours->execute();
    $deleted_counts['site_operating_hours'] = $stmtDelSiteOpHours->affected_rows;
    $stmtDelSiteOpHours->close();
    
    // PROJECT DELETION STEPS (existing logic)
    
    // Step 1: Delete delivery_pallets linked to this project's deliveries
    $stmtDelDeliveryPallets = $conn->prepare("
        DELETE dp FROM delivery_pallets dp 
        JOIN deliveries d ON dp.delivery_id = d.id 
        WHERE d.project_id = ?
    ");
    $stmtDelDeliveryPallets->bind_param("i", $project_id);
    $stmtDelDeliveryPallets->execute();
    $deleted_counts['delivery_pallets'] = $stmtDelDeliveryPallets->affected_rows;
    $stmtDelDeliveryPallets->close();
    
    // Step 2: Delete inventory_pallets from modules assigned to this project
    $stmtDelPallets = $conn->prepare("
        DELETE ip FROM inventory_pallets ip 
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id 
        JOIN modules m ON umi.unassigned_module_id = m.id 
        WHERE m.project_id = ?
    ");
    $stmtDelPallets->bind_param("i", $project_id);
    $stmtDelPallets->execute();
    $deleted_counts['pallets'] = $stmtDelPallets->affected_rows;
    $stmtDelPallets->close();
    
    // Step 3: Delete unassigned_module_items from modules assigned to this project
    $stmtDelModuleItems = $conn->prepare("
        DELETE umi FROM unassigned_module_items umi 
        JOIN modules m ON umi.unassigned_module_id = m.id 
        WHERE m.project_id = ?
    ");
    $stmtDelModuleItems->bind_param("i", $project_id);
    $stmtDelModuleItems->execute();
    $deleted_counts['module_items'] = $stmtDelModuleItems->affected_rows;
    $stmtDelModuleItems->close();
    
    // Step 4: Delete modules assigned to this project
    $stmtDelModules = $conn->prepare("DELETE FROM modules WHERE project_id = ?");
    $stmtDelModules->bind_param("i", $project_id);
    $stmtDelModules->execute();
    $deleted_counts['modules'] = $stmtDelModules->affected_rows;
    $stmtDelModules->close();
    
    // Step 5: Delete deliveries for this project
    $stmtDelDeliveries = $conn->prepare("DELETE FROM deliveries WHERE project_id = ?");
    $stmtDelDeliveries->bind_param("i", $project_id);
    $stmtDelDeliveries->execute();
    $deleted_counts['deliveries'] = $stmtDelDeliveries->affected_rows;
    $stmtDelDeliveries->close();
    
    // Step 6: Delete project_wattage_orders
    $stmtDelWattageOrders = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ?");
    $stmtDelWattageOrders->bind_param("i", $project_id);
    $stmtDelWattageOrders->execute();
    $deleted_counts['wattage_orders'] = $stmtDelWattageOrders->affected_rows;
    $stmtDelWattageOrders->close();
    
    // Step 7: Finally delete the project itself
    $stmtDelProject = $conn->prepare("DELETE FROM projects WHERE id = ?");
    $stmtDelProject->bind_param("i", $project_id);
    $stmtDelProject->execute();
    $project_deleted = $stmtDelProject->affected_rows > 0;
    $stmtDelProject->close();
    
    if (!$project_deleted) {
        throw new Exception("Failed to delete project");
    }
    
    // Commit transaction
    $conn->commit();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => "Project '{$project['project_name']}' and all associated data deleted successfully",
        'deleted_counts' => $deleted_counts,
        'project_name' => $project['project_name']
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    exit(json_encode(['error' => 'Delete failed: ' . $e->getMessage()]));
}
?> 