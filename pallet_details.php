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
$is_global_admin = ($role === 'global_admin');
$can_manage_pallet_updates = in_array($role, ['admin', 'global_admin', 'customer_admin'], true);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once '../config.php';
require_once 'milestone_helpers.php';
$conn = getDBConnection();

// Validate pallet_id
if (!isset($_GET['pallet_id']) || empty($_GET['pallet_id']) || !ctype_digit((string)$_GET['pallet_id'])) {
    die("Invalid Pallet ID provided.");
}
$pallet_id = (int)$_GET['pallet_id'];

$pallet_data = null;
$associated_deliveries = [];
$warehouse_history = [];
$errorMessage = '';
$successMessage = '';
$total_delivery_cost = 0.0;
$total_warehouse_cost = 0.0;
$total_module_cost = 0.0;
$total_pallet_cost = 0.0;
$module_cost_per_watt = 0.0;
$pallet_wattage = 0.0;
$pallet_quantity = 0;
$full_module_cost = 0.0;
$has_module_cost = false;
$has_milestones = false;
$module_milestone_rows = [];
$breadcrumbProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$fromWarehouseInfo = (($_GET['from'] ?? '') === 'warehouse_info');
$fromCostDetails   = (($_GET['from'] ?? '') === 'cost_details');
$fromModuleMovements = (($_GET['from'] ?? '') === 'module_movements');
$breadcrumbWarehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$breadcrumbWarehouseName = '';
$originBatchId = isset($_GET['origin_batch_id']) ? (int)$_GET['origin_batch_id'] : 0;

if (!empty($_SESSION['pallet_details_success'])) {
    $successMessage = (string)$_SESSION['pallet_details_success'];
    unset($_SESSION['pallet_details_success']);
}
if (!empty($_SESSION['pallet_details_error']) && $errorMessage === '') {
    $errorMessage = (string)$_SESSION['pallet_details_error'];
    unset($_SESSION['pallet_details_error']);
}

$account_id_for_user = null;
if (!$is_global_admin) {
    if (in_array($role, ['admin', 'customer_admin'], true)) {
        $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    } else {
        $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    }

    if ($stmtAccount) {
        $stmtAccount->bind_param('i', $user_id);
        $stmtAccount->execute();
        $stmtAccount->bind_result($acct);
        if ($stmtAccount->fetch()) {
            $account_id_for_user = (int)$acct;
        }
        $stmtAccount->close();
    }
}


try {
    // 1. Fetch Pallet Master Data
                    $sql_pallet = "SELECT
                        ip.id AS pallet_id,
                        ip.pallet_identifier,
                        ip.manufacturer_pallet_id,
                        COALESCE(NULLIF(TRIM(ip.manufacturer_pallet_id), ''), ip.pallet_identifier) AS display_identifier,
                        ip.wattage,
                        ip.quantity,
                        ip.current_warehouse_id AS current_warehouse_id,
                        ip.current_project_id AS current_project_id,
                        ip.assigned_project_id AS assigned_project_id,
                        ip.status,
                        ip.arrival_date,
                        -- Clean manufacturer name by removing project suffix
                        CASE
                            WHEN m.vendor_name LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(m.vendor_name, '-', 1))
                            ELSE m.vendor_name
                        END AS origin_vendor,
                        w.name AS current_warehouse_name,
                        p.project_name AS current_project_name,
                        m.id AS module_batch_id,
                        m.cost_per_watt AS module_cost_per_watt,
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
        if ($fromWarehouseInfo) {
            if ($breadcrumbWarehouseId <= 0 && !empty($pallet_data['current_warehouse_id'])) {
                $breadcrumbWarehouseId = (int)$pallet_data['current_warehouse_id'];
            }
            if (!$breadcrumbWarehouseName && !empty($pallet_data['current_warehouse_name'])) {
                $breadcrumbWarehouseName = $pallet_data['current_warehouse_name'];
            }
        }
        // Determine project context for breadcrumbs
        if ($pallet_data) {
            $projectFromData = (int)($pallet_data['current_project_id'] ?? 0);
            if ($projectFromData > 0) {
                $breadcrumbProjectId = $projectFromData;
            } elseif ($breadcrumbProjectId <= 0) {
                // Try to infer via any associated delivery's project
                $stmtProj = $conn->prepare("SELECT d.project_id FROM deliveries d JOIN delivery_pallets dp ON d.id = dp.delivery_id WHERE dp.inventory_pallet_id = ? AND d.project_id IS NOT NULL ORDER BY d.id DESC LIMIT 1");
                if ($stmtProj) {
                    $stmtProj->bind_param("i", $pallet_id);
                    $stmtProj->execute();
                    $stmtProj->bind_result($projIdTmp);
                    if ($stmtProj->fetch()) { $breadcrumbProjectId = (int)$projIdTmp; }
                    $stmtProj->close();
                }
            }
        }
    } else {
        throw new Exception("Pallet with ID {$pallet_id} not found.");
    }

    if ($fromWarehouseInfo && $breadcrumbWarehouseId > 0 && !$breadcrumbWarehouseName) {
        $stmtWh = $conn->prepare("SELECT name FROM warehouses WHERE id = ? LIMIT 1");
        if ($stmtWh) {
            $stmtWh->bind_param("i", $breadcrumbWarehouseId);
            $stmtWh->execute();
            $stmtWh->bind_result($whName);
            if ($stmtWh->fetch()) { $breadcrumbWarehouseName = $whName; }
            $stmtWh->close();
        }
    }
    $stmt_pallet->close();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pallet_ids'])) {
        try {
            if (!$can_manage_pallet_updates) {
                throw new Exception('You are not authorized to update pallet IDs.');
            }
            if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
                throw new Exception('Invalid request token. Please refresh and try again.');
            }
            if (!$is_global_admin && !$account_id_for_user) {
                throw new Exception('Unable to resolve account scope for this user.');
            }

            $new_display_identifier = trim((string)($_POST['display_pallet_id'] ?? ''));
            $solterra_identifier = trim((string)($pallet_data['pallet_identifier'] ?? ''));
            if ($new_display_identifier === '') {
                throw new Exception('Pallet ID cannot be empty.');
            }
            if ($solterra_identifier === '') {
                throw new Exception('This pallet does not have a base Solterra identifier.');
            }

            // Single editable Pallet ID UX:
            // - if equal to Solterra identifier -> clear stored override
            // - otherwise -> store the entered display ID as an override
            $new_manufacturer_pallet_id = (strcasecmp($new_display_identifier, $solterra_identifier) === 0)
                ? null
                : $new_display_identifier;

            if ($new_manufacturer_pallet_id !== null && strlen($new_manufacturer_pallet_id) > 100) {
                throw new Exception('Pallet ID must be 100 characters or fewer.');
            }

            if (!$is_global_admin) {
                $stmtScope = $conn->prepare("
                    SELECT ip.id
                    FROM inventory_pallets ip
                    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                    LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
                    LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
                    WHERE ip.id = ?
                      AND (p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?)
                    LIMIT 1
                ");
                if (!$stmtScope) {
                    throw new Exception('Failed to validate pallet scope: ' . $conn->error);
                }
                $stmtScope->bind_param('iiii', $pallet_id, $account_id_for_user, $account_id_for_user, $account_id_for_user);
                $stmtScope->execute();
                $scopeRow = $stmtScope->get_result()->fetch_assoc();
                $stmtScope->close();
                if (!$scopeRow) {
                    throw new Exception('This pallet is outside your account scope.');
                }
            }

            if ($new_manufacturer_pallet_id !== null) {
                $assignedProjectId = isset($pallet_data['assigned_project_id']) ? (int)$pallet_data['assigned_project_id'] : 0;
                if ($assignedProjectId > 0) {
                    $stmtDup = $conn->prepare("
                        SELECT id
                        FROM inventory_pallets
                        WHERE manufacturer_pallet_id = ? AND id <> ? AND assigned_project_id = ?
                        LIMIT 1
                    ");
                    if (!$stmtDup) {
                        throw new Exception('Failed to prepare duplicate check: ' . $conn->error);
                    }
                    $stmtDup->bind_param('sii', $new_manufacturer_pallet_id, $pallet_id, $assignedProjectId);
                } else {
                    $stmtDup = $conn->prepare("
                        SELECT id
                        FROM inventory_pallets
                        WHERE manufacturer_pallet_id = ? AND id <> ? AND assigned_project_id IS NULL
                        LIMIT 1
                    ");
                    if (!$stmtDup) {
                        throw new Exception('Failed to prepare duplicate check: ' . $conn->error);
                    }
                    $stmtDup->bind_param('si', $new_manufacturer_pallet_id, $pallet_id);
                }

                $stmtDup->execute();
                $dupRow = $stmtDup->get_result()->fetch_assoc();
                $stmtDup->close();

                if ($dupRow) {
                    throw new Exception('This pallet ID is already used by another pallet in this project scope.');
                }
            }

            $stmtUpdate = $conn->prepare("
                UPDATE inventory_pallets
                SET manufacturer_pallet_id = ?
                WHERE id = ?
            ");
            if (!$stmtUpdate) {
                throw new Exception('Failed to prepare pallet update: ' . $conn->error);
            }
            $stmtUpdate->bind_param('si', $new_manufacturer_pallet_id, $pallet_id);
            if (!$stmtUpdate->execute()) {
                $stmtUpdate->close();
                throw new Exception('Failed to update pallet IDs: ' . $conn->error);
            }
            $stmtUpdate->close();

            $_SESSION['pallet_details_success'] = 'Pallet ID updated successfully.';
            $redirectParams = $_GET;
            $redirectParams['pallet_id'] = $pallet_id;
            header('Location: pallet_details.php?' . http_build_query($redirectParams));
            exit();
        } catch (Exception $updateError) {
            $errorMessage = $updateError->getMessage();
        }
    }

    // 2. Fetch Associated Deliveries with BOL-based cost calculations
    $sql_deliveries = "SELECT 
                            d.id AS delivery_id,
                            d.bol_number,
                            d.status_of_delivery,
                            -- Clean supplier name by removing project suffix
                            CASE
                                WHEN d.supplier LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(d.supplier, '-', 1))
                                ELSE d.supplier
                            END AS supplier,
                            d.anticipated_delivery_date,
                            d.actual_delivery_date,
                            proj.project_name AS delivery_project_name,
                            d.freight_cost,
                            d.accessorial_costs,
                            -- Calculate total cost for this specific delivery
                            (COALESCE(d.freight_cost, 0) + COALESCE(d.accessorial_costs, 0)) AS delivery_cost,
                            -- Get total cost for entire BOL (sum all deliveries with same BOL)
                            (SELECT SUM(COALESCE(d2.freight_cost, 0) + COALESCE(d2.accessorial_costs, 0)) 
                             FROM deliveries d2 
                             WHERE d2.bol_number = d.bol_number AND d2.bol_number IS NOT NULL AND d2.bol_number != '') AS bol_total_cost,
                            -- Count total pallets for entire BOL
                            (SELECT COUNT(DISTINCT dp3.inventory_pallet_id) 
                             FROM deliveries d3 
                             JOIN delivery_pallets dp3 ON d3.id = dp3.delivery_id 
                             WHERE d3.bol_number = d.bol_number AND d3.bol_number IS NOT NULL AND d3.bol_number != '') AS bol_total_pallets
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

    $total_delivery_cost = 0; // Initialize delivery cost tracker
    $processed_bols = []; // Track BOLs to avoid double-counting
    
    while ($row = $result_deliveries->fetch_assoc()) {
        // Calculate pallet cost based on BOL totals
        if ($row['bol_total_pallets'] > 0 && !empty($row['bol_number'])) {
            $row['truckload_cost'] = $row['bol_total_cost'];
            $row['pallet_cost'] = $row['bol_total_cost'] / $row['bol_total_pallets'];
            
            // Only add to total if we haven't processed this BOL yet
            if (!in_array($row['bol_number'], $processed_bols)) {
                $total_delivery_cost += $row['pallet_cost'];
                $processed_bols[] = $row['bol_number'];
            }
        } else {
            // Fallback for deliveries without BOL
            $row['truckload_cost'] = $row['delivery_cost'];
            $row['pallet_cost'] = $row['delivery_cost'];
            $total_delivery_cost += $row['pallet_cost'];
        }
        
        $associated_deliveries[] = $row;
    }
    $stmt_deliveries->close();

    // 3. Fetch Warehouse History with proper warehouse cost calculations
    $warehouse_history = [];
    $sql_warehouse_history = "SELECT DISTINCT
                                w.id AS warehouse_id,
                                w.name AS warehouse_name,
                                -- Get arrival info (when pallet first arrived at this warehouse)
                                (SELECT MIN(d_arr.warehouse_arrival_date) 
                                 FROM deliveries d_arr 
                                 JOIN delivery_pallets dp_arr ON d_arr.id = dp_arr.delivery_id 
                                 WHERE dp_arr.inventory_pallet_id = ? 
                                 AND d_arr.warehouse_id = w.id 
                                 AND d_arr.warehouse_arrival_date IS NOT NULL) AS arrival_date,
                                -- Get departure info (when pallet left this warehouse)
                                (SELECT MIN(d_dep.left_warehouse_date) 
                                 FROM deliveries d_dep 
                                 JOIN delivery_pallets dp_dep ON d_dep.id = dp_dep.delivery_id 
                                 WHERE dp_dep.inventory_pallet_id = ? 
                                 AND d_dep.origin_type = 'warehouse' 
                                 AND d_dep.origin_id = w.id 
                                 AND d_dep.left_warehouse_date IS NOT NULL) AS departure_date,
                                -- Count deliveries for in/out fee calculations
                                (SELECT COUNT(DISTINCT d_in.id) 
                                 FROM deliveries d_in 
                                 JOIN delivery_pallets dp_in ON d_in.id = dp_in.delivery_id 
                                 WHERE dp_in.inventory_pallet_id = ? 
                                 AND d_in.warehouse_id = w.id 
                                 AND d_in.warehouse_arrival_date IS NOT NULL) AS inbound_deliveries,
                                (SELECT COUNT(DISTINCT d_out.id) 
                                 FROM deliveries d_out 
                                 JOIN delivery_pallets dp_out ON d_out.id = dp_out.delivery_id 
                                 WHERE dp_out.inventory_pallet_id = ? 
                                 AND d_out.origin_type = 'warehouse' 
                                 AND d_out.origin_id = w.id 
                                 AND d_out.left_warehouse_date IS NOT NULL) AS outbound_deliveries
                            FROM warehouses w
                            WHERE w.id IN (
                                SELECT DISTINCT COALESCE(d.warehouse_id, d.origin_id) 
                                FROM deliveries d 
                                JOIN delivery_pallets dp ON d.id = dp.delivery_id 
                                WHERE dp.inventory_pallet_id = ? 
                                AND (d.warehouse_id IS NOT NULL OR (d.origin_type = 'warehouse' AND d.origin_id IS NOT NULL))
                            )
                            ORDER BY arrival_date ASC";

    $stmt_warehouse = $conn->prepare($sql_warehouse_history);
    if (!$stmt_warehouse) {
        throw new Exception("Error preparing warehouse history query: " . $conn->error);
    }
    $stmt_warehouse->bind_param("iiiii", $pallet_id, $pallet_id, $pallet_id, $pallet_id, $pallet_id);
    $stmt_warehouse->execute();
    $result_warehouse = $stmt_warehouse->get_result();

    // Fetch warehouse cost structures for all relevant warehouses
    $warehouse_costs = [];
    if ($result_warehouse->num_rows > 0) {
        $warehouse_ids = [];
        $temp_rows = [];
        
        // First pass: collect warehouse IDs and store rows
        while ($row = $result_warehouse->fetch_assoc()) {
            $warehouse_ids[] = $row['warehouse_id'];
            $temp_rows[] = $row;
        }
        
        // Fetch cost items for all warehouses at once
        if (!empty($warehouse_ids)) {
            $warehouse_ids_str = implode(',', array_map('intval', $warehouse_ids));
            $cost_sql = "SELECT warehouse_id, trigger_event, amount 
                         FROM warehouse_cost_items 
                         WHERE warehouse_id IN ({$warehouse_ids_str}) AND is_active = 1";
            $cost_result = $conn->query($cost_sql);
            
            if ($cost_result) {
                while ($cost = $cost_result->fetch_assoc()) {
                    $wid = $cost['warehouse_id'];
                    if (!isset($warehouse_costs[$wid])) {
                        $warehouse_costs[$wid] = ['in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];
                    }
                    switch ($cost['trigger_event']) {
                        case 'entry':
                            $warehouse_costs[$wid]['in_fee'] = $cost['amount'];
                            break;
                        case 'exit':
                            $warehouse_costs[$wid]['out_fee'] = $cost['amount'];
                            break;
                        case 'monthly':
                            $warehouse_costs[$wid]['monthly_storage_fee'] = $cost['amount'];
                            break;
                    }
                }
            }
        }
        
        // Second pass: calculate costs with fetched data
        $total_warehouse_cost = 0;
        foreach ($temp_rows as $row) {
            $wid = $row['warehouse_id'];
            $costs = $warehouse_costs[$wid] ?? ['in_fee' => 0, 'out_fee' => 0, 'monthly_storage_fee' => 0];
            
            // Calculate actual warehouse costs based on fees
            $in_fee_cost = $costs['in_fee'] * ($row['inbound_deliveries'] ?? 0);
            $out_fee_cost = $costs['out_fee'] * ($row['outbound_deliveries'] ?? 0);
            
            // Calculate storage cost based on days stored
            $storage_cost = 0;
            $days_stored = 0;
            if (!empty($row['arrival_date'])) {
                $arrival = new DateTime($row['arrival_date']);
                $departure = !empty($row['departure_date']) ? new DateTime($row['departure_date']) : new DateTime();
                $days_stored = $arrival->diff($departure)->days;
                $daily_storage_fee = $costs['monthly_storage_fee'] / 30;
                $storage_cost = $days_stored * $daily_storage_fee;
            }
            
            $row['total_warehouse_costs'] = $in_fee_cost + $out_fee_cost + $storage_cost;
            $row['cost_breakdown'] = "in_fee:{$in_fee_cost}|out_fee:{$out_fee_cost}|storage:{$storage_cost}|days:{$days_stored}";
            
            $total_warehouse_cost += $row['total_warehouse_costs'];
            $warehouse_history[] = $row;
        }
    } else {
        $total_warehouse_cost = 0;
    }
    $stmt_warehouse->close();
    
    // Calculate module cost (full and milestone-accrued)
    $module_cost_per_watt = $pallet_data['module_cost_per_watt'] ?? 0;
    $pallet_wattage = (float)($pallet_data['wattage'] ?? 0);
    $pallet_quantity = (int)($pallet_data['quantity'] ?? 0);
    $pallet_total_watts = $pallet_wattage * $pallet_quantity;
    $full_module_cost = ($module_cost_per_watt > 0 && $pallet_total_watts > 0)
        ? $module_cost_per_watt * $pallet_total_watts
        : 0;
    $has_module_cost = $module_cost_per_watt > 0;

    $module_milestone_breakdown = [
        'po_execution' => 0.0,
        'shipping' => 0.0,
        'customs_cleared' => 0.0,
        'project_delivery' => 0.0
    ];
    $module_trigger_labels = [
        'po_execution' => 'PO Execution',
        'shipping' => 'Shipping',
        'customs_cleared' => 'Customs Cleared',
        'project_delivery' => 'Project Delivery'
    ];
    $module_milestone_rows = [];
    $has_milestones = false;
    $accrued_module_cost = 0.0;

    $module_batch_id = $pallet_data['module_batch_id'] ?? null;
    if ($module_batch_id && $has_module_cost) {
        $stmt_milestones = $conn->prepare("SELECT COUNT(*) FROM module_batch_milestones WHERE module_id = ? AND is_active = 1");
        if ($stmt_milestones) {
            $stmt_milestones->bind_param("i", $module_batch_id);
            $stmt_milestones->execute();
            $stmt_milestones->bind_result($milestone_count);
            if ($stmt_milestones->fetch()) {
                $has_milestones = $milestone_count > 0;
            }
            $stmt_milestones->close();
        }
    }

    if ($has_milestones && $pallet_total_watts > 0) {
        $configured_triggers = [];
        $stmt_configured = $conn->prepare("
            SELECT trigger_event
            FROM module_batch_milestones
            WHERE module_id = ? AND is_active = 1
            ORDER BY display_order, id
        ");
        if ($stmt_configured) {
            $stmt_configured->bind_param("i", $module_batch_id);
            $stmt_configured->execute();
            $configured_result = $stmt_configured->get_result();
            while ($row = $configured_result->fetch_assoc()) {
                $trigger = $row['trigger_event'] ?? '';
                if ($trigger !== '') {
                    $configured_triggers[$trigger] = true;
                }
            }
            $stmt_configured->close();
        }

        $batch_total_watts = 0.0;
        $stmt_batch_watts = $conn->prepare("SELECT COALESCE(SUM(wattage * quantity), 0) FROM unassigned_module_items WHERE unassigned_module_id = ?");
        if ($stmt_batch_watts) {
            $stmt_batch_watts->bind_param("i", $module_batch_id);
            $stmt_batch_watts->execute();
            $stmt_batch_watts->bind_result($batch_total_watts);
            $stmt_batch_watts->fetch();
            $stmt_batch_watts->close();
        }

        $stmt_batch_triggered = $conn->prepare("
            SELECT mbm.trigger_event, COALESCE(SUM(dmi.payment_amount), 0) as total_amount
            FROM delivery_milestone_instances dmi
            JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
            WHERE dmi.module_batch_id = ? AND dmi.delivery_id IS NULL
            GROUP BY mbm.trigger_event
        ");
        if ($stmt_batch_triggered) {
            $stmt_batch_triggered->bind_param("i", $module_batch_id);
            $stmt_batch_triggered->execute();
            $batch_triggered = $stmt_batch_triggered->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_batch_triggered->close();
            foreach ($batch_triggered as $row) {
                $trigger = $row['trigger_event'] ?? '';
                if ($batch_total_watts > 0 && isset($module_milestone_breakdown[$trigger])) {
                    $share = ($pallet_total_watts / $batch_total_watts) * (float)$row['total_amount'];
                    $module_milestone_breakdown[$trigger] += $share;
                }
            }
        }

        $delivery_ids = array_values(array_unique(array_filter(array_column($associated_deliveries, 'delivery_id'))));
        if (!empty($delivery_ids)) {
            $placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));
            $types = str_repeat('i', count($delivery_ids));
            $delivery_watts = [];

            $stmt_delivery_watts = $conn->prepare("
                SELECT dp.delivery_id, COALESCE(SUM(ip.wattage * ip.quantity), 0) as total_watts
                FROM delivery_pallets dp
                JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
                WHERE dp.delivery_id IN ($placeholders)
                GROUP BY dp.delivery_id
            ");
            if ($stmt_delivery_watts) {
                $stmt_delivery_watts->bind_param($types, ...$delivery_ids);
                $stmt_delivery_watts->execute();
                $delivery_watt_rows = $stmt_delivery_watts->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_delivery_watts->close();
                foreach ($delivery_watt_rows as $row) {
                    $delivery_watts[(int)$row['delivery_id']] = (float)$row['total_watts'];
                }
            }

            $stmt_delivery_triggered = $conn->prepare("
                SELECT
                    dmi.delivery_id,
                    mbm.trigger_event,
                    COALESCE(SUM(
                        CASE
                            WHEN mbm.trigger_event = 'shipping' AND d.origin_type = 'warehouse' THEN 0
                            ELSE dmi.payment_amount
                        END
                    ), 0) as total_amount
                FROM delivery_milestone_instances dmi
                JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
                JOIN deliveries d ON dmi.delivery_id = d.id
                WHERE dmi.delivery_id IN ($placeholders)
                GROUP BY dmi.delivery_id, mbm.trigger_event
            ");
            if ($stmt_delivery_triggered) {
                $stmt_delivery_triggered->bind_param($types, ...$delivery_ids);
                $stmt_delivery_triggered->execute();
                $delivery_triggered_rows = $stmt_delivery_triggered->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_delivery_triggered->close();
                foreach ($delivery_triggered_rows as $row) {
                    $delivery_id = (int)$row['delivery_id'];
                    $trigger = $row['trigger_event'] ?? '';
                    $delivery_total_watts = $delivery_watts[$delivery_id] ?? 0.0;
                    if ($delivery_total_watts > 0 && isset($module_milestone_breakdown[$trigger])) {
                        $share = ($pallet_total_watts / $delivery_total_watts) * (float)$row['total_amount'];
                        $module_milestone_breakdown[$trigger] += $share;
                    }
                }
            }
        }

        foreach ($module_trigger_labels as $trigger => $label) {
            if (!empty($configured_triggers[$trigger])) {
                $module_milestone_rows[] = [
                    'label' => $label,
                    'amount' => $module_milestone_breakdown[$trigger] ?? 0.0
                ];
            }
        }

        $accrued_module_cost = array_sum($module_milestone_breakdown);
    }

    $total_module_cost = $has_milestones ? $accrued_module_cost : $full_module_cost;

    // Calculate total pallet cost (deliveries + warehouse + module)
    $total_pallet_cost = $total_delivery_cost + $total_warehouse_cost + $total_module_cost;

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

$edit_display_identifier_value = (string)($pallet_data['display_identifier'] ?? ($pallet_data['pallet_identifier'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pallet_ids']) && !empty($errorMessage)) {
    $edit_display_identifier_value = trim((string)($_POST['display_pallet_id'] ?? $edit_display_identifier_value));
}

$conn->close();

// (Breadcrumbs handled in template using the shared helper)
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
        main {
            padding: 0 0 24px;
        }
        .page-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 28px 30px;
            margin: 8px 20px 18px;
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
        .page-header h1 {
            margin: 0 0 6px;
            color: #293E4C;
            font-size: 1.95rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .page-header p {
            margin: 0;
            color: #6c757d;
            font-size: 0.95rem;
        }
        .main-content {
            width: 100%;
            max-width: none;
            margin: 0;
            box-sizing: border-box;
        }
        .details-container {
            background-color: #fff;
            padding: 20px;
            border: 1px solid #e9ecef;
            border-radius: 14px;
            margin-bottom: 30px;
            margin: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
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
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
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
        .success-message {
             color: #155724;
             background-color: #e6f6eb;
             padding: 10px;
             border: 1px solid #8fd19e;
             border-radius: 5px;
             margin: 20px;
        }
        .inline-edit-card {
            margin-top: 18px;
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
        }
        .inline-edit-card h3 {
            margin: 0 0 10px;
            font-size: 1rem;
            color: #293E4C;
        }
        .inline-edit-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
        }
        .inline-edit-field label {
            display: block;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .inline-edit-field input[type="text"] {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.95rem;
        }
        .inline-edit-hint {
            margin: 10px 0 0;
            color: #64748b;
            font-size: 0.85rem;
        }
        .inline-edit-actions {
            margin-top: 14px;
            display: flex;
            justify-content: flex-end;
        }
        .inline-edit-actions button {
            border: none;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .inline-edit-actions button:hover {
            opacity: 0.95;
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
        /* Clickable cost link */
        .cost-link {
            color: #488C9A;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 1px dashed #488C9A;
            transition: all 0.2s;
        }
        .cost-link:hover {
            color: #293E4C;
            border-bottom-color: #293E4C;
        }

        /* Module Cost Modal */
        .module-cost-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }
        .module-cost-modal-content {
            background: white;
            margin: 10% auto;
            padding: 0;
            width: 90%;
            max-width: 450px;
            border-radius: 16px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .module-cost-modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            color: white;
            padding: 20px 24px;
            border-radius: 16px 16px 0 0;
            position: relative;
        }
        .module-cost-modal-header h3 {
            margin: 0;
            font-size: 1.3em;
            font-weight: 600;
        }
        .module-cost-modal-close {
            position: absolute;
            top: 16px;
            right: 20px;
            font-size: 24px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            transition: transform 0.2s ease;
            border: none;
            background: transparent;
        }
        .module-cost-modal-close:hover {
            transform: scale(1.1);
        }
        .module-cost-modal-body {
            padding: 24px;
        }
        .calc-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e6e6e6;
        }
        .calc-row:last-child {
            border-bottom: none;
        }
        .calc-row.total {
            border-top: 2px solid #488C9A;
            margin-top: 8px;
            padding-top: 16px;
            font-weight: 700;
        }
        .calc-label {
            color: #6c757d;
        }
        .calc-value {
            font-weight: 600;
            color: #293E4C;
        }
        .calc-row.total .calc-value {
            color: #488C9A;
            font-size: 1.2em;
        }
        .calc-formula {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 16px;
            font-size: 0.9em;
            color: #6c757d;
            text-align: center;
        }
        .calc-formula strong {
            color: #293E4C;
        }

        @media screen and (max-width: 768px) {
            .page-header {
                margin: 8px 10px 14px;
                padding: 20px;
                border-radius: 16px;
            }
            .page-header h1 {
                font-size: 1.45rem;
            }
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
            .inline-edit-grid {
                grid-template-columns: 1fr;
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
    <?php 
        require_once 'components/breadcrumbs.php'; 
        // For customers, Manage Pallets breadcrumb should return to manage_pallets
        // Admins/Global Admins keep create_shipment as destination unless we came from warehouse view
        $useWarehouseCrumb = $fromWarehouseInfo && $breadcrumbWarehouseId > 0;
        $extraCrumbs = [];

        if ($useWarehouseCrumb) {
            $warehouseBackUrl = 'warehouse_info.php?warehouse_id=' . (int)$breadcrumbWarehouseId;
            if ($breadcrumbProjectId > 0) {
                $warehouseBackUrl .= '&project_id=' . (int)$breadcrumbProjectId;
            }
            if ($originBatchId > 0) {
                $warehouseBackUrl .= '&module_batch_id=' . (int)$originBatchId;
            }
            $extraCrumbs[] = [
                'label' => !empty($breadcrumbWarehouseName) ? $breadcrumbWarehouseName : 'Warehouse Details',
                'url' => $warehouseBackUrl
            ];
        } elseif ($fromCostDetails && $breadcrumbProjectId > 0) {
            $costUrl = 'project_cost_details.php?project_id=' . (int)$breadcrumbProjectId;
            $extraCrumbs[] = [
                'label' => 'Cost Details',
                'url' => $costUrl
            ];
        } elseif ($fromModuleMovements) {
            // Build module movements URL
            $mmUrl = 'module_movements.php';
            if ($breadcrumbProjectId > 0) {
                $mmUrl .= '?project_id=' . (int)$breadcrumbProjectId;
            }
            $extraCrumbs[] = [
                'label' => 'Module Movements',
                'url' => $mmUrl
            ];
            // Also add Manage Pallets in the chain
            $mpUrl = 'manage_pallets.php?from=module_movements';
            if ($breadcrumbProjectId > 0) {
                $mpUrl .= '&project_id=' . (int)$breadcrumbProjectId;
            }
            $extraCrumbs[] = [
                'label' => 'Manage Pallets',
                'url' => $mpUrl
            ];
        } else {
            $mpUrl = '';
            if ($role === 'admin' || $role === 'global_admin' || $role === 'customer_admin') {
                $mpUrl = 'create_shipment.php' . ($breadcrumbProjectId > 0 ? ('?project_id='.(int)$breadcrumbProjectId) : '');
            } else {
                $mpUrl = 'manage_pallets.php' . ($breadcrumbProjectId > 0 ? ('?project_id='.(int)$breadcrumbProjectId) : '');
            }
            $extraCrumbs[] = ['label' => 'Manage Pallets', 'url' => $mpUrl];
        }

        echo slp_render_breadcrumbs([
            'current_label' => 'Pallet Details',
            'project_id' => (int)$breadcrumbProjectId,
            'extra' => $extraCrumbs
        ]);
    ?>

    <div class="main-content">
        <div class="page-header">
            <h1>Pallet Details</h1>
            <p>
                Pallet ID <?php echo (int)$pallet_id; ?>. Full delivery, warehouse, and cost history for this pallet.
            </p>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="success-message">
                <strong>Success:</strong> <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>
        <?php if ($pallet_data): ?>
            <div class="details-container">
                <h2>Pallet Information</h2>
                <dl class="details-list">
                    <dt>Pallet ID:</dt>
                    <dd>
                        <?php if ($can_manage_pallet_updates): ?>
                            <form method="post" action="pallet_details.php?<?php echo htmlspecialchars(http_build_query($_GET)); ?>" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="update_pallet_ids" value="1">
                                <input
                                    type="text"
                                    id="display_pallet_id"
                                    name="display_pallet_id"
                                    value="<?php echo htmlspecialchars($edit_display_identifier_value); ?>"
                                    required
                                    style="min-width:240px; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.95rem;"
                                >
                                <button type="submit" style="border:none; background:linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color:#fff; padding:8px 14px; border-radius:8px; font-weight:600; cursor:pointer;">Save</button>
                            </form>
                            <div style="margin-top:6px; font-size:0.82rem; color:#64748b;">
                                Defaults to the Solterra generated ID. Enter the real pallet ID once available.
                            </div>
                        <?php else: ?>
                            <?php echo htmlspecialchars($pallet_data['display_identifier'] ?? ($pallet_data['pallet_identifier'] ?? 'N/A')); ?>
                        <?php endif; ?>
                    </dd>

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

            <?php if ($total_pallet_cost > 0 || $total_delivery_cost > 0 || $total_warehouse_cost > 0 || $total_module_cost > 0): ?>
            <div class="cost-summary">
                <h3>Total Pallet Cost Breakdown</h3>
                <div style="margin-bottom: 10px;">
                    <strong>Pallet Cost (Deliveries):</strong> $<?php echo number_format($total_delivery_cost, 2); ?>
                </div>
                <div style="margin-bottom: 10px;">
                    <strong>Pallet Cost (Warehouse):</strong> $<?php echo number_format($total_warehouse_cost, 2); ?>
                </div>
                <?php if ($has_module_cost): ?>
                <div style="margin-bottom: 10px;">
                    <strong>Pallet Cost (Module<?php echo $has_milestones ? ' - Accrued' : ''; ?>):</strong>
                    <a href="javascript:void(0)" onclick="openModuleCostModal()" class="cost-link">$<?php echo number_format($total_module_cost, 2); ?></a>
                </div>
                <?php endif; ?>
                <hr style="margin: 10px 0; border: 1px solid #488C9A;">
                <div class="cost-amount">
                    <strong>Total Pallet Cost:</strong> $<?php echo number_format($total_pallet_cost, 2); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Module Cost Calculation Modal -->
            <?php if ($has_module_cost): ?>
            <div id="moduleCostModal" class="module-cost-modal">
                <div class="module-cost-modal-content">
                    <div class="module-cost-modal-header">
                        <h3>Module Cost Breakdown</h3>
                        <button class="module-cost-modal-close" onclick="closeModuleCostModal()">&times;</button>
                    </div>
                    <div class="module-cost-modal-body">
                        <div class="calc-row">
                            <span class="calc-label">Cost per Watt</span>
                            <span class="calc-value">$<?php echo number_format($module_cost_per_watt, 4); ?></span>
                        </div>
                        <div class="calc-row">
                            <span class="calc-label">Wattage per Module</span>
                            <span class="calc-value"><?php echo number_format($pallet_wattage); ?> W</span>
                        </div>
                        <div class="calc-row">
                            <span class="calc-label">Number of Modules</span>
                            <span class="calc-value"><?php echo number_format($pallet_quantity); ?></span>
                        </div>
                        <?php if ($has_milestones): ?>
                            <?php if (!empty($module_milestone_rows)): ?>
                                <?php foreach ($module_milestone_rows as $row): ?>
                                    <div class="calc-row">
                                        <span class="calc-label"><?php echo htmlspecialchars($row['label']); ?> (Accrued)</span>
                                        <span class="calc-value">$<?php echo number_format($row['amount'], 2); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <div class="calc-row total">
                                <span class="calc-label">Accrued Module Cost</span>
                                <span class="calc-value">$<?php echo number_format($total_module_cost, 2); ?></span>
                            </div>
                            <div class="calc-formula">
                                <strong>Note:</strong> Accrued totals reflect triggered milestones.
                            </div>
                        <?php else: ?>
                            <div class="calc-row">
                                <span class="calc-label">Full Module Value</span>
                                <span class="calc-value">$<?php echo number_format($full_module_cost, 2); ?></span>
                            </div>
                            <div class="calc-row total">
                                <span class="calc-label">Total Module Cost</span>
                                <span class="calc-value">$<?php echo number_format($total_module_cost, 2); ?></span>
                            </div>
                            <div class="calc-formula">
                                <strong>Formula:</strong> Cost/Watt × Wattage × Modules
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
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
                                    <th>Origin</th>
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
                                            <?php if ($role === 'admin' || $role === 'global_admin' || $role === 'customer_admin'): ?>
                                                <a href="manage_deliveries.php#delivery-<?php echo $delivery['delivery_id']; ?>" style="color: #488C9A; text-decoration: none; font-weight: 500;"><?php echo $delivery['delivery_id']; ?></a>
                                            <?php else: ?>
                                                <span style="font-weight: 500;"><?php echo $delivery['delivery_id']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($delivery['bol_number'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['delivery_project_name'] ?? ''); ?></td>
                                        <td>
                                            <?php 
                                            // Always show the actual manufacturer from pallet data
                                            echo htmlspecialchars($pallet_data['origin_vendor'] ?? 'N/A'); 
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            // Show the origin (supplier field from delivery)
                                            echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); 
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

            <div class="deliveries-section">
                <h2>Warehouse History</h2>
                <?php if (!empty($warehouse_history)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Warehouse</th>
                                    <th>Arrival Date</th>
                                    <th>Departure Date</th>
                                    <th>Days at Warehouse</th>
                                    <th>Status</th>
                                    <th>Total Costs</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($warehouse_history as $warehouse): ?>
                                    <tr>
                                        <td>
                                            <?php if ($role === 'admin' || $role === 'global_admin' || $role === 'customer_admin'): ?>
                                                <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo $warehouse['warehouse_id']; ?>" style="color: #488C9A; text-decoration: none; font-weight: 500;"><?php echo htmlspecialchars($warehouse['warehouse_name']); ?></a>
                                            <?php else: ?>
                                                <span style="font-weight: 500;"><?php echo htmlspecialchars($warehouse['warehouse_name']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($warehouse['arrival_date'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                            if (!empty($warehouse['departure_date'])) {
                                                echo htmlspecialchars($warehouse['departure_date']);
                                            } else {
                                                echo '<span style="color: #856404; font-style: italic;">Still at warehouse</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if (!empty($warehouse['arrival_date'])) {
                                                $arrival = new DateTime($warehouse['arrival_date']);
                                                $departure = !empty($warehouse['departure_date']) ? new DateTime($warehouse['departure_date']) : new DateTime();
                                                $days = $arrival->diff($departure)->days;
                                                echo $days . ' day' . ($days != 1 ? 's' : '');
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if (empty($warehouse['departure_date'])) {
                                                echo '<span class="status-badge status-transit">Current Location</span>';
                                            } else {
                                                echo '<span class="status-badge status-delivered">Departed</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($warehouse['total_warehouse_costs'] > 0) {
                                                $cost_data = htmlspecialchars($warehouse['cost_breakdown'] ?? '');
                                                echo '<a href="#" onclick="showWarehouseCostModal(\'' . htmlspecialchars($warehouse['warehouse_name']) . '\', \'' . $cost_data . '\', ' . $warehouse['total_warehouse_costs'] . ')" style="color: #488C9A; text-decoration: none; font-weight: bold;">$' . number_format($warehouse['total_warehouse_costs'], 2) . '</a>';
                                            } else {
                                                echo '$0.00';
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
                        <p style="margin: 0; font-size: 1.1em;">This pallet has no warehouse history recorded.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?> 
    </div>
</main>

<!-- Warehouse Cost Breakdown Modal -->
<div id="warehouseCostModal" class="cost-modal">
    <div class="cost-modal-content">
        <div class="cost-modal-header">
            <h3 id="warehouseModalTitle">Warehouse Cost Breakdown</h3>
            <span class="cost-modal-close" onclick="closeWarehouseCostModal()">&times;</span>
        </div>
        <div class="cost-modal-body">
            <div id="warehouseModalContent">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>
</div>

<style>
/* Cost Modal Styling */
.cost-modal {
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

.cost-modal-content {
    background-color: #fefefe;
    margin: 10% auto;
    padding: 0;
    border: 1px solid #888;
    width: 90%;
    max-width: 600px;
    border-radius: 8px;
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.cost-modal-header {
    color: white;
    padding: 15px 20px;
    border-radius: 8px 8px 0 0;
    position: relative;
}

.cost-modal-header h3 {
    margin: 0;
    font-size: 1.2em;
}

.cost-modal-close {
    position: absolute;
    right: 15px;
    top: 15px;
    font-size: 24px;
    cursor: pointer;
    color: #293E4C;
}

.cost-modal-close:hover {
    opacity: 0.7;
}

.cost-modal-body {
    padding: 20px;
}

.breakdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.breakdown-item:last-child {
    border-bottom: none;
    font-weight: bold;
    border-top: 2px solid #488C9A;
    margin-top: 10px;
    padding-top: 15px;
}

.breakdown-label {
    font-weight: 500;
    color: #333;
}

.breakdown-value {
    font-weight: bold;
    color: #488C9A;
}
</style>

<script>
// Warehouse Cost Modal Functions
function showWarehouseCostModal(warehouseName, costData, totalCost) {
    document.getElementById('warehouseModalTitle').textContent = `Cost Breakdown - ${warehouseName}`;
    
    const modalContent = document.getElementById('warehouseModalContent');
    modalContent.innerHTML = '';
    
    if (costData && costData.trim() !== '') {
        const costParts = costData.split('|');
        let inFeeCost = 0;
        let outFeeCost = 0;
        let storageCost = 0;
        let daysStored = 0;
        
        // Parse the new cost breakdown format
        costParts.forEach(part => {
            const [key, value] = part.split(':');
            const numValue = parseFloat(value) || 0;
            
            switch(key) {
                case 'in_fee':
                    inFeeCost = numValue;
                    break;
                case 'out_fee':
                    outFeeCost = numValue;
                    break;
                case 'storage':
                    storageCost = numValue;
                    break;
                case 'days':
                    daysStored = parseInt(value) || 0;
                    break;
            }
        });
        
        // Create breakdown items
        if (inFeeCost > 0) {
            const inFeeDiv = document.createElement('div');
            inFeeDiv.className = 'breakdown-item';
            inFeeDiv.innerHTML = `
                <div class="breakdown-label">
                    Incoming Fee<br>
                    <small style="color: #666;">Fee for receiving pallet</small>
                </div>
                <div class="breakdown-value">$${inFeeCost.toFixed(2)}</div>
            `;
            modalContent.appendChild(inFeeDiv);
        }
        
        if (outFeeCost > 0) {
            const outFeeDiv = document.createElement('div');
            outFeeDiv.className = 'breakdown-item';
            outFeeDiv.innerHTML = `
                <div class="breakdown-label">
                    Outgoing Fee<br>
                    <small style="color: #666;">Fee for shipping pallet out</small>
                </div>
                <div class="breakdown-value">$${outFeeCost.toFixed(2)}</div>
            `;
            modalContent.appendChild(outFeeDiv);
        }
        
        if (storageCost > 0) {
            const storageDiv = document.createElement('div');
            storageDiv.className = 'breakdown-item';
            storageDiv.innerHTML = `
                <div class="breakdown-label">
                    Storage Fee<br>
                    <small style="color: #666;">${daysStored} days of storage</small>
                </div>
                <div class="breakdown-value">$${storageCost.toFixed(2)}</div>
            `;
            modalContent.appendChild(storageDiv);
        }
        
        // Add totals
        const totalDiv = document.createElement('div');
        totalDiv.className = 'breakdown-item';
        totalDiv.innerHTML = `
            <div class="breakdown-label">Total Warehouse Costs</div>
            <div class="breakdown-value">$${totalCost.toFixed(2)}</div>
        `;
        modalContent.appendChild(totalDiv);
        
    } else {
        modalContent.innerHTML = '<p>No detailed cost breakdown available.</p>';
    }
    
    document.getElementById('warehouseCostModal').style.display = 'block';
}

function closeWarehouseCostModal() {
    document.getElementById('warehouseCostModal').style.display = 'none';
}

// Close modal when clicking outside of it
window.addEventListener('click', function(event) {
    const warehouseModal = document.getElementById('warehouseCostModal');
    if (event.target === warehouseModal) {
        closeWarehouseCostModal();
    }
    const moduleModal = document.getElementById('moduleCostModal');
    if (moduleModal && event.target === moduleModal) {
        closeModuleCostModal();
    }
});

// Module Cost Modal Functions
function openModuleCostModal() {
    const modal = document.getElementById('moduleCostModal');
    if (modal) {
        modal.style.display = 'block';
    }
}

function closeModuleCostModal() {
    const modal = document.getElementById('moduleCostModal');
    if (modal) {
        modal.style.display = 'none';
    }
}
</script>

</body>
</html> 
