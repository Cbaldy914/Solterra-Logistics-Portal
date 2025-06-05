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

// Get and validate warehouse ID
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
if ($warehouse_id <= 0) {
    die("Invalid Warehouse ID provided.");
}

$warehouse = null;
$pallets_in_storage = [];
$pallets_in_transit = [];
$errorMessage = '';
$successMessage = $_SESSION['move_pallet_message'] ?? '';
if (isset($_SESSION['move_pallet_message'])) unset($_SESSION['move_pallet_message']);

$total_storage_cost_monthly_rate = 0;
$all_projects = [];
$other_warehouses = [];

try {
    // Fetch Warehouse Details
    $stmtW = $conn->prepare("SELECT * FROM warehouses WHERE id = ?");
    if (!$stmtW) throw new Exception("Failed to prepare warehouse query: " . $conn->error);
    $stmtW->bind_param("i", $warehouse_id);
    $stmtW->execute();
    $resultW = $stmtW->get_result();
    if ($resultW->num_rows === 0) {
        throw new Exception("Warehouse not found.");
    }
    $warehouse = $resultW->fetch_assoc();
    $stmtW->close();

    // Fetch Pallets in Warehouse (Stored Inventory - By Pallet)
    $stmtP_Stored = $conn->prepare("
        SELECT 
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            ip.arrival_date,
            m.vendor_name AS origin_vendor,
            d_received.id AS received_delivery_id,
            d_received.bol_number AS received_bol
        FROM inventory_pallets ip
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN delivery_pallets dp_received ON ip.id = dp_received.inventory_pallet_id
        LEFT JOIN deliveries d_received ON dp_received.delivery_id = d_received.id AND d_received.warehouse_id = ?
        WHERE ip.current_warehouse_id = ? AND ip.status = 'In Warehouse'
        ORDER BY ip.arrival_date DESC, ip.id DESC
    ");
    if (!$stmtP_Stored) throw new Exception("Failed to prepare stored pallets query: " . $conn->error);
    $stmtP_Stored->bind_param("ii", $warehouse_id, $warehouse_id);
    $stmtP_Stored->execute();
    $resultP_Stored = $stmtP_Stored->get_result();
    
    $today = new DateTime();
    $daily_storage_rate = ($warehouse['monthly_storage_fee'] ?? 0) / 30;
    $total_pallets = 0;

    while ($pallet = $resultP_Stored->fetch_assoc()) {
        $pallets_in_storage[] = $pallet;
        $total_pallets++;
    }
    $stmtP_Stored->close();
    
    // Fetch Pallets in transit (Inbound Transit - By Pallet)
    $stmtP_Transit = $conn->prepare("
        SELECT 
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            m.vendor_name AS origin_vendor,
            d.bol_number AS delivery_bol,
            d.anticipated_delivery_date AS est_arrival_date,
            d.id AS delivery_id 
        FROM inventory_pallets ip
        JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
        JOIN deliveries d ON dp.delivery_id = d.id
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE ip.status = 'In Transit to Warehouse' AND d.warehouse_id = ?
        ORDER BY d.anticipated_delivery_date ASC, ip.id DESC
    ");
    if (!$stmtP_Transit) throw new Exception("Failed to prepare transit pallets query: " . $conn->error);
    $stmtP_Transit->bind_param("i", $warehouse_id);
    $stmtP_Transit->execute();
    $resultP_Transit = $stmtP_Transit->get_result();
    while ($pallet = $resultP_Transit->fetch_assoc()) {
        $pallets_in_transit[] = $pallet;
    }
    $stmtP_Transit->close();

    // NEW: Fetch Stored Inventory by Truckload (group by delivery_id)
    $stored_truckloads = [];
    $stmtStoredTruckloads = $conn->prepare("
        SELECT 
            d.id AS delivery_id,
            d.bol_number,
            d.supplier AS origin_vendor,
            d.warehouse_arrival_date AS received_date,
            d.proof_of_delivery,
            COUNT(ip.id) AS total_pallets,
            SUM(ip.quantity) AS total_modules,
            GROUP_CONCAT(DISTINCT ip.wattage ORDER BY ip.wattage SEPARATOR ', ') AS wattages
        FROM deliveries d
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        WHERE d.warehouse_id = ? 
        AND ip.current_warehouse_id = ? 
        AND ip.status = 'In Warehouse'
        AND d.status_of_delivery = 'Delivered to Warehouse'
        GROUP BY d.id, d.bol_number, d.supplier, d.warehouse_arrival_date, d.proof_of_delivery
        ORDER BY d.warehouse_arrival_date DESC
    ");
    if ($stmtStoredTruckloads) {
        $stmtStoredTruckloads->bind_param("ii", $warehouse_id, $warehouse_id);
        $stmtStoredTruckloads->execute();
        $resultStoredTruckloads = $stmtStoredTruckloads->get_result();
        while ($truckload = $resultStoredTruckloads->fetch_assoc()) {
            // Create wattage breakdown manually
            $wattages = explode(', ', $truckload['wattages']);
            $wattage_details = [];
            foreach ($wattages as $wattage) {
                // Get count and quantity for this specific wattage and delivery
                $stmtWattageDetail = $conn->prepare("
                    SELECT COUNT(ip.id) as pallet_count, SUM(ip.quantity) as module_count
                    FROM inventory_pallets ip
                    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                    WHERE dp.delivery_id = ? AND ip.wattage = ? AND ip.status = 'In Warehouse'
                ");
                if ($stmtWattageDetail) {
                    $stmtWattageDetail->bind_param("ii", $truckload['delivery_id'], $wattage);
                    $stmtWattageDetail->execute();
                    $stmtWattageDetail->bind_result($pallet_count, $module_count);
                    if ($stmtWattageDetail->fetch()) {
                        $wattage_details[] = "{$wattage}W: {$pallet_count} pallets ({$module_count} modules)";
                    }
                    $stmtWattageDetail->close();
                }
            }
            $truckload['wattage_breakdown'] = implode(' • ', $wattage_details);
            $stored_truckloads[] = $truckload;
        }
        $stmtStoredTruckloads->close();
    }

    // NEW: Fetch Inbound Transit by Truckload (group by delivery_id)
    $transit_truckloads = [];
    $stmtTransitTruckloads = $conn->prepare("
        SELECT 
            d.id AS delivery_id,
            d.bol_number,
            d.supplier AS origin_vendor,
            d.anticipated_delivery_date AS est_arrival_date,
            COUNT(ip.id) AS total_pallets,
            SUM(ip.quantity) AS total_modules,
            GROUP_CONCAT(DISTINCT ip.wattage ORDER BY ip.wattage SEPARATOR ', ') AS wattages
        FROM deliveries d
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        WHERE d.warehouse_id = ? 
        AND ip.status = 'In Transit to Warehouse'
        GROUP BY d.id, d.bol_number, d.supplier, d.anticipated_delivery_date
        ORDER BY d.anticipated_delivery_date ASC
    ");
    if ($stmtTransitTruckloads) {
        $stmtTransitTruckloads->bind_param("i", $warehouse_id);
        $stmtTransitTruckloads->execute();
        $resultTransitTruckloads = $stmtTransitTruckloads->get_result();
        while ($truckload = $resultTransitTruckloads->fetch_assoc()) {
            // Create wattage breakdown manually
            $wattages = explode(', ', $truckload['wattages']);
            $wattage_details = [];
            foreach ($wattages as $wattage) {
                // Get count and quantity for this specific wattage and delivery
                $stmtWattageDetail = $conn->prepare("
                    SELECT COUNT(ip.id) as pallet_count, SUM(ip.quantity) as module_count
                    FROM inventory_pallets ip
                    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                    WHERE dp.delivery_id = ? AND ip.wattage = ? AND ip.status = 'In Transit to Warehouse'
                ");
                if ($stmtWattageDetail) {
                    $stmtWattageDetail->bind_param("ii", $truckload['delivery_id'], $wattage);
                    $stmtWattageDetail->execute();
                    $stmtWattageDetail->bind_result($pallet_count, $module_count);
                    if ($stmtWattageDetail->fetch()) {
                        $wattage_details[] = "{$wattage}W: {$pallet_count} pallets ({$module_count} modules)";
                    }
                    $stmtWattageDetail->close();
                }
            }
            $truckload['wattage_breakdown'] = implode(' • ', $wattage_details);
            $transit_truckloads[] = $truckload;
        }
        $stmtTransitTruckloads->close();
    }

    // NEW: Fetch Inbound History (completed arrivals)
    $inbound_history = [];
    $stmtInboundHistory = $conn->prepare("
        SELECT 
            d.id AS delivery_id,
            d.bol_number,
            d.supplier,
            d.wattage,
            d.quantity AS total_modules,
            d.warehouse_arrival_date,
            d.proof_of_delivery
        FROM deliveries d
        WHERE d.warehouse_id = ? 
        AND d.status_of_delivery = 'Delivered to Warehouse'
        AND d.warehouse_arrival_date IS NOT NULL
        ORDER BY d.warehouse_arrival_date DESC
    ");
    if ($stmtInboundHistory) {
        $stmtInboundHistory->bind_param("i", $warehouse_id);
        $stmtInboundHistory->execute();
        $resultInboundHistory = $stmtInboundHistory->get_result();
        while ($delivery = $resultInboundHistory->fetch_assoc()) {
            $inbound_history[] = $delivery;
        }
        $stmtInboundHistory->close();
    }

    // NEW: Fetch Outbound History (departures from this warehouse)
    $outbound_history = [];
    $stmtOutboundHistory = $conn->prepare("
        SELECT 
            d.id AS delivery_id,
            d.bol_number,
            d.supplier,
            d.wattage,
            d.quantity AS total_modules,
            d.anticipated_delivery_date AS departure_date,
            CASE 
                WHEN d.project_id IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                WHEN d.warehouse_id != ? THEN CONCAT('Warehouse: ', w.name)
                ELSE 'Unknown Destination'
            END AS destination,
            d.status_of_delivery
        FROM deliveries d
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN warehouses w ON d.warehouse_id = w.id
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        WHERE ip.id IN (
            SELECT ip_inner.id 
            FROM inventory_pallets ip_inner 
            JOIN delivery_pallets dp_inner ON ip_inner.id = dp_inner.inventory_pallet_id
            JOIN deliveries d_inner ON dp_inner.delivery_id = d_inner.id
            WHERE d_inner.warehouse_id = ? AND d_inner.status_of_delivery = 'Delivered to Warehouse'
        )
        AND d.warehouse_id != ?  -- Exclude deliveries TO this warehouse
        AND (d.status_of_delivery LIKE 'In Transit%' OR d.status_of_delivery LIKE 'Delivered%')
        GROUP BY d.id, d.bol_number, d.supplier, d.wattage, d.quantity, d.anticipated_delivery_date, destination, d.status_of_delivery
        ORDER BY d.anticipated_delivery_date DESC
    ");
    if ($stmtOutboundHistory) {
        $stmtOutboundHistory->bind_param("iii", $warehouse_id, $warehouse_id, $warehouse_id);
        $stmtOutboundHistory->execute();
        $resultOutboundHistory = $stmtOutboundHistory->get_result();
        while ($delivery = $resultOutboundHistory->fetch_assoc()) {
            $outbound_history[] = $delivery;
        }
        $stmtOutboundHistory->close();
    }
    
    // Monthly cost estimate
    $total_storage_cost_monthly_rate = $total_pallets * ($warehouse['monthly_storage_fee'] ?? 0);

    // Fetch all projects
    $stmtAllP = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
    if ($stmtAllP) {
        $stmtAllP->execute();
        $resultAllP = $stmtAllP->get_result();
        while ($proj = $resultAllP->fetch_assoc()) {
            $all_projects[] = $proj;
        }
        $stmtAllP->close();
    }

    // Fetch other warehouses
    $stmtOtherW = $conn->prepare("SELECT id, name FROM warehouses WHERE id != ? ORDER BY name ASC");
    if ($stmtOtherW) {
        $stmtOtherW->bind_param("i", $warehouse_id);
        $stmtOtherW->execute();
        $resultOtherW = $stmtOtherW->get_result();
        while ($wh = $resultOtherW->fetch_assoc()) {
            $other_warehouses[] = $wh;
        }
        $stmtOtherW->close();
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
    <title>Manage Warehouse Inventory - <?php echo htmlspecialchars($warehouse['name'] ?? 'Unknown'); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">

    <style>
        .warehouse-details-container {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .warehouse-info p {
            margin: 6px 0;
            line-height: 1.5;
        }
        .cost-summary {
            margin-bottom: 25px;
        }
        .error-message, .success-message {
            color: red;
            background-color: #ffe6e6;
            padding: 10px;
            border: 1px solid red;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .success-message {
            color: green;
            border-color: green;
            background-color: #e6ffed;
        }
        .table-controls-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        .filter-controls {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .tabs-container {
            text-align: center;
            width: 100%;
            margin-bottom: 20px;
            border-bottom: 1px solid #ccc;
        }
        .tabs {
            display: inline-flex;
            gap: 1px;
        }
        .tabs button {
            background: #e9ecef;
            color: #333;
            padding: 10px 15px;
            cursor: pointer;
            font-weight: 600;
            border: none;
            border-top-left-radius: 5px;
            border-top-right-radius: 5px;
            font-size: 1em;
            border: 1px solid #ccc;
            border-bottom: none;
            margin-bottom: -1px;
            transition: background-color 0.2s, color 0.2s;
        }
        .tabs button.active {
            background: #fff;
            color: #293E4C;
            border-bottom: 1px solid #fff;
        }
        .sub-tabs-container {
            text-align: left;
            margin-bottom: 15px;
        }
        .sub-tab-button {
            padding: 8px 12px;
            cursor: pointer;
            background-color: #f0f0f0;
            border: 1px solid #ccc;
            margin-right: 5px;
            border-top-left-radius: 4px;
            border-top-right-radius: 4px;
            font-size: 0.9em;
            transition: background-color 0.2s, color 0.2s;
        }
        .sub-tab-button.active {
            background-color: #fff;
            border-bottom: 1px solid #fff;
            font-weight: bold;
            color: #293E4C;
        }
        .tab-content {
            display: none;
            margin-top: 10px;
        }
        .tab-content.active {
            display: block;
        }
        .sub-tab-content {
            display: none;
        }
        .sub-tab-content.active {
            display: block;
        }
        .page-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }
        .inventory-section {
            display: none;
            margin-top: 10px;
        }
        .inventory-section.active {
            display: block;
        }
        .table-responsive {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        th {
            background-color: #e9ecef;
        }
        th, td {
            padding: 8px;
            border: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 30px 30px 20px 30px;
            border: 1px solid #888;
            width: 100%;
            max-width: 500px;
            border-radius: 8px;
            position: relative;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .modal-header {
            text-align: center;
            font-size: 1.3em;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .close-modal, .close-receive-modal {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-modal:hover, .close-modal:focus,
        .close-receive-modal:hover, .close-receive-modal:focus {
            color: black;
            text-decoration: none;
        }
        .modal-form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 18px;
        }
        .modal-form-row > div {
            flex: 1;
            min-width: 150px;
        }
        .modal-content label {
            font-weight: 500;
            margin-bottom: 6px;
            display: block;
        }
        .modal-content input[type="text"],
        .modal-content input[type="date"],
        .modal-content select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-bottom: 8px;
            font-size: 1em;
            box-sizing: border-box;
        }
        .modal-content .radio-label {
            display: inline-block;
            margin-right: 20px;
            font-weight: normal;
        }
        .modal-content .radio-label input[type=radio] {
            margin-right: 5px;
            vertical-align: middle;
        }
        .modal-content button.action-button {
            background-color: #488C9A;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 1em;
            margin-top: 10px;
        }
        .modal-content button.action-button:hover {
            background-color: #3A6E7F;
        }
        /* Remove old background from form containers */
        #movePalletFormContainer, #receiveFormContainer {
            background: none;
            border: none;
            border-radius: 0;
            padding: 0;
            margin: 0;
        }
        #movePalletFormContainer h3 {
            margin-top: 0;
            color: #293E4C;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
        }
        .form-row > div {
            flex: 1;
            min-width: 200px;
        }
        .form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        label.radio-label {
            display: inline-block;
            margin-right: 15px;
            font-weight: normal;
        }
        label.radio-label input[type=radio] {
            width: auto;
            margin-right: 5px;
            vertical-align: middle;
        }
        .action-button {
            padding: 10px 20px;
            background-color: #488C9A;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .action-button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        .filter-controls label {
            font-weight: 500;
        }
        .filter-controls input[type="text"],
        .filter-controls select {
            padding: 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .back-link {
            margin-top: 20px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Add breadcrumb navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="admin_dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manage_warehouses.php" style="color: #488C9A; text-decoration: none;">Manage Warehouses</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Warehouse Inventory</span>
    </div>
    
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
        <p><a href="manage_warehouses.php" class="action-buttons">&larr; Back to Warehouses List</a></p>
    <?php elseif (!$warehouse): ?>
        <div class="error-message">
            Warehouse not found or could not be loaded.
        </div>
        <p><a href="manage_warehouses.php" class="action-buttons">&larr; Back to Warehouses List</a></p>
    <?php else: ?>
        <!-- Warehouse Details -->
        <div class="warehouse-details-container">
            <div class="warehouse-info">
                <h1><?php echo htmlspecialchars($warehouse['name']); ?></h1>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($warehouse['address']); ?></p>
                <p><strong>In Fee:</strong> $<?php echo number_format($warehouse['in_fee'] ?? 0, 2); ?></p>
                <p><strong>Out Fee:</strong> $<?php echo number_format($warehouse['out_fee'] ?? 0, 2); ?></p>
                <p><strong>Monthly Storage Fee (per Pallet):</strong> $<?php echo number_format($warehouse['monthly_storage_fee'] ?? 0, 2); ?></p>
            </div>
            <div class="warehouse-actions">
                <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="action-buttons">
                    Edit Warehouse Info
                </a>
            </div>
        </div>

        <!-- Cost Summary -->
        <div class="cost-summary">
            <h2>Current Inventory Summary</h2>
            <p>Total Pallets Currently Stored: <span><?php echo number_format($total_pallets); ?></span></p>
            <p>Estimated Monthly Storage Cost (Current Rate): <span>$<?php echo number_format($total_storage_cost_monthly_rate, 2); ?></span></p>
        </div>

        <!-- TABS (always visible) -->
        <div class="tabs-container">
            <div class="tabs">
                <button id="storedInventoryTab" class="active">Stored Inventory (<?php echo count($pallets_in_storage); ?>)</button>
                <button id="inboundTransitTab">Inbound Transit (<?php echo count($pallets_in_transit); ?>)</button>
                <button id="truckloadHistoryTab">Truckload History (<?php echo count($inbound_history) + count($outbound_history); ?>)</button>
            </div>
        </div>

        <!-- TAB CONTENT: STORED INVENTORY -->
        <div id="storedInventoryContent" class="tab-content active">
            <div class="sub-tabs-container">
                <button class="sub-tab-button active" onclick="showStoredSubView('byPallet')">By Pallet</button>
                <button class="sub-tab-button" onclick="showStoredSubView('byTruckload')">By Truckload</button>
            </div>

            <!-- Stored Inventory - By Pallet -->
            <div id="storedByPalletView" class="sub-tab-content active">
                <h2>Stored Inventory - Individual Pallets</h2>
                <div class="table-controls-header">
                    <div class="filter-controls">
                        <label>Search:</label>
                        <input type="text" id="storedSearch" placeholder="Filter by Identifier, Vendor...">
                        <label>Wattage:</label>
                        <select id="storedWattageFilter">
                            <option value="">All</option>
                            <?php
                            $storedWattages = [];
                            foreach ($pallets_in_storage as $p) {
                                $storedWattages[] = $p['wattage'];
                            }
                            $storedWattages = array_unique($storedWattages);
                            sort($storedWattages);
                            foreach ($storedWattages as $w) {
                                echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="page-actions">
                        <button id="movePalletsBtn" class="action-button" disabled>Move Selected Pallets</button>
                    </div>
                </div>

                <form id="palletInventoryForm" method="POST" action="handle_pallet_move.php">
                    <input type="hidden" name="current_warehouse_id" value="<?php echo $warehouse_id; ?>">
                    <input type="hidden" name="action" value="move_pallets">

                    <div class="table-responsive">
                        <table id="storedTable">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="select-all-stored"></th>
                                    <th>Identifier</th>
                                    <th>Origin Vendor</th>
                                    <th>Wattage</th>
                                    <th>Quantity</th>
                                    <th>Arrival Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pallets_in_storage)): ?>
                                    <?php foreach ($pallets_in_storage as $pallet): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox"
                                                       name="selected_pallets[]"
                                                       value="<?php echo $pallet['pallet_id']; ?>"
                                                       class="pallet-checkbox-stored">
                                            </td>
                                            <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                            <td><?php echo number_format($pallet['quantity']); ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($pallet['arrival_date']) && $pallet['arrival_date'] !== 'N/A') {
                                                    try {
                                                        $date = new DateTime($pallet['arrival_date']);
                                                        echo $date->format('m-d-Y');
                                                    } catch (Exception $e) {
                                                        echo htmlspecialchars($pallet['arrival_date']);
                                                    }
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6">No pallets currently stored in this warehouse.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

            <!-- Stored Inventory - By Truckload -->
            <div id="storedByTruckloadView" class="sub-tab-content">
                <h2>Stored Inventory - By Truckload</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>BOL Number</th>
                                <th>Origin Vendor</th>
                                <th>Received Date</th>
                                <th>Total Pallets</th>
                                <th>Total Modules</th>
                                <th>Wattage Breakdown</th>
                                <th>POD</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($stored_truckloads)): ?>
                                <?php foreach ($stored_truckloads as $truckload): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($truckload['bol_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['received_date'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($truckload['total_pallets']); ?></td>
                                        <td><?php echo number_format($truckload['total_modules']); ?></td>
                                        <td style="font-size: 0.9em;"><?php echo htmlspecialchars($truckload['wattage_breakdown'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                            if (!empty($truckload['proof_of_delivery'])) {
                                                echo '<a href="view_pod.php?delivery_id=' . $truckload['delivery_id'] . '" target="_blank" style="color: #488C9A;">View POD</a>';
                                            } else {
                                                echo '<button class="action-button" style="padding: 2px 6px; font-size: 0.8em;" onclick="uploadPOD(' . $truckload['delivery_id'] . ')">Upload POD</button>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <button class="action-button" style="padding: 3px 8px; font-size: 0.9em;" 
                                                    onclick="moveTruckload(<?php echo $truckload['delivery_id']; ?>)">
                                                Move Truckload
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                                            <?php else: ?>
                                    <tr><td colspan="8">No stored truckloads found in this warehouse.</td></tr>
                                <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT: INBOUND TRANSIT -->
        <div id="inboundTransitContent" class="tab-content">
            <div class="sub-tabs-container">
                <button class="sub-tab-button active" onclick="showTransitSubView('byPallet')">By Pallet</button>
                <button class="sub-tab-button" onclick="showTransitSubView('byTruckload')">By Truckload</button>
            </div>

            <!-- Inbound Transit - By Pallet -->
            <div id="transitByPalletView" class="sub-tab-content active">
                <h2>Inbound Transit - Individual Pallets</h2>
                <div class="table-controls-header">
                    <div class="filter-controls">
                        <label>Search:</label>
                        <input type="text" id="transitSearch" placeholder="Filter by Identifier, Vendor...">
                        <label>Wattage:</label>
                        <select id="transitWattageFilter">
                            <option value="">All</option>
                            <?php
                            $transitWattages = [];
                            foreach ($pallets_in_transit as $p) {
                                $transitWattages[] = $p['wattage'];
                            }
                            $transitWattages = array_unique($transitWattages);
                            sort($transitWattages);
                            foreach ($transitWattages as $w) {
                                echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="page-actions">
                        <button id="receivePalletsBtn" class="action-button" disabled>Receive Selected Pallets</button>
                    </div>
                </div>

                <form id="receivePalletsForm" method="POST" action="handle_pallet_arrival.php">
                    <input type="hidden" name="warehouse_id" value="<?php echo $warehouse_id; ?>">
                    <input type="hidden" name="action" value="receive_pallets">

                    <div class="table-responsive">
                        <table id="transitTable">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="select-all-transit"></th>
                                    <th>Identifier</th>
                                    <th>Origin Vendor</th>
                                    <th>Wattage</th>
                                    <th>Quantity</th>
                                    <th>Delivery BOL</th>
                                    <th>Est. Arrival Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pallets_in_transit)): ?>
                                    <?php foreach ($pallets_in_transit as $pallet): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox"
                                                       name="inbound_pallets[]"
                                                       value="<?php echo $pallet['pallet_id']; ?>"
                                                       class="transit-checkbox">
                                                <input type="hidden"
                                                       name="delivery_id_for_pallet[<?php echo $pallet['pallet_id']; ?>]"
                                                       value="<?php echo $pallet['delivery_id']; ?>">
                                            </td>
                                            <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                            <td><?php echo number_format($pallet['quantity']); ?></td>
                                            <td><?php echo htmlspecialchars($pallet['delivery_bol'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($pallet['est_arrival_date'] ?? 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7">No pallets currently in transit to this warehouse.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

            <!-- Inbound Transit - By Truckload -->
            <div id="transitByTruckloadView" class="sub-tab-content">
                <h2>Inbound Transit - By Truckload</h2>
                <div class="table-controls-header">
                    <div class="filter-controls">
                        <!-- Optional filters can be added here -->
                    </div>
                    <div class="page-actions">
                        <button id="receiveTruckloadBtn" class="action-button" disabled>Receive Selected Truckload</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="transitTruckloadTable">
                        <thead>
                            <tr>
                                <th><input type="radio" name="selected_truckload" value="" disabled></th>
                                <th>BOL Number</th>
                                <th>Origin Vendor</th>
                                <th>Est. Arrival Date</th>
                                <th>Total Pallets</th>
                                <th>Total Modules</th>
                                <th>Wattage Breakdown</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($transit_truckloads)): ?>
                                <?php foreach ($transit_truckloads as $truckload): ?>
                                    <tr>
                                        <td>
                                            <input type="radio" 
                                                   name="selected_truckload" 
                                                   value="<?php echo $truckload['delivery_id']; ?>"
                                                   class="truckload-radio"
                                                   onchange="updateReceiveTruckloadButton()">
                                        </td>
                                        <td><?php echo htmlspecialchars($truckload['bol_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['est_arrival_date'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($truckload['total_pallets']); ?></td>
                                        <td><?php echo number_format($truckload['total_modules']); ?></td>
                                        <td style="font-size: 0.9em;"><?php echo htmlspecialchars($truckload['wattage_breakdown'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7">No truckloads currently in transit to this warehouse.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT: TRUCKLOAD HISTORY -->
        <div id="truckloadHistoryContent" class="tab-content">
            <div class="sub-tabs-container">
                <button class="sub-tab-button active" onclick="showHistorySubView('inbound')">Inbound History</button>
                <button class="sub-tab-button" onclick="showHistorySubView('outbound')">Outbound History</button>
            </div>

            <!-- Inbound History -->
            <div id="inboundHistoryView" class="sub-tab-content active">
                <h2>Inbound Truckload History</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>BOL Number</th>
                                <th>Supplier</th>
                                <th>Wattage</th>
                                <th>Total Modules</th>
                                <th>Arrival Date</th>
                                <th>Proof of Delivery</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($inbound_history)): ?>
                                <?php foreach ($inbound_history as $delivery): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['wattage'] ?? 'N/A'); ?>W</td>
                                        <td><?php echo number_format($delivery['total_modules'] ?? 0); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                                <a href="view_pod.php?delivery_id=<?php echo $delivery['delivery_id']; ?>" target="_blank" style="color: #488C9A;">View POD</a>
                                            <?php else: ?>
                                                <button class="action-button" style="padding: 2px 6px; font-size: 0.8em;" onclick="uploadPOD(<?php echo $delivery['delivery_id']; ?>)">Upload POD</button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="edit_delivery.php?delivery_id=<?php echo $delivery['delivery_id']; ?>&warehouse_id=<?php echo $warehouse_id; ?>" 
                                               class="action-button" style="padding: 3px 8px; font-size: 0.9em;">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7">No inbound deliveries recorded for this warehouse.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Outbound History -->
            <div id="outboundHistoryView" class="sub-tab-content">
                <h2>Outbound Truckload History</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>BOL Number</th>
                                <th>Supplier</th>
                                <th>Destination</th>
                                <th>Wattage</th>
                                <th>Total Modules</th>
                                <th>Departure Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($outbound_history)): ?>
                                <?php foreach ($outbound_history as $delivery): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['destination'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['wattage'] ?? 'N/A'); ?>W</td>
                                        <td><?php echo number_format($delivery['total_modules'] ?? 0); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['departure_date'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7">No outbound deliveries recorded from this warehouse.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- Hidden "Move Pallet" form container (for modal) -->
<div id="movePalletFormContainer" style="display: none;">
    <div class="modal-form-row">
        <div>
            <label for="bol_number">BOL Number (Optional):</label>
            <input type="text" id="bol_number" name="bol_number">
        </div>
    </div>
    <div class="modal-form-row">
        <div>
            <label for="departure_date">Departure Date:</label>
            <input type="date" id="departure_date" name="departure_date" required>
        </div>
        <div>
            <label for="est_arrival_date">Est. Arrival Date:</label>
            <input type="date" id="est_arrival_date" name="est_arrival_date" required>
        </div>
    </div>
    <div>
        <label style="margin-bottom: 10px; display:block;">Destination:</label>
        <label class="radio-label">
            <input type="radio" name="destination_type" value="project" checked> Project
        </label>
        <label class="radio-label">
            <input type="radio" name="destination_type" value="warehouse"> Another Warehouse
        </label>
    </div>
    <div id="destinationSelectContainer">
        <label for="destination_id" id="destinationLabel">Project:</label>
        <select name="destination_id" id="destination_id" required></select>
    </div>
    
    <div style="margin-top: 20px; text-align: center;">
        <button type="button" id="submitMoveBtn" class="action-button">Create Transfer Delivery</button>
    </div>
</div>

<!-- Hidden "Receive Pallets" form container -->
<div id="receiveFormContainer" style="display: none;">
    <div class="modal-form-row">
        <div>
            <label for="receive_bol">BOL Number:</label>
            <input type="text" id="receive_bol" name="receive_bol">
        </div>
        <div>
            <label for="actual_arrival_date">Actual Arrival Date:</label>
            <input type="date" id="actual_arrival_date" name="actual_arrival_date" required>
        </div>
    </div>
    <div style="margin-top: 20px; text-align: center;">
        <button type="button" id="confirmReceiveBtn" class="action-button">Mark as Received</button>
    </div>
</div>

<!-- Hidden "Receive Truckload" form container -->
<div id="receiveTruckloadFormContainer" style="display: none;">
    <div class="modal-form-row">
        <div>
            <label for="receive_truckload_bol">BOL Number:</label>
            <input type="text" id="receive_truckload_bol" name="receive_truckload_bol">
        </div>
        <div>
            <label for="actual_truckload_arrival_date">Actual Arrival Date:</label>
            <input type="date" id="actual_truckload_arrival_date" name="actual_truckload_arrival_date" required>
        </div>
    </div>
    <div class="modal-form-row">
        <div>
            <label for="pod_file">Proof of Delivery (POD) - Optional:</label>
            <input type="file" id="pod_file" name="pod_file" accept=".pdf,.jpg,.jpeg,.png">
            <small style="display: block; color: #666; margin-top: 5px;">PDF, JPG, PNG files up to 5MB</small>
        </div>
    </div>
    <div style="margin-top: 20px; text-align: center;">
        <button type="button" id="confirmReceiveTruckloadBtn" class="action-button">Receive Entire Truckload</button>
    </div>
</div>

<div class="back-link">
    <a href="manage_warehouses.php" class="action-buttons">&larr; Back to Warehouses List</a>
</div>

<!-- MOVE PALLETS MODAL -->
<div id="moveModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <div class="modal-header">Create Transfer Delivery</div>
        <!-- #movePalletFormContainer is moved here dynamically -->
    </div>
</div>

<!-- RECEIVE PALLETS MODAL -->
<div id="receiveModal" class="modal">
    <div class="modal-content">
        <span class="close-receive-modal">&times;</span>
        <div class="modal-header">Receive Pallets</div>
        <!-- #receiveFormContainer is moved here dynamically -->
    </div>
</div>

<!-- RECEIVE TRUCKLOAD MODAL -->
<div id="receiveTruckloadModal" class="modal">
    <div class="modal-content">
        <span class="close-receive-truckload-modal">&times;</span>
        <div class="modal-header">Receive Truckload</div>
        <!-- #receiveTruckloadFormContainer is moved here dynamically -->
    </div>
</div>

<script>
// Projects & Warehouses for the Move Pallets dropdown
const allProjectsData = <?php echo json_encode($all_projects); ?>;
const otherWarehousesData = <?php echo json_encode($other_warehouses); ?>;

// ========== TAB MANAGEMENT ==========
function showMainTab(tabName) {
    // Hide all main tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active from all main tab buttons
    document.querySelectorAll('.tabs button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content and activate button
    const contentId = tabName + 'Content';
    const buttonId = tabName + 'Tab';
    
    document.getElementById(contentId).classList.add('active');
    document.getElementById(buttonId).classList.add('active');
}

function showStoredSubView(view) {
    // Hide all stored sub-tab contents
    document.querySelectorAll('#storedInventoryContent .sub-tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active from all stored sub-tab buttons
    document.querySelectorAll('#storedInventoryContent .sub-tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected view
    if (view === 'byPallet') {
        document.getElementById('storedByPalletView').classList.add('active');
        document.querySelector('#storedInventoryContent .sub-tab-button:first-child').classList.add('active');
    } else {
        document.getElementById('storedByTruckloadView').classList.add('active');
        document.querySelector('#storedInventoryContent .sub-tab-button:last-child').classList.add('active');
    }
}

function showTransitSubView(view) {
    // Hide all transit sub-tab contents
    document.querySelectorAll('#inboundTransitContent .sub-tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active from all transit sub-tab buttons
    document.querySelectorAll('#inboundTransitContent .sub-tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected view
    if (view === 'byPallet') {
        document.getElementById('transitByPalletView').classList.add('active');
        document.querySelector('#inboundTransitContent .sub-tab-button:first-child').classList.add('active');
    } else {
        document.getElementById('transitByTruckloadView').classList.add('active');
        document.querySelector('#inboundTransitContent .sub-tab-button:last-child').classList.add('active');
    }
}

function showHistorySubView(view) {
    // Hide all history sub-tab contents
    document.querySelectorAll('#truckloadHistoryContent .sub-tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active from all history sub-tab buttons
    document.querySelectorAll('#truckloadHistoryContent .sub-tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected view
    if (view === 'inbound') {
        document.getElementById('inboundHistoryView').classList.add('active');
        document.querySelector('#truckloadHistoryContent .sub-tab-button:first-child').classList.add('active');
    } else {
        document.getElementById('outboundHistoryView').classList.add('active');
        document.querySelector('#truckloadHistoryContent .sub-tab-button:last-child').classList.add('active');
    }
}

// ========== CHECKBOX MANAGEMENT ==========
function toggleAllCheckboxesInTable(tableId, isChecked) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const checkboxes = table.querySelectorAll('tbody input[type="checkbox"]');
    checkboxes.forEach(cb => { cb.checked = isChecked; });
}

function updateMoveButtonState() {
    const moveBtn = document.getElementById('movePalletsBtn');
    if (!moveBtn) return;
    const table = document.getElementById('storedTable');
    if (!table) return;
    const checked = table.querySelectorAll('.pallet-checkbox-stored:checked').length;
    moveBtn.disabled = (checked === 0);
    if (checked === 0) closeMoveModal();
}

function updateReceiveButtonState() {
    const receiveBtn = document.getElementById('receivePalletsBtn');
    if (!receiveBtn) return;
    const table = document.getElementById('transitTable');
    if (!table) return;
    const checked = table.querySelectorAll('.transit-checkbox:checked').length;
    receiveBtn.disabled = (checked === 0);
    if (checked === 0) closeReceiveModal();
}

function updateReceiveTruckloadButton() {
    const receiveBtn = document.getElementById('receiveTruckloadBtn');
    if (!receiveBtn) return;
    const selected = document.querySelector('input[name="selected_truckload"]:checked');
    receiveBtn.disabled = !selected;
    if (!selected) closeReceiveTruckloadModal();
}

// ========== FILTER FUNCTIONS ==========
function filterStoredTable() {
    const textFilter = document.getElementById('storedSearch').value.toLowerCase();
    const wattageFilter = document.getElementById('storedWattageFilter').value;
    const rows = document.querySelectorAll('#storedTable tbody tr');

    rows.forEach(row => {
        let show = true;
        let rowText = '';
        for (let i = 1; i < row.cells.length; i++) {
            rowText += row.cells[i].textContent.toLowerCase() + ' ';
        }
        if (textFilter && !rowText.includes(textFilter)) show = false;
        let wattageText = row.cells[3]?.textContent.replace('W','').trim() || '';
        if (wattageFilter && wattageText !== wattageFilter) show = false;

        row.style.display = show ? '' : 'none';
    });
}

function filterTransitTable() {
    const textFilter = document.getElementById('transitSearch').value.toLowerCase();
    const wattageFilter = document.getElementById('transitWattageFilter').value;
    const rows = document.querySelectorAll('#transitTable tbody tr');

    rows.forEach(row => {
        let show = true;
        let rowText = '';
        for (let i = 1; i < row.cells.length; i++) {
            rowText += row.cells[i].textContent.toLowerCase() + ' ';
        }
        if (textFilter && !rowText.includes(textFilter)) show = false;
        let wattageText = row.cells[3]?.textContent.replace('W','').trim() || '';
        if (wattageFilter && wattageText !== wattageFilter) show = false;

        row.style.display = show ? '' : 'none';
    });
}

// ========== MODAL MANAGEMENT ==========
const moveModal = document.getElementById('moveModal');
const receiveModal = document.getElementById('receiveModal');
const receiveTruckloadModal = document.getElementById('receiveTruckloadModal');

const moveFormContainer = document.getElementById('movePalletFormContainer');
const receiveFormContainer = document.getElementById('receiveFormContainer');
const receiveTruckloadFormContainer = document.getElementById('receiveTruckloadFormContainer');

const moveOriginalParent = moveFormContainer?.parentNode;
const receiveOriginalParent = receiveFormContainer?.parentNode;
const receiveTruckloadOriginalParent = receiveTruckloadFormContainer?.parentNode;

function openMoveModal() {
    const moveBtn = document.getElementById('movePalletsBtn');
    if (moveBtn?.disabled) return;
    
    const modalContent = moveModal.querySelector('.modal-content');
    const header = modalContent.querySelector('.modal-header');
    if (header && moveFormContainer) {
        modalContent.insertBefore(moveFormContainer, header.nextSibling);
    }
    moveFormContainer.style.display = 'block';
    moveModal.style.display = 'block';
    toggleDestinationSelect();
}

function closeMoveModal() {
    if (moveModal.style.display === 'block') {
        moveOriginalParent?.appendChild(moveFormContainer);
        moveFormContainer.style.display = 'none';
        moveModal.style.display = 'none';
    }
}

function openReceiveModal() {
    const btn = document.getElementById('receivePalletsBtn');
    if (btn?.disabled) return;
    
    // Auto-populate BOL and date
    const selectedCheckboxes = document.querySelectorAll('.transit-checkbox:checked');
    const bolNumbers = new Set();
    
    selectedCheckboxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        const bolCell = row.cells[5];
        const bolText = bolCell.textContent.trim();
        if (bolText && bolText !== 'N/A') {
            bolNumbers.add(bolText);
        }
    });
    
    const bolField = document.getElementById('receive_bol');
    if (bolNumbers.size > 0) {
        bolField.value = Array.from(bolNumbers)[0];
    } else {
        bolField.value = '';
    }
    
    const arrivalDateField = document.getElementById('actual_arrival_date');
    const today = new Date();
    const todayString = today.getFullYear() + '-' + 
                       String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                       String(today.getDate()).padStart(2, '0');
    arrivalDateField.value = todayString;
    
    const modalContent = receiveModal.querySelector('.modal-content');
    const header = modalContent.querySelector('.modal-header');
    if (header && receiveFormContainer) {
        modalContent.insertBefore(receiveFormContainer, header.nextSibling);
    }
    receiveFormContainer.style.display = 'block';
    receiveModal.style.display = 'block';
}

function closeReceiveModal() {
    if (receiveModal.style.display === 'block') {
        receiveOriginalParent?.appendChild(receiveFormContainer);
        receiveFormContainer.style.display = 'none';
        receiveModal.style.display = 'none';
    }
}

function openReceiveTruckloadModal() {
    const btn = document.getElementById('receiveTruckloadBtn');
    if (btn?.disabled) return;
    
    const selected = document.querySelector('input[name="selected_truckload"]:checked');
    if (!selected) return;
    
    // Get BOL from selected truckload row
    const row = selected.closest('tr');
    const bolText = row.cells[1].textContent.trim(); // BOL is in second column
    
    const bolField = document.getElementById('receive_truckload_bol');
    bolField.value = bolText !== 'N/A' ? bolText : '';
    
    // Set today's date
    const arrivalDateField = document.getElementById('actual_truckload_arrival_date');
    const today = new Date();
    const todayString = today.getFullYear() + '-' + 
                       String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                       String(today.getDate()).padStart(2, '0');
    arrivalDateField.value = todayString;
    
    const modalContent = receiveTruckloadModal.querySelector('.modal-content');
    const header = modalContent.querySelector('.modal-header');
    if (header && receiveTruckloadFormContainer) {
        modalContent.insertBefore(receiveTruckloadFormContainer, header.nextSibling);
    }
    receiveTruckloadFormContainer.style.display = 'block';
    receiveTruckloadModal.style.display = 'block';
}

function closeReceiveTruckloadModal() {
    if (receiveTruckloadModal.style.display === 'block') {
        receiveTruckloadOriginalParent?.appendChild(receiveTruckloadFormContainer);
        receiveTruckloadFormContainer.style.display = 'none';
        receiveTruckloadModal.style.display = 'none';
    }
}

// ========== DESTINATION TOGGLE ==========
function toggleDestinationSelect() {
    const destType = document.querySelector('input[name="destination_type"]:checked')?.value;
    const lbl = document.getElementById('destinationLabel');
    const sel = document.getElementById('destination_id');
    
    if (!destType || !lbl || !sel) return;
    
    sel.innerHTML = '';
    if (destType === 'project') {
        lbl.textContent = 'Project:';
        if (allProjectsData.length === 0) {
            sel.innerHTML = '<option value="">No projects found</option>';
            sel.disabled = true;
        } else {
            sel.disabled = false;
            sel.innerHTML = '<option value="">-- Select Project --</option>';
            allProjectsData.forEach(proj => {
                const opt = document.createElement('option');
                opt.value = proj.id;
                opt.textContent = proj.project_name;
                sel.appendChild(opt);
            });
        }
    } else {
        lbl.textContent = 'Destination Warehouse:';
        if (otherWarehousesData.length === 0) {
            sel.innerHTML = '<option value="">No other warehouses found</option>';
            sel.disabled = true;
        } else {
            sel.disabled = false;
            sel.innerHTML = '<option value="">-- Select Warehouse --</option>';
            otherWarehousesData.forEach(wh => {
                const opt = document.createElement('option');
                opt.value = wh.id;
                opt.textContent = wh.name;
                sel.appendChild(opt);
            });
        }
    }
}

// ========== UTILITY FUNCTIONS ==========
function setHidden(form, fieldName, val) {
    let el = form.querySelector(`[name="${fieldName}"]`);
    if (!el) {
        el = document.createElement('input');
        el.type = 'hidden';
        el.name = fieldName;
        form.appendChild(el);
    }
    el.value = val;
}

function moveTruckload(deliveryId) {
    // TODO: Implement truckload move functionality
    alert('Move truckload functionality - delivery ID: ' + deliveryId);
}

function uploadPOD(deliveryId) {
    // Navigate to the existing upload_pod.php page
    window.location.href = 'upload_pod.php?delivery_id=' + deliveryId;
}

// ========== EVENT LISTENERS ==========
document.addEventListener('DOMContentLoaded', () => {
    // Main tab clicks
    document.getElementById('storedInventoryTab')?.addEventListener('click', () => showMainTab('storedInventory'));
    document.getElementById('inboundTransitTab')?.addEventListener('click', () => showMainTab('inboundTransit'));
    document.getElementById('truckloadHistoryTab')?.addEventListener('click', () => showMainTab('truckloadHistory'));

    // Checkbox event listeners
    document.getElementById('select-all-stored')?.addEventListener('change', (e) => {
        toggleAllCheckboxesInTable('storedTable', e.target.checked);
        updateMoveButtonState();
    });
    
    document.getElementById('select-all-transit')?.addEventListener('change', (e) => {
        toggleAllCheckboxesInTable('transitTable', e.target.checked);
        updateReceiveButtonState();
    });

    // Individual checkboxes
    document.querySelectorAll('.pallet-checkbox-stored').forEach(cb => {
        cb.addEventListener('change', updateMoveButtonState);
    });
    
    document.querySelectorAll('.transit-checkbox').forEach(cb => {
        cb.addEventListener('change', updateReceiveButtonState);
    });

    // Filter event listeners
    document.getElementById('storedSearch')?.addEventListener('keyup', filterStoredTable);
    document.getElementById('storedWattageFilter')?.addEventListener('change', filterStoredTable);
    document.getElementById('transitSearch')?.addEventListener('keyup', filterTransitTable);
    document.getElementById('transitWattageFilter')?.addEventListener('change', filterTransitTable);

    // Button event listeners
    document.getElementById('movePalletsBtn')?.addEventListener('click', openMoveModal);
    document.getElementById('receivePalletsBtn')?.addEventListener('click', openReceiveModal);
    document.getElementById('receiveTruckloadBtn')?.addEventListener('click', openReceiveTruckloadModal);

    // Modal close event listeners
    document.querySelector('#moveModal .close-modal')?.addEventListener('click', closeMoveModal);
    document.querySelector('#receiveModal .close-receive-modal')?.addEventListener('click', closeReceiveModal);
    document.querySelector('#receiveTruckloadModal .close-receive-truckload-modal')?.addEventListener('click', closeReceiveTruckloadModal);

    // Click outside modal to close
    window.addEventListener('click', (e) => {
        if (e.target === moveModal) closeMoveModal();
        if (e.target === receiveModal) closeReceiveModal();
        if (e.target === receiveTruckloadModal) closeReceiveTruckloadModal();
    });

    // Form submission handlers
    document.getElementById('submitMoveBtn')?.addEventListener('click', () => {
        const mainForm = document.getElementById('palletInventoryForm');
        if (!mainForm) return;

        const destType = document.querySelector('input[name="destination_type"]:checked')?.value;
        const destId = document.getElementById('destination_id').value;

        if (!destId) {
            alert('Please select a destination.');
            return;
        }

        setHidden(mainForm, 'destination_type', destType);
        setHidden(mainForm, 'destination_id', destId);
        setHidden(mainForm, 'bol_number', document.getElementById('bol_number').value);
        setHidden(mainForm, 'departure_date', document.getElementById('departure_date').value);
        setHidden(mainForm, 'est_arrival_date', document.getElementById('est_arrival_date').value);

        mainForm.submit();
    });

    document.getElementById('confirmReceiveBtn')?.addEventListener('click', () => {
        const mainForm = document.getElementById('receivePalletsForm');
        if (!mainForm) return;

        setHidden(mainForm, 'receive_bol_hidden', document.getElementById('receive_bol').value);
        setHidden(mainForm, 'actual_arrival_date_hidden', document.getElementById('actual_arrival_date').value);

        mainForm.submit();
    });

    document.getElementById('confirmReceiveTruckloadBtn')?.addEventListener('click', () => {
        const selected = document.querySelector('input[name="selected_truckload"]:checked');
        if (!selected) {
            alert('Please select a truckload to receive.');
            return;
        }

        // Create form to handle truckload receiving with file upload
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'handle_pallet_arrival.php'; // Updated to use the unified handler
        form.enctype = 'multipart/form-data'; // Required for file upload

        const warehouseInput = document.createElement('input');
        warehouseInput.type = 'hidden';
        warehouseInput.name = 'warehouse_id';
        warehouseInput.value = '<?php echo $warehouse_id; ?>';
        form.appendChild(warehouseInput);

        const deliveryInput = document.createElement('input');
        deliveryInput.type = 'hidden';
        deliveryInput.name = 'delivery_id';
        deliveryInput.value = selected.value;
        form.appendChild(deliveryInput);

        const bolInput = document.createElement('input');
        bolInput.type = 'hidden';
        bolInput.name = 'bol_number';
        bolInput.value = document.getElementById('receive_truckload_bol').value;
        form.appendChild(bolInput);

        const dateInput = document.createElement('input');
        dateInput.type = 'hidden';
        dateInput.name = 'actual_arrival_date';
        dateInput.value = document.getElementById('actual_truckload_arrival_date').value;
        form.appendChild(dateInput);

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'receive_truckload';
        form.appendChild(actionInput);

        // Handle file upload if a file is selected
        const fileInput = document.getElementById('pod_file');
        if (fileInput && fileInput.files.length > 0) {
            // Clone the file input to preserve the file
            const clonedFileInput = fileInput.cloneNode(true);
            form.appendChild(clonedFileInput);
        }

        document.body.appendChild(form);
        form.submit();
    });

    // Destination type change listeners
    document.querySelectorAll('input[name="destination_type"]').forEach(radio => {
        radio.addEventListener('change', toggleDestinationSelect);
    });

    // Initial states
    updateMoveButtonState();
    updateReceiveButtonState();
    updateReceiveTruckloadButton();
    
    // Initialize default destination dropdown
    toggleDestinationSelect();
});
</script>
</body>
</html>
