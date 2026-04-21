<?php
/**
 * Pallet Import Helpers
 *
 * Standalone copies of module-batch / module-item creation helpers used by
 * the warehouse audit commit flow. Mirrors the logic in
 * process_pallet_upload.php (findOrCreateModuleBatch / findOrCreateModuleItem)
 * but is load-safe: this file has no top-level side effects, so it can be
 * required from any endpoint without touching sessions or connections.
 *
 * Functions are prefixed with audit_ to avoid collision with the originals
 * when both files happen to load in the same process.
 *
 * If the original functions in process_pallet_upload.php are ever updated,
 * grep for "audit_find_or_create_module_batch" / "audit_find_or_create_module_item"
 * and mirror the change here.
 */

if (!function_exists('audit_find_or_create_module_batch')) {

/**
 * Find or create a module batch (modules table row) for a manufacturer+project.
 * Mirrors process_pallet_upload.php::findOrCreateModuleBatch.
 */
function audit_find_or_create_module_batch($conn, $account_id, $project_id, $manufacturer_id, $manufacturerName, $initial_location = '', $logistics = [], $batch_name = '')
{
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
        if (!empty($logistics)) {
            $updateFields = [];
            $updateParams = [];
            $updateTypes  = '';

            if (!empty($initial_location)) {
                $updateFields[] = 'initial_location = ?';
                $updateParams[] = $initial_location;
                $updateTypes   .= 's';
            }

            $intFields = [
                'modules_per_pallet', 'pallets_per_truck', 'modules_per_truck',
                'pallet_length_mm', 'pallet_depth_mm', 'pallet_double_stacked_height_mm',
                'pallet_total_weight_kg', 'forklift_truck_long_side_mm',
                'forklift_truck_short_side_mm', 'pallet_jack_long_side_mm',
                'pallet_jack_short_side_mm'
            ];
            foreach ($intFields as $field) {
                if (isset($logistics[$field]) && $logistics[$field] !== null) {
                    $updateFields[] = "$field = ?";
                    $updateParams[] = $logistics[$field];
                    $updateTypes   .= 'i';
                }
            }

            $strFields = ['stacking_in_warehouse', 'stacking_during_transport', 'module_notes'];
            foreach ($strFields as $field) {
                if (isset($logistics[$field]) && $logistics[$field] !== null && $logistics[$field] !== '') {
                    $updateFields[] = "$field = ?";
                    $updateParams[] = $logistics[$field];
                    $updateTypes   .= 's';
                }
            }

            if (isset($logistics['cost_per_watt']) && $logistics['cost_per_watt'] !== null) {
                $updateFields[] = "cost_per_watt = ?";
                $updateParams[] = $logistics['cost_per_watt'];
                $updateTypes   .= 'd';
            }
            if (isset($logistics['po_execution_date']) && $logistics['po_execution_date'] !== null) {
                $updateFields[] = "po_execution_date = ?";
                $updateParams[] = $logistics['po_execution_date'];
                $updateTypes   .= 's';
            }

            if (!empty($updateFields)) {
                $updateParams[] = $existing['id'];
                $updateTypes   .= 'i';
                $sql = "UPDATE modules SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($updateTypes, ...$updateParams);
                $stmt->execute();
                $stmt->close();
            }
        }
        return (int)$existing['id'];
    }

    // Create a new module batch. Normalize all logistics keys to null if missing
    // so the bind_param call below never throws "Undefined array key" notices.
    $defaults = [
        'modules_per_pallet' => null, 'pallets_per_truck' => null, 'modules_per_truck' => null,
        'pallet_length_mm' => null, 'pallet_depth_mm' => null,
        'pallet_double_stacked_height_mm' => null, 'pallet_total_weight_kg' => null,
        'forklift_truck_long_side_mm' => null, 'forklift_truck_short_side_mm' => null,
        'pallet_jack_long_side_mm' => null, 'pallet_jack_short_side_mm' => null,
        'stacking_in_warehouse' => null, 'stacking_during_transport' => null,
        'module_notes' => null, 'cost_per_watt' => null, 'po_execution_date' => null,
    ];
    $logistics = array_merge($defaults, $logistics);

    $batchNameVal = !empty($batch_name) ? $batch_name : null;

    $stmt = $conn->prepare("
        INSERT INTO modules (
            account_id, project_id, vendor_name, initial_location, data_source, batch_name,
            modules_per_pallet, pallets_per_truck, modules_per_truck,
            pallet_length_mm, pallet_depth_mm, pallet_double_stacked_height_mm, pallet_total_weight_kg,
            forklift_truck_long_side_mm, forklift_truck_short_side_mm,
            pallet_jack_long_side_mm, pallet_jack_short_side_mm,
            stacking_in_warehouse, stacking_during_transport, module_notes,
            cost_per_watt, po_execution_date
        ) VALUES (?, ?, ?, ?, 'warehouse_audit', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // 21 params: account_id i, project_id i, vendor_name s, initial_location s,
    //            batch_name s, 11 int logistics fields, 3 string fields,
    //            cost_per_watt d, po_execution_date s
    $stmt->bind_param(
        "iisssiiiiiiiiiiisssds",
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
    $batchId = (int)$conn->insert_id;
    $stmt->close();

    return $batchId;
}

}

if (!function_exists('audit_find_or_create_module_item')) {

/**
 * Find or create an unassigned_module_items row for a given module batch + wattage.
 * Mirrors process_pallet_upload.php::findOrCreateModuleItem.
 */
function audit_find_or_create_module_item($conn, $moduleBatchId, $wattage, $quantityToAdd, $domesticContentPct = null)
{
    $stmt = $conn->prepare("
        SELECT id, quantity FROM unassigned_module_items
        WHERE unassigned_module_id = ? AND wattage = ?
    ");
    $stmt->bind_param("ii", $moduleBatchId, $wattage);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $newQty = (int)$existing['quantity'] + (int)$quantityToAdd;
        if ($domesticContentPct !== null) {
            $stmt = $conn->prepare("UPDATE unassigned_module_items SET quantity = ?, domestic_content_pct = ? WHERE id = ?");
            $stmt->bind_param("idi", $newQty, $domesticContentPct, $existing['id']);
        } else {
            $stmt = $conn->prepare("UPDATE unassigned_module_items SET quantity = ? WHERE id = ?");
            $stmt->bind_param("ii", $newQty, $existing['id']);
        }
        $stmt->execute();
        $stmt->close();
        return (int)$existing['id'];
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
        // Fallback lookup if LAST_INSERT_ID didn't return (shouldn't happen
        // with the ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) trick,
        // but kept for safety parity with the original helper).
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

}
