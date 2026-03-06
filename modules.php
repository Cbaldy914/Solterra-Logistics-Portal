<?php
session_name("logistics_session");
session_start();

// Allow access for admin, global_admin, customer_admin, and user roles.
// Specific functionalities will be controlled by role checks within the page.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin', 'user'])) {
    header("Location: unauthorized");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Database connection
require_once '../config.php';
require_once 'module_reconciliation_audit_helpers.php';
$conn = getDBConnection(); // Keep connection open initially
if (!$conn) {
    die("Database connection failed.");
}

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$isAdmin = in_array($role, ['admin', 'global_admin', 'customer_admin'], true);

// --- Account and Project Fetching Logic ---
$account_id_for_admin = null;
$account_id_for_user  = null;
$accounts             = []; // For global_admin account dropdown
$projects_for_account = []; // For project assignment dropdown

if (in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
    // Fetch the specific account_id linked to this admin/global_admin user
    $sqlAdminUserAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1";
    $stmtAdminUserAcc = $conn->prepare($sqlAdminUserAcc);
    if (!$stmtAdminUserAcc) die("Error preparing admin account lookup: " . $conn->error);
    $stmtAdminUserAcc->bind_param("i", $user_id);
    $stmtAdminUserAcc->execute();
    $stmtAdminUserAcc->bind_result($adminAcctID);
    if ($stmtAdminUserAcc->fetch()) {
        $account_id_for_admin = $adminAcctID;
    }
    $stmtAdminUserAcc->close();
}

if ($role === 'global_admin') {
    // Global admins can see all accounts and projects in dropdowns
    $sqlAllAcc = "SELECT id, name FROM customer_accounts ORDER BY name ASC";
    $resAllAcc = $conn->query($sqlAllAcc);
    if ($resAllAcc && $resAllAcc->num_rows > 0) {
        while ($row = $resAllAcc->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
    $sqlAllProj = "SELECT id, project_name, account_id, project_size FROM projects ORDER BY account_id, project_name ASC";
    $resAllProj = $conn->query($sqlAllProj);
    if ($resAllProj && $resAllProj->num_rows > 0) {
        while ($proj = $resAllProj->fetch_assoc()) {
            $projects_for_account[] = $proj;
        }
    }
} elseif (in_array($role, ['admin', 'customer_admin'], true) && $account_id_for_admin) {
    // Admins see projects for their assigned account in dropdown
    $sqlAdminProjs = "SELECT id, project_name, account_id, project_size FROM projects WHERE account_id = ? ORDER BY project_name ASC";
    $stmtAdminProjs = $conn->prepare($sqlAdminProjs);
    if (!$stmtAdminProjs) die("Error preparing project lookup for admin: " . $conn->error);
    $stmtAdminProjs->bind_param("i", $account_id_for_admin);
    $stmtAdminProjs->execute();
    $resultAdminProjs = $stmtAdminProjs->get_result();
    while ($projAdmin = $resultAdminProjs->fetch_assoc()) {
         $projects_for_account[] = $projAdmin;
    }
    $stmtAdminProjs->close();
} elseif ($role === 'user') {
    // Fetch account_id for the user
    $sqlUserAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1";
    $stmtUserAcc = $conn->prepare($sqlUserAcc);
    if (!$stmtUserAcc) die("Error preparing user account lookup: " . $conn->error);
    $stmtUserAcc->bind_param("i", $user_id);
    $stmtUserAcc->execute();
    $stmtUserAcc->bind_result($userAcctID);
    if ($stmtUserAcc->fetch()) {
        $account_id_for_user = $userAcctID;
    }
    $stmtUserAcc->close();

    if ($account_id_for_user) {
        // Fetch projects for this user's account (for the dropdown, if ever needed by user)
        $sqlUserProj = "SELECT id, project_name, account_id, project_size FROM projects WHERE account_id = ? ORDER BY project_name ASC";
        $stmtUserProj = $conn->prepare($sqlUserProj);
        if (!$stmtUserProj) die("Error preparing project lookup for user: " . $conn->error);
        $stmtUserProj->bind_param("i", $account_id_for_user);
        $stmtUserProj->execute();
        $resultUserProj = $stmtUserProj->get_result();
        while ($projUser = $resultUserProj->fetch_assoc()) {
             $projects_for_account[] = $projUser; // Populate for consistency if dropdown is used
        }
        $stmtUserProj->close();
    }
}
// Ensure $projects_for_account has account_id if not already present (e.g. for admin role)
// For global_admin, account_id is already fetched. For user role, it's fetched.
// For admin role, we fetched projects WHERE account_id = $account_id_for_admin, so we can add it.
if (in_array($role, ['admin', 'customer_admin'], true) && $account_id_for_admin && !empty($projects_for_account)) {
    foreach ($projects_for_account as $key => $proj) {
        if (!isset($proj['account_id'])) { // Should already be there from the query
            $projects_for_account[$key]['account_id'] = $account_id_for_admin;
        }
    }
}

// Fetch manufacturers for dropdown with primary location address
$manufacturers = [];
$sqlManufacturers = "
    SELECT 
        m.id, 
        m.name, 
        m.short_name,
        CONCAT_WS(', ', 
            NULLIF(ml.street_address, ''),
            NULLIF(ml.city, ''),
            CONCAT(NULLIF(ml.state, ''), ' ', NULLIF(ml.zip_code, ''))
        ) as address
    FROM manufacturers m
    LEFT JOIN manufacturer_locations ml ON m.id = ml.manufacturer_id AND ml.is_primary = TRUE
    WHERE m.is_active = 1 
    ORDER BY m.name ASC";
$resManufacturers = $conn->query($sqlManufacturers);
if ($resManufacturers && $resManufacturers->num_rows > 0) {
    while ($row = $resManufacturers->fetch_assoc()) {
        $manufacturers[] = $row;
    }
}

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";
$projectImpactData = [];

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

function assignUnassignedBatchToProject($conn, $batch_id, $target_project_id, $role, $account_id_for_admin, $actor_user_id, $assign_context = 'single') {
    $batch_id = (int)$batch_id;
    $target_project_id = (int)$target_project_id;
    if ($batch_id <= 0 || $target_project_id <= 0) {
        throw new Exception("Please select a valid batch and project.");
    }

    if ($role === 'global_admin') {
        $stmtBatch = $conn->prepare("SELECT id, account_id, project_id, batch_name FROM modules WHERE id = ? LIMIT 1");
        if (!$stmtBatch) throw new Exception("Failed preparing batch lookup: " . $conn->error);
        $stmtBatch->bind_param("i", $batch_id);
    } else {
        if (!$account_id_for_admin) {
            throw new Exception("No valid account found for this admin user.");
        }
        $stmtBatch = $conn->prepare("SELECT id, account_id, project_id, batch_name FROM modules WHERE id = ? AND account_id = ? LIMIT 1");
        if (!$stmtBatch) throw new Exception("Failed preparing batch lookup: " . $conn->error);
        $stmtBatch->bind_param("ii", $batch_id, $account_id_for_admin);
    }
    $stmtBatch->execute();
    $batchRow = $stmtBatch->get_result()->fetch_assoc();
    $stmtBatch->close();

    if (!$batchRow) {
        throw new Exception("Module batch not found or access denied.");
    }
    if (!empty($batchRow['project_id'])) {
        throw new Exception("Batch #" . $batch_id . " is already assigned to a project.");
    }

    $stmtProject = $conn->prepare("SELECT id, account_id, project_name, status FROM projects WHERE id = ? LIMIT 1");
    if (!$stmtProject) throw new Exception("Failed preparing project lookup: " . $conn->error);
    $stmtProject->bind_param("i", $target_project_id);
    $stmtProject->execute();
    $projectRow = $stmtProject->get_result()->fetch_assoc();
    $stmtProject->close();

    if (!$projectRow) {
        throw new Exception("Target project not found.");
    }
    if ((int)$projectRow['account_id'] !== (int)$batchRow['account_id']) {
        throw new Exception("Batch #" . $batch_id . " does not belong to the selected project's account.");
    }
    if (isset($projectRow['status']) && strtolower((string)$projectRow['status']) === 'closed') {
        throw new Exception("Cannot assign modules to a closed project.");
    }

    // Guard against re-pointing pallets that are already tied to another project context.
    $stmtConflicts = $conn->prepare("
        SELECT COUNT(*) AS conflict_count
        FROM inventory_pallets ip
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN delivery_pallets dp ON dp.inventory_pallet_id = ip.id
        LEFT JOIN deliveries d ON d.id = dp.delivery_id
        WHERE umi.unassigned_module_id = ?
          AND (
              (ip.current_project_id IS NOT NULL AND ip.current_project_id <> ?)
              OR (ip.assigned_project_id IS NOT NULL AND ip.assigned_project_id <> ?)
              OR (d.project_id IS NOT NULL AND d.project_id <> ?)
          )
    ");
    if (!$stmtConflicts) throw new Exception("Failed preparing conflict check: " . $conn->error);
    $stmtConflicts->bind_param("iiii", $batch_id, $target_project_id, $target_project_id, $target_project_id);
    $stmtConflicts->execute();
    $conflictRow = $stmtConflicts->get_result()->fetch_assoc();
    $stmtConflicts->close();
    if ((int)($conflictRow['conflict_count'] ?? 0) > 0) {
        throw new Exception("Batch #" . $batch_id . " has pallets tied to a different project context.");
    }

    $assignTxStarted = false;
    $affectedPalletRows = 0;
    try {
        $conn->begin_transaction();
        $assignTxStarted = true;

        $stmtAssignBatch = $conn->prepare("UPDATE modules SET project_id = ?, last_updated_at = NOW() WHERE id = ?");
        if (!$stmtAssignBatch) throw new Exception("Failed preparing module batch assignment: " . $conn->error);
        $stmtAssignBatch->bind_param("ii", $target_project_id, $batch_id);
        if (!$stmtAssignBatch->execute()) {
            throw new Exception("Failed assigning module batch: " . $stmtAssignBatch->error);
        }
        $stmtAssignBatch->close();

        $stmtAssignPallets = $conn->prepare("
            UPDATE inventory_pallets ip
            JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            SET ip.assigned_project_id = ?
            WHERE umi.unassigned_module_id = ?
        ");
        if (!$stmtAssignPallets) throw new Exception("Failed preparing pallet assignment update: " . $conn->error);
        $stmtAssignPallets->bind_param("ii", $target_project_id, $batch_id);
        if (!$stmtAssignPallets->execute()) {
            throw new Exception("Failed updating pallet project assignments: " . $stmtAssignPallets->error);
        }
        $affectedPalletRows = (int)$stmtAssignPallets->affected_rows;
        $stmtAssignPallets->close();

        $conn->commit();
        $assignTxStarted = false;
    } catch (Exception $txEx) {
        if ($assignTxStarted) {
            $conn->rollback();
        }
        throw $txEx;
    }

    syncProjectWattageOrders($conn, $target_project_id);

    if (function_exists('mr_insert_reconciliation_audit')) {
        try {
            mr_insert_reconciliation_audit($conn, [
                'module_batch_id' => (int)$batch_id,
                'project_id' => (int)$target_project_id,
                'account_id' => (int)($batchRow['account_id'] ?? 0),
                'action_type' => 'assign_batch_to_project',
                'reason' => ($assign_context === 'bulk') ? 'bulk_assign' : 'single_assign',
                'reconciliation_mode' => 'direct_assignment',
                'preview_signature' => '',
                'actor_user_id' => (int)$actor_user_id,
                'actor_role' => (string)$role,
                'source_page' => 'modules.php',
                'before_state' => [
                    'project_id' => null
                ],
                'after_state' => [
                    'project_id' => (int)$target_project_id
                ],
                'impact' => [
                    'updated_pallet_rows' => $affectedPalletRows
                ]
            ]);
        } catch (Exception $auditEx) {
            error_log("Module assignment audit logging failed: " . $auditEx->getMessage());
        }
    }

    $batchLabel = !empty($batchRow['batch_name']) ? $batchRow['batch_name'] : ('Batch #' . (int)$batch_id);
    return [
        'batch_label' => $batchLabel,
        'project_name' => (string)($projectRow['project_name'] ?? 'Unknown'),
        'batch_id' => (int)$batch_id
    ];
}

// --- Handle POST actions --- 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-establish connection if it was closed (it shouldn't be closed yet based on new logic)
    if (!$conn || !$conn->ping()) {
        $conn = getDBConnection();
        if (!$conn) {
             $errorMessage = "Database re-connection failed during POST handling.";
             // We might need to exit or handle this more gracefully
        }
    }
    
    if ($conn) { // Proceed only if connection is valid
        $postAction = $_POST['action'] ?? '';

        if ($postAction === 'assign_batch' || $postAction === 'assign_batch_bulk') {
            try {
                if (!$isAdmin) {
                    throw new Exception("Only admin roles can assign module batches.");
                }

                $target_project_id = isset($_POST['assign_project_id']) ? (int)$_POST['assign_project_id'] : 0;
                if ($target_project_id <= 0) {
                    throw new Exception("Please select a target project.");
                }

                if ($postAction === 'assign_batch_bulk') {
                    $rawBatchIds = $_POST['batch_ids'] ?? [];
                    if (is_string($rawBatchIds)) {
                        $rawBatchIds = explode(',', $rawBatchIds);
                    }
                    if (!is_array($rawBatchIds)) {
                        $rawBatchIds = [];
                    }

                    $batchIds = [];
                    foreach ($rawBatchIds as $rawId) {
                        $id = (int)$rawId;
                        if ($id > 0) {
                            $batchIds[$id] = $id;
                        }
                    }
                    $batchIds = array_values($batchIds);
                    if (empty($batchIds)) {
                        throw new Exception("Please select at least one unassigned batch.");
                    }

                    $successes = [];
                    $errors = [];
                    foreach ($batchIds as $batchId) {
                        try {
                            $result = assignUnassignedBatchToProject(
                                $conn,
                                $batchId,
                                $target_project_id,
                                $role,
                                $account_id_for_admin,
                                $user_id,
                                'bulk'
                            );
                            $successes[] = $result['batch_label'];
                        } catch (Exception $assignEx) {
                            $errors[] = $assignEx->getMessage();
                        }
                    }

                    if (!empty($successes)) {
                        $successMessage = 'Assigned ' . count($successes) . ' batch(es) successfully.';
                    }
                    if (!empty($errors)) {
                        $errorMessage = implode(' ', array_unique($errors));
                    }
                    if (empty($successes) && empty($errors)) {
                        $errorMessage = "No batches were updated.";
                    }
                } else {
                    $batch_id = isset($_POST['batch_id']) ? (int)$_POST['batch_id'] : 0;
                    $result = assignUnassignedBatchToProject(
                        $conn,
                        $batch_id,
                        $target_project_id,
                        $role,
                        $account_id_for_admin,
                        $user_id,
                        'single'
                    );
                    $successMessage = "Assigned {$result['batch_label']} to project \"{$result['project_name']}\" successfully.";
                }
            } catch (Exception $ex) {
                $errorMessage = $ex->getMessage();
            }
        } else {
            $conn->begin_transaction(); // Start transaction
            try {
                // Determine correct account_id (using previously fetched variables)
                if ($role === 'global_admin') {
                    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
                    if ($account_id <= 0) {
                        throw new Exception("Please select a valid Account.");
                    }
                } else { // admin
                    if (!$account_id_for_admin) { // Double check admin has an account
                       throw new Exception("No valid account found for this admin user.");
                    }
                    $account_id = $account_id_for_admin;
                }
    
                // Gather main fields
                $manufacturer_id    = isset($_POST['manufacturer_id']) ? intval($_POST['manufacturer_id']) : null;
                $project_id_input   = $_POST['project_id'] ?? '';
                $project_id         = ($project_id_input !== '' && $project_id_input > 0) ? intval($project_id_input) : null;
    
                if (!$manufacturer_id) {
                    throw new Exception("Please select a Manufacturer.");
                }

                // Get manufacturer details and set vendor name and initial location
                $vendor_name = "Unknown Manufacturer";
                $initial_location = "Unknown Location";
                $stmt_mfg = $conn->prepare("
                SELECT 
                    m.name,
                    CONCAT_WS(', ', 
                        NULLIF(ml.street_address, ''),
                        NULLIF(ml.city, ''),
                        CONCAT(NULLIF(ml.state, ''), ' ', NULLIF(ml.zip_code, ''))
                    ) as address
                FROM manufacturers m
                LEFT JOIN manufacturer_locations ml ON m.id = ml.manufacturer_id AND ml.is_primary = TRUE
                WHERE m.id = ?");
                if ($stmt_mfg) {
                    $stmt_mfg->bind_param("i", $manufacturer_id);
                    $stmt_mfg->execute();
                    $stmt_mfg->bind_result($mfg_name, $mfg_address);
                    if ($stmt_mfg->fetch()) {
                        $vendor_name = $mfg_name;
                        $initial_location = $mfg_address ?: "Unknown Location";
                    } else {
                        throw new Exception("Selected manufacturer not found.");
                    }
                    $stmt_mfg->close();
                } else {
                    throw new Exception("Error preparing manufacturer query: " . $conn->error);
                }
            
                if ($role === 'global_admin' && $project_id !== null) {
                    $validProject = false;
                    $stmtCheckProj = $conn->prepare("SELECT 1 FROM projects WHERE id = ? AND account_id = ?");
                    if ($stmtCheckProj) {
                        $stmtCheckProj->bind_param("ii", $project_id, $account_id);
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
    
                // Insert into modules (including new project_id)
                $stmt = $conn->prepare("INSERT INTO modules (account_id, vendor_name, initial_location, project_id) VALUES (?, ?, ?, NULLIF(?, 0))");
                if (!$stmt) throw new Exception("Error preparing main insert: " . $conn->error);
                $stmt->bind_param("issi", $account_id, $vendor_name, $initial_location, $project_id);
                if (!$stmt->execute()) throw new Exception("Error inserting module batch: " . $stmt->error);
                $unassigned_module_id = $stmt->insert_id;
                $stmt->close();
    
                // Insert wattage+quantity items
                $wattage_items_added = 0;
                if (isset($_POST['wattages'], $_POST['quantities'])) {
                $wattages   = $_POST['wattages'];
                $quantities = $_POST['quantities'];
                $domestic_content_pcts = $_POST['domestic_content_pcts'] ?? [];
                $track_domestic_content = !empty($_POST['track_domestic_content']);
    
                if (count($wattages) !== count($quantities)) throw new Exception("Mismatch between wattage[] and quantities[] arrays.");
                
                $stmt2WithDomestic = $conn->prepare("INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity, domestic_content_pct) VALUES (?, ?, ?, ?)");
                $stmt2NoDomestic = $conn->prepare("INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)");
                if (!$stmt2WithDomestic || !$stmt2NoDomestic) throw new Exception("Error preparing item insert: " . $conn->error);
                
                for ($i=0; $i<count($wattages); $i++) {
                    $w = intval($wattages[$i]);
                    $q = intval($quantities[$i]);
    
                    if ($w <= 0 || $q <= 0) throw new Exception("Wattage and Quantity must be positive integers for all entries.");

                    $domesticContentPct = null;
                    if ($track_domestic_content) {
                        $pctRaw = trim((string)($domestic_content_pcts[$i] ?? ''));
                        if ($pctRaw === '' || !is_numeric($pctRaw)) {
                            throw new Exception("Domestic Content % is required and must be numeric when tracking is enabled.");
                        }
                        $domesticContentPct = (float)$pctRaw;
                        if ($domesticContentPct < 0 || $domesticContentPct > 100) {
                            throw new Exception("Domestic Content % must be between 0 and 100.");
                        }
                    }
                    
                    if ($track_domestic_content) {
                        $stmt2WithDomestic->bind_param("iiid", $unassigned_module_id, $w, $q, $domesticContentPct);
                        if (!$stmt2WithDomestic->execute()) throw new Exception("Error inserting wattage/quantity item: " . $stmt2WithDomestic->error);
                    } else {
                        $stmt2NoDomestic->bind_param("iii", $unassigned_module_id, $w, $q);
                        if (!$stmt2NoDomestic->execute()) throw new Exception("Error inserting wattage/quantity item: " . $stmt2NoDomestic->error);
                    }
                    $wattage_items_added++;
                }
                $stmt2WithDomestic->close();
                $stmt2NoDomestic->close();
            }
    
                if ($wattage_items_added === 0) throw new Exception("You must add at least one Wattage/Quantity entry.");
    
                $conn->commit();
                $successMessage = "Module batch (ID: {$unassigned_module_id}) and {$wattage_items_added} item(s) added successfully!";
    
                // Sync project_wattage_orders table
                syncProjectWattageOrders($conn, $project_id);

            } catch (Exception $ex) {
                $conn->rollback();
                $errorMessage = $ex->getMessage();
            } 
            // Removed finally block with conn->close()
        }
    }
}

// --- Fetch Modules Data for Table Display ---
$modulesData = [];
if ($conn) { // Check connection is still valid
    $sqlModules        = "";
    $paramTypesModules = "";
    $paramsModules     = [];
    
    if ($role === 'global_admin') {
        $sqlModules = "SELECT
                         um.id, um.account_id, um.batch_name, um.vendor_name, um.initial_location, um.project_id,
                         c.name AS account_name,
                         p.project_name
                       FROM modules um
                       JOIN customer_accounts c ON um.account_id = c.id
                       LEFT JOIN projects p ON um.project_id = p.id
                       ORDER BY c.name ASC, um.vendor_name ASC";
    } elseif (in_array($role, ['admin', 'customer_admin'], true) && !empty($account_id_for_admin)) {
         $sqlModules = "SELECT
                          um.id, um.account_id, um.batch_name, um.vendor_name, um.initial_location, um.project_id,
                          c.name AS account_name,
                          p.project_name
                        FROM modules um
                        JOIN customer_accounts c ON um.account_id = c.id
                        LEFT JOIN projects p ON um.project_id = p.id
                        WHERE um.account_id = ?
                        ORDER BY um.vendor_name ASC";
        $paramTypesModules = "i";
        $paramsModules     = [$account_id_for_admin];
    } elseif ($role === 'user' && !empty($account_id_for_user)) { // Added condition for 'user' role
         $sqlModules = "SELECT
                          um.id, um.account_id, um.batch_name, um.vendor_name, um.initial_location, um.project_id,
                          c.name AS account_name,
                          p.project_name
                        FROM modules um
                        JOIN customer_accounts c ON um.account_id = c.id
                        LEFT JOIN projects p ON um.project_id = p.id
                        WHERE um.account_id = ?
                        ORDER BY um.vendor_name ASC";
        $paramTypesModules = "i";
        $paramsModules     = [$account_id_for_user];
    } else {
        // Admin with no account or other unhandled roles see no modules
         $sqlModules = "SELECT NULL LIMIT 0";
    }
    
    $stmtModules = $conn->prepare($sqlModules);
    if (!$stmtModules) {
        $errorMessage .= " Error preparing modules query: " . $conn->error;
    } else {
        if (!empty($paramTypesModules)) {
            $stmtModules->bind_param($paramTypesModules, ...$paramsModules);
        }
        $stmtModules->execute();
        $resultModules = $stmtModules->get_result();
        
        // Fetch items for each batch
        $stmtItems = $conn->prepare("SELECT wattage, quantity, domestic_content_pct FROM unassigned_module_items WHERE unassigned_module_id = ?");
        if (!$stmtItems) {
            $errorMessage .= " Error preparing item query: " . $conn->error;
        } else {
            while ($batch = $resultModules->fetch_assoc()) {
                $batch_id = $batch['id'];
                $batch['items'] = [];
                $batch['total_quantity'] = 0;
                $batch['total_watts'] = 0;
            
                $stmtItems->bind_param("i", $batch_id);
                $stmtItems->execute();
                $resultItems = $stmtItems->get_result();
                while ($item = $resultItems->fetch_assoc()) {
                    $batch['items'][] = $item;
                    $batch['total_quantity'] += $item['quantity'];
                    $batch['total_watts'] += ((int)$item['wattage']) * ((int)$item['quantity']);
                }
                
                // NEW: Fetch palletization status for this batch
                $batch['total_palletized'] = 0;
                $batch['palletization_percentage'] = 0;
                $batch['palletization_status'] = 'Not Palletized';
                
                // Query to get total palletized quantity for this batch
                $stmtPallets = $conn->prepare("
                    SELECT COALESCE(SUM(ip.quantity), 0) as total_palletized
                    FROM inventory_pallets ip
                    JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                    WHERE umi.unassigned_module_id = ?
                ");
                if ($stmtPallets) {
                    $stmtPallets->bind_param("i", $batch_id);
                    $stmtPallets->execute();
                    $stmtPallets->bind_result($total_palletized);
                    if ($stmtPallets->fetch()) {
                        $batch['total_palletized'] = $total_palletized ?: 0;
                    }
                    $stmtPallets->close();
                    
                    // Calculate palletization percentage and status
                    if ($batch['total_quantity'] > 0) {
                        $batch['palletization_percentage'] = ($batch['total_palletized'] / $batch['total_quantity']) * 100;
                        
                        if ($batch['total_palletized'] == 0) {
                            $batch['palletization_status'] = 'Not Palletized';
                        } elseif ($batch['total_palletized'] >= $batch['total_quantity']) {
                            $batch['palletization_status'] = 'Fully Palletized';
                        } else {
                            $batch['palletization_status'] = 'Partially Palletized';
                        }
                    }
                }
                
                $modulesData[] = $batch;
            }
            $stmtItems->close();
        }
        $stmtModules->close();
    }

    // Split modulesData into project-assigned and unassigned
    $projectModulesData = [];
    $unassignedModulesData = [];
    foreach ($modulesData as $module) {
        if (!empty($module['project_id'])) {
            $projectModulesData[] = $module;
        } else {
            $unassignedModulesData[] = $module;
        }
    }

    if (!empty($projects_for_account)) {
        foreach ($projects_for_account as $proj) {
            $pid = (int)($proj['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $projectSizeMw = (float)($proj['project_size'] ?? 0);
            $projectImpactData[$pid] = [
                'project_name' => (string)($proj['project_name'] ?? ('Project #' . $pid)),
                'project_size_mw' => $projectSizeMw,
                'target_modules' => null,
                'target_watts' => $projectSizeMw > 0 ? ($projectSizeMw * 1000000) : 0.0,
                'current_modules' => 0,
                'current_watts' => 0.0
            ];
        }

        $projectIds = array_keys($projectImpactData);
        if (!empty($projectIds)) {
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $types = str_repeat('i', count($projectIds));

            $sqlTarget = "
                SELECT
                    pwo.project_id,
                    COALESCE(SUM(pwo.total_order), 0) AS target_modules,
                    COALESCE(SUM(CAST(pwo.wattage AS DECIMAL(10,2)) * pwo.total_order), 0) AS target_watts
                FROM project_wattage_orders pwo
                WHERE pwo.project_id IN ($placeholders)
                GROUP BY pwo.project_id
            ";
            $stmtTarget = $conn->prepare($sqlTarget);
            if ($stmtTarget) {
                $stmtTarget->bind_param($types, ...$projectIds);
                $stmtTarget->execute();
                $resTarget = $stmtTarget->get_result();
                while ($row = $resTarget->fetch_assoc()) {
                    $pid = (int)($row['project_id'] ?? 0);
                    if (!isset($projectImpactData[$pid])) {
                        continue;
                    }
                    // Project target is based on project_size (MW). Only fall back to PWO if project_size is unavailable.
                    if ((float)($projectImpactData[$pid]['target_watts'] ?? 0) <= 0) {
                        $projectImpactData[$pid]['target_modules'] = (int)($row['target_modules'] ?? 0);
                        $projectImpactData[$pid]['target_watts'] = (float)($row['target_watts'] ?? 0);
                    }
                }
                $stmtTarget->close();
            }

            $sqlCurrent = "
                SELECT
                    m.project_id,
                    COALESCE(SUM(umi.quantity), 0) AS current_modules,
                    COALESCE(SUM(umi.wattage * umi.quantity), 0) AS current_watts
                FROM modules m
                JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
                WHERE m.project_id IN ($placeholders)
                GROUP BY m.project_id
            ";
            $stmtCurrent = $conn->prepare($sqlCurrent);
            if ($stmtCurrent) {
                $stmtCurrent->bind_param($types, ...$projectIds);
                $stmtCurrent->execute();
                $resCurrent = $stmtCurrent->get_result();
                while ($row = $resCurrent->fetch_assoc()) {
                    $pid = (int)($row['project_id'] ?? 0);
                    if (!isset($projectImpactData[$pid])) {
                        continue;
                    }
                    $projectImpactData[$pid]['current_modules'] = (int)($row['current_modules'] ?? 0);
                    $projectImpactData[$pid]['current_watts'] = (float)($row['current_watts'] ?? 0);
                }
                $stmtCurrent->close();
            }
        }
    }
} // end if($conn)

// --- Close connection after all DB operations for the page are done --- 
if ($conn && $conn instanceof mysqli) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Modules</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Modern Page Header */
        .modules-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .modules-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .modules-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modules-header h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .modules-header .subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }
        .btn-add-batch {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .btn-add-batch:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }

        /* Section Headers */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e9ecef;
        }
        .section-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
        }
        .section-header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-bulk-assign {
            border: none;
            border-radius: 10px;
            padding: 8px 14px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 3px 10px rgba(72, 140, 154, 0.28);
        }
        .btn-bulk-assign:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            box-shadow: none;
        }
        .batch-select-cell {
            text-align: center;
            width: 42px;
        }
        .batch-select-cell input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        /* Modern Table Styling */
        .table-container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 32px;
        }
        .table-container table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .table-container thead {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
        }
        .table-container th {
            padding: 16px 20px;
            text-align: center;
            font-weight: 600;
            color: #fff;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: none;
        }
        .table-container td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        .table-container tbody tr {
            transition: background-color 0.2s ease;
        }
        .table-container tbody tr:hover {
            background-color: #f8f9fa;
        }
        .table-container tbody tr:last-child td {
            border-bottom: none;
        }

        /* Clickable row styling */
        .clickable-row {
            cursor: pointer;
        }
        .clickable-row:hover {
            background-color: #f0f7f8 !important;
        }

        /* Modern Action Buttons */
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }
        .btn-action.primary {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: white;
        }
        .btn-action.primary:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
            transform: translateY(-1px);
        }
        .btn-action.secondary {
            background: #f8f9fa;
            color: #488C9A;
            border: 1px solid #e9ecef;
        }
        .btn-action.secondary:hover {
            background: #e9ecef;
            color: #293E4C;
        }
        .btn-action.warning {
            background: linear-gradient(135deg, #fd7e14 0%, #e8690b 100%);
            color: white;
        }
        .btn-action.warning:hover {
            background: linear-gradient(135deg, #e8690b 0%, #d45d0a 100%);
        }

        /* Batch name styling */
        .batch-name {
            font-weight: 600;
            color: #293E4C;
            font-size: 0.95rem;
        }
        .batch-vendor {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 2px;
        }

        /* Empty State Styling */
        .table-container tr.empty-row td {
            text-align: center;
            padding: 48px 20px !important;
            background: #fafafa;
        }
        .empty-state-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
        .empty-state-content .empty-icon {
            font-size: 3rem;
            opacity: 0.5;
        }
        .empty-state-content p {
            color: #666;
            margin: 0;
            font-size: 0.95rem;
        }
        .empty-state-content a {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
        }
        .empty-state-content a:hover {
            text-decoration: underline;
        }

        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success-message {
            color: #155724;
            background-color: #d4edda;
            padding: 15px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .module-info {
            font-size: 0.9em;
            color: #666;
        }
        .quantity-display {
            font-weight: 500;
            color: #488C9A;
        }
        .assignment-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 500;
        }
        .assignment-status.assigned {
            background-color: #d4edda;
            color: #155724;
        }
        .assignment-status.unassigned {
            background-color: #f8d7da;
            color: #721c24;
        }
        /* NEW: Palletization status styles */
        .palletization-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 500;
            margin-top: 5px;
        }
        .palletization-status.not-palletized {
            background-color: #f8d7da;
            color: #721c24;
        }
        .palletization-status.partially-palletized {
            background-color: #fff3cd;
            color: #856404;
        }
        .palletization-status.fully-palletized {
            background-color: #d4edda;
            color: #155724;
        }
        .palletization-details {
            font-size: 0.8em;
            color: #666;
            margin-top: 3px;
        }
        
        /* Dropdown menu styling */
        .dropdown {
            position: relative;
            display: inline-block;
        }
        .kebab-trigger {
            background: none;
            border: 1px solid transparent;
            color: #6c757d;
            padding: 6px 8px;
            cursor: pointer;
            font-size: 1.3em;
            border-radius: 8px;
            transition: all 0.2s ease;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .kebab-trigger:hover {
            background-color: #e9ecef;
            color: #293E4C;
            border-color: #dee2e6;
        }
        .dropdown-menu {
            display: none;
            position: fixed;
            background-color: white;
            min-width: 140px;
            box-shadow: 0 12px 28px rgba(0,0,0,0.12), 0 4px 10px rgba(0,0,0,0.08);
            border-radius: 10px;
            z-index: 9999;
            border: 1px solid rgba(0,0,0,0.06);
            padding: 4px;
            backdrop-filter: blur(8px);
        }
        .dropdown-menu.show {
            display: block;
            animation: dropdownFadeIn 0.15s ease-out;
        }
        @keyframes dropdownFadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 10px 14px;
            text-decoration: none;
            color: #333;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            font-size: 0.9em;
            border-radius: 6px;
            transition: background-color 0.15s ease;
        }
        .dropdown-item:hover {
            background-color: #f0f7f8;
        }
        .dropdown-item.edit {
            color: #293E4C;
        }
        .dropdown-item.delete {
            color: #dc3545;
        }
        .dropdown-item.delete:hover {
            background-color: #fdf0f0;
        }
        
        /* Actions cell styling */
        .actions-cell {
            position: relative;
            text-align: center;
            width: 60px;
        }
        
        /* Form styles for modal */
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

        /* Modal Styles */
        .modal {
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.5); 
        }
        .modal-content {
            background-color: #fefefe;
            margin: 8% auto; /* Adjusted margin */
            padding: 30px;
            border: 1px solid #888;
            width: 80%;
            max-width: 700px;
            border-radius: 5px;
            position: relative;
        }
        .close-button {
            color: #aaa;
            position: absolute;
            right: 15px;
            top: 5px;
            font-size: 28px;
            font-weight: bold;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        .modal-content h2 {
            margin-top: 0;
            margin-bottom: 20px;
            text-align: center;
        }
        /* Adjust form styles within modal if needed */
        .modal-content form label { margin-top: 10px; }
        .modal-content .btn-submit { margin-top: 15px; }
        .assign-impact-panel {
            margin-top: 14px;
            border: 1px solid #d6e5ea;
            border-radius: 10px;
            background: #f8fcfd;
            padding: 12px;
        }
        .assign-impact-title {
            font-weight: 700;
            color: #2d4a55;
            margin-bottom: 8px;
        }
        .assign-impact-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 12px;
            font-size: 0.92em;
        }
        .assign-impact-grid .k {
            color: #64748b;
            font-weight: 600;
        }
        .assign-impact-grid .v {
            color: #1f2937;
            font-weight: 700;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .assign-impact-note {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px dashed #c9dbe1;
            color: #334155;
            font-size: 0.9em;
        }
    </style>
    <script>
        // Wattage field adder - remains the same
        function addWattageField() {
            var container = document.getElementById('wattage-container');
            var index = container.children.length;
            var trackDomesticCheckbox = document.getElementById('track_domestic_content');
            var trackDomestic = !!(trackDomesticCheckbox && trackDomesticCheckbox.checked);

            var div = document.createElement('div');
            div.className = 'wattage-entry';

            var wattageDiv = document.createElement('div');
            var wattageLabel = document.createElement('label');
            wattageLabel.textContent = 'Wattage:';
            wattageLabel.htmlFor = 'wattages_' + index;
            var wattageInput = document.createElement('input');
            wattageInput.type = 'number';
            wattageInput.step = '1'; 
            wattageInput.name = 'wattages[' + index + ']';
            wattageInput.id = 'wattages_' + index;
            wattageInput.required = true;
            wattageDiv.appendChild(wattageLabel);
            wattageDiv.appendChild(wattageInput);

            var quantityDiv = document.createElement('div');
            var quantityLabel = document.createElement('label');
            quantityLabel.textContent = 'Quantity:';
            quantityLabel.htmlFor = 'quantities_' + index;
            var quantityInput = document.createElement('input');
            quantityInput.type = 'number';
            quantityInput.step = '1'; 
            quantityInput.name = 'quantities[' + index + ']';
            quantityInput.id = 'quantities_' + index;
            quantityInput.required = true;
            quantityDiv.appendChild(quantityLabel);
            quantityDiv.appendChild(quantityInput);

            var domesticDiv = document.createElement('div');
            domesticDiv.className = 'domestic-content-group';
            domesticDiv.style.display = trackDomestic ? '' : 'none';
            var domesticLabel = document.createElement('label');
            domesticLabel.textContent = 'Domestic Content %';
            domesticLabel.htmlFor = 'domestic_content_pcts_' + index;
            var domesticInput = document.createElement('input');
            domesticInput.type = 'number';
            domesticInput.step = '0.01';
            domesticInput.min = '0';
            domesticInput.max = '100';
            domesticInput.name = 'domestic_content_pcts[' + index + ']';
            domesticInput.id = 'domestic_content_pcts_' + index;
            domesticInput.required = trackDomestic;
            domesticDiv.appendChild(domesticLabel);
            domesticDiv.appendChild(domesticInput);

            var removeBtnDiv = document.createElement('div');
            removeBtnDiv.className = 'remove-btn-container';
            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Remove';
            removeButton.onclick = function() {
                container.removeChild(div);
            };
            removeBtnDiv.appendChild(removeButton);

            div.appendChild(wattageDiv);
            div.appendChild(quantityDiv);
            div.appendChild(domesticDiv);
            div.appendChild(removeBtnDiv);

            container.appendChild(div);
        }

        function toggleDomesticContentFields() {
            var checkbox = document.getElementById('track_domestic_content');
            var enabled = !!(checkbox && checkbox.checked);
            document.querySelectorAll('.domestic-content-group').forEach(function(group) {
                group.style.display = enabled ? '' : 'none';
                var input = group.querySelector('input');
                if (input) {
                    input.required = enabled;
                }
            });
        }

        // Modal handling will be added after DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('addModuleModal');
            var btn = document.getElementById('openAddModalBtn');
            var span = modal ? modal.querySelector('.close-button') : null;
            var assignModal = document.getElementById('assignBatchModal');
            var assignClose = document.getElementById('closeAssignBatchModal');
            var assignActionInput = document.getElementById('assign_action');
            var assignBatchIdInput = document.getElementById('assign_batch_id');
            var assignBatchIdsInput = document.getElementById('assign_batch_ids');
            var assignProjectSelect = document.getElementById('assign_project_id');
            var assignBatchHelp = document.getElementById('assignBatchHelp');
            var assignBatchSubmitBtn = document.getElementById('assignBatchSubmitBtn');
            var assignSelectedBatchesBtn = document.getElementById('assignSelectedBatchesBtn');
            var selectAllUnassignedBatches = document.getElementById('selectAllUnassignedBatches');
            var unassignedBatchCheckboxes = Array.from(document.querySelectorAll('.unassigned-batch-checkbox'));
            var isGlobalAdminRole = ('<?php echo $role; ?>' === 'global_admin');
            var projectImpactById = <?php echo json_encode($projectImpactData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var selectedAssignMetrics = { modules: 0, watts: 0 };
            var assignProjectOptions = assignProjectSelect
                ? Array.from(assignProjectSelect.options).slice(1).map(function(opt) { return opt.cloneNode(true); })
                : [];
            var impactPanel = document.getElementById('assignImpactPanel');
            var impactTargetEl = document.getElementById('impact_target');
            var impactCurrentEl = document.getElementById('impact_current');
            var impactSelectedEl = document.getElementById('impact_selected');
            var impactProjectedEl = document.getElementById('impact_projected');
            var impactNoteEl = document.getElementById('impact_note');

            if(btn) { btn.onclick = function() { modal.style.display = 'block'; } }
            if(span) { span.onclick = function() { modal.style.display = 'none'; } }
            if (assignClose && assignModal) {
                assignClose.onclick = function() {
                    assignModal.style.display = 'none';
                    selectedAssignMetrics = { modules: 0, watts: 0 };
                    if (impactPanel) impactPanel.style.display = 'none';
                };
            }
            window.addEventListener('click', function(event) {
                if (modal && event.target === modal) {
                    modal.style.display = 'none';
                }
                if (assignModal && event.target === assignModal) {
                    assignModal.style.display = 'none';
                    selectedAssignMetrics = { modules: 0, watts: 0 };
                    if (impactPanel) impactPanel.style.display = 'none';
                }
            });

            function getSelectedUnassignedBatchCheckboxes() {
                return unassignedBatchCheckboxes.filter(function(cb) { return cb.checked; });
            }

            function updateAssignSelectedButtonState() {
                if (!assignSelectedBatchesBtn) return;
                assignSelectedBatchesBtn.disabled = (getSelectedUnassignedBatchCheckboxes().length === 0);
            }

            function formatModulesAndMw(modules, watts) {
                var safeModules = Number(modules || 0);
                var safeWatts = Number(watts || 0);
                var mw = safeWatts / 1000000;
                return safeModules.toLocaleString() + ' modules (' + mw.toFixed(3) + ' MW)';
            }

            function formatTargetValue(projectStats) {
                var targetWatts = Number(projectStats.target_watts || 0);
                var targetModulesRaw = projectStats.target_modules;
                var hasTargetModules = (targetModulesRaw !== null && targetModulesRaw !== '' && !isNaN(Number(targetModulesRaw)) && Number(targetModulesRaw) > 0);
                if (hasTargetModules) {
                    return formatModulesAndMw(Number(targetModulesRaw), targetWatts);
                }
                if (targetWatts > 0) {
                    return (targetWatts / 1000000).toFixed(3) + ' MW (Project Size)';
                }
                return '-';
            }

            function renderAssignImpact() {
                if (!impactPanel || !assignProjectSelect) return;
                var selectedProjectId = parseInt(assignProjectSelect.value || '0', 10);
                if (!selectedProjectId || !projectImpactById[selectedProjectId]) {
                    impactPanel.style.display = selectedAssignMetrics.modules > 0 ? 'block' : 'none';
                    if (impactTargetEl) impactTargetEl.textContent = '-';
                    if (impactCurrentEl) impactCurrentEl.textContent = '-';
                    if (impactSelectedEl) impactSelectedEl.textContent = formatModulesAndMw(selectedAssignMetrics.modules, selectedAssignMetrics.watts);
                    if (impactProjectedEl) impactProjectedEl.textContent = '-';
                    if (impactNoteEl) impactNoteEl.textContent = 'Select a project to see projected totals.';
                    return;
                }

                var projectStats = projectImpactById[selectedProjectId] || {};
                var rawTargetModules = projectStats.target_modules;
                var hasTargetModules = (rawTargetModules !== null && rawTargetModules !== '' && !isNaN(Number(rawTargetModules)) && Number(rawTargetModules) > 0);
                var targetModules = hasTargetModules ? Number(rawTargetModules) : null;
                var targetWatts = Number(projectStats.target_watts || 0);
                var currentModules = Number(projectStats.current_modules || 0);
                var currentWatts = Number(projectStats.current_watts || 0);
                var selectedModules = Number(selectedAssignMetrics.modules || 0);
                var selectedWatts = Number(selectedAssignMetrics.watts || 0);

                var projectedModules = currentModules + selectedModules;
                var projectedWatts = currentWatts + selectedWatts;

                if (impactTargetEl) impactTargetEl.textContent = formatTargetValue(projectStats);
                if (impactCurrentEl) impactCurrentEl.textContent = formatModulesAndMw(currentModules, currentWatts);
                if (impactSelectedEl) impactSelectedEl.textContent = formatModulesAndMw(selectedModules, selectedWatts);
                if (impactProjectedEl) impactProjectedEl.textContent = formatModulesAndMw(projectedModules, projectedWatts);

                if (impactNoteEl) {
                    if (targetWatts > 0) {
                        var wattsDiff = projectedWatts - targetWatts;
                        var mwDiff = Math.abs(wattsDiff) / 1000000;
                        var mwDiffText = mwDiff.toFixed(3) + ' MW';
                        var moduleDiffText = '';
                        if (hasTargetModules && targetModules !== null) {
                            var moduleDiff = projectedModules - targetModules;
                            moduleDiffText = Math.abs(moduleDiff).toLocaleString() + ' modules';
                        }
                        var unitDetail = moduleDiffText ? (moduleDiffText + ' / ' + mwDiffText) : mwDiffText;
                        if (wattsDiff > 0) {
                            impactNoteEl.textContent = 'Projected to be over target by ' + unitDetail + '.';
                        } else if (wattsDiff < 0) {
                            impactNoteEl.textContent = 'Projected to remain under target by ' + unitDetail + '.';
                        } else {
                            impactNoteEl.textContent = 'Projected to match target exactly.';
                        }
                    } else {
                        impactNoteEl.textContent = 'No project target defined. Review projected totals above.';
                    }
                }
                impactPanel.style.display = 'block';
            }

            function rebuildAssignProjectOptions(accountIdForFilter) {
                if (!assignProjectSelect) return;
                while (assignProjectSelect.options.length > 1) {
                    assignProjectSelect.remove(1);
                }
                assignProjectOptions.forEach(function(optionTemplate) {
                    var cloned = optionTemplate.cloneNode(true);
                    if (isGlobalAdminRole && accountIdForFilter > 0) {
                        var optionAccountId = parseInt(cloned.getAttribute('data-account-id') || '0', 10);
                        if (optionAccountId !== accountIdForFilter) {
                            return;
                        }
                    }
                    assignProjectSelect.add(cloned);
                });
            }

            if (selectAllUnassignedBatches) {
                selectAllUnassignedBatches.addEventListener('change', function() {
                    unassignedBatchCheckboxes.forEach(function(cb) {
                        cb.checked = !!selectAllUnassignedBatches.checked;
                    });
                    updateAssignSelectedButtonState();
                });
            }

            unassignedBatchCheckboxes.forEach(function(cb) {
                cb.addEventListener('click', function(event) {
                    event.stopPropagation();
                });
                cb.addEventListener('change', function() {
                    if (selectAllUnassignedBatches) {
                        selectAllUnassignedBatches.checked = unassignedBatchCheckboxes.length > 0 && unassignedBatchCheckboxes.every(function(item) { return item.checked; });
                    }
                    updateAssignSelectedButtonState();
                });
            });
            updateAssignSelectedButtonState();

            window.openAssignBatchModal = function(event, batchId, batchAccountId, batchLabel, totalQuantity, totalWatts) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                if (!assignModal || !assignBatchIdInput || !assignProjectSelect) return;

                if (assignActionInput) assignActionInput.value = 'assign_batch';
                assignBatchIdInput.value = String(batchId || '');
                if (assignBatchIdsInput) assignBatchIdsInput.value = '';
                if (assignBatchSubmitBtn) assignBatchSubmitBtn.textContent = 'Assign Batch';
                selectedAssignMetrics = {
                    modules: Number(totalQuantity || 0),
                    watts: Number(totalWatts || 0)
                };
                if (assignBatchHelp) {
                    assignBatchHelp.textContent = 'Assign "' + (batchLabel || ('Batch #' + batchId)) + '" to a project.';
                }

                rebuildAssignProjectOptions(parseInt(batchAccountId || '0', 10));

                assignProjectSelect.value = '';
                renderAssignImpact();
                assignModal.style.display = 'block';
            };

            window.openAssignSelectedBatchesModal = function(event) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                if (!assignModal || !assignProjectSelect || !assignBatchIdsInput) return;

                var selected = getSelectedUnassignedBatchCheckboxes();
                if (selected.length === 0) {
                    alert('Select at least one unassigned batch first.');
                    return;
                }

                var accountIds = Array.from(new Set(selected.map(function(cb) {
                    return parseInt(cb.getAttribute('data-account-id') || '0', 10);
                }).filter(function(id) { return id > 0; })));

                if (isGlobalAdminRole && accountIds.length > 1) {
                    alert('Select batches from a single account for bulk assignment.');
                    return;
                }

                if (assignActionInput) assignActionInput.value = 'assign_batch_bulk';
                assignBatchIdInput.value = '';
                assignBatchIdsInput.value = selected.map(function(cb) { return cb.value; }).join(',');
                if (assignBatchSubmitBtn) assignBatchSubmitBtn.textContent = 'Assign Selected';
                selectedAssignMetrics = selected.reduce(function(acc, cb) {
                    acc.modules += Number(cb.getAttribute('data-total-quantity') || 0);
                    acc.watts += Number(cb.getAttribute('data-total-watts') || 0);
                    return acc;
                }, { modules: 0, watts: 0 });

                var singleAccountId = accountIds.length === 1 ? accountIds[0] : 0;
                rebuildAssignProjectOptions(singleAccountId);
                assignProjectSelect.value = '';

                if (assignBatchHelp) {
                    assignBatchHelp.textContent = 'Assign ' + selected.length + ' selected batch(es) to a project.';
                }
                renderAssignImpact();
                assignModal.style.display = 'block';
            };

            if (assignSelectedBatchesBtn) {
                assignSelectedBatchesBtn.addEventListener('click', window.openAssignSelectedBatchesModal);
            }
            if (assignProjectSelect) {
                assignProjectSelect.addEventListener('change', renderAssignImpact);
            }
            
            // If there was a POST error, re-open the modal to show the error message within context
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errorMessage)): ?>
            <?php if (isset($_POST['action']) && in_array($_POST['action'], ['assign_batch', 'assign_batch_bulk'], true)): ?>
            if (assignModal) { assignModal.style.display = 'block'; }
            <?php if (isset($_POST['action']) && $_POST['action'] === 'assign_batch_bulk'): ?>
            if (assignActionInput) { assignActionInput.value = 'assign_batch_bulk'; }
            if (assignBatchSubmitBtn) { assignBatchSubmitBtn.textContent = 'Assign Selected'; }
            <?php endif; ?>
            <?php else: ?>
            if (modal) { modal.style.display = 'block'; }
            <?php endif; ?>
            <?php endif; ?>

            var trackDomesticCheckbox = document.getElementById('track_domestic_content');
            if (trackDomesticCheckbox) {
                trackDomesticCheckbox.addEventListener('change', toggleDomesticContentFields);
            }
            toggleDomesticContentFields();

            // NEW: Add initial wattage field if container is empty on modal load
            if (modal && modal.style.display === 'block') {
                var wattageContainer = document.getElementById('wattage-container');
                 if (wattageContainer && wattageContainer.children.length === 0) {
                      addWattageField();
                 }
            }
             // NEW: Logic to filter project dropdown based on selected account (for global admin)
            var accountSelect = document.getElementById('account_id');
            var projectSelect = document.getElementById('project_id');
            if (accountSelect && projectSelect && '<?php echo $role; ?>' === 'global_admin') {
                 // Store all project options initially
                 var allProjectOptions = Array.from(projectSelect.options).slice(1); // Skip placeholder

                 accountSelect.addEventListener('change', function() {
                     var selectedAccountId = this.value;
                     // Clear current project options (except placeholder)
                     while (projectSelect.options.length > 1) {
                         projectSelect.remove(1);
                     }

                     // Add back relevant options
                     allProjectOptions.forEach(function(option) {
                          // Check if the option's data-account-id matches the selected account
                          if (selectedAccountId === '' || option.getAttribute('data-account-id') === selectedAccountId) {
                              projectSelect.add(option.cloneNode(true));
                          }
                     });
                 });
                 // Trigger change on load if an account is pre-selected (e.g., after error)
                 if (accountSelect.value !== '') {
                      accountSelect.dispatchEvent(new Event('change'));
                 }
            }
            
            // Dropdown functionality with smart positioning
            window.toggleDropdown = function(event, dropdownId) {
                event.stopPropagation();

                // Close all other dropdowns
                document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                    if (menu.id !== dropdownId) {
                        menu.classList.remove('show');
                    }
                });

                const dropdown = document.getElementById(dropdownId);
                const isOpen = dropdown.classList.contains('show');

                if (isOpen) {
                    dropdown.classList.remove('show');
                    return;
                }

                // Position the dropdown relative to the trigger button
                const trigger = event.currentTarget;
                const rect = trigger.getBoundingClientRect();

                // Show briefly off-screen to measure height
                dropdown.style.visibility = 'hidden';
                dropdown.classList.add('show');
                const menuHeight = dropdown.offsetHeight;
                const menuWidth = dropdown.offsetWidth;
                dropdown.style.visibility = '';

                // Decide whether to open above or below
                const spaceBelow = window.innerHeight - rect.bottom;
                const openAbove = spaceBelow < menuHeight + 8 && rect.top > menuHeight + 8;

                if (openAbove) {
                    dropdown.style.top = (rect.top - menuHeight - 4) + 'px';
                } else {
                    dropdown.style.top = (rect.bottom + 4) + 'px';
                }

                // Align to the right edge of the trigger
                const leftPos = rect.right - menuWidth;
                dropdown.style.left = Math.max(8, leftPos) + 'px';
            }

            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                    menu.classList.remove('show');
                });
            });

            // Close dropdowns on scroll to prevent stale positioning
            window.addEventListener('scroll', function() {
                document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                    menu.classList.remove('show');
                });
            }, true);
        });
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Manage Modules']); ?>

    <!-- Modern Page Header -->
    <div class="modules-header">
        <div class="modules-header-content">
            <div>
                <h1>Manage Modules</h1>
                <p class="subtitle">Track and manage module batches across your projects</p>
            </div>
            <?php if ($isAdmin): ?>
            <a href="add_module_batch.php" class="btn-add-batch">
                <span>+</span> Add Module Batch
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Display session message if it exists
    if (isset($_SESSION['del_message']) && !empty($_SESSION['del_message'])) {
        $messageClass = (strpos(strtolower($_SESSION['del_message']), 'error') === false) ? 'success-message' : 'error-message';
        echo '<div class="' . $messageClass . '">' . htmlspecialchars($_SESSION['del_message']) . '</div>';
        unset($_SESSION['del_message']); // Clear the message
    }
    // Display messages from POST handling on this page
    if (!empty($successMessage)) {
        echo '<div class="success-message">' . htmlspecialchars($successMessage) . '</div>';
    }
    if (!empty($errorMessage)) {
        echo '<div class="error-message">' . htmlspecialchars($errorMessage) . '</div>';
    }
    ?>

    <!-- The Modal -->
    <div id="addModuleModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>Add New Module Batch</h2>

            <!-- Display error message INSIDE modal if POST failed -->
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errorMessage)): ?>
                <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- The form moved inside the modal -->
            <form action="modules.php" method="POST">
                <?php if ($role === 'global_admin'): ?>
                    <label for="account_id">Account Name:</label>
                    <select name="account_id" id="account_id" required>
                        <option value="">--Select Account--</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" <?php echo (isset($_POST['account_id']) && $_POST['account_id'] == $acc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: // Admin Role ?>
                    <?php
                        // Need to re-establish connection briefly to get account name if needed
                        $adminAccountName = '[Account Name Not Found]';
                        if ($account_id_for_admin) {
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
                        }
                        echo "<p><strong>Account:</strong> " . htmlspecialchars($adminAccountName) . "</p>";
                    ?>
                    <input type="hidden" name="account_id" value="<?php echo $account_id_for_admin; ?>">
                <?php endif; ?>
        
                <label for="manufacturer_id">Manufacturer: <span style="color: red;">*</span></label>
                <select name="manufacturer_id" id="manufacturer_id" required>
                    <option value="">--Select Manufacturer--</option>
                    <?php foreach ($manufacturers as $mfg): ?>
                        <option value="<?php echo $mfg['id']; ?>" <?php echo (isset($_POST['manufacturer_id']) && $_POST['manufacturer_id'] == $mfg['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($mfg['name']); ?>
                            <?php if (!empty($mfg['short_name'])): ?>
                                (<?php echo htmlspecialchars($mfg['short_name']); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size: 0.9em; color: #666; font-style: italic; margin-top: 3px;">
                    Vendor name and initial location will be auto-filled from manufacturer
                </div>
                
                <!-- NEW: Project Assignment Dropdown -->
                <label for="project_id">Assign to Project (Optional):</label>
                <select name="project_id" id="project_id">
                     <option value="">-- None (Unassigned Stock) --</option>
                     <?php foreach ($projects_for_account as $proj): ?>
                          <option value="<?php echo $proj['id']; ?>" 
                                  <?php if ($role === 'global_admin') echo ' data-account-id="' . $proj['account_id'] . '"'; // Add data attribute for filtering ?>
                                  <?php echo (isset($_POST['project_id']) && $_POST['project_id'] == $proj['id']) ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($proj['project_name']); ?>
                              <?php if ($role === 'global_admin') echo ' (Account ID: ' . $proj['account_id'] . ')'; // Show account ID for clarity ?>
                          </option>
                     <?php endforeach; ?>
                </select>

                <div class="section-title">Module Wattage and Quantities</div>
                <label style="display:flex; align-items:center; gap:8px; margin-top: 8px; font-weight:500;">
                    <input type="checkbox" id="track_domestic_content" name="track_domestic_content" value="1" <?php echo !empty($_POST['track_domestic_content']) ? 'checked' : ''; ?>>
                    Track Domestic Content %
                </label>
                <div id="wattage-container">
                     <!-- Dynamically added fields go here -->
                     <?php
                     // Re-populate wattage fields on error
                     if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['wattages'])) {
                          $showDomestic = !empty($_POST['track_domestic_content']);
                          for ($i = 0; $i < count($_POST['wattages']); $i++) {
                               $w_val = htmlspecialchars($_POST['wattages'][$i] ?? '');
                               $q_val = htmlspecialchars($_POST['quantities'][$i] ?? '');
                               $d_val = htmlspecialchars($_POST['domestic_content_pcts'][$i] ?? '');
                               echo '<div class="wattage-entry">';
                               echo '<div><label>Wattage:</label><input type="number" step="1" name="wattages[]" value="' . $w_val . '" required></div>';
                               echo '<div><label>Quantity:</label><input type="number" step="1" name="quantities[]" value="' . $q_val . '" min="1" required></div>';
                               echo '<div class="domestic-content-group" style="' . ($showDomestic ? '' : 'display:none;') . '"><label>Domestic Content %</label><input type="number" step="0.01" min="0" max="100" name="domestic_content_pcts[]" value="' . $d_val . '" ' . ($showDomestic ? 'required' : '') . '></div>';
                               echo '<div class="remove-btn-container"><button type="button" class="remove-wattage-btn" onclick="this.closest(\'.wattage-entry\').remove()">Remove</button></div>';
                               echo '</div>';
                          }
                     }
                     ?>
                </div>
                <button type="button" class="btn-add-wattage" onclick="addWattageField()">+ Add Wattage/Quantity</button>
        
                <input type="submit" value="Add Batch" class="btn-submit">
            </form>
        </div>
    </div>

    <!-- Project-Assigned Modules Table -->
    <div class="section-header">
        <h2>Project-Assigned Module Batches</h2>
    </div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Batch</th>
                    <th>Vendor & Location</th>
                    <th>Assigned Project</th>
                    <th>Module Details</th>
                    <th>Palletization Status</th>
                    <?php if ($isAdmin): ?><th style="width: 60px;"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($projectModulesData)): ?>
                    <?php foreach ($projectModulesData as $batch): ?>
                        <tr class="clickable-row" data-href="module_overview.php?batch_id=<?php echo $batch['id']; ?>" onclick="window.location='module_overview.php?batch_id=<?php echo $batch['id']; ?>'">
                            <td>
                                <div class="batch-name"><?php echo !empty($batch['batch_name']) ? htmlspecialchars($batch['batch_name']) : 'Batch #' . $batch['id']; ?></div>
                                <div class="batch-vendor"><?php echo htmlspecialchars($batch['vendor_name']); ?></div>
                                <div class="module-info"><?php echo htmlspecialchars($batch['account_name']); ?></div>
                            </td>
                            <td class="module-info">
                                <strong><?php echo htmlspecialchars($batch['vendor_name']); ?></strong><br>
                                <?php echo htmlspecialchars($batch['initial_location']); ?>
                            </td>
                            <td>
                                <div class="assignment-status assigned">
                                    <?php echo htmlspecialchars($batch['project_name'] ?? 'Error: Missing project name'); ?>
                                </div>
                            </td>
                            <td>
                                <div class="quantity-display">
                                    <?php echo number_format($batch['total_quantity']); ?> total
                                </div>
                                <div style="font-size: 0.85em; color: #666;">
                                    <?php
                                        $details = [];
                                        if (!empty($batch['items'])) {
                                            foreach ($batch['items'] as $item) {
                                                 $detail = htmlspecialchars((int)$item['wattage']) . 'W: ' . number_format((int)$item['quantity']);
                                                 if (isset($item['domestic_content_pct']) && $item['domestic_content_pct'] !== null && $item['domestic_content_pct'] !== '') {
                                                     $detail .= ' (' . number_format((float)$item['domestic_content_pct'], 2) . '% domestic)';
                                                 }
                                                 $details[] = $detail;
                                            }
                                        }
                                        echo implode(', ', $details);
                                    ?>
                                </div>
                            </td>
                            <td>
                                <div class="palletization-status <?php
                                    echo strtolower(str_replace(' ', '-', $batch['palletization_status']));
                                ?>">
                                    <?php echo htmlspecialchars($batch['palletization_status']); ?>
                                </div>
                                <div class="palletization-details">
                                    <?php echo number_format($batch['palletization_percentage'], 1); ?>%
                                    (<?php echo number_format($batch['total_palletized']); ?>/<?php echo number_format($batch['total_quantity']); ?>)
                                </div>
                            </td>
                            <?php if ($isAdmin): ?>
                            <td class="actions-cell" onclick="event.stopPropagation();">
                                <div class="dropdown">
                                    <button class="kebab-trigger" onclick="toggleDropdown(event, 'dropdown-menu-p<?php echo $batch['id']; ?>')" title="More actions">&#8942;</button>
                                    <div id="dropdown-menu-p<?php echo $batch['id']; ?>" class="dropdown-menu">
                                        <a href="module_overview.php?batch_id=<?php echo (int)$batch['id']; ?>" class="dropdown-item">View</a>
                                        <a href="edit_module_batch.php?project_id=<?php echo (int)$batch['project_id']; ?>&batch_id=<?php echo (int)$batch['id']; ?>" class="dropdown-item edit">Edit</a>
                                        <form action="delete_module_batch.php" method="POST" style="display:inline; margin: 0;" onsubmit="return confirm('Are you sure you want to delete this entire batch permanently?');">
                                            <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <button type="submit" class="dropdown-item delete" style="border: none; cursor: pointer; background: none; width: 100%; text-align: left;">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="<?php echo $isAdmin ? 6 : 5; ?>">
                            <div class="empty-state-content">
                                <span class="empty-icon">📦</span>
                                <p>No project-assigned module batches found<?php echo (in_array($role, ['admin', 'customer_admin'], true) && !$account_id_for_admin) ? ' for your assigned account' : ''; ?><?php if ($isAdmin): ?>. <a href="add_module_batch.php">Add the first batch</a><?php endif; ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Unassigned Modules Table -->
    <div class="section-header" style="margin-top: 40px;">
        <h2>Unassigned (Stock) Module Batches</h2>
        <?php if ($isAdmin): ?>
            <div class="section-header-actions">
                <button type="button" class="btn-bulk-assign" id="assignSelectedBatchesBtn" disabled>Assign Selected</button>
            </div>
        <?php endif; ?>
    </div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <?php if ($isAdmin): ?><th class="batch-select-cell"><input type="checkbox" id="selectAllUnassignedBatches" title="Select all unassigned batches"></th><?php endif; ?>
                    <th>Batch</th>
                    <th>Vendor & Location</th>
                    <th>Status</th>
                    <th>Module Details</th>
                    <th>Palletization Status</th>
                    <?php if ($isAdmin): ?><th style="width: 60px;"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($unassignedModulesData)): ?>
                    <?php foreach ($unassignedModulesData as $batch): ?>
                        <tr class="clickable-row" data-href="module_overview.php?batch_id=<?php echo $batch['id']; ?>" onclick="if (event.target.closest('a, button, input, .dropdown')) { return; } window.location='module_overview.php?batch_id=<?php echo $batch['id']; ?>';">
                            <?php if ($isAdmin): ?>
                            <td class="batch-select-cell" onclick="event.stopPropagation();">
                                <input
                                    type="checkbox"
                                    class="unassigned-batch-checkbox"
                                    value="<?php echo (int)$batch['id']; ?>"
                                    data-batch-id="<?php echo (int)$batch['id']; ?>"
                                    data-account-id="<?php echo (int)($batch['account_id'] ?? 0); ?>"
                                    data-total-quantity="<?php echo (int)($batch['total_quantity'] ?? 0); ?>"
                                    data-total-watts="<?php echo (float)($batch['total_watts'] ?? 0); ?>"
                                    data-batch-label="<?php echo htmlspecialchars(!empty($batch['batch_name']) ? $batch['batch_name'] : ('Batch #' . (int)$batch['id']), ENT_QUOTES); ?>"
                                    title="Select batch"
                                >
                            </td>
                            <?php endif; ?>
                            <td>
                                <div class="batch-name"><?php echo !empty($batch['batch_name']) ? htmlspecialchars($batch['batch_name']) : 'Batch #' . $batch['id']; ?></div>
                                <div class="batch-vendor"><?php echo htmlspecialchars($batch['vendor_name']); ?></div>
                                <div class="module-info"><?php echo htmlspecialchars($batch['account_name']); ?></div>
                            </td>
                            <td class="module-info">
                                <strong><?php echo htmlspecialchars($batch['vendor_name']); ?></strong><br>
                                <?php echo htmlspecialchars($batch['initial_location']); ?>
                            </td>
                            <td>
                                <div class="assignment-status unassigned">
                                    Unassigned Stock
                                </div>
                            </td>
                            <td>
                                <div class="quantity-display">
                                    <?php echo number_format($batch['total_quantity']); ?> total
                                </div>
                                <div style="font-size: 0.85em; color: #666;">
                                    <?php
                                        $details = [];
                                        if (!empty($batch['items'])) {
                                            foreach ($batch['items'] as $item) {
                                                 $detail = htmlspecialchars((int)$item['wattage']) . 'W: ' . number_format((int)$item['quantity']);
                                                 if (isset($item['domestic_content_pct']) && $item['domestic_content_pct'] !== null && $item['domestic_content_pct'] !== '') {
                                                     $detail .= ' (' . number_format((float)$item['domestic_content_pct'], 2) . '% domestic)';
                                                 }
                                                 $details[] = $detail;
                                            }
                                        }
                                        echo implode(', ', $details);
                                    ?>
                                </div>
                            </td>
                            <td>
                                <div class="palletization-status <?php
                                    echo strtolower(str_replace(' ', '-', $batch['palletization_status']));
                                ?>">
                                    <?php echo htmlspecialchars($batch['palletization_status']); ?>
                                </div>
                                <div class="palletization-details">
                                    <?php echo number_format($batch['palletization_percentage'], 1); ?>%
                                    (<?php echo number_format($batch['total_palletized']); ?>/<?php echo number_format($batch['total_quantity']); ?>)
                                </div>
                            </td>
                            <?php if ($isAdmin): ?>
                            <td class="actions-cell" onclick="event.stopPropagation();">
                                <div class="dropdown">
                                    <button class="kebab-trigger" onclick="toggleDropdown(event, 'dropdown-menu-u<?php echo $batch['id']; ?>')" title="More actions">&#8942;</button>
                                    <div id="dropdown-menu-u<?php echo $batch['id']; ?>" class="dropdown-menu">
                                        <a href="module_overview.php?batch_id=<?php echo (int)$batch['id']; ?>" class="dropdown-item">View</a>
                                        <a href="edit_module_batch.php?batch_id=<?php echo (int)$batch['id']; ?>" class="dropdown-item edit">Edit</a>
                                        <button
                                            type="button"
                                            class="dropdown-item edit"
                                            style="border: none; cursor: pointer; background: none; width: 100%; text-align: left;"
                                            onclick="openAssignBatchModal(event, <?php echo (int)$batch['id']; ?>, <?php echo (int)($batch['account_id'] ?? 0); ?>, <?php echo json_encode(!empty($batch['batch_name']) ? $batch['batch_name'] : ('Batch #' . (int)$batch['id'])); ?>, <?php echo (int)($batch['total_quantity'] ?? 0); ?>, <?php echo (float)($batch['total_watts'] ?? 0); ?>)"
                                        >
                                            Assign to Project
                                        </button>
                                        <form action="delete_module_batch.php" method="POST" style="display:inline; margin: 0;" onsubmit="return confirm('Are you sure you want to delete this entire batch permanently?');">
                                            <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <button type="submit" class="dropdown-item delete" style="border: none; cursor: pointer; background: none; width: 100%; text-align: left;">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="<?php echo $isAdmin ? 7 : 5; ?>">
                            <div class="empty-state-content">
                                <span class="empty-icon">📦</span>
                                <p>No unassigned module batches found<?php echo (in_array($role, ['admin', 'customer_admin'], true) && !$account_id_for_admin) ? ' for your assigned account' : ''; ?><?php if ($isAdmin): ?>. <a href="add_module_batch.php">Add the first batch</a><?php endif; ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($isAdmin): ?>
    <div id="assignBatchModal" class="modal">
        <div class="modal-content">
            <span id="closeAssignBatchModal" class="close-button">&times;</span>
            <h2>Assign Unassigned Batch</h2>
            <form method="POST" id="assignBatchForm">
                <input type="hidden" name="action" value="assign_batch" id="assign_action">
                <input type="hidden" name="batch_id" id="assign_batch_id">
                <input type="hidden" name="batch_ids" id="assign_batch_ids">

                <p id="assignBatchHelp" style="margin-top: 0; color: #6c757d;"></p>

                <label for="assign_project_id">Assign to Project:</label>
                <select name="assign_project_id" id="assign_project_id" required>
                    <option value="">--Select Project--</option>
                    <?php foreach ($projects_for_account as $proj): ?>
                        <option
                            value="<?php echo (int)$proj['id']; ?>"
                            <?php if ($role === 'global_admin') echo ' data-account-id="' . (int)$proj['account_id'] . '"'; ?>
                        >
                            <?php echo htmlspecialchars($proj['project_name']); ?>
                            <?php if ($role === 'global_admin') echo ' (Account ID: ' . (int)$proj['account_id'] . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div id="assignImpactPanel" class="assign-impact-panel" style="display:none;">
                    <div class="assign-impact-title">Assignment Impact</div>
                    <div class="assign-impact-grid">
                        <div class="k">Project Target</div><div class="v" id="impact_target">-</div>
                        <div class="k">Currently Assigned</div><div class="v" id="impact_current">-</div>
                        <div class="k">Selected to Assign</div><div class="v" id="impact_selected">-</div>
                        <div class="k">Projected Total</div><div class="v" id="impact_projected">-</div>
                    </div>
                    <div class="assign-impact-note" id="impact_note">Select a project to see the impact.</div>
                </div>

                <button type="submit" class="btn-submit" id="assignBatchSubmitBtn">Assign Batch</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

</main>
</body>
</html> 
