<?php
session_name("logistics_session");
session_start();

// Allow access for admin, global_admin, customer_admin, and user roles.
// Specific functionalities will be controlled by role checks within the page.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin', 'user'])) {
    header("Location: unauthorized");
    exit();
}
// Database connection
require_once '../config.php';
$conn = getDBConnection(); // Keep connection open initially
if (!$conn) {
    die("Database connection failed.");
}

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// --- Account and Project Fetching Logic ---
$account_id_for_admin = null;
$account_id_for_user  = null;
$accounts             = []; // For global_admin account dropdown
$projects_for_account = []; // For project assignment dropdown

if ($role === 'admin' || $role === 'global_admin') {
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
    $sqlAllProj = "SELECT id, project_name, account_id FROM projects ORDER BY account_id, project_name ASC";
    $resAllProj = $conn->query($sqlAllProj);
    if ($resAllProj && $resAllProj->num_rows > 0) {
        while ($proj = $resAllProj->fetch_assoc()) {
            $projects_for_account[] = $proj;
        }
    }
} elseif ($role === 'admin' && $account_id_for_admin) {
    // Admins see projects for their assigned account in dropdown
    $sqlAdminProjs = "SELECT id, project_name, account_id FROM projects WHERE account_id = ? ORDER BY project_name ASC";
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
        $sqlUserProj = "SELECT id, project_name, account_id FROM projects WHERE account_id = ? ORDER BY project_name ASC";
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
if ($role === 'admin' && $account_id_for_admin && !empty($projects_for_account)) {
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

// --- Handle POST for adding new modules --- 
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
            $stmt = $conn->prepare("INSERT INTO modules (account_id, vendor_name, initial_location, project_id) VALUES (?, ?, ?, ?)");
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
    
                if (count($wattages) !== count($quantities)) throw new Exception("Mismatch between wattage[] and quantities[] arrays.");
                
                $stmt2 = $conn->prepare("INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)");
                if (!$stmt2) throw new Exception("Error preparing item insert: " . $conn->error);
                
                for ($i=0; $i<count($wattages); $i++) {
                    $w = intval($wattages[$i]);
                    $q = intval($quantities[$i]);
    
                    if ($w <= 0 || $q <= 0) throw new Exception("Wattage and Quantity must be positive integers for all entries.");
                    
                    $stmt2->bind_param("iii", $unassigned_module_id, $w, $q);
                    if (!$stmt2->execute()) throw new Exception("Error inserting wattage/quantity item: " . $stmt2->error);
                    $wattage_items_added++;
                }
                $stmt2->close();
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

// --- Fetch Modules Data for Table Display ---
$modulesData = [];
if ($conn) { // Check connection is still valid
    $sqlModules        = "";
    $paramTypesModules = "";
    $paramsModules     = [];
    
    if ($role === 'global_admin') {
        $sqlModules = "SELECT 
                         um.id, um.vendor_name, um.initial_location, um.project_id,
                         c.name AS account_name,
                         p.project_name 
                       FROM modules um 
                       JOIN customer_accounts c ON um.account_id = c.id
                       LEFT JOIN projects p ON um.project_id = p.id
                       ORDER BY c.name ASC, um.vendor_name ASC";
    } elseif ($role === 'admin' && !empty($account_id_for_admin)) {
         $sqlModules = "SELECT 
                          um.id, um.vendor_name, um.initial_location, um.project_id,
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
                          um.id, um.vendor_name, um.initial_location, um.project_id,
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
        $stmtItems = $conn->prepare("SELECT wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ?");
        if (!$stmtItems) {
            $errorMessage .= " Error preparing item query: " . $conn->error;
        } else {
            while ($batch = $resultModules->fetch_assoc()) {
                $batch_id = $batch['id'];
                $batch['items'] = [];
                $batch['total_quantity'] = 0;
            
                $stmtItems->bind_param("i", $batch_id);
                $stmtItems->execute();
                $resultItems = $stmtItems->get_result();
                while ($item = $resultItems->fetch_assoc()) {
                    $batch['items'][] = $item;
                    $batch['total_quantity'] += $item['quantity'];
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

        /* Legacy button classes for backwards compatibility */
        .action-buttons.add-new {
            display: inline-block;
            padding: 8px 15px;
            background-color: #488C9A;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
        }
        .action-buttons.add-new:hover {
            background-color: #293E4C;
        }
        .action-buttons.edit {
            background-color: #488C9A;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .action-buttons.edit:hover {
            background-color: #293E4C;
        }
        .action-buttons.delete {
            background-color: #dc3545;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .action-buttons.delete:hover {
            background-color: #c82333;
        }
        .action-buttons.view {
            background-color: #488C9A;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .action-buttons.view:hover {
            background-color: #293E4C;
        }
        .action-buttons.warehouse {
            background-color: #fd7e14;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .action-buttons.warehouse:hover {
            background-color: #e8690b;
        }
        .action-buttons.project {
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .action-buttons.project:hover {
            background-color: #293E4C;
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
        .dropdown-toggle {
            background: none;
            border: none;
            color: #488C9A;
            padding: 4px;
            cursor: pointer;
            font-size: 1.1em;
            border-radius: 3px;
            transition: all 0.2s ease;
        }
        .dropdown-toggle:hover {
            background-color: #f8f9fa;
            color: #293E4C;
        }
        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            min-width: 120px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            border-radius: 4px;
            z-index: 1000;
            border: 1px solid #ddd;
        }
        .dropdown-menu.show {
            display: block;
        }
        .dropdown-item {
            display: block;
            width: 100%;
            padding: 8px 12px;
            text-decoration: none;
            color: #333;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            font-size: 0.9em;
        }
        .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        .dropdown-item.edit {
            color: #488C9A;
        }
        .dropdown-item.delete {
            color: #dc3545;
        }
        .dropdown-item:first-child {
            border-radius: 4px 4px 0 0;
        }
        .dropdown-item:last-child {
            border-radius: 0 0 4px 4px;
        }
        
        /* Actions cell styling */
        .actions-cell {
            position: relative;
        }
        .actions-cell .dropdown {
            position: absolute;
            top: 4px;
            right: 4px;
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
    </style>
    <script>
        // Wattage field adder - remains the same
        function addWattageField() {
            var container = document.getElementById('wattage-container');
            var index = container.children.length;

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
            div.appendChild(removeBtnDiv);

            container.appendChild(div);
        }

        // Modal handling will be added after DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('addModuleModal');
            var btn = document.getElementById('openAddModalBtn');
            var span = modal.querySelector('.close-button');

            if(btn) { btn.onclick = function() { modal.style.display = 'block'; } }
            if(span) { span.onclick = function() { modal.style.display = 'none'; } }
            
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            }
            
            // If there was a POST error, re-open the modal to show the error message within context
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errorMessage)): ?>
            modal.style.display = 'block';
            <?php endif; ?>

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
            
            // Dropdown functionality
            window.toggleDropdown = function(event, dropdownId) {
                event.stopPropagation();
                
                // Close all other dropdowns
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    if (menu.id !== dropdownId) {
                        menu.classList.remove('show');
                    }
                });
                
                // Toggle the clicked dropdown
                const dropdown = document.getElementById(dropdownId);
                dropdown.classList.toggle('show');
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
            });
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
            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
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
                <div id="wattage-container">
                     <!-- Dynamically added fields go here -->
                     <?php
                     // Re-populate wattage fields on error
                     if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['wattages'])) {
                          for ($i = 0; $i < count($_POST['wattages']); $i++) {
                               $w_val = htmlspecialchars($_POST['wattages'][$i] ?? '');
                               $q_val = htmlspecialchars($_POST['quantities'][$i] ?? '');
                               echo '<div class="wattage-entry">';
                               echo '<div><label>Wattage:</label><input type="number" step="1" name="wattages[]" value="' . $w_val . '" required></div>';
                               echo '<div><label>Quantity:</label><input type="number" step="1" name="quantities[]" value="' . $q_val . '" min="1" required></div>';
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
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($projectModulesData)): ?>
                    <?php foreach ($projectModulesData as $batch): ?>
                        <tr>
                            <td>
                                <strong>Batch #<?php echo $batch['id']; ?></strong>
                                <div class="module-info"><?php echo htmlspecialchars($batch['account_name']); ?></div>
                            </td>
                            <td class="module-info">
                                <strong><?php echo htmlspecialchars($batch['vendor_name']); ?></strong><br>
                                📍 <?php echo htmlspecialchars($batch['initial_location']); ?>
                            </td>
                            <td>
                                <div class="assignment-status assigned">
                                    ✓ <?php echo htmlspecialchars($batch['project_name'] ?? 'Error: Missing project name'); ?>
                                </div>
                            </td>
                            <td>
                                <div class="quantity-display">
                                    📦 <?php echo number_format($batch['total_quantity']); ?> total
                                </div>
                                <div style="font-size: 0.85em; color: #666;">
                                    <?php
                                        $details = [];
                                        if (!empty($batch['items'])) {
                                            foreach ($batch['items'] as $item) {
                                                 $details[] = htmlspecialchars((int)$item['wattage']) . 'W: ' . number_format((int)$item['quantity']);
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
                            <td class="actions-cell">
                                <a href="module_overview.php?batch_id=<?php echo $batch['id']; ?>" class="action-buttons view">View Details</a>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                <div class="dropdown" style="display: inline-block;">
                                    <button class="dropdown-toggle" onclick="toggleDropdown(event, 'dropdown-menu-p<?php echo $batch['id']; ?>')" title="More actions">✏️</button>
                                    <div id="dropdown-menu-p<?php echo $batch['id']; ?>" class="dropdown-menu">
                                        <a href="edit_module_batch.php?project_id=<?php echo (int)$batch['project_id']; ?>&batch_id=<?php echo (int)$batch['id']; ?>" class="dropdown-item edit">Edit</a>
                                        <form action="delete_module_batch.php" method="POST" style="display:inline; margin: 0;" onsubmit="return confirm('Are you sure you want to delete this entire batch permanently?');">
                                            <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                            <button type="submit" class="dropdown-item delete" style="border: none; cursor: pointer; background: none; width: 100%; text-align: left;">Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'user' && !empty($batch['project_id'])): ?>
                                <a href="project_overview.php?project_id=<?php echo $batch['project_id']; ?>" class="action-buttons project">Project Overview</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="6">
                            <div class="empty-state-content">
                                <span class="empty-icon">📦</span>
                                <p>No project-assigned module batches found<?php echo ($role==='admin' && !$account_id_for_admin) ? ' for your assigned account' : ''; ?><?php if (in_array($role, ['admin', 'global_admin'])): ?>. <a href="add_module_batch.php">Add the first batch</a><?php endif; ?></p>
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
    </div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Batch</th>
                    <th>Vendor & Location</th>
                    <th>Status</th>
                    <th>Module Details</th>
                    <th>Palletization Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($unassignedModulesData)): ?>
                    <?php foreach ($unassignedModulesData as $batch): ?>
                        <tr>
                            <td>
                                <strong>Batch #<?php echo $batch['id']; ?></strong>
                                <div class="module-info"><?php echo htmlspecialchars($batch['account_name']); ?></div>
                            </td>
                            <td class="module-info">
                                <strong><?php echo htmlspecialchars($batch['vendor_name']); ?></strong><br>
                                📍 <?php echo htmlspecialchars($batch['initial_location']); ?>
                            </td>
                            <td>
                                <div class="assignment-status unassigned">
                                    ⚪ Unassigned Stock
                                </div>
                            </td>
                            <td>
                                <div class="quantity-display">
                                    📦 <?php echo number_format($batch['total_quantity']); ?> total
                                </div>
                                <div style="font-size: 0.85em; color: #666;">
                                    <?php
                                        $details = [];
                                        if (!empty($batch['items'])) {
                                            foreach ($batch['items'] as $item) {
                                                 $details[] = htmlspecialchars((int)$item['wattage']) . 'W: ' . number_format((int)$item['quantity']);
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
                            <td class="actions-cell">
                                <a href="module_overview.php?batch_id=<?php echo $batch['id']; ?>" class="action-buttons view">View Details</a>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                <div class="dropdown" style="display: inline-block;">
                                    <button class="dropdown-toggle" onclick="toggleDropdown(event, 'dropdown-menu-u<?php echo $batch['id']; ?>')" title="More actions">✏️</button>
                                    <div id="dropdown-menu-u<?php echo $batch['id']; ?>" class="dropdown-menu">
                                        <a href="edit_module_batch.php?batch_id=<?php echo (int)$batch['id']; ?>" class="dropdown-item edit">Edit</a>
                                        <form action="delete_module_batch.php" method="POST" style="display:inline; margin: 0;" onsubmit="return confirm('Are you sure you want to delete this entire batch permanently?');">
                                            <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                            <button type="submit" class="dropdown-item delete" style="border: none; cursor: pointer; background: none; width: 100%; text-align: left;">Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'user'): ?>
                                <a href="warehouse_info.php?module_batch_id=<?php echo $batch['id']; ?>" class="action-buttons warehouse">Warehouse</a>
                                <a href="view_project.php?origin_batch_id=<?php echo $batch['id']; ?>" class="action-buttons view">Deliveries</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="6">
                            <div class="empty-state-content">
                                <span class="empty-icon">📦</span>
                                <p>No unassigned module batches found<?php echo ($role==='admin' && !$account_id_for_admin) ? ' for your assigned account' : ''; ?><?php if (in_array($role, ['admin', 'global_admin'])): ?>. <a href="add_module_batch.php">Add the first batch</a><?php endif; ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>
</body>
</html> 
