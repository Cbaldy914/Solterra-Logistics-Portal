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
$can_manage_warehouses = in_array($role, ['admin', 'global_admin', 'customer_admin'], true);
$is_global_admin = $role === 'global_admin';
$account_id = null;

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

// Resolve account scope (all accounts for global_admin)
if (!$is_global_admin) {
    $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    if ($stmtAccount) {
        $stmtAccount->bind_param("i", $user_id);
        $stmtAccount->execute();
        $stmtAccount->bind_result($account_id);
        $stmtAccount->fetch();
        $stmtAccount->close();
    }
    if (!$account_id) {
        die("No valid account found for this user.");
    }
}

$errorMessage = '';
$successMessage = '';

// Handle delete action (admins only)
if ($can_manage_warehouses && isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    try {
        $warehouse_id = intval($_GET['id']);
        if ($is_global_admin) {
            $stmt = $conn->prepare("DELETE FROM warehouses WHERE id = ?");
            $stmt->bind_param("i", $warehouse_id);
        } else {
            $stmt = $conn->prepare("DELETE FROM warehouses WHERE id = ? AND account_id = ?");
            $stmt->bind_param("ii", $warehouse_id, $account_id);
        }
        if (!$stmt) {
            throw new Exception("Error preparing delete statement: " . $conn->error);
        }
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

// Fetch ALL warehouses (not just ones with inventory)
$where_sql = $is_global_admin ? '' : 'WHERE w.account_id = ?';
$sql_all_warehouses = "
    SELECT
        w.id,
        w.account_id,
        w.name,
        w.address,
        w.street_address,
        w.city,
        w.state,
        w.zip_code,
        w.image_url,
        w.is_port,
        -- Sum ALL monthly fees (supports multiple fees per trigger type)
        (SELECT COALESCE(SUM(amount), 0) FROM warehouse_cost_items
         WHERE warehouse_id = w.id AND trigger_event = 'monthly' AND is_active = 1) as monthly_storage_fee,
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
        -- Sum wattage stored (assigned to projects)
        (SELECT COALESCE(SUM(ip_stored.quantity * COALESCE(ip_stored.wattage, 0)), 0)
         FROM inventory_pallets ip_stored
         WHERE ip_stored.current_warehouse_id = w.id
           AND ip_stored.status = 'In Warehouse'
           AND ip_stored.assigned_project_id IS NOT NULL) as assigned_wattage_stored,
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
        -- Sum wattage stored (unassigned)
        (SELECT COALESCE(SUM(ip_unassigned.quantity * COALESCE(ip_unassigned.wattage, 0)), 0)
         FROM inventory_pallets ip_unassigned
         WHERE ip_unassigned.current_warehouse_id = w.id
           AND ip_unassigned.status = 'In Warehouse'
           AND ip_unassigned.assigned_project_id IS NULL) as unassigned_wattage_stored,
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
           AND d_transit.warehouse_id = w.id) as modules_in_transit,
        -- Sum wattage in transit
        (SELECT COALESCE(SUM(ip_transit.quantity * COALESCE(ip_transit.wattage, 0)), 0)
         FROM inventory_pallets ip_transit
         JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
         JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
         WHERE ip_transit.status = 'In Transit to Warehouse'
           AND d_transit.warehouse_id = w.id) as wattage_in_transit
    FROM warehouses w
    $where_sql
    ORDER BY w.name ASC
";
if ($is_global_admin) {
    $result_all_warehouses = $conn->query($sql_all_warehouses);
} else {
    $stmt_all = $conn->prepare($sql_all_warehouses);
    $stmt_all->bind_param("i", $account_id);
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

if ($can_manage_warehouses) {
    foreach ($all_warehouses as &$warehouse) {
        $warehouse['cost_items'] = [];
        $stmtCosts = $conn->prepare("
            SELECT id, label, amount, trigger_event, unit_type, pallets_per_truck, sqft_per_pallet
            FROM warehouse_cost_items
            WHERE warehouse_id = ? AND is_active = 1
            ORDER BY display_order ASC, trigger_event ASC
        ");
        if ($stmtCosts) {
            $stmtCosts->bind_param("i", $warehouse['id']);
            $stmtCosts->execute();
            $costResult = $stmtCosts->get_result();
            while ($cost = $costResult->fetch_assoc()) {
                $warehouse['cost_items'][] = $cost;
            }
            $stmtCosts->close();
        }
    }
    unset($warehouse);
}

// 1) Fetch warehouses that have inventory or transit activity
$inventory_conditions = [];
if (!$is_global_admin) {
    $inventory_conditions[] = "w.account_id = ?";
}
$inventory_conditions[] = "(
    EXISTS (
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
)";
$where_inventory_sql = "WHERE " . implode(" AND ", $inventory_conditions);

$sql_warehouses = "
    SELECT DISTINCT
        w.id,
        w.name,
        w.address,
        w.image_url,
        w.is_port,
        -- Sum ALL monthly fees (supports multiple fees per trigger type)
        (SELECT COALESCE(SUM(amount), 0) FROM warehouse_cost_items
         WHERE warehouse_id = w.id AND trigger_event = 'monthly' AND is_active = 1) as monthly_storage_fee,
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
        -- Sum wattage stored (assigned)
        (SELECT COALESCE(SUM(ip_stored.quantity * COALESCE(ip_stored.wattage, 0)), 0)
         FROM inventory_pallets ip_stored
         WHERE ip_stored.current_warehouse_id = w.id
           AND ip_stored.status = 'In Warehouse'
           AND ip_stored.assigned_project_id IS NOT NULL) as assigned_wattage_stored,
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
        -- Sum wattage stored (unassigned)
        (SELECT COALESCE(SUM(ip_unassigned.quantity * COALESCE(ip_unassigned.wattage, 0)), 0)
         FROM inventory_pallets ip_unassigned
         WHERE ip_unassigned.current_warehouse_id = w.id
           AND ip_unassigned.status = 'In Warehouse'
           AND ip_unassigned.assigned_project_id IS NULL) as unassigned_wattage_stored,
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
           AND d_transit.warehouse_id = w.id) as modules_in_transit,
        -- Sum wattage in transit
        (SELECT COALESCE(SUM(ip_transit.quantity * COALESCE(ip_transit.wattage, 0)), 0)
         FROM inventory_pallets ip_transit
         JOIN delivery_pallets dp_transit ON ip_transit.id = dp_transit.inventory_pallet_id
         JOIN deliveries d_transit ON dp_transit.delivery_id = d_transit.id
         WHERE ip_transit.status = 'In Transit to Warehouse'
           AND d_transit.warehouse_id = w.id) as wattage_in_transit
    FROM warehouses w
    $where_inventory_sql
    ORDER BY w.name ASC
";

$stmt_warehouses = $conn->prepare($sql_warehouses);
if (!$is_global_admin) {
    $stmt_warehouses->bind_param("i", $account_id);
}
$stmt_warehouses->execute();
$result_warehouses = $stmt_warehouses->get_result();

$warehouses_with_inventory = [];
while ($warehouse = $result_warehouses->fetch_assoc()) {
    $warehouses_with_inventory[] = $warehouse;
}
$stmt_warehouses->close();

// 2) Calculate total modules in storage (assigned to projects)
if ($is_global_admin) {
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
        WHERE ip.status = 'In Warehouse'
          AND ip.assigned_project_id IS NOT NULL
          AND p.account_id = ?
    ";
    $params_assigned = [$account_id];
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
if ($is_global_admin) {
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
        WHERE ip.status = 'In Warehouse'
          AND ip.assigned_project_id IS NULL
          AND m.account_id = ?
    ";
    $params_unassigned = [$account_id];
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

// 3b) Calculate total pallets and wattage in storage
if ($is_global_admin) {
    $sql_total_assigned_pallets = "
        SELECT COUNT(ip.id) AS total_assigned_pallets
        FROM inventory_pallets ip
        WHERE ip.status = 'In Warehouse' AND ip.assigned_project_id IS NOT NULL
    ";
    $sql_total_unassigned_pallets = "
        SELECT COUNT(ip.id) AS total_unassigned_pallets
        FROM inventory_pallets ip
        WHERE ip.status = 'In Warehouse' AND ip.assigned_project_id IS NULL
    ";
    $sql_total_assigned_wattage = "
        SELECT SUM(ip.quantity * COALESCE(ip.wattage, 0)) AS total_assigned_wattage
        FROM inventory_pallets ip
        WHERE ip.status = 'In Warehouse' AND ip.assigned_project_id IS NOT NULL
    ";
    $sql_total_unassigned_wattage = "
        SELECT SUM(ip.quantity * COALESCE(ip.wattage, 0)) AS total_unassigned_wattage
        FROM inventory_pallets ip
        WHERE ip.status = 'In Warehouse' AND ip.assigned_project_id IS NULL
    ";
    $params_pallets = [];
    $types_pallets = "";
} else {
    $sql_total_assigned_pallets = "
        SELECT COUNT(ip.id) AS total_assigned_pallets
        FROM inventory_pallets ip
        JOIN projects p ON ip.assigned_project_id = p.id
        WHERE ip.status = 'In Warehouse'
          AND ip.assigned_project_id IS NOT NULL
          AND p.account_id = ?
    ";
    $sql_total_unassigned_pallets = "
        SELECT COUNT(ip.id) AS total_unassigned_pallets
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE ip.status = 'In Warehouse'
          AND ip.assigned_project_id IS NULL
          AND m.account_id = ?
    ";
    $sql_total_assigned_wattage = "
        SELECT SUM(ip.quantity * COALESCE(ip.wattage, 0)) AS total_assigned_wattage
        FROM inventory_pallets ip
        JOIN projects p ON ip.assigned_project_id = p.id
        WHERE ip.status = 'In Warehouse'
          AND ip.assigned_project_id IS NOT NULL
          AND p.account_id = ?
    ";
    $sql_total_unassigned_wattage = "
        SELECT SUM(ip.quantity * COALESCE(ip.wattage, 0)) AS total_unassigned_wattage
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE ip.status = 'In Warehouse'
          AND ip.assigned_project_id IS NULL
          AND m.account_id = ?
    ";
    $params_pallets = [$account_id];
    $types_pallets = "i";
}

$stmt_assigned_pallets = $conn->prepare($sql_total_assigned_pallets);
if (!empty($types_pallets)) {
    $stmt_assigned_pallets->bind_param($types_pallets, ...$params_pallets);
}
$stmt_assigned_pallets->execute();
$stmt_assigned_pallets->bind_result($total_assigned_pallets);
$stmt_assigned_pallets->fetch();
$stmt_assigned_pallets->close();
$total_assigned_pallets = $total_assigned_pallets ?: 0;

$stmt_unassigned_pallets = $conn->prepare($sql_total_unassigned_pallets);
if (!empty($types_pallets)) {
    $stmt_unassigned_pallets->bind_param($types_pallets, ...$params_pallets);
}
$stmt_unassigned_pallets->execute();
$stmt_unassigned_pallets->bind_result($total_unassigned_pallets);
$stmt_unassigned_pallets->fetch();
$stmt_unassigned_pallets->close();
$total_unassigned_pallets = $total_unassigned_pallets ?: 0;

$stmt_assigned_wattage = $conn->prepare($sql_total_assigned_wattage);
if (!empty($types_pallets)) {
    $stmt_assigned_wattage->bind_param($types_pallets, ...$params_pallets);
}
$stmt_assigned_wattage->execute();
$stmt_assigned_wattage->bind_result($total_assigned_wattage);
$stmt_assigned_wattage->fetch();
$stmt_assigned_wattage->close();
$total_assigned_wattage = $total_assigned_wattage ?: 0;

$stmt_unassigned_wattage = $conn->prepare($sql_total_unassigned_wattage);
if (!empty($types_pallets)) {
    $stmt_unassigned_wattage->bind_param($types_pallets, ...$params_pallets);
}
$stmt_unassigned_wattage->execute();
$stmt_unassigned_wattage->bind_result($total_unassigned_wattage);
$stmt_unassigned_wattage->fetch();
$stmt_unassigned_wattage->close();
$total_unassigned_wattage = $total_unassigned_wattage ?: 0;

// 4) Calculate total monthly storage cost
$total_monthly_storage_cost = 0;
foreach ($warehouses_with_inventory as $warehouse) {
    $total_pallets_in_warehouse = ($warehouse['assigned_pallets_stored'] ?? 0) + ($warehouse['unassigned_pallets_stored'] ?? 0);
    $warehouse_monthly_cost = $total_pallets_in_warehouse * ($warehouse['monthly_storage_fee'] ?? 0);
    $total_monthly_storage_cost += $warehouse_monthly_cost;
}

// 5) Count projects with modules in storage
if ($is_global_admin) {
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
        WHERE ip.status = 'In Warehouse'
          AND ip.assigned_project_id IS NOT NULL
          AND p.account_id = ?
    ";
    $params_proj = [$account_id];
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
$total_pallets_in_storage = $total_assigned_pallets + $total_unassigned_pallets;
$total_wattage_in_storage = $total_assigned_wattage + $total_unassigned_wattage;
$total_mw_in_storage = $total_wattage_in_storage > 0 ? $total_wattage_in_storage / 1000000 : 0;
$total_assigned_mw = $total_assigned_wattage > 0 ? $total_assigned_wattage / 1000000 : 0;
$total_unassigned_mw = $total_unassigned_wattage > 0 ? $total_unassigned_wattage / 1000000 : 0;
$avg_assigned_modules = $total_projects_with_storage > 0 ? $total_assigned_modules / $total_projects_with_storage : 0;
$avg_assigned_pallets = $total_projects_with_storage > 0 ? $total_assigned_pallets / $total_projects_with_storage : 0;
$avg_assigned_mw = $total_projects_with_storage > 0 ? $total_assigned_mw / $total_projects_with_storage : 0;
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
        .page-header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
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
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(72, 140, 154, 0.12);
            background: #ffffff;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .filter-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: #6c757d;
        }

        /* Unit/Filter Chips (match Project Overview) */
        .unit-filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            border: 1px solid rgba(72, 140, 154, 0.12);
            width: fit-content;
        }
        .unit-filter-bar .filter-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: #6c757d;
        }
        .filter-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .filter-chip {
            padding: 8px 16px;
            border: 1px solid #e9ecef;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
            color: #6c757d;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .filter-chip:hover:not(.active) {
            background: rgba(72, 140, 154, 0.08);
            border-color: rgba(72, 140, 154, 0.3);
            color: #488C9A;
        }
        .filter-chip.active {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.3);
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
            flex: 1 1 auto;
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
        .wh-overview-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.7em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(72, 140, 154, 0.1);
            color: #488C9A;
            margin-left: 8px;
        }
        .wh-overview-type-badge.port {
            background: rgba(255, 193, 7, 0.15);
            color: #b87900;
        }
        .wh-overview-type-badge.warehouse {
            background: rgba(72, 140, 154, 0.12);
            color: #488C9A;
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
            grid-template-columns: repeat(3, 1fr);
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
        .wh-overview-card-actions {
            display: flex;
            gap: 10px;
            padding: 14px 20px 20px 20px;
            border-top: 1px solid rgba(72, 140, 154, 0.08);
            background: #ffffff;
            flex-wrap: wrap;
        }
        .wh-action-btn {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.85em;
            font-weight: 600;
            border: 1px solid rgba(72, 140, 154, 0.2);
            background: rgba(72, 140, 154, 0.08);
            color: #488C9A;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .wh-action-btn:hover {
            background: rgba(72, 140, 154, 0.16);
            color: #293E4C;
        }
        .wh-action-btn.primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-color: transparent;
            color: #fff;
        }
        .wh-action-btn.primary:hover {
            opacity: 0.9;
        }
        .wh-action-btn.danger {
            background: rgba(220, 53, 69, 0.1);
            border-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        .wh-action-btn.danger:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        .btn-add-warehouse {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 12px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 6px 16px rgba(72, 140, 154, 0.25);
            transition: all 0.2s ease;
        }
        .btn-add-warehouse:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(72, 140, 154, 0.3);
        }

        .alert {
            border-radius: 12px;
            padding: 12px 16px;
            margin: 16px 0;
            font-weight: 600;
        }
        .alert-success {
            background: rgba(40, 167, 69, 0.12);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        .alert-error {
            background: rgba(220, 53, 69, 0.12);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            background: #fff;
            border-radius: 20px;
            padding: 0;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            transform: scale(0.9);
            transition: transform 0.3s;
        }
        .modal-overlay.active .modal-content {
            transform: scale(1);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e9ecef;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }
        .modal-header h3 {
            margin: 0;
            color: #293E4C;
            font-size: 1.2em;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-header h3 i {
            color: #488C9A;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #6c757d;
            padding: 0;
            line-height: 1;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .modal-close:hover {
            background: #f8f9fa;
            color: #293E4C;
        }
        .modal-body {
            padding: 24px;
            max-height: 50vh;
            overflow-y: auto;
        }
        .fee-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .fee-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #488C9A;
        }
        .fee-item.entry { border-left-color: #28a745; }
        .fee-item.exit { border-left-color: #dc3545; }
        .fee-item.monthly { border-left-color: #ffc107; }
        .fee-item.customs_clearance { border-left-color: #007cba; }
        .fee-item.drayage { border-left-color: #6f42c1; }
        .fee-info { flex: 1; }
        .fee-name {
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 2px;
        }
        .fee-trigger {
            font-size: 0.8em;
            color: #6c757d;
            text-transform: capitalize;
        }
        .fee-amount {
            font-size: 1.1em;
            font-weight: 700;
            color: #488C9A;
        }
        .fee-unit {
            font-size: 0.75em;
            color: #6c757d;
            font-weight: 400;
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .modal-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.9em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .modal-btn.primary {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: white;
            border: none;
        }
        .modal-btn.primary:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
        }
        .modal-btn.secondary {
            background: #f8f9fa;
            color: #495057;
            border: 1px solid #e9ecef;
        }
        .modal-btn.secondary:hover {
            background: #e9ecef;
        }
        .no-fees-message {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        .no-fees-message i {
            font-size: 3em;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .wh-overview-cards-container {
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            }
            .wh-overview-stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            .wh-overview-card-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .unit-filter-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            .unit-filter-bar .filter-label {
                text-align: center;
            }
            .filter-chips {
                justify-content: center;
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
            <?php if ($can_manage_warehouses): ?>
            <div class="page-header-actions">
                <a href="add_warehouse.php" class="btn-add-warehouse">
                    <i class="fas fa-plus"></i> Add Warehouse
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($successMessage)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <div class="unit-filter-bar">
        <span class="filter-label">View as:</span>
        <div class="filter-chips">
            <button type="button" class="filter-chip active" onclick="setUnitFilter('modules', this)">Modules</button>
            <button type="button" class="filter-chip" onclick="setUnitFilter('pallets', this)">Pallets</button>
            <button type="button" class="filter-chip" onclick="setUnitFilter('mw', this)">MWs</button>
        </div>
    </div>

    <!-- Enhanced Key Figures with Dropdowns -->
    <div class="key-figures">
        <div class="figure" onclick="toggleFigure(this)">
            <i class="fas fa-chevron-down figure-expand-icon"></i>
            <div class="figure-icon"><i class="fas fa-boxes-stacked"></i></div>
            <h3>Total <span class="js-unit-name">Modules</span> in Storage</h3>
            <div class="number js-unit-value"
                 data-modules="<?php echo (int)$total_modules_in_storage; ?>"
                 data-pallets="<?php echo (int)$total_pallets_in_storage; ?>"
                 data-mw="<?php echo number_format($total_mw_in_storage, 2, '.', ''); ?>">
                <?php echo number_format($total_modules_in_storage); ?>
            </div>
            <div class="label">Across all warehouses</div>
            <div class="figure-details">
                <div class="detail-row">
                    <span class="detail-label">Project Assigned</span>
                    <span class="detail-value js-unit-value"
                          data-modules="<?php echo (int)$total_assigned_modules; ?>"
                          data-pallets="<?php echo (int)$total_assigned_pallets; ?>"
                          data-mw="<?php echo number_format($total_assigned_mw, 2, '.', ''); ?>">
                        <?php echo number_format($total_assigned_modules); ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Unassigned</span>
                    <span class="detail-value js-unit-value"
                          data-modules="<?php echo (int)$total_unassigned_modules; ?>"
                          data-pallets="<?php echo (int)$total_unassigned_pallets; ?>"
                          data-mw="<?php echo number_format($total_unassigned_mw, 2, '.', ''); ?>">
                        <?php echo number_format($total_unassigned_modules); ?>
                    </span>
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
            <h3>Project <span class="js-unit-name">Modules</span></h3>
            <div class="number js-unit-value"
                 data-modules="<?php echo (int)$total_assigned_modules; ?>"
                 data-pallets="<?php echo (int)$total_assigned_pallets; ?>"
                 data-mw="<?php echo number_format($total_assigned_mw, 2, '.', ''); ?>">
                <?php echo number_format($total_assigned_modules); ?>
            </div>
            <div class="label">Assigned to <?php echo $total_projects_with_storage; ?> projects</div>
            <div class="figure-details">
                <div class="detail-row">
                    <span class="detail-label">Projects with Storage</span>
                    <span class="detail-value"><?php echo $total_projects_with_storage; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label js-unit-detail-label" data-base="Avg" data-suffix="per Project">Avg Modules per Project</span>
                    <span class="detail-value js-unit-value"
                          data-modules="<?php echo number_format($avg_assigned_modules, 2, '.', ''); ?>"
                          data-pallets="<?php echo number_format($avg_assigned_pallets, 2, '.', ''); ?>"
                          data-mw="<?php echo number_format($avg_assigned_mw, 2, '.', ''); ?>">
                        <?php echo number_format($avg_assigned_modules, 0); ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="figure" onclick="toggleFigure(this)">
            <i class="fas fa-chevron-down figure-expand-icon"></i>
            <div class="figure-icon"><i class="fas fa-box-open"></i></div>
            <h3>Unassigned <span class="js-unit-name">Modules</span></h3>
            <div class="number js-unit-value"
                 data-modules="<?php echo (int)$total_unassigned_modules; ?>"
                 data-pallets="<?php echo (int)$total_unassigned_pallets; ?>"
                 data-mw="<?php echo number_format($total_unassigned_mw, 2, '.', ''); ?>">
                <?php echo number_format($total_unassigned_modules); ?>
            </div>
            <div class="label">Available for assignment</div>
            <div class="figure-details">
                <?php foreach ($warehouses_with_inventory as $wh_unass):
                    $unassigned_modules = (int)($wh_unass['unassigned_modules_stored'] ?? 0);
                    $unassigned_pallets = (int)($wh_unass['unassigned_pallets_stored'] ?? 0);
                    $unassigned_mw = !empty($wh_unass['unassigned_wattage_stored']) ? ($wh_unass['unassigned_wattage_stored'] / 1000000) : 0;
                    if ($unassigned_modules > 0 || $unassigned_pallets > 0 || $unassigned_mw > 0):
                ?>
                <div class="detail-row">
                    <span class="detail-label"><?php echo htmlspecialchars($wh_unass['name']); ?></span>
                    <span class="detail-value js-unit-value"
                          data-modules="<?php echo (int)$unassigned_modules; ?>"
                          data-pallets="<?php echo (int)$unassigned_pallets; ?>"
                          data-mw="<?php echo number_format($unassigned_mw, 2, '.', ''); ?>">
                        <?php echo number_format($unassigned_modules); ?>
                    </span>
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
            <div class="filter-group">
                <span class="filter-label">Inventory</span>
                <div class="filter-chips">
                    <button class="filter-chip active" onclick="setInventoryFilter('all', this)">All</button>
                    <button class="filter-chip" onclick="setInventoryFilter('with-inventory', this)">With Inventory</button>
                    <button class="filter-chip" onclick="setInventoryFilter('empty', this)">Empty</button>
                </div>
            </div>
            <div class="filter-group">
                <span class="filter-label">View As:</span>
                <div class="filter-chips">
                    <button class="filter-chip active" onclick="setTypeFilter('all', this)">All</button>
                    <button class="filter-chip" onclick="setTypeFilter('warehouse', this)">Warehouses</button>
                    <button class="filter-chip" onclick="setTypeFilter('port', this)">Ports</button>
                </div>
            </div>
        </div>
    </div>

    <div class="wh-overview-cards-container" id="warehouseContainer">
        <?php if (!empty($all_warehouses)): ?>
            <?php foreach ($all_warehouses as $wh):
                $total_modules = ($wh['assigned_modules_stored'] ?? 0) + ($wh['unassigned_modules_stored'] ?? 0);
                $total_pallets = ($wh['assigned_pallets_stored'] ?? 0) + ($wh['unassigned_pallets_stored'] ?? 0);
                $total_wattage = ($wh['assigned_wattage_stored'] ?? 0) + ($wh['unassigned_wattage_stored'] ?? 0);
                $total_mw = $total_wattage > 0 ? $total_wattage / 1000000 : 0;
                $transit_modules = ($wh['modules_in_transit'] ?? 0);
                $transit_pallets = ($wh['pallets_in_transit'] ?? 0);
                $transit_wattage = ($wh['wattage_in_transit'] ?? 0);
                $transit_mw = $transit_wattage > 0 ? $transit_wattage / 1000000 : 0;
                $has_inventory = $total_modules > 0 || $total_pallets > 0;
                $has_transit = $transit_modules > 0 || $transit_pallets > 0;
                $warehouse_monthly_cost = $total_pallets * ($wh['monthly_storage_fee'] ?? 0);
                $warehouse_type = !empty($wh['is_port']) ? 'port' : 'warehouse';

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
                     data-has-transit="<?php echo $has_transit ? '1' : '0'; ?>"
                     data-type="<?php echo $warehouse_type; ?>">
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
                                <span class="wh-overview-type-badge <?php echo $warehouse_type; ?>">
                                    <?php echo $warehouse_type === 'port' ? 'Port' : 'Warehouse'; ?>
                                </span>
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
                                    <p class="stat-value js-unit-value"
                                       data-modules="<?php echo (int)$total_modules; ?>"
                                       data-pallets="<?php echo (int)$total_pallets; ?>"
                                       data-mw="<?php echo number_format($total_mw, 2, '.', ''); ?>">
                                        <?php echo number_format($total_modules); ?>
                                    </p>
                                    <p class="stat-label js-unit-label" data-base="Stored">Stored Modules</p>
                                </div>
                                <div class="wh-overview-stat-item">
                                    <p class="stat-value js-unit-value"
                                       data-modules="<?php echo (int)$transit_modules; ?>"
                                       data-pallets="<?php echo (int)$transit_pallets; ?>"
                                       data-mw="<?php echo number_format($transit_mw, 2, '.', ''); ?>">
                                        <?php echo number_format($transit_modules); ?>
                                    </p>
                                    <p class="stat-label js-unit-label" data-base="In Transit">In Transit Modules</p>
                                </div>
                                <div class="wh-overview-stat-item">
                                    <p class="stat-value">$<?php echo number_format($warehouse_monthly_cost, 0); ?></p>
                                    <p class="stat-label">Monthly</p>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php if ($can_manage_warehouses): ?>
                    <div class="wh-overview-card-actions">
                        <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo $wh['id']; ?>" class="wh-action-btn primary">Inventory</a>
                        <button type="button" class="wh-action-btn" onclick="openFeeModal(<?php echo $wh['id']; ?>)">Fees</button>
                        <a href="edit_warehouse.php?id=<?php echo $wh['id']; ?>" class="wh-action-btn">Edit</a>
                        <button type="button" class="wh-action-btn danger" onclick="confirmDelete('<?php echo htmlspecialchars($wh['name'], ENT_QUOTES); ?>', <?php echo $wh['id']; ?>)">Delete</button>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="grid-column: 1/-1; text-align: center; color: #6c757d;">No warehouses available.</p>
        <?php endif; ?>
    </div>

    <?php if ($can_manage_warehouses): ?>
    <!-- Fee Details Modal -->
    <div class="modal-overlay" id="feeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-dollar-sign"></i> <span id="modalWarehouseName">Fee Structure</span></h3>
                <button class="modal-close" onclick="closeFeeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalFeeList">
                <!-- Fee items will be inserted here -->
            </div>
            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeFeeModal()">Close</button>
                <a href="#" class="modal-btn primary" id="modalEditBtn">
                    <i class="fas fa-edit"></i> Edit Fees
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
function toggleFigure(element) {
    element.classList.toggle('expanded');
}

let currentInventoryFilter = 'all';
let currentTypeFilter = 'all';
let currentUnit = 'modules';

function setInventoryFilter(filter, btn) {
    currentInventoryFilter = filter;
    if (btn) {
        btn.closest('.filter-chips').querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
    applyWarehouseFilters();
}

function setTypeFilter(filter, btn) {
    currentTypeFilter = filter;
    if (btn) {
        btn.closest('.filter-chips').querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
    applyWarehouseFilters();
}

function setUnitFilter(unit, btn) {
    currentUnit = unit;
    if (btn) {
        btn.closest('.filter-chips').querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
    updateUnitDisplay();
}

function applyWarehouseFilters() {
    const cards = document.querySelectorAll('.wh-overview-card');
    cards.forEach(card => {
        const hasInventory = card.dataset.hasInventory === '1';
        const hasTransit = card.dataset.hasTransit === '1';
        const type = card.dataset.type || 'warehouse';

        let matchesInventory = true;
        if (currentInventoryFilter === 'with-inventory') {
            matchesInventory = hasInventory || hasTransit;
        } else if (currentInventoryFilter === 'empty') {
            matchesInventory = !hasInventory && !hasTransit;
        }

        let matchesType = true;
        if (currentTypeFilter !== 'all') {
            matchesType = type === currentTypeFilter;
        }

        card.style.display = (matchesInventory && matchesType) ? '' : 'none';
    });
}

function formatUnitValue(value, unit) {
    const num = parseFloat(value) || 0;
    if (unit === 'mw') {
        return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    return Math.round(num).toLocaleString('en-US');
}

function updateUnitDisplay() {
    const unitLabel = currentUnit === 'mw' ? 'MWs' : (currentUnit === 'pallets' ? 'Pallets' : 'Modules');
    document.querySelectorAll('.js-unit-value').forEach(el => {
        const rawValue = el.dataset[currentUnit] || 0;
        el.textContent = formatUnitValue(rawValue, currentUnit);
    });
    document.querySelectorAll('.js-unit-label').forEach(el => {
        const base = el.dataset.base || '';
        el.textContent = `${base} ${unitLabel}`.trim();
    });
    document.querySelectorAll('.js-unit-name').forEach(el => {
        el.textContent = unitLabel;
    });
    document.querySelectorAll('.js-unit-detail-label').forEach(el => {
        const base = el.dataset.base || '';
        const suffix = el.dataset.suffix || '';
        const text = `${base} ${unitLabel} ${suffix}`.trim();
        el.textContent = text;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    applyWarehouseFilters();
    updateUnitDisplay();
});

<?php if ($can_manage_warehouses): ?>
const warehouseData = <?php echo json_encode($all_warehouses, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS); ?>;

function confirmDelete(warehouseName, warehouseId) {
    if (confirm(`Are you sure you want to delete "${warehouseName}"?\n\nThis action cannot be undone.`)) {
        window.location.href = `warehousing_overview.php?action=delete&id=${warehouseId}`;
    }
}

function openFeeModal(warehouseId) {
    const warehouse = warehouseData.find(w => w.id == warehouseId);
    if (!warehouse) return;

    document.getElementById('modalWarehouseName').textContent = warehouse.name + ' - Fees';
    document.getElementById('modalEditBtn').href = `edit_warehouse.php?id=${warehouseId}#cost-structure`;

    const feeListContainer = document.getElementById('modalFeeList');

    if (warehouse.cost_items && warehouse.cost_items.length > 0) {
        let html = '<div class="fee-list">';
        warehouse.cost_items.forEach(fee => {
            const triggerClass = fee.trigger_event || 'other';
            let unitSuffix = '/pallet';
            switch (fee.unit_type) {
                case 'per_truck': unitSuffix = '/truck'; break;
                case 'per_sqft': unitSuffix = '/sqft'; break;
                case 'flat': unitSuffix = ' flat'; break;
            }
            const triggerLabel = fee.trigger_event ? fee.trigger_event.replace('_', ' ') : 'Other';

            html += `
                <div class="fee-item ${triggerClass}">
                    <div class="fee-info">
                        <div class="fee-name">${escapeHtml(fee.label)}</div>
                        <div class="fee-trigger">${triggerLabel}</div>
                    </div>
                    <div class="fee-amount">
                        $${parseFloat(fee.amount).toFixed(2)}
                        <span class="fee-unit">${unitSuffix}</span>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        feeListContainer.innerHTML = html;
    } else {
        feeListContainer.innerHTML = `
            <div class="no-fees-message">
                <i class="fas fa-receipt"></i>
                <p>No fees configured for this warehouse.</p>
                <p style="font-size: 0.9em;">Click "Edit Fees" to add a fee structure.</p>
            </div>
        `;
    }

    document.getElementById('feeModal').classList.add('active');
}

function closeFeeModal() {
    document.getElementById('feeModal').classList.remove('active');
}

document.getElementById('feeModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeFeeModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeFeeModal();
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
<?php endif; ?>
</script>
</body>
</html>
