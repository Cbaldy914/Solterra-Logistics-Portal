<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    die("Unauthorized access.");
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Legacy redirect: forward to new batch editor
$legacy_batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;
if ($legacy_batch_id > 0) {
    $pid = null;
    if ($stmt = $conn->prepare("SELECT project_id FROM modules WHERE id = ? LIMIT 1")) {
        $stmt->bind_param("i", $legacy_batch_id);
        $stmt->execute();
        $stmt->bind_result($pid);
        $stmt->fetch();
        $stmt->close();
    }
    $q = 'edit_module_batch.php?batch_id=' . $legacy_batch_id;
    if (!empty($pid)) { $q .= '&project_id=' . intval($pid); }
    header('Location: ' . $q);
    exit();
}

// Function to sync project_wattage_orders table from actual module batches
function syncProjectWattageOrders($conn, $project_id) {
    if (!$project_id) return;
    
    try {
        // Start transaction for consistency
        $conn->begin_transaction();
        
        // First, delete existing entries for this project
        $stmtDelete = $conn->prepare("DELETE FROM project_wattage_orders WHERE project_id = ?");
        $stmtDelete->bind_param("i", $project_id);
        $stmtDelete->execute();
        $stmtDelete->close();
        
        // Get actual totals from assigned module batches
        $stmtActual = $conn->prepare("
            SELECT 
                umi.wattage,
                SUM(umi.quantity) as total_quantity
            FROM modules m
            JOIN unassigned_module_items umi ON m.id = umi.unassigned_module_id
            WHERE m.project_id = ?
            GROUP BY umi.wattage
        ");
        $stmtActual->bind_param("i", $project_id);
        $stmtActual->execute();
        $resultActual = $stmtActual->get_result();
        
        // Insert new entries based on actual module batches
        $stmtInsert = $conn->prepare("INSERT INTO project_wattage_orders (project_id, wattage, total_order) VALUES (?, ?, ?)");
        while ($row = $resultActual->fetch_assoc()) {
            $wattage = $row['wattage'];
            $total_quantity = $row['total_quantity'];
            $stmtInsert->bind_param("iii", $project_id, $wattage, $total_quantity);
            $stmtInsert->execute();
        }
        $stmtInsert->close();
        $stmtActual->close();
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error syncing project_wattage_orders: " . $e->getMessage());
    }
}

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;

// Variables for form data and messages
$batch_data = null;
$batch_items = [];
$accounts = [];
$projects_for_account = [];
$account_id_for_admin = null;
$successMessage = "";
$errorMessage   = "";

// --- Fetch Account & Project Info (needed for both GET and POST) ---
if ($role === 'global_admin') {
    // Fetch all accounts
    $sqlAccounts = "SELECT id, name FROM customer_accounts ORDER BY name ASC";
    $resAccounts = $conn->query($sqlAccounts);
    if ($resAccounts) {
        while ($row = $resAccounts->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
    // Fetch all projects
    $sqlAllProj = "SELECT id, project_name, account_id FROM projects ORDER BY account_id, project_name ASC";
    $resAllProj = $conn->query($sqlAllProj);
    if ($resAllProj && $resAllProj->num_rows > 0) {
        while ($proj = $resAllProj->fetch_assoc()) {
            $projects_for_account[] = $proj;
        }
    }
} else { // Role is 'admin'
    // Fetch admin's account ID
    $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $user_id);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($account_id_for_admin);
        $stmtAdminAcc->fetch();
        $stmtAdminAcc->close();
    }
    if (!$account_id_for_admin) {
        die("Admin user not associated with any account.");
    }
    // Fetch projects for the admin's account
    $sqlOneProj = "SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC";
    $stmtOneProj = $conn->prepare($sqlOneProj);
    if (!$stmtOneProj) die("Error preparing project lookup: " . $conn->error);
    $stmtOneProj->bind_param("i", $account_id_for_admin);
    $stmtOneProj->execute();
    $resultProj = $stmtOneProj->get_result();
    while ($proj = $resultProj->fetch_assoc()) {
         $projects_for_account[] = $proj;
    }
    $stmtOneProj->close();
}

// --- Handle POST Request (Save Changes) --- 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batch_id_post = isset($_POST['batch_id']) ? intval($_POST['batch_id']) : 0;
    if ($batch_id_post <= 0) {
        $errorMessage = "Invalid Batch ID provided for update.";
    } else {
        $conn->begin_transaction();
        try {
            // Validate account access for admin
            if ($role === 'admin') {
                $checkStmt = $conn->prepare("SELECT account_id FROM modules WHERE id = ?");
                $checkStmt->bind_param("i", $batch_id_post);
                $checkStmt->execute();
                $checkStmt->bind_result($current_account_id);
                if (!$checkStmt->fetch() || $current_account_id != $account_id_for_admin) {
                    $checkStmt->close();
                    throw new Exception("Access denied: You do not have permission to edit this batch.");
                }
                $checkStmt->close();
            }
            
            // Get account_id for update
            $account_id_update = ($role === 'global_admin') 
                ? (isset($_POST['account_id']) ? intval($_POST['account_id']) : 0)
                : $account_id_for_admin;
            if ($account_id_update <= 0) {
                throw new Exception("Invalid Account selected.");
            }

            // Get the old project ID before updating
            $old_project_id = null;
            $stmtOldProject = $conn->prepare("SELECT project_id FROM modules WHERE id = ?");
            if ($stmtOldProject) {
                $stmtOldProject->bind_param("i", $batch_id_post);
                $stmtOldProject->execute();
                $stmtOldProject->bind_result($old_project_id);
                $stmtOldProject->fetch();
                $stmtOldProject->close();
            }

            // Get other main fields
            $vendor_name      = trim($_POST['vendor_name'] ?? '');
            $initial_location = trim($_POST['initial_location'] ?? '');
            $project_id_input = $_POST['project_id'] ?? '';
            $project_id       = ($project_id_input !== '' && $project_id_input > 0) ? intval($project_id_input) : null;

            if (empty($vendor_name) || empty($initial_location)) {
                throw new Exception("Vendor Name and Initial Location cannot be empty.");
            }
            
            if ($role === 'global_admin' && $project_id !== null) {
                $validProject = false;
                $stmtCheckProj = $conn->prepare("SELECT 1 FROM projects WHERE id = ? AND account_id = ?");
                if ($stmtCheckProj) {
                    $stmtCheckProj->bind_param("ii", $project_id, $account_id_update);
                    $stmtCheckProj->execute();
                    $stmtCheckProj->store_result();
                    if ($stmtCheckProj->num_rows > 0) {
                        $validProject = true;
                    }
                    $stmtCheckProj->close();
                }
                if (!$validProject) {
                     throw new Exception("Selected project does not belong to the selected account.");
                }
            }

            // Update main batch details
            $updateMainStmt = $conn->prepare("UPDATE modules SET account_id=?, vendor_name=?, initial_location=?, project_id=? WHERE id=?");
            if (!$updateMainStmt) throw new Exception("Prepare main update failed: " . $conn->error);
            $updateMainStmt->bind_param("issii", $account_id_update, $vendor_name, $initial_location, $project_id, $batch_id_post);
            if (!$updateMainStmt->execute()) throw new Exception("Execute main update failed: " . $updateMainStmt->error);
            $updateMainStmt->close();

            // --- Handle Module Items (Update/Insert/Delete logic) ---

            // 1. Get existing item IDs for this batch from DB
            $existing_item_ids = [];
            $stmt_get_ids = $conn->prepare("SELECT id FROM unassigned_module_items WHERE unassigned_module_id = ?");
            if ($stmt_get_ids) {
                $stmt_get_ids->bind_param("i", $batch_id_post);
                $stmt_get_ids->execute();
                $result_ids = $stmt_get_ids->get_result();
                while ($row = $result_ids->fetch_assoc()) {
                    $existing_item_ids[] = $row['id'];
                }
                $stmt_get_ids->close();
            } else {
                throw new Exception("Failed to fetch existing item IDs: " . $conn->error);
            }

            // 2. Prepare Update and Insert statements
            $stmt_update_item = $conn->prepare("UPDATE unassigned_module_items SET wattage = ?, quantity = ? WHERE id = ?");
            $stmt_insert_item = $conn->prepare("INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)");
            if (!$stmt_update_item || !$stmt_insert_item) {
                throw new Exception("Failed to prepare item update/insert statements: " . $conn->error);
            }

            // 3. Process submitted items with validation for palletized quantities
            $submitted_item_ids = [];
            $items_processed_count = 0;
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                foreach ($_POST['items'] as $index => $item_data) {
                    $item_id = isset($item_data['id']) ? intval($item_data['id']) : 0;
                    $wattage = isset($item_data['wattage']) ? intval($item_data['wattage']) : 0;
                    $quantity = isset($item_data['quantity']) ? intval($item_data['quantity']) : 0;

                    if ($wattage <= 0 || $quantity <= 0) {
                        // Skip invalid entries, maybe log a warning later
                        continue; 
                    }

                    if ($item_id > 0 && in_array($item_id, $existing_item_ids)) {
                        // Existing item: Update it (allow reduction below palletized amounts for bulk workflow)
                        $stmt_update_item->bind_param("iii", $wattage, $quantity, $item_id);
                        if (!$stmt_update_item->execute()) throw new Exception("Failed to update item ID {$item_id}: " . $stmt_update_item->error);
                        $submitted_item_ids[] = $item_id; // Keep track of IDs that were submitted
                        $items_processed_count++;
                    } else {
                        // New item (or invalid ID submitted): Insert it
                        $stmt_insert_item->bind_param("iii", $batch_id_post, $wattage, $quantity);
                        if (!$stmt_insert_item->execute()) throw new Exception("Failed to insert new item ({$wattage}W, {$quantity}qty): " . $stmt_insert_item->error);
                        // We don't add the new insert_id to submitted_item_ids as it wasn't pre-existing
                        $items_processed_count++;
                    }
                }
            }
            $stmt_update_item->close();
            $stmt_insert_item->close();

            if ($items_processed_count === 0) {
                throw new Exception("No valid Wattage/Quantity items were provided.");
            }

            // 4. Handle Deletions (Safely - only if no pallets are linked)
            $items_to_delete = array_diff($existing_item_ids, $submitted_item_ids);
            $skipped_deletions = [];
            if (!empty($items_to_delete)) {
                $placeholders_del = implode(',', array_fill(0, count($items_to_delete), '?'));
                $types_del = str_repeat('i', count($items_to_delete));

                // Check which items have linked pallets
                $stmt_check_pallets = $conn->prepare("SELECT DISTINCT unassigned_module_item_id FROM inventory_pallets WHERE unassigned_module_item_id IN ({$placeholders_del})");
                $linked_item_ids = [];
                if ($stmt_check_pallets) {
                    $stmt_check_pallets->bind_param($types_del, ...$items_to_delete);
                    $stmt_check_pallets->execute();
                    $result_linked = $stmt_check_pallets->get_result();
                    while($row = $result_linked->fetch_assoc()) {
                        $linked_item_ids[] = $row['unassigned_module_item_id'];
                    }
                    $stmt_check_pallets->close();
                } else {
                     throw new Exception("Failed to check for linked pallets: " . $conn->error);
                }

                // Prepare delete statement
                $stmt_delete_item = $conn->prepare("DELETE FROM unassigned_module_items WHERE id = ?");
                if (!$stmt_delete_item) throw new Exception("Failed to prepare item delete statement: " . $conn->error);

                foreach ($items_to_delete as $delete_id) {
                    if (in_array($delete_id, $linked_item_ids)) {
                        // Cannot delete - Pallets are linked
                        $skipped_deletions[] = $delete_id;
                    } else {
                        // Safe to delete
                        $stmt_delete_item->bind_param("i", $delete_id);
                        if (!$stmt_delete_item->execute()) {
                             error_log("Failed to delete unlinked item ID {$delete_id}: " . $stmt_delete_item->error);
                             // Decide whether to throw exception or just log error and continue
                             // For now, let's log and continue, but add to error message later
                             $skipped_deletions[] = $delete_id; // Add to skipped list even on error
                        }
                    }
                }
                $stmt_delete_item->close();
            }

            $conn->commit();
            $successMessage = "Batch updated successfully!";
            if (!empty($skipped_deletions)) {
                $successMessage .= " Note: Items with IDs (" . implode(', ', $skipped_deletions) . ") were not deleted because they still have pallets linked to them.";
            }
            
            // Sync project_wattage_orders for both old and new projects
            if ($old_project_id && $old_project_id != $project_id) {
                // Sync the old project (batch was removed from it)
                syncProjectWattageOrders($conn, $old_project_id);
            }
            if ($project_id) {
                // Sync the new/current project (batch was added/updated)
                syncProjectWattageOrders($conn, $project_id);
            }
            $_SESSION['edit_module_message'] = $successMessage; // Use a specific session key
            header("Location: module_overview.php?batch_id=" . $batch_id_post . "&update_status=success");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = "Error updating batch: " . $e->getMessage();
        }
        // Re-fetch data after potential update for display
        $batch_id = $batch_id_post; // Use the ID from the POST
    }
}

// --- Fetch Batch Data for Display (GET request or after POST) ---
if ($batch_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM modules WHERE id = ?");
    if (!$stmt) {
        $errorMessage = "Error preparing batch fetch: " . $conn->error;
    } else {
        $stmt->bind_param("i", $batch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $batch_data = $result->fetch_assoc();
            // Security check for admin role
            if ($role === 'admin' && $batch_data['account_id'] != $account_id_for_admin) {
                $batch_data = null; // Clear data if admin doesn't own this batch
                die("Access Denied: You do not have permission to view this batch.");
            }
        } else {
            $errorMessage = "Module batch not found.";
            $batch_data = null; // Ensure batch data is null if not found
        }
        $stmt->close();
    }

    // Fetch items if batch data was found
    if ($batch_data) {
        $stmtItems = $conn->prepare("SELECT id, wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ?");
        if (!$stmtItems) {
            $errorMessage = "Error preparing items fetch: " . $conn->error;
        } else {
            $stmtItems->bind_param("i", $batch_id);
            $stmtItems->execute();
            $resultItems = $stmtItems->get_result();
            $item_wattage_map = [];
            $original_item_quantities = []; // Store original quantities here
            while ($item = $resultItems->fetch_assoc()) {
                $item['discrepancy_details'] = [];
                $batch_items[] = $item;
                $item_wattage_map[$item['id']] = $item['wattage'];
                $original_item_quantities[$item['id']] = (int)$item['quantity']; // Store original
            }
            $stmtItems->close();
        }
    }

    // Fetch associated pallets and collate wattage discrepancies
    $item_discrepancy_collector = []; 
    if (!empty($batch_items)) {
        $item_ids = array_column($batch_items, 'id');
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $types = str_repeat('i', count($item_ids));
        $sqlPallets = "SELECT id, pallet_identifier, unassigned_module_item_id, wattage, quantity 
                       FROM inventory_pallets 
                       WHERE unassigned_module_item_id IN ({$placeholders})";
        $stmtPallets = $conn->prepare($sqlPallets);
        if ($stmtPallets) {
            $stmtPallets->bind_param($types, ...$item_ids);
            $stmtPallets->execute();
            $resultPallets = $stmtPallets->get_result();
            while ($pallet = $resultPallets->fetch_assoc()) {
                $item_id = $pallet['unassigned_module_item_id'];
                $pallet_wattage = $pallet['wattage'];
                $pallet_quantity = (int)$pallet['quantity'];

                if (isset($item_wattage_map[$item_id]) && $item_wattage_map[$item_id] != $pallet_wattage) {
                    if (!isset($item_discrepancy_collector[$item_id])) {
                        $item_discrepancy_collector[$item_id] = [];
                    }
                    $item_discrepancy_collector[$item_id][$pallet_wattage] = 
                        ($item_discrepancy_collector[$item_id][$pallet_wattage] ?? 0) + $pallet_quantity;
                }
            }
            $stmtPallets->close();
        }
    }

    // Add discrepancy details back to the main batch_items array
    foreach ($batch_items as &$item) { 
        $item_id = $item['id'];
        if (isset($item_discrepancy_collector[$item_id])) {
            $item['discrepancy_details'] = $item_discrepancy_collector[$item_id];
            $outgoing_discrepant_qty = array_sum($item['discrepancy_details']);
            // Use the original quantity for calculation
            $item['adjusted_quantity'] = max(0, $original_item_quantities[$item_id] - $outgoing_discrepant_qty);
        }
    }
    unset($item); 
} elseif ($batch_id <= 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errorMessage = "No Batch ID provided.";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Module Batch</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        main {
            max-width: 800px;
        }
        form label {
            display: block;
            margin-top: 15px;
            font-weight: 600;
        }
        form input[type="text"],
        form input[type="number"],
        form input[type="date"],
        form input[type="file"],
        form select {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
            box-sizing: border-box;
        }
        .wattage-entry {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            align-items: flex-end; 
        }
        .wattage-entry > div {
            flex: 1; 
            min-width: 150px; 
        }
         .wattage-entry > div label {
             margin-top: 0;
         }
        .wattage-entry .remove-btn-container {
             flex: 0 0 auto; 
             margin-left: 10px; 
        }
        .wattage-entry button {
            background: #dc3545; 
            color: #fff;
            border: none;
            padding: 8px 14px;
            cursor: pointer;
            border-radius: 4px;
            height: 36px; 
        }
         .wattage-entry button:hover {
             background: #c82333;
         }
         .btn-add-wattage {
             background: #488C9A;
             color: #fff;
             border: none;
             padding: 8px 14px;
             cursor: pointer;
             border-radius: 4px;
             margin-top: 10px;
        }
        .btn-add-wattage:hover {
            background: #293E4C;
        }
        .btn-submit {
            background: #293E4C;
            color: #fff;
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 1rem;
            margin-top: 20px;
            display: block;
        }
        .btn-submit:hover {
            background: #488C9A;
        }
        .section-title {
            margin-top: 30px;
            margin-bottom: 10px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .success-message {
            color: green;
            margin: 20px 0;
            padding: 10px;
            border: 1px solid green;
            background-color: #e6ffed;
            text-align: center;
            border-radius: 4px;
        }
        .error-message {
            color: red;
            margin: 20px 0;
            padding: 10px;
            border: 1px solid red;
            background-color: #ffe6e6;
            text-align: center;
            border-radius: 4px;
        }
        .discrepancy-warning {
            /* Add desired styles, e.g.: */
            /* background-color: #fff3cd; */
            /* border: 1px solid #ffeeba; */
            /* padding: 10px; */
            /* border-radius: 4px; */
        }
    </style>
    <script>
        function addWattageField() {
            var container = document.getElementById('wattage-container');
            var index = container.children.length;
            var div = document.createElement('div');
            div.className = 'wattage-entry';
            div.innerHTML = `
                <div>
                    <label for="items_${index}_wattage">Wattage:</label>
                    <input type="number" step="1" name="items[${index}][wattage]" id="items_${index}_wattage" required>
                </div>
                <div>
                    <label for="items_${index}_quantity">Quantity:</label>
                    <input type="number" step="1" name="items[${index}][quantity]" id="items_${index}_quantity" required>
                </div>
                <div class="remove-btn-container">
                    <button type="button" class="remove-wattage-btn" onclick="this.closest('.wattage-entry').remove()">Remove</button>
                </div>
            `;
            container.appendChild(div);
        }

        document.addEventListener('DOMContentLoaded', function() {
            var accountSelect = document.getElementById('account_id');
            var projectSelect = document.getElementById('project_id');
            if (accountSelect && projectSelect && '<?php echo $role; ?>' === 'global_admin') {
                 var allProjectOptions = Array.from(projectSelect.options).slice(1);

                 accountSelect.addEventListener('change', function() {
                     var selectedAccountId = this.value;
                     var previouslySelectedProjectId = projectSelect.value;
                     while (projectSelect.options.length > 1) {
                         projectSelect.remove(1);
                     }

                     var currentProjectStillValid = false;
                     allProjectOptions.forEach(function(option) {
                         var optionAccountId = option.getAttribute('data-account-id');
                         if (selectedAccountId === '' || optionAccountId === selectedAccountId) {
                             var clonedOption = option.cloneNode(true);
                             if(clonedOption.value === previouslySelectedProjectId && optionAccountId === selectedAccountId) {
                                 clonedOption.selected = true;
                                 currentProjectStillValid = true;
                             }
                             projectSelect.add(clonedOption);
                         }
                     });
                     if (!currentProjectStillValid) {
                          projectSelect.value = '';
                     }
                 });
                 if (accountSelect.value !== '') {
                      accountSelect.dispatchEvent(new Event('change'));
                 }
            }
        });
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Edit Module Batch #<?php echo $batch_id; ?></h1>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($batch_data): ?>
        <form action="edit_module.php?batch_id=<?php echo $batch_id; ?>" method="POST">
            <input type="hidden" name="batch_id" value="<?php echo $batch_id; ?>">

            <?php if ($role === 'global_admin'): ?>
                <label for="account_id">Account Name:</label>
                <select name="account_id" id="account_id" required>
                    <option value="">--Select Account--</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($batch_data['account_id'] == $acc['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($acc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: // Admin Role - display name, hidden input ?>
                 <?php
                     $adminAccountName = '[Account Name Not Found]';
                     $connTemp = getDBConnection(); // Temporary connection
                     if ($connTemp) {
                         $stmtAccName = $connTemp->prepare("SELECT name FROM customer_accounts WHERE id = ?");
                    if ($stmtAccName) {
                             $stmtAccName->bind_param("i", $account_id_for_admin);
                        $stmtAccName->execute();
                             $stmtAccName->bind_result($fetchedName);
                             if ($stmtAccName->fetch()) { $adminAccountName = $fetchedName; }
                        $stmtAccName->close();
                    }
                         $connTemp->close();
                     }
                     echo "<p><strong>Account:</strong> " . htmlspecialchars($adminAccountName) . "</p>";
                 ?>
            <?php endif; ?>

            <label for="vendor_name">Vendor Name:</label>
            <input type="text" name="vendor_name" id="vendor_name" value="<?php echo htmlspecialchars($batch_data['vendor_name']); ?>" required>

            <label for="initial_location">Initial Location:</label>
            <input type="text" name="initial_location" id="initial_location" value="<?php echo htmlspecialchars($batch_data['initial_location']); ?>" required>

            <label for="project_id">Assign to Project:</label>
            <select name="project_id" id="project_id">
                 <option value="">-- None (Unassigned Stock) --</option>
                 <?php foreach ($projects_for_account as $proj): ?>
                      <option value="<?php echo $proj['id']; ?>" 
                              <?php if ($role === 'global_admin') echo ' data-account-id="' . $proj['account_id'] . '"'; ?>
                              <?php echo ($batch_data['project_id'] == $proj['id']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($proj['project_name']); ?>
                          <?php if ($role === 'global_admin') echo ' (Account ID: ' . $proj['account_id'] . ')'; ?>
                      </option>
                 <?php endforeach; ?>
            </select>

            <div class="section-title">Module Wattage and Quantities</div>

            <?php
            // --- GLOBAL DISCREPANCY WARNING ---
            // Build a map of actual pallet quantities by wattage
            $pallet_wattage_totals = [];
            if (!empty($batch_items)) {
                $item_ids = array_column($batch_items, 'id');
                if (!empty($item_ids)) {
                    $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
                    $types = str_repeat('i', count($item_ids));
                    $sqlPallets = "SELECT wattage, SUM(quantity) as total_qty FROM inventory_pallets WHERE unassigned_module_item_id IN ({$placeholders}) GROUP BY wattage";
                    $conn2 = getDBConnection();
                    $stmtPallets = $conn2->prepare($sqlPallets);
                    if ($stmtPallets) {
                        $stmtPallets->bind_param($types, ...$item_ids);
                        $stmtPallets->execute();
                        $resultPallets = $stmtPallets->get_result();
                        while ($row = $resultPallets->fetch_assoc()) {
                            $pallet_wattage_totals[$row['wattage']] = (int)$row['total_qty'];
                        }
                        $stmtPallets->close();
                    }
                    $conn2->close();
                }
            }
            // Build a map of batch item quantities by wattage
            $batch_item_wattage_qty = [];
            foreach ($batch_items as $item) {
                $batch_item_wattage_qty[$item['wattage']] = (int)$item['quantity'];
            }
            // Find all wattages present in either batch or pallets
            $all_wattages = array_unique(array_merge(array_keys($batch_item_wattage_qty), array_keys($pallet_wattage_totals)));
            sort($all_wattages);
            $discrepancy_found = false;
            $discrepancy_lines = [];
            foreach ($all_wattages as $watt) {
                $expected = $batch_item_wattage_qty[$watt] ?? 0;
                $actual = $pallet_wattage_totals[$watt] ?? 0;
                if ($expected !== $actual) {
                    $discrepancy_found = true;
                    $discrepancy_lines[] = "Wattage $watt: $actual modules on pallets (should match batch: $expected)";
                }
            }
            if ($discrepancy_found): ?>
                <div style="background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 12px; margin-bottom: 18px; border-radius: 4px;">
                    <strong>📋 Pallet vs Batch Quantities:</strong> Current status of palletized modules vs batch quantities:<br>
                    <ul style="margin-top: 8px;">
                        <?php foreach ($discrepancy_lines as $line): ?>
                            <li><?php echo htmlspecialchars($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <strong>Note:</strong> After saving changes, check the Module Overview page to see if you have excess pallets that need to be removed or if you need to palletize additional modules.
                </div>
            <?php endif; ?>

            <div id="wattage-container">
                <?php foreach ($batch_items as $index => $item): ?>
                    <div class="wattage-entry">
                        <input type="hidden" name="items[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                        <div>
                            <label for="items_<?php echo $index; ?>_wattage">Wattage:</label>
                            <input type="number" step="1" name="items[<?php echo $index; ?>][wattage]" id="items_<?php echo $index; ?>_wattage" value="<?php echo htmlspecialchars($item['wattage']); ?>" required>
                        </div>
                        <div>
                            <label for="items_<?php echo $index; ?>_quantity">Quantity:</label>
                            <input type="number" step="1" name="items[<?php echo $index; ?>][quantity]" id="items_<?php echo $index; ?>_quantity" value="<?php echo htmlspecialchars($item['quantity']); ?>" required min="1">
                        </div>
                        <div class="remove-btn-container">
                            <button type="button" class="remove-wattage-btn" onclick="removeExistingItem(this, <?php echo $item['id']; ?>)">Remove</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add-wattage" onclick="addWattageField()">+ Add Wattage/Quantity</button>

            <input type="submit" value="Save Changes" class="btn-submit">
        </form>
    <?php elseif (empty($errorMessage)): ?>
        <p>Loading batch data...</p> 
    <?php endif; ?> 

    <div class="back-link" style="margin-top: 20px;">
        <a href="module_overview.php?batch_id=<?php echo $batch_id; ?>">&larr; Back to Module Overview</a>
    </div>
</main>
</body>
</html>
