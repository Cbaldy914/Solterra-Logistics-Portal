<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

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

    // 2. Fetch Associated Deliveries
    $sql_deliveries = "SELECT 
                            d.id AS delivery_id,
                            d.bol_number,
                            d.status_of_delivery,
                            d.supplier,
                            d.anticipated_delivery_date,
                            d.warehouse_arrival_date,
                            d.actual_delivery_date,
                            d.left_warehouse_date,
                            proj.project_name AS delivery_project_name
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

    while ($row = $result_deliveries->fetch_assoc()) {
        $associated_deliveries[] = $row;
    }
    $stmt_deliveries->close();

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
    <title>Pallet Details - ID: <?php echo $pallet_id; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .details-container {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 30px;
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
             margin-bottom: 20px;
         }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <a href="manage_pallets.php" class="back-icon" style="margin-bottom: 20px;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:24px;height:24px;">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back to Manage Pallets
    </a>

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

                <dt>Origin Vendor:</dt>
                <dd><?php echo htmlspecialchars($pallet_data['origin_vendor'] ?? 'N/A'); ?></dd>

                <dt>Wattage:</dt>
                <dd><?php echo htmlspecialchars($pallet_data['wattage']); ?>W</dd>

                <dt>Quantity:</dt>
                <dd><?php echo number_format($pallet_data['quantity']); ?></dd>

                <dt>Current Status:</dt>
                <dd><?php echo htmlspecialchars($pallet_data['status']); ?></dd>

                <dt>Current Location:</dt>
                <dd><?php echo htmlspecialchars($pallet_data['current_location_display']); ?></dd>
                
                <dt>Arrival Date (at first location):</dt>
                <dd><?php echo htmlspecialchars($pallet_data['arrival_date'] ?? 'N/A'); ?></dd>
            </dl>
        </div>

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
                                <th>Supplier</th>
                                <th>Delivery Status</th>
                                <th>Anticipated Date</th>
                                <th>Warehouse Arrival</th>
                                <th>Actual Delivery</th>
                                <th>Left Warehouse</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($associated_deliveries as $delivery): ?>
                                <tr>
                                    <td><?php echo $delivery['delivery_id']; ?></td>
                                    <td><?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($delivery['delivery_project_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($delivery['anticipated_delivery_date'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($delivery['actual_delivery_date'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($delivery['left_warehouse_date'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>This pallet has not been associated with any deliveries yet.</p>
            <?php endif; ?>
        </div>

    <?php endif; ?> 

</main>
</body>
</html> 