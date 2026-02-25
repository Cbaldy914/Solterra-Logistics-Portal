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

// Role detection
$role = $_SESSION['role'] ?? '';
$isAdmin = in_array($role, ['admin', 'global_admin', 'customer_admin'], true);
if ($isAdmin) {
    require_once 'document_helpers.php';
    require_once 'components/warehouse_inventory_helpers.php';
}

// Admin-specific initialization
$google_maps_api_key = '';
$account_id = null;
$successMessage = '';
$all_projects = [];
$other_warehouses = [];
$admin_pallets_in_storage = [];
$admin_transit_pallets = [];
$admin_transit_truckloads = [];
$admin_inbound_history = [];
$admin_outbound_history = [];
$admin_containers_cleared = [];
$admin_port_cleared_pallets = [];
$admin_port_customs_hold_pallets = [];
$customs_action_result = null;
$show_customs_next_step_banner = false;
$is_port = false;
$warehouse_fees = [
    'entry' => 0, 'exit' => 0, 'monthly' => 0,
    'customs_clearance' => 0, 'drayage' => 0, 'other' => 0,
    'all_items' => []
];
$facility_type = 'Warehouse';
$received_status = 'In Warehouse';

if ($isAdmin) {
    $google_maps_api_key = getGoogleMapsApiKey();
    $user_id = $_SESSION['user_id'] ?? 0;
    if ($role !== 'global_admin') {
        $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
        if ($stmtAccount) {
            $stmtAccount->bind_param("i", $user_id);
            $stmtAccount->execute();
            $stmtAccount->bind_result($account_id);
            $stmtAccount->fetch();
            $stmtAccount->close();
        }
    }
    $successMessage = $_SESSION['move_pallet_message'] ?? '';
    if (isset($_SESSION['move_pallet_message'])) unset($_SESSION['move_pallet_message']);
    $customs_action_result = $_SESSION['customs_action_result'] ?? null;
    if (isset($_SESSION['customs_action_result'])) unset($_SESSION['customs_action_result']);
}

// Get parameters
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : null;
$project_id   = isset($_GET['project_id'])   ? intval($_GET['project_id'])   : null;
$module_batch_id = isset($_GET['module_batch_id']) ? intval($_GET['module_batch_id']) : null;

// Initialize variables
$warehouse_data         = null;
$project_name_for_title = null;
$show_warehouse_list    = false;
$relevant_warehouses    = [];
$errorMessage           = '';
$page_title             = "Warehouse Information";
$origin_batch_vendor_name = null;

// ===========================================================================================
// ADMIN POST HANDLERS
// ===========================================================================================
if ($isAdmin && $warehouse_id) {
    // POD Upload Handler
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_warehouse_pod') {
        header('Content-Type: application/json');
        try {
            $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
            $pod_project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
            $description = trim($_POST['description'] ?? '');
            if ($delivery_id <= 0 || $pod_project_id <= 0) {
                throw new Exception("Invalid delivery or project ID");
            }
            if (!isset($_FILES['warehouse_pod_file']) || $_FILES['warehouse_pod_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a valid file to upload");
            }
            $file_data = [
                'name' => $_FILES['warehouse_pod_file']['name'],
                'type' => $_FILES['warehouse_pod_file']['type'],
                'tmp_name' => $_FILES['warehouse_pod_file']['tmp_name'],
                'error' => $_FILES['warehouse_pod_file']['error'],
                'size' => $_FILES['warehouse_pod_file']['size']
            ];
            $processed_file = processDocumentUpload($file_data, 'pods');
            $document_data = [
                'project_id' => $pod_project_id,
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
            $result = saveDocumentToProjectDocuments($conn, $document_data);
            echo json_encode(['success' => true, 'message' => 'Warehouse POD uploaded successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // Customs Hold Actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['place_customs_hold', 'release_customs_hold'], true)) {
        $redirect_tab = 'customsHold';
        $redirect_url = "warehouse.php?warehouse_id={$warehouse_id}&tab={$redirect_tab}";
        if (!empty($_GET['project_id'])) {
            $redirect_url .= '&project_id=' . urlencode((string)$_GET['project_id']);
        }
        try {
            $action = $_POST['action'];
            $selected = isset($_POST['customs_pallet_ids']) && is_array($_POST['customs_pallet_ids']) ? $_POST['customs_pallet_ids'] : [];
            $pallet_ids = array_values(array_filter(array_map('intval', $selected), static function ($v) { return $v > 0; }));
            if (empty($pallet_ids)) { throw new Exception('Select at least one pallet.'); }

            $stmtPort = $conn->prepare("SELECT is_port FROM warehouses WHERE id = ? LIMIT 1");
            if (!$stmtPort) { throw new Exception('Unable to validate facility type.'); }
            $stmtPort->bind_param("i", $warehouse_id);
            $stmtPort->execute();
            $stmtPort->bind_result($port_flag);
            $is_port_action = false;
            if ($stmtPort->fetch()) { $is_port_action = ((int)$port_flag === 1); }
            $stmtPort->close();
            if (!$is_port_action) { throw new Exception('Customs hold actions are only available for ports.'); }

            $cost_input = trim((string)($_POST['customs_cost_per_pallet'] ?? '0'));
            $cost_per_pallet = is_numeric($cost_input) ? (float)$cost_input : 0.0;
            if ($cost_per_pallet < 0) { throw new Exception('Cost per pallet cannot be negative.'); }
            $hold_reason = trim((string)($_POST['customs_hold_reason'] ?? ''));
            if ($action === 'place_customs_hold' && $hold_reason === '') { throw new Exception('Hold reason is required.'); }
            if (strlen($hold_reason) > 120) { $hold_reason = substr($hold_reason, 0, 120); }
            $cost_notes = trim((string)($_POST['customs_cost_notes'] ?? ''));
            if (strlen($cost_notes) > 255) { $cost_notes = substr($cost_notes, 0, 255); }
            $update_notes = $cost_notes;
            if ($action === 'place_customs_hold' && $hold_reason !== '') {
                $update_notes = $cost_notes !== '' ? 'Reason: ' . $hold_reason . ' | Notes: ' . $cost_notes : 'Reason: ' . $hold_reason;
                if (strlen($update_notes) > 255) { $update_notes = substr($update_notes, 0, 255); }
            }

            $new_status = ($action === 'place_customs_hold') ? 'Customs Hold' : 'Cleared Customs';
            $expected_status = ($action === 'place_customs_hold') ? 'Cleared Customs' : 'Customs Hold';
            $id_list = implode(',', $pallet_ids);

            $sql = "UPDATE inventory_pallets SET status = ?, customs_hold_cost = COALESCE(customs_hold_cost, 0) + ?,
                    customs_hold_cost_notes = CASE WHEN ? <> '' THEN ? ELSE customs_hold_cost_notes END,
                    customs_hold_cost_updated_at = NOW()
                    WHERE id IN ($id_list) AND current_warehouse_id = ? AND status = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) { throw new Exception('Failed to prepare customs hold update.'); }
            $stmt->bind_param("sdssis", $new_status, $cost_per_pallet, $update_notes, $update_notes, $warehouse_id, $expected_status);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected <= 0) { throw new Exception('No pallets were updated. They may already be in the target status.'); }

            $successMessage = $action === 'place_customs_hold'
                ? "Placed {$affected} pallet(s) on Customs Hold."
                : "Released {$affected} pallet(s) to Cleared Customs.";
            if ($action === 'place_customs_hold' && $hold_reason !== '') { $successMessage .= ' Reason: ' . $hold_reason . '.'; }
            if ($cost_per_pallet > 0) { $successMessage .= ' Added $' . number_format($cost_per_pallet, 2) . ' per pallet.'; }
            $_SESSION['move_pallet_message'] = $successMessage;

            $verify_rows = [];
            $verify_sql = "SELECT ip.id, ip.pallet_identifier, ip.status, ip.current_warehouse_id,
                           COALESCE(MAX(NULLIF(TRIM(d.bol_number), '')), 'N/A') AS container_number
                           FROM inventory_pallets ip
                           LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                           LEFT JOIN deliveries d ON dp.delivery_id = d.id
                           WHERE ip.id IN ($id_list)
                           GROUP BY ip.id, ip.pallet_identifier, ip.status, ip.current_warehouse_id
                           ORDER BY ip.pallet_identifier ASC";
            $stmtVerify = $conn->prepare($verify_sql);
            if ($stmtVerify) {
                $stmtVerify->execute();
                $verify_result = $stmtVerify->get_result();
                while ($verify_row = $verify_result->fetch_assoc()) { $verify_rows[] = $verify_row; }
                $stmtVerify->close();
            }
            $_SESSION['customs_action_result'] = [
                'action' => $action, 'expected_status' => $new_status,
                'warehouse_id' => (int)$warehouse_id, 'rows' => $verify_rows
            ];
        } catch (Exception $e) {
            $_SESSION['move_pallet_message'] = 'Error: ' . $e->getMessage();
            unset($_SESSION['customs_action_result']);
        }
        header("Location: {$redirect_url}");
        exit();
    }
}

// --- Dispatcher Logic ---

try {
    if ($module_batch_id) {
        // --- Scenario 3: Module Batch ID provided (for user view of unassigned batches) ---
        $stmtBatchVendor = $conn->prepare("SELECT vendor_name FROM modules WHERE id = ?");
        if ($stmtBatchVendor) {
            $stmtBatchVendor->bind_param("i", $module_batch_id);
            $stmtBatchVendor->execute();
            $stmtBatchVendor->bind_result($vendorName);
            if ($stmtBatchVendor->fetch()) {
                $origin_batch_vendor_name = $vendorName;
            }
            $stmtBatchVendor->close();
        }
        if (!$origin_batch_vendor_name) {
            throw new Exception("Module batch with ID {$module_batch_id} not found or has no vendor name.");
        }

        $page_title = "Warehouses for Batch: " . htmlspecialchars($origin_batch_vendor_name) . " (ID: {$module_batch_id})";

        // Find warehouses that contain pallets originating from this module batch
        $sqlDistinctWH_Batch = "
            SELECT DISTINCT
                wh.id, 
                wh.name,
                wh.address, 
                wh.image_url,
                (SELECT COUNT(ip_count.id) FROM inventory_pallets ip_count 
                 JOIN unassigned_module_items umi_count ON ip_count.unassigned_module_item_id = umi_count.id 
                 WHERE umi_count.unassigned_module_id = ? AND ip_count.current_warehouse_id = wh.id AND ip_count.status = 'In Warehouse') as pallets_in_warehouse,
                (SELECT SUM(ip_sum.quantity) FROM inventory_pallets ip_sum 
                 JOIN unassigned_module_items umi_sum ON ip_sum.unassigned_module_item_id = umi_sum.id 
                 WHERE umi_sum.unassigned_module_id = ? AND ip_sum.current_warehouse_id = wh.id AND ip_sum.status = 'In Warehouse') as modules_in_warehouse
            FROM warehouses wh
            JOIN inventory_pallets ip ON wh.id = ip.current_warehouse_id
            JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            WHERE umi.unassigned_module_id = ? AND ip.status = 'In Warehouse'
            ORDER BY wh.name ASC
        ";
        // Note: Simplified transit counts for this view, focusing on currently stored.

        $stmtDistinctWH_Batch = $conn->prepare($sqlDistinctWH_Batch);
        if (!$stmtDistinctWH_Batch) throw new Exception("Prepare distinct warehouses for batch failed: " . $conn->error);
        // Bind the module_batch_id three times for the subqueries and the main query
        $stmtDistinctWH_Batch->bind_param("iii", $module_batch_id, $module_batch_id, $module_batch_id);
        $stmtDistinctWH_Batch->execute();
        $resultDistinctWH_Batch = $stmtDistinctWH_Batch->get_result();

        while ($wh_row = $resultDistinctWH_Batch->fetch_assoc()) {
            $relevant_warehouses[] = $wh_row;
        }
        $stmtDistinctWH_Batch->close();

        $warehouse_count = count($relevant_warehouses);

        if ($warehouse_count === 0) {
            $errorMessage = "No inventory from Module Batch '" . htmlspecialchars($origin_batch_vendor_name) . " (ID: {$module_batch_id})' is currently tracked in any warehouse.";
        } elseif ($warehouse_count === 1) {
            // If only one warehouse, redirect to its view, but keep the context of the batch if needed
            $single_warehouse_id = $relevant_warehouses[0]['id'];
            header("Location: warehouse.php?warehouse_id={$single_warehouse_id}&module_batch_id={$module_batch_id}");
            exit();
        } else {
            $show_warehouse_list = true;
            // Page title already set
        }
        // This path (module_batch_id provided) implies we should NOT show a single warehouse detailed view unless redirected.
        // So, $warehouse_data remains null unless a single warehouse redirect happens and then it behaves like warehouse_id was passed.

    } elseif ($warehouse_id) {
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

        // Fetch warehouse cost items for this warehouse
        $warehouse_costs = [];
        $warehouse_costs_all = []; // Flat list for modal
        $stmtCosts = $conn->prepare("
            SELECT label, trigger_event, amount, unit_type
            FROM warehouse_cost_items
            WHERE warehouse_id = ? AND is_active = 1
            ORDER BY trigger_event, label
        ");
        if ($stmtCosts) {
            $stmtCosts->bind_param("i", $warehouse_id);
            $stmtCosts->execute();
            $resultCosts = $stmtCosts->get_result();
            while ($cost = $resultCosts->fetch_assoc()) {
                $warehouse_costs[$cost['trigger_event']][] = $cost;
                $warehouse_costs_all[] = $cost; // Flat list for modal
            }
            $stmtCosts->close();
        }

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
            SELECT 
                wh.id, 
                wh.name,
                wh.address, 
                wh.image_url,
                SUM(CASE WHEN ip_stored.status = 'In Warehouse' AND ip_stored.current_warehouse_id = wh.id THEN 1 ELSE 0 END) as pallets_in_warehouse,
                SUM(CASE WHEN ip_stored.status = 'In Warehouse' AND ip_stored.current_warehouse_id = wh.id THEN ip_stored.quantity ELSE 0 END) as modules_in_warehouse,
                SUM(CASE WHEN d_transit.status_of_delivery LIKE 'In Transit%' AND d_transit.warehouse_id = wh.id AND d_transit.warehouse_arrival_date IS NULL THEN 1 ELSE 0 END) as pallets_in_transit_to_wh,
                SUM(CASE WHEN d_transit.status_of_delivery LIKE 'In Transit%' AND d_transit.warehouse_id = wh.id AND d_transit.warehouse_arrival_date IS NULL THEN d_pal.quantity_on_delivery END) as modules_in_transit_to_wh
            FROM warehouses wh
            LEFT JOIN inventory_pallets ip_stored ON ip_stored.current_warehouse_id = wh.id AND ip_stored.assigned_project_id = ?
            LEFT JOIN deliveries d_transit ON d_transit.warehouse_id = wh.id AND d_transit.project_id = ? AND d_transit.status_of_delivery LIKE 'In Transit%' AND d_transit.warehouse_arrival_date IS NULL
            LEFT JOIN (
                SELECT dp.delivery_id, SUM(ip_inner.quantity) as quantity_on_delivery
                FROM delivery_pallets dp
                JOIN inventory_pallets ip_inner ON dp.inventory_pallet_id = ip_inner.id
                WHERE ip_inner.assigned_project_id = ? /* Ensure pallets in transit also belong to the project */
                GROUP BY dp.delivery_id
            ) d_pal ON d_transit.id = d_pal.delivery_id
            WHERE
                EXISTS (
                    SELECT 1 FROM inventory_pallets ip_check_stored
                    WHERE ip_check_stored.assigned_project_id = ? AND ip_check_stored.current_warehouse_id = wh.id AND ip_check_stored.status = 'In Warehouse'
                )
                OR EXISTS (
                    SELECT 1 FROM deliveries d_check_transit
                    JOIN delivery_pallets dp_check_transit ON d_check_transit.id = dp_check_transit.delivery_id
                    JOIN inventory_pallets ip_check_d_transit ON dp_check_transit.inventory_pallet_id = ip_check_d_transit.id
                    WHERE ip_check_d_transit.assigned_project_id = ?
                      AND d_check_transit.warehouse_id = wh.id
                      AND d_check_transit.status_of_delivery LIKE 'In Transit%'
                      AND d_check_transit.warehouse_arrival_date IS NULL
                )
                OR EXISTS (
                    SELECT 1 FROM deliveries d_hist
                    WHERE d_hist.warehouse_id = wh.id
                      AND d_hist.project_id = ?
                )
            GROUP BY wh.id, wh.name, wh.address, wh.image_url
            ORDER BY wh.name ASC
        ";
        $stmtDistinctWH = $conn->prepare($sqlDistinctWH);
        if (!$stmtDistinctWH) throw new Exception("Prepare distinct warehouses failed: " . $conn->error);
        $stmtDistinctWH->bind_param("iiiiii", $project_id, $project_id, $project_id, $project_id, $project_id, $project_id);
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
            header("Location: warehouse.php?warehouse_id={$single_warehouse_id}&project_id={$project_id}&single_wh=1");
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
            SELECT ip.id AS pallet_id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.arrival_date, m.vendor_name AS origin_vendor,
                   p.project_name, ip.assigned_project_id,
                   DATEDIFF(CURDATE(), ip.arrival_date) AS days_stored
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN projects p ON ip.assigned_project_id = p.id
            WHERE ip.current_warehouse_id = ? AND ip.status = 'In Warehouse'
        ";
        $pallet_params = [$warehouse_id];
        $pallet_types = "i";

        if ($project_id) {
            $sql_pallets .= " AND ip.assigned_project_id = ?";
            $pallet_params[] = $project_id;
            $pallet_types .= "i";
        } elseif ($module_batch_id) {
            $sql_pallets .= " AND umi.unassigned_module_id = ?";
            $pallet_params[] = $module_batch_id;
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

        // Fetch Deliveries Arrived (for Inbound Truckloads Table) - Grouped by BOL#
        $arrived_delivery_ids = [];
        $inbound_deliveries_for_table = []; // Grouped by BOL#
        $inbound_grouped = [];
        
        $sql_deliveries_arrived = "
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
                CASE WHEN COUNT(DISTINCT d.wattage) > 1 THEN 1 ELSE 0 END AS is_mixed_wattage
            FROM deliveries d
            LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
            LEFT JOIN projects p ON d.project_id = p.id
            WHERE d.warehouse_id = ? AND d.warehouse_arrival_date IS NOT NULL
        ";
        $delivered_params = [$warehouse_id];
        $delivered_types = "i";
        if ($project_id) {
             $sql_deliveries_arrived .= " AND d.project_id = ?";
             $delivered_params[] = $project_id;
             $delivered_types .= "i";
        } elseif ($module_batch_id && !$project_id) {
            // If viewing warehouse from batch context, show all its deliveries
        }
        $sql_deliveries_arrived .= " GROUP BY d.bol_number, d.supplier, d.warehouse_arrival_date, d.proof_of_delivery ORDER BY d.warehouse_arrival_date DESC";
        
        $stmt_delivered = $conn->prepare($sql_deliveries_arrived);
        if (!$stmt_delivered) throw new Exception("Prepare arrived deliveries failed: ".$conn->error);
        $stmt_delivered->bind_param($delivered_types, ...$delivered_params);
        $stmt_delivered->execute();
        $result_delivered = $stmt_delivered->get_result();
        $index = 0;
        while ($drow = $result_delivered->fetch_assoc()) {
            $drow['index'] = $index;
            $delivery_ids_array = explode(',', $drow['delivery_ids']);
            $arrived_delivery_ids = array_merge($arrived_delivery_ids, $delivery_ids_array);
            $arrived_date_values[] = $drow['warehouse_arrival_date'] ?? '';
            
            // If mixed wattage, get individual delivery details
            if ($drow['is_mixed_wattage']) {
                $drow['details'] = [];
                foreach ($delivery_ids_array as $del_id) {
                    $stmtDetail = $conn->prepare("
                        SELECT d.id, d.project_id, d.wattage, d.quantity, p.project_name,
                               COUNT(dp.inventory_pallet_id) AS pallet_count
                        FROM deliveries d
                        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
                        LEFT JOIN projects p ON d.project_id = p.id
                        WHERE d.id = ?
                        GROUP BY d.id, d.project_id, d.wattage, d.quantity, p.project_name
                    ");
                    if ($stmtDetail) {
                        $stmtDetail->bind_param("i", $del_id);
                        $stmtDetail->execute();
                        $resultDetail = $stmtDetail->get_result();
                        if ($detail = $resultDetail->fetch_assoc()) {
                            $drow['details'][] = $detail;
                        }
                        $stmtDetail->close();
                    }
                }
            }
            $inbound_grouped[] = $drow;
            $index++;
        }
        $stmt_delivered->close();
        $inbound_deliveries_for_table = $inbound_grouped;

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
        
        // Fetch Deliveries Departed (for Outbound Truckloads Table) - Grouped by BOL#
        $departed_delivery_ids = [];
        $outbound_deliveries_for_table = []; // Grouped by BOL#
        $outbound_grouped = [];
        
        $sql_deliveries_left = "
            SELECT
                d.bol_number,
                d.supplier,
                d.left_warehouse_date,
                d.proof_of_delivery,
                COUNT(DISTINCT d.id) AS delivery_count,
                COUNT(DISTINCT dp.inventory_pallet_id) AS total_pallets,
                (SELECT SUM(d_inner.quantity) FROM deliveries d_inner WHERE d_inner.bol_number = d.bol_number AND d_inner.warehouse_id = d.warehouse_id AND d_inner.left_warehouse_date = d.left_warehouse_date) AS total_modules,
                GROUP_CONCAT(DISTINCT d.wattage ORDER BY d.wattage SEPARATOR ', ') AS wattages,
                GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects,
                GROUP_CONCAT(DISTINCT d.id ORDER BY d.id SEPARATOR ',') AS delivery_ids,
                GROUP_CONCAT(DISTINCT d.project_id ORDER BY d.project_id SEPARATOR ',') AS project_ids,
                CASE WHEN COUNT(DISTINCT d.wattage) > 1 THEN 1 ELSE 0 END AS is_mixed_wattage
            FROM deliveries d
            LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
            LEFT JOIN projects p ON d.project_id = p.id
            WHERE d.left_warehouse_date IS NOT NULL
            AND (
                (d.origin_type = 'warehouse' AND d.origin_id = ?)
                OR (d.warehouse_id = ? AND d.warehouse_arrival_date IS NOT NULL)
            )
        "; 
        $left_params = [$warehouse_id, $warehouse_id];
        $left_types = "ii";
        if ($project_id) {
             $sql_deliveries_left .= " AND d.project_id = ?";
             $left_params[] = $project_id;
             $left_types .= "i";
        } elseif ($module_batch_id && !$project_id) {
            // Similar logic as above for arrived deliveries.
            // If we are here via module_batch_id, then outbound deliveries are for this warehouse.
        }
        $sql_deliveries_left .= " GROUP BY d.bol_number, d.supplier, d.left_warehouse_date, d.proof_of_delivery ORDER BY d.left_warehouse_date DESC";
        
        $stmt_left = $conn->prepare($sql_deliveries_left);
        if (!$stmt_left) throw new Exception("Prepare left deliveries failed: ".$conn->error);
        $stmt_left->bind_param($left_types, ...$left_params);
        $stmt_left->execute();
        $result_left = $stmt_left->get_result();
        $index = 0;
        while ($drow = $result_left->fetch_assoc()) {
            $drow['index'] = $index;
            $delivery_ids_array = explode(',', $drow['delivery_ids']);
            $departed_delivery_ids = array_merge($departed_delivery_ids, $delivery_ids_array);
            $left_warehouse_date_values[] = $drow['left_warehouse_date'] ?? '';
            
            // If mixed wattage, get individual delivery details
            if ($drow['is_mixed_wattage']) {
                $drow['details'] = [];
                foreach ($delivery_ids_array as $del_id) {
                    $stmtDetail = $conn->prepare("
                        SELECT d.id, d.project_id, d.wattage, d.quantity, p.project_name,
                               COUNT(dp.inventory_pallet_id) AS pallet_count
                        FROM deliveries d
                        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
                        LEFT JOIN projects p ON d.project_id = p.id
                        WHERE d.id = ?
                        GROUP BY d.id, d.project_id, d.wattage, d.quantity, p.project_name
                    ");
                    if ($stmtDetail) {
                        $stmtDetail->bind_param("i", $del_id);
                        $stmtDetail->execute();
                        $resultDetail = $stmtDetail->get_result();
                        if ($detail = $resultDetail->fetch_assoc()) {
                            $drow['details'][] = $detail;
                        }
                        $stmtDetail->close();
                    }
                }
            }
            $outbound_grouped[] = $drow;
            $index++;
        }
        $stmt_left->close();
        $outbound_deliveries_for_table = $outbound_grouped;

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
        
        // Calculate Costs using new warehouse_cost_items structure
        $in_fee_cost = 0;
        $out_fee_cost = 0;
        $monthly_storage_rate = 0;
        $current_monthly_accrual = 0;
        $departed_storage_cost = 0;
        $current_storage_cost = 0;
        $departed_pallets_count = 0;
        
        // Get cost rates from warehouse_cost_items - separate per_pallet vs per_truck
        $monthly_rate_per_pallet = 0;
        if (!empty($warehouse_costs['monthly'])) {
            foreach ($warehouse_costs['monthly'] as $cost) {
                if (($cost['unit_type'] ?? 'per_pallet') === 'per_pallet' || empty($cost['unit_type'])) {
                    $monthly_rate_per_pallet += floatval($cost['amount']);
                }
            }
        }
        $daily_rate = $monthly_rate_per_pallet > 0 ? ($monthly_rate_per_pallet / 30) : 0;

        $total_inbound_bols = count($inbound_grouped);
        $total_outbound_bols = count($outbound_grouped);

        if (!empty($warehouse_costs['entry'])) {
            foreach ($warehouse_costs['entry'] as $cost) {
                $unit = $cost['unit_type'] ?? 'per_pallet';
                $amt = floatval($cost['amount']);
                if ($unit === 'per_truck' || $unit === 'per_bol') {
                    $in_fee_cost += $amt * $total_inbound_bols;
                } else {
                    $in_fee_cost += $amt * $total_inbound_pallets_count;
                }
            }
        }
        if (!empty($warehouse_costs['exit'])) {
            foreach ($warehouse_costs['exit'] as $cost) {
                $unit = $cost['unit_type'] ?? 'per_pallet';
                $amt = floatval($cost['amount']);
                if ($unit === 'per_truck' || $unit === 'per_bol') {
                    $out_fee_cost += $amt * $total_outbound_bols;
                } else {
                    $out_fee_cost += $amt * $total_outbound_pallets_count;
                }
            }
        }
        if ($monthly_rate_per_pallet > 0) {
            $monthly_storage_rate = $monthly_rate_per_pallet * $total_pallets_count; // current pallets only
            $current_monthly_accrual = $monthly_storage_rate;
        }
        
        // Calculate actual storage costs and average months (current + departed pallets)
        $total_storage_cost_actual = 0;
        $total_days_all_pallets = 0;
        
        // Current pallets accruing
        if (!empty($inventory_pallets)) {
            foreach ($inventory_pallets as $pallet_calc) {
                $days = max(0, intval($pallet_calc['days_stored'] ?? 0));
                $total_days_all_pallets += $days;
                $current_storage_cost += $days * $daily_rate;
            }
        }

        // Departed pallets (calculate storage using actual stay at this warehouse)
        $departed_sql = "
            SELECT dp.inventory_pallet_id AS pallet_id,
                   MIN(CASE WHEN d.warehouse_id = ? THEN d.warehouse_arrival_date END) AS arrival_date,
                   MIN(CASE WHEN d.origin_type = 'warehouse' AND d.origin_id = ? THEN d.left_warehouse_date END) AS departure_date
            FROM deliveries d
            JOIN delivery_pallets dp ON d.id = dp.delivery_id
            WHERE (d.warehouse_id = ? OR (d.origin_type = 'warehouse' AND d.origin_id = ?))
        ";
        $departed_params = [$warehouse_id, $warehouse_id, $warehouse_id, $warehouse_id];
        $departed_types  = "iiii";
        if ($project_id) {
            $departed_sql .= " AND d.project_id = ?";
            $departed_params[] = $project_id;
            $departed_types   .= "i";
        }
        $departed_sql .= " GROUP BY dp.inventory_pallet_id";

        $stmt_departed_storage = $conn->prepare($departed_sql);
        if ($stmt_departed_storage) {
            $stmt_departed_storage->bind_param($departed_types, ...$departed_params);
            $stmt_departed_storage->execute();
            $result_departed_storage = $stmt_departed_storage->get_result();
            while ($row_dep = $result_departed_storage->fetch_assoc()) {
                $arrival     = $row_dep['arrival_date'] ?? null;
                $departure   = $row_dep['departure_date'] ?? null;

                if ($arrival && $departure) {
                    $arrival_ts = strtotime($arrival);
                    $left_ts    = strtotime($departure);
                    if ($arrival_ts !== false && $left_ts !== false && $left_ts >= $arrival_ts) {
                        $departed_pallets_count++;
                        $days = max(0, (int)ceil(($left_ts - $arrival_ts) / (60 * 60 * 24)));
                        $total_days_all_pallets += $days;
                        $departed_storage_cost  += $days * $daily_rate;
                    }
                }
            }
            $stmt_departed_storage->close();
        }

        $total_storage_cost_actual = $current_storage_cost + $departed_storage_cost;
        $average_divisor = ($total_pallets_count + $departed_pallets_count) > 0 ? ($total_pallets_count + $departed_pallets_count) : 0;
        $average_days = $average_divisor > 0 ? $total_days_all_pallets / $average_divisor : 0;
        $average_months = $average_days / 30;
        
        $total_cost_to_date = $in_fee_cost + $out_fee_cost + $total_storage_cost_actual;

        // ===========================================================================================
        // ADMIN-ONLY DATA FETCHING
        // ===========================================================================================
        if ($isAdmin) {
            $is_port = !empty($warehouse_data['is_port']) && $warehouse_data['is_port'] == 1;
            $facility_type = $is_port ? 'Port' : 'Warehouse';
            $received_status = $is_port ? 'Cleared Customs' : 'In Warehouse';
            $receiving_title = $is_port ? 'Receive Container(s)' : 'Receive Selected Truckloads';
            $history_title = $is_port ? 'Container History' : 'Truckload History';
            $inventory_title = $is_port ? 'Containers Cleared' : 'Stored Inventory';
            $from_project_id = (int)($project_id ?? 0);

            // Admin port redirect: admin users viewing ports go to dedicated port page
            if ($is_port && $_SERVER['REQUEST_METHOD'] === 'GET') {
                header("Location: manage_port_inventory.php?" . $_SERVER['QUERY_STRING']);
                exit();
            }

            // Build warehouse_fees from existing $warehouse_costs data (no duplicate query)
            $warehouse_fees = [
                'entry' => 0, 'exit' => 0, 'monthly' => 0,
                'customs_clearance' => 0, 'drayage' => 0, 'other' => 0,
                'all_items' => $warehouse_costs_all
            ];
            foreach ($warehouse_costs_all as $cost) {
                $trigger = $cost['trigger_event'] ?? 'other';
                if (isset($warehouse_fees[$trigger])) {
                    if (($cost['unit_type'] ?? 'per_pallet') === 'per_pallet' || empty($cost['unit_type'])) {
                        $warehouse_fees[$trigger] += floatval($cost['amount']);
                    }
                }
            }

            // Use helper functions for admin inventory data (filtered by project if set)
            list($admin_pallets_in_storage, $admin_total_pallets) = fetchStoredInventory($conn, $warehouse_id, $received_status, $is_port, $from_project_id ?: null);
            $admin_transit_pallets = fetchTransitPallets($conn, $warehouse_id, $from_project_id ?: null);
            $admin_transit_truckloads = fetchTransitTruckloads($conn, $warehouse_id, $from_project_id ?: null);
            $admin_inbound_history = fetchInboundHistory($conn, $warehouse_id, $from_project_id ?: null);
            $admin_outbound_history = fetchOutboundHistory($conn, $warehouse_id, $from_project_id ?: null);

            // Port-specific data
            if ($is_port) {
                $admin_containers_cleared = fetchPortContainersCleared($conn, $warehouse_id, $received_status);
                $admin_port_cleared_pallets = fetchPortPalletsByStatus($conn, $warehouse_id, 'Cleared Customs');
                $admin_port_customs_hold_pallets = fetchPortPalletsByStatus($conn, $warehouse_id, 'Customs Hold');
            }

            // Admin cost variables from admin data
            // When project_id is set, use the already project-filtered values from the non-admin section
            // Only override with warehouse-wide totals when viewing the full warehouse
            if (!$from_project_id) {
                $admin_total_storage_cost_monthly_rate = ($admin_total_pallets ?? 0) * $warehouse_fees['monthly'];
                $current_monthly_accrual = $admin_total_storage_cost_monthly_rate;
            }

            // Fetch all projects for dropdown (admin move/create shipment features)
            $project_clause = $role === 'global_admin' ? '' : ' AND account_id = ?';
            $stmtAllP = $conn->prepare("SELECT id, project_name, street_address, city, state, zip_code FROM projects WHERE (status IS NULL OR status = 'active')$project_clause ORDER BY project_name ASC");
            if ($stmtAllP) {
                if ($role !== 'global_admin') { $stmtAllP->bind_param("i", $account_id); }
                $stmtAllP->execute();
                $resultAllP = $stmtAllP->get_result();
                while ($proj = $resultAllP->fetch_assoc()) {
                    $address_parts = array_filter([$proj['street_address'], $proj['city'], $proj['state'], $proj['zip_code']]);
                    $proj['full_address'] = implode(', ', $address_parts);
                    $all_projects[] = $proj;
                }
                $stmtAllP->close();
            }

            // Fetch other warehouses for dropdown
            $acct_clause = $role === 'global_admin' ? '' : ' AND account_id = ?';
            $stmtOtherW = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM warehouses WHERE (is_port = 0 OR is_port IS NULL) AND id != ?$acct_clause ORDER BY name ASC");
            if ($stmtOtherW) {
                if ($role === 'global_admin') {
                    $stmtOtherW->bind_param("i", $warehouse_id);
                } else {
                    $stmtOtherW->bind_param("ii", $warehouse_id, $account_id);
                }
                $stmtOtherW->execute();
                $resultOtherW = $stmtOtherW->get_result();
                while ($wh = $resultOtherW->fetch_assoc()) {
                    $address_parts = array_filter([$wh['street_address'], $wh['city'], $wh['state'], $wh['zip_code']]);
                    $wh['full_address'] = implode(', ', $address_parts);
                    $other_warehouses[] = $wh;
                }
                $stmtOtherW->close();
            }

            // Check for customs next step banner (port-specific)
            if ($is_port) {
                $receivedHintFromMessage = stripos((string)$successMessage, 'Successfully received') !== false;
                $show_customs_next_step_banner = $receivedHintFromMessage;
            }
        }

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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
        /* ========== FACILITY HERO HEADER (matches project_overview pattern) ========== */
        .facility-header-hero {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin: 0 20px 24px 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: visible;
        }
        .facility-header-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
            border-radius: 24px 24px 0 0;
        }
        .facility-header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .facility-header-left {
            display: flex;
            align-items: center;
            gap: 24px;
            min-width: 0;
            flex: 1;
        }
        .facility-header-icon {
            position: relative;
            width: 150px;
            height: 120px;
            border-radius: 20px;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
        }
        .facility-header-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .facility-header-icon-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .facility-header-icon-placeholder i {
            font-size: 3rem;
            color: rgba(255,255,255,0.85);
        }
        .facility-header-info {
            min-width: 0;
            flex: 1;
        }
        .facility-title-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .facility-header-info h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
            line-height: 1.2;
        }
        .facility-header-subtitle {
            font-size: 1.05em;
            color: #6c757d;
            margin: 0 0 4px;
            font-weight: 500;
        }
        .facility-header-context {
            font-size: 0.92em;
            color: #555;
            margin: 0 0 8px;
        }
        .facility-header-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .facility-meta-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        .facility-meta-badge.fees-badge {
            background: rgba(72, 140, 154, 0.08);
            color: #3A6E7F;
            border: 1px solid rgba(72, 140, 154, 0.15);
            cursor: pointer;
        }
        .facility-meta-badge.fees-badge:hover {
            background: rgba(72, 140, 154, 0.15);
        }
        .facility-meta-badge.edit-badge {
            background: #fff;
            color: #495057;
            border: 1px solid #dee2e6;
        }
        .facility-meta-badge.edit-badge:hover {
            background: #488C9A;
            border-color: #488C9A;
            color: #fff;
        }
        .facility-header-stats {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: stretch;
        }
        .facility-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(72, 140, 154, 0.07);
            padding: 12px 20px;
            border-radius: 12px;
            min-width: 100px;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .facility-stat:hover {
            background: rgba(72, 140, 154, 0.12);
            border-color: rgba(72, 140, 154, 0.15);
        }
        .facility-stat.clickable {
            cursor: pointer;
        }
        .facility-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #293E4C;
            line-height: 1;
        }
        .facility-stat-label {
            font-size: 0.72rem;
            color: #6c757d;
            text-transform: uppercase;
            margin-top: 4px;
            text-align: center;
            letter-spacing: 0.02em;
        }
        .facility-stat.accent-green .facility-stat-value { color: #059669; }
        .facility-stat.accent-green { background: rgba(5,150,105,0.06); border-color: rgba(5,150,105,0.12); }
        .facility-stat.accent-teal {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
        }
        .facility-stat.accent-teal .facility-stat-value,
        .facility-stat.accent-teal .facility-stat-label {
            color: rgba(255,255,255,0.9);
        }
        /* Keep old classes for warehouse card listing */
        .warehouse-image-placeholder {
            width: 200px;
            height: 150px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            margin-right: 20px;
        }
        .warehouse-image-placeholder i {
            font-size: 4rem;
            color: #488C9A;
            opacity: 0.7;
        }
        .warehouse-card-image-placeholder {
            width: 100%;
            height: 150px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px 8px 0 0;
        }
        .warehouse-card-image-placeholder i {
            font-size: 3rem;
            color: #488C9A;
            opacity: 0.7;
        }
        /* Responsive for facility header */
        @media (max-width: 992px) {
            .facility-header-content { flex-direction: column; align-items: flex-start; gap: 16px; }
            .facility-header-stats { width: 100%; }
            .facility-header-hero { margin: 0 10px 20px 10px; padding: 20px; }
        }
        @media (max-width: 768px) {
            .facility-header-icon { width: 100px; height: 80px; border-radius: 14px; }
            .facility-header-info h1 { font-size: 1.5em; }
            .facility-header-stats { flex-direction: column; gap: 8px; }
            .facility-stat { flex-direction: row; gap: 10px; padding: 10px 16px; }
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
        .tabs-container {
            width: 100%;
            margin-bottom: 14px;
        }
        .tabs {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .tabs button {
            padding: 10px 22px;
            border: none;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: 0.92em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .tabs button:hover {
            background: #e2e8f0;
        }
        .tabs button.active {
            background: #488C9A;
            color: #fff;
            box-shadow: 0 3px 8px rgba(72, 140, 154, 0.24);
        }
        .tab-content {
            display: none;
            margin-top: 12px;
        }
        .tab-content.active {
            display: block;
        }
        .tab-heading-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .tab-heading-row h2 {
            margin: 0;
        }
        .action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 9px 18px;
            border: none;
            border-radius: 8px;
            background: #488C9A;
            color: #fff;
            font-size: 0.88em;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            line-height: 1.25;
        }
        .action-button:hover {
            background: #3A6E7F;
            color: #fff;
            text-decoration: none;
        }
        .action-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .action-button-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #d1d5db;
        }
        .action-button-secondary:hover {
            background: #e2e8f0;
            color: #293E4C;
        }
        .table-responsive a:not(.action-button):not(.view-details-btn) {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
            border: 1px solid #d3e6ef;
            background: #eef7fb;
            color: #2f6471;
            font-size: 0.86em;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .table-responsive a:not(.action-button):not(.view-details-btn):hover {
            background: #deedf4;
            border-color: #b7d4e1;
            color: #244d58;
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

        /* Modern Cost Overview Cards */
                 .cost-overview-container {
             display: grid;
             grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
             gap: 20px;
             margin-bottom: 30px;
             padding: 0;
             overflow: visible;
             position: relative;
         }
        
        .cost-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border: 1px solid rgba(72, 140, 154, 0.1);
            border-radius: 12px;
            padding: 24px 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .cost-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A, #293E4C);
        }
        
        .cost-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 140, 154, 0.15);
        }
        
        .cost-card.total-cost {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
        }
        
        .cost-card.total-cost::before {
            background: linear-gradient(90deg, #fff, rgba(255,255,255,0.8));
        }
        
        .cost-icon {
            font-size: 32px;
            margin-bottom: 12px;
            opacity: 0.8;
        }
        
        .cost-value {
            font-size: 28px;
            font-weight: 700;
            color: #293E4C;
            margin-bottom: 8px;
            line-height: 1;
        }
        
        .cost-card.total-cost .cost-value {
            color: white;
        }
        
        .cost-label {
            font-size: 14px;
            font-weight: 500;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        
                 .cost-card.total-cost .cost-label {
             color: rgba(255, 255, 255, 0.9);
         }
         
         /* Clickable cost card styling */
         .clickable-cost-card {
             cursor: pointer;
             position: relative;
             overflow: visible;
         }
         
         .clickable-cost-card:hover {
             transform: translateY(-2px);
             box-shadow: 0 8px 25px rgba(72, 140, 154, 0.15);
         }
         
         .cost-dropdown-arrow {
             position: absolute;
             top: 16px;
             right: 16px;
             color: rgba(255, 255, 255, 0.8);
             font-size: 12px;
             transition: transform 0.3s ease;
         }
         
         .clickable-cost-card.open .cost-dropdown-arrow {
             transform: rotate(180deg);
         }
         
         .cost-card-dropdown {
             display: none;
             position: absolute;
             top: 100%;
             left: 0;
             right: 0;
             background: white;
             border: 1px solid #ddd;
             border-radius: 0 0 12px 12px;
             box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
             z-index: 2000;
             padding: 16px;
             margin-top: -1px;
             border-top: none;
         }
         
         .clickable-cost-card.open .cost-card-dropdown {
             display: block !important;
             animation: dropdownSlide 0.3s ease;
         }
         
         @keyframes dropdownSlide {
             from {
                 opacity: 0;
                 transform: translateY(-10px);
             }
             to {
                 opacity: 1;
                 transform: translateY(0);
             }
         }
         
         .cost-dropdown-item {
             display: flex;
             justify-content: space-between;
             padding: 8px 0;
             border-bottom: 1px solid #f0f0f0;
         }
         
         .cost-dropdown-item:last-child {
             border-bottom: none;
         }
         
         .cost-dropdown-divider {
             border-top: 2px solid #488C9A;
             margin: 12px 0 8px 0;
         }
         
         .cost-dropdown-label {
             font-weight: 500;
             color: #555;
             font-size: 0.9em;
         }
         
         .cost-dropdown-amount {
             font-weight: 600;
             color: #488C9A;
             font-size: 0.9em;
         }
         
         .total-item .cost-dropdown-label,
         .total-item .cost-dropdown-amount {
             font-weight: 700;
             color: #293E4C;
             font-size: 1em;
         }
         
         /* Inventory table cost styling */
         .days-badge {
             background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
             color: #1565c0;
             padding: 4px 8px;
             border-radius: 12px;
             font-size: 0.85em;
             font-weight: 600;
             display: inline-block;
         }
         
         .cost-value {
             color: #488C9A;
             font-weight: 600;
         }
         
         .total-cost-value {
             color: #293E4C;
             font-weight: 700;
         }
         
         /* Header dropdown styling */
         .cost-breakdown-header {
             position: relative;
             cursor: pointer;
         }
         
         .cost-breakdown-dropdown {
             display: none;
             position: absolute;
             top: 100%;
             left: 50%;
             transform: translateX(-50%);
             background: white;
             border: 1px solid #ddd;
             border-radius: 8px;
             box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
             padding: 12px;
             z-index: 1000;
             min-width: 250px;
             margin-top: 5px;
         }
         
         .cost-breakdown-header:hover .cost-breakdown-dropdown {
             display: block;
         }
         
         .cost-breakdown-item {
             display: flex;
             justify-content: space-between;
             padding: 6px 0;
             border-bottom: 1px solid #f0f0f0;
         }
         
         .cost-breakdown-item:last-child {
             border-bottom: none;
         }
         
         .cost-label {
             font-weight: 500;
             color: #555;
         }
         
         .cost-amount {
             font-weight: 600;
             color: #488C9A;
         }
         
         /* Clickable total cost styling */
         .total-cost-clickable {
             color: #488C9A;
             font-weight: 700;
             cursor: pointer;
             text-decoration: underline;
             transition: all 0.3s ease;
         }
         
         .total-cost-clickable:hover {
             color: #293E4C;
             transform: scale(1.05);
         }
         
         /* Enhanced View Details button */
         .view-details-btn {
             display: inline-flex;
             align-items: center;
             gap: 6px;
             background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
             color: white;
             padding: 8px 12px;
             border-radius: 6px;
             text-decoration: none;
             font-size: 0.85em;
             font-weight: 500;
             transition: all 0.3s ease;
             box-shadow: 0 2px 4px rgba(72, 140, 154, 0.3);
         }
         
         .view-details-btn:hover {
             background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
             transform: translateY(-1px);
             box-shadow: 0 4px 8px rgba(72, 140, 154, 0.4);
             color: white;
             text-decoration: none;
         }
         
         .view-details-btn svg {
             flex-shrink: 0;
         }
         
         /* Cost Modal Styling */
         .cost-modal {
             display: none;
             position: fixed;
             z-index: 2000;
             left: 0;
             top: 0;
             width: 100%;
             height: 100%;
             background-color: rgba(0, 0, 0, 0.5);
             backdrop-filter: blur(4px);
         }
         
         .cost-modal-content {
             background-color: white;
             margin: 10% auto;
             border-radius: 12px;
             width: 90%;
             max-width: 400px;
             box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
             overflow: hidden;
             animation: modalSlideIn 0.3s ease;
         }
         
         @keyframes modalSlideIn {
             from {
                 opacity: 0;
                 transform: translateY(-50px) scale(0.9);
             }
             to {
                 opacity: 1;
                 transform: translateY(0) scale(1);
             }
         }
         
         .cost-modal-header {
             background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
             color: white;
             padding: 16px 20px;
             display: flex;
             justify-content: space-between;
             align-items: center;
         }
         
         .cost-modal-header h3 {
             margin: 0;
             font-size: 1.2em;
             color: white;
         }
         
         .cost-modal-close {
             font-size: 24px;
             font-weight: bold;
             cursor: pointer;
             line-height: 1;
             transition: opacity 0.3s ease;
         }
         
         .cost-modal-close:hover {
             opacity: 0.7;
         }
         
         .cost-modal-body {
             padding: 20px;
         }
         
         .cost-breakdown-row {
             display: flex;
             justify-content: space-between;
             align-items: center;
             padding: 10px 0;
             border-bottom: 1px solid #f0f0f0;
         }
         
         .cost-breakdown-row:last-child {
             border-bottom: none;
         }
         
         .cost-breakdown-divider {
             border-top: 2px solid #488C9A;
             margin: 15px 0 10px 0;
         }
         
         .breakdown-label {
             font-weight: 500;
             color: #555;
         }
         
         .breakdown-value {
             font-weight: 600;
             color: #293E4C;
         }
         
         .total-row .breakdown-label,
         .total-row .breakdown-value {
             font-weight: 700;
             font-size: 1.1em;
             color: #488C9A;
         }
        .sub-tabs-container {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 16px;
            padding: 3px;
            border-radius: 8px;
            background: #f1f5f9;
            flex-wrap: wrap;
        }
        .sub-tab-button {
            padding: 7px 16px;
            cursor: pointer;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: #475569;
            font-size: 0.88em;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .sub-tab-button:hover {
            background: #e2e8f0;
        }
        .sub-tab-button.active {
            background: #fff;
            color: #293E4C;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        /* New styles for warehouse cards */
        .warehouse-cards-container {
            display: flex;
            flex-wrap: wrap;
            gap: 75px; /* Space between cards */
            justify-content: flex-start; /* Align cards to the start */
            padding: 10px 0; /* Padding around the container */
        }
        .warehouse-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden; /* Ensures image corners are rounded if image is larger */
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            width: 425px;
            min-width: 280px; /* Minimum width before wrapping */
            background-color: #fff;
            display: flex; /* Added for flex column layout */
            flex-direction: column; /* Added for flex column layout */
        }
        .warehouse-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        .warehouse-card-link {
            display: flex; /* Make link a flex container */
            flex-direction: column; /* Stack image, name, details vertically */
            text-decoration: none;
            color: inherit; /* Inherit text color from parent */
            height: 100%; /* Make link fill the card */
        }
        .warehouse-card-image {
            width: 100%;
            height: 180px; /* Fixed height for images */
            object-fit: cover; /* Crop image to fit */
            display: block;
            border-bottom: 1px solid #eee; /* Separator */
        }
        .warehouse-card-name {
            font-size: 1.15em;
            font-weight: 600;
            color: #293E4C;
            padding: 12px 15px;
            /* border-bottom: 1px solid #eee; */ /* Removed, image has border now */
            text-align: center;
        }
        .warehouse-card-details {
            padding: 10px 15px 15px 15px; /* More padding at bottom */
            font-size: 0.9em;
            line-height: 1.6;
            flex-grow: 1; /* Allows details to take up remaining space */
        }
        .warehouse-card-details p {
            margin: 5px 0;
            color: #555;
        }
        .warehouse-card-details p strong {
            color: #333;
        }

        /* Responsive adjustments for cards */
        @media (max-width: 992px) {
            .warehouse-card {
                width: calc(50% - 10px); /* 2 cards per row (20px gap * 1/2 items = 10px adjustment) */
            }
        }
        @media (max-width: 600px) {
            .warehouse-card {
                width: 100%; /* 1 card per row */
            }
        }

        @media (max-width: 768px) {
             .tabs {
                 gap: 6px;
             }
             .tabs button {
                 width: 100%;
                 text-align: center;
             }
             .sub-tabs-container {
                 display: flex;
                 width: 100%;
             }
             .sub-tab-button {
                 flex: 1;
                 text-align: center;
             }
             .tab-heading-row {
                 align-items: flex-start;
             }
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
             .cost-overview-container {
                 grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                 gap: 15px;
             }
             .cost-card {
                 padding: 16px 12px;
             }
             .cost-value {
                 font-size: 22px;
             }
             .cost-icon {
                 font-size: 24px;
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

        /* Cost Breakdown Modal (all users) */
        .cost-modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:3000; justify-content:center; align-items:center; }
        .cost-modal-overlay.active { display:flex; }
        .cost-modal-box { background:#fff; border-radius:12px; max-width:550px; width:90%; padding:0; box-shadow:0 20px 60px rgba(0,0,0,0.3); }
        .cost-modal-box .cost-modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 24px; border-bottom:1px solid #e5e7eb; }
        .cost-modal-box .cost-modal-header h3 { margin:0; font-size:1.1em; }
        .cost-modal-box .cost-modal-close { background:none; border:none; font-size:1.5em; cursor:pointer; color:#fff; opacity:0.85; }
        .cost-modal-box .cost-modal-close:hover { opacity:1; }
        .cost-modal-box .cost-modal-body { padding:20px 24px; }
        .cost-scope-note { margin:0 0 12px; padding:10px 12px; border:1px solid #d3e6ef; border-radius:8px; background:#eef7fb; color:#345768; font-size:0.88em; line-height:1.4; }
        .cost-modal-row { display:flex; justify-content:space-between; padding:8px 0; }
        .cost-modal-row.total-row { font-weight:700; font-size:1.05em; color:#293E4C; }
        .cost-modal-label { color:#6b7280; }
        .cost-modal-amount { font-weight:600; }
        .cost-modal-divider { border-top:1px solid #e5e7eb; margin:8px 0; }
        .cost-modal-footer { padding:12px 24px 18px; border-top:1px solid #e5e7eb; text-align:center; }

        /* ============================================== */
        /* ADMIN-SPECIFIC STYLES */
        /* ============================================== */
        <?php if ($isAdmin): ?>

        /* Verification panel */
        .verification-panel { background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:16px; margin-bottom:16px; }
        .verification-panel h4 { margin:0 0 8px; }
        .verification-table { width:100%; border-collapse:collapse; font-size:0.9em; }
        .verification-table th, .verification-table td { padding:6px 10px; border:1px solid #ddd; text-align:left; }
        .verification-ok { color:#059669; font-weight:600; }
        .verification-mismatch { color:#dc2626; font-weight:600; }

        /* Workflow banner */
        .workflow-banner { background:linear-gradient(135deg, #f0f9ff, #e0f2fe); border:1px solid #bae6fd; border-radius:12px; padding:20px; margin-bottom:20px; }
        .workflow-banner-title { font-weight:700; color:#0369a1; margin:0 0 12px; }
        .workflow-steps { display:flex; gap:16px; flex-wrap:wrap; }
        .workflow-step { display:flex; gap:10px; align-items:flex-start; flex:1; min-width:200px; }
        .workflow-step-badge { background:#488C9A; color:#fff; border-radius:50%; width:28px; height:28px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85em; flex-shrink:0; }
        .workflow-step strong { display:block; }
        .workflow-step span { font-size:0.85em; color:#6b7280; }

        /* Next step banner */
        .next-step-banner { background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:14px 18px; margin-bottom:16px; }
        .next-step-banner p { margin:0 0 8px; }
        .next-step-actions { display:flex; gap:8px; }

        /* Admin tab styles */
        .table-controls-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px; }
        .page-actions { display:flex; gap:8px; align-items:center; }

        /* Customs styles */
        .customs-filter-bar { display:flex; gap:8px; margin-bottom:12px; }
        .customs-filter-btn { padding:6px 14px; border:1px solid #d1d5db; border-radius:6px; background:#fff; cursor:pointer; font-size:0.9em; transition:all 0.2s; }
        .customs-filter-btn.active { background:#488C9A; color:#fff; border-color:#488C9A; }
        .customs-chip-row { display:flex; gap:10px; flex-wrap:wrap; }
        .customs-chip { display:inline-flex; flex-direction:column; align-items:center; padding:8px 14px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; }
        .customs-chip-value { font-size:1.2em; font-weight:700; }
        .customs-chip-label { font-size:0.75em; color:#6b7280; }
        .customs-status-badge { padding:3px 10px; border-radius:12px; font-size:0.85em; font-weight:600; }
        .customs-status-cleared { background:#d1fae5; color:#059669; }
        .customs-status-held { background:#fecaca; color:#dc2626; }
        .customs-action-bar { background:#f8f9fa; border:1px solid #e5e7eb; border-radius:8px; padding:16px; margin-bottom:16px; }
        .customs-action-bar-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
        .customs-action-close { background:none; border:none; font-size:1.5em; cursor:pointer; color:#6b7280; }
        .customs-form-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
        .customs-form-grid label { display:block; font-weight:600; margin-bottom:4px; font-size:0.9em; }
        .customs-form-grid input { width:100%; padding:8px; border:1px solid #d1d5db; border-radius:4px; }
        .hold-badge { background:#fecaca; color:#dc2626; padding:2px 8px; border-radius:10px; font-size:0.85em; font-weight:600; }
        .selection-counter { color:#6b7280; font-size:0.9em; }

        /* Receive Truckload Modal */
        #receiveTruckloadModal .modal-content {
            background: #fff;
            margin: 8% auto;
            padding: 0;
            width: 90%;
            max-width: 520px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            animation: modalSlideIn 0.3s ease;
        }
        #receiveTruckloadModal .modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            padding: 18px 24px;
            font-size: 1.15em;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #receiveTruckloadModal .close-receive-truckload-modal {
            position: absolute;
            top: 14px;
            right: 18px;
            font-size: 1.6em;
            color: #fff;
            cursor: pointer;
            background: none;
            border: none;
            line-height: 1;
            opacity: 0.85;
            transition: opacity 0.2s;
            z-index: 1;
        }
        #receiveTruckloadModal .close-receive-truckload-modal:hover { opacity: 1; }
        #receiveTruckloadModal .modal-content { position: relative; }
        #receiveTruckloadFormContainer {
            padding: 24px;
        }
        .modal-form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        .modal-form-row label {
            display: block;
            font-weight: 600;
            font-size: 0.9em;
            color: #374151;
            margin-bottom: 6px;
        }
        .modal-form-row input[type="text"],
        .modal-form-row input[type="date"],
        .modal-form-row input[type="file"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.95em;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }
        .modal-form-row input[type="text"]:focus,
        .modal-form-row input[type="date"]:focus {
            border-color: #488C9A;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.15);
            outline: none;
        }
        .modal-form-row small {
            display: block;
            color: #6b7280;
            margin-top: 5px;
            font-size: 0.85em;
        }
        #receiveTruckloadFormContainer .action-button {
            width: 100%;
            padding: 12px 24px;
            font-size: 1em;
            border-radius: 8px;
            margin-top: 8px;
        }

        /* Action button variants */
        .action-button-secondary { background:#f3f4f6 !important; color:#374151 !important; border:1px solid #d1d5db !important; }
        .action-button-secondary:hover { background:#e5e7eb !important; }
        .action-button-danger { background:#dc2626 !important; color:#fff !important; border:none !important; }
        .action-button-danger:hover { background:#b91c1c !important; }

        /* Drayage hold warning */
        .drayage-hold-warning-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:2000; display:flex; justify-content:center; align-items:center; }
        .drayage-hold-warning-box { background:#fff; border-radius:12px; max-width:500px; width:90%; padding:30px; text-align:center; }
        .drayage-hold-warning-icon { font-size:3em; color:#f59e0b; }
        .drayage-hold-warning-details { background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:12px; margin:12px 0; text-align:left; }
        .drayage-hold-warning-actions { display:flex; gap:10px; justify-content:center; margin-top:16px; }
        .drayage-modal-hold-banner { background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:10px 14px; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
        .drayage-modal-hold-icon { font-size:1.2em; color:#f59e0b; }

        /* Sub-tab styles */
        .sub-tab-content { display:none; }
        .sub-tab-content.active { display:block; }
        <?php endif; ?>
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
     <?php
     // Determine back link based on context
     $back_link = "manage_warehouses.php"; // Default back link
     if ($project_id && !$warehouse_id && !$module_batch_id) { // If only project_id is given and we show warehouse list OR error for project
         $back_link = "project_overview.php?id=" . $project_id;
     } elseif ($warehouse_id && $project_id) { // If warehouse and project are given (specific view)
         $back_link = "warehouse.php?project_id=" . $project_id; // Link back to project's warehouse list
     } elseif ($warehouse_id && $module_batch_id) { // If warehouse and batch are given (specific view from batch context)
         $back_link = "warehouse.php?module_batch_id=" . $module_batch_id; // Link back to batch's warehouse list
     } elseif ($module_batch_id && !$warehouse_id) { // Listing warehouses for a batch
         $back_link = "modules.php"; // Or to module_overview.php?batch_id=$module_batch_id
     } elseif ($warehouse_id) { // If only warehouse_id is given
         $back_link = "manage_warehouses.php";
     }
     ?>
    <?php
        require_once 'components/breadcrumbs.php';
        $from = $_GET['from'] ?? '';
        $backToManagePallets = ($from === 'manage_pallets');
        $backToModuleMovements = ($from === 'module_movements');
        $managePalletsUrl = 'manage_pallets.php' . ($project_id ? ('?project_id='.(int)$project_id) : '');
        $moduleMovementsUrl = 'module_movements.php' . ($project_id ? ('?project_id='.(int)$project_id) : '');

        if ($backToManagePallets) {
            // Always show a single back breadcrumb to Manage Pallets
            echo slp_render_breadcrumbs([
                'current_label' => ($warehouse_id ? ($warehouse_data['name'] ?? 'Warehouse Details') : 'Warehouse Locations'),
                'extra' => [ ['label' => 'Manage Pallets', 'url' => $managePalletsUrl] ]
            ]);
        } else if ($backToModuleMovements) {
            // Show a single back breadcrumb to Module Movements
            echo slp_render_breadcrumbs([
                'current_label' => ($warehouse_id ? ($warehouse_data['name'] ?? 'Warehouse Details') : 'Warehouse Locations'),
                'extra' => [ ['label' => 'Module Movements', 'url' => $moduleMovementsUrl] ]
            ]);
        } else {
            if ($project_id && !$warehouse_id && !$module_batch_id) {
                echo slp_render_breadcrumbs(['current_label' => 'Warehouse Locations', 'project_id' => (int)$project_id]);
            } elseif ($warehouse_id && $project_id) {
                // Only show "Warehouse Locations" crumb when the project truly spans multiple warehouses.
                $single_wh = isset($_GET['single_wh']) && $_GET['single_wh'] == '1';
                $project_warehouse_count = null;
                if (function_exists('getDBConnection')) {
                    $breadcrumb_conn = getDBConnection();
                    if ($breadcrumb_conn) {
                        $stmtWhCount = $breadcrumb_conn->prepare("
                        SELECT COUNT(DISTINCT wh.id) AS warehouse_count
                        FROM warehouses wh
                        WHERE EXISTS (
                            SELECT 1
                            FROM inventory_pallets ip
                            WHERE ip.assigned_project_id = ?
                              AND ip.current_warehouse_id = wh.id
                              AND ip.status = 'In Warehouse'
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM deliveries d
                            WHERE d.project_id = ?
                              AND d.warehouse_id = wh.id
                        )
                    ");
                        if ($stmtWhCount) {
                            $stmtWhCount->bind_param("ii", $project_id, $project_id);
                            $stmtWhCount->execute();
                            $stmtWhCount->bind_result($project_warehouse_count_result);
                            if ($stmtWhCount->fetch()) {
                                $project_warehouse_count = (int)$project_warehouse_count_result;
                            }
                            $stmtWhCount->close();
                        }
                        $breadcrumb_conn->close();
                    }
                }

                $show_locations_crumb = !$single_wh;
                if ($project_warehouse_count !== null && $project_warehouse_count <= 1) {
                    $show_locations_crumb = false;
                }

                if (!$show_locations_crumb) {
                    echo slp_render_breadcrumbs([
                        'current_label' => ($warehouse_data['name'] ?? 'Warehouse Details'),
                        'project_id' => (int)$project_id
                    ]);
                } else {
                    echo slp_render_breadcrumbs([
                        'current_label' => ($warehouse_data['name'] ?? 'Warehouse Details'),
                        'project_id' => (int)$project_id,
                        'extra' => [ ['label' => 'Warehouse Locations', 'url' => 'warehouse.php?project_id='.(int)$project_id] ]
                    ]);
                }
            } elseif ($module_batch_id && !$warehouse_id) {
                echo slp_render_breadcrumbs([
                    'current_label' => 'Warehouse Locations',
                    'extra' => [
                        ['label' => 'Modules', 'url' => 'modules.php'],
                        ['label' => 'Batch '.htmlspecialchars($origin_batch_vendor_name ?? $module_batch_id), 'url' => 'module_overview.php?batch_id='.(int)$module_batch_id]
                    ]
                ]);
            } elseif ($warehouse_id && $module_batch_id) {
                echo slp_render_breadcrumbs([
                    'current_label' => ($warehouse_data['name'] ?? 'Warehouse Details'),
                    'extra' => [
                        ['label' => 'Modules', 'url' => 'modules.php'],
                        ['label' => 'Batch '.htmlspecialchars($origin_batch_vendor_name ?? $module_batch_id), 'url' => 'module_overview.php?batch_id='.(int)$module_batch_id],
                        ['label' => 'Warehouse Locations', 'url' => 'warehouse.php?module_batch_id='.(int)$module_batch_id]
                    ]
                ]);
            } elseif ($warehouse_id) {
                echo slp_render_breadcrumbs([
                    'current_label' => ($warehouse_data['name'] ?? 'Warehouse Details'),
                    'extra' => [ ['label' => 'Warehouse Overview', 'url' => 'warehousing_overview.php'] ]
                ]);
            } else {
                echo slp_render_breadcrumbs([
                    'current_label' => 'Warehouse Information',
                    'extra' => [ ['label' => 'Warehouse Overview', 'url' => 'warehousing_overview.php'] ]
                ]);
            }
        }
    ?>

    <?php if (!empty($errorMessage)): ?>
        <p class="message error-message"><?php echo htmlspecialchars($errorMessage); ?></p>

    <?php elseif ($show_warehouse_list): ?>
        <p class="info-message" style="margin-bottom: 20px;">Inventory for Project '<?php echo htmlspecialchars($project_name_for_title); ?>' is located in the following warehouses:</p>
        <div class="warehouse-cards-container">
            <?php foreach ($relevant_warehouses as $wh): ?>
                <div class="warehouse-card">
                    <a href="warehouse.php?warehouse_id=<?php echo $wh['id']; ?>&project_id=<?php echo $project_id; ?>" class="warehouse-card-link">
                        <?php
                        $wh_image_path = "";
                        $has_wh_image = false;
                        if (!empty($wh['image_url'])) {
                            // Basic check if it's a full URL or a relative path
                            if (filter_var($wh['image_url'], FILTER_VALIDATE_URL)) {
                                $wh_image_path = $wh['image_url'];
                                $has_wh_image = true;
                            } else {
                                if (strpos($wh['image_url'], 'http') !== 0 && strpos($wh['image_url'], 'pictures/') !== 0 && strpos($wh['image_url'], 'uploads/') !== 0) {
                                   $wh_image_path = 'uploads/warehouse_images/' . ltrim(htmlspecialchars($wh['image_url']), '/');
                                } else {
                                   $wh_image_path = htmlspecialchars($wh['image_url']);
                                }
                                $has_wh_image = file_exists(__DIR__ . '/' . $wh_image_path);
                            }
                        }
                        ?>
                        <?php if ($has_wh_image): ?>
                            <img src="<?php echo $wh_image_path; ?>" alt="<?php echo htmlspecialchars($wh['name']); ?>" class="warehouse-card-image">
                        <?php else: ?>
                            <div class="warehouse-card-image-placeholder">
                                <i class="fas fa-warehouse"></i>
                            </div>
                        <?php endif; ?>
                        <div class="warehouse-card-name">
                            <?php echo htmlspecialchars($wh['name']); ?> (ID: <?php echo $wh['id']; ?>)
                        </div>
                        <div class="warehouse-card-details">
                            <p><strong>Address:</strong> <?php echo htmlspecialchars($wh['address'] ?? 'N/A'); ?></p>
                            <p><strong>Pallets Stored:</strong> <?php echo number_format($wh['pallets_in_warehouse'] ?? 0); ?> </p>
                            <p><strong>Modules Stored:</strong> <?php echo number_format($wh['modules_in_warehouse'] ?? 0); ?> </p>
                            <p><strong>Pallets In Transit:</strong> <?php echo number_format($wh['pallets_in_transit_to_wh'] ?? 0); ?> </p>
                            <p><strong>Modules In Transit:</strong> <?php echo number_format($wh['modules_in_transit_to_wh'] ?? 0); ?> </p>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($warehouse_data): ?>
        <!-- === START Standard Warehouse View === -->
        <?php
            // Resolve warehouse image
            $wh_image_path = '';
            $has_wh_main_image = false;
            if (!empty($warehouse_data['image_url'])) {
                if (filter_var($warehouse_data['image_url'], FILTER_VALIDATE_URL)) {
                    $wh_image_path = $warehouse_data['image_url'];
                    $has_wh_main_image = true;
                } else {
                    $wh_image_path = htmlspecialchars($warehouse_data['image_url']);
                    if (strpos($wh_image_path, 'uploads/') !== 0 && strpos($wh_image_path, 'pictures/') !== 0) {
                        $wh_image_path = 'uploads/warehouse_images/' . ltrim($wh_image_path, '/');
                    }
                    $has_wh_main_image = file_exists(__DIR__ . '/' . $wh_image_path);
                }
            }
            $is_port_facility = !empty($warehouse_data['is_port']) && $warehouse_data['is_port'] == 1;
            $cost_scope_summary = 'Facility-wide costs across all projects in this location.';
            if ($project_id) {
                $project_scope_name = $project_name_for_title ?: ('Project #' . (int)$project_id);
                $cost_scope_summary = 'Project-only costs for ' . $project_scope_name . '.';
            } elseif (!$isAdmin && $module_batch_id) {
                $batch_scope_name = $origin_batch_vendor_name ?: ('Batch #' . (int)$module_batch_id);
                $cost_scope_summary = 'Filtered costs for ' . $batch_scope_name . '.';
            }
        ?>
        <div class="facility-header-hero">
            <div class="facility-header-content">
                <div class="facility-header-left">
                    <div class="facility-header-icon">
                        <?php if ($has_wh_main_image): ?>
                            <img src="<?php echo $wh_image_path; ?>" alt="<?php echo htmlspecialchars($warehouse_data['name']); ?>">
                        <?php else: ?>
                            <div class="facility-header-icon-placeholder">
                                <i class="fas fa-<?php echo $is_port_facility ? 'anchor' : 'warehouse'; ?>"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="facility-header-info">
                        <div class="facility-title-row">
                            <h1><?php echo htmlspecialchars($warehouse_data['name']); ?></h1>
                        </div>
                        <?php if ($project_name_for_title): ?>
                            <p class="facility-header-context">Viewing inventory for Project: <strong><?php echo htmlspecialchars($project_name_for_title); ?></strong></p>
                        <?php elseif ($module_batch_id && $origin_batch_vendor_name): ?>
                            <p class="facility-header-context">Viewing inventory from Batch: <strong><?php echo htmlspecialchars($origin_batch_vendor_name); ?></strong></p>
                        <?php endif; ?>
                        <p class="facility-header-subtitle"><?php echo htmlspecialchars($warehouse_data['address']); ?></p>
                        <div class="facility-header-meta">
                            <?php if (!empty($warehouse_costs_all)): ?>
                                <?php $fee_count = count($warehouse_costs_all); ?>
                                <button type="button" class="facility-meta-badge fees-badge" onclick="openFeeModal()">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    Cost Structure (<?php echo $fee_count; ?> fee<?php echo $fee_count !== 1 ? 's' : ''; ?>)
                                </button>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                                <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="facility-meta-badge edit-badge">
                                    <i class="fas fa-pencil-alt"></i> Edit <?php echo $is_port_facility ? 'Port' : 'Warehouse'; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="facility-header-stats">
                    <div class="facility-stat">
                        <span class="facility-stat-value"><?php echo number_format($total_pallets_count ?? 0); ?></span>
                        <span class="facility-stat-label">Pallets Stored</span>
                    </div>
                    <div class="facility-stat">
                        <span class="facility-stat-value"><?php echo number_format($total_modules ?? 0); ?></span>
                        <span class="facility-stat-label">Modules</span>
                    </div>
                    <div id="facilityCostCard" class="facility-stat accent-teal clickable clickable-cost-card" role="button" tabindex="0" style="cursor:pointer;">
                        <span class="facility-stat-value">$<?php echo number_format($total_cost_to_date, 2); ?></span>
                        <span class="facility-stat-label">Est. Cost <i class="fas fa-expand-alt" style="font-size:0.7em; opacity:0.7;"></i></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isAdmin && !empty($successMessage)): ?>
            <div class="success-message"><?php echo $successMessage; ?></div>
        <?php endif; ?>
        <?php if ($isAdmin && !empty($customs_action_result) && !empty($customs_action_result['rows']) && is_array($customs_action_result['rows'])):
            $verification_expected_status = (string)($customs_action_result['expected_status'] ?? '');
            $verification_mismatch_count = 0;
            foreach ($customs_action_result['rows'] as $verification_row_tmp) {
                if ((string)($verification_row_tmp['status'] ?? '') !== $verification_expected_status) {
                    $verification_mismatch_count++;
                }
            }
            if ($verification_mismatch_count > 0): ?>
            <div class="verification-panel alert">
                <h4>Customs Action Verification</h4>
                <p>Expected status: <strong><?php echo htmlspecialchars($verification_expected_status); ?></strong>. <?php echo number_format($verification_mismatch_count); ?> pallet(s) did not end in the expected status.</p>
                <div class="verification-table-wrap">
                    <table class="verification-table">
                        <thead><tr><th>Pallet</th><th>Container</th><th>Current Status</th><th>Current Warehouse ID</th><th>Check</th></tr></thead>
                        <tbody>
                            <?php foreach ($customs_action_result['rows'] as $verification_row): ?>
                                <?php $status_match = ((string)($verification_row['status'] ?? '') === $verification_expected_status); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($verification_row['pallet_identifier'] ?? ('ID ' . (int)($verification_row['id'] ?? 0))); ?></td>
                                    <td><?php echo htmlspecialchars($verification_row['container_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($verification_row['status'] ?? 'N/A'); ?></td>
                                    <td><?php echo (int)($verification_row['current_warehouse_id'] ?? 0); ?></td>
                                    <td class="<?php echo $status_match ? 'verification-ok' : 'verification-mismatch'; ?>"><?php echo $status_match ? 'OK' : 'Mismatch'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; endif; ?>

        <?php if ($isAdmin): ?>
        <!-- ============================================== -->
        <!-- ADMIN TABS AND CONTENT -->
        <!-- ============================================== -->
        <?php
        $admin_total_modules_stored = 0;
        foreach ($admin_pallets_in_storage as $p) { $admin_total_modules_stored += (int)($p['quantity'] ?? 0); }
        ?>

        <?php if ($is_port && $show_customs_next_step_banner): ?>
            <div class="next-step-banner">
                <p>Container(s) received. Next step: review pallets in <strong>Customs &amp; Clearance</strong> and place any flagged pallets on hold.</p>
            </div>
        <?php endif; ?>

        <?php if ($is_port): ?>
        <div class="workflow-banner">
            <p class="workflow-banner-title">Port Customs Workflow</p>
            <div class="workflow-steps">
                <div class="workflow-step"><span class="workflow-step-badge">1</span><div><strong>Receive Container(s)</strong><span>Inbound Transit &gt; By Truckload</span></div></div>
                <div class="workflow-step"><span class="workflow-step-badge">2</span><div><strong>Select Pallets</strong><span>Open Customs &amp; Clearance and select from cleared pallets.</span></div></div>
                <div class="workflow-step"><span class="workflow-step-badge">3</span><div><strong>Place On Hold</strong><span>Set required reason plus optional notes/cost per pallet.</span></div></div>
                <div class="workflow-step"><span class="workflow-step-badge">4</span><div><strong>Release To Cleared</strong><span>Release selected held pallets after customs clearance.</span></div></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="tabs-container">
            <div class="tabs">
                <button id="storedInventoryTab" class="active"><?php echo $inventory_title; ?> (<?php echo $is_port ? count($admin_containers_cleared) : count($admin_pallets_in_storage); ?>)</button>
                <?php if ($is_port): ?>
                    <button id="customsHoldTab">Customs &amp; Clearance (<?php echo count($admin_port_cleared_pallets) + count($admin_port_customs_hold_pallets); ?>)</button>
                <?php endif; ?>
                <button id="inboundTransitTab">Inbound Transit (<?php echo count($admin_transit_pallets); ?>)</button>
                <button id="truckloadHistoryTab"><?php echo $history_title; ?> (<?php echo count($admin_inbound_history) + count($admin_outbound_history); ?>)</button>
            </div>
        </div>
        <!-- ADMIN TAB: STORED INVENTORY -->
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
                        $storedWattages = array_unique(array_column($admin_pallets_in_storage, 'wattage'));
                        sort($storedWattages);
                        foreach ($storedWattages as $w) { echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>'; }
                        ?>
                    </select>
                    <label>Project:</label>
                    <select id="storedProjectFilter">
                        <option value="">All</option>
                        <?php foreach ($all_projects as $proj) { echo '<option value="' . htmlspecialchars($proj['project_name']) . '">' . htmlspecialchars($proj['project_name']) . '</option>'; } ?>
                    </select>
                </div>
                <div class="page-actions">
                    <?php if (!$is_port): ?>
                        <a href="create_shipment.php?source_type=warehouse&source_id=<?php echo $warehouse_id; ?>&status_filter=<?php echo urlencode($received_status); ?>" class="action-button">Create Shipment</a>
                    <?php endif; ?>
                    <?php if ($is_port): ?>
                        <button id="moveContainerBtn" class="action-button" disabled>Move Container (Drayage)</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-responsive">
                <table id="storedTable">
                    <thead>
                        <tr>
                            <?php if ($is_port): ?>
                                <th><input type="checkbox" id="selectAllContainers" onchange="toggleAllContainers()"> Select All</th>
                                <th>Container Number</th><th>Project(s)</th><th>Origin Vendor</th><th>Total Pallets</th><th>Customs Hold</th><th>Total Modules</th><th>Wattage Breakdown</th><th>Arrival Date</th>
                            <?php else: ?>
                                <th>Identifier</th><th>Project</th><th>Origin Vendor</th><th>Wattage</th><th>Quantity</th><th>Arrival Date</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($is_port): ?>
                            <?php if (!empty($admin_containers_cleared)): ?>
                                <?php foreach ($admin_containers_cleared as $container): ?>
                                    <tr>
                                        <td><input type="checkbox" class="container-checkbox" value="<?php echo $container['delivery_id']; ?>" data-container-number="<?php echo htmlspecialchars($container['container_number'] ?? ''); ?>" data-hold-pallets="<?php echo (int)$container['hold_pallets']; ?>" data-hold-modules="<?php echo (int)$container['hold_modules']; ?>" onchange="toggleMoveContainerBtn()"></td>
                                        <td><?php echo htmlspecialchars($container['container_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($container['projects']); ?></td>
                                        <td><?php echo htmlspecialchars($container['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($container['total_pallets']); ?></td>
                                        <td><?php if ($container['hold_pallets'] > 0): ?><span class="hold-badge"><?php echo $container['hold_pallets']; ?> on hold</span><?php else: ?><span style="color:#9ca3af;">None</span><?php endif; ?></td>
                                        <td><?php echo number_format($container['total_modules']); ?></td>
                                        <td style="font-size:0.9em;"><?php echo htmlspecialchars($container['wattage_breakdown'] ?? 'N/A'); ?></td>
                                        <td><?php echo !empty($container['arrival_date']) && $container['arrival_date'] !== 'N/A' ? (new DateTime($container['arrival_date']))->format('m-d-Y') : 'N/A'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9">No containers currently cleared at this port.</td></tr>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if (!empty($admin_pallets_in_storage)): ?>
                                <?php foreach ($admin_pallets_in_storage as $pallet): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['assigned_project']); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                        <td><?php echo number_format($pallet['quantity']); ?></td>
                                        <td><?php echo !empty($pallet['arrival_date']) && $pallet['arrival_date'] !== 'N/A' ? (new DateTime($pallet['arrival_date']))->format('m-d-Y') : 'N/A'; ?></td>
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
        <!-- ADMIN TAB: CUSTOMS & CLEARANCE -->
        <div id="customsHoldContent" class="tab-content">
            <?php
            $eligible_customs_modules = 0;
            foreach ($admin_port_cleared_pallets as $eligible_row) { $eligible_customs_modules += (int)($eligible_row['quantity'] ?? 0); }
            $held_customs_modules = 0; $held_customs_cost_total = 0.0;
            foreach ($admin_port_customs_hold_pallets as $held_row) { $held_customs_modules += (int)($held_row['quantity'] ?? 0); $held_customs_cost_total += (float)($held_row['customs_hold_cost'] ?? 0); }
            $customs_all_pallets = array_merge($admin_port_cleared_pallets, $admin_port_customs_hold_pallets);
            $total_customs_count = count($customs_all_pallets);
            $cleared_count = count($admin_port_cleared_pallets);
            $held_count = count($admin_port_customs_hold_pallets);
            ?>
            <h2>Customs &amp; Clearance</h2>
            <p style="margin: 0 0 16px; color: #6b7280;">All customs-related pallets in one view. Filter by status, then select pallets to place on hold or release.</p>
            <div class="customs-filter-bar">
                <button type="button" class="customs-filter-btn active" data-filter="all" onclick="filterCustomsUnified('all')">All (<?php echo $total_customs_count; ?>)</button>
                <button type="button" class="customs-filter-btn" data-filter="cleared" onclick="filterCustomsUnified('cleared')">Cleared (<?php echo $cleared_count; ?>)</button>
                <button type="button" class="customs-filter-btn" data-filter="held" onclick="filterCustomsUnified('held')">On Hold (<?php echo $held_count; ?>)</button>
            </div>
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
            <div id="customsActionBar" class="customs-action-bar" style="display:none;">
                <div id="customsActionHold" style="display:none;">
                    <div class="customs-action-bar-header"><span>Place <strong id="holdActionCount">0</strong> selected pallet(s) on Customs Hold</span><button type="button" class="customs-action-close" onclick="closeCustomsActionBar()">&times;</button></div>
                    <form method="POST" id="customsHoldForm" onsubmit="return submitCustomsAction('hold')">
                        <input type="hidden" name="action" value="place_customs_hold">
                        <div id="holdPalletInputs"></div>
                        <div class="customs-form-grid">
                            <div><label for="hold_reason">Hold Reason *</label><input type="text" maxlength="120" name="customs_hold_reason" id="hold_reason" placeholder="Exam hold, document discrepancy..." required></div>
                            <div><label for="hold_cost_per_pallet">Cost per Pallet ($)</label><input type="number" step="0.01" min="0" name="customs_cost_per_pallet" id="hold_cost_per_pallet" value="0"></div>
                            <div><label for="hold_cost_notes">Notes (Optional)</label><input type="text" maxlength="255" name="customs_cost_notes" id="hold_cost_notes" placeholder="Broker charge, exam fee details..."></div>
                        </div>
                        <button type="submit" class="action-button action-button-danger" style="margin-top: 10px;">Place on Customs Hold</button>
                    </form>
                </div>
                <div id="customsActionRelease" style="display:none;">
                    <div class="customs-action-bar-header"><span>Release <strong id="releaseActionCount">0</strong> selected pallet(s) to Cleared Customs</span><button type="button" class="customs-action-close" onclick="closeCustomsActionBar()">&times;</button></div>
                    <form method="POST" id="customsReleaseForm" onsubmit="return submitCustomsAction('release')">
                        <input type="hidden" name="action" value="release_customs_hold">
                        <div id="releasePalletInputs"></div>
                        <div class="customs-form-grid">
                            <div><label for="release_cost_per_pallet">Additional Cost per Pallet ($)</label><input type="number" step="0.01" min="0" name="customs_cost_per_pallet" id="release_cost_per_pallet" value="0"></div>
                            <div><label for="release_cost_notes">Release Notes (Optional)</label><input type="text" maxlength="255" name="customs_cost_notes" id="release_cost_notes" placeholder="Released by customs, inspection complete..."></div>
                        </div>
                        <button type="submit" class="action-button" style="margin-top: 10px;">Release to Cleared Customs</button>
                    </form>
                </div>
                <div id="customsActionMixed" style="display:none;">
                    <div class="customs-action-bar-header"><span style="color:#b45309;">Select pallets of the same status to perform an action.</span><button type="button" class="customs-action-close" onclick="closeCustomsActionBar()">&times;</button></div>
                </div>
            </div>
            <div class="table-responsive">
                <table id="customsUnifiedTable">
                    <thead><tr><th><input type="checkbox" id="selectAllCustomsUnified" onchange="toggleAllCustomsUnified()"> Select</th><th>Pallet ID</th><th>Container</th><th>Project</th><th>Vendor</th><th>Wattage</th><th>Modules</th><th>Status</th><th>Hold Cost</th><th>Arrival Date</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (!empty($customs_all_pallets)): ?>
                            <?php foreach ($admin_port_cleared_pallets as $row): ?>
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
                                    <td><a href="pallet_details.php?pallet_id=<?php echo (int)$row['pallet_id']; ?>&from=warehouse_info&warehouse_id=<?php echo (int)$warehouse_id; ?>" style="color:#488C9A; text-decoration:none; font-weight:600;">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($admin_port_customs_hold_pallets as $row): ?>
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
                                    <td><a href="pallet_details.php?pallet_id=<?php echo (int)$row['pallet_id']; ?>&from=warehouse_info&warehouse_id=<?php echo (int)$warehouse_id; ?>" style="color:#488C9A; text-decoration:none; font-weight:600;">View</a></td>
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

        <!-- ADMIN TAB: INBOUND TRANSIT -->
        <div id="inboundTransitContent" class="tab-content">
            <div class="sub-tabs-container">
                <button class="sub-tab-button active" onclick="showTransitSubView('byTruckload')">By Truckload (<?php echo count($admin_transit_truckloads); ?>)</button>
                <button class="sub-tab-button" onclick="showTransitSubView('byPallet')">By Pallet (<?php echo count($admin_transit_pallets); ?>)</button>
            </div>
            <div id="transitByPalletView" class="sub-tab-content">
                <h2>Inbound Transit - Individual Pallets</h2>
                <div class="table-controls-header">
                    <div class="filter-controls">
                        <label>Search:</label><input type="text" id="transitSearch" placeholder="Filter by Identifier, Vendor...">
                        <label>Wattage:</label>
                        <select id="transitWattageFilter">
                            <option value="">All</option>
                            <?php $tw = array_unique(array_column($admin_transit_pallets, 'wattage')); sort($tw); foreach ($tw as $w) { echo '<option value="'.htmlspecialchars($w).'">'.htmlspecialchars($w).'W</option>'; } ?>
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="transitTable">
                        <thead><tr><th>Identifier</th><th>Project</th><th>Origin Vendor</th><th>Wattage</th><th>Quantity</th><th>Delivery BOL</th><th>Est. Arrival Date</th></tr></thead>
                        <tbody>
                            <?php if (!empty($admin_transit_pallets)): ?>
                                <?php foreach ($admin_transit_pallets as $pallet): ?>
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
            <div id="transitByTruckloadView" class="sub-tab-content active">
                <h2>Inbound Transit - By Truckload</h2>
                <?php if ($is_port): ?>
                    <div class="next-step-banner" style="margin-top: 0;">
                        <p><strong>Step 1:</strong> Receive containers here. Then go to <strong>Customs &amp; Clearance</strong> to place specific pallets on hold.</p>
                        <div class="next-step-actions"><button type="button" class="action-button action-button-secondary" onclick="showMainTab('customsHold');">Go to Customs &amp; Clearance</button></div>
                    </div>
                <?php endif; ?>
                <div class="table-controls-header">
                    <div class="filter-controls"><label for="transitTruckloadSearch">Search:</label><input type="text" id="transitTruckloadSearch" placeholder="Filter by project, BOL/container, vendor..."></div>
                    <div class="page-actions"><button id="receiveTruckloadBtn" class="action-button" disabled><?php echo $receiving_title; ?></button></div>
                </div>
                <div class="table-responsive">
                    <table id="transitTruckloadTable">
                        <thead><tr><th><input type="checkbox" id="selectAllTruckloads" onchange="toggleAllTruckloads()"> Select All</th><th>Project</th><th>BOL Number</th><th>Origin Vendor</th><th>Est. Arrival Date</th><th>Total Pallets</th><th>Total Modules</th><th>Wattage Breakdown</th></tr></thead>
                        <tbody>
                            <?php if (!empty($admin_transit_truckloads)): ?>
                                <?php foreach ($admin_transit_truckloads as $truckload): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_truckloads" value="<?php echo $truckload['delivery_id']; ?>" class="truckload-checkbox" onchange="updateReceiveTruckloadButton()"></td>
                                        <td><?php echo htmlspecialchars($truckload['projects']); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['bol_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['origin_vendor'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($truckload['est_arrival_date'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($truckload['total_pallets']); ?></td>
                                        <td><?php echo number_format($truckload['total_modules']); ?></td>
                                        <td style="font-size:0.9em;"><?php echo htmlspecialchars($truckload['wattage_breakdown'] ?? 'N/A'); ?></td>
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

        <!-- ADMIN TAB: TRUCKLOAD HISTORY -->
        <div id="truckloadHistoryContent" class="tab-content">
            <div class="sub-tabs-container">
                <button class="sub-tab-button active" onclick="showHistorySubView('inbound')">Inbound History (<?php echo count($admin_inbound_history); ?>)</button>
                <button class="sub-tab-button" onclick="showHistorySubView('outbound')">Outbound History (<?php echo count($admin_outbound_history); ?>)</button>
            </div>
            <div id="inboundHistoryView" class="sub-tab-content active">
                <h2>Inbound Truckload History</h2>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>BOL Number</th><th>Project</th><th>Supplier</th><th>Wattage</th><th>Modules (Pallets)</th><th>Arrival Date</th><th>Proof of Delivery</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php if (!empty($admin_inbound_history)): ?>
                                <?php foreach ($admin_inbound_history as $delivery): ?>
                                    <tr data-delivery-index="<?php echo $delivery['index']; ?>" class="<?php echo $delivery['is_mixed_wattage'] ? 'mixed-wattage-row' : ''; ?>"
                                        <?php if ($delivery['is_mixed_wattage']): ?>onclick="toggleInboundDetails(<?php echo $delivery['index']; ?>)" style="cursor: pointer;"<?php endif; ?>>
                                        <td><?php if ($delivery['is_mixed_wattage']): ?><span class="expand-icon" id="expand-icon-<?php echo $delivery['index']; ?>">&#9654;</span><?php endif; ?><?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['source_project']); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                        <td><?php echo $delivery['is_mixed_wattage'] ? 'Mixed (' . htmlspecialchars($delivery['wattages']) . 'W)' : htmlspecialchars($delivery['wattages']) . 'W'; ?></td>
                                        <td><?php echo number_format($delivery['total_modules']) . ' (' . number_format($delivery['total_pallets']) . ')'; ?></td>
                                        <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? 'N/A'); ?></td>
                                        <td><?php if (!empty($delivery['proof_of_delivery']) || ($delivery['has_warehouse_pod'] ?? 0) > 0): ?><a href="view_pod.php?delivery_id=<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>" target="_blank" style="color: #488C9A;">View POD</a><?php else: ?><button class="action-button" style="padding: 8px 15px;" onclick="uploadWarehousePOD(<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>, <?php echo explode(',', $delivery['project_ids'])[0]; ?>)">Upload POD</button><?php endif; ?></td>
                                        <td>
                                            <?php
                                                $inbound_view_delivery_id = (int)(explode(',', (string)($delivery['delivery_ids'] ?? '0'))[0] ?? 0);
                                                $inbound_view_project_id = (int)(explode(',', (string)($delivery['project_ids'] ?? '0'))[0] ?? 0);
                                            ?>
                                            <?php if ($inbound_view_delivery_id > 0 && $inbound_view_project_id > 0): ?>
                                                <a href="view_project.php?project_id=<?php echo $inbound_view_project_id; ?>&delivery_id=<?php echo $inbound_view_delivery_id; ?>" class="action-button" style="padding:3px 8px; font-size:0.9em;">View</a>
                                            <?php else: ?>
                                                <span style="color:#9ca3af;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($delivery['is_mixed_wattage'] && !empty($delivery['details'])): ?>
                                        <?php foreach ($delivery['details'] as $detail_index => $detail): ?>
                                            <tr id="inbound-detail-<?php echo $delivery['index']; ?>-<?php echo $detail_index; ?>" class="detail-row" style="display: none; background-color: #f8f9fa;">
                                                <td style="padding-left: 30px;">&boxur; Detail <?php echo $detail_index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($detail['project_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['wattage']); ?>W</td>
                                                <td><?php echo number_format($detail['quantity']) . ' (' . number_format($detail['pallet_count']) . ')'; ?></td>
                                                <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? 'N/A'); ?></td>
                                                <td>-</td>
                                                <td>
                                                    <?php
                                                        $inbound_detail_project_id = (int)($detail['project_id'] ?? 0);
                                                        $inbound_detail_delivery_id = (int)($detail['id'] ?? 0);
                                                    ?>
                                                    <?php if ($inbound_detail_project_id > 0 && $inbound_detail_delivery_id > 0): ?>
                                                        <a href="view_project.php?project_id=<?php echo $inbound_detail_project_id; ?>&delivery_id=<?php echo $inbound_detail_delivery_id; ?>" class="action-button" style="padding:2px 6px; font-size:0.8em;">View</a>
                                                    <?php else: ?>
                                                        <span style="color:#9ca3af;">N/A</span>
                                                    <?php endif; ?>
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
            <div id="outboundHistoryView" class="sub-tab-content">
                <h2>Outbound Truckload History</h2>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>BOL Number</th><th>Project</th><th>Supplier</th><th>Destination</th><th>Wattage</th><th>Modules (Pallets)</th><th>Departure Date</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php if (!empty($admin_outbound_history)): ?>
                                <?php foreach ($admin_outbound_history as $delivery): ?>
                                    <tr data-delivery-index="<?php echo $delivery['index']; ?>" class="<?php echo $delivery['is_mixed_wattage'] ? 'mixed-wattage-row' : ''; ?>"
                                        <?php if ($delivery['is_mixed_wattage']): ?>onclick="toggleOutboundDetails(<?php echo $delivery['index']; ?>)" style="cursor: pointer;"<?php endif; ?>>
                                        <td><?php if ($delivery['is_mixed_wattage']): ?><span class="expand-icon" id="outbound-expand-icon-<?php echo $delivery['index']; ?>">&#9654;</span><?php endif; ?><?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['destination_project']); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['destinations'] ?? 'N/A'); ?></td>
                                        <td><?php echo $delivery['is_mixed_wattage'] ? 'Mixed (' . htmlspecialchars($delivery['wattages']) . 'W)' : htmlspecialchars($delivery['wattages']) . 'W'; ?></td>
                                        <td><?php echo number_format($delivery['total_modules']) . ' (' . number_format($delivery['total_pallets']) . ')'; ?></td>
                                        <td><?php echo htmlspecialchars($delivery['departure_date'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php
                                                $outbound_view_delivery_id = (int)(explode(',', (string)($delivery['delivery_ids'] ?? '0'))[0] ?? 0);
                                                $outbound_view_project_id = (int)(explode(',', (string)($delivery['project_ids'] ?? '0'))[0] ?? 0);
                                            ?>
                                            <?php if ($outbound_view_delivery_id > 0 && $outbound_view_project_id > 0): ?>
                                                <a href="view_project.php?project_id=<?php echo $outbound_view_project_id; ?>&delivery_id=<?php echo $outbound_view_delivery_id; ?>" class="action-button" style="padding:3px 8px; font-size:0.9em;">View</a>
                                            <?php else: ?>
                                                <span style="color:#9ca3af;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($delivery['is_mixed_wattage'] && !empty($delivery['details'])): ?>
                                        <?php foreach ($delivery['details'] as $detail_index => $detail): ?>
                                            <tr id="outbound-detail-<?php echo $delivery['index']; ?>-<?php echo $detail_index; ?>" class="detail-row" style="display: none; background-color: #f8f9fa;">
                                                <td style="padding-left: 30px;">&boxur; Detail <?php echo $detail_index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($detail['project_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['destination'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['wattage']); ?>W</td>
                                                <td><?php echo number_format($detail['quantity']) . ' (' . number_format($detail['pallet_count']) . ')'; ?></td>
                                                <td><?php echo htmlspecialchars($delivery['departure_date'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php
                                                        $outbound_detail_project_id = (int)($detail['project_id'] ?? 0);
                                                        $outbound_detail_delivery_id = (int)($detail['id'] ?? 0);
                                                    ?>
                                                    <?php if ($outbound_detail_project_id > 0 && $outbound_detail_delivery_id > 0): ?>
                                                        <a href="view_project.php?project_id=<?php echo $outbound_detail_project_id; ?>&delivery_id=<?php echo $outbound_detail_delivery_id; ?>" class="action-button" style="padding:2px 6px; font-size:0.8em;">View</a>
                                                    <?php else: ?>
                                                        <span style="color:#9ca3af;">N/A</span>
                                                    <?php endif; ?>
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
        <!-- === END Admin Warehouse View === -->

        <?php else: ?>
        <!-- ============================================== -->
        <!-- REGULAR USER TABS AND CONTENT -->
        <!-- ============================================== -->
        <!-- Tabs -->
        <div class="tabs-container">
            <div class="tabs">
                <button class="tab-link active" onclick="openTab(event, 'InventoryView')">Inventory View (<?php echo count($inventory_pallets); ?>)</button>
                <button class="tab-link" onclick="openTab(event, 'TruckloadView')">Truckload History (<?php
                    $inbound_count = !empty($inbound_deliveries_for_table) ? count($inbound_deliveries_for_table) : 0;
                    $outbound_count = !empty($outbound_deliveries_for_table) ? count($outbound_deliveries_for_table) : 0;
                    echo $inbound_count + $outbound_count;
                ?>)</button>
            </div>
        </div>
        <!-- Tab Content: Inventory View (DEFAULT ACTIVE) -->
        <div id="InventoryView" class="tab-content active">
             <div class="tab-heading-row">
                <h2>Stored Inventory Details</h2>
             </div>
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
                  <?php if ($warehouse_id && !$project_id && !$module_batch_id): // Show project filter when viewing general warehouse ?>
                  <label for="inventoryProjectFilter">Project:</label>
                  <select id="inventoryProjectFilter">
                       <option value="">All Projects</option>
                       <option value="UNASSIGNED">Unassigned</option>
                       <?php
                       $unique_projects_inv = [];
                       if (!empty($inventory_pallets)) {
                           foreach($inventory_pallets as $p) { 
                               if (!empty($p['project_name'])) {
                                   $unique_projects_inv[$p['project_name']] = true; 
                               }
                           }
                           ksort($unique_projects_inv);
                           foreach (array_keys($unique_projects_inv) as $project_name):
                       ?>
                          <option value="<?php echo htmlspecialchars($project_name); ?>"><?php echo htmlspecialchars($project_name); ?></option>
                       <?php 
                           endforeach; 
                       }
                       ?>
                  </select>
                  <?php endif; ?>
             </div>
             <div class="table-responsive">
                 <table id="inventoryTable">
                     <thead>
                         <tr>
                             <?php if ($warehouse_id && !$project_id && !$module_batch_id): // Show project column when viewing general warehouse ?>
                             <th>Project</th>
                             <?php endif; ?>
                             <th>Pallet Identifier</th>
                             <th>Origin Vendor</th>
                             <th>Wattage</th>
                             <th>Quantity</th>
                             <th>Arrival Date</th>
                             <th>Days Stored</th>
                             <th>Est. Total Cost To Date</th>
                             <th>Actions</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php if (!empty($inventory_pallets)): ?>
                                                         <?php foreach ($inventory_pallets as $pallet): 
                                // Calculate costs for this pallet using new cost structure
                                $days_stored = max(0, intval($pallet['days_stored'] ?? 0));
                                // Sum ALL monthly fees (supports multiple fees per trigger type)
                                $monthly_storage_fee = 0;
                                if (!empty($warehouse_costs['monthly'])) {
                                    foreach ($warehouse_costs['monthly'] as $cost) {
                                        $monthly_storage_fee += floatval($cost['amount']);
                                    }
                                }
                                $daily_storage_cost = $monthly_storage_fee / 30; // Approximate daily cost
                                $storage_cost = $days_stored * $daily_storage_cost;
                                
                                // Add proportional in fee cost (in fee divided by total pallets)
                                $in_fee_per_pallet = $total_pallets_count > 0 ? $in_fee_cost / $total_pallets_count : 0;
                                $total_pallet_cost = $storage_cost + $in_fee_per_pallet;
                             ?>
                                 <tr>
                                     <?php if ($warehouse_id && !$project_id && !$module_batch_id): // Show project column when viewing general warehouse ?>
                                     <td><?php echo !empty($pallet['project_name']) ? htmlspecialchars($pallet['project_name']) : 'Unassigned'; ?></td>
                                     <?php endif; ?>
                                     <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                     <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                                     <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                     <td><?php echo number_format($pallet['quantity']); ?></td>
                                     <td><?php echo htmlspecialchars($pallet['arrival_date'] ?? 'N/A'); ?></td>
                                     <td>
                                         <span class="days-badge"><?php echo $days_stored; ?> days</span>
                                     </td>
                                     <td>
                                         <span class="total-cost-clickable" 
                                               onclick="showCostModal('<?php echo $pallet['pallet_identifier']; ?>', 
                                                                     <?php echo $days_stored; ?>, 
                                                                     <?php echo $monthly_storage_fee; ?>, 
                                                                     <?php echo $storage_cost; ?>,
                                                                     <?php echo $in_fee_per_pallet; ?>,
                                                                     <?php echo $total_pallet_cost; ?>)">
                                             $<?php echo number_format($total_pallet_cost, 2); ?>
                                         </span>
                                     </td>
                                     <td>
                                         <?php
                                             $palletDetailsUrl = 'pallet_details.php?pallet_id=' . (int)$pallet['pallet_id'];
                                             if ($module_batch_id) { $palletDetailsUrl .= '&origin_batch_id=' . (int)$module_batch_id; }
                                             if ($warehouse_id)     { $palletDetailsUrl .= '&warehouse_id=' . (int)$warehouse_id; }
                                             if ($project_id)       { $palletDetailsUrl .= '&project_id=' . (int)$project_id; }
                                             $palletDetailsUrl .= '&from=warehouse_info';
                                         ?>
                                          <a href="<?php echo $palletDetailsUrl; ?>" class="view-details-btn">
                                              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                  <circle cx="12" cy="12" r="3"></circle>
                                              </svg>
                                              View Details
                                          </a>
                                     </td>
                                 </tr>
                             <?php endforeach; ?>
                         <?php else: ?>
                             <tr><td colspan="<?php echo ($warehouse_id && !$project_id && !$module_batch_id) ? '9' : '8'; ?>">No inventory currently stored<?php 
                                if ($project_id) echo ' for this project'; 
                                elseif ($module_batch_id) echo ' from this module batch'; 
                             ?> in this warehouse.</td></tr>
                         <?php endif; ?>
                     </tbody>
                 </table>
             </div>
        </div>
        
        <!-- Tab Content: Truckload View -->
         <div id="TruckloadView" class="tab-content">
            <div class="tab-heading-row">
                <h2>Truckload History</h2>
            </div>
            <div class="sub-tabs-container">
                <button class="sub-tab-button active" onclick="showTruckloadSubView('arrivals')">View Arrivals (<?php echo !empty($inbound_deliveries_for_table) ? count($inbound_deliveries_for_table) : 0; ?>)</button>
                <button class="sub-tab-button" onclick="showTruckloadSubView('departures')">View Departures (<?php echo !empty($outbound_deliveries_for_table) ? count($outbound_deliveries_for_table) : 0; ?>)</button>
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
                             foreach($inbound_deliveries_for_table as $d) { foreach(explode(', ', $d['wattages'] ?? '') as $w) { if ($w !== '') $unique_wattages_inbound[$w] = true; } }
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
                                    <tr data-delivery-index="<?php echo $delivery['index']; ?>" 
                                        class="<?php echo $delivery['is_mixed_wattage'] ? 'mixed-wattage-row' : ''; ?>"
                                        <?php if ($delivery['is_mixed_wattage']): ?>
                                            onclick="toggleWarehouseInboundDetails(<?php echo $delivery['index']; ?>)" 
                                            style="cursor: pointer;"
                                        <?php endif; ?>>
                                        <td>
                                            <?php if ($delivery['is_mixed_wattage']): ?>
                                                <span class="expand-icon" id="wh-inbound-expand-icon-<?php echo $delivery['index']; ?>">▶</span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($delivery['is_mixed_wattage']): ?>
                                                Mixed (<?php echo htmlspecialchars($delivery['wattages']); ?>W)
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($delivery['wattages']); ?>W
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo number_format($delivery['total_modules']); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? 'N/A'); ?></td>
                                         <td>
                                             <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                                 <a href="view_pod.php?delivery_id=<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>" target="_blank">View</a>
                                             <?php else: ?>
                                                 N/A
                                             <?php endif; ?>
                                         </td>
                                         <td>
                                             <?php
                                                $inbound_view_delivery_id = (int)(explode(',', (string)($delivery['delivery_ids'] ?? '0'))[0] ?? 0);
                                                $inbound_view_project_id = $project_id ? (int)$project_id : (int)(explode(',', (string)($delivery['project_ids'] ?? '0'))[0] ?? 0);
                                             ?>
                                             <?php if ($inbound_view_delivery_id > 0 && $inbound_view_project_id > 0): ?>
                                                 <a href="view_project.php?project_id=<?php echo $inbound_view_project_id; ?>&delivery_id=<?php echo $inbound_view_delivery_id; ?>" class="action-button" style="padding:3px 8px; font-size:0.85em;" onclick="event.stopPropagation();">View</a>
                                             <?php else: ?>
                                                 <span style="color:#9ca3af;">N/A</span>
                                             <?php endif; ?>
                                         </td>
                                    </tr>
                                    
                                    <?php if ($delivery['is_mixed_wattage'] && !empty($delivery['details'])): ?>
                                        <?php foreach ($delivery['details'] as $detail_index => $detail): ?>
                                            <tr id="wh-inbound-detail-<?php echo $delivery['index']; ?>-<?php echo $detail_index; ?>" 
                                                class="detail-row" style="display: none; background-color: #f8f9fa;">
                                                <td style="padding-left: 30px;">└ Detail <?php echo $detail_index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['wattage']); ?>W</td>
                                                <td><?php echo number_format($detail['quantity']); ?></td>
                                                <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date'] ?? 'N/A'); ?></td>
                                                <td>-</td>
                                                <td>
                                                    <?php
                                                        $inbound_detail_project_id = $project_id ? (int)$project_id : (int)($detail['project_id'] ?? 0);
                                                        $inbound_detail_delivery_id = (int)($detail['id'] ?? 0);
                                                    ?>
                                                    <?php if ($inbound_detail_project_id > 0 && $inbound_detail_delivery_id > 0): ?>
                                                        <a href="view_project.php?project_id=<?php echo $inbound_detail_project_id; ?>&delivery_id=<?php echo $inbound_detail_delivery_id; ?>" class="action-button" style="padding:2px 6px; font-size:0.8em;">View</a>
                                                    <?php else: ?>
                                                        <span style="color:#9ca3af;">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
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
                            foreach($outbound_deliveries_for_table as $d) { foreach(explode(', ', $d['wattages'] ?? '') as $w) { if ($w !== '') $unique_wattages_outbound[$w] = true; } }
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
                                  <tr data-delivery-index="<?php echo $delivery['index']; ?>" 
                                      class="<?php echo $delivery['is_mixed_wattage'] ? 'mixed-wattage-row' : ''; ?>"
                                      <?php if ($delivery['is_mixed_wattage']): ?>
                                          onclick="toggleWarehouseOutboundDetails(<?php echo $delivery['index']; ?>)" 
                                          style="cursor: pointer;"
                                      <?php endif; ?>>
                                      <td>
                                          <?php if ($delivery['is_mixed_wattage']): ?>
                                              <span class="expand-icon" id="wh-outbound-expand-icon-<?php echo $delivery['index']; ?>">▶</span>
                                          <?php endif; ?>
                                          <?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?>
                                      </td>
                                      <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                      <td>
                                          <?php if ($delivery['is_mixed_wattage']): ?>
                                              Mixed (<?php echo htmlspecialchars($delivery['wattages']); ?>W)
                                          <?php else: ?>
                                              <?php echo htmlspecialchars($delivery['wattages']); ?>W
                                          <?php endif; ?>
                                      </td>
                                      <td><?php echo number_format($delivery['total_modules']); ?></td>
                                      <td><?php echo htmlspecialchars($delivery['left_warehouse_date'] ?? 'N/A'); ?></td>
                                       <td>
                                           <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                               <a href="view_pod.php?delivery_id=<?php echo explode(',', $delivery['delivery_ids'])[0]; ?>" target="_blank">View</a>
                                           <?php else: ?>
                                               N/A
                                           <?php endif; ?>
                                       </td>
                                       <td>
                                           <?php
                                              $outbound_view_delivery_id = (int)(explode(',', (string)($delivery['delivery_ids'] ?? '0'))[0] ?? 0);
                                              $outbound_view_project_id = $project_id ? (int)$project_id : (int)(explode(',', (string)($delivery['project_ids'] ?? '0'))[0] ?? 0);
                                           ?>
                                           <?php if ($outbound_view_delivery_id > 0 && $outbound_view_project_id > 0): ?>
                                               <a href="view_project.php?project_id=<?php echo $outbound_view_project_id; ?>&delivery_id=<?php echo $outbound_view_delivery_id; ?>" class="action-button" style="padding:3px 8px; font-size:0.85em;" onclick="event.stopPropagation();">View</a>
                                           <?php else: ?>
                                               <span style="color:#9ca3af;">N/A</span>
                                           <?php endif; ?>
                                       </td>
                                  </tr>
                                  
                                  <?php if ($delivery['is_mixed_wattage'] && !empty($delivery['details'])): ?>
                                      <?php foreach ($delivery['details'] as $detail_index => $detail): ?>
                                          <tr id="wh-outbound-detail-<?php echo $delivery['index']; ?>-<?php echo $detail_index; ?>" 
                                              class="detail-row" style="display: none; background-color: #f8f9fa;">
                                              <td style="padding-left: 30px;">└ Detail <?php echo $detail_index + 1; ?></td>
                                              <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                              <td><?php echo htmlspecialchars($detail['wattage']); ?>W</td>
                                              <td><?php echo number_format($detail['quantity']); ?></td>
                                              <td><?php echo htmlspecialchars($delivery['left_warehouse_date'] ?? 'N/A'); ?></td>
                                              <td>-</td>
                                              <td>
                                                  <?php
                                                      $outbound_detail_project_id = $project_id ? (int)$project_id : (int)($detail['project_id'] ?? 0);
                                                      $outbound_detail_delivery_id = (int)($detail['id'] ?? 0);
                                                  ?>
                                                  <?php if ($outbound_detail_project_id > 0 && $outbound_detail_delivery_id > 0): ?>
                                                      <a href="view_project.php?project_id=<?php echo $outbound_detail_project_id; ?>&delivery_id=<?php echo $outbound_detail_delivery_id; ?>" class="action-button" style="padding:2px 6px; font-size:0.8em;">View</a>
                                                  <?php else: ?>
                                                      <span style="color:#9ca3af;">N/A</span>
                                                  <?php endif; ?>
                                              </td>
                                          </tr>
                                      <?php endforeach; ?>
                                  <?php endif; ?>
                              <?php endforeach; ?>
                          <?php else: ?>
                              <tr><td colspan="7">No outbound truckloads recorded<?php echo $project_id ? ' for this project' : ''; ?> in this warehouse.</td></tr>
                          <?php endif; ?>
                      </tbody>
                  </table>
              </div>
         </div>
         <!-- === END Standard Warehouse View === -->
        <?php endif; ?><!-- end admin/user conditional -->
    <?php endif; ?>

    <!-- Cost Breakdown Modal -->
    <div id="costModal" class="cost-modal">
        <div class="cost-modal-content">
            <div class="cost-modal-header">
                <h3 id="modalTitle">Cost Breakdown</h3>
                <span class="cost-modal-close" onclick="closeCostModal()">&times;</span>
            </div>
            <div class="cost-modal-body">
                <div class="cost-breakdown-row">
                    <span class="breakdown-label">Days Stored:</span>
                    <span id="modalDaysStored" class="breakdown-value"></span>
                </div>
                <div class="cost-breakdown-row">
                    <span class="breakdown-label">Daily Storage Rate:</span>
                    <span id="modalDailyRate" class="breakdown-value"></span>
                </div>
                <div class="cost-breakdown-row">
                    <span class="breakdown-label">Monthly Storage Fee:</span>
                    <span id="modalMonthlyFee" class="breakdown-value"></span>
                </div>
                <div class="cost-breakdown-divider"></div>
                <div class="cost-breakdown-row total-row">
                    <span class="breakdown-label">Total Storage Cost:</span>
                    <span id="modalTotalCost" class="breakdown-value"></span>
                </div>
            </div>
        </div>
    </div>

<?php if ($isAdmin && $warehouse_data): ?>
<!-- ADMIN MODALS AND HIDDEN FORMS -->
<div id="receiveTruckloadFormContainer" style="display: none;">
    <?php if ($is_port): ?>
        <div class="next-step-banner" style="margin-bottom: 12px;"><p>After receiving, use the <strong>Customs Hold</strong> tab to place specific pallets on hold.</p></div>
        <div class="modal-form-row">
            <div><label for="house_bol">House BOL:</label><input type="text" id="house_bol" name="house_bol" placeholder="House Bill of Lading"></div>
            <div><label for="master_bol">Master BOL:</label><input type="text" id="master_bol" name="master_bol" placeholder="Master Bill of Lading"></div>
        </div>
        <div class="modal-form-row"><div><label for="actual_truckload_arrival_date">Container Cleared Date:</label><input type="date" id="actual_truckload_arrival_date" name="actual_truckload_arrival_date" required></div></div>
    <?php else: ?>
        <div class="modal-form-row">
            <div><label for="receive_truckload_bol">BOL Number (if different):</label><input type="text" id="receive_truckload_bol" name="receive_truckload_bol" placeholder="Leave blank to use existing BOL numbers"></div>
            <div><label for="actual_truckload_arrival_date">Actual Arrival Date:</label><input type="date" id="actual_truckload_arrival_date" name="actual_truckload_arrival_date" required></div>
        </div>
        <div class="modal-form-row"><div><label for="pod_file">Proof of Delivery (POD) - Optional:</label><input type="file" id="pod_file" name="pod_file" accept=".pdf,.jpg,.jpeg,.png"><small style="display: block; color: #666; margin-top: 5px;">PDF, JPG, PNG files up to 5MB</small></div></div>
    <?php endif; ?>
    <div style="margin-top: 20px; text-align: center;"><button type="button" id="confirmReceiveTruckloadBtn" class="action-button"><?php echo $is_port ? 'Receive Container(s)' : 'Receive Selected Truckloads'; ?></button></div>
</div>

<div id="receiveTruckloadModal" class="modal">
    <div class="modal-content">
        <span class="close-receive-truckload-modal">&times;</span>
        <?php if ($is_port): ?>
            <div class="modal-header">
                <span>Receive Container(s) <span style="font-size: 0.8em; font-weight: normal; opacity: 0.85;">(Cleared Customs)</span></span>
            </div>
        <?php else: ?>
            <div class="modal-header"><span><?php echo $receiving_title; ?></span></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_port): ?>
<div id="moveContainerModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <span class="close-move-container-modal">&times;</span>
        <div class="shipment-details-modal-content">
            <h2 class="section-title" style="margin-top:0; text-align:center;">Move Container (Drayage)</h2>
            <div id="drayageHoldBanner" style="display:none;"></div>
            <form id="moveContainerForm" method="POST" action="create_shipment.php">
                <div class="form-row">
                    <div><label for="move_departure_date">Departure Date:</label><input type="date" id="move_departure_date" name="departure_date" required></div>
                    <div><label for="move_est_arrival_date">Est. Arrival Date:</label><input type="date" id="move_est_arrival_date" name="est_arrival_date" required></div>
                </div>
                <div class="form-row">
                    <div><label for="move_freight_cost">Freight Cost ($):</label><input type="number" id="move_freight_cost" name="freight_cost" step="0.01" min="0"></div>
                    <div><label for="move_customer_cost">Customer Cost ($):</label><input type="number" id="move_customer_cost" name="customer_cost" step="0.01" min="0"></div>
                </div>
                <div class="origin-destination-section">
                    <div class="location-container" style="display: flex; align-items: flex-start; gap: 20px;">
                        <div class="origin-section" style="flex: 1;">
                            <label style="margin-bottom: 10px; display:block; font-weight: 600;">Origin:</label>
                            <div style="padding: 12px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; min-height: 45px; display: flex; align-items: center;">
                                <strong><?php echo htmlspecialchars($warehouse_data['name']); ?></strong>
                            </div>
                        </div>
                        <div class="distance-separator" style="display: flex; flex-direction: column; justify-content: center; align-items: center; margin-top: 35px;">
                            <div style="font-size: 1.8em; color: #488C9A; margin-bottom: 5px;">&rarr;</div>
                            <div id="drayageDistanceDisplay" style="text-align: center; font-weight: bold; color: #488C9A; white-space: nowrap; font-size: 0.85em;"></div>
                        </div>
                        <div class="destination-section" style="flex: 1;">
                            <label style="margin-bottom: 10px; display:block; font-weight: 600;">Destination:</label>
                            <div class="destination-radio-group" style="display: flex; gap: 15px; margin-bottom: 10px;">
                                <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;"><input type="radio" name="destination_type" value="project" checked onchange="updateMoveDestinations()" style="margin-right: 5px;"> Project</label>
                                <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;"><input type="radio" name="destination_type" value="warehouse" onchange="updateMoveDestinations()" style="margin-right: 5px;"> Warehouse</label>
                            </div>
                            <select id="move_destination_id" name="destination_id" required onchange="calculateDrayageMiles()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"><option value="">-- Select Destination --</option></select>
                        </div>
                    </div>
                    <input type="hidden" id="move_miles" name="miles" value="">
                </div>
                <input type="hidden" name="action" value="ship_pallets">
                <input type="hidden" name="origin_type" value="warehouse">
                <input type="hidden" name="origin_id" value="<?php echo $warehouse_id; ?>">
                <input type="hidden" id="container_ids_input" name="drayage_container_ids" value="">
                <input type="hidden" id="pallet_ids_container" name="selected_pallets" value="">
                <input type="hidden" id="bol_number_input" name="bol_number" value="">
                <input type="hidden" id="container_number_input" name="container_number" value="">
                <button type="submit" id="confirmMoveContainerBtn" class="action-button" style="margin-top:15px;">Create Drayage Shipment</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if ($warehouse_data): ?>
<!-- Cost Breakdown Modal (all users) -->
<div class="cost-modal-overlay" id="costBreakdownModal">
    <div class="cost-modal-box">
        <div class="cost-modal-header">
            <h3>Cost Breakdown - <?php echo htmlspecialchars($warehouse_data['name']); ?></h3>
            <button class="cost-modal-close" onclick="closeCostBreakdownModal()">&times;</button>
        </div>
        <div class="cost-modal-body">
            <p class="cost-scope-note"><strong>Scope:</strong> <?php echo htmlspecialchars($cost_scope_summary); ?></p>
            <div class="cost-modal-row"><span class="cost-modal-label">Current Monthly Accrual</span><span class="cost-modal-amount">$<?php echo number_format($current_monthly_accrual ?? 0, 2); ?> / mo</span></div>
            <div class="cost-modal-row"><span class="cost-modal-label">In Fee Cost</span><span class="cost-modal-amount">$<?php echo number_format($in_fee_cost, 2); ?></span></div>
            <div class="cost-modal-row"><span class="cost-modal-label">Out Fee Cost</span><span class="cost-modal-amount">$<?php echo number_format($out_fee_cost, 2); ?></span></div>
            <div class="cost-modal-row"><span class="cost-modal-label">Storage Cost To Date</span><span class="cost-modal-amount">$<?php echo number_format($total_storage_cost_actual, 2); ?> (<?php echo number_format($average_days, 1); ?> days avg)</span></div>
            <div class="cost-modal-divider"></div>
            <div class="cost-modal-row total-row"><span class="cost-modal-label">Total Est. Cost</span><span class="cost-modal-amount">$<?php echo number_format($total_cost_to_date, 2); ?></span></div>
        </div>
        <?php if (!empty($warehouse_costs_all) || !empty($warehouse_fees['all_items'])): ?>
        <div class="cost-modal-footer">
            <button type="button" class="action-button" style="font-size:0.85em; padding:8px 16px;" onclick="closeCostBreakdownModal(); openFeeModal();"><i class="fas fa-file-invoice-dollar"></i> View Fee Structure</button>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</main>

<script>
    // --- Fee Modal Functions ---
    function openFeeModal() {
        document.getElementById('feeModal').style.display = 'block';
    }

    function closeFeeModal() {
        document.getElementById('feeModal').style.display = 'none';
    }

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

         // Handle project filter for inventory table
         const projectSelect = document.getElementById('inventoryProjectFilter');
         const projectFilter = (tableId === 'inventoryTable' && projectSelect) ? projectSelect.value : '';

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

             // Handle project filter for inventory table
             if (show && projectFilter && tableId === 'inventoryTable') {
                 // Determine project cell index - check if we have the project column
                 const hasProjectColumn = document.querySelector('#inventoryTable th:nth-child(1)')?.textContent.includes('Project');
                 if (hasProjectColumn) {
                     const projectCellIndex = 0; // 1st column (0-indexed)
                     if (cells[projectCellIndex]) {
                         const projectText = cells[projectCellIndex].textContent.trim();
                         if (projectFilter === 'UNASSIGNED') {
                             if (projectText !== 'Unassigned') {
                                 show = false;
                             }
                         } else if (projectText !== projectFilter) {
                             show = false;
                         }
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
         const costBreakdownModalEl = document.getElementById('costBreakdownModal');
         if (costBreakdownModalEl && costBreakdownModalEl.parentElement !== document.body) {
             document.body.appendChild(costBreakdownModalEl);
         }
         const facilityCostCard = document.getElementById('facilityCostCard');
         if (facilityCostCard) {
             facilityCostCard.addEventListener('click', function(e) {
                 e.preventDefault();
                 e.stopPropagation();
                 openCostBreakdownModal();
             });
             facilityCostCard.addEventListener('keydown', function(e) {
                 if (e.key === 'Enter' || e.key === ' ') {
                     e.preventDefault();
                     openCostBreakdownModal();
                 }
             });
         }

         // Set Inventory View as default active tab (regular user only)
         const inventoryViewEl = document.getElementById('InventoryView');
         if (inventoryViewEl) {
             const defaultActiveButton = document.querySelector('.tabs button.active');
             if (defaultActiveButton) {
                 openTab({ currentTarget: defaultActiveButton }, defaultActiveButton.textContent.includes('Inventory') ? 'InventoryView' : 'TruckloadView');
             }
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
         const inventoryProjectFilter = document.getElementById('inventoryProjectFilter');

         if(inventorySearch) inventorySearch.addEventListener('keyup', () => filterTable('inventoryTable', 'inventorySearch', 'inventoryWattageFilter'));
         if(inventoryWattageFilter) inventoryWattageFilter.addEventListener('change', () => filterTable('inventoryTable', 'inventorySearch', 'inventoryWattageFilter'));
         if(inventoryProjectFilter) inventoryProjectFilter.addEventListener('change', () => filterTable('inventoryTable', 'inventorySearch', 'inventoryWattageFilter'));
         
         filterTable('inventoryTable', 'inventorySearch', 'inventoryWattageFilter');
         filterTable('inboundTruckloadsTable', 'inboundTruckloadSearch', 'inboundTruckloadWattageFilter', inboundDateFilters);
         filterTable('outboundTruckloadsTable', 'outboundTruckloadSearch', 'outboundTruckloadWattageFilter', outboundDateFilters);
     });

     // Cost Modal Functions
     function showCostModal(palletId, daysStored, monthlyFee, storageCost, inFeePortion, totalCost) {
         const dailyRate = monthlyFee / 30;
         
         document.getElementById('modalTitle').textContent = `Cost Breakdown - ${palletId}`;
         document.getElementById('modalDaysStored').textContent = `${daysStored} days`;
         document.getElementById('modalDailyRate').textContent = `$${dailyRate.toFixed(2)} per day`;
         document.getElementById('modalMonthlyFee').textContent = `$${monthlyFee.toFixed(2)}`;
         document.getElementById('modalTotalCost').textContent = `Storage: $${storageCost.toFixed(2)} + In Fee: $${inFeePortion.toFixed(2)} = $${totalCost.toFixed(2)}`;
         
         document.getElementById('costModal').style.display = 'block';
     }
     
     // Cost Breakdown Modal Functions
     function openCostBreakdownModal() {
         const modal = document.getElementById('costBreakdownModal');
         if (!modal) return;
         modal.style.display = 'flex';
         modal.classList.add('active');
     }
     function closeCostBreakdownModal() {
         const modal = document.getElementById('costBreakdownModal');
         if (!modal) return;
         modal.classList.remove('active');
         modal.style.display = 'none';
     }
     document.getElementById('costBreakdownModal')?.addEventListener('click', function(e) {
         if (e.target === this) closeCostBreakdownModal();
     });
     
     function closeCostModal() {
         document.getElementById('costModal').style.display = 'none';
     }
     
     // Close modal when clicking outside of it
     window.onclick = function(event) {
         const modal = document.getElementById('costModal');
         if (event.target === modal) {
             closeCostModal();
                 }
    }

    // Toggle warehouse inbound delivery details
    function toggleWarehouseInboundDetails(index) {
        try {
            var detailRows = document.querySelectorAll('[id^="wh-inbound-detail-' + index + '-"]');
            var mainRow = document.querySelector('[data-delivery-index="' + index + '"]');
            var expandIcon = document.getElementById('wh-inbound-expand-icon-' + index);
            
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
            console.error('Error toggling warehouse inbound details:', error);
        }
    }

    // Toggle warehouse outbound delivery details
    function toggleWarehouseOutboundDetails(index) {
        try {
            var detailRows = document.querySelectorAll('[id^="wh-outbound-detail-' + index + '-"]');
            var mainRow = document.querySelector('[data-delivery-index="' + index + '"]');
            var expandIcon = document.getElementById('wh-outbound-expand-icon-' + index);
            
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
            console.error('Error toggling warehouse outbound details:', error);
        }
    }

    // Close fee modal when clicking outside or pressing Escape
    document.getElementById('feeModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeFeeModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeFeeModal(); closeCostBreakdownModal(); }
    });

</script>

<!-- Fee Structure Modal -->
<?php if (!empty($warehouse_costs_all) && $warehouse_data): ?>
<div id="feeModal" class="fee-modal">
    <div class="fee-modal-content">
        <div class="fee-modal-header">
            <h3>Cost Structure - <?php echo htmlspecialchars($warehouse_data['name']); ?></h3>
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
                    <?php foreach ($warehouse_costs_all as $fee): ?>
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
        <?php if ($isAdmin): ?>
        <div class="fee-modal-footer">
            <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="action-button">
                <i class="fas fa-edit"></i> Edit Fees
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin && $warehouse_data): ?>
<!-- ============================================== -->
<!-- ADMIN JAVASCRIPT -->
<!-- ============================================== -->
<script>
const projectsData = <?php echo json_encode($all_projects); ?>;
const warehousesData = <?php echo json_encode($other_warehouses); ?>;
const fromProjectId = <?php echo (int)$from_project_id; ?>;

// ========== ADMIN TAB MANAGEMENT ==========
function showMainTab(tabId) {
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(tc => { tc.style.display = 'none'; tc.classList.remove('active'); });
    const buttons = document.querySelectorAll('.tabs button');
    buttons.forEach(b => b.classList.remove('active'));
    const content = document.getElementById(tabId + 'Content');
    const button = document.getElementById(tabId + 'Tab');
    if (content) { content.style.display = 'block'; content.classList.add('active'); }
    if (button) { button.classList.add('active'); }
    if (tabId === 'inboundTransit') { showTransitSubView('byTruckload'); }
    if (tabId === 'truckloadHistory') { showHistorySubView('inbound'); }
}

function showTransitSubView(viewType) {
    document.querySelectorAll('#inboundTransitContent .sub-tab-content').forEach(v => { v.style.display = 'none'; v.classList.remove('active'); });
    document.querySelectorAll('#inboundTransitContent .sub-tab-button').forEach(b => b.classList.remove('active'));
    if (viewType === 'byTruckload') {
        const el = document.getElementById('transitByTruckloadView'); if (el) { el.style.display = 'block'; el.classList.add('active'); }
        document.querySelectorAll('#inboundTransitContent .sub-tab-button')[0]?.classList.add('active');
    } else {
        const el = document.getElementById('transitByPalletView'); if (el) { el.style.display = 'block'; el.classList.add('active'); }
        document.querySelectorAll('#inboundTransitContent .sub-tab-button')[1]?.classList.add('active');
    }
}

function showHistorySubView(viewType) {
    document.querySelectorAll('#truckloadHistoryContent .sub-tab-content').forEach(v => { v.style.display = 'none'; v.classList.remove('active'); });
    document.querySelectorAll('#truckloadHistoryContent .sub-tab-button').forEach(b => b.classList.remove('active'));
    if (viewType === 'inbound') {
        const el = document.getElementById('inboundHistoryView'); if (el) { el.style.display = 'block'; el.classList.add('active'); }
        document.querySelectorAll('#truckloadHistoryContent .sub-tab-button')[0]?.classList.add('active');
    } else {
        const el = document.getElementById('outboundHistoryView'); if (el) { el.style.display = 'block'; el.classList.add('active'); }
        document.querySelectorAll('#truckloadHistoryContent .sub-tab-button')[1]?.classList.add('active');
    }
}

// ========== CHECKBOX MANAGEMENT ==========
function toggleAllTruckloads() {
    const selectAll = document.getElementById('selectAllTruckloads');
    document.querySelectorAll('.truckload-checkbox').forEach(cb => { cb.checked = selectAll.checked; });
    updateReceiveTruckloadButton();
}

function updateReceiveTruckloadButton() {
    const checked = document.querySelectorAll('.truckload-checkbox:checked');
    const btn = document.getElementById('receiveTruckloadBtn');
    if (btn) { btn.disabled = checked.length === 0; }
}

// ========== FILTER FUNCTIONS ==========
function filterStoredTable() {
    const table = document.getElementById('storedTable');
    if (!table) return;
    const search = (document.getElementById('storedSearch')?.value || '').toLowerCase();
    const wattage = document.getElementById('storedWattageFilter')?.value || '';
    const project = document.getElementById('storedProjectFilter')?.value || '';
    table.querySelectorAll('tbody tr').forEach(row => {
        let show = true;
        const text = row.textContent.toLowerCase();
        if (search && !text.includes(search)) show = false;
        if (show && wattage) { const cells = row.cells; if (cells[3] && !cells[3].textContent.includes(wattage)) show = false; }
        if (show && project) { const cells = row.cells; if (cells[1] && !cells[1].textContent.includes(project)) show = false; }
        row.style.display = show ? '' : 'none';
    });
}

function filterTransitTable() {
    const table = document.getElementById('transitTable');
    if (!table) return;
    const search = (document.getElementById('transitSearch')?.value || '').toLowerCase();
    const wattage = document.getElementById('transitWattageFilter')?.value || '';
    table.querySelectorAll('tbody tr').forEach(row => {
        let show = true;
        if (search && !row.textContent.toLowerCase().includes(search)) show = false;
        if (show && wattage) { const cells = row.cells; if (cells[3] && !cells[3].textContent.includes(wattage)) show = false; }
        row.style.display = show ? '' : 'none';
    });
}

function filterTransitTruckloadTable() {
    const table = document.getElementById('transitTruckloadTable');
    if (!table) return;
    const search = (document.getElementById('transitTruckloadSearch')?.value || '').toLowerCase();
    table.querySelectorAll('tbody tr').forEach(row => {
        row.style.display = (!search || row.textContent.toLowerCase().includes(search)) ? '' : 'none';
    });
}

// ========== CUSTOMS FUNCTIONS ==========
function filterCustomsUnified(filter) {
    document.querySelectorAll('.customs-filter-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.customs-filter-btn[data-filter="'+filter+'"]')?.classList.add('active');
    document.querySelectorAll('#customsUnifiedTable tbody tr').forEach(row => {
        const status = row.dataset.customsStatus;
        if (filter === 'all' || status === (filter === 'held' ? 'held' : 'cleared')) { row.style.display = ''; }
        else { row.style.display = 'none'; }
    });
}

function toggleAllCustomsUnified() {
    const selectAll = document.getElementById('selectAllCustomsUnified');
    document.querySelectorAll('.customs-unified-checkbox').forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') { cb.checked = selectAll.checked; }
    });
    updateCustomsUnifiedSelection();
}

function updateCustomsUnifiedSelection() {
    const checked = document.querySelectorAll('.customs-unified-checkbox:checked');
    const count = checked.length;
    const countEl = document.getElementById('customsSelectionCount');
    if (countEl) countEl.textContent = count + ' selected';
    const actionBar = document.getElementById('customsActionBar');
    if (!actionBar) return;
    if (count === 0) { actionBar.style.display = 'none'; return; }
    actionBar.style.display = 'block';
    let statuses = new Set();
    checked.forEach(cb => statuses.add(cb.dataset.status));
    const holdDiv = document.getElementById('customsActionHold');
    const releaseDiv = document.getElementById('customsActionRelease');
    const mixedDiv = document.getElementById('customsActionMixed');
    holdDiv.style.display = 'none'; releaseDiv.style.display = 'none'; mixedDiv.style.display = 'none';
    if (statuses.size > 1) { mixedDiv.style.display = 'block'; }
    else if (statuses.has('cleared')) {
        holdDiv.style.display = 'block';
        document.getElementById('holdActionCount').textContent = count;
        const inputs = document.getElementById('holdPalletInputs');
        inputs.innerHTML = '';
        checked.forEach(cb => { inputs.innerHTML += '<input type="hidden" name="customs_pallet_ids[]" value="'+cb.value+'">'; });
    } else if (statuses.has('held')) {
        releaseDiv.style.display = 'block';
        document.getElementById('releaseActionCount').textContent = count;
        const inputs = document.getElementById('releasePalletInputs');
        inputs.innerHTML = '';
        checked.forEach(cb => { inputs.innerHTML += '<input type="hidden" name="customs_pallet_ids[]" value="'+cb.value+'">'; });
    }
}

function closeCustomsActionBar() {
    const bar = document.getElementById('customsActionBar');
    if (bar) bar.style.display = 'none';
    document.querySelectorAll('.customs-unified-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAllCustomsUnified');
    if (selectAll) selectAll.checked = false;
    const countEl = document.getElementById('customsSelectionCount');
    if (countEl) countEl.textContent = '0 selected';
}

function submitCustomsAction(type) { return true; }

// ========== RECEIVE TRUCKLOAD MODAL ==========
function openReceiveTruckloadModal() {
    const modal = document.getElementById('receiveTruckloadModal');
    if (!modal) return;
    const formContainer = document.getElementById('receiveTruckloadFormContainer');
    if (formContainer) {
        formContainer.style.display = 'block';
        modal.querySelector('.modal-content').appendChild(formContainer);
    }
    // Set date defaults
    const today = new Date();
    const todayString = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    const arrivalField = document.getElementById('actual_truckload_arrival_date');
    if (arrivalField && !arrivalField.value) {
        // Use the earliest est_arrival_date from selected truckloads, or today
        const checked = document.querySelectorAll('.truckload-checkbox:checked');
        let earliestDate = todayString;
        checked.forEach(cb => {
            const row = cb.closest('tr');
            if (row) {
                const dateCell = row.cells[4]; // Est. Arrival Date column
                if (dateCell) {
                    const dateText = dateCell.textContent.trim();
                    if (dateText && dateText !== 'N/A' && dateText < earliestDate) earliestDate = dateText;
                }
            }
        });
        arrivalField.value = earliestDate;
    }
    modal.style.display = 'block';
}

function closeReceiveTruckloadModal() {
    const modal = document.getElementById('receiveTruckloadModal');
    if (modal) modal.style.display = 'none';
}

// ========== POD UPLOAD ==========
function uploadWarehousePOD(deliveryId, projectId) {
    // Create a dynamic POD upload modal
    let existingModal = document.getElementById('warehousePodModal');
    if (existingModal) existingModal.remove();
    const modal = document.createElement('div');
    modal.id = 'warehousePodModal';
    modal.className = 'modal';
    modal.style.display = 'block';
    modal.innerHTML = `
        <div class="modal-content">
            <span class="close-modal" onclick="document.getElementById('warehousePodModal').style.display='none'">&times;</span>
            <div class="modal-header">Upload Proof of Delivery</div>
            <form id="podUploadForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_warehouse_pod">
                <input type="hidden" name="delivery_id" value="${deliveryId}">
                <input type="hidden" name="project_id" value="${projectId}">
                <div style="margin: 15px 0;">
                    <label for="warehouse_pod_file">Select POD File:</label>
                    <input type="file" name="warehouse_pod_file" id="warehouse_pod_file" accept=".pdf,.jpg,.jpeg,.png" required>
                    <small style="display: block; color: #666; margin-top: 5px;">PDF, JPG, PNG files up to 5MB</small>
                </div>
                <div style="margin: 15px 0;">
                    <label for="pod_description">Description (Optional):</label>
                    <input type="text" name="description" id="pod_description" placeholder="e.g. Warehouse receiving POD">
                </div>
                <button type="submit" class="action-button">Upload POD</button>
            </form>
            <div id="podUploadStatus" style="margin-top: 10px;"></div>
        </div>`;
    document.body.appendChild(modal);
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.style.display = 'none'; });
    document.getElementById('podUploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const statusEl = document.getElementById('podUploadStatus');
        statusEl.innerHTML = '<span style="color:#666;">Uploading...</span>';
        fetch('warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    statusEl.innerHTML = '<span style="color:green;">POD uploaded successfully!</span>';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    statusEl.innerHTML = '<span style="color:red;">Error: ' + (data.error || 'Upload failed') + '</span>';
                }
            })
            .catch(err => { statusEl.innerHTML = '<span style="color:red;">Upload error: ' + err.message + '</span>'; });
    });
}

// ========== DETAIL TOGGLING ==========
function toggleInboundDetails(index) {
    const detailRows = document.querySelectorAll('[id^="inbound-detail-' + index + '-"]');
    const expandIcon = document.getElementById('expand-icon-' + index);
    let isExpanded = false;
    detailRows.forEach(row => { if (row.style.display !== 'none') isExpanded = true; });
    detailRows.forEach(row => { row.style.display = isExpanded ? 'none' : 'table-row'; });
    if (expandIcon) expandIcon.innerHTML = isExpanded ? '&#9654;' : '&#9660;';
}

function toggleOutboundDetails(index) {
    const detailRows = document.querySelectorAll('[id^="outbound-detail-' + index + '-"]');
    const expandIcon = document.getElementById('outbound-expand-icon-' + index);
    let isExpanded = false;
    detailRows.forEach(row => { if (row.style.display !== 'none') isExpanded = true; });
    detailRows.forEach(row => { row.style.display = isExpanded ? 'none' : 'table-row'; });
    if (expandIcon) expandIcon.innerHTML = isExpanded ? '&#9654;' : '&#9660;';
}

// ========== PORT CONTAINER MANAGEMENT ==========
function toggleAllContainers() {
    const selectAll = document.getElementById('selectAllContainers');
    document.querySelectorAll('.container-checkbox').forEach(cb => { cb.checked = selectAll.checked; });
    toggleMoveContainerBtn();
}

function toggleMoveContainerBtn() {
    const btn = document.getElementById('moveContainerBtn');
    if (btn) btn.disabled = document.querySelectorAll('.container-checkbox:checked').length === 0;
}

function openMoveContainerModal() {
    const modal = document.getElementById('moveContainerModal');
    if (!modal) return;
    const checked = document.querySelectorAll('.container-checkbox:checked');
    if (checked.length === 0) { alert('Select at least one container.'); return; }
    const containerIds = Array.from(checked).map(cb => cb.value);
    modal.dataset.containerIds = JSON.stringify(containerIds);
    // Set departure date
    const departureField = document.getElementById('move_departure_date');
    if (departureField) {
        const today = new Date();
        departureField.value = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    }
    const arrivalField = document.getElementById('move_est_arrival_date');
    if (arrivalField && departureField) {
        const dep = new Date(departureField.value);
        dep.setDate(dep.getDate() + 1);
        arrivalField.value = dep.getFullYear() + '-' + String(dep.getMonth() + 1).padStart(2, '0') + '-' + String(dep.getDate()).padStart(2, '0');
    }
    document.getElementById('move_destination_id').innerHTML = '<option value="">-- Select Destination --</option>';
    document.getElementById('move_miles').value = '';
    document.getElementById('move_freight_cost').value = '';
    document.getElementById('move_customer_cost').value = '';
    const distanceDisplay = document.getElementById('drayageDistanceDisplay');
    if (distanceDisplay) distanceDisplay.innerHTML = '';
    updateMoveDestinations();
    // Hold warning banner
    const holdBanner = document.getElementById('drayageHoldBanner');
    if (holdBanner) {
        const holdItems = [];
        checked.forEach(cb => {
            const hp = parseInt(cb.dataset.holdPallets || '0', 10);
            const hm = parseInt(cb.dataset.holdModules || '0', 10);
            if (hp > 0) holdItems.push({ container: cb.dataset.containerNumber || 'Unknown', pallets: hp, modules: hm });
        });
        if (holdItems.length > 0) {
            const lines = holdItems.map(h => '<strong>'+h.container+'</strong>: '+h.pallets+' pallet'+(h.pallets !== 1 ? 's' : '')+' ('+h.modules.toLocaleString()+' modules)').join(' &middot; ');
            holdBanner.innerHTML = '<div class="drayage-modal-hold-banner"><span class="drayage-modal-hold-icon">&#9888;</span> <span>'+lines+' on <strong>Customs Hold</strong> &mdash; will not be shipped</span></div>';
            holdBanner.style.display = '';
        } else { holdBanner.innerHTML = ''; holdBanner.style.display = 'none'; }
    }
    modal.style.display = 'block';
}

function closeMoveContainerModal() {
    const modal = document.getElementById('moveContainerModal');
    if (modal) { modal.style.display = 'none'; delete modal.dataset.containerIds; }
}

function populateDropdownMove(selectElement, type, dataSource, nameField, placeholderPrefix) {
    if (!selectElement) return;
    selectElement.innerHTML = '';
    if (!dataSource || dataSource.length === 0) {
        selectElement.innerHTML = '<option value="">No '+placeholderPrefix.toLowerCase()+' found</option>';
        selectElement.disabled = true;
    } else {
        selectElement.disabled = false;
        selectElement.innerHTML = '<option value="">-- Select '+placeholderPrefix+' --</option>';
        const filteredData = type === 'warehouse' ? dataSource.filter(item => item.id != <?php echo $warehouse_id; ?>) : dataSource;
        filteredData.forEach(function(item) {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item[nameField];
            opt.setAttribute('data-address', item.full_address || '');
            selectElement.appendChild(opt);
        });
    }
}

// ========== GOOGLE MAPS DISTANCE ==========
let directionsService;
let mapsInitialized = false;

function initializeGoogleMaps() {
    if (mapsInitialized || !window.google) return;
    directionsService = new google.maps.DirectionsService();
    mapsInitialized = true;
}

function calculateDistanceFromAddresses(originAddress, destinationAddress, callback) {
    if (!mapsInitialized) initializeGoogleMaps();
    if (!directionsService || !originAddress || !destinationAddress) { callback(null, 'Missing address information'); return; }
    if (originAddress.toLowerCase() === destinationAddress.toLowerCase()) { callback(null, 'Origin and destination cannot be the same'); return; }
    directionsService.route({ origin: originAddress, destination: destinationAddress, travelMode: 'DRIVING' }, function(result, status) {
        if (status === 'OK') {
            const miles = (result.routes[0].legs[0].distance.value / 1609.34).toFixed(2);
            callback(miles, null);
        } else { callback(null, 'Could not calculate distance'); }
    });
}

function calculateDrayageMiles() {
    const destSelect = document.getElementById('move_destination_id');
    const distanceDisplay = document.getElementById('drayageDistanceDisplay');
    const milesField = document.getElementById('move_miles');
    if (!destSelect || !distanceDisplay || !milesField) return;
    const selectedOption = destSelect.options[destSelect.selectedIndex];
    const destinationAddress = selectedOption ? selectedOption.getAttribute('data-address') : '';
    const originAddress = '<?php echo addslashes(($warehouse_data['street_address'] ?? '') . ', ' . ($warehouse_data['city'] ?? '') . ', ' . ($warehouse_data['state'] ?? '') . ' ' . ($warehouse_data['zip_code'] ?? '')); ?>';
    if (!originAddress || !destinationAddress) { distanceDisplay.innerHTML = ''; milesField.value = ''; return; }
    distanceDisplay.innerHTML = '<span style="color: #666;">Calculating...</span>';
    calculateDistanceFromAddresses(originAddress, destinationAddress, function(distance, error) {
        if (error) {
            distanceDisplay.innerHTML = error.includes('cannot be the same') ? '<span style="color:#d32f2f;">Same location</span>' : '<span style="color:#d32f2f;">Error</span>';
            milesField.value = '';
        } else {
            distanceDisplay.innerHTML = '<span style="color:#488C9A; font-weight:bold;">'+distance+' miles</span>';
            milesField.value = distance;
        }
    });
}

window.updateMoveDestinations = function() {
    const selectedTypeRadio = document.querySelector('#moveContainerModal input[name="destination_type"]:checked');
    const destSelect = document.getElementById('move_destination_id');
    if (!selectedTypeRadio || !destSelect) return;
    const destType = selectedTypeRadio.value;
    const distanceDisplay = document.getElementById('drayageDistanceDisplay');
    const milesField = document.getElementById('move_miles');
    if (distanceDisplay) distanceDisplay.innerHTML = '';
    if (milesField) milesField.value = '';
    const data = (destType === 'project') ? projectsData : warehousesData;
    const nameField = (destType === 'project') ? 'project_name' : 'name';
    const placeholder = (destType === 'project') ? 'Project' : 'Warehouse';
    populateDropdownMove(destSelect, destType, data, nameField, placeholder);
    calculateDrayageMiles();
};
function updateMoveDestinations() { window.updateMoveDestinations(); }

// ========== COST BREAKDOWN MODAL ==========
function openCostBreakdownModal() {
    const modal = document.getElementById('costBreakdownModal');
    if (!modal) return;
    modal.style.display = 'flex';
    modal.classList.add('active');
}
function closeCostBreakdownModal() {
    const modal = document.getElementById('costBreakdownModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.style.display = 'none';
}
document.getElementById('costBreakdownModal')?.addEventListener('click', function(e) { if (e.target === this) closeCostBreakdownModal(); });

// ========== ADMIN DOM READY ==========
document.addEventListener('DOMContentLoaded', function() {
    // Tab click handlers
    document.querySelectorAll('.tabs button').forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.id.replace('Tab', '');
            showMainTab(tabId);
        });
    });

    // Filter listeners
    document.getElementById('storedSearch')?.addEventListener('keyup', filterStoredTable);
    document.getElementById('storedWattageFilter')?.addEventListener('change', filterStoredTable);
    document.getElementById('storedProjectFilter')?.addEventListener('change', filterStoredTable);
    document.getElementById('transitSearch')?.addEventListener('keyup', filterTransitTable);
    document.getElementById('transitWattageFilter')?.addEventListener('change', filterTransitTable);
    document.getElementById('transitTruckloadSearch')?.addEventListener('keyup', filterTransitTruckloadTable);
    document.getElementById('customsHoldSearch')?.addEventListener('keyup', function() {
        const search = this.value.toLowerCase();
        document.querySelectorAll('#customsUnifiedTable tbody tr').forEach(row => {
            row.style.display = (!search || row.textContent.toLowerCase().includes(search)) ? '' : 'none';
        });
    });

    // Receive truckload button
    document.getElementById('receiveTruckloadBtn')?.addEventListener('click', openReceiveTruckloadModal);

    // Close receive truckload modal
    document.querySelector('.close-receive-truckload-modal')?.addEventListener('click', closeReceiveTruckloadModal);
    window.addEventListener('click', function(e) {
        const modal = document.getElementById('receiveTruckloadModal');
        if (e.target === modal) closeReceiveTruckloadModal();
    });

    // Confirm receive truckload
    document.getElementById('confirmReceiveTruckloadBtn')?.addEventListener('click', function() {
        const checked = document.querySelectorAll('.truckload-checkbox:checked');
        if (checked.length === 0) { alert('No truckloads selected.'); return; }
        const deliveryIds = Array.from(checked).map(cb => cb.value);
        const arrivalDate = document.getElementById('actual_truckload_arrival_date')?.value;
        if (!arrivalDate) { alert('Please enter an arrival date.'); return; }
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'handle_pallet_arrival.php';
        form.enctype = 'multipart/form-data';
        const fields = {
            action: 'receive_multiple_truckloads',
            warehouse_id: '<?php echo $warehouse_id; ?>',
            actual_arrival_date: arrivalDate,
            redirect_url: window.location.href
        };
        for (const [key, value] of Object.entries(fields)) {
            const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = value; form.appendChild(input);
        }
        deliveryIds.forEach(id => {
            const input = document.createElement('input'); input.type = 'hidden'; input.name = 'delivery_ids[]'; input.value = id; form.appendChild(input);
        });
        // Optional fields
        const bolField = document.getElementById('receive_truckload_bol');
        if (bolField && bolField.value) {
            const input = document.createElement('input'); input.type = 'hidden'; input.name = 'bol_number_override'; input.value = bolField.value; form.appendChild(input);
        }
        const houseBol = document.getElementById('house_bol');
        if (houseBol && houseBol.value) {
            const input = document.createElement('input'); input.type = 'hidden'; input.name = 'house_bol'; input.value = houseBol.value; form.appendChild(input);
        }
        const masterBol = document.getElementById('master_bol');
        if (masterBol && masterBol.value) {
            const input = document.createElement('input'); input.type = 'hidden'; input.name = 'master_bol'; input.value = masterBol.value; form.appendChild(input);
        }
        // Include POD file if selected
        const podFile = document.getElementById('pod_file');
        if (podFile && podFile.files.length > 0) {
            form.appendChild(podFile.cloneNode(true));
        }
        document.body.appendChild(form);
        form.submit();
    });

    // Move container button
    document.getElementById('moveContainerBtn')?.addEventListener('click', openMoveContainerModal);
    document.querySelector('.close-move-container-modal')?.addEventListener('click', closeMoveContainerModal);
    window.addEventListener('click', function(e) {
        const modal = document.getElementById('moveContainerModal');
        if (e.target === modal) closeMoveContainerModal();
    });

    // Move container form submission
    const moveForm = document.getElementById('moveContainerForm');
    let drayageHoldWarningConfirmed = false;
    if (moveForm) {
        moveForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const modal = document.getElementById('moveContainerModal');
            const containerIds = JSON.parse(modal.dataset.containerIds || '[]');
            if (containerIds.length === 0) { alert('No containers selected.'); return; }
            let containerNumber = '';
            const checkedContainers = document.querySelectorAll('.container-checkbox:checked');
            if (checkedContainers.length > 0) {
                const firstRow = checkedContainers[0].closest('tr');
                if (firstRow && firstRow.cells[1]) containerNumber = firstRow.cells[1].textContent.trim();
            }
            if (!containerNumber) { alert('Error: Could not determine container number.'); return; }
            if (!drayageHoldWarningConfirmed) {
                const holdWarnings = [];
                checkedContainers.forEach(cb => {
                    const hp = parseInt(cb.dataset.holdPallets || '0', 10);
                    const hm = parseInt(cb.dataset.holdModules || '0', 10);
                    if (hp > 0) holdWarnings.push({ container: cb.dataset.containerNumber || 'Unknown', pallets: hp, modules: hm });
                });
                if (holdWarnings.length > 0) {
                    showDrayageHoldWarning(holdWarnings, function() { drayageHoldWarningConfirmed = true; moveForm.dispatchEvent(new Event('submit', { cancelable: true })); });
                    return;
                }
            }
            drayageHoldWarningConfirmed = false;
            const drayageBolNumber = containerNumber + '-DRAY';
            document.getElementById('container_ids_input').value = JSON.stringify(containerIds);
            document.getElementById('bol_number_input').value = drayageBolNumber;
            document.getElementById('container_number_input').value = containerNumber;
            fetch('get_container_pallets.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ delivery_ids: containerIds }) })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'Failed to get pallet IDs');
                    data.pallet_ids.forEach((palletId, index) => {
                        const input = document.createElement('input'); input.type = 'hidden'; input.name = 'selected_pallets['+index+']'; input.value = palletId; moveForm.appendChild(input);
                    });
                    moveForm.submit();
                })
                .catch(err => { alert('Error: ' + err.message); });
        });
    }

    function showDrayageHoldWarning(holdWarnings, onConfirm) {
        document.getElementById('drayageHoldWarning')?.remove();
        let warningLines = holdWarnings.map(w => '<strong>'+w.container+'</strong>: '+w.pallets+' pallet'+(w.pallets !== 1 ? 's' : '')+' ('+w.modules.toLocaleString()+' modules) on Customs Hold').join('<br>');
        const overlay = document.createElement('div');
        overlay.id = 'drayageHoldWarning';
        overlay.className = 'drayage-hold-warning-overlay';
        overlay.innerHTML = '<div class="drayage-hold-warning-box"><div class="drayage-hold-warning-icon">&#9888;</div><h3>Customs Hold Warning</h3><p>The following container(s) have pallets on Customs Hold that <strong>will not be included</strong> in this drayage shipment:</p><div class="drayage-hold-warning-details">'+warningLines+'</div><p style="margin-top:12px; color:#6b7280; font-size:0.9em;">These pallets will remain at the port and must be shipped separately after release from customs.</p><div class="drayage-hold-warning-actions"><button type="button" class="action-button action-button-secondary" id="drayageHoldCancel">Cancel</button><button type="button" class="action-button action-button-danger" id="drayageHoldProceed">Proceed Without Held Pallets</button></div></div>';
        document.body.appendChild(overlay);
        document.getElementById('drayageHoldCancel').addEventListener('click', function() { overlay.remove(); });
        document.getElementById('drayageHoldProceed').addEventListener('click', function() { overlay.remove(); onConfirm(); });
        overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });
    }

    // Google Maps init
    if (window.google) { initializeGoogleMaps(); } else { window.addEventListener('load', initializeGoogleMaps); }

    // Tab URL parameter support & default init
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam) { showMainTab(tabParam); } else { showMainTab('storedInventory'); }

    // Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeReceiveTruckloadModal(); closeMoveContainerModal(); closeCostBreakdownModal(); }
    });
});
</script>
<?php if ($google_maps_api_key): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places" async defer></script>
<?php endif; ?>
<?php endif; ?>

</body>
</html>
