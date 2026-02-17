<?php
session_name("logistics_session");
session_start();

// Allow users to view pallets; only admin/customer_admin/global_admin can create shipments.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin', 'user'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
require_once 'delivery_notification_helpers.php';
require_once 'milestone_helpers.php';
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
$is_customer_admin = ($role === 'customer_admin');
$is_standard_user = ($role === 'user');
$can_manage_shipments = in_array($role, ['admin', 'global_admin', 'customer_admin'], true);

if (!$is_global_admin) {
    if (in_array($role, ['admin', 'customer_admin'], true)) {
        $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1";
        $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
    } else {
        $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1";
        $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
    }

    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $user_id);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($account_id_for_admin);
        $stmtAdminAcc->fetch();
        $stmtAdminAcc->close();
    }

    if (!$account_id_for_admin) {
        header("Location: unauthorized.php");
        exit();
    }
}

// --- Handle Bulk Manufacturer Pallet ID Linking ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_link_manufacturer_ids') {
    header('Content-Type: application/json');
    if (!$can_manage_shipments) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    try {
        $linksRaw = $_POST['links_json'] ?? '[]';
        $linksPayload = json_decode($linksRaw, true);
        if (!is_array($linksPayload)) {
            throw new Exception('Invalid link payload.');
        }

        $linksByPallet = [];
        foreach ($linksPayload as $row) {
            $palletId = (int)($row['pallet_id'] ?? 0);
            if ($palletId <= 0) {
                continue;
            }
            $manufacturerIdRaw = trim((string)($row['manufacturer_pallet_id'] ?? ''));
            if ($manufacturerIdRaw !== '' && strlen($manufacturerIdRaw) > 100) {
                throw new Exception("Manufacturer pallet ID must be 100 characters or fewer (pallet {$palletId}).");
            }
            $linksByPallet[$palletId] = ($manufacturerIdRaw === '') ? null : $manufacturerIdRaw;
        }

        if (empty($linksByPallet)) {
            throw new Exception('No pallets were provided for linking.');
        }

        $palletIds = array_values(array_keys($linksByPallet));
        $placeholders = implode(',', array_fill(0, count($palletIds), '?'));
        $types = str_repeat('i', count($palletIds));

        $sqlScope = "
            SELECT
                ip.id,
                ip.assigned_project_id,
                ip.manufacturer_pallet_id
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
            WHERE ip.id IN ($placeholders)
        ";
        if (!$is_global_admin) {
            $sqlScope .= " AND (p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?)";
            $types .= 'iii';
        }

        $stmtScope = $conn->prepare($sqlScope);
        if (!$stmtScope) {
            throw new Exception('Failed to validate selected pallets.');
        }

        $params = $palletIds;
        if (!$is_global_admin) {
            $params[] = (int)$account_id_for_admin;
            $params[] = (int)$account_id_for_admin;
            $params[] = (int)$account_id_for_admin;
        }
        $stmtScope->bind_param($types, ...$params);
        $stmtScope->execute();
        $scopeResult = $stmtScope->get_result();

        $scopedRows = [];
        while ($row = $scopeResult->fetch_assoc()) {
            $scopedRows[(int)$row['id']] = $row;
        }
        $stmtScope->close();

        $missing = [];
        foreach ($palletIds as $pid) {
            if (!isset($scopedRows[$pid])) {
                $missing[] = $pid;
            }
        }
        if (!empty($missing)) {
            throw new Exception('Some selected pallets are unavailable or out of scope: ' . implode(', ', $missing));
        }

        $seenInPayload = [];
        foreach ($palletIds as $pid) {
            $newManufacturerId = $linksByPallet[$pid];
            if ($newManufacturerId === null) {
                continue;
            }
            $assignedProjectId = isset($scopedRows[$pid]['assigned_project_id']) && $scopedRows[$pid]['assigned_project_id'] !== null
                ? (int)$scopedRows[$pid]['assigned_project_id']
                : 0;
            $key = $assignedProjectId . '|' . strtolower($newManufacturerId);
            if (isset($seenInPayload[$key])) {
                throw new Exception('Duplicate manufacturer pallet ID "' . $newManufacturerId . '" in the same project scope.');
            }
            $seenInPayload[$key] = $pid;
        }

        $stmtDupWithProject = $conn->prepare("
            SELECT id
            FROM inventory_pallets
            WHERE manufacturer_pallet_id = ? AND assigned_project_id = ? AND id <> ?
            LIMIT 1
        ");
        if (!$stmtDupWithProject) {
            throw new Exception('Failed preparing duplicate check (project scope).');
        }

        $stmtDupWithoutProject = $conn->prepare("
            SELECT id
            FROM inventory_pallets
            WHERE manufacturer_pallet_id = ? AND assigned_project_id IS NULL AND id <> ?
            LIMIT 1
        ");
        if (!$stmtDupWithoutProject) {
            $stmtDupWithProject->close();
            throw new Exception('Failed preparing duplicate check (unassigned scope).');
        }

        foreach ($palletIds as $pid) {
            $newManufacturerId = $linksByPallet[$pid];
            if ($newManufacturerId === null) {
                continue;
            }

            $assignedProjectId = isset($scopedRows[$pid]['assigned_project_id']) && $scopedRows[$pid]['assigned_project_id'] !== null
                ? (int)$scopedRows[$pid]['assigned_project_id']
                : 0;

            if ($assignedProjectId > 0) {
                $stmtDupWithProject->bind_param('sii', $newManufacturerId, $assignedProjectId, $pid);
                $stmtDupWithProject->execute();
                $dupRow = $stmtDupWithProject->get_result()->fetch_assoc();
                if ($dupRow) {
                    throw new Exception('Manufacturer pallet ID "' . $newManufacturerId . '" already exists in this project scope.');
                }
            } else {
                $stmtDupWithoutProject->bind_param('si', $newManufacturerId, $pid);
                $stmtDupWithoutProject->execute();
                $dupRow = $stmtDupWithoutProject->get_result()->fetch_assoc();
                if ($dupRow) {
                    throw new Exception('Manufacturer pallet ID "' . $newManufacturerId . '" already exists in unassigned scope.');
                }
            }
        }

        $stmtDupWithProject->close();
        $stmtDupWithoutProject->close();

        $conn->begin_transaction();
        $stmtUpdate = $conn->prepare("UPDATE inventory_pallets SET manufacturer_pallet_id = ? WHERE id = ?");
        if (!$stmtUpdate) {
            throw new Exception('Failed preparing pallet update statement.');
        }

        $updatedCount = 0;
        $linkedCount = 0;
        $clearedCount = 0;
        foreach ($palletIds as $pid) {
            $newManufacturerId = $linksByPallet[$pid];
            $currentRaw = $scopedRows[$pid]['manufacturer_pallet_id'] ?? null;
            $currentValue = ($currentRaw === null || trim((string)$currentRaw) === '') ? null : trim((string)$currentRaw);

            if ($currentValue === $newManufacturerId) {
                continue;
            }

            $stmtUpdate->bind_param('si', $newManufacturerId, $pid);
            if (!$stmtUpdate->execute()) {
                throw new Exception('Failed to update pallet #' . $pid . ': ' . $stmtUpdate->error);
            }

            $updatedCount++;
            if ($newManufacturerId === null) {
                $clearedCount++;
            } else {
                $linkedCount++;
            }
        }
        $stmtUpdate->close();
        $conn->commit();

        echo json_encode([
            'success' => true,
            'updated_count' => $updatedCount,
            'linked_count' => $linkedCount,
            'cleared_count' => $clearedCount
        ]);
    } catch (Exception $e) {
        if ($conn) {
            $conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// --- Handle BOL Check Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_bol') {
    header('Content-Type: application/json');
    if (!$can_manage_shipments) {
        echo json_encode(['exists' => false, 'error' => 'Unauthorized']);
        exit();
    }
    
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
    if (!$can_manage_shipments) {
        $_SESSION['create_shipment_message'] = 'You are not authorized to create shipments.';
        $redirect_url = 'create_shipment.php';
        if ($project_id_from_url > 0) { $redirect_url .= '?project_id=' . $project_id_from_url; }
        header('Location: ' . $redirect_url);
        exit();
    }
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
            $stmt_mfg = $conn->prepare("SELECT id FROM manufacturers WHERE (name = ? OR short_name = ?) LIMIT 1");
            if ($stmt_mfg) {
                $stmt_mfg->bind_param("ss", $manufacturer_name, $manufacturer_name);
                $stmt_mfg->execute();
                $stmt_mfg->bind_result($manufacturer_id);
                if ($stmt_mfg->fetch()) {
                    $stmt_mfg->close();
                    
                    // If vendor name contains location info (like "Meyer Burger - Raleigh Plant"), find specific location
                    if (strpos($current_batch_vendor_name, ' - ') !== false) {
                        $location_part = trim(explode(' - ', $current_batch_vendor_name)[1]);
                        $stmt_loc = $conn->prepare("SELECT ml.id FROM manufacturer_locations ml JOIN manufacturers m ON m.id = ml.manufacturer_id WHERE ml.manufacturer_id = ? AND ml.location_name LIKE ? AND ml.is_active = 1 LIMIT 1");
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
                        $stmt_primary = $conn->prepare("SELECT ml.id FROM manufacturer_locations ml JOIN manufacturers m ON m.id = ml.manufacturer_id WHERE ml.manufacturer_id = ? AND ml.is_primary = 1 AND ml.is_active = 1 LIMIT 1");
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
    if (!$can_manage_shipments) {
        $_SESSION['create_shipment_message'] = 'You are not authorized to delete pallets.';
        $redirect_url = 'create_shipment.php';
        if ($project_id_from_url > 0) { $redirect_url .= '?project_id=' . $project_id_from_url; }
        header('Location: ' . $redirect_url);
        exit();
    }
    $deleteMessage = '';
    $conn->begin_transaction();
    try {
        $palletIds = $_POST['selected_pallets'] ?? [];
        if (empty($palletIds)) {
            throw new Exception('No pallets selected for deletion.');
        }

        $placeholders = implode(',', array_fill(0, count($palletIds), '?'));
        $types = str_repeat('i', count($palletIds));

        // Ensure pallets are not linked downstream (deliveries/warranty)
        $stmtCheck = $conn->prepare("SELECT inventory_pallet_id FROM delivery_pallets WHERE inventory_pallet_id IN ($placeholders)");
        if (!$stmtCheck) { throw new Exception('Failed to prepare delivery check.'); }
        $stmtCheck->bind_param($types, ...$palletIds);
        $stmtCheck->execute();
        $res = $stmtCheck->get_result();
        $linked = [];
        while ($row = $res->fetch_assoc()) { $linked[] = $row['inventory_pallet_id']; }
        $stmtCheck->close();

        $stmtCheckWarranty = $conn->prepare("SELECT pallet_id FROM warranty_claim_replacements WHERE pallet_id IN ($placeholders)");
        if (!$stmtCheckWarranty) { throw new Exception('Failed to prepare warranty check.'); }
        $stmtCheckWarranty->bind_param($types, ...$palletIds);
        $stmtCheckWarranty->execute();
        $resWarranty = $stmtCheckWarranty->get_result();
        while ($row = $resWarranty->fetch_assoc()) { $linked[] = $row['pallet_id']; }
        $stmtCheckWarranty->close();

        if (!empty($linked)) {
            $linked = array_values(array_unique(array_map('intval', $linked)));
            throw new Exception('Cannot delete pallets linked downstream (delivery/warranty). Pallet IDs: ' . implode(', ', $linked));
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
            $account_clause = $is_global_admin ? '' : ' AND account_id = ?';
            $stmt_port_check = $conn->prepare("SELECT is_port FROM warehouses WHERE id = ? AND is_port = 1$account_clause LIMIT 1");
            if ($stmt_port_check) {
                if ($is_global_admin) {
                    $stmt_port_check->bind_param("i", $port_of_entry_id);
                } else {
                    $stmt_port_check->bind_param("ii", $port_of_entry_id, $account_id_for_admin);
                }
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
        $customerCost = null;
        if (isset($_POST['customer_cost']) && $_POST['customer_cost'] !== '') {
            $customerCost = (float)$_POST['customer_cost'];
        }
        if ($customerCost === null) {
            $customerCost = $freightCost;
        }
        if ($freightCost <= 0 && $customerCost > 0) {
            $freightCost = $customerCost;
        }
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
                   ip.status, ip.arrival_date,
                   COALESCE(
                       CASE
                           WHEN ip.status = 'In Warehouse' THEN MAX(d.warehouse_arrival_date)
                           WHEN ip.status = 'Delivered to Project' THEN MAX(d.actual_delivery_date)
                           ELSE NULL
                       END,
                       ip.arrival_date,
                       MAX(d.warehouse_arrival_date),
                       MAX(d.actual_delivery_date)
                   ) AS prior_arrival_date,
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
            LEFT JOIN delivery_pallets dp ON dp.inventory_pallet_id = ip.id
            LEFT JOIN deliveries d ON d.id = dp.delivery_id
            WHERE ip.id IN ($placeholders)
            GROUP BY
                ip.id, ip.wattage, ip.quantity, ip.manufacturer_location_id, ip.assigned_project_id,
                ip.status, ip.arrival_date, ip.manufacturer, m.vendor_name, ml.country
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

        $statusesRequiringPriorArrival = ['In Warehouse', 'Delivered to Project'];
        $latestPriorArrivalDate = null;
        $missingPriorArrivalPalletIds = [];
        foreach ($allPallets as $pallet) {
            $palletStatus = trim((string)($pallet['status'] ?? ''));
            if (!in_array($palletStatus, $statusesRequiringPriorArrival, true)) {
                continue;
            }

            $arrivalDateRawFull = trim((string)($pallet['prior_arrival_date'] ?? $pallet['arrival_date'] ?? ''));
            $arrivalDateRaw = substr($arrivalDateRawFull, 0, 10);
            if ($arrivalDateRaw === '' || $arrivalDateRaw === '0000-00-00' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $arrivalDateRaw)) {
                $missingPriorArrivalPalletIds[] = (int)($pallet['id'] ?? 0);
                continue;
            }

            if ($latestPriorArrivalDate === null || $arrivalDateRaw > $latestPriorArrivalDate) {
                $latestPriorArrivalDate = $arrivalDateRaw;
            }
        }

        if (!empty($missingPriorArrivalPalletIds)) {
            $missingPriorArrivalPalletIds = array_values(array_filter(array_unique($missingPriorArrivalPalletIds)));
            throw new Exception('Selected pallets are missing prior arrival dates: ' . implode(', ', $missingPriorArrivalPalletIds));
        }

        if ($latestPriorArrivalDate !== null && $departureDate < $latestPriorArrivalDate) {
            throw new Exception('Departure date cannot be earlier than the latest prior arrival date (' . $latestPriorArrivalDate . ') for selected pallets.');
        }

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

                // Trigger milestones now that pallets are linked
                trigger_delivery_milestones_for_status($deliveryId, $statusOfDelivery, $conn, $user_id);
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
// Pallets are now loaded via AJAX - skip the heavy PHP query
$total_pallets_count = 0;
$available_to_ship_count = 0;
$skip_pallet_loading = true; // Flag to skip old pallet loading code

// Apply project filter at SQL level if provided
$project_filter_sql = '';
$project_filter_param = null;
if ($project_id_from_url > 0) {
    $project_filter_sql = " AND (p_current.id = ? OR p_assigned.id = ?)";
    $project_filter_param = $project_id_from_url;
}

try {
    if (!$skip_pallet_loading) {
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
    
    if (!$is_global_admin && $account_id_for_admin) {
        $sql .= " WHERE (p_current.account_id = ? OR p_assigned.account_id = ?) AND ip.status IN ($status_placeholders)";
        if ($project_filter_sql) {
            $sql .= $project_filter_sql;
        }
        if (!empty($pallet_ids_filter)) {
            $sql .= " AND ip.id IN (" . implode(',', array_fill(0, count($pallet_ids_filter), '?')) . ")";
        }
    } else {
        $sql .= " WHERE ip.status IN ($status_placeholders)";
        if ($project_filter_sql) {
            $sql .= $project_filter_sql;
        }
        if (!empty($pallet_ids_filter)) {
            $sql .= " AND ip.id IN (" . implode(',', array_fill(0, count($pallet_ids_filter), '?')) . ")";
        }
    }
    
    $sql .= " GROUP BY ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status, ip.arrival_date, ip.unassigned_module_item_id, ip.current_warehouse_id, ip.current_project_id, ip.assigned_project_id, ip.manufacturer_location_id, m.vendor_name, m.pallets_per_truck, ml.street_address, ml.city, ml.state, ml.zip_code, ml.country, ml.location_name, mfg.name, w.name, w.street_address, w.city, w.state, w.zip_code, p_current.project_name, p_current.account_id, p_current.street_address, p_current.city, p_current.state, p_current.zip_code, p_assigned.project_name, p_assigned.account_id
              ORDER BY ip.id ASC LIMIT 1000";
    
    // Also compute accurate global counts for header/pagination
    $total_pallets_count = 0;
    $available_to_ship_count = 0;

    if (!$is_global_admin && $account_id_for_admin) {
        $stmt = $conn->prepare($sql);
        $params = array_merge([$account_id_for_admin, $account_id_for_admin], $allowed_statuses);
        $types = 'ii' . str_repeat('s', count($allowed_statuses));
        
        // Add project filter params if present
        if ($project_filter_param) {
            $params[] = $project_filter_param;
            $params[] = $project_filter_param;
            $types .= 'ii';
        }
        
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

        $count_where = " WHERE (p_current.account_id = ? OR p_assigned.account_id = ?) AND ip.status IN (" . $status_placeholders . ")";
        $count_params = [$account_id_for_admin, $account_id_for_admin];
        $count_types = 'ii' . str_repeat('s', count($allowed_statuses));
        $count_params = array_merge($count_params, $allowed_statuses);

        // Add project filter if present
        if ($project_filter_sql) {
            $count_where .= $project_filter_sql;
            $count_params[] = $project_filter_param;
            $count_params[] = $project_filter_param;
            $count_types .= 'ii';
        }

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
        
        // Add project filter params if present
        if ($project_filter_param) {
            $params[] = $project_filter_param;
            $params[] = $project_filter_param;
            $types .= 'ii';
        }
        
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
        
        // Add project filter if present
        if ($project_filter_sql) {
            $count_where .= $project_filter_sql;
            $count_params[] = $project_filter_param;
            $count_params[] = $project_filter_param;
            $count_types .= 'ii';
        }
        
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
    } // End of if (!$skip_pallet_loading)

    // Get all projects for the filter dropdown
    $all_projects_for_filter = [];
    if ($is_global_admin) {
        $stmtPFilter = $conn->prepare("SELECT id, project_name FROM projects WHERE (status IS NULL OR status = 'active') ORDER BY project_name ASC");
        if ($stmtPFilter) {
            $stmtPFilter->execute();
            $resultPFilter = $stmtPFilter->get_result();
            while ($proj = $resultPFilter->fetch_assoc()) {
                $all_projects_for_filter[] = $proj;
            }
            $stmtPFilter->close();
        }
    } else if ($account_id_for_admin) {
        $stmtPFilter = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? AND (status IS NULL OR status = 'active') ORDER BY project_name ASC");
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
        $stmtP = $conn->prepare("SELECT id, project_name, street_address, city, state, zip_code FROM projects WHERE (status IS NULL OR status = 'active') ORDER BY project_name ASC");
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
        $stmtP = $conn->prepare("SELECT id, project_name, street_address, city, state, zip_code FROM projects WHERE account_id = ? AND (status IS NULL OR status = 'active') ORDER BY project_name ASC");
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
    $account_clause = $is_global_admin ? '' : ' AND account_id = ?';
    $stmtW = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM warehouses WHERE (is_port = 0 OR is_port IS NULL)$account_clause ORDER BY name ASC");
    if ($stmtW) {
        if (!$is_global_admin) {
            $stmtW->bind_param("i", $account_id_for_admin);
        }
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
            gap: 16px;
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

        .header-right-stats {
            margin-left: auto;
            display: flex;
            align-items: flex-start;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .header-stat-card {
            background: #ffffff;
            border: 1px solid rgba(72, 140, 154, 0.14);
            border-radius: 14px;
            padding: 10px 14px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
            min-width: 145px;
            min-height: 96px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
        }

        .header-stat-label {
            margin: 0 0 6px 0;
            color: #5b6b76;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .header-stat-value {
            margin: 0;
            color: #1f2f3a;
            font-size: 1.55rem;
            font-weight: 700;
            line-height: 1.1;
        }

        .status-mix-card {
            background: #ffffff;
            border: 1px solid rgba(72, 140, 154, 0.14);
            border-radius: 14px;
            padding: 8px 10px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
            min-width: 255px;
            max-width: 292px;
            min-height: 96px;
        }

        .status-mix-trigger {
            cursor: pointer;
            text-align: left;
            font-family: inherit;
            color: inherit;
            appearance: none;
            -webkit-appearance: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .status-mix-trigger:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(72, 140, 154, 0.16);
        }

        .status-mix-trigger:focus-visible {
            outline: 2px solid #1d4ed8;
            outline-offset: 2px;
        }

        .status-mix-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 6px;
        }

        .status-mix-title {
            margin: 0;
            color: #2d4554;
            font-size: 0.88rem;
            font-weight: 700;
        }

        .status-mix-subtitle {
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .status-mix-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-mix-donut {
            --donut-bg: #e2e8f0;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: var(--donut-bg);
            position: relative;
            flex-shrink: 0;
        }

        .status-mix-donut::after {
            content: '';
            position: absolute;
            inset: 9px;
            border-radius: 50%;
            background: #fff;
            box-shadow: inset 0 0 0 1px rgba(72, 140, 154, 0.14);
        }

        .status-mix-donut-center {
            position: absolute;
            inset: 0;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1f2f3a;
            font-size: 0.7rem;
            font-weight: 700;
            text-align: center;
            line-height: 1.1;
        }

        .status-mix-legend {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 4px;
            width: 100%;
            min-width: 0;
        }

        .status-mix-legend-item {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-label {
            color: #334155;
            font-size: 0.74rem;
            font-weight: 600;
            min-width: 0;
        }

        .status-value {
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-mix-empty {
            margin: 0;
            color: #8a99a7;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-breakdown-modal-content {
            max-width: 760px;
            border-radius: 18px;
            overflow: hidden;
        }

        .status-breakdown-modal-content .shipment-details-modal-content h2 {
            padding: 18px 28px;
            font-size: 1.35em;
            background: linear-gradient(135deg, #4f90a0 0%, #3a6e7f 100%);
        }

        .status-breakdown-body {
            padding: 18px 22px 20px;
            background: linear-gradient(180deg, #f8fbfd 0%, #ffffff 100%);
        }

        .status-breakdown-summary {
            margin: 0 0 14px 0;
            color: #64748b;
            font-size: 0.92em;
            font-weight: 600;
        }

        .status-breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
        }

        .status-breakdown-table th {
            background: #e9f2f5;
            color: #334155;
            font-weight: 700;
            font-size: 0.85em;
            padding: 11px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .status-breakdown-table td {
            padding: 11px 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9em;
        }

        .status-breakdown-table tbody tr:nth-child(even) td {
            background: #fbfdff;
        }

        .status-breakdown-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #334155;
            font-weight: 600;
        }

        .status-breakdown-num {
            text-align: right;
            color: #1f2937;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .status-breakdown-pct {
            text-align: right;
            color: #64748b;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        @media (max-width: 768px) {
            .manage-pallets-header {
                padding: 24px;
                margin-bottom: 24px;
            }

            .header-content {
                flex-direction: column;
                text-align: left;
                gap: 20px;
            }

            .header-left {
                flex-direction: row;
                gap: 16px;
            }

            .header-info h1 {
                font-size: 2em;
            }

            .header-right-stats {
                width: 100%;
                margin-left: 0;
                justify-content: flex-start;
            }

            .header-stat-card,
            .status-mix-card {
                width: 100%;
                max-width: none;
                min-width: 0;
            }
        }

        @media (min-width: 769px) and (max-width: 1300px) {
            .status-mix-card {
                flex-basis: 100%;
                max-width: none;
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
        .table-header-main { display:flex; align-items:center; flex: 1 1 220px; min-width: 220px; }
        .table-title { font-size:1.7em; font-weight:600; margin:0; display:flex; align-items:center; gap:10px; color:white; }
        .table-header-admin { flex: 1 1 300px; min-width: 260px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; padding:10px 12px; border-radius:14px; border:1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.12); box-shadow: 0 8px 18px rgba(0,0,0,0.12); }
        .admin-tools-title { margin: 0; font-size: 0.9em; font-weight: 600; color: rgba(255,255,255,0.95); text-align: center; }
        .admin-tools-buttons { display:flex; align-items:center; justify-content:center; gap:8px; flex-wrap:wrap; }
        .table-header-actions { display:flex; gap:10px; align-items:center; flex-wrap: wrap; justify-content:flex-end; flex: 1 1 220px; min-width: 220px; }
        @media (max-width: 768px) {
            .table-header {
                justify-content: center;
            }
            .table-header-main,
            .table-header-admin,
            .table-header-actions {
                flex: 1 1 100%;
                min-width: 0;
                justify-content: center;
            }
            .table-header-admin {
                padding: 10px;
            }
        }
        .action-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:10px; font-size:.85em; font-weight:600; text-decoration:none; border:none; cursor:pointer; white-space:nowrap; transition: all .2s ease; }
        .action-btn-primary { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color:white; box-shadow: 0 2px 8px rgba(16,185,129,.25); }
        .action-btn-primary:hover:not([disabled]) { background: linear-gradient(135deg, #059669 0%, #047857 100%); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(16,185,129,.35); }
        .action-btn-import { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color:white; box-shadow: 0 2px 8px rgba(37,99,235,.25); }
        .action-btn-import:hover:not([disabled]) { background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(37,99,235,.35); }
        .action-btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color:white; box-shadow: 0 2px 8px rgba(239,68,68,.25); }
        .action-btn-danger:hover:not([disabled]) { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(239,68,68,.35); }
        .action-btn-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color:white; box-shadow: 0 2px 8px rgba(245,158,11,.3); }
        .action-btn-warning:hover:not([disabled]) { background: linear-gradient(135deg, #d97706 0%, #b45309 100%); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(245,158,11,.4); }
        .btn-export-header { background: rgba(255,255,255,.95); color:#16a34a; border:none; box-shadow: 0 2px 8px rgba(0,0,0,.15); cursor: pointer; padding:8px 14px; border-radius:10px; font-size:.85em; font-weight:600; display:inline-flex; align-items:center; gap:6px; transition: all .2s ease; }
        .action-btn:hover:not([disabled]) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-export-header:hover { background:white; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .action-btn[disabled] { opacity: .5; cursor: not-allowed; filter: grayscale(20%); box-shadow: none; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
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
        .warehouse-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid rgba(72,140,154,0.35);
            background: linear-gradient(135deg, #eef8fb 0%, #d9edf2 100%);
            color: #2f5f6b;
            font-size: 0.82em;
            font-weight: 600;
            text-decoration: none;
        }
        .warehouse-pill:hover {
            background: linear-gradient(135deg, #dff1f6 0%, #c9e5ec 100%);
            color: #274f59;
        }
        .warehouse-pill.empty {
            border-color: rgba(148,163,184,0.35);
            background: #f8fafc;
            color: #64748b;
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
            background-color: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .modal-content {
            background: #fff;
            margin: 3% auto;
            padding: 0;
            border: none;
            width: 95%;
            max-width: 850px;
            border-radius: 20px;
            position: relative;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
            overflow: hidden;
        }
        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .close-modal-btn {
            color: #fff;
            position: absolute;
            top: 18px;
            right: 24px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }
        .close-modal-btn:hover,
        .close-modal-btn:focus {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
            color: #fff;
        }
        .shipment-details-modal-content {
            padding: 0;
        }
        .shipment-details-modal-content h2 {
            margin: 0 0 0 0;
            padding: 24px 36px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            font-size: 1.5em;
            font-weight: 700;
            text-align: center;
            box-shadow: 0 4px 12px rgba(72,140,154,0.2);
        }
        .shipment-details-modal-content form {
            padding: 36px;
        }
        .previous-arrival-card {
            margin: -6px 0 18px 0;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(72,140,154,0.25);
            background: linear-gradient(135deg, #ecf8fb 0%, #f5fbfd 100%);
            color: #2f4c57;
        }
        .previous-arrival-label {
            margin: 0 0 4px 0;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: #3a6e7f;
        }
        .previous-arrival-value {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #1f3f49;
        }
        .previous-arrival-meta {
            margin-top: 5px;
            font-size: 0.82rem;
            color: #4e6b76;
        }
        .previous-arrival-card.warning {
            border-color: rgba(234,179,8,0.45);
            background: linear-gradient(135deg, #fff8dd 0%, #fffbee 100%);
            color: #7c5e10;
        }
        .previous-arrival-card.warning .previous-arrival-label,
        .previous-arrival-card.warning .previous-arrival-value {
            color: #7c5e10;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-row > div {
            flex: 1;
            min-width: 150px;
        }
        .shipment-details-modal-content label {
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
            color: #293E4C;
            font-size: 0.95em;
        }
        .shipment-details-modal-content input[type="text"],
        .shipment-details-modal-content input[type="date"],
        .shipment-details-modal-content input[type="number"],
        .shipment-details-modal-content select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid rgba(72,140,154,0.15);
            border-radius: 10px;
            margin-bottom: 0;
            font-size: 1em;
            box-sizing: border-box;
            transition: all 0.3s ease;
            background: #fff;
        }
        .shipment-details-modal-content input[type="text"]:focus,
        .shipment-details-modal-content input[type="date"]:focus,
        .shipment-details-modal-content input[type="number"]:focus,
        .shipment-details-modal-content select:focus {
            border-color: #488C9A;
            outline: none;
            box-shadow: 0 0 0 3px rgba(72,140,154,0.1);
        }
        .radio-label {
            display: inline-flex;
            align-items: center;
            margin-right: 0;
            font-weight: normal;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .radio-label:hover {
            background: rgba(72,140,154,0.08);
        }
        .radio-label input[type=radio] {
            margin-right: 6px;
            cursor: pointer;
            width: 16px;
            height: 16px;
        }
        
        /* Origin-Destination Layout Styling */
        .origin-destination-section {
            margin: 24px 0;
            padding: 20px;
            background: rgba(72,140,154,0.05);
            border-radius: 12px;
            border: 2px dashed rgba(72,140,154,0.2);
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
            display: flex;
            gap: 12px;
        }
        
        #originDisplay, #originDisplayMulti {
            padding: 14px 16px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 2px solid rgba(72,140,154,0.15);
            border-radius: 10px;
            min-height: 48px;
            display: flex;
            align-items: center;
            font-size: 0.95em;
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
        
        /* Portal-style action buttons - Table row buttons */
        .action-button {
            background: #488C9A !important;
            color: #fff !important;
            border: none !important;
            padding: 6px 12px !important;
            cursor: pointer !important;
            border-radius: 5px !important;
            font-weight: 600 !important;
            font-size: 0.9em !important;
            text-decoration: none !important;
            display: inline-block !important;
            transition: all 0.3s ease !important;
            margin: 0 4px !important;
        }
        .action-button:hover {
            background: #3A6E7F !important;
            color: #fff !important;
        }
        .action-button:disabled {
            background: #ccc !important;
            color: #666 !important;
            cursor: not-allowed !important;
        }
        .action-button:disabled:hover {
            background: #ccc !important;
            color: #666 !important;
        }
        
        /* Modal submit buttons */
        #confirmShipmentBtn, #confirmMultiShipmentBtn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            color: #fff !important;
            border: none !important;
            padding: 14px 28px !important;
            cursor: pointer !important;
            border-radius: 12px !important;
            font-weight: 600 !important;
            font-size: 1.05em !important;
            text-decoration: none !important;
            display: block !important;
            transition: all 0.3s ease !important;
            margin: 0 !important;
            box-shadow: 0 4px 12px rgba(16,185,129,0.25) !important;
            width: 100% !important;
        }
        #confirmShipmentBtn:hover, #confirmMultiShipmentBtn:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(16,185,129,0.35) !important;
            color: #fff !important;
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
                    <p class="header-subtitle">View pallet inventory and shipment activity</p>
                </div>
            </div>
            <div class="header-right-stats">
                <div class="header-stat-card">
                    <p class="header-stat-label">Total Pallets</p>
                    <p class="header-stat-value" id="statTotalPallets">0</p>
                </div>
                <div class="header-stat-card">
                    <p class="header-stat-label">Ready to Ship</p>
                    <p class="header-stat-value" id="statReadyToShip">0</p>
                </div>
                <button type="button" class="status-mix-card status-mix-trigger" id="statusMixTrigger" aria-haspopup="dialog" aria-controls="statusBreakdownModal" title="View full status breakdown">
                    <div class="status-mix-header">
                        <p class="status-mix-title">Status Mix</p>
                        <span class="status-mix-subtitle" id="statusMixSubtitle">0 pallets</span>
                    </div>
                    <div class="status-mix-content">
                        <div class="status-mix-donut" id="statusMixDonut">
                            <span class="status-mix-donut-center" id="statusMixDonutCenter">0</span>
                        </div>
                        <ul class="status-mix-legend" id="statusMixLegend">
                            <li class="status-mix-empty">No status data</li>
                        </ul>
                    </div>
                </button>
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
                                <?php $available_statuses = array_unique(array_map(function($p) { return $p['status']; }, $pallets)); foreach ($available_statuses as $s) { if ($s === 'Allocated to Project') { continue; } echo '<option value="' . htmlspecialchars($s) . '">' . htmlspecialchars($s) . '</option>'; } ?>
                            </select>
                        </div>
                        <div class="filter-group" id="warehouseSubFilterGroup" style="display:none;">
                            <label class="filter-label" for="cs_warehouse">Warehouse</label>
                            <select id="cs_warehouse" class="filter-select">
                                <option value="">All Warehouses</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Section Title (moved below filters) -->
                <h2 class="section-title"><?php echo $can_manage_shipments ? 'Select Pallets to Create Shipment' : 'Pallet Inventory'; ?></h2>

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
                                            if ($s === 'Allocated to Project') { continue; }
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
                        <span id="selectedCount_old" style="font-weight: bold; color: #488C9A;">0 pallets selected</span>
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
                
                <div class="deliveries-container">
                    <div class="table-header">
                        <div class="table-header-main">
                            <h3 class="table-title"><i class="fas fa-boxes"></i> Pallets</h3>
                        </div>
                        <?php if ($can_manage_shipments): ?>
                        <div class="table-header-admin">
                            <p class="admin-tools-title">Admin Tools: <span id="selectedCount">0 pallets selected</span></p>
                            <div class="admin-tools-buttons">
                                <a href="upload_shipments.php<?php echo $project_id_from_url ? '?project_id=' . $project_id_from_url : ''; ?>" class="action-btn action-btn-import"><i class="fas fa-file-import"></i> Import Shipments</a>
                                <button type="button" id="deletePalletsBtn" class="action-btn action-btn-danger" disabled><i class="fas fa-trash"></i> Delete</button>
                                <a href="upload_manufacturer_links.php<?php echo $project_id_from_url ? '?project_id=' . $project_id_from_url : ''; ?>" class="action-btn action-btn-warning"><i class="fas fa-link"></i> Map Real Pallet IDs</a>
                                <button type="button" id="openShipModalBtn" class="action-btn action-btn-primary" disabled><i class="fas fa-truck-loading"></i> Create Shipment</button>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="table-header-actions">
                            <button type="button" id="exportCsvBtn" class="btn-export-header"><i class="fas fa-download"></i> Export</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="palletsTable">
                            <thead>
                                <tr>
                                    <?php if ($can_manage_shipments): ?><th><input type="checkbox" id="selectAllPallets" disabled></th><?php endif; ?>
                                    <th>Project</th>
                                    <th>Pallet ID</th>
                                    <th>Manufacturer</th>
                                    <th>Wattage</th>
                                    <th>Quantity</th>
                                    <th>Status</th>
                                    <th>Deliveries</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="palletsTableBody">
                                <tr>
                                    <td colspan="<?php echo $can_manage_shipments ? 9 : 8; ?>" style="text-align: center; padding: 40px;">
                                        <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #488C9A;"></i>
                                        <p style="margin-top: 10px; color: #666;">Loading pallets...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
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
                    <div id="previousArrivalCardSingle" class="previous-arrival-card" role="status" aria-live="polite">
                        <p class="previous-arrival-label">Previous Arrival Checkpoint</p>
                        <p class="previous-arrival-value" id="previousArrivalValueSingle">Select pallets to load arrival guard</p>
                        <div class="previous-arrival-meta" id="previousArrivalMetaSingle"></div>
                    </div>
                    <div class="form-row" id="domestic-cost-fields">
                        <div>
                            <label for="freight_cost">Freight Cost ($):</label>
                            <input type="number" id="freight_cost" name="freight_cost" step="0.01" min="0">
                        </div>
                        <?php if (!$is_customer_admin): ?>
                            <div>
                                <label for="customer_cost">Customer Cost ($):</label>
                                <input type="number" id="customer_cost" name="customer_cost" step="0.01" min="0">
                            </div>
                        <?php else: ?>
                            <input type="hidden" id="customer_cost" name="customer_cost" value="">
                        <?php endif; ?>
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
                    <div id="maxShipmentsNote" style="display:none; margin-top:8px; padding:8px 12px; background-color:#fff3cd; border:1px solid #ffc107; border-radius:4px; font-size:0.85em; color:#856404;">
                        <strong>Note:</strong> Maximum 12 shipments can be created at once. For more shipments, please use the <a href="#" onclick="document.querySelector('.import-btn')?.click(); return false;" style="color:#664d03; text-decoration:underline;">Import feature</a>.
                    </div>
                    
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
                    <div id="previousArrivalCardMulti" class="previous-arrival-card" role="status" aria-live="polite">
                        <p class="previous-arrival-label">Previous Arrival Checkpoint</p>
                        <p class="previous-arrival-value" id="previousArrivalValueMulti">Select pallets to load arrival guard</p>
                        <div class="previous-arrival-meta" id="previousArrivalMetaMulti"></div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label for="freight_cost_multi">Freight Cost ($):</label>
                            <input type="number" id="freight_cost_multi" name="freight_cost_multi" step="0.01" min="0">
                        </div>
                        <?php if (!$is_customer_admin): ?>
                            <div>
                                <label for="customer_cost_multi">Customer Cost ($):</label>
                                <input type="number" id="customer_cost_multi" name="customer_cost_multi" step="0.01" min="0">
                            </div>
                        <?php else: ?>
                            <input type="hidden" id="customer_cost_multi" name="customer_cost_multi" value="">
                        <?php endif; ?>
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

<!-- Status Breakdown Modal -->
<div id="statusBreakdownModal" class="modal" style="display: none;">
    <div class="modal-content status-breakdown-modal-content">
        <span class="close-modal-btn" id="closeStatusBreakdownModalBtn">&times;</span>
        <div class="shipment-details-modal-content">
            <h2 style="margin-top:0; text-align:center;">Pallet Status Breakdown</h2>
            <div class="status-breakdown-body">
                <p class="status-breakdown-summary" id="statusBreakdownSummary">0 filtered pallets</p>
                <div class="table-responsive">
                    <table class="status-breakdown-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th style="text-align:right;">Count</th>
                                <th style="text-align:right;">Share</th>
                            </tr>
                        </thead>
                        <tbody id="statusBreakdownTableBody">
                            <tr>
                                <td colspan="3" style="text-align:center; color:#64748b;">No status data</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats are now loaded via AJAX -->

<!-- Embed PHP data as JS variables for populating dropdowns -->
<script>
    const projectsData = <?php echo json_encode($all_projects); ?>;
    const warehousesData = <?php echo json_encode($all_warehouses); ?>;
    const manufacturersData = <?php echo json_encode($all_manufacturers); ?>;
    const projectIdFromUrl = <?php echo (int)$project_id_from_url; ?>;
    const canManageShipments = <?php echo $can_manage_shipments ? 'true' : 'false'; ?>;
    const isStandardUser = <?php echo $is_standard_user ? 'true' : 'false'; ?>;
    const tableColumnCount = canManageShipments ? 9 : 8;
    // Pallets are now loaded via AJAX
    let palletsData = [];
    let currentStatusCounts = {};
    const palletsMap = new Map();
</script>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places,geometry"></script>

<script>
// ----------------- GLOBAL STATE TRACKING -----------------
let currentOverseasState = null; // Track current overseas state to prevent unnecessary UI updates

// ----------------- PALLET TABLE CHECKBOXES -----------------
function toggleAllPalletCheckboxes(isChecked) {
    if (!canManageShipments) return;
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
    if (!canManageShipments) return;
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
    if (!canManageShipments) return;
    const count = document.querySelectorAll('.pallet-checkbox:checked').length;
    const countEl = document.getElementById('selectedCount');
    if (countEl) {
        countEl.textContent = count + ' pallet' + (count === 1 ? '' : 's') + ' selected';
    }
    const delBtn = document.getElementById('deletePalletsBtn');
    if (delBtn) {
        delBtn.disabled = (count === 0);
    }
}

function statusColorFor(statusName) {
    switch (statusName) {
        case 'In Warehouse':
            return '#0ea5e9';
        case 'At Manufacturer':
            return '#22c55e';
        case 'In Transit to Warehouse':
        case 'In Transit to Project':
        case 'On Water':
            return '#f59e0b';
        case 'Delivered to Project':
            return '#6366f1';
        default:
            return '#94a3b8';
    }
}

function getStatusBreakdownRows() {
    const allocatedToProjectCount = Number(currentStatusCounts['Allocated to Project'] || 0);
    const orderedStatuses = [
        'At Manufacturer',
        'In Warehouse',
        'In Transit to Warehouse',
        'In Transit to Project',
        'On Water',
        'Delivered to Project'
    ];

    const rows = orderedStatuses.map((status) => ({
        status,
        count: Number(currentStatusCounts[status] || 0) + (status === 'In Warehouse' ? allocatedToProjectCount : 0)
    }));

    Object.keys(currentStatusCounts).forEach((status) => {
        if (status === 'Allocated to Project') {
            return;
        }
        if (!orderedStatuses.includes(status)) {
            rows.push({ status, count: Number(currentStatusCounts[status] || 0) });
        }
    });

    return rows;
}

function populateStatusBreakdownModal() {
    const summaryEl = document.getElementById('statusBreakdownSummary');
    const tbodyEl = document.getElementById('statusBreakdownTableBody');
    if (!summaryEl || !tbodyEl) {
        return;
    }

    const total = Number(totalPallets || 0);
    const rows = getStatusBreakdownRows();
    summaryEl.textContent = `${total.toLocaleString()} filtered pallet${total === 1 ? '' : 's'}`;

    if (rows.every((row) => row.count === 0)) {
        tbodyEl.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#64748b;">No status data</td></tr>';
        return;
    }

    tbodyEl.innerHTML = rows.map((row) => {
        const pct = total > 0 ? (row.count / total) * 100 : 0;
        return `<tr>
            <td>
                <span class="status-breakdown-status">
                    <span class="status-dot" style="background:${statusColorFor(row.status)};"></span>
                    ${escapeHtml(row.status)}
                </span>
            </td>
            <td class="status-breakdown-num">${row.count.toLocaleString()}</td>
            <td class="status-breakdown-pct">${pct.toFixed(1)}%</td>
        </tr>`;
    }).join('');
}

function openStatusBreakdownModal() {
    populateStatusBreakdownModal();
    const modalEl = document.getElementById('statusBreakdownModal');
    if (modalEl) {
        modalEl.style.display = 'block';
    }
}

function closeStatusBreakdownModal() {
    const modalEl = document.getElementById('statusBreakdownModal');
    if (modalEl) {
        modalEl.style.display = 'none';
    }
}

function updateHeaderStats() {
    const totalEl = document.getElementById('statTotalPallets');
    const readyEl = document.getElementById('statReadyToShip');
    const triggerEl = document.getElementById('statusMixTrigger');
    const subtitleEl = document.getElementById('statusMixSubtitle');
    const donutEl = document.getElementById('statusMixDonut');
    const donutCenterEl = document.getElementById('statusMixDonutCenter');
    const legendEl = document.getElementById('statusMixLegend');

    if (!totalEl || !readyEl || !triggerEl || !subtitleEl || !donutEl || !donutCenterEl || !legendEl) {
        return;
    }

    const getStatusCount = (statusName) => Number(currentStatusCounts[statusName] || 0);
    const total = Number(totalPallets || 0);
    const allocatedToProject = getStatusCount('Allocated to Project');
    const inWarehouse = getStatusCount('In Warehouse') + allocatedToProject;
    const atManufacturer = getStatusCount('At Manufacturer');
    const delivered = getStatusCount('Delivered to Project');
    const inTransit = getStatusCount('In Transit to Warehouse') + getStatusCount('In Transit to Project') + getStatusCount('On Water');
    const readyToShip = inWarehouse + atManufacturer + delivered;
    const other = Math.max(0, total - (inWarehouse + atManufacturer + inTransit + delivered));

    totalEl.textContent = total.toLocaleString();
    readyEl.textContent = readyToShip.toLocaleString();
    subtitleEl.textContent = `${total.toLocaleString()} pallet${total === 1 ? '' : 's'}`;
    donutCenterEl.textContent = total.toLocaleString();
    triggerEl.setAttribute('aria-label', `Status mix for ${total.toLocaleString()} filtered pallets. Open full breakdown.`);

    const segments = [
        { label: 'In Warehouse', value: inWarehouse, color: '#0ea5e9' },
        { label: 'At Manufacturer', value: atManufacturer, color: '#22c55e' },
        { label: 'In Transit', value: inTransit, color: '#f59e0b' },
        { label: 'Delivered', value: delivered, color: '#6366f1' },
        { label: 'Other', value: other, color: '#94a3b8' }
    ].filter((segment) => segment.value > 0);

    if (total <= 0 || segments.length === 0) {
        donutEl.style.setProperty('--donut-bg', '#e2e8f0');
        legendEl.innerHTML = '<li class="status-mix-empty">No status data</li>';
        return;
    }

    let running = 0;
    const gradientStops = segments.map((segment) => {
        const portion = (segment.value / total) * 100;
        const start = running;
        const end = running + portion;
        running = end;
        return `${segment.color} ${start.toFixed(2)}% ${end.toFixed(2)}%`;
    });
    donutEl.style.setProperty('--donut-bg', `conic-gradient(${gradientStops.join(', ')})`);

    legendEl.innerHTML = segments.map((segment) => {
        const pct = Math.round((segment.value / total) * 100);
        return `<li class="status-mix-legend-item">
            <span class="status-dot" style="background:${segment.color};"></span>
            <span class="status-label">${segment.label}</span>
            <span class="status-value">${segment.value.toLocaleString()} (${pct}%)</span>
        </li>`;
    }).join('');
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAllPallets');
    const palletCheckboxes = document.querySelectorAll('.pallet-checkbox');
    const statusMixTrigger = document.getElementById('statusMixTrigger');
    const statusBreakdownModal = document.getElementById('statusBreakdownModal');
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
    updatePreviousArrivalDisplay();
    toggleWarehouseSubFilter();
    
    // Initialize pagination
    initializePagination();
    
    // Load persisted filters
    loadPersistedFilters();
    
    // Add event listeners to save filters on change
    document.getElementById('palletSearch')?.addEventListener('input', saveFilters);
    document.getElementById('projectFilter')?.addEventListener('change', saveFilters);
    document.getElementById('wattageFilter')?.addEventListener('change', saveFilters);
    document.getElementById('statusFilter')?.addEventListener('change', saveFilters);
    // New filter bar listeners - also need to save filters
    document.getElementById('cs_search')?.addEventListener('keyup', function() { filterPallets(); saveFilters(); });
    document.getElementById('cs_project')?.addEventListener('change', function() { filterPallets(); saveFilters(); });
    document.getElementById('cs_wattage')?.addEventListener('change', function() { filterPallets(); saveFilters(); });
    document.getElementById('cs_status')?.addEventListener('change', function() { toggleWarehouseSubFilter(); filterPallets(); saveFilters(); });
    document.getElementById('cs_warehouse')?.addEventListener('change', function() { filterPallets(); saveFilters(); });
    document.getElementById('itemsPerPage')?.addEventListener('change', saveFilters);

    if (statusMixTrigger) {
        statusMixTrigger.addEventListener('click', openStatusBreakdownModal);
    }
    document.getElementById('closeStatusBreakdownModalBtn')?.addEventListener('click', closeStatusBreakdownModal);
    if (statusBreakdownModal) {
        statusBreakdownModal.addEventListener('click', function(event) {
            if (event.target === statusBreakdownModal) {
                closeStatusBreakdownModal();
            }
        });
    }

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
        const headers = ["id", "identifier", "identifier_source", "solterra_identifier", "manufacturer_pallet_id", "wattage", "quantity", "status", "Project", "Warehouse", "Associated Deliveries"];
        csvData.push(headers.map(h => '"' + h.replace(/"/g, '""') + '"').join(','));
        rows.forEach(row => {
            if (row.style.display === 'none') return;
            const cells = row.querySelectorAll('td');
            const rowData = [];
            const id = row.getAttribute('data-id') || '';
            if (!id) return;
            const pallet = palletsMap.get(Number(id)) || {};
            const colOffset = canManageShipments ? 1 : 0;
            const deliveryCellIdx = colOffset + 6;
            headers.forEach(h => {
                let val = '';
                if (h === 'id') {
                    val = id;
                } else if (h === 'identifier') {
                    val = String(pallet.display_identifier || pallet.pallet_identifier || '');
                } else if (h === 'identifier_source') {
                    const hasManufacturerId = String(pallet.manufacturer_pallet_id || '').trim() !== '';
                    val = hasManufacturerId ? 'Linked Manufacturer ID' : 'Solterra Generated ID';
                } else if (h === 'solterra_identifier') {
                    val = String(pallet.pallet_identifier || '');
                } else if (h === 'manufacturer_pallet_id') {
                    val = String(pallet.manufacturer_pallet_id || '');
                } else if (h === 'wattage') {
                    val = String(pallet.wattage || '');
                } else if (h === 'quantity') {
                    val = String(pallet.quantity || '');
                } else if (h === 'status') {
                    val = String(pallet.status || '');
                } else if (h === 'Project') {
                    val = String(pallet.display_project_name || 'Unassigned');
                } else if (h === 'Warehouse') {
                    val = String(pallet.current_warehouse_name || '');
                } else if (h === 'Associated Deliveries') {
                    val = (cells[deliveryCellIdx]?.textContent || '').trim();
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

// ----------------- AJAX PAGINATION -----------------
let currentPage = 1;
let itemsPerPage = 100;
let totalPallets = 0;
let totalPages = 0;
let isLoading = false;

async function loadPallets() {
    if (isLoading) return;
    isLoading = true;

    const tbody = document.getElementById('palletsTableBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="${tableColumnCount}" style="text-align: center; padding: 40px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #488C9A;"></i>
                <p style="margin-top: 10px; color: #666;">Loading pallets...</p>
            </td>
        </tr>
    `;

    // Get filter values
    const search = document.getElementById('cs_search')?.value || document.getElementById('palletSearch')?.value || '';
    const projectFilter = document.getElementById('cs_project')?.value || document.getElementById('projectFilter')?.value || '';
    const wattageFilter = document.getElementById('cs_wattage')?.value || document.getElementById('wattageFilter')?.value || '';
    const statusFilter = document.getElementById('cs_status')?.value || document.getElementById('statusFilter')?.value || '';
    const warehouseFilter = document.getElementById('cs_warehouse')?.value || '';

    const params = new URLSearchParams({
        page: currentPage,
        pageSize: itemsPerPage,
        search: search,
        project: projectFilter,
        wattage: wattageFilter,
        status: statusFilter
    });

    if (projectIdFromUrl > 0) {
        params.append('project_id', projectIdFromUrl);
    }
    if (statusFilter === 'In Warehouse' && warehouseFilter) {
        params.append('warehouse_id', warehouseFilter);
    }

    try {
        const response = await fetch(`get_shipment_pallets.php?${params.toString()}`);
        const data = await response.json();

        if (data.success) {
            totalPallets = data.totalCount;
            totalPages = data.totalPages;
            currentStatusCounts = data.statusCounts || {};

            // Update palletsData and palletsMap for shipment creation
            palletsData = data.pallets;
            palletsMap.clear();
            data.pallets.forEach(p => palletsMap.set(p.pallet_id, p));

            // Populate filter dropdowns on first load
            if (data.filterOptions && data.filterOptions.projects) {
                populateFilterDropdowns(data.filterOptions);
            }

            renderPalletsTable(data.pallets);
            updatePaginationInfo();
            updateHeaderStats();
        } else {
            totalPallets = 0;
            totalPages = 0;
            currentStatusCounts = {};
            tbody.innerHTML = `
                <tr>
                    <td colspan="${tableColumnCount}" style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-circle" style="font-size: 24px;"></i>
                        <p style="margin-top: 10px;">Error loading pallets: ${data.message || 'Unknown error'}</p>
                    </td>
                </tr>
            `;
            updatePaginationInfo();
            updateHeaderStats();
        }
    } catch (error) {
        console.error('Error loading pallets:', error);
        totalPallets = 0;
        totalPages = 0;
        currentStatusCounts = {};
        tbody.innerHTML = `
            <tr>
                <td colspan="${tableColumnCount}" style="text-align: center; padding: 40px; color: #dc3545;">
                    <i class="fas fa-exclamation-circle" style="font-size: 24px;"></i>
                    <p style="margin-top: 10px;">Failed to load pallets. Please try again.</p>
                </td>
            </tr>
        `;
        updatePaginationInfo();
        updateHeaderStats();
    } finally {
        isLoading = false;
    }
}

function renderPalletsTable(pallets) {
    const tbody = document.getElementById('palletsTableBody');
    if (!tbody) return;

    if (pallets.length === 0) {
        const projectFilter = document.getElementById('cs_project');
        const selectedProject = projectFilter ? projectFilter.value : '';

        let emptyMessage = 'No pallets found matching your criteria.';
        let subMessage = '';

        if (selectedProject && selectedProject !== 'Unassigned') {
            emptyMessage = `No pallets available for project "${selectedProject}".`;
            subMessage = '<p style="margin-top: 8px; font-size: 13px; color: #888;">Import pallets first or adjust your filters.</p>';
        } else if (selectedProject === 'Unassigned') {
            emptyMessage = 'No unassigned pallets available.';
            subMessage = '<p style="margin-top: 8px; font-size: 13px; color: #888;">All pallets are currently assigned to projects.</p>';
        }

        tbody.innerHTML = `
            <tr>
                <td colspan="${tableColumnCount}" style="text-align: center; padding: 40px; color: #666;">
                    <i class="fas fa-box-open" style="font-size: 24px;"></i>
                    <p style="margin-top: 10px;">${emptyMessage}</p>
                    ${subMessage}
                </td>
            </tr>
        `;
        if (canManageShipments) {
            const selectAll = document.getElementById('selectAllPallets');
            if (selectAll) {
                selectAll.checked = false;
                selectAll.disabled = true;
            }
            updateOpenShipModalButtonState();
            updateSelectedCount();
        }
        return;
    }

    let html = '';
    pallets.forEach(pallet => {
        const status = pallet.status || '';
        let statusHtml = escapeHtml(status);

        if (pallet.current_warehouse_id && status === 'In Transit to Warehouse') {
            statusHtml = `<a href="manage_warehouse_inventory.php?warehouse_id=${pallet.current_warehouse_id}&view=inbound_transit" style="color: #488C9A; text-decoration: underline;">${escapeHtml(status)}</a>`;
        } else if (pallet.current_warehouse_id && status === 'In Warehouse') {
            if (isStandardUser) {
                const backProjectParam = projectIdFromUrl > 0 ? `&project_id=${projectIdFromUrl}` : '';
                statusHtml = `<a href="warehouse_info.php?warehouse_id=${pallet.current_warehouse_id}&from=manage_pallets${backProjectParam}" style="color: #488C9A; text-decoration: underline;">${escapeHtml(status)}</a>`;
            } else {
                statusHtml = `<a href="manage_warehouse_inventory.php?warehouse_id=${pallet.current_warehouse_id}&view=stored_inventory" style="color: #488C9A; text-decoration: underline;">${escapeHtml(status)}</a>`;
            }
        }

        let manufacturerName = pallet.origin_vendor_name || pallet.origin_vendor || 'N/A';
        if (manufacturerName && manufacturerName.includes(' - ')) {
            manufacturerName = manufacturerName.split(' - ')[0].trim();
        }
        const manufacturerLower = String(manufacturerName || '').toLowerCase();
        if (manufacturerLower === 'phoenix wh' || manufacturerLower === 'phoenix warehouse') {
            manufacturerName = 'Meyer Burger';
        }

        let warehouseHtml = '';
        if (pallet.current_warehouse_id && pallet.current_warehouse_name) {
            const warehouseUrl = isStandardUser
                ? `warehouse_info.php?warehouse_id=${pallet.current_warehouse_id}${projectIdFromUrl > 0 ? `&project_id=${projectIdFromUrl}` : ''}&from=create_shipment`
                : `manage_warehouse_inventory.php?warehouse_id=${pallet.current_warehouse_id}`;
            warehouseHtml = `<a class="warehouse-pill" href="${warehouseUrl}"><i class="fas fa-warehouse"></i>${escapeHtml(pallet.current_warehouse_name)}</a>`;
        } else if (status === 'In Warehouse' || status === 'In Transit to Warehouse') {
            warehouseHtml = '<span class="warehouse-pill empty">Unknown warehouse</span>';
        }
        const statusCellHtml = warehouseHtml
            ? `${statusHtml}<div style="margin-top:6px;">${warehouseHtml}</div>`
            : statusHtml;

        let deliveriesHtml = 'No deliveries';
        const deliveryInfo = pallet.delivery_info || '';
        if (deliveryInfo) {
            const deliveries = deliveryInfo.split('|');
            if (deliveries.length === 1) {
                const parts = deliveries[0].split(':');
                const deliveryId = parts[0];
                const bolNumber = parts[1] || 'No BOL';

                if (isStandardUser) {
                    const projectForLink = Number(pallet.current_project_id || pallet.assigned_project_id || 0);
                    if (projectForLink > 0) {
                        deliveriesHtml = `<a href="view_project.php?project_id=${projectForLink}&delivery_id=${deliveryId}" style="color: #488C9A; text-decoration: underline;">${escapeHtml(bolNumber)}</a>`;
                    } else {
                        deliveriesHtml = escapeHtml(bolNumber);
                    }
                } else {
                    deliveriesHtml = `<a href="manage_deliveries.php?delivery_id=${deliveryId}" style="color: #488C9A; text-decoration: underline;">${escapeHtml(bolNumber)}</a>`;
                }
            } else {
                const items = deliveries.map(d => {
                    const parts = d.split(':');
                    const deliveryId = parts[0];
                    const bolNumber = parts[1] || 'No BOL';

                    if (isStandardUser) {
                        const projectForLink = Number(pallet.current_project_id || pallet.assigned_project_id || 0);
                        if (projectForLink > 0) {
                            return `<a href="view_project.php?project_id=${projectForLink}&delivery_id=${deliveryId}" style="display: block; padding: 8px 12px; color: #488C9A; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f5f5f5'" onmouseout="this.style.backgroundColor='white'">${escapeHtml(bolNumber)}</a>`;
                        }
                        return `<span style="display: block; padding: 8px 12px; color: #333; border-bottom: 1px solid #eee;">${escapeHtml(bolNumber)}</span>`;
                    }

                    return `<a href="manage_deliveries.php?delivery_id=${deliveryId}" style="display: block; padding: 8px 12px; color: #488C9A; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f5f5f5'" onmouseout="this.style.backgroundColor='white'">${escapeHtml(bolNumber)}</a>`;
                }).join('');

                deliveriesHtml = `
                    <div class="delivery-dropdown">
                        <button type="button" class="delivery-toggle" onclick="toggleDeliveryDropdown(this)" style="background: #488C9A; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer;">Multiple (${deliveries.length})</button>
                        <div class="delivery-list" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 3px; z-index: 1000; min-width: 150px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">${items}</div>
                    </div>
                `;
            }
        }

        const detailsUrl = `pallet_details.php?pallet_id=${pallet.pallet_id}${projectIdFromUrl > 0 ? `&project_id=${projectIdFromUrl}` : ''}`;
        const hasManufacturerIdentifier = String(pallet.manufacturer_pallet_id || '').trim() !== '';
        const identifierBadge = hasManufacturerIdentifier
            ? '<span style="display:inline-block; margin-top:4px; padding:2px 7px; font-size:11px; border-radius:999px; background:#dcfce7; color:#166534; font-weight:600;">Linked Manufacturer ID</span>'
            : '<span style="display:inline-block; margin-top:4px; padding:2px 7px; font-size:11px; border-radius:999px; background:#eef2ff; color:#334155; font-weight:600;">Solterra Generated ID</span>';

        const checkboxHtml = canManageShipments
            ? `<td><input type="checkbox" name="selected_pallets[]" value="${pallet.pallet_id}" class="pallet-checkbox" data-status="${escapeHtml(status)}"></td>`
            : '';

        const actionsHtml = `
            <a href="${detailsUrl}" class="action-button">${canManageShipments ? 'View/Edit Details' : 'Pallet Details'}</a>
        `;

        html += `
            <tr data-id="${pallet.pallet_id}">
                ${checkboxHtml}
                <td>${escapeHtml(pallet.display_project_name || 'Unassigned')}</td>
                <td>
                    <div>${escapeHtml(pallet.display_identifier || pallet.pallet_identifier || 'N/A')}</div>
                    <div>${identifierBadge}</div>
                </td>
                <td>${escapeHtml(manufacturerName || 'N/A')}</td>
                <td>${escapeHtml(pallet.wattage || '')}</td>
                <td>${Number(pallet.quantity || 0).toLocaleString()}</td>
                <td>${statusCellHtml}</td>
                <td>${deliveriesHtml}</td>
                <td>${actionsHtml}</td>
            </tr>
        `;
    });

    tbody.innerHTML = html;

    if (canManageShipments) {
        document.querySelectorAll('.pallet-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateOpenShipModalButtonState();
                updateSelectedCount();
                updateOriginDisplay();
                updateMultiShipSummary();
            });
        });

        const selectAll = document.getElementById('selectAllPallets');
        if (selectAll) {
            selectAll.disabled = false;
            selectAll.checked = false;
        }
        updateOpenShipModalButtonState();
        updateSelectedCount();
    }
}
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function toggleWarehouseSubFilter() {
    const statusSelect = document.getElementById('cs_status') || document.getElementById('statusFilter');
    const warehouseGroup = document.getElementById('warehouseSubFilterGroup');
    const warehouseSelect = document.getElementById('cs_warehouse');
    if (!statusSelect || !warehouseGroup) return;

    const shouldShow = statusSelect.value === 'In Warehouse';
    warehouseGroup.style.display = shouldShow ? '' : 'none';
    if (!shouldShow && warehouseSelect) {
        warehouseSelect.value = '';
    }
}

function populateFilterDropdowns(options) {
    // Populate project filter
    const projectSelect = document.getElementById('cs_project') || document.getElementById('projectFilter');
    if (projectSelect && options.projects) {
        const currentVal = projectSelect.value;
        projectSelect.innerHTML = '<option value="">All Projects</option><option value="Unassigned">Unassigned</option>';
        options.projects.forEach(p => {
            if (p === 'Unassigned') return;
            projectSelect.innerHTML += `<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`;
        });
        projectSelect.value = currentVal;
    }

    // Populate wattage filter
    const wattageSelect = document.getElementById('cs_wattage') || document.getElementById('wattageFilter');
    if (wattageSelect && options.wattages) {
        const currentVal = wattageSelect.value;
        wattageSelect.innerHTML = '<option value="">All Wattages</option>';
        options.wattages.forEach(w => {
            wattageSelect.innerHTML += `<option value="${w}">${w}W</option>`;
        });
        wattageSelect.value = currentVal;
    }

    // Populate status filter
    const statusSelect = document.getElementById('cs_status') || document.getElementById('statusFilter');
    if (statusSelect && options.statuses) {
        const currentVal = statusSelect.value;
        statusSelect.innerHTML = '<option value="">All Statuses</option>';
        options.statuses.forEach(s => {
            if (s === 'Allocated to Project') return;
            statusSelect.innerHTML += `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`;
        });
        statusSelect.value = currentVal;
    }

    // Populate warehouse sub-filter (shown when status=In Warehouse)
    const warehouseSelect = document.getElementById('cs_warehouse');
    if (warehouseSelect && options.warehouses) {
        const currentVal = warehouseSelect.value || warehouseSelect.dataset.persistedValue || '';
        warehouseSelect.innerHTML = '<option value="">All Warehouses</option>';
        options.warehouses.forEach((wh) => {
            const whId = Number(wh.id || 0);
            const whName = String(wh.name || '').trim();
            if (whId <= 0 || !whName) return;
            warehouseSelect.innerHTML += `<option value="${whId}">${escapeHtml(whName)}</option>`;
        });
        if (currentVal) {
            warehouseSelect.value = currentVal;
        }
        delete warehouseSelect.dataset.persistedValue;
    }

    toggleWarehouseSubFilter();
}

function updatePaginationInfo() {
    const paginationInfo = document.getElementById('paginationInfo');
    const pageInfo = document.getElementById('pageInfo');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');

    if (paginationInfo) {
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = Math.min(startIndex + itemsPerPage, totalPallets);
        const displayStart = totalPallets > 0 ? startIndex + 1 : 0;
        paginationInfo.textContent = `Showing ${displayStart}-${endIndex} of ${totalPallets} pallets`;
    }

    if (pageInfo) {
        pageInfo.textContent = `Page ${currentPage} of ${Math.max(1, totalPages)}`;
    }

    if (prevButton) {
        prevButton.disabled = currentPage <= 1;
    }

    if (nextButton) {
        nextButton.disabled = currentPage >= totalPages || totalPallets === 0;
    }
}

function initializePagination() {
    const itemsPerPageInput = document.getElementById('itemsPerPage');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');

    if (itemsPerPageInput) {
        itemsPerPageInput.addEventListener('change', function() {
            itemsPerPage = Math.min(Math.max(1, parseInt(this.value) || 100), 500);
            this.value = itemsPerPage;
            currentPage = 1;
            loadPallets();
        });
    }

    if (prevButton) {
        prevButton.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                loadPallets();
            }
        });
    }

    if (nextButton) {
        nextButton.addEventListener('click', function() {
            if (currentPage < totalPages) {
                currentPage++;
                loadPallets();
            }
        });
    }

    // Initial load
    loadPallets();
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
    // Reset to page 1 when filter changes and reload via AJAX
    currentPage = 1;
    loadPallets();
}

// Apply/Clear for new filter bar
function applyFilterBar() {
    filterPallets();
    saveFilters();
}
function clearFilterBar() {
    // Clear both new and legacy filter inputs
    const newIds = ['cs_search','cs_project','cs_wattage','cs_status','cs_warehouse'];
    const legacyIds = ['palletSearch','projectFilter','wattageFilter','statusFilter'];
    newIds.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    legacyIds.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    toggleWarehouseSubFilter();
    filterPallets();
    saveFilters();
}

// ----------------- MODAL LOGIC -----------------
const shipModal = document.getElementById('shipModal');
const openShipModalBtn = document.getElementById('openShipModalBtn');
const closeShipModalBtn = shipModal ? shipModal.querySelector('.close-modal-btn') : null;

function selectionHasInvalidPallets() {
    const invalidMatch = /in transit|on water/i;
    const selected = document.querySelectorAll('.pallet-checkbox:checked');

    for (const cb of selected) {
        const statusText = String(cb.getAttribute('data-status') || '').trim();
        if (invalidMatch.test(statusText)) {
            return true;
        }
    }

    return false;
}
function openShipModal() {
    if (!shipModal) return;
    shipModal.style.display = 'block';
    // Initialize multi-shipment defaults and BOL fields when modal opens
    try { setDefaultPalletsPerTruckFromSelection(); } catch(e) {}
    updateMultiShipSummary();
    updatePreviousArrivalDisplay();
}
function closeShipModal() {
    if (!shipModal) return;
    shipModal.style.display = 'none';
}

function handleOpenShipModalClick(e) {
    if (selectionHasInvalidPallets()) {
        alert('You cannot create a delivery for pallets that are already In Transit or On Water. Please deselect those pallets to proceed.');
        return;
    }
    openShipModal();
}

if (openShipModalBtn) {
    openShipModalBtn.addEventListener('click', handleOpenShipModalClick);
}
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

function populateDropdown(selectElement, type, dataSource, nameField, placeholderPrefix, defaultValue = null) {
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
            // Select default value if provided
            if (defaultValue && item.id == defaultValue) {
                opt.selected = true;
            }
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

function normalizeIsoDate(dateValue) {
    const raw = String(dateValue || '').trim();
    if (!raw || raw === '0000-00-00') {
        return '';
    }
    const match = raw.match(/^(\d{4}-\d{2}-\d{2})/);
    return match ? match[1] : '';
}

function formatIsoDateForDisplay(dateValue) {
    const normalized = normalizeIsoDate(dateValue);
    if (!normalized) {
        return 'N/A';
    }
    const [year, month, day] = normalized.split('-').map(Number);
    const parsed = new Date(Date.UTC(year, month - 1, day));
    if (Number.isNaN(parsed.getTime())) {
        return normalized;
    }
    return parsed.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC'
    });
}

function computePreviousArrivalConstraint() {
    const selectedPallets = getSelectedPallets();
    const statusesNeedingArrival = new Set(['In Warehouse', 'Delivered to Project']);
    const arrivalDates = [];
    let missingArrivalCount = 0;

    selectedPallets.forEach((pallet) => {
        const status = String(pallet.status || '');
        if (!statusesNeedingArrival.has(status)) {
            return;
        }
        const arrivalDate = normalizeIsoDate(pallet.prior_arrival_date || pallet.arrival_date);
        if (arrivalDate) {
            arrivalDates.push(arrivalDate);
        } else {
            missingArrivalCount++;
        }
    });

    if (arrivalDates.length === 0) {
        return {
            hasConstraint: false,
            selectedCount: selectedPallets.length,
            constrainedCount: 0,
            missingArrivalCount: missingArrivalCount,
            earliestDate: '',
            latestDate: ''
        };
    }

    arrivalDates.sort();
    return {
        hasConstraint: true,
        selectedCount: selectedPallets.length,
        constrainedCount: arrivalDates.length,
        missingArrivalCount: missingArrivalCount,
        earliestDate: arrivalDates[0],
        latestDate: arrivalDates[arrivalDates.length - 1]
    };
}

function applyPreviousArrivalCard(cardEl, valueEl, metaEl, valueText, metaText, isWarning) {
    if (!cardEl || !valueEl || !metaEl) {
        return;
    }
    valueEl.textContent = valueText;
    metaEl.textContent = metaText;
    cardEl.classList.toggle('warning', Boolean(isWarning));
}

function updatePreviousArrivalDisplay() {
    const constraint = computePreviousArrivalConstraint();

    const singleCard = document.getElementById('previousArrivalCardSingle');
    const singleValue = document.getElementById('previousArrivalValueSingle');
    const singleMeta = document.getElementById('previousArrivalMetaSingle');
    const multiCard = document.getElementById('previousArrivalCardMulti');
    const multiValue = document.getElementById('previousArrivalValueMulti');
    const multiMeta = document.getElementById('previousArrivalMetaMulti');

    const departureSingle = document.getElementById('departure_date');
    const departureMulti = document.getElementById('departure_date_multi');
    const departureInputs = [departureSingle, departureMulti].filter(Boolean);

    if (constraint.hasConstraint) {
        departureInputs.forEach((input) => {
            input.min = constraint.latestDate;
        });

        const valueText = `Latest prior arrival: ${formatIsoDateForDisplay(constraint.latestDate)}`;
        let metaText = `Departure must be on/after ${formatIsoDateForDisplay(constraint.latestDate)} (${constraint.constrainedCount} pallet${constraint.constrainedCount === 1 ? '' : 's'}).`;
        let warning = false;
        if (constraint.missingArrivalCount > 0) {
            warning = true;
            metaText += ` ${constraint.missingArrivalCount} selected pallet${constraint.missingArrivalCount === 1 ? '' : 's'} missing arrival date.`;
        }

        applyPreviousArrivalCard(singleCard, singleValue, singleMeta, valueText, metaText, warning);
        applyPreviousArrivalCard(multiCard, multiValue, multiMeta, valueText, metaText, warning);
        return constraint;
    }

    departureInputs.forEach((input) => {
        input.removeAttribute('min');
    });

    if (constraint.selectedCount === 0) {
        const valueText = 'Select pallets to load arrival guard';
        const metaText = 'Choose pallets first to validate departure against prior arrival.';
        applyPreviousArrivalCard(singleCard, singleValue, singleMeta, valueText, metaText, false);
        applyPreviousArrivalCard(multiCard, multiValue, multiMeta, valueText, metaText, false);
        return constraint;
    }

    if (constraint.missingArrivalCount > 0) {
        const valueText = 'Prior arrival date missing';
        const metaText = `Some selected pallets are missing arrival dates. Please verify pallet history before shipping.`;
        applyPreviousArrivalCard(singleCard, singleValue, singleMeta, valueText, metaText, true);
        applyPreviousArrivalCard(multiCard, multiValue, multiMeta, valueText, metaText, true);
        return constraint;
    }

    const valueText = 'No prior-arrival constraint';
    const metaText = 'Current selection originates at manufacturer or has no recorded prior arrival requirement.';
    applyPreviousArrivalCard(singleCard, singleValue, singleMeta, valueText, metaText, false);
    applyPreviousArrivalCard(multiCard, multiValue, multiMeta, valueText, metaText, false);
    return constraint;
}

function validateDepartureDateAgainstPreviousArrival(departureDate) {
    const normalizedDeparture = normalizeIsoDate(departureDate);
    if (!normalizedDeparture) {
        return true;
    }

    const constraint = updatePreviousArrivalDisplay();
    if (constraint.missingArrivalCount > 0) {
        alert('Some selected pallets are missing prior arrival dates. Please update pallet arrival history before creating this shipment.');
        return false;
    }
    if (!constraint.hasConstraint) {
        return true;
    }

    if (normalizedDeparture < constraint.latestDate) {
        const requiredDate = formatIsoDateForDisplay(constraint.latestDate);
        alert(`Departure Date cannot be earlier than ${requiredDate}, the latest prior arrival date for selected pallets.`);
        return false;
    }

    return true;
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
        // Use Map for O(1) lookup instead of Array.find O(n)
        const pallet = palletsMap.get(palletId);
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

    updatePreviousArrivalDisplay();
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
    // Default to current project if destination type is project and we have a project from URL
    const defaultValue = (destType === 'project' && projectIdFromUrl > 0) ? projectIdFromUrl : null;

    populateDropdown(destSelect, destType, data, nameField, placeholder, defaultValue);

    // Add "+ Add New Warehouse" option at the end for warehouse type
    if (destType === 'warehouse') {
        const addOption = document.createElement('option');
        addOption.value = '__add_new__';
        addOption.textContent = '+ Add New Warehouse...';
        addOption.style.color = '#488C9A';
        addOption.style.fontStyle = 'italic';
        destSelect.appendChild(addOption);
    }

    calculateDistance();
}

function toggleDestinationSelectMulti() {
    const destType = document.querySelector('input[name="destination_type_multi"]:checked').value;
    const destSelect = document.getElementById('destination_id_multi');

    const data = (destType === 'project') ? projectsData : warehousesData;
    const nameField = (destType === 'project') ? 'project_name' : 'name';
    const placeholder = (destType === 'project') ? 'Project' : 'Warehouse';
    // Default to current project if destination type is project and we have a project from URL
    const defaultValue = (destType === 'project' && projectIdFromUrl > 0) ? projectIdFromUrl : null;

    populateDropdown(destSelect, destType, data, nameField, placeholder, defaultValue);

    // Add "+ Add New Warehouse" option at the end for warehouse type
    if (destType === 'warehouse') {
        const addOption = document.createElement('option');
        addOption.value = '__add_new__';
        addOption.textContent = '+ Add New Warehouse...';
        addOption.style.color = '#488C9A';
        addOption.style.fontStyle = 'italic';
        destSelect.appendChild(addOption);
    }

    calculateDistanceMulti();
}

// ----------------- DISTANCE CALCULATION FUNCTIONS -----------------
function calculateDistance() {
    const destSelect = document.getElementById('destination_id');
    const distanceDisplay = document.getElementById('distanceDisplay');
    const milesInput = document.getElementById('miles');

    if (!destSelect || !distanceDisplay) return;

    // Handle "Add New Warehouse" option
    if (destSelect.value === '__add_new__') {
        window.open('add_warehouse.php', '_blank');
        destSelect.value = ''; // Reset selection
        return;
    }

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

    // Handle "Add New Warehouse" option
    if (destSelect.value === '__add_new__') {
        window.open('add_warehouse.php', '_blank');
        destSelect.value = ''; // Reset selection
        return;
    }

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
        const customerCostInput = document.getElementById('customer_cost');
        const customerCostValue = customerCostInput ? customerCostInput.value : '';
        const customerCost = customerCostValue !== '' ? customerCostValue : freightCost;
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
        if (arrival < departure) {
            alert('Est. Arrival Date cannot be before Departure Date.');
            return;
        }
        if (!validateDepartureDateAgainstPreviousArrival(departure)) {
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
        const customerCostMultiInput = document.getElementById('customer_cost_multi');
        const customerCostValue = customerCostMultiInput ? customerCostMultiInput.value : '';
        const customerCost = customerCostValue !== '' ? customerCostValue : freightCost;
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
        if (arrival < departure) {
            alert('Est. Arrival Date cannot be before Departure Date.');
            return;
        }
        if (!validateDepartureDateAgainstPreviousArrival(departure)) {
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

    // Show/hide max shipments note
    const maxShipmentsNote = document.getElementById('maxShipmentsNote');
    if (maxShipmentsNote) {
        const wouldNeedMoreThan12 = selected > 0 && Math.ceil(selected / validPerTruck) > 12;
        maxShipmentsNote.style.display = wouldNeedMoreThan12 ? 'block' : 'none';
    }

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
    // Save from the new filter bar (cs_* elements) which are the active ones
    localStorage.setItem('createShipment_palletSearch', document.getElementById('cs_search')?.value || document.getElementById('palletSearch')?.value || '');
    localStorage.setItem('createShipment_projectFilter', document.getElementById('cs_project')?.value || document.getElementById('projectFilter')?.value || '');
    localStorage.setItem('createShipment_wattageFilter', document.getElementById('cs_wattage')?.value || document.getElementById('wattageFilter')?.value || '');
    localStorage.setItem('createShipment_statusFilter', document.getElementById('cs_status')?.value || document.getElementById('statusFilter')?.value || '');
    localStorage.setItem('createShipment_warehouseFilter', document.getElementById('cs_warehouse')?.value || '');
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
        document.getElementById('cs_search').value = '';
        document.getElementById('wattageFilter').value = '';
        document.getElementById('cs_wattage').value = '';
        if (document.getElementById('cs_warehouse')) {
            document.getElementById('cs_warehouse').value = '';
        }
        document.getElementById('itemsPerPage').value = '100'; // Default to 100
        itemsPerPage = 100;
        currentPage = 1;
        
        // Don't clear projectFilter and statusFilter - they should already be set by PHP
        // Apply only the URL parameters that might not be set by PHP
        if (statusFromUrl) {
            document.getElementById('statusFilter').value = statusFromUrl;
            document.getElementById('cs_status').value = statusFromUrl;
        } else {
            // Clear status filters to show all statuses
            document.getElementById('statusFilter').value = '';
            document.getElementById('cs_status').value = '';
        }
        toggleWarehouseSubFilter();
        
        // Project filter should already be correctly set by PHP via the selected attribute
        // No need to manipulate it further
    } else {
        // Not from project_overview.php - use localStorage as before
        const search = localStorage.getItem('createShipment_palletSearch');
        const project = localStorage.getItem('createShipment_projectFilter');
        const wattage = localStorage.getItem('createShipment_wattageFilter');
        const status = localStorage.getItem('createShipment_statusFilter');
        const warehouse = localStorage.getItem('createShipment_warehouseFilter');
        const perPage = localStorage.getItem('createShipment_itemsPerPage');
        const page = localStorage.getItem('createShipment_currentPage');
        
        if (search) {
            document.getElementById('palletSearch').value = search;
            document.getElementById('cs_search').value = search;
        }
        if (project) {
            document.getElementById('projectFilter').value = project;
            document.getElementById('cs_project').value = project;
        }
        if (wattage) {
            document.getElementById('wattageFilter').value = wattage;
            document.getElementById('cs_wattage').value = wattage;
        }
        if (status) {
            document.getElementById('statusFilter').value = status;
            document.getElementById('cs_status').value = status;
        }
        toggleWarehouseSubFilter();
        if (warehouse && document.getElementById('cs_warehouse')) {
            document.getElementById('cs_warehouse').dataset.persistedValue = warehouse;
            document.getElementById('cs_warehouse').value = warehouse;
        }
        
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
