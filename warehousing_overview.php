<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'user';

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

/**
 * We want to fetch:
 *  - Distinct warehouses that have modules in storage for this account
 *  - Total modules in storage (assigned to projects)
 *  - Total unassigned modules in storage
 *  - Total monthly storage cost
 *  - Total # projects with modules in storage
 *  - Total # warehouses with modules in storage
 */

// Build account filter conditions based on role
$account_condition = "";
$account_params = [];
$account_param_types = "";

if ($role !== 'admin' && $role !== 'global_admin') {
    $account_condition = " AND cau.user_id = ?";
    $account_params[] = $user_id;
    $account_param_types .= "i";
}

// Fetch ALL warehouses (not just ones with inventory)
if ($role === 'admin' || $role === 'global_admin') {
    $sql_all_warehouses = "
        SELECT
            w.id,
            w.name,
            w.address,
            w.street_address,
            w.city,
            w.state,
            w.zip_code,
            w.image_url,
            COALESCE(wci_monthly.amount, 0) as monthly_storage_fee,
            -- Count pallets stored (assigned to projects)
            (SELECT COUNT(ip_stored.id)
             FROM inventory_pallets ip_stored
             WHERE ip_stored.current_warehouse_id = w.id
               AND ip_stored.status = 'In Warehouse'
               AND ip_stored.assigned_project_id IS NOT NULL) as assigned_pallets_stored,
            -- Count modules stored (assigned to projects)
            (SELECT COALESCE(SUM(ip_stored.quantity), 0)
             FROM inventory_pallets ip_stored
             WHERE ip_stored.current_warehouse_id = w.id
               AND ip_stored.status = 'In Warehouse'
               AND ip_stored.assigned_project_id IS NOT NULL) as assigned_modules_stored,
            -- Count unassigned pallets stored
            (SELECT COUNT(ip_unassigned.id)
             FROM inventory_pallets ip_unassigned
             WHERE ip_unassigned.current_warehouse_id = w.id
               AND ip_unassigned.status = 'In Warehouse'
               AND ip_unassigned.assigned_project_id IS NULL) as unassigned_pallets_stored,
            -- Count unassigned modules stored
            (SELECT COALESCE(SUM(ip_unassigned.quantity), 0)
             FROM inventory_pallets ip_unassigned
             WHERE ip_unassigned.current_warehouse_id = w.id
               AND ip_unassigned.status = 'In Warehouse'
               AND ip_unassigned.assigned_project_id IS NULL) as unassigned_modules_stored,
            -- Count pallets in transit
            (SELECT COUNT(ip_transit.id)
             FROM inventory_pallets ip_transit
             JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
             JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
             WHERE ip_transit.status = 'In Transit to Warehouse'
               AND d_transit.warehouse_id = w.id) as pallets_in_transit,
            -- Count modules in transit
            (SELECT COALESCE(SUM(ip_transit.quantity), 0)
             FROM inventory_pallets ip_transit
             JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
             JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
             WHERE ip_transit.status = 'In Transit to Warehouse'
               AND d_transit.warehouse_id = w.id) as modules_in_transit
        FROM warehouses w
        LEFT JOIN warehouse_cost_items wci_monthly ON w.id = wci_monthly.warehouse_id
            AND wci_monthly.trigger_event = 'monthly' AND wci_monthly.is_active = 1
        ORDER BY w.name ASC
    ";
    $result_all_warehouses = $conn->query($sql_all_warehouses);
} else {
    // For regular users, fetch all warehouses but with account-scoped inventory counts
    $sql_all_warehouses = "
        SELECT
            w.id,
            w.name,
            w.address,
            w.street_address,
            w.city,
            w.state,
            w.zip_code,
            w.image_url,
            COALESCE(wci_monthly.amount, 0) as monthly_storage_fee,
            -- Count pallets stored (assigned to projects in user's account)
            (SELECT COUNT(ip_stored.id)
             FROM inventory_pallets ip_stored
             JOIN projects p_stored ON ip_stored.assigned_project_id = p_stored.id
             JOIN customer_account_users cau_stored ON p_stored.account_id = cau_stored.account_id
             WHERE ip_stored.current_warehouse_id = w.id
               AND ip_stored.status = 'In Warehouse'
               AND ip_stored.assigned_project_id IS NOT NULL
               AND cau_stored.user_id = ?) as assigned_pallets_stored,
            -- Count modules stored (assigned to projects in user's account)
            (SELECT COALESCE(SUM(ip_stored.quantity), 0)
             FROM inventory_pallets ip_stored
             JOIN projects p_stored ON ip_stored.assigned_project_id = p_stored.id
             JOIN customer_account_users cau_stored ON p_stored.account_id = cau_stored.account_id
             WHERE ip_stored.current_warehouse_id = w.id
               AND ip_stored.status = 'In Warehouse'
               AND ip_stored.assigned_project_id IS NOT NULL
               AND cau_stored.user_id = ?) as assigned_modules_stored,
            -- Count unassigned pallets stored (from user's account batches)
            (SELECT COUNT(ip_unassigned.id)
             FROM inventory_pallets ip_unassigned
             JOIN unassigned_module_items umi ON ip_unassigned.unassigned_module_item_id = umi.id
             JOIN modules m ON umi.unassigned_module_id = m.id
             JOIN customer_account_users cau_unassigned ON m.account_id = cau_unassigned.account_id
             WHERE ip_unassigned.current_warehouse_id = w.id
               AND ip_unassigned.status = 'In Warehouse'
               AND ip_unassigned.assigned_project_id IS NULL
               AND cau_unassigned.user_id = ?) as unassigned_pallets_stored,
            -- Count unassigned modules stored (from user's account batches)
            (SELECT COALESCE(SUM(ip_unassigned.quantity), 0)
             FROM inventory_pallets ip_unassigned
             JOIN unassigned_module_items umi ON ip_unassigned.unassigned_module_item_id = umi.id
             JOIN modules m ON umi.unassigned_module_id = m.id
             JOIN customer_account_users cau_unassigned ON m.account_id = cau_unassigned.account_id
             WHERE ip_unassigned.current_warehouse_id = w.id
               AND ip_unassigned.status = 'In Warehouse'
               AND ip_unassigned.assigned_project_id IS NULL
               AND cau_unassigned.user_id = ?) as unassigned_modules_stored,
            -- Count pallets in transit (for user's account)
            (SELECT COUNT(ip_transit.id)
             FROM inventory_pallets ip_transit
             JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
             JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
             LEFT JOIN projects p_transit ON d_transit.project_id = p_transit.id
             LEFT JOIN customer_account_users cau_transit ON p_transit.account_id = cau_transit.account_id
             WHERE ip_transit.status = 'In Transit to Warehouse'
               AND d_transit.warehouse_id = w.id
               AND (cau_transit.user_id = ? OR d_transit.project_id IS NULL)) as pallets_in_transit,
            -- Count modules in transit (for user's account)
            (SELECT COALESCE(SUM(ip_transit.quantity), 0)
             FROM inventory_pallets ip_transit
             JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
             JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
             LEFT JOIN projects p_transit ON d_transit.project_id = p_transit.id
             LEFT JOIN customer_account_users cau_transit ON p_transit.account_id = cau_transit.account_id
             WHERE ip_transit.status = 'In Transit to Warehouse'
               AND d_transit.warehouse_id = w.id
               AND (cau_transit.user_id = ? OR d_transit.project_id IS NULL)) as modules_in_transit
        FROM warehouses w
        LEFT JOIN warehouse_cost_items wci_monthly ON w.id = wci_monthly.warehouse_id
            AND wci_monthly.trigger_event = 'monthly' AND wci_monthly.is_active = 1
        ORDER BY w.name ASC
    ";
    $stmt_all = $conn->prepare($sql_all_warehouses);
    $stmt_all->bind_param("iiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
    $stmt_all->execute();
    $result_all_warehouses = $stmt_all->get_result();
}

$all_warehouses = [];
while ($warehouse = $result_all_warehouses->fetch_assoc()) {
    $all_warehouses[] = $warehouse;
}
if (isset($stmt_all)) {
    $stmt_all->close();
}

// 1) Fetch warehouses that have modules in storage for this account
if ($role === 'admin' || $role === 'global_admin') {
    $sql_warehouses = "
        SELECT DISTINCT 
            w.id, 
            w.name,
            w.address, 
            w.image_url,
            COALESCE(wci_monthly.amount, 0) as monthly_storage_fee,
            -- Count pallets stored (assigned to projects)
            (SELECT COUNT(ip_stored.id) 
             FROM inventory_pallets ip_stored 
             WHERE ip_stored.current_warehouse_id = w.id 
               AND ip_stored.status = 'In Warehouse' 
               AND ip_stored.assigned_project_id IS NOT NULL) as assigned_pallets_stored,
            -- Count modules stored (assigned to projects)
            (SELECT SUM(ip_stored.quantity) 
             FROM inventory_pallets ip_stored 
             WHERE ip_stored.current_warehouse_id = w.id 
               AND ip_stored.status = 'In Warehouse' 
               AND ip_stored.assigned_project_id IS NOT NULL) as assigned_modules_stored,
            -- Count unassigned pallets stored
            (SELECT COUNT(ip_unassigned.id) 
             FROM inventory_pallets ip_unassigned 
             WHERE ip_unassigned.current_warehouse_id = w.id 
               AND ip_unassigned.status = 'In Warehouse' 
               AND ip_unassigned.assigned_project_id IS NULL) as unassigned_pallets_stored,
            -- Count unassigned modules stored
            (SELECT SUM(ip_unassigned.quantity) 
             FROM inventory_pallets ip_unassigned 
             WHERE ip_unassigned.current_warehouse_id = w.id 
               AND ip_unassigned.status = 'In Warehouse' 
               AND ip_unassigned.assigned_project_id IS NULL) as unassigned_modules_stored,
            -- Count pallets in transit
            (SELECT COUNT(ip_transit.id) 
             FROM inventory_pallets ip_transit 
             JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
             JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
             WHERE ip_transit.status = 'In Transit to Warehouse' 
               AND d_transit.warehouse_id = w.id) as pallets_in_transit,
            -- Count modules in transit
            (SELECT SUM(ip_transit.quantity) 
             FROM inventory_pallets ip_transit 
             JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
             JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
             WHERE ip_transit.status = 'In Transit to Warehouse' 
               AND d_transit.warehouse_id = w.id) as modules_in_transit
        FROM warehouses w
        LEFT JOIN warehouse_cost_items wci_monthly ON w.id = wci_monthly.warehouse_id 
            AND wci_monthly.trigger_event = 'monthly' AND wci_monthly.is_active = 1
        WHERE EXISTS (
            SELECT 1 FROM inventory_pallets ip_check
            WHERE ip_check.current_warehouse_id = w.id 
              AND ip_check.status = 'In Warehouse'
        ) OR EXISTS (
            SELECT 1 FROM inventory_pallets ip_transit_check
            JOIN delivery_pallets dp_check ON ip_transit_check.id = dp_check.inventory_pallet_id
            JOIN deliveries d_check ON dp_check.delivery_id = d_check.id
            WHERE ip_transit_check.status = 'In Transit to Warehouse' 
              AND d_check.warehouse_id = w.id
        )
        ORDER BY w.name ASC
    ";
} else {
    $sql_warehouses = "
        SELECT DISTINCT 
            w.id, 
            w.name,
            w.address, 
            w.image_url,
            COALESCE(wci_monthly.amount, 0) as monthly_storage_fee,
            -- Count pallets stored (assigned to projects in user's account)
            (SELECT COUNT(ip_stored.id) 
             FROM inventory_pallets ip_stored 
             JOIN projects p_stored ON ip_stored.assigned_project_id = p_stored.id
             JOIN customer_account_users cau_stored ON p_stored.account_id = cau_stored.account_id
             WHERE ip_stored.current_warehouse_id = w.id 
               AND ip_stored.status = 'In Warehouse' 
               AND ip_stored.assigned_project_id IS NOT NULL
               AND cau_stored.user_id = ?) as assigned_pallets_stored,
            -- Count modules stored (assigned to projects in user's account)
            (SELECT SUM(ip_stored.quantity) 
             FROM inventory_pallets ip_stored 
             JOIN projects p_stored ON ip_stored.assigned_project_id = p_stored.id
             JOIN customer_account_users cau_stored ON p_stored.account_id = cau_stored.account_id
             WHERE ip_stored.current_warehouse_id = w.id 
               AND ip_stored.status = 'In Warehouse' 
               AND ip_stored.assigned_project_id IS NOT NULL
               AND cau_stored.user_id = ?) as assigned_modules_stored,
            -- Count unassigned pallets stored (from user's account batches)
            (SELECT COUNT(ip_unassigned.id) 
             FROM inventory_pallets ip_unassigned 
             JOIN unassigned_module_items umi ON ip_unassigned.unassigned_module_item_id = umi.id
             JOIN modules m ON umi.unassigned_module_id = m.id
             JOIN customer_account_users cau_unassigned ON m.account_id = cau_unassigned.account_id
             WHERE ip_unassigned.current_warehouse_id = w.id 
               AND ip_unassigned.status = 'In Warehouse' 
               AND ip_unassigned.assigned_project_id IS NULL
               AND cau_unassigned.user_id = ?) as unassigned_pallets_stored,
            -- Count unassigned modules stored (from user's account batches)
            (SELECT SUM(ip_unassigned.quantity) 
             FROM inventory_pallets ip_unassigned 
             JOIN unassigned_module_items umi ON ip_unassigned.unassigned_module_item_id = umi.id
             JOIN modules m ON umi.unassigned_module_id = m.id
             JOIN customer_account_users cau_unassigned ON m.account_id = cau_unassigned.account_id
             WHERE ip_unassigned.current_warehouse_id = w.id 
               AND ip_unassigned.status = 'In Warehouse' 
               AND ip_unassigned.assigned_project_id IS NULL
               AND cau_unassigned.user_id = ?) as unassigned_modules_stored,
            -- Count pallets in transit (for user's account)
            (SELECT COUNT(ip_transit.id) 
             FROM inventory_pallets ip_transit 
             JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
             JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
             LEFT JOIN projects p_transit ON d_transit.project_id = p_transit.id
             LEFT JOIN customer_account_users cau_transit ON p_transit.account_id = cau_transit.account_id
             WHERE ip_transit.status = 'In Transit to Warehouse' 
               AND d_transit.warehouse_id = w.id
               AND (cau_transit.user_id = ? OR d_transit.project_id IS NULL)) as pallets_in_transit,
            -- Count modules in transit (for user's account)
            (SELECT SUM(ip_transit.quantity) 
             FROM inventory_pallets ip_transit 
             JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
             JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
             LEFT JOIN projects p_transit ON d_transit.project_id = p_transit.id
             LEFT JOIN customer_account_users cau_transit ON p_transit.account_id = cau_transit.account_id
             WHERE ip_transit.status = 'In Transit to Warehouse' 
               AND d_transit.warehouse_id = w.id
               AND (cau_transit.user_id = ? OR d_transit.project_id IS NULL)) as modules_in_transit
        FROM warehouses w
        LEFT JOIN warehouse_cost_items wci_monthly ON w.id = wci_monthly.warehouse_id 
            AND wci_monthly.trigger_event = 'monthly' AND wci_monthly.is_active = 1
        WHERE EXISTS (
            -- Warehouses with assigned inventory for user's account
            SELECT 1 FROM inventory_pallets ip_check
            JOIN projects p_check ON ip_check.assigned_project_id = p_check.id
            JOIN customer_account_users cau_check ON p_check.account_id = cau_check.account_id
            WHERE ip_check.current_warehouse_id = w.id 
              AND ip_check.status = 'In Warehouse'
              AND cau_check.user_id = ?
        ) OR EXISTS (
            -- Warehouses with unassigned inventory from user's account batches
            SELECT 1 FROM inventory_pallets ip_unass_check
            JOIN unassigned_module_items umi_check ON ip_unass_check.unassigned_module_item_id = umi_check.id
            JOIN modules m_check ON umi_check.unassigned_module_id = m_check.id
            JOIN customer_account_users cau_unass_check ON m_check.account_id = cau_unass_check.account_id
            WHERE ip_unass_check.current_warehouse_id = w.id 
              AND ip_unass_check.status = 'In Warehouse'
              AND ip_unass_check.assigned_project_id IS NULL
              AND cau_unass_check.user_id = ?
        ) OR EXISTS (
            -- Warehouses with inventory in transit for user's account
            SELECT 1 FROM inventory_pallets ip_transit_check
            JOIN delivery_pallets dp_check ON ip_transit_check.id = dp_check.inventory_pallet_id
            JOIN deliveries d_check ON dp_check.delivery_id = d_check.id
            LEFT JOIN projects p_trans_check ON d_check.project_id = p_trans_check.id
            LEFT JOIN customer_account_users cau_trans_check ON p_trans_check.account_id = cau_trans_check.account_id
            WHERE ip_transit_check.status = 'In Transit to Warehouse' 
              AND d_check.warehouse_id = w.id
              AND (cau_trans_check.user_id = ? OR d_check.project_id IS NULL)
        )
        ORDER BY w.name ASC
    ";
}

$stmt_warehouses = $conn->prepare($sql_warehouses);
if ($role !== 'admin' && $role !== 'global_admin') {
    // Bind user_id for all the subqueries (9 times for regular users)
    $stmt_warehouses->bind_param("iiiiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
}
$stmt_warehouses->execute();
$result_warehouses = $stmt_warehouses->get_result();

$warehouses_with_inventory = [];
while ($warehouse = $result_warehouses->fetch_assoc()) {
    $warehouses_with_inventory[] = $warehouse;
}
$stmt_warehouses->close();

// 2) Calculate total modules in storage (assigned to projects)
if ($role === 'admin' || $role === 'global_admin') {
    $sql_total_assigned = "
        SELECT SUM(ip.quantity) AS total_assigned_modules
        FROM inventory_pallets ip
        WHERE ip.status = 'In Warehouse' AND ip.assigned_project_id IS NOT NULL
    ";
    $params_assigned = [];
    $types_assigned = "";
} else {
    $sql_total_assigned = "
        SELECT SUM(ip.quantity) AS total_assigned_modules
        FROM inventory_pallets ip
        JOIN projects p ON ip.assigned_project_id = p.id
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE ip.status = 'In Warehouse' 
          AND ip.assigned_project_id IS NOT NULL
          AND cau.user_id = ?
    ";
    $params_assigned = [$user_id];
    $types_assigned = "i";
}
$stmt_assigned = $conn->prepare($sql_total_assigned);
if (!empty($types_assigned)) {
    $stmt_assigned->bind_param($types_assigned, ...$params_assigned);
}
$stmt_assigned->execute();
$stmt_assigned->bind_result($total_assigned_modules);
$stmt_assigned->fetch();
$stmt_assigned->close();
$total_assigned_modules = $total_assigned_modules ?: 0;

// 3) Calculate total unassigned modules in storage
if ($role === 'admin' || $role === 'global_admin') {
    $sql_total_unassigned = "
        SELECT SUM(ip.quantity) AS total_unassigned_modules
        FROM inventory_pallets ip
        WHERE ip.status = 'In Warehouse' AND ip.assigned_project_id IS NULL
    ";
    $params_unassigned = [];
    $types_unassigned = "";
} else {
    $sql_total_unassigned = "
        SELECT SUM(ip.quantity) AS total_unassigned_modules
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        JOIN customer_account_users cau ON m.account_id = cau.account_id
        WHERE ip.status = 'In Warehouse' 
          AND ip.assigned_project_id IS NULL
          AND cau.user_id = ?
    ";
    $params_unassigned = [$user_id];
    $types_unassigned = "i";
}
$stmt_unassigned = $conn->prepare($sql_total_unassigned);
if (!empty($types_unassigned)) {
    $stmt_unassigned->bind_param($types_unassigned, ...$params_unassigned);
}
$stmt_unassigned->execute();
$stmt_unassigned->bind_result($total_unassigned_modules);
$stmt_unassigned->fetch();
$stmt_unassigned->close();
$total_unassigned_modules = $total_unassigned_modules ?: 0;

// 4) Calculate total monthly storage cost
$total_monthly_storage_cost = 0;
foreach ($warehouses_with_inventory as $warehouse) {
    $total_pallets_in_warehouse = ($warehouse['assigned_pallets_stored'] ?? 0) + ($warehouse['unassigned_pallets_stored'] ?? 0);
    $warehouse_monthly_cost = $total_pallets_in_warehouse * ($warehouse['monthly_storage_fee'] ?? 0);
    $total_monthly_storage_cost += $warehouse_monthly_cost;
}

// 5) Count projects with modules in storage
if ($role === 'admin' || $role === 'global_admin') {
    $sql_proj_count = "
        SELECT COUNT(DISTINCT ip.assigned_project_id) AS projects_with_storage
        FROM inventory_pallets ip
        WHERE ip.status = 'In Warehouse' AND ip.assigned_project_id IS NOT NULL
    ";
    $params_proj = [];
    $types_proj = "";
} else {
    $sql_proj_count = "
        SELECT COUNT(DISTINCT ip.assigned_project_id) AS projects_with_storage
        FROM inventory_pallets ip
        JOIN projects p ON ip.assigned_project_id = p.id
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE ip.status = 'In Warehouse' 
          AND ip.assigned_project_id IS NOT NULL
          AND cau.user_id = ?
    ";
    $params_proj = [$user_id];
    $types_proj = "i";
}
$stmt_proj = $conn->prepare($sql_proj_count);
if (!empty($types_proj)) {
    $stmt_proj->bind_param($types_proj, ...$params_proj);
}
$stmt_proj->execute();
$stmt_proj->bind_result($total_projects_with_storage);
$stmt_proj->fetch();
$stmt_proj->close();
$total_projects_with_storage = $total_projects_with_storage ?: 0;

$total_modules_in_storage = $total_assigned_modules + $total_unassigned_modules;
$total_warehouses_with_storage = count($warehouses_with_inventory);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Warehousing Overview</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Modern Page Header */
        .page-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .page-header .subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        /* Enhanced Key Figures */
        .key-figures {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .figure {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 16px;
            padding: 20px 24px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        .figure:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(72, 140, 154, 0.15);
            border-color: rgba(72, 140, 154, 0.2);
        }
        .figure.expanded {
            box-shadow: 0 8px 30px rgba(72, 140, 154, 0.2);
            border-color: #488C9A;
        }
        .figure-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .figure-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(72, 140, 154, 0.08) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #488C9A;
            font-size: 20px;
            margin-bottom: 12px;
        }
        .figure h3 {
            margin: 0 0 8px 0;
            color: #293E4C;
            font-size: 1em;
            font-weight: 600;
        }
        .figure .number {
            font-size: 2.2em;
            font-weight: 700;
            color: #488C9A;
            margin-bottom: 4px;
            line-height: 1;
        }
        .figure .label {
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 500;
        }
        .figure-expand-icon {
            position: absolute;
            top: 12px;
            right: 12px;
            color: #adb5bd;
            font-size: 14px;
            transition: transform 0.3s ease;
        }
        .figure.expanded .figure-expand-icon {
            transform: rotate(180deg);
            color: #488C9A;
        }
        .figure-details {
            display: none;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(72, 140, 154, 0.15);
            text-align: left;
        }
        .figure.expanded .figure-details {
            display: block;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #6c757d;
            font-size: 0.9em;
        }
        .detail-value {
            font-weight: 600;
            color: #293E4C;
            font-size: 0.9em;
        }
        .detail-value.positive {
            color: #28a745;
        }
        .detail-value.warning {
            color: #ffc107;
        }
        
        /* Section Header */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .section-title {
            font-size: 1.5em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section-title i {
            color: #488C9A;
        }
        .section-badge {
            background: rgba(72, 140, 154, 0.1);
            color: #488C9A;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .section-filters {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .filter-btn {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 0.9em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            background: #f8f9fa;
            color: #6c757d;
        }
        .filter-btn:hover {
            background: rgba(72, 140, 154, 0.1);
            color: #488C9A;
        }
        .filter-btn.active {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border-color: transparent;
        }

        /* Enhanced Warehouse cards - using wh-overview prefix to avoid portal.css conflicts */
        .wh-overview-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 24px;
            padding: 10px 0;
        }
        .wh-overview-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(72, 140, 154, 0.08);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .wh-overview-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .wh-overview-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 60px rgba(72, 140, 154, 0.2);
            border-color: rgba(72, 140, 154, 0.2);
        }
        .wh-overview-card:hover::before {
            opacity: 1;
        }
        .wh-overview-card.no-inventory {
            opacity: 0.7;
        }
        .wh-overview-card.no-inventory:hover {
            opacity: 1;
        }
        .wh-overview-card-link {
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            height: 100%;
        }
        .wh-overview-card-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            display: block;
        }
        .wh-overview-card-placeholder {
            width: 100%;
            height: 180px;
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
        }
        .wh-overview-card-placeholder i {
            font-size: 48px;
            margin-bottom: 8px;
        }
        .wh-overview-card-placeholder span {
            font-size: 0.85em;
            font-weight: 500;
        }
        .wh-overview-card-content {
            padding: 20px;
            flex-grow: 1;
        }
        .wh-overview-card-name {
            font-size: 1.25em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .wh-overview-status-badge {
            font-size: 0.65em;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        .wh-overview-status-badge.has-inventory {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        .wh-overview-status-badge.no-inventory {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }
        .wh-overview-status-badge.in-transit {
            background: rgba(0, 123, 255, 0.1);
            color: #007bff;
        }
        .wh-overview-card-address {
            color: #6c757d;
            font-size: 0.9em;
            margin: 0 0 16px 0;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .wh-overview-card-address i {
            color: #adb5bd;
            margin-top: 2px;
        }
        .wh-overview-card-stats {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.05) 0%, rgba(72, 140, 154, 0.02) 100%);
            padding: 16px 20px;
            border-top: 1px solid rgba(72, 140, 154, 0.1);
        }
        .wh-overview-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .wh-overview-stat-item {
            text-align: center;
            padding: 8px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        .wh-overview-stat-item .stat-value {
            font-size: 1.4em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
            line-height: 1;
        }
        .wh-overview-stat-item .stat-label {
            font-size: 0.75em;
            color: #6c757d;
            margin: 4px 0 0 0;
            font-weight: 500;
        }
        .wh-overview-stat-item.highlight {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
        }
        .wh-overview-stat-item.highlight .stat-value,
        .wh-overview-stat-item.highlight .stat-label {
            color: white;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .wh-overview-cards-container {
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            }
        }
        @media (max-width: 600px) {
            .wh-overview-cards-container {
                grid-template-columns: 1fr;
            }
            .key-figures {
                grid-template-columns: 1fr;
            }
            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Warehousing Overview']); ?>

    <!-- Modern Page Header -->
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1>Warehousing Overview</h1>
                <p class="subtitle">Monitor inventory across all warehouses</p>
            </div>
        </div>
    </div>

    <!-- Enhanced Key Figures with Dropdowns -->
    <div class="key-figures">
        <div class="figure" onclick="toggleFigure(this)">
            <i class="fas fa-chevron-down figure-expand-icon"></i>
            <div class="figure-icon"><i class="fas fa-boxes-stacked"></i></div>
            <h3>Total Modules in Storage</h3>
            <div class="number"><?php echo number_format($total_modules_in_storage); ?></div>
            <div class="label">Across all warehouses</div>
            <div class="figure-details">
                <div class="detail-row">
                    <span class="detail-label">Project Assigned</span>
                    <span class="detail-value"><?php echo number_format($total_assigned_modules); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Unassigned</span>
                    <span class="detail-value"><?php echo number_format($total_unassigned_modules); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Warehouses with Inventory</span>
                    <span class="detail-value"><?php echo $total_warehouses_with_storage; ?></span>
                </div>
            </div>
        </div>
        <div class="figure" onclick="toggleFigure(this)">
            <i class="fas fa-chevron-down figure-expand-icon"></i>
            <div class="figure-icon"><i class="fas fa-dollar-sign"></i></div>
            <h3>Monthly Storage Cost</h3>
            <div class="number">$<?php echo number_format($total_monthly_storage_cost, 0); ?></div>
            <div class="label">Estimated monthly cost</div>
            <div class="figure-details">
                <?php foreach ($warehouses_with_inventory as $wh_cost):
                    $wh_pallets = ($wh_cost['assigned_pallets_stored'] ?? 0) + ($wh_cost['unassigned_pallets_stored'] ?? 0);
                    $wh_cost_val = $wh_pallets * ($wh_cost['monthly_storage_fee'] ?? 0);
                    if ($wh_cost_val > 0):
                ?>
                <div class="detail-row">
                    <span class="detail-label"><?php echo htmlspecialchars($wh_cost['name']); ?></span>
                    <span class="detail-value">$<?php echo number_format($wh_cost_val, 0); ?></span>
                </div>
                <?php endif; endforeach; ?>
                <div class="detail-row" style="border-top: 1px solid rgba(72, 140, 154, 0.15); margin-top: 8px; padding-top: 8px;">
                    <span class="detail-label"><strong>Total Pallets Stored</strong></span>
                    <span class="detail-value"><strong><?php
                        $total_pallets = 0;
                        foreach ($warehouses_with_inventory as $wh_p) {
                            $total_pallets += ($wh_p['assigned_pallets_stored'] ?? 0) + ($wh_p['unassigned_pallets_stored'] ?? 0);
                        }
                        echo number_format($total_pallets);
                    ?></strong></span>
                </div>
            </div>
        </div>
        <div class="figure" onclick="toggleFigure(this)">
            <i class="fas fa-chevron-down figure-expand-icon"></i>
            <div class="figure-icon"><i class="fas fa-project-diagram"></i></div>
            <h3>Project Modules</h3>
            <div class="number"><?php echo number_format($total_assigned_modules); ?></div>
            <div class="label">Assigned to <?php echo $total_projects_with_storage; ?> projects</div>
            <div class="figure-details">
                <div class="detail-row">
                    <span class="detail-label">Projects with Storage</span>
                    <span class="detail-value"><?php echo $total_projects_with_storage; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Avg Modules per Project</span>
                    <span class="detail-value"><?php echo $total_projects_with_storage > 0 ? number_format($total_assigned_modules / $total_projects_with_storage, 0) : '0'; ?></span>
                </div>
            </div>
        </div>
        <div class="figure" onclick="toggleFigure(this)">
            <i class="fas fa-chevron-down figure-expand-icon"></i>
            <div class="figure-icon"><i class="fas fa-box-open"></i></div>
            <h3>Unassigned Modules</h3>
            <div class="number"><?php echo number_format($total_unassigned_modules); ?></div>
            <div class="label">Available for assignment</div>
            <div class="figure-details">
                <?php foreach ($warehouses_with_inventory as $wh_unass):
                    if (($wh_unass['unassigned_modules_stored'] ?? 0) > 0):
                ?>
                <div class="detail-row">
                    <span class="detail-label"><?php echo htmlspecialchars($wh_unass['name']); ?></span>
                    <span class="detail-value"><?php echo number_format($wh_unass['unassigned_modules_stored']); ?></span>
                </div>
                <?php endif; endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Warehouse Section -->
    <div class="section-header">
        <h2 class="section-title">
            <i class="fas fa-warehouse"></i>
            All Warehouses
            <span class="section-badge"><?php echo count($all_warehouses); ?> total</span>
        </h2>
        <div class="section-filters">
            <button class="filter-btn active" onclick="filterWarehouses('all', this)">All</button>
            <button class="filter-btn" onclick="filterWarehouses('with-inventory', this)">With Inventory</button>
            <button class="filter-btn" onclick="filterWarehouses('empty', this)">Empty</button>
        </div>
    </div>

    <div class="wh-overview-cards-container" id="warehouseContainer">
        <?php if (!empty($all_warehouses)): ?>
            <?php foreach ($all_warehouses as $wh):
                $total_modules = ($wh['assigned_modules_stored'] ?? 0) + ($wh['unassigned_modules_stored'] ?? 0);
                $total_pallets = ($wh['assigned_pallets_stored'] ?? 0) + ($wh['unassigned_pallets_stored'] ?? 0);
                $in_transit = ($wh['modules_in_transit'] ?? 0);
                $has_inventory = $total_modules > 0;
                $has_transit = $in_transit > 0;
                $warehouse_monthly_cost = $total_pallets * ($wh['monthly_storage_fee'] ?? 0);

                // Build address
                $address_parts = array_filter([
                    $wh['street_address'] ?? '',
                    $wh['city'] ?? '',
                    $wh['state'] ?? ''
                ]);
                $display_address = !empty($address_parts) ? implode(', ', $address_parts) : ($wh['address'] ?? 'Address not available');

                // Check if image exists
                $has_image = false;
                $wh_image_path = '';
                if (!empty($wh['image_url'])) {
                    if (filter_var($wh['image_url'], FILTER_VALIDATE_URL)) {
                        // External URL - assume it exists
                        $wh_image_path = $wh['image_url'];
                        $has_image = true;
                    } else {
                        // Local file - check if it actually exists
                        if (strpos($wh['image_url'], 'http') !== 0 && strpos($wh['image_url'], 'pictures/') !== 0 && strpos($wh['image_url'], 'uploads/') !== 0) {
                           $wh_image_path = 'uploads/warehouse_images/' . ltrim($wh['image_url'], '/');
                        } else {
                           $wh_image_path = $wh['image_url'];
                        }
                        // Check if file actually exists on disk
                        $has_image = file_exists(__DIR__ . '/' . $wh_image_path);
                        $wh_image_path = htmlspecialchars($wh_image_path);
                    }
                }
            ?>
                <div class="wh-overview-card <?php echo !$has_inventory && !$has_transit ? 'no-inventory' : ''; ?>"
                     data-has-inventory="<?php echo $has_inventory ? '1' : '0'; ?>"
                     data-has-transit="<?php echo $has_transit ? '1' : '0'; ?>">
                    <a href="warehouse_info.php?warehouse_id=<?php echo $wh['id']; ?>" class="wh-overview-card-link">
                        <?php if ($has_image): ?>
                            <img src="<?php echo $wh_image_path; ?>" alt="<?php echo htmlspecialchars($wh['name']); ?>" class="wh-overview-card-image">
                        <?php else: ?>
                            <div class="wh-overview-card-placeholder">
                                <i class="fas fa-warehouse"></i>
                                <span>No image available</span>
                            </div>
                        <?php endif; ?>
                        <div class="wh-overview-card-content">
                            <h3 class="wh-overview-card-name">
                                <?php echo htmlspecialchars($wh['name']); ?>
                                <?php if ($has_inventory): ?>
                                    <span class="wh-overview-status-badge has-inventory">Active</span>
                                <?php elseif ($has_transit): ?>
                                    <span class="wh-overview-status-badge in-transit">Incoming</span>
                                <?php else: ?>
                                    <span class="wh-overview-status-badge no-inventory">Empty</span>
                                <?php endif; ?>
                            </h3>
                            <p class="wh-overview-card-address">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($display_address); ?></span>
                            </p>
                        </div>
                        <div class="wh-overview-card-stats">
                            <div class="wh-overview-stats-grid">
                                <div class="wh-overview-stat-item <?php echo $total_modules > 0 ? 'highlight' : ''; ?>">
                                    <p class="stat-value"><?php echo number_format($total_modules); ?></p>
                                    <p class="stat-label">Modules</p>
                                </div>
                                <div class="wh-overview-stat-item">
                                    <p class="stat-value"><?php echo number_format($total_pallets); ?></p>
                                    <p class="stat-label">Pallets</p>
                                </div>
                                <div class="wh-overview-stat-item">
                                    <p class="stat-value"><?php echo number_format($in_transit); ?></p>
                                    <p class="stat-label">In Transit</p>
                                </div>
                                <div class="wh-overview-stat-item">
                                    <p class="stat-value">$<?php echo number_format($warehouse_monthly_cost, 0); ?></p>
                                    <p class="stat-label">Monthly</p>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="grid-column: 1/-1; text-align: center; color: #6c757d;">No warehouses available.</p>
        <?php endif; ?>
    </div>
</main>

<script>
function toggleFigure(element) {
    element.classList.toggle('expanded');
}

function filterWarehouses(filter, btn) {
    // Update active button
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // Filter cards
    const cards = document.querySelectorAll('.wh-overview-card');
    cards.forEach(card => {
        const hasInventory = card.dataset.hasInventory === '1';
        const hasTransit = card.dataset.hasTransit === '1';

        if (filter === 'all') {
            card.style.display = '';
        } else if (filter === 'with-inventory') {
            card.style.display = (hasInventory || hasTransit) ? '' : 'none';
        } else if (filter === 'empty') {
            card.style.display = (!hasInventory && !hasTransit) ? '' : 'none';
        }
    });
}
</script>
</body>
</html>
