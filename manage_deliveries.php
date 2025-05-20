<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'global_admin' && $_SESSION['role'] != 'admin')) {
    header("Location: unauthorized");
    exit();
}

// NEW: Get selected project filter. Can be 'all', 'unassigned', or a specific project ID.
$filter_project_id = isset($_GET['filter_project_id']) ? $_GET['filter_project_id'] : 'all'; // Default to 'all'

// OLD project_id from URL is now used to pre-select the filter if `filter_project_id` isn't set
if (isset($_GET['project_id']) && empty($_GET['filter_project_id'])) {
    $filter_project_id = intval($_GET['project_id']);
}

// Set up time filter variables (if not passed, use defaults)
$time_filter   = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date      = isset($_GET['ref_date']) ? $_GET['ref_date'] : date('Y-m-d');
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// --- NEW: Admin Account Fetching and Permission Setup ---
$account_id_for_admin = null;
$is_global_admin = ($_SESSION['role'] === 'global_admin');

if (!$is_global_admin) { // User role is 'admin'
    $stmtAdminAcc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1");
    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $_SESSION['user_id']);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($acctID);
        if ($stmtAdminAcc->fetch()) {
            $account_id_for_admin = $acctID;
        }
        $stmtAdminAcc->close();
    }
    if (!$account_id_for_admin) {
        // Optional: Handle admin with no assigned account - perhaps redirect or show error
        // For now, they won't see any projects or deliveries if $account_id_for_admin remains null.
         $_SESSION['messages'][] = "<p class='error-message'>Error: Admin user is not associated with an account.</p>";
        // Consider adding exit() here if needed
    }
    // If admin and filter is 'all', reset filter to prevent access issues (or choose a default)
    if ($filter_project_id === 'all') {
        $filter_project_id = 'unassigned'; // Default admin view to unassigned if 'all' was somehow selected
    }
}
// --- END NEW --- 

// Fetch all projects for the filter dropdown
$all_projects_for_filter = [];
// --- MODIFIED: Filter projects based on role --- 
if ($is_global_admin) {
    $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
} else { // Admin role
    if ($account_id_for_admin) {
        // Only fetch projects for this admin's account
        $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
        $stmt_all_proj->bind_param("i", $account_id_for_admin);
    } else {
        // Prepare a statement that returns nothing if admin has no account
        $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects WHERE 1=0"); 
    }
}
// --- END MODIFIED --- 
if ($stmt_all_proj) {
    $stmt_all_proj->execute();
    $result_all_proj = $stmt_all_proj->get_result();
    while ($proj_row = $result_all_proj->fetch_assoc()) {
        $all_projects_for_filter[] = $proj_row;
    }
    $stmt_all_proj->close();
}

// Initialize messages
if (!isset($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

$project_name = "All Projects"; // Default page title
$default_freight_cost = 0; // Default if no specific project is selected

if (is_numeric($filter_project_id)) {
    // Fetch the specific project name and default freight cost
    $stmt_proj_details = $conn->prepare("SELECT project_name, default_freight_cost FROM projects WHERE id = ?");
    if ($stmt_proj_details) {
        $stmt_proj_details->bind_param("i", $filter_project_id);
        $stmt_proj_details->execute();
        $stmt_proj_details->bind_result($project_name_specific, $default_freight_cost_specific);
        if ($stmt_proj_details->fetch()) {
            $project_name = $project_name_specific;
            $default_freight_cost = $default_freight_cost_specific ?: 0;
        }
        $stmt_proj_details->close();
    }
} elseif ($filter_project_id === 'unassigned') {
    $project_name = "Unassigned Deliveries";
}

/*
  -----------------------------------------------------------------------------
  1) Handle Bulk Edit
  -----------------------------------------------------------------------------
*/
if (isset($_POST['bulk_edit_submit'])) {
    if (isset($_POST['selected_deliveries']) && !empty($_POST['selected_deliveries'])) {
        $selected_ids = array_map('intval', $_POST['selected_deliveries']);

        // --- NEW: Security check for admin role ---
        if (!$is_global_admin && $account_id_for_admin) {
            $valid_ids_for_admin = [];
            $id_placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $check_stmt = $conn->prepare(
                "SELECT d.id FROM deliveries d JOIN projects p ON d.project_id = p.id WHERE d.id IN ($id_placeholders) AND p.account_id = ?"
            );
            $check_types = str_repeat('i', count($selected_ids)) . 'i';
            $check_params = array_merge($selected_ids, [$account_id_for_admin]);
            $check_stmt->bind_param($check_types, ...$check_params);
            $check_stmt->execute();
            $result_check = $check_stmt->get_result();
            while ($row = $result_check->fetch_assoc()) {
                $valid_ids_for_admin[] = $row['id'];
            }
            $check_stmt->close();

            // Filter out any IDs that don't belong to the admin or are unassigned (unassigned handled separately or not allowed for bulk edit by admin)
            $selected_ids = array_intersect($selected_ids, $valid_ids_for_admin);

            if (empty($selected_ids)) {
                $_SESSION['messages'][] = "<p class='error-message'>Bulk edit failed: No valid deliveries selected for your account.</p>";
                header("Location: manage_deliveries?" . $_SERVER['QUERY_STRING']); // Redirect with original query params
                exit();
            }
        }
        // --- END NEW ---

        // Build dynamic SET clauses based on non-empty fields
        $updates = [];
        $types   = '';
        $values  = [];

        // List of possible fields to update
        $fields_to_update = [
            'supplier'                => 's',
            'wattage'                 => 's',
            'status_of_delivery'      => 's',
            'quantity'                => 'i',
            'bol_number'              => 's',
            'anticipated_delivery_date' => 's',
            'warehouse_arrival_date'  => 's',
            'actual_delivery_date'    => 's',
            'miles'                   => 'd',
            'freight_cost'            => 'd',
            'accessorial_costs'       => 'd'
        ];

        // For each field, if user has provided a value, add to the update set
        foreach ($fields_to_update as $field => $bindType) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $updates[] = "$field = ?";
                $types    .= $bindType;

                // Convert to proper type if needed
                if ($bindType == 'i') {
                    $values[] = (int)$_POST[$field];
                } elseif ($bindType == 'd') {
                    $values[] = (float)$_POST[$field];
                } else {
                    $values[] = $_POST[$field];
                }
            }
        }

        if (!empty($updates)) {
            // Build the UPDATE query with placeholders for each selected ID
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $sql = "UPDATE deliveries SET " . implode(", ", $updates) . " WHERE id IN ($placeholders)";

            $stmt = $conn->prepare($sql);
            // Add 'i' for each selected ID to bind their values
            $types .= str_repeat('i', count($selected_ids));

            // Merge $values with the list of selected IDs
            foreach ($selected_ids as $id) {
                $values[] = $id;
            }

            $stmt->bind_param($types, ...$values);
            if ($stmt->execute()) {
                $_SESSION['messages'][] = "<p>Bulk update successful.</p>";
            } else {
                $_SESSION['messages'][] = "<p>Error on bulk update: " . $stmt->error . "</p>";
            }
            $stmt->close();
        } else {
            $_SESSION['messages'][] = "<p>No fields were filled in for bulk update.</p>";
        }
    } else {
        $_SESSION['messages'][] = "<p>No deliveries selected for bulk edit.</p>";
    }

    // Redirect back
    $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id);
    header("Location: manage_deliveries?" . $redirect_params);
    exit();
}

/*
  -----------------------------------------------------------------------------
  2) Handle Bulk Delete
  -----------------------------------------------------------------------------
*/
if (isset($_POST['delete_selected'])) {
    if (isset($_POST['selected_deliveries']) && !empty($_POST['selected_deliveries'])) {
        $selected_ids   = array_map('intval', $_POST['selected_deliveries']);
        
        // --- Initialize delete parameters based on initially selected IDs ---
        $delete_params = $selected_ids;
        $delete_types = str_repeat('i', count($selected_ids));
        $ids_placeholder = implode(',', array_fill(0, count($delete_params), '?')); // Placeholder based on initial count

        // --- NEW: Security check for admin role for delete ---
        if (!$is_global_admin && $account_id_for_admin) {
             $valid_ids_for_delete = [];
             $id_placeholders_del = implode(',', array_fill(0, count($selected_ids), '?'));
             
             // Check project-assigned deliveries
             $sql_check_project_deliveries = "SELECT d.id FROM deliveries d JOIN projects p ON d.project_id = p.id WHERE d.id IN ($id_placeholders_del) AND p.account_id = ?";
             $stmt_check_proj = $conn->prepare($sql_check_project_deliveries);
             $types_check_proj = str_repeat('i', count($selected_ids)) . 'i';
             $params_check_proj = array_merge($selected_ids, [$account_id_for_admin]);
             $stmt_check_proj->bind_param($types_check_proj, ...$params_check_proj);
             $stmt_check_proj->execute();
             $res_check_proj = $stmt_check_proj->get_result();
             while($r = $res_check_proj->fetch_assoc()) { $valid_ids_for_delete[] = $r['id']; }
             $stmt_check_proj->close();

             // Check unassigned deliveries (admins can delete unassigned too if $filter_project_id === 'unassigned')
             if ($filter_project_id === 'unassigned') {
                 $sql_check_unassigned = "SELECT id FROM deliveries WHERE id IN ($id_placeholders_del) AND project_id IS NULL";
                 $stmt_check_unassigned = $conn->prepare($sql_check_unassigned);
                 $types_check_unassigned = str_repeat('i', count($selected_ids));
                 $stmt_check_unassigned->bind_param($types_check_unassigned, ...$selected_ids);
                 $stmt_check_unassigned->execute();
                 $res_check_unassigned = $stmt_check_unassigned->get_result();
                 while($r = $res_check_unassigned->fetch_assoc()) { $valid_ids_for_delete[] = $r['id']; }
                 $stmt_check_unassigned->close();
             }

             $selected_ids = array_values(array_unique(array_intersect($selected_ids, $valid_ids_for_delete)));

             if (empty($selected_ids)) {
                 $_SESSION['messages'][] = "<p class='error-message'>Bulk delete failed: No valid deliveries selected for your account scope.</p>";
                 // Rebuild redirect_params for safety as it might not be set here
                 $redirect_params_del = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id);
                 header("Location: manage_deliveries?" . $redirect_params_del);
                 exit();
             }
             // Re-prepare delete parameters after filtering
             // --- IMPORTANT: Update params and types ONLY if IDs were filtered ---
             $delete_params = $selected_ids;
             $delete_types = str_repeat('i', count($selected_ids));
             $ids_placeholder = implode(',', array_fill(0, count($delete_params), '?')); // Regenerate placeholder
        }
        // --- END NEW ---

        // --- Apply project condition AFTER security check --- 
        $delete_project_condition = ''; // Initialize
        if (is_numeric($filter_project_id)) {
            $delete_project_condition = " AND project_id = ?";
            $delete_params[] = $filter_project_id; // Add to the (potentially filtered) params
            $delete_types .= 'i';
        } elseif ($filter_project_id === 'unassigned') {
            $delete_project_condition = " AND project_id IS NULL";
            // No additional param needed
        }
        // If 'all', no project condition, so it deletes from any project (dangerous, ensure this is intended or add further checks)

        $stmt = $conn->prepare("DELETE FROM deliveries WHERE id IN ($ids_placeholder) $delete_project_condition");
        $stmt->bind_param($delete_types, ...$delete_params);
        if ($stmt->execute()) {
            $_SESSION['messages'][] = "<p>Selected deliveries have been deleted.</p>";
        } else {
            $_SESSION['messages'][] = "<p>Error deleting deliveries: " . $stmt->error . "</p>";
        }
        $stmt->close();
    } else {
        $_SESSION['messages'][] = "<p>No deliveries selected for deletion.</p>";
    }

    $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id);
    header("Location: manage_deliveries?" . $redirect_params);
    exit();
}

/*
  -----------------------------------------------------------------------------
  3) Handle CSV Upload (now includes miles, freight_cost, accessorial_costs)
  -----------------------------------------------------------------------------
*/
if (isset($_POST['upload_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName    = $_FILES['csv_file']['name'];
        $fileSize    = $_FILES['csv_file']['size'];
        $fileType    = $_FILES['csv_file']['type'];

        $allowedFileTypes = ['text/csv', 'application/vnd.ms-excel'];
        $maxFileSize      = 2 * 1024 * 1024; // 2MB

        if (in_array($fileType, $allowedFileTypes) && $fileSize <= $maxFileSize) {
            $csvData = file_get_contents($fileTmpPath);
            $lines   = explode(PHP_EOL, $csvData);
            $header  = null;
            $data    = [];
            $importErrors = [];
            $insertedRows = 0;
            $updatedRows  = 0;

            foreach ($lines as $lineNumber => $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                $row = str_getcsv($line);

                // Skip empty lines
                $allFieldsEmpty = true;
                foreach ($row as $field) {
                    if (trim($field) !== '') {
                        $allFieldsEmpty = false;
                        break;
                    }
                }
                if ($allFieldsEmpty) {
                    continue;
                }

                // First non-empty row is the header
                if (!$header) {
                    $header = $row;
                } else {
                    // Validate column count
                    if (count($row) != count($header)) {
                        $rowNumber = $lineNumber + 1;
                        $importErrors[] = "Row $rowNumber: Column count does not match header.";
                        continue;
                    }
                    $data[] = array_combine($header, $row);
                }
            }

            // Process rows
            foreach ($data as $rowIndex => $rowData) {
                $rowNumber = $rowIndex + 2; // +2 because header is row 1, array index starts at 0

                // Required fields
                $requiredFields = ['supplier', 'wattage', 'status_of_delivery', 'quantity', 'bol_number', 'anticipated_delivery_date'];
                foreach ($requiredFields as $field) {
                    if (!isset($rowData[$field]) || trim($rowData[$field]) === '') {
                        $importErrors[] = "Row $rowNumber: Missing required field '$field'.";
                        continue 2;
                    }
                }

                // Gather required
                $supplier                = $rowData['supplier'];
                $wattage                 = $rowData['wattage'];
                $status_of_delivery      = $rowData['status_of_delivery'];
                $quantity                = intval($rowData['quantity']);
                $bol_number              = $rowData['bol_number'];
                $anticipated_delivery_date = $rowData['anticipated_delivery_date'];

                // Optional fields
                $warehouse_arrival_date  = !empty($rowData['warehouse_arrival_date']) ? $rowData['warehouse_arrival_date'] : null;
                $actual_delivery_date    = !empty($rowData['actual_delivery_date']) ? $rowData['actual_delivery_date'] : null;

                // New optional fields (miles, freight_cost, accessorial_costs)
                $miles = (isset($rowData['miles']) && trim($rowData['miles']) !== '') ? floatval($rowData['miles']) : null;
                $freight_cost = (isset($rowData['freight_cost']) && trim($rowData['freight_cost']) !== '') ? floatval($rowData['freight_cost']) : null;
                $accessorial_costs = (isset($rowData['accessorial_costs']) && trim($rowData['accessorial_costs']) !== '') ? floatval($rowData['accessorial_costs']) : null;

                // Check if this delivery (by BOL number) already exists FOR THE CURRENT PROJECT CONTEXT
                // If filter_project_id is 'all' or 'unassigned', this check might need adjustment or be less strict.
                // For now, assuming CSV upload is primarily for a specific project context.
                // If uploading for 'unassigned', project_id in DB should be NULL.
                // If uploading for 'all', this logic is complex and needs careful thought - perhaps disable CSV upload for 'all'.
                
                $current_upload_project_id_context = null;
                if (is_numeric($filter_project_id)) {
                    $current_upload_project_id_context = $filter_project_id;
                } // For 'unassigned', project_id is NULL. For 'all', it's tricky.

                if ($current_upload_project_id_context !== null) {
                    $stmt = $conn->prepare("SELECT id FROM deliveries WHERE project_id = ? AND bol_number = ?");
                    $stmt->bind_param("is", $current_upload_project_id_context, $bol_number);
                } else if ($filter_project_id === 'unassigned') {
                     $stmt = $conn->prepare("SELECT id FROM deliveries WHERE project_id IS NULL AND bol_number = ?");
                     $stmt->bind_param("s", $bol_number);
                } else {
                    // Potentially disallow CSV upload when "All Projects" is selected, or require a project_id column in CSV
                     $_SESSION['messages'][] = "<p>CSV Upload is not supported when 'All Projects' is selected without a project identifier in the CSV.</p>";
                     $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id);
                     header("Location: manage_deliveries?" . $redirect_params);
                     exit();
                }
                
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    // Update existing
                    $stmt->bind_result($existing_delivery_id);
                    $stmt->fetch();
                    $stmt->close();

                    $update_stmt = $conn->prepare("
                        UPDATE deliveries SET
                            supplier = ?,
                            wattage = ?,
                            status_of_delivery = ?,
                            quantity = ?,
                            anticipated_delivery_date = ?,
                            warehouse_arrival_date = ?,
                            actual_delivery_date = ?,
                            miles = ?,
                            freight_cost = ?,
                            accessorial_costs = ?
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param(
                        "sssisssdddi",
                        $supplier,
                        $wattage,
                        $status_of_delivery,
                        $quantity,
                        $anticipated_delivery_date,
                        $warehouse_arrival_date,
                        $actual_delivery_date,
                        $miles,
                        $freight_cost,
                        $accessorial_costs,
                        $existing_delivery_id
                    );

                    if ($update_stmt->execute()) {
                        $updatedRows++;
                    } else {
                        $importErrors[] = "Row $rowNumber: Database error during update - " . $update_stmt->error;
                    }
                    $update_stmt->close();

                } else {
                    // Insert new
                    $stmt->close();
                    $insert_stmt = $conn->prepare("
                        INSERT INTO deliveries
                        (project_id, supplier, wattage, status_of_delivery, quantity, bol_number, anticipated_delivery_date, warehouse_arrival_date, actual_delivery_date, miles, freight_cost, accessorial_costs)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    // Use $current_upload_project_id_context for project_id (which will be NULL if 'unassigned')
                    $insert_stmt->bind_param(
                        "isssissssddd",
                        $current_upload_project_id_context, 
                        $supplier,
                        $wattage,
                        $status_of_delivery,
                        $quantity,
                        $bol_number,
                        $anticipated_delivery_date,
                        $warehouse_arrival_date,
                        $actual_delivery_date,
                        $miles,
                        $freight_cost,
                        $accessorial_costs
                    );

                    if ($insert_stmt->execute()) {
                        $insertedRows++;
                    } else {
                        $importErrors[] = "Row $rowNumber: Database error during insert - " . $insert_stmt->error;
                    }
                    $insert_stmt->close();
                }
            }

            // Show errors if any
            if (!empty($importErrors)) {
                $errorMessages = "<div class='error-messages'>";
                $errorMessages .= "<h3>Some errors occurred during import:</h3>";
                $errorMessages .= "<ul>";
                foreach ($importErrors as $error) {
                    $errorMessages .= "<li>" . htmlspecialchars($error) . "</li>";
                }
                $errorMessages .= "</ul></div>";
                $_SESSION['messages'][] = $errorMessages;
            }

            $_SESSION['messages'][] = "<p>Successfully imported $insertedRows new entries and updated $updatedRows existing entries.</p>";
            $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id);
            header("Location: manage_deliveries?" . $redirect_params);
            exit();

        } else {
            $_SESSION['messages'][] = "<p>Invalid file type or file too large. Please upload a valid CSV file (max 2MB).</p>";
            $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id);
            header("Location: manage_deliveries?" . $redirect_params);
            exit();
        }
    } else {
        $_SESSION['messages'][] = "<p>Error uploading the file. Please try again.</p>";
        $redirect_params = "time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter) . "&filter_project_id=" . urlencode($filter_project_id);
        header("Location: manage_deliveries?" . $redirect_params);
        exit();
    }
}

/*
  -----------------------------------------------------------------------------
  4) Fetch Deliveries for Display
  -----------------------------------------------------------------------------
*/
$filterColumn = "COALESCE(d.actual_delivery_date, d.anticipated_delivery_date)";
$dateCondition = "";
$projectConditionSQL = ""; // New variable for project filtering condition
$paramTypes    = "d"; // for default_freight_cost (d) - will be prepended
$params        = [$default_freight_cost];

if (is_numeric($filter_project_id)) {
    $projectConditionSQL = " AND d.project_id = ?";
    // NEW: Add account check for admin
    if (!$is_global_admin && $account_id_for_admin) {
        $projectConditionSQL .= " AND p.account_id = ?";
        $paramTypes .= "i";
        $params[] = $account_id_for_admin;
    }
    $paramTypes .= "i";
    $params[] = $filter_project_id;
} elseif ($filter_project_id === 'unassigned') {
    $projectConditionSQL = " AND d.project_id IS NULL";
    // No parameter to add for IS NULL
} elseif ($filter_project_id === 'all' && $is_global_admin) {
    // No project condition for global admin viewing all
    $projectConditionSQL = ""; 
} else { 
    // Admin viewing their account's projects implicitly (or error state)
    // $projectConditionSQL should effectively filter by account_id if $filter_project_id isn't 'unassigned'
    if (!$is_global_admin && $account_id_for_admin) {
         $projectConditionSQL = " AND p.account_id = ?"; // Filter by account
         $paramTypes .= "i";
         $params[] = $account_id_for_admin;
    } else {
         $projectConditionSQL = " AND 1=0 "; // Prevent admin without account from seeing anything if logic reaches here unexpectedly
    }
}
// If $filter_project_id is 'all', $projectConditionSQL remains empty, showing all projects.

if ($time_filter === 'day') {
    $dateCondition .= " AND DATE($filterColumn) = ?";
    $paramTypes .= "s";
    $params[] = $ref_date;

    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($ref_date . " -1 day"));
    $next_date = date('Y-m-d', strtotime($ref_date . " +1 day"));
} elseif ($time_filter === 'week') {
    $timestamp   = strtotime($ref_date);
    $dayOfWeek   = date('w', $timestamp);
    $startOfWeek = date('Y-m-d', strtotime("-{$dayOfWeek} days", $timestamp));
    $endOfWeek   = date('Y-m-d', strtotime("+" . (6 - $dayOfWeek) . " days", $timestamp));

    $dateCondition .= " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    $params[] = $startOfWeek;
    $params[] = $endOfWeek;

    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime($startOfWeek . " -7 days"));
    $next_date = date('Y-m-d', strtotime($startOfWeek . " +7 days"));
} elseif ($time_filter === 'month') {
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth   = date('Y-m-t', strtotime($ref_date));

    $dateCondition .= " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    $params[] = $startOfMonth;
    $params[] = $endOfMonth;

    $dateLabel = date('F Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($startOfMonth . " -1 month"));
    $next_date = date('Y-m-d', strtotime($startOfMonth . " +1 month"));
} else {
    $dateLabel = "All Deliveries";
    $prev_date = $ref_date;
    $next_date = $ref_date;
}

if (!empty($status_filter)) {
    $statusCondition = " AND status_of_delivery = ?";
    $paramTypes .= "s";
    $params[] = $status_filter;
} else {
    $statusCondition = "";
}

$sql = "
    SELECT d.*, p.project_name AS project_name_from_join,
           IFNULL(d.freight_cost, ?) AS freight_cost_with_default 
    FROM deliveries d
    LEFT JOIN projects p ON d.project_id = p.id
    WHERE 1=1
    $projectConditionSQL
    $dateCondition
    $statusCondition
    ORDER BY $filterColumn DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$deliveries_result = $stmt->get_result();
$stmt->close();

/*
  -----------------------------------------------------------------------------
  5) Calculate Total Freight and Accessorial Costs (unfiltered totals)
  -----------------------------------------------------------------------------
*/
$currentProjectConditionForCosts = ""; 
$costParams = [$default_freight_cost];
$costParamTypes = "d";

// --- REVISED: Build cost conditions based on role and filter ---
if (!$is_global_admin && !$account_id_for_admin) {
    // Admin without an account should see no costs
    $currentProjectConditionForCosts = " AND 1=0 "; // Effectively prevents matching any rows
} elseif ($is_global_admin) {
    // Global admin: Filter costs based on the selected project filter
    if (!$is_global_admin && $account_id_for_admin) {
        // Costs for a specific project (global admin doesn't need account check)
        $currentProjectConditionForCosts = " AND d.project_id = ?";
        $costParams[] = $filter_project_id;
        $costParamTypes .= "i";
    } elseif ($filter_project_id === 'unassigned') {
        // Costs for unassigned deliveries
        $currentProjectConditionForCosts = " AND d.project_id IS NULL";
    }
    // If $filter_project_id is 'all', $currentProjectConditionForCosts remains "" (no extra condition needed)
} else {
    // Regular admin with an account: Filter costs based on their account scope
    if (is_numeric($filter_project_id)) {
        // Costs for a specific project, ONLY if it belongs to their account
        $currentProjectConditionForCosts = " AND d.project_id = ? AND d.project_id IN (SELECT id FROM projects WHERE account_id = ?)";
        $costParams[] = $filter_project_id;
        // For cost calculation, filter deliveries that belong to projects in the admin's account
        $costParams[] = $account_id_for_admin;
        $costParamTypes .= "ii"; // one for project_id, one for account_id in subquery
    } elseif ($filter_project_id === 'unassigned') {
        // Costs for unassigned deliveries (admin can see these)
        $currentProjectConditionForCosts = " AND d.project_id IS NULL";
    } else {
        // 'all' filter (or any other unexpected state) for admin: sum costs for ALL projects in their account
        $currentProjectConditionForCosts = " AND d.project_id IN (SELECT id FROM projects WHERE account_id = ?)";
        $costParams[] = $account_id_for_admin;
        $costParamTypes .= "i";
    }
}
// --- END REVISED ---

$sql_costs = "
    SELECT 
        SUM(IF(d.status_of_delivery = 'delivered', IFNULL(d.freight_cost, ?), 0)) AS total_freight_cost,
        SUM(IFNULL(d.accessorial_costs, 0)) AS total_accessorial_costs
    FROM deliveries d
    WHERE 1=1 
    {$currentProjectConditionForCosts}  -- Use the correctly built condition for costs
";

$stmt = $conn->prepare($sql_costs);
$stmt->bind_param($costParamTypes, ...$costParams);
$stmt->execute();
$stmt->bind_result($total_freight_cost, $total_accessorial_costs);
$stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project_name); ?> - Manage Deliveries</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .error-messages {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-bottom: 20px;
            color: #721c24;
        }
        .top-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 20px;
            padding: 15px;
            border: 1px solid #ccc;
        }
        .top-container > div {
            flex: 1;
            min-width: 300px;
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background-color: #f9f9f9;
            text-align: center;
        }
        /* Styling for single entry button */
        .add-single-entry-button {
            background-color: #488C9A;
            border: none;
            color: #fff;
            padding: 1px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1.5em;
            font-weight: bold;
            margin-left: 10px;
        }
        .add-single-entry-button:hover {
            background-color: #3A6E7F;
        }
        /* Time Filter Header Styling */
        .time-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 30px 20px 10px 20px;
            flex-wrap: wrap;
        }
        .time-filters {
            display: flex;
            gap: 10px;
        }
        .time-filters a {
            text-decoration: none;
            padding: 6px 12px;
            background: #eee;
            border-radius: 4px;
            color: #333;
        }
        .time-filters a.active {
            background: #488C9A;
            color: #fff;
        }
        .date-navigation {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .nav-arrow {
            font-weight: bold;
            cursor: pointer;
            background: #eee;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
        }
        .nav-arrow:hover {
            background: #ccc;
        }
        .date-label {
            font-weight: bold;
            font-size: 1.1em;
        }
        .right-filters {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        @media screen and (max-width: 768px) {
            .mobile-hide {
                display: none !important;
            }
        }
        /* Modal styling for Bulk Edit */
        #bulkEditModal {
            display: none; /* Hidden by default */
            position: fixed; 
            z-index: 9999; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0, 0, 0, 0.5);
        }
        #bulkEditModalContent {
            background-color: #fff;
            margin: 5% auto;
            padding: 20px;
            width: 600px; /* Increased width to prevent overlap */
            max-width: 90%;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            position: relative;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .modal-header h2 {
            margin: 0;
            color: #293E4C;
        }
        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 24px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
            border: none;
            background: transparent;
        }
        .close-modal:hover {
            color: #488C9A;
        }
        .modal-field {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
        }
        .modal-field label {
            display: inline-block;
            width: 150px;
            font-weight: 500;
            color: #555;
        }
        .modal-field input {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .modal-field input:focus {
            border-color: #488C9A;
            outline: none;
            box-shadow: 0 0 3px rgba(72, 140, 154, 0.3);
        }
        .modal-sections {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }
        .modal-section {
            flex: 1;
            min-width: 200px; /* Increased minimum width */
        }
        .section-header {
            font-weight: 600;
            margin: 10px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
            color: #555;
        }
        .modal-cost-section {
            width: 100%;
            margin-top: 10px;
        }
        .modal-cost-fields {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .cost-row {
            display: flex;
            gap: 15px;
        }
        .cost-field-full {
            width: 100%;
        }
        .cost-field-half {
            flex: 1;
            min-width: 160px;
        }
        .modal-buttons {
            text-align: center; /* Changed to center */
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .modal-buttons button {
            background-color: #488C9A;
            color: white;
            border: none;
            padding: 8px 20px; /* Slightly wider button */
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
        }
        .modal-buttons button:hover {
            background-color: #3A6E7F;
        }
        /* Modal styling for Miles & Costs section */
        .modal-cost-section {
            width: 100%;
            margin-top: 10px;
        }
        .modal-cost-fields {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .cost-row {
            display: flex;
            gap: 15px;
        }
        /* Miles row styling */
        .miles-row {
            display: flex;
            justify-content: center;
        }
        .miles-field {
            width: 60%;
        }
        /* Costs row styling */
        .costs-row {
            display: flex;
            gap: 15px;
        }
        .cost-field-half {
            flex: 1;
            min-width: 160px;
        }
        /* Media query for mobile view */
        @media screen and (max-width: 600px) {
            .costs-row {
                flex-direction: column;
                gap: 12px;
            }
            .cost-field-half {
                width: 100%;
            }
            .miles-field {
                width: 100%;
            }
        }
        /* Associated Pallets Modal Styling */
        #associatedPalletsModal {
             display: none;
             /* Reuse general modal styles */
        }
         #associatedPalletsModal .modal-content {
             max-width: 500px; /* Smaller modal */
             position: relative; /* Added for absolute positioning of child */
         }
        /* UPDATED: Changed from #associatedPalletsModal ul to #palletList for the div container */
        #palletList {
             /* list-style: none; */ /* No longer a ul */
             padding: 0;
             max-height: 300px; /* Or your desired max height */
             overflow-y: auto;  /* Add scroll for vertical overflow */
             border: 1px solid #eee; /* Optional: to see the scroll container bounds */
        }
        /* Styles for the table within #palletList, if not already covered by inline styles */
        #palletList table {
            /* Add any specific table styling here if needed, e.g., for borders within the scrollable area */
        }

    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Add breadcrumb navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="admin_dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Manage Deliveries</span>
    </div>

    <a href="<?php echo (is_numeric($filter_project_id)) ? 'project_overview?id=' . $filter_project_id : 'admin_dashboard.php'; ?>" class="back-icon" style="margin:20px;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:24px;height:24px;">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back <?php echo (is_numeric($filter_project_id)) ? 'to Project Overview' : 'to Dashboard'; ?>
    </a>
    <h1>Manage Deliveries: <?php echo htmlspecialchars($project_name); ?></h1>

    <!-- Display Messages -->
    <?php
    if (isset($_SESSION['messages']) && !empty($_SESSION['messages'])) {
        foreach ($_SESSION['messages'] as $message) {
            echo $message;
        }
        $_SESSION['messages'] = [];
    }
    ?>

    <div class="top-container">
        <!-- Add Deliveries Section -->
        <div class="left-section">
            <h2>Add Deliveries</h2>
            <h3>Upload via CSV</h3>
            <!-- CSV Upload Form -->
            <form action="manage_deliveries?filter_project_id=<?php echo urlencode($filter_project_id); ?>" method="post" enctype="multipart/form-data">
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit" name="upload_csv">Upload CSV</button>
            </form>
            <div class="single-entry">
                <h3>Add Single Entry:
                    <button type="button" class="add-single-entry-button"
                            onclick="window.location.href='add_delivery?<?php echo (is_numeric($filter_project_id)) ? 'project_id=' . $filter_project_id : ''; ?>';">+</button>
                </h3>
            </div>
        </div>

        <!-- Bulk Actions Section -->
        <div class="middle-section">
            <h2>Bulk Edit / Bulk Delete</h2>
            <span id="selectedRowCount" style="margin-left: 10px; font-size: 0.9em; color: #555;"></span>
            <div class="bulk-actions-buttons">
                <!-- Bulk Edit opens the modal -->
                <button type="button" id="bulkEditBtn" disabled onclick="openBulkEditModal()">Bulk Edit</button>
                <!-- Bulk Delete submits the form -->
                <button type="submit" form="deliveriesForm" name="delete_selected" id="bulkDeleteBtn" disabled
                    onclick="return confirm('Are you sure you want to delete the selected deliveries?');">
                    Bulk Delete
                </button>
            </div>
        </div>

        <!-- Freight Costs Overview Section -->
        <div class="right-section">
            <h2>Freight Costs</h2>
            <table class="freight-costs">
                <tr>
                    <th>Total Freight Cost</th>
                    <th>Total Accessorial Costs</th>
                </tr>
                <tr>
                    <td>$<?php echo number_format($total_freight_cost, 2); ?></td>
                    <td>$<?php echo number_format($total_accessorial_costs, 2); ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Time Filter Header -->
    <div class="time-filter-header">
        <!-- Left: Time Filters -->
        <div class="time-filters">
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=all"
               class="<?php echo ($time_filter === 'all') ? 'active' : ''; ?>">All</a>
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=day&ref_date=<?php echo $ref_date; ?>"
               class="<?php echo ($time_filter === 'day') ? 'active' : ''; ?>">Day</a>
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=week&ref_date=<?php echo $ref_date; ?>"
               class="<?php echo ($time_filter === 'week') ? 'active' : ''; ?>">Week</a>
            <a href="?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=month&ref_date=<?php echo $ref_date; ?>"
               class="<?php echo ($time_filter === 'month') ? 'active' : ''; ?>">Month</a>
        </div>
        <!-- Center: Date Navigation -->
        <div class="date-navigation">
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>'">
                    &larr;
                </button>
            <?php endif; ?>
            <span class="date-label"><?php echo $dateLabel; ?></span>
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?filter_project_id=<?php echo urlencode($filter_project_id); ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>'">
                    &rarr;
                </button>
            <?php endif; ?>
        </div>
        <!-- Right: Search and Status Filter -->
        <div class="right-filters">
            <div style="display: flex; gap: 10px;" class="mobile-hide">
                <label for="searchInput" style="align-self: center;">Search in Table:</label>
                <input type="text" id="searchInput" placeholder="Type to filter..." onkeyup="searchTable()">
            </div>
            <form method="get" action="" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter ?? ''); ?>">
                <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date ?? ''); ?>">
                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter ?? ''); ?>">
                
                <div style="display: flex; align-items: center; gap: 5px;">
                    <label for="filter_project_id" style="align-self: center;">Project:</label>
                    <select name="filter_project_id" id="filter_project_id" onchange="this.form.submit()">
                        <?php if ($is_global_admin): // Only show "All Projects" for global admin ?>
                        <option value="all" <?php if($filter_project_id === 'all') echo 'selected'; ?>>All Projects</option>
                        <?php endif; ?>
                        <option value="unassigned" <?php if($filter_project_id === 'unassigned') echo 'selected'; ?>>Unassigned Deliveries</option>
                        <?php foreach ($all_projects_for_filter as $proj_filter_item): ?>
                            <option value="<?php echo $proj_filter_item['id']; ?>" <?php if (is_numeric($filter_project_id) && $filter_project_id == $proj_filter_item['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($proj_filter_item['project_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; align-items: center; gap: 5px;">
                    <label for="status_filter" style="align-self: center;">Status:</label>
                    <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="Pending" <?php if($status_filter === 'Pending') echo 'selected'; ?>>Pending</option>
                        <option value="In Transit" <?php if($status_filter === 'In Transit') echo 'selected'; ?>>In Transit</option>
                        <option value="Delivered" <?php if($status_filter === 'Delivered') echo 'selected'; ?>>Delivered</option>
                        <option value="Complete" <?php if($status_filter === 'Complete') echo 'selected'; ?>>Complete</option>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Deliveries Form + Table -->
    <form action="manage_deliveries?filter_project_id=<?php echo urlencode($filter_project_id); ?>" method="post" id="deliveriesForm">
        <!-- Preserve filter parameters -->
        <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter ?? ''); ?>">
        <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date ?? ''); ?>">
        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter ?? ''); ?>">

        <div class="table-responsive">
            <table class="deliveries-table" id="deliveriesTable">
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
                    <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                        <th>Project</th>
                    <?php endif; ?>
                    <th>Supplier</th>
                    <th>Wattage</th>
                    <th>Status of Delivery</th>
                    <th>Quantity</th>
                    <th>BOL Number</th>
                    <th>Anticipated Delivery Date</th>
                    <th>Warehouse Arrival Date</th>
                    <th>Actual Delivery Date</th>
                    <th>Proof of Delivery</th>
                    <th>Miles</th>
                    <th>Freight Cost</th>
                    <th>Accessorial Costs</th>
                    <th>Associated Pallets</th>
                    <th>Actions</th>
                </tr>
                <?php if ($deliveries_result->num_rows > 0): ?>
                    <?php 
                    // Prepare statement for fetching pallets outside the loop for efficiency
                    $stmtPallets = $conn->prepare("SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity FROM delivery_pallets dp JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id WHERE dp.delivery_id = ? ORDER BY ip.id");
                    if (!$stmtPallets) {
                        // Handle error appropriately - maybe log it or display a generic error
                        echo "Error preparing pallet statement: " . $conn->error;
                        // Optionally exit or skip the loop
                    }
                    ?>
                    <?php while($delivery = $deliveries_result->fetch_assoc()): ?>
                        <?php
                        // Fetch associated pallets for this delivery
                        $associatedPallets = [];
                        $palletDataJson = '[]'; // Default to empty JSON array
                        $palletCount = 0;
                        if ($stmtPallets) { // Check if statement was prepared successfully
                            $stmtPallets->bind_param("i", $delivery['id']);
                            $stmtPallets->execute();
                            $palletsResult = $stmtPallets->get_result();
                            while ($palletRow = $palletsResult->fetch_assoc()) {
                                $associatedPallets[] = $palletRow;
                            }
                            $palletCount = count($associatedPallets);
                            if ($palletCount > 0) {
                                $palletDataJson = htmlspecialchars(json_encode($associatedPallets), ENT_QUOTES, 'UTF-8');
                            }
                        } else {
                            // Handle case where statement couldn't be prepared
                            $associatedPallets = 'Error fetching pallets'; // Or some other indicator
                        }
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="selected_deliveries[]" value="<?php echo $delivery['id']; ?>" onclick="updateBulkActionButtons()">
                            </td>
                            <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                                <td>
                                    <?php 
                                    if (!empty($delivery['project_id'])) {
                                        // Display project name from the join, or fetch if necessary (though join is better)
                                        echo htmlspecialchars($delivery['project_name_from_join'] ?? 'N/A');
                                    } else {
                                        echo "<em>Unassigned</em>";
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($delivery['supplier']); ?></td>
                            <td><?php echo htmlspecialchars($delivery['wattage']); ?></td>
                            <td><?php echo htmlspecialchars($delivery['status_of_delivery']); ?></td>
                            <td><?php echo htmlspecialchars($delivery['quantity']); ?></td>
                            <td><?php echo htmlspecialchars($delivery['bol_number']); ?></td>
                            <td><?php echo htmlspecialchars($delivery['anticipated_delivery_date']); ?></td>
                            <td><?php echo htmlspecialchars($delivery['warehouse_arrival_date']); ?></td>
                            <td><?php echo htmlspecialchars($delivery['actual_delivery_date']); ?></td>
                            <td>
                                <?php if (!empty($delivery['proof_of_delivery'])): ?>
                                    <a href="view_pod?delivery_id=<?php echo $delivery['id']; ?>" target="_blank">View POD</a>
                                <?php else: ?>
                                    <?php if ($_SESSION['role'] == 'global_admin'): ?>
                                        <a href="upload_pod?delivery_id=<?php echo $delivery['id']; ?>">Upload POD</a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($delivery['miles']); ?></td>
                            <td>$<?php echo number_format($delivery['freight_cost_with_default'], 2); ?></td>
                            <td>$<?php echo number_format($delivery['accessorial_costs'], 2); ?></td>
                            <td>
                                <?php if ($stmtPallets && $palletCount > 0): ?>
                                    <button type="button" class="action-buttons" 
                                            onclick="showPalletModal(this)" 
                                            data-pallets='<?php echo $palletDataJson; ?>'
                                            >View Pallets (<?php echo $palletCount; ?>)</button>
                                <?php elseif ($stmtPallets): ?>
                                    N/A
                                <?php else: ?>
                                    Error
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="edit_delivery?delivery_id=<?php echo $delivery['id']; ?><?php echo (is_numeric($filter_project_id)) ? '&project_id=' . $filter_project_id : ((!empty($delivery['project_id'])) ? '&project_id=' . $delivery['project_id'] : ''); ?>">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    <?php 
                    // Close the prepared statement after the loop
                    if ($stmtPallets) $stmtPallets->close(); 
                    ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo ($filter_project_id === 'all' || $filter_project_id === 'unassigned') ? '16' : '15'; ?>">No delivery entries found.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
        <!-- The bulk delete button is now triggered from the top (middle-section) with "Bulk Delete" -->
    </form>

    <br>
    <a href="admin_dashboard">Back to Admin Dashboard</a>

    <!-- Bulk Edit Modal -->
    <div id="bulkEditModal">
        <div id="bulkEditModalContent">
            <span class="close-modal" onclick="closeBulkEditModal()">&times;</span>
            
            <div class="modal-header">
                <h2>Bulk Edit</h2>
            </div>
            
            <p>Fill in only the fields you want to update. Leave others blank.</p>
            
            <form method="post" action="manage_deliveries?filter_project_id=<?php echo urlencode($filter_project_id); ?>">
                <!-- Keep time/status filters in case you want to preserve them after submit -->
                <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter ?? ''); ?>">
                <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date ?? ''); ?>">
                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter ?? ''); ?>">

                <!-- Hidden checkboxes for selected_deliveries[] will be appended by JS -->
                <div id="bulkEditSelectedIds"></div>

                <!-- Two column layout for Delivery Details and Dates -->
                <div class="modal-sections">
                    <div class="modal-section">
                        <div class="section-header">Delivery Details</div>
                        <div class="modal-field">
                            <label for="bulk_supplier">Supplier</label>
                            <input type="text" id="bulk_supplier" name="supplier">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_wattage">Wattage</label>
                            <input type="text" id="bulk_wattage" name="wattage">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_status_of_delivery">Status</label>
                            <input type="text" id="bulk_status_of_delivery" name="status_of_delivery">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_quantity">Quantity</label>
                            <input type="number" step="1" id="bulk_quantity" name="quantity">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_bol_number">BOL #</label>
                            <input type="text" id="bulk_bol_number" name="bol_number">
                        </div>
                    </div>
                    
                    <div class="modal-section">
                        <div class="section-header">Dates</div>
                        <div class="modal-field">
                            <label for="bulk_anticipated_delivery_date">Anticipated Date</label>
                            <input type="date" id="bulk_anticipated_delivery_date" name="anticipated_delivery_date">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_warehouse_arrival_date">Warehouse Date</label>
                            <input type="date" id="bulk_warehouse_arrival_date" name="warehouse_arrival_date">
                        </div>
                        <div class="modal-field">
                            <label for="bulk_actual_delivery_date">Actual Date</label>
                            <input type="date" id="bulk_actual_delivery_date" name="actual_delivery_date">
                        </div>
                    </div>
                </div>
                
                <!-- Miles & Costs section with improved layout -->
                <div class="modal-cost-section">
                    <div class="section-header">Miles & Costs</div>
                    <div class="modal-cost-fields">
                        <!-- Miles centered on its own line -->
                        <div class="miles-row">
                            <div class="modal-field miles-field">
                                <label for="bulk_miles">Miles</label>
                                <input type="number" step="0.01" id="bulk_miles" name="miles">
                            </div>
                        </div>
                        
                        <!-- Costs on a second line that collapses on mobile -->
                        <div class="costs-row">
                            <div class="modal-field cost-field-half">
                                <label for="bulk_freight_cost">Freight Cost ($)</label>
                                <input type="number" step="0.01" id="bulk_freight_cost" name="freight_cost">
                            </div>
                            <div class="modal-field cost-field-half">
                                <label for="bulk_accessorial_costs">Accessorial ($)</label>
                                <input type="number" step="0.01" id="bulk_accessorial_costs" name="accessorial_costs">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-buttons">
                    <button type="submit" name="bulk_edit_submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Associated Pallets Modal -->
    <div id="associatedPalletsModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeAssociatedPalletModal()">&times;</span>
            <h2>Associated Pallets</h2>
            <div id="palletList"> 
                <!-- Pallet details will be loaded here by JS -->
            </div>
        </div>
    </div>

</main>

<script>
    // Handle Select All
    document.getElementById('select-all').onclick = function() {
        var checkboxes = document.getElementsByName('selected_deliveries[]');
        for (var checkbox of checkboxes) {
            checkbox.checked = this.checked;
        }
        updateBulkActionButtons();
    };

    // Enable/Disable Bulk Edit/Delete buttons based on selection
    function updateBulkActionButtons() {
        var checkboxes = document.getElementsByName('selected_deliveries[]');
        var anyChecked = false;
        for (var checkbox of checkboxes) {
            if (checkbox.checked) {
                anyChecked = true;
                break;
            }
        }
        document.getElementById('bulkEditBtn').disabled = !anyChecked;
        document.getElementById('bulkDeleteBtn').disabled = !anyChecked;

        // NEW: Update selected row count
        var count = 0;
        for (var checkbox of checkboxes) {
            if (checkbox.checked) {
                count++;
            }
        }
        var countDisplay = document.getElementById('selectedRowCount');
        if (countDisplay) {
            countDisplay.textContent = count > 0 ? count + (count === 1 ? " row selected" : " rows selected") : "";
        }
    }

    // Table search
    function searchTable() {
        var input = document.getElementById("searchInput");
        var filter = input.value.toLowerCase();
        var table = document.getElementById("deliveriesTable");
        var trs = table.getElementsByTagName("tr");
        // Skip header row (index 0)
        for (var i = 1; i < trs.length; i++) {
            var tds = trs[i].getElementsByTagName("td");
            var show = false;
            for (var j = 0; j < tds.length; j++) {
                var txtValue = tds[j].textContent || tds[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    show = true;
                    break;
                }
            }
            trs[i].style.display = show ? "" : "none";
        }
    }

    // Open Bulk Edit Modal
    function openBulkEditModal() {
        // Copy selected delivery IDs into hidden inputs in the modal
        var checkboxes = document.getElementsByName('selected_deliveries[]');
        var container = document.getElementById('bulkEditSelectedIds');
        container.innerHTML = ''; // Clear old data

        for (var checkbox of checkboxes) {
            if (checkbox.checked) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_deliveries[]';
                input.value = checkbox.value;
                container.appendChild(input);
            }
        }

        document.getElementById('bulkEditModal').style.display = 'block';
    }

    // Close Bulk Edit Modal
    function closeBulkEditModal() {
        document.getElementById('bulkEditModal').style.display = 'none';
    }

    // Close modal when clicking outside the modal content
    window.onclick = function(event) {
        var modal = document.getElementById('bulkEditModal');
        if (event.target == modal) {
            closeBulkEditModal();
        }
    }

    // --- Associated Pallets Modal --- 
    var associatedPalletsModal = document.getElementById('associatedPalletsModal');
    var palletListUl = document.getElementById('palletList'); // This will now be the container for the table

    function showPalletModal(buttonElement) {
        var palletsJson = buttonElement.getAttribute('data-pallets');
        try {
            var pallets = JSON.parse(palletsJson);
            palletListUl.innerHTML = ''; // Clear previous content (table or list)

            if (pallets.length > 0) {
                var table = document.createElement('table');
                table.style.width = '100%';
                table.style.borderCollapse = 'collapse';

                var thead = table.createTHead();
                var headerRow = thead.insertRow();
                var headers = ['Identifier', 'Wattage', 'Quantity', 'Actions'];
                headers.forEach(function(headerText) {
                    var th = document.createElement('th');
                    th.textContent = headerText;
                    th.style.border = '1px solid #ddd';
                    th.style.padding = '8px';
                    th.style.textAlign = 'center';
                    th.style.backgroundColor = '#293E4C';
                    th.style.maxHeight = '50px';
                    headerRow.appendChild(th);
                });

                var tbody = table.createTBody();
                pallets.forEach(function(pallet) {
                    var row = tbody.insertRow();
                    
                    var cellIdentifier = row.insertCell();
                    cellIdentifier.textContent = pallet.pallet_identifier ? pallet.pallet_identifier : `ID: ${pallet.id}`;
                    cellIdentifier.style.border = '1px solid #ddd';
                    cellIdentifier.style.padding = '8px';

                    var cellWattage = row.insertCell();
                    cellWattage.textContent = pallet.wattage ? `${pallet.wattage}W` : 'N/A';
                    cellWattage.style.border = '1px solid #ddd';
                    cellWattage.style.padding = '8px';

                    var cellQuantity = row.insertCell();
                    cellQuantity.textContent = pallet.quantity ? pallet.quantity : 'N/A';
                    cellQuantity.style.border = '1px solid #ddd';
                    cellQuantity.style.padding = '8px';

                    var cellActions = row.insertCell();
                    cellActions.style.border = '1px solid #ddd';
                    cellActions.style.padding = '8px';
                    cellActions.style.textAlign = 'center';

                    var viewDetailsBtn = document.createElement('a');
                    viewDetailsBtn.href = `pallet_details.php?pallet_id=${pallet.id}`;
                    viewDetailsBtn.textContent = 'View Details';
                    viewDetailsBtn.className = 'action-button'; 
                    cellActions.appendChild(viewDetailsBtn);
                });

                palletListUl.appendChild(table); // Append the table to the designated container
            } else {
                 var p = document.createElement('p');
                 p.textContent = 'No pallets found.';
                 palletListUl.appendChild(p);
            }

            associatedPalletsModal.style.display = 'block';
        } catch (e) {
            console.error("Error parsing pallet data or creating table:", e);
            palletListUl.innerHTML = '<p>Error loading pallet data.</p>';
            associatedPalletsModal.style.display = 'block';
        }
    }

    function closeAssociatedPalletModal() {
         associatedPalletsModal.style.display = 'none';
         palletListUl.innerHTML = ''; // Clear list on close
    }

    // Add event listener for clicks outside the associated pallets modal too
    window.addEventListener('click', function(event) {
        if (event.target == associatedPalletsModal) {
            closeAssociatedPalletModal();
        }
    });

</script>
</body>
</html>
<?php
$conn->close();
?>
