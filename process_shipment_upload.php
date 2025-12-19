<?php
/**
 * Process Shipment Upload
 *
 * Backend handler for shipment/delivery imports.
 * Creates delivery records and links existing pallets.
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
 * Parse headers from uploaded file
 */
function handleParseHeaders($conn, $user_id) {
    if (!isset($_FILES['shipment_file']) || $_FILES['shipment_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'No file uploaded or upload error']);
        return;
    }

    $manufacturer_id = intval($_POST['manufacturer_id'] ?? 0);
    if (!$manufacturer_id) {
        echo json_encode(['error' => 'Manufacturer is required']);
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

    // Check for saved mapping for this manufacturer (shipment-specific)
    $savedMapping = null;
    $account_id = getAccountIdForUser($conn, $user_id);

    if ($account_id) {
        $stmt = $conn->prepare("SELECT column_mappings FROM manufacturer_column_mappings WHERE manufacturer_id = ? AND account_id = ?");
        $stmt->bind_param("ii", $manufacturer_id, $account_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $savedMapping = json_decode($row['column_mappings'], true);
        }
        $stmt->close();
    }

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
        'bol_number' => ['BOL', 'BOL #', 'BOL Number', 'Bill of Lading', 'B/L'],
        'pallet_id' => ['Pallet', 'Pallet #', 'Pallet ID', 'Pallet Number', 'Serial', 'Serial #'],
        'container_number' => ['Container', 'Container #', 'Container Number', 'CNTR'],
        'status' => ['Status', 'Delivery Status', 'Ship Status', 'State'],
        'estimated_delivery' => ['Est Delivery', 'Est. Delivery', 'ETA', 'Expected Delivery', 'Estimated Arrival'],
        'actual_delivery' => ['Actual Delivery', 'Delivered', 'Delivery Date', 'Actual Del']
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
 * Parse data with column mapping
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
    $manufacturer_id = intval($_POST['manufacturer_id'] ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);
    $account_id = intval($_POST['account_id'] ?? 0) ?: getAccountIdForUser($conn, $user_id);
    $defaultStatus = $_POST['default_status'] ?? 'At Manufacturer';
    $saveMapping = $_POST['save_mapping'] === '1';

    // Parse file
    $result = parseShipmentFile($tempFile, $columnMapping, $defaultStatus);

    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]);
        return;
    }

    $parsedData = $result['data'];
    $warnings = $result['warnings'];

    // Check which pallets exist in inventory
    $palletIds = array_unique(array_filter(array_column($parsedData, 'pallet_id')));
    $existingPallets = [];

    if (!empty($palletIds) && $project_id) {
        $placeholders = str_repeat('?,', count($palletIds) - 1) . '?';
        $types = str_repeat('s', count($palletIds));

        $stmt = $conn->prepare("
            SELECT id, manufacturer_pallet_id, pallet_identifier
            FROM inventory_pallets
            WHERE assigned_project_id = ? AND (manufacturer_pallet_id IN ($placeholders) OR pallet_identifier IN ($placeholders))
        ");

        $params = array_merge([$project_id], $palletIds, $palletIds);
        $stmt->bind_param('i' . $types . $types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $existingPallets[$row['manufacturer_pallet_id']] = $row['id'];
            $existingPallets[$row['pallet_identifier']] = $row['id'];
        }
        $stmt->close();
    }

    // Mark which pallets were found
    $palletsFound = 0;
    $palletsMissing = 0;

    foreach ($parsedData as &$row) {
        $palletId = $row['pallet_id'] ?? '';
        if (isset($existingPallets[$palletId])) {
            $row['pallet_found'] = true;
            $row['inventory_pallet_id'] = $existingPallets[$palletId];
            $palletsFound++;
        } else {
            $row['pallet_found'] = false;
            $row['inventory_pallet_id'] = null;
            $palletsMissing++;
            $warnings[] = ['row' => $row['_row_number'] ?? 0, 'message' => "Pallet '$palletId' not found in inventory"];
        }
    }

    // Get summary
    $uniqueBols = array_unique(array_filter(array_column($parsedData, 'bol_number')));
    $uniquePallets = array_unique(array_filter(array_column($parsedData, 'pallet_id')));

    $summary = [
        'total_rows' => count($parsedData),
        'unique_bols' => count($uniqueBols),
        'unique_pallets' => count($uniquePallets),
        'pallets_found' => $palletsFound,
        'pallets_missing' => $palletsMissing
    ];

    // Save mapping if requested
    if ($saveMapping && $manufacturer_id && $account_id) {
        saveShipmentColumnMapping($conn, $manufacturer_id, $account_id, $columnMapping, $user_id);
    }

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
 * Parse shipment file with custom column mapping
 */
function parseShipmentFile($filePath, $columnMapping, $defaultStatus) {
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

        $rowNum = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;

            if (count(array_filter($row)) === 0) {
                continue;
            }

            $mapped = mapShipmentRow($row, $headerIndex, $columnMapping, $rowNum, $defaultStatus, $warnings);
            if ($mapped !== null) {
                $data[] = $mapped;
            }
        }

        fclose($handle);
    } else {
        // Excel parsing
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $autoloadPath = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }

        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            return ['error' => 'Excel support not available'];
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        // Get headers
        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $value = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
            $headers[] = $value !== null ? trim($value) : '';
        }

        $headerIndex = [];
        foreach ($headers as $idx => $header) {
            $headerIndex[$header] = $idx;
        }

        for ($rowNum = 2; $rowNum <= $highestRow; $rowNum++) {
            $row = [];
            $hasData = false;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $worksheet->getCellByColumnAndRow($col, $rowNum);
                $value = $cell->getValue();

                // Handle date cells
                if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell) && is_numeric($value)) {
                    $value = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
                }

                $row[] = $value;
                if ($value !== null && $value !== '') {
                    $hasData = true;
                }
            }

            if (!$hasData) {
                continue;
            }

            $mapped = mapShipmentRow($row, $headerIndex, $columnMapping, $rowNum, $defaultStatus, $warnings);
            if ($mapped !== null) {
                $data[] = $mapped;
            }
        }
    }

    return ['data' => $data, 'warnings' => $warnings];
}

/**
 * Map a single row to shipment fields
 */
function mapShipmentRow($row, $headerIndex, $columnMapping, $rowNum, $defaultStatus, &$warnings) {
    $mapped = [];
    $hasRequiredData = false;

    $requiredFields = ['bol_number', 'pallet_id'];

    foreach ($columnMapping as $fieldKey => $columnName) {
        if (empty($columnName) || !isset($headerIndex[$columnName])) {
            $mapped[$fieldKey] = null;
            continue;
        }

        $colIndex = $headerIndex[$columnName];
        $value = isset($row[$colIndex]) ? $row[$colIndex] : null;

        // Clean and convert value
        if ($value !== null) {
            $value = trim($value);

            // Parse dates
            if ($fieldKey === 'estimated_delivery' || $fieldKey === 'actual_delivery') {
                $value = parseDate($value);
            }

            // Normalize status
            if ($fieldKey === 'status') {
                $value = normalizeStatus($value);
            }
        }

        $mapped[$fieldKey] = $value;

        if (in_array($fieldKey, $requiredFields) && $value !== null && $value !== '') {
            $hasRequiredData = true;
        }
    }

    // Apply default status if not set
    if (empty($mapped['status'])) {
        $mapped['status'] = $defaultStatus;
    }

    // Validate required fields
    $missingRequired = [];
    foreach ($requiredFields as $fieldKey) {
        if (!isset($mapped[$fieldKey]) || $mapped[$fieldKey] === null || $mapped[$fieldKey] === '') {
            $missingRequired[] = $fieldKey;
        }
    }

    if (!empty($missingRequired) && $hasRequiredData) {
        $warnings[] = ['row' => $rowNum, 'message' => "Missing required fields: " . implode(', ', $missingRequired)];
    }

    if (!$hasRequiredData) {
        return null;
    }

    $mapped['_row_number'] = $rowNum;
    return $mapped;
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
 * Parse date value
 */
function parseDate($value) {
    if (empty($value)) {
        return null;
    }

    $formats = ['Y-m-d', 'm/d/Y', 'm/d/y', 'd/m/Y', 'd/m/y', 'Y/m/d', 'm-d-Y', 'd-m-Y', 'M d, Y'];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return null;
}

/**
 * Normalize status value
 */
function normalizeStatus($value) {
    if (empty($value)) {
        return null;
    }

    $value = trim($value);
    $valueLower = strtolower($value);

    $validStatuses = ScheduleParser::$validStatuses;

    // Direct match
    foreach ($validStatuses as $status) {
        if (strtolower($status) === $valueLower) {
            return $status;
        }
    }

    // Common variations mapping
    $statusMap = [
        'at mfg' => 'At Manufacturer',
        'at manufacturer' => 'At Manufacturer',
        'on water' => 'On Water',
        'in transit' => 'In Transit to Warehouse',
        'in warehouse' => 'In Warehouse',
        'at warehouse' => 'In Warehouse',
        'delivered' => 'Delivered to Project',
        'delivered to site' => 'Delivered to Project',
        'pending' => 'Pending',
        'canceled' => 'Canceled',
        'cancelled' => 'Canceled'
    ];

    if (isset($statusMap[$valueLower])) {
        return $statusMap[$valueLower];
    }

    return 'Pending';
}

/**
 * Import the parsed data
 */
function handleImport($conn, $user_id) {
    $tempFile = $_SESSION['shipment_temp_file'] ?? null;

    // If file is re-uploaded, re-parse it
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

    $columnMapping = json_decode($_POST['column_mapping'] ?? '{}', true);
    $manufacturer_id = intval($_POST['manufacturer_id'] ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);
    $account_id = intval($_POST['account_id'] ?? 0) ?: getAccountIdForUser($conn, $user_id);
    $defaultStatus = $_POST['default_status'] ?? 'At Manufacturer';
    $saveMapping = $_POST['save_mapping'] === '1';

    if (!$manufacturer_id || !$project_id || !$account_id) {
        echo json_encode(['error' => 'Missing required parameters']);
        return;
    }

    // Parse file fresh for import
    $result = parseShipmentFile($tempFile, $columnMapping, $defaultStatus);
    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]);
        return;
    }

    $data = $result['data'];

    // Get manufacturer info
    $stmt = $conn->prepare("SELECT name FROM manufacturers WHERE id = ?");
    $stmt->bind_param("i", $manufacturer_id);
    $stmt->execute();
    $mfgResult = $stmt->get_result()->fetch_assoc();
    $manufacturerName = $mfgResult ? $mfgResult['name'] : 'Unknown';
    $stmt->close();

    // Get existing pallets lookup
    $palletIds = array_unique(array_filter(array_column($data, 'pallet_id')));
    $existingPallets = [];

    if (!empty($palletIds)) {
        $placeholders = str_repeat('?,', count($palletIds) - 1) . '?';
        $types = str_repeat('s', count($palletIds));

        $stmt = $conn->prepare("
            SELECT id, manufacturer_pallet_id, pallet_identifier, wattage, quantity
            FROM inventory_pallets
            WHERE assigned_project_id = ? AND (manufacturer_pallet_id IN ($placeholders) OR pallet_identifier IN ($placeholders))
        ");

        $params = array_merge([$project_id], $palletIds, $palletIds);
        $stmt->bind_param('i' . $types . $types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $existingPallets[$row['manufacturer_pallet_id']] = $row;
            $existingPallets[$row['pallet_identifier']] = $row;
        }
        $stmt->close();
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        $deliveriesCreated = 0;
        $deliveriesUpdated = 0;
        $palletsLinked = 0;

        // Group data by BOL
        $bolGroups = [];
        foreach ($data as $row) {
            $bol = $row['bol_number'] ?? '';
            if ($bol) {
                if (!isset($bolGroups[$bol])) {
                    $bolGroups[$bol] = [
                        'bol_number' => $bol,
                        'container_number' => $row['container_number'] ?? null,
                        'estimated_delivery' => $row['estimated_delivery'] ?? null,
                        'actual_delivery' => $row['actual_delivery'] ?? null,
                        'status' => $row['status'],
                        'pallets' => []
                    ];
                }
                $bolGroups[$bol]['pallets'][] = $row;
            }
        }

        // Process each BOL group
        foreach ($bolGroups as $bolData) {
            $bolNumber = $bolData['bol_number'];
            $containerNumber = $bolData['container_number'];
            $estimatedDelivery = $bolData['estimated_delivery'];
            $actualDelivery = $bolData['actual_delivery'];
            $status = $bolData['status'];
            $pallets = $bolData['pallets'];

            // Map to delivery status
            $deliveryStatus = mapToDeliveryStatus($status);

            // Calculate totals for this BOL
            $totalQuantity = 0;
            $wattages = [];
            foreach ($pallets as $p) {
                $palletId = $p['pallet_id'] ?? '';
                if (isset($existingPallets[$palletId])) {
                    $palletInfo = $existingPallets[$palletId];
                    $totalQuantity += $palletInfo['quantity'] ?? 0;
                    if (!empty($palletInfo['wattage'])) {
                        $wattages[$palletInfo['wattage']] = ($wattages[$palletInfo['wattage']] ?? 0) + ($palletInfo['quantity'] ?? 0);
                    }
                }
            }

            // Determine primary wattage
            $primaryWattage = !empty($wattages) ? array_search(max($wattages), $wattages) : 0;

            // Check if delivery exists
            $stmt = $conn->prepare("SELECT id FROM deliveries WHERE bol_number = ? AND project_id = ?");
            $stmt->bind_param("si", $bolNumber, $project_id);
            $stmt->execute();
            $existingDelivery = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existingDelivery) {
                // Update existing delivery
                $deliveryId = $existingDelivery['id'];
                $stmt = $conn->prepare("
                    UPDATE deliveries SET
                        container_number = COALESCE(?, container_number),
                        anticipated_delivery_date = COALESCE(?, anticipated_delivery_date),
                        actual_delivery_date = COALESCE(?, actual_delivery_date),
                        status_of_delivery = ?,
                        quantity = ?,
                        wattage = ?,
                        data_source = 'shipment_import'
                    WHERE id = ?
                ");
                $stmt->bind_param("sssiiii", $containerNumber, $estimatedDelivery, $actualDelivery, $deliveryStatus, $totalQuantity, $primaryWattage, $deliveryId);
                $stmt->execute();
                $stmt->close();
                $deliveriesUpdated++;
            } else {
                // Create new delivery
                $stmt = $conn->prepare("
                    INSERT INTO deliveries (
                        project_id, supplier, origin_type, origin_id, wattage, status_of_delivery, quantity,
                        bol_number, container_number, anticipated_delivery_date, actual_delivery_date,
                        data_source
                    ) VALUES (?, ?, 'manufacturer', ?, ?, ?, ?, ?, ?, ?, ?, 'shipment_import')
                ");
                $stmt->bind_param(
                    "isiisissss",
                    $project_id, $manufacturerName, $manufacturer_id, $primaryWattage, $deliveryStatus, $totalQuantity,
                    $bolNumber, $containerNumber, $estimatedDelivery, $actualDelivery
                );
                $stmt->execute();
                $deliveryId = $conn->insert_id;
                $stmt->close();
                $deliveriesCreated++;
            }

            // Link pallets to delivery and update their status
            foreach ($pallets as $pallet) {
                $palletId = $pallet['pallet_id'] ?? '';
                if (isset($existingPallets[$palletId])) {
                    $inventoryPalletId = $existingPallets[$palletId]['id'];

                    // Link pallet to delivery
                    $stmt = $conn->prepare("
                        INSERT IGNORE INTO delivery_pallets (delivery_id, inventory_pallet_id)
                        VALUES (?, ?)
                    ");
                    $stmt->bind_param("ii", $deliveryId, $inventoryPalletId);
                    $stmt->execute();
                    if ($stmt->affected_rows > 0) {
                        $palletsLinked++;
                    }
                    $stmt->close();

                    // Update pallet status
                    $palletStatus = mapToPalletStatus($status);
                    $stmt = $conn->prepare("UPDATE inventory_pallets SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $palletStatus, $inventoryPalletId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // Save mapping if requested
        if ($saveMapping) {
            saveShipmentColumnMapping($conn, $manufacturer_id, $account_id, $columnMapping, $user_id);
        }

        $conn->commit();

        // Clean up temp file
        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
        }
        unset($_SESSION['shipment_temp_file']);
        unset($_SESSION['shipment_original_name']);
        unset($_SESSION['shipment_parsed_data']);
        unset($_SESSION['shipment_column_mapping']);

        echo json_encode([
            'success' => true,
            'deliveries_created' => $deliveriesCreated,
            'deliveries_updated' => $deliveriesUpdated,
            'pallets_linked' => $palletsLinked
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
    }
}

/**
 * Map status to delivery status
 */
function mapToDeliveryStatus($status) {
    $map = [
        'At Manufacturer' => 'Pending',
        'On Water' => 'In Transit to Warehouse',
        'Cleared Customs' => 'In Transit to Warehouse',
        'In Transit to Warehouse' => 'In Transit to Warehouse',
        'Delivered to Warehouse' => 'Delivered to Warehouse',
        'In Warehouse' => 'Delivered to Warehouse',
        'In Transit to Project' => 'In Transit to Project',
        'Delivered to Project' => 'Delivered to Project',
        'Pending' => 'Pending',
        'Canceled' => 'Canceled'
    ];
    return $map[$status] ?? 'Pending';
}

/**
 * Map status to inventory pallet status
 */
function mapToPalletStatus($status) {
    $validStatuses = [
        'At Manufacturer', 'On Water', 'Cleared Customs',
        'In Transit to Warehouse', 'In Warehouse',
        'In Transit to Project', 'Delivered to Project', 'Damaged'
    ];

    if (in_array($status, $validStatuses)) {
        return $status;
    }

    $map = [
        'Delivered to Warehouse' => 'In Warehouse',
        'Pending' => 'At Manufacturer',
        'Canceled' => 'At Manufacturer'
    ];

    return $map[$status] ?? 'At Manufacturer';
}

/**
 * Save shipment column mapping for manufacturer
 */
function saveShipmentColumnMapping($conn, $manufacturer_id, $account_id, $mapping, $user_id) {
    $mappingJson = json_encode($mapping);

    // Check if a mapping already exists
    $stmt = $conn->prepare("SELECT id FROM manufacturer_column_mappings WHERE manufacturer_id = ? AND account_id = ?");
    $stmt->bind_param("ii", $manufacturer_id, $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();

    if ($existing) {
        // Update existing mapping
        $stmt = $conn->prepare("UPDATE manufacturer_column_mappings SET column_mappings = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $mappingJson, $existing['id']);
    } else {
        // Insert new mapping
        $stmt = $conn->prepare("INSERT INTO manufacturer_column_mappings (manufacturer_id, account_id, column_mappings, created_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iisi", $manufacturer_id, $account_id, $mappingJson, $user_id);
    }
    $stmt->execute();
    $stmt->close();
}

/**
 * Get account ID for user
 */
function getAccountIdForUser($conn, $user_id) {
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['account_id'] : null;
}
