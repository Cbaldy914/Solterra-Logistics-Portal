<?php
session_name("logistics_session");
session_start();

// Only allow admin and global_admin roles for shipment creation
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get project_id from URL parameter for auto-filtering and breadcrumbs
$project_id_from_url = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

// Get status_filter from URL parameter for auto-filtering pallets
$status_filter_from_url = isset($_GET['status_filter']) ? htmlspecialchars($_GET['status_filter']) : '';

// --- Account Access Control ---
$account_id_for_admin = null;
$is_global_admin = ($role === 'global_admin');

if ($role === 'admin') {
    $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $user_id);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($account_id_for_admin);
        $stmtAdminAcc->fetch();
        $stmtAdminAcc->close();
    }
}

// --- Handle Pallet Shipment ---
$shipMessage = '';
$createdDeliveryIds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ship_pallets') {
    $conn->begin_transaction();
    try {
        // Get selected pallet IDs first
        $palletIds = $_POST['selected_pallets'] ?? [];
        if (empty($palletIds)) {
            throw new Exception('No pallets selected to ship.');
        }

        // --- Determine batch info from selected pallets ---
        $placeholders_for_batch = implode(',', array_fill(0, count($palletIds), '?'));
        $types_for_batch = str_repeat('i', count($palletIds));
        
        $batch_info_stmt = $conn->prepare("
            SELECT DISTINCT m.vendor_name, m.project_id 
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            WHERE ip.id IN ($placeholders_for_batch)
            LIMIT 1
        ");
        if (!$batch_info_stmt) {
            throw new Exception("Failed to prepare batch info query: " . $conn->error);
        }
        $batch_info_stmt->bind_param($types_for_batch, ...$palletIds);
        $batch_info_stmt->execute();
        $batch_info_result = $batch_info_stmt->get_result();
        
        if ($batch_info_row = $batch_info_result->fetch_assoc()) {
            $current_batch_vendor_name = $batch_info_row['vendor_name'] ?? 'Unknown Vendor';
            $source_project_id_for_delivery = $batch_info_row['project_id']; // This can be NULL
        } else {
            $batch_info_stmt->close();
            throw new Exception("Could not find batch information for selected pallets.");
        }
        $batch_info_stmt->close();
        // --- END batch info determination --- 

        $destinationType = $_POST['destination_type'] ?? 'project';
        $destinationId   = isset($_POST['destination_id']) ? intval($_POST['destination_id']) : 0;
        $originType      = $_POST['origin_type'] ?? 'manufacturer';
        $originId        = isset($_POST['origin_id']) && $_POST['origin_id'] !== '' ? intval($_POST['origin_id']) : null;
        $bolNumber       = trim($_POST['bol_number'] ?? '');
        $bolNumbers      = isset($_POST['bol_numbers']) ? json_decode($_POST['bol_numbers'], true) : [];
        $departureDate   = $_POST['departure_date'] ?? null;
        $estArrivalDate  = $_POST['est_arrival_date'] ?? null;
        $shipmentMode    = $_POST['shipment_mode'] ?? 'single';
        
        // If origin is manufacturer, find the correct manufacturer location ID (override any manufacturer ID passed from UI)
        if ($originType === 'manufacturer' && !empty($current_batch_vendor_name)) {
            $originId = null; // Reset to ensure we find the correct location ID, not use manufacturer ID from UI
            
            // Extract manufacturer name (remove location suffix like " - Raleigh Plant")
            $manufacturer_name = $current_batch_vendor_name;
            if (strpos($current_batch_vendor_name, ' - ') !== false) {
                $manufacturer_name = trim(explode(' - ', $current_batch_vendor_name)[0]);
            }
            
            // Try to find the manufacturer location ID
            // First, get manufacturer ID
            $stmt_mfg = $conn->prepare("SELECT id FROM manufacturers WHERE name = ? OR short_name = ? LIMIT 1");
            if ($stmt_mfg) {
                $stmt_mfg->bind_param("ss", $manufacturer_name, $manufacturer_name);
                $stmt_mfg->execute();
                $stmt_mfg->bind_result($manufacturer_id);
                if ($stmt_mfg->fetch()) {
                    $stmt_mfg->close();
                    
                    // If vendor name contains location info (like "Meyer Burger - Raleigh Plant"), find specific location
                    if (strpos($current_batch_vendor_name, ' - ') !== false) {
                        $location_part = trim(explode(' - ', $current_batch_vendor_name)[1]);
                        $stmt_loc = $conn->prepare("SELECT id FROM manufacturer_locations WHERE manufacturer_id = ? AND location_name LIKE ? AND is_active = 1 LIMIT 1");
                        if ($stmt_loc) {
                            $location_search = "%{$location_part}%";
                            $stmt_loc->bind_param("is", $manufacturer_id, $location_search);
                            $stmt_loc->execute();
                            $stmt_loc->bind_result($found_location_id);
                            if ($stmt_loc->fetch()) {
                                $originId = $found_location_id;
                            }
                            $stmt_loc->close();
                        }
                    }
                    
                    // If no specific location found, use primary location
                    if (!$originId) {
                        $stmt_primary = $conn->prepare("SELECT id FROM manufacturer_locations WHERE manufacturer_id = ? AND is_primary = 1 AND is_active = 1 LIMIT 1");
                        if ($stmt_primary) {
                            $stmt_primary->bind_param("i", $manufacturer_id);
                            $stmt_primary->execute();
                            $stmt_primary->bind_result($primary_location_id);
                            if ($stmt_primary->fetch()) {
                                $originId = $primary_location_id;
                            }
                            $stmt_primary->close();
                        }
                    }
                } else {
                    $stmt_mfg->close();
                }
            }
        }
        

        $palletsPerTruck = (isset($_POST['pallets_per_truck']) && is_numeric($_POST['pallets_per_truck']))
                           ? intval($_POST['pallets_per_truck'])
                           : 1;
        
        // Cost and logistics fields
        $freightCost = isset($_POST['freight_cost']) && $_POST['freight_cost'] !== '' ? (float)$_POST['freight_cost'] : 0.0;
        $accessorialCost = isset($_POST['accessorial_cost']) && $_POST['accessorial_cost'] !== '' ? (float)$_POST['accessorial_cost'] : 0.0;
        $customerCost = isset($_POST['customer_cost']) && $_POST['customer_cost'] !== '' ? (float)$_POST['customer_cost'] : 0.0;
        $miles = isset($_POST['miles']) && $_POST['miles'] !== '' ? (float)$_POST['miles'] : null;
        
        if ($destinationId <= 0) {
            throw new Exception('No destination selected.');
        }
        if (empty($departureDate)) {
            throw new Exception('Departure date is required.');
        }
        if (empty($estArrivalDate)) {
            throw new Exception('Estimated arrival date is required.');
        }

        // Fetch supplier/vendor_name for this batch - ALWAYS use manufacturer, not origin location
        $vendor_name = $current_batch_vendor_name ?? 'Unknown Vendor';
        
        // Extract manufacturer name from vendor_name (remove anything after " - " if present)
        $supplier_name = $vendor_name;
        if (strpos($vendor_name, ' - ') !== false) {
            $supplier_name = trim(explode(' - ', $vendor_name)[0]);
        }
        
        // Note: We do NOT change supplier based on origin type
        // Supplier should always reflect the manufacturer, not the origin location

        // Fetch details for selected pallets including manufacturer information
        $placeholders = implode(',', array_fill(0, count($palletIds), '?'));
        $types        = str_repeat('i', count($palletIds));
        $stmtFetchPallets = $conn->prepare("
            SELECT ip.id, ip.wattage, ip.quantity,
                   COALESCE(ip.manufacturer, 
                       CASE 
                           WHEN m.vendor_name LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(m.vendor_name, '-', 1))
                           ELSE m.vendor_name
                       END,
                       'Unknown Manufacturer'
                   ) as manufacturer
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            WHERE ip.id IN ($placeholders)
        ");
        if (!$stmtFetchPallets) throw new Exception("Failed to prepare pallet fetch: " . $conn->error);
        $stmtFetchPallets->bind_param($types, ...$palletIds);
        $stmtFetchPallets->execute();
        $resultPallets = $stmtFetchPallets->get_result();
        $allPallets = [];
        while ($pallet = $resultPallets->fetch_assoc()) {
            $allPallets[] = $pallet;
        }
        $stmtFetchPallets->close();

        // Split into groups if multi-shipment
        $palletGroups = [];
        if ($shipmentMode === 'multi' && $palletsPerTruck > 0) {
            for ($i = 0; $i < count($allPallets); $i += $palletsPerTruck) {
                $palletGroups[] = array_slice($allPallets, $i, $palletsPerTruck);
            }
        } else {
            $palletGroups[] = $allPallets;
        }

        // Dynamic delivery insert to properly record origin details
        $stmtLink = $conn->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
        if (!$stmtLink) throw new Exception("Failed to prepare pallet link insert: " . $conn->error);

        $sqlUp = "UPDATE inventory_pallets SET status = ?, current_project_id = ?, current_warehouse_id = ?, arrival_date = ? WHERE id = ?";
        $stmtUp = $conn->prepare($sqlUp);
        if (!$stmtUp) throw new Exception("Failed to prepare pallet update: " . $conn->error);

        $groupIndex = 0;
        foreach ($palletGroups as $group) {
            // Determine which BOL number to use
            $currentBolNumber = $bolNumber;
            if ($shipmentMode === 'multi' && !empty($bolNumbers)) {
                $currentBolNumber = $bolNumbers[$groupIndex] ?? $bolNumber;
            }
            
            // Ensure we have a BOL number - fallback to generic if empty
            if (empty($currentBolNumber)) {
                $currentBolNumber = 'BOL-' . date('Ymd-His') . '-' . ($groupIndex + 1);
            }
            


            // Group by wattage for each delivery
            $groupByWattage = [];
            foreach ($group as $pallet) {
                $w = $pallet['wattage'];
                if (!isset($groupByWattage[$w])) $groupByWattage[$w] = [];
                $groupByWattage[$w][] = $pallet;
            }

            foreach ($groupByWattage as $wattage => $palletsForWatt) {
                $groupQty = array_sum(array_column($palletsForWatt, 'quantity'));
                
                // Determine manufacturer for this specific group (should be consistent within a wattage group)
                $groupManufacturer = $supplier_name; // Default fallback
                if (!empty($palletsForWatt) && isset($palletsForWatt[0]['manufacturer'])) {
                    $groupManufacturer = $palletsForWatt[0]['manufacturer'];
                }

                $deliveryColumns = [
                    'supplier',
                    'origin_type',
                    'origin_id',
                    'wattage',
                    'quantity',
                    'bol_number'
                ];
                $deliveryParams = [
                    $groupManufacturer, // Use manufacturer from pallets, not warehouse name
                    $originType,
                    $originId,
                    $wattage,
                    $groupQty,
                    $currentBolNumber
                ];
                $deliveryTypes = 'ssiiss'; // supplier, origin_type, origin_id, wattage, quantity, bol_number

                if ($originType === 'warehouse') {
                    $deliveryColumns[] = 'left_warehouse_date';
                    $deliveryParams[] = $departureDate;
                    $deliveryTypes .= 's';
                }

                $deliveryColumns[] = 'anticipated_delivery_date';
                $deliveryParams[] = $estArrivalDate;
                $deliveryTypes .= 's';

                $statusOfDelivery = ($destinationType === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';
                $deliveryColumns[] = 'status_of_delivery';
                $deliveryParams[] = $statusOfDelivery;
                $deliveryTypes .= 's';

                $deliveryColumns[] = 'freight_cost';
                $deliveryParams[] = $freightCost;
                $deliveryTypes .= 'd';

                $deliveryColumns[] = 'accessorial_costs';
                $deliveryParams[] = $accessorialCost;
                $deliveryTypes .= 'd';

                $deliveryColumns[] = 'customer_cost';
                $deliveryParams[] = $customerCost;
                $deliveryTypes .= 'd';

                $deliveryColumns[] = 'miles';
                $deliveryParams[] = $miles;
                $deliveryTypes .= 'd';

                if ($destinationType === 'project') {
                    $deliveryColumns[] = 'project_id';
                    $deliveryParams[] = $destinationId;
                    $deliveryTypes .= 'i';
                    if ($originType === 'warehouse') {
                        $deliveryColumns[] = 'warehouse_id';
                        $deliveryParams[] = $originId;
                        $deliveryTypes .= 'i';
                    }
                } else { // Destination is another warehouse
                    $deliveryColumns[] = 'project_id';
                    $deliveryParams[] = $source_project_id_for_delivery;
                    $deliveryTypes .= 'i';

                    $deliveryColumns[] = 'warehouse_id';
                    $deliveryParams[] = $destinationId;
                    $deliveryTypes .= 'i';
                }
                


                $placeholders = implode(',', array_fill(0, count($deliveryParams), '?'));
                $sqlDeliveryInsert = 'INSERT INTO deliveries (' . implode(',', $deliveryColumns) . ') VALUES (' . $placeholders . ')';
                $stmtDelivery = $conn->prepare($sqlDeliveryInsert);
                if (!$stmtDelivery) throw new Exception('Failed to prepare delivery insert: ' . $conn->error);

                $stmtDelivery->bind_param($deliveryTypes, ...$deliveryParams);
                if (!$stmtDelivery->execute()) {
                    throw new Exception('Failed to insert delivery for ' . $wattage . 'W: ' . $stmtDelivery->error);
                }

                $deliveryId = $conn->insert_id;
                $createdDeliveryIds[] = $deliveryId;

                foreach ($palletsForWatt as $pallet) {
                    $stmtLink->bind_param('ii', $deliveryId, $pallet['id']);
                    if (!$stmtLink->execute()) {
                        throw new Exception('Failed to link pallet ID ' . $pallet['id'] . ' to delivery ' . $deliveryId . ': ' . $stmtLink->error);
                    }

                    $status = ($destinationType === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';
                    $projectId = ($destinationType === 'project') ? $destinationId : null;
                    $warehouseId = ($destinationType === 'warehouse') ? $destinationId : null;
                    $stmtUp->bind_param('siisi', $status, $projectId, $warehouseId, $estArrivalDate, $pallet['id']);
                    if (!$stmtUp->execute()) {
                        throw new Exception('Failed to update pallet ID ' . $pallet['id'] . ': ' . $stmtUp->error);
                    }
                }
            }
            $groupIndex++;
        }

        $stmtLink->close();
        $stmtUp->close();
        $conn->commit();
        
        $totalDeliveries = count($createdDeliveryIds);
        $totalPallets = count($palletIds);
        
        // Check if user wants to generate BOL
        $generateBol = isset($_POST['generate_bol']) && $_POST['generate_bol'] === '1';
        $deliveryIdsParam = implode(',', $createdDeliveryIds);
        
        if ($generateBol) {
            // User wants to generate BOL - redirect directly to BOL generation
            // Store a simple success message for later display after BOL generation
            $_SESSION['shipment_success_for_bol'] = "{$totalDeliveries} deliveries successfully created for {$totalPallets} pallets.";
            
            // Preserve project_id for breadcrumb navigation and links
            if ($project_id_from_url > 0) {
                $_SESSION['shipment_origin_project_id'] = $project_id_from_url;
            }
            
            // Preserve project_id for any links needed later
            if ($destinationType === 'project' && $destinationId > 0) {
                $dateParam = urlencode($estArrivalDate);
                $_SESSION['shipment_scheduling_link'] = "scheduling.php?project_id={$destinationId}&date={$dateParam}";
            }
            
            // Include project_id in BOL URL if available
            $bolUrl = "generate_bol.php?delivery_ids={$deliveryIdsParam}";
            if ($project_id_from_url > 0) {
                $bolUrl .= "&project_id={$project_id_from_url}";
            }
            
            header("Location: {$bolUrl}");
            exit();
        } else {
            // Normal flow - show success message with links
            $bolLinkUrl = "generate_bol.php?delivery_ids={$deliveryIdsParam}";
            if ($project_id_from_url > 0) {
                $bolLinkUrl .= "&project_id={$project_id_from_url}";
            }
            $bolLink = " <a href='{$bolLinkUrl}' style='color: #488C9A; text-decoration: underline; margin-left: 10px;'>Generate BOL</a>";
            
            if ($destinationType === 'warehouse') {
                $shipMessage = "{$totalDeliveries} deliveries successfully created for {$totalPallets} pallets. Pallets are now in transit to the selected warehouse. To receive modules into the warehouse when they arrive, <a href='manage_warehouse_inventory.php?warehouse_id={$destinationId}' style='color: #488C9A; text-decoration: underline;'>click here</a>.{$bolLink}";
            } else {
                // Project delivery - offer scheduling
                $shipMessage = "{$totalDeliveries} deliveries successfully created for {$totalPallets} pallets.";
                
                // Provide single scheduling link for the project
                if ($destinationId > 0) {
                    $dateParam = urlencode($estArrivalDate);
                    $shipMessage .= " <a href='scheduling.php?project_id={$destinationId}&date={$dateParam}' style='color: #488C9A; text-decoration: underline;'>Schedule Delivery</a>";
                }
                
                // Add BOL generation link
                $shipMessage .= $bolLink;
            }
        }
    } catch (Exception $e) {
        $conn->rollback();
        $shipMessage = "Error creating transfer delivery: " . $e->getMessage();
    }
    
    // Store message in session and redirect to prevent form resubmission
    $_SESSION['create_shipment_message'] = $shipMessage;
    // Preserve project_id in redirect for breadcrumb navigation
    $redirect_url = "create_shipment.php";
    if ($project_id_from_url > 0) {
        $redirect_url .= "?project_id=" . $project_id_from_url;
    }
    header("Location: " . $redirect_url);
    exit();
}

// --- Data Fetching ---
$pallets = [];
$errorMessage = '';

try {
    // Comprehensive query to fetch pallet details from ALL projects
    $sql = "SELECT 
                ip.id AS pallet_id,
                ip.pallet_identifier,
                ip.wattage,
                ip.quantity,
                ip.status,
                ip.arrival_date,
                ip.unassigned_module_item_id,
                ip.current_warehouse_id,
                ip.current_project_id,
                ip.assigned_project_id,
                m.vendor_name AS origin_vendor,
                COALESCE(
                    CONCAT(ml.street_address, ', ', ml.city, ', ', ml.state, ' ', ml.zip_code),
                    m.initial_location
                ) AS origin_vendor_address,
                COALESCE(mfg.name, 
                    CASE 
                        WHEN m.vendor_name LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(m.vendor_name, '-', 1))
                        ELSE m.vendor_name
                    END
                ) AS origin_vendor_name,
                COALESCE(ml.location_name, '') AS origin_location_name,
                COALESCE(ml.city, '') AS origin_vendor_city,
                COALESCE(ml.state, '') AS origin_vendor_state,
                m.account_id AS pallet_account_id,
                w.name AS current_warehouse_name,
                w.street_address as warehouse_street, w.city as warehouse_city, w.state as warehouse_state, w.zip_code as warehouse_zip,
                p_current.project_name AS current_project_name,
                p_current.account_id AS current_project_account_id,
                p_current.street_address as project_street, p_current.city as project_city, p_current.state as project_state, p_current.zip_code as project_zip,
                p_assigned.project_name AS assigned_project_name,
                p_assigned.account_id AS assigned_project_account_id,
                COALESCE(p_current.project_name, p_assigned.project_name, 'Unassigned') AS display_project_name,
                GROUP_CONCAT(DISTINCT CONCAT(d.id, ':', COALESCE(d.bol_number, 'No BOL')) ORDER BY d.id SEPARATOR '|') as delivery_info
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN manufacturer_locations ml ON ip.manufacturer_location_id = ml.id
            LEFT JOIN manufacturers mfg ON ml.manufacturer_id = mfg.id
            LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
            LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
            LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
            LEFT JOIN deliveries d ON dp.delivery_id = d.id";
    
    // Add account filtering for admin role (only see pallets from their account's projects)
    if ($role === 'admin' && $account_id_for_admin) {
        $sql .= " WHERE (p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?)";
    }
    
    $sql .= " GROUP BY ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status, ip.arrival_date, ip.unassigned_module_item_id, ip.current_warehouse_id, ip.current_project_id, ip.assigned_project_id, m.vendor_name, m.account_id, ml.street_address, ml.city, ml.state, ml.zip_code, ml.location_name, mfg.name, w.name, w.street_address, w.city, w.state, w.zip_code, p_current.project_name, p_current.account_id, p_current.street_address, p_current.city, p_current.state, p_current.zip_code, p_assigned.project_name, p_assigned.account_id
              ORDER BY ip.id ASC";
    
    if ($role === 'admin' && $account_id_for_admin) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $account_id_for_admin, $account_id_for_admin, $account_id_for_admin);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pallets[] = $row;
        }
        $stmt->close();
    } else {
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $pallets[] = $row;
            }
        }
    }

    // Add full addresses for pallets
    foreach ($pallets as &$pallet) {
        if ($pallet['status'] === 'In Warehouse' && $pallet['current_warehouse_id']) {
            $warehouse_address_parts = array_filter([$pallet['warehouse_street'], $pallet['warehouse_city'], $pallet['warehouse_state'], $pallet['warehouse_zip']]);
            $pallet['warehouse_full_address'] = implode(', ', $warehouse_address_parts);
        }
        if ($pallet['status'] === 'Delivered to Project' && $pallet['current_project_id']) {
            $project_address_parts = array_filter([$pallet['project_street'], $pallet['project_city'], $pallet['project_state'], $pallet['project_zip']]);
            $pallet['project_full_address'] = implode(', ', $project_address_parts);
        }
    }
    unset($pallet); // Unset reference after loop

    // Get all projects for the filter dropdown
    $all_projects_for_filter = [];
    if ($is_global_admin) {
        $stmtPFilter = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
        if ($stmtPFilter) {
            $stmtPFilter->execute();
            $resultPFilter = $stmtPFilter->get_result();
            while ($proj = $resultPFilter->fetch_assoc()) {
                $all_projects_for_filter[] = $proj;
            }
            $stmtPFilter->close();
        }
    } else if ($account_id_for_admin) {
        $stmtPFilter = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
        if ($stmtPFilter) {
            $stmtPFilter->bind_param("i", $account_id_for_admin);
            $stmtPFilter->execute();
            $resultPFilter = $stmtPFilter->get_result();
            while ($proj = $resultPFilter->fetch_assoc()) {
                $all_projects_for_filter[] = $proj;
            }
            $stmtPFilter->close();
        }
    }

    // Get project name for breadcrumbs if project_id provided
    $project_name_for_breadcrumb = '';
    if ($project_id_from_url > 0) {
        if ($is_global_admin) {
            // Global admin can see all projects
            $stmtProjectName = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
            if ($stmtProjectName) {
                $stmtProjectName->bind_param("i", $project_id_from_url);
                $stmtProjectName->execute();
                $stmtProjectName->bind_result($project_name_for_breadcrumb);
                $stmtProjectName->fetch();
                $stmtProjectName->close();
            }
        } else if ($account_id_for_admin) {
            // Regular admin can only see projects from their account
            $stmtProjectName = $conn->prepare("SELECT project_name FROM projects WHERE id = ? AND account_id = ?");
            if ($stmtProjectName) {
                $stmtProjectName->bind_param("ii", $project_id_from_url, $account_id_for_admin);
                $stmtProjectName->execute();
                $stmtProjectName->bind_result($project_name_for_breadcrumb);
                $stmtProjectName->fetch();
                $stmtProjectName->close();
            }
        }
    }

    // Fetch all projects for shipping (with addresses)
    $all_projects = [];
    if ($is_global_admin) {
        $stmtP = $conn->prepare("SELECT id, project_name, street_address, city, state, zip_code FROM projects ORDER BY project_name ASC");
        if ($stmtP) {
            $stmtP->execute();
            $resultP = $stmtP->get_result();
            while ($proj = $resultP->fetch_assoc()) {
                $address_parts = array_filter([$proj['street_address'], $proj['city'], $proj['state'], $proj['zip_code']]);
                $proj['full_address'] = implode(', ', $address_parts);
                $all_projects[] = $proj;
            }
            $stmtP->close();
        }
    } else if ($account_id_for_admin) {
        $stmtP = $conn->prepare("SELECT id, project_name, street_address, city, state, zip_code FROM projects WHERE account_id = ? ORDER BY project_name ASC");
        if ($stmtP) {
            $stmtP->bind_param("i", $account_id_for_admin);
            $stmtP->execute();
            $resultP = $stmtP->get_result();
            while ($proj = $resultP->fetch_assoc()) {
                $address_parts = array_filter([$proj['street_address'], $proj['city'], $proj['state'], $proj['zip_code']]);
                $proj['full_address'] = implode(', ', $address_parts);
                $all_projects[] = $proj;
            }
            $stmtP->close();
        }
    }

    // Fetch Warehouses (with addresses)
    $all_warehouses = [];
    $stmtW = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM warehouses ORDER BY name ASC");
    if ($stmtW) {
        $stmtW->execute();
        $resultW = $stmtW->get_result();
        while ($wh = $resultW->fetch_assoc()) {
            $address_parts = array_filter([$wh['street_address'], $wh['city'], $wh['state'], $wh['zip_code']]);
            $wh['full_address'] = implode(', ', $address_parts);
            $all_warehouses[] = $wh;
        }
        $stmtW->close();
    }

    // Fetch Manufacturers for origin selection (with addresses from primary locations)
    $all_manufacturers = [];
    $stmtM = $conn->prepare("
        SELECT 
            m.id, 
            m.name, 
            ml.street_address, 
            ml.city, 
            ml.state, 
            ml.zip_code 
        FROM manufacturers m
        LEFT JOIN manufacturer_locations ml ON m.id = ml.manufacturer_id AND ml.is_primary = TRUE
        WHERE m.is_active = 1 
        ORDER BY m.name ASC");
    if ($stmtM) {
        $stmtM->execute();
        $resultM = $stmtM->get_result();
        while ($mfg = $resultM->fetch_assoc()) {
            $address_parts = array_filter([$mfg['street_address'], $mfg['city'], $mfg['state'], $mfg['zip_code']]);
            $mfg['full_address'] = implode(', ', $address_parts);
            $all_manufacturers[] = $mfg;
        }
        $stmtM->close();
    }

} catch (Exception $e) {
    $errorMessage = "Error loading data: " . $e->getMessage();
}

$conn->close();

// Check for session messages
$sessionMessage = $_SESSION['create_shipment_message'] ?? '';
if (!empty($sessionMessage)) {
    unset($_SESSION['create_shipment_message']);
}

// Check for BOL completion message
$bolCompletionMessage = $_SESSION['bol_completion_message'] ?? '';
if (!empty($bolCompletionMessage)) {
    $sessionMessage = $bolCompletionMessage; // Override with BOL completion message
    unset($_SESSION['bol_completion_message']);
    // Clean up the origin project ID since we're back to the main flow
    unset($_SESSION['shipment_origin_project_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Shipment</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
            margin-top: 10px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
        .section-title {
            font-size: 1.3em;
            margin-bottom: 15px;
            color: #488C9A;
            border-bottom: 2px solid #488C9A;
            padding-bottom: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th {
            background-color: #e9ecef;
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .pallet-table-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        /* Modal Styles for Transfer Delivery */
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
            max-width: 600px;
            border-radius: 8px;
            position: relative;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .close-modal-btn {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-modal-btn:hover,
        .close-modal-btn:focus {
            color: black;
            text-decoration: none;
        }
        .shipment-details-modal-content h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #293E4C;
            font-size: 1.3em;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 18px;
        }
        .form-row > div {
            flex: 1;
            min-width: 150px;
        }
        .shipment-details-modal-content label {
            font-weight: 500;
            margin-bottom: 6px;
            display: block;
        }
        .shipment-details-modal-content input[type="text"],
        .shipment-details-modal-content input[type="date"],
        .shipment-details-modal-content input[type="number"],
        .shipment-details-modal-content select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-bottom: 8px;
            font-size: 1em;
            box-sizing: border-box;
        }
        .radio-label {
            display: inline-block;
            margin-right: 20px;
            font-weight: normal;
        }
        .radio-label input[type=radio] {
            margin-right: 5px;
            vertical-align: middle;
        }
        
        /* Origin-Destination Layout Styling */
        .origin-destination-section {
            margin: 20px 0;
        }
        
        .location-container {
            align-items: flex-start;
        }
        
        .origin-section, .destination-section {
            min-width: 0;
        }
        
        .distance-separator {
            min-width: 80px;
            text-align: center;
        }
        
        .destination-radio-group {
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .location-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .distance-separator {
                flex-direction: row;
                justify-content: center;
                margin-top: 0;
            }
            
            .distance-separator > div:first-child {
                transform: rotate(90deg);
                margin-right: 10px;
            }
        }

        .tabs {
            display: flex;
            justify-content: center;
            gap: 1px;
            margin-bottom: 20px;
        }
        .tabs button {
            flex: 1;
            min-width: 120px;
            max-width: 200px;
            background: #293E4C;
            color: #fff;
            padding: 10px;
            cursor: pointer;
            font-weight: 600;
            border: none;
            font-size: 1em;
            transition: background 0.2s, color 0.2s;
        }
        .tabs button.active {
            background: #f39c12;
            color: #000;
        }
        
        /* Portal-style action buttons */
        .action-button {
            background: #488C9A !important;
            color: #fff !important;
            border: none !important;
            padding: 12px 20px !important;
            cursor: pointer !important;
            border-radius: 4px !important;
            font-weight: 600 !important;
            font-size: 1em !important;
            text-decoration: none !important;
            display: inline-block !important;
            transition: background-color 0.3s ease !important;
            margin: 0 !important;
        }
        .action-button:hover {
            background: #293E4C !important;
            color: #fff !important;
        }
        .action-button:disabled {
            background: #cccccc !important;
            color: #666666 !important;
            cursor: not-allowed !important;
        }
        .action-button:disabled:hover {
            background: #cccccc !important;
            color: #666666 !important;
        }
        
        /* Success and error message styling */
        .success-message {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 15px;
            border-radius: 4px;
            text-align: center;
            margin-bottom: 20px;
        }
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 4px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        /* Pagination styles */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 15px 0;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        .pagination-info {
            font-size: 0.9em;
            color: #666;
        }
        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .pagination-controls label {
            font-size: 0.9em;
            margin-right: 5px;
        }
        .pagination-controls input,
        .pagination-controls select {
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }
        .pagination-controls button {
            padding: 5px 10px;
            background-color: #488C9A;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .pagination-controls button:hover {
            background-color: #3A6E7F;
        }
        .pagination-controls button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        
        /* Filter dropdown styles */
        .filter-dropdown {
            position: relative;
            display: inline-block;
        }
        .filter-toggle-btn {
            background-color: #488C9A;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-toggle-btn:hover {
            background-color: #3A6E7F;
        }
        .filter-arrow {
            transition: transform 0.3s ease;
        }
        .filter-toggle-btn.active .filter-arrow {
            transform: rotate(180deg);
        }
        .filter-content {
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 700px;
            z-index: 1000;
            background-color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .filters-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        /* BOL Fields Grid Styling */
        #bolFieldsGrid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            #bolFieldsGrid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
        }
        
        @media (max-width: 480px) {
            #bolFieldsGrid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <span class="separator">&raquo;</span>
        <?php if ($project_id_from_url > 0): ?>
            <a href="project_overview.php?project_id=<?php echo $project_id_from_url; ?>">Project Overview</a>
        <?php else: ?>
            <a href="project_overview.php">Project Overview</a>
        <?php endif; ?>
        <span class="separator">&raquo;</span>
        <span>Create Shipment</span>
    </div>

    <h1>Create Shipment</h1>

    <?php if (!empty($sessionMessage)): ?>
        <?php $messageClass = (strpos(strtolower($sessionMessage), 'error') !== false) ? 'error-message' : 'success-message'; ?>
        <div class="<?php echo $messageClass; ?>">
            <strong><?php 
                // Allow HTML in success messages (for scheduling links), but escape error messages
                if ($messageClass === 'success-message') {
                    echo $sessionMessage;
                } else {
                    echo htmlspecialchars($sessionMessage);
                }
            ?></strong>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php else: ?>
        <form method="POST" id="shipPalletsForm">
            <input type="hidden" name="action" value="ship_pallets">
            
            <div class="pallets-section">
                <h2 class="section-title">Select Inventory Pallets to Include in Shipment</h2>
                <!-- Filters and Controls -->
                <div class="filters-container" style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;">
                    <div class="filter-dropdown" style="width: 300px;">
                        <button type="button" class="filter-toggle-btn" onclick="toggleFilters()">
                            <span>Filters</span> <span class="filter-arrow">▼</span>
                        </button>
                        <div class="filter-content" id="filterContent" style="display: none;">
                            <div style="display: flex; flex-direction: column; gap: 15px; padding: 15px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 0 0 4px 4px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label>Search:</label>
                                    <input type="text" id="palletSearch" placeholder="Filter by ID, Identifier, Wattage..." onkeyup="filterPallets()" style="flex: 1;">
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label for="projectFilter">Project:</label>
                                    <select id="projectFilter" onchange="filterPallets()" style="flex: 1;">
                                        <option value="">All Projects</option>
                                        <option value="Unassigned">Unassigned</option>
                                        <?php foreach ($all_projects_for_filter as $proj): ?>
                                            <option value="<?php echo htmlspecialchars($proj['project_name']); ?>" <?php echo ($project_id_from_url > 0 && $proj['id'] == $project_id_from_url) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($proj['project_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label for="wattageFilter">Wattage:</label>
                                    <select id="wattageFilter" onchange="filterPallets()" style="flex: 1;">
                                        <option value="">All</option>
                                        <?php
                                        $wattages = array_unique(array_map(function($p) { return $p['wattage']; }, $pallets));
                                        sort($wattages);
                                        foreach ($wattages as $w) {
                                            echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label for="statusFilter">Status:</label>
                                    <select id="statusFilter" onchange="filterPallets()" style="flex: 1;">
                                        <option value="">All</option>
                                        <?php
                                        $statuses = array_unique(array_map(function($p) { return $p['status']; }, $pallets));
                                        sort($statuses);
                                        foreach ($statuses as $s) {
                                            echo '<option value="' . htmlspecialchars($s) . '">' . htmlspecialchars($s) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; justify-content: center; flex: 1;">
                        <span id="selectedCount" style="font-weight: bold; color: #488C9A;">0 pallets selected</span>
                    </div>
                    <div style="display: flex; align-items: center;">
                        <button type="button" id="openShipModalBtn" class="action-button" disabled>
                            Create Delivery for Selected Pallets
                        </button>
                    </div>
                </div>
                
                <div class="pagination-container">
                    <div class="pagination-info">
                        <span id="paginationInfo">Showing 0 of 0 pallets</span>
                    </div>
                    <div class="pagination-controls">
                        <label for="itemsPerPage">Show:</label>
                        <input type="number" id="itemsPerPage" value="100" min="1" max="500" style="width: 80px;">
                        <label>pallets per page</label>
                        <button type="button" id="prevPage" disabled>Previous</button>
                        <span id="pageInfo">Page 1 of 1</span>
                        <button type="button" id="nextPage" disabled>Next</button>
                    </div>
                </div>
                
                <?php if (!empty($pallets)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllPallets" onclick="toggleAllPalletCheckboxes(this.checked)"></th>
                                <th>Identifier</th>
                                <th>Wattage</th>
                                <th>Quantity</th>
                                <th>Status</th>
                                <th>Project</th>
                                <th>Deliveries</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pallets as $pallet): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_pallets[]" value="<?php echo $pallet['pallet_id']; ?>" class="pallet-checkbox"></td>
                                    <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                    <td><?php echo $pallet['wattage']; ?>W</td>
                                    <td><?php echo number_format($pallet['quantity']); ?></td>
                                    <td><?php echo htmlspecialchars($pallet['status']); ?></td>
                                    <td><?php echo htmlspecialchars($pallet['display_project_name']); ?></td>
                                    <td>
                                        <?php 
                                        $deliveryInfo = $pallet['delivery_info'] ?? '';
                                        if (empty($deliveryInfo)) {
                                            echo 'No deliveries';
                                        } else {
                                            $deliveries = explode('|', $deliveryInfo);
                                            if (count($deliveries) == 1) {
                                                $parts = explode(':', $deliveries[0]);
                                                $deliveryId = $parts[0];
                                                $bolNumber = $parts[1];
                                                echo '<a href="manage_deliveries.php?delivery_id=' . htmlspecialchars($deliveryId) . '" style="color: #488C9A; text-decoration: underline;">' . htmlspecialchars($bolNumber) . '</a>';
                                            } else {
                                                echo '<div class="delivery-dropdown">';
                                                echo '<button type="button" class="delivery-toggle" onclick="toggleDeliveryDropdown(this)" style="background: #488C9A; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer;">Multiple (' . count($deliveries) . ')</button>';
                                                echo '<div class="delivery-list" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 3px; z-index: 1000; min-width: 150px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">';
                                                foreach ($deliveries as $delivery) {
                                                    $parts = explode(':', $delivery);
                                                    $deliveryId = $parts[0];
                                                    $bolNumber = $parts[1];
                                                    echo '<a href="manage_deliveries.php?delivery_id=' . htmlspecialchars($deliveryId) . '" style="display: block; padding: 8px 12px; color: #488C9A; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor=\'#f5f5f5\'" onmouseout="this.style.backgroundColor=\'white\'">' . htmlspecialchars($bolNumber) . '</a>';
                                                }
                                                echo '</div>';
                                                echo '</div>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <a href="pallet_details.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" class="action-button" style="background-color: #488C9A; color: white; padding: 4px 8px; text-decoration: none; border-radius: 3px; font-size: 0.9em;">View Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No pallets found.</p>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</main>

<!-- Modal for Shipment Details -->
<div id="shipModal" class="modal">
    <div class="modal-content">
        <span class="close-modal-btn">&times;</span>
        <div class="shipment-details-modal-content">
            <h2 class="section-title" style="margin-top:0; text-align:center;">Create Delivery</h2>
            <div class="tabs">
                <button type="button" class="modal-tab active" id="singleTabBtn">Single Shipment</button>
                <button type="button" class="modal-tab" id="multiTabBtn">Multiple Shipments</button>
            </div>
            <!-- SINGLE SHIPMENT SECTION -->
            <div id="singleShipmentSection">
                <form id="singleShipmentForm" onsubmit="return false;">
                    <label for="bol_number">BOL Number:</label>
                    <input type="text" id="bol_number" name="bol_number" required>
                    <div class="form-row">
                        <div>
                            <label for="departure_date">Departure Date:</label>
                            <input type="date" id="departure_date" name="departure_date" required>
                        </div>
                        <div>
                            <label for="est_arrival_date">Est. Arrival Date:</label>
                            <input type="date" id="est_arrival_date" name="est_arrival_date" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label for="freight_cost">Freight Cost ($):</label>
                            <input type="number" id="freight_cost" name="freight_cost" step="0.01" min="0">
                        </div>
                        <div>
                            <label for="customer_cost">Customer Cost ($):</label>
                            <input type="number" id="customer_cost" name="customer_cost" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <!-- Origin and Destination Section -->
                    <div class="origin-destination-section">
                        <div class="location-container" style="display: flex; align-items: flex-start; gap: 20px;">
                            <div class="origin-section" style="flex: 1;">
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Origin:</label>
                                <div id="originDisplay" style="padding: 12px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; min-height: 45px; display: flex; align-items: center;">
                                    <strong id="originLocationText">Select pallets to see origin</strong>
                                </div>
                                <input type="hidden" id="origin_type" name="origin_type" value="">
                                <input type="hidden" id="origin_id" name="origin_id" value="">
                            </div>
                            
                            <div class="distance-separator" style="display: flex; flex-direction: column; justify-content: center; align-items: center; margin-top: 35px;">
                                <div style="font-size: 1.8em; color: #488C9A; margin-bottom: 5px;">→</div>
                                <div id="distanceDisplay" style="text-align: center; font-weight: bold; color: #488C9A; white-space: nowrap; font-size: 0.85em;">
                                    <!-- Distance will be calculated and displayed here -->
                                </div>
                            </div>
                            
                            <div class="destination-section" style="flex: 1;">
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Destination:</label>
                                <div class="destination-radio-group" style="display: flex; gap: 15px; margin-bottom: 10px;">
                                    <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                        <input type="radio" name="destination_type" value="project" checked onchange="toggleDestinationSelectSingle()" style="margin-right: 5px;"> Project
                                    </label>
                                    <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                        <input type="radio" name="destination_type" value="warehouse" onchange="toggleDestinationSelectSingle()" style="margin-right: 5px;"> Warehouse
                                    </label>
                                </div>
                                <div id="destinationSelectContainer">
                                    <select name="destination_id" id="destination_id" required onchange="calculateDistance()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                        <!-- Filled by JS -->
                                    </select>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="miles" name="miles" value="">
                    </div>
                    
                    <!-- Generate BOL Checkbox -->
                    <div style="margin-top: 15px; margin-bottom: 20px; padding: 10px; background-color: #f8f9fa; border-radius: 4px; border: 1px solid #e9ecef;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                            <input type="checkbox" id="generate_bol_single" name="generate_bol" value="1" style="margin: 0;">
                            <span>Generate Bill of Lading (BOL) after creating delivery</span>
                        </label>
                        <small style="color: #6c757d; margin-left: 20px; display: block; margin-top: 3px;">
                            Check this to immediately create a BOL document for this shipment
                        </small>
                    </div>
                    
                    <button type="button" id="confirmShipmentBtn" class="action-button" style="margin-top:15px;">
                        Create Delivery
                    </button>
                </form>
            </div>
            <!-- MULTIPLE SHIPMENTS SECTION -->
            <div id="multiShipmentSection" style="display:none;">
                <form id="multiShipmentForm" onsubmit="return false;">
                    <label for="palletsPerTruck">Pallets per Truck:</label>
                    <input type="number" id="palletsPerTruck" min="1" max="12" value="1" style="width:100px;" data-user-edited="false">
                    <div id="multiShipSummary" style="margin-top:10px; color:#488C9A;"></div>
                    
                    <!-- Dynamic BOL Number Fields -->
                    <div id="bolFieldsContainer" style="margin-top:15px;">
                        <label style="font-weight: 600; margin-bottom: 10px; display: block;">BOL Numbers:</label>
                        <div id="bolFieldsGrid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                            <!-- BOL fields will be dynamically created here -->
                        </div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label for="departure_date_multi">Departure Date:</label>
                            <input type="date" id="departure_date_multi" name="departure_date_multi" required>
                        </div>
                        <div>
                            <label for="est_arrival_date_multi">Est. Arrival Date:</label>
                            <input type="date" id="est_arrival_date_multi" name="est_arrival_date_multi" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label for="freight_cost_multi">Freight Cost ($):</label>
                            <input type="number" id="freight_cost_multi" name="freight_cost_multi" step="0.01" min="0">
                        </div>
                        <div>
                            <label for="customer_cost_multi">Customer Cost ($):</label>
                            <input type="number" id="customer_cost_multi" name="customer_cost_multi" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <!-- Origin and Destination Section -->
                    <div class="origin-destination-section">
                        <div class="location-container" style="display: flex; align-items: flex-start; gap: 20px;">
                            <div class="origin-section" style="flex: 1;">
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Origin:</label>
                                <div id="originDisplayMulti" style="padding: 12px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; min-height: 45px; display: flex; align-items: center;">
                                    <strong id="originLocationTextMulti">Select pallets to see origin</strong>
                                </div>
                                <input type="hidden" id="origin_type_multi" name="origin_type_multi" value="">
                                <input type="hidden" id="origin_id_multi" name="origin_id_multi" value="">
                            </div>
                            
                            <div class="distance-separator" style="display: flex; flex-direction: column; justify-content: center; align-items: center; margin-top: 35px;">
                                <div style="font-size: 1.8em; color: #488C9A; margin-bottom: 5px;">→</div>
                                <div id="distanceDisplayMulti" style="text-align: center; font-weight: bold; color: #488C9A; white-space: nowrap; font-size: 0.85em;">
                                    <!-- Distance will be calculated and displayed here -->
                                </div>
                            </div>
                            
                            <div class="destination-section" style="flex: 1;">
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Destination:</label>
                                <div class="destination-radio-group" style="display: flex; gap: 15px; margin-bottom: 10px;">
                                    <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                        <input type="radio" name="destination_type_multi" value="project" checked onchange="toggleDestinationSelectMulti()" style="margin-right: 5px;"> Project
                                    </label>
                                    <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                        <input type="radio" name="destination_type_multi" value="warehouse" onchange="toggleDestinationSelectMulti()" style="margin-right: 5px;"> Warehouse
                                    </label>
                                </div>
                                <div id="destinationSelectContainerMulti">
                                    <select name="destination_id_multi" id="destination_id_multi" required onchange="calculateDistanceMulti()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                        <!-- Filled by JS -->
                                    </select>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="miles_multi" name="miles_multi" value="">
                    </div>
                    
                    <!-- Generate BOL Checkbox -->
                    <div style="margin-top: 15px; padding: 10px; background-color: #f8f9fa; border-radius: 4px; border: 1px solid #e9ecef;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                            <input type="checkbox" id="generate_bol_multi" name="generate_bol" value="1" style="margin: 0;">
                            <span>Generate Bill of Lading (BOL) after creating deliveries</span>
                        </label>
                        <small style="color: #6c757d; margin-left: 20px; display: block; margin-top: 3px;">
                            Check this to immediately create BOL documents for these shipments
                        </small>
                    </div>
                    
                    <button type="button" id="confirmMultiShipmentBtn" class="action-button" style="margin-top:15px;">
                        Create Deliveries
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Embed PHP data as JS variables for populating dropdowns -->
<script>
    const projectsData = <?php echo json_encode($all_projects); ?>;
    const warehousesData = <?php echo json_encode($all_warehouses); ?>;
    const manufacturersData = <?php echo json_encode($all_manufacturers); ?>;
    const palletsData = <?php echo json_encode($pallets); ?>;
</script>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places"></script>

<script>
// ----------------- PALLET TABLE CHECKBOXES -----------------
function toggleAllPalletCheckboxes(isChecked) {
    document.querySelectorAll('.pallets-section table tbody tr').forEach(function(row) {
        if (row.style.display !== 'none') {
            const checkbox = row.querySelector('.pallet-checkbox');
            if (checkbox) checkbox.checked = isChecked;
        }
    });
    updateOpenShipModalButtonState();
    updateSelectedCount();
    updateOriginDisplay();
    updateMultiShipSummary();
}

function updateOpenShipModalButtonState() {
    const openBtn = document.getElementById('openShipModalBtn');
    const checked = document.querySelectorAll('.pallet-checkbox:checked').length;
    if (openBtn) {
        openBtn.disabled = (checked === 0);
    }
}

function updateSelectedCount() {
    let count = document.querySelectorAll('.pallet-checkbox:checked').length;
    const countEl = document.getElementById('selectedCount');
    if (countEl) {
        countEl.textContent = count + ' pallet' + (count === 1 ? '' : 's') + ' selected';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAllPallets');
    const palletCheckboxes = document.querySelectorAll('.pallet-checkbox');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            toggleAllPalletCheckboxes(this.checked);
        });
    }
    palletCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            updateOpenShipModalButtonState();
            updateSelectedCount();
            updateOriginDisplay();
            updateMultiShipSummary();
        });
    });
    // Initial state
    updateOpenShipModalButtonState();
    updateSelectedCount();
    
    // Initialize pagination
    initializePagination();
    
    // Load persisted filters
    loadPersistedFilters();
    
    // Add event listeners to save filters on change
    document.getElementById('palletSearch')?.addEventListener('input', saveFilters);
    document.getElementById('projectFilter')?.addEventListener('change', saveFilters);
    document.getElementById('wattageFilter')?.addEventListener('change', saveFilters);
    document.getElementById('statusFilter')?.addEventListener('change', saveFilters);
    document.getElementById('itemsPerPage')?.addEventListener('change', saveFilters);
});

// ----------------- PAGINATION -----------------
let currentPage = 1;
let itemsPerPage = 100;
let allPalletRows = [];

function initializePagination() {
    const table = document.querySelector('.pallets-section table tbody');
    if (!table) return;
    
    allPalletRows = Array.from(table.querySelectorAll('tr'));
    
    const itemsPerPageInput = document.getElementById('itemsPerPage');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');
    
    if (itemsPerPageInput) {
        itemsPerPageInput.addEventListener('change', function() {
            itemsPerPage = Math.min(Math.max(1, parseInt(this.value) || 100), 500);
            this.value = itemsPerPage;
            currentPage = 1;
            updatePagination();
        });
    }
    
    if (prevButton) {
        prevButton.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updatePagination();
            }
        });
    }
    
    if (nextButton) {
        nextButton.addEventListener('click', function() {
            const maxPages = Math.ceil(getFilteredRows().length / itemsPerPage);
            if (currentPage < maxPages) {
                currentPage++;
                updatePagination();
            }
        });
    }
    
    updatePagination();
}

function getFilteredRows() {
    return allPalletRows.filter(row => {
        if (!row || !row.cells) return false;
        
        const filter = document.getElementById('palletSearch')?.value.toLowerCase() || '';
        const projectFilter = document.getElementById('projectFilter')?.value || '';
        const wattageFilter = document.getElementById('wattageFilter')?.value || '';
        const statusFilter = document.getElementById('statusFilter')?.value || '';
        
        // Get cell contents
        let textContent = '';
        for (let i = 1; i < row.cells.length; i++) { // Skip checkbox column
            textContent += (row.cells[i].textContent || row.cells[i].innerText || '').toLowerCase() + ' ';
        }
        
        // Check search filter
        if (filter && !textContent.includes(filter)) {
            return false;
        }
        
        // Check project filter (Project is in column 5, index 5)
        if (projectFilter && row.cells[5]) {
            const cellProject = (row.cells[5].textContent || row.cells[5].innerText || '').trim();
            if (projectFilter === "Unassigned") {
                if (cellProject !== "Unassigned") {
                    return false;
                }
            } else {
                if (cellProject !== projectFilter) {
                    return false;
                }
            }
        }
        
        // Check wattage filter (Wattage is in column 2, index 2)
        if (wattageFilter && row.cells[2]) {
            const cellWattage = (row.cells[2].textContent || row.cells[2].innerText || '').replace('W','').trim();
            if (cellWattage !== wattageFilter) {
                return false;
            }
        }
        
        // Check status filter (Status is in column 4, index 4)
        if (statusFilter && row.cells[4]) {
            const cellStatus = (row.cells[4].textContent || row.cells[4].innerText || '').trim();
            if (cellStatus !== statusFilter) {
                return false;
            }
        }
        
        return true;
    });
}

function updatePagination() {
    const filteredRows = getFilteredRows();
    const totalItems = filteredRows.length;
    const maxPages = Math.ceil(totalItems / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    
    // Hide all rows first
    allPalletRows.forEach(row => {
        row.style.display = 'none';
    });
    
    // Show only current page rows from filtered set
    filteredRows.slice(startIndex, endIndex).forEach(row => {
        row.style.display = '';
    });
    
    // Update pagination info
    const paginationInfo = document.getElementById('paginationInfo');
    const pageInfo = document.getElementById('pageInfo');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');
    
    if (paginationInfo) {
        const showing = Math.min(endIndex, totalItems);
        const displayStart = totalItems > 0 ? startIndex + 1 : 0;
        paginationInfo.textContent = `Showing ${displayStart}-${showing} of ${totalItems} pallets`;
    }
    
    if (pageInfo) {
        pageInfo.textContent = `Page ${Math.max(1, currentPage)} of ${Math.max(1, maxPages)}`;
    }
    
    if (prevButton) {
        prevButton.disabled = currentPage <= 1;
    }
    
    if (nextButton) {
        nextButton.disabled = currentPage >= maxPages || totalItems === 0;
    }
    
    // Update selection counts after pagination
    updateSelectedCount();
}

// ----------------- FILTER DROPDOWN FUNCTIONALITY -----------------
function toggleFilters() {
    const filterContent = document.getElementById('filterContent');
    const toggleBtn = document.querySelector('.filter-toggle-btn');
    
    if (filterContent.style.display === 'none' || filterContent.style.display === '') {
        filterContent.style.display = 'block';
        toggleBtn.classList.add('active');
    } else {
        filterContent.style.display = 'none';
        toggleBtn.classList.remove('active');
    }
}

// ----------------- PALLET FILTER -----------------
function filterPallets() {
    // Reset to page 1 when filter changes
    currentPage = 1;
    updatePagination();
}

// ----------------- MODAL LOGIC -----------------
const shipModal = document.getElementById('shipModal');
const openShipModalBtn = document.getElementById('openShipModalBtn');
const closeShipModalBtn = shipModal.querySelector('.close-modal-btn');

function openShipModal() {
    shipModal.style.display = 'block';
    // Initialize multi-shipment BOL fields when modal opens
    updateMultiShipSummary();
}
function closeShipModal() {
    shipModal.style.display = 'none';
}

openShipModalBtn.addEventListener('click', openShipModal);
if (closeShipModalBtn) {
    closeShipModalBtn.addEventListener('click', closeShipModal);
}
window.addEventListener('click', function(e) {
    if (e.target === shipModal) {
        closeShipModal();
    }
});

// ----------------- TAB SWITCHING (SINGLE vs MULTI) -----------------
const singleTabBtn = document.getElementById('singleTabBtn');
const multiTabBtn = document.getElementById('multiTabBtn');
const singleSection = document.getElementById('singleShipmentSection');
const multiSection = document.getElementById('multiShipmentSection');

if (singleTabBtn && multiTabBtn && singleSection && multiSection) {
    singleTabBtn.addEventListener('click', () => {
        singleTabBtn.classList.add('active');
        multiTabBtn.classList.remove('active');
        singleSection.style.display = '';
        multiSection.style.display = 'none';
        singleTabBtn.style.background = '#f39c12';
        singleTabBtn.style.color = '#000';
        multiTabBtn.style.background = '#293E4C';
        multiTabBtn.style.color = '#fff';
    });
    multiTabBtn.addEventListener('click', () => {
        singleTabBtn.classList.remove('active');
        multiTabBtn.classList.add('active');
        singleSection.style.display = 'none';
        multiSection.style.display = '';
        multiTabBtn.style.background = '#f39c12';
        multiTabBtn.style.color = '#000';
        singleTabBtn.style.background = '#293E4C';
        singleTabBtn.style.color = '#fff';
        // Refresh BOL fields when switching to multi tab
        updateMultiShipSummary();
    });
    // Init default tab look
    singleTabBtn.style.background = '#f39c12';
    singleTabBtn.style.color = '#000';
}

// ----------------- DISTANCE CALCULATION WITH GOOGLE MAPS -----------------
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

function populateDropdown(selectElement, type, dataSource, nameField, placeholderPrefix) {
    if (!selectElement) return;
    
    selectElement.innerHTML = '';

    if (!dataSource || dataSource.length === 0) {
        selectElement.innerHTML = `<option value="">No ${placeholderPrefix.toLowerCase()} found</option>`;
        selectElement.disabled = true;
    } else {
        selectElement.disabled = false;
        selectElement.innerHTML = `<option value="">-- Select ${placeholderPrefix} --</option>`;
        dataSource.forEach(function(item) {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item[nameField];
            opt.setAttribute('data-address', item.full_address || '');
            selectElement.appendChild(opt);
        });
    }
}

function getAddressFromSelection(selectElement, type) {
    if (!selectElement || !selectElement.value) return '';
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    return selectedOption ? selectedOption.getAttribute('data-address') : '';
}

// ----------------- STATE ABBREVIATION FUNCTION -----------------
function getStateAbbreviation(stateName) {
    const stateMap = {
        'Alabama': 'AL', 'Alaska': 'AK', 'Arizona': 'AZ', 'Arkansas': 'AR', 'California': 'CA',
        'Colorado': 'CO', 'Connecticut': 'CT', 'Delaware': 'DE', 'Florida': 'FL', 'Georgia': 'GA',
        'Hawaii': 'HI', 'Idaho': 'ID', 'Illinois': 'IL', 'Indiana': 'IN', 'Iowa': 'IA',
        'Kansas': 'KS', 'Kentucky': 'KY', 'Louisiana': 'LA', 'Maine': 'ME', 'Maryland': 'MD',
        'Massachusetts': 'MA', 'Michigan': 'MI', 'Minnesota': 'MN', 'Mississippi': 'MS', 'Missouri': 'MO',
        'Montana': 'MT', 'Nebraska': 'NE', 'Nevada': 'NV', 'New Hampshire': 'NH', 'New Jersey': 'NJ',
        'New Mexico': 'NM', 'New York': 'NY', 'North Carolina': 'NC', 'North Dakota': 'ND', 'Ohio': 'OH',
        'Oklahoma': 'OK', 'Oregon': 'OR', 'Pennsylvania': 'PA', 'Rhode Island': 'RI', 'South Carolina': 'SC',
        'South Dakota': 'SD', 'Tennessee': 'TN', 'Texas': 'TX', 'Utah': 'UT', 'Vermont': 'VT',
        'Virginia': 'VA', 'Washington': 'WA', 'West Virginia': 'WV', 'Wisconsin': 'WI', 'Wyoming': 'WY'
    };
    
    // Return abbreviation if found, otherwise return original (in case it's already abbreviated)
    return stateMap[stateName] || stateName;
}

// ----------------- ORIGIN DETERMINATION FUNCTIONS -----------------
function determineOriginFromSelectedPallets() {
    const selectedPallets = getSelectedPallets();
    
    if (selectedPallets.length === 0) {
        return {
            success: false,
            message: 'No pallets selected'
        };
    }

    // Group pallets by their origin
    const origins = {};
    
    selectedPallets.forEach(pallet => {
        let originKey;
        if (pallet.status === 'At Manufacturer') {
            originKey = `manufacturer_${pallet.origin_vendor}`;
        } else if (pallet.status === 'In Warehouse' && pallet.current_warehouse_id) {
            originKey = `warehouse_${pallet.current_warehouse_id}`;
        } else if (pallet.status === 'Delivered to Project' && pallet.current_project_id) {
            originKey = `project_${pallet.current_project_id}`;
        } else {
            originKey = 'unknown';
        }
        
        if (!origins[originKey]) {
            origins[originKey] = [];
        }
        origins[originKey].push(pallet);
    });

    const originKeys = Object.keys(origins);
    
    if (originKeys.length > 1) {
        return {
            success: false,
            message: 'Selected pallets must all have the same origin location'
        };
    }
    
    if (originKeys.length === 0 || originKeys[0] === 'unknown') {
        return {
            success: false,
            message: 'Cannot determine origin for selected pallets'
        };
    }

    const originKey = originKeys[0];
    const firstPallet = origins[originKey][0];
    
    // Determine origin details
    let originInfo = {};
    
    if (firstPallet.status === 'At Manufacturer') {
        // Use the specific manufacturer name and location from the database
        let manufacturerName = firstPallet.origin_vendor_name || firstPallet.origin_vendor;
        if (manufacturerName.includes(' - ')) {
            manufacturerName = manufacturerName.split(' - ')[0].trim();
        }
        
        // Use the specific location address from the manufacturer_locations table
        let manufacturerAddress = firstPallet.origin_vendor_address || '';
        
        // Create display text with city and state if available
        let locationDisplay = manufacturerName;
        if (firstPallet.origin_vendor_city && firstPallet.origin_vendor_state) {
            // Convert state to abbreviation if it's a full state name
            const stateAbbr = getStateAbbreviation(firstPallet.origin_vendor_state);
            locationDisplay += ` - ${firstPallet.origin_vendor_city}, ${stateAbbr}`;
        }
        
        // Find manufacturer by extracted name for ID
        const manufacturer = manufacturersData.find(m => m.name === manufacturerName);
        if (manufacturer) {
            // Use the specific location address if available, otherwise fall back to primary location
            if (!manufacturerAddress) {
                manufacturerAddress = manufacturer.full_address;
            }
            
            originInfo = {
                type: 'manufacturer',
                id: manufacturer.id,
                name: manufacturer.name,
                address: manufacturerAddress,
                displayText: `Manufacturer: ${locationDisplay}`
            };
        } else {
            return {
                success: false,
                message: `Manufacturer "${manufacturerName}" not found in system`
            };
        }
    } else if (firstPallet.status === 'In Warehouse') {
        originInfo = {
            type: 'warehouse',
            id: firstPallet.current_warehouse_id,
            name: firstPallet.current_warehouse_name,
            address: firstPallet.warehouse_full_address,
            displayText: `Warehouse: ${firstPallet.current_warehouse_name}`
        };
    } else if (firstPallet.status === 'Delivered to Project') {
        originInfo = {
            type: 'project',
            id: firstPallet.current_project_id,
            name: firstPallet.current_project_name,
            address: firstPallet.project_full_address,
            displayText: `Project: ${firstPallet.current_project_name}`
        };
    }

    return {
        success: true,
        origin: originInfo
    };
}

function getSelectedPallets() {
    const selectedCheckboxes = document.querySelectorAll('.pallet-checkbox:checked');
    const selectedPallets = [];
    
    selectedCheckboxes.forEach(checkbox => {
        const palletId = parseInt(checkbox.value);
        const pallet = palletsData.find(p => p.pallet_id === palletId);
        if (pallet) {
            selectedPallets.push(pallet);
        }
    });
    
    return selectedPallets;
}

function updateOriginDisplay() {
    const result = determineOriginFromSelectedPallets();
    
    // Update single shipment display
    const originText = document.getElementById('originLocationText');
    const originType = document.getElementById('origin_type');
    const originId = document.getElementById('origin_id');
    
    // Update multi shipment display
    const originTextMulti = document.getElementById('originLocationTextMulti');
    const originTypeMulti = document.getElementById('origin_type_multi');
    const originIdMulti = document.getElementById('origin_id_multi');
    
    if (result.success) {
        const displayText = result.origin.displayText;
        
        if (originText) originText.textContent = displayText;
        if (originTextMulti) originTextMulti.textContent = displayText;
        
        if (originType) originType.value = result.origin.type;
        if (originId) originId.value = result.origin.id;
        if (originTypeMulti) originTypeMulti.value = result.origin.type;
        if (originIdMulti) originIdMulti.value = result.origin.id;
        
        // Calculate distance
        calculateDistance();
        calculateDistanceMulti();
    } else {
        const errorText = result.message;
        
        if (originText) originText.textContent = errorText;
        if (originTextMulti) originTextMulti.textContent = errorText;
        
        if (originType) originType.value = '';
        if (originId) originId.value = '';
        if (originTypeMulti) originTypeMulti.value = '';
        if (originIdMulti) originIdMulti.value = '';
        
        // Clear distance display
        const distanceDisplay = document.getElementById('distanceDisplay');
        const distanceDisplayMulti = document.getElementById('distanceDisplayMulti');
        if (distanceDisplay) distanceDisplay.innerHTML = '';
        if (distanceDisplayMulti) distanceDisplayMulti.innerHTML = '';
    }
}

// ----------------- DESTINATION SELECTION FUNCTIONS -----------------
function toggleDestinationSelectSingle() {
    const destType = document.querySelector('input[name="destination_type"]:checked').value;
    const destSelect = document.getElementById('destination_id');
    
    const data = (destType === 'project') ? projectsData : warehousesData;
    const nameField = (destType === 'project') ? 'project_name' : 'name';
    const placeholder = (destType === 'project') ? 'Project' : 'Warehouse';

    populateDropdown(destSelect, destType, data, nameField, placeholder);
    calculateDistance();
}

function toggleDestinationSelectMulti() {
    const destType = document.querySelector('input[name="destination_type_multi"]:checked').value;
    const destSelect = document.getElementById('destination_id_multi');
    
    const data = (destType === 'project') ? projectsData : warehousesData;
    const nameField = (destType === 'project') ? 'project_name' : 'name';
    const placeholder = (destType === 'project') ? 'Project' : 'Warehouse';

    populateDropdown(destSelect, destType, data, nameField, placeholder);
    calculateDistanceMulti();
}

// ----------------- DISTANCE CALCULATION FUNCTIONS -----------------
function calculateDistance() {
    const destSelect = document.getElementById('destination_id');
    const distanceDisplay = document.getElementById('distanceDisplay');
    const milesInput = document.getElementById('miles');

    if (!destSelect || !distanceDisplay) return;

    const result = determineOriginFromSelectedPallets();
    if (!result.success) {
        distanceDisplay.innerHTML = '';
        milesInput.value = '';
        return;
    }

    const originAddress = result.origin.address;
    const destAddress = getAddressFromSelection(destSelect, 'destination');

    if (!originAddress || !destAddress) {
        distanceDisplay.innerHTML = '';
        milesInput.value = '';
        return;
    }

    distanceDisplay.innerHTML = '<span style="color: #666;">Calculating distance...</span>';

    calculateDistanceFromAddresses(originAddress, destAddress, function(distance, error) {
        if (error) {
            if (error.includes('cannot be the same')) {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">⚠️ Same location</span>';
            } else {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">Error</span>';
            }
            milesInput.value = '';
        } else {
            distanceDisplay.innerHTML = `${distance} miles`;
            milesInput.value = distance;
        }
    });
}

function calculateDistanceMulti() {
    const destSelect = document.getElementById('destination_id_multi');
    const distanceDisplay = document.getElementById('distanceDisplayMulti');
    const milesInput = document.getElementById('miles_multi');

    if (!destSelect || !distanceDisplay) return;

    const result = determineOriginFromSelectedPallets();
    if (!result.success) {
        distanceDisplay.innerHTML = '';
        milesInput.value = '';
        return;
    }

    const originAddress = result.origin.address;
    const destAddress = getAddressFromSelection(destSelect, 'destination');

    if (!originAddress || !destAddress) {
        distanceDisplay.innerHTML = '';
        milesInput.value = '';
        return;
    }

    distanceDisplay.innerHTML = '<span style="color: #666;">Calculating distance...</span>';

    calculateDistanceFromAddresses(originAddress, destAddress, function(distance, error) {
        if (error) {
            if (error.includes('cannot be the same')) {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">⚠️ Same location</span>';
            } else {
                distanceDisplay.innerHTML = '<span style="color: #d32f2f;">Error</span>';
            }
            milesInput.value = '';
        } else {
            distanceDisplay.innerHTML = `${distance} miles`;
            milesInput.value = distance;
        }
    });
}

// ----------------- CONFIRM BUTTONS (SINGLE & MULTI) -----------------
const confirmShipmentBtn = document.getElementById('confirmShipmentBtn');
const confirmMultiShipmentBtn = document.getElementById('confirmMultiShipmentBtn');

if (confirmShipmentBtn) {
    confirmShipmentBtn.addEventListener('click', function() {
        const mainForm = document.getElementById('shipPalletsForm');
        if (!mainForm) return;

        // Single shipment mode
        let shipmentModeInput = mainForm.querySelector('input[name="shipment_mode"]');
        if (!shipmentModeInput) {
            shipmentModeInput = document.createElement('input');
            shipmentModeInput.type = 'hidden';
            shipmentModeInput.name = 'shipment_mode';
            mainForm.appendChild(shipmentModeInput);
        }
        shipmentModeInput.value = 'single';

        // Pallets per truck (not used in single, so set blank or 1)
        let pTruckInput = mainForm.querySelector('input[name="pallets_per_truck"]');
        if (!pTruckInput) {
            pTruckInput = document.createElement('input');
            pTruckInput.type = 'hidden';
            pTruckInput.name = 'pallets_per_truck';
            mainForm.appendChild(pTruckInput);
        }
        pTruckInput.value = '1';

        // Validate origin can be determined
        const originResult = determineOriginFromSelectedPallets();
        if (!originResult.success) {
            alert('Error: ' + originResult.message);
            return;
        }

        // Get single form values
        const originType = document.getElementById('origin_type').value;
        const originId = document.getElementById('origin_id').value;
        const destinationType = document.querySelector('input[name="destination_type"]:checked').value;
        const destinationId = document.getElementById('destination_id').value;
        const bol = document.getElementById('bol_number').value;
        const departure = document.getElementById('departure_date').value;
        const arrival = document.getElementById('est_arrival_date').value;
        const freightCost = document.getElementById('freight_cost').value;
        const customerCost = document.getElementById('customer_cost').value;
        const miles = document.getElementById('miles').value;

        if (!originId) {
            alert('Please select pallets to determine origin location.');
            return;
        }
        if (!destinationId) {
            alert('Please select a destination location.');
            return;
        }
        if (!bol || bol.trim() === '') {
            alert('BOL Number is required.');
            return;
        }

        // Check if origin and destination are the same
        if (originType === destinationType && originId === destinationId) {
            alert('Origin and destination cannot be the same location.');
            return;
        }

        // Check if Generate BOL checkbox is checked
        const generateBol = document.getElementById('generate_bol_single').checked;

        // Populate hidden inputs
        setOrCreateHidden(mainForm, 'origin_type', originType);
        setOrCreateHidden(mainForm, 'origin_id', originId);
        setOrCreateHidden(mainForm, 'destination_type', destinationType);
        setOrCreateHidden(mainForm, 'destination_id', destinationId);
        setOrCreateHidden(mainForm, 'bol_number', bol);
        setOrCreateHidden(mainForm, 'departure_date', departure);
        setOrCreateHidden(mainForm, 'est_arrival_date', arrival);
        setOrCreateHidden(mainForm, 'freight_cost', freightCost);
        setOrCreateHidden(mainForm, 'accessorial_cost', '0'); // Default to 0 since field is removed
        setOrCreateHidden(mainForm, 'customer_cost', customerCost);
        setOrCreateHidden(mainForm, 'miles', miles);
        setOrCreateHidden(mainForm, 'generate_bol', generateBol ? '1' : '0');

        mainForm.submit();
    });
}

if (confirmMultiShipmentBtn) {
    confirmMultiShipmentBtn.addEventListener('click', function() {
        const mainForm = document.getElementById('shipPalletsForm');
        if (!mainForm) return;

        // Multi shipment mode
        let shipmentModeInput = mainForm.querySelector('input[name="shipment_mode"]');
        if (!shipmentModeInput) {
            shipmentModeInput = document.createElement('input');
            shipmentModeInput.type = 'hidden';
            shipmentModeInput.name = 'shipment_mode';
            mainForm.appendChild(shipmentModeInput);
        }
        shipmentModeInput.value = 'multi';

        // Pallets per truck
        let perTruckInput = mainForm.querySelector('input[name="pallets_per_truck"]');
        if (!perTruckInput) {
            perTruckInput = document.createElement('input');
            perTruckInput.type = 'hidden';
            perTruckInput.name = 'pallets_per_truck';
            mainForm.appendChild(perTruckInput);
        }
        const palletsPerTruckValue = parseInt(document.getElementById('palletsPerTruck').value, 10);
        perTruckInput.value = (palletsPerTruckValue && palletsPerTruckValue > 0) ? palletsPerTruckValue : '1';

        // Validate origin can be determined
        const originResult = determineOriginFromSelectedPallets();
        if (!originResult.success) {
            alert('Error: ' + originResult.message);
            return;
        }

        // Get multi form values
        const originType = document.getElementById('origin_type_multi').value;
        const originId = document.getElementById('origin_id_multi').value;
        const destinationType = document.querySelector('input[name="destination_type_multi"]:checked').value;
        const destinationId = document.getElementById('destination_id_multi').value;
        const departure = document.getElementById('departure_date_multi').value;
        const arrival = document.getElementById('est_arrival_date_multi').value;
        const freightCost = document.getElementById('freight_cost_multi').value;
        const customerCost = document.getElementById('customer_cost_multi').value;
        const miles = document.getElementById('miles_multi').value;

        if (!originId) {
            alert('Please select pallets to determine origin location.');
            return;
        }
        if (!destinationId) {
            alert('Please select a destination location.');
            return;
        }

        // Validate all BOL fields
        const bolFields = document.querySelectorAll('#bolFieldsGrid input[name^="bol_number_"]');
        const bolNumbers = [];
        let missingBol = false;
        
        bolFields.forEach((field, index) => {
            const bolValue = field.value.trim();
            if (!bolValue) {
                missingBol = true;
                return;
            }
            bolNumbers.push(bolValue);
        });
        
        if (missingBol) {
            alert('All BOL Numbers are required.');
            return;
        }

        // Check if origin and destination are the same
        if (originType === destinationType && originId === destinationId) {
            alert('Origin and destination cannot be the same location.');
            return;
        }

        // Check if Generate BOL checkbox is checked
        const generateBol = document.getElementById('generate_bol_multi').checked;

        // Populate hidden inputs
        setOrCreateHidden(mainForm, 'origin_type', originType);
        setOrCreateHidden(mainForm, 'origin_id', originId);
        setOrCreateHidden(mainForm, 'destination_type', destinationType);
        setOrCreateHidden(mainForm, 'destination_id', destinationId);
        setOrCreateHidden(mainForm, 'departure_date', departure);
        setOrCreateHidden(mainForm, 'est_arrival_date', arrival);
        setOrCreateHidden(mainForm, 'freight_cost', freightCost);
        setOrCreateHidden(mainForm, 'accessorial_cost', '0'); // Default to 0 since field is removed
        setOrCreateHidden(mainForm, 'customer_cost', customerCost);
        setOrCreateHidden(mainForm, 'miles', miles);
        
        // Add all BOL numbers as a JSON array
        setOrCreateHidden(mainForm, 'bol_numbers', JSON.stringify(bolNumbers));
        
        // Also set the first BOL as fallback for bol_number field
        setOrCreateHidden(mainForm, 'bol_number', bolNumbers.length > 0 ? bolNumbers[0] : '');
        setOrCreateHidden(mainForm, 'generate_bol', generateBol ? '1' : '0');

        mainForm.submit();
    });
}

function setOrCreateHidden(form, fieldName, fieldValue) {
    let el = form.querySelector(`input[name="${fieldName}"]`);
    if (!el) {
        el = document.createElement('input');
        el.type = 'hidden';
        el.name = fieldName;
        form.appendChild(el);
    }
    el.value = fieldValue;
}

// ----------------- MULTI-SHIPMENT SUMMARY -----------------
const palletsPerTruckInput = document.getElementById('palletsPerTruck');
const multiShipSummary = document.getElementById('multiShipSummary');

function updateMultiShipSummary() {
    if (!palletsPerTruckInput || !multiShipSummary) return;
    const selected = document.querySelectorAll('.pallet-checkbox:checked').length;
    
    // Reset user edited flag if no pallets are selected
    if (selected === 0) {
        palletsPerTruckInput.dataset.userEdited = 'false';
        palletsPerTruckInput.value = '1';
    }
    
    // Auto-set default to all pallets for single truck, but only if field hasn't been manually edited
    if (selected > 0 && (palletsPerTruckInput.value == "1" || palletsPerTruckInput.value == "") && palletsPerTruckInput.dataset.userEdited !== 'true') {
        palletsPerTruckInput.value = selected;
    }
    
    const perTruck = parseInt(palletsPerTruckInput.value, 10);
    const validPerTruck = (perTruck && perTruck > 0) ? perTruck : 1;
    const numDeliveries = selected > 0 ? Math.min(Math.ceil(selected / validPerTruck), 12) : 0; // Max 12 trucks
    
    multiShipSummary.textContent = selected > 0
        ? (numDeliveries + ' deliveries will be created (' + validPerTruck + ' pallets per truck)')
        : '';
    
    // Update BOL fields
    updateBolFields(numDeliveries);
}

function updateBolFields(numDeliveries) {
    const bolFieldsGrid = document.getElementById('bolFieldsGrid');
    if (!bolFieldsGrid) return;
    
    // Clear existing fields
    bolFieldsGrid.innerHTML = '';
    
    // Create BOL fields for each delivery
    for (let i = 1; i <= numDeliveries; i++) {
        const fieldDiv = document.createElement('div');
        fieldDiv.style.cssText = 'display: flex; flex-direction: column;';
        
        const label = document.createElement('label');
        label.textContent = `Truck ${i} BOL:`;
        label.style.cssText = 'font-weight: 500; margin-bottom: 5px; font-size: 0.9em;';
        
        const input = document.createElement('input');
        input.type = 'text';
        input.name = `bol_number_${i}`;
        input.id = `bol_number_${i}`;
        input.required = true;
        input.style.cssText = 'padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em;';
        input.placeholder = `BOL for truck ${i}`;
        
        fieldDiv.appendChild(label);
        fieldDiv.appendChild(input);
        bolFieldsGrid.appendChild(fieldDiv);
    }
}

if (palletsPerTruckInput && multiShipSummary) {
    palletsPerTruckInput.addEventListener('input', function() {
        // Mark as user edited when they type
        this.dataset.userEdited = 'true';
        updateMultiShipSummary();
    });
    
    palletsPerTruckInput.addEventListener('focus', function() {
        // Mark as user edited when they focus to edit
        this.dataset.userEdited = 'true';
    });
    
    palletsPerTruckInput.addEventListener('keydown', function(e) {
        // Allow clearing the field
        if (e.key === 'Backspace' || e.key === 'Delete') {
            if (this.value.length === 1) {
                // If we're about to clear the last digit, allow it
                this.dataset.userEdited = 'true';
            }
        }
    });
    
    updateMultiShipSummary();
}

// ----------------- DELIVERY DROPDOWN FUNCTIONALITY -----------------
function toggleDeliveryDropdown(button) {
    const dropdown = button.nextElementSibling;
    const isVisible = dropdown.style.display !== 'none';
    
    // Close all other dropdowns first
    document.querySelectorAll('.delivery-list').forEach(function(list) {
        list.style.display = 'none';
    });
    
    // Toggle this dropdown
    dropdown.style.display = isVisible ? 'none' : 'block';
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    // Close delivery dropdowns
    if (!event.target.closest('.delivery-dropdown')) {
        document.querySelectorAll('.delivery-list').forEach(function(list) {
            list.style.display = 'none';
        });
    }
    
    // Close filter dropdown
    if (!event.target.closest('.filter-dropdown')) {
        const filterContent = document.getElementById('filterContent');
        const toggleBtn = document.querySelector('.filter-toggle-btn');
        if (filterContent && toggleBtn) {
            filterContent.style.display = 'none';
            toggleBtn.classList.remove('active');
        }
    }
});

// ----------------- INITIALIZE DEFAULT DROPDOWNS -----------------
document.addEventListener('DOMContentLoaded', () => {
    // Initialize Google Maps when DOM is ready
    if (window.google) {
        initializeGoogleMaps();
    } else {
        // Wait for Google Maps to load
        window.addEventListener('load', initializeGoogleMaps);
    }
    
    // Default load the destination dropdowns
    toggleDestinationSelectSingle();
    toggleDestinationSelectMulti();
    
    // Initialize origin display
    updateOriginDisplay();
    
    // Auto-apply project filter if coming from a project (but don't auto-open filters)
    const projectFilter = document.getElementById('projectFilter');
    if (projectFilter && projectFilter.value) {
        // Apply the filter without opening the filters dropdown
        filterPallets();
    }
});

// Add functions for persistence
function saveFilters() {
    localStorage.setItem('createShipment_palletSearch', document.getElementById('palletSearch')?.value || '');
    localStorage.setItem('createShipment_projectFilter', document.getElementById('projectFilter')?.value || '');
    localStorage.setItem('createShipment_wattageFilter', document.getElementById('wattageFilter')?.value || '');
    localStorage.setItem('createShipment_statusFilter', document.getElementById('statusFilter')?.value || '');
    localStorage.setItem('createShipment_itemsPerPage', document.getElementById('itemsPerPage')?.value || '100');
    localStorage.setItem('createShipment_currentPage', currentPage);
}

function loadPersistedFilters() {
    // First check for URL parameters (these take priority)
    const urlParams = new URLSearchParams(window.location.search);
    const statusFromUrl = urlParams.get('status_filter');
    const projectFromUrl = urlParams.get('project_id');
    
    // Load from localStorage
    const search = localStorage.getItem('createShipment_palletSearch');
    const project = localStorage.getItem('createShipment_projectFilter');
    const wattage = localStorage.getItem('createShipment_wattageFilter');
    const status = localStorage.getItem('createShipment_statusFilter');
    const perPage = localStorage.getItem('createShipment_itemsPerPage');
    const page = localStorage.getItem('createShipment_currentPage');
    
    if (search) document.getElementById('palletSearch').value = search;
    if (project) document.getElementById('projectFilter').value = project;
    if (wattage) document.getElementById('wattageFilter').value = wattage;
    
    // URL status filter takes priority over localStorage
    if (statusFromUrl) {
        document.getElementById('statusFilter').value = statusFromUrl;
    } else if (status) {
        document.getElementById('statusFilter').value = status;
    }
    
    // URL project filter takes priority over localStorage
    if (projectFromUrl && projectFromUrl !== '0') {
        // Find the project name in the dropdown and select it
        const projectSelect = document.getElementById('projectFilter');
        for (let option of projectSelect.options) {
            if (option.value.includes('project_id=' + projectFromUrl)) {
                projectSelect.value = option.value;
                break;
            }
        }
    }
    
    if (perPage) {
        document.getElementById('itemsPerPage').value = perPage;
        itemsPerPage = parseInt(perPage);
    }
    if (page) currentPage = parseInt(page);
    
    // Apply filters after loading
    filterPallets();
}
</script>
</body>
</html> 