<?php
session_name("logistics_session");
session_start();

// Ensure the user is either an admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header('Location: unauthorized.php');
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;

if (!$project_id || !$batch_id) {
    header('Location: dashboard.php?error=missing_parameters');
    exit();
}

// Verify user has access to this batch
if ($role === 'admin') {
    $stmtAccess = $conn->prepare("
        SELECT m.*, p.project_name, p.account_id
        FROM modules m 
        JOIN projects p ON m.project_id = p.id
        JOIN customer_account_users cau ON p.account_id = cau.account_id 
        WHERE m.id = ? AND m.project_id = ? AND cau.user_id = ? AND cau.role = 'admin'
    ");
    $stmtAccess->bind_param("iii", $batch_id, $project_id, $user_id);
} else {
    // Global admin has access to all batches
    $stmtAccess = $conn->prepare("
        SELECT m.*, p.project_name, p.account_id
        FROM modules m 
        JOIN projects p ON m.project_id = p.id
        WHERE m.id = ? AND m.project_id = ?
    ");
    $stmtAccess->bind_param("ii", $batch_id, $project_id);
}

$stmtAccess->execute();
$result = $stmtAccess->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php?error=access_denied');
    exit();
}

$module_batch = $result->fetch_assoc();
$stmtAccess->close();

// Get current wattage items for this batch
$stmtWattages = $conn->prepare("
    SELECT id, wattage, quantity 
    FROM unassigned_module_items 
    WHERE unassigned_module_id = ? 
    ORDER BY wattage ASC
");
$stmtWattages->bind_param("i", $batch_id);
$stmtWattages->execute();
$wattageResult = $stmtWattages->get_result();

$current_wattages = [];
while ($row = $wattageResult->fetch_assoc()) {
    $current_wattages[] = $row;
}
$stmtWattages->close();

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
    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $initial_location = trim($_POST['initial_location'] ?? '');
    $modules_per_pallet = isset($_POST['modules_per_pallet']) && $_POST['modules_per_pallet'] !== '' ? intval($_POST['modules_per_pallet']) : 0;
    $pallets_per_truck = isset($_POST['pallets_per_truck']) && $_POST['pallets_per_truck'] !== '' ? intval($_POST['pallets_per_truck']) : 0;
    $modules_per_truck = isset($_POST['modules_per_truck']) && $_POST['modules_per_truck'] !== '' ? intval($_POST['modules_per_truck']) : 0;
    $pallet_length_mm = isset($_POST['pallet_length_mm']) && $_POST['pallet_length_mm'] !== '' ? intval($_POST['pallet_length_mm']) : 0;
    $pallet_depth_mm = isset($_POST['pallet_depth_mm']) && $_POST['pallet_depth_mm'] !== '' ? intval($_POST['pallet_depth_mm']) : 0;
    $pallet_double_stacked_height_mm = isset($_POST['pallet_double_stacked_height_mm']) && $_POST['pallet_double_stacked_height_mm'] !== '' ? intval($_POST['pallet_double_stacked_height_mm']) : 0;
    $pallet_total_weight_kg = isset($_POST['pallet_total_weight_kg']) && $_POST['pallet_total_weight_kg'] !== '' ? intval($_POST['pallet_total_weight_kg']) : 0;
    $stacking_in_warehouse = trim($_POST['stacking_in_warehouse'] ?? '');
    $stacking_during_transport = trim($_POST['stacking_during_transport'] ?? '');
    $forklift_truck_long_side_mm = isset($_POST['forklift_truck_long_side_mm']) && $_POST['forklift_truck_long_side_mm'] !== '' ? intval($_POST['forklift_truck_long_side_mm']) : 0;
    $forklift_truck_short_side_mm = isset($_POST['forklift_truck_short_side_mm']) && $_POST['forklift_truck_short_side_mm'] !== '' ? intval($_POST['forklift_truck_short_side_mm']) : 0;
    $pallet_jack_long_side_mm = isset($_POST['pallet_jack_long_side_mm']) && $_POST['pallet_jack_long_side_mm'] !== '' ? intval($_POST['pallet_jack_long_side_mm']) : 0;
    $pallet_jack_short_side_mm = isset($_POST['pallet_jack_short_side_mm']) && $_POST['pallet_jack_short_side_mm'] !== '' ? intval($_POST['pallet_jack_short_side_mm']) : 0;
    $module_notes = trim($_POST['module_notes'] ?? '');
    
    // Validate required fields
    $errors = [];
    if (empty($vendor_name)) {
        $errors[] = 'Vendor name is required';
    }
    if (empty($initial_location)) {
        $errors[] = 'Initial location is required';
    }
    
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
                $wattage = filter_var(trim($wattages[$i]), FILTER_VALIDATE_INT);
                $quantity = filter_var(trim($quantities[$i]), FILTER_VALIDATE_INT);
                
                if ($wattage === false || $quantity === false || $wattage <= 0 || $quantity <= 0) {
                    $errors[] = 'All wattages and quantities must be positive integers';
                    break;
                }
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->begin_transaction();
            
            // Get current wattage totals for project_wattage_orders update
            $stmtCurrent = $conn->prepare("
                SELECT wattage, quantity 
                FROM unassigned_module_items 
                WHERE unassigned_module_id = ?
            ");
            $stmtCurrent->bind_param("i", $batch_id);
            $stmtCurrent->execute();
            $currentResult = $stmtCurrent->get_result();
            
            $current_totals = [];
            while ($row = $currentResult->fetch_assoc()) {
                $w = (int)$row['wattage'];
                $current_totals[$w] = ($current_totals[$w] ?? 0) + (int)$row['quantity'];
            }
            $stmtCurrent->close();
            
            // Update module batch
            $stmtUpdate = $conn->prepare("
                UPDATE modules SET 
                    vendor_name = ?, initial_location = ?, modules_per_pallet = ?, 
                    pallets_per_truck = ?, modules_per_truck = ?, pallet_length_mm = ?, 
                    pallet_depth_mm = ?, pallet_double_stacked_height_mm = ?, pallet_total_weight_kg = ?, 
                    stacking_in_warehouse = ?, stacking_during_transport = ?, forklift_truck_long_side_mm = ?, 
                    forklift_truck_short_side_mm = ?, pallet_jack_long_side_mm = ?, pallet_jack_short_side_mm = ?, 
                    module_notes = ?, last_updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpdate->bind_param("ssiiiiiiiissiiisi", 
                $vendor_name, $initial_location, $modules_per_pallet, $pallets_per_truck, $modules_per_truck,
                $pallet_length_mm, $pallet_depth_mm, $pallet_double_stacked_height_mm, $pallet_total_weight_kg,
                $stacking_in_warehouse, $stacking_during_transport, $forklift_truck_long_side_mm,
                $forklift_truck_short_side_mm, $pallet_jack_long_side_mm, $pallet_jack_short_side_mm,
                $module_notes, $batch_id
            );
            
            if (!$stmtUpdate->execute()) {
                throw new Exception("Error updating module batch: " . $stmtUpdate->error);
            }
            $stmtUpdate->close();
            
            // Update/insert wattage items instead of deleting and re-inserting
            // First, get existing items for comparison
            $existing_items = [];
            foreach ($current_wattages as $item) {
                $existing_items[$item['wattage']] = $item;
            }
            
            // Process new wattages
            $new_totals = [];
            $processed_wattages = [];
            
            for ($i = 0; $i < count($wattages); $i++) {
                $wattage = intval(trim($wattages[$i]));
                $quantity = intval(trim($quantities[$i]));
                $processed_wattages[] = $wattage;
                
                if (isset($existing_items[$wattage])) {
                    // Update existing item
                    $stmtUpdate = $conn->prepare("
                        UPDATE unassigned_module_items 
                        SET quantity = ? 
                        WHERE id = ?
                    ");
                    $stmtUpdate->bind_param("ii", $quantity, $existing_items[$wattage]['id']);
                    if (!$stmtUpdate->execute()) {
                        throw new Exception("Error updating wattage item: " . $stmtUpdate->error);
                    }
                    $stmtUpdate->close();
                } else {
                    // Insert new item
                    $stmtInsert = $conn->prepare("
                        INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) 
                        VALUES (?, ?, ?)
                    ");
                    $stmtInsert->bind_param("iii", $batch_id, $wattage, $quantity);
                    if (!$stmtInsert->execute()) {
                        throw new Exception("Error inserting wattage item: " . $stmtInsert->error);
                    }
                    $stmtInsert->close();
                }
                
                $new_totals[$wattage] = ($new_totals[$wattage] ?? 0) + $quantity;
            }
            
            // Delete items that are no longer needed (only if they don't have inventory pallets)
            foreach ($existing_items as $wattage => $item) {
                if (!in_array($wattage, $processed_wattages)) {
                    // Check if this item has any inventory pallets referencing it
                    $stmtCheckPallets = $conn->prepare("
                        SELECT COUNT(*) as pallet_count 
                        FROM inventory_pallets 
                        WHERE unassigned_module_item_id = ?
                    ");
                    $stmtCheckPallets->bind_param("i", $item['id']);
                    $stmtCheckPallets->execute();
                    $palletResult = $stmtCheckPallets->get_result();
                    $palletCount = $palletResult->fetch_assoc()['pallet_count'];
                    $stmtCheckPallets->close();
                    
                    if ($palletCount == 0) {
                        // Safe to delete - no inventory pallets reference this item
                        $stmtDelete = $conn->prepare("DELETE FROM unassigned_module_items WHERE id = ?");
                        $stmtDelete->bind_param("i", $item['id']);
                        if (!$stmtDelete->execute()) {
                            throw new Exception("Error deleting unused wattage item: " . $stmtDelete->error);
                        }
                        $stmtDelete->close();
                    } else {
                        // Can't delete - has inventory pallets, so set quantity to 0 instead
                        $stmtZero = $conn->prepare("UPDATE unassigned_module_items SET quantity = 0 WHERE id = ?");
                        $stmtZero->bind_param("i", $item['id']);
                        if (!$stmtZero->execute()) {
                            throw new Exception("Error zeroing wattage item quantity: " . $stmtZero->error);
                        }
                        $stmtZero->close();
                    }
                }
            }
            
            // Update project_wattage_orders to reflect changes
            $all_wattages = array_unique(array_merge(array_keys($current_totals), array_keys($new_totals)));
            
            foreach ($all_wattages as $wattage) {
                $old_total = $current_totals[$wattage] ?? 0;
                $new_total = $new_totals[$wattage] ?? 0;
                $difference = $new_total - $old_total;
                
                if ($difference != 0) {
                    $stmtUpdateOrders = $conn->prepare("
                        INSERT INTO project_wattage_orders (project_id, wattage, total_order) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE total_order = total_order + VALUES(total_order)
                    ");
                    $stmtUpdateOrders->bind_param("iii", $project_id, $wattage, $difference);
                    if (!$stmtUpdateOrders->execute()) {
                        throw new Exception("Error updating project wattage orders: " . $stmtUpdateOrders->error);
                    }
                    $stmtUpdateOrders->close();
                }
            }
            
            // Clean up zero or negative entries in project_wattage_orders
            $stmtCleanup = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ? AND total_order <= 0");
            $stmtCleanup->bind_param("i", $project_id);
            $stmtCleanup->execute();
            $stmtCleanup->close();
            
            $conn->commit();
            
            header("Location: project_overview.php?project_id={$project_id}&success=batch_updated");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error updating module batch: " . $e->getMessage();
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
    <title>Edit Module Batch - <?php echo htmlspecialchars($module_batch['project_name']); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
        
        .form-container {
            max-width: 1200px;
            margin: 20px auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .form-header {

            padding: 30px;
            text-align: center;
        }
        
        .form-header h1 {
            margin: 0;
            font-size: 2em;
            font-weight: 600;
        }
        
        .form-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
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
            justify-content: space-between;
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
            transition: background 0.3s ease;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        .btn-submit {
            background: #488C9A;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .btn-submit:hover {
            background: #3A6E7F;
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
                margin: 10px;
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
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <main>
        <div class="breadcrumb" style="margin: 10px 20px;">
            <a href="dashboard.php">Dashboard</a>
            <span class="separator">»</span>
            <a href="project_overview.php?project_id=<?php echo $project_id; ?>"><?php echo htmlspecialchars($module_batch['project_name']); ?></a>
            <span class="separator">»</span>
            <span>Edit Module Batch</span>
        </div>
        
        <div class="form-container">
            <div class="form-header">
                <h1>Edit Module Batch</h1>
                <p><?php echo htmlspecialchars($module_batch['project_name']); ?> - <?php echo htmlspecialchars($module_batch['vendor_name']); ?></p>
            </div>
            
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
                
                <form method="POST" id="editBatchForm">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h2>Basic Information</h2>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="vendor_name">Vendor/Manufacturer Name: *</label>
                                <input type="text" name="vendor_name" id="vendor_name" value="<?php echo htmlspecialchars($module_batch['vendor_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="initial_location">Initial Location: *</label>
                                <input type="text" name="initial_location" id="initial_location" value="<?php echo htmlspecialchars($module_batch['initial_location']); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Wattage & Quantities -->
                    <div class="form-section">
                        <h2>Module Configuration</h2>
                        <div class="wattage-container">
                            <button type="button" class="add-wattage-btn" onclick="addWattageEntry()">+ Add Wattage</button>
                            <div id="wattage-entries">
                                <?php foreach ($current_wattages as $index => $wattage_item): ?>
                                    <div class="wattage-entry">
                                        <div class="form-group">
                                            <label>Wattage (W)</label>
                                            <input type="number" name="wattages[]" value="<?php echo $wattage_item['wattage']; ?>" min="1" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Quantity</label>
                                            <input type="number" name="quantities[]" value="<?php echo $wattage_item['quantity']; ?>" min="1" required>
                                        </div>
                                        <button type="button" class="remove-wattage-btn" onclick="removeWattageEntry(this)">Remove</button>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($current_wattages)): ?>
                                    <div class="wattage-entry">
                                        <div class="form-group">
                                            <label>Wattage (W)</label>
                                            <input type="number" name="wattages[]" min="1" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Quantity</label>
                                            <input type="number" name="quantities[]" min="1" required>
                                        </div>
                                        <button type="button" class="remove-wattage-btn" onclick="removeWattageEntry(this)">Remove</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Logistics Specifications -->
                    <div class="form-section">
                        <h2>Logistics Specifications</h2>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="modules_per_pallet">Modules per Pallet:</label>
                                <input type="number" name="modules_per_pallet" id="modules_per_pallet" value="<?php echo $module_batch['modules_per_pallet'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallets_per_truck">Pallets per Truck:</label>
                                <input type="number" name="pallets_per_truck" id="pallets_per_truck" value="<?php echo $module_batch['pallets_per_truck'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="modules_per_truck">Modules per Truck:</label>
                                <input type="number" name="modules_per_truck" id="modules_per_truck" value="<?php echo $module_batch['modules_per_truck'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_length_mm">Pallet Length (mm):</label>
                                <input type="number" name="pallet_length_mm" id="pallet_length_mm" value="<?php echo $module_batch['pallet_length_mm'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_depth_mm">Pallet Depth (mm):</label>
                                <input type="number" name="pallet_depth_mm" id="pallet_depth_mm" value="<?php echo $module_batch['pallet_depth_mm'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_double_stacked_height_mm">Stack Height (mm):</label>
                                <input type="number" name="pallet_double_stacked_height_mm" id="pallet_double_stacked_height_mm" value="<?php echo $module_batch['pallet_double_stacked_height_mm'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_total_weight_kg">Total Weight (kg):</label>
                                <input type="number" name="pallet_total_weight_kg" id="pallet_total_weight_kg" value="<?php echo $module_batch['pallet_total_weight_kg'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="forklift_truck_long_side_mm">Forklift Long Side (mm):</label>
                                <input type="number" name="forklift_truck_long_side_mm" id="forklift_truck_long_side_mm" value="<?php echo $module_batch['forklift_truck_long_side_mm'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="forklift_truck_short_side_mm">Forklift Short Side (mm):</label>
                                <input type="number" name="forklift_truck_short_side_mm" id="forklift_truck_short_side_mm" value="<?php echo $module_batch['forklift_truck_short_side_mm'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_jack_long_side_mm">Pallet Jack Long Side (mm):</label>
                                <input type="number" name="pallet_jack_long_side_mm" id="pallet_jack_long_side_mm" value="<?php echo $module_batch['pallet_jack_long_side_mm'] ?: ''; ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="pallet_jack_short_side_mm">Pallet Jack Short Side (mm):</label>
                                <input type="number" name="pallet_jack_short_side_mm" id="pallet_jack_short_side_mm" value="<?php echo $module_batch['pallet_jack_short_side_mm'] ?: ''; ?>" min="0">
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="stacking_in_warehouse">Warehouse Stacking Instructions:</label>
                                <textarea name="stacking_in_warehouse" id="stacking_in_warehouse" rows="3"><?php echo htmlspecialchars($module_batch['stacking_in_warehouse']); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="stacking_during_transport">Transport Stacking Instructions:</label>
                                <textarea name="stacking_during_transport" id="stacking_during_transport" rows="3"><?php echo htmlspecialchars($module_batch['stacking_during_transport']); ?></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="module_notes">Module Notes:</label>
                            <textarea name="module_notes" id="module_notes" rows="4"><?php echo htmlspecialchars($module_batch['module_notes']); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="button-group">
                        <a href="project_overview.php?project_id=<?php echo $project_id; ?>" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-submit">Update Module Batch</button>
                    </div>
                </form>
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
                    <input type="number" name="wattages[]" min="1" required>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantities[]" min="1" required>
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
    </script>
</body>
</html> 