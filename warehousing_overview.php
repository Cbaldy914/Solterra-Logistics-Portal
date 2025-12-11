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

        .key-figures {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        .figure {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 15px 20px;
            text-align: center;
            min-width: 200px;
            flex: 1;
        }
        .figure h3 {
            margin: 0 0 10px 0;
            color: #293E4C;
            font-size: 1.1em;
        }
        .figure .number {
            font-size: 1.8em;
            font-weight: 600;
            color: #488C9A;
            margin-bottom: 5px;
        }
        .figure .label {
            font-size: 0.9em;
            color: #666;
        }
        
        /* Warehouse cards styling from warehouse_info.php */
        .warehouse-cards-container {
            display: flex;
            flex-wrap: wrap;
            gap: 75px;
            justify-content: flex-start;
            padding: 10px 0;
        }
        .warehouse-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            width: 425px;
            min-width: 280px;
            background-color: #fff;
            display: flex;
            flex-direction: column;
        }
        .warehouse-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        .warehouse-card-link {
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            height: 100%;
        }
        .warehouse-card-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            display: block;
            border-bottom: 1px solid #eee;
        }
        .warehouse-card-name {
            font-size: 1.15em;
            font-weight: 600;
            color: #293E4C;
            padding: 12px 15px;
            text-align: center;
        }
        .warehouse-card-details {
            padding: 10px 15px 15px 15px;
            font-size: 0.9em;
            line-height: 1.6;
            flex-grow: 1;
        }
        .warehouse-card-details p {
            margin: 5px 0;
            color: #555;
        }
        .warehouse-card-details p strong {
            color: #333;
        }
        .warehouse-card-stats {
            background: #f8f9fa;
            padding: 10px 15px;
            border-top: 1px solid #eee;
            font-size: 0.85em;
        }
        .warehouse-card-stats .stat-row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
        }
        .warehouse-card-stats .stat-label {
            color: #666;
        }
        .warehouse-card-stats .stat-value {
            font-weight: 500;
            color: #488C9A;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .warehouse-card {
                width: calc(50% - 10px);
            }
        }
        @media (max-width: 600px) {
            .warehouse-card {
                width: 100%;
            }
            .key-figures {
                flex-direction: column;
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

    <div class="key-figures">
        <div class="figure">
            <h3>Total Modules in Storage</h3>
            <div class="number"><?php echo number_format($total_modules_in_storage); ?></div>
            <div class="label">Across all warehouses</div>
        </div>
        <div class="figure">
            <h3>Monthly Storage Cost</h3>
            <div class="number">$<?php echo number_format($total_monthly_storage_cost, 0); ?></div>
            <div class="label">Estimated monthly cost</div>
        </div>
        <div class="figure">
            <h3>Project Modules in Storage</h3>
            <div class="number"><?php echo number_format($total_assigned_modules); ?></div>
            <div class="label">Modules assigned to projects</div>
        </div>
        <div class="figure">
            <h3>Unassigned Modules in Storage</h3>
            <div class="number"><?php echo number_format($total_unassigned_modules); ?></div>
            <div class="label">Available for assignment</div>
        </div>
    </div>

    <h2>Warehouses with Inventory:</h2>
    <div class="warehouse-cards-container">
        <?php if (!empty($warehouses_with_inventory)): ?>
            <?php foreach ($warehouses_with_inventory as $wh): ?>
                <div class="warehouse-card">
                    <a href="warehouse_info.php?warehouse_id=<?php echo $wh['id']; ?>" class="warehouse-card-link">
                        <?php 
                        $wh_image_path = "pictures/warehouse-default.png"; // Default image
                        if (!empty($wh['image_url'])) {
                            if (filter_var($wh['image_url'], FILTER_VALIDATE_URL)) {
                                $wh_image_path = $wh['image_url'];
                            } else {
                                if (strpos($wh['image_url'], 'http') !== 0 && strpos($wh['image_url'], 'pictures/') !== 0 && strpos($wh['image_url'], 'uploads/') !== 0) {
                                   $wh_image_path = 'uploads/warehouse_images/' . ltrim(htmlspecialchars($wh['image_url']), '/');
                                } else {
                                   $wh_image_path = htmlspecialchars($wh['image_url']); 
                                }
                            }
                        }
                        ?>
                        <img src="<?php echo $wh_image_path; ?>" alt="<?php echo htmlspecialchars($wh['name']); ?>" class="warehouse-card-image">
                        <div class="warehouse-card-name">
                            <?php echo htmlspecialchars($wh['name']); ?> (ID: <?php echo $wh['id']; ?>)
                        </div>
                        <div class="warehouse-card-details">
                            <p><strong>Address:</strong> <?php echo htmlspecialchars($wh['address'] ?? 'N/A'); ?></p>
                            <p><strong>Monthly Fee per Pallet:</strong> $<?php echo number_format($wh['monthly_storage_fee'] ?? 0, 2); ?></p>
                        </div>
                        <div class="warehouse-card-stats">
                            <div class="stat-row">
                                <span class="stat-label">Assigned Modules:</span>
                                <span class="stat-value"><?php echo number_format($wh['assigned_modules_stored'] ?? 0); ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">Unassigned Modules:</span>
                                <span class="stat-value"><?php echo number_format($wh['unassigned_modules_stored'] ?? 0); ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">Total Pallets:</span>
                                <span class="stat-value"><?php echo number_format(($wh['assigned_pallets_stored'] ?? 0) + ($wh['unassigned_pallets_stored'] ?? 0)); ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">In Transit:</span>
                                <span class="stat-value"><?php echo number_format($wh['modules_in_transit'] ?? 0); ?> modules</span>
                            </div>
                            <?php 
                            $warehouse_monthly_cost = (($wh['assigned_pallets_stored'] ?? 0) + ($wh['unassigned_pallets_stored'] ?? 0)) * ($wh['monthly_storage_fee'] ?? 0);
                            ?>
                            <div class="stat-row">
                                <span class="stat-label">Monthly Cost:</span>
                                <span class="stat-value">$<?php echo number_format($warehouse_monthly_cost, 0); ?></span>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No warehouses currently have inventory for your account.</p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
