<?php
session_name("logistics_session");
session_start();

// Only admins/global_admins/customer_admins
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header('Location: unauthorized.php');
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) { die('Database connection failed.'); }

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

$batch_id = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
if ($batch_id <= 0) { header('Location: modules.php?error=missing_batch'); exit(); }

// Load module batch and access control
if ($role === 'admin') {
    $stmt = $conn->prepare("SELECT m.*, p.project_name FROM modules m LEFT JOIN projects p ON m.project_id = p.id JOIN customer_account_users cau ON m.account_id = cau.account_id AND cau.role='admin' WHERE m.id=? AND cau.user_id=?");
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

// Load manufacturers
$manufacturers = [];
if ($stmtMfgs = $conn->prepare('SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC')) {
    $stmtMfgs->execute();
    $r = $stmtMfgs->get_result();
    while ($row = $r->fetch_assoc()) { $manufacturers[] = $row; }
    $stmtMfgs->close();
}

// Load current wattages
$current_wattages = [];
if ($stmtW = $conn->prepare('SELECT id, wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ? ORDER BY wattage ASC')) {
    $stmtW->bind_param('i', $batch_id);
    $stmtW->execute();
    $r = $stmtW->get_result();
    while ($row = $r->fetch_assoc()) { $current_wattages[] = $row; }
    $stmtW->close();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read posted values
    $manufacturer_id = isset($_POST['manufacturer_id']) && $_POST['manufacturer_id'] !== '' ? (int)$_POST['manufacturer_id'] : null;
    $location_id = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;

    $modules_per_pallet = isset($_POST['modules_per_pallet']) && $_POST['modules_per_pallet'] !== '' ? (int)$_POST['modules_per_pallet'] : 0;
    $pallets_per_truck = isset($_POST['pallets_per_truck']) && $_POST['pallets_per_truck'] !== '' ? (int)$_POST['pallets_per_truck'] : 0;
    $modules_per_truck = isset($_POST['modules_per_truck']) && $_POST['modules_per_truck'] !== '' ? (int)$_POST['modules_per_truck'] : 0;
    $pallet_length_mm = isset($_POST['pallet_length_mm']) && $_POST['pallet_length_mm'] !== '' ? (int)$_POST['pallet_length_mm'] : 0;
    $pallet_depth_mm = isset($_POST['pallet_depth_mm']) && $_POST['pallet_depth_mm'] !== '' ? (int)$_POST['pallet_depth_mm'] : 0;
    $pallet_double_stacked_height_mm = isset($_POST['pallet_double_stacked_height_mm']) && $_POST['pallet_double_stacked_height_mm'] !== '' ? (int)$_POST['pallet_double_stacked_height_mm'] : 0;
    $pallet_total_weight_kg = isset($_POST['pallet_total_weight_kg']) && $_POST['pallet_total_weight_kg'] !== '' ? (int)$_POST['pallet_total_weight_kg'] : 0;
    $forklift_truck_long_side_mm = isset($_POST['forklift_truck_long_side_mm']) && $_POST['forklift_truck_long_side_mm'] !== '' ? (int)$_POST['forklift_truck_long_side_mm'] : 0;
    $forklift_truck_short_side_mm = isset($_POST['forklift_truck_short_side_mm']) && $_POST['forklift_truck_short_side_mm'] !== '' ? (int)$_POST['forklift_truck_short_side_mm'] : 0;
    $pallet_jack_long_side_mm = isset($_POST['pallet_jack_long_side_mm']) && $_POST['pallet_jack_long_side_mm'] !== '' ? (int)$_POST['pallet_jack_long_side_mm'] : 0;
    $pallet_jack_short_side_mm = isset($_POST['pallet_jack_short_side_mm']) && $_POST['pallet_jack_short_side_mm'] !== '' ? (int)$_POST['pallet_jack_short_side_mm'] : 0;
    $stacking_in_warehouse = trim($_POST['stacking_in_warehouse'] ?? '');
    $stacking_during_transport = trim($_POST['stacking_during_transport'] ?? '');
    $module_notes = trim($_POST['module_notes'] ?? '');

    $posted_watts = $_POST['wattages'] ?? [];
    $posted_qtys = $_POST['quantities'] ?? [];

    // Build vendor/location
    $vendor_name = $module['vendor_name'] ?: 'Unknown Manufacturer';
    $initial_location = $module['initial_location'] ?: '';
    if ($manufacturer_id) {
        if ($stmtV = $conn->prepare('SELECT name FROM manufacturers WHERE id = ?')) {
            $stmtV->bind_param('i', $manufacturer_id);
            $stmtV->execute();
            $stmtV->bind_result($v);
            if ($stmtV->fetch()) { $vendor_name = $v; }
            $stmtV->close();
        }
        if ($location_id && ($stmtL = $conn->prepare('SELECT street_address, city, state, zip_code FROM manufacturer_locations WHERE id = ?'))) {
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
            $stmtU = $conn->prepare('UPDATE modules SET vendor_name=?, initial_location=?, modules_per_pallet=?, pallets_per_truck=?, modules_per_truck=?, pallet_length_mm=?, pallet_depth_mm=?, pallet_double_stacked_height_mm=?, pallet_total_weight_kg=?, stacking_in_warehouse=?, stacking_during_transport=?, forklift_truck_long_side_mm=?, forklift_truck_short_side_mm=?, pallet_jack_long_side_mm=?, pallet_jack_short_side_mm=?, module_notes=?, last_updated_at=NOW() WHERE id=?');
            $stmtU->bind_param('ssiiiiiiiissiiisi',
                $vendor_name, $initial_location, $modules_per_pallet, $pallets_per_truck, $modules_per_truck,
                $pallet_length_mm, $pallet_depth_mm, $pallet_double_stacked_height_mm, $pallet_total_weight_kg,
                $stacking_in_warehouse, $stacking_during_transport,
                $forklift_truck_long_side_mm, $forklift_truck_short_side_mm, $pallet_jack_long_side_mm, $pallet_jack_short_side_mm,
                $module_notes, $batch_id
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

            // Remove items not in new set (or zero them if pallets exist)
            foreach ($existing_items as $w => $item) {
                if (!isset($posted_watts_set[$w])) {
                    $stmtC = $conn->prepare('SELECT COUNT(*) AS c FROM inventory_pallets WHERE unassigned_module_item_id = ?');
                    $stmtC->bind_param('i', $item['id']);
                    $stmtC->execute();
                    $rc = $stmtC->get_result()->fetch_assoc()['c'] ?? 0;
                    $stmtC->close();
                    if ($rc == 0) {
                        $stmtD = $conn->prepare('DELETE FROM unassigned_module_items WHERE id = ?');
                        $stmtD->bind_param('i', $item['id']);
                        if (!$stmtD->execute()) { throw new Exception('Failed deleting unused item: '.$stmtD->error); }
                        $stmtD->close();
                    } else {
                        $stmtZ = $conn->prepare('UPDATE unassigned_module_items SET quantity = 0 WHERE id = ?');
                        $stmtZ->bind_param('i', $item['id']);
                        if (!$stmtZ->execute()) { throw new Exception('Failed zeroing item: '.$stmtZ->error); }
                        $stmtZ->close();
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
            $redir = ($project_id > 0) ? ('project_overview.php?project_id='.$project_id.'&success=batch_updated') : 'modules.php?success=batch_updated';
            header('Location: '.$redir);
            exit();
        } catch (Exception $ex) {
            $conn->rollback();
            $errors[] = 'Error updating module batch: '.$ex->getMessage();
        }
    }
}

// Preselects for component
$prefManufacturerId = null; $prefLocationId = null; $existingWattages = [];
// Manufacturer by matching name
if (!empty($module['vendor_name'])) {
    if ($stmtPM = $conn->prepare('SELECT id FROM manufacturers WHERE name = ? LIMIT 1')) {
        $stmtPM->bind_param('s', $module['vendor_name']);
        $stmtPM->execute();
        $stmtPM->bind_result($pmid);
        if ($stmtPM->fetch()) { $prefManufacturerId = (int)$pmid; }
        $stmtPM->close();
    }
}
// Location by matching formatted address for that manufacturer
if ($prefManufacturerId) {
    if ($stmtL = $conn->prepare('SELECT id, street_address, city, state, zip_code FROM manufacturer_locations WHERE manufacturer_id = ?')) {
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
foreach ($current_wattages as $w) { if ((int)$w['wattage']>0 && (int)$w['quantity']>0) $existingWattages[] = ['wattage'=>(int)$w['wattage'],'quantity'=>(int)$w['quantity']]; }

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
            
            .header-info h1 {
                font-size: 2em;
            }
            
            .header-subtitle {
                font-size: 1em;
            }
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

    <div class="form-container">
        <div class="form-content">
            <?php if (!empty($errors)): ?>
                <div class="error-message"><ul style="margin:0; padding-left:20px; ">
                    <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>

            <form method="POST" id="editBatchForm">
                <?php include __DIR__ . '/components/module_batch_section.php'; ?>

                <div class="button-group">
                    <a href="<?php echo $project_id ? ('project_overview.php?project_id='.(int)$project_id) : 'modules.php'; ?>" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-submit">Save Module Batch</button>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
