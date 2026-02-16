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
require_once 'document_helpers.php';
require_once 'milestone_helpers.php';

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

    case 'check_duplicates':
        handleCheckDuplicates($conn, $user_id);
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
        'quantity' => ['Quantity', 'Qty', 'Count', 'Modules', 'Module Count', 'PCS', 'Pieces']
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

    // Check for duplicate pallet IDs within the uploaded file
    $palletIdCounts = [];
    foreach ($parsedData as $row) {
        $palletId = $row['pallet_id'] ?? '';
        if (!empty($palletId)) {
            if (!isset($palletIdCounts[$palletId])) {
                $palletIdCounts[$palletId] = 0;
            }
            $palletIdCounts[$palletId]++;
        }
    }
    $duplicatesInFile = array_filter($palletIdCounts, function($count) { return $count > 1; });
    if (!empty($duplicatesInFile)) {
        $dupCount = count($duplicatesInFile);
        $examples = array_slice(array_keys($duplicatesInFile), 0, 3);
        $warnings[] = [
            'row' => 0,
            'message' => "{$dupCount} pallet ID(s) appear multiple times in your file (e.g., " . implode(', ', $examples) . "). Only the last occurrence will be used.",
            'type' => 'warning'
        ];
    }

    // Get summary
    $totalPallets = count($parsedData);
    $totalModules = 0;
    $wattages = [];
    $quantities = [];

    foreach ($parsedData as $row) {
        $qty = intval($row['quantity'] ?? 0);
        $totalModules += $qty;
        if ($qty > 0) {
            $quantities[] = $qty;
        }
        if (!empty($row['wattage'])) {
            $wattages[$row['wattage']] = true;
        }
    }

    // Calculate average modules per pallet
    $avgModulesPerPallet = $totalPallets > 0 ? round($totalModules / $totalPallets) : 0;

    $summary = [
        'total_rows' => $totalPallets,
        'total_pallets' => $totalPallets,
        'total_modules' => $totalModules,
        'total_quantity' => $totalModules,
        'unique_wattages' => count($wattages),
        'avg_modules_per_pallet' => $avgModulesPerPallet,
        'wattages' => $wattages
    ];

    // Check for existing pallets and get their current data for comparison
    $existingCount = 0;
    $newCount = 0;
    $existingPalletsList = [];
    $existingPalletsData = []; // Full data for comparison

    if ($project_id) {
        $palletIds = array_unique(array_filter(array_column($parsedData, 'pallet_id')));
        if (!empty($palletIds)) {
            $placeholders = str_repeat('?,', count($palletIds) - 1) . '?';
            $types = str_repeat('s', count($palletIds));

            $stmt = $conn->prepare("
                SELECT manufacturer_pallet_id, pallet_identifier, wattage, quantity
                FROM inventory_pallets
                WHERE assigned_project_id = ?
                  AND (
                    manufacturer_pallet_id IN ($placeholders)
                    OR (manufacturer_pallet_id IS NULL AND pallet_identifier IN ($placeholders))
                  )
            ");

            $params = array_merge([$project_id], $palletIds, $palletIds);
            $stmt->bind_param('i' . $types . $types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $existingPallets = [];
            while ($row = $result->fetch_assoc()) {
                $lookupId = $row['manufacturer_pallet_id'] ?: $row['pallet_identifier'];
                if ($lookupId === null || $lookupId === '') {
                    continue;
                }
                $existingPallets[$lookupId] = true;
                $existingPalletsList[] = $lookupId;
                $existingPalletsData[$lookupId] = [
                    'wattage' => (int)$row['wattage'],
                    'quantity' => (int)$row['quantity']
                ];
            }
            $stmt->close();

            $existingCount = count($existingPallets);
            $newCount = count($palletIds) - $existingCount;
        }
    }

    // Calculate changes for existing pallets
    $wattageChanges = 0;
    $quantityChanges = 0;
    $currentMwFromExisting = 0;
    $newMwFromExisting = 0;

    foreach ($parsedData as $row) {
        $palletId = $row['pallet_id'] ?? '';
        if (isset($existingPalletsData[$palletId])) {
            $oldData = $existingPalletsData[$palletId];
            $newWattage = (int)($row['wattage'] ?? 0);
            $newQuantity = (int)($row['quantity'] ?? 0);

            // Track current MW from these pallets
            $currentMwFromExisting += ($oldData['wattage'] * $oldData['quantity']) / 1000000;
            // Track new MW from these pallets
            $newMwFromExisting += ($newWattage * $newQuantity) / 1000000;

            if ($oldData['wattage'] != $newWattage) {
                $wattageChanges++;
            }
            if ($oldData['quantity'] != $newQuantity) {
                $quantityChanges++;
            }
        }
    }

    $mwDifferenceFromUpdates = $newMwFromExisting - $currentMwFromExisting;

    $summary['pallets_existing'] = $existingCount;
    $summary['pallets_new'] = $project_id ? $newCount : $summary['total_rows'];
    $summary['existing_pallet_ids'] = $existingPalletsList;
    $summary['existing_pallets_data'] = $existingPalletsData;
    $summary['wattage_changes'] = $wattageChanges;
    $summary['quantity_changes'] = $quantityChanges;
    $summary['mw_difference_from_updates'] = round($mwDifferenceFromUpdates, 4);

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
        // Skip manual_* keys for now, we'll handle them separately
        if (strpos($fieldKey, 'manual_') === 0) {
            continue;
        }

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

    // Apply manual values for unmapped fields
    foreach ($columnMapping as $manualKey => $manualValue) {
        if (strpos($manualKey, 'manual_') === 0 && !empty($manualValue)) {
            $actualKey = str_replace('manual_', '', $manualKey);
            if (!isset($mapped[$actualKey]) || $mapped[$actualKey] === null) {
                $mapped[$actualKey] = (int)$manualValue;
                // Mark as having required data if this is a required field
                if (in_array($actualKey, $requiredFields)) {
                    $hasRequiredData = true;
                }
            }
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
        $warnings[] = ['row' => $rowNum, 'message' => "Missing required fields: " . implode(', ', $missingRequired), 'type' => 'error'];
    }

    if (!$hasRequiredData) {
        return null;
    }

    // Additional data quality validations (soft warnings)
    $wattage = $mapped['wattage'] ?? 0;
    $quantity = $mapped['quantity'] ?? 0;
    $palletId = $mapped['pallet_id'] ?? '';

    // Wattage validation - typical solar panels are 300W-800W
    if ($wattage > 0 && ($wattage < 100 || $wattage > 1000)) {
        $warnings[] = ['row' => $rowNum, 'message' => "Unusual wattage value: {$wattage}W (typical range: 300-700W)", 'type' => 'warning'];
    }

    // Quantity validation - typical pallets have 20-40 modules
    if ($quantity > 0 && ($quantity < 1 || $quantity > 100)) {
        $warnings[] = ['row' => $rowNum, 'message' => "Unusual quantity: {$quantity} modules (typical range: 20-40)", 'type' => 'warning'];
    }

    // Pallet ID validation - should have reasonable format
    if (!empty($palletId)) {
        // Check if pallet ID is too short
        if (strlen($palletId) < 3) {
            $warnings[] = ['row' => $rowNum, 'message' => "Pallet ID '{$palletId}' is very short - verify this is correct", 'type' => 'warning'];
        }
        // Check if pallet ID is only numbers (might be wrong column)
        if (preg_match('/^\d{1,5}$/', $palletId)) {
            $warnings[] = ['row' => $rowNum, 'message' => "Pallet ID '{$palletId}' looks like a row number - verify correct column is mapped", 'type' => 'warning'];
        }
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
    $batch_name = trim($_POST['batch_name'] ?? '');
    $trackDomesticContent = !empty($_POST['track_domestic_content']);
    $defaultDomesticContentPct = null;

    if (!$manufacturer_id || !$project_id || !$account_id) {
        echo json_encode(['error' => 'Missing required parameters']);
        return;
    }

    if ($trackDomesticContent) {
        $pctRaw = trim((string)($_POST['domestic_content_pct'] ?? ''));
        if ($pctRaw === '' || !is_numeric($pctRaw)) {
            echo json_encode(['error' => 'Domestic Content % is required and must be numeric when tracking is enabled']);
            return;
        }
        $defaultDomesticContentPct = (float)$pctRaw;
        if ($defaultDomesticContentPct < 0 || $defaultDomesticContentPct > 100) {
            echo json_encode(['error' => 'Domestic Content % must be between 0 and 100']);
            return;
        }
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

    // Get manufacturer location address for initial_location
    $initial_location = '';
    if ($manufacturer_location_id) {
        $stmt = $conn->prepare("SELECT street_address, city, state, zip_code FROM manufacturer_locations WHERE id = ?");
        $stmt->bind_param("i", $manufacturer_location_id);
        $stmt->execute();
        $locResult = $stmt->get_result()->fetch_assoc();
        if ($locResult) {
            $initial_location = implode(', ', array_filter([
                $locResult['street_address'] ?? '',
                $locResult['city'] ?? '',
                $locResult['state'] ?? '',
                $locResult['zip_code'] ?? ''
            ]));
        }
        $stmt->close();
    }
    if (empty($initial_location)) {
        $initial_location = $manufacturerName;
    }

    // Get logistics specifications from POST
    $logistics = [
        'modules_per_pallet' => !empty($_POST['modules_per_pallet']) ? intval($_POST['modules_per_pallet']) : null,
        'pallets_per_truck' => !empty($_POST['pallets_per_truck']) ? intval($_POST['pallets_per_truck']) : null,
        'modules_per_truck' => !empty($_POST['modules_per_truck']) ? intval($_POST['modules_per_truck']) : null,
        'trucks_needed' => !empty($_POST['trucks_needed']) ? intval($_POST['trucks_needed']) : null,
        'pallet_length_mm' => !empty($_POST['pallet_length_mm']) ? intval($_POST['pallet_length_mm']) : null,
        'pallet_depth_mm' => !empty($_POST['pallet_depth_mm']) ? intval($_POST['pallet_depth_mm']) : null,
        'pallet_double_stacked_height_mm' => !empty($_POST['pallet_double_stacked_height_mm']) ? intval($_POST['pallet_double_stacked_height_mm']) : null,
        'pallet_total_weight_kg' => !empty($_POST['pallet_total_weight_kg']) ? intval($_POST['pallet_total_weight_kg']) : null,
        'forklift_truck_long_side_mm' => !empty($_POST['forklift_truck_long_side_mm']) ? intval($_POST['forklift_truck_long_side_mm']) : null,
        'forklift_truck_short_side_mm' => !empty($_POST['forklift_truck_short_side_mm']) ? intval($_POST['forklift_truck_short_side_mm']) : null,
        'pallet_jack_long_side_mm' => !empty($_POST['pallet_jack_long_side_mm']) ? intval($_POST['pallet_jack_long_side_mm']) : null,
        'pallet_jack_short_side_mm' => !empty($_POST['pallet_jack_short_side_mm']) ? intval($_POST['pallet_jack_short_side_mm']) : null,
        'stacking_in_warehouse' => $_POST['stacking_in_warehouse'] ?? null,
        'stacking_during_transport' => $_POST['stacking_during_transport'] ?? null,
        'module_notes' => $_POST['module_notes'] ?? null,
        'cost_per_watt' => !empty($_POST['cost_per_watt']) ? floatval($_POST['cost_per_watt']) : null,
        'po_execution_date' => !empty($_POST['po_execution_date']) ? $_POST['po_execution_date'] : null
    ];

    // Start transaction
    $conn->begin_transaction();

    try {
        // Find or create module batch for this manufacturer + project
        $moduleBatchId = findOrCreateModuleBatch($conn, $account_id, $project_id, $manufacturer_id, $manufacturerName, $initial_location, $logistics, $batch_name);

        // Handle milestones
        $milestones = [];
        if (!empty($_POST['milestones']) && is_array($_POST['milestones'])) {
            foreach ($_POST['milestones'] as $ms) {
                $milestones[] = [
                    'trigger_event' => $ms['trigger_event'] ?? '',
                    'percentage' => floatval($ms['percentage'] ?? 0)
                ];
            }
        }
        if (!empty($milestones)) {
            save_module_milestones($moduleBatchId, $milestones, $conn, $user_id);
        } else {
            ensure_default_module_milestone($moduleBatchId, $conn);
        }

        $palletsCreated = 0;
        $palletsUpdated = 0;
        $totalModules = 0;

        foreach ($data as $palletData) {
            $palletId = $palletData['pallet_id'] ?? null;
            $wattage = $palletData['wattage'] ?? 0;
            $quantity = $palletData['quantity'] ?? 0;

            if (!$palletId || !$wattage || !$quantity) {
                continue;
            }

            // Check if pallet exists by manufacturer ID first (legacy fallback to pallet_identifier).
            $stmt = $conn->prepare("
                SELECT id, wattage as old_wattage, quantity as old_quantity, unassigned_module_item_id
                FROM inventory_pallets
                WHERE assigned_project_id = ?
                  AND (
                    manufacturer_pallet_id = ?
                    OR (manufacturer_pallet_id IS NULL AND pallet_identifier = ?)
                  )
                LIMIT 1
            ");
            $stmt->bind_param("iss", $project_id, $palletId, $palletId);
            $stmt->execute();
            $existingPallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existingPallet) {
                // Existing pallet updates are metadata-only in this flow.
                // Changing wattage/quantity for an existing pallet must go through
                // controlled reconciliation to avoid downstream drift.
                $oldWattage = (int)$existingPallet['old_wattage'];
                $oldQuantity = (int)$existingPallet['old_quantity'];
                if ($oldWattage != $wattage || $oldQuantity != $quantity) {
                    throw new Exception(
                        "Import cannot change wattage/quantity for existing pallet '{$palletId}' " .
                        "({$oldWattage}W x {$oldQuantity} -> {$wattage}W x {$quantity}). " .
                        "Use module batch reconciliation for structural changes."
                    );
                }

                // No wattage/quantity change, just update location if needed.
                $stmt = $conn->prepare("
                    UPDATE inventory_pallets SET
                        manufacturer_location_id = COALESCE(?, manufacturer_location_id),
                        manufacturer_pallet_id = COALESCE(manufacturer_pallet_id, ?),
                        pallet_identifier = COALESCE(pallet_identifier, ?)
                    WHERE id = ?
                ");
                $stmt->bind_param("issi", $manufacturer_location_id, $palletId, $palletId, $existingPallet['id']);
                $stmt->execute();
                $stmt->close();
                $palletsUpdated++;
            } else {
                // Create new pallet
                // Find or create unassigned_module_item for this wattage
                $moduleItemId = findOrCreateModuleItem($conn, $moduleBatchId, $wattage, $quantity, $defaultDomesticContentPct);

                $defaultStatus = 'At Manufacturer';
                $stmt = $conn->prepare("
                    INSERT INTO inventory_pallets (
                        pallet_identifier, manufacturer_pallet_id, unassigned_module_item_id,
                        wattage, quantity, status, manufacturer, manufacturer_location_id,
                        assigned_project_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "ssiiissii",
                    $palletId, $palletId, $moduleItemId, $wattage, $quantity,
                    $defaultStatus, $manufacturerName, $manufacturer_location_id,
                    $project_id
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

        // Handle module documentation files if provided
        $hasDocs = isset($_FILES['module_docs']) && (
            (is_array($_FILES['module_docs']['error']) && count(array_filter($_FILES['module_docs']['error'], fn($e)=>$e!==UPLOAD_ERR_NO_FILE))>0) ||
            (!is_array($_FILES['module_docs']['error']) && $_FILES['module_docs']['error'] !== UPLOAD_ERR_NO_FILE)
        );

        if ($hasDocs && $project_id > 0) {
            $module_docs_sub_type = trim($_POST['module_docs_sub_type'] ?? '');
            $module_docs_description = trim($_POST['module_docs_description'] ?? '');

            if ($module_docs_sub_type === '') {
                throw new Exception('Please choose a Document Sub-Type when uploading module documentation.');
            }

            $names = (array)$_FILES['module_docs']['name'];
            $tmps  = (array)$_FILES['module_docs']['tmp_name'];
            $errs  = (array)$_FILES['module_docs']['error'];
            $sizes = (array)$_FILES['module_docs']['size'];

            foreach ($names as $i => $orig) {
                if (!isset($errs[$i]) || $errs[$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($errs[$i] !== UPLOAD_ERR_OK) {
                    throw new Exception('Module document upload error for file: ' . $orig);
                }

                $tmpName = $tmps[$i];
                $mime = mime_content_type($tmpName);
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $allowed_ext = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','txt','csv'];

                if (!in_array($ext, $allowed_ext)) {
                    throw new Exception('Invalid documentation file type: ' . $ext);
                }

                $doc = [
                    'project_id' => $project_id,
                    'document_type' => 'modules',
                    'document_sub_type' => $module_docs_sub_type,
                    'original_name' => $orig,
                    'file_size' => $sizes[$i],
                    'mime_type' => $mime,
                    'uploaded_by' => $user_id,
                    'tmp_name' => $tmpName,
                    'description' => ($module_docs_description !== '' ? $module_docs_description : 'Module Documentation from Pallet Import')
                ];

                saveDocumentToProjectDocuments($conn, $doc);
            }
        }

        if ($project_id > 0) {
            syncProjectWattageOrdersCanonical($conn, $project_id);
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
function findOrCreateModuleBatch($conn, $account_id, $project_id, $manufacturer_id, $manufacturerName, $initial_location = '', $logistics = [], $batch_name = '') {
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
        // Update existing batch with logistics info if provided
        if (!empty($logistics)) {
            $updateFields = [];
            $updateParams = [];
            $updateTypes = '';

            // Only update fields that have values
            if (!empty($initial_location)) {
                $updateFields[] = 'initial_location = ?';
                $updateParams[] = $initial_location;
                $updateTypes .= 's';
            }
            foreach (['modules_per_pallet', 'pallets_per_truck', 'modules_per_truck', 'pallet_length_mm',
                      'pallet_depth_mm', 'pallet_double_stacked_height_mm', 'pallet_total_weight_kg',
                      'forklift_truck_long_side_mm', 'forklift_truck_short_side_mm',
                      'pallet_jack_long_side_mm', 'pallet_jack_short_side_mm'] as $field) {
                if (isset($logistics[$field]) && $logistics[$field] !== null) {
                    $updateFields[] = "$field = ?";
                    $updateParams[] = $logistics[$field];
                    $updateTypes .= 'i';
                }
            }
            foreach (['stacking_in_warehouse', 'stacking_during_transport', 'module_notes'] as $field) {
                if (isset($logistics[$field]) && $logistics[$field] !== null && $logistics[$field] !== '') {
                    $updateFields[] = "$field = ?";
                    $updateParams[] = $logistics[$field];
                    $updateTypes .= 's';
                }
            }
            // Handle cost_per_watt as decimal
            if (isset($logistics['cost_per_watt']) && $logistics['cost_per_watt'] !== null) {
                $updateFields[] = "cost_per_watt = ?";
                $updateParams[] = $logistics['cost_per_watt'];
                $updateTypes .= 'd';
            }
            // Handle po_execution_date
            if (isset($logistics['po_execution_date']) && $logistics['po_execution_date'] !== null) {
                $updateFields[] = "po_execution_date = ?";
                $updateParams[] = $logistics['po_execution_date'];
                $updateTypes .= 's';
            }

            if (!empty($updateFields)) {
                $updateParams[] = $existing['id'];
                $updateTypes .= 'i';
                $sql = "UPDATE modules SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($updateTypes, ...$updateParams);
                $stmt->execute();
                $stmt->close();
            }
        }
        return $existing['id'];
    }

    // Create new module batch with all fields
    $batchNameVal = !empty($batch_name) ? $batch_name : null;
    $stmt = $conn->prepare("
        INSERT INTO modules (
            account_id, project_id, vendor_name, initial_location, data_source, batch_name,
            modules_per_pallet, pallets_per_truck, modules_per_truck,
            pallet_length_mm, pallet_depth_mm, pallet_double_stacked_height_mm, pallet_total_weight_kg,
            forklift_truck_long_side_mm, forklift_truck_short_side_mm,
            pallet_jack_long_side_mm, pallet_jack_short_side_mm,
            stacking_in_warehouse, stacking_during_transport, module_notes, cost_per_watt, po_execution_date
        ) VALUES (?, ?, ?, ?, 'pallet_import', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iisssiiiiiiiiiiisssd" . "s",
        $account_id, $project_id, $manufacturerName, $initial_location, $batchNameVal,
        $logistics['modules_per_pallet'], $logistics['pallets_per_truck'], $logistics['modules_per_truck'],
        $logistics['pallet_length_mm'], $logistics['pallet_depth_mm'],
        $logistics['pallet_double_stacked_height_mm'], $logistics['pallet_total_weight_kg'],
        $logistics['forklift_truck_long_side_mm'], $logistics['forklift_truck_short_side_mm'],
        $logistics['pallet_jack_long_side_mm'], $logistics['pallet_jack_short_side_mm'],
        $logistics['stacking_in_warehouse'], $logistics['stacking_during_transport'], $logistics['module_notes'],
        $logistics['cost_per_watt'], $logistics['po_execution_date']
    );
    $stmt->execute();
    $batchId = $conn->insert_id;
    $stmt->close();

    return $batchId;
}

/**
 * Find or create unassigned_module_item for wattage
 */
function findOrCreateModuleItem($conn, $moduleBatchId, $wattage, $quantityToAdd, $domesticContentPct = null) {
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
        if ($domesticContentPct !== null) {
            $stmt = $conn->prepare("UPDATE unassigned_module_items SET quantity = ?, domestic_content_pct = ? WHERE id = ?");
            $stmt->bind_param("idi", $newQty, $domesticContentPct, $existing['id']);
        } else {
            $stmt = $conn->prepare("UPDATE unassigned_module_items SET quantity = ? WHERE id = ?");
            $stmt->bind_param("ii", $newQty, $existing['id']);
        }
        $stmt->execute();
        $stmt->close();
        return $existing['id'];
    }

    if ($domesticContentPct !== null) {
        $stmt = $conn->prepare("
            INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity, domestic_content_pct)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                quantity = quantity + VALUES(quantity),
                domestic_content_pct = VALUES(domestic_content_pct)
        ");
        $stmt->bind_param("iiid", $moduleBatchId, $wattage, $quantityToAdd, $domesticContentPct);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                quantity = quantity + VALUES(quantity)
        ");
        $stmt->bind_param("iii", $moduleBatchId, $wattage, $quantityToAdd);
    }
    $stmt->execute();
    $itemId = (int)$conn->insert_id;
    $stmt->close();

    if ($itemId <= 0) {
        $stmt = $conn->prepare("
            SELECT id FROM unassigned_module_items
            WHERE unassigned_module_id = ? AND wattage = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $moduleBatchId, $wattage);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $itemId = (int)($row['id'] ?? 0);
    }

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

function syncProjectWattageOrdersCanonical($conn, $projectId) {
    $projectId = (int)$projectId;
    if ($projectId <= 0) {
        return;
    }

    $totals = [];
    $stmtTotals = $conn->prepare("
        SELECT CAST(umi.wattage AS CHAR) AS wattage_key, SUM(umi.quantity) AS total_qty
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ? AND umi.wattage > 0 AND umi.quantity > 0
        GROUP BY umi.wattage
    ");
    if (!$stmtTotals) {
        throw new Exception('Failed preparing canonical totals query: ' . $conn->error);
    }
    $stmtTotals->bind_param('i', $projectId);
    $stmtTotals->execute();
    $resTotals = $stmtTotals->get_result();
    while ($row = $resTotals->fetch_assoc()) {
        $wattage = (string)($row['wattage_key'] ?? '');
        $qty = (int)($row['total_qty'] ?? 0);
        if ($wattage === '' || $qty <= 0) { continue; }
        $totals[$wattage] = $qty;
    }
    $stmtTotals->close();

    $stmtDelete = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ?");
    if (!$stmtDelete) {
        throw new Exception('Failed preparing project_wattage_orders cleanup: ' . $conn->error);
    }
    $stmtDelete->bind_param('i', $projectId);
    $stmtDelete->execute();
    $stmtDelete->close();

    if (!empty($totals)) {
        $stmtInsert = $conn->prepare("INSERT INTO project_wattage_orders (project_id, wattage, total_order) VALUES (?, ?, ?)");
        if (!$stmtInsert) {
            throw new Exception('Failed preparing project_wattage_orders insert: ' . $conn->error);
        }
        foreach ($totals as $wattage => $qty) {
            $stmtInsert->bind_param('isi', $projectId, $wattage, $qty);
            $stmtInsert->execute();
        }
        $stmtInsert->close();
    }
}
