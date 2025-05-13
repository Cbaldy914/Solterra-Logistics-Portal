<?php
session_name("logistics_session");
session_start();

// Check if user is logged in (adjust roles as needed for warehouse view)
if (!isset($_SESSION['user_id'])) { 
    header("Location: login"); 
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please try again later.");
}

// Get parameters
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : null;
$project_id   = isset($_GET['project_id'])   ? intval($_GET['project_id'])   : null;

// Initialize variables
$warehouse_data         = null;
$project_name_for_title = null;
$show_warehouse_list    = false;
$relevant_warehouses    = []; 
$errorMessage           = '';
$page_title             = "Warehouse Information"; 

// --- Dispatcher Logic ---

try {
    if ($warehouse_id) {
        // --- Scenario 1: Specific Warehouse ID provided ---
        $stmtW = $conn->prepare("SELECT * FROM warehouses WHERE id = ?");
        if (!$stmtW) throw new Exception("Prepare warehouse failed: " . $conn->error);
        $stmtW->bind_param("i", $warehouse_id);
        $stmtW->execute();
        $resultW = $stmtW->get_result();
        if ($resultW->num_rows === 0) {
            throw new Exception("Warehouse with ID {$warehouse_id} not found.");
        }
        $warehouse_data = $resultW->fetch_assoc();
        $page_title = htmlspecialchars($warehouse_data['name']);
        $stmtW->close();

        if ($project_id) {
            $stmtPName = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
            if ($stmtPName) {
                $stmtPName->bind_param("i", $project_id);
                $stmtPName->execute();
                $stmtPName->bind_result($pName);
                if ($stmtPName->fetch()) {
                    $project_name_for_title = $pName;
                    $page_title .= " - Inventory for Project: " . htmlspecialchars($project_name_for_title);
                }
                $stmtPName->close();
            }
        }

    } elseif ($project_id) {
        // --- Scenario 2: Only Project ID provided ---
        $stmtPName = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
        if (!$stmtPName) throw new Exception("Prepare project name failed: " . $conn->error);
        $stmtPName->bind_param("i", $project_id);
        $stmtPName->execute();
        $stmtPName->bind_result($pName);
        if (!$stmtPName->fetch()) {
            $stmtPName->close();
            throw new Exception("Project with ID {$project_id} not found.");
        }
        $project_name_for_title = $pName;
        $stmtPName->close();

        $sqlDistinctWH = "
            SELECT DISTINCT wh.id, wh.name
            FROM warehouses wh
            WHERE EXISTS (
                SELECT 1 FROM inventory_pallets ip 
                WHERE ip.assigned_project_id = ? AND ip.current_warehouse_id = wh.id AND ip.status = 'In Warehouse'
            ) 
            OR EXISTS (
                SELECT 1 FROM deliveries d
                JOIN delivery_pallets dp ON d.id = dp.delivery_id
                JOIN inventory_pallets ip_d ON dp.inventory_pallet_id = ip_d.id
                WHERE ip_d.assigned_project_id = ? 
                  AND d.warehouse_id = wh.id 
                  AND d.status_of_delivery LIKE 'In Transit%'
                  AND d.warehouse_arrival_date IS NULL
            )
            ORDER BY wh.name ASC
        ";
        $stmtDistinctWH = $conn->prepare($sqlDistinctWH);
        if (!$stmtDistinctWH) throw new Exception("Prepare distinct warehouses failed: " . $conn->error);
        $stmtDistinctWH->bind_param("ii", $project_id, $project_id);
        $stmtDistinctWH->execute();
        $resultDistinctWH = $stmtDistinctWH->get_result();

        while ($wh_row = $resultDistinctWH->fetch_assoc()) {
            $relevant_warehouses[] = $wh_row;
        }
        $stmtDistinctWH->close();

        $warehouse_count = count($relevant_warehouses);

        if ($warehouse_count === 0) {
            $errorMessage = "No inventory for Project '" . htmlspecialchars($project_name_for_title) . "' is currently tracked in any warehouse.";
            $page_title = "Inventory for Project: " . htmlspecialchars($project_name_for_title);
        } elseif ($warehouse_count === 1) {
            $single_warehouse_id = $relevant_warehouses[0]['id'];
            header("Location: warehouse_info.php?warehouse_id={$single_warehouse_id}&project_id={$project_id}");
            exit();
        } else {
            $show_warehouse_list = true;
            $page_title = "Inventory Locations for Project: " . htmlspecialchars($project_name_for_title);
        }

    } else {
        $errorMessage = "Please specify a Warehouse ID or a Project ID.";
    }

} catch (Exception $e) {
    $errorMessage = "Error: " . $e->getMessage();
    $warehouse_data = null; 
}

// --- Proceed only if we are displaying a single warehouse view --- 
$inventory_pallets = [];
$delivered_deliveries = []; 
$left_warehouse_deliveries = []; 
$arrived_date_values = []; 
$left_warehouse_date_values = []; 
$total_cost_to_date = 0;
$in_fee_cost = 0;
$out_fee_cost = 0;
$monthly_storage_cost = 0; 
$total_modules = 0; 
$total_pallets_count = 0; // Added for clarity in cost summary

if (!$show_warehouse_list && empty($errorMessage) && $warehouse_data) {
    // This ensures $warehouse_id is set if $warehouse_data is available
    $warehouse_id = $warehouse_data['id']; 

    try {
        // Fetch Pallets currently IN this warehouse (for Inventory View)
        $sql_pallets = "
            SELECT ip.id AS pallet_id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.arrival_date, m.vendor_name AS origin_vendor
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            WHERE ip.current_warehouse_id = ? AND ip.status = 'In Warehouse'
        ";
        $pallet_params = [$warehouse_id];
        $pallet_types = "i";

        if ($project_id) {
            $sql_pallets .= " AND ip.assigned_project_id = ?";
            $pallet_params[] = $project_id;
            $pallet_types .= "i";
        }
        $sql_pallets .= " ORDER BY ip.arrival_date DESC, ip.id DESC";

        $stmt_pallets = $conn->prepare($sql_pallets);
        if (!$stmt_pallets) throw new Exception("Prepare stored pallets failed: ".$conn->error);
        $stmt_pallets->bind_param($pallet_types, ...$pallet_params);
        $stmt_pallets->execute();
        $result_pallets = $stmt_pallets->get_result();
        while ($row = $result_pallets->fetch_assoc()) {
            $inventory_pallets[] = $row;
            $total_pallets_count++;
            $total_modules += $row['quantity'];
        }
        $stmt_pallets->close();

        // Fetch Deliveries Arrived (for Inbound Truckloads Table)
        $arrived_delivery_ids = [];
        $inbound_deliveries_for_table = []; // Renamed from $delivered_deliveries
        $sql_deliveries_arrived = "
            SELECT 
                d.id, d.supplier, d.wattage, d.quantity, d.bol_number, 
                d.warehouse_arrival_date, d.proof_of_delivery /* Removed d.left_warehouse_date */
            FROM deliveries d
            WHERE d.warehouse_id = ? AND d.warehouse_arrival_date IS NOT NULL
        ";
        $delivered_params = [$warehouse_id];
        $delivered_types = "i";
        if ($project_id) {
             $sql_deliveries_arrived .= " AND d.project_id = ?";
             $delivered_params[] = $project_id;
             $delivered_types .= "i";
        }
         $sql_deliveries_arrived .= " ORDER BY d.warehouse_arrival_date DESC";
        
        $stmt_delivered = $conn->prepare($sql_deliveries_arrived);
        if (!$stmt_delivered) throw new Exception("Prepare arrived deliveries failed: ".$conn->error);
        $stmt_delivered->bind_param($delivered_types, ...$delivered_params);
        $stmt_delivered->execute();
        $result_delivered = $stmt_delivered->get_result();
        while ($drow = $result_delivered->fetch_assoc()) {
            $inbound_deliveries_for_table[] = $drow; // Populate new array
            $arrived_delivery_ids[] = $drow['id']; 
            $arrived_date_values[] = $drow['warehouse_arrival_date'] ?? '';
        }
        $stmt_delivered->close();

        // Count actual pallets for INBOUND deliveries
        $total_inbound_pallets_count = 0;
        if (!empty($arrived_delivery_ids)) {
            $placeholders = implode(',', array_fill(0, count($arrived_delivery_ids), '?'));
            $stmt_count_in_pallets = $conn->prepare("SELECT COUNT(DISTINCT inventory_pallet_id) FROM delivery_pallets WHERE delivery_id IN ({$placeholders})");
            if ($stmt_count_in_pallets) {
                $types_in_pallets = str_repeat('i', count($arrived_delivery_ids));
                $stmt_count_in_pallets->bind_param($types_in_pallets, ...$arrived_delivery_ids);
                $stmt_count_in_pallets->execute();
                $stmt_count_in_pallets->bind_result($count_in);
                if ($stmt_count_in_pallets->fetch()) {
                    $total_inbound_pallets_count = $count_in;
                }
                $stmt_count_in_pallets->close();
            }
        }

        $arrived_date_values = array_unique(array_filter($arrived_date_values));
        sort($arrived_date_values);
        
        // Fetch Deliveries Departed (for Outbound Truckloads Table)
        $departed_delivery_ids = [];
        $outbound_deliveries_for_table = []; // New array for direct use in outbound table
        $sql_deliveries_left = "
            SELECT 
                d.id, d.supplier, d.wattage, d.quantity, d.bol_number, 
                d.left_warehouse_date, d.proof_of_delivery /* Removed d.warehouse_arrival_date */
            FROM deliveries d
            WHERE d.warehouse_id = ? AND d.left_warehouse_date IS NOT NULL 
        "; 
        $left_params = [$warehouse_id];
        $left_types = "i";
        if ($project_id) {
             $sql_deliveries_left .= " AND d.project_id = ?";
             $left_params[] = $project_id;
             $left_types .= "i";
        }
        $sql_deliveries_left .= " ORDER BY d.left_warehouse_date DESC";
        
        $stmt_left = $conn->prepare($sql_deliveries_left);
        if (!$stmt_left) throw new Exception("Prepare left deliveries failed: ".$conn->error);
        $stmt_left->bind_param($left_types, ...$left_params);
        $stmt_left->execute();
        $result_left = $stmt_left->get_result();
        while ($drow = $result_left->fetch_assoc()) {
            $outbound_deliveries_for_table[] = $drow; // Populate new array directly
            $departed_delivery_ids[] = $drow['id']; 
            $left_warehouse_date_values[] = $drow['left_warehouse_date'] ?? '';
        }
        $stmt_left->close();

        // Count actual pallets for OUTBOUND deliveries
        $total_outbound_pallets_count = 0;
        if (!empty($departed_delivery_ids)) {
            $placeholders_out = implode(',', array_fill(0, count($departed_delivery_ids), '?'));
            $stmt_count_out_pallets = $conn->prepare("SELECT COUNT(DISTINCT inventory_pallet_id) FROM delivery_pallets WHERE delivery_id IN ({$placeholders_out})");
            if ($stmt_count_out_pallets) {
                $types_out_pallets = str_repeat('i', count($departed_delivery_ids));
                $stmt_count_out_pallets->bind_param($types_out_pallets, ...$departed_delivery_ids);
                $stmt_count_out_pallets->execute();
                $stmt_count_out_pallets->bind_result($count_out);
                if ($stmt_count_out_pallets->fetch()) {
                    $total_outbound_pallets_count = $count_out;
                }
                $stmt_count_out_pallets->close();
            }
        }

        $left_warehouse_date_values = array_unique(array_filter($left_warehouse_date_values));
        sort($left_warehouse_date_values);
        
        // Calculate Costs (Based on this specific warehouse's fees)
        $in_fee_cost  = ($warehouse_data['in_fee'] ?? 0)  * $total_inbound_pallets_count;
        $out_fee_cost = ($warehouse_data['out_fee'] ?? 0) * $total_outbound_pallets_count;
        
        // Monthly Storage Cost Estimate (based on current pallet count)
        $monthly_storage_cost = $total_pallets_count * ($warehouse_data['monthly_storage_fee'] ?? 0);
        
        $storage_cost_to_date = 0; // Removed calculation, set to 0
        
        $total_cost_to_date = $in_fee_cost + $out_fee_cost; // Updated total cost

    } catch (Exception $e) {
        $errorMessage = "Error fetching inventory data: " . $e->getMessage();
    }

} // End if (!$show_warehouse_list && empty($errorMessage) && $warehouse_data)

// Final connection close
if ($conn) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title> <!-- Use dynamic page title -->
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .back-icon {
            text-decoration: none;
            color: #333;
            display: inline-flex;
            align-items: center;
            margin-bottom: 20px;
        }
        .back-icon svg {
            width: 24px;
            height: 24px;
            margin-right: 5px;
        }
        .warehouse-info-container { 
            display: flex;
            align-items: flex-start; 
            flex-wrap: wrap;
            margin-bottom: 20px;
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #e0e0e0;
        }
        .warehouse-image img {
            display: block;
            border-radius: 4px;
            margin-right: 20px; 
        }
        .warehouse-details {
            flex: 1; 
            min-width: 300px; 
        }
        .warehouse-details h1 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1.6em;
            color: #293E4C;
        }
         .warehouse-details p {
             margin: 5px 0;
             line-height: 1.5;
         }
        .tabs-container {
            text-align: center; /* Center the tabs */
            width: 100%;
            margin-bottom: 20px; /* Space below tabs */
            border-bottom: 1px solid #ccc; /* Separator line */
        }
        .tabs {
            display: inline-flex; /* Make buttons sit side-by-side */
            gap: 1px; /* Small gap between buttons */
        }
        .tabs button {
            background: #e9ecef; /* Light grey background */
            color: #333; /* Dark text */
            padding: 10px 15px;
            cursor: pointer;
            font-weight: 600;
            border: none;
            border-top-left-radius: 5px;
            border-top-right-radius: 5px;
            font-size: 1em;
            border: 1px solid #ccc; /* Add border */
            border-bottom: none; /* Remove bottom border */
            margin-bottom: -1px; /* Overlap the container's bottom border */
        }
        .tabs button.active {
            background: #fff; /* White background for active tab */
            color: #293E4C; /* Darker color for active text */
            border-bottom: 1px solid #fff; /* Make bottom border white to blend */
        }
        .tab-content { /* Combined style for both sections */
            display: none; /* Hide sections by default */
            margin-top: 10px; /* Space above section content */
        }
        .tab-content.active { /* Style for the active section */
            display: block; /* Show active section */
        }
         .table-responsive {
             width: 100%;
             overflow-x: auto;
             margin-bottom: 10px;
         }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
             padding: 8px 10px;
             border: 1px solid #ddd;
             text-align: left;
             white-space: nowrap;
        }
         th {
             background-color: #e9ecef;
             font-weight: 600;
         }
         tr:nth-child(even) {
             background-color: #f8f9fa;
         }
        .filter-controls {
             display: flex;
             flex-wrap: wrap;
             gap: 15px;
             margin-bottom: 15px;
             align-items: center;
         }
         .filter-controls label {
             font-weight: 500;
             margin-right: 5px;
         }
         .filter-controls input[type="text"],
         .filter-controls select {
             padding: 6px;
             border: 1px solid #ccc;
             border-radius: 4px;
             height: 34px; /* Align height */
         }
        .message { 
             padding: 15px;
             margin: 20px 0;
             border: 1px solid transparent;
             border-radius: 4px;
             text-align: center;
        }
        .error-message {
             color: #721c24;
             background-color: #f8d7da;
             border-color: #f5c6cb;
        }
        .info-message { 
             color: #0c5460;
             background-color: #d1ecf1;
             border-color: #bee5eb;
        }
         .warehouse-list {
             list-style: none;
             padding: 0;
             margin-top: 15px;
         }
         .warehouse-list li {
             background-color: #f8f9fa;
             margin-bottom: 10px;
             padding: 10px 15px;
             border: 1px solid #dee2e6;
             border-radius: 4px;
         }
         .warehouse-list li a {
             text-decoration: none;
             color: #007bff;
             font-weight: 500;
         }
         .warehouse-list li a:hover {
             text-decoration: underline;
         }
         .cost-summary {
            margin-bottom: 20px;
            width: 100%;
            border-collapse: collapse;
        }
        .cost-summary th,
        .cost-summary td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: center;
            white-space: nowrap;
        }
        .sub-tabs-container {
            /* Add any container styling if needed */
            /* Example: margin-bottom: 10px; */
        }
        .sub-tab-button {
            padding: 8px 12px;
            cursor: pointer;
            background-color: #f0f0f0;
            border: 1px solid #ccc;
            /* border-bottom: none; */ /* Keep bottom border for unselected or adjust as preferred */
            margin-right: 5px;
            border-top-left-radius: 4px;
            border-top-right-radius: 4px;
            font-size: 0.9em; /* Slightly smaller font for sub-tabs */
        }
        .sub-tab-button.active {
            background-color: #fff;
            border-bottom: 1px solid #fff; /* To make it look like it merges with content */
            font-weight: bold;
            color: #293E4C; /* Match active main tab text color */
        }

        @media (max-width: 768px) {
             .filter-controls {
                 flex-direction: column;
                 align-items: stretch;
             }
             .filter-controls label {
                  margin-right: 0;
                  margin-bottom: 5px;
             }
             .filter-controls input[type="text"],
             .filter-controls select {
                  width: 100%;
             }
             .cost-summary th, .cost-summary td {
                font-size: 14px;
                white-space: normal;
            }
        }
         @media (max-width: 600px) {
             .warehouse-info-container {
                 flex-direction: column; 
                 align-items: center; 
             }
             .warehouse-image img {
                 margin-right: 0;
                 margin-bottom: 15px; 
                 max-width: 150px; /* Smaller image on mobile */
             }
             .warehouse-details {
                  margin-left: 0;
                  text-align: center; 
             }
         }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
     <?php
     // Determine back link based on context
     $back_link = "manage_warehouses.php"; // Default back link
     if ($project_id && !$warehouse_id) { // If only project_id is given and we show warehouse list OR error for project
         $back_link = "project_overview.php?id=" . $project_id;
     } elseif ($warehouse_id && $project_id) { // If warehouse and project are given (specific view)
         $back_link = "warehouse_info.php?project_id=" . $project_id; // Link back to project's warehouse list
     } elseif ($warehouse_id) { // If only warehouse_id is given
         $back_link = "manage_warehouses.php";
     }
     ?>
     <a href="<?php echo $back_link; ?>" class="back-icon">
         <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="fill: currentColor; width: 24px; height: 24px;">
             <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
         </svg>
         Back
     </a>

    <h1><?php echo $page_title; ?></h1>

    <?php if (!empty($errorMessage)): ?>
        <p class="message error-message"><?php echo htmlspecialchars($errorMessage); ?></p>

    <?php elseif ($show_warehouse_list): ?>
        <p>Inventory for Project '<?php echo htmlspecialchars($project_name_for_title); ?>' is located in the following warehouses:</p>
        <ul class="warehouse-list">
            <?php foreach ($relevant_warehouses as $wh): ?>
                <li>
                    <a href="warehouse_info.php?warehouse_id=<?php echo $wh['id']; ?>&project_id=<?php echo $project_id; ?>">
                        <?php echo htmlspecialchars($wh['name']); ?> (ID: <?php echo $wh['id']; ?>)
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php elseif ($warehouse_data): ?>
        <!-- === START Standard Warehouse View === -->

        <div class="warehouse-info-container">
            <div class="warehouse-image">
                <?php 
                $image_path = "pictures/warehouse-default.png"; // Default image
                if (!empty($warehouse_data['image_url'])) {
                    // Check if the image_url is a full URL or a relative path
                    if (filter_var($warehouse_data['image_url'], FILTER_VALIDATE_URL)) {
                        $image_path = $warehouse_data['image_url'];
                    } else {
                        // Assuming it's a relative path from the webroot, adjust if structure is different
                        // If image_url already includes a base path like 'uploads/', that's fine.
                        $image_path = htmlspecialchars($warehouse_data['image_url']); 
                    }
                }
                ?>
                <img src="<?php echo $image_path; ?>" alt="<?php echo htmlspecialchars($warehouse_data['name']); ?> Warehouse">
            </div>
            <div class="warehouse-details">
                <h1><?php echo htmlspecialchars($warehouse_data['name']); ?></h1>
                 <?php if ($project_name_for_title): ?>
                    <h2 style="font-size: 1.1em; color: #555; margin-top: 5px;">Viewing Inventory for Project: <?php echo htmlspecialchars($project_name_for_title); ?></h2>
                 <?php endif; ?>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($warehouse_data['address']); ?></p>
                <p><strong>In Fee:</strong> $<?php echo number_format($warehouse_data['in_fee'] ?? 0, 2); ?></p>
                <p><strong>Out Fee:</strong> $<?php echo number_format($warehouse_data['out_fee'] ?? 0, 2); ?></p>
                <p><strong>Monthly Storage Fee (per Pallet):</strong> $<?php echo number_format($warehouse_data['monthly_storage_fee'] ?? 0, 2); ?></p>
                <?php if (($_SESSION['role'] ?? '') === 'global_admin'): // Only allow edit for global admin ?>
                    <p style="margin-top:10px;"><a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="action-button">Edit Warehouse Info</a></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Cost Summary -->
        <div class="table-responsive">
             <table class="cost-summary">
                 <thead>
                     <tr>
                         <th>Total Pallets Stored</th>
                         <th>Total Modules Stored</th>
                         <th>Est. Monthly Storage Cost</th>
                         <th>In Fee Cost (Total)</th>
                         <th>Out Fee Cost (Total)</th>
                         <th>Total Cost To Date</th>
                     </tr>
                 </thead>
                 <tbody>
                     <tr>
                         <td><?php echo number_format($total_pallets_count ?? 0); ?></td>
                         <td><?php echo number_format($total_modules ?? 0); ?></td>
                         <td>$<?php echo number_format($monthly_storage_cost, 2); ?></td>
                         <td>$<?php echo number_format($in_fee_cost, 2); ?></td>
                         <td>$<?php echo number_format($out_fee_cost, 2); ?></td>
                         <td>$<?php echo number_format($total_cost_to_date, 2); ?></td>
                     </tr>
                 </tbody>
             </table>
         </div>

        <!-- Tabs -->
        <div class="tabs-container">
            <div class="tabs">
                <button class="tab-link active" onclick="openTab(event, 'InventoryView')">Inventory View (<?php echo count($inventory_pallets); ?>)</button>
                <button class="tab-link" onclick="openTab(event, 'TruckloadView')">Truckload History (<?php 
                    $all_truckload_ids = [];
                    if (!empty($inbound_deliveries_for_table)) {
                        foreach($inbound_deliveries_for_table as $d) { $all_truckload_ids[] = $d['id']; }
                    }
                    if (!empty($outbound_deliveries_for_table)) {
                        foreach($outbound_deliveries_for_table as $d) { $all_truckload_ids[] = $d['id']; }
                    }
                    echo count(array_unique($all_truckload_ids)); 
                ?>)</button>
            </div>
        </div>

        <!-- Tab Content: Inventory View (DEFAULT ACTIVE) -->
        <div id="InventoryView" class="tab-content active">
             <h2>Stored Inventory Details</h2>
              <div class="filter-controls">
                  <label for="inventorySearch">Search:</label>
                  <input type="text" id="inventorySearch" placeholder="Filter by Identifier, Vendor...">
                  <label for="inventoryWattageFilter">Wattage:</label>
                  <select id="inventoryWattageFilter">
                       <option value="">All Wattages</option>
                       <?php
                       $unique_wattages_inv = [];
                       if (!empty($inventory_pallets)) {
                           foreach($inventory_pallets as $p) { $unique_wattages_inv[$p['wattage']] = true; }
                           ksort($unique_wattages_inv);
                           foreach (array_keys($unique_wattages_inv) as $wattage):
                       ?>
                          <option value="<?php echo htmlspecialchars($wattage); ?>"><?php echo htmlspecialchars($wattage); ?>W</option>
                       <?php 
                           endforeach; 
                       }
                       ?>
                  </select>
             </div>
             <div class="table-responsive">
                 <table id="inventoryTable">
                     <thead>
                         <tr>
                             <th>Pallet Identifier</th>
                             <th>Origin Vendor</th>
                             <th>Wattage</th>
                             <th>Quantity</th>
                             <th>Arrival Date</th>
                             <th>Actions</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php if (!empty($inventory_pallets)): ?>
                             <?php foreach ($inventory_pallets as $pallet): ?>
                                 <tr>
                                     <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                     <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                                     <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                     <td><?php echo number_format($pallet['quantity']); ?></td>
                                     <td><?php echo htmlspecialchars($pallet['arrival_date'] ?? 'N/A'); ?></td>
                                     <td>
                                          <a href="pallet_details.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" class="action-button" target="_blank" style="padding: 3px 8px; font-size: 0.9em;">View Details</a>
                                     </td>
                                 </tr>
                             <?php endforeach; ?>
                         <?php else: ?>
                             <tr><td colspan="6">No inventory currently stored<?php echo $project_id ? ' for this project' : ''; ?> in this warehouse.</td></tr>
                         <?php endif; ?>
                     </tbody>
                 </table>
             </div>
        </div>
        
        <!-- Tab Content: Truckload View -->
         <div id="TruckloadView" class="tab-content">
            <div class="sub-tabs-container" style="text-align: left; margin-bottom: 15px;">
                <button class="sub-tab-button active" onclick="showTruckloadSubView('arrivals')">View Arrivals</button>
                <button class="sub-tab-button" onclick="showTruckloadSubView('departures')">View Departures</button>
            </div>

            <div id="truckloadArrivalsSubView">
                <h2 style="margin-top:0;">Inbound Truckloads (Arrivals)</h2>
                 <div class="filter-controls">
                     <label for="inboundTruckloadSearch">Search:</label>
                     <input type="text" id="inboundTruckloadSearch" placeholder="Filter by BOL, Supplier...">
                     <label for="inboundArrivedDateFilter">Arrived On:</label>
                     <select id="inboundArrivedDateFilter">
                         <option value="">All Dates</option>
                         <?php foreach ($arrived_date_values as $date): ?>
                             <option value="<?php echo htmlspecialchars($date); ?>"><?php echo htmlspecialchars($date); ?></option>
                         <?php endforeach; ?>
                     </select>
                     <label for="inboundTruckloadWattageFilter">Wattage:</label>
                     <select id="inboundTruckloadWattageFilter">
                         <option value="">All Wattages</option>
                         <?php
                         $unique_wattages_inbound = [];
                         if (!empty($inbound_deliveries_for_table)) {
                             foreach($inbound_deliveries_for_table as $d) { $unique_wattages_inbound[$d['wattage']] = true; }
                             ksort($unique_wattages_inbound);
                             foreach (array_keys($unique_wattages_inbound) as $wattage):
                         ?>
                            <option value="<?php echo htmlspecialchars($wattage); ?>"><?php echo htmlspecialchars($wattage); ?>W</option>
                         <?php 
                             endforeach; 
                         }
                         ?>
                     </select>
                 </div>
                 <div class="table-responsive">
                     <table id="inboundTruckloadsTable">
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
                             <?php if (!empty($inbound_deliveries_for_table)): ?>
                                  <?php foreach ($inbound_deliveries_for_table as $delivery): ?>
                                     <tr>
                                         <td><?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?></td>
                                         <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                         <td><?php echo htmlspecialchars($delivery['wattage'] ?? 'N/A'); ?>W</td>
                                         <td><?php echo number_format($delivery['quantity'] ?? 0); ?></td>
                                         <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? 'N/A'); ?></td>
                                          <td>
                                              <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                                  <a href="view_pod.php?delivery_id=<?php echo $delivery['id']; ?>" target="_blank">View</a>
                                              <?php else: ?>
                                                  N/A
                                              <?php endif; ?>
                                          </td>
                                         <td>
                                             <a href="edit_delivery.php?delivery_id=<?php echo $delivery['id']; ?>&warehouse_id=<?php echo $warehouse_id; ?><?php if($project_id) echo '&project_id='.$project_id; ?>" class="action-button" style="padding: 3px 8px; font-size: 0.9em;">Edit</a>
                                         </td>
                                     </tr>
                                 <?php endforeach; ?>
                             <?php else: ?>
                                 <tr><td colspan="7">No inbound truckloads recorded<?php echo $project_id ? ' for this project' : ''; ?> in this warehouse.</td></tr>
                             <?php endif; ?>
                         </tbody>
                     </table>
                 </div>
            </div>

            <div id="truckloadDeparturesSubView" style="display: none;">
                <h2 style="margin-top:0;">Outbound Truckloads (Departures)</h2>
                <div class="filter-controls">
                    <label for="outboundTruckloadSearch">Search:</label>
                    <input type="text" id="outboundTruckloadSearch" placeholder="Filter by BOL, Supplier...">
                    <label for="outboundLeftDateFilter">Departed On:</label>
                    <select id="outboundLeftDateFilter">
                        <option value="">All Dates</option>
                        <?php foreach ($left_warehouse_date_values as $date): // Assuming $left_warehouse_date_values is populated correctly ?>
                            <option value="<?php echo htmlspecialchars($date); ?>"><?php echo htmlspecialchars($date); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="outboundTruckloadWattageFilter">Wattage:</label>
                    <select id="outboundTruckloadWattageFilter">
                        <option value="">All Wattages</option>
                        <?php
                        $unique_wattages_outbound = [];
                        if (!empty($outbound_deliveries_for_table)) {
                            foreach($outbound_deliveries_for_table as $d) { $unique_wattages_outbound[$d['wattage']] = true; }
                            ksort($unique_wattages_outbound);
                            foreach (array_keys($unique_wattages_outbound) as $wattage):
                        ?>
                           <option value="<?php echo htmlspecialchars($wattage); ?>"><?php echo htmlspecialchars($wattage); ?>W</option>
                        <?php 
                            endforeach; 
                        }
                        ?>
                    </select>
               </div>
              <div class="table-responsive">
                  <table id="outboundTruckloadsTable">
                      <thead>
                          <tr>
                              <th>BOL Number</th>
                              <th>Supplier</th>
                              <th>Wattage</th>
                              <th>Total Modules</th>
                              <th>Departure Date</th>
                              <th>Proof of Delivery</th>
                              <th>Actions</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php if (!empty($outbound_deliveries_for_table)): ?>
                               <?php foreach ($outbound_deliveries_for_table as $delivery): ?>
                                  <tr>
                                      <td><?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?></td>
                                      <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                      <td><?php echo htmlspecialchars($delivery['wattage'] ?? 'N/A'); ?>W</td>
                                      <td><?php echo number_format($delivery['quantity'] ?? 0); ?></td>
                                      <td><?php echo htmlspecialchars($delivery['left_warehouse_date'] ?? 'N/A'); ?></td>
                                       <td>
                                           <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                               <a href="view_pod.php?delivery_id=<?php echo $delivery['id']; ?>" target="_blank">View</a>
                                           <?php else: ?>
                                               N/A
                                           <?php endif; ?>
                                       </td>
                                      <td>
                                          <a href="edit_delivery.php?delivery_id=<?php echo $delivery['id']; ?>&warehouse_id=<?php echo $warehouse_id; ?><?php if($project_id) echo '&project_id='.$project_id; ?>" class="action-button" style="padding: 3px 8px; font-size: 0.9em;">Edit</a>
                                      </td>
                                  </tr>
                              <?php endforeach; ?>
                          <?php else: ?>
                              <tr><td colspan="7">No outbound truckloads recorded<?php echo $project_id ? ' for this project' : ''; ?> in this warehouse.</td></tr>
                          <?php endif; ?>
                      </tbody>
                  </table>
              </div>
         </div>
         <!-- === END Standard Warehouse View === -->
    <?php endif; ?>

</main>

<script>
    // --- Tab Switching --- 
    function openTab(evt, tabName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) {
            tabcontent[i].style.display = "none";
            tabcontent[i].classList.remove("active");
        }
        tablinks = document.getElementsByClassName("tab-link");
        for (i = 0; i < tablinks.length; i++) {
            tablinks[i].className = tablinks[i].className.replace(" active", "");
        }
        var activeTab = document.getElementById(tabName);
        if(activeTab) {
            activeTab.style.display = "block";
            activeTab.classList.add("active");
        }
        if(evt && evt.currentTarget) {
            evt.currentTarget.className += " active";
        }

        // If opening TruckloadView, default to showing arrivals sub-view
        if (tabName === 'TruckloadView') {
            showTruckloadSubView('arrivals');
        }
    }

    // --- NEW: Sub-view toggling for Truckload History ---
    function showTruckloadSubView(viewType) {
        const arrivalsView = document.getElementById('truckloadArrivalsSubView');
        const departuresView = document.getElementById('truckloadDeparturesSubView');
        const arrivalsButton = document.querySelector('.sub-tab-button[onclick*="arrivals"]');
        const departuresButton = document.querySelector('.sub-tab-button[onclick*="departures"]');

        if (viewType === 'arrivals') {
            if(arrivalsView) arrivalsView.style.display = 'block';
            if(departuresView) departuresView.style.display = 'none';
            if(arrivalsButton) arrivalsButton.classList.add('active');
            if(departuresButton) departuresButton.classList.remove('active');
        } else if (viewType === 'departures') {
            if(arrivalsView) arrivalsView.style.display = 'none';
            if(departuresView) departuresView.style.display = 'block';
            if(arrivalsButton) arrivalsButton.classList.remove('active');
            if(departuresButton) departuresButton.classList.add('active');
        }
    }

     // --- Filtering Logic --- 
     function filterTable(tableId, searchInputId, wattageSelectId, dateFilterIds = {}) {
         const table = document.getElementById(tableId);
         if (!table) return;
         const searchInput = document.getElementById(searchInputId);
         const wattageSelect = document.getElementById(wattageSelectId);
         const searchFilter = searchInput ? searchInput.value.toLowerCase().trim() : '';
         const wattageFilter = wattageSelect ? wattageSelect.value : '';

         const dateFilters = {};
         if(dateFilterIds) { // Check if dateFilterIds is provided
             for (const key in dateFilterIds) {
                 const select = document.getElementById(dateFilterIds[key].selectId);
                 if (select) {
                     dateFilters[key] = { value: select.value, cellIndex: dateFilterIds[key].cellIndex };
                 }
             }
         }

         const rows = table.querySelectorAll('tbody tr');
         let noResultsRow = table.querySelector('tbody tr td[colspan]'); 

         rows.forEach(row => {
             if (noResultsRow && row === noResultsRow.parentNode) return;
             
             let show = true;
             const cells = row.cells;

             if (searchFilter) {
                 let rowText = row.textContent.toLowerCase();
                 if (!rowText.includes(searchFilter)) {
                     show = false;
                 }
             }

             if (show && wattageFilter) {
                 let wattageCellIndex = -1;
                 if (tableId === 'inventoryTable' && cells.length > 2) wattageCellIndex = 2;
                 else if (tableId === 'inboundTruckloadsTable' && cells.length > 2) wattageCellIndex = 2;
                 else if (tableId === 'outboundTruckloadsTable' && cells.length > 2) wattageCellIndex = 2;

                 if (wattageCellIndex !== -1 && cells[wattageCellIndex]) {
                     const wattageText = cells[wattageCellIndex].textContent.replace('W', '').trim();
                     if (wattageText !== wattageFilter) {
                         show = false;
                     }
                 }
             }

             if (show && dateFilterIds) {
                  for (const key in dateFilters) {
                      const filterInfo = dateFilters[key];
                      if (filterInfo.value && cells[filterInfo.cellIndex]) {
                           const cellDate = cells[filterInfo.cellIndex].textContent.trim();
                           if (cellDate !== filterInfo.value) {
                                show = false;
                                break; 
                           }
                      }
                  }
             }

             row.style.display = show ? '' : 'none';
         });
     }

     document.addEventListener('DOMContentLoaded', function() {
         // Set Inventory View as default active tab
         const defaultActiveButton = document.querySelector('.tabs button.active');
         if (defaultActiveButton) {
             openTab({ currentTarget: defaultActiveButton }, defaultActiveButton.textContent.includes('Inventory') ? 'InventoryView' : 'TruckloadView');
         }
         
         // Filters for Inbound Truckloads Table
         const inboundArrivedDateFilter = document.getElementById('inboundArrivedDateFilter');
         const inboundTruckloadSearch = document.getElementById('inboundTruckloadSearch');
         const inboundTruckloadWattageFilter = document.getElementById('inboundTruckloadWattageFilter'); 

         const inboundDateFilters = {};
         if(inboundArrivedDateFilter) inboundDateFilters['arrived'] = { selectId: 'inboundArrivedDateFilter', cellIndex: 4 }; 

         if(inboundArrivedDateFilter) inboundArrivedDateFilter.addEventListener('change', () => filterTable('inboundTruckloadsTable', 'inboundTruckloadSearch', 'inboundTruckloadWattageFilter', inboundDateFilters));
         if(inboundTruckloadSearch) inboundTruckloadSearch.addEventListener('keyup', () => filterTable('inboundTruckloadsTable', 'inboundTruckloadSearch', 'inboundTruckloadWattageFilter', inboundDateFilters));
         if(inboundTruckloadWattageFilter) inboundTruckloadWattageFilter.addEventListener('change', () => filterTable('inboundTruckloadsTable', 'inboundTruckloadSearch', 'inboundTruckloadWattageFilter', inboundDateFilters));

         // Filters for Outbound Truckloads Table
         const outboundLeftDateFilter = document.getElementById('outboundLeftDateFilter');
         const outboundTruckloadSearch = document.getElementById('outboundTruckloadSearch');
         const outboundTruckloadWattageFilter = document.getElementById('outboundTruckloadWattageFilter'); 

         const outboundDateFilters = {};
         if(outboundLeftDateFilter) outboundDateFilters['left'] = { selectId: 'outboundLeftDateFilter', cellIndex: 4 }; // Departure date is cell index 4 in outbound table

         if(outboundLeftDateFilter) outboundLeftDateFilter.addEventListener('change', () => filterTable('outboundTruckloadsTable', 'outboundTruckloadSearch', 'outboundTruckloadWattageFilter', outboundDateFilters));
         if(outboundTruckloadSearch) outboundTruckloadSearch.addEventListener('keyup', () => filterTable('outboundTruckloadsTable', 'outboundTruckloadSearch', 'outboundTruckloadWattageFilter', outboundDateFilters));
         if(outboundTruckloadWattageFilter) outboundTruckloadWattageFilter.addEventListener('change', () => filterTable('outboundTruckloadsTable', 'outboundTruckloadSearch', 'outboundTruckloadWattageFilter', outboundDateFilters));

         // Filters for Inventory Table (remains the same)
         const inventorySearch = document.getElementById('inventorySearch');
         const inventoryWattageFilter = document.getElementById('inventoryWattageFilter');

         if(inventorySearch) inventorySearch.addEventListener('keyup', () => filterTable('inventoryTable', 'inventorySearch', 'inventoryWattageFilter'));
         if(inventoryWattageFilter) inventoryWattageFilter.addEventListener('change', () => filterTable('inventoryTable', 'inventorySearch', 'inventoryWattageFilter'));
         
         filterTable('inventoryTable', 'inventorySearch', 'inventoryWattageFilter');
         filterTable('inboundTruckloadsTable', 'inboundTruckloadSearch', 'inboundTruckloadWattageFilter', inboundDateFilters);
         filterTable('outboundTruckloadsTable', 'outboundTruckloadSearch', 'outboundTruckloadWattageFilter', outboundDateFilters);
     });

</script>

</body>
</html>
