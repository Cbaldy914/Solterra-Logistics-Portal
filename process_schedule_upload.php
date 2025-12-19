<?php
/**
 * Process Schedule Upload
 *
 * Backend handler for manufacturer schedule imports.
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
    if (!isset($_FILES['schedule_file']) || $_FILES['schedule_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'No file uploaded or upload error']);
        return;
    }

    $manufacturer_id = intval($_POST['manufacturer_id'] ?? 0);
    if (!$manufacturer_id) {
        echo json_encode(['error' => 'Manufacturer is required']);
        return;
    }

    $filePath = $_FILES['schedule_file']['tmp_name'];

    // Save file temporarily for later steps
    $tempDir = sys_get_temp_dir() . '/schedule_uploads';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . basename($_FILES['schedule_file']['name']);
    move_uploaded_file($filePath, $tempFile);

    // Store temp file path in session for later steps
    $_SESSION['schedule_temp_file'] = $tempFile;
    $_SESSION['schedule_original_name'] = $_FILES['schedule_file']['name'];

    // Parse headers
    $parser = new ScheduleParser($tempFile);
    $headers = $parser->parseHeaders();

    if ($errors = $parser->getErrors()) {
        echo json_encode(['error' => implode(', ', $errors)]);
        return;
    }

    // Get suggested mappings
    $suggestedMappings = $parser->suggestMappings();

    // Check for saved mapping for this manufacturer
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
 * Parse data with column mapping
 */
function handleParseData($conn, $user_id) {
    $tempFile = $_SESSION['schedule_temp_file'] ?? null;

    // If file is re-uploaded, save it
    if (isset($_FILES['schedule_file']) && $_FILES['schedule_file']['error'] === UPLOAD_ERR_OK) {
        $tempDir = sys_get_temp_dir() . '/schedule_uploads';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . basename($_FILES['schedule_file']['name']);
        move_uploaded_file($_FILES['schedule_file']['tmp_name'], $tempFile);
        $_SESSION['schedule_temp_file'] = $tempFile;
        $_SESSION['schedule_original_name'] = $_FILES['schedule_file']['name'];
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
    $parser = new ScheduleParser($tempFile);
    $parser->parseHeaders();
    $parser->setColumnMapping($columnMapping);
    $data = $parser->parseData();

    if ($errors = $parser->getErrors()) {
        echo json_encode(['error' => implode(', ', $errors)]);
        return;
    }

    // Apply default status where missing
    foreach ($data as &$row) {
        if (empty($row['status'])) {
            $row['status'] = $defaultStatus;
        }
    }

    // Get summary
    $summary = $parser->getSummary();

    // Check for existing pallets that will be updated
    $existingCount = 0;
    $newCount = 0;

    if ($project_id) {
        $palletIds = array_unique(array_filter(array_column($data, 'pallet_id')));
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
    $summary['pallets_new'] = $newCount > 0 ? $newCount : $summary['unique_pallets'];

    // Save mapping if requested
    if ($saveMapping && $manufacturer_id && $account_id) {
        saveColumnMapping($conn, $manufacturer_id, $account_id, $columnMapping, $user_id);
    }

    // Store parsed data in session for import step
    $_SESSION['schedule_parsed_data'] = $data;
    $_SESSION['schedule_column_mapping'] = $columnMapping;

    echo json_encode([
        'success' => true,
        'data' => $data,
        'summary' => $summary,
        'warnings' => $parser->getWarnings()
    ]);
}

/**
 * Import the parsed data
 */
function handleImport($conn, $user_id) {
    $tempFile = $_SESSION['schedule_temp_file'] ?? null;

    // If file is re-uploaded, re-parse it
    if (isset($_FILES['schedule_file']) && $_FILES['schedule_file']['error'] === UPLOAD_ERR_OK) {
        $tempDir = sys_get_temp_dir() . '/schedule_uploads';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . basename($_FILES['schedule_file']['name']);
        move_uploaded_file($_FILES['schedule_file']['tmp_name'], $tempFile);
        $_SESSION['schedule_temp_file'] = $tempFile;
        $_SESSION['schedule_original_name'] = $_FILES['schedule_file']['name'];
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
    $parser = new ScheduleParser($tempFile);
    $parser->parseHeaders();
    $parser->setColumnMapping($columnMapping);
    $data = $parser->parseData();

    if ($errors = $parser->getErrors()) {
        echo json_encode(['error' => implode(', ', $errors)]);
        return;
    }

    // Apply default status
    foreach ($data as &$row) {
        if (empty($row['status'])) {
            $row['status'] = $defaultStatus;
        }
    }

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
        // Create schedule_upload record
        $originalFileName = $_SESSION['schedule_original_name'] ?? 'unknown.csv';
        $stmt = $conn->prepare("
            INSERT INTO schedule_uploads (account_id, project_id, manufacturer_id, file_name, original_file_name, uploaded_by, rows_processed)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $rowCount = count($data);
        $stmt->bind_param("iiissii", $account_id, $project_id, $manufacturer_id, $originalFileName, $originalFileName, $user_id, $rowCount);
        $stmt->execute();
        $uploadId = $conn->insert_id;
        $stmt->close();

        // Find or create module batch for this manufacturer + project
        $moduleBatchId = findOrCreateModuleBatch($conn, $account_id, $project_id, $manufacturer_id, $manufacturerName);

        // Process the data
        $palletsCreated = 0;
        $palletsUpdated = 0;
        $deliveriesCreated = 0;
        $deliveriesUpdated = 0;

        // Group data by BOL for delivery creation
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
            $result = processBoLGroup($conn, $bolData, $project_id, $account_id, $manufacturer_id, $manufacturerName, $moduleBatchId, $uploadId);
            $palletsCreated += $result['pallets_created'];
            $palletsUpdated += $result['pallets_updated'];
            $deliveriesCreated += $result['deliveries_created'];
            $deliveriesUpdated += $result['deliveries_updated'];
        }

        // Update schedule_upload record with stats
        $stmt = $conn->prepare("
            UPDATE schedule_uploads
            SET pallets_created = ?, pallets_updated = ?, deliveries_created = ?, deliveries_updated = ?, status = 'completed'
            WHERE id = ?
        ");
        $stmt->bind_param("iiiii", $palletsCreated, $palletsUpdated, $deliveriesCreated, $deliveriesUpdated, $uploadId);
        $stmt->execute();
        $stmt->close();

        // Save mapping if requested
        if ($saveMapping) {
            saveColumnMapping($conn, $manufacturer_id, $account_id, $columnMapping, $user_id);
        }

        $conn->commit();

        // Clean up temp file
        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
        }
        unset($_SESSION['schedule_temp_file']);
        unset($_SESSION['schedule_original_name']);
        unset($_SESSION['schedule_parsed_data']);
        unset($_SESSION['schedule_column_mapping']);

        echo json_encode([
            'success' => true,
            'pallets_created' => $palletsCreated,
            'pallets_updated' => $palletsUpdated,
            'deliveries_created' => $deliveriesCreated,
            'deliveries_updated' => $deliveriesUpdated,
            'upload_id' => $uploadId
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
    }
}

/**
 * Process a BOL group (create/update delivery and pallets)
 */
function processBoLGroup($conn, $bolData, $project_id, $account_id, $manufacturer_id, $manufacturerName, $moduleBatchId, $uploadId) {
    $result = [
        'pallets_created' => 0,
        'pallets_updated' => 0,
        'deliveries_created' => 0,
        'deliveries_updated' => 0
    ];

    $bolNumber = $bolData['bol_number'];
    $containerNumber = $bolData['container_number'];
    $estimatedDelivery = $bolData['estimated_delivery'];
    $actualDelivery = $bolData['actual_delivery'];
    $status = $bolData['status'];
    $pallets = $bolData['pallets'];

    // Map delivery status
    $deliveryStatus = mapToDeliveryStatus($status);

    // Calculate totals for this BOL
    $totalQuantity = 0;
    $wattages = [];
    foreach ($pallets as $p) {
        $totalQuantity += $p['quantity'] ?? 0;
        if (!empty($p['wattage'])) {
            $wattages[$p['wattage']] = ($wattages[$p['wattage']] ?? 0) + ($p['quantity'] ?? 0);
        }
    }

    // Determine primary wattage (most common)
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
                schedule_upload_id = ?,
                data_source = 'schedule_import'
            WHERE id = ?
        ");
        $stmt->bind_param("ssssiiii", $containerNumber, $estimatedDelivery, $actualDelivery, $deliveryStatus, $totalQuantity, $primaryWattage, $uploadId, $deliveryId);
        $stmt->execute();
        $stmt->close();
        $result['deliveries_updated']++;
    } else {
        // Create new delivery
        $stmt = $conn->prepare("
            INSERT INTO deliveries (
                project_id, supplier, origin_type, origin_id, wattage, status_of_delivery, quantity,
                bol_number, container_number, anticipated_delivery_date, actual_delivery_date,
                schedule_upload_id, data_source
            ) VALUES (?, ?, 'manufacturer', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'schedule_import')
        ");
        $stmt->bind_param(
            "isiisisssi",
            $project_id, $manufacturerName, $manufacturer_id, $primaryWattage, $deliveryStatus, $totalQuantity,
            $bolNumber, $containerNumber, $estimatedDelivery, $actualDelivery, $uploadId
        );
        $stmt->execute();
        $deliveryId = $conn->insert_id;
        $stmt->close();
        $result['deliveries_created']++;
    }

    // Process pallets for this BOL
    foreach ($pallets as $pallet) {
        $palletResult = processSchedulePallet($conn, $pallet, $project_id, $account_id, $manufacturer_id, $manufacturerName, $moduleBatchId, $deliveryId, $uploadId);
        $result['pallets_created'] += $palletResult['created'];
        $result['pallets_updated'] += $palletResult['updated'];
    }

    return $result;
}

/**
 * Process a single pallet from schedule
 */
function processSchedulePallet($conn, $palletData, $project_id, $account_id, $manufacturer_id, $manufacturerName, $moduleBatchId, $deliveryId, $uploadId) {
    $result = ['created' => 0, 'updated' => 0];

    $palletId = $palletData['pallet_id'] ?? null;
    $wattage = $palletData['wattage'] ?? 0;
    $quantity = $palletData['quantity'] ?? 0;
    $status = $palletData['status'] ?? 'At Manufacturer';

    if (!$palletId) {
        return $result;
    }

    // Map to inventory pallet status
    $palletStatus = mapToPalletStatus($status);

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
                status = ?,
                pallet_identifier = COALESCE(pallet_identifier, ?),
                schedule_upload_id = ?
            WHERE id = ?
        ");
        $stmt->bind_param("iissii", $wattage, $quantity, $palletStatus, $palletId, $uploadId, $existingPallet['id']);
        $stmt->execute();
        $stmt->close();
        $inventoryPalletId = $existingPallet['id'];
        $result['updated'] = 1;
    } else {
        // Create new pallet
        $stmt = $conn->prepare("
            INSERT INTO inventory_pallets (
                pallet_identifier, manufacturer_pallet_id, unassigned_module_item_id,
                wattage, quantity, status, manufacturer,
                assigned_project_id, schedule_upload_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "ssiiiisii",
            $palletId, $palletId, $moduleItemId, $wattage, $quantity, $palletStatus, $manufacturerName,
            $project_id, $uploadId
        );
        $stmt->execute();
        $inventoryPalletId = $conn->insert_id;
        $stmt->close();
        $result['created'] = 1;
    }

    // Link pallet to delivery if not already linked
    $stmt = $conn->prepare("
        INSERT IGNORE INTO delivery_pallets (delivery_id, inventory_pallet_id)
        VALUES (?, ?)
    ");
    $stmt->bind_param("ii", $deliveryId, $inventoryPalletId);
    $stmt->execute();
    $stmt->close();

    return $result;
}

/**
 * Find or create module batch for manufacturer + project
 */
function findOrCreateModuleBatch($conn, $account_id, $project_id, $manufacturer_id, $manufacturerName) {
    // Check for existing batch
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

    // Create new batch
    $stmt = $conn->prepare("
        INSERT INTO modules (account_id, project_id, vendor_name, data_source)
        VALUES (?, ?, ?, 'schedule_import')
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
    // Check for existing item
    $stmt = $conn->prepare("
        SELECT id, quantity FROM unassigned_module_items
        WHERE unassigned_module_id = ? AND wattage = ?
    ");
    $stmt->bind_param("ii", $moduleBatchId, $wattage);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        // Update quantity if needed
        $newQty = $existing['quantity'] + $quantityToAdd;
        $stmt = $conn->prepare("UPDATE unassigned_module_items SET quantity = ? WHERE id = ?");
        $stmt->bind_param("ii", $newQty, $existing['id']);
        $stmt->execute();
        $stmt->close();
        return $existing['id'];
    }

    // Create new item
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
    // Most statuses map directly
    $validStatuses = [
        'At Manufacturer', 'On Water', 'Cleared Customs',
        'In Transit to Warehouse', 'In Warehouse',
        'In Transit to Project', 'Delivered to Project', 'Damaged'
    ];

    if (in_array($status, $validStatuses)) {
        return $status;
    }

    // Map other statuses
    $map = [
        'Delivered to Warehouse' => 'In Warehouse',
        'Pending' => 'At Manufacturer',
        'Canceled' => 'At Manufacturer'
    ];

    return $map[$status] ?? 'At Manufacturer';
}

/**
 * Save column mapping for manufacturer
 */
function saveColumnMapping($conn, $manufacturer_id, $account_id, $mapping, $user_id) {
    $mappingJson = json_encode($mapping);

    $stmt = $conn->prepare("
        INSERT INTO manufacturer_column_mappings (manufacturer_id, account_id, column_mappings, created_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE column_mappings = VALUES(column_mappings), updated_at = NOW()
    ");
    $stmt->bind_param("iisi", $manufacturer_id, $account_id, $mappingJson, $user_id);
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
