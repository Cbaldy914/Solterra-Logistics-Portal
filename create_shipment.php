<?php
session_name("logistics_session");
session_start();

// Only allow admin and global_admin roles for shipment creation
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
require_once 'delivery_notification_helpers.php';
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

// Optional deep-link filter: only show specific pallet IDs
$pallet_ids_filter = [];
if (!empty($_GET['pallet_ids'])) {
    $raw_ids = explode(',', $_GET['pallet_ids']);
    foreach ($raw_ids as $rid) {
        $ival = intval($rid);
        if ($ival > 0) { $pallet_ids_filter[] = $ival; }
    }
    $pallet_ids_filter = array_values(array_unique($pallet_ids_filter));
}

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

// --- Handle BOL Check Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_bol') {
    header('Content-Type: application/json');
    
    $bolNumber = trim($_POST['bol_number'] ?? '');
    $originType = $_POST['origin_type'] ?? '';
    $originId = isset($_POST['origin_id']) ? intval($_POST['origin_id']) : null;
    $destinationType = $_POST['destination_type'] ?? '';
    $destinationId = isset($_POST['destination_id']) ? intval($_POST['destination_id']) : null;
    
    if (empty($bolNumber)) {
        echo json_encode(['exists' => false]);
        exit();
    }
    
    try {
        // Check for existing deliveries with this BOL number
        $stmt = $conn->prepare("
            SELECT d.id, d.origin_type, d.origin_id, d.project_id, d.warehouse_id,
                   COALESCE(p.project_name, 'Unknown Project') as project_name,
                   COALESCE(w.name, 'Unknown Warehouse') as warehouse_name,
                   COUNT(*) as delivery_count,
                   MAX(d.created_at) as latest_created_at,
                   MIN(d.created_at) as earliest_created_at
            FROM deliveries d
            LEFT JOIN projects p ON d.project_id = p.id
            LEFT JOIN warehouses w ON d.warehouse_id = w.id
            WHERE d.bol_number = ?
            GROUP BY d.origin_type, d.origin_id, d.project_id, d.warehouse_id
        ");
        
        if ($stmt) {
            $stmt->bind_param("s", $bolNumber);
            $stmt->execute();
            $result = $stmt->get_result();
            $existingDeliveries = [];
            
            while ($row = $result->fetch_assoc()) {
                $existingDeliveries[] = $row;
            }
            $stmt->close();
            
            if (empty($existingDeliveries)) {
                echo json_encode(['exists' => false]);
                exit();
            }
            
            // Check if any of the existing deliveries conflict with the new one
            $hasConflict = false;
            $conflictDetails = [];
            
                         foreach ($existingDeliveries as $existing) {
                 $sameOrigin = ($existing['origin_type'] === $originType && $existing['origin_id'] == $originId);
                 $sameDestination = false;
                 
                 if ($destinationType === 'project' && $existing['project_id']) {
                     $sameDestination = ($existing['project_id'] == $destinationId);
                 } elseif ($destinationType === 'warehouse' && $existing['warehouse_id']) {
                     $sameDestination = ($existing['warehouse_id'] == $destinationId);
                 }
                 
                 // If origin and destination are the same, check if created recently (within 10 minutes)
                 if ($sameOrigin && $sameDestination) {
                     // Calculate time difference between the latest existing delivery and now
                     $latestCreatedAt = new DateTime($existing['latest_created_at']);
                     $now = new DateTime();
                     $timeDifferenceMinutes = ($now->getTimestamp() - $latestCreatedAt->getTimestamp()) / 60;
                     
                                           // If created within 1 minute, this is likely intended wattage combining
                      if ($timeDifferenceMinutes <= 1) {
                          continue; // This is fine, probably combining wattages
                      }
                     
                     // If created more than 10 minutes ago, warn about potential reuse
                     $hasConflict = true;
                     $destinationName = $existing['project_id'] ? $existing['project_name'] : $existing['warehouse_name'];
                     $destinationTypeText = $existing['project_id'] ? 'Project' : 'Warehouse';
                     
                     // Calculate how long ago it was created for the warning message
                     $timeAgo = '';
                     if ($timeDifferenceMinutes < 60) {
                         $timeAgo = round($timeDifferenceMinutes) . ' minutes ago';
                     } elseif ($timeDifferenceMinutes < 1440) { // Less than 24 hours
                         $timeAgo = round($timeDifferenceMinutes / 60) . ' hours ago';
                     } else {
                         $timeAgo = round($timeDifferenceMinutes / 1440) . ' days ago';
                     }
                     
                     $conflictDetails[] = [
                         'destination_type' => $destinationTypeText,
                         'destination_name' => $destinationName,
                         'delivery_count' => $existing['delivery_count'],
                         'time_ago' => $timeAgo,
                         'is_same_destination' => true
                     ];
                 } else {
                     // Different origin or destination - definitely a conflict
                     $hasConflict = true;
                     $destinationName = $existing['project_id'] ? $existing['project_name'] : $existing['warehouse_name'];
                     $destinationTypeText = $existing['project_id'] ? 'Project' : 'Warehouse';
                     
                     $conflictDetails[] = [
                         'destination_type' => $destinationTypeText,
                         'destination_name' => $destinationName,
                         'delivery_count' => $existing['delivery_count'],
                         'is_same_destination' => false
                     ];
                 }
             }
            
            echo json_encode([
                'exists' => true,
                'has_conflict' => $hasConflict,
                'conflicts' => $conflictDetails
            ]);
        } else {
            echo json_encode(['exists' => false, 'error' => 'Database error']);
        }
    } catch (Exception $e) {
        echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
    }
    
    exit();
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

        // Server-side guard: block shipments for pallets already in transit or on water
        $placeholders_guard = implode(',', array_fill(0, count($palletIds), '?'));
        $types_guard = str_repeat('i', count($palletIds));
        $disallowed = ['In Transit to Warehouse','In Transit to Project','On Water'];
        $status_placeholders_guard = implode(',', array_fill(0, count($disallowed), '?'));
        $stmtGuard = $conn->prepare(
            "SELECT id, status FROM inventory_pallets WHERE id IN ($placeholders_guard) AND status IN ($status_placeholders_guard)"
        );
        if ($stmtGuard) {
            $bind_types = $types_guard . str_repeat('s', count($disallowed));
            $bind_params = array_merge($palletIds, $disallowed);
            $stmtGuard->bind_param($bind_types, ...$bind_params);
            $stmtGuard->execute();
            $resGuard = $stmtGuard->get_result();
            $blocked = [];
            while ($r = $resGuard->fetch_assoc()) { $blocked[] = $r; }
            $stmtGuard->close();
            if (!empty($blocked)) {
                $ids = array_map(function($x){ return $x['id'].' ('.$x['status'].')'; }, $blocked);
                throw new Exception('Cannot create shipment for pallets already in transit or on water: ' . implode(', ', $ids));
            }
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
        
        // Check if this is an overseas shipment
        $is_overseas_shipment = false;
        $origin_country = 'USA'; // Default
        // Handle both single and multiple container numbers
$container_number = $_POST['container_number'] ?? '';
$container_numbers_json = $_POST['container_numbers'] ?? '';
$container_numbers_array = [];

if (!empty($container_numbers_json)) {
    // Multiple shipments: decode JSON array
    $container_numbers_array = json_decode($container_numbers_json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($container_numbers_array) && !empty($container_numbers_array)) {
        $container_number = $container_numbers_array[0]; // Set first container as primary for validation
    }
} elseif (!empty($container_number)) {
    // Single shipment: use single container number
    $container_numbers_array = [$container_number];
}

// --- Handle Pallet Deletion (admins/global_admins) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_pallets') {
    $deleteMessage = '';
    $conn->begin_transaction();
    try {
        $palletIds = $_POST['selected_pallets'] ?? [];
        if (empty($palletIds)) {
            throw new Exception('No pallets selected for deletion.');
        }

        $placeholders = implode(',', array_fill(0, count($palletIds), '?'));
        $types = str_repeat('i', count($palletIds));

        // Ensure pallets are not linked to deliveries
        $stmtCheck = $conn->prepare("SELECT inventory_pallet_id FROM delivery_pallets WHERE inventory_pallet_id IN ($placeholders)");
        if (!$stmtCheck) { throw new Exception('Failed to prepare delivery check.'); }
        $stmtCheck->bind_param($types, ...$palletIds);
        $stmtCheck->execute();
        $res = $stmtCheck->get_result();
        $linked = [];
        while ($row = $res->fetch_assoc()) { $linked[] = $row['inventory_pallet_id']; }
        $stmtCheck->close();
        if (!empty($linked)) {
            throw new Exception('Cannot delete pallets linked to deliveries. Pallet IDs: ' . implode(', ', $linked));
        }

        $stmtDel = $conn->prepare("DELETE FROM inventory_pallets WHERE id IN ($placeholders)");
        if (!$stmtDel) { throw new Exception('Failed to prepare pallet deletion.'); }
        $stmtDel->bind_param($types, ...$palletIds);
        if (!$stmtDel->execute()) { throw new Exception('Failed to delete pallets: ' . $stmtDel->error); }
        $deleted = $stmtDel->affected_rows;
        $stmtDel->close();

        $conn->commit();
        $_SESSION['create_shipment_message'] = "Successfully deleted $deleted pallet(s).";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['create_shipment_message'] = 'Error deleting pallets: ' . $e->getMessage();
    }
    // Redirect back preserving project filter
    $redirect_url = 'create_shipment.php';
    if ($project_id_from_url > 0) { $redirect_url .= '?project_id=' . $project_id_from_url; }
    header('Location: ' . $redirect_url);
    exit();
}
        $master_bol = $_POST['master_bol'] ?? '';
        $house_bol = $_POST['house_bol'] ?? '';
        $port_of_entry_id = isset($_POST['port_of_entry_id']) && $_POST['port_of_entry_id'] !== '' ? intval($_POST['port_of_entry_id']) : null;
        $origin_port_id = isset($_POST['origin_port_id']) && $_POST['origin_port_id'] !== '' ? intval($_POST['origin_port_id']) : null;
        
        if ($originType === 'manufacturer' && $originId) {
            // Get the country for this manufacturer location
            $stmt_country = $conn->prepare("SELECT country FROM manufacturer_locations WHERE id = ? LIMIT 1");
            if ($stmt_country) {
                $stmt_country->bind_param("i", $originId);
                $stmt_country->execute();
                $stmt_country->bind_result($origin_country);
                if ($stmt_country->fetch()) {
                    $is_overseas_shipment = (strtoupper(trim($origin_country)) !== 'USA');
                }
                $stmt_country->close();
            }
        }
        
        // Validate overseas shipment requirements
        if ($is_overseas_shipment) {
            if (empty($container_number) && empty($container_numbers_array)) {
                throw new Exception('Container number is required for overseas shipments.');
            }
            if (!$port_of_entry_id) {
                throw new Exception('Port of entry is required for overseas shipments.');
            }
            // Verify the selected port is actually marked as a port
            $stmt_port_check = $conn->prepare("SELECT is_port FROM warehouses WHERE id = ? AND is_port = 1 LIMIT 1");
            if ($stmt_port_check) {
                $stmt_port_check->bind_param("i", $port_of_entry_id);
                $stmt_port_check->execute();
                if (!$stmt_port_check->get_result()->num_rows) {
                    throw new Exception('Selected port of entry is not valid.');
                }
                $stmt_port_check->close();
            }
        }
        
        // For overseas shipments, destination is always a warehouse (port), regardless of radio button selection
        if ($is_overseas_shipment) {
            $destinationType = 'warehouse';
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
            SELECT ip.id, ip.wattage, ip.quantity, ip.manufacturer_location_id, ip.assigned_project_id,
                   COALESCE(ip.manufacturer, 
                       CASE 
                           WHEN m.vendor_name LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(m.vendor_name, '-', 1))
                           ELSE m.vendor_name
                       END,
                       'Unknown Manufacturer'
                   ) as manufacturer,
                   ml.country as origin_vendor_country
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN manufacturer_locations ml ON ip.manufacturer_location_id = ml.id
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

            // Calculate total quantity for this group to proportionally distribute costs
            $totalGroupQty = array_sum(array_map(function($palletsForWatt) {
                return array_sum(array_column($palletsForWatt, 'quantity'));
            }, $groupByWattage));
            
            foreach ($groupByWattage as $wattage => $palletsForWatt) {
                $groupQty = array_sum(array_column($palletsForWatt, 'quantity'));
                
                // Calculate proportional costs based on quantity
                $proportionOfTotal = $totalGroupQty > 0 ? ($groupQty / $totalGroupQty) : 1;
                $proportionalFreightCost = $freightCost * $proportionOfTotal;
                $proportionalAccessorialCost = $accessorialCost * $proportionOfTotal;
                $proportionalCustomerCost = $customerCost * $proportionOfTotal;
                $proportionalMiles = $miles * $proportionOfTotal;
                
                // Determine manufacturer for this specific group (should be consistent within a wattage group)
                $groupManufacturer = $supplier_name; // Default fallback
                if (!empty($palletsForWatt) && isset($palletsForWatt[0]['manufacturer'])) {
                    $groupManufacturer = $palletsForWatt[0]['manufacturer'];
                }
                
                // Get assigned project ID for this group (should be consistent within a group)
                $groupAssignedProjectId = null;
                if (!empty($palletsForWatt) && isset($palletsForWatt[0]['assigned_project_id'])) {
                    $groupAssignedProjectId = $palletsForWatt[0]['assigned_project_id'];
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

                if ($is_overseas_shipment) {
                    $statusOfDelivery = 'On Water';
                } else {
                    $statusOfDelivery = ($destinationType === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';
                }
                $deliveryColumns[] = 'status_of_delivery';
                $deliveryParams[] = $statusOfDelivery;
                $deliveryTypes .= 's';

                $deliveryColumns[] = 'freight_cost';
                $deliveryParams[] = $proportionalFreightCost;
                $deliveryTypes .= 'd';

                $deliveryColumns[] = 'accessorial_costs';
                $deliveryParams[] = $proportionalAccessorialCost;
                $deliveryTypes .= 'd';

                $deliveryColumns[] = 'customer_cost';
                $deliveryParams[] = $proportionalCustomerCost;
                $deliveryTypes .= 'd';

                $deliveryColumns[] = 'miles';
                $deliveryParams[] = $proportionalMiles;
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
                    if ($is_overseas_shipment) {
                        // For overseas shipments to ports, use the assigned project ID from the pallets
                        // This tracks what project these pallets are ultimately destined for
                        $deliveryColumns[] = 'project_id';
                        $deliveryParams[] = $groupAssignedProjectId;
                        $deliveryTypes .= 'i';
                    } else {
                        // For domestic warehouse-to-warehouse transfers, use source project ID
                        $deliveryColumns[] = 'project_id';
                        $deliveryParams[] = $source_project_id_for_delivery;
                        $deliveryTypes .= 'i';
                    }

                    $deliveryColumns[] = 'warehouse_id';
                    $deliveryParams[] = $destinationId;
                    $deliveryTypes .= 'i';
                }
                
                // Add overseas shipment fields
                $deliveryColumns[] = 'is_overseas_shipment';
                $deliveryParams[] = $is_overseas_shipment ? 1 : 0;
                $deliveryTypes .= 'i';
                
                // Add container_number for overseas shipments or when explicitly provided (e.g., drayage)
                if ($is_overseas_shipment || !empty($container_number)) {
                    $deliveryColumns[] = 'container_number';
                    $deliveryParams[] = $container_number;
                    $deliveryTypes .= 's';
                }
                
                if ($is_overseas_shipment) {
                    if (!empty($master_bol)) {
                        $deliveryColumns[] = 'master_bol';
                        $deliveryParams[] = $master_bol;
                        $deliveryTypes .= 's';
                    }
                    
                    if (!empty($house_bol)) {
                        $deliveryColumns[] = 'house_bol';
                        $deliveryParams[] = $house_bol;
                        $deliveryTypes .= 's';
                    }
                    
                    // For overseas shipments, port_of_entry_id should be the destination (US port)
                    // not the origin port. The destination port is where the shipment enters the US.
                    $deliveryColumns[] = 'port_of_entry_id';
                    $deliveryParams[] = $destinationId; // Use destination port, not origin port
                    $deliveryTypes .= 'i';
                    
                    // Add origin port ID (where the shipment departed from)
                    if ($origin_port_id) {
                        $deliveryColumns[] = 'origin_port_id';
                        $deliveryParams[] = $origin_port_id;
                        $deliveryTypes .= 'i';
                    }
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

                    if ($is_overseas_shipment) {
                        $status = 'On Water';
                    } else {
                        $status = ($destinationType === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';
                    }
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
        
        // Count unique BOL numbers instead of total delivery records
        $uniqueBolNumbers = [];
        if ($shipmentMode === 'multi' && !empty($bolNumbers)) {
            $uniqueBolNumbers = array_unique($bolNumbers);
        } else {
            $uniqueBolNumbers = [$bolNumber];
        }
        $totalDeliveries = count($uniqueBolNumbers);
        $totalPallets = count($palletIds);
        
        // Check if user wants to generate BOL
        $generateBol = isset($_POST['generate_bol']) && $_POST['generate_bol'] === '1';
        $deliveryIdsParam = implode(',', $createdDeliveryIds);
        
        // ===============================================================
        // DRAYAGE CONTAINER PROCESSING - Handle container updates 
        // ===============================================================
        
        // If this is a drayage shipment (has drayage_container_ids), update the original container deliveries
        if (!empty($_POST['drayage_container_ids'])) {
            $drayage_container_ids = json_decode($_POST['drayage_container_ids'], true);
            $container_number_for_update = $_POST['container_number'] ?? '';
            
            if (is_array($drayage_container_ids) && !empty($drayage_container_ids) && !empty($container_number_for_update)) {
                // Fetch old delivery statuses before updating (for notifications)
                $old_container_statuses = [];
                $placeholders = implode(',', array_fill(0, count($drayage_container_ids), '?'));
                $types = str_repeat('i', count($drayage_container_ids));
                $stmt_old_container_status = $conn->prepare("SELECT id, status_of_delivery FROM deliveries WHERE id IN ($placeholders)");
                if ($stmt_old_container_status) {
                    $stmt_old_container_status->bind_param($types, ...$drayage_container_ids);
                    $stmt_old_container_status->execute();
                    $result_old_container = $stmt_old_container_status->get_result();
                    while ($row_old_container = $result_old_container->fetch_assoc()) {
                        $old_container_statuses[$row_old_container['id']] = $row_old_container['status_of_delivery'];
                    }
                    $stmt_old_container_status->close();
                }
                
                // Update original container deliveries to mark them as "Departed Port"
                // Remove pallet associations from original container deliveries
                $sql_unlink = "DELETE FROM delivery_pallets WHERE delivery_id IN ($placeholders)";
                $stmt_unlink = $conn->prepare($sql_unlink);
                if ($stmt_unlink) {
                    $stmt_unlink->bind_param($types, ...$drayage_container_ids);
                    $stmt_unlink->execute();
                    $stmt_unlink->close();
                }
                
                // Update container delivery status to prevent reappearance in port lists
                $sql_update = "UPDATE deliveries SET status_of_delivery = 'Departed Port', actual_delivery_date = CURDATE() WHERE id IN ($placeholders)";
                $stmt_update = $conn->prepare($sql_update);
                if ($stmt_update) {
                    $stmt_update->bind_param($types, ...$drayage_container_ids);
                    $stmt_update->execute();
                    $stmt_update->close();
                    
                    // Send notifications for container departure
                    foreach ($drayage_container_ids as $container_id) {
                        if (isset($old_container_statuses[$container_id]) && $old_container_statuses[$container_id] !== 'Departed Port') {
                            notify_delivery_status_change($container_id, $old_container_statuses[$container_id], 'Departed Port');
                        }
                    }
                }
            }
        }
        
        // ===============================================================
        // BOL GENERATION AND REDIRECT HANDLING
        // ===============================================================
        
        if ($generateBol) {
            // User wants to generate BOL - redirect directly to BOL generation
            // Store a simple success message for later display after BOL generation
            $deliveryWord = ($totalDeliveries === 1) ? 'delivery' : 'deliveries';
            $_SESSION['shipment_success_for_bol'] = "{$totalDeliveries} {$deliveryWord} successfully created for {$totalPallets} pallets.";
            
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
            // Check if this is a drayage shipment - if so, don't include Generate BOL link
            $isDrayageShipment = !empty($_POST['drayage_container_ids']);
            $bolLink = $isDrayageShipment ? '' : " <a href='{$bolLinkUrl}' style='color: #488C9A; text-decoration: underline; margin-left: 10px;'>Generate BOL</a>";
            
            $deliveryWord = ($totalDeliveries === 1) ? 'delivery' : 'deliveries';
            
            if ($destinationType === 'warehouse') {
                if ($is_overseas_shipment) {
                    $shipMessage = "{$totalDeliveries} {$deliveryWord} successfully created for {$totalPallets} pallets. Pallets are now on water to the selected port. To receive modules into the port when they arrive, <a href='manage_warehouse_inventory.php?warehouse_id={$destinationId}' style='color: #488C9A; text-decoration: underline;'>click here</a>.{$bolLink}";
                } else {
                    $shipMessage = "{$totalDeliveries} {$deliveryWord} successfully created for {$totalPallets} pallets. Pallets are now in transit to the selected warehouse. To receive modules into the warehouse when they arrive, <a href='manage_warehouse_inventory.php?warehouse_id={$destinationId}' style='color: #488C9A; text-decoration: underline;'>click here</a>.{$bolLink}";
                }
            } else {
                // Project delivery - offer scheduling
                $shipMessage = "{$totalDeliveries} {$deliveryWord} successfully created for {$totalPallets} pallets.";
                
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
    // Check if this is a drayage shipment - if so, redirect back to warehouse inventory
    if (!empty($_POST['drayage_container_ids'])) {
        // This is a drayage shipment - redirect back to manage_warehouse_inventory.php
        $_SESSION['move_pallet_message'] = $shipMessage;
        
        // Get the origin warehouse ID for redirect
        $origin_warehouse_id = isset($_POST['origin_id']) ? intval($_POST['origin_id']) : 0;
        $redirect_url = "manage_warehouse_inventory.php";
        if ($origin_warehouse_id > 0) {
            $redirect_url .= "?warehouse_id=" . $origin_warehouse_id;
            // Also preserve project_id if available
            if ($project_id_from_url > 0) {
                $redirect_url .= "&project_id=" . $project_id_from_url;
            }
        }
    } else {
        // Normal shipment - redirect back to create_shipment.php
        $_SESSION['create_shipment_message'] = $shipMessage;
        // Preserve project_id in redirect for breadcrumb navigation
        $redirect_url = "create_shipment.php";
        if ($project_id_from_url > 0) {
            $redirect_url .= "?project_id=" . $project_id_from_url;
        }
    }
    header("Location: " . $redirect_url);
    exit();
}

// --- Data Fetching ---
$pallets = [];
$errorMessage = '';
// Cap the number of pallets rendered to keep page responsive on huge datasets
$server_side_limit = 1000;

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
                ip.manufacturer_location_id,
                m.vendor_name AS origin_vendor,
                m.pallets_per_truck AS module_pallets_per_truck,
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
                COALESCE(ml.country, 'USA') AS origin_vendor_country,
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
    // Include in-transit and allocated statuses so they appear and are filterable
    $allowed_statuses = [
        'At Manufacturer',
        'In Warehouse',
        'Delivered to Project',
        'Allocated to Project',
        'In Transit to Warehouse',
        'In Transit to Project',
        'On Water'
    ];
    $status_placeholders = str_repeat('?,', count($allowed_statuses) - 1) . '?';
    
    if ($role === 'admin' && $account_id_for_admin) {
        $sql .= " WHERE (p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?) AND ip.status IN ($status_placeholders)";
        if (!empty($pallet_ids_filter)) {
            $sql .= " AND ip.id IN (" . implode(',', array_fill(0, count($pallet_ids_filter), '?')) . ")";
        }
    } else {
        $sql .= " WHERE ip.status IN ($status_placeholders)";
        if (!empty($pallet_ids_filter)) {
            $sql .= " AND ip.id IN (" . implode(',', array_fill(0, count($pallet_ids_filter), '?')) . ")";
        }
    }
    
    $sql .= " GROUP BY ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status, ip.arrival_date, ip.unassigned_module_item_id, ip.current_warehouse_id, ip.current_project_id, ip.assigned_project_id, ip.manufacturer_location_id, m.vendor_name, m.pallets_per_truck, m.account_id, ml.street_address, ml.city, ml.state, ml.zip_code, ml.country, ml.location_name, mfg.name, w.name, w.street_address, w.city, w.state, w.zip_code, p_current.project_name, p_current.account_id, p_current.street_address, p_current.city, p_current.state, p_current.zip_code, p_assigned.project_name, p_assigned.account_id
              ORDER BY ip.id ASC LIMIT " . (int)$server_side_limit . "";
    
    // Also compute accurate global counts for header/pagination
    $total_pallets_count = 0;
    $available_to_ship_count = 0;

    if ($role === 'admin' && $account_id_for_admin) {
        $stmt = $conn->prepare($sql);
        $params = array_merge([$account_id_for_admin, $account_id_for_admin, $account_id_for_admin], $allowed_statuses);
        $types = 'iii' . str_repeat('s', count($allowed_statuses));
        
        // No project-level restriction so switching projects in filter works
        if (!empty($pallet_ids_filter)) {
            foreach ($pallet_ids_filter as $pid) { $params[] = $pid; $types .= 'i'; }
        }
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pallets[] = $row;
        }
        $stmt->close();

        // Count total pallets across full dataset (no LIMIT)
        $count_base = "FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN manufacturer_locations ml ON ip.manufacturer_location_id = ml.id
            LEFT JOIN manufacturers mfg ON ml.manufacturer_id = mfg.id
            LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
            LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
            LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
            LEFT JOIN deliveries d ON dp.delivery_id = d.id";

        $count_where = " WHERE (p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?) AND ip.status IN (" . $status_placeholders . ")";
        $count_params = [$account_id_for_admin, $account_id_for_admin, $account_id_for_admin];
        $count_types = 'iii' . str_repeat('s', count($allowed_statuses));
        $count_params = array_merge($count_params, $allowed_statuses);

        // No project-level restriction so switching projects via UI filter works
        if (!empty($pallet_ids_filter)) {
            $count_where .= " AND ip.id IN (" . implode(',', array_fill(0, count($pallet_ids_filter), '?')) . ")";
            foreach ($pallet_ids_filter as $pid) { $count_params[] = $pid; $count_types .= 'i'; }
        }

        $stmtCount = $conn->prepare("SELECT COUNT(DISTINCT ip.id) as total " . $count_base . $count_where);
        if ($stmtCount) {
            $stmtCount->bind_param($count_types, ...$count_params);
            $stmtCount->execute();
            $stmtCount->bind_result($totalTmp);
            if ($stmtCount->fetch()) { $total_pallets_count = (int)$totalTmp; }
            $stmtCount->close();
        }

        // Available to ship count
        $available_statuses = ['In Warehouse','At Manufacturer','Delivered to Project'];
        $avail_placeholders = implode(',', array_fill(0, count($available_statuses), '?'));
        $stmtAvail = $conn->prepare(
            "SELECT COUNT(DISTINCT ip.id) as total " . $count_base . $count_where . " AND ip.status IN (" . $avail_placeholders . ")"
        );
        if ($stmtAvail) {
            $avail_types = $count_types . str_repeat('s', count($available_statuses));
            $avail_params = array_merge($count_params, $available_statuses);
            $stmtAvail->bind_param($avail_types, ...$avail_params);
            $stmtAvail->execute();
            $stmtAvail->bind_result($availTmp);
            if ($stmtAvail->fetch()) { $available_to_ship_count = (int)$availTmp; }
            $stmtAvail->close();
        }
    } else {
        $stmt = $conn->prepare($sql);
        $params = $allowed_statuses;
        $types = str_repeat('s', count($allowed_statuses));
        
        // No project-level restriction so switching projects in filter works
        if (!empty($pallet_ids_filter)) {
            foreach ($pallet_ids_filter as $pid) { $params[] = $pid; $types .= 'i'; }
        }
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pallets[] = $row;
        }
        $stmt->close();

        // Count totals for non-admin/global_admin
        $count_base = "FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN manufacturer_locations ml ON ip.manufacturer_location_id = ml.id
            LEFT JOIN manufacturers mfg ON ml.manufacturer_id = mfg.id
            LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
            LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
            LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
            LEFT JOIN deliveries d ON dp.delivery_id = d.id";
        $count_where = " WHERE ip.status IN (" . $status_placeholders . ")";
        $count_params = $allowed_statuses;
        $count_types = str_repeat('s', count($allowed_statuses));
        
        if (!empty($pallet_ids_filter)) {
            $count_where .= " AND ip.id IN (" . implode(',', array_fill(0, count($pallet_ids_filter), '?')) . ")";
            foreach ($pallet_ids_filter as $pid) { $count_params[] = $pid; $count_types .= 'i'; }
        }

        $stmtCount = $conn->prepare("SELECT COUNT(DISTINCT ip.id) as total " . $count_base . $count_where);
        if ($stmtCount) {
            $stmtCount->bind_param($count_types, ...$count_params);
            $stmtCount->execute();
            $stmtCount->bind_result($totalTmp);
            if ($stmtCount->fetch()) { $total_pallets_count = (int)$totalTmp; }
            $stmtCount->close();
        }

        $available_statuses = ['In Warehouse','At Manufacturer','Delivered to Project'];
        $avail_placeholders = implode(',', array_fill(0, count($available_statuses), '?'));
        $stmtAvail = $conn->prepare(
            "SELECT COUNT(DISTINCT ip.id) as total " . $count_base . $count_where . " AND ip.status IN (" . $avail_placeholders . ")"
        );
        if ($stmtAvail) {
            $avail_types = $count_types . str_repeat('s', count($available_statuses));
            $avail_params = array_merge($count_params, $available_statuses);
            $stmtAvail->bind_param($avail_types, ...$avail_params);
            $stmtAvail->execute();
            $stmtAvail->bind_result($availTmp);
            if ($stmtAvail->fetch()) { $available_to_ship_count = (int)$availTmp; }
            $stmtAvail->close();
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

    // Fetch Warehouses (with addresses) - exclude ports for domestic use
    $all_warehouses = [];
    $stmtW = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM warehouses WHERE is_port = 0 OR is_port IS NULL ORDER BY name ASC");
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
            ml.zip_code,
            ml.country
        FROM manufacturers m
        LEFT JOIN manufacturer_locations ml ON m.id = ml.manufacturer_id AND ml.is_primary = TRUE
        WHERE m.is_active = 1 
        ORDER BY m.name ASC");
    if ($stmtM) {
        $stmtM->execute();
        $resultM = $stmtM->get_result();
        while ($mfg = $resultM->fetch_assoc()) {
            // For international addresses, include country; for USA, it's optional
            $country = $mfg['country'] ?? 'USA';
            if (strtoupper($country) === 'USA') {
                $address_parts = array_filter([$mfg['street_address'], $mfg['city'], $mfg['state'], $mfg['zip_code']]);
            } else {
                // For international addresses, always include country for proper geocoding
                $address_parts = array_filter([$mfg['street_address'], $mfg['city'], $mfg['state'], $mfg['zip_code'], $country]);
            }
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
    <title>Manage Pallets - Solterra Logistics Portal</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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

        /* Header Section */
        .manage-pallets-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .manage-pallets-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        .header-stats {
            display: flex;
            gap: 32px;
            align-items: center;
        }

        .stat-item {
            text-align: center;
            min-width: 80px;
        }

        .stat-number {
            font-size: 2.2em;
            font-weight: 700;
            color: #293E4C;
            margin: 0 0 4px 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 500;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @media (max-width: 768px) {
            .manage-pallets-header {
                padding: 24px;
                margin-bottom: 24px;
            }

            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }

            .header-left {
                flex-direction: column;
                gap: 16px;
            }

            .header-icon {
                width: 60px;
                height: 60px;
                font-size: 24px;
            }

            .header-info h1 {
                font-size: 2em;
            }

            .header-stats {
                gap: 20px;
                flex-wrap: wrap;
                justify-content: center;
            }

            .stat-item {
                min-width: 60px;
            }

            .stat-number {
                font-size: 1.8em;
            }
        }

        .section-title {
            font-size: 1.3em;
            margin-bottom: 15px;
            color: #488C9A;
            border-bottom: 2px solid #488C9A;
            padding-bottom: 5px;
        }
        /* Unified Filter + Table Header (from view_project) */
        .filter-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }
        .filter-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; gap: 16px; flex-wrap: wrap; }
        .filter-title { font-size: 1.2em; font-weight: 600; color:#293E4C; margin:0; display:flex; align-items:center; gap:10px; }
        .filter-title i { color:#488C9A; }
        .filter-actions { display:flex; gap:10px; align-items:center; flex-wrap: wrap; }
        .btn-clear, .btn-apply { padding:10px 16px; border-radius:10px; font-size:.9em; font-weight:600; cursor:pointer; border:none; display:flex; align-items:center; gap:8px; }
        .btn-clear { background: linear-gradient(135deg, rgba(239,68,68,.1) 0%, rgba(220,38,38,.15) 100%); color:#dc2626; border:1px solid rgba(239,68,68,.2); }
        .btn-apply { background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color:#fff; }
        .filter-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(250px,1fr)); gap: 16px; }
        .filter-group { display:flex; flex-direction:column; }
        .filter-label { font-weight:600; color:#293E4C; font-size:.95em; margin-bottom:6px; }
        .filter-select, .filter-input { width:100%; padding: 10px 12px; border: 2px solid rgba(72,140,154,.15); border-radius:10px; background:#fff; font-size:.95em; box-sizing:border-box; }
        .deliveries-container { background: linear-gradient(135deg,#ffffff 0%, #f8f9fa 100%); border-radius:20px; overflow:hidden; box-shadow: 0 8px 32px rgba(0,0,0,.06); border:1px solid rgba(72,140,154,.08); margin-top: 12px; }
        .table-header { background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color:white; padding: 16px 20px; display:flex; align-items:center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .table-title { font-size:1.2em; font-weight:600; margin:0; display:flex; align-items:center; gap:10px; color:white; }
        .table-header-actions { display:flex; gap:10px; align-items:center; flex-wrap: wrap; }
        .action-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:10px; font-size:.85em; font-weight:600; text-decoration:none; border:none; cursor:pointer; white-space:nowrap; transition: all .2s ease; }
        .action-btn-primary { background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color:white; }
        .action-btn-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color:white; }
        .btn-export-header { background: rgba(255,255,255,.95); color:#16a34a; border:none; box-shadow: 0 2px 8px rgba(0,0,0,.15); cursor: pointer; }
        .action-btn:hover:not([disabled]) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-export-header:hover { background:white; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .action-btn[disabled] { opacity: .5; cursor: not-allowed; filter: grayscale(20%); box-shadow: none; }
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
            padding: 12px 20px;
            border: none;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
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
    <?php
        require_once 'components/breadcrumbs.php';
        if ($project_id_from_url > 0) {
            echo slp_render_breadcrumbs(['current_label' => 'Manage Pallets', 'project_id' => (int)$project_id_from_url]);
        } else {
            echo slp_render_breadcrumbs(['current_label' => 'Manage Pallets']);
        }
    ?>

    <div class="manage-pallets-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-info">
                    <h1>Manage Pallets</h1>
                    <p class="header-subtitle">Manage pallets and create shipments</p>
                </div>
            </div>
            <div class="header-stats">
                <div class="stat-item">
                    <p class="stat-number" id="totalPallets">0</p>
                    <p class="stat-label">Total Pallets</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number" id="selectedPallets">0</p>
                    <p class="stat-label">Selected</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number" id="availablePallets">0</p>
                    <p class="stat-label">Available to Ship</p>
                </div>
            </div>
        </div>
    </div>

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
                <h2 class="section-title" style="display:none;">Select Pallets to Create Shipment</h2>
                <!-- New Unified Filter Bar -->
                <div class="filter-section">
                    <div class="filter-header">
                        <h2 class="filter-title"><i class="fas fa-filter"></i> Filter Pallets</h2>
                        <div class="filter-actions">
                            <button type="button" class="btn-clear" onclick="clearFilterBar()"><i class="fas fa-times"></i> Clear</button>
                            <button type="button" class="btn-apply" onclick="applyFilterBar()"><i class="fas fa-search"></i> Apply Filters</button>
                        </div>
                    </div>
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label" for="cs_search">Search</label>
                            <input type="text" id="cs_search" class="filter-input" placeholder="Search pallets...">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="cs_project">Project</label>
                            <select id="cs_project" class="filter-select">
                                <option value="">All Projects</option>
                                <option value="Unassigned">Unassigned</option>
                                <?php foreach ($all_projects_for_filter as $proj): ?>
                                    <option value="<?php echo htmlspecialchars($proj['project_name']); ?>" <?php echo ($project_id_from_url > 0 && $proj['id'] == $project_id_from_url) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($proj['project_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="cs_wattage">Wattage</label>
                            <select id="cs_wattage" class="filter-select">
                                <option value="">All Wattages</option>
                                <?php $wattages = array_unique(array_map(function($p) { return $p['wattage']; }, $pallets)); sort($wattages); foreach ($wattages as $w) { echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>'; } ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="cs_status">Status</label>
                            <select id="cs_status" class="filter-select">
                                <option value="">All Statuses</option>
                                <?php $available_statuses = array_unique(array_map(function($p) { return $p['status']; }, $pallets)); foreach ($available_statuses as $s) { echo '<option value="' . htmlspecialchars($s) . '">' . htmlspecialchars($s) . '</option>'; } ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Section Title (moved below filters) -->
                <h2 class="section-title">Select Pallets to Create Shipment</h2>

                <!-- Legacy controls (hidden) -->
                <div class="filters-container" style="display:none; margin-bottom: 15px; justify-content: space-between; align-items: flex-start; gap: 20px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="filter-dropdown">
                            <button type="button" class="filter-toggle-btn" onclick="toggleFilters()" style="display:none;">
                                <span>Filters</span> <span class="filter-arrow">▼</span>
                            </button>
                            <div class="filter-content" id="filterContent" style="display: block;">
                                <div style="display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; gap: 12px; padding: 10px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="text" id="palletSearch" placeholder="Search pallets..." onkeyup="filterPallets()" style="flex: 1; min-width: 220px;">
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label for="projectFilter" style="display:none;">Project:</label>
                                    <select id="projectFilter" onchange="filterPallets()" style="min-width: 200px;">
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
                                    <label for="wattageFilter" style="display:none;">Wattage:</label>
                                    <select id="wattageFilter" onchange="filterPallets()" style="min-width: 160px;">
                                        <option value="">All Wattages</option>
                                        <?php
                                        // Only show wattages that are available in current pallets (filtered by account if admin)
                                        $wattages = array_unique(array_map(function($p) { return $p['wattage']; }, $pallets));
                                        sort($wattages);
                                        foreach ($wattages as $w) {
                                            echo '<option value="' . htmlspecialchars($w) . '">' . htmlspecialchars($w) . 'W</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label for="statusFilter" style="display:none;">Status:</label>
                                    <select id="statusFilter" onchange="filterPallets()" style="min-width: 200px;">
                                        <option value="">All Statuses</option>
                                        <?php
                                        // Only show statuses that are available in current pallets (filtered by account if admin)
                                        $available_statuses = array_unique(array_map(function($p) { return $p['status']; }, $pallets));
                                        foreach ($available_statuses as $s) {
                                            echo '<option value="' . htmlspecialchars($s) . '">' . htmlspecialchars($s) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                    <div style="display: none; align-items: center; justify-content: center; flex: 1;">
                        <span id="selectedCount" style="font-weight: bold; color: #488C9A;">0 pallets selected</span>
                    </div>
                    <div style="display: none; align-items: center; gap: 12px;">
                        <button type="button" id="deletePalletsBtn_old" class="action-button" style="background-color:#dc3545;" disabled>Delete</button>
                        <button type="button" id="exportCsvBtn_old" class="action-button">Export</button>
                        <button type="button" id="openShipModalBtn_old" class="action-button" disabled> Create Shipment </button>
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
                    <div class="deliveries-container">
                        <div class="table-header">
                            <h3 class="table-title"><i class="fas fa-boxes"></i> Pallets</h3>
                            <div class="table-header-actions">
                                <button type="button" id="deletePalletsBtn" class="action-btn action-btn-warning" disabled><i class="fas fa-trash"></i> Delete</button>
                                <button type="button" id="exportCsvBtn" class="btn-export-header"><i class="fas fa-download"></i> Export</button>
                                <button type="button" id="openShipModalBtn" class="action-btn action-btn-primary" disabled><i class="fas fa-truck-loading"></i> Create Shipment</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                    <table id="palletsTable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllPallets" disabled></th>
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
                                <tr data-id="<?php echo htmlspecialchars($pallet['pallet_id']); ?>">
                                    <td><input type="checkbox" name="selected_pallets[]" value="<?php echo $pallet['pallet_id']; ?>" class="pallet-checkbox" data-status="<?php echo htmlspecialchars($pallet['status']); ?>"></td>
                                    <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                    <td><?php echo $pallet['wattage']; ?>W</td>
                                    <td><?php echo number_format($pallet['quantity']); ?></td>
                                    <td>
                                        <?php 
                                        $status = htmlspecialchars($pallet['status']);
                                        if ($pallet['current_warehouse_id'] && $status === 'In Transit to Warehouse') {
                                            echo '<a href="manage_warehouse_inventory.php?warehouse_id=' . (int)$pallet['current_warehouse_id'] . '&view=inbound_transit" style="color: #488C9A; text-decoration: underline;">' . $status . '</a>';
                                        } elseif ($pallet['current_warehouse_id'] && $status === 'In Warehouse') {
                                            echo '<a href="manage_warehouse_inventory.php?warehouse_id=' . (int)$pallet['current_warehouse_id'] . '&view=stored_inventory" style="color: #488C9A; text-decoration: underline;">' . $status . '</a>';
                                        } else {
                                            echo $status;
                                        }
                                        ?>
                                    </td>
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
                                                echo '<a href="manage_deliveries.php?delivery_id=' . htmlspecialchars($deliveryId) . '&from=manage_pallets" style="color: #488C9A; text-decoration: underline;">' . htmlspecialchars($bolNumber) . '</a>';
                                            } else {
                                                echo '<div class="delivery-dropdown">';
                                                echo '<button type="button" class="delivery-toggle" onclick="toggleDeliveryDropdown(this)" style="background: #488C9A; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer;">Multiple (' . count($deliveries) . ')</button>';
                                                echo '<div class="delivery-list" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 3px; z-index: 1000; min-width: 150px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">';
                                                foreach ($deliveries as $delivery) {
                                                    $parts = explode(':', $delivery);
                                                    $deliveryId = $parts[0];
                                                    $bolNumber = $parts[1];
                                                    echo '<a href="manage_deliveries.php?delivery_id=' . htmlspecialchars($deliveryId) . '&from=manage_pallets" style="display: block; padding: 8px 12px; color: #488C9A; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor=\'#f5f5f5\'" onmouseout="this.style.backgroundColor=\'white\'">' . htmlspecialchars($bolNumber) . '</a>';
                                                }
                                                echo '</div>';
                                                echo '</div>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php $detailsUrl = 'pallet_details.php?pallet_id=' . (int)$pallet['pallet_id'] . ($project_id_from_url > 0 ? ('&project_id=' . (int)$project_id_from_url) : ''); ?>
                                        <a href="<?php echo $detailsUrl; ?>" class="action-button" style="background-color: #488C9A; color: white; padding: 4px 8px; text-decoration: none; border-radius: 3px; font-size: 0.9em;">View Details</a>
                                        <a href="edit_pallet.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" class="action-button" style="background-color: #6c757d; color: white; padding: 4px 8px; text-decoration: none; border-radius: 3px; font-size: 0.9em; margin-left:6px;">Edit</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                        </div>
                    </div>
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
            <h2 class="section-title" style="margin-top:0; text-align:center;">Create Shipment</h2>
            <div class="tabs" style="display:none;"></div>
            

            
            <!-- SINGLE SHIPMENT SECTION -->
            <div id="singleShipmentSection" style="display:none;">
                <form id="singleShipmentForm" onsubmit="return false;">
                    <!-- BOL Number for Domestic Shipments -->
                    <div id="domesticBolField">
                        <label for="bol_number">BOL Number:</label>
                        <input type="text" id="bol_number" name="bol_number" required>
                    </div>
                    
                    <!-- Container Number for Overseas Shipments -->
                    <div id="overseasContainerField" style="display: none;">
                        <label for="container_number">Container Number: *</label>
                        <input type="text" id="container_number" name="container_number" placeholder="e.g. MSKU7073334" required>
                        
                        <div class="form-row" style="margin-top: 10px;">
                            <div>
                                <label for="master_bol">Master BOL:</label>
                                <input type="text" id="master_bol" name="master_bol" placeholder="Optional">
                            </div>
                            <div>
                                <label for="house_bol">House BOL:</label>
                                <input type="text" id="house_bol" name="house_bol" placeholder="Optional">
                            </div>
                        </div>
                    </div>
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
                    <div class="form-row" id="domestic-cost-fields">
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
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Origin Port:</label>
                                
                                <!-- Origin Port for Overseas Shipments -->
                                <div id="originPortSection" style="display: none; margin-bottom: 15px;">
                                    <select id="origin_port_id" name="origin_port_id" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="">Select departure port...</option>
                                    </select>
                                </div>
                                
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
                                <label id="destinationLabel" style="margin-bottom: 10px; display:block; font-weight: 600;">Destination:</label>
                                
                                <!-- Domestic Destination Selection -->
                                <div id="domesticDestinationGroup">
                                    <div class="destination-radio-group" style="display: flex; gap: 15px; margin-bottom: 10px;">
                                        <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                            <input type="radio" name="destination_type" value="project" checked onchange="toggleDestinationSelectSingle()" style="margin-right: 5px;"> Project
                                        </label>
                                        <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                            <input type="radio" name="destination_type" value="warehouse" onchange="toggleDestinationSelectSingle()" style="margin-right: 5px;"> Warehouse
                                        </label>
                                    </div>
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
                        Create Shipment
                    </button>
                </form>
            </div>
            <!-- MULTIPLE SHIPMENTS SECTION -->
            <div id="multiShipmentSection">
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
                    
                    <!-- Overseas Shipment Fields -->
                    <div id="overseasContainerFieldsMulti" style="display: none; margin-top: 15px;">
                        <div class="form-row">
                            <div>
                                <label for="master_bol_multi">Master BOL:</label>
                                <input type="text" id="master_bol_multi" name="master_bol_multi" placeholder="Optional">
                            </div>
                            <div>
                                <label for="house_bol_multi">House BOL:</label>
                                <input type="text" id="house_bol_multi" name="house_bol_multi" placeholder="Optional">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Origin and Destination Section -->
                    <div class="origin-destination-section">
                        <div class="location-container" style="display: flex; align-items: flex-start; gap: 20px;">
                            <div class="origin-section" style="flex: 1;">
                                <label style="margin-bottom: 10px; display:block; font-weight: 600;">Origin Port:</label>
                                
                                <!-- Origin Port for Overseas Shipments -->
                                <div id="originPortSectionMulti" style="display: none; margin-bottom: 15px;">
                                    <select id="origin_port_id_multi" name="origin_port_id_multi" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="">Select departure port...</option>
                                    </select>
                                </div>
                                
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
                                <label id="destinationLabelMulti" style="margin-bottom: 10px; display:block; font-weight: 600;">Destination:</label>
                                
                                <!-- Domestic Destination Selection -->
                                <div id="domesticDestinationGroupMulti">
                                    <div class="destination-radio-group" style="display: flex; gap: 15px; margin-bottom: 10px;">
                                        <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                            <input type="radio" name="destination_type_multi" value="project" checked onchange="toggleDestinationSelectMulti()" style="margin-right: 5px;"> Project
                                        </label>
                                        <label class="radio-label" style="display: flex; align-items: center; margin: 0; font-weight: normal;">
                                            <input type="radio" name="destination_type_multi" value="warehouse" onchange="toggleDestinationSelectMulti()" style="margin-right: 5px;"> Warehouse
                                        </label>
                                    </div>
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

<!-- BOL Duplicate Warning Modal -->
<div id="bolWarningModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
        <span class="close-modal-btn" onclick="closeBolWarningModal()">&times;</span>
        <div class="shipment-details-modal-content">
            <h2 style="margin-top: 0; text-align: center; color: #d32f2f;">⚠️ BOL Number Already Exists</h2>
            <div id="bolWarningContent" style="margin: 20px 0;">
                <!-- Warning content will be populated by JavaScript -->
            </div>
            <div style="display: flex; gap: 15px; justify-content: center; margin-top: 25px;">
                <button type="button" class="action-button" style="background: #d32f2f !important;" onclick="closeBolWarningModal()">
                    Cancel
                </button>
                <button type="button" class="action-button" onclick="proceedWithDuplicateBol()">
                    Proceed Anyway
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Expose server-side totals for accurate header/pagination info -->
<div id="statsData" data-total-pallets="<?php echo (int)($total_pallets_count ?? 0); ?>" data-available-pallets="<?php echo (int)($available_to_ship_count ?? 0); ?>" data-loaded="<?php echo (int)count($pallets); ?>" data-limit="<?php echo (int)$server_side_limit; ?>" style="display:none"></div>

<!-- Embed PHP data as JS variables for populating dropdowns -->
<script>
    const projectsData = <?php echo json_encode($all_projects); ?>;
    const warehousesData = <?php echo json_encode($all_warehouses); ?>;
    const manufacturersData = <?php echo json_encode($all_manufacturers); ?>;
    const palletsData = <?php echo json_encode($pallets); ?>;
</script>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places,geometry"></script>

<script>
// ----------------- GLOBAL STATE TRACKING -----------------
let currentOverseasState = null; // Track current overseas state to prevent unnecessary UI updates

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
        if (checked > 0 && selectionHasInvalidPallets()) {
            openBtn.title = 'Selection includes pallets already in transit or on water';
            openBtn.setAttribute('data-invalid-selection', '1');
        } else {
            openBtn.title = '';
            openBtn.removeAttribute('data-invalid-selection');
        }
    }
}

function updateSelectedCount() {
    let count = document.querySelectorAll('.pallet-checkbox:checked').length;
    const countEl = document.getElementById('selectedCount');
    if (countEl) {
        countEl.textContent = count + ' pallet' + (count === 1 ? '' : 's') + ' selected';
    }
    const delBtn = document.getElementById('deletePalletsBtn');
    if (delBtn) { delBtn.disabled = (count === 0); }
    
    // Update header stats
    updateHeaderStats();
}

function updateHeaderStats() {
    // Update selected pallets count
    const selectedCount = document.querySelectorAll('.pallet-checkbox:checked').length;
    const selectedPalletsEl = document.getElementById('selectedPallets');
    if (selectedPalletsEl) {
        selectedPalletsEl.textContent = selectedCount;
    }

    // Use server totals for total/available counts
    const statsEl = document.getElementById('statsData');
    const totalPalletsEl = document.getElementById('totalPallets');
    const availablePalletsEl = document.getElementById('availablePallets');
    const serverTotal = statsEl ? parseInt(statsEl.dataset.totalPallets || '0') : 0;
    const serverAvailable = statsEl ? parseInt(statsEl.dataset.availablePallets || '0') : 0;
    if (totalPalletsEl) totalPalletsEl.textContent = serverTotal;
    if (availablePalletsEl) availablePalletsEl.textContent = serverAvailable;
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAllPallets');
    const palletCheckboxes = document.querySelectorAll('.pallet-checkbox');
    if (selectAll) {
        // Enable once scripts are ready to avoid inline handler errors on huge pages
        selectAll.removeAttribute('disabled');
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
    // New filter bar listeners (no persistence needed)
    document.getElementById('cs_search')?.addEventListener('keyup', filterPallets);
    document.getElementById('cs_project')?.addEventListener('change', filterPallets);
    document.getElementById('cs_wattage')?.addEventListener('change', filterPallets);
    document.getElementById('cs_status')?.addEventListener('change', filterPallets);
    document.getElementById('itemsPerPage')?.addEventListener('change', saveFilters);

    // Wire up Export and Delete controls
    initializeExportCsv();
    initializeDeletePallets();
    
    // Initialize header stats
    updateHeaderStats();
});

// Export to CSV (table-level export of visible rows)
function initializeExportCsv() {
    const exportBtn = document.getElementById('exportCsvBtn');
    if (!exportBtn) return;
    exportBtn.addEventListener('click', function() {
        const table = document.getElementById('palletsTable');
        if (!table) return;
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        const csvData = [];
        const headers = ["id", "pallet_identifier", "wattage", "quantity", "status", "Project", "Associated Deliveries"];
        csvData.push(headers.map(h => '"' + h.replace(/"/g, '""') + '"').join(','));
        rows.forEach(row => {
            if (row.style.display === 'none') return;
            const cells = row.querySelectorAll('td');
            const rowData = [];
            const id = row.getAttribute('data-id') || '';
            const mapping = { pallet_identifier:1, wattage:2, quantity:3, status:4, Project:5, deliveries:6 };
            headers.forEach(h => {
                let val = '';
                if (h === 'id') { val = id; }
                else if (h === 'Associated Deliveries') { val = (cells[mapping.deliveries]?.textContent || '').trim(); }
                else if (h === 'Project') { val = (cells[mapping.Project]?.textContent || '').trim(); }
                else {
                    const key = h.replace(/\s+/g,'_');
                    const idx = mapping[key] ?? mapping[h];
                    val = (cells[idx]?.textContent || '').trim();
                }
                val = val.replace(/"/g,'""');
                if (val.includes(',')) val = '"' + val + '"';
                rowData.push(val);
            });
            csvData.push(rowData.join(','));
        });
        const blob = new Blob([csvData.join("\r\n")], {type:'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url; link.download = 'pallets_export_' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(link); link.click();
        document.body.removeChild(link); URL.revokeObjectURL(url);
    });
}

function initializeDeletePallets() {
    const delBtn = document.getElementById('deletePalletsBtn');
    if (!delBtn) return;
    delBtn.addEventListener('click', function() {
        const count = document.querySelectorAll('.pallet-checkbox:checked').length;
        if (count === 0) { alert('Please select pallets to delete.'); return; }
        if (!confirm(`Are you sure you want to delete ${count} selected pallet(s)? This action cannot be undone.`)) return;
        const form = document.getElementById('shipPalletsForm');
        let actionInput = form.querySelector('input[name="action"]');
        if (!actionInput) { actionInput = document.createElement('input'); actionInput.type='hidden'; actionInput.name='action'; form.appendChild(actionInput); }
        actionInput.value = 'delete_pallets';
        form.submit();
    });
}

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
        
        const filter = (document.getElementById('cs_search')?.value || document.getElementById('palletSearch')?.value || '').toLowerCase();
        const projectFilter = document.getElementById('cs_project')?.value || document.getElementById('projectFilter')?.value || '';
        const wattageFilter = document.getElementById('cs_wattage')?.value || document.getElementById('wattageFilter')?.value || '';
        const statusFilter = document.getElementById('cs_status')?.value || document.getElementById('statusFilter')?.value || '';
        
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
    const statsEl = document.getElementById('statsData');
    const serverTotal = statsEl ? parseInt(statsEl.dataset.totalPallets || '0') : totalItems;
    const loadedCount = statsEl ? parseInt(statsEl.dataset.loaded || '0') : totalItems;
    const limitCount = statsEl ? parseInt(statsEl.dataset.limit || '0') : 0;
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
        let suffix = '';
        if (serverTotal > loadedCount && limitCount > 0) {
            suffix = ` (limited to first ${loadedCount})`;
        }
        paginationInfo.textContent = `Showing ${displayStart}-${showing} of ${serverTotal} pallets${suffix}`;
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

// Apply/Clear for new filter bar
function applyFilterBar() {
    filterPallets();
}
function clearFilterBar() {
    const ids = ['cs_search','cs_project','cs_wattage','cs_status'];
    ids.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    filterPallets();
}

// ----------------- MODAL LOGIC -----------------
const shipModal = document.getElementById('shipModal');
const openShipModalBtn = document.getElementById('openShipModalBtn');
const closeShipModalBtn = shipModal.querySelector('.close-modal-btn');

function selectionHasInvalidPallets() {
    const invalidMatch = /in transit|on water/i;
    const selected = document.querySelectorAll('.pallet-checkbox:checked');
    for (const cb of selected) {
        const row = cb.closest('tr');
        if (!row) continue;
        const statusCell = row.cells && row.cells[4]; // Status column
        const statusText = (statusCell ? (statusCell.textContent || statusCell.innerText || '') : '').trim();
        if (invalidMatch.test(statusText)) {
            return true;
        }
    }
    return false;
}

function openShipModal() {
    shipModal.style.display = 'block';
    // Initialize multi-shipment defaults and BOL fields when modal opens
    try { setDefaultPalletsPerTruckFromSelection(); } catch(e) {}
    updateMultiShipSummary();
}
function closeShipModal() {
    shipModal.style.display = 'none';
}

function handleOpenShipModalClick(e) {
    if (selectionHasInvalidPallets()) {
        alert('You cannot create a delivery for pallets that are already In Transit or On Water. Please deselect those pallets to proceed.');
        return;
    }
    openShipModal();
}

openShipModalBtn.addEventListener('click', handleOpenShipModalClick);
if (closeShipModalBtn) {
    closeShipModalBtn.addEventListener('click', closeShipModal);
}
// Determine default pallets per truck from selected pallets' module settings
function setDefaultPalletsPerTruckFromSelection() {
    const perTruckInput = document.getElementById('palletsPerTruck');
    if (!perTruckInput) return;
    const selected = getSelectedPallets();
    if (!selected || selected.length === 0) return;
    const counts = {};
    let fallback = 1;
    selected.forEach(p => {
        const v = parseInt(p.module_pallets_per_truck || 0, 10);
        if (v && v > 0) {
            counts[v] = (counts[v] || 0) + 1;
        }
    });
    let best = null, bestCount = 0;
    Object.entries(counts).forEach(([k,c]) => { if (c > bestCount) { best = parseInt(k,10); bestCount = c; } });
    perTruckInput.value = best || fallback;
}
// Disable clicking outside to close modal for admin/global_admin users
// (They need to click the X button to close)
// window.addEventListener('click', function(e) {
//     if (e.target === shipModal) {
//         closeShipModal();
//     }
// });

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
                location_id: firstPallet.manufacturer_location_id,
                name: manufacturer.name,
                address: manufacturerAddress,
                country: firstPallet.origin_vendor_country,
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
    // Get ALL checkboxes, regardless of visibility, and filter for checked ones
    const allCheckboxes = document.querySelectorAll('.pallet-checkbox');
    const selectedCheckboxes = Array.from(allCheckboxes).filter(checkbox => checkbox.checked);
    const selectedPallets = [];
    
    selectedCheckboxes.forEach(checkbox => {
        const palletId = parseInt(checkbox.value);
        const pallet = palletsData.find(p => p.pallet_id === palletId);
        if (pallet) {
            selectedPallets.push(pallet);
        }
    });
    
    // Debug logging to help identify issues with selection across filters
    console.log(`Found ${selectedCheckboxes.length} checked checkboxes out of ${allCheckboxes.length} total, ${selectedPallets.length} matching pallets in data`);
    
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
        
        // Check if this is an overseas shipment
        checkOverseasShipment(result.origin);
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
        
        // Hide overseas fields when origin is invalid
        hideOverseasFields();
    }
}

// ----------------- OVERSEAS SHIPMENT DETECTION -----------------
function checkOverseasShipment(origin, destination = null) {
    // Check origin country (manufacturer location)
    if (origin && origin.type === 'manufacturer') {
        // If country is already available from pallet data, use it directly
        if (origin.country) {
            const originCountry = origin.country.toUpperCase();
            const destinationCountry = 'USA'; // For now, assume all destinations are USA
            
            console.log('Overseas check:', originCountry, 'to', destinationCountry);
            
            // Show overseas fields if origin is not USA
            const isOverseas = originCountry !== 'USA';
            if (currentOverseasState !== isOverseas) {
                // Only update UI if overseas state has changed
                currentOverseasState = isOverseas;
                if (isOverseas) {
                    console.log('Overseas shipment detected:', originCountry);
                    showOverseasFields();
                    loadPorts();
                    updateOverseasShipmentType(originCountry, destinationCountry);
                } else {
                    console.log('Domestic shipment detected');
                    hideOverseasFields();
                }
            }
        } else if (origin.location_id) {
            // Fallback: fetch country if not available in pallet data
            fetch('get_manufacturer_country.php?location_id=' + origin.location_id)
                .then(response => response.json())
                .then(data => {
                    const originCountry = data.country ? data.country.toUpperCase() : 'USA';
                    const destinationCountry = 'USA';
                    
                    console.log('Overseas check (fallback):', originCountry, 'to', destinationCountry);
                    
                    const isOverseas = originCountry !== 'USA';
                    if (currentOverseasState !== isOverseas) {
                        // Only update UI if overseas state has changed
                        currentOverseasState = isOverseas;
                        if (isOverseas) {
                            console.log('Overseas shipment detected (fallback):', originCountry);
                            showOverseasFields();
                            loadPorts();
                            updateOverseasShipmentType(originCountry, destinationCountry);
                        } else {
                            console.log('Domestic shipment detected (fallback)');
                            hideOverseasFields();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error checking manufacturer country:', error);
                    if (currentOverseasState !== false) {
                        currentOverseasState = false;
                        hideOverseasFields();
                    }
                });
        } else {
            console.log('No location data available for overseas check');
            if (currentOverseasState !== false) {
                currentOverseasState = false;
                hideOverseasFields();
            }
        }
    } else {
        if (currentOverseasState !== false) {
            currentOverseasState = false;
            hideOverseasFields();
        }
    }
}

function updateOverseasShipmentType(originCountry, destinationCountry) {
    // This function can be used to show different UI messages based on shipment type
    // For example: "Import from India to USA", "Export from USA to Canada", etc.
    const overseasElements = document.querySelectorAll('.overseas-shipment-info');
    overseasElements.forEach(element => {
        if (originCountry !== 'USA' && destinationCountry === 'USA') {
            element.textContent = `Import shipment from ${originCountry} to ${destinationCountry}`;
        } else if (originCountry === 'USA' && destinationCountry !== 'USA') {
            element.textContent = `Export shipment from ${originCountry} to ${destinationCountry}`;
        } else if (originCountry !== destinationCountry) {
            element.textContent = `International shipment from ${originCountry} to ${destinationCountry}`;
        }
    });
}

function showOverseasFields() {
    // Show overseas container fields instead of domestic BOL fields
    const domesticBolField = document.getElementById('domesticBolField');
    const overseasContainerField = document.getElementById('overseasContainerField');
    const overseasContainerFieldsMulti = document.getElementById('overseasContainerFieldsMulti');
    const domesticCostFields = document.getElementById('domestic-cost-fields');
    
    if (domesticBolField) domesticBolField.style.display = 'none';
    if (overseasContainerField) overseasContainerField.style.display = 'block';
    if (overseasContainerFieldsMulti) overseasContainerFieldsMulti.style.display = 'block';
    if (domesticCostFields) domesticCostFields.style.display = 'none';
    
    // Show origin port sections
    const originPortSection = document.getElementById('originPortSection');
    const originPortSectionMulti = document.getElementById('originPortSectionMulti');
    if (originPortSection) originPortSection.style.display = 'block';
    if (originPortSectionMulti) originPortSectionMulti.style.display = 'block';
    
    // Update origin labels for overseas shipments - they're already "Origin Port:"
    // No need to change labels since they're set correctly in HTML
    
    // Update destination for ports only
    updateDestinationForOverseas(true);
    
    // Hide Generate BOL checkbox for overseas shipments
    const generateBolSingle = document.querySelector('#singleShipmentSection .action-button')?.previousElementSibling;
    const generateBolMulti = document.getElementById('generate_bol_multi')?.closest('div');
    if (generateBolSingle) generateBolSingle.style.display = 'none';
    if (generateBolMulti) generateBolMulti.style.display = 'none';
    
    // Load ports
    loadPorts();
    
    // Update button and modal styling for container shipments
    const createButton = document.getElementById('openShipModalBtn');
    if (createButton) {
        createButton.innerHTML = '🚢 Create Container Shipment';
        createButton.style.backgroundColor = '#1976d2'; // Blue background
        createButton.style.borderColor = '#1976d2'; // Blue border
    }
    
    // Update modal title for container shipments with enhanced styling
    const modalTitle = document.querySelector('.shipment-details-modal-content h2.section-title');
    if (modalTitle) {
        modalTitle.innerHTML = '🚢 Create Ocean Delivery';
        modalTitle.style.color = '#1976d2'; // Blue color
        modalTitle.style.background = 'linear-gradient(135deg, #1976d2 0%, #42a5f5 100%)';
        modalTitle.style.webkitBackgroundClip = 'text';
        modalTitle.style.webkitTextFillColor = 'transparent';
        modalTitle.style.backgroundClip = 'text';
        modalTitle.style.fontSize = '1.5em';
        modalTitle.style.fontWeight = '700';
        modalTitle.style.textAlign = 'center';
        modalTitle.style.padding = '20px 0';
        modalTitle.style.borderBottom = '3px solid #1976d2';
        modalTitle.style.marginBottom = '25px';
        modalTitle.style.textShadow = '0 2px 4px rgba(25, 118, 210, 0.3)';
    }
}

function hideOverseasFields() {
    // 🚢 RESET overseas state when hiding fields
    currentOverseasState = false;
    
    // Hide overseas container fields and show domestic BOL fields
    const domesticBolField = document.getElementById('domesticBolField');
    const overseasContainerField = document.getElementById('overseasContainerField');
    const overseasContainerFieldsMulti = document.getElementById('overseasContainerFieldsMulti');
    const domesticCostFields = document.getElementById('domestic-cost-fields');
    
    if (domesticBolField) domesticBolField.style.display = 'block';
    if (overseasContainerField) overseasContainerField.style.display = 'none';
    if (overseasContainerFieldsMulti) overseasContainerFieldsMulti.style.display = 'none';
    if (domesticCostFields) domesticCostFields.style.display = 'block';
    
    // Hide origin port sections
    const originPortSection = document.getElementById('originPortSection');
    const originPortSectionMulti = document.getElementById('originPortSectionMulti');
    if (originPortSection) originPortSection.style.display = 'none';
    if (originPortSectionMulti) originPortSectionMulti.style.display = 'none';
    
    // Update origin labels back to "Origin:" for domestic shipments
    const originLabels = document.querySelectorAll('.origin-section > label');
    originLabels.forEach(label => {
        if (label) label.textContent = 'Origin:';
    });
    
    // Restore domestic destination options
    updateDestinationForOverseas(false);
    
    // Show Generate BOL checkbox for domestic shipments
    const generateBolSingle = document.querySelector('#singleShipmentSection .action-button')?.previousElementSibling;
    const generateBolMulti = document.getElementById('generate_bol_multi')?.closest('div');
    if (generateBolSingle) generateBolSingle.style.display = 'block';
    if (generateBolMulti) generateBolMulti.style.display = 'block';
    
    // Reset button and modal styling for domestic shipments
    const createButton = document.getElementById('openShipModalBtn');
    if (createButton) {
        createButton.innerHTML = 'Create Shipment';
        createButton.style.backgroundColor = ''; // Reset to default background
        createButton.style.borderColor = ''; // Reset to default border
    }
    
    // Reset modal title for domestic shipments
    const modalTitle = document.querySelector('.shipment-details-modal-content h2.section-title');
    if (modalTitle) {
        modalTitle.innerHTML = 'Create Shipment';
        modalTitle.style.color = ''; // Reset to default color
        modalTitle.style.background = '';
        modalTitle.style.webkitBackgroundClip = '';
        modalTitle.style.webkitTextFillColor = '';
        modalTitle.style.backgroundClip = '';
        modalTitle.style.fontSize = '';
        modalTitle.style.fontWeight = '';
        modalTitle.style.textAlign = '';
        modalTitle.style.padding = '';
        modalTitle.style.borderBottom = '';
        modalTitle.style.marginBottom = '';
        modalTitle.style.textShadow = '';
    }
}

function loadPorts() {
    fetch('get_ports.php')
        .then(response => response.json())
        .then(data => {
            // Origin port selects
            const originPortSingle = document.getElementById('origin_port_id');
            const originPortMulti = document.getElementById('origin_port_id_multi');
            
            [originPortSingle, originPortMulti].forEach(select => {
                if (select) {
                    // Store current selection to prevent clearing
                    const currentValue = select.value;
                    select.innerHTML = '<option value="">Select departure port...</option>';
                    
                    data.ports.forEach(port => {
                        const option = document.createElement('option');
                        option.value = port.id;
                        option.textContent = port.name + ' - ' + port.city + ', ' + port.state;
                        // Add address data for distance calculation
                        option.setAttribute('data-address', port.address || `${port.city}, ${port.state}`);
                        select.appendChild(option);
                    });
                    
                    // Restore previous selection
                    if (currentValue) select.value = currentValue;
                }
            });
            
            // Store ports data globally for destination dropdown use
            window.portsData = data.ports;
            
            // Update destination dropdowns if we're in overseas mode
            const overseasContainerField = document.getElementById('overseasContainerField');
            const isOverseas = overseasContainerField && overseasContainerField.style.display !== 'none';
            if (isOverseas) {
                updateDestinationForOverseas(true);
            }
        })
        .catch(error => {
            console.error('Error loading ports:', error);
        });
}

function updateDestinationForOverseas(isOverseas) {
    const destinationLabel = document.getElementById('destinationLabel');
    const destinationLabelMulti = document.getElementById('destinationLabelMulti');
    const domesticDestinationGroup = document.getElementById('domesticDestinationGroup');
    const domesticDestinationGroupMulti = document.getElementById('domesticDestinationGroupMulti');
    const destinationSelect = document.getElementById('destination_id');
    const destinationSelectMulti = document.getElementById('destination_id_multi');
    
    if (isOverseas) {
        // Update labels
        if (destinationLabel) destinationLabel.textContent = 'Destination Port:';
        if (destinationLabelMulti) destinationLabelMulti.textContent = 'Destination Port:';
        
        // Hide radio buttons for overseas shipments
        if (domesticDestinationGroup) domesticDestinationGroup.style.display = 'none';
        if (domesticDestinationGroupMulti) domesticDestinationGroupMulti.style.display = 'none';
        
        // Populate with ports only
        if (window.portsData) {
            [destinationSelect, destinationSelectMulti].forEach(select => {
                if (select) {
                    // Store current selection
                    const currentValue = select.value;
                    select.innerHTML = '<option value="">Select arrival port...</option>';
                    
                    window.portsData.forEach(port => {
                        const option = document.createElement('option');
                        option.value = port.id;
                        option.textContent = port.name + ' - ' + port.city + ', ' + port.state;
                        option.setAttribute('data-address', port.address || `${port.city}, ${port.state}`);
                        option.setAttribute('data-type', 'port');
                        select.appendChild(option);
                    });
                    
                    // Restore previous selection
                    if (currentValue) select.value = currentValue;
                }
            });
        }
    } else {
        // Restore domestic labels
        if (destinationLabel) destinationLabel.textContent = 'Destination:';
        if (destinationLabelMulti) destinationLabelMulti.textContent = 'Destination:';
        
        // Show radio buttons for domestic shipments
        if (domesticDestinationGroup) domesticDestinationGroup.style.display = 'block';
        if (domesticDestinationGroupMulti) domesticDestinationGroupMulti.style.display = 'block';
        
        // Restore normal dropdown functionality
        toggleDestinationSelectSingle();
        toggleDestinationSelectMulti();
    }
}

// ----------------- INTERNATIONAL DISTANCE CALCULATION -----------------
function calculateApproximateInternationalDistance(originAddress, destAddress, callback) {
    if (!window.google || !window.google.maps) {
        console.error('Google Maps API not loaded');
        callback(null, 'Google Maps API not available');
        return;
    }
    
    const geocoder = new google.maps.Geocoder();
    
    // Geocode origin address
    geocoder.geocode({ address: originAddress }, function(originResults, originStatus) {
        if (originStatus === 'OK' && originResults[0]) {
            const originLocation = originResults[0].geometry.location;
            
            // Geocode destination address
            geocoder.geocode({ address: destAddress }, function(destResults, destStatus) {
                if (destStatus === 'OK' && destResults[0]) {
                    const destLocation = destResults[0].geometry.location;
                    
                    // Calculate air distance using spherical geometry
                    const airDistanceMeters = google.maps.geometry.spherical.computeDistanceBetween(originLocation, destLocation);
                    const airDistanceMiles = Math.round(airDistanceMeters * 0.000621371); // Convert meters to miles
                    
                    console.log(`International distance: ${originAddress} to ${destAddress} = ${airDistanceMiles} miles (air distance)`);
                    callback(airDistanceMiles, null);
                } else {
                    console.error('Could not geocode destination address:', destAddress, destStatus);
                    callback(null, 'Could not find destination location');
                }
            });
        } else {
            console.error('Could not geocode origin address:', originAddress, originStatus);
            callback(null, 'Could not find origin location');
        }
    });
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

    // Check if overseas fields are visible
    const overseasContainerField = document.getElementById('overseasContainerField');
    const isOverseas = overseasContainerField && overseasContainerField.style.display !== 'none';
    
    let originAddress, destAddress;
    
    if (isOverseas) {
        // Use port addresses for overseas shipments
        const originPortSelect = document.getElementById('origin_port_id');
        originAddress = originPortSelect ? getAddressFromSelection(originPortSelect, 'port') : '';
        destAddress = getAddressFromSelection(destSelect, 'destination');
        
        if (!originAddress || !destAddress) {
            distanceDisplay.innerHTML = '<span style="color: #666;">Select both ports</span>';
            milesInput.value = '';
            return;
        }
        
        distanceDisplay.innerHTML = '<span style="color: #488C9A;">Calculating ocean route...</span>';
        
        // Use international distance calculation for overseas shipments
        calculateApproximateInternationalDistance(originAddress, destAddress, function(distance, error) {
            if (distance && distance > 0) {
                distanceDisplay.innerHTML = `<span style="color: #488C9A; font-weight: bold;">${distance} miles</span><br><span style="font-size: 0.8em; color: #666;">(Air Distance)</span>`;
                milesInput.value = distance;
            } else {
                distanceDisplay.innerHTML = '<span style="color: #f39c12;">🌊 Enter miles manually</span>';
                milesInput.value = '';
                console.error('International distance calculation failed:', error);
            }
        });
    } else {
        // Use manufacturer/warehouse address for domestic shipments
        originAddress = result.origin.address;
        destAddress = getAddressFromSelection(destSelect, 'destination');

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
    
    // Check for overseas shipment requirements whenever distance is calculated
    checkOverseasShipment(result.origin);
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

    // Check if overseas fields are visible
    const overseasContainerFieldsMulti = document.getElementById('overseasContainerFieldsMulti');
    const isOverseas = overseasContainerFieldsMulti && overseasContainerFieldsMulti.style.display !== 'none';
    
    let originAddress, destAddress;
    
    if (isOverseas) {
        // Use port addresses for overseas shipments
        const originPortSelect = document.getElementById('origin_port_id_multi');
        originAddress = originPortSelect ? getAddressFromSelection(originPortSelect, 'port') : '';
        destAddress = getAddressFromSelection(destSelect, 'destination');
        
        if (!originAddress || !destAddress) {
            distanceDisplay.innerHTML = '<span style="color: #666;">Select both ports</span>';
            milesInput.value = '';
            return;
        }
        
        distanceDisplay.innerHTML = '<span style="color: #488C9A;">Calculating ocean route...</span>';
        
        // Use international distance calculation for overseas shipments
        calculateApproximateInternationalDistance(originAddress, destAddress, function(distance, error) {
            if (distance && distance > 0) {
                distanceDisplay.innerHTML = `<span style="color: #488C9A; font-weight: bold;">${distance} miles</span><br><span style="font-size: 0.8em; color: #666;">(Air Distance)</span>`;
                milesInput.value = distance;
            } else {
                distanceDisplay.innerHTML = '<span style="color: #f39c12;">🌊 Enter miles manually</span>';
                milesInput.value = '';
                console.error('International distance calculation failed:', error);
            }
        });
    } else {
        // Use manufacturer/warehouse address for domestic shipments
        originAddress = result.origin.address;
        destAddress = getAddressFromSelection(destSelect, 'destination');

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
    
    // Check for overseas shipment requirements whenever distance is calculated
    checkOverseasShipment(result.origin);
}

// ----------------- BOL DUPLICATE WARNING SYSTEM -----------------
let pendingSubmission = null; // Store the pending submission details

function checkBolDuplicates(bolNumber, originType, originId, destinationType, destinationId, callback) {
    if (!bolNumber || !bolNumber.trim()) {
        callback(false);
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'check_bol');
    formData.append('bol_number', bolNumber.trim());
    formData.append('origin_type', originType);
    formData.append('origin_id', originId);
    formData.append('destination_type', destinationType);
    formData.append('destination_id', destinationId);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.exists && data.has_conflict) {
            showBolWarning(bolNumber, data.conflicts);
            callback(true); // Has conflict
        } else {
            callback(false); // No conflict
        }
    })
    .catch(error => {
        console.error('Error checking BOL:', error);
        callback(false); // On error, allow to proceed
    });
}

function showBolWarning(bolNumber, conflicts) {
    const modal = document.getElementById('bolWarningModal');
    const content = document.getElementById('bolWarningContent');
    
    let conflictText = `<p>The BOL number "<strong>${bolNumber}</strong>" is already being used for:</p><ul style="margin: 15px 0; padding-left: 25px;">`;
    
    conflicts.forEach(conflict => {
        const plural = conflict.delivery_count > 1 ? 'deliveries' : 'delivery';
        let listItem = `<li>${conflict.destination_type}: <strong>${conflict.destination_name}</strong> (${conflict.delivery_count} ${plural})`;
        
        // Add time information if it's the same destination but created at different times
        if (conflict.is_same_destination && conflict.time_ago) {
            listItem += ` <em style="color: #d32f2f;">- Created ${conflict.time_ago}</em>`;
        }
        
        listItem += `</li>`;
        conflictText += listItem;
    });
    
    conflictText += `</ul>`;
    
    // Customize the warning message based on conflict type
    const hasSameDestinationConflict = conflicts.some(c => c.is_same_destination);
    if (hasSameDestinationConflict) {
        conflictText += `<p><strong>Warning:</strong> This BOL number was used for the same destination but created at a different time. This may be an accidental duplicate. Are you sure you want to proceed?</p>`;
    } else {
        conflictText += `<p><strong>Warning:</strong> Using the same BOL number for different destinations may cause confusion. Are you sure you want to proceed?</p>`;
    }
    
    content.innerHTML = conflictText;
    modal.style.display = 'block';
}

function closeBolWarningModal() {
    const modal = document.getElementById('bolWarningModal');
    modal.style.display = 'none';
    pendingSubmission = null;
}

function proceedWithDuplicateBol() {
    closeBolWarningModal();
    if (pendingSubmission) {
        pendingSubmission(); // Execute the pending submission
        pendingSubmission = null;
    }
}

// Disable clicking outside to close BOL warning modal for admin/global_admin users
// (They need to click the X button or the Cancel/Proceed buttons to close)
// window.addEventListener('click', function(e) {
//     const modal = document.getElementById('bolWarningModal');
//     if (e.target === modal) {
//         closeBolWarningModal();
//     }
// });

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
        const departure = document.getElementById('departure_date').value;
        const arrival = document.getElementById('est_arrival_date').value;
        const freightCost = document.getElementById('freight_cost').value;
        const customerCost = document.getElementById('customer_cost').value;
        const miles = document.getElementById('miles').value;

        // Check if overseas fields are visible
        const overseasContainerField = document.getElementById('overseasContainerField');
        const isOverseas = overseasContainerField && overseasContainerField.style.display !== 'none';
        
        let bol, containerNumber, originPortId, masterBol, houseBol;
        
        if (isOverseas) {
            // Overseas shipment validation
            containerNumber = document.getElementById('container_number').value;
            originPortId = document.getElementById('origin_port_id').value;
            masterBol = document.getElementById('master_bol').value;
            houseBol = document.getElementById('house_bol').value;
            bol = containerNumber; // Use container number as BOL for database compatibility
            
            if (!containerNumber || containerNumber.trim() === '') {
                alert('Container Number is required for overseas shipments.');
                return;
            }
            if (!originPortId) {
                alert('Origin Port is required for overseas shipments.');
                return;
            }
        } else {
            // Domestic shipment validation
            bol = document.getElementById('bol_number').value;
            
            if (!bol || bol.trim() === '') {
                alert('BOL Number is required for domestic shipments.');
                return;
            }
        }

        if (!originId) {
            alert('Please select pallets to determine origin location.');
            return;
        }
        if (!destinationId) {
            alert('Please select a destination location.');
            return;
        }
        
        // Validate required date fields
        if (!departure || departure.trim() === '') {
            alert('Departure Date is required.');
            return;
        }
        if (!arrival || arrival.trim() === '') {
            alert('Est. Arrival Date is required.');
            return;
        }

        // Check if origin and destination are the same
        if (originType === destinationType && originId === destinationId) {
            alert('Origin and destination cannot be the same location.');
            return;
        }

        // Check if Generate BOL checkbox is checked
        const generateBol = document.getElementById('generate_bol_single').checked;

        // Function to actually submit the form
        const submitForm = () => {
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
            
            // Add overseas shipment fields if this is an overseas shipment
            if (isOverseas) {
                setOrCreateHidden(mainForm, 'container_number', containerNumber);
                setOrCreateHidden(mainForm, 'origin_port_id', originPortId);  // Departure port
                setOrCreateHidden(mainForm, 'port_of_entry_id', originPortId); // For validation (kept for compatibility)
                setOrCreateHidden(mainForm, 'master_bol', masterBol);
                setOrCreateHidden(mainForm, 'house_bol', houseBol);
            }

            mainForm.submit();
        };

        // Check for BOL duplicates before submitting
        checkBolDuplicates(bol, originType, originId, destinationType, destinationId, (hasConflict) => {
            if (hasConflict) {
                // Store the submission function to execute if user confirms
                pendingSubmission = submitForm;
                // Modal is already shown by checkBolDuplicates
            } else {
                // No conflict, proceed normally
                submitForm();
            }
        });
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
        
        // Validate required date fields
        if (!departure || departure.trim() === '') {
            alert('Departure Date is required.');
            return;
        }
        if (!arrival || arrival.trim() === '') {
            alert('Est. Arrival Date is required.');
            return;
        }

        // Check if overseas fields are visible
        const overseasContainerFieldsMulti = document.getElementById('overseasContainerFieldsMulti');
        const isOverseas = overseasContainerFieldsMulti && overseasContainerFieldsMulti.style.display !== 'none';
        
        let bolNumbers = [];
        let containerNumbers = [];
        let originPortId, masterBol, houseBol;
        
        if (isOverseas) {
            // Validate container numbers for overseas shipments
            const containerFields = document.querySelectorAll('#bolFieldsGrid input[name^="container_number_"]');
            let missingContainer = false;
            
            containerFields.forEach((field, index) => {
                const containerValue = field.value.trim();
                if (!containerValue) {
                    missingContainer = true;
                    return;
                }
                containerNumbers.push(containerValue);
                bolNumbers.push(containerValue); // Use container numbers as BOL for database compatibility
            });
            
            if (missingContainer) {
                alert('All Container Numbers are required for overseas shipments.');
                return;
            }
            
            // Validate overseas-specific fields
            originPortId = document.getElementById('origin_port_id_multi').value;
            masterBol = document.getElementById('master_bol_multi').value;
            houseBol = document.getElementById('house_bol_multi').value;
            
            if (!originPortId) {
                alert('Origin Port is required for overseas shipments.');
                return;
            }
        } else {
            // Validate BOL numbers for domestic shipments
            const bolFields = document.querySelectorAll('#bolFieldsGrid input[name^="bol_number_"]');
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
                alert('All BOL Numbers are required for domestic shipments.');
                return;
            }
        }

        // Check if origin and destination are the same
        if (originType === destinationType && originId === destinationId) {
            alert('Origin and destination cannot be the same location.');
            return;
        }

        // Check if Generate BOL checkbox is checked
        const generateBol = document.getElementById('generate_bol_multi').checked;

        // Function to actually submit the form
        const submitForm = () => {
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
            
            // Add overseas shipment fields if this is an overseas shipment
            if (isOverseas) {
                setOrCreateHidden(mainForm, 'container_numbers', JSON.stringify(containerNumbers));
                setOrCreateHidden(mainForm, 'origin_port_id', originPortId);  // Departure port
                setOrCreateHidden(mainForm, 'port_of_entry_id', originPortId); // For validation (kept for compatibility)
                setOrCreateHidden(mainForm, 'master_bol', masterBol);
                setOrCreateHidden(mainForm, 'house_bol', houseBol);
            }

            mainForm.submit();
        };

        // Check each BOL number for duplicates
        let bolChecksCompleted = 0;
        let hasAnyConflict = false;
        const totalBolNumbers = bolNumbers.length;

        if (totalBolNumbers === 0) {
            submitForm();
            return;
        }

        bolNumbers.forEach((bolNum, index) => {
            checkBolDuplicates(bolNum, originType, originId, destinationType, destinationId, (hasConflict) => {
                if (hasConflict) {
                    hasAnyConflict = true;
                }
                
                bolChecksCompleted++;
                
                // If this is the last BOL to check
                if (bolChecksCompleted === totalBolNumbers) {
                    if (hasAnyConflict) {
                        // Store the submission function to execute if user confirms
                        pendingSubmission = submitForm;
                        // Modal is already shown by the first conflict found
                    } else {
                        // No conflicts found, proceed normally
                        submitForm();
                    }
                }
            });
        });
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
    const bolFieldsLabel = document.querySelector('#bolFieldsContainer label');
    if (!bolFieldsGrid) return;
    
    // Check if overseas fields are visible
    const overseasContainerFieldsMulti = document.getElementById('overseasContainerFieldsMulti');
    const isOverseas = overseasContainerFieldsMulti && overseasContainerFieldsMulti.style.display !== 'none';
    
    // Update the label based on shipment type
    if (bolFieldsLabel) {
        bolFieldsLabel.textContent = isOverseas ? 'Container Numbers:' : 'BOL Numbers:';
    }
    
    // Clear existing fields
    bolFieldsGrid.innerHTML = '';
    
    // Create fields for each delivery
    for (let i = 1; i <= numDeliveries; i++) {
        const fieldDiv = document.createElement('div');
        fieldDiv.style.cssText = 'display: flex; flex-direction: column;';
        
        const label = document.createElement('label');
        if (isOverseas) {
            label.textContent = `Container ${i}:`;
        } else {
            label.textContent = `Truck ${i} BOL:`;
        }
        label.style.cssText = 'font-weight: 500; margin-bottom: 5px; font-size: 0.9em;';
        
        const input = document.createElement('input');
        input.type = 'text';
        input.required = true;
        input.style.cssText = 'padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em;';
        
        if (isOverseas) {
            input.name = `container_number_${i}`;
            input.id = `container_number_${i}`;
            input.placeholder = `Container ${i} (e.g. MSKU${7073334 + i})`;
        } else {
            input.name = `bol_number_${i}`;
            input.id = `bol_number_${i}`;
            input.placeholder = `BOL for truck ${i}`;
        }
        
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
    
    // Add event listeners for port selections to trigger distance recalculation
    const originPortSingle = document.getElementById('origin_port_id');
    const originPortMulti = document.getElementById('origin_port_id_multi');
    
    if (originPortSingle) {
        originPortSingle.addEventListener('change', function() {
            if (this.value) {
                calculateDistance();
            }
        });
    }
    
    if (originPortMulti) {
        originPortMulti.addEventListener('change', function() {
            if (this.value) {
                calculateDistanceMulti();
            }
        });
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
    
    // Check if we're coming from project_overview.php (has project_id parameter)
    const isFromProjectOverview = projectFromUrl && projectFromUrl !== '0';
    
    if (isFromProjectOverview) {
        // Coming from project_overview.php - start with clean slate
        // Clear search and non-URL filters to defaults
        document.getElementById('palletSearch').value = '';
        document.getElementById('wattageFilter').value = '';
        document.getElementById('itemsPerPage').value = '100'; // Default to 100
        itemsPerPage = 100;
        currentPage = 1;
        
        // Don't clear projectFilter and statusFilter - they should already be set by PHP
        // Apply only the URL parameters that might not be set by PHP
        if (statusFromUrl) {
            document.getElementById('statusFilter').value = statusFromUrl;
        }
        
        // Project filter should already be correctly set by PHP via the selected attribute
        // No need to manipulate it further
    } else {
        // Not from project_overview.php - use localStorage as before
        const search = localStorage.getItem('createShipment_palletSearch');
        const project = localStorage.getItem('createShipment_projectFilter');
        const wattage = localStorage.getItem('createShipment_wattageFilter');
        const status = localStorage.getItem('createShipment_statusFilter');
        const perPage = localStorage.getItem('createShipment_itemsPerPage');
        const page = localStorage.getItem('createShipment_currentPage');
        
        if (search) document.getElementById('palletSearch').value = search;
        if (project) document.getElementById('projectFilter').value = project;
        if (wattage) document.getElementById('wattageFilter').value = wattage;
        if (status) document.getElementById('statusFilter').value = status;
        
        if (perPage) {
            document.getElementById('itemsPerPage').value = perPage;
            itemsPerPage = parseInt(perPage);
        } else {
            // Default to 100 if no saved preference
            document.getElementById('itemsPerPage').value = '100';
            itemsPerPage = 100;
        }
        if (page) currentPage = parseInt(page);
    }
    
    // Apply filters after loading
    filterPallets();
}




</script>
</body>
</html> 
