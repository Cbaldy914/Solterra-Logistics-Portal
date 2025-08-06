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
$google_maps_api_key = getGoogleMapsApiKey();

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

// ===========================================================================================
// HELPER FUNCTIONS: DATA FETCHING LOGIC SEPARATED BY FACILITY TYPE
// ===========================================================================================

/**
 * Fetch stored pallets/containers for warehouse inventory display
 * @param PDO $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @param string $received_status Status filter for received items
 * @param bool $is_port Whether this is a port facility
 * @return array Array of stored pallets or containers
 */
function fetchStoredInventory($conn, $warehouse_id, $received_status, $is_port) {
    $pallets_in_storage = [];
    $total_pallets = 0;
    
    // Robust pallet query - INNER JOIN to only include pallets with deliveries to current warehouse
    $stmtP_Stored = $conn->prepare("
        SELECT 
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            ip.arrival_date,
            m.vendor_name AS origin_vendor,
            d_received.id AS received_delivery_id,
            d_received.bol_number AS received_bol,
            p_assigned.project_name AS assigned_project
        FROM inventory_pallets ip
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        INNER JOIN delivery_pallets dp_received ON ip.id = dp_received.inventory_pallet_id
        INNER JOIN deliveries d_received ON dp_received.delivery_id = d_received.id 
            AND d_received.warehouse_id = ?
            AND d_received.status_of_delivery != 'Departed Port'
        LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
        WHERE ip.current_warehouse_id = ? AND ip.status = ?
        ORDER BY ip.arrival_date DESC, ip.id DESC
    ");
    
    if (!$stmtP_Stored) throw new Exception("Failed to prepare stored pallets query: " . $conn->error);
    $stmtP_Stored->bind_param("iis", $warehouse_id, $warehouse_id, $received_status);
    $stmtP_Stored->execute();
    $resultP_Stored = $stmtP_Stored->get_result();

    while ($pallet = $resultP_Stored->fetch_assoc()) {
        $pallets_in_storage[] = $pallet;
        $pallet['assigned_project'] = $pallet['assigned_project'] ?? 'N/A';
        $total_pallets++;
    }
    $stmtP_Stored->close();
    
    return [$pallets_in_storage, $total_pallets];
}

/**
 * PORT-SPECIFIC: Fetch containers grouped for port operations interface
 * @param PDO $conn Database connection
 * @param int $warehouse_id Port ID
 * @param string $received_status Status filter (typically 'Cleared Customs')
 * @return array Array of cleared containers with grouped data
 */
function fetchPortContainersCleared($conn, $warehouse_id, $received_status) {
    $containers_cleared = [];
    
        $stmtContainers = $conn->prepare("
            SELECT 
                d_received.bol_number AS container_number,
                d_received.id AS delivery_id,
                MIN(ip.arrival_date) AS arrival_date,
                m.vendor_name AS origin_vendor,
                COUNT(ip.id) AS total_pallets,
                SUM(ip.quantity) AS total_modules,
                GROUP_CONCAT(DISTINCT ip.wattage ORDER BY ip.wattage SEPARATOR ', ') AS wattages,
                GROUP_CONCAT(DISTINCT p_assigned.project_name SEPARATOR ', ') AS projects
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN delivery_pallets dp_received ON ip.id = dp_received.inventory_pallet_id
            LEFT JOIN deliveries d_received ON dp_received.delivery_id = d_received.id AND d_received.warehouse_id = ?
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
        WHERE ip.current_warehouse_id = ? 
            AND ip.status = ?
            AND d_received.status_of_delivery != 'Departed Port'
            GROUP BY d_received.bol_number, d_received.id, m.vendor_name
            ORDER BY MIN(ip.arrival_date) DESC
        ");
    
        if ($stmtContainers) {
            $stmtContainers->bind_param("iis", $warehouse_id, $warehouse_id, $received_status);
            $stmtContainers->execute();
            $resultContainers = $stmtContainers->get_result();
            
            while ($container = $resultContainers->fetch_assoc()) {
            // Create detailed wattage breakdown for this container
                $wattages = explode(', ', $container['wattages']);
                $wattage_details = [];
                foreach ($wattages as $wattage) {
                    $stmtWattageDetail = $conn->prepare("
                        SELECT COUNT(ip.id) as pallet_count, SUM(ip.quantity) as module_count
                        FROM inventory_pallets ip
                        JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                        WHERE dp.delivery_id = ? AND ip.wattage = ? AND ip.status = ?
                    ");
                    if ($stmtWattageDetail) {
                        $stmtWattageDetail->bind_param("iis", $container['delivery_id'], $wattage, $received_status);
                        $stmtWattageDetail->execute();
                        $stmtWattageDetail->bind_result($pallet_count, $module_count);
                        if ($stmtWattageDetail->fetch()) {
                            $wattage_details[] = "{$wattage}W: {$pallet_count} pallets ({$module_count} modules)";
                        }
                        $stmtWattageDetail->close();
                    }
                }
                $container['wattage_breakdown'] = implode(' • ', $wattage_details);
                $container['projects'] = $container['projects'] ?? 'N/A';
                $containers_cleared[] = $container;
            }
            $stmtContainers->close();
        }
    
    return $containers_cleared;
}

/**
 * Fetch pallets currently in transit to this facility
 * @param PDO $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @return array Array of pallets in transit
 */
function fetchTransitPallets($conn, $warehouse_id) {
    $pallets_in_transit = [];
    
    $stmtP_Transit = $conn->prepare("
        SELECT 
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            m.vendor_name AS origin_vendor,
            d.bol_number AS delivery_bol,
            d.anticipated_delivery_date AS est_arrival_date,
            d.id AS delivery_id,
            p.project_name AS source_project
        FROM inventory_pallets ip
        JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
        JOIN deliveries d ON dp.delivery_id = d.id
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE ip.status IN ('In Transit to Warehouse', 'On Water') 
            AND d.warehouse_id = ?
            AND d.status_of_delivery != 'Departed Port'
        ORDER BY d.anticipated_delivery_date ASC, ip.id DESC
    ");
    
    if (!$stmtP_Transit) throw new Exception("Failed to prepare transit pallets query: " . $conn->error);
    $stmtP_Transit->bind_param("i", $warehouse_id);
    $stmtP_Transit->execute();
    $resultP_Transit = $stmtP_Transit->get_result();
    
    while ($pallet = $resultP_Transit->fetch_assoc()) {
        $pallets_in_transit[] = $pallet;
        $pallet['source_project'] = $pallet['source_project'] ?? 'N/A';
    }
    $stmtP_Transit->close();

    return $pallets_in_transit;
}

/**
 * Fetch transit deliveries grouped by delivery/truckload
 * @param PDO $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @return array Array of transit truckloads with grouped data
 */
function fetchTransitTruckloads($conn, $warehouse_id) {
    $transit_truckloads = [];
    
    $stmtTransitTruckloads = $conn->prepare("
        SELECT 
            d.id AS delivery_id,
            d.bol_number,
            d.supplier AS origin_vendor,
            d.anticipated_delivery_date AS est_arrival_date,
            COUNT(ip.id) AS total_pallets,
            SUM(ip.quantity) AS total_modules,
            GROUP_CONCAT(DISTINCT ip.wattage ORDER BY ip.wattage SEPARATOR ', ') AS wattages,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects
        FROM deliveries d
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE d.warehouse_id = ? 
        AND ip.status IN ('In Transit to Warehouse', 'On Water')
            AND d.status_of_delivery != 'Departed Port'
        GROUP BY d.id, d.bol_number, d.supplier, d.anticipated_delivery_date
        ORDER BY d.anticipated_delivery_date ASC
    ");
    
    if ($stmtTransitTruckloads) {
        $stmtTransitTruckloads->bind_param("i", $warehouse_id);
        $stmtTransitTruckloads->execute();
        $resultTransitTruckloads = $stmtTransitTruckloads->get_result();
        
        while ($truckload = $resultTransitTruckloads->fetch_assoc()) {
            // Create detailed wattage breakdown
            $wattages = explode(', ', $truckload['wattages']);
            $wattage_details = [];
            foreach ($wattages as $wattage) {
                $stmtWattageDetail = $conn->prepare("
                    SELECT COUNT(ip.id) as pallet_count, SUM(ip.quantity) as module_count
                    FROM inventory_pallets ip
                    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                    WHERE dp.delivery_id = ? AND ip.wattage = ? AND ip.status IN ('In Transit to Warehouse', 'On Water')
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
            $truckload['projects'] = $truckload['projects'] ?? 'N/A';
        }
        $stmtTransitTruckloads->close();
    }

    return $transit_truckloads;
}

/**
 * Fetch inbound delivery history for this facility
 * @param PDO $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @return array Array of inbound delivery history grouped by BOL
 */
function fetchInboundHistory($conn, $warehouse_id) {
    $inbound_history = [];
    
    $stmtInboundHistory = $conn->prepare("
        SELECT 
            d.bol_number,
            d.supplier,
            d.warehouse_arrival_date,
            d.proof_of_delivery,
            COUNT(DISTINCT d.id) AS delivery_count,
            COUNT(DISTINCT dp.inventory_pallet_id) AS total_pallets,
            (SELECT SUM(d_inner.quantity) FROM deliveries d_inner WHERE d_inner.bol_number = d.bol_number AND d_inner.warehouse_id = d.warehouse_id AND d_inner.warehouse_arrival_date = d.warehouse_arrival_date) AS total_modules,
            GROUP_CONCAT(DISTINCT d.wattage ORDER BY d.wattage SEPARATOR ', ') AS wattages,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects,
            GROUP_CONCAT(DISTINCT d.id ORDER BY d.id SEPARATOR ',') AS delivery_ids,
            CASE WHEN COUNT(DISTINCT d.wattage) > 1 THEN 1 ELSE 0 END AS is_mixed_wattage
        FROM deliveries d
        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE d.warehouse_id = ? 
        AND d.status_of_delivery = 'Delivered to Warehouse'
        AND d.warehouse_arrival_date IS NOT NULL
        GROUP BY d.bol_number, d.supplier, d.warehouse_arrival_date, d.proof_of_delivery
        ORDER BY d.warehouse_arrival_date DESC
    ");
    
    if ($stmtInboundHistory) {
        $stmtInboundHistory->bind_param("i", $warehouse_id);
        $stmtInboundHistory->execute();
        $resultInboundHistory = $stmtInboundHistory->get_result();
        $index = 0;
        
        while ($delivery = $resultInboundHistory->fetch_assoc()) {
            $delivery['source_project'] = $delivery['projects'] ?? 'N/A';
            $delivery['index'] = $index;
            
            // Handle mixed wattage deliveries with detailed breakdown
            if ($delivery['is_mixed_wattage']) {
                $delivery_ids = explode(',', $delivery['delivery_ids']);
                $delivery['details'] = [];
                
                foreach ($delivery_ids as $del_id) {
                    $stmtDetail = $conn->prepare("
                        SELECT d.id, d.wattage, d.quantity, p.project_name,
                               COUNT(dp.inventory_pallet_id) AS pallet_count
                        FROM deliveries d
                        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
                        LEFT JOIN projects p ON d.project_id = p.id
                        WHERE d.id = ?
                        GROUP BY d.id, d.wattage, d.quantity, p.project_name
                    ");
                    if ($stmtDetail) {
                        $stmtDetail->bind_param("i", $del_id);
                        $stmtDetail->execute();
                        $resultDetail = $stmtDetail->get_result();
                        if ($detail = $resultDetail->fetch_assoc()) {
                            $delivery['details'][] = $detail;
                        }
                        $stmtDetail->close();
                    }
                }
            }
            
            $inbound_history[] = $delivery;
            $index++;
        }
        $stmtInboundHistory->close();
    }
    
    return $inbound_history;
}

/**
 * Fetch outbound delivery history for this facility
 * @param PDO $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @return array Array of outbound delivery history grouped by BOL
 */
function fetchOutboundHistory($conn, $warehouse_id) {
    $outbound_history = [];
    
    $stmtOutboundHistory = $conn->prepare("
        SELECT 
            d.bol_number,
            d.supplier,
            d.left_warehouse_date AS departure_date,
            d.anticipated_delivery_date,
            d.status_of_delivery,
            COUNT(DISTINCT d.id) AS delivery_count,
            COUNT(DISTINCT dp.inventory_pallet_id) AS total_pallets,
            (SELECT SUM(d_inner.quantity) FROM deliveries d_inner WHERE d_inner.bol_number = d.bol_number AND d_inner.supplier = d.supplier AND d_inner.left_warehouse_date = d.left_warehouse_date) AS total_modules,
            GROUP_CONCAT(DISTINCT d.wattage ORDER BY d.wattage SEPARATOR ', ') AS wattages,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects,
            GROUP_CONCAT(DISTINCT d.id ORDER BY d.id SEPARATOR ',') AS delivery_ids,
            CASE WHEN COUNT(DISTINCT d.wattage) > 1 THEN 1 ELSE 0 END AS is_mixed_wattage,
            GROUP_CONCAT(DISTINCT 
                CASE 
                    WHEN d.project_id IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                    WHEN d.warehouse_id IS NOT NULL AND d.warehouse_id != ? THEN CONCAT('Warehouse: ', w.name)
                    ELSE 'Unknown Destination'
                END SEPARATOR ', '
            ) AS destinations
        FROM deliveries d
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN warehouses w ON d.warehouse_id = w.id
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        WHERE d.warehouse_id = ?
        AND d.left_warehouse_date IS NOT NULL
        GROUP BY d.bol_number, d.supplier, d.left_warehouse_date, d.anticipated_delivery_date, d.status_of_delivery
        ORDER BY d.left_warehouse_date DESC
    ");
    
    if ($stmtOutboundHistory) {
        $stmtOutboundHistory->bind_param("ii", $warehouse_id, $warehouse_id);
        $stmtOutboundHistory->execute();
        $resultOutboundHistory = $stmtOutboundHistory->get_result();
        $index = 0;
        
        while ($delivery = $resultOutboundHistory->fetch_assoc()) {
            $delivery['destination_project'] = $delivery['projects'] ?? 'N/A';
            $delivery['index'] = $index;
            
            // Handle mixed wattage deliveries with detailed breakdown
            if ($delivery['is_mixed_wattage']) {
                $delivery_ids = explode(',', $delivery['delivery_ids']);
                $delivery['details'] = [];
                
                foreach ($delivery_ids as $del_id) {
                    $stmtDetail = $conn->prepare("
                        SELECT d.id, d.wattage, d.quantity, p.project_name,
                               COUNT(dp.inventory_pallet_id) AS pallet_count,
                               CASE 
                                   WHEN d.project_id IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                                   WHEN d.warehouse_id IS NOT NULL AND d.warehouse_id != ? THEN CONCAT('Warehouse: ', w2.name)
                                   ELSE 'Unknown Destination'
                               END AS destination
                        FROM deliveries d
                        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
                        LEFT JOIN projects p ON d.project_id = p.id
                        LEFT JOIN warehouses w2 ON d.warehouse_id = w2.id
                        WHERE d.id = ?
                        GROUP BY d.id, d.wattage, d.quantity, p.project_name, destination
                    ");
                    if ($stmtDetail) {
                        $stmtDetail->bind_param("ii", $warehouse_id, $del_id);
                        $stmtDetail->execute();
                        $resultDetail = $stmtDetail->get_result();
                        if ($detail = $resultDetail->fetch_assoc()) {
                            $delivery['details'][] = $detail;
                        }
                        $stmtDetail->close();
                    }
                }
            }
            
            $outbound_history[] = $delivery;
            $index++;
        }
        $stmtOutboundHistory->close();
    }
    
    return $outbound_history;
}

// ===========================================================================================
// MAIN EXECUTION: FETCH FACILITY DATA AND CONFIGURE INTERFACE
// ===========================================================================================

try {
    // Fetch Warehouse/Port Details
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

    // ===========================================================================================
    // FACILITY TYPE DETECTION AND UI CONFIGURATION
    // ===========================================================================================
    $is_port = ($warehouse['is_port'] == 1);
    
    // Configure UI text and behavior based on facility type
    $facility_type = $is_port ? 'Port' : 'Warehouse';
    $page_title = $is_port ? 'Port Operations' : 'Warehouse Inventory';
    $receiving_title = $is_port ? 'Receive Container(s)' : 'Receive Selected Truckloads';
    $history_title = $is_port ? 'Container History' : 'Truckload History';
    $inventory_title = $is_port ? 'Containers Cleared' : 'Stored Inventory';
    $received_status = $is_port ? 'Cleared Customs' : 'In Warehouse';
    $transit_status_filter = $is_port ? "('In Transit to Warehouse', 'On Water')" : "('In Transit to Warehouse')";
    $grouping_field = $is_port ? 'container_number' : 'bol_number';
    
    // ===========================================================================================
    // FETCH STORED INVENTORY DATA (COMMON FOR BOTH FACILITIES)
    // ===========================================================================================
    list($pallets_in_storage, $total_pallets) = fetchStoredInventory($conn, $warehouse_id, $received_status, $is_port);
    
    // ===========================================================================================
    // PORT-SPECIFIC: FETCH CONTAINERS CLEARED (FOR GROUPED CONTAINER VIEW)
    // ===========================================================================================
    $containers_cleared = [];
    if ($is_port) {
        $containers_cleared = fetchPortContainersCleared($conn, $warehouse_id, $received_status);
    }

    // ===========================================================================================
    // FETCH TRANSIT INVENTORY DATA (COMMON FOR BOTH FACILITIES)
    // ===========================================================================================
    $pallets_in_transit = fetchTransitPallets($conn, $warehouse_id);
    $transit_truckloads = fetchTransitTruckloads($conn, $warehouse_id);

    // ===========================================================================================
    // FETCH DELIVERY HISTORY DATA (COMMON FOR BOTH FACILITIES)
    // ===========================================================================================
    $inbound_history = fetchInboundHistory($conn, $warehouse_id);
    $outbound_history = fetchOutboundHistory($conn, $warehouse_id);
    
    // ===========================================================================================
    // CALCULATE COST ESTIMATES AND FETCH REFERENCE DATA
    // ===========================================================================================
    
    // Monthly cost estimate calculation
    $total_storage_cost_monthly_rate = $total_pallets * ($warehouse['monthly_storage_fee'] ?? 0);

    // Fetch all projects for dropdown options (build full addresses like create_shipment.php)
    $stmtAllP = $conn->prepare("SELECT id, project_name, street_address, city, state, zip_code FROM projects ORDER BY project_name ASC");
    if ($stmtAllP) {
        $stmtAllP->execute();
        $resultAllP = $stmtAllP->get_result();
        while ($proj = $resultAllP->fetch_assoc()) {
            // Build full address like create_shipment.php
            $address_parts = array_filter([$proj['street_address'], $proj['city'], $proj['state'], $proj['zip_code']]);
            $proj['full_address'] = implode(', ', $address_parts);
            $all_projects[] = $proj;
        }
        $stmtAllP->close();
    }

    // Fetch other warehouses (exclude ports and current warehouse, build full addresses like create_shipment.php)
    $stmtOtherW = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM warehouses WHERE (is_port = 0 OR is_port IS NULL) AND id != ? ORDER BY name ASC");
    if ($stmtOtherW) {
        $stmtOtherW->bind_param("i", $warehouse_id);
        $stmtOtherW->execute();
        $resultOtherW = $stmtOtherW->get_result();
        while ($wh = $resultOtherW->fetch_assoc()) {
            // Build full address like create_shipment.php
            $address_parts = array_filter([$wh['street_address'], $wh['city'], $wh['state'], $wh['zip_code']]);
            $wh['full_address'] = implode(', ', $address_parts);
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
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places,geometry"></script>

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
        .close-modal, .close-receive-modal, .close-receive-truckload-modal, .close-move-container-modal {
            color: #aaa;
            position: absolute;
            top: 15px;
            right: 25px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            z-index: 1001;
            line-height: 1;
        }
        .close-modal:hover, .close-modal:focus,
        .close-receive-modal:hover, .close-receive-modal:focus,
        .close-receive-truckload-modal:hover, .close-receive-truckload-modal:focus,
        .close-move-container-modal:hover, .close-move-container-modal:focus {
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
        
        /* Mixed wattage row styling */
        .mixed-wattage-row {
            background-color: #f0f8ff !important;
            font-weight: 500;
        }
        .mixed-wattage-row:hover {
            background-color: #e6f3ff !important;
        }
        .expand-icon {
            display: inline-block;
            margin-right: 8px;
            transition: transform 0.3s ease;
            font-size: 12px;
            color: #488C9A;
        }
        .expanded .expand-icon {
            transform: rotate(90deg);
        }
        .detail-row {
            transition: all 0.3s ease;
        }
        .detail-row.show {
            opacity: 1;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Add breadcrumb navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <?php 
        $from_project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
        if ($from_project_id > 0): 
            // Coming from project overview - show project breadcrumb
            $project_name = 'Project';
            $conn_breadcrumb = getDBConnection();
            if ($conn_breadcrumb) {
                $stmt_breadcrumb = $conn_breadcrumb->prepare("SELECT project_name FROM projects WHERE id = ?");
                if ($stmt_breadcrumb) {
                    $stmt_breadcrumb->bind_param("i", $from_project_id);
                    $stmt_breadcrumb->execute();
                    $stmt_breadcrumb->bind_result($project_name);
                    $stmt_breadcrumb->fetch();
                    $stmt_breadcrumb->close();
                }
                $conn_breadcrumb->close();
            }
        ?>
            <a href="project_overview.php?project_id=<?php echo $from_project_id; ?>" style="color: #488C9A; text-decoration: none;"><?php echo htmlspecialchars($project_name); ?></a>
            <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <?php else: ?>
            <a href="manage_warehouses.php" style="color: #488C9A; text-decoration: none;">Manage Warehouses</a>
            <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <?php endif; ?>
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
                <button id="storedInventoryTab" class="active"><?php echo $inventory_title; ?> (<?php echo $is_port ? count($containers_cleared) : count($pallets_in_storage); ?>)</button>
                <button id="inboundTransitTab">Inbound Transit (<?php echo count($pallets_in_transit); ?>)</button>
                <button id="truckloadHistoryTab"><?php echo $history_title; ?> (<?php echo count($inbound_history) + count($outbound_history); ?>)</button>
            </div>
        </div>

        <!-- TAB CONTENT: STORED INVENTORY -->
        <div id="storedInventoryContent" class="tab-content active">
            <h2><?php echo $inventory_title; ?></h2>
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
                    <?php if (!$is_port): ?>
                        <a href="create_shipment.php?source_type=warehouse&source_id=<?php echo $warehouse_id; ?>&status_filter=<?php echo urlencode($received_status); ?>" class="action-button">Create Shipment</a>
                    <?php endif; ?>
                    <?php if ($is_port && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                        <button id="moveContainerBtn" class="action-button" disabled>Move Container (Drayage)</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-responsive">
                <table id="storedTable">
                    <thead>
                        <tr>
                            <?php if ($is_port && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                <th><input type="checkbox" id="selectAllContainers" onchange="toggleAllContainers()"> Select All</th>
                                <th>Container Number</th>
                                <th>Project(s)</th>
                                <th>Origin Vendor</th>
                                <th>Total Pallets</th>
                                <th>Total Modules</th>
                                <th>Wattage Breakdown</th>
                                <th>Arrival Date</th>
                            <?php else: ?>
                                <th>Identifier</th>
                                <th>Project</th>
                                <th>Origin Vendor</th>
                                <th>Wattage</th>
                                <th>Quantity</th>
                                <th>Arrival Date</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($is_port): ?>
                            <!-- 🚢 PORT VIEW: Show containers grouped -->
                            <?php if (!empty($containers_cleared)): ?>
                                <?php foreach ($containers_cleared as $container): ?>
                                    <tr>
                                        <?php if (in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                            <td><input type="checkbox" class="container-checkbox" value="<?php echo $container['delivery_id']; ?>" onchange="toggleMoveContainerBtn()"></td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($container['container_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($container['projects']); ?></td>
                                        <td><?php echo htmlspecialchars($container['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($container['total_pallets']); ?></td>
                                        <td><?php echo number_format($container['total_modules']); ?></td>
                                        <td style="font-size: 0.9em;"><?php echo htmlspecialchars($container['wattage_breakdown'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                            if (!empty($container['arrival_date']) && $container['arrival_date'] !== 'N/A') {
                                                try {
                                                    $date = new DateTime($container['arrival_date']);
                                                    echo $date->format('m-d-Y');
                                                } catch (Exception $e) {
                                                    echo htmlspecialchars($container['arrival_date']);
                                                }
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8">No containers currently cleared at this port.</td></tr>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- 🚛 WAREHOUSE VIEW: Show individual pallets -->
                            <?php if (!empty($pallets_in_storage)): ?>
                                <?php foreach ($pallets_in_storage as $pallet): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['assigned_project']); ?></td>
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
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB CONTENT: INBOUND TRANSIT -->
        <div id="inboundTransitContent" class="tab-content">
            <div class="sub-tabs-container">
                <button class="sub-tab-button active" onclick="showTransitSubView('byTruckload')">By Truckload (<?php echo count($transit_truckloads); ?>)</button>
                <button class="sub-tab-button" onclick="showTransitSubView('byPallet')">By Pallet (<?php echo count($pallets_in_transit); ?>)</button>
            </div>

            <!-- Inbound Transit - By Pallet -->
            <div id="transitByPalletView" class="sub-tab-content">
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
                </div>

                <div class="table-responsive">
                    <table id="transitTable">
                        <thead>
                            <tr>
                                <th>Identifier</th>
                                <th>Project</th>
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
                                        <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['source_project']); ?></td>
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
            </div>

            <!-- Inbound Transit - By Truckload -->
            <div id="transitByTruckloadView" class="sub-tab-content active">
                <h2>Inbound Transit - By Truckload</h2>
                <div class="table-controls-header">
                    <div class="filter-controls">
                        <!-- Optional filters can be added here -->
                    </div>
                    <div class="page-actions">
                        <button id="receiveTruckloadBtn" class="action-button" disabled><?php echo $receiving_title; ?></button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="transitTruckloadTable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllTruckloads" onchange="toggleAllTruckloads()"> Select All</th>
                                <th>Project</th>
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
                                            <input type="checkbox" 
                                                   name="selected_truckloads" 
                                                   value="<?php echo $truckload['delivery_id']; ?>"
                                                   class="truckload-checkbox"
                                                   onchange="updateReceiveTruckloadButton()">
                                        </td>
                                        <td><?php echo htmlspecialchars($truckload['projects']); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['bol_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['est_arrival_date'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($truckload['total_pallets']); ?></td>
                                        <td><?php echo number_format($truckload['total_modules']); ?></td>
                                        <td style="font-size: 0.9em;"><?php echo htmlspecialchars($truckload['wattage_breakdown'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8">No truckloads currently in transit to this warehouse.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT: TRUCKLOAD HISTORY -->
        <div id="truckloadHistoryContent" class="tab-content">
            <div class="sub-tabs-container">
                <button class="sub-tab-button active" onclick="showHistorySubView('inbound')">Inbound History (<?php echo count($inbound_history); ?>)</button>
                <button class="sub-tab-button" onclick="showHistorySubView('outbound')">Outbound History (<?php echo count($outbound_history); ?>)</button>
            </div>

            <!-- Inbound History -->
            <div id="inboundHistoryView" class="sub-tab-content active">
                <h2>Inbound Truckload History</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>BOL Number</th>
                                <th>Project</th>
                                <th>Supplier</th>
                                <th>Wattage</th>
                                <th>Modules (Pallets)</th>
                                <th>Arrival Date</th>
                                <th>Proof of Delivery</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($inbound_history)): ?>
                                <?php foreach ($inbound_history as $delivery): ?>
                                    <tr data-delivery-index="<?php echo $delivery['index']; ?>" 
                                        class="<?php echo $delivery['is_mixed_wattage'] ? 'mixed-wattage-row' : ''; ?>"
                                        <?php if ($delivery['is_mixed_wattage']): ?>
                                            onclick="toggleInboundDetails(<?php echo $delivery['index']; ?>)" 
                                            style="cursor: pointer;"
                                        <?php endif; ?>>
                                        <td>
                                            <?php if ($delivery['is_mixed_wattage']): ?>
                                                <span class="expand-icon" id="expand-icon-<?php echo $delivery['index']; ?>">▶</span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($delivery['source_project']); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($delivery['is_mixed_wattage']): ?>
                                                Mixed (<?php echo htmlspecialchars($delivery['wattages']); ?>W)
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($delivery['wattages']); ?>W
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo number_format($delivery['total_modules']) . ' (' . number_format($delivery['total_pallets']) . ')'; ?></td>
                                        <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                                <a href="view_pod.php?delivery_id=<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>" target="_blank" style="color: #488C9A;">View POD</a>
                                            <?php else: ?>
                                                <button class="action-button" style="padding: 2px 6px; font-size: 0.8em;" onclick="uploadPOD(<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>)">Upload POD</button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($delivery['is_mixed_wattage']): ?>
                                                <button class="action-button" style="padding: 3px 8px; font-size: 0.9em;" onclick="event.stopPropagation(); window.location.href='manage_deliveries.php?search=<?php echo urlencode($delivery['bol_number']); ?>'">Manage</button>
                                            <?php else: ?>
                                                <a href="edit_delivery.php?delivery_id=<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>&warehouse_id=<?php echo $warehouse_id; ?>" 
                                                   class="action-button" style="padding: 3px 8px; font-size: 0.9em;">Edit</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    
                                    <?php if ($delivery['is_mixed_wattage'] && !empty($delivery['details'])): ?>
                                        <?php foreach ($delivery['details'] as $detail_index => $detail): ?>
                                            <tr id="inbound-detail-<?php echo $delivery['index']; ?>-<?php echo $detail_index; ?>" 
                                                class="detail-row" style="display: none; background-color: #f8f9fa;">
                                                <td style="padding-left: 30px;">└ Detail <?php echo $detail_index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($detail['project_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['wattage']); ?>W</td>
                                                <td><?php echo number_format($detail['quantity']) . ' (' . number_format($detail['pallet_count']) . ')'; ?></td>
                                                <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? 'N/A'); ?></td>
                                                <td>-</td>
                                                <td>
                                                    <a href="edit_delivery.php?delivery_id=<?php echo $detail['id']; ?>&warehouse_id=<?php echo $warehouse_id; ?>" 
                                                       class="action-button" style="padding: 2px 6px; font-size: 0.8em;">Edit</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8">No inbound deliveries recorded for this warehouse.</td></tr>
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
                                <th>Project</th>
                                <th>Supplier</th>
                                <th>Destination</th>
                                <th>Wattage</th>
                                <th>Modules (Pallets)</th>
                                <th>Departure Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($outbound_history)): ?>
                                <?php foreach ($outbound_history as $delivery): ?>
                                    <tr data-delivery-index="<?php echo $delivery['index']; ?>" 
                                        class="<?php echo $delivery['is_mixed_wattage'] ? 'mixed-wattage-row' : ''; ?>"
                                        <?php if ($delivery['is_mixed_wattage']): ?>
                                            onclick="toggleOutboundDetails(<?php echo $delivery['index']; ?>)" 
                                            style="cursor: pointer;"
                                        <?php endif; ?>>
                                        <td>
                                            <?php if ($delivery['is_mixed_wattage']): ?>
                                                <span class="expand-icon" id="outbound-expand-icon-<?php echo $delivery['index']; ?>">▶</span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($delivery['destination_project']); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['destinations'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($delivery['is_mixed_wattage']): ?>
                                                Mixed (<?php echo htmlspecialchars($delivery['wattages']); ?>W)
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($delivery['wattages']); ?>W
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo number_format($delivery['total_modules']) . ' (' . number_format($delivery['total_pallets']) . ')'; ?></td>
                                        <td><?php echo htmlspecialchars($delivery['departure_date'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($delivery['is_mixed_wattage']): ?>
                                                <button class="action-button" style="padding: 3px 8px; font-size: 0.9em;" onclick="event.stopPropagation(); window.location.href='manage_deliveries.php?search=<?php echo urlencode($delivery['bol_number']); ?>'">Manage</button>
                                            <?php else: ?>
                                                <a href="edit_delivery.php?delivery_id=<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>&warehouse_id=<?php echo $warehouse_id; ?>" 
                                                   class="action-button" style="padding: 3px 8px; font-size: 0.9em;">Edit</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    
                                    <?php if ($delivery['is_mixed_wattage'] && !empty($delivery['details'])): ?>
                                        <?php foreach ($delivery['details'] as $detail_index => $detail): ?>
                                            <tr id="outbound-detail-<?php echo $delivery['index']; ?>-<?php echo $detail_index; ?>" 
                                                class="detail-row" style="display: none; background-color: #f8f9fa;">
                                                <td style="padding-left: 30px;">└ Detail <?php echo $detail_index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($detail['project_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['destination'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['wattage']); ?>W</td>
                                                <td><?php echo number_format($detail['quantity']) . ' (' . number_format($detail['pallet_count']) . ')'; ?></td>
                                                <td><?php echo htmlspecialchars($delivery['departure_date'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <a href="edit_delivery.php?delivery_id=<?php echo $detail['id']; ?>&warehouse_id=<?php echo $warehouse_id; ?>" 
                                                       class="action-button" style="padding: 2px 6px; font-size: 0.8em;">Edit</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9">No outbound deliveries recorded from this warehouse.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="back-link" style="margin-top: 20px;">
            <a href="manage_warehouses.php" class="action-buttons">&larr; Back to Warehouses List</a>
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
            <input type="radio" name="pallet_destination_type" value="project" checked> Project
        </label>
        <label class="radio-label">
            <input type="radio" name="pallet_destination_type" value="warehouse"> Another Warehouse
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
    <?php if ($is_port): ?>
        <!-- 🚢 PORT RECEIVING FORM -->
        <div class="modal-form-row">
            <div>
                <label for="house_bol">House BOL:</label>
                <input type="text" id="house_bol" name="house_bol" placeholder="House Bill of Lading">
            </div>
            <div>
                <label for="master_bol">Master BOL:</label>
                <input type="text" id="master_bol" name="master_bol" placeholder="Master Bill of Lading">
            </div>
        </div>
        <div class="modal-form-row">
            <div>
                <label for="actual_truckload_arrival_date">Container Cleared Date:</label>
                <input type="date" id="actual_truckload_arrival_date" name="actual_truckload_arrival_date" required>
            </div>
        </div>
    <?php else: ?>
        <!-- 🚛 WAREHOUSE RECEIVING FORM -->
        <div class="modal-form-row">
            <div>
                <label for="receive_truckload_bol">BOL Number (if different):</label>
                <input type="text" id="receive_truckload_bol" name="receive_truckload_bol" placeholder="Leave blank to use existing BOL numbers">
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
    <?php endif; ?>
    <div style="margin-top: 20px; text-align: center;">
        <button type="button" id="confirmReceiveTruckloadBtn" class="action-button"><?php echo $is_port ? 'Receive Container(s)' : 'Receive Selected Truckloads'; ?></button>
    </div>
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
        <?php if ($is_port): ?>
            <div class="modal-header">
                Receive Container(s)
                <div style="font-size: 0.8em; color: #666; font-weight: normal; margin-top: 3px;">(Cleared Customs)</div>
            </div>
        <?php else: ?>
            <div class="modal-header">Receive Pallets</div>
        <?php endif; ?>
        <!-- #receiveFormContainer is moved here dynamically -->
    </div>
</div>

<!-- RECEIVE TRUCKLOAD MODAL -->
<div id="receiveTruckloadModal" class="modal">
    <div class="modal-content">
        <span class="close-receive-truckload-modal">&times;</span>
        <?php if ($is_port): ?>
            <div class="modal-header">
                Receive Container(s)
                <div style="font-size: 0.8em; color: #666; font-weight: normal; margin-top: 3px;">(Cleared Customs)</div>
            </div>
        <?php else: ?>
            <div class="modal-header"><?php echo $receiving_title; ?></div>
        <?php endif; ?>
        <!-- #receiveTruckloadFormContainer is moved here dynamically -->
    </div>
</div>

<!-- 🚢 MOVE CONTAINER MODAL (DRAYAGE) -->
<div id="moveContainerModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <span class="close-move-container-modal">&times;</span>
        <div class="shipment-details-modal-content">
            <h2 class="section-title" style="margin-top:0; text-align:center;">Move Container (Drayage)</h2>
            
            <form id="moveContainerForm" method="POST" action="create_shipment.php">
                <div class="form-row">
                    <div>
                        <label for="move_departure_date">Departure Date:</label>
                        <input type="date" id="move_departure_date" name="departure_date" required>
                    </div>
                    <div>
                        <label for="move_est_arrival_date">Est. Arrival Date:</label>
                        <input type="date" id="move_est_arrival_date" name="est_arrival_date" required>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label for="move_freight_cost">Freight Cost ($):</label>
                        <input type="number" id="move_freight_cost" name="freight_cost" step="0.01" min="0">
                    </div>
                    <div>
                        <label for="move_customer_cost">Customer Cost ($):</label>
                        <input type="number" id="move_customer_cost" name="customer_cost" step="0.01" min="0">
                    </div>
                </div>
                
                <!-- Origin and Destination Section -->
                <div class="origin-destination-section">
                    <div class="location-container" style="display: flex; align-items: flex-start; gap: 20px;">
                        <div class="origin-section" style="flex: 1;">
                            <label style="margin-bottom: 10px; display:block; font-weight: 600;">Origin:</label>
                            <div style="padding: 12px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; min-height: 45px; display: flex; align-items: center;">
                                <strong><?php echo htmlspecialchars($warehouse['name']); ?></strong>
                            </div>
                        </div>
                        
                        <div class="distance-separator" style="display: flex; flex-direction: column; justify-content: center; align-items: center; margin-top: 35px;">
                            <div style="font-size: 1.8em; color: #488C9A; margin-bottom: 5px;">→</div>
                            <div id="drayageDistanceDisplay" style="text-align: center; font-weight: bold; color: #488C9A; white-space: nowrap; font-size: 0.85em;">
                                <!-- Distance will be calculated and displayed here -->
                            </div>
                        </div>
                        
                        <div class="destination-section" style="flex: 1;">
                            <label style="margin-bottom: 10px; display:block; font-weight: 600;">Destination:</label>
                            
                            <div class="destination-radio-group" style="display: flex; gap: 15px; margin-bottom: 10px;">
                                <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                    <input type="radio" name="destination_type" value="project" checked onchange="updateMoveDestinations()" style="margin-right: 5px;"> Project
                                </label>
                                <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                    <input type="radio" name="destination_type" value="warehouse" onchange="updateMoveDestinations()" style="margin-right: 5px;"> Warehouse
                                </label>
                            </div>
                            
                            <select id="move_destination_id" name="destination_id" required onchange="calculateDrayageMiles()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">-- Select Destination --</option>
                            </select>
                        </div>
                    </div>
                    <input type="hidden" id="move_miles" name="miles" value="">
                </div>
                
                <!-- Generate BOL Checkbox -->
                <div style="margin-top: 15px; margin-bottom: 20px; padding: 10px; background-color: #f8f9fa; border-radius: 4px; border: 1px solid #e9ecef;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" id="generate_bol_drayage" name="generate_bol" value="1" style="margin: 0;">
                        <span>Generate Bill of Lading (BOL) after creating delivery</span>
                    </label>
                    <small style="color: #6c757d; margin-left: 20px; display: block; margin-top: 3px;">
                        Check this to immediately create a BOL document for this shipment
                    </small>
                </div>
                
                <!-- Hidden fields for container data that will be populated by JavaScript -->
                <input type="hidden" name="action" value="ship_pallets">
                <input type="hidden" name="origin_type" value="warehouse">
                <input type="hidden" name="origin_id" value="<?php echo $warehouse_id; ?>">
                <input type="hidden" id="container_ids_input" name="drayage_container_ids" value="">
                <input type="hidden" id="pallet_ids_container" name="selected_pallets" value="">
                <input type="hidden" id="bol_number_input" name="bol_number" value="">
                <input type="hidden" id="container_number_input" name="container_number" value="">
                
                <button type="submit" id="confirmMoveContainerBtn" class="action-button" style="margin-top:15px;">
                    Create Drayage Shipment
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// Projects & Warehouses for the Move Pallets dropdown (match create_shipment.php variable names)
const projectsData = <?php echo json_encode($all_projects); ?>;
const warehousesData = <?php echo json_encode($other_warehouses); ?>;
// Keep old names for backward compatibility
const allProjectsData = projectsData;
const otherWarehousesData = warehousesData;

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
    if (view === 'byTruckload') {
        document.getElementById('transitByTruckloadView').classList.add('active');
        document.querySelector('#inboundTransitContent .sub-tab-button:first-child').classList.add('active');
    } else {
        document.getElementById('transitByPalletView').classList.add('active');
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
function toggleAllTruckloads() {
    const selectAllCheckbox = document.getElementById('selectAllTruckloads');
    const truckloadCheckboxes = document.querySelectorAll('.truckload-checkbox');
    
    truckloadCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    updateReceiveTruckloadButton();
}

function updateReceiveTruckloadButton() {
    const receiveBtn = document.getElementById('receiveTruckloadBtn');
    if (!receiveBtn) return;
    
    const selectedCheckboxes = document.querySelectorAll('.truckload-checkbox:checked');
    const hasSelections = selectedCheckboxes.length > 0;
    
    receiveBtn.disabled = !hasSelections;
    
    // Update button text based on selection count and facility type
    <?php if ($is_port): ?>
        // Port-specific button text
        if (hasSelections) {
            receiveBtn.textContent = selectedCheckboxes.length === 1 ? 
                'Receive Container(s)' : 
                `Receive Container(s) (${selectedCheckboxes.length})`;
        } else {
            receiveBtn.textContent = 'Receive Container(s)';
            closeReceiveTruckloadModal();
        }
    <?php else: ?>
        // Warehouse-specific button text
        if (hasSelections) {
            receiveBtn.textContent = selectedCheckboxes.length === 1 ? 
                'Receive Selected Truckload' : 
                `Receive Selected Truckloads (${selectedCheckboxes.length})`;
        } else {
            receiveBtn.textContent = 'Receive Selected Truckloads';
            closeReceiveTruckloadModal();
        }
    <?php endif; ?>
    
    // Update select all checkbox state
    const selectAllCheckbox = document.getElementById('selectAllTruckloads');
    const allCheckboxes = document.querySelectorAll('.truckload-checkbox');
    if (allCheckboxes.length > 0) {
        selectAllCheckbox.checked = selectedCheckboxes.length === allCheckboxes.length;
        selectAllCheckbox.indeterminate = selectedCheckboxes.length > 0 && selectedCheckboxes.length < allCheckboxes.length;
    }
}

// ========== FILTER FUNCTIONS ==========
function filterStoredTable() {
    const textFilter = document.getElementById('storedSearch').value.toLowerCase();
    const wattageFilter = document.getElementById('storedWattageFilter').value;
    const rows = document.querySelectorAll('#storedTable tbody tr');

    rows.forEach(row => {
        let show = true;
        let rowText = '';
        for (let i = 0; i < row.cells.length; i++) {
            rowText += row.cells[i].textContent.toLowerCase() + ' ';
        }
        if (textFilter && !rowText.includes(textFilter)) show = false;
        let wattageText = row.cells[2]?.textContent.replace('W','').trim() || '';
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
        for (let i = 0; i < row.cells.length; i++) {
            rowText += row.cells[i].textContent.toLowerCase() + ' ';
        }
        if (textFilter && !rowText.includes(textFilter)) show = false;
        let wattageText = row.cells[2]?.textContent.replace('W','').trim() || '';
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

function openReceiveTruckloadModal() {
    const btn = document.getElementById('receiveTruckloadBtn');
    if (btn?.disabled) return;
    
    const selectedCheckboxes = document.querySelectorAll('.truckload-checkbox:checked');
    if (selectedCheckboxes.length === 0) return;
    
    const modalContent = receiveTruckloadModal.querySelector('.modal-content');
    const header = modalContent.querySelector('.modal-header');
    if (header && receiveTruckloadFormContainer) {
        modalContent.insertBefore(receiveTruckloadFormContainer, header.nextSibling);
    }
    receiveTruckloadFormContainer.style.display = 'block';
    
    // Clear fields and set date AFTER the form is visible and DOM is ready
    setTimeout(() => {
        // Clear previous values based on facility type
        <?php if ($is_port): ?>
            // For ports: clear House and Master BOL fields
            const houseBolField = document.getElementById('house_bol');
            const masterBolField = document.getElementById('master_bol');
            if (houseBolField) houseBolField.value = '';
            if (masterBolField) masterBolField.value = '';
        <?php else: ?>
            // For warehouses: clear regular BOL field
            const bolField = document.getElementById('receive_truckload_bol');
            if (bolField) bolField.value = '';
        <?php endif; ?>
        
        // Set date based on facility type
        const arrivalDateField = document.getElementById('actual_truckload_arrival_date');
        if (arrivalDateField) {
            <?php if ($is_port): ?>
                // For ports: try to use anticipated delivery date from selected truckload
                let defaultDate = '';
                const firstSelectedCheckbox = selectedCheckboxes[0];
                if (firstSelectedCheckbox) {
                    const selectedRow = firstSelectedCheckbox.closest('tr');
                    // Find the Est. Arrival Date column (should be the 5th visible column, account for checkbox)
                    const estArrivalCellIndex = 4; // Est. Arrival Date column index
                    const estArrivalCell = selectedRow ? selectedRow.cells[estArrivalCellIndex] : null;
                    if (estArrivalCell && estArrivalCell.textContent.trim() !== 'N/A' && estArrivalCell.textContent.trim() !== '') {
                        defaultDate = estArrivalCell.textContent.trim();
                    }
                }
                
                // If no anticipated date found, use today's date
                if (!defaultDate) {
                    const today = new Date();
                    defaultDate = today.getFullYear() + '-' + 
                                 String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                                 String(today.getDate()).padStart(2, '0');
                } else {
                    // Convert from display format (if needed) to YYYY-MM-DD
                    try {
                        const date = new Date(defaultDate);
                        if (!isNaN(date.getTime())) {
                            defaultDate = date.getFullYear() + '-' + 
                                         String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                                         String(date.getDate()).padStart(2, '0');
                        }
                    } catch (e) {
                        // If date parsing fails, use today
                        const today = new Date();
                        defaultDate = today.getFullYear() + '-' + 
                                     String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                                     String(today.getDate()).padStart(2, '0');
                    }
                }
                arrivalDateField.value = defaultDate;
            <?php else: ?>
                // For warehouses: use today's date
                const today = new Date();
                const todayString = today.getFullYear() + '-' + 
                                   String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                                   String(today.getDate()).padStart(2, '0');
                arrivalDateField.value = todayString;
            <?php endif; ?>
        }
    }, 100);
    
    receiveTruckloadModal.style.display = 'block';
}

function closeReceiveTruckloadModal() {
    if (receiveTruckloadModal.style.display === 'block') {
        receiveTruckloadOriginalParent?.appendChild(receiveTruckloadFormContainer);
        receiveTruckloadFormContainer.style.display = 'none';
        receiveTruckloadModal.style.display = 'none';
    }
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

    // Filter event listeners
    document.getElementById('storedSearch')?.addEventListener('keyup', filterStoredTable);
    document.getElementById('storedWattageFilter')?.addEventListener('change', filterStoredTable);
    document.getElementById('transitSearch')?.addEventListener('keyup', filterTransitTable);
    document.getElementById('transitWattageFilter')?.addEventListener('change', filterTransitTable);

    // Button event listeners
    document.getElementById('receiveTruckloadBtn')?.addEventListener('click', openReceiveTruckloadModal);

    // Modal close event listeners
    document.querySelector('#receiveTruckloadModal .close-receive-truckload-modal')?.addEventListener('click', closeReceiveTruckloadModal);

    // Click outside modal to close
    window.addEventListener('click', (e) => {
        if (e.target === receiveTruckloadModal) closeReceiveTruckloadModal();
    });

    // Form submission handlers
    document.getElementById('confirmReceiveTruckloadBtn')?.addEventListener('click', () => {
        const selectedCheckboxes = document.querySelectorAll('.truckload-checkbox:checked');
        if (selectedCheckboxes.length === 0) {
            alert('Please select at least one truckload to receive.');
            return;
        }

        // Create form to handle multiple truckload receiving with file upload
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'handle_pallet_arrival.php';
        form.enctype = 'multipart/form-data';

        const warehouseInput = document.createElement('input');
        warehouseInput.type = 'hidden';
        warehouseInput.name = 'warehouse_id';
        warehouseInput.value = '<?php echo $warehouse_id; ?>';
        form.appendChild(warehouseInput);

        // Add delivery IDs as array
        selectedCheckboxes.forEach((checkbox, index) => {
            const deliveryInput = document.createElement('input');
            deliveryInput.type = 'hidden';
            deliveryInput.name = `delivery_ids[${index}]`;
            deliveryInput.value = checkbox.value;
            form.appendChild(deliveryInput);
        });

        const bolInput = document.createElement('input');
        bolInput.type = 'hidden';
        bolInput.name = 'bol_number_override';
        <?php if ($is_port): ?>
            // For ports, get House BOL (Master BOL could be added if needed)
            const houseBolField = document.getElementById('house_bol');
            bolInput.value = houseBolField ? houseBolField.value : '';
        <?php else: ?>
            // For warehouses, get regular BOL field
            const bolField = document.getElementById('receive_truckload_bol');
            bolInput.value = bolField ? bolField.value : '';
        <?php endif; ?>
        form.appendChild(bolInput);

        <?php if ($is_port): ?>
            // For ports, also add Master BOL if available
            const masterBolInput = document.createElement('input');
            masterBolInput.type = 'hidden';
            masterBolInput.name = 'master_bol';
            const masterBolField = document.getElementById('master_bol');
            masterBolInput.value = masterBolField ? masterBolField.value : '';
            form.appendChild(masterBolInput);
        <?php endif; ?>

        const dateInput = document.createElement('input');
        dateInput.type = 'hidden';
        dateInput.name = 'actual_arrival_date';
        const dateField = document.getElementById('actual_truckload_arrival_date');
        dateInput.value = dateField ? dateField.value : '';
        form.appendChild(dateInput);

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'receive_multiple_truckloads';
        form.appendChild(actionInput);

        // Handle file upload if a file is selected (warehouses only)
        <?php if (!$is_port): ?>
            const fileInput = document.getElementById('pod_file');
            if (fileInput && fileInput.files.length > 0) {
                // Clone the file input to preserve the file
                const clonedFileInput = fileInput.cloneNode(true);
                form.appendChild(clonedFileInput);
            }
        <?php endif; ?>

        document.body.appendChild(form);
        form.submit();
    });

    // Initial states
    updateReceiveTruckloadButton();
});

// Toggle inbound delivery details
function toggleInboundDetails(index) {
    try {
        var detailRows = document.querySelectorAll('[id^="inbound-detail-' + index + '-"]');
        var mainRow = document.querySelector('[data-delivery-index="' + index + '"]');
        var expandIcon = document.getElementById('expand-icon-' + index);
        
        if (!mainRow) {
            console.error('Main row not found for index:', index);
            return;
        }
        
        // Check if any detail row is currently visible
        var isExpanded = false;
        detailRows.forEach(function(row) {
            if (row.style.display !== 'none') {
                isExpanded = true;
            }
        });
        
        if (isExpanded) {
            // Collapse
            detailRows.forEach(function(row) {
                row.style.display = 'none';
            });
            mainRow.classList.remove('expanded');
            if (expandIcon) expandIcon.textContent = '▶';
        } else {
            // Expand
            detailRows.forEach(function(row) {
                row.style.display = 'table-row';
            });
            mainRow.classList.add('expanded');
            if (expandIcon) expandIcon.textContent = '▼';
        }
    } catch (error) {
        console.error('Error toggling inbound details:', error);
    }
}

// Toggle outbound delivery details
function toggleOutboundDetails(index) {
    try {
        var detailRows = document.querySelectorAll('[id^="outbound-detail-' + index + '-"]');
        var mainRow = document.querySelector('[data-delivery-index="' + index + '"]');
        var expandIcon = document.getElementById('outbound-expand-icon-' + index);
        
        if (!mainRow) {
            console.error('Main row not found for index:', index);
            return;
        }
        
        // Check if any detail row is currently visible
        var isExpanded = false;
        detailRows.forEach(function(row) {
            if (row.style.display !== 'none') {
                isExpanded = true;
            }
        });
        
        if (isExpanded) {
            // Collapse
            detailRows.forEach(function(row) {
                row.style.display = 'none';
            });
            mainRow.classList.remove('expanded');
            if (expandIcon) expandIcon.textContent = '▶';
        } else {
            // Expand
            detailRows.forEach(function(row) {
                row.style.display = 'table-row';
            });
            mainRow.classList.add('expanded');
            if (expandIcon) expandIcon.textContent = '▼';
        }
    } catch (error) {
        console.error('Error toggling outbound details:', error);
    }
}

// ========== 🚢 PORT CONTAINER MANAGEMENT ==========

// Toggle all containers selection
function toggleAllContainers() {
    const selectAllCheckbox = document.getElementById('selectAllContainers');
    const containerCheckboxes = document.querySelectorAll('.container-checkbox');
    
    containerCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    toggleMoveContainerBtn();
}

// Enable/disable Move Container button based on selection
function toggleMoveContainerBtn() {
    const moveBtn = document.getElementById('moveContainerBtn');
    if (!moveBtn) return;
    
    const checkedContainers = document.querySelectorAll('.container-checkbox:checked');
    moveBtn.disabled = checkedContainers.length === 0;
    
    // Update "Select All" checkbox state
    const selectAllCheckbox = document.getElementById('selectAllContainers');
    const allCheckboxes = document.querySelectorAll('.container-checkbox');
    if (selectAllCheckbox && allCheckboxes.length > 0) {
        const checkedCount = checkedContainers.length;
        selectAllCheckbox.checked = checkedCount === allCheckboxes.length;
        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
    }
}

// Handle Move Container button click
document.addEventListener('DOMContentLoaded', function() {
    const moveContainerBtn = document.getElementById('moveContainerBtn');
    if (moveContainerBtn) {
        moveContainerBtn.addEventListener('click', function() {
            const checkedContainers = document.querySelectorAll('.container-checkbox:checked');
            if (checkedContainers.length === 0) {
                alert('Please select at least one container to move.');
                return;
            }
            
            // Collect selected container IDs
            const containerIds = Array.from(checkedContainers).map(cb => cb.value);
            console.log('Moving containers:', containerIds);
            
            // Open Move Container modal
            openMoveContainerModal(containerIds);
        });
    }
});

// ========== 🚢 MOVE CONTAINER MODAL FUNCTIONS ==========

function openMoveContainerModal(containerIds) {
    const modal = document.getElementById('moveContainerModal');
    if (!modal) return;
    
    // Store selected container IDs for submission
    modal.dataset.containerIds = JSON.stringify(containerIds);
    
    // 📅 GET ARRIVAL DATE FROM SELECTED CONTAINER
    let containerArrivalDate = null;
    if (containerIds.length > 0) {
        // Find the container row to get its arrival date
        const firstContainerId = containerIds[0];
        const containerRows = document.querySelectorAll('.container-checkbox');
        
        for (let checkbox of containerRows) {
            if (checkbox.value === firstContainerId && checkbox.checked) {
                const row = checkbox.closest('tr');
                if (row) {
                    // Get arrival date from the last column (adjust index if needed)
                    const arrivalDateCell = row.cells[row.cells.length - 1];
                    if (arrivalDateCell) {
                        const arrivalText = arrivalDateCell.textContent.trim();
                        // Convert from MM-DD-YYYY to YYYY-MM-DD format
                        try {
                            const dateParts = arrivalText.split('-');
                            if (dateParts.length === 3) {
                                containerArrivalDate = `${dateParts[2]}-${dateParts[0].padStart(2, '0')}-${dateParts[1].padStart(2, '0')}`;
                            }
                        } catch (e) {
                            console.log('Could not parse arrival date:', arrivalText);
                        }
                    }
                }
                break;
            }
        }
    }
    
    // Set departure date to container arrival date, fallback to today
    const departureField = document.getElementById('move_departure_date');
    if (departureField) {
        if (containerArrivalDate) {
            departureField.value = containerArrivalDate;
        } else {
            const today = new Date();
            const todayString = today.getFullYear() + '-' + 
                               String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                               String(today.getDate()).padStart(2, '0');
            departureField.value = todayString;
        }
    }
    
    // Set estimated arrival (departure + 1 day)
    const arrivalField = document.getElementById('move_est_arrival_date');
    if (arrivalField && departureField) {
        try {
            const departureDate = new Date(departureField.value);
            departureDate.setDate(departureDate.getDate() + 1);
            const arrivalString = departureDate.getFullYear() + '-' + 
                                 String(departureDate.getMonth() + 1).padStart(2, '0') + '-' + 
                                 String(departureDate.getDate()).padStart(2, '0');
            arrivalField.value = arrivalString;
        } catch (e) {
            console.log('Could not set arrival date');
        }
    }
    
    // Reset form
    const projectRadio = document.querySelector('input[name="destination_type"][value="project"]');
    if (projectRadio) projectRadio.checked = true;
    
    document.getElementById('move_destination_id').innerHTML = '<option value="">-- Select Destination --</option>';
    document.getElementById('move_miles').value = '';
    document.getElementById('move_freight_cost').value = '';
    document.getElementById('move_customer_cost').value = '';
    
    // Clear distance display
    const distanceDisplay = document.getElementById('drayageDistanceDisplay');
    if (distanceDisplay) distanceDisplay.innerHTML = '';
    
    // Clear Generate BOL checkbox
    const generateBolCheckbox = document.getElementById('generate_bol_drayage');
    if (generateBolCheckbox) generateBolCheckbox.checked = false;
    
    // Debug data availability when modal opens
    console.log('Modal opening - checking data availability:');
    console.log('projectsData:', projectsData);
    console.log('warehousesData:', warehousesData);
    
    // Populate initial destinations (projects by default)
    console.log('Calling updateMoveDestinations from modal open');
    updateMoveDestinations();
    
    modal.style.display = 'block';
}

function closeMoveContainerModal() {
    const modal = document.getElementById('moveContainerModal');
    if (modal) {
        modal.style.display = 'none';
        delete modal.dataset.containerIds;
    }
}

// Populate dropdown function similar to create_shipment.php
function populateDropdownMove(selectElement, type, dataSource, nameField, placeholderPrefix) {
    if (!selectElement) return;
    
    selectElement.innerHTML = '';

    if (!dataSource || dataSource.length === 0) {
        selectElement.innerHTML = `<option value="">No ${placeholderPrefix.toLowerCase()} found</option>`;
        selectElement.disabled = true;
    } else {
        selectElement.disabled = false;
        const placeholder = `-- Select ${placeholderPrefix} --`;
        selectElement.innerHTML = `<option value="">${placeholder}</option>`;
        
        // Filter out current warehouse for warehouse destinations
        const filteredData = type === 'warehouse' ? 
            dataSource.filter(item => item.id != <?php echo $warehouse_id; ?>) : 
            dataSource;
        
        filteredData.forEach(function(item) {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item[nameField];
            opt.setAttribute('data-address', item.full_address || '');
            selectElement.appendChild(opt);
        });
    }
}

// ----------------- GOOGLE MAPS DISTANCE CALCULATION FUNCTIONS -----------------
let directionsService;
let initialized = false;

function initializeGoogleMaps() {
    if (initialized || !window.google) return;
    directionsService = new google.maps.DirectionsService();
    initialized = true;
}

function calculateDistanceFromAddresses(originAddress, destinationAddress, callback) {
    if (!initialized) {
        initializeGoogleMaps();
    }
    
    if (!directionsService || !originAddress || !destinationAddress) {
        callback(null, 'Missing address information');
        return;
    }
    
    if (originAddress.toLowerCase() === destinationAddress.toLowerCase()) {
        callback(null, 'Origin and destination cannot be the same');
        return;
    }

    const request = {
            origin: originAddress,
            destination: destinationAddress,
        travelMode: 'DRIVING'
    };

    directionsService.route(request, function(result, status) {
            if (status === 'OK') {
            const distanceInMeters = result.routes[0].legs[0].distance.value;
            const distanceInMiles = (distanceInMeters / 1609.34).toFixed(2);
            callback(distanceInMiles, null);
        } else {
            callback(null, 'Could not calculate distance');
        }
    });
}

function getAddressFromSelection(selectElement, type) {
    if (!selectElement || !selectElement.value) return '';
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    return selectedOption ? selectedOption.getAttribute('data-address') : '';
}

// Drayage distance calculation function
function calculateDrayageMiles() {
    const destSelect = document.getElementById('move_destination_id');
    const distanceDisplay = document.getElementById('drayageDistanceDisplay');
    const milesField = document.getElementById('move_miles');
    
    if (!destSelect || !distanceDisplay || !milesField) {
        console.log('Missing elements for distance calculation');
        return;
    }
    
    const destinationAddress = getAddressFromSelection(destSelect, 'destination');
    const originAddress = '<?php echo addslashes($warehouse['street_address'] . ', ' . $warehouse['city'] . ', ' . $warehouse['state'] . ' ' . $warehouse['zip_code']); ?>';
    
    if (!originAddress || !destinationAddress) {
        distanceDisplay.innerHTML = '';
        milesField.value = '';
        return;
    }
    
    distanceDisplay.innerHTML = '<span style="color: #666;">Calculating...</span>';
    
    calculateDistanceFromAddresses(originAddress, destinationAddress, function(distance, error) {
        if (error) {
            if (error.includes('cannot be the same')) {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">⚠️ Same location</span>';
            } else {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">Error calculating distance</span>';
            }
            milesField.value = '';
            console.error('Distance calculation error:', error);
    } else {
            distanceDisplay.innerHTML = `<span style="color: #488C9A; font-weight: bold;">${distance} miles</span>`;
            milesField.value = distance;
        }
    });
}

// Make sure this function is in global scope for HTML onchange handlers
window.updateMoveDestinations = function() {
    const selectedTypeRadio = document.querySelector('#moveContainerModal input[name="destination_type"]:checked');
    const destSelect = document.getElementById('move_destination_id');
    
    if (!selectedTypeRadio || !destSelect) {
        return;
    }
    
    const destType = selectedTypeRadio.value;
    
    // Clear distance display when destination type changes
    const distanceDisplay = document.getElementById('drayageDistanceDisplay');
    const milesField = document.getElementById('move_miles');
    if (distanceDisplay) distanceDisplay.innerHTML = '';
        if (milesField) milesField.value = '';
    
    // Use EXACT same approach as create_shipment.php toggleDestinationSelectSingle()
    const data = (destType === 'project') ? projectsData : warehousesData;
    const nameField = (destType === 'project') ? 'project_name' : 'name';
    const placeholder = (destType === 'project') ? 'Project' : 'Warehouse';

    populateDropdownMove(destSelect, destType, data, nameField, placeholder);
    calculateDrayageMiles();
}

// Also create the function without window prefix for backward compatibility
function updateMoveDestinations() {
    window.updateMoveDestinations();
}



// Manual distance update function for fallback
function updateManualDistance(value) {
    const milesField = document.getElementById('move_miles');
    if (milesField) {
        milesField.value = value;
    }
}

// Move Container modal event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Google Maps when DOM is ready
    if (window.google) {
        initializeGoogleMaps();
    } else {
        // Wait for Google Maps to load
        window.addEventListener('load', initializeGoogleMaps);
    }
    
    const closeBtn = document.querySelector('#moveContainerModal .close-move-container-modal');
    if (closeBtn) {
        closeBtn.addEventListener('click', closeMoveContainerModal);
    }
    
    // Remove JavaScript event listeners since we're using onchange in HTML
    // This prevents conflicts with the onchange handlers in the HTML
    
    // Check for and display success message from container move
    const moveSuccessMsg = sessionStorage.getItem('move_container_success');
    if (moveSuccessMsg) {
        // Create and display success message
        const successDiv = document.createElement('div');
        successDiv.className = 'success-message';
        successDiv.innerHTML = '<strong>' + moveSuccessMsg + '</strong>';
        
        // Insert after any existing success/error messages
        const main = document.querySelector('main');
        const existingMessages = main.querySelector('.success-message, .error-message');
        if (existingMessages) {
            existingMessages.parentNode.insertBefore(successDiv, existingMessages.nextSibling);
        } else {
            const firstChild = main.firstElementChild;
            main.insertBefore(successDiv, firstChild);
        }
        
        // Clear the stored message
        sessionStorage.removeItem('move_container_success');
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(e) {
        const modal = document.getElementById('moveContainerModal');
        if (e.target === modal) closeMoveContainerModal();
    });
    
    // Handle Move Container form submission - populate hidden fields before form submits
    const form = document.getElementById('moveContainerForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault(); // Temporarily prevent submission to populate hidden fields
            
            const modal = document.getElementById('moveContainerModal');
            const containerIds = JSON.parse(modal.dataset.containerIds || '[]');
            
            if (containerIds.length === 0) {
                alert('No containers selected.');
                return;
            }
            
            // Get container number from first checked container for BOL naming
            let containerNumber = '';
            const checkedContainers = document.querySelectorAll('.container-checkbox:checked');
            
            if (checkedContainers.length > 0) {
                const firstCheckedRow = checkedContainers[0].closest('tr');
                if (firstCheckedRow) {
                    const containerCell = firstCheckedRow.cells[1]; // Container Number column (index 1)
                    if (containerCell) {
                        containerNumber = containerCell.textContent.trim();
                    }
                }
            }
            
            if (!containerNumber) {
                alert('Error: Could not determine container number. Please try again.');
                return;
            }
            
            // Set BOL number as container number + drayage suffix
            const drayageBolNumber = containerNumber + '-DRAY';
            
            // Populate hidden fields with container data
            document.getElementById('container_ids_input').value = JSON.stringify(containerIds);
            document.getElementById('bol_number_input').value = drayageBolNumber;
            document.getElementById('container_number_input').value = containerNumber;
            
            // Get pallet IDs and then submit the form
            fetch('get_container_pallets.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    delivery_ids: containerIds
                })
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Failed to get pallet IDs');
                }
                
                // Populate pallet IDs in proper format for create_shipment.php
                const palletContainer = document.getElementById('pallet_ids_container');
                data.pallet_ids.forEach((palletId, index) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `selected_pallets[${index}]`;
                    input.value = palletId;
                    form.appendChild(input);
                });
                
                // Now submit the form normally - server handles everything including BOL redirect
                form.submit();
            })
            .catch(error => {
                console.error('Error getting pallet IDs:', error);
                alert('Error: ' + error.message);
            });
        });
    }
});
</script>
</body>
</html>
