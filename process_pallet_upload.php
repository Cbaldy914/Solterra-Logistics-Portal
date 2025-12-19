<?php
/**
 * Process Pallet Upload
 *
 * Backend handler for pallet/module imports.
 * Creates inventory_pallets records without creating deliveries.
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
    if (!isset($_FILES['pallet_file']) || $_FILES['pallet_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'No file uploaded or upload error']);
        return;
    }

    $manufacturer_id = intval($_POST['manufacturer_id'] ?? 0);
    if (!$manufacturer_id) {
        echo json_encode(['error' => 'Manufacturer is required']);
        return;
    }

    $filePath = $_FILES['pallet_file']['tmp_name'];

    // Save file temporarily for later steps
    $tempDir = sys_get_temp_dir() . '/pallet_uploads';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . basename($_FILES['pallet_file']['name']);
    move_uploaded_file($filePath, $tempFile);

    // Store temp file path in session
    $_SESSION['pallet_temp_file'] = $tempFile;
    $_SESSION['pallet_original_name'] = $_FILES['pallet_file']['name'];

    // Parse headers using the existing ScheduleParser
    $parser = new ScheduleParser($tempFile);
    $headers = $parser->parseHeaders();

    if ($errors = $parser->getErrors()) {
        echo json_encode(['error' => implode(', ', $errors)]);
        return;
    }

    // Suggest mappings for pallet-specific fields
    $suggestedMappings = suggestPalletMappings($headers);

    // Check for saved mapping for this manufacturer (pallet-specific)
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
 * Suggest column mappings for pallet import
 */
function suggestPalletMappings($headers) {
    $palletFields = [
        'pallet_id' => ['Pallet', 'Pallet #', 'Pallet ID', 'Pallet Number', 'Serial', 'Serial #', 'Pallet No'],
        'wattage' => ['Wattage', 'Watts', 'W', 'Power', 'Module Wattage', 'Wp'],
        'quantity' => ['Quantity', 'Qty', 'Count', 'Modules', 'Module Count', 'PCS', 'Pieces'],
        'container_number' => ['Container', 'Container #', 'Container Number', 'CNTR', 'Container No'],
        'serial_range' => ['Serial Range', 'Serials', 'Serial Numbers', 'S/N Range', 'Serial #s']
    ];

    $suggestions = [];

    foreach ($palletFields as $fieldKey => $commonNames) {
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
    $tempFile = $_SESSION['pallet_temp_file'] ?? null;

    // If file is re-uploaded, save it
    if (isset($_FILES['pallet_file']) && $_FILES['pallet_file']['error'] === UPLOAD_ERR_OK) {
        $tempDir = sys_get_temp_dir() . '/pallet_uploads';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . basename($_FILES['pallet_file']['name']);
        move_uploaded_file($_FILES['pallet_file']['tmp_name'], $tempFile);
        $_SESSION['pallet_temp_file'] = $tempFile;
        $_SESSION['pallet_original_name'] = $_FILES['pallet_file']['name'];
    }

    if (!$tempFile || !file_exists($tempFile)) {
        echo json_encode(['error' => 'File not found. Please re-upload.']);
        return;
    }

    $columnMapping = json_decode($_POST['column_mapping'] ?? '{}', true);
    $manufacturer_id = intval($_POST['manufacturer_id'] ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);
    $account_id = intval($_POST['account_id'] ?? 0) ?: getAccountIdForUser($conn, $user_id);
    $saveMapping = $_POST['save_mapping'] === '1';

    // Parse file with custom mapping
    $data = parsePalletFile($tempFile, $columnMapping);

    if (isset($data['error'])) {
        echo json_encode(['error' => $data['error']]);
        return;
    }

    $parsedData = $data['data'];
    $warnings = $data['warnings'];

    // Get summary
    $summary = [
        'total_rows' => count($parsedData),
        'total_quantity' => 0,
        'wattages' => []
    ];

    foreach ($parsedData as $row) {
        $summary['total_quantity'] += $row['quantity'] ?? 0;
        if (!empty($row['wattage'])) {
            $summary['wattages'][$row['wattage']] = true;
        }
    }
    $summary['unique_wattages'] = count($summary['wattages']);

    // Check for existing pallets
    $existingCount = 0;
    $newCount = 0;

    if ($project_id) {
        $palletIds = array_unique(array_filter(array_column($parsedData, 'pallet_id')));
        if (!empty($palletIds)) {
            $placeholders = str_repeat('?,', count($palletIds) - 1) . '?';
            $types = str_repeat('s', count($palletIds));

            $stmt = $conn->prepare("
                SELECT manufacturer_pallet_id
                FROM inventory_pallets
                WHERE assigned_project_id = ? AND manufacturer_pallet_id IN ($placeholders)
            ");

            $params = array_merge([$project_id], $palletIds);
            $stmt->bind_param('i' . $types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $existingPallets = [];
            while ($row = $result->fetch_assoc()) {
                $existingPallets[$row['manufacturer_pallet_id']] = true;
            }
            $stmt->close();

            $existingCount = count($existingPallets);
            $newCount = count($palletIds) - $existingCount;
        }
    }

    $summary['pallets_existing'] = $existingCount;
    $summary['pallets_new'] = $newCount > 0 ? $newCount : $summary['total_rows'];

    // Save mapping if requested
    if ($saveMapping && $manufacturer_id && $account_id) {
        savePalletColumnMapping($conn, $manufacturer_id, $account_id, $columnMapping, $user_id);
    }

    // Store parsed data in session
    $_SESSION['pallet_parsed_data'] = $parsedData;
    $_SESSION['pallet_column_mapping'] = $columnMapping;

    echo json_encode([
        'success' => true,
        'data' => $parsedData,
        'summary' => $summary,
        'warnings' => $warnings
    ]);
}

/**
 * Parse pallet file with custom column mapping
 */
function parsePalletFile($filePath, $columnMapping) {
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

            // Skip empty rows
            if (count(array_filter($row)) === 0) {
                continue;
            }

            $mapped = mapPalletRow($row, $headerIndex, $columnMapping, $rowNum, $warnings);
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
                $value = $worksheet->getCellByColumnAndRow($col, $rowNum)->getValue();
                $row[] = $value;
                if ($value !== null && $value !== '') {
                    $hasData = true;
                }
            }

            if (!$hasData) {
                continue;
            }

            $mapped = mapPalletRow($row, $headerIndex, $columnMapping, $rowNum, $warnings);
            if ($mapped !== null) {
                $data[] = $mapped;
            }
        }
    }

    return ['data' => $data, 'warnings' => $warnings];
}

/**
 * Map a single row to pallet fields
 */
function mapPalletRow($row, $headerIndex, $columnMapping, $rowNum, &$warnings) {
    $mapped = [];
    $hasRequiredData = false;

    $requiredFields = ['pallet_id', 'wattage', 'quantity'];

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

            if ($fieldKey === 'wattage' || $fieldKey === 'quantity') {
                $cleaned = preg_replace('/[^0-9]/', '', $value);
                if ($cleaned === '') {
                    $warnings[] = ['row' => $rowNum, 'message' => "Invalid number for $fieldKey: $value"];
                    $value = null;
                } else {
                    $value = (int)$cleaned;
                }
            }
        }

        $mapped[$fieldKey] = $value;

        if (in_array($fieldKey, $requiredFields) && $value !== null && $value !== '') {
            $hasRequiredData = true;
        }
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
 * Import the parsed data
 */
function handleImport($conn, $user_id) {
    $tempFile = $_SESSION['pallet_temp_file'] ?? null;

    // If file is re-uploaded, re-parse it
    if (isset($_FILES['pallet_file']) && $_FILES['pallet_file']['error'] === UPLOAD_ERR_OK) {
        $tempDir = sys_get_temp_dir() . '/pallet_uploads';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . basename($_FILES['pallet_file']['name']);
        move_uploaded_file($_FILES['pallet_file']['tmp_name'], $tempFile);
        $_SESSION['pallet_temp_file'] = $tempFile;
        $_SESSION['pallet_original_name'] = $_FILES['pallet_file']['name'];
    }

    $columnMapping = json_decode($_POST['column_mapping'] ?? '{}', true);
    $manufacturer_id = intval($_POST['manufacturer_id'] ?? 0);
    $manufacturer_location_id = intval($_POST['manufacturer_location_id'] ?? 0) ?: null;
    $project_id = intval($_POST['project_id'] ?? 0);
    $account_id = intval($_POST['account_id'] ?? 0) ?: getAccountIdForUser($conn, $user_id);
    $saveMapping = $_POST['save_mapping'] === '1';

    if (!$manufacturer_id || !$project_id || !$account_id) {
        echo json_encode(['error' => 'Missing required parameters']);
        return;
    }

    // Parse file fresh for import
    $result = parsePalletFile($tempFile, $columnMapping);
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

    // Start transaction
    $conn->begin_transaction();

    try {
        // Find or create module batch for this manufacturer + project
        $moduleBatchId = findOrCreateModuleBatch($conn, $account_id, $project_id, $manufacturer_id, $manufacturerName);

        $palletsCreated = 0;
        $palletsUpdated = 0;
        $totalModules = 0;

        foreach ($data as $palletData) {
            $palletId = $palletData['pallet_id'] ?? null;
            $wattage = $palletData['wattage'] ?? 0;
            $quantity = $palletData['quantity'] ?? 0;
            $containerNumber = $palletData['container_number'] ?? null;
            $serialRange = $palletData['serial_range'] ?? null;

            if (!$palletId || !$wattage || !$quantity) {
                continue;
            }

            // Find or create unassigned_module_item for this wattage
            $moduleItemId = findOrCreateModuleItem($conn, $moduleBatchId, $wattage, $quantity);

            // Check if pallet exists
            $stmt = $conn->prepare("
                SELECT id FROM inventory_pallets
                WHERE manufacturer_pallet_id = ? AND assigned_project_id = ?
            ");
            $stmt->bind_param("si", $palletId, $project_id);
            $stmt->execute();
            $existingPallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existingPallet) {
                // Update existing pallet
                $stmt = $conn->prepare("
                    UPDATE inventory_pallets SET
                        wattage = ?,
                        quantity = ?,
                        container_number = COALESCE(?, container_number),
                        serial_range = COALESCE(?, serial_range),
                        manufacturer_location_id = COALESCE(?, manufacturer_location_id)
                    WHERE id = ?
                ");
                $stmt->bind_param("iissii", $wattage, $quantity, $containerNumber, $serialRange, $manufacturer_location_id, $existingPallet['id']);
                $stmt->execute();
                $stmt->close();
                $palletsUpdated++;
            } else {
                // Create new pallet
                $defaultStatus = 'At Manufacturer';
                $stmt = $conn->prepare("
                    INSERT INTO inventory_pallets (
                        pallet_identifier, manufacturer_pallet_id, unassigned_module_item_id,
                        wattage, quantity, status, manufacturer, manufacturer_location_id,
                        assigned_project_id, container_number, serial_range
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "ssiiisissss",
                    $palletId, $palletId, $moduleItemId, $wattage, $quantity,
                    $defaultStatus, $manufacturerName, $manufacturer_location_id,
                    $project_id, $containerNumber, $serialRange
                );
                $stmt->execute();
                $stmt->close();
                $palletsCreated++;
            }

            $totalModules += $quantity;
        }

        // Save mapping if requested
        if ($saveMapping) {
            savePalletColumnMapping($conn, $manufacturer_id, $account_id, $columnMapping, $user_id);
        }

        $conn->commit();

        // Clean up temp file
        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
        }
        unset($_SESSION['pallet_temp_file']);
        unset($_SESSION['pallet_original_name']);
        unset($_SESSION['pallet_parsed_data']);
        unset($_SESSION['pallet_column_mapping']);

        echo json_encode([
            'success' => true,
            'pallets_created' => $palletsCreated,
            'pallets_updated' => $palletsUpdated,
            'total_modules' => $totalModules
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
    }
}

/**
 * Find or create module batch for manufacturer + project
 */
function findOrCreateModuleBatch($conn, $account_id, $project_id, $manufacturer_id, $manufacturerName) {
    $stmt = $conn->prepare("
        SELECT id FROM modules
        WHERE account_id = ? AND project_id = ? AND vendor_name = ?
        LIMIT 1
    ");
    $stmt->bind_param("iis", $account_id, $project_id, $manufacturerName);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        return $existing['id'];
    }

    $stmt = $conn->prepare("
        INSERT INTO modules (account_id, project_id, vendor_name, data_source)
        VALUES (?, ?, ?, 'pallet_import')
    ");
    $stmt->bind_param("iis", $account_id, $project_id, $manufacturerName);
    $stmt->execute();
    $batchId = $conn->insert_id;
    $stmt->close();

    return $batchId;
}

/**
 * Find or create unassigned_module_item for wattage
 */
function findOrCreateModuleItem($conn, $moduleBatchId, $wattage, $quantityToAdd) {
    $stmt = $conn->prepare("
        SELECT id, quantity FROM unassigned_module_items
        WHERE unassigned_module_id = ? AND wattage = ?
    ");
    $stmt->bind_param("ii", $moduleBatchId, $wattage);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $newQty = $existing['quantity'] + $quantityToAdd;
        $stmt = $conn->prepare("UPDATE unassigned_module_items SET quantity = ? WHERE id = ?");
        $stmt->bind_param("ii", $newQty, $existing['id']);
        $stmt->execute();
        $stmt->close();
        return $existing['id'];
    }

    $stmt = $conn->prepare("
        INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iii", $moduleBatchId, $wattage, $quantityToAdd);
    $stmt->execute();
    $itemId = $conn->insert_id;
    $stmt->close();

    return $itemId;
}

/**
 * Save pallet column mapping for manufacturer
 */
function savePalletColumnMapping($conn, $manufacturer_id, $account_id, $mapping, $user_id) {
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
