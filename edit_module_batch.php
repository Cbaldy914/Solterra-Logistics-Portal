<?php
session_name("logistics_session");
session_start();

// Only admins/global_admins/customer_admins
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header('Location: unauthorized.php');
    exit();
}

require_once '../config.php';
require_once 'milestone_helpers.php';
$conn = getDBConnection();
if (!$conn) { die('Database connection failed.'); }

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$account_id = null;

if ($role !== 'global_admin') {
    $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    if ($stmtAccount) {
        $stmtAccount->bind_param('i', $user_id);
        $stmtAccount->execute();
        $stmtAccount->bind_result($account_id);
        $stmtAccount->fetch();
        $stmtAccount->close();
    }
}

$batch_id = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
if ($batch_id <= 0 && isset($_POST['batch_id'])) {
    $batch_id = (int)$_POST['batch_id'];
}
if ($batch_id <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Missing module batch id',
        ]);
        $conn->close();
        exit();
    }
    header('Location: modules.php?error=missing_batch');
    exit();
}

// Project capacity tracking variables
$project_size_mw = 0;
$current_ordered_mw = 0;
$this_batch_mw = 0;
$remaining_mw = 0;

// Load module batch and access control
if (in_array($role, ['admin', 'customer_admin'], true)) {
    $stmt = $conn->prepare("SELECT m.*, p.project_name FROM modules m LEFT JOIN projects p ON m.project_id = p.id JOIN customer_account_users cau ON m.account_id = cau.account_id AND cau.role IN ('admin', 'customer_admin') WHERE m.id=? AND cau.user_id=?");
    $stmt->bind_param('ii', $batch_id, $user_id);
} else {
    $stmt = $conn->prepare("SELECT m.*, p.project_name FROM modules m LEFT JOIN projects p ON m.project_id = p.id WHERE m.id=?");
    $stmt->bind_param('i', $batch_id);
}
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) { header('Location: modules.php?error=access_denied'); exit(); }
$module = $res->fetch_assoc();
$stmt->close();

$project_id = (int)($module['project_id'] ?? 0);

// Load project capacity data if this batch is assigned to a project
if ($project_id > 0) {
    // Get project size
    $stmtProj = $conn->prepare("SELECT project_size FROM projects WHERE id = ?");
    $stmtProj->bind_param("i", $project_id);
    $stmtProj->execute();
    $projResult = $stmtProj->get_result()->fetch_assoc();
    $project_size_mw = floatval($projResult['project_size'] ?? 0);
    $stmtProj->close();

    // Calculate total ordered MW for the project (all batches)
    $stmtTotal = $conn->prepare("
        SELECT SUM(umi.wattage * umi.quantity) / 1000000 as total_mw
        FROM unassigned_module_items umi
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ?
    ");
    $stmtTotal->bind_param("i", $project_id);
    $stmtTotal->execute();
    $totalResult = $stmtTotal->get_result()->fetch_assoc();
    $current_ordered_mw = floatval($totalResult['total_mw'] ?? 0);
    $stmtTotal->close();

    // Calculate MW from THIS batch specifically
    $stmtBatch = $conn->prepare("
        SELECT SUM(wattage * quantity) / 1000000 as batch_mw
        FROM unassigned_module_items
        WHERE unassigned_module_id = ?
    ");
    $stmtBatch->bind_param("i", $batch_id);
    $stmtBatch->execute();
    $batchResult = $stmtBatch->get_result()->fetch_assoc();
    $this_batch_mw = floatval($batchResult['batch_mw'] ?? 0);
    $stmtBatch->close();

    $remaining_mw = max(0, $project_size_mw - $current_ordered_mw);
}

// Load manufacturers
$manufacturers = [];
if ($stmtMfgs = $conn->prepare("SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC")) {
    $stmtMfgs->execute();
    $r = $stmtMfgs->get_result();
    while ($row = $r->fetch_assoc()) { $manufacturers[] = $row; }
    $stmtMfgs->close();
}

// Load current wattages with pallet counts
$current_wattages = [];
if ($stmtW = $conn->prepare('SELECT id, wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ? ORDER BY wattage ASC')) {
    $stmtW->bind_param('i', $batch_id);
    $stmtW->execute();
    $r = $stmtW->get_result();
    while ($row = $r->fetch_assoc()) {
        // Count pallets for this wattage item
        $pallet_count = 0;
        $pallet_modules = 0;
        if ($stmtP = $conn->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(quantity), 0) as modules FROM inventory_pallets WHERE unassigned_module_item_id = ?')) {
            $stmtP->bind_param('i', $row['id']);
            $stmtP->execute();
            $pRes = $stmtP->get_result()->fetch_assoc();
            $pallet_count = (int)($pRes['cnt'] ?? 0);
            $pallet_modules = (int)($pRes['modules'] ?? 0);
            $stmtP->close();
        }
        $row['pallet_count'] = $pallet_count;
        $row['pallet_modules'] = $pallet_modules;
        $current_wattages[] = $row;
    }
    $stmtW->close();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle delete action
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        try {
            $conn->begin_transaction();

            // Get all unassigned_module_items for this batch to delete related pallets
            $stmtGetItems = $conn->prepare("SELECT id FROM unassigned_module_items WHERE unassigned_module_id = ?");
            $stmtGetItems->bind_param("i", $batch_id);
            $stmtGetItems->execute();
            $itemsResult = $stmtGetItems->get_result();
            $itemIds = [];
            while ($row = $itemsResult->fetch_assoc()) {
                $itemIds[] = $row['id'];
            }
            $stmtGetItems->close();

            // Delete inventory_pallets associated with these items
            if (!empty($itemIds)) {
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $stmtDelPallets = $conn->prepare("DELETE FROM inventory_pallets WHERE unassigned_module_item_id IN ($placeholders)");
                $types = str_repeat('i', count($itemIds));
                $stmtDelPallets->bind_param($types, ...$itemIds);
                $stmtDelPallets->execute();
                $stmtDelPallets->close();
            }

            // Update project_wattage_orders if assigned to a project
            if ($project_id > 0) {
                foreach ($current_wattages as $wattage_row) {
                    $w = (int)$wattage_row['wattage'];
                    $q = (int)$wattage_row['quantity'];
                    $wStr = (string)$w;

                    // Decrease the project wattage order
                    $stmtGetOrder = $conn->prepare("SELECT id, total_order FROM project_wattage_orders WHERE project_id = ? AND wattage = ? LIMIT 1");
                    $stmtGetOrder->bind_param("is", $project_id, $wStr);
                    $stmtGetOrder->execute();
                    $stmtGetOrder->bind_result($orderId, $totalOrder);
                    if ($stmtGetOrder->fetch()) {
                        $stmtGetOrder->close();
                        $newTotal = max(0, (int)$totalOrder - $q);
                        if ($newTotal > 0) {
                            $stmtUpdateOrder = $conn->prepare("UPDATE project_wattage_orders SET total_order = ? WHERE id = ?");
                            $stmtUpdateOrder->bind_param("ii", $newTotal, $orderId);
                            $stmtUpdateOrder->execute();
                            $stmtUpdateOrder->close();
                        } else {
                            $stmtDeleteOrder = $conn->prepare("DELETE FROM project_wattage_orders WHERE id = ?");
                            $stmtDeleteOrder->bind_param("i", $orderId);
                            $stmtDeleteOrder->execute();
                            $stmtDeleteOrder->close();
                        }
                    } else {
                        $stmtGetOrder->close();
                    }
                }
            }

            // Delete unassigned_module_items
            $stmtDelItems = $conn->prepare("DELETE FROM unassigned_module_items WHERE unassigned_module_id = ?");
            $stmtDelItems->bind_param("i", $batch_id);
            $stmtDelItems->execute();
            $stmtDelItems->close();

            // Delete the module batch itself
            $stmtDelModule = $conn->prepare("DELETE FROM modules WHERE id = ?");
            $stmtDelModule->bind_param("i", $batch_id);
            $stmtDelModule->execute();
            $stmtDelModule->close();

            $conn->commit();

            // Return JSON for AJAX requests
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Module batch deleted successfully!',
                    'action' => 'delete',
                    'project_id' => $project_id,
                    'redirect_url' => ($project_id > 0) ? "project_overview.php?project_id={$project_id}" : 'modules.php'
                ]);
                $conn->close();
                exit();
            }

            // Redirect to appropriate page (non-AJAX fallback)
            $redir = ($project_id > 0) ? ('project_overview.php?project_id=' . $project_id . '&success=batch_deleted') : 'modules.php?success=batch_deleted';
            header('Location: ' . $redir);
            exit();
        } catch (Exception $ex) {
            $conn->rollback();
            $error_message = 'Error deleting module batch: ' . $ex->getMessage();

            // Return JSON error for AJAX requests
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => $error_message
                ]);
                $conn->close();
                exit();
            }

            $errors[] = $error_message;
        }
    }

    // Read posted values
    $manufacturer_id = isset($_POST['manufacturer_id']) && $_POST['manufacturer_id'] !== '' ? (int)$_POST['manufacturer_id'] : null;
    $location_id = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;

    $modules_per_pallet = isset($_POST['modules_per_pallet']) && $_POST['modules_per_pallet'] !== '' ? (int)$_POST['modules_per_pallet'] : null;
    $pallets_per_truck = isset($_POST['pallets_per_truck']) && $_POST['pallets_per_truck'] !== '' ? (int)$_POST['pallets_per_truck'] : null;
    $modules_per_truck = isset($_POST['modules_per_truck']) && $_POST['modules_per_truck'] !== '' ? (int)$_POST['modules_per_truck'] : null;
    $pallet_length_mm = isset($_POST['pallet_length_mm']) && $_POST['pallet_length_mm'] !== '' ? (int)$_POST['pallet_length_mm'] : null;
    $pallet_depth_mm = isset($_POST['pallet_depth_mm']) && $_POST['pallet_depth_mm'] !== '' ? (int)$_POST['pallet_depth_mm'] : null;
    $pallet_double_stacked_height_mm = isset($_POST['pallet_double_stacked_height_mm']) && $_POST['pallet_double_stacked_height_mm'] !== '' ? (int)$_POST['pallet_double_stacked_height_mm'] : null;
    $pallet_total_weight_kg = isset($_POST['pallet_total_weight_kg']) && $_POST['pallet_total_weight_kg'] !== '' ? (int)$_POST['pallet_total_weight_kg'] : null;
    $forklift_truck_long_side_mm = isset($_POST['forklift_truck_long_side_mm']) && $_POST['forklift_truck_long_side_mm'] !== '' ? (int)$_POST['forklift_truck_long_side_mm'] : null;
    $forklift_truck_short_side_mm = isset($_POST['forklift_truck_short_side_mm']) && $_POST['forklift_truck_short_side_mm'] !== '' ? (int)$_POST['forklift_truck_short_side_mm'] : null;
    $pallet_jack_long_side_mm = isset($_POST['pallet_jack_long_side_mm']) && $_POST['pallet_jack_long_side_mm'] !== '' ? (int)$_POST['pallet_jack_long_side_mm'] : null;
    $pallet_jack_short_side_mm = isset($_POST['pallet_jack_short_side_mm']) && $_POST['pallet_jack_short_side_mm'] !== '' ? (int)$_POST['pallet_jack_short_side_mm'] : null;
    $stacking_in_warehouse = trim($_POST['stacking_in_warehouse'] ?? '');
    $stacking_during_transport = trim($_POST['stacking_during_transport'] ?? '');
    $module_notes = trim($_POST['module_notes'] ?? '');
    $cost_per_watt = isset($_POST['cost_per_watt']) && $_POST['cost_per_watt'] !== '' ? floatval($_POST['cost_per_watt']) : null;
    $po_execution_date = isset($_POST['po_execution_date']) && $_POST['po_execution_date'] !== '' ? trim($_POST['po_execution_date']) : null;

    $posted_watts = $_POST['wattages'] ?? [];
    $posted_qtys = $_POST['quantities'] ?? [];

    // Build vendor/location
    $vendor_name = $module['vendor_name'] ?: 'Unknown Manufacturer';
    $initial_location = $module['initial_location'] ?: '';
    if ($manufacturer_id) {
        if ($stmtV = $conn->prepare("SELECT name FROM manufacturers WHERE id = ?")) {
            $stmtV->bind_param('i', $manufacturer_id);
            $stmtV->execute();
            $stmtV->bind_result($v);
            if ($stmtV->fetch()) { $vendor_name = $v; }
            $stmtV->close();
        }
        if ($location_id && ($stmtL = $conn->prepare("SELECT ml.street_address, ml.city, ml.state, ml.zip_code FROM manufacturer_locations ml JOIN manufacturers m ON m.id = ml.manufacturer_id WHERE ml.id = ?"))) {
            $stmtL->bind_param('i', $location_id);
            $stmtL->execute();
            $stmtL->bind_result($st, $ci, $stt, $zp);
            if ($stmtL->fetch()) { $initial_location = implode(', ', array_filter([$st, $ci, $stt, $zp])); }
            $stmtL->close();
        }
    }
    if ($initial_location === '') { $initial_location = 'Unassigned'; }

    // Validate wattages
    $new_pairs = [];
    if (!is_array($posted_watts) || !is_array($posted_qtys) || count($posted_watts) !== count($posted_qtys)) {
        $errors[] = 'Wattage and quantity arrays must match.';
    } else {
        for ($i=0; $i<count($posted_watts); $i++) {
            $w = (int)trim((string)$posted_watts[$i]);
            $q = (int)trim((string)$posted_qtys[$i]);
            if ($w <= 0 && $q <= 0) { continue; }
            if ($w <= 0 || $q <= 0) { $errors[] = 'All wattages and quantities must be positive integers.'; break; }
            $new_pairs[] = [$w,$q];
        }
        if (empty($errors) && empty($new_pairs)) { $errors[] = 'At least one wattage and quantity is required.'; }
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            // Current totals by wattage
            $current_totals = [];
            foreach ($current_wattages as $row) {
                $w = (int)$row['wattage'];
                $current_totals[$w] = ($current_totals[$w] ?? 0) + (int)$row['quantity'];
            }

            // Update modules
            $stmtU = $conn->prepare('UPDATE modules SET vendor_name=?, initial_location=?, modules_per_pallet=?, pallets_per_truck=?, modules_per_truck=?, pallet_length_mm=?, pallet_depth_mm=?, pallet_double_stacked_height_mm=?, pallet_total_weight_kg=?, stacking_in_warehouse=?, stacking_during_transport=?, forklift_truck_long_side_mm=?, forklift_truck_short_side_mm=?, pallet_jack_long_side_mm=?, pallet_jack_short_side_mm=?, module_notes=?, cost_per_watt=?, po_execution_date=?, last_updated_at=NOW() WHERE id=?');
            $stmtU->bind_param('ssiiiiiiissiiiisdsi',
                $vendor_name, $initial_location, $modules_per_pallet, $pallets_per_truck, $modules_per_truck,
                $pallet_length_mm, $pallet_depth_mm, $pallet_double_stacked_height_mm, $pallet_total_weight_kg,
                $stacking_in_warehouse, $stacking_during_transport,
                $forklift_truck_long_side_mm, $forklift_truck_short_side_mm, $pallet_jack_long_side_mm, $pallet_jack_short_side_mm,
                $module_notes, $cost_per_watt, $po_execution_date, $batch_id
            );
            if (!$stmtU->execute()) { throw new Exception('Failed updating module: '.$stmtU->error); }
            $stmtU->close();

            // Map existing items by wattage
            $existing_items = [];
            foreach ($current_wattages as $row) { $existing_items[(int)$row['wattage']] = $row; }

            // Apply new pairs
            $new_totals = [];
            $posted_watts_set = [];
            foreach ($new_pairs as [$w,$q]) {
                $posted_watts_set[$w] = true;
                if (isset($existing_items[$w])) {
                    $stmtE = $conn->prepare('UPDATE unassigned_module_items SET quantity = ? WHERE id = ?');
                    $stmtE->bind_param('ii', $q, $existing_items[$w]['id']);
                    if (!$stmtE->execute()) { throw new Exception('Failed updating item: '.$stmtE->error); }
                    $stmtE->close();
                } else {
                    $stmtI = $conn->prepare('INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)');
                    $stmtI->bind_param('iii', $batch_id, $w, $q);
                    if (!$stmtI->execute()) { throw new Exception('Failed inserting item: '.$stmtI->error); }
                    $stmtI->close();
                }
                $new_totals[$w] = ($new_totals[$w] ?? 0) + $q;
            }

            // Remove items not in new set - delete pallets if confirmed
            $confirm_delete_pallets = isset($_POST['confirm_delete_pallets']) && $_POST['confirm_delete_pallets'] === 'yes';

            foreach ($existing_items as $w => $item) {
                if (!isset($posted_watts_set[$w])) {
                    // Check for associated pallets
                    $stmtC = $conn->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(quantity), 0) AS modules FROM inventory_pallets WHERE unassigned_module_item_id = ?');
                    $stmtC->bind_param('i', $item['id']);
                    $stmtC->execute();
                    $palletInfo = $stmtC->get_result()->fetch_assoc();
                    $pallet_count = (int)($palletInfo['c'] ?? 0);
                    $pallet_modules = (int)($palletInfo['modules'] ?? 0);
                    $stmtC->close();

                    if ($pallet_count == 0) {
                        // No pallets - safe to delete the wattage item
                        $stmtD = $conn->prepare('DELETE FROM unassigned_module_items WHERE id = ?');
                        $stmtD->bind_param('i', $item['id']);
                        if (!$stmtD->execute()) { throw new Exception('Failed deleting unused item: '.$stmtD->error); }
                        $stmtD->close();
                    } else if ($confirm_delete_pallets) {
                        // User confirmed - delete the pallets first, then the wattage item
                        $stmtDelPallets = $conn->prepare('DELETE FROM inventory_pallets WHERE unassigned_module_item_id = ?');
                        $stmtDelPallets->bind_param('i', $item['id']);
                        if (!$stmtDelPallets->execute()) { throw new Exception('Failed deleting pallets: '.$stmtDelPallets->error); }
                        $stmtDelPallets->close();

                        // Now delete the wattage item
                        $stmtD = $conn->prepare('DELETE FROM unassigned_module_items WHERE id = ?');
                        $stmtD->bind_param('i', $item['id']);
                        if (!$stmtD->execute()) { throw new Exception('Failed deleting item: '.$stmtD->error); }
                        $stmtD->close();
                    } else {
                        // Pallets exist but not confirmed - rollback and show error
                        throw new Exception("Cannot remove {$w}W wattage: {$pallet_count} pallet(s) with {$pallet_modules} modules exist. Please confirm deletion or keep this wattage.");
                    }
                }
            }

            // Update project totals if assigned
            if ($project_id > 0) {
                $allW = array_unique(array_merge(array_keys($current_totals), array_keys($new_totals)));
                foreach ($allW as $w) {
                    $diff = ($new_totals[$w] ?? 0) - ($current_totals[$w] ?? 0);
                    if ($diff === 0) continue;
                    // Upsert-like: check existing row
                    $wStr = (string)$w;
                    if ($stmtS = $conn->prepare('SELECT id, total_order FROM project_wattage_orders WHERE project_id = ? AND wattage = ? LIMIT 1')) {
                        $stmtS->bind_param('is', $project_id, $wStr);
                        $stmtS->execute();
                        $stmtS->bind_result($rowId, $tot);
                        if ($stmtS->fetch()) {
                            $stmtS->close();
                            $newTot = max(0, (int)$tot + (int)$diff);
                            if ($newTot > 0) {
                                $stmtU2 = $conn->prepare('UPDATE project_wattage_orders SET total_order = ? WHERE id = ?');
                                $stmtU2->bind_param('ii', $newTot, $rowId);
                                $stmtU2->execute();
                                $stmtU2->close();
                            } else {
                                $stmtDel = $conn->prepare('DELETE FROM project_wattage_orders WHERE id = ?');
                                $stmtDel->bind_param('i', $rowId);
                                $stmtDel->execute();
                                $stmtDel->close();
                            }
                        } else {
                            $stmtS->close();
                            if ($diff > 0) {
                                $stmtIns = $conn->prepare('INSERT INTO project_wattage_orders (project_id, wattage, total_order) VALUES (?, ?, ?)');
                                $stmtIns->bind_param('isi', $project_id, $wStr, $diff);
                                $stmtIns->execute();
                                $stmtIns->close();
                            }
                        }
                    }
                }
            }

            $conn->commit();

            // Save milestones
            $posted_milestones = $_POST['milestones'] ?? [];
            save_module_milestones($batch_id, $posted_milestones, $conn, $user_id);

            // Calculate total modules for response
            $total_modules = 0;
            foreach ($posted_watts as $i => $w) {
                $q = intval(trim($posted_qtys[$i] ?? 0));
                if ($q > 0) $total_modules += $q;
            }

            // Return JSON for AJAX requests
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Module batch updated successfully!',
                    'action' => 'update',
                    'batch_id' => $batch_id,
                    'total_modules' => $total_modules,
                    'project_id' => $project_id,
                    'redirect_url' => ($project_id > 0) ? "project_overview.php?project_id={$project_id}" : 'modules.php'
                ]);
                $conn->close();
                exit();
            }

            // Non-AJAX fallback
            $redir = ($project_id > 0) ? ('project_overview.php?project_id='.$project_id.'&success=batch_updated') : 'modules.php?success=batch_updated';
            header('Location: '.$redir);
            exit();
        } catch (Exception $ex) {
            $conn->rollback();
            $error_message = 'Error updating module batch: '.$ex->getMessage();

            // Return JSON error for AJAX requests
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => $error_message
                ]);
                $conn->close();
                exit();
            }

            $errors[] = $error_message;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    $message = !empty($errors) ? implode(' ', array_map('strval', $errors)) : 'An unexpected error occurred. Please try again.';

    header('Content-Type: application/json');
    echo json_encode([
        'success' => empty($errors),
        'message' => $message,
        'errors' => $errors,
        'redirect_url' => ($project_id > 0) ? 'project_overview.php?project_id=' . $project_id : 'modules.php'
    ]);
    $conn->close();
    exit();
}

// Preselects for component
$prefManufacturerId = null; $prefLocationId = null; $existingWattages = [];
// Manufacturer by matching name
if (!empty($module['vendor_name'])) {
    if ($stmtPM = $conn->prepare("SELECT id FROM manufacturers WHERE name = ? LIMIT 1")) {
        $stmtPM->bind_param('s', $module['vendor_name']);
        $stmtPM->execute();
        $stmtPM->bind_result($pmid);
        if ($stmtPM->fetch()) { $prefManufacturerId = (int)$pmid; }
        $stmtPM->close();
    }
}
// Location by matching formatted address for that manufacturer
if ($prefManufacturerId) {
    if ($stmtL = $conn->prepare("SELECT ml.id, ml.street_address, ml.city, ml.state, ml.zip_code FROM manufacturer_locations ml JOIN manufacturers m ON m.id = ml.manufacturer_id WHERE ml.manufacturer_id = ?")) {
        $stmtL->bind_param('i', $prefManufacturerId);
        $stmtL->execute();
        $resL = $stmtL->get_result();
        $target = trim((string)$module['initial_location']);
        while ($lr = $resL->fetch_assoc()) {
            $addr = implode(', ', array_filter([$lr['street_address'], $lr['city'], $lr['state'], $lr['zip_code']]));
            if ($addr === $target) { $prefLocationId = (int)$lr['id']; break; }
        }
        $stmtL->close();
    }
}
foreach ($current_wattages as $w) {
    if ((int)$w['wattage']>0 && (int)$w['quantity']>0) {
        $existingWattages[] = [
            'wattage'=>(int)$w['wattage'],
            'quantity'=>(int)$w['quantity'],
            'pallet_count'=>(int)($w['pallet_count'] ?? 0),
            'pallet_modules'=>(int)($w['pallet_modules'] ?? 0)
        ];
    }
}

// Load existing milestones for this batch
$existingMilestones = [];
if ($stmtM = $conn->prepare('SELECT trigger_event, percentage FROM module_batch_milestones WHERE module_id = ? AND is_active = 1 ORDER BY display_order, id')) {
    $stmtM->bind_param('i', $batch_id);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    while ($row = $resM->fetch_assoc()) {
        $existingMilestones[] = [
            'trigger_event' => $row['trigger_event'],
            'percentage' => $row['percentage']
        ];
    }
    $stmtM->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Module Batch<?php echo $module['project_name'] ? (' - '.htmlspecialchars($module['project_name'])) : ''; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .breadcrumb {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            margin-top: 10px;
            font-size: 0.95em;
            color: #6c757d;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
            transition: color 0.3s ease;
            font-weight: 500;
        }
        .breadcrumb a:hover {
            color: #293E4C;
        }
        .breadcrumb .separator {
            margin: 0 12px;
            color: #d1d5db;
            font-weight: 300;
        }

        /* Header Section */
        .form-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .form-header::before {
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
        
        .form-container {
            margin: 20px 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .form-content {
            padding: 40px;
        }
        
        .form-section {
            margin-bottom: 40px;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #488C9A;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        
        .button-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 40px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .button-group-left {
            display: flex;
            align-items: center;
        }

        .button-group-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            padding: 14px 24px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
        }

        .btn-cancel:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .btn-submit {
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            color: white;
            border: none;
            padding: 14px 36px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(40, 62, 76, 0.2);
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(40, 62, 76, 0.3);
        }

        @media (max-width: 768px) {
            .form-container {
                margin: 10px 0;
            }

            .form-content {
                padding: 20px;
            }

            .form-section {
                padding: 20px;
            }

            .button-group {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .button-group-left,
            .button-group-right {
                justify-content: center;
            }

            .button-group-right {
                flex-direction: column;
                gap: 12px;
            }

            .btn-delete, .btn-cancel, .btn-submit {
                width: 100%;
                justify-content: center;
                text-align: center;
            }
            
            .form-header {
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .header-content {
                gap: 16px;
            }
            
            .header-left {
                gap: 16px;
            }
            
            .header-info h1 {
                font-size: 2em;
            }

            .header-subtitle {
                font-size: 1em;
            }
        }

        /* Loading spinner modal styles */
        .loading-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        .loading-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 40px 50px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        .loading-content h3 {
            margin: 0 0 8px 0;
            color: #293E4C;
            font-size: 1.3em;
        }
        .loading-content p {
            margin: 0;
            color: #6c757d;
            font-size: 0.95em;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #488C9A;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        $extra = [];
        if (!$project_id) { $extra[] = ['label' => 'Modules', 'url' => 'modules.php']; }
        echo slp_render_breadcrumbs(['current_label' => 'Edit Module Batch', 'extra' => $extra]);
    ?>

    <!-- Beautiful Header Section -->
    <div class="form-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-info">
                    <h1>Edit Module Batch</h1>
                    <p class="header-subtitle">
                        <?php if ($project_id): ?>
                            Editing modules for <?php echo htmlspecialchars($module['project_name']); ?>
                        <?php else: ?>
                            Edit an unassigned module batch
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Entry Method Toggle -->
    <div class="entry-method-toggle" style="background: #fff; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
        <h3 style="margin: 0 0 16px 0; color: #293E4C; font-size: 1.1em;">How would you like to update modules?</h3>
        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
            <label class="method-option" style="flex: 1; min-width: 200px; padding: 20px; border: 2px solid #488C9A; border-radius: 12px; cursor: pointer; background: rgba(72,140,154,0.05); transition: all 0.2s ease;">
                <input type="radio" name="entry_method" value="manual" checked style="margin-right: 10px;">
                <strong style="color: #293E4C;">Manual Entry</strong>
                <p style="margin: 8px 0 0 0; font-size: 0.9em; color: #6c757d;">Edit generic module batch information manually. System-generated pallet IDs.</p>
            </label>
            <label class="method-option" style="flex: 1; min-width: 200px; padding: 20px; border: 2px solid #e9ecef; border-radius: 12px; cursor: pointer; background: #f8f9fa; transition: all 0.2s ease;">
                <input type="radio" name="entry_method" value="import" style="margin-right: 10px;">
                <strong style="color: #293E4C;">Import Pallets</strong>
                <p style="margin: 8px 0 0 0; font-size: 0.9em; color: #6c757d;">Upload real manufacturer pallet data from a CSV/Excel file.</p>
            </label>
        </div>
    </div>

    <?php if ($project_id > 0 && $project_size_mw > 0): ?>
    <!-- Project Capacity Info Banner -->
    <div id="capacityBanner" style="background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%); border: 1px solid #b8daff; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
        <h3 style="margin: 0 0 16px 0; color: #0056b3; font-size: 1.1em; display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 1.2em;">&#9889;</span> Project Capacity Status
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                <div style="font-size: 1.6rem; font-weight: 700; color: #488C9A;"><?php echo number_format($current_ordered_mw, 2); ?> MW</div>
                <div style="font-size: 0.85rem; color: #6c757d;">Total Ordered</div>
            </div>
            <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                <div style="font-size: 1.6rem; font-weight: 700; color: #6c757d;"><?php echo number_format($this_batch_mw, 2); ?> MW</div>
                <div style="font-size: 0.85rem; color: #6c757d;">This Batch (current)</div>
            </div>
            <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                <div style="font-size: 1.6rem; font-weight: 700; color: #293E4C;"><?php echo number_format($project_size_mw, 2); ?> MW</div>
                <div style="font-size: 0.85rem; color: #6c757d;">Project Target</div>
            </div>
            <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                <div style="font-size: 1.6rem; font-weight: 700; color: <?php echo $remaining_mw > 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($remaining_mw, 2); ?> MW</div>
                <div style="font-size: 0.85rem; color: #6c757d;">Remaining</div>
            </div>
        </div>
        <?php
        $capacity_pct = $project_size_mw > 0 ? min(100, ($current_ordered_mw / $project_size_mw) * 100) : 0;
        ?>
        <div style="background: #e9ecef; border-radius: 8px; height: 20px; overflow: hidden;">
            <div style="background: linear-gradient(90deg, #488C9A 0%, #3a7086 100%); height: 100%; width: <?php echo $capacity_pct; ?>%; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 11px; font-weight: 600;">
                <?php echo number_format($capacity_pct, 1); ?>%
            </div>
        </div>
        <div id="newMwPreview" style="display: none; margin-top: 16px; padding: 12px; background: white; border-radius: 8px; border: 2px solid #ffc107;"></div>
    </div>
    <?php endif; ?>

    <div class="form-container" id="manualEntryContainer">
        <div class="form-content">
            <?php if (!empty($errors)): ?>
                <div class="error-message"><ul style="margin:0; padding-left:20px; ">
                    <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>

            <?php $formAction = 'edit_module_batch.php?batch_id='.(int)$batch_id; ?>
            <form method="POST" id="editBatchForm" action="<?php echo $formAction; ?>">
                <input type="hidden" name="confirm_delete_pallets" id="confirmDeletePallets" value="no">
                <input type="hidden" name="batch_id" value="<?php echo (int)$batch_id; ?>">
                <?php include __DIR__ . '/components/module_batch_section.php'; ?>

                <div class="button-group">
                    <div class="button-group-left">
                        <button type="button" id="deleteBatchBtn" class="btn-delete">
                            <i class="fas fa-trash-alt"></i> Delete Batch
                        </button>
                    </div>
                    <div class="button-group-right">
                        <a href="<?php echo $project_id ? ('project_overview.php?project_id='.(int)$project_id) : 'modules.php'; ?>" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-submit">Save Module Batch</button>
                    </div>
                </div>
            </form>

            <!-- Delete Batch Form (hidden) -->
            <form id="deleteBatchForm" method="POST" action="<?php echo $formAction; ?>" style="display: none;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="batch_id" value="<?php echo (int)$batch_id; ?>">
            </form>

            <!-- Delete Batch Confirmation Modal -->
            <div id="deleteBatchModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                <div style="background: #fff; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                    <h3 style="margin: 0 0 15px; color: #dc3545; display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;">🗑️</span> Delete Module Batch?
                    </h3>
                    <p style="color: #495057; margin-bottom: 15px;">You are about to permanently delete this module batch:</p>
                    <div style="background: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <div style="margin-bottom: 8px;"><strong>Manufacturer:</strong> <?php echo htmlspecialchars($module['vendor_name'] ?? 'Unknown'); ?></div>
                        <div style="margin-bottom: 8px;"><strong>Location:</strong> <?php echo htmlspecialchars($module['initial_location'] ?? 'Unknown'); ?></div>
                        <div style="margin-bottom: 8px;"><strong>Total MW:</strong> <?php echo number_format($this_batch_mw, 2); ?> MW</div>
                        <?php
                        $total_pallets = 0;
                        $total_pallet_modules = 0;
                        foreach ($current_wattages as $cw) {
                            $total_pallets += $cw['pallet_count'];
                            $total_pallet_modules += $cw['pallet_modules'];
                        }
                        if ($total_pallets > 0): ?>
                        <div style="color: #dc3545; font-weight: 500; margin-top: 10px; padding-top: 10px; border-top: 1px solid #dee2e6;">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo $total_pallets; ?> pallet(s) with <?php echo number_format($total_pallet_modules); ?> modules will also be deleted!
                        </div>
                        <?php endif; ?>
                    </div>
                    <p style="color: #dc3545; font-weight: 500; margin-bottom: 20px;">This action cannot be undone.</p>
                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button type="button" id="cancelDeleteBatch" style="padding: 10px 20px; border: 1px solid #dee2e6; background: #fff; color: #495057; border-radius: 6px; cursor: pointer; font-size: 14px;">Cancel</button>
                        <button type="button" id="confirmDeleteBatch" style="padding: 10px 20px; border: none; background: #dc3545; color: #fff; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">Delete Permanently</button>
                    </div>
                </div>
            </div>

            <!-- Pallet Deletion Confirmation Modal -->
            <div id="palletDeleteModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
                <div style="background: #fff; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                    <h3 style="margin: 0 0 15px; color: #dc3545; display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;">⚠️</span> Warning: Pallets Will Be Deleted
                    </h3>
                    <p style="color: #495057; margin-bottom: 15px;">You are about to remove the following wattage(s) that have existing pallets:</p>
                    <div id="palletDeleteList" style="background: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 20px; max-height: 200px; overflow-y: auto;"></div>
                    <p style="color: #dc3545; font-weight: 500; margin-bottom: 20px;">This action cannot be undone. All associated pallets and their modules will be permanently deleted.</p>
                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button type="button" id="cancelPalletDelete" style="padding: 10px 20px; border: 1px solid #dee2e6; background: #fff; color: #495057; border-radius: 6px; cursor: pointer; font-size: 14px;">Cancel</button>
                        <button type="button" id="confirmPalletDelete" style="padding: 10px 20px; border: none; background: #dc3545; color: #fff; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">Delete Pallets & Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Container (hidden by default) -->
    <div id="importContainer" style="display: none; background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 40px; margin-bottom: 20px;">
        <div style="text-align: center; padding: 40px 20px;">
            <div style="font-size: 48px; color: #488C9A; margin-bottom: 16px;">📦</div>
            <h2 style="color: #293E4C; margin-bottom: 12px;">Import Pallets</h2>
            <p style="color: #6c757d; margin-bottom: 24px; max-width: 500px; margin-left: auto; margin-right: auto;">
                Upload a CSV or Excel file with pallet data from your manufacturer to add pallets to inventory.
            </p>
            <a href="upload_pallets.php<?php echo $project_id ? '?project_id='.$project_id : ''; ?>"
               class="btn-submit" style="display: inline-block; text-decoration: none; padding: 16px 32px;">
                Import Pallets →
            </a>
        </div>
    </div>

    <!-- Loading Modal -->
    <div id="loadingModal" class="loading-modal">
        <div class="loading-content">
            <div class="spinner"></div>
            <h3 id="loadingTitle">Saving Changes...</h3>
            <p id="loadingSubtitle">Please wait while we process your request.</p>
        </div>
    </div>

    <!-- Result Modal (Success/Error) -->
    <div id="resultModal" class="loading-modal" style="display: none;">
        <div class="loading-content" style="max-width: 480px; padding: 32px 40px;">
            <div id="resultIcon" style="font-size: 56px; margin-bottom: 16px;"></div>
            <h3 id="resultTitle" style="margin: 0 0 12px 0; font-size: 1.4em;"></h3>
            <p id="resultMessage" style="margin: 0 0 8px 0; color: #6c757d;"></p>
            <div id="resultDetails" style="margin: 16px 0; padding: 16px; background: #f8f9fa; border-radius: 8px; text-align: center; display: none;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #488C9A;" id="resultModules">0</div>
                <div style="font-size: 0.85rem; color: #6c757d;">Total Modules</div>
            </div>
            <div style="display: flex; gap: 12px; justify-content: center; margin-top: 24px; flex-wrap: wrap;">
                <a href="#" id="resultGoBackBtn" style="background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%); color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center;">
                    <i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Project Overview
                </a>
                <button type="button" id="resultCloseBtn" onclick="closeResultModal()" style="background: #e9ecef; color: #293E4C; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 500; display: none;">
                    Close
                </button>
            </div>
        </div>
    </div>
</main>

<script>
    // Entry method toggle
    document.addEventListener('DOMContentLoaded', function() {
        const methodRadios = document.querySelectorAll('input[name="entry_method"]');
        const manualContainer = document.getElementById('manualEntryContainer');
        const importContainer = document.getElementById('importContainer');
        const methodOptions = document.querySelectorAll('.method-option');

        methodRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'manual') {
                    manualContainer.style.display = 'block';
                    importContainer.style.display = 'none';
                    methodOptions[0].style.border = '2px solid #488C9A';
                    methodOptions[0].style.background = 'rgba(72,140,154,0.05)';
                    methodOptions[1].style.border = '2px solid #e9ecef';
                    methodOptions[1].style.background = '#f8f9fa';
                } else {
                    manualContainer.style.display = 'none';
                    importContainer.style.display = 'block';
                    methodOptions[1].style.border = '2px solid #488C9A';
                    methodOptions[1].style.background = 'rgba(72,140,154,0.05)';
                    methodOptions[0].style.border = '2px solid #e9ecef';
                    methodOptions[0].style.background = '#f8f9fa';
                }
            });
        });
    });

    // MW Capacity tracking for edit mode
    const capacityData = {
        projectSizeMw: <?php echo json_encode($project_size_mw); ?>,
        currentOrderedMw: <?php echo json_encode($current_ordered_mw); ?>,
        thisBatchMw: <?php echo json_encode($this_batch_mw); ?>
    };

    // Original wattages with pallet info for removal confirmation
    const originalWattages = <?php echo json_encode($existingWattages); ?>;

    function calculateNewBatchMw() {
        let batchMw = 0;
        const wattageInputs = document.querySelectorAll('input[name="wattages[]"]');
        const quantityInputs = document.querySelectorAll('input[name="quantities[]"]');

        wattageInputs.forEach((wInput, i) => {
            const wattage = parseFloat(wInput.value) || 0;
            const quantity = parseInt(quantityInputs[i]?.value) || 0;
            batchMw += (wattage * quantity) / 1000000;
        });
        return batchMw;
    }

    function updateMwPreview() {
        if (capacityData.projectSizeMw <= 0) return;

        const newBatchMw = calculateNewBatchMw();
        // New total = current total - old batch MW + new batch MW
        const newTotalMw = capacityData.currentOrderedMw - capacityData.thisBatchMw + newBatchMw;
        const previewDiv = document.getElementById('newMwPreview');

        if (previewDiv) {
            const difference = newBatchMw - capacityData.thisBatchMw;

            if (Math.abs(difference) > 0.001) {
                previewDiv.style.display = 'block';

                if (newTotalMw > capacityData.projectSizeMw) {
                    const excessMw = newTotalMw - capacityData.projectSizeMw;
                    const excessPct = ((newTotalMw / capacityData.projectSizeMw) - 1) * 100;
                    previewDiv.style.borderColor = '#dc3545';
                    previewDiv.style.background = '#f8d7da';
                    previewDiv.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="color: #721c24; font-weight: 600;">&#9888; Over Capacity Warning</span>
                            <span style="font-weight: 700; color: #721c24;">${newTotalMw.toFixed(2)} MW total</span>
                        </div>
                        <div style="font-size: 0.9rem; color: #721c24;">
                            This change will exceed the project target by ${excessMw.toFixed(2)} MW (${excessPct.toFixed(1)}% over).
                        </div>
                    `;
                } else {
                    const changeSign = difference > 0 ? '+' : '';
                    const changeColor = difference > 0 ? '#856404' : '#155724';
                    previewDiv.style.borderColor = difference > 0 ? '#ffc107' : '#28a745';
                    previewDiv.style.background = difference > 0 ? '#fff3cd' : '#d4edda';
                    const remainingAfter = capacityData.projectSizeMw - newTotalMw;
                    previewDiv.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: ${changeColor}; font-weight: 500;">After Changes:</span>
                            <span style="font-weight: 700; color: ${changeColor};">${newTotalMw.toFixed(2)} MW total (${changeSign}${difference.toFixed(2)} MW)</span>
                        </div>
                        <div style="font-size: 0.85rem; color: ${changeColor}; margin-top: 4px;">
                            ${remainingAfter.toFixed(2)} MW remaining capacity
                        </div>
                    `;
                }
            } else {
                previewDiv.style.display = 'none';
            }
        }
    }

    // Add listeners to wattage container for dynamic updates
    document.addEventListener('DOMContentLoaded', function() {
        const wattageContainer = document.getElementById('wattage-container');
        if (wattageContainer) {
            wattageContainer.addEventListener('input', updateMwPreview);
            const observer = new MutationObserver(updateMwPreview);
            observer.observe(wattageContainer, { childList: true, subtree: true });
        }
    });

    // Check for removed wattages that have pallets
    function getRemovedWattagesWithPallets() {
        const currentWattages = new Set();
        document.querySelectorAll('input[name="wattages[]"]').forEach(input => {
            const val = parseInt(input.value);
            if (val > 0) currentWattages.add(val);
        });

        const removedWithPallets = [];
        originalWattages.forEach(orig => {
            if (!currentWattages.has(orig.wattage) && orig.pallet_count > 0) {
                removedWithPallets.push(orig);
            }
        });
        return removedWithPallets;
    }

    // Form submission confirmation for over-capacity and pallet deletion
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('editBatchForm');
        const modal = document.getElementById('palletDeleteModal');
        const confirmBtn = document.getElementById('confirmPalletDelete');
        const cancelBtn = document.getElementById('cancelPalletDelete');
        const palletList = document.getElementById('palletDeleteList');
        const confirmInput = document.getElementById('confirmDeletePallets');

        if (!form) return;

        // Handle modal buttons
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                confirmInput.value = 'yes';
                modal.style.display = 'none';
                submitFormViaAjax(form);
            });
        }

        // Close modal on background click
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            // Check for removed wattages with pallets
            const removedWithPallets = getRemovedWattagesWithPallets();

            if (removedWithPallets.length > 0 && confirmInput.value !== 'yes') {
                // Build the list of affected wattages
                let listHtml = '';
                let totalPallets = 0;
                let totalModules = 0;

                removedWithPallets.forEach(item => {
                    totalPallets += item.pallet_count;
                    totalModules += item.pallet_modules;
                    listHtml += `<div style="padding: 8px 0; border-bottom: 1px solid #e9ecef;">
                        <strong>${item.wattage}W</strong>: ${item.pallet_count} pallet(s) containing ${item.pallet_modules.toLocaleString()} modules
                    </div>`;
                });

                listHtml += `<div style="padding: 10px 0 0; font-weight: 600; color: #dc3545;">
                    Total: ${totalPallets} pallet(s), ${totalModules.toLocaleString()} modules
                </div>`;

                palletList.innerHTML = listHtml;
                modal.style.display = 'flex';
                return false;
            }

            // Check for over-capacity
            if (capacityData.projectSizeMw > 0) {
                const newBatchMw = calculateNewBatchMw();
                const newTotalMw = capacityData.currentOrderedMw - capacityData.thisBatchMw + newBatchMw;

                if (newTotalMw > capacityData.projectSizeMw) {
                    const excessMw = newTotalMw - capacityData.projectSizeMw;
                    const excessPct = ((newTotalMw / capacityData.projectSizeMw) - 1) * 100;

                    const confirmMsg = `WARNING: This change will exceed the project capacity!\n\n` +
                        `New Total: ${newTotalMw.toFixed(2)} MW\n` +
                        `Target: ${capacityData.projectSizeMw.toFixed(2)} MW\n\n` +
                        `This is ${excessMw.toFixed(2)} MW (${excessPct.toFixed(1)}%) over the target.\n\n` +
                        `Are you sure you want to proceed?`;

                    if (!confirm(confirmMsg)) {
                        return false;
                    }
                }
            }

            // All checks passed, submit via AJAX
            submitFormViaAjax(form);
        });
    });

    // ========== Loading Modal Functions ==========
    function showLoadingModal(title = 'Saving Changes...', subtitle = 'Please wait while we process your request.') {
        const modal = document.getElementById('loadingModal');
        const titleEl = document.getElementById('loadingTitle');
        const subtitleEl = document.getElementById('loadingSubtitle');

        if (modal) {
            if (titleEl) titleEl.textContent = title;
            if (subtitleEl) subtitleEl.textContent = subtitle;
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    }

    function hideLoadingModal() {
        const modal = document.getElementById('loadingModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    // ========== Result Modal Functions ==========
    function showResultModal(success, data) {
        hideLoadingModal();

        const modal = document.getElementById('resultModal');
        const icon = document.getElementById('resultIcon');
        const title = document.getElementById('resultTitle');
        const message = document.getElementById('resultMessage');
        const details = document.getElementById('resultDetails');
        const modulesEl = document.getElementById('resultModules');
        const goBackBtn = document.getElementById('resultGoBackBtn');
        const closeBtn = document.getElementById('resultCloseBtn');

        const redirectTarget = data.redirect_url || (data.project_id ? `project_overview.php?project_id=${data.project_id}` : 'modules.php');

        if (success) {
            icon.innerHTML = '&#10004;';
            icon.style.color = '#28a745';

            if (data.action === 'delete') {
                title.textContent = 'Module Batch Deleted!';
            } else {
                title.textContent = 'Module Batch Updated!';
            }

            title.style.color = '#28a745';
            message.textContent = data.message || 'Your changes have been saved successfully.';

            // Show details for update action
            if (data.action === 'update' && data.total_modules) {
                details.style.display = 'block';
                modulesEl.textContent = (data.total_modules || 0).toLocaleString();
            } else {
                details.style.display = 'none';
            }

            // Update go back button
            if (redirectTarget) {
                goBackBtn.href = redirectTarget;
                if (data.project_id) {
                    goBackBtn.innerHTML = '<i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Project Overview';
                } else {
                    goBackBtn.innerHTML = '<i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Modules';
                }
                goBackBtn.style.display = 'inline-flex';
            }
            closeBtn.style.display = 'none';
        } else {
            icon.innerHTML = '&#10006;';
            icon.style.color = '#dc3545';
            title.textContent = 'Error';
            title.style.color = '#dc3545';
            message.textContent = data.message || 'An error occurred. Please try again.';

            details.style.display = 'none';
            goBackBtn.style.display = 'none';
            closeBtn.style.display = 'inline-flex';
        }

        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeResultModal() {
        const modal = document.getElementById('resultModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    // ========== AJAX Form Submission ==========
    function parseJsonResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            return response.json();
        }
        return response.text().then(text => {
            const error = new Error('Non-JSON response');
            error.rawText = text;
            throw error;
        });
    }

    function buildFriendlyErrorMessage(error) {
        if (error && error.rawText) {
            const temp = document.createElement('div');
            temp.innerHTML = error.rawText;
            const plain = (temp.textContent || temp.innerText || '').trim();
            if (plain) {
                return plain.substring(0, 500);
            }
        }
        if (error && error.message) {
            return error.message;
        }
        return 'An unexpected error occurred. Please try again.';
    }

    function submitFormViaAjax(form) {
        showLoadingModal('Saving Changes...', 'Please wait while we update your module batch.');

        const formData = new FormData(form);
        const actionUrl = form.getAttribute('action') || window.location.href;

        fetch(actionUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(parseJsonResponse)
        .then(data => {
            showResultModal(data.success, data);
        })
        .catch(error => {
            console.error('Error:', error);
            showResultModal(false, { message: buildFriendlyErrorMessage(error) });
        });
    }

    function submitDeleteViaAjax(form) {
        showLoadingModal('Deleting Module Batch...', 'Please wait while we remove the batch and associated pallets.');

        const formData = new FormData(form);
        const actionUrl = form.getAttribute('action') || window.location.href;

        fetch(actionUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(parseJsonResponse)
        .then(data => {
            showResultModal(data.success, data);
        })
        .catch(error => {
            console.error('Error:', error);
            showResultModal(false, { message: buildFriendlyErrorMessage(error) });
        });
    }

    // Delete batch modal handling
    document.addEventListener('DOMContentLoaded', function() {
        const deleteBatchBtn = document.getElementById('deleteBatchBtn');
        const deleteBatchModal = document.getElementById('deleteBatchModal');
        const cancelDeleteBatch = document.getElementById('cancelDeleteBatch');
        const confirmDeleteBatch = document.getElementById('confirmDeleteBatch');
        const deleteBatchForm = document.getElementById('deleteBatchForm');

        // Hide modals on page load
        hideLoadingModal();
        closeResultModal();

        if (deleteBatchBtn) {
            deleteBatchBtn.addEventListener('click', function() {
                deleteBatchModal.style.display = 'flex';
            });
        }

        if (cancelDeleteBatch) {
            cancelDeleteBatch.addEventListener('click', function() {
                deleteBatchModal.style.display = 'none';
            });
        }

        if (confirmDeleteBatch) {
            confirmDeleteBatch.addEventListener('click', function() {
                deleteBatchModal.style.display = 'none';
                submitDeleteViaAjax(deleteBatchForm);
            });
        }

        // Close modal on background click
        if (deleteBatchModal) {
            deleteBatchModal.addEventListener('click', function(e) {
                if (e.target === deleteBatchModal) {
                    deleteBatchModal.style.display = 'none';
                }
            });
        }
    });
</script>
</body>
</html>
