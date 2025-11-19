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
$module_batch_id = isset($_GET['module_batch_id']) ? intval($_GET['module_batch_id']) : null;

// Initialize variables
$warehouse_data         = null;
$project_name_for_title = null;
$show_warehouse_list    = false;
$relevant_warehouses    = []; 
$errorMessage           = '';
$page_title             = "Warehouse Information"; 
$origin_batch_vendor_name = null;

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
            header("Location: warehouse_info.php?warehouse_id={$single_warehouse_id}&module_batch_id={$module_batch_id}");
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
        $stmtCosts = $conn->prepare("
            SELECT label, trigger_event, amount 
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
                CASE WHEN COUNT(DISTINCT d.wattage) > 1 THEN 1 ELSE 0 END AS is_mixed_wattage
            FROM deliveries d
            LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
            LEFT JOIN projects p ON d.project_id = p.id
            WHERE d.warehouse_id = ? AND d.left_warehouse_date IS NOT NULL 
        "; 
        $left_params = [$warehouse_id];
        $left_types = "i";
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
        
        // Get cost rates from warehouse_cost_items
        if (!empty($warehouse_costs['entry'])) {
            $in_fee_cost = $warehouse_costs['entry'][0]['amount'] * $total_inbound_pallets_count;
        }
        if (!empty($warehouse_costs['exit'])) {
            $out_fee_cost = $warehouse_costs['exit'][0]['amount'] * $total_outbound_pallets_count;
        }
        if (!empty($warehouse_costs['monthly'])) {
            $monthly_storage_rate = $warehouse_costs['monthly'][0]['amount'] * $total_pallets_count;
        }
        
        // Calculate actual storage costs and average months
        $total_storage_cost_actual = 0;
        $total_days_all_pallets = 0;
        
        if (!empty($inventory_pallets)) {
            $daily_rate = !empty($warehouse_costs['monthly']) ? $warehouse_costs['monthly'][0]['amount'] / 30 : 0;
            foreach ($inventory_pallets as $pallet_calc) {
                $days = max(0, intval($pallet_calc['days_stored'] ?? 0));
                $total_days_all_pallets += $days;
                $total_storage_cost_actual += $days * $daily_rate;
            }
        }
        
        $average_days = $total_pallets_count > 0 ? $total_days_all_pallets / $total_pallets_count : 0;
        $average_months = $average_days / 30;
        
        $total_cost_to_date = $in_fee_cost + $out_fee_cost + $total_storage_cost_actual;

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
            background: #fff;
            color: #293E4C;
            border-bottom: 1px solid #fff; /* Make bottom border white to blend */
        }
        .tab-content { /* Combined style for both sections */
            display: none; /* Hide sections by default */
            margin-top: 10px; /* Space above section content */
        }
        .tab-content.active {
            display: block;
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
         $back_link = "warehouse_info.php?project_id=" . $project_id; // Link back to project's warehouse list
     } elseif ($warehouse_id && $module_batch_id) { // If warehouse and batch are given (specific view from batch context)
         $back_link = "warehouse_info.php?module_batch_id=" . $module_batch_id; // Link back to batch's warehouse list
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
        $managePalletsUrl = 'manage_pallets.php' . ($project_id ? ('?project_id='.(int)$project_id) : '');

        if ($backToManagePallets) {
            // Always show a single back breadcrumb to Manage Pallets
            echo slp_render_breadcrumbs([
                'current_label' => ($warehouse_id ? ($warehouse_data['name'] ?? 'Warehouse Details') : 'Warehouse Locations'),
                'extra' => [ ['label' => 'Manage Pallets', 'url' => $managePalletsUrl] ]
            ]);
        } else {
            if ($project_id && !$warehouse_id && !$module_batch_id) {
                echo slp_render_breadcrumbs(['current_label' => 'Warehouse Locations', 'project_id' => (int)$project_id]);
            } elseif ($warehouse_id && $project_id) {
                echo slp_render_breadcrumbs([
                    'current_label' => ($warehouse_data['name'] ?? 'Warehouse Details'),
                    'project_id' => (int)$project_id,
                    'extra' => [ ['label' => 'Warehouse Locations', 'url' => 'warehouse_info.php?project_id='.(int)$project_id] ]
                ]);
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
                        ['label' => 'Warehouse Locations', 'url' => 'warehouse_info.php?module_batch_id='.(int)$module_batch_id]
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

    <h1><?php echo $page_title; ?></h1>

    <?php if (!empty($errorMessage)): ?>
        <p class="message error-message"><?php echo htmlspecialchars($errorMessage); ?></p>

    <?php elseif ($show_warehouse_list): ?>
        <p class="info-message" style="margin-bottom: 20px;">Inventory for Project '<?php echo htmlspecialchars($project_name_for_title); ?>' is located in the following warehouses:</p>
        <div class="warehouse-cards-container">
            <?php foreach ($relevant_warehouses as $wh): ?>
                <div class="warehouse-card">
                    <a href="warehouse_info.php?warehouse_id=<?php echo $wh['id']; ?>&project_id=<?php echo $project_id; ?>" class="warehouse-card-link">
                        <?php 
                        $wh_image_path = "pictures/warehouse-default.png"; // Default image
                        if (!empty($wh['image_url'])) {
                            // Basic check if it's a full URL or a relative path
                            if (filter_var($wh['image_url'], FILTER_VALIDATE_URL)) {
                                $wh_image_path = $wh['image_url'];
                            } else {
                                // Assuming it might be a path relative to a specific directory if not a full URL
                                // For now, let's prepend 'uploads/warehouse_images/' if it doesn't look like a full URL
                                // and isn't already starting with a common image path indicator.
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
                 <?php elseif ($module_batch_id && $origin_batch_vendor_name): ?>
                    <h2 style="font-size: 1.1em; color: #555; margin-top: 5px;">Viewing Inventory from Batch: <?php echo htmlspecialchars($origin_batch_vendor_name); ?> (ID: <?php echo $module_batch_id; ?>)</h2>
                 <?php endif; ?>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($warehouse_data['address']); ?></p>
                <?php if (!empty($warehouse_costs)): ?>
                    <div class="warehouse-cost-summary">
                        <p><strong>Cost Structure:</strong></p>
                        <?php 
                        foreach ($warehouse_costs as $trigger => $costs): 
                            foreach ($costs as $cost):
                        ?>
                            <p style="margin-left: 15px; font-size: 0.9em;">
                                <strong><?php echo htmlspecialchars($cost['label']); ?>:</strong> 
                                $<?php echo number_format($cost['amount'], 2); ?>
                                <?php if ($trigger === 'monthly'): ?> per pallet per month<?php endif; ?>
                                <?php if ($trigger === 'entry' || $trigger === 'exit'): ?> per pallet<?php endif; ?>
                            </p>
                        <?php 
                            endforeach;
                        endforeach; 
                        ?>
                    </div>
                <?php else: ?>
                    <p><em>No cost structure defined for this warehouse.</em></p>
                <?php endif; ?>
                <?php if (($_SESSION['role'] ?? '') === 'global_admin'): // Only allow edit for global admin ?>
                    <p style="margin-top:10px;"><a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="action-button">Edit Warehouse Info</a></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Cost Summary -->
        <div class="cost-overview-container">
            <div class="cost-card">
                <div class="cost-icon">📦</div>
                <div class="cost-value"><?php echo number_format($total_pallets_count ?? 0); ?></div>
                <div class="cost-label">Total Pallets Stored</div>
            </div>
            <div class="cost-card">
                <div class="cost-icon">⚡</div>
                <div class="cost-value"><?php echo number_format($total_modules ?? 0); ?></div>
                <div class="cost-label">Total Modules Stored</div>
            </div>
            <div class="cost-card total-cost clickable-cost-card" onclick="toggleCostDropdown()">
                <div class="cost-icon">💰</div>
                <div class="cost-value">$<?php echo number_format($total_cost_to_date, 2); ?></div>
                <div class="cost-label">Est. Total Cost To Date</div>
                <div class="cost-dropdown-arrow">▼</div>
                <div class="cost-card-dropdown" id="costCardDropdown">
                    <div class="cost-dropdown-item">
                        <span class="cost-dropdown-label">In Fee Cost:</span>
                        <span class="cost-dropdown-amount">$<?php echo number_format($in_fee_cost, 2); ?></span>
                    </div>
                    <div class="cost-dropdown-item">
                        <span class="cost-dropdown-label">Out Fee Cost:</span>
                        <span class="cost-dropdown-amount">$<?php echo number_format($out_fee_cost, 2); ?></span>
                    </div>
                    <div class="cost-dropdown-item">
                        <span class="cost-dropdown-label">Est. Monthly Storage:</span>
                        <span class="cost-dropdown-amount">$<?php echo number_format($monthly_storage_rate, 2); ?> × <?php echo number_format($average_months, 1); ?> mo = $<?php echo number_format($total_storage_cost_actual, 2); ?></span>
                    </div>
                    <div class="cost-dropdown-divider"></div>
                    <div class="cost-dropdown-item total-item">
                        <span class="cost-dropdown-label">Total Est. Cost:</span>
                        <span class="cost-dropdown-amount">$<?php echo number_format($total_cost_to_date, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

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
                                $monthly_storage_fee = !empty($warehouse_costs['monthly']) ? floatval($warehouse_costs['monthly'][0]['amount']) : 0;
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
                                          <a href="pallet_details.php?pallet_id=<?php echo $pallet['pallet_id']; ?><?php if ($module_batch_id) echo '&origin_batch_id='.$module_batch_id; ?>" class="view-details-btn" target="_blank">
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
            <div class="sub-tabs-container" style="text-align: left; margin-bottom: 15px;">
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
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6">No inbound truckloads recorded<?php echo $project_id ? ' for this project' : ''; ?> in this warehouse.</td></tr>
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
                                          </tr>
                                      <?php endforeach; ?>
                                  <?php endif; ?>
                              <?php endforeach; ?>
                          <?php else: ?>
                              <tr><td colspan="6">No outbound truckloads recorded<?php echo $project_id ? ' for this project' : ''; ?> in this warehouse.</td></tr>
                          <?php endif; ?>
                      </tbody>
                  </table>
              </div>
         </div>
         <!-- === END Standard Warehouse View === -->
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
     
     // Cost Card Dropdown Functions
     function toggleCostDropdown() {
         const costCard = document.querySelector('.clickable-cost-card');
         costCard.classList.toggle('open');
     }
     
     // Close dropdown when clicking outside
     document.addEventListener('click', function(event) {
         const costCard = document.querySelector('.clickable-cost-card');
         if (costCard && !costCard.contains(event.target)) {
             costCard.classList.remove('open');
         }
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

</script>

</body>
</html>
