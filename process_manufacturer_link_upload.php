<?php
/**
 * Process Manufacturer ID Linking Upload
 *
 * Bulk-links Manufacturer Pallet IDs to existing pallets using Solterra Identifier matching.
 * Handles: parse_headers, parse_data, import.
 */

session_name("logistics_session");
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
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

$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'parse_headers':
            handleParseHeaders();
            break;
        case 'parse_data':
            handleParseData($conn, $user_id, $role);
            break;
        case 'import':
            handleImport($conn, $user_id, $role);
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();

function getAccountScopeForUser($conn, $user_id, $role, $requestedAccountId = 0) {
    if ($role === 'global_admin') {
        return [
            'is_global_admin' => true,
            'account_id' => ($requestedAccountId > 0 ? (int)$requestedAccountId : null)
        ];
    }

    if (in_array($role, ['admin', 'customer_admin'], true)) {
        $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    } else {
        $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    }
    if (!$stmt) {
        throw new Exception('Failed to resolve account scope.');
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($accountId);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || !$accountId) {
        throw new Exception('User is not associated with an account.');
    }

    return [
        'is_global_admin' => false,
        'account_id' => (int)$accountId
    ];
}

function saveUploadedTempFile($fileField, $sessionPrefix, $subdir) {
    if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error.');
    }

    $tempDir = sys_get_temp_dir() . '/' . $subdir;
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $sourceName = basename((string)$_FILES[$fileField]['name']);
    $tempFile = $tempDir . '/' . session_id() . '_' . time() . '_' . $sourceName;
    if (!move_uploaded_file($_FILES[$fileField]['tmp_name'], $tempFile)) {
        throw new Exception('Failed to store uploaded file.');
    }

    $_SESSION[$sessionPrefix . '_temp_file'] = $tempFile;
    $_SESSION[$sessionPrefix . '_original_name'] = $_FILES[$fileField]['name'];

    return $tempFile;
}

function resolveTempFileForStep($fileField, $sessionPrefix, $subdir) {
    $tempFile = $_SESSION[$sessionPrefix . '_temp_file'] ?? null;

    if (isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
        return saveUploadedTempFile($fileField, $sessionPrefix, $subdir);
    }

    if (!$tempFile || !file_exists($tempFile)) {
        throw new Exception('File not found. Please upload the file again.');
    }

    return $tempFile;
}

function handleParseHeaders() {
    $tempFile = saveUploadedTempFile('link_file', 'manufacturer_link', 'manufacturer_link_uploads');

    $parser = new ScheduleParser($tempFile);
    $headers = $parser->parseHeaders();
    $errors = $parser->getErrors();
    if (!empty($errors)) {
        throw new Exception(implode(', ', $errors));
    }

    echo json_encode([
        'success' => true,
        'headers' => $headers,
        'suggested_mappings' => suggestManufacturerLinkMappings($headers)
    ]);
}

function handleParseData($conn, $user_id, $role) {
    $tempFile = resolveTempFileForStep('link_file', 'manufacturer_link', 'manufacturer_link_uploads');

    $columnMapping = json_decode($_POST['column_mapping'] ?? '{}', true);
    if (!is_array($columnMapping)) {
        throw new Exception('Invalid column mapping payload.');
    }

    $projectScopeId = (int)($_POST['project_scope_id'] ?? 0);
    $requestedAccountId = (int)($_POST['account_id'] ?? 0);

    if ($role === 'global_admin' && $requestedAccountId <= 0 && $projectScopeId <= 0) {
        throw new Exception('Select an account scope (or project scope) before parsing data.');
    }

    $scope = getAccountScopeForUser($conn, $user_id, $role, $requestedAccountId);

    $parsed = parseManufacturerLinkFile($tempFile, $columnMapping);
    if (isset($parsed['error'])) {
        throw new Exception((string)$parsed['error']);
    }

    $plan = buildManufacturerLinkPlan($conn, $parsed['data'], $scope, $projectScopeId);
    $warnings = array_merge($parsed['warnings'], $plan['warnings']);

    $_SESSION['manufacturer_link_column_mapping'] = $columnMapping;
    $_SESSION['manufacturer_link_project_scope_id'] = $projectScopeId;
    $_SESSION['manufacturer_link_account_scope_id'] = $scope['account_id'] ?? null;

    echo json_encode([
        'success' => true,
        'data' => $plan['rows'],
        'summary' => $plan['summary'],
        'warnings' => $warnings
    ]);
}

function handleImport($conn, $user_id, $role) {
    $tempFile = resolveTempFileForStep('link_file', 'manufacturer_link', 'manufacturer_link_uploads');

    $columnMapping = json_decode($_POST['column_mapping'] ?? '{}', true);
    if (!is_array($columnMapping)) {
        throw new Exception('Invalid column mapping payload.');
    }

    $projectScopeId = (int)($_POST['project_scope_id'] ?? 0);
    $requestedAccountId = (int)($_POST['account_id'] ?? 0);

    if ($role === 'global_admin' && $requestedAccountId <= 0 && $projectScopeId <= 0) {
        throw new Exception('Select an account scope (or project scope) before importing.');
    }

    $scope = getAccountScopeForUser($conn, $user_id, $role, $requestedAccountId);

    $parsed = parseManufacturerLinkFile($tempFile, $columnMapping);
    if (isset($parsed['error'])) {
        throw new Exception((string)$parsed['error']);
    }

    $plan = buildManufacturerLinkPlan($conn, $parsed['data'], $scope, $projectScopeId);

    $rowsToApply = [];
    foreach ($plan['rows'] as $row) {
        if (!empty($row['actionable']) && !empty($row['matched_pallet_id'])) {
            $rowsToApply[] = $row;
        }
    }

    if (empty($rowsToApply)) {
        echo json_encode([
            'success' => true,
            'updated_count' => 0,
            'linked_count' => 0,
            'updated_existing_count' => 0,
            'overwritten_count' => 0,
            'skipped_count' => count($plan['rows']),
            'message' => 'No matching rows required updates.'
        ]);
        return;
    }

    $conn->begin_transaction();
    try {
        $stmtUpdate = $conn->prepare("UPDATE inventory_pallets SET manufacturer_pallet_id = ? WHERE id = ?");
        if (!$stmtUpdate) {
            throw new Exception('Failed to prepare update statement.');
        }

        $linkedCount = 0;
        $updatedExistingCount = 0;

        foreach ($rowsToApply as $row) {
            $newManufacturerId = $row['manufacturer_pallet_id'];
            $palletId = (int)$row['matched_pallet_id'];

            $stmtUpdate->bind_param('si', $newManufacturerId, $palletId);
            if (!$stmtUpdate->execute()) {
                throw new Exception('Failed to update pallet #' . $palletId . ': ' . $stmtUpdate->error);
            }

            if (empty($row['current_manufacturer_pallet_id'])) {
                $linkedCount++;
            } else {
                $updatedExistingCount++;
            }
        }

        $stmtUpdate->close();
        $conn->commit();

        cleanupManufacturerLinkTempState();

        echo json_encode([
            'success' => true,
            'updated_count' => count($rowsToApply),
            'linked_count' => $linkedCount,
            'updated_existing_count' => $updatedExistingCount,
            'overwritten_count' => $updatedExistingCount,
            'skipped_count' => count($plan['rows']) - count($rowsToApply)
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

function cleanupManufacturerLinkTempState() {
    $tempFile = $_SESSION['manufacturer_link_temp_file'] ?? null;
    if ($tempFile && file_exists($tempFile)) {
        @unlink($tempFile);
    }

    unset(
        $_SESSION['manufacturer_link_temp_file'],
        $_SESSION['manufacturer_link_original_name'],
        $_SESSION['manufacturer_link_column_mapping'],
        $_SESSION['manufacturer_link_project_scope_id'],
        $_SESSION['manufacturer_link_account_scope_id']
    );
}

function suggestManufacturerLinkMappings($headers) {
    $fieldNames = [
        'manufacturer_pallet_id' => ['Manufacturer Pallet ID', 'Mfr Pallet ID', 'Vendor Pallet ID', 'Supplier Pallet ID', 'Factory Pallet ID'],
        'solterra_identifier' => ['Solterra Identifier', 'Pallet Identifier', 'System Pallet ID', 'Internal Pallet ID', 'Identifier']
    ];

    $suggestions = [];
    foreach ($fieldNames as $fieldKey => $names) {
        $suggestions[$fieldKey] = null;

        foreach ($headers as $header) {
            $headerLower = strtolower(trim((string)$header));
            foreach ($names as $name) {
                if (strtolower($name) === $headerLower) {
                    $suggestions[$fieldKey] = $header;
                    break 2;
                }
            }

            if ($suggestions[$fieldKey] === null) {
                foreach ($names as $name) {
                    if (stripos((string)$header, $name) !== false) {
                        $suggestions[$fieldKey] = $header;
                        break 2;
                    }
                }
            }
        }
    }

    return $suggestions;
}

function parseManufacturerLinkFile($filePath, $columnMapping) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $data = [];
    $warnings = [];

    if ($extension === 'csv') {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ['error' => 'Could not open file'];
        }

        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = detectDelimiter((string)$firstLine);

        $headers = fgetcsv($handle, 0, $delimiter);
        $headers = is_array($headers) ? $headers : [];
        $headers = array_map(function ($header) {
            $header = trim((string)$header);
            return preg_replace('/^\xEF\xBB\xBF/', '', $header);
        }, $headers);

        $headerIndex = [];
        foreach ($headers as $idx => $header) {
            $headerIndex[$header] = $idx;
        }

        $rowNum = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if (empty(array_filter($row, function ($value) { return trim((string)$value) !== ''; }))) {
                continue;
            }

            $mapped = mapManufacturerLinkRow($row, $headerIndex, $columnMapping, $rowNum, $warnings);
            if ($mapped !== null) {
                $data[] = $mapped;
            }
        }

        fclose($handle);
    } else {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            $autoloadPath = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }

        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            return ['error' => 'Excel support is not available'];
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            if (empty($rows)) {
                return ['error' => 'File is empty'];
            }

            $headers = array_map('trim', array_map('strval', $rows[0]));
            $headerIndex = [];
            foreach ($headers as $idx => $header) {
                $headerIndex[$header] = $idx;
            }

            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $rowNum = $i + 1;

                if (empty(array_filter($row, function ($value) { return trim((string)$value) !== ''; }))) {
                    continue;
                }

                $mapped = mapManufacturerLinkRow($row, $headerIndex, $columnMapping, $rowNum, $warnings);
                if ($mapped !== null) {
                    $data[] = $mapped;
                }
            }
        } catch (Exception $e) {
            return ['error' => 'Error parsing Excel file: ' . $e->getMessage()];
        }
    }

    return ['data' => $data, 'warnings' => $warnings];
}

function mapManufacturerLinkRow($row, $headerIndex, $columnMapping, $rowNum, &$warnings) {
    $mapped = [
        '_row_number' => $rowNum,
        'solterra_identifier' => '',
        'manufacturer_pallet_id' => ''
    ];

    foreach (['solterra_identifier', 'manufacturer_pallet_id'] as $fieldKey) {
        $columnName = trim((string)($columnMapping[$fieldKey] ?? ''));
        if ($columnName === '' || !isset($headerIndex[$columnName])) {
            continue;
        }

        $colIndex = $headerIndex[$columnName];
        $value = isset($row[$colIndex]) ? trim((string)$row[$colIndex]) : '';
        $mapped[$fieldKey] = $value;
    }

    if ($mapped['solterra_identifier'] === '' && $mapped['manufacturer_pallet_id'] === '') {
        return null;
    }

    if ($mapped['manufacturer_pallet_id'] !== '' && strlen($mapped['manufacturer_pallet_id']) > 100) {
        $warnings[] = [
            'row' => $rowNum,
            'type' => 'error',
            'message' => 'Manufacturer Pallet ID is longer than 100 characters and will be skipped.'
        ];
    }

    return $mapped;
}

function detectDelimiter($line) {
    $delimiters = [',', ';', "\t", '|'];
    $counts = [];
    foreach ($delimiters as $delimiter) {
        $counts[$delimiter] = substr_count($line, $delimiter);
    }
    return array_search(max($counts), $counts, true);
}

function buildManufacturerLinkPlan($conn, $parsedRows, $scope, $projectScopeId) {
    $rows = [];
    $warnings = [];
    $lookupIdentifiers = [];
    $lookupKeyToRowIndex = [];

    foreach ($parsedRows as $index => $parsedRow) {
        $manufacturerId = trim((string)($parsedRow['manufacturer_pallet_id'] ?? ''));
        $solterraIdentifier = trim((string)($parsedRow['solterra_identifier'] ?? ''));

        $row = [
            'row_number' => (int)($parsedRow['_row_number'] ?? ($index + 2)),
            'solterra_identifier' => $solterraIdentifier,
            'manufacturer_pallet_id' => $manufacturerId,
            'matched_pallet_id' => null,
            'assigned_project_id' => null,
            'current_manufacturer_pallet_id' => null,
            'status_code' => '',
            'status_label' => '',
            'message' => '',
            'actionable' => false,
            'duplicate_lookup' => false
        ];

        if ($solterraIdentifier === '') {
            setRowStatus($row, 'skip_missing_lookup', 'Missing Solterra Identifier', 'Solterra Identifier is required.');
            $rows[] = $row;
            continue;
        }
        if ($manufacturerId === '') {
            setRowStatus($row, 'skip_missing_manufacturer_id', 'Missing Manufacturer ID', 'Manufacturer Pallet ID is required.');
            $rows[] = $row;
            continue;
        }
        if (strlen($manufacturerId) > 100) {
            setRowStatus($row, 'skip_invalid_manufacturer_id', 'Invalid Manufacturer ID', 'Manufacturer Pallet ID exceeds 100 characters.');
            $rows[] = $row;
            continue;
        }

        $lookupKey = strtolower($solterraIdentifier);
        if (isset($lookupKeyToRowIndex[$lookupKey])) {
            $row['duplicate_lookup'] = true;
            $rows[$lookupKeyToRowIndex[$lookupKey]]['duplicate_lookup'] = true;
        } else {
            $lookupKeyToRowIndex[$lookupKey] = count($rows);
        }

        $lookupIdentifiers[] = $solterraIdentifier;
        $rows[] = $row;
    }

    $lookupIdentifiers = array_values(array_unique(array_filter($lookupIdentifiers, function ($value) {
        return trim((string)$value) !== '';
    })));

    $palletLookup = fetchScopedPalletsBySolterra($conn, $lookupIdentifiers, $scope, $projectScopeId);
    $candidateIndices = [];

    foreach ($rows as $idx => $row) {
        if ($row['status_code'] !== '') {
            continue;
        }

        if (!empty($row['duplicate_lookup'])) {
            setRowStatus($rows[$idx], 'skip_duplicate_lookup', 'Duplicate Solterra Identifier', 'The same Solterra Identifier appears multiple times in this file.');
            continue;
        }

        $matches = $palletLookup[$row['solterra_identifier']] ?? [];
        if (count($matches) > 1) {
            setRowStatus($rows[$idx], 'skip_ambiguous_lookup', 'Ambiguous Identifier', 'Multiple pallets matched this Solterra Identifier in current scope.');
            continue;
        }
        if (count($matches) === 0) {
            setRowStatus($rows[$idx], 'skip_not_found', 'Pallet Not Found', 'No pallet matched this Solterra Identifier in selected scope.');
            continue;
        }

        $matchedPallet = $matches[0];
        $currentManufacturer = normalizeNullableString($matchedPallet['manufacturer_pallet_id'] ?? null);
        $newManufacturer = $row['manufacturer_pallet_id'];

        $rows[$idx]['matched_pallet_id'] = (int)$matchedPallet['id'];
        $rows[$idx]['assigned_project_id'] = ($matchedPallet['assigned_project_id'] !== null) ? (int)$matchedPallet['assigned_project_id'] : null;
        $rows[$idx]['current_manufacturer_pallet_id'] = $currentManufacturer;

        if ($currentManufacturer !== null && strcasecmp($currentManufacturer, $newManufacturer) === 0) {
            setRowStatus($rows[$idx], 'no_change', 'No Change', 'Manufacturer Pallet ID already matches.');
            continue;
        }

        $rows[$idx]['actionable'] = true;
        $candidateIndices[] = $idx;
    }

    $seenManufacturerKeys = [];
    foreach ($candidateIndices as $idx) {
        if (empty($rows[$idx]['actionable'])) {
            continue;
        }

        $scopeProjectId = ($rows[$idx]['assigned_project_id'] !== null) ? (int)$rows[$idx]['assigned_project_id'] : 0;
        $manufacturerKey = $scopeProjectId . '|' . strtolower($rows[$idx]['manufacturer_pallet_id']);

        if (isset($seenManufacturerKeys[$manufacturerKey])) {
            $otherIdx = $seenManufacturerKeys[$manufacturerKey];
            if ((int)$rows[$otherIdx]['matched_pallet_id'] !== (int)$rows[$idx]['matched_pallet_id']) {
                setRowStatus($rows[$otherIdx], 'skip_duplicate_manufacturer', 'Duplicate Manufacturer ID in File', 'Another row uses the same Manufacturer Pallet ID in this project scope.');
                setRowStatus($rows[$idx], 'skip_duplicate_manufacturer', 'Duplicate Manufacturer ID in File', 'Another row uses the same Manufacturer Pallet ID in this project scope.');
                $rows[$otherIdx]['actionable'] = false;
                $rows[$idx]['actionable'] = false;
            }
            continue;
        }

        $seenManufacturerKeys[$manufacturerKey] = $idx;
    }

    $stmtDupWithProject = $conn->prepare("\n        SELECT id\n        FROM inventory_pallets\n        WHERE manufacturer_pallet_id = ? AND assigned_project_id = ? AND id <> ?\n        LIMIT 1\n    ");
    $stmtDupWithoutProject = $conn->prepare("\n        SELECT id\n        FROM inventory_pallets\n        WHERE manufacturer_pallet_id = ? AND assigned_project_id IS NULL AND id <> ?\n        LIMIT 1\n    ");

    if (!$stmtDupWithProject || !$stmtDupWithoutProject) {
        throw new Exception('Failed to prepare duplicate validation statements.');
    }

    foreach ($candidateIndices as $idx) {
        if (empty($rows[$idx]['actionable'])) {
            continue;
        }

        $manufacturerId = $rows[$idx]['manufacturer_pallet_id'];
        $palletId = (int)$rows[$idx]['matched_pallet_id'];
        $assignedProjectId = ($rows[$idx]['assigned_project_id'] !== null) ? (int)$rows[$idx]['assigned_project_id'] : 0;

        if ($assignedProjectId > 0) {
            $stmtDupWithProject->bind_param('sii', $manufacturerId, $assignedProjectId, $palletId);
            $stmtDupWithProject->execute();
            $dup = $stmtDupWithProject->get_result()->fetch_assoc();
        } else {
            $stmtDupWithoutProject->bind_param('si', $manufacturerId, $palletId);
            $stmtDupWithoutProject->execute();
            $dup = $stmtDupWithoutProject->get_result()->fetch_assoc();
        }

        if ($dup) {
            setRowStatus($rows[$idx], 'skip_conflict_existing', 'Conflict in Database', 'Manufacturer Pallet ID already exists on another pallet in this project scope.');
            $rows[$idx]['actionable'] = false;
        }
    }

    $stmtDupWithProject->close();
    $stmtDupWithoutProject->close();

    foreach ($rows as $idx => $row) {
        if (!empty($rows[$idx]['actionable'])) {
            if (empty($row['current_manufacturer_pallet_id'])) {
                setRowStatus($rows[$idx], 'will_link', 'Will Link', 'Manufacturer Pallet ID will be added.');
            } else {
                setRowStatus($rows[$idx], 'will_update', 'Will Update Existing', 'Manufacturer Pallet ID will be updated.');
            }
        }
    }

    $summary = summarizePlanRows($rows);

    if (($summary['skip_duplicate_lookup'] ?? 0) > 0) {
        $warnings[] = ['type' => 'warning', 'row' => 0, 'message' => $summary['skip_duplicate_lookup'] . ' row(s) had duplicate Solterra Identifier values in the file.'];
    }
    if (($summary['skip_duplicate_manufacturer'] ?? 0) > 0) {
        $warnings[] = ['type' => 'warning', 'row' => 0, 'message' => $summary['skip_duplicate_manufacturer'] . ' row(s) had duplicate Manufacturer Pallet IDs within the same project scope.'];
    }
    if (($summary['skip_conflict_existing'] ?? 0) > 0) {
        $warnings[] = ['type' => 'warning', 'row' => 0, 'message' => $summary['skip_conflict_existing'] . ' row(s) conflict with existing linked Manufacturer Pallet IDs.'];
    }

    return ['rows' => $rows, 'summary' => $summary, 'warnings' => $warnings];
}

function fetchScopedPalletsBySolterra($conn, $identifiers, $scope, $projectScopeId) {
    if (empty($identifiers)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($identifiers), '?'));
    $types = str_repeat('s', count($identifiers));
    $params = $identifiers;

    $sql = "\n        SELECT\n            ip.id,\n            ip.pallet_identifier,\n            ip.manufacturer_pallet_id,\n            ip.assigned_project_id\n        FROM inventory_pallets ip\n        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id\n        LEFT JOIN modules m ON umi.unassigned_module_id = m.id\n        LEFT JOIN projects p_current ON ip.current_project_id = p_current.id\n        LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id\n        WHERE ip.pallet_identifier IN ($placeholders)";

    if (!$scope['is_global_admin']) {
        $sql .= " AND (p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?)";
        $types .= 'iii';
        $params[] = (int)$scope['account_id'];
        $params[] = (int)$scope['account_id'];
        $params[] = (int)$scope['account_id'];
    } elseif (!empty($scope['account_id'])) {
        $sql .= " AND (p_current.account_id = ? OR p_assigned.account_id = ? OR m.account_id = ?)";
        $types .= 'iii';
        $params[] = (int)$scope['account_id'];
        $params[] = (int)$scope['account_id'];
        $params[] = (int)$scope['account_id'];
    }

    if ($projectScopeId > 0) {
        $sql .= " AND (ip.current_project_id = ? OR ip.assigned_project_id = ?)";
        $types .= 'ii';
        $params[] = $projectScopeId;
        $params[] = $projectScopeId;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to query pallets for matching.');
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $lookup = [];
    while ($row = $result->fetch_assoc()) {
        $identifier = (string)($row['pallet_identifier'] ?? '');
        if (!isset($lookup[$identifier])) {
            $lookup[$identifier] = [];
        }
        $lookup[$identifier][] = $row;
    }

    $stmt->close();
    return $lookup;
}

function setRowStatus(&$row, $code, $label, $message) {
    $row['status_code'] = $code;
    $row['status_label'] = $label;
    $row['message'] = $message;
}

function normalizeNullableString($value) {
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    return ($value === '') ? null : $value;
}

function summarizePlanRows($rows) {
    $summary = [
        'total_rows' => count($rows),
        'matched_rows' => 0,
        'updates_to_apply' => 0,
        'will_link' => 0,
        'will_update' => 0,
        'no_change' => 0,
        'skip_missing_lookup' => 0,
        'skip_missing_manufacturer_id' => 0,
        'skip_invalid_manufacturer_id' => 0,
        'skip_duplicate_lookup' => 0,
        'skip_not_found' => 0,
        'skip_ambiguous_lookup' => 0,
        'skip_duplicate_manufacturer' => 0,
        'skip_conflict_existing' => 0
    ];

    foreach ($rows as $row) {
        if (!empty($row['matched_pallet_id'])) {
            $summary['matched_rows']++;
        }

        $code = (string)($row['status_code'] ?? '');
        if ($code !== '' && isset($summary[$code])) {
            $summary[$code]++;
        }

        if ($code === 'will_link' || $code === 'will_update') {
            $summary['updates_to_apply']++;
        }
    }

    return $summary;
}
