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
        'freight_cost' => ['Freight', 'Freight Cost', 'Cost', 'Shipping Cost', 'Price', 'Rate'],
        'estimated_delivery' => ['Est Delivery', 'Est. Delivery', 'ETA', 'Expected Delivery', 'Estimated Arrival', 'Due Date', 'Expected'],
        'actual_delivery' => ['Actual Delivery', 'Delivered', 'Delivery Date', 'Actual Del', 'Arrival Date']
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

    if (!$destination_id) {
        echo json_encode(['error' => 'Destination is required']);
        return;
    }

    // Parse file
    $result = parseShipmentFile($tempFile, $columnMapping);

    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]);
        return;
    }

    $parsedData = $result['data'];
    $warnings = $result['warnings'];

    // Validate that pallets exist and get their current status/location
    $palletIds = array_unique(array_filter(array_column($parsedData, 'pallet_id')));
    $foundPallets = [];

    if (!empty($palletIds)) {
        $placeholders = str_repeat('?,', count($palletIds) - 1) . '?';
        $types = str_repeat('s', count($palletIds));

        $stmt = $conn->prepare("
            SELECT ip.id, ip.pallet_identifier, ip.status, ip.current_warehouse_id, ip.current_project_id,
                   ip.assigned_project_id, ip.wattage, ip.quantity
            FROM inventory_pallets ip
            WHERE ip.pallet_identifier IN ($placeholders)
        ");

        $stmt->bind_param($types, ...$palletIds);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $foundPallets[$row['pallet_identifier']] = $row;
        }
        $stmt->close();
    }

    // Determine status based on destination type
    $calculatedStatus = ($destination_type === 'project') ? 'In Transit to Project' : 'In Transit to Warehouse';

    // Enrich parsed data with pallet info
    $palletsFound = 0;
    $palletsNotFound = 0;
    $uniqueBols = [];

    foreach ($parsedData as &$row) {
        $palletId = $row['pallet_id'] ?? '';
        if (isset($foundPallets[$palletId])) {
            $row['pallet_found'] = true;
            $row['pallet_db_id'] = $foundPallets[$palletId]['id'];
            $row['current_status'] = $foundPallets[$palletId]['status'];
            $row['wattage'] = $foundPallets[$palletId]['wattage'];
            $row['quantity'] = $foundPallets[$palletId]['quantity'];
            $palletsFound++;
        } else {
            $row['pallet_found'] = false;
            $row['pallet_db_id'] = null;
            $palletsNotFound++;
            $warnings[] = ['row' => $row['_row_number'] ?? 0, 'message' => "Pallet ID '{$palletId}' not found in inventory"];
        }
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
        'pallets_found' => $palletsFound,
        'pallets_not_found' => $palletsNotFound,
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
function parseShipmentFile($filePath, $columnMapping) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $data = [];
    $warnings = [];

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
                    $parsedRow[$fieldKey] = trim($value);
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
                        $parsedRow[$fieldKey] = trim($value);
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

    if (!$destination_id) {
        echo json_encode(['error' => 'Destination is required']);
        return;
    }

    // Parse file again
    $result = parseShipmentFile($tempFile, $columnMapping);

    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]);
        return;
    }

    $parsedData = $result['data'];

    // Get all valid pallet IDs from inventory
    $palletIds = array_unique(array_filter(array_column($parsedData, 'pallet_id')));
    $foundPallets = [];

    if (!empty($palletIds)) {
        $placeholders = str_repeat('?,', count($palletIds) - 1) . '?';
        $types = str_repeat('s', count($palletIds));

        $stmt = $conn->prepare("
            SELECT ip.id, ip.pallet_identifier, ip.status, ip.current_warehouse_id, ip.current_project_id,
                   ip.assigned_project_id, ip.wattage, ip.quantity,
                   COALESCE(ip.manufacturer, m.vendor_name, 'Unknown') as manufacturer
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            WHERE ip.pallet_identifier IN ($placeholders)
        ");

        $stmt->bind_param($types, ...$palletIds);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $foundPallets[$row['pallet_identifier']] = $row;
        }
        $stmt->close();
    }

    // Group rows by BOL number
    $shipmentGroups = [];
    foreach ($parsedData as $row) {
        $bol = $row['bol_number'] ?? '';
        $palletId = $row['pallet_id'] ?? '';

        if (empty($bol) || empty($palletId)) continue;
        if (!isset($foundPallets[$palletId])) continue; // Skip pallets not found

        if (!isset($shipmentGroups[$bol])) {
            $shipmentGroups[$bol] = [
                'bol_number' => $bol,
                'pallets' => [],
                'ship_date' => $row['ship_date'] ?? date('Y-m-d'),
                'freight_cost' => $row['freight_cost'] ?? 0,
                'estimated_delivery' => $row['estimated_delivery'] ?? null,
                'actual_delivery' => $row['actual_delivery'] ?? null
            ];
        }

        $palletInfo = $foundPallets[$palletId];
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

                // Add destination
                if ($destination_type === 'project') {
                    $deliveryColumns[] = 'project_id';
                    $deliveryParams[] = $destination_id;
                    $deliveryTypes .= 'i';
                } else {
                    $deliveryColumns[] = 'warehouse_id';
                    $deliveryParams[] = $destination_id;
                    $deliveryTypes .= 'i';
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
