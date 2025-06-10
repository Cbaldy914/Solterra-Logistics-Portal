<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in (allow all logged-in users)
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

require_once '../config.php';
$conn = getDBConnection();

// Validate pallet_id
if (!isset($_GET['pallet_id']) || empty($_GET['pallet_id']) || !ctype_digit((string)$_GET['pallet_id'])) {
    die("Invalid Pallet ID provided.");
}
$pallet_id = (int)$_GET['pallet_id'];

$pallet_data = null;
$associated_deliveries = [];
$errorMessage = '';

try {
    // 1. Fetch Pallet Master Data
    $sql_pallet = "SELECT 
                        ip.id AS pallet_id,
                        ip.pallet_identifier,
                        ip.wattage,
                        ip.quantity,
                        ip.status,
                        ip.arrival_date,
                        m.vendor_name AS origin_vendor,
                        w.name AS current_warehouse_name,
                        p.project_name AS current_project_name,
                        CASE
                            WHEN ip.status = 'At Manufacturer' THEN 'At Manufacturer'
                            WHEN ip.status = 'In Warehouse' AND w.name IS NOT NULL THEN CONCAT('Warehouse: ', w.name)
                            WHEN ip.status = 'In Transit to Warehouse' AND w.name IS NOT NULL THEN CONCAT('In Transit to Warehouse: ', w.name)
                            WHEN ip.status = 'Delivered to Project' AND p.project_name IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                            WHEN ip.status = 'In Transit to Project' AND p.project_name IS NOT NULL THEN CONCAT('In Transit to Project: ', p.project_name)
                            ELSE ip.status
                        END AS current_location_display
                    FROM inventory_pallets ip
                    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                    LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
                    LEFT JOIN projects p ON ip.current_project_id = p.id
                    WHERE ip.id = ?";
    
    $stmt_pallet = $conn->prepare($sql_pallet);
    if (!$stmt_pallet) {
        throw new Exception("Error preparing pallet query: " . $conn->error);
    }
    $stmt_pallet->bind_param("i", $pallet_id);
    $stmt_pallet->execute();
    $result_pallet = $stmt_pallet->get_result();

    if ($result_pallet->num_rows > 0) {
        $pallet_data = $result_pallet->fetch_assoc();
    } else {
        throw new Exception("Pallet with ID {$pallet_id} not found.");
    }
    $stmt_pallet->close();

    // 2. Fetch Associated Deliveries with enhanced cost calculations
    $sql_deliveries = "SELECT 
                            d.id AS delivery_id,
                            d.bol_number,
                            d.status_of_delivery,
                            d.supplier,
                            d.anticipated_delivery_date,
                            d.actual_delivery_date,
                            proj.project_name AS delivery_project_name,
                            d.freight_cost,
                            d.accessorial_costs,
                            -- Calculate total cost for truckload
                            (COALESCE(d.freight_cost, 0) + COALESCE(d.accessorial_costs, 0)) AS truckload_cost,
                            -- Count total pallets in this delivery
                            (SELECT COUNT(*) FROM delivery_pallets dp2 WHERE dp2.delivery_id = d.id) AS total_pallets_in_delivery,
                            -- Calculate cost per pallet (truckload cost divided by number of pallets)
                            CASE 
                                WHEN (SELECT COUNT(*) FROM delivery_pallets dp2 WHERE dp2.delivery_id = d.id) > 0 
                                THEN (COALESCE(d.freight_cost, 0) + COALESCE(d.accessorial_costs, 0)) / (SELECT COUNT(*) FROM delivery_pallets dp2 WHERE dp2.delivery_id = d.id)
                                ELSE 0 
                            END AS pallet_cost
                        FROM deliveries d 
                        JOIN delivery_pallets dp ON d.id = dp.delivery_id
                        LEFT JOIN projects proj ON d.project_id = proj.id
                        WHERE dp.inventory_pallet_id = ?
                        ORDER BY COALESCE(d.actual_delivery_date, d.anticipated_delivery_date) DESC";

    $stmt_deliveries = $conn->prepare($sql_deliveries);
    if (!$stmt_deliveries) {
        throw new Exception("Error preparing deliveries query: " . $conn->error);
    }
    $stmt_deliveries->bind_param("i", $pallet_id);
    $stmt_deliveries->execute();
    $result_deliveries = $stmt_deliveries->get_result();

    $total_pallet_cost = 0; // Initialize total cost tracker
    while ($row = $result_deliveries->fetch_assoc()) {
        $associated_deliveries[] = $row;
        $total_pallet_cost += $row['pallet_cost']; // Add to total
    }
    $stmt_deliveries->close();

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

$conn->close();

// Set up breadcrumbs
$breadcrumbs = [];
if ($role === 'admin' || $role === 'global_admin') {
    $breadcrumbs[] = ['href' => 'admin_dashboard.php', 'text' => 'Dashboard'];
    $breadcrumbs[] = ['href' => 'manage_pallets.php', 'text' => 'Manage Pallets'];
} else {
    $breadcrumbs[] = ['href' => 'dashboard.php', 'text' => 'Dashboard'];
}
$breadcrumbs[] = ['text' => 'Pallet Details'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pallet Details - ID: <?php echo $pallet_id; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
            margin-top: 10px;
            margin-left: 20px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
        .details-container {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 30px;
            margin: 20px;
        }
        .details-container h2 {
            margin-top: 0;
            color: #293E4C;
            border-bottom: 2px solid #488C9A;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .details-list dt {
            font-weight: 600;
            color: #333;
            width: 180px;
            float: left;
            clear: left;
            margin-bottom: 8px;
        }
        .details-list dd {
            margin-left: 190px;
            margin-bottom: 8px;
            color: #555;
        }
        .deliveries-section {
            margin: 20px;
        }
        .deliveries-section h2 {
             margin-top: 0;
             color: #293E4C;
             border-bottom: 2px solid #488C9A;
             padding-bottom: 10px;
             margin-bottom: 15px;
        }
        .error-message {
             color: red;
             background-color: #ffe6e6;
             padding: 10px;
             border: 1px solid red;
             border-radius: 5px;
             margin: 20px;
        }
        .table-responsive {
             width: 100%;
             overflow-x: auto;
        }
        table {
             width: 100%;
             border-collapse: collapse;
             margin-bottom: 20px;
        }
        table, th, td {
             border: 1px solid #ccc;
        }
        th, td { 
             padding: 12px; 
             text-align: center;
        }
        th {
             background-color: #293E4C;
             color: white;
             font-weight: 600;
        }
        tr:hover { 
             background: #f1f1f1; 
        }
        .cost-summary {
            background-color: #e8f4f8;
            padding: 15px;
            border: 1px solid #488C9A;
            border-radius: 8px;
            margin: 20px;
            text-align: center;
            width: auto !important;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        .cost-summary h3 {
            margin: 0 0 10px 0;
            color: #293E4C;
        }
        .cost-amount {
            font-size: 1.5em;
            font-weight: bold;
            color: #488C9A;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            font-weight: 500;
        }
        .status-delivered {
            background-color: #d4edda;
            color: #155724;
        }
        .status-transit {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-pending {
            background-color: #f8d7da;
            color: #721c24;
        }
        @media screen and (max-width: 768px) {
            .details-list dt {
                width: 100%;
                float: none;
                margin-bottom: 4px;
            }
            .details-list dd {
                margin-left: 0;
                margin-bottom: 12px;
                padding-left: 10px;
                border-left: 3px solid #488C9A;
            }
            th, td {
                padding: 8px 4px;
                font-size: 0.9em;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Breadcrumb navigation -->
    <div class="breadcrumb">
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <?php if (isset($crumb['href'])): ?>
                <a href="<?php echo $crumb['href']; ?>"><?php echo htmlspecialchars($crumb['text']); ?></a>
            <?php else: ?>
                <span><?php echo htmlspecialchars($crumb['text']); ?></span>
            <?php endif; ?>
            <?php if ($index < count($breadcrumbs) - 1): ?>
                <span class="separator">&raquo;</span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="main-content">
        <h1>Pallet Details - ID: <?php echo $pallet_id; ?></h1>

        <?php if (!empty($errorMessage)): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php elseif ($pallet_data): ?>
            <div class="details-container">
                <h2>Pallet Information</h2>
                <dl class="details-list">
                    <dt>Pallet ID:</dt>
                    <dd><?php echo $pallet_data['pallet_id']; ?></dd>
                    
                    <dt>Identifier:</dt>
                    <dd><?php echo htmlspecialchars($pallet_data['pallet_identifier'] ?? 'N/A'); ?></dd>

                    <dt>Manufacturer:</dt>
                    <dd><?php echo htmlspecialchars($pallet_data['origin_vendor'] ?? 'N/A'); ?></dd>

                    <dt>Wattage:</dt>
                    <dd><?php echo htmlspecialchars($pallet_data['wattage']); ?>W</dd>

                    <dt>Quantity:</dt>
                    <dd><?php echo number_format($pallet_data['quantity']); ?></dd>

                    <dt>Current Status:</dt>
                    <dd>
                        <?php 
                        $status = $pallet_data['status'];
                        $badge_class = 'status-badge ';
                        if (strpos($status, 'Delivered') !== false) {
                            $badge_class .= 'status-delivered';
                        } elseif (strpos($status, 'Transit') !== false) {
                            $badge_class .= 'status-transit';
                        } else {
                            $badge_class .= 'status-pending';
                        }
                        ?>
                        <span class="<?php echo $badge_class; ?>"><?php echo htmlspecialchars($status); ?></span>
                    </dd>

                    <dt>Current Location:</dt>
                    <dd><?php echo htmlspecialchars($pallet_data['current_location_display']); ?></dd>
                    
                    <dt>Arrival Date (at first location):</dt>
                    <dd><?php echo htmlspecialchars($pallet_data['arrival_date'] ?? 'N/A'); ?></dd>
                </dl>
            </div>

            <?php if ($total_pallet_cost > 0): ?>
            <div class="cost-summary">
                <h3>Total Pallet Cost (All Deliveries)</h3>
                <div class="cost-amount">$<?php echo number_format($total_pallet_cost, 2); ?></div>
            </div>
            <?php endif; ?>

            <div class="deliveries-section">
                <h2>Associated Deliveries</h2>
                <?php if (!empty($associated_deliveries)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Delivery ID</th>
                                    <th>BOL Number</th>
                                    <th>Delivery Project</th>
                                    <th>Manufacturer</th>
                                    <th>Delivery Status</th>
                                    <th>Anticipated Date</th>
                                    <th>Actual Delivery</th>
                                    <th>Truckload Cost</th>
                                    <th>Pallet Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($associated_deliveries as $delivery): ?>
                                    <tr>
                                        <td>
                                            <?php if ($role === 'admin' || $role === 'global_admin'): ?>
                                                <a href="manage_deliveries.php#delivery-<?php echo $delivery['delivery_id']; ?>" style="color: #488C9A; text-decoration: none; font-weight: 500;"><?php echo $delivery['delivery_id']; ?></a>
                                            <?php else: ?>
                                                <span style="font-weight: 500;"><?php echo $delivery['delivery_id']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($delivery['bol_number'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['delivery_project_name'] ?? ''); ?></td>
                                        <td>
                                            <?php 
                                            // Extract manufacturer name (remove anything after " - ")
                                            $manufacturer = $delivery['supplier'] ?? '';
                                            if (strpos($manufacturer, ' - ') !== false) {
                                                $manufacturer = trim(explode(' - ', $manufacturer)[0]);
                                            }
                                            echo htmlspecialchars($manufacturer); 
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $delivery_status = $delivery['status_of_delivery'] ?? '';
                                            $badge_class = 'status-badge ';
                                            if (strpos($delivery_status, 'Delivered') !== false) {
                                                $badge_class .= 'status-delivered';
                                            } elseif (strpos($delivery_status, 'Transit') !== false) {
                                                $badge_class .= 'status-transit';
                                            } else {
                                                $badge_class .= 'status-pending';
                                            }
                                            ?>
                                            <span class="<?php echo $badge_class; ?>"><?php echo htmlspecialchars($delivery_status); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($delivery['anticipated_delivery_date'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['actual_delivery_date'] ?? ''); ?></td>
                                        <td>
                                            <?php 
                                            if ($delivery['truckload_cost'] > 0) {
                                                echo '<strong>$' . number_format($delivery['truckload_cost'], 2) . '</strong>';
                                            } else {
                                                echo '';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($delivery['pallet_cost'] > 0) {
                                                echo '<strong>$' . number_format($delivery['pallet_cost'], 2) . '</strong>';
                                            } else {
                                                echo '';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; background-color: #f8f9fa; border-radius: 8px; color: #6c757d;">
                        <p style="margin: 0; font-size: 1.1em;">This pallet has not been associated with any deliveries yet.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?> 
    </div>
</main>
</body>
</html> 