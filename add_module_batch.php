<?php
session_name("logistics_session");
session_start();

// Ensure the user is either an admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
    header('Location: unauthorized.php');
    exit();
}

require_once '../config.php';
require_once 'document_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

// Project capacity tracking variables
$project_size_mw = 0;
$current_ordered_mw = 0;
$remaining_mw = 0;

// If a project is provided, verify access and load it; otherwise enable unassigned flow
$project = null;
if ($project_id) {
    if ($role === 'admin') {
        $stmtAccess = $conn->prepare("
            SELECT p.*, ca.name as account_name
            FROM projects p 
            JOIN customer_accounts ca ON p.account_id = ca.id
            JOIN customer_account_users cau ON p.account_id = cau.account_id 
            WHERE p.id = ? AND cau.user_id = ? AND cau.role = 'admin'
        ");
        $stmtAccess->bind_param("ii", $project_id, $user_id);
    } else {
        $stmtAccess = $conn->prepare("
            SELECT p.*, ca.name as account_name
            FROM projects p 
            JOIN customer_accounts ca ON p.account_id = ca.id
            WHERE p.id = ?
        ");
        $stmtAccess->bind_param("i", $project_id);
    }
    $stmtAccess->execute();
    $result = $stmtAccess->get_result();
    if ($result->num_rows === 0) {
        header('Location: dashboard.php?error=access_denied');
        exit();
    }
    $project = $result->fetch_assoc();
    $stmtAccess->close();

    // Get project size and current MW ordered
    $project_size_mw = floatval($project['project_size'] ?? 0);

    // Calculate current ordered MW from existing modules
    $stmtMw = $conn->prepare("
        SELECT SUM(umi.wattage * umi.quantity) / 1000000 as ordered_mw
        FROM unassigned_module_items umi
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ?
    ");
    $stmtMw->bind_param("i", $project_id);
    $stmtMw->execute();
    $mwResult = $stmtMw->get_result()->fetch_assoc();
    $current_ordered_mw = floatval($mwResult['ordered_mw'] ?? 0);
    $stmtMw->close();

    $remaining_mw = max(0, $project_size_mw - $current_ordered_mw);
}

// For admin/global_admin with no project selected, prepare account/projects for selection
$accounts = [];
$projects_for_account = [];
$account_id_for_admin = null;
if (!$project && $role === 'admin') {
    $stmtAdmin = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1");
    $stmtAdmin->bind_param("i", $user_id);
    $stmtAdmin->execute();
    $stmtAdmin->bind_result($account_id_for_admin);
    $stmtAdmin->fetch();
    $stmtAdmin->close();
    if ($account_id_for_admin) {
        $stmtProj = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
        $stmtProj->bind_param("i", $account_id_for_admin);
        $stmtProj->execute();
        $resProj = $stmtProj->get_result();
        while ($row = $resProj->fetch_assoc()) { $projects_for_account[] = $row; }
        $stmtProj->close();
    }
}
if (!$project && $role === 'global_admin') {
    $resAcc = $conn->query("SELECT id, name FROM customer_accounts ORDER BY name ASC");
    if ($resAcc) { while ($r = $resAcc->fetch_assoc()) { $accounts[] = $r; } }
    $resProj = $conn->query("SELECT id, project_name, account_id FROM projects ORDER BY account_id, project_name ASC");
    if ($resProj) { while ($r = $resProj->fetch_assoc()) { $projects_for_account[] = $r; } }
}

// Get manufacturers for dropdown
$manufacturers = [];
$stmtManufacturers = $conn->prepare("SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC");
$stmtManufacturers->execute();
$manufacturersResult = $stmtManufacturers->get_result();
while ($manufacturer = $manufacturersResult->fetch_assoc()) {
    $manufacturers[] = $manufacturer;
}
$stmtManufacturers->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Detect AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // Allow selecting project on page
    if (!$project_id) {
        $project_id = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? intval($_POST['project_id']) : 0;
        if ($project_id) {
            // Load for account id
            $stmtP = $conn->prepare("SELECT account_id, project_name FROM projects WHERE id = ?");
            $stmtP->bind_param("i", $project_id);
            $stmtP->execute();
            $stmtP->bind_result($proj_acc_id, $proj_name);
            if ($stmtP->fetch()) {
                $project = ['id'=>$project_id, 'account_id'=>$proj_acc_id, 'project_name'=>$proj_name];
            }
            $stmtP->close();
        }
    }
    // Derive vendor/location from manufacturer + location selection (align with add_project Initial Module Batch)
    $manufacturer_id = isset($_POST['manufacturer_id']) && $_POST['manufacturer_id'] !== '' ? (int)$_POST['manufacturer_id'] : null;
    $location_id = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;
    $vendor_name = '';
    $initial_location = '';
    $modules_per_pallet = isset($_POST['modules_per_pallet']) && $_POST['modules_per_pallet'] !== '' ? intval($_POST['modules_per_pallet']) : null;
    $pallets_per_truck = isset($_POST['pallets_per_truck']) && $_POST['pallets_per_truck'] !== '' ? intval($_POST['pallets_per_truck']) : null;
    $modules_per_truck = isset($_POST['modules_per_truck']) && $_POST['modules_per_truck'] !== '' ? intval($_POST['modules_per_truck']) : null;
    $pallet_length_mm = isset($_POST['pallet_length_mm']) && $_POST['pallet_length_mm'] !== '' ? intval($_POST['pallet_length_mm']) : null;
    $pallet_depth_mm = isset($_POST['pallet_depth_mm']) && $_POST['pallet_depth_mm'] !== '' ? intval($_POST['pallet_depth_mm']) : null;
    $pallet_double_stacked_height_mm = isset($_POST['pallet_double_stacked_height_mm']) && $_POST['pallet_double_stacked_height_mm'] !== '' ? intval($_POST['pallet_double_stacked_height_mm']) : null;
    $pallet_total_weight_kg = isset($_POST['pallet_total_weight_kg']) && $_POST['pallet_total_weight_kg'] !== '' ? intval($_POST['pallet_total_weight_kg']) : null;
    $stacking_in_warehouse = trim($_POST['stacking_in_warehouse'] ?? '');
    $stacking_during_transport = trim($_POST['stacking_during_transport'] ?? '');
    $forklift_truck_long_side_mm = isset($_POST['forklift_truck_long_side_mm']) && $_POST['forklift_truck_long_side_mm'] !== '' ? intval($_POST['forklift_truck_long_side_mm']) : null;
    $forklift_truck_short_side_mm = isset($_POST['forklift_truck_short_side_mm']) && $_POST['forklift_truck_short_side_mm'] !== '' ? intval($_POST['forklift_truck_short_side_mm']) : null;
    $pallet_jack_long_side_mm = isset($_POST['pallet_jack_long_side_mm']) && $_POST['pallet_jack_long_side_mm'] !== '' ? intval($_POST['pallet_jack_long_side_mm']) : null;
    $pallet_jack_short_side_mm = isset($_POST['pallet_jack_short_side_mm']) && $_POST['pallet_jack_short_side_mm'] !== '' ? intval($_POST['pallet_jack_short_side_mm']) : null;
    $module_notes = trim($_POST['module_notes'] ?? '');
    $cost_per_watt = isset($_POST['cost_per_watt']) && $_POST['cost_per_watt'] !== '' ? floatval($_POST['cost_per_watt']) : null;

    // Build vendor/location defaults
    if ($manufacturer_id) {
        if ($stmtM = $conn->prepare("SELECT name FROM manufacturers WHERE id = ?")) {
            $stmtM->bind_param("i", $manufacturer_id);
            $stmtM->execute();
            $stmtM->bind_result($mname);
            if ($stmtM->fetch()) { $vendor_name = $mname; }
            $stmtM->close();
        }
        if ($location_id && ($stmtL = $conn->prepare("SELECT street_address, city, state, zip_code FROM manufacturer_locations WHERE id = ?"))) {
            $stmtL->bind_param("i", $location_id);
            $stmtL->execute();
            $stmtL->bind_result($st, $ci, $stt, $zip);
            if ($stmtL->fetch()) { $initial_location = implode(', ', array_filter([$st, $ci, $stt, $zip])); }
            $stmtL->close();
        } else {
            // Fallback to primary location
            if ($stmtL2 = $conn->prepare("SELECT street_address, city, state, zip_code FROM manufacturer_locations WHERE manufacturer_id = ? AND is_primary = TRUE LIMIT 1")) {
                $stmtL2->bind_param("i", $manufacturer_id);
                $stmtL2->execute();
                $stmtL2->bind_result($st2, $ci2, $stt2, $zip2);
                if ($stmtL2->fetch()) { $initial_location = implode(', ', array_filter([$st2, $ci2, $stt2, $zip2])); }
                $stmtL2->close();
            }
        }
    }
    if ($vendor_name === '') { $vendor_name = 'Unknown Manufacturer'; }
    if ($initial_location === '') {
        $initial_location = implode(', ', array_filter([$project['street_address'] ?? '', $project['city'] ?? '', $project['state'] ?? '', $project['zip_code'] ?? '']));
    }
    if ($initial_location === '') { $initial_location = 'Unassigned'; }

    // Validate required fields
    $errors = [];
    
    // Validate wattages and quantities
    if (!isset($_POST['wattages']) || !isset($_POST['quantities']) || empty($_POST['wattages'])) {
        $errors[] = 'At least one wattage and quantity is required';
    } else {
        $wattages = $_POST['wattages'];
        $quantities = $_POST['quantities'];
        
        if (count($wattages) !== count($quantities)) {
            $errors[] = 'Wattage and quantity arrays must match';
        } else {
            for ($i = 0; $i < count($wattages); $i++) {
                $wattage_str = trim($wattages[$i]);
                $quantity_str = trim($quantities[$i]);
                
                // Skip empty entries
                if (empty($wattage_str) && empty($quantity_str)) {
                    continue;
                }
                
                // Check if values are numeric and positive
                if (!is_numeric($wattage_str) || !is_numeric($quantity_str)) {
                    $errors[] = 'All wattages and quantities must be positive integers';
                    break;
                }
                
                $wattage = intval($wattage_str);
                $quantity = intval($quantity_str);
                
                if ($wattage <= 0 || $quantity <= 0) {
                    $errors[] = 'All wattages and quantities must be positive integers';
                    break;
                }
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->begin_transaction();
            
            // Insert new module batch
            $stmtInsert = $conn->prepare("
                INSERT INTO modules (
                    account_id, vendor_name, initial_location, project_id, modules_per_pallet,
                    pallets_per_truck, modules_per_truck, pallet_length_mm, pallet_depth_mm,
                    pallet_double_stacked_height_mm, pallet_total_weight_kg, stacking_in_warehouse,
                    stacking_during_transport, forklift_truck_long_side_mm, forklift_truck_short_side_mm,
                    pallet_jack_long_side_mm, pallet_jack_short_side_mm, module_notes, module_docs_url, cost_per_watt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)
            ");
            // Determine account for insert
            $insert_account_id = 0;
            if (!empty($project['account_id'])) {
                $insert_account_id = (int)$project['account_id'];
            } elseif ($role === 'admin' && $account_id_for_admin) {
                $insert_account_id = (int)$account_id_for_admin;
            } elseif ($role === 'global_admin') {
                $insert_account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
            }
            if ($insert_account_id <= 0) {
                throw new Exception('Please select a valid account (or project).');
            }

            $stmtInsert->bind_param("issiiiiiiiissiiiisd",
                $insert_account_id, $vendor_name, $initial_location, $project_id, $modules_per_pallet,
                $pallets_per_truck, $modules_per_truck, $pallet_length_mm, $pallet_depth_mm,
                $pallet_double_stacked_height_mm, $pallet_total_weight_kg, $stacking_in_warehouse,
                $stacking_during_transport, $forklift_truck_long_side_mm, $forklift_truck_short_side_mm,
                $pallet_jack_long_side_mm, $pallet_jack_short_side_mm, $module_notes, $cost_per_watt
            );
            
            if (!$stmtInsert->execute()) {
                throw new Exception("Error creating module batch: " . $stmtInsert->error);
            }
            
            $module_id = $conn->insert_id;
            $stmtInsert->close();
            
            // Insert wattage items
            $stmtWattages = $conn->prepare("
                INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) 
                VALUES (?, ?, ?)
            ");
            
            for ($i = 0; $i < count($wattages); $i++) {
                $wattage = intval(trim($wattages[$i]));
                $quantity = intval(trim($quantities[$i]));
                // Skip empty or zero entries defensively
                if ($wattage <= 0 || $quantity <= 0) { continue; }
                
                $stmtWattages->bind_param("iii", $module_id, $wattage, $quantity);
                if (!$stmtWattages->execute()) {
                    throw new Exception("Error adding wattage item: " . $stmtWattages->error);
                }
            }
            $stmtWattages->close();
            
            // Update project_wattage_orders for project size calculation (only if assigned to a project)
            if ($project_id > 0) {
                $stmtOrders = $conn->prepare("
                    INSERT INTO project_wattage_orders (project_id, wattage, total_order) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE total_order = total_order + VALUES(total_order)
                ");
                
                for ($i = 0; $i < count($wattages); $i++) {
                    $wattage = intval(trim($wattages[$i]));
                    $quantity = intval(trim($quantities[$i]));
                    // Skip empty or zero entries defensively
                    if ($wattage <= 0 || $quantity <= 0) { continue; }
                    
                    $stmtOrders->bind_param("iii", $project_id, $wattage, $quantity);
                    if (!$stmtOrders->execute()) {
                        throw new Exception("Error updating project wattage orders: " . $stmtOrders->error);
                    }
                }
                $stmtOrders->close();
                // Clean up zero or negative entries for this project, if any existed
                $stmtCleanup = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ? AND total_order <= 0");
                if ($stmtCleanup) {
                    $stmtCleanup->bind_param("i", $project_id);
                    $stmtCleanup->execute();
                    $stmtCleanup->close();
                }
            }

            // Auto-palletization - create pallets if enabled
            $enable_palletization = isset($_POST['enable_palletization']) && $_POST['enable_palletization'] === '1';
            $auto_modules_per_pallet = isset($_POST['auto_modules_per_pallet']) && $_POST['auto_modules_per_pallet'] !== '' ? intval($_POST['auto_modules_per_pallet']) : 0;

            $pallets_created = 0;
            if ($enable_palletization && $auto_modules_per_pallet > 0 && $project_id > 0) {
                // Get the unassigned_module_items we just created
                $stmtItems = $conn->prepare("
                    SELECT id, wattage, quantity
                    FROM unassigned_module_items
                    WHERE unassigned_module_id = ?
                ");
                $stmtItems->bind_param("i", $module_id);
                $stmtItems->execute();
                $itemsResult = $stmtItems->get_result();

                // Also update modules_per_pallet in the modules table if not already set
                if ($modules_per_pallet === null || $modules_per_pallet === 0) {
                    $stmtUpdateMpp = $conn->prepare("UPDATE modules SET modules_per_pallet = ? WHERE id = ?");
                    $stmtUpdateMpp->bind_param("ii", $auto_modules_per_pallet, $module_id);
                    $stmtUpdateMpp->execute();
                    $stmtUpdateMpp->close();
                }

                // Get the next pallet number for auto-generated IDs
                $stmtMaxPallet = $conn->query("SELECT MAX(CAST(SUBSTRING(pallet_identifier, 5) AS UNSIGNED)) as max_num FROM inventory_pallets WHERE pallet_identifier LIKE 'PAL-%'");
                $maxRow = $stmtMaxPallet->fetch_assoc();
                $nextPalletNum = ($maxRow['max_num'] ?? 0) + 1;

                $defaultStatus = 'At Manufacturer';

                // Handle NULL location_id properly
                $loc_id_for_insert = $location_id !== null ? $location_id : null;

                while ($item = $itemsResult->fetch_assoc()) {
                    $item_id = $item['id'];
                    $item_wattage = $item['wattage'];
                    $item_quantity = $item['quantity'];

                    // Calculate how many full pallets and partial pallet
                    $full_pallets = floor($item_quantity / $auto_modules_per_pallet);
                    $remaining_modules = $item_quantity % $auto_modules_per_pallet;

                    // Create full pallets
                    for ($p = 0; $p < $full_pallets; $p++) {
                        $pallet_id = 'PAL-' . str_pad($nextPalletNum++, 4, '0', STR_PAD_LEFT);

                        // Use direct query to handle NULL properly
                        $stmtPallet = $conn->prepare("
                            INSERT INTO inventory_pallets (
                                pallet_identifier, unassigned_module_item_id, wattage, quantity,
                                status, manufacturer, manufacturer_location_id, assigned_project_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmtPallet->bind_param("siiissii",
                            $pallet_id, $item_id, $item_wattage, $auto_modules_per_pallet,
                            $defaultStatus, $vendor_name, $loc_id_for_insert, $project_id
                        );
                        if (!$stmtPallet->execute()) {
                            throw new Exception("Error creating pallet: " . $stmtPallet->error);
                        }
                        $stmtPallet->close();
                        $pallets_created++;
                    }

                    // Create partial pallet if there are remaining modules
                    if ($remaining_modules > 0) {
                        $pallet_id = 'PAL-' . str_pad($nextPalletNum++, 4, '0', STR_PAD_LEFT);

                        $stmtPallet = $conn->prepare("
                            INSERT INTO inventory_pallets (
                                pallet_identifier, unassigned_module_item_id, wattage, quantity,
                                status, manufacturer, manufacturer_location_id, assigned_project_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmtPallet->bind_param("siiissii",
                            $pallet_id, $item_id, $item_wattage, $remaining_modules,
                            $defaultStatus, $vendor_name, $loc_id_for_insert, $project_id
                        );
                        if (!$stmtPallet->execute()) {
                            throw new Exception("Error creating partial pallet: " . $stmtPallet->error);
                        }
                        $stmtPallet->close();
                        $pallets_created++;
                    }
                }

                $stmtItems->close();
            }

            // If documentation files are provided and a project is selected, save them to project_documents
            $hasDocs = isset($_FILES['module_docs']) && (
                (is_array($_FILES['module_docs']['error']) && count(array_filter($_FILES['module_docs']['error'], fn($e)=>$e!==UPLOAD_ERR_NO_FILE))>0) ||
                (!is_array($_FILES['module_docs']['error']) && $_FILES['module_docs']['error'] !== UPLOAD_ERR_NO_FILE)
            );
            if ($hasDocs) {
                $module_docs_sub_type = trim($_POST['module_docs_sub_type'] ?? '');
                $module_docs_description = trim($_POST['module_docs_description'] ?? '');
                $names = (array)$_FILES['module_docs']['name'];
                $tmps  = (array)$_FILES['module_docs']['tmp_name'];
                $errs  = (array)$_FILES['module_docs']['error'];
                $sizes = (array)$_FILES['module_docs']['size'];
                $firstSaved = '';
                foreach ($names as $i => $orig) {
                    if (!isset($errs[$i]) || $errs[$i] === UPLOAD_ERR_NO_FILE) continue;
                    if ($errs[$i] !== UPLOAD_ERR_OK) {
                        throw new Exception('Module document upload error for file: ' . $orig);
                    }
                    $tmpName = $tmps[$i];
                    $mime    = mime_content_type($tmpName);
                    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed_ext = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','txt','csv'];
                    if (!in_array($ext, $allowed_ext)) {
                        throw new Exception('Invalid documentation file type: ' . $ext);
                    }
                    if ($project_id > 0) {
                        if ($module_docs_sub_type === '') {
                            throw new Exception('Please choose a Module Documentation Sub-Type when uploading files.');
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
                            'description' => ($module_docs_description !== '' ? $module_docs_description : 'Module Documentation')
                        ];
                        saveDocumentToProjectDocuments($conn, $doc);
                    } else {
                        // Save under batch folder and link first file to modules.module_docs_url
                        $baseDir = 'uploads/module_batches/' . $module_id . '/';
                        if (!is_dir($baseDir)) { @mkdir($baseDir, 0755, true); }
                        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
                        $server = time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeName . '.' . $ext;
                        $dest = $baseDir . $server;
                        if (!move_uploaded_file($tmpName, $dest)) {
                            throw new Exception('Failed to store documentation file: ' . $orig);
                        }
                        if ($firstSaved === '') { $firstSaved = $dest; }
                    }
                }
                if ($project_id <= 0 && $firstSaved !== '') {
                    $stmtUpd = $conn->prepare("UPDATE modules SET module_docs_url = ? WHERE id = ?");
                    if ($stmtUpd) {
                        $stmtUpd->bind_param("si", $firstSaved, $module_id);
                        $stmtUpd->execute();
                        $stmtUpd->close();
                    }
                }
            }

            $conn->commit();

            // Calculate total modules for the response
            $total_modules = 0;
            for ($i = 0; $i < count($wattages); $i++) {
                $qty = intval(trim($quantities[$i]));
                if ($qty > 0) $total_modules += $qty;
            }

            // Return JSON for AJAX requests
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Module batch created successfully!',
                    'module_id' => $module_id,
                    'pallets_created' => $pallets_created,
                    'total_modules' => $total_modules,
                    'project_id' => $project_id,
                    'redirect_url' => $project_id ? "project_overview.php?project_id={$project_id}" : 'modules.php'
                ]);
                $conn->close();
                exit();
            }

            // Build redirect URL with success message including pallets count (non-AJAX fallback)
            $success_msg = 'batch_added';
            if ($pallets_created > 0) {
                $success_msg = 'batch_added&pallets_created=' . $pallets_created;
            }
            $redirect = $project_id ? ("project_overview.php?project_id={$project_id}&success={$success_msg}") : 'modules.php?success=batch_added';
            header("Location: $redirect");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error creating module batch: " . $e->getMessage();

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
    } else {
        // Validation errors - return JSON for AJAX
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => implode(', ', $errors)
            ]);
            $conn->close();
            exit();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Module Batch<?php echo $project ? (' - ' . htmlspecialchars($project['project_name'])) : ''; ?></title>
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
        
        .form-section h2 {
            color: #293E4C;
            margin-bottom: 20px;
            font-size: 1.3em;
            font-weight: 600;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 8px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #488C9A;
        }
        
        .wattage-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .wattage-entry {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 15px;
            align-items: end;
            margin-bottom: 15px;
        }
        
        .add-wattage-btn,
        .remove-wattage-btn {
            padding: 10px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .add-wattage-btn {
            background: #488C9A;
            color: white;
            margin-bottom: 15px;
        }
        
        .add-wattage-btn:hover {
            background: #3A6E7F;
        }
        
        .remove-wattage-btn {
            background: #dc3545;
            color: white;
        }
        
        .remove-wattage-btn:hover {
            background: #c82333;
        }
        
         .button-group {
             display: flex;
             justify-content: center;
             align-items: center;
             margin-top: 40px;
             gap: 20px;
         }
         
         .btn-cancel {
             background: #6c757d;
             color: white;
             padding: 12px 30px;
             border: none;
             border-radius: 8px;
             text-decoration: none;
             font-weight: 600;
             transition: all 0.3s ease;
         }
         
         .btn-cancel:hover {
             background: #5a6268;
             transform: translateY(-1px);
         }
         
         .btn-submit {
             background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
             color: white;
             border: none;
             padding: 16px 48px;
             border-radius: 50px;
             font-size: 1.1em;
             font-weight: 600;
             cursor: pointer;
             transition: all 0.3s ease;
             box-shadow: 0 4px 16px rgba(40, 62, 76, 0.2);
         }
         
         .btn-submit:hover {
             transform: translateY(-2px);
             box-shadow: 0 8px 24px rgba(40, 62, 76, 0.3);
         }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .wattage-entry {
                grid-template-columns: 1fr;
                gap: 10px;
            }

             .button-group {
                 flex-direction: column;
                 align-items: center;
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

            .header-icon {
                width: 60px;
                height: 60px;
                font-size: 24px;
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
    <?php require_once 'components/breadcrumbs.php'; ?>
    
    <main>
        <?php
            $extra = [];
            if (!$project_id) { $extra[] = ['label' => 'Modules', 'url' => 'modules.php']; }
            echo slp_render_breadcrumbs(['current_label' => 'Add Module Batch', 'extra' => $extra]);
        ?>
        
        <!-- Beautiful Header Section -->
        <div class="form-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-info">
                        <h1>Add Module Batch</h1>
                        <p class="header-subtitle">
                            <?php if ($project): ?>
                                Adding modules to <?php echo htmlspecialchars($project['project_name']); ?>
                            <?php else: ?>
                                Create a new module batch for assignment
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Entry Method Toggle -->
        <div class="entry-method-toggle" style="background: #fff; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
            <h3 style="margin: 0 0 16px 0; color: #293E4C; font-size: 1.1em;">How would you like to add modules?</h3>
            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                <label class="method-option" style="flex: 1; min-width: 200px; padding: 20px; border: 2px solid #488C9A; border-radius: 12px; cursor: pointer; background: rgba(72,140,154,0.05); transition: all 0.2s ease;">
                    <input type="radio" name="entry_method" value="manual" checked style="margin-right: 10px;">
                    <strong style="color: #293E4C;">Manual Entry</strong>
                    <p style="margin: 8px 0 0 0; font-size: 0.9em; color: #6c757d;">Enter generic module batch information manually. Pallets can be auto-generated with system IDs.</p>
                </label>
                <label class="method-option" style="flex: 1; min-width: 200px; padding: 20px; border: 2px solid #e9ecef; border-radius: 12px; cursor: pointer; background: #f8f9fa; transition: all 0.2s ease;">
                    <input type="radio" name="entry_method" value="import" style="margin-right: 10px;">
                    <strong style="color: #293E4C;">Import Pallets</strong>
                    <p style="margin: 8px 0 0 0; font-size: 0.9em; color: #6c757d;">Upload real manufacturer pallet data from a CSV/Excel file.</p>
                </label>
            </div>
        </div>

        <?php if ($project && $project_size_mw > 0): ?>
        <!-- Project Capacity Info Banner -->
        <div id="capacityBanner" style="background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%); border: 1px solid #b8daff; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
            <h3 style="margin: 0 0 16px 0; color: #0056b3; font-size: 1.1em; display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.2em;">&#9889;</span> Project Capacity Status
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                    <div style="font-size: 1.6rem; font-weight: 700; color: #488C9A;"><?php echo number_format($current_ordered_mw, 2); ?> MW</div>
                    <div style="font-size: 0.85rem; color: #6c757d;">Currently Ordered</div>
                </div>
                <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                    <div style="font-size: 1.6rem; font-weight: 700; color: #293E4C;"><?php echo number_format($project_size_mw, 2); ?> MW</div>
                    <div style="font-size: 0.85rem; color: #6c757d;">Project Target</div>
                </div>
                <div style="background: white; border-radius: 12px; padding: 16px; text-align: center;">
                    <div style="font-size: 1.6rem; font-weight: 700; color: <?php echo $remaining_mw > 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($remaining_mw, 2); ?> MW</div>
                    <div style="font-size: 0.85rem; color: #6c757d;">Remaining Capacity</div>
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
            <div id="newMwPreview" style="display: none; margin-top: 16px; padding: 12px; background: white; border-radius: 8px; border: 2px solid #ffc107;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: #856404; font-weight: 500;">New Total After This Batch:</span>
                    <span id="newTotalMw" style="font-weight: 700; color: #856404;">0.00 MW</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-container" id="manualEntryContainer">
            
            <div class="form-content">
                <?php if (!empty($errors)): ?>
                    <div class="error-message">
                        <ul style="margin: 0; padding-left: 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="addBatchForm" enctype="multipart/form-data">
                    <?php if (!$project): ?>
                        <div class="form-section">
                            <h2>Assignment</h2>
                            <div class="form-grid">
                                <?php if ($role === 'global_admin'): ?>
                                <div class="form-group">
                                    <label for="account_id">Account (required if no project)</label>
                                    <select name="account_id" id="account_id" required>
                                        <option value="">Select Account</option>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?php echo (int)$acc['id']; ?>"><?php echo htmlspecialchars($acc['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="form-group">
                                    <label for="project_id">Assign to Project (optional)</label>
                                    <select name="project_id" id="project_id">
                                        <option value="">-- None (Unassigned) --</option>
                                        <?php foreach ($projects_for_account as $p): ?>
                                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['project_name']); ?><?php if (!empty($p['account_id'])) echo ' (Acct '.$p['account_id'].')'; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php $prefManufacturerId = null; $prefLocationId = null; $existingWattages = []; include __DIR__ . '/components/module_batch_section.php'; ?>

                    <!-- Palletization Section -->
                    <div class="form-section" style="margin-top: 30px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border: 1px solid #e9ecef; border-left: 4px solid #488C9A; border-radius: 12px; padding: 24px;">
                        <h2 style="display: flex; align-items: center; gap: 10px; margin: 0 0 12px 0;">
                            <span style="font-size: 1.2em;">📦</span> Auto-Palletization
                            <span style="color: #999; font-weight: 400; font-size: 0.75rem;">(optional)</span>
                        </h2>

                        <!-- Explanation Box -->
                        <div style="background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%); border: 1px solid #ffc107; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                            <div style="display: flex; align-items: flex-start; gap: 12px;">
                                <span style="font-size: 1.2rem;">💡</span>
                                <div>
                                    <div style="font-weight: 600; color: #856404; margin-bottom: 6px;">How Auto-Palletization Works</div>
                                    <ul style="margin: 0; padding-left: 18px; color: #856404; font-size: 0.9rem; line-height: 1.6;">
                                        <li>Pallets will be created with <strong>system-generated IDs</strong> (e.g., PAL-0001, PAL-0002)</li>
                                        <li>You can link actual <strong>manufacturer pallet IDs</strong> to these later when shipments arrive</li>
                                        <li>This is ideal for planning and tracking before real pallet data is available</li>
                                        <li>For importing real manufacturer pallet data, use the <strong>Import Pallets</strong> option instead</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: stretch; gap: 16px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 16px 20px; background: #fff; border-radius: 10px; border: 2px solid #e9ecef; transition: all 0.2s ease;" id="enablePalletizationLabel">
                                <input type="checkbox" name="enable_palletization" id="enable_palletization" value="1" style="width: 22px; height: 22px; cursor: pointer; accent-color: #488C9A;">
                                <div>
                                    <div style="font-weight: 600; color: #293E4C; font-size: 1rem;">Enable Auto-Palletization</div>
                                    <div style="font-size: 0.85rem; color: #6c757d;">Create pallets automatically on save</div>
                                </div>
                            </label>

                            <div id="palletizationConfig" style="display: none; padding: 16px 20px; background: #fff; border-radius: 10px; border: 2px solid #488C9A; box-shadow: 0 4px 12px rgba(72,140,154,0.15);">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <label for="auto_modules_per_pallet" style="font-weight: 600; color: #293E4C; font-size: 0.95rem; white-space: nowrap;">
                                        Modules per Pallet:
                                    </label>
                                    <input type="number" name="auto_modules_per_pallet" id="auto_modules_per_pallet" min="1" placeholder="30" style="width: 80px; padding: 8px 12px; border: 2px solid #e9ecef; border-radius: 6px; font-size: 1rem; font-weight: 600; text-align: center;">
                                    <div id="palletCalculation" style="display: none; padding: 6px 12px; background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%); border-radius: 6px; border: 1px solid #c8e6c9;">
                                        <span style="font-size: 0.9rem; font-weight: 600; color: #28a745;" id="calcTotalPallets">0 pallets</span>
                                        <span id="calcPartialPallet" style="color: #856404; font-size: 0.85rem; margin-left: 4px;"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Detailed pallet calculation (shown below when there's data) -->
                        <div id="palletCalcDetails" style="display: none; margin-top: 16px; padding: 16px; background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%); border-radius: 10px; border: 1px solid #c8e6c9;">
                            <div style="display: flex; gap: 24px; flex-wrap: wrap; justify-content: center;">
                                <div style="text-align: center;">
                                    <div style="font-size: 1.3rem; font-weight: 700; color: #293E4C;" id="calcTotalModules">0</div>
                                    <div style="font-size: 0.75rem; color: #6c757d;">Total Modules</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 1.3rem; font-weight: 700; color: #28a745;" id="calcFullPallets">0</div>
                                    <div style="font-size: 0.75rem; color: #6c757d;">Full Pallets</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 1.3rem; font-weight: 700; color: #ffc107;" id="calcPartialModules">0</div>
                                    <div style="font-size: 0.75rem; color: #6c757d;">Partial Pallet</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Basic Information -->
                    <div class="form-section" style="display:none">
                        <h2>Basic Information</h2>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="vendor_name">Vendor/Manufacturer Name: *</label>
                                <input type="text" name="vendor_name" id="vendor_name" value="<?php echo htmlspecialchars($_POST['vendor_name'] ?? ''); ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label for="initial_location">Initial Location: *</label>
                                <input type="text" name="initial_location" id="initial_location" value="<?php echo htmlspecialchars($_POST['initial_location'] ?? ''); ?>" disabled>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Wattage & Quantities -->
                    <div class="form-section" style="display:none">
                        <h2>Module Configuration</h2>
                        <div class="wattage-container">
                            <button type="button" class="add-wattage-btn" onclick="addWattageEntry()">+ Add Wattage</button>
                            <div id="wattage-entries">
                                <div class="wattage-entry">
                                    <div class="form-group">
                                        <label>Wattage (W)</label>
                                        <input type="number" name="wattages[]" min="1">
                                    </div>
                                    <div class="form-group">
                                        <label>Quantity</label>
                                        <input type="number" name="quantities[]" min="1">
                                    </div>
                                    <button type="button" class="remove-wattage-btn" onclick="removeWattageEntry(this)">Remove</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Logistics Specifications -->
                    <div class="form-section" style="display:none">
                        <h2>Logistics Specifications (Optional)</h2>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="modules_per_pallet">Modules per Pallet:</label>
                                <input type="number" name="modules_per_pallet" id="modules_per_pallet" value="<?php echo htmlspecialchars($_POST['modules_per_pallet'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallets_per_truck">Pallets per Truck:</label>
                                <input type="number" name="pallets_per_truck" id="pallets_per_truck" value="<?php echo htmlspecialchars($_POST['pallets_per_truck'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="modules_per_truck">Modules per Truck:</label>
                                <input type="number" name="modules_per_truck" id="modules_per_truck" value="<?php echo htmlspecialchars($_POST['modules_per_truck'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_length_mm">Pallet Length (mm):</label>
                                <input type="number" name="pallet_length_mm" id="pallet_length_mm" value="<?php echo htmlspecialchars($_POST['pallet_length_mm'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_depth_mm">Pallet Depth (mm):</label>
                                <input type="number" name="pallet_depth_mm" id="pallet_depth_mm" value="<?php echo htmlspecialchars($_POST['pallet_depth_mm'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_double_stacked_height_mm">Stack Height (mm):</label>
                                <input type="number" name="pallet_double_stacked_height_mm" id="pallet_double_stacked_height_mm" value="<?php echo htmlspecialchars($_POST['pallet_double_stacked_height_mm'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_total_weight_kg">Total Weight (kg):</label>
                                <input type="number" name="pallet_total_weight_kg" id="pallet_total_weight_kg" value="<?php echo htmlspecialchars($_POST['pallet_total_weight_kg'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="forklift_truck_long_side_mm">Forklift Long Side (mm):</label>
                                <input type="number" name="forklift_truck_long_side_mm" id="forklift_truck_long_side_mm" value="<?php echo htmlspecialchars($_POST['forklift_truck_long_side_mm'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="forklift_truck_short_side_mm">Forklift Short Side (mm):</label>
                                <input type="number" name="forklift_truck_short_side_mm" id="forklift_truck_short_side_mm" value="<?php echo htmlspecialchars($_POST['forklift_truck_short_side_mm'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_jack_long_side_mm">Pallet Jack Long Side (mm):</label>
                                <input type="number" name="pallet_jack_long_side_mm" id="pallet_jack_long_side_mm" value="<?php echo htmlspecialchars($_POST['pallet_jack_long_side_mm'] ?? ''); ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_jack_short_side_mm">Pallet Jack Short Side (mm):</label>
                                <input type="number" name="pallet_jack_short_side_mm" id="pallet_jack_short_side_mm" value="<?php echo htmlspecialchars($_POST['pallet_jack_short_side_mm'] ?? ''); ?>" min="0">
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="stacking_in_warehouse">Warehouse Stacking Instructions:</label>
                                <textarea name="stacking_in_warehouse" id="stacking_in_warehouse" rows="3"><?php echo htmlspecialchars($_POST['stacking_in_warehouse'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="stacking_during_transport">Transport Stacking Instructions:</label>
                                <textarea name="stacking_during_transport" id="stacking_during_transport" rows="3"><?php echo htmlspecialchars($_POST['stacking_during_transport'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="module_notes">Module Notes:</label>
                            <textarea name="module_notes" id="module_notes" rows="4"><?php echo htmlspecialchars($_POST['module_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="button-group">
                        <a href="<?php echo $project ? ('project_overview.php?project_id='.(int)$project_id) : 'modules.php'; ?>" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-submit">Create Module Batch</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Import Container (hidden by default) -->
        <div id="importContainer" style="display: none; background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 40px; margin-bottom: 20px;">
            <div style="text-align: center; padding: 40px 20px;">
                <div style="font-size: 48px; color: #488C9A; margin-bottom: 16px;">📦</div>
                <h2 style="color: #293E4C; margin-bottom: 12px;">Import Pallets</h2>
                <p style="color: #6c757d; margin-bottom: 24px; max-width: 500px; margin-left: auto; margin-right: auto;">
                    Upload a CSV or Excel file with pallet data from your manufacturer to add pallets to inventory. Each row = one pallet.
                </p>
                <a href="upload_pallets.php<?php echo $project_id ? '?project_id='.$project_id : ''; ?>"
                   class="btn-submit" style="display: inline-block; text-decoration: none; padding: 16px 32px;">
                    Import Pallets →
                </a>
            </div>
        </div>

        <!-- Module Documentation Upload Modal -->
        <div id="moduleUploadModal" class="upload-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
            <div class="modal-content" style="background: white; border-radius: 12px; padding: 0; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
                <div class="modal-header" style="padding: 24px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center;">
                    <h2 class="modal-title" style="margin: 0; color: #293E4C; font-size: 1.5em;">
                        <i class="fas fa-file-alt" style="margin-right: 8px; color: #488C9A;"></i>
                        Upload Module Documentation
                    </h2>
                    <button type="button" class="close-modal" onclick="closeModuleUploadModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6c757d;">&times;</button>
                </div>
                 <div class="modal-body" style="padding: 24px;">
                     <div class="input-group" style="margin-bottom: 20px;">
                         <label for="module_docs_sub_type" style="display: block; font-weight: 600; margin-bottom: 8px;">Document Sub-Type <span style="color:#999; font-weight:400;">(required only if uploading)</span></label>
                         <select id="module_docs_sub_type" name="module_docs_sub_type" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px;">
                             <option value="">Choose sub-type...</option>
                             <option value="Module Invoice">Module Invoice</option>
                             <option value="Flash Test Data">Flash Test Data</option>
                             <option value="Spec Sheets">Spec Sheets</option>
                         </select>
                     </div>
                     <div class="input-group" style="margin-bottom: 20px;">
                         <label for="module_docs_description" style="display: block; font-weight: 600; margin-bottom: 8px;">Description (Optional)</label>
                         <input type="text" id="module_docs_description" name="module_docs_description" placeholder="Short description" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; box-sizing: border-box;">
                     </div>
                     <div class="input-group" style="margin-bottom: 20px;">
                         <label style="display: block; font-weight: 600; margin-bottom: 8px;">Select File(s)</label>
                         <div class="file-upload-area" onclick="document.getElementById('module_docs').click()" style="border: 2px dashed #488C9A; border-radius: 12px; padding: 40px; text-align: center; cursor: pointer; background: #f8f9fa; transition: all 0.3s ease;">
                             <i class="fas fa-cloud-upload-alt upload-icon" style="font-size: 48px; color: #488C9A; margin-bottom: 16px;"></i>
                             <div class="upload-text" style="font-size: 1.1em; font-weight: 600; color: #293E4C; margin-bottom: 8px;">Drop files here or click to browse</div>
                             <div class="upload-subtext" style="color: #6c757d; font-size: 0.9em;">Supports: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, CSV (Max: 50MB each)</div>
                         </div>
                         <input type="file" id="module_docs" name="module_docs[]" multiple style="display:none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.csv">
                         <div id="moduleDocFileList" class="file-list" style="margin-top: 16px;"></div>
                     </div>
                     <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                         <button type="button" onclick="closePreModuleUploadModal()" style="background: #6c757d; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer;">Cancel</button>
                         <button type="button" onclick="confirmPreModuleDocs()" style="background: #488C9A; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600;">Done</button>
                     </div>
                 </div>
            </div>
        </div>

        <!-- Loading Modal for Module Batch Creation -->
        <div id="loadingModal" class="loading-modal">
            <div class="loading-content">
                <div class="spinner"></div>
                <h3 id="loadingTitle">Creating Module Batch...</h3>
                <p id="loadingSubtitle">Please wait while we process your request.</p>
            </div>
        </div>

        <!-- Result Modal (Success/Error) -->
        <div id="resultModal" class="loading-modal" style="display: none;">
            <div class="loading-content" style="max-width: 480px; padding: 32px 40px;">
                <div id="resultIcon" style="font-size: 56px; margin-bottom: 16px;"></div>
                <h3 id="resultTitle" style="margin: 0 0 12px 0; font-size: 1.4em;"></h3>
                <p id="resultMessage" style="margin: 0 0 8px 0; color: #6c757d;"></p>
                <div id="resultDetails" style="margin: 16px 0; padding: 16px; background: #f8f9fa; border-radius: 8px; text-align: left; display: none;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: #488C9A;" id="resultModules">0</div>
                            <div style="font-size: 0.85rem; color: #6c757d;">Modules Added</div>
                        </div>
                        <div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: #28a745;" id="resultPallets">0</div>
                            <div style="font-size: 0.85rem; color: #6c757d;">Pallets Created</div>
                        </div>
                    </div>
                </div>
                <div id="resultWarning" style="display: none; margin: 16px 0; padding: 12px 16px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; text-align: left;">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <span style="font-size: 1.1rem;">&#9888;&#65039;</span>
                        <div style="font-size: 0.9rem; color: #856404;" id="resultWarningText"></div>
                    </div>
                </div>
                <div style="display: flex; gap: 12px; justify-content: center; margin-top: 24px; flex-wrap: wrap;">
                    <button type="button" id="resultAddAnotherBtn" onclick="resetFormForAnother()" style="background: #6c757d; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 500; display: none;">
                        <i class="fas fa-plus" style="margin-right: 6px;"></i> Add Another Batch
                    </button>
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
        function addWattageEntry() {
            const container = document.getElementById('wattage-entries');
            const newEntry = document.createElement('div');
            newEntry.className = 'wattage-entry';
            newEntry.innerHTML = `
                <div class="form-group">
                    <label>Wattage (W)</label>
                    <input type="number" name="wattages[]" min="1">
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantities[]" min="1">
                </div>
                <button type="button" class="remove-wattage-btn" onclick="removeWattageEntry(this)">Remove</button>
            `;
            container.appendChild(newEntry);
        }
        
        function removeWattageEntry(button) {
            const container = document.getElementById('wattage-entries');
            if (container.children.length > 1) {
                button.parentElement.remove();
            } else {
                alert('At least one wattage entry is required.');
            }
        }

        // Module Documentation Upload Modal controls
        function openModuleUploadModal() {
            const modal = document.getElementById('moduleUploadModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModuleUploadModal() {
            const modal = document.getElementById('moduleUploadModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
                // Reset form
                document.getElementById('moduleDocSubType').value = '';
                document.getElementById('moduleDocDescription').value = '';
                document.getElementById('moduleDocFiles').value = '';
                document.getElementById('moduleDocFileList').innerHTML = '';
                document.getElementById('moduleDocProgress').style.display = 'none';
            }
        }

        async function uploadModuleDocuments() {
            const filesInput = document.getElementById('moduleDocFiles');
            const subTypeEl = document.getElementById('moduleDocSubType');
            const descEl = document.getElementById('moduleDocDescription');
            const progress = document.getElementById('moduleDocProgress');
            const fill = document.getElementById('moduleDocProgressFill');
            const text = document.getElementById('moduleDocProgressText');
            const files = filesInput ? filesInput.files : null;
            const subType = subTypeEl ? (subTypeEl.value || '').trim() : '';
            const description = descEl ? (descEl.value || '').trim() : '';
            const projectId = <?php echo $project_id ? (int)$project_id : 0; ?>;

            if (!projectId) { 
                alert('Project not found. Please save the module batch first or select a project.'); 
                return; 
            }
            if (!files || files.length === 0) { 
                alert('Please select at least one file.'); 
                return; 
            }
            if (!subType) { 
                alert('Please select a document sub-type.'); 
                return; 
            }

            progress.style.display = 'block';
            fill.style.width = '0%';
            text.textContent = 'Uploading...';

            try {
                for (let i = 0; i < files.length; i++) {
                    const fd = new FormData();
                    fd.append('document', files[i]);
                    fd.append('project_id', projectId);
                    fd.append('document_type', 'modules');
                    fd.append('document_sub_type', subType);
                    if (description) fd.append('description', description);

                    await new Promise((resolve, reject) => {
                        const xhr = new XMLHttpRequest();
                        xhr.upload.onprogress = function(e){
                            if (e.lengthComputable) {
                                const pct = ((i + e.loaded / e.total) / files.length) * 100;
                                fill.style.width = pct + '%';
                                text.textContent = 'Uploading ' + (i+1) + '/' + files.length + '...';
                            }
                        };
                        xhr.onload = function(){
                            if (xhr.status === 200) {
                                const resp = JSON.parse(xhr.responseText || '{}');
                                if (resp.success) resolve(); 
                                else reject(new Error(resp.message || 'Upload failed'));
                            } else {
                                reject(new Error('Upload failed'));
                            }
                        };
                        xhr.onerror = () => reject(new Error('Network error'));
                        xhr.open('POST', 'upload_project_document.php');
                        xhr.send(fd);
                    });
                }
                text.textContent = 'Upload complete';
                setTimeout(() => { 
                    closeModuleUploadModal(); 
                    updateModuleDocumentsList();
                }, 600);
                alert('Module documentation uploaded successfully.');
            } catch (err) {
                alert('Upload failed: ' + err.message);
                progress.style.display = 'none';
            }
        }

        function updateModuleDocumentsList() {
            // This would typically fetch and display uploaded documents
            // For now, just show a simple confirmation
            const list = document.getElementById('moduleDocumentsList');
            if (list) {
                list.innerHTML = '<div style="color: #28a745; font-size: 0.9em; margin-top: 8px;"><i class="fas fa-check-circle"></i> Documents uploaded successfully</div>';
            }
        }

         // Pre-creation Module Documentation Modal controls
         function openPreModuleUploadModal(){
             const m = document.getElementById('moduleUploadModal');
             if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
         }
         
         function closePreModuleUploadModal(){
             const m = document.getElementById('moduleUploadModal');
             if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
         }
         
         function confirmPreModuleDocs(){
             const select = document.getElementById('module_docs_sub_type');
             const files = document.getElementById('module_docs');
             if (!select || !files) { closePreModuleUploadModal(); return; }
             if (files.files && files.files.length > 0 && !select.value) {
                 alert('Please choose a Module Documentation Sub-Type.');
                 return;
             }
             const summary = document.getElementById('preModuleDocsSummary');
             if (summary) {
                 const n = files.files ? files.files.length : 0;
                 if (n > 0) {
                     summary.style.display = '';
                     summary.textContent = n + ' file' + (n>1?'s':'') + ' attached (' + select.value + ')';
                 } else {
                     summary.style.display = 'none'; 
                     summary.textContent = '';
                 }
             }
             closePreModuleUploadModal();
         }

         // File selection display
         document.addEventListener('DOMContentLoaded', function() {
             const fileInput = document.getElementById('module_docs');
             const fileList = document.getElementById('moduleDocFileList');

             if (fileInput && fileList) {
                 fileInput.addEventListener('change', function() {
                     fileList.innerHTML = '';
                     if (this.files.length > 0) {
                         for (let i = 0; i < this.files.length; i++) {
                             const file = this.files[i];
                             const fileItem = document.createElement('div');
                             fileItem.style.cssText = 'display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8f9fa; border-radius: 6px; margin-bottom: 8px; border: 1px solid #e9ecef;';
                             fileItem.innerHTML = `
                                 <span style="font-size: 0.9em; color: #293E4C;">${file.name}</span>
                                 <span style="font-size: 0.8em; color: #6c757d;">${(file.size / 1024 / 1024).toFixed(2)} MB</span>
                             `;
                             fileList.appendChild(fileItem);
                         }
                     }
                 });
             }

             // Entry method toggle
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

         // MW Capacity tracking
         const capacityData = {
             projectSizeMw: <?php echo json_encode($project_size_mw); ?>,
             currentOrderedMw: <?php echo json_encode($current_ordered_mw); ?>
         };

         function calculateBatchMw() {
             let batchMw = 0;
             // Get wattages and quantities from the component form
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

             const batchMw = calculateBatchMw();
             const newTotalMw = capacityData.currentOrderedMw + batchMw;
             const previewDiv = document.getElementById('newMwPreview');

             if (batchMw > 0 && previewDiv) {
                 previewDiv.style.display = 'block';

                 // Update styling based on capacity
                 if (newTotalMw > capacityData.projectSizeMw) {
                     const excessMw = newTotalMw - capacityData.projectSizeMw;
                     const excessPct = ((newTotalMw / capacityData.projectSizeMw) - 1) * 100;
                     previewDiv.style.borderColor = '#dc3545';
                     previewDiv.style.background = '#f8d7da';
                     previewDiv.innerHTML = `
                         <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                             <span style="color: #721c24; font-weight: 600;">&#9888; Over Capacity Warning</span>
                             <span style="font-weight: 700; color: #721c24;">${newTotalMw.toFixed(2)} MW</span>
                         </div>
                         <div style="font-size: 0.9rem; color: #721c24;">
                             This batch will exceed the project target by ${excessMw.toFixed(2)} MW (${excessPct.toFixed(1)}% over).
                             You will be asked to confirm before saving.
                         </div>
                         <div style="font-size: 0.85rem; color: #856404; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.1);">
                             This batch adds: <strong>+${batchMw.toFixed(4)} MW</strong>
                         </div>
                     `;
                 } else {
                     previewDiv.style.borderColor = '#28a745';
                     previewDiv.style.background = '#d4edda';
                     const remainingAfter = capacityData.projectSizeMw - newTotalMw;
                     previewDiv.innerHTML = `
                         <div style="display: flex; justify-content: space-between; align-items: center;">
                             <span style="color: #155724; font-weight: 500;">New Total After This Batch:</span>
                             <span style="font-weight: 700; color: #155724;">${newTotalMw.toFixed(2)} MW</span>
                         </div>
                         <div style="font-size: 0.85rem; color: #155724; margin-top: 4px;">
                             ${remainingAfter.toFixed(2)} MW remaining capacity
                         </div>
                         <div style="font-size: 0.85rem; color: #666; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.1);">
                             This batch adds: <strong>+${batchMw.toFixed(4)} MW</strong>
                         </div>
                     `;
                 }
             } else if (previewDiv) {
                 previewDiv.style.display = 'none';
             }
         }

         // Add listeners to wattage container for dynamic updates
         document.addEventListener('DOMContentLoaded', function() {
             const wattageContainer = document.getElementById('wattage-container');
             if (wattageContainer) {
                 wattageContainer.addEventListener('input', updateMwPreview);
                 // Also observe for new fields being added
                 const observer = new MutationObserver(updateMwPreview);
                 observer.observe(wattageContainer, { childList: true, subtree: true });
             }
         });

         // ========== Palletization Section ==========
         document.addEventListener('DOMContentLoaded', function() {
             const enableCheckbox = document.getElementById('enable_palletization');
             const configDiv = document.getElementById('palletizationConfig');
             const modulesPerPalletInput = document.getElementById('auto_modules_per_pallet');
             const calcDiv = document.getElementById('palletCalculation');
             const enableLabel = document.getElementById('enablePalletizationLabel');

             if (!enableCheckbox || !configDiv) return;

             // Toggle config visibility when checkbox changes
             enableCheckbox.addEventListener('change', function() {
                 configDiv.style.display = this.checked ? 'block' : 'none';
                 if (this.checked) {
                     enableLabel.style.borderColor = '#28a745';
                     enableLabel.style.background = '#d4edda';
                     updatePalletCalculation();
                 } else {
                     enableLabel.style.borderColor = '#e9ecef';
                     enableLabel.style.background = '#f8f9fa';
                 }
             });

             // Calculate pallets when modules per pallet changes
             if (modulesPerPalletInput) {
                 modulesPerPalletInput.addEventListener('input', updatePalletCalculation);
             }

             // Also recalculate when wattage/quantity inputs change
             const form = document.getElementById('addBatchForm');
             if (form) {
                 form.addEventListener('input', function(e) {
                     if (e.target.name === 'wattages[]' || e.target.name === 'quantities[]') {
                         updatePalletCalculation();
                     }
                 });
             }

             function updatePalletCalculation() {
                 if (!enableCheckbox.checked) return;

                 const modulesPerPallet = parseInt(modulesPerPalletInput.value) || 0;
                 const quantityInputs = document.querySelectorAll('input[name="quantities[]"]');

                 let totalModules = 0;
                 quantityInputs.forEach(input => {
                     totalModules += parseInt(input.value) || 0;
                 });

                 const detailsDiv = document.getElementById('palletCalcDetails');

                 if (modulesPerPallet > 0 && totalModules > 0) {
                     const fullPallets = Math.floor(totalModules / modulesPerPallet);
                     const partialModules = totalModules % modulesPerPallet;
                     const totalPallets = fullPallets + (partialModules > 0 ? 1 : 0);

                     // Update inline display
                     document.getElementById('calcTotalPallets').textContent = totalPallets + ' pallet' + (totalPallets !== 1 ? 's' : '');
                     const partialSpan = document.getElementById('calcPartialPallet');
                     if (partialModules > 0) {
                         partialSpan.textContent = '(+' + partialModules + ' partial)';
                     } else {
                         partialSpan.textContent = '';
                     }

                     // Update detailed display
                     document.getElementById('calcTotalModules').textContent = totalModules.toLocaleString();
                     document.getElementById('calcFullPallets').textContent = fullPallets;
                     document.getElementById('calcPartialModules').textContent = partialModules > 0 ? partialModules : '0';

                     calcDiv.style.display = 'block';
                     if (detailsDiv) detailsDiv.style.display = 'block';
                 } else {
                     calcDiv.style.display = 'none';
                     if (detailsDiv) detailsDiv.style.display = 'none';
                 }
             }

             // Watch for changes to wattage container (new fields added)
             const wattageContainer = document.getElementById('wattage-container');
             if (wattageContainer) {
                 const observer = new MutationObserver(updatePalletCalculation);
                 observer.observe(wattageContainer, { childList: true, subtree: true });
             }
         });

         // ========== Loading Modal Functions ==========
         function showLoadingModal(withPalletization = false) {
             const modal = document.getElementById('loadingModal');
             const title = document.getElementById('loadingTitle');
             const subtitle = document.getElementById('loadingSubtitle');

             if (modal) {
                 if (withPalletization) {
                     title.textContent = 'Creating Module Batch & Pallets...';
                     subtitle.textContent = 'Please wait while we create your modules and pallets.';
                 } else {
                     title.textContent = 'Creating Module Batch...';
                     subtitle.textContent = 'Please wait while we process your request.';
                 }
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
             const warning = document.getElementById('resultWarning');
             const warningText = document.getElementById('resultWarningText');
             const modulesEl = document.getElementById('resultModules');
             const palletsEl = document.getElementById('resultPallets');
             const addAnotherBtn = document.getElementById('resultAddAnotherBtn');
             const goBackBtn = document.getElementById('resultGoBackBtn');
             const closeBtn = document.getElementById('resultCloseBtn');

             if (success) {
                 icon.innerHTML = '&#10004;';
                 icon.style.color = '#28a745';
                 title.textContent = 'Module Batch Created!';
                 title.style.color = '#28a745';
                 message.textContent = data.message || 'Your module batch has been created successfully.';

                 // Show details
                 details.style.display = 'block';
                 modulesEl.textContent = (data.total_modules || 0).toLocaleString();
                 palletsEl.textContent = data.pallets_created || 0;

                 // Show warning if palletization was expected but none created
                 const enablePalletization = document.getElementById('enable_palletization');
                 const isPalletizing = enablePalletization && enablePalletization.checked;
                 if (isPalletizing && data.pallets_created === 0) {
                     warning.style.display = 'block';
                     warningText.textContent = 'Auto-palletization was enabled but no pallets were created. Please check your modules per pallet setting.';
                 } else {
                     warning.style.display = 'none';
                 }

                 // Show buttons
                 addAnotherBtn.style.display = 'inline-flex';
                 closeBtn.style.display = 'none';

                 // Update go back button
                 if (data.project_id) {
                     goBackBtn.href = 'project_overview.php?project_id=' + data.project_id;
                     goBackBtn.innerHTML = '<i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Project Overview';
                     goBackBtn.style.display = 'inline-flex';
                 } else {
                     goBackBtn.href = 'modules.php';
                     goBackBtn.innerHTML = '<i class="fas fa-arrow-left" style="margin-right: 8px;"></i> Go to Modules';
                     goBackBtn.style.display = 'inline-flex';
                 }
             } else {
                 icon.innerHTML = '&#10006;';
                 icon.style.color = '#dc3545';
                 title.textContent = 'Error Creating Batch';
                 title.style.color = '#dc3545';
                 message.textContent = data.message || 'An error occurred while creating the module batch.';

                 // Hide details, show close button
                 details.style.display = 'none';
                 warning.style.display = 'none';
                 addAnotherBtn.style.display = 'none';
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

         function resetFormForAnother() {
             closeResultModal();

             // Reset the form
             const form = document.getElementById('addBatchForm');
             if (form) {
                 // Clear wattage/quantity inputs but keep one entry
                 const wattageContainer = document.getElementById('wattage-container');
                 if (wattageContainer) {
                     const entries = wattageContainer.querySelectorAll('.wattage-entry');
                     entries.forEach((entry, index) => {
                         if (index === 0) {
                             // Clear first entry values
                             entry.querySelectorAll('input').forEach(input => input.value = '');
                         } else {
                             // Remove extra entries
                             entry.remove();
                         }
                     });
                 }

                 // Reset palletization checkbox
                 const enablePalletization = document.getElementById('enable_palletization');
                 if (enablePalletization) {
                     enablePalletization.checked = false;
                     const configDiv = document.getElementById('palletizationConfig');
                     if (configDiv) configDiv.style.display = 'none';
                     const enableLabel = document.getElementById('enablePalletizationLabel');
                     if (enableLabel) {
                         enableLabel.style.borderColor = '#e9ecef';
                         enableLabel.style.background = '#f8f9fa';
                     }
                 }

                 // Clear modules per pallet
                 const modulesPerPallet = document.getElementById('auto_modules_per_pallet');
                 if (modulesPerPallet) modulesPerPallet.value = '';

                 // Hide pallet calculation
                 const calcDiv = document.getElementById('palletCalculation');
                 const detailsDiv = document.getElementById('palletCalcDetails');
                 if (calcDiv) calcDiv.style.display = 'none';
                 if (detailsDiv) detailsDiv.style.display = 'none';
             }

             // Scroll to top
             window.scrollTo({ top: 0, behavior: 'smooth' });
         }

         // ========== AJAX Form Submission Handler ==========
         document.addEventListener('DOMContentLoaded', function() {
             const form = document.getElementById('addBatchForm');

             // Hide modals on page load (in case of back button)
             hideLoadingModal();
             closeResultModal();

             if (form) {
                 form.addEventListener('submit', function(e) {
                     e.preventDefault();

                     // Check if auto-palletization is enabled
                     const enablePalletization = document.getElementById('enable_palletization');
                     const isPalletizing = enablePalletization && enablePalletization.checked;
                     const modulesPerPalletInput = document.getElementById('auto_modules_per_pallet');
                     const hasModulesPerPallet = modulesPerPalletInput && parseInt(modulesPerPalletInput.value) > 0;

                     // Check capacity before submitting
                     if (capacityData.projectSizeMw > 0) {
                         const batchMw = calculateBatchMw();
                         const newTotalMw = capacityData.currentOrderedMw + batchMw;

                         if (newTotalMw > capacityData.projectSizeMw) {
                             const excessMw = newTotalMw - capacityData.projectSizeMw;
                             const excessPct = ((newTotalMw / capacityData.projectSizeMw) - 1) * 100;

                             const confirmMsg = `WARNING: This batch will exceed the project capacity!\n\n` +
                                 `Current: ${capacityData.currentOrderedMw.toFixed(2)} MW\n` +
                                 `This Batch: +${batchMw.toFixed(2)} MW\n` +
                                 `New Total: ${newTotalMw.toFixed(2)} MW\n` +
                                 `Target: ${capacityData.projectSizeMw.toFixed(2)} MW\n\n` +
                                 `This is ${excessMw.toFixed(2)} MW (${excessPct.toFixed(1)}%) over the target.\n\n` +
                                 `Are you sure you want to proceed?`;

                             if (!confirm(confirmMsg)) {
                                 return;
                             }
                         }
                     }

                     // Show loading modal
                     showLoadingModal(isPalletizing && hasModulesPerPallet);

                     // Create FormData from the form
                     const formData = new FormData(form);

                     // Submit via AJAX
                     fetch(form.action || window.location.href, {
                         method: 'POST',
                         body: formData,
                         headers: {
                             'X-Requested-With': 'XMLHttpRequest'
                         }
                     })
                     .then(response => response.json())
                     .then(data => {
                         showResultModal(data.success, data);
                     })
                     .catch(error => {
                         console.error('Error:', error);
                         showResultModal(false, { message: 'An unexpected error occurred. Please try again.' });
                     });
                 });
             }
         });
    </script>
</body>
</html> 
