<?php
/**
 * Process Shipment Upload
 *
 * Backend handler for shipment/delivery imports.
 * Creates delivery records and links existing pallets.
 * Works like create_shipment.php / handle_pallet_move.php - destination can be project or warehouse.
 * Status is determined automatically based on where pallets are coming from and going to.
 *
 * Handles: parse_headers, parse_data, import actions
 */

session_name("logistics_session");
session_start();

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../config.php';
require_once 'milestone_helpers.php';
require_once 'schedule_parser.php';

$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// Handle different actions
switch ($action) {
    case 'parse_headers':
        handleParseHeaders($conn, $user_id);
        break;

    case 'parse_data':
        handleParseData($conn, $user_id);
        break;

    case 'import':
        handleImport($conn, $user_id);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}

$conn->close();

/**
 * Get account ID for a user
 */
function getAccountIdForUser($conn, $user_id) {
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return $row['account_id'];
    }
    $stmt->close();
    return null;
}

/**
 * Parse headers from uploaded file
 */
function handleParseHeaders($conn, $user_id) {
    if (!isset($_FILES['shipment_file']) || $_FILES['shipment_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'No file uploaded or upload error']);
        return;
    }

    $filePath = $_FILES['shipment_file']['tmp_name'];

    // Save file temporarily
    $tempDir = sys_get_temp_dir() . '/shipment_uploads';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . basename($_FILES['shipment_file']['name']);
    move_uploaded_file($filePath, $tempFile);

    // Store temp file path in session
    $_SESSION['shipment_temp_file'] = $tempFile;
    $_SESSION['shipment_original_name'] = $_FILES['shipment_file']['name'];

    // Parse headers
    $parser = new ScheduleParser($tempFile);
    $headers = $parser->parseHeaders();

    if ($errors = $parser->getErrors()) {
        echo json_encode(['error' => implode(', ', $errors)]);
        return;
    }

    // Suggest mappings for shipment-specific fields
    $suggestedMappings = suggestShipmentMappings($headers);

    // Check for saved mapping
    $savedMapping = null;
    $account_id = getAccountIdForUser($conn, $user_id);

    // We don't have manufacturer_id for shipments, so we use a generic "shipment" mapping
    // This could be improved to use per-account mappings

    echo json_encode([
        'success' => true,
        'headers' => $headers,
        'suggested_mappings' => $suggestedMappings,
        'saved_mapping' => $savedMapping
    ]);
}

/**
 * Suggest column mappings for shipment import
 */
function suggestShipmentMappings($headers) {
    $shipmentFields = [
        'bol_number' => ['BOL', 'BOL #', 'BOL Number', 'Bill of Lading', 'B/L', 'Container', 'Container #', 'Container Number', 'CNTR', 'Tracking'],
        'pallet_id' => ['Pallet', 'Pallet #', 'Pallet ID', 'Pallet Number', 'Serial', 'Serial #', 'Pallet No'],
        'ship_date' => ['Ship Date', 'Shipping Date', 'Departure Date', 'Departure', 'Shipped', 'Ship', 'Dispatch Date', 'Date Shipped', 'Ship Dt', 'Shipped Date'],
        'wattage' => ['Wattage', 'Watts', 'W', 'Module Wattage', 'Power'],
        'freight_cost' => ['Freight', 'Freight Cost', 'Cost', 'Shipping Cost', 'Price', 'Rate'],
        'estimated_delivery' => ['Est Delivery', 'Est. Delivery', 'ETA', 'Expected Delivery', 'Estimated Arrival', 'Due Date', 'Expected'],
        'actual_delivery' => ['Actual Delivery', 'Delivered', 'Delivery Date', 'Actual Del', 'Arrival Date'],
        'carrier_name' => ['Carrier', 'Carrier Name', 'Trucking Company', 'Freight Company', 'Transport', 'Hauler', 'Trucker']
    ];

    $suggestions = [];

    foreach ($shipmentFields as $fieldKey => $commonNames) {
        $suggestions[$fieldKey] = null;

        foreach ($headers as $header) {
            $headerLower = strtolower(trim($header));

            foreach ($commonNames as $commonName) {
                if (strtolower($commonName) === $headerLower) {
                    $suggestions[$fieldKey] = $header;
                    break 2;
                }
            }

            // Fuzzy matching
            if ($suggestions[$fieldKey] === null) {
                foreach ($commonNames as $commonName) {
                    if (stripos($header, $commonName) !== false) {
                        $suggestions[$fieldKey] = $header;
                        break 2;
                    }
                }
            }
        }
    }

    return $suggestions;
}

function getShipmentResolutionOptions() {
    $defaultWattageRaw = $_POST['default_wattage'] ?? '';
    $defaultWattage = 0;
    if ($defaultWattageRaw !== '' && $defaultWattageRaw !== null) {
        $defaultWattage = max(0, (int)$defaultWattageRaw);
    }
    return [
        'enable_placeholder_resolution' => !empty($_POST['enable_placeholder_resolution']),
        'origin_type' => trim((string)($_POST['origin_type'] ?? '')),
        'origin_id' => intval($_POST['origin_id'] ?? 0),
        'default_wattage' => $defaultWattage
    ];
}

function getShipmentMatchTypeLabel($matchType) {
    return match ($matchType) {
        'exact_manufacturer_id' => 'Exact Manufacturer ID',
        'exact_pallet_id' => 'Exact Pallet ID',
        'resolved_placeholder' => 'Resolved Placeholder',
        default => 'Not Found',
    };
}

function fetchShipmentExactMatches($conn, $palletIds) {
    $lookup = [
        'manufacturer' => [],
        'pallet' => []
    ];

    if (empty($palletIds)) {
        return $lookup;
    }

    $placeholders = str_repeat('?,', count($palletIds) - 1) . '?';
    $types = str_repeat('s', count($palletIds));
    $params = array_merge($palletIds, $palletIds);

    // Only shippable pallets are eligible. Excludes 'In Transit to ...', 'Delivered ...', 'Canceled',
    // etc. — prevents re-uploads from silently re-shipping a pallet whose manufacturer_pallet_id was
    // previously enriched from a placeholder.
    $stmt = $conn->prepare("
        SELECT ip.id, ip.pallet_identifier, ip.manufacturer_pallet_id, ip.status, ip.current_warehouse_id, ip.current_project_id,
               ip.assigned_project_id, ip.manufacturer_location_id, ip.wattage, ip.quantity,
               COALESCE(ip.manufacturer, m.vendor_name, 'Unknown') AS manufacturer
        FROM inventory_pallets ip
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE (ip.manufacturer_pallet_id IN ($placeholders)
               OR ip.pallet_identifier IN ($placeholders))
          AND ip.status IN ('In Warehouse', 'At Manufacturer')
    ");

    if (!$stmt) {
        throw new Exception('Failed to prepare pallet lookup: ' . $conn->error);
    }

    $stmt->bind_param($types . $types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $manufacturerId = trim((string)($row['manufacturer_pallet_id'] ?? ''));
        $palletIdentifier = trim((string)($row['pallet_identifier'] ?? ''));

        if ($manufacturerId !== '') {
            if (!isset($lookup['manufacturer'][$manufacturerId])) {
                $lookup['manufacturer'][$manufacturerId] = [];
            }
            $lookup['manufacturer'][$manufacturerId][] = $row;
        }

        if ($palletIdentifier !== '') {
            if (!isset($lookup['pallet'][$palletIdentifier])) {
                $lookup['pallet'][$palletIdentifier] = [];
            }
            $lookup['pallet'][$palletIdentifier][] = $row;
        }
    }

    $stmt->close();

    return $lookup;
}

function fetchShipmentPlaceholderCandidates($conn, $wattages, $resolutionOptions, $destinationContext = null) {
    if (empty($resolutionOptions['enable_placeholder_resolution']) || empty($wattages)) {
        return [];
    }

    $originType = $resolutionOptions['origin_type'] ?? '';
    $originId = (int)($resolutionOptions['origin_id'] ?? 0);
    if ($originId <= 0 || !in_array($originType, ['warehouse', 'manufacturer'], true)) {
        return [];
    }

    $wattages = array_values(array_unique(array_filter(array_map('intval', $wattages))));
    if (empty($wattages)) {
        return [];
    }

    $placeholders = str_repeat('?,', count($wattages) - 1) . '?';
    $types = str_repeat('i', count($wattages));
    $params = $wattages;

    $sql = "
        SELECT ip.id, ip.pallet_identifier, ip.manufacturer_pallet_id, ip.status, ip.current_warehouse_id, ip.current_project_id,
               ip.assigned_project_id, ip.manufacturer_location_id, ip.wattage, ip.quantity,
               COALESCE(ip.manufacturer, m.vendor_name, 'Unknown') AS manufacturer
        FROM inventory_pallets ip
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE ip.manufacturer_pallet_id IS NULL
          AND ip.wattage IN ($placeholders)
    ";

    if ($originType === 'warehouse') {
        $sql .= " AND ip.current_warehouse_id = ? AND ip.status = 'In Warehouse'";
        $types .= 'i';
        $params[] = $originId;
    } else {
        $sql .= " AND ip.manufacturer_location_id = ? AND ip.status = 'At Manufacturer'";
        $types .= 'i';
        $params[] = $originId;
    }

    // When shipping to a specific project, only consider placeholders already assigned to that
    // project (or unassigned). Prevents Project A's CSV from claiming Project B's placeholder when
    // a warehouse holds mixed inventory.
    if (is_array($destinationContext)
        && ($destinationContext['type'] ?? '') === 'project'
        && (int)($destinationContext['id'] ?? 0) > 0) {
        $sql .= " AND (ip.assigned_project_id = ? OR ip.assigned_project_id IS NULL)";
        $types .= 'i';
        $params[] = (int)$destinationContext['id'];
    }

    $sql .= " ORDER BY ip.id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare placeholder lookup: ' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $candidatesByWattage = [];
    while ($row = $result->fetch_assoc()) {
        $wattage = (int)($row['wattage'] ?? 0);
        if (!isset($candidatesByWattage[$wattage])) {
            $candidatesByWattage[$wattage] = [];
        }
        $candidatesByWattage[$wattage][] = $row;
    }

    $stmt->close();

    return $candidatesByWattage;
}

function resolveShipmentPallets($conn, $parsedData, $resolutionOptions, &$warnings, $destinationContext = null, $expectedResolution = null) {
    $palletIds = array_values(array_unique(array_filter(array_map(static function ($value) {
        return trim((string)$value);
    }, array_column($parsedData, 'pallet_id')))));

    $exactMatches = fetchShipmentExactMatches($conn, $palletIds);
    $placeholderCandidates = fetchShipmentPlaceholderCandidates($conn, array_column($parsedData, 'wattage'), $resolutionOptions, $destinationContext);

    $resolutionEnabled = !empty($resolutionOptions['enable_placeholder_resolution']);
    $selectedOriginType = $resolutionOptions['origin_type'] ?? '';
    $selectedOriginId = (int)($resolutionOptions['origin_id'] ?? 0);

    // Closure: does this pallet physically sit at the origin the user selected in the UI?
    $originMatchesSelection = static function (array $pallet) use ($selectedOriginType, $selectedOriginId) {
        if ($selectedOriginId <= 0) {
            return true;
        }
        if ($selectedOriginType === 'warehouse') {
            return (int)($pallet['current_warehouse_id'] ?? 0) === $selectedOriginId;
        }
        if ($selectedOriginType === 'manufacturer') {
            return (int)($pallet['manufacturer_location_id'] ?? 0) === $selectedOriginId;
        }
        return true;
    };

    $inputIdCounts = [];
    foreach ($parsedData as $row) {
        $palletId = trim((string)($row['pallet_id'] ?? ''));
        if ($palletId !== '') {
            $inputIdCounts[$palletId] = ($inputIdCounts[$palletId] ?? 0) + 1;
        }
    }

    // Direct Solterra/manufacturer ID matches must win over generic placeholder resolution,
    // regardless of row order in the upload. Otherwise an early unmatched manufacturer ID can
    // consume a placeholder whose pallet_identifier appears later in the same file.
    $exactMatchInventoryIds = [];
    foreach ($parsedData as $row) {
        $uploadedPalletId = trim((string)($row['pallet_id'] ?? ''));
        if ($uploadedPalletId === '' || ($inputIdCounts[$uploadedPalletId] ?? 0) > 1) {
            continue;
        }

        $candidate = null;
        $manufacturerMatches = $exactMatches['manufacturer'][$uploadedPalletId] ?? [];
        if (count($manufacturerMatches) === 1) {
            $candidate = $manufacturerMatches[0];
        } elseif (count($manufacturerMatches) === 0) {
            $palletMatches = $exactMatches['pallet'][$uploadedPalletId] ?? [];
            if (count($palletMatches) === 1) {
                $candidate = $palletMatches[0];
            }
        }

        if ($candidate && (!$resolutionEnabled || $originMatchesSelection($candidate))) {
            $candidateId = (int)($candidate['id'] ?? 0);
            if ($candidateId > 0) {
                $exactMatchInventoryIds[$candidateId] = true;
            }
        }
    }

    $reservedInventoryIds = [];
    $palletsFound = 0;
    $palletsNotFound = 0;
    $placeholderResolved = 0;
    $driftedRows = 0;

    foreach ($parsedData as &$row) {
        $uploadedPalletId = trim((string)($row['pallet_id'] ?? ''));
        $row['pallet_found'] = false;
        $row['pallet_db_id'] = null;
        $row['match_type'] = 'not_found';
        $row['match_type_label'] = getShipmentMatchTypeLabel('not_found');
        $row['resolved_pallet_identifier'] = null;
        $row['resolved_display_identifier'] = null;
        $row['resolved_from_placeholder'] = false;

        if ($uploadedPalletId === '') {
            $palletsNotFound++;
            continue;
        }

        if (($inputIdCounts[$uploadedPalletId] ?? 0) > 1) {
            $palletsNotFound++;
            $warnings[] = ['row' => $row['_row_number'] ?? 0, 'message' => "Pallet ID '{$uploadedPalletId}' appears multiple times in the upload", 'type' => 'error'];
            continue;
        }

        $resolvedPallet = null;
        $matchType = 'not_found';
        $exactMatchMismatchOrigin = false;

        $manufacturerMatches = $exactMatches['manufacturer'][$uploadedPalletId] ?? [];
        if (count($manufacturerMatches) === 1) {
            $candidate = $manufacturerMatches[0];
            if ($resolutionEnabled && !$originMatchesSelection($candidate)) {
                $exactMatchMismatchOrigin = true;
            } else {
                $resolvedPallet = $candidate;
                $matchType = 'exact_manufacturer_id';
            }
        } elseif (count($manufacturerMatches) > 1) {
            $palletsNotFound++;
            $warnings[] = ['row' => $row['_row_number'] ?? 0, 'message' => "Pallet ID '{$uploadedPalletId}' matched multiple manufacturer pallet IDs", 'type' => 'error'];
            continue;
        }

        if (!$resolvedPallet && !$exactMatchMismatchOrigin) {
            $palletMatches = $exactMatches['pallet'][$uploadedPalletId] ?? [];
            if (count($palletMatches) === 1) {
                $candidate = $palletMatches[0];
                if ($resolutionEnabled && !$originMatchesSelection($candidate)) {
                    $exactMatchMismatchOrigin = true;
                } else {
                    $resolvedPallet = $candidate;
                    $matchType = 'exact_pallet_id';
                }
            } elseif (count($palletMatches) > 1) {
                $palletsNotFound++;
                $warnings[] = ['row' => $row['_row_number'] ?? 0, 'message' => "Pallet ID '{$uploadedPalletId}' matched multiple pallet records", 'type' => 'error'];
                continue;
            }
        }

        if ($exactMatchMismatchOrigin) {
            $palletsNotFound++;
            $warnings[] = ['row' => $row['_row_number'] ?? 0, 'message' => "Pallet ID '{$uploadedPalletId}' exists but is not at the selected origin", 'type' => 'error'];
            continue;
        }

        if (!$resolvedPallet && $resolutionEnabled) {
            $rowWattage = (int)($row['wattage'] ?? 0);
            if ($rowWattage <= 0) {
                $warnings[] = ['row' => $row['_row_number'] ?? 0, 'message' => "Pallet ID '{$uploadedPalletId}' could not be resolved because wattage is missing", 'type' => 'error'];
            } else {
                foreach (($placeholderCandidates[$rowWattage] ?? []) as $candidate) {
                    $candidateId = (int)($candidate['id'] ?? 0);
                    if ($candidateId > 0
                        && !isset($reservedInventoryIds[$candidateId])
                        && !isset($exactMatchInventoryIds[$candidateId])) {
                        $resolvedPallet = $candidate;
                        $matchType = 'resolved_placeholder';
                        break;
                    }
                }
            }
        }

        if ($resolvedPallet) {
            $resolvedId = (int)($resolvedPallet['id'] ?? 0);
            if ($resolvedId > 0 && isset($reservedInventoryIds[$resolvedId])) {
                $palletsNotFound++;
                $warnings[] = ['row' => $row['_row_number'] ?? 0, 'message' => "Pallet ID '{$uploadedPalletId}' would reuse a pallet already assigned earlier in this upload", 'type' => 'error'];
                continue;
            }

            $reservedInventoryIds[$resolvedId] = true;
            $row['pallet_found'] = true;
            $row['pallet_db_id'] = $resolvedId;
            $row['current_status'] = $resolvedPallet['status'];
            $row['wattage'] = (int)($resolvedPallet['wattage'] ?? 0);
            $row['quantity'] = (int)($resolvedPallet['quantity'] ?? 0);
            $row['resolved_manufacturer'] = $resolvedPallet['manufacturer'] ?? 'Unknown';
            $row['resolved_pallet_identifier'] = $resolvedPallet['pallet_identifier'] ?? null;
            $row['resolved_assigned_project_id'] = (int)($resolvedPallet['assigned_project_id'] ?? 0) ?: null;
            $row['resolved_display_identifier'] = ($matchType === 'resolved_placeholder')
                ? ($resolvedPallet['pallet_identifier'] ?? null)
                : (($resolvedPallet['manufacturer_pallet_id'] ?: $resolvedPallet['pallet_identifier']) ?? null);
            $row['resolved_from_placeholder'] = ($matchType === 'resolved_placeholder');
            $row['match_type'] = $matchType;
            $row['match_type_label'] = getShipmentMatchTypeLabel($matchType);
            $palletsFound++;
            if ($matchType === 'resolved_placeholder') {
                $placeholderResolved++;
            }
        } else {
            $palletsNotFound++;
            $warnings[] = ['row' => $row['_row_number'] ?? 0, 'message' => "Pallet ID '{$uploadedPalletId}' not found in inventory", 'type' => 'error'];
        }
    }
    unset($row);

    // Drift check: if a preview resolution was provided (commit phase), any row whose resolved
    // pallet_db_id differs from what the user saw in preview gets demoted to failed. Prevents
    // silent substitution when a placeholder is claimed by another process between preview and
    // commit.
    if (is_array($expectedResolution) && !empty($expectedResolution)) {
        foreach ($parsedData as &$row) {
            $rowKey = (int)($row['_row_number'] ?? 0);
            if ($rowKey <= 0 || !array_key_exists($rowKey, $expectedResolution)) {
                continue;
            }
            $expectedId = (int)$expectedResolution[$rowKey];
            $currentId = (int)($row['pallet_db_id'] ?? 0);
            if ($expectedId <= 0 || $expectedId === $currentId) {
                continue;
            }

            // Only rebalance counters if this row was previously found. If it was already
            // not_found, it's already in $palletsNotFound from the main loop — don't double-count.
            if (!empty($row['pallet_found'])) {
                $palletsFound--;
                $palletsNotFound++;
                if (($row['match_type'] ?? '') === 'resolved_placeholder') {
                    $placeholderResolved--;
                }
            }
            $row['pallet_found'] = false;
            $row['pallet_db_id'] = null;
            $row['match_type'] = 'not_found';
            $row['match_type_label'] = getShipmentMatchTypeLabel('not_found');
            $row['resolved_pallet_identifier'] = null;
            $row['resolved_display_identifier'] = null;
            $row['resolved_from_placeholder'] = false;
            $driftedRows++;
            $warnings[] = ['row' => $rowKey, 'message' => "Pallet ID '" . trim((string)($row['pallet_id'] ?? '')) . "' changed since preview — please re-run preview", 'type' => 'error'];
        }
        unset($row);
    }

    return [
        'rows' => $parsedData,
        'pallets_found' => $palletsFound,
        'pallets_not_found' => $palletsNotFound,
        'placeholder_resolved' => $placeholderResolved,
        'drifted_rows' => $driftedRows
    ];
}

/**
 * Parse data with column mapping and validate pallets exist
 */
function handleParseData($conn, $user_id) {
    $tempFile = $_SESSION['shipment_temp_file'] ?? null;

    // If file is re-uploaded, save it
    if (isset($_FILES['shipment_file']) && $_FILES['shipment_file']['error'] === UPLOAD_ERR_OK) {
        $tempDir = sys_get_temp_dir() . '/shipment_uploads';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . basename($_FILES['shipment_file']['name']);
        move_uploaded_file($_FILES['shipment_file']['tmp_name'], $tempFile);
        $_SESSION['shipment_temp_file'] = $tempFile;
        $_SESSION['shipment_original_name'] = $_FILES['shipment_file']['name'];
    }

    if (!$tempFile || !file_exists($tempFile)) {
        echo json_encode(['error' => 'File not found. Please re-upload.']);
        return;
    }

    $columnMapping = json_decode($_POST['column_mapping'] ?? '{}', true);
    $destination_type = $_POST['destination_type'] ?? 'project';
    $destination_id = intval($_POST['destination_id'] ?? 0);
    $account_id = intval($_POST['account_id'] ?? 0) ?: getAccountIdForUser($conn, $user_id);
    $resolutionOptions = getShipmentResolutionOptions();

    if (!$destination_id) {
        echo json_encode(['error' => 'Destination is required']);
        return;
    }

    if (!empty($resolutionOptions['enable_placeholder_resolution'])) {
        if ($resolutionOptions['origin_id'] <= 0 || !in_array($resolutionOptions['origin_type'], ['warehouse', 'manufacturer'], true)) {
            echo json_encode(['error' => 'Origin is required when placeholder resolution is enabled']);
            return;
        }
    }

    // Parse file
    $result = parseShipmentFile($tempFile, $columnMapping, $resolutionOptions);

    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]);
        return;
    }

    $parsedData = $result['data'];
    $warnings = $result['warnings'];

    // Determine status based on destination type
    $calculatedStatus = ($destination_type === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';
    $destinationContext = ['type' => $destination_type, 'id' => $destination_id];
    $resolutionResult = resolveShipmentPallets($conn, $parsedData, $resolutionOptions, $warnings, $destinationContext);
    $parsedData = $resolutionResult['rows'];
    $uniqueBols = [];

    foreach ($parsedData as &$row) {
        $row['calculated_status'] = $calculatedStatus;

        if (!empty($row['bol_number'])) {
            $uniqueBols[$row['bol_number']] = true;
        }
    }
    unset($row);

    // Check for existing BOL/Container numbers in the database (duplicate shipments)
    // The deliveries table has separate bol_number and container_number columns
    $existingBols = [];
    $existingBolsList = [];
    if (!empty($uniqueBols)) {
        $bolNumbers = array_keys($uniqueBols);
        $placeholders = str_repeat('?,', count($bolNumbers) - 1) . '?';
        $types = str_repeat('s', count($bolNumbers));

        // Check both bol_number and container_number columns
        $stmt = $conn->prepare("
            SELECT DISTINCT
                CASE
                    WHEN bol_number IN ($placeholders) THEN bol_number
                    WHEN container_number IN ($placeholders) THEN container_number
                END as matched_bol,
                id, created_at
            FROM deliveries
            WHERE bol_number IN ($placeholders) OR container_number IN ($placeholders)
        ");

        // We need to pass the parameters 4 times (for each IN clause)
        $allParams = array_merge($bolNumbers, $bolNumbers, $bolNumbers, $bolNumbers);
        $allTypes = str_repeat('s', count($allParams));

        $stmt->bind_param($allTypes, ...$allParams);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['matched_bol'])) {
                $existingBols[$row['matched_bol']] = [
                    'id' => $row['id'],
                    'created_at' => $row['created_at']
                ];
                if (!in_array($row['matched_bol'], $existingBolsList)) {
                    $existingBolsList[] = $row['matched_bol'];
                }
            }
        }
        $stmt->close();
    }

    $existingBolCount = count($existingBols);
    $newBolCount = count($uniqueBols) - $existingBolCount;

    // Summary
    $summary = [
        'total_pallets' => count($parsedData),
        'pallets_found' => $resolutionResult['pallets_found'],
        'pallets_not_found' => $resolutionResult['pallets_not_found'],
        'placeholder_resolved' => $resolutionResult['placeholder_resolved'],
        'unique_shipments' => count($uniqueBols),
        'existing_bols' => $existingBolCount,
        'new_bols' => $newBolCount,
        'existing_bol_list' => $existingBolsList
    ];

    // Store parsed data in session
    $_SESSION['shipment_parsed_data'] = $parsedData;
    $_SESSION['shipment_column_mapping'] = $columnMapping;

    echo json_encode([
        'success' => true,
        'data' => $parsedData,
        'summary' => $summary,
        'warnings' => $warnings
    ]);
}

/**
 * Parse shipment file with column mapping
 */
function parseShipmentFile($filePath, $columnMapping, $options = []) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $data = [];
    $warnings = [];
    $resolutionEnabled = !empty($options['enable_placeholder_resolution']);
    $defaultWattage = (int)($options['default_wattage'] ?? 0);

    if ($extension === 'csv') {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ['error' => 'Could not open file'];
        }

        // Detect delimiter
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = detectDelimiter($firstLine);

        // Get headers
        $headers = fgetcsv($handle, 0, $delimiter);
        $headers = array_map(function($h) {
            $h = trim($h);
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            return $h;
        }, $headers);

        // Create header index
        $headerIndex = [];
        foreach ($headers as $idx => $header) {
            $headerIndex[$header] = $idx;
        }

        $rowNumber = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            $parsedRow = ['_row_number' => $rowNumber];

            // Map columns
            foreach ($columnMapping as $fieldKey => $headerName) {
                if (!empty($headerName) && isset($headerIndex[$headerName])) {
                    $value = $row[$headerIndex[$headerName]] ?? '';
                    $parsedRow[$fieldKey] = is_string($value) ? trim($value) : $value;
                } else {
                    $parsedRow[$fieldKey] = null;
                }
            }

            // Validate required fields
            if (empty($parsedRow['bol_number'])) {
                $warnings[] = ['row' => $rowNumber, 'message' => 'Missing BOL/Container number', 'type' => 'error'];
                continue;
            }
            if (empty($parsedRow['pallet_id'])) {
                $warnings[] = ['row' => $rowNumber, 'message' => 'Missing Pallet ID', 'type' => 'error'];
                continue;
            }
            if (empty($parsedRow['ship_date'])) {
                $warnings[] = ['row' => $rowNumber, 'message' => 'Missing Ship Date', 'type' => 'error'];
                continue;
            }

            // Data quality validations
            $bolNumber = $parsedRow['bol_number'];
            $palletId = $parsedRow['pallet_id'];
            if ($parsedRow['wattage'] !== null && $parsedRow['wattage'] !== '') {
                $parsedRow['wattage'] = intval(preg_replace('/[^0-9\-]/', '', (string)$parsedRow['wattage']));
            }
            if (($parsedRow['wattage'] === null || $parsedRow['wattage'] === '' || (int)$parsedRow['wattage'] <= 0) && $defaultWattage > 0) {
                $parsedRow['wattage'] = $defaultWattage;
            }
            if ($resolutionEnabled && (empty($parsedRow['wattage']) || (int)$parsedRow['wattage'] <= 0)) {
                $warnings[] = ['row' => $rowNumber, 'message' => 'Missing Wattage (map a column or set a default wattage)', 'type' => 'error'];
                continue;
            }

            // BOL format validation
            if (strlen($bolNumber) < 3) {
                $warnings[] = ['row' => $rowNumber, 'message' => "BOL '{$bolNumber}' is very short - verify this is correct", 'type' => 'warning'];
            }

            // Pallet ID validation
            if (strlen($palletId) < 3) {
                $warnings[] = ['row' => $rowNumber, 'message' => "Pallet ID '{$palletId}' is very short - verify this is correct", 'type' => 'warning'];
            }
            if (preg_match('/^\d{1,5}$/', $palletId)) {
                $warnings[] = ['row' => $rowNumber, 'message' => "Pallet ID '{$palletId}' looks like a row number - verify correct column", 'type' => 'warning'];
            }
            if (!empty($parsedRow['wattage']) && ((int)$parsedRow['wattage'] < 100 || (int)$parsedRow['wattage'] > 1000)) {
                $warnings[] = ['row' => $rowNumber, 'message' => "Wattage '{$parsedRow['wattage']}' looks unusual - verify this is correct", 'type' => 'warning'];
            }

            // Parse dates
            if (!empty($parsedRow['ship_date'])) {
                $parsedRow['ship_date'] = parseDate($parsedRow['ship_date']);
                if (empty($parsedRow['ship_date'])) {
                    $warnings[] = ['row' => $rowNumber, 'message' => 'Invalid Ship Date format'];
                    continue;
                }
            }
            if (!empty($parsedRow['estimated_delivery'])) {
                $parsedRow['estimated_delivery'] = parseDate($parsedRow['estimated_delivery']);
            }
            if (!empty($parsedRow['actual_delivery'])) {
                $parsedRow['actual_delivery'] = parseDate($parsedRow['actual_delivery']);
            }

            // Parse freight cost
            if (!empty($parsedRow['freight_cost'])) {
                $parsedRow['freight_cost'] = parseFloat($parsedRow['freight_cost']);
            }

            $data[] = $parsedRow;
        }
        fclose($handle);

    } else {
        // Excel file
        if (!ScheduleParser::isExcelSupported()) {
            return ['error' => 'Excel file support not available'];
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (empty($rows)) {
                return ['error' => 'File is empty'];
            }

            $headers = array_map('trim', $rows[0]);
            $headerIndex = [];
            foreach ($headers as $idx => $header) {
                $headerIndex[$header] = $idx;
            }

            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $rowNumber = $i + 1;

                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                $parsedRow = ['_row_number' => $rowNumber];

                // Map columns
                foreach ($columnMapping as $fieldKey => $headerName) {
                    if (!empty($headerName) && isset($headerIndex[$headerName])) {
                        $value = $row[$headerIndex[$headerName]] ?? '';
                        $parsedRow[$fieldKey] = is_string($value) ? trim($value) : $value;
                    } else {
                        $parsedRow[$fieldKey] = null;
                    }
                }

                // Validate required fields
                if (empty($parsedRow['bol_number'])) {
                    $warnings[] = ['row' => $rowNumber, 'message' => 'Missing BOL/Container number'];
                    continue;
                }
                if (empty($parsedRow['pallet_id'])) {
                    $warnings[] = ['row' => $rowNumber, 'message' => 'Missing Pallet ID'];
                    continue;
                }
                if (empty($parsedRow['ship_date'])) {
                    $warnings[] = ['row' => $rowNumber, 'message' => 'Missing Ship Date'];
                    continue;
                }

                if ($parsedRow['wattage'] !== null && $parsedRow['wattage'] !== '') {
                    $parsedRow['wattage'] = intval(preg_replace('/[^0-9\-]/', '', (string)$parsedRow['wattage']));
                }
                if (($parsedRow['wattage'] === null || $parsedRow['wattage'] === '' || (int)$parsedRow['wattage'] <= 0) && $defaultWattage > 0) {
                    $parsedRow['wattage'] = $defaultWattage;
                }
                if ($resolutionEnabled && (empty($parsedRow['wattage']) || (int)$parsedRow['wattage'] <= 0)) {
                    $warnings[] = ['row' => $rowNumber, 'message' => 'Missing Wattage (map a column or set a default wattage)', 'type' => 'error'];
                    continue;
                }

                // Pallet ID shape validation (soft warnings only)
                $palletId = (string)$parsedRow['pallet_id'];
                if (strlen($palletId) < 3) {
                    $warnings[] = ['row' => $rowNumber, 'message' => "Pallet ID '{$palletId}' is very short - verify this is correct", 'type' => 'warning'];
                }
                if (preg_match('/^\d{1,5}$/', $palletId)) {
                    $warnings[] = ['row' => $rowNumber, 'message' => "Pallet ID '{$palletId}' looks like a row number - verify correct column", 'type' => 'warning'];
                }

                // Parse dates
                if (!empty($parsedRow['ship_date'])) {
                    $parsedRow['ship_date'] = parseDate($parsedRow['ship_date']);
                    if (empty($parsedRow['ship_date'])) {
                        $warnings[] = ['row' => $rowNumber, 'message' => 'Invalid Ship Date format'];
                        continue;
                    }
                }
                if (!empty($parsedRow['estimated_delivery'])) {
                    $parsedRow['estimated_delivery'] = parseDate($parsedRow['estimated_delivery']);
                }
                if (!empty($parsedRow['actual_delivery'])) {
                    $parsedRow['actual_delivery'] = parseDate($parsedRow['actual_delivery']);
                }

                // Parse freight cost
                if (!empty($parsedRow['freight_cost'])) {
                    $parsedRow['freight_cost'] = parseFloat($parsedRow['freight_cost']);
                }

                if (!empty($parsedRow['wattage']) && ((int)$parsedRow['wattage'] < 100 || (int)$parsedRow['wattage'] > 1000)) {
                    $warnings[] = ['row' => $rowNumber, 'message' => "Wattage '{$parsedRow['wattage']}' looks unusual - verify this is correct", 'type' => 'warning'];
                }

                $data[] = $parsedRow;
            }
        } catch (Exception $e) {
            return ['error' => 'Error reading Excel file: ' . $e->getMessage()];
        }
    }

    return ['data' => $data, 'warnings' => $warnings];
}

/**
 * Detect CSV delimiter
 */
function detectDelimiter($line) {
    $delimiters = [',', ';', "\t", '|'];
    $counts = [];
    foreach ($delimiters as $d) {
        $counts[$d] = substr_count($line, $d);
    }
    return array_search(max($counts), $counts);
}

/**
 * Parse date string to Y-m-d format
 */
function parseDate($dateStr) {
    if (empty($dateStr)) return null;

    // Try various formats
    $formats = ['Y-m-d', 'm/d/Y', 'd/m/Y', 'Y/m/d', 'm-d-Y', 'd-m-Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateStr);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }

    // Try strtotime
    $timestamp = strtotime($dateStr);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return null;
}

/**
 * Parse float from string (handles currency formatting)
 */
function parseFloat($str) {
    if (empty($str)) return 0.0;
    $str = preg_replace('/[^0-9.\-]/', '', $str);
    return floatval($str);
}

/**
 * Import shipments - create deliveries and link pallets
 * This works similar to handle_pallet_move.php
 */
function handleImport($conn, $user_id) {
    $tempFile = $_SESSION['shipment_temp_file'] ?? null;

    // If file is re-uploaded, save it
    if (isset($_FILES['shipment_file']) && $_FILES['shipment_file']['error'] === UPLOAD_ERR_OK) {
        $tempDir = sys_get_temp_dir() . '/shipment_uploads';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . basename($_FILES['shipment_file']['name']);
        move_uploaded_file($_FILES['shipment_file']['tmp_name'], $tempFile);
        $_SESSION['shipment_temp_file'] = $tempFile;
        $_SESSION['shipment_original_name'] = $_FILES['shipment_file']['name'];
    }

    if (!$tempFile || !file_exists($tempFile)) {
        echo json_encode(['error' => 'File not found. Please re-upload.']);
        return;
    }

    $columnMapping = json_decode($_POST['column_mapping'] ?? '{}', true);
    $destination_type = $_POST['destination_type'] ?? 'project';
    $destination_id = intval($_POST['destination_id'] ?? 0);
    $account_id = intval($_POST['account_id'] ?? 0) ?: getAccountIdForUser($conn, $user_id);
    $default_carrier_id = intval($_POST['default_carrier_id'] ?? 0) ?: null;
    $resolutionOptions = getShipmentResolutionOptions();

    // Build carrier name-to-id lookup for fuzzy matching from file
    $carrier_lookup = [];
    $stmtCL = $conn->prepare("SELECT id, name, short_name FROM carriers WHERE is_active = 1");
    if ($stmtCL) {
        $stmtCL->execute();
        $resCL = $stmtCL->get_result();
        while ($cl = $resCL->fetch_assoc()) {
            $carrier_lookup[] = $cl;
        }
        $stmtCL->close();
    }

    if (!$destination_id) {
        echo json_encode(['error' => 'Destination is required']);
        return;
    }

    if (!empty($resolutionOptions['enable_placeholder_resolution'])) {
        if ($resolutionOptions['origin_id'] <= 0 || !in_array($resolutionOptions['origin_type'], ['warehouse', 'manufacturer'], true)) {
            echo json_encode(['error' => 'Origin is required when placeholder resolution is enabled']);
            return;
        }
    }

    // Parse file again
    $result = parseShipmentFile($tempFile, $columnMapping, $resolutionOptions);

    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]);
        return;
    }

    $parsedData = $result['data'];
    $warnings = $result['warnings'];

    // Preview resolution map: _row_number => pallet_db_id chosen during preview. Enforces that
    // commit does not silently assign a different placeholder (or different tier-1 match) than
    // the user saw and approved.
    $expectedResolution = [];
    $previewResolutionJson = $_POST['preview_resolution'] ?? '';
    if (is_string($previewResolutionJson) && $previewResolutionJson !== '') {
        $decoded = json_decode($previewResolutionJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                $rowNumber = (int)($entry['row'] ?? 0);
                $palletDbId = (int)($entry['pallet_db_id'] ?? 0);
                if ($rowNumber > 0 && $palletDbId > 0) {
                    $expectedResolution[$rowNumber] = $palletDbId;
                }
            }
        }
    }

    $destinationContext = ['type' => $destination_type, 'id' => $destination_id];
    $resolutionResult = resolveShipmentPallets($conn, $parsedData, $resolutionOptions, $warnings, $destinationContext, $expectedResolution);
    $parsedData = $resolutionResult['rows'];

    if (!empty($resolutionResult['drifted_rows'])) {
        echo json_encode([
            'error' => 'Inventory changed since preview — ' . (int)$resolutionResult['drifted_rows'] . ' row(s) can no longer use the pallet shown in preview. Please re-run preview.',
            'warnings' => $warnings
        ]);
        return;
    }

    // Group rows by BOL number
    $shipmentGroups = [];
    foreach ($parsedData as $row) {
        $bol = $row['bol_number'] ?? '';
        $palletId = $row['pallet_id'] ?? '';

        if (empty($bol) || empty($palletId)) continue;
        if (empty($row['pallet_found']) || empty($row['pallet_db_id'])) continue;

        if (!isset($shipmentGroups[$bol])) {
            $shipmentGroups[$bol] = [
                'bol_number' => $bol,
                'pallets' => [],
                'ship_date' => $row['ship_date'] ?? date('Y-m-d'),
                'freight_cost' => $row['freight_cost'] ?? 0,
                'estimated_delivery' => $row['estimated_delivery'] ?? null,
                'actual_delivery' => $row['actual_delivery'] ?? null,
                'carrier_name' => $row['carrier_name'] ?? '',
                'origin_type' => !empty($resolutionOptions['enable_placeholder_resolution']) ? $resolutionOptions['origin_type'] : null,
                'origin_id' => !empty($resolutionOptions['enable_placeholder_resolution']) ? $resolutionOptions['origin_id'] : null
            ];
        }

        $palletInfo = [
            'id' => (int)$row['pallet_db_id'],
            'pallet_identifier' => $row['resolved_pallet_identifier'] ?? null,
            'status' => $row['current_status'] ?? null,
            'wattage' => (int)($row['wattage'] ?? 0),
            'quantity' => (int)($row['quantity'] ?? 0),
            'manufacturer' => $row['resolved_manufacturer'] ?? 'Unknown',
            'match_type' => $row['match_type'] ?? 'exact_pallet_id',
            'uploaded_pallet_id' => $row['pallet_id'] ?? '',
            'assigned_project_id' => $row['resolved_assigned_project_id'] ?? null
        ];
        $palletInfo['from_row'] = $row;
        $shipmentGroups[$bol]['pallets'][] = $palletInfo;
    }

    if (empty($shipmentGroups)) {
        echo json_encode(['error' => 'No valid shipments to import (no pallets found)']);
        return;
    }

    // Determine status based on destination type
    $deliveryStatus = ($destination_type === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';
    $palletStatus = ($destination_type === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';

    $conn->begin_transaction();

    try {
        $deliveriesCreated = 0;
        $palletsLinked = 0;
        $palletsSkipped = 0;
        $createdDeliveryIds = [];

        // Prepare statements
        $stmtLink = $conn->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
        $stmtPalletUpdate = $conn->prepare("
            UPDATE inventory_pallets
            SET status = ?, current_project_id = ?, current_warehouse_id = ?, arrival_date = ?
            WHERE id = ?
        ");
        $stmtManufacturerLink = $conn->prepare("
            UPDATE inventory_pallets
            SET manufacturer_pallet_id = ?
            WHERE id = ? AND manufacturer_pallet_id IS NULL
        ");
        if (!$stmtLink || !$stmtPalletUpdate || !$stmtManufacturerLink) {
            throw new Exception('Failed to prepare shipment import statements: ' . $conn->error);
        }

        foreach ($shipmentGroups as $bol => $group) {
            if (empty($group['pallets'])) {
                continue;
            }

            // Group pallets by wattage for delivery records
            $palletsByWattage = [];
            foreach ($group['pallets'] as $pallet) {
                $wattage = $pallet['wattage'] ?? 0;
                if (!isset($palletsByWattage[$wattage])) {
                    $palletsByWattage[$wattage] = [];
                }
                $palletsByWattage[$wattage][] = $pallet;
            }

            foreach ($palletsByWattage as $wattage => $pallets) {
                $totalQuantity = array_sum(array_column($pallets, 'quantity'));
                $manufacturer = $pallets[0]['manufacturer'] ?? 'Unknown';

                // Build delivery insert
                $deliveryColumns = ['supplier', 'wattage', 'quantity', 'bol_number', 'status_of_delivery'];
                $deliveryParams = [$manufacturer, $wattage, $totalQuantity, $bol, $deliveryStatus];
                $deliveryTypes = 'siiss';

                if (!empty($group['origin_type']) && !empty($group['origin_id'])) {
                    $deliveryColumns[] = 'origin_type';
                    $deliveryColumns[] = 'origin_id';
                    $deliveryParams[] = $group['origin_type'];
                    $deliveryParams[] = (int)$group['origin_id'];
                    $deliveryTypes .= 'si';
                }

                // Add destination. For warehouse-destined deliveries, also set project_id when
                // every pallet in the group shares an assigned_project_id — otherwise the project
                // can't see this leg of its own shipment history (view_project.php filters on
                // deliveries.project_id). Mirrors the behavior in handle_pallet_move.php.
                if ($destination_type === 'project') {
                    $deliveryColumns[] = 'project_id';
                    $deliveryParams[] = $destination_id;
                    $deliveryTypes .= 'i';
                } else {
                    $deliveryColumns[] = 'warehouse_id';
                    $deliveryParams[] = $destination_id;
                    $deliveryTypes .= 'i';

                    $groupProjectIds = array_values(array_unique(array_filter(
                        array_map(static function ($p) {
                            return (int)($p['assigned_project_id'] ?? 0);
                        }, $pallets),
                        static function ($id) { return $id > 0; }
                    )));
                    if (count($groupProjectIds) === 1) {
                        $deliveryColumns[] = 'project_id';
                        $deliveryParams[] = $groupProjectIds[0];
                        $deliveryTypes .= 'i';
                    }
                }

                // Add estimated delivery date
                $estDelivery = $group['estimated_delivery'] ?? null;
                if ($estDelivery) {
                    $deliveryColumns[] = 'anticipated_delivery_date';
                    $deliveryParams[] = $estDelivery;
                    $deliveryTypes .= 's';
                }

                // Add actual delivery date
                $actualDelivery = $group['actual_delivery'] ?? null;
                if ($actualDelivery) {
                    $deliveryColumns[] = 'actual_delivery_date';
                    $deliveryParams[] = $actualDelivery;
                    $deliveryTypes .= 's';
                }

                // Add freight cost (proportionally distributed by this wattage group)
                $totalPalletsInBol = count($group['pallets']);
                $palletsInThisGroup = count($pallets);
                $proportionalFreightCost = 0;
                if ($totalPalletsInBol > 0 && ($group['freight_cost'] ?? 0) > 0) {
                    $proportionalFreightCost = ($group['freight_cost'] / $totalPalletsInBol) * $palletsInThisGroup;
                }
                $deliveryColumns[] = 'freight_cost';
                $deliveryParams[] = $proportionalFreightCost;
                $deliveryTypes .= 'd';

                // Add ship date (from file data)
                $shipDate = $group['ship_date'] ?? date('Y-m-d');
                $deliveryColumns[] = 'created_at';
                $deliveryParams[] = $shipDate . ' ' . date('H:i:s');
                $deliveryTypes .= 's';

                // Add carrier_id (from file column or default)
                $resolved_carrier_id = null;
                $carrier_name_from_file = trim($group['carrier_name'] ?? '');
                if ($carrier_name_from_file !== '') {
                    // Fuzzy match against carriers table
                    foreach ($carrier_lookup as $cl) {
                        if (strcasecmp($cl['name'], $carrier_name_from_file) === 0
                            || ($cl['short_name'] && strcasecmp($cl['short_name'], $carrier_name_from_file) === 0)) {
                            $resolved_carrier_id = (int)$cl['id'];
                            break;
                        }
                    }
                    // Substring fallback
                    if (!$resolved_carrier_id) {
                        foreach ($carrier_lookup as $cl) {
                            if (stripos($cl['name'], $carrier_name_from_file) !== false
                                || ($cl['short_name'] && stripos($cl['short_name'], $carrier_name_from_file) !== false)) {
                                $resolved_carrier_id = (int)$cl['id'];
                                break;
                            }
                        }
                    }
                }
                if (!$resolved_carrier_id && $default_carrier_id) {
                    $resolved_carrier_id = $default_carrier_id;
                }
                if ($resolved_carrier_id) {
                    $deliveryColumns[] = 'carrier_id';
                    $deliveryParams[] = $resolved_carrier_id;
                    $deliveryTypes .= 'i';
                }

                // Create delivery record
                $placeholders = str_repeat('?,', count($deliveryParams) - 1) . '?';
                $sql = 'INSERT INTO deliveries (' . implode(',', $deliveryColumns) . ') VALUES (' . $placeholders . ')';
                $stmtDelivery = $conn->prepare($sql);

                if (!$stmtDelivery) {
                    throw new Exception('Failed to prepare delivery insert: ' . $conn->error);
                }

                $stmtDelivery->bind_param($deliveryTypes, ...$deliveryParams);

                if (!$stmtDelivery->execute()) {
                    throw new Exception('Failed to insert delivery: ' . $stmtDelivery->error);
                }

                $deliveryId = $conn->insert_id;
                $createdDeliveryIds[] = $deliveryId;
                $deliveriesCreated++;
                $stmtDelivery->close();

                // Link pallets to delivery and update their status
                foreach ($pallets as $pallet) {
                    if (($pallet['match_type'] ?? '') === 'resolved_placeholder') {
                        $uploadedPalletId = trim((string)($pallet['uploaded_pallet_id'] ?? ''));
                        if ($uploadedPalletId === '') {
                            throw new Exception('Resolved placeholder is missing uploaded pallet ID.');
                        }
                        $stmtManufacturerLink->bind_param('si', $uploadedPalletId, $pallet['id']);
                        if (!$stmtManufacturerLink->execute()) {
                            throw new Exception('Failed to link manufacturer pallet ID: ' . $stmtManufacturerLink->error);
                        }
                        if ($stmtManufacturerLink->affected_rows < 1) {
                            throw new Exception("Placeholder pallet '{$pallet['pallet_identifier']}' is no longer available for linking.");
                        }
                    }

                    // Link to delivery
                    $stmtLink->bind_param('ii', $deliveryId, $pallet['id']);
                    if (!$stmtLink->execute()) {
                        throw new Exception('Failed to link pallet: ' . $stmtLink->error);
                    }

                    // Update pallet status
                    $newProjectId = ($destination_type === 'project') ? $destination_id : null;
                    $newWarehouseId = ($destination_type === 'warehouse') ? $destination_id : null;
                    $arrivalDate = $estDelivery ?? null;

                    $stmtPalletUpdate->bind_param('siisi', $palletStatus, $newProjectId, $newWarehouseId, $arrivalDate, $pallet['id']);
                    if (!$stmtPalletUpdate->execute()) {
                        throw new Exception('Failed to update pallet: ' . $stmtPalletUpdate->error);
                    }

                    $palletsLinked++;
                }

                // Trigger milestones now that pallets are linked
                trigger_delivery_milestones_for_status($deliveryId, $deliveryStatus, $conn, $user_id);
            }
        }

        $stmtLink->close();
        $stmtPalletUpdate->close();
        $stmtManufacturerLink->close();

        // Calculate skipped pallets
        $totalPalletsInFile = count($parsedData);
        $palletsSkipped = $totalPalletsInFile - $palletsLinked;

        $conn->commit();

        // Cleanup
        unset($_SESSION['shipment_temp_file']);
        unset($_SESSION['shipment_original_name']);
        unset($_SESSION['shipment_parsed_data']);
        unset($_SESSION['shipment_column_mapping']);

        echo json_encode([
            'success' => true,
            'deliveries_created' => $deliveriesCreated,
            'pallets_linked' => $palletsLinked,
            'pallets_skipped' => $palletsSkipped,
            'delivery_ids' => $createdDeliveryIds
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
    }
}
