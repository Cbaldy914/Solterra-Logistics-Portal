<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
require_once 'document_helpers.php';
$conn = getDBConnection();
$google_maps_api_key = getGoogleMapsApiKey();

$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$account_id = null;

if ($role !== 'global_admin') {
    $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
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

// Get and validate warehouse ID
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
if ($warehouse_id <= 0) {
    die("Invalid Warehouse ID provided.");
}

if ($role !== 'global_admin') {
    $stmtAccess = $conn->prepare("SELECT id FROM warehouses WHERE id = ? AND account_id = ?");
    if ($stmtAccess) {
        $stmtAccess->bind_param("ii", $warehouse_id, $account_id);
        $stmtAccess->execute();
        $accessResult = $stmtAccess->get_result();
        $stmtAccess->close();
        if ($accessResult->num_rows === 0) {
            header("Location: unauthorized.php");
            exit();
        }
    }
}

$warehouse = null;
$pallets_in_storage = [];
$pallets_in_transit = [];
$port_cleared_pallets = [];
$port_customs_hold_pallets = [];
$errorMessage = '';
$successMessage = $_SESSION['move_pallet_message'] ?? '';
if (isset($_SESSION['move_pallet_message'])) unset($_SESSION['move_pallet_message']);
$customs_hold_next_step = $_SESSION['customs_hold_next_step'] ?? null;
if (isset($_SESSION['customs_hold_next_step'])) unset($_SESSION['customs_hold_next_step']);
$customs_action_result = $_SESSION['customs_action_result'] ?? null;
if (isset($_SESSION['customs_action_result'])) unset($_SESSION['customs_action_result']);
$show_customs_next_step_banner = false;

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

                // Count pallets on customs hold for this container
                $stmtHold = $conn->prepare("
                    SELECT COUNT(ip.id) as hold_count, COALESCE(SUM(ip.quantity), 0) as hold_modules
                    FROM inventory_pallets ip
                    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                    WHERE dp.delivery_id = ? AND ip.status = 'Customs Hold'
                ");
                $container['hold_pallets'] = 0;
                $container['hold_modules'] = 0;
                if ($stmtHold) {
                    $stmtHold->bind_param("i", $container['delivery_id']);
                    $stmtHold->execute();
                    $stmtHold->bind_result($hold_count, $hold_modules);
                    if ($stmtHold->fetch()) {
                        $container['hold_pallets'] = (int)$hold_count;
                        $container['hold_modules'] = (int)$hold_modules;
                    }
                    $stmtHold->close();
                }

                $containers_cleared[] = $container;
            }
            $stmtContainers->close();
        }
    
    return $containers_cleared;
}

/**
 * PORT-SPECIFIC: Fetch individual pallets by status for customs workflow.
 *
 * @param mysqli $conn Database connection
 * @param int $warehouse_id Port ID
 * @param string $status Pallet status to return (e.g., Cleared Customs, Customs Hold)
 * @return array
 */
function fetchPortPalletsByStatus($conn, $warehouse_id, $status) {
    $rows = [];

    $stmt = $conn->prepare("
        SELECT
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            ip.arrival_date,
            COALESCE(ip.customs_hold_cost, 0) AS customs_hold_cost,
            ip.customs_hold_cost_notes,
            ip.customs_hold_cost_updated_at,
            COALESCE(NULLIF(TRIM(d.bol_number), ''), 'N/A') AS container_number,
            COALESCE(p.project_name, 'N/A') AS project_name,
            COALESCE(m.vendor_name, 'N/A') AS origin_vendor
        FROM inventory_pallets ip
        LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
        LEFT JOIN deliveries d ON dp.delivery_id = d.id
        LEFT JOIN projects p ON ip.assigned_project_id = p.id
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE ip.current_warehouse_id = ?
          AND ip.status = ?
        ORDER BY d.bol_number ASC, ip.arrival_date DESC, ip.id DESC
    ");

    if (!$stmt) {
        return $rows;
    }

    $stmt->bind_param("is", $warehouse_id, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
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
            GROUP_CONCAT(DISTINCT d.project_id ORDER BY d.project_id SEPARATOR ',') AS project_ids,
            CASE WHEN COUNT(DISTINCT d.wattage) > 1 THEN 1 ELSE 0 END AS is_mixed_wattage,
            (SELECT COUNT(*) FROM project_documents pd 
             WHERE pd.delivery_id IN (SELECT d_inner.id FROM deliveries d_inner 
                                     WHERE d_inner.bol_number = d.bol_number 
                                     AND d_inner.warehouse_id = d.warehouse_id 
                                     AND d_inner.warehouse_arrival_date = d.warehouse_arrival_date)
             AND pd.document_type = 'pods' 
             AND pd.document_sub_type = 'Warehouse POD') AS has_warehouse_pod
        FROM deliveries d
        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE d.warehouse_id = ? 
        AND d.status_of_delivery IN ('Delivered to Warehouse', 'Cleared Customs', 'Departed Port')
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
        WHERE (d.warehouse_id = ? OR (d.origin_type = 'warehouse' AND d.origin_id = ?))
        AND d.left_warehouse_date IS NOT NULL
        GROUP BY d.bol_number, d.supplier, d.left_warehouse_date, d.anticipated_delivery_date, d.status_of_delivery
        ORDER BY d.left_warehouse_date DESC
    ");
    
    if ($stmtOutboundHistory) {
        $stmtOutboundHistory->bind_param("iii", $warehouse_id, $warehouse_id, $warehouse_id);
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
// HANDLE WAREHOUSE POD UPLOAD REQUESTS
// ===========================================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_warehouse_pod') {
    header('Content-Type: application/json');
    
    try {
        $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        $description = trim($_POST['description'] ?? '');
        
        if ($delivery_id <= 0 || $project_id <= 0) {
            throw new Exception("Invalid delivery or project ID");
        }
        
        if (!isset($_FILES['warehouse_pod_file']) || $_FILES['warehouse_pod_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please select a valid file to upload");
        }
        
        // Prepare file data for upload
        $file_data = [
            'name' => $_FILES['warehouse_pod_file']['name'],
            'type' => $_FILES['warehouse_pod_file']['type'],
            'tmp_name' => $_FILES['warehouse_pod_file']['tmp_name'],
            'error' => $_FILES['warehouse_pod_file']['error'],
            'size' => $_FILES['warehouse_pod_file']['size']
        ];
        
        // Upload using new document system
        $processed_file = processDocumentUpload($file_data, 'pods');
        
        // Prepare document data
        $document_data = [
            'project_id' => $project_id,
            'document_type' => 'pods',
            'document_sub_type' => 'Warehouse POD',
            'delivery_id' => $delivery_id,
            'warehouse_id' => $warehouse_id,
            'original_name' => $processed_file['original_name'],
            'file_size' => $processed_file['size'],
            'mime_type' => $processed_file['mime_type'],
            'uploaded_by' => $_SESSION['user_id'],
            'tmp_name' => $processed_file['tmp_name'],
            'description' => $description,
            'entity_context' => "Warehouse POD for delivery ID: $delivery_id"
        ];
        
        // Save to project_documents table
        $result = saveDocumentToProjectDocuments($conn, $document_data);
        
        echo json_encode(['success' => true, 'message' => 'Warehouse POD uploaded successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit();
}

// ===========================================================================================
// PORT CUSTOMS HOLD ACTIONS
// ===========================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['place_customs_hold', 'release_customs_hold'], true)) {
    $redirect_tab = 'customsHold';
    $redirect_url = "manage_warehouse_inventory.php?warehouse_id={$warehouse_id}&tab={$redirect_tab}";
    if (!empty($_GET['project_id'])) {
        $redirect_url .= '&project_id=' . urlencode((string)$_GET['project_id']);
    }

    try {
        $action = $_POST['action'];
        $selected = isset($_POST['customs_pallet_ids']) && is_array($_POST['customs_pallet_ids']) ? $_POST['customs_pallet_ids'] : [];
        $pallet_ids = array_values(array_filter(array_map('intval', $selected), static function ($v) {
            return $v > 0;
        }));

        if (empty($pallet_ids)) {
            throw new Exception('Select at least one pallet.');
        }

        $stmtPort = $conn->prepare("SELECT is_port FROM warehouses WHERE id = ? LIMIT 1");
        if (!$stmtPort) {
            throw new Exception('Unable to validate facility type.');
        }
        $stmtPort->bind_param("i", $warehouse_id);
        $stmtPort->execute();
        $stmtPort->bind_result($port_flag);
        $is_port_action = false;
        if ($stmtPort->fetch()) {
            $is_port_action = ((int)$port_flag === 1);
        }
        $stmtPort->close();

        if (!$is_port_action) {
            throw new Exception('Customs hold actions are only available for ports.');
        }

        $cost_input = trim((string)($_POST['customs_cost_per_pallet'] ?? '0'));
        $cost_per_pallet = is_numeric($cost_input) ? (float)$cost_input : 0.0;
        if ($cost_per_pallet < 0) {
            throw new Exception('Cost per pallet cannot be negative.');
        }
        $hold_reason = trim((string)($_POST['customs_hold_reason'] ?? ''));
        if ($action === 'place_customs_hold' && $hold_reason === '') {
            throw new Exception('Hold reason is required.');
        }
        if (strlen($hold_reason) > 120) {
            $hold_reason = substr($hold_reason, 0, 120);
        }

        $cost_notes = trim((string)($_POST['customs_cost_notes'] ?? ''));
        if (strlen($cost_notes) > 255) {
            $cost_notes = substr($cost_notes, 0, 255);
        }
        $update_notes = $cost_notes;
        if ($action === 'place_customs_hold' && $hold_reason !== '') {
            $update_notes = $cost_notes !== ''
                ? 'Reason: ' . $hold_reason . ' | Notes: ' . $cost_notes
                : 'Reason: ' . $hold_reason;
            if (strlen($update_notes) > 255) {
                $update_notes = substr($update_notes, 0, 255);
            }
        }

        $new_status = ($action === 'place_customs_hold') ? 'Customs Hold' : 'Cleared Customs';
        $expected_status = ($action === 'place_customs_hold') ? 'Cleared Customs' : 'Customs Hold';
        $id_list = implode(',', $pallet_ids);

        $sql = "
            UPDATE inventory_pallets
            SET status = ?,
                customs_hold_cost = COALESCE(customs_hold_cost, 0) + ?,
                customs_hold_cost_notes = CASE WHEN ? <> '' THEN ? ELSE customs_hold_cost_notes END,
                customs_hold_cost_updated_at = NOW()
            WHERE id IN ($id_list)
              AND current_warehouse_id = ?
              AND status = ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare customs hold update.');
        }

        $stmt->bind_param("sdssis", $new_status, $cost_per_pallet, $update_notes, $update_notes, $warehouse_id, $expected_status);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected <= 0) {
            throw new Exception('No pallets were updated. They may already be in the target status.');
        }

        $successMessage = $action === 'place_customs_hold'
            ? "Placed {$affected} pallet(s) on Customs Hold."
            : "Released {$affected} pallet(s) to Cleared Customs.";
        if ($action === 'place_customs_hold' && $hold_reason !== '') {
            $successMessage .= ' Reason: ' . $hold_reason . '.';
        }
        if ($cost_per_pallet > 0) {
            $successMessage .= ' Added $' . number_format($cost_per_pallet, 2) . ' per pallet.';
        }
        $_SESSION['move_pallet_message'] = $successMessage;

        $verify_rows = [];
        $verify_sql = "
            SELECT
                ip.id,
                ip.pallet_identifier,
                ip.status,
                ip.current_warehouse_id,
                COALESCE(MAX(NULLIF(TRIM(d.bol_number), '')), 'N/A') AS container_number
            FROM inventory_pallets ip
            LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
            LEFT JOIN deliveries d ON dp.delivery_id = d.id
            WHERE ip.id IN ($id_list)
            GROUP BY ip.id, ip.pallet_identifier, ip.status, ip.current_warehouse_id
            ORDER BY ip.pallet_identifier ASC
        ";
        $stmtVerify = $conn->prepare($verify_sql);
        if ($stmtVerify) {
            $stmtVerify->execute();
            $verify_result = $stmtVerify->get_result();
            while ($verify_row = $verify_result->fetch_assoc()) {
                $verify_rows[] = $verify_row;
            }
            $stmtVerify->close();
        }
        $_SESSION['customs_action_result'] = [
            'action' => $action,
            'expected_status' => $new_status,
            'warehouse_id' => (int)$warehouse_id,
            'rows' => $verify_rows
        ];
    } catch (Exception $e) {
        $_SESSION['move_pallet_message'] = 'Error: ' . $e->getMessage();
        unset($_SESSION['customs_action_result']);
    }

    header("Location: {$redirect_url}");
    exit();
}

// ===========================================================================================
// MAIN EXECUTION: FETCH FACILITY DATA AND CONFIGURE INTERFACE
// ===========================================================================================

try {
    // Fetch Warehouse/Port Details
    if ($role === 'global_admin') {
        $stmtW = $conn->prepare("SELECT * FROM warehouses WHERE id = ?");
    } else {
        $stmtW = $conn->prepare("SELECT * FROM warehouses WHERE id = ? AND account_id = ?");
    }
    if (!$stmtW) throw new Exception("Failed to prepare warehouse query: " . $conn->error);
    if ($role === 'global_admin') {
        $stmtW->bind_param("i", $warehouse_id);
    } else {
        $stmtW->bind_param("ii", $warehouse_id, $account_id);
    }
    $stmtW->execute();
    $resultW = $stmtW->get_result();
    if ($resultW->num_rows === 0) {
        throw new Exception("Warehouse not found.");
    }
    $warehouse = $resultW->fetch_assoc();
    $stmtW->close();

    // ===========================================================================================
    // FETCH WAREHOUSE COST ITEMS FROM warehouse_cost_items TABLE
    // ===========================================================================================
    $warehouse_cost_items = [];
    $warehouse_fees = [
        'entry' => 0,
        'exit' => 0,
        'monthly' => 0,
        'customs_clearance' => 0,
        'drayage' => 0,
        'other' => 0,
        'all_items' => []
    ];

    $stmtCosts = $conn->prepare("
        SELECT id, label, amount, trigger_event, unit_type, pallets_per_truck, sqft_per_pallet, display_order
        FROM warehouse_cost_items
        WHERE warehouse_id = ? AND is_active = 1
        ORDER BY display_order ASC, trigger_event ASC
    ");
    if ($stmtCosts) {
        $stmtCosts->bind_param("i", $warehouse_id);
        $stmtCosts->execute();
        $resultCosts = $stmtCosts->get_result();
        while ($cost = $resultCosts->fetch_assoc()) {
            $warehouse_cost_items[] = $cost;
            $warehouse_fees['all_items'][] = $cost;

            // Sum amounts by trigger event type (for per_pallet base rates)
            $trigger = $cost['trigger_event'] ?? 'other';
            if (isset($warehouse_fees[$trigger])) {
                // For per_pallet fees, add the amount directly
                // For other unit types, we'll calculate differently when needed
                if ($cost['unit_type'] === 'per_pallet' || $cost['unit_type'] === '' || $cost['unit_type'] === null) {
                    $warehouse_fees[$trigger] += floatval($cost['amount']);
                }
            }
        }
        $stmtCosts->close();
    }

    // ===========================================================================================
    // FACILITY TYPE DETECTION AND UI CONFIGURATION
    // ===========================================================================================
    $is_port = ($warehouse['is_port'] == 1);

    // Auto-redirect GET requests for ports to the dedicated port page
    if ($is_port && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $qs = $_SERVER['QUERY_STRING'];
        header("Location: manage_port_inventory.php?" . $qs);
        exit();
    }

    if ($is_port) {
        $receivedHintFromMessage = stripos((string)$successMessage, 'Successfully received') !== false;
        $show_customs_next_step_banner = !empty($customs_hold_next_step) || $receivedHintFromMessage;
    }

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
        $port_cleared_pallets = fetchPortPalletsByStatus($conn, $warehouse_id, 'Cleared Customs');
        $port_customs_hold_pallets = fetchPortPalletsByStatus($conn, $warehouse_id, 'Customs Hold');
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
    
    // Monthly cost estimate calculation - using warehouse_fees from warehouse_cost_items
    $total_storage_cost_monthly_rate = $total_pallets * $warehouse_fees['monthly'];

    // Fetch all projects for dropdown options (build full addresses like create_shipment.php)
    $project_clause = $role === 'global_admin' ? '' : ' AND account_id = ?';
    $stmtAllP = $conn->prepare("SELECT id, project_name, street_address, city, state, zip_code FROM projects WHERE (status IS NULL OR status = 'active')$project_clause ORDER BY project_name ASC");
    if ($stmtAllP) {
        if ($role !== 'global_admin') {
            $stmtAllP->bind_param("i", $account_id);
        }
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
    $account_clause = $role === 'global_admin' ? '' : ' AND account_id = ?';
    $stmtOtherW = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM warehouses WHERE (is_port = 0 OR is_port IS NULL) AND id != ?$account_clause ORDER BY name ASC");
    if ($stmtOtherW) {
        if ($role === 'global_admin') {
            $stmtOtherW->bind_param("i", $warehouse_id);
        } else {
            $stmtOtherW->bind_param("ii", $warehouse_id, $account_id);
        }
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places,geometry"></script>

    <style>
        .warehouse-details-container {
            position: relative;
            background-color: #f9f9f9;
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .warehouse-edit-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            color: #495057;
            font-size: 0.85em;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .warehouse-edit-btn:hover {
            background: #488C9A;
            border-color: #488C9A;
            color: #fff;
        }
        .warehouse-edit-btn i {
            font-size: 0.9em;
        }
        .warehouse-image {
            margin-right: 20px;
        }
        .warehouse-image img {
            display: block;
            border-radius: 4px;
            width: 200px;
            height: 150px;
            object-fit: cover;
        }
        .warehouse-image-placeholder {
            width: 200px;
            height: 150px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }
        .warehouse-image-placeholder i {
            font-size: 4rem;
            color: #488C9A;
            opacity: 0.7;
        }
        .warehouse-info {
            flex: 1;
            min-width: 300px;
        }
        .warehouse-info h1 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1.6em;
            color: #293E4C;
        }
        .warehouse-info p {
            margin: 5px 0;
            line-height: 1.5;
        }
        .warehouse-cost-summary {
            margin-top: 10px;
        }
        .warehouse-cost-summary p {
            margin-left: 15px;
            font-size: 0.9em;
        }
        /* Fee Summary Link Styles */
        .fee-summary-container {
            margin-top: 12px;
        }
        .fee-summary-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1em;
            color: #488C9A;
            font-weight: 600;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .fee-summary-link:hover {
            color: #3A6E7F;
            text-decoration: underline;
        }
        .fee-summary-link i {
            font-size: 1.1em;
        }
        .fee-summary-link .fee-count {
            color: #6c757d;
            font-weight: 500;
        }
        /* Fee Modal Styles */
        .fee-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeInModal 0.2s ease;
        }
        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .fee-modal-content {
            background: #fff;
            margin: 8% auto;
            max-width: 550px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: slideInModal 0.3s ease;
            overflow: hidden;
        }
        @keyframes slideInModal {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fee-modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .fee-modal-header h3 {
            margin: 0;
            font-size: 1.1em;
            font-weight: 600;
        }
        .fee-modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5em;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
            line-height: 1;
        }
        .fee-modal-close:hover {
            opacity: 1;
        }
        .fee-modal-body {
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
        }
        .fee-table {
            width: 100%;
            border-collapse: collapse;
        }
        .fee-table th {
            text-align: left;
            padding: 10px 12px;
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.85em;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        .fee-table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9em;
        }
        .fee-table tr:last-child td {
            border-bottom: none;
        }
        .fee-table tr:hover td {
            background: #f8f9fa;
        }
        .fee-table .fee-name {
            font-weight: 500;
            color: #293E4C;
        }
        .fee-table .fee-amount {
            font-weight: 600;
            color: #488C9A;
        }
        .fee-table .fee-unit, .fee-table .fee-trigger {
            color: #6c757d;
            font-size: 0.85em;
        }
        .fee-trigger-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 500;
        }
        .fee-trigger-badge.entry { background: #d4edda; color: #155724; }
        .fee-trigger-badge.exit { background: #f8d7da; color: #721c24; }
        .fee-trigger-badge.monthly { background: #d1ecf1; color: #0c5460; }
        .fee-trigger-badge.customs_clearance { background: #fff3cd; color: #856404; }
        .fee-trigger-badge.drayage { background: #e2e3e5; color: #383d41; }
        .fee-trigger-badge.other { background: #f5f5f5; color: #666; }
        .fee-modal-footer {
            padding: 12px 20px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            text-align: right;
        }
        .fee-modal-footer .action-button {
            padding: 8px 16px;
            font-size: 0.9em;
        }
        /* Cost Overview Cards - matching warehouse_info.php */
        .cost-overview-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .cost-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-radius: 12px;
            padding: 20px 16px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .cost-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(72, 140, 154, 0.12);
        }
        .cost-card .cost-icon {
            font-size: 28px;
            margin-bottom: 8px;
        }
        .cost-card .cost-value {
            font-size: 26px;
            font-weight: 700;
            color: #488C9A;
            margin-bottom: 4px;
        }
        .cost-card .cost-label {
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 500;
        }
        .cost-card.total-cost {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
        }
        .cost-card.total-cost .cost-icon,
        .cost-card.total-cost .cost-value,
        .cost-card.total-cost .cost-label {
            color: rgba(255, 255, 255, 0.9);
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
        .verification-panel {
            border: 1px solid #c7d7e2;
            background: #f8fcff;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 16px;
        }
        .verification-panel.alert {
            border-color: #fca5a5;
            background: #fef2f2;
        }
        .verification-panel h4 {
            margin: 0 0 8px;
            color: #1f3442;
            font-size: 0.96rem;
        }
        .verification-panel p {
            margin: 0 0 10px;
            color: #526577;
            font-size: 0.88rem;
        }
        .verification-table-wrap {
            overflow-x: auto;
            border: 1px solid #d7e4ec;
            border-radius: 8px;
            background: #fff;
        }
        .verification-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .verification-table th,
        .verification-table td {
            border: 1px solid #e5edf3;
            padding: 8px 9px;
            text-align: left;
            font-size: 0.82rem;
        }
        .verification-table th {
            background: #eff6fa;
            color: #2c4758;
            font-weight: 700;
        }
        .verification-ok {
            color: #166534;
            font-weight: 700;
        }
        .verification-mismatch {
            color: #b91c1c;
            font-weight: 700;
        }
        .workflow-banner {
            border: 1px solid #d6e3eb;
            border-radius: 12px;
            background: linear-gradient(135deg, #f9fcfe 0%, #eef6fa 100%);
            padding: 14px 16px;
            margin-bottom: 18px;
        }
        .workflow-banner-title {
            margin: 0 0 10px;
            color: #1f3442;
            font-size: 1rem;
            font-weight: 700;
        }
        .workflow-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .workflow-step {
            background: #fff;
            border: 1px solid #dfe9ef;
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .workflow-step-badge {
            width: 22px;
            height: 22px;
            border-radius: 999px;
            background: #488C9A;
            color: #fff;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 22px;
        }
        .workflow-step strong {
            display: block;
            color: #293E4C;
            margin-bottom: 2px;
            font-size: 0.9rem;
        }
        .workflow-step span {
            color: #5f7180;
            font-size: 0.82rem;
            line-height: 1.35;
        }
        .next-step-banner {
            border: 1px solid #86efac;
            background: #f0fdf4;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 16px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }
        .next-step-banner p {
            margin: 0;
            color: #166534;
            font-weight: 600;
        }
        .next-step-actions {
            display: inline-flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .customs-panel {
            border: 1px solid #d6e3eb;
            border-radius: 12px;
            background: #fff;
            padding: 14px 14px 12px;
            margin-bottom: 18px;
        }
        .customs-panel-head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .customs-panel h3 {
            margin: 0;
            color: #1e3443;
        }
        .customs-panel p {
            margin: 6px 0 0;
            color: #5b6d7b;
            font-size: 0.9em;
        }
        .customs-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .customs-chip {
            border: 1px solid #d5e6ec;
            border-radius: 999px;
            padding: 6px 10px;
            background: #f6fbfd;
            display: inline-flex;
            align-items: baseline;
            gap: 6px;
        }
        .customs-chip-value {
            font-weight: 700;
            color: #1f3a49;
            font-size: 0.9rem;
        }
        .customs-chip-label {
            color: #5f7180;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .customs-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .customs-form-grid > div label {
            font-weight: 600;
            margin-bottom: 5px;
            display: block;
            color: #334a58;
        }
        .customs-form-grid input[type="text"],
        .customs-form-grid input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccd8df;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .selection-counter {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 9px;
            border-radius: 999px;
            background: #eef7fb;
            border: 1px solid #d3e6ef;
            color: #345768;
            font-size: 0.82rem;
            font-weight: 600;
        }
        /* Customs filter bar */
        .customs-filter-bar {
            display: flex;
            gap: 6px;
            margin-bottom: 14px;
        }
        .customs-filter-btn {
            padding: 7px 16px;
            border: 1px solid #d6e3eb;
            border-radius: 999px;
            background: #fff;
            color: #4b5563;
            font-size: 0.85em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .customs-filter-btn:hover {
            background: #f0f7fa;
            border-color: #488C9A;
        }
        .customs-filter-btn.active {
            background: #488C9A;
            color: #fff;
            border-color: #488C9A;
        }
        /* Customs status badges */
        .customs-status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.8em;
            font-weight: 600;
        }
        .customs-status-cleared {
            background: #ecfdf5;
            color: #059669;
        }
        .customs-status-held {
            background: #fef2f2;
            color: #dc2626;
        }
        /* Customs action bar */
        .customs-action-bar {
            border: 1px solid #d6e3eb;
            border-radius: 10px;
            background: #f8fbfc;
            padding: 14px;
            margin-bottom: 14px;
            animation: slideDown 0.2s ease-out;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .customs-action-bar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 0.92em;
        }
        .customs-action-close {
            background: none;
            border: none;
            font-size: 1.3em;
            cursor: pointer;
            color: #6b7280;
            padding: 0 4px;
        }
        .customs-action-close:hover {
            color: #1f2937;
        }
        /* Hold badge on container rows */
        .hold-badge {
            display: inline-block;
            background: #fef2f2;
            color: #dc2626;
            border-radius: 12px;
            padding: 2px 10px;
            font-size: 0.82em;
            font-weight: 600;
        }
        .hold-badge-link {
            text-decoration: none;
        }
        .hold-badge-link:hover .hold-badge {
            background: #fee2e2;
        }
        /* Drayage modal inline hold banner */
        .drayage-modal-hold-banner {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            background: #fef3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 0.88em;
            color: #664d03;
            line-height: 1.5;
        }
        .drayage-modal-hold-icon {
            font-size: 1.2em;
            flex-shrink: 0;
            margin-top: 1px;
        }
        /* Drayage customs hold warning */
        .drayage-hold-warning-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: fadeIn 0.15s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .drayage-hold-warning-box {
            background: #fff;
            border-radius: 14px;
            padding: 28px 32px;
            max-width: 520px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            text-align: center;
        }
        .drayage-hold-warning-icon {
            font-size: 2.5em;
            color: #f59e0b;
            margin-bottom: 4px;
        }
        .drayage-hold-warning-box h3 {
            margin: 0 0 10px;
            color: #1f2937;
            font-size: 1.15em;
        }
        .drayage-hold-warning-box p {
            margin: 0 0 10px;
            color: #4b5563;
            font-size: 0.92em;
            line-height: 1.5;
        }
        .drayage-hold-warning-details {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 12px 16px;
            text-align: left;
            font-size: 0.9em;
            color: #991b1b;
            line-height: 1.6;
        }
        .drayage-hold-warning-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 18px;
        }
        .action-button-danger {
            background: #b91c1c;
        }
        .action-button-danger:hover {
            background: #991b1b;
        }
        .action-button-secondary {
            background: #334155;
        }
        .action-button-secondary:hover {
            background: #1f2937;
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
            max-height: 550px;
            overflow-y: auto;
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
        #customsEligibleTable thead th,
        #customsHeldTable thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #e7f1f6;
        }
        #customsEligibleTable tbody tr:hover,
        #customsHeldTable tbody tr:hover {
            background: #f3f9fc;
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
        @media (max-width: 760px) {
            .tabs {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
            }
            .tabs button {
                margin-bottom: 0;
            }
            .filter-controls {
                flex-wrap: wrap;
                gap: 8px;
            }
            .filter-controls input[type="text"],
            .filter-controls select {
                width: 100%;
            }
            .next-step-banner {
                align-items: flex-start;
            }
            .next-step-actions {
                width: 100%;
            }
            .next-step-actions .action-button {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php require_once 'components/breadcrumbs.php'; ?>
<main>
    <?php
    require_once 'components/breadcrumbs.php';
    $extra = [];
    $from_project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    if ($from_project_id <= 0) { $extra[] = ['label'=>'Manage Warehouses','url'=>'manage_warehouses.php']; }
    echo slp_render_breadcrumbs(['current_label' => 'Warehouse Inventory', 'extra' => $extra]);
    ?>
    
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><?php echo $successMessage; ?></div>
    <?php endif; ?>
    <?php if (!empty($customs_action_result) && !empty($customs_action_result['rows']) && is_array($customs_action_result['rows'])):
        $verification_expected_status = (string)($customs_action_result['expected_status'] ?? '');
        $verification_mismatch_count = 0;
        foreach ($customs_action_result['rows'] as $verification_row_tmp) {
            if ((string)($verification_row_tmp['status'] ?? '') !== $verification_expected_status) {
                $verification_mismatch_count++;
            }
        }
        // Only show the verification panel when there are actual mismatches
        if ($verification_mismatch_count > 0): ?>
        <div class="verification-panel alert">
            <h4>Customs Action Verification</h4>
            <p>
                Expected status: <strong><?php echo htmlspecialchars($verification_expected_status); ?></strong>.
                <?php echo number_format($verification_mismatch_count); ?> pallet(s) did not end in the expected status.
            </p>
            <div class="verification-table-wrap">
                <table class="verification-table">
                    <thead>
                        <tr>
                            <th>Pallet</th>
                            <th>Container</th>
                            <th>Current Status</th>
                            <th>Current Warehouse ID</th>
                            <th>Check</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customs_action_result['rows'] as $verification_row): ?>
                            <?php $status_match = ((string)($verification_row['status'] ?? '') === $verification_expected_status); ?>
                            <tr>
                                <td><?php echo htmlspecialchars($verification_row['pallet_identifier'] ?? ('ID ' . (int)($verification_row['id'] ?? 0))); ?></td>
                                <td><?php echo htmlspecialchars($verification_row['container_number'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($verification_row['status'] ?? 'N/A'); ?></td>
                                <td><?php echo (int)($verification_row['current_warehouse_id'] ?? 0); ?></td>
                                <td class="<?php echo $status_match ? 'verification-ok' : 'verification-mismatch'; ?>">
                                    <?php echo $status_match ? 'OK' : 'Mismatch'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
        
    <?php elseif (!$warehouse): ?>
        <div class="error-message">
            Warehouse not found or could not be loaded.
        </div>
        
    <?php else: ?>
        <!-- Warehouse Details - styled like warehouse_info.php -->
        <div class="warehouse-details-container">
            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'global_admin', 'customer_admin'])): ?>
                <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="warehouse-edit-btn">
                    <i class="fas fa-pencil-alt"></i> Edit
                </a>
            <?php endif; ?>
            <div class="warehouse-image">
                <?php
                $image_path = "";
                $has_main_image = false;
                if (!empty($warehouse['image_url'])) {
                    // Check if the image_url is a full URL or a relative path
                    if (filter_var($warehouse['image_url'], FILTER_VALIDATE_URL)) {
                        $image_path = $warehouse['image_url'];
                        $has_main_image = true;
                    } else {
                        $image_path = htmlspecialchars($warehouse['image_url']);
                        $has_main_image = file_exists(__DIR__ . '/' . $image_path);
                    }
                }
                ?>
                <?php if ($has_main_image): ?>
                    <img src="<?php echo $image_path; ?>" alt="<?php echo htmlspecialchars($warehouse['name']); ?> Warehouse">
                <?php else: ?>
                    <div class="warehouse-image-placeholder">
                        <i class="fas fa-warehouse"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="warehouse-info">
                <h1><?php echo htmlspecialchars($warehouse['name']); ?></h1>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($warehouse['address']); ?></p>

                <?php if (!empty($warehouse_fees['all_items'])): ?>
                    <?php $fee_count = count($warehouse_fees['all_items']); ?>
                    <div class="fee-summary-container">
                        <a href="javascript:void(0);" class="fee-summary-link" onclick="openFeeModal()">
                            <i class="fas fa-file-invoice-dollar"></i>
                            Cost Structure <span class="fee-count">(<?php echo $fee_count; ?> fee<?php echo $fee_count !== 1 ? 's' : ''; ?>)</span>
                        </a>
                    </div>
                <?php else: ?>
                    <p style="color: #999; font-style: italic; margin-top: 10px;"><em>No cost structure defined</em></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cost Overview Cards - matching warehouse_info.php -->
        <?php
        // Calculate total modules in storage
        $total_modules_stored = 0;
        foreach ($pallets_in_storage as $p) {
            $total_modules_stored += (int)($p['quantity'] ?? 0);
        }
        if ($is_port && !empty($port_customs_hold_pallets)) {
            foreach ($port_customs_hold_pallets as $p) {
                $total_modules_stored += (int)($p['quantity'] ?? 0);
            }
            $total_pallets += count($port_customs_hold_pallets);
            $total_storage_cost_monthly_rate += count($port_customs_hold_pallets) * (float)($warehouse_fees['monthly'] ?? 0);
        }
        ?>
        <div class="cost-overview-container">
            <div class="cost-card">
                <div class="cost-icon">📦</div>
                <div class="cost-value"><?php echo number_format($total_pallets); ?></div>
                <div class="cost-label">Total Pallets Stored</div>
            </div>
            <div class="cost-card">
                <div class="cost-icon">⚡</div>
                <div class="cost-value"><?php echo number_format($total_modules_stored); ?></div>
                <div class="cost-label">Total Modules Stored</div>
            </div>
            <div class="cost-card total-cost">
                <div class="cost-icon">💰</div>
                <div class="cost-value">$<?php echo number_format($total_storage_cost_monthly_rate, 2); ?></div>
                <div class="cost-label">Est. Monthly Cost</div>
            </div>
        </div>

        <?php if ($is_port): ?>
            <?php if ($show_customs_next_step_banner): ?>
                <div class="next-step-banner">
                    <p>Container(s) received. Next step: review pallets in <strong>Customs &amp; Clearance</strong> and place any flagged pallets on hold.</p>
                    <div class="next-step-actions">
                        <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo (int)$warehouse_id; ?>&tab=customsHold<?php echo $from_project_id > 0 ? '&project_id=' . (int)$from_project_id : ''; ?>" class="action-button">Go to Customs &amp; Clearance</a>
                        <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo (int)$warehouse_id; ?>&tab=inboundTransit<?php echo $from_project_id > 0 ? '&project_id=' . (int)$from_project_id : ''; ?>" class="action-button action-button-secondary">Back to Inbound Transit</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="workflow-banner">
                <p class="workflow-banner-title">Port Customs Workflow</p>
                <div class="workflow-steps">
                    <div class="workflow-step">
                        <span class="workflow-step-badge">1</span>
                        <div>
                            <strong>Receive Container(s)</strong>
                            <span>Inbound Transit &gt; By Truckload</span>
                        </div>
                    </div>
                    <div class="workflow-step">
                        <span class="workflow-step-badge">2</span>
                        <div>
                            <strong>Select Pallets</strong>
                            <span>Open Customs &amp; Clearance and select from cleared pallets.</span>
                        </div>
                    </div>
                    <div class="workflow-step">
                        <span class="workflow-step-badge">3</span>
                        <div>
                            <strong>Place On Hold</strong>
                            <span>Set required reason plus optional notes/cost per pallet.</span>
                        </div>
                    </div>
                    <div class="workflow-step">
                        <span class="workflow-step-badge">4</span>
                        <div>
                            <strong>Release To Cleared</strong>
                            <span>Release selected held pallets after customs clearance.</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TABS (always visible) -->
        <div class="tabs-container">
            <div class="tabs">
                <button id="storedInventoryTab" class="active"><?php echo $inventory_title; ?> (<?php echo $is_port ? count($containers_cleared) : count($pallets_in_storage); ?>)</button>
                <?php if ($is_port): ?>
                    <button id="customsHoldTab">Customs &amp; Clearance (<?php echo count($port_cleared_pallets) + count($port_customs_hold_pallets); ?>)</button>
                <?php endif; ?>
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
                    <label>Project:</label>
                    <select id="storedProjectFilter">
                        <option value="">All</option>
                        <?php
                        if (!empty($all_projects)) {
                            foreach ($all_projects as $proj) {
                                echo '<option value="' . htmlspecialchars($proj['project_name']) . '">' . htmlspecialchars($proj['project_name']) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="page-actions">
                    <?php if (!$is_port): ?>
                        <a href="create_shipment.php?source_type=warehouse&source_id=<?php echo $warehouse_id; ?>&status_filter=<?php echo urlencode($received_status); ?>" class="action-button">Create Shipment</a>
                    <?php endif; ?>
                    <?php if ($is_port && in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])): ?>
                        <button id="moveContainerBtn" class="action-button" disabled>Move Container (Drayage)</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-responsive">
                <table id="storedTable">
                    <thead>
                        <tr>
                            <?php if ($is_port && in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])): ?>
                                <th><input type="checkbox" id="selectAllContainers" onchange="toggleAllContainers()"> Select All</th>
                                <th>Container Number</th>
                                <th>Project(s)</th>
                                <th>Origin Vendor</th>
                                <th>Total Pallets</th>
                                <th>Customs Hold</th>
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
                                        <?php if (in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])): ?>
                                            <td><input type="checkbox" class="container-checkbox" value="<?php echo $container['delivery_id']; ?>" data-container-number="<?php echo htmlspecialchars($container['container_number'] ?? ''); ?>" data-hold-pallets="<?php echo (int)$container['hold_pallets']; ?>" data-hold-modules="<?php echo (int)$container['hold_modules']; ?>" onchange="toggleMoveContainerBtn()"></td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($container['container_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($container['projects']); ?></td>
                                        <td><?php echo htmlspecialchars($container['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($container['total_pallets']); ?></td>
                                        <td>
                                            <?php if ($container['hold_pallets'] > 0): ?>
                                                <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo (int)$warehouse_id; ?>&tab=customsHold&search=<?php echo urlencode($container['container_number'] ?? ''); ?>" class="hold-badge-link">
                                                    <span class="hold-badge"><?php echo $container['hold_pallets']; ?> on hold</span>
                                                </a>
                                            <?php else: ?>
                                                <span style="color:#9ca3af;">None</span>
                                            <?php endif; ?>
                                        </td>
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
                                <tr><td colspan="9">No containers currently cleared at this port.</td></tr>
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

        <?php if ($is_port): ?>
        <!-- TAB CONTENT: CUSTOMS & CLEARANCE -->
        <div id="customsHoldContent" class="tab-content">
            <?php
            $eligible_customs_modules = 0;
            foreach ($port_cleared_pallets as $eligible_row) {
                $eligible_customs_modules += (int)($eligible_row['quantity'] ?? 0);
            }
            $held_customs_modules = 0;
            $held_customs_cost_total = 0.0;
            foreach ($port_customs_hold_pallets as $held_row) {
                $held_customs_modules += (int)($held_row['quantity'] ?? 0);
                $held_customs_cost_total += (float)($held_row['customs_hold_cost'] ?? 0);
            }
            $customs_all_pallets = array_merge($port_cleared_pallets, $port_customs_hold_pallets);
            $total_customs_count = count($customs_all_pallets);
            $cleared_count = count($port_cleared_pallets);
            $held_count = count($port_customs_hold_pallets);
            ?>
            <h2>Customs &amp; Clearance</h2>
            <p style="margin: 0 0 16px; color: #6b7280;">
                All customs-related pallets in one view. Filter by status, then select pallets to place on hold or release.
            </p>

            <!-- Filter toggle bar -->
            <div class="customs-filter-bar">
                <button type="button" class="customs-filter-btn active" data-filter="all" onclick="filterCustomsUnified('all')">All (<?php echo $total_customs_count; ?>)</button>
                <button type="button" class="customs-filter-btn" data-filter="cleared" onclick="filterCustomsUnified('cleared')">Cleared (<?php echo $cleared_count; ?>)</button>
                <button type="button" class="customs-filter-btn" data-filter="held" onclick="filterCustomsUnified('held')">On Hold (<?php echo $held_count; ?>)</button>
            </div>

            <!-- Summary chips -->
            <div class="customs-chip-row" style="margin-bottom: 14px;">
                <span class="customs-chip"><span class="customs-chip-value" style="color:#059669;"><?php echo number_format($cleared_count); ?></span><span class="customs-chip-label">Cleared</span></span>
                <span class="customs-chip"><span class="customs-chip-value"><?php echo number_format($eligible_customs_modules); ?></span><span class="customs-chip-label">Cleared Modules</span></span>
                <span class="customs-chip" style="border-color:#fecaca; background:#fff5f5;"><span class="customs-chip-value" style="color:#dc2626;"><?php echo number_format($held_count); ?></span><span class="customs-chip-label">On Hold</span></span>
                <span class="customs-chip" style="border-color:#fecaca; background:#fff5f5;"><span class="customs-chip-value" style="color:#dc2626;"><?php echo number_format($held_customs_modules); ?></span><span class="customs-chip-label">Hold Modules</span></span>
                <?php if ($held_customs_cost_total > 0): ?>
                    <span class="customs-chip" style="border-color:#fecaca; background:#fff5f5;"><span class="customs-chip-value" style="color:#dc2626;">$<?php echo number_format($held_customs_cost_total, 2); ?></span><span class="customs-chip-label">Total Hold Cost</span></span>
                <?php endif; ?>
            </div>

            <div class="table-controls-header">
                <div class="filter-controls">
                    <label>Search:</label>
                    <input type="text" id="customsHoldSearch" placeholder="Filter by pallet ID, container, project, vendor...">
                </div>
                <div class="page-actions">
                    <span id="customsSelectionCount" class="selection-counter">0 selected</span>
                    <button type="button" class="action-button action-button-secondary" onclick="showMainTab('inboundTransit'); showTransitSubView('byTruckload');">Receive Container(s)</button>
                </div>
            </div>

            <!-- Contextual action bar -->
            <div id="customsActionBar" class="customs-action-bar" style="display:none;">
                <div id="customsActionHold" style="display:none;">
                    <div class="customs-action-bar-header">
                        <span>Place <strong id="holdActionCount">0</strong> selected pallet(s) on Customs Hold</span>
                        <button type="button" class="customs-action-close" onclick="closeCustomsActionBar()">&times;</button>
                    </div>
                    <form method="POST" id="customsHoldForm" onsubmit="return submitCustomsAction('hold')">
                        <input type="hidden" name="action" value="place_customs_hold">
                        <div id="holdPalletInputs"></div>
                        <div class="customs-form-grid">
                            <div>
                                <label for="hold_reason">Hold Reason *</label>
                                <input type="text" maxlength="120" name="customs_hold_reason" id="hold_reason" placeholder="Exam hold, document discrepancy, customs inspection..." required>
                            </div>
                            <div>
                                <label for="hold_cost_per_pallet">Cost per Pallet ($)</label>
                                <input type="number" step="0.01" min="0" name="customs_cost_per_pallet" id="hold_cost_per_pallet" value="0">
                            </div>
                            <div>
                                <label for="hold_cost_notes">Notes (Optional)</label>
                                <input type="text" maxlength="255" name="customs_cost_notes" id="hold_cost_notes" placeholder="Broker charge, exam fee details...">
                            </div>
                        </div>
                        <button type="submit" class="action-button action-button-danger" style="margin-top: 10px;">Place on Customs Hold</button>
                    </form>
                </div>
                <div id="customsActionRelease" style="display:none;">
                    <div class="customs-action-bar-header">
                        <span>Release <strong id="releaseActionCount">0</strong> selected pallet(s) to Cleared Customs</span>
                        <button type="button" class="customs-action-close" onclick="closeCustomsActionBar()">&times;</button>
                    </div>
                    <form method="POST" id="customsReleaseForm" onsubmit="return submitCustomsAction('release')">
                        <input type="hidden" name="action" value="release_customs_hold">
                        <div id="releasePalletInputs"></div>
                        <div class="customs-form-grid">
                            <div>
                                <label for="release_cost_per_pallet">Additional Cost per Pallet ($)</label>
                                <input type="number" step="0.01" min="0" name="customs_cost_per_pallet" id="release_cost_per_pallet" value="0">
                            </div>
                            <div>
                                <label for="release_cost_notes">Release Notes (Optional)</label>
                                <input type="text" maxlength="255" name="customs_cost_notes" id="release_cost_notes" placeholder="Released by customs, inspection complete...">
                            </div>
                        </div>
                        <button type="submit" class="action-button" style="margin-top: 10px;">Release to Cleared Customs</button>
                    </form>
                </div>
                <div id="customsActionMixed" style="display:none;">
                    <div class="customs-action-bar-header">
                        <span style="color:#b45309;">Select pallets of the same status to perform an action.</span>
                        <button type="button" class="customs-action-close" onclick="closeCustomsActionBar()">&times;</button>
                    </div>
                </div>
            </div>

            <!-- Unified customs table -->
            <div class="table-responsive">
                <table id="customsUnifiedTable">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAllCustomsUnified" onchange="toggleAllCustomsUnified()"> Select</th>
                            <th>Pallet ID</th>
                            <th>Container</th>
                            <th>Project</th>
                            <th>Vendor</th>
                            <th>Wattage</th>
                            <th>Modules</th>
                            <th>Status</th>
                            <th>Hold Cost</th>
                            <th>Arrival Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($customs_all_pallets)): ?>
                            <?php foreach ($port_cleared_pallets as $row): ?>
                                <tr data-customs-status="cleared">
                                    <td><input type="checkbox" class="customs-unified-checkbox" value="<?php echo (int)$row['pallet_id']; ?>" data-status="cleared" onchange="updateCustomsUnifiedSelection()"></td>
                                    <td><?php echo htmlspecialchars($row['pallet_identifier'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['container_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['project_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['origin_vendor'] ?? 'N/A'); ?></td>
                                    <td><?php echo (int)$row['wattage']; ?>W</td>
                                    <td><?php echo number_format((int)$row['quantity']); ?></td>
                                    <td><span class="customs-status-badge customs-status-cleared">Cleared</span></td>
                                    <td style="color:#9ca3af;">-</td>
                                    <td><?php echo !empty($row['arrival_date']) ? htmlspecialchars(date('m-d-Y', strtotime($row['arrival_date']))) : 'N/A'; ?></td>
                                    <td>
                                        <a href="pallet_details.php?pallet_id=<?php echo (int)$row['pallet_id']; ?>&project_id=<?php echo (int)$from_project_id; ?>&from=warehouse_info&warehouse_id=<?php echo (int)$warehouse_id; ?>" style="color:#488C9A; text-decoration:none; font-weight:600;">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($port_customs_hold_pallets as $row): ?>
                                <tr data-customs-status="held">
                                    <td><input type="checkbox" class="customs-unified-checkbox" value="<?php echo (int)$row['pallet_id']; ?>" data-status="held" onchange="updateCustomsUnifiedSelection()"></td>
                                    <td><?php echo htmlspecialchars($row['pallet_identifier'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['container_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['project_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['origin_vendor'] ?? 'N/A'); ?></td>
                                    <td><?php echo (int)$row['wattage']; ?>W</td>
                                    <td><?php echo number_format((int)$row['quantity']); ?></td>
                                    <td><span class="customs-status-badge customs-status-held">On Hold</span></td>
                                    <td>$<?php echo number_format((float)($row['customs_hold_cost'] ?? 0), 2); ?></td>
                                    <td><?php echo !empty($row['arrival_date']) ? htmlspecialchars(date('m-d-Y', strtotime($row['arrival_date']))) : 'N/A'; ?></td>
                                    <td>
                                        <a href="pallet_details.php?pallet_id=<?php echo (int)$row['pallet_id']; ?>&project_id=<?php echo (int)$from_project_id; ?>&from=warehouse_info&warehouse_id=<?php echo (int)$warehouse_id; ?>" style="color:#488C9A; text-decoration:none; font-weight:600;">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="11">No customs-related pallets at this port.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

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
                <?php if ($is_port): ?>
                    <div class="next-step-banner" style="margin-top: 0;">
                        <p><strong>Step 1:</strong> Receive containers here. Then go to <strong>Customs &amp; Clearance</strong> to place specific pallets on hold.</p>
                        <div class="next-step-actions">
                            <button type="button" class="action-button action-button-secondary" onclick="showMainTab('customsHold');">Go to Customs &amp; Clearance</button>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="table-controls-header">
                    <div class="filter-controls">
                        <label for="transitTruckloadSearch">Search:</label>
                        <input type="text" id="transitTruckloadSearch" placeholder="Filter by project, BOL/container, vendor...">
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
                                            <?php if (!empty($delivery['proof_of_delivery']) || $delivery['has_warehouse_pod'] > 0): ?>
                                                <a href="view_pod.php?delivery_id=<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>" target="_blank" style="color: #488C9A;">View POD</a>
                                            <?php else: ?>
                                                <button class="action-button" style="padding: 8px 15px;" onclick="uploadWarehousePOD(<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>, <?php echo explode(',', $delivery['project_ids'])[0]; ?>)">Upload POD</button>
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
        <div class="next-step-banner" style="margin-bottom: 12px;">
            <p>After receiving, use the <strong>Customs Hold</strong> tab to place specific pallets on hold.</p>
        </div>
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
            <div id="drayageHoldBanner" style="display:none;"></div>

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
const fromProjectId = <?php echo (int)$from_project_id; ?>;

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
    const projectFilter = document.getElementById('storedProjectFilter')?.value || '';
    const rows = document.querySelectorAll('#storedTable tbody tr');

    // Determine column indices dynamically from header labels
    const headerCells = document.querySelectorAll('#storedTable thead th');
    let projectColIndex = -1;
    let wattageColIndex = -1;
    headerCells.forEach((th, idx) => {
        const label = (th.textContent || '').trim().toLowerCase();
        if (projectColIndex === -1 && (label === 'project' || label === 'project(s)')) {
            projectColIndex = idx;
        }
        if (wattageColIndex === -1 && label.startsWith('wattage')) {
            wattageColIndex = idx; // 'Wattage' or 'Wattage Breakdown'
        }
    });

    rows.forEach(row => {
        let show = true;
        // Text search across all cells
        if (textFilter) {
        let rowText = '';
        for (let i = 0; i < row.cells.length; i++) {
                rowText += (row.cells[i].textContent || '').toLowerCase() + ' ';
            }
            if (!rowText.includes(textFilter)) show = false;
        }

        // Wattage filter: match numeric wattage; for 'Wattage Breakdown' use substring match
        if (wattageFilter && wattageColIndex >= 0) {
            const cell = row.cells[wattageColIndex];
            const cellText = (cell ? cell.textContent : '').toString();
            const normalized = cellText.replace(/\s+/g, '');
            if (!normalized.includes(wattageFilter)) show = false;
        }

        // Project filter: match if cell contains the project name (handles 'Project(s)')
        if (projectFilter && projectColIndex >= 0) {
            const cell = row.cells[projectColIndex];
            const projText = (cell ? cell.textContent : '').toString().trim();
            if (!projText || projText.indexOf(projectFilter) === -1) show = false;
        }

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

function filterTransitTruckloadTable() {
    const input = document.getElementById('transitTruckloadSearch');
    if (!input) return;
    const textFilter = input.value.toLowerCase().trim();
    document.querySelectorAll('#transitTruckloadTable tbody tr').forEach(row => {
        if (!textFilter) {
            row.style.display = '';
            return;
        }
        const rowText = (row.textContent || '').toLowerCase();
        row.style.display = rowText.includes(textFilter) ? '' : 'none';
    });
}

// Track current customs filter
let customsCurrentFilter = 'all';

function filterCustomsHoldTables() {
    const input = document.getElementById('customsHoldSearch');
    if (!input) return;
    const textFilter = input.value.toLowerCase().trim();
    document.querySelectorAll('#customsUnifiedTable tbody tr').forEach(row => {
        const statusAttr = row.getAttribute('data-customs-status');
        // Apply status filter first
        let statusVisible = true;
        if (customsCurrentFilter !== 'all' && statusAttr) {
            statusVisible = statusAttr === customsCurrentFilter;
        }
        // Apply text filter
        let textVisible = true;
        if (textFilter) {
            const rowText = (row.textContent || '').toLowerCase();
            textVisible = rowText.includes(textFilter);
        }
        row.style.display = (statusVisible && textVisible) ? '' : 'none';
    });
}

function filterCustomsUnified(filter) {
    customsCurrentFilter = filter;
    // Update active button
    document.querySelectorAll('.customs-filter-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-filter') === filter);
    });
    filterCustomsHoldTables();
    // Uncheck select-all when filter changes
    const selectAll = document.getElementById('selectAllCustomsUnified');
    if (selectAll) selectAll.checked = false;
    document.querySelectorAll('.customs-unified-checkbox').forEach(cb => cb.checked = false);
    updateCustomsUnifiedSelection();
}

function toggleAllCustomsUnified() {
    const selectAll = document.getElementById('selectAllCustomsUnified');
    if (!selectAll) return;
    document.querySelectorAll('#customsUnifiedTable tbody tr').forEach(row => {
        if (row.style.display === 'none') return;
        const cb = row.querySelector('.customs-unified-checkbox');
        if (cb) cb.checked = selectAll.checked;
    });
    updateCustomsUnifiedSelection();
}

function updateCustomsUnifiedSelection() {
    const checked = document.querySelectorAll('.customs-unified-checkbox:checked');
    const count = checked.length;
    const counterEl = document.getElementById('customsSelectionCount');
    if (counterEl) counterEl.textContent = `${count} selected`;

    const actionBar = document.getElementById('customsActionBar');
    const holdSection = document.getElementById('customsActionHold');
    const releaseSection = document.getElementById('customsActionRelease');
    const mixedSection = document.getElementById('customsActionMixed');

    if (count === 0) {
        actionBar.style.display = 'none';
        return;
    }

    let hasCleared = false, hasHeld = false;
    checked.forEach(cb => {
        if (cb.getAttribute('data-status') === 'cleared') hasCleared = true;
        if (cb.getAttribute('data-status') === 'held') hasHeld = true;
    });

    actionBar.style.display = '';
    holdSection.style.display = 'none';
    releaseSection.style.display = 'none';
    mixedSection.style.display = 'none';

    if (hasCleared && hasHeld) {
        mixedSection.style.display = '';
    } else if (hasCleared) {
        holdSection.style.display = '';
        document.getElementById('holdActionCount').textContent = count;
    } else if (hasHeld) {
        releaseSection.style.display = '';
        document.getElementById('releaseActionCount').textContent = count;
    }
}

function closeCustomsActionBar() {
    document.getElementById('customsActionBar').style.display = 'none';
    document.querySelectorAll('.customs-unified-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAllCustomsUnified');
    if (selectAll) selectAll.checked = false;
    const counterEl = document.getElementById('customsSelectionCount');
    if (counterEl) counterEl.textContent = '0 selected';
}

function submitCustomsAction(type) {
    const checked = document.querySelectorAll('.customs-unified-checkbox:checked');
    if (checked.length === 0) {
        alert('Select at least one pallet before submitting.');
        return false;
    }

    if (type === 'hold') {
        const reasonInput = document.getElementById('hold_reason');
        if (!reasonInput || reasonInput.value.trim() === '') {
            alert('Hold reason is required.');
            reasonInput?.focus();
            return false;
        }
        // Populate hidden pallet inputs
        const container = document.getElementById('holdPalletInputs');
        container.innerHTML = '';
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'customs_pallet_ids[]';
            input.value = cb.value;
            container.appendChild(input);
        });
    } else {
        // Populate hidden pallet inputs for release
        const container = document.getElementById('releasePalletInputs');
        container.innerHTML = '';
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'customs_pallet_ids[]';
            input.value = cb.value;
            container.appendChild(input);
        });
    }
    return true;
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
                // For ports: try to use anticipated delivery date from selected containers
                let defaultDate = '';
                let earliestDate = null;

                // Loop through all selected checkboxes to find the earliest anticipated delivery date
                selectedCheckboxes.forEach(checkbox => {
                    const selectedRow = checkbox.closest('tr');
                    // Find the Est. Arrival Date column (5th column - index 4)
                    const estArrivalCellIndex = 4;
                    const estArrivalCell = selectedRow ? selectedRow.cells[estArrivalCellIndex] : null;
                    if (estArrivalCell && estArrivalCell.textContent.trim() !== 'N/A' && estArrivalCell.textContent.trim() !== '') {
                        try {
                            const dateText = estArrivalCell.textContent.trim();
                            const date = new Date(dateText);
                            if (!isNaN(date.getTime())) {
                                if (earliestDate === null || date < earliestDate) {
                                    earliestDate = date;
                                }
                            }
                        } catch (e) {
                            // Skip invalid dates
                        }
                    }
                });

                // Use earliest anticipated date if found, otherwise use today
                if (earliestDate) {
                    defaultDate = earliestDate.getFullYear() + '-' +
                                 String(earliestDate.getMonth() + 1).padStart(2, '0') + '-' +
                                 String(earliestDate.getDate()).padStart(2, '0');
                } else {
                    const today = new Date();
                    defaultDate = today.getFullYear() + '-' +
                                 String(today.getMonth() + 1).padStart(2, '0') + '-' +
                                 String(today.getDate()).padStart(2, '0');
                }
                arrivalDateField.value = defaultDate;
            <?php else: ?>
                // For warehouses: try to use anticipated delivery date from selected truckloads
                let defaultDate = '';
                let earliestDate = null;

                // Loop through all selected checkboxes to find the earliest anticipated delivery date
                selectedCheckboxes.forEach(checkbox => {
                    const selectedRow = checkbox.closest('tr');
                    // Find the Est. Arrival Date column (5th column - index 4)
                    const estArrivalCellIndex = 4;
                    const estArrivalCell = selectedRow ? selectedRow.cells[estArrivalCellIndex] : null;
                    if (estArrivalCell && estArrivalCell.textContent.trim() !== 'N/A' && estArrivalCell.textContent.trim() !== '') {
                        try {
                            const dateText = estArrivalCell.textContent.trim();
                            const date = new Date(dateText);
                            if (!isNaN(date.getTime())) {
                                if (earliestDate === null || date < earliestDate) {
                                    earliestDate = date;
                                }
                            }
                        } catch (e) {
                            // Skip invalid dates
                        }
                    }
                });

                // Use earliest anticipated date if found, otherwise use today
                if (earliestDate) {
                    defaultDate = earliestDate.getFullYear() + '-' +
                                 String(earliestDate.getMonth() + 1).padStart(2, '0') + '-' +
                                 String(earliestDate.getDate()).padStart(2, '0');
                } else {
                    const today = new Date();
                    defaultDate = today.getFullYear() + '-' +
                                 String(today.getMonth() + 1).padStart(2, '0') + '-' +
                                 String(today.getDate()).padStart(2, '0');
                }
                arrivalDateField.value = defaultDate;
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

// Enhanced POD upload function for warehouse operations
function uploadWarehousePOD(deliveryId, projectId) {
    // Create modal for POD upload with enhanced Solterra styling
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.style.display = 'flex';
    modal.innerHTML = `
        <style>
            /* Override portal.css conflicts with higher specificity */
            .warehouse-pod-modal * {
                box-sizing: border-box !important;
            }
            .warehouse-pod-modal .modal-content {
                position: relative !important;
                background: white !important;
                margin: auto !important;
                padding: 0 !important;
                border: 1px solid #e1e6ea !important;
                border-radius: 12px !important;
                box-shadow: 0 20px 60px rgba(0,0,0,0.25) !important;
                max-width: 550px !important;
                width: 90% !important;
                max-height: 500px !important;
                overflow: hidden !important;
            }
            .warehouse-pod-modal input[type="file"] {
                width: 100% !important;
                padding: 12px 16px !important;
                border: 2px dashed #bdc3c7 !important;
                border-radius: 8px !important;
                background: #f8f9fa !important;
                font-size: 14px !important;
                font-family: 'Poppins', sans-serif !important;
                cursor: pointer !important;
                transition: all 0.3s ease !important;
            }
            .warehouse-pod-modal textarea {
                width: 100% !important;
                padding: 12px 16px !important;
                border: 1px solid #e1e6ea !important;
                border-radius: 8px !important;
                background: #f8f9fa !important;
                font-size: 14px !important;
                font-family: 'Poppins', sans-serif !important;
                resize: vertical !important;
                transition: all 0.3s ease !important;
            }
            .warehouse-pod-modal button {
                border: none !important;
                border-radius: 8px !important;
                font-family: 'Poppins', sans-serif !important;
                cursor: pointer !important;
                transition: all 0.3s ease !important;
                text-decoration: none !important;
            }
        </style>
        <div class="modal-content warehouse-pod-modal">
            <span class="close-modal" onclick="this.closest('.modal').remove()" 
                  style="position: absolute; top: 16px; right: 20px; font-size: 28px; font-weight: 300; color: #6c757d; cursor: pointer; transition: color 0.2s ease; z-index: 10;"
                  onmouseover="this.style.color='#e74c3c'" onmouseout="this.style.color='#6c757d'">&times;</span>
            
            <div style="background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color: white; padding: 24px 32px; border-radius: 12px 12px 0 0; margin: -1px -1px 0 -1px;">
                <h2 style="margin: 0; color: white; font-size: 24px; font-weight: 600; letter-spacing: -0.5px;">📋 Upload Warehouse POD</h2>
                <p style="margin: 8px 0 0 0; color: white; font-size: 14px; opacity: 0.9; font-weight: 300;">Upload proof of delivery document for warehouse operations</p>
            </div>
            
            <form id="warehousePODForm" enctype="multipart/form-data" style="padding: 32px;">
                <div style="margin-bottom: 24px;">
                    <label for="warehouse_pod_file" style="display: block; font-weight: 600; color: #2c3e50; margin-bottom: 8px; font-size: 14px;">
                        📎 Select Document
                    </label>
                    <div style="position: relative;">
                        <input type="file" id="warehouse_pod_file" name="warehouse_pod_file" 
                               accept=".pdf,.jpg,.jpeg,.png" required
                               onchange="updateFilePreview(this)"
                               onfocus="this.style.borderColor='#488C9A'; this.style.backgroundColor='#ffffff'"
                               onblur="this.style.borderColor='#bdc3c7'; this.style.backgroundColor='#f8f9fa'">
                    </div>
                    <div id="filePreview" style="margin-top: 8px; font-size: 12px; color: #7f8c8d;"></div>
                    <small style="display: block; color: #95a5a6; margin-top: 6px; font-size: 12px;">
                        📄 Supported: PDF, JPG, PNG files up to 50MB
                    </small>
                </div>
                
                <div style="margin-bottom: 24px;">
                    <label for="pod_description" style="display: block; font-weight: 600; color: #2c3e50; margin-bottom: 8px; font-size: 14px;">
                        📝 Description (Optional)
                    </label>
                    <textarea id="pod_description" name="description" rows="3" 
                              placeholder="Additional notes about this POD document..."
                              onfocus="this.style.borderColor='#488C9A'; this.style.backgroundColor='#ffffff'"
                              onblur="this.style.borderColor='#e1e6ea'; this.style.backgroundColor='#f8f9fa'"></textarea>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: center; padding-top: 16px; border-top: 1px solid #ecf0f1;">
                    <button type="submit" 
                            style="background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color: white; 
                                   padding: 12px 24px; font-weight: 600; font-size: 14px; 
                                   box-shadow: 0 4px 12px rgba(72,140,154,0.3);"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(72,140,154,0.4)'"
                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(72,140,154,0.3)'">
                        🚀 Upload Document
                    </button>
                    <button type="button" onclick="this.closest('.modal').remove()" 
                            style="background: #ecf0f1; color: #34495e; padding: 12px 24px; font-weight: 500; font-size: 14px;"
                            onmouseover="this.style.backgroundColor='#d5dbdb'"
                            onmouseout="this.style.backgroundColor='#ecf0f1'">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Add file preview functionality
    window.updateFilePreview = function(input) {
        const preview = document.getElementById('filePreview');
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const fileName = file.name;
            const fileSize = (file.size / 1024 / 1024).toFixed(2);
            const fileType = file.type.split('/')[1].toUpperCase();
            
            preview.innerHTML = `
                <div style="display: flex; align-items: center; padding: 8px 12px; background: #e8f5e8; border-radius: 6px; border-left: 4px solid #27ae60;">
                    <span style="margin-right: 8px;">✅</span>
                    <div>
                        <strong>${fileName}</strong><br>
                        <span style="color: #7f8c8d;">${fileType} • ${fileSize} MB</span>
                    </div>
                </div>
            `;
            
            // Update the input styling to show success
            input.style.borderColor = '#27ae60';
            input.style.backgroundColor = '#e8f5e8';
        } else {
            preview.innerHTML = '';
            input.style.borderColor = '#bdc3c7';
            input.style.backgroundColor = '#f8f9fa';
        }
    };
    
    // Handle form submission
    document.getElementById('warehousePODForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData();
        const fileInput = document.getElementById('warehouse_pod_file');
        const description = document.getElementById('pod_description').value;
        
        if (!fileInput.files[0]) {
            // Show error styling
            fileInput.style.borderColor = '#e74c3c';
            fileInput.style.backgroundColor = '#fdf2f2';
            alert('Please select a file to upload.');
            return;
        }
        
        formData.append('action', 'upload_warehouse_pod');
        formData.append('delivery_id', deliveryId);
        formData.append('project_id', projectId);
        formData.append('warehouse_pod_file', fileInput.files[0]);
        formData.append('description', description);
        
        // Show loading state with enhanced styling
        const submitBtn = e.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '⏳ Uploading...';
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.7';
        
        fetch('manage_warehouse_inventory.php?warehouse_id=<?php echo $warehouse_id; ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success state
                submitBtn.innerHTML = '✅ Upload Complete!';
                submitBtn.style.background = 'linear-gradient(135deg, #27ae60 0%, #229954 100%)';
                
                setTimeout(() => {
                    modal.remove();
                    location.reload(); // Refresh to show updated data
                }, 1500);
            } else {
                alert('Error: ' + (data.error || 'Unknown error occurred'));
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error occurred. Please try again.');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        });
    });
}

// ========== EVENT LISTENERS ==========
document.addEventListener('DOMContentLoaded', () => {
    // Main tab clicks
    document.getElementById('storedInventoryTab')?.addEventListener('click', () => showMainTab('storedInventory'));
    document.getElementById('customsHoldTab')?.addEventListener('click', () => showMainTab('customsHold'));
    document.getElementById('inboundTransitTab')?.addEventListener('click', () => showMainTab('inboundTransit'));
    document.getElementById('truckloadHistoryTab')?.addEventListener('click', () => showMainTab('truckloadHistory'));

    // Filter event listeners
    document.getElementById('storedSearch')?.addEventListener('keyup', filterStoredTable);
    document.getElementById('storedWattageFilter')?.addEventListener('change', filterStoredTable);
    document.getElementById('storedProjectFilter')?.addEventListener('change', filterStoredTable);
    document.getElementById('transitSearch')?.addEventListener('keyup', filterTransitTable);
    document.getElementById('transitWattageFilter')?.addEventListener('change', filterTransitTable);
    document.getElementById('transitTruckloadSearch')?.addEventListener('keyup', filterTransitTruckloadTable);
    document.getElementById('customsHoldSearch')?.addEventListener('keyup', filterCustomsHoldTables);

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
    updateCustomsSelectionCount('eligible');
    updateCustomsSelectionCount('held');

    // Auto-apply project filter if arriving from a specific project
    try {
        if (fromProjectId && projectsData && Array.isArray(projectsData)) {
            const proj = projectsData.find(p => String(p.id) === String(fromProjectId));
            if (proj) {
                const pf = document.getElementById('storedProjectFilter');
                if (pf) {
                    pf.value = proj.project_name;
                    filterStoredTable();
                }
            }
        }
    } catch (e) { console.warn('Project prefilter not applied:', e); }

    // Respect ?tab=... for direct navigation and optional prefilter from container tracker
    try {
        const params = new URLSearchParams(window.location.search);
        const requestedTab = params.get('tab');
        const requestedContainer = (params.get('container') || '').trim();
        if (requestedTab === 'customsHold' && document.getElementById('customsHoldTab')) {
            showMainTab('customsHold');
            // Pre-fill search if provided (e.g. from container hold badge click)
            const searchParam = (params.get('search') || '').trim();
            if (searchParam) {
                const customsSearch = document.getElementById('customsHoldSearch');
                if (customsSearch) {
                    customsSearch.value = searchParam;
                    filterCustomsHoldTables();
                }
            }
        } else if (requestedTab === 'storedInventory' && document.getElementById('storedInventoryTab')) {
            showMainTab('storedInventory');
        } else if (requestedTab === 'inboundTransit') {
            showMainTab('inboundTransit');
            showTransitSubView('byTruckload');
        } else if (requestedTab === 'truckloadHistory') {
            showMainTab('truckloadHistory');
        }

        if (requestedContainer !== '' && (!requestedTab || requestedTab === 'inboundTransit')) {
            const truckloadSearch = document.getElementById('transitTruckloadSearch');
            if (truckloadSearch) {
                showMainTab('inboundTransit');
                showTransitSubView('byTruckload');
                truckloadSearch.value = requestedContainer;
                filterTransitTruckloadTable();
            }
        }
    } catch (e) {
        console.warn('Tab query parameter could not be applied.', e);
    }
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
    

    
    // Debug data availability when modal opens
    console.log('Modal opening - checking data availability:');
    console.log('projectsData:', projectsData);
    console.log('warehousesData:', warehousesData);
    
    // Populate initial destinations (projects by default)
    console.log('Calling updateMoveDestinations from modal open');
    updateMoveDestinations();

    // Show customs hold warning banner inside modal if applicable
    const holdBanner = document.getElementById('drayageHoldBanner');
    if (holdBanner) {
        const checkedContainers = document.querySelectorAll('.container-checkbox:checked');
        const holdItems = [];
        checkedContainers.forEach(cb => {
            const hp = parseInt(cb.dataset.holdPallets || '0', 10);
            const hm = parseInt(cb.dataset.holdModules || '0', 10);
            if (hp > 0) {
                holdItems.push({ container: cb.dataset.containerNumber || 'Unknown', pallets: hp, modules: hm });
            }
        });
        if (holdItems.length > 0) {
            const lines = holdItems.map(h =>
                `<strong>${h.container}</strong>: ${h.pallets} pallet${h.pallets !== 1 ? 's' : ''} (${h.modules.toLocaleString()} modules)`
            ).join(' &middot; ');
            holdBanner.innerHTML = `<div class="drayage-modal-hold-banner"><span class="drayage-modal-hold-icon">&#9888;</span> <span>${lines} on <strong>Customs Hold</strong> &mdash; will not be shipped</span></div>`;
            holdBanner.style.display = '';
        } else {
            holdBanner.innerHTML = '';
            holdBanner.style.display = 'none';
        }
    }

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
    let drayageHoldWarningConfirmed = false;
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

            // Check for pallets on customs hold before proceeding
            if (!drayageHoldWarningConfirmed) {
                const holdWarnings = [];
                checkedContainers.forEach(cb => {
                    const holdPallets = parseInt(cb.dataset.holdPallets || '0', 10);
                    const holdModules = parseInt(cb.dataset.holdModules || '0', 10);
                    const contNum = cb.dataset.containerNumber || 'Unknown';
                    if (holdPallets > 0) {
                        holdWarnings.push({ container: contNum, pallets: holdPallets, modules: holdModules });
                    }
                });

                if (holdWarnings.length > 0) {
                    showDrayageHoldWarning(holdWarnings, function() {
                        drayageHoldWarningConfirmed = true;
                        form.dispatchEvent(new Event('submit', { cancelable: true }));
                    });
                    return;
                }
            }
            drayageHoldWarningConfirmed = false;

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

    // Drayage customs hold warning modal
    function showDrayageHoldWarning(holdWarnings, onConfirm) {
        // Remove any existing warning overlay
        document.getElementById('drayageHoldWarning')?.remove();

        let warningLines = holdWarnings.map(w =>
            `<strong>${w.container}</strong>: ${w.pallets} pallet${w.pallets !== 1 ? 's' : ''} (${w.modules.toLocaleString()} modules) on Customs Hold`
        ).join('<br>');

        const overlay = document.createElement('div');
        overlay.id = 'drayageHoldWarning';
        overlay.className = 'drayage-hold-warning-overlay';
        overlay.innerHTML = `
            <div class="drayage-hold-warning-box">
                <div class="drayage-hold-warning-icon">&#9888;</div>
                <h3>Customs Hold Warning</h3>
                <p>The following container(s) have pallets on Customs Hold that <strong>will not be included</strong> in this drayage shipment:</p>
                <div class="drayage-hold-warning-details">${warningLines}</div>
                <p style="margin-top:12px; color:#6b7280; font-size:0.9em;">These pallets will remain at the port and must be shipped separately after release from customs.</p>
                <div class="drayage-hold-warning-actions">
                    <button type="button" class="action-button action-button-secondary" id="drayageHoldCancel">Cancel</button>
                    <button type="button" class="action-button action-button-danger" id="drayageHoldProceed">Proceed Without Held Pallets</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        document.getElementById('drayageHoldCancel').addEventListener('click', function() {
            overlay.remove();
        });
        document.getElementById('drayageHoldProceed').addEventListener('click', function() {
            overlay.remove();
            onConfirm();
        });
        // Close on backdrop click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) overlay.remove();
        });
    }
});
</script>

<!-- Fee Structure Modal -->
<?php if (!empty($warehouse_fees['all_items'])): ?>
<div id="feeModal" class="fee-modal">
    <div class="fee-modal-content">
        <div class="fee-modal-header">
            <h3>Cost Structure - <?php echo htmlspecialchars($warehouse['name']); ?></h3>
            <button class="fee-modal-close" onclick="closeFeeModal()">&times;</button>
        </div>
        <div class="fee-modal-body">
            <table class="fee-table">
                <thead>
                    <tr>
                        <th>Fee Name</th>
                        <th>Amount</th>
                        <th>Billing Unit</th>
                        <th>When Charged</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($warehouse_fees['all_items'] as $fee): ?>
                    <?php
                        // Format unit type for display
                        $unit_display = 'Per Pallet';
                        switch ($fee['unit_type'] ?? 'per_pallet') {
                            case 'per_truck': $unit_display = 'Per Truck'; break;
                            case 'per_sqft': $unit_display = 'Per Sq Ft'; break;
                            case 'flat': $unit_display = 'Flat Rate'; break;
                        }
                        // Format trigger event for display
                        $trigger = $fee['trigger_event'] ?? 'other';
                        $trigger_display = ucwords(str_replace('_', ' ', $trigger));
                    ?>
                    <tr>
                        <td class="fee-name"><?php echo htmlspecialchars($fee['label']); ?></td>
                        <td class="fee-amount">$<?php echo number_format($fee['amount'], 2); ?></td>
                        <td class="fee-unit"><?php echo $unit_display; ?></td>
                        <td><span class="fee-trigger-badge <?php echo $trigger; ?>"><?php echo $trigger_display; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'global_admin', 'customer_admin'])): ?>
        <div class="fee-modal-footer">
            <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="action-button">
                <i class="fas fa-edit"></i> Edit Fees
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function openFeeModal() {
    document.getElementById('feeModal').style.display = 'block';
}

function closeFeeModal() {
    document.getElementById('feeModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('feeModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeFeeModal();
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeFeeModal();
});
</script>
<?php endif; ?>

</body>
</html>
