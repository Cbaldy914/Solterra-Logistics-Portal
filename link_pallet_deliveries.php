<?php
session_name("logistics_session");
session_start();

require_once '../config.php'; // Placed early for CSV processing too

// Initialize messages array if not already set
if (!isset($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

// --- Handle CSV Upload for Linking Pallets to Deliveries ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_link_csv'])) {
    $conn_csv = getDBConnection(); // Use a separate connection variable for clarity within this block
    if (!$conn_csv) {
        $_SESSION['messages'][] = "<p class='error-message'>Database connection failed during CSV upload.</p>";
    } else {
        if (isset($_FILES['link_csv_file']) && $_FILES['link_csv_file']['error'] == 0) {
            $fileTmpPath = $_FILES['link_csv_file']['tmp_name'];
            $fileName    = $_FILES['link_csv_file']['name'];
            $fileSize    = $_FILES['link_csv_file']['size'];
            $fileType    = $_FILES['link_csv_file']['type'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedFileTypes = ['text/csv'];
            $allowedExtension = 'csv';
            $maxFileSize      = 5 * 1024 * 1024; // 5MB

            if (($fileType == $allowedFileTypes[0] || $fileExtension == $allowedExtension) && $fileSize <= $maxFileSize) {
                $csvFile = fopen($fileTmpPath, 'r');
                if ($csvFile === false) {
                    $_SESSION['messages'][] = "<p class='error-message'>Error opening the uploaded CSV file.</p>";
                } else {
                    $header = fgetcsv($csvFile); // Read the header row
                    $expectedHeaders = ['delivery_id', 'pallet_identifier'];
                    $header = array_map('trim', $header); // Trim whitespace from headers

                    if ($header !== $expectedHeaders) {
                        $_SESSION['messages'][] = "<p class='error-message'>CSV header mismatch. Expected columns: " . implode(', ', $expectedHeaders) . ". Found: " . implode(', ', $header) . "</p>";
                    } else {
                        $conn_csv->begin_transaction();
                        $linked_count = 0;
                        $error_count = 0;
                        $line_number = 1; // For error reporting, header is line 1

                        $stmt_check_delivery = $conn_csv->prepare("SELECT id, wattage FROM deliveries WHERE id = ?");
                        $stmt_check_pallet = $conn_csv->prepare("SELECT id, wattage, status FROM inventory_pallets WHERE pallet_identifier = ?");
                        $stmt_check_existing_link = $conn_csv->prepare("SELECT COUNT(*) FROM delivery_pallets WHERE delivery_id = ? AND inventory_pallet_id = ?");
                        $stmt_insert_link = $conn_csv->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
                        $stmt_update_pallet_status = $conn_csv->prepare("UPDATE inventory_pallets SET status = ?, current_delivery_id = ? WHERE id = ?");

                        while (($row = fgetcsv($csvFile)) !== FALSE) {
                            $line_number++;
                            if (count($row) !== count($expectedHeaders)) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Column count mismatch.</p>";
                                $error_count++;
                                continue;
                            }
                            $data = array_combine($header, $row);
                            $delivery_id_csv = trim($data['delivery_id']);
                            $pallet_identifier_csv = trim($data['pallet_identifier']);

                            if (empty($delivery_id_csv) || empty($pallet_identifier_csv)) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: delivery_id or pallet_identifier is empty. Skipping.</p>";
                                $error_count++;
                                continue;
                            }

                            // Validate Delivery
                            $stmt_check_delivery->bind_param("i", $delivery_id_csv);
                            $stmt_check_delivery->execute();
                            $result_delivery = $stmt_check_delivery->get_result();
                            if ($result_delivery->num_rows === 0) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Delivery ID '{$delivery_id_csv}' not found. Skipping.</p>";
                                $error_count++;
                                continue;
                            }
                            $delivery_data = $result_delivery->fetch_assoc();
                            $delivery_wattage = $delivery_data['wattage'];

                            // Validate Pallet Identifier
                            $stmt_check_pallet->bind_param("s", $pallet_identifier_csv);
                            $stmt_check_pallet->execute();
                            $result_pallet = $stmt_check_pallet->get_result();
                            if ($result_pallet->num_rows === 0) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Pallet Identifier '{$pallet_identifier_csv}' not found. Skipping.</p>";
                                $error_count++;
                                continue;
                            }
                            $pallet_data = $result_pallet->fetch_assoc();
                            $inventory_pallet_id = $pallet_data['id'];
                            $pallet_wattage = $pallet_data['wattage'];
                            $pallet_status = $pallet_data['status'];

                            // Check Wattage Match
                            if ($delivery_wattage !== $pallet_wattage) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Wattage mismatch. Delivery '{$delivery_id_csv}' ({$delivery_wattage}W) vs Pallet '{$pallet_identifier_csv}' ({$pallet_wattage}W). Skipping.</p>";
                                $error_count++;
                                continue;
                            }

                            // Check Pallet Status (Example: allow linking if 'At Manufacturer' or 'In Warehouse')
                            $linkable_statuses = ['At Manufacturer', 'In Warehouse', 'Available']; // Add more as needed
                            if (!in_array($pallet_status, $linkable_statuses)) {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Pallet '{$pallet_identifier_csv}' has status '{$pallet_status}' which is not linkable. Skipping.</p>";
                                $error_count++;
                                continue;
                            }

                            // Check for existing link
                            $stmt_check_existing_link->bind_param("ii", $delivery_id_csv, $inventory_pallet_id);
                            $stmt_check_existing_link->execute();
                            $stmt_check_existing_link->bind_result($link_count);
                            $stmt_check_existing_link->fetch();
                            if ($link_count > 0) {
                                $_SESSION['messages'][] = "<p class='info-message'>Line {$line_number}: Pallet '{$pallet_identifier_csv}' already linked to Delivery ID '{$delivery_id_csv}'. Skipping.</p>";
                                // Not strictly an error, but good to inform.
                                continue;
                            }

                            // All checks passed, attempt to insert link
                            $stmt_insert_link->bind_param("ii", $delivery_id_csv, $inventory_pallet_id);
                            if ($stmt_insert_link->execute()) {
                                // Update pallet status and current_delivery_id
                                $new_pallet_status = 'Assigned to Delivery'; // Or 'Staged for Delivery'
                                $stmt_update_pallet_status->bind_param("sii", $new_pallet_status, $delivery_id_csv, $inventory_pallet_id);
                                if (!$stmt_update_pallet_status->execute()) {
                                    $_SESSION['messages'][] = "<p class='warning-message'>Line {$line_number}: Pallet '{$pallet_identifier_csv}' linked to Delivery ID '{$delivery_id_csv}', but failed to update pallet status: " . htmlspecialchars($stmt_update_pallet_status->error) . "</p>";
                                    // Decide if this is a critical error to rollback or just a warning
                                }
                                $linked_count++;
                            } else {
                                $_SESSION['messages'][] = "<p class='error-message'>Line {$line_number}: Failed to link Pallet '{$pallet_identifier_csv}' to Delivery ID '{$delivery_id_csv}'. Error: " . htmlspecialchars($stmt_insert_link->error) . "</p>";
                                $error_count++;
                            }
                        }
                        fclose($csvFile);

                        if ($error_count > 0) {
                            $conn_csv->rollback();
                            $_SESSION['messages'][] = "<p class='error-message'>CSV import finished with {$error_count} errors. No changes were committed. Please review messages and try again.</p>";
                        } else {
                            $conn_csv->commit();
                            if ($linked_count > 0) {
                                $_SESSION['messages'][] = "<p class='success-message'>Successfully linked {$linked_count} pallet(s) from the CSV.</p>";
                            } else {
                                $_SESSION['messages'][] = "<p class='info-message'>No new pallet links were made from the CSV (either all were already linked or no valid entries found).</p>";
                            }
                        }
                        // Close prepared statements
                        $stmt_check_delivery->close();
                        $stmt_check_pallet->close();
                        $stmt_check_existing_link->close();
                        $stmt_insert_link->close();
                        $stmt_update_pallet_status->close();
                    }
                }
            } else {
                $_SESSION['messages'][] = "<p class='error-message'>Invalid file type or file too large. Please upload a valid CSV file (max 5MB).</p>";
            }
        } else {
            $_SESSION['messages'][] = "<p class='error-message'>Error uploading the CSV file. Code: {$_FILES['link_csv_file']['error']}</p>";
        }
        if ($conn_csv) $conn_csv->close();
        // Redirect to avoid form resubmission on refresh
        header("Location: link_pallet_deliveries.php?" . http_build_query($_GET)); // Preserve existing GET params
        exit();
    }
}
// End CSV Upload Handling

// Filter parameters (must be after potential redirect from CSV upload)
$filter_project_id = isset($_GET['filter_project_id']) ? $_GET['filter_project_id'] : 'all';
$search_term = isset($_GET['search_term']) ? trim($_GET['search_term']) : '';
$filter_needs_pallets = isset($_GET['filter_needs_pallets']) && $_GET['filter_needs_pallets'] === '1'; // New filter

// Database connection (main connection for page display)
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed for page display");
}

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'global_admin' && $_SESSION['role'] != 'admin')) {
    header("Location: unauthorized");
    exit();
}

// --- Admin Account Fetching and Permission Setup (from manage_deliveries) ---
$account_id_for_admin = null;
$is_global_admin = ($_SESSION['role'] === 'global_admin');

if (!$is_global_admin) { 
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
        $_SESSION['messages'][] = "<p class='error-message'>Error: Admin user is not associated with an account.</p>";
    }
}

// Fetch all projects for the filter dropdown (from manage_deliveries)
$all_projects_for_filter = [];
if ($is_global_admin) {
    $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
} else { 
    if ($account_id_for_admin) {
        $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
        $stmt_all_proj->bind_param("i", $account_id_for_admin);
    } else {
        $stmt_all_proj = $conn->prepare("SELECT id, project_name FROM projects WHERE 1=0"); 
    }
}
if ($stmt_all_proj) {
    $stmt_all_proj->execute();
    $result_all_proj = $stmt_all_proj->get_result();
    while ($proj_row = $result_all_proj->fetch_assoc()) {
        $all_projects_for_filter[] = $proj_row;
    }
    $stmt_all_proj->close();
}

$page_title_project_name = "All Projects";
if (is_numeric($filter_project_id)) {
    $stmt_proj_details = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    if ($stmt_proj_details) {
        $stmt_proj_details->bind_param("i", $filter_project_id);
        $stmt_proj_details->execute();
        $stmt_proj_details->bind_result($project_name_specific);
        if ($stmt_proj_details->fetch()) {
            $page_title_project_name = $project_name_specific;
        }
        $stmt_proj_details->close();
    }
} elseif ($filter_project_id === 'unassigned') {
    $page_title_project_name = "Unassigned Deliveries";
}


// --- Fetch Deliveries for Display ---
$sql = "
    SELECT d.id, d.project_id, d.supplier, d.wattage, d.status_of_delivery, 
           d.quantity, d.bol_number, d.proof_of_delivery,
           p.project_name AS project_name_from_join
    FROM deliveries d
    LEFT JOIN projects p ON d.project_id = p.id
    WHERE 1=1
";

$params = [];
$paramTypes = "";

// Project Filtering
if (is_numeric($filter_project_id)) {
    $sql .= " AND d.project_id = ?";
    if (!$is_global_admin && $account_id_for_admin) {
        $sql .= " AND p.account_id = ?";
        $paramTypes .= "i";
        $params[] = $account_id_for_admin;
    }
    $paramTypes .= "i";
    $params[] = $filter_project_id;
} elseif ($filter_project_id === 'unassigned') {
    $sql .= " AND d.project_id IS NULL";
} elseif ($filter_project_id === 'all' && !$is_global_admin && $account_id_for_admin) {
    // Admin viewing their account's projects implicitly
    $sql .= " AND p.account_id = ?";
    $paramTypes .= "i";
    $params[] = $account_id_for_admin;
} // Global admin viewing 'all' needs no additional project SQL filter here

// New Filter: Show only deliveries needing pallets
if ($filter_needs_pallets) {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM delivery_pallets dp_check WHERE dp_check.delivery_id = d.id)";
}

// Search Term Filtering (similar to manage_pallets)
if (!empty($search_term)) {
    $sql .= " AND (
        d.supplier LIKE ? OR 
        d.wattage LIKE ? OR 
        d.status_of_delivery LIKE ? OR 
        d.bol_number LIKE ? OR 
        p.project_name LIKE ?
    )";
    $like_search_term = "%{$search_term}%";
    for ($i = 0; $i < 5; $i++) {
        $paramTypes .= "s";
        $params[] = $like_search_term;
    }
}

$sql .= " ORDER BY COALESCE(d.actual_delivery_date, d.anticipated_delivery_date, d.id) DESC"; // Keep a default sort

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($paramTypes)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    $stmt->execute();
    $deliveries_result = $stmt->get_result();
    $stmt->close();
} else {
    $_SESSION['messages'][] = "<p class='error-message'>Error preparing deliveries query: " . htmlspecialchars($conn->error) . "</p>";
    $deliveries_result = null; // Ensure it's set
}

// --- NEW: Fetch Available Pallets for Linking ---
$available_pallets = [];
$sql_pallets_available_base = "
    SELECT 
        ip.id AS pallet_id, 
        ip.pallet_identifier, 
        ip.wattage, 
        ip.quantity, 
        ip.status AS pallet_status,
        ip.arrival_date AS pallet_status_timestamp, /* Use arrival_date as the status timestamp */
        w.name AS current_warehouse_name,
        p_assigned.project_name AS assigned_project_name,
        ip.assigned_project_id
    FROM inventory_pallets ip
    LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
    LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
    LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
    WHERE dp.inventory_pallet_id IS NULL  /* Pallet is not in delivery_pallets */
    AND ip.status IN ('In Warehouse', 'Produced', 'At Manufacturer')
";

$pallets_available_conditions = [];
$pallets_available_params = [];
$pallets_available_types = "";

// Project filtering for available pallets
if (is_numeric($filter_project_id)) {
    $pallets_available_conditions[] = "(ip.assigned_project_id = ? OR ip.assigned_project_id IS NULL)";
    $pallets_available_params[] = $filter_project_id;
    $pallets_available_types .= "i";
    if (!$is_global_admin && $account_id_for_admin) {
        // Ensure that if a project is assigned, it belongs to the admin's account
        $pallets_available_conditions[] = "(ip.assigned_project_id IS NULL OR (ip.assigned_project_id = ? AND p_assigned.account_id = ?))";
        $pallets_available_params[] = $filter_project_id; // Redundant but matches structure
        $pallets_available_params[] = $account_id_for_admin;
        $pallets_available_types .= "ii";
    }
} elseif ($filter_project_id === 'unassigned') {
    $pallets_available_conditions[] = "ip.assigned_project_id IS NULL";
    // No account check needed for unassigned, admin can see them.
} elseif ($filter_project_id === 'all' && $is_global_admin) {
    // No additional project condition, global admin sees all unlinked.
} else { // Admin but not global, and filter_project_id is 'all' (or invalid state)
    if (!$is_global_admin && $account_id_for_admin) {
        $pallets_available_conditions[] = "(p_assigned.account_id = ? OR ip.assigned_project_id IS NULL)";
        $pallets_available_params[] = $account_id_for_admin;
        $pallets_available_types .= "i";
    } else if (!$is_global_admin && !$account_id_for_admin) {
        $pallets_available_conditions[] = "1=0"; // Admin with no account sees nothing
    }
}

$sql_pallets_available = $sql_pallets_available_base;
if (!empty($pallets_available_conditions)) {
    $sql_pallets_available .= " AND (" . implode(" AND ", $pallets_available_conditions) . ")";
}
$sql_pallets_available .= " ORDER BY ip.id DESC";

$stmt_pallets_available = $conn->prepare($sql_pallets_available);
if ($stmt_pallets_available) {
    if (!empty($pallets_available_types)) {
        $stmt_pallets_available->bind_param($pallets_available_types, ...$pallets_available_params);
    }
    $stmt_pallets_available->execute();
    $result_pallets_available = $stmt_pallets_available->get_result();
    while ($row = $result_pallets_available->fetch_assoc()) {
        $available_pallets[] = $row;
    }
    $stmt_pallets_available->close();
} else {
    $_SESSION['messages'][] = "<p class='error-message'>Error fetching available pallets: " . $conn->error . "</p>";
}
// --- END NEW: Fetch Available Pallets ---


// Determine project name for page title (remains largely the same)
$project_name_for_title = "All Deliveries";
if (is_numeric($filter_project_id)) {
    $stmt_proj_title = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    if ($stmt_proj_title) {
        $stmt_proj_title->bind_param("i", $filter_project_id);
        $stmt_proj_title->execute();
        $stmt_proj_title->bind_result($p_name_title);
        if ($stmt_proj_title->fetch()) {
            $project_name_for_title = $p_name_title;
        }
        $stmt_proj_title->close();
    }
} elseif ($filter_project_id === 'unassigned') {
    $project_name_for_title = "Unassigned Deliveries";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Pallets to Deliveries <?php if ($project_name_for_title !== "All Deliveries") echo " - " . htmlspecialchars($project_name_for_title); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Styles from manage_deliveries.php & manage_pallets.php, simplified */
        .filter-container {
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            display: flex; 
            flex-wrap: wrap; 
            justify-content: space-between; 
            align-items: center; 
            gap: 15px; 
            margin-bottom: 20px;
        }
        .filter-group-left {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .filter-group-right {
            display: flex;
            gap: 10px; 
            align-items: center;
        }
        .filter-container label {
            margin-right: 5px; 
            font-weight: 500;
            white-space: nowrap; 
        }
        .filter-container input[type="text"],
        .filter-container select {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        .action-button {
            background-color: #488C9A;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            padding: 5px 10px;
            margin: 2px;
            font-weight: bold;
            cursor: pointer;
            border: none;
            font-size: 0.9em;
        }
        .action-button:hover {
            background-color: #3A6E7F;
        }
        .error-message {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-bottom: 20px;
            color: #721c24;
        }
        .success-message {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 10px;
            margin-bottom: 20px;
            color: #155724;
        }
        /* Modal styling (copied from manage_deliveries for associated pallets) */
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
            margin: 10% auto; 
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px; 
            border-radius: 8px;
            position: relative;
        }
        .close-modal {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-modal:hover, .close-modal:focus {
            color: black;
            text-decoration: none;
        }
        #palletList table {
            width: 100%;
            border-collapse: collapse;
        }
        #palletList th, #palletList td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        #palletList th {
            background-color: #e9ecef;
        }
        /* Tab styling */
        .tabs-container {
            text-align: center;
            width: 100%;
            margin-bottom: 20px;
            border-bottom: 1px solid #ccc;
        }
        .tabs {
            display: inline-flex;
            gap: 1px;
        }
        .tabs button.tab-link { /* More specific selector */
            background: #e9ecef;
            color: #333;
            padding: 10px 15px;
            cursor: pointer;
            font-weight: 600;
            border: 1px solid #ccc;
            border-bottom: none;
            border-top-left-radius: 5px;
            border-top-right-radius: 5px;
            font-size: 1em;
            margin-bottom: -1px; /* Overlap container's bottom border */
        }
        .tabs button.tab-link.active { /* More specific selector */
            background: #fff;
            color: #293E4C;
            border-bottom: 1px solid #fff;
        }
        .tab-content {
            display: none;
            padding-top: 10px; /* Add some space above tab content */
        }
        .tab-content.active {
            display: block;
        }
        /* Ensure filters are laid out nicely */
        #palletsTab .filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
            align-items: center;
        }
        #palletsTab .filter-controls label {
            font-weight: 500;
            margin-right: 5px;
        }
        #palletsTab .filter-controls input[type="text"],
        #palletsTab .filter-controls select {
            padding: 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
            height: 34px; /* Align height */
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Link Pallets to Deliveries: <?php echo htmlspecialchars($project_name_for_title); ?></h1>

    <!-- Display Messages -->
    <?php
    if (isset($_SESSION['messages']) && !empty($_SESSION['messages'])) {
        foreach ($_SESSION['messages'] as $message) {
            echo $message;
        }
        $_SESSION['messages'] = []; // Clear messages after displaying
    }
    ?>

    <!-- CSV Upload for Linking -->
    <div class="csv-upload-section" style="margin-bottom: 20px; padding: 15px; background-color: #f0f0f0; border: 1px solid #ddd; border-radius: 5px;">
        <h3>Link Pallets via CSV</h3>
        <form action="link_pallet_deliveries.php" method="post" enctype="multipart/form-data">
            <input type="file" name="link_csv_file" accept=".csv" required>
            <button type="submit" name="upload_link_csv" class="action-button">Upload CSV to Link</button>
        </form>
    </div>

    <!-- Tab Buttons MOVED HERE -->
    <div class="tabs-container">
        <div class="tabs">
            <button class="tab-link active" data-tab="deliveriesTab">Deliveries (<?php echo $deliveries_result ? $deliveries_result->num_rows : 0; ?>)</button>
            <button class="tab-link" data-tab="palletsTab">Available Pallets (<?php echo count($available_pallets); ?>)</button>
        </div>
    </div>

    <!-- Deliveries Tab Content -->
    <div id="deliveriesTab" class="tab-content active">
        <!-- Filters MOVED INSIDE Deliveries Tab -->
        <div class="filter-container">
            <form action="link_pallet_deliveries.php" method="get" id="filterFormDeliveries" class="filter-group-left">
                <label for="search_term_deliveries">Search Deliveries:</label>
                <input type="text" name="search_term" id="search_term_deliveries" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="BOL, Supplier, Project...">
                
                <label for="filter_project_id_deliveries">Project:</label>
                <select name="filter_project_id" id="filter_project_id_deliveries">
                    <?php if ($is_global_admin): ?>
                    <option value="all" <?php if($filter_project_id === 'all') echo 'selected'; ?>>All Projects</option>
                    <?php endif; ?>
                    <option value="unassigned" <?php if($filter_project_id === 'unassigned') echo 'selected'; ?>>Unassigned</option>
                    <?php foreach ($all_projects_for_filter as $proj_filter_item): ?>
                        <option value="<?php echo $proj_filter_item['id']; ?>" <?php if (is_numeric($filter_project_id) && $filter_project_id == $proj_filter_item['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($proj_filter_item['project_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="filter_needs_pallets" style="margin-left: 15px;">
                    <input type="checkbox" name="filter_needs_pallets" id="filter_needs_pallets" value="1" <?php if ($filter_needs_pallets) echo 'checked'; ?>>
                    Show only deliveries needing pallets
                </label>

                <button type="submit" class="action-button">Apply Filters</button>
            </form>
            <div class="filter-group-right">
                <button type="button" id="exportDeliveriesCsvBtn" class="action-button">Export Deliveries CSV</button>
            </div>
        </div>

        <!-- Deliveries Table -->
        <div class="table-responsive">
            <table class="deliveries-table" id="deliveriesTable">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                            <th>Project</th>
                        <?php endif; ?>
                        <th>Supplier</th>
                        <th>BOL Number</th>
                        <th>Delivery Wattage</th>
                        <th>Status</th>
                        <th>Relevant Date</th>
                        <th>Associated Pallets</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($deliveries_result && $deliveries_result->num_rows > 0): ?>
                        <?php while($delivery = $deliveries_result->fetch_assoc()): ?>
                            <?php
                            // Fetch associated pallets for this delivery to display in the modal
                            $current_delivery_pallets = [];
                            $stmt_current_pallets = $conn->prepare("SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity FROM delivery_pallets dp JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id WHERE dp.delivery_id = ? ORDER BY ip.id");
                            if ($stmt_current_pallets) {
                                $stmt_current_pallets->bind_param("i", $delivery['id']);
                                $stmt_current_pallets->execute();
                                $pallets_res = $stmt_current_pallets->get_result();
                                while ($p_row = $pallets_res->fetch_assoc()) {
                                    $current_delivery_pallets[] = $p_row;
                                }
                                $stmt_current_pallets->close();
                            }
                            $palletDataJson = htmlspecialchars(json_encode($current_delivery_pallets), ENT_QUOTES, 'UTF-8');
                            $linked_pallet_display_count = $delivery['linked_pallet_count']; // Use pre-fetched count
                            ?>
                            <tr>
                                <td><?php echo $delivery['id']; ?></td>
                                <?php if ($filter_project_id === 'all' || $filter_project_id === 'unassigned'): ?>
                                    <td><?php echo htmlspecialchars($delivery['project_name'] ?? 'N/A'); ?></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($delivery['supplier'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($delivery['bol_number'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($delivery['wattage'] ?? 'N/A'); ?>W</td>
                                <td><?php echo htmlspecialchars($delivery['status_of_delivery'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($delivery['relevant_date'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($linked_pallet_display_count > 0): ?>
                                        <button type="button" class="action-button view-pallets-btn"
                                                data-pallets='<?php echo $palletDataJson; ?>'>
                                            View (<?php echo $linked_pallet_display_count; ?>)
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #888;">None Linked</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="manage_delivery_pallets.php?delivery_id=<?php echo $delivery['id']; ?>&wattage=<?php echo urlencode($delivery['wattage']); ?><?php if(is_numeric($filter_project_id) && $filter_project_id > 0) { echo '&project_id=' . $filter_project_id; } elseif (!empty($delivery['project_id'])) { echo '&project_id=' . $delivery['project_id'];} ?>"
                                       class="action-button"
                                       title="Manage pallet associations for this delivery">
                                       Manage Links
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo ($filter_project_id === 'all' || $filter_project_id === 'unassigned') ? '9' : '8'; ?>">No deliveries found matching your criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div> <!-- End Deliveries Tab -->

    <!-- Pallets Tab Content -->
    <div id="palletsTab" class="tab-content">
        <h2>Available Pallets for Linking</h2>
        <form method="POST" action="export_available_pallets_csv.php" style="margin-bottom: 15px;">
            <input type="hidden" name="filter_project_id_export" value="<?php echo htmlspecialchars($filter_project_id); ?>">
            <!-- Add other active filters as hidden inputs if export_available_pallets_csv.php needs them -->
            <button type="submit" name="export_pallets_csv" class="action-button">Export Available Pallets CSV</button>
        </form>

        <div class="filter-controls">
            <label for="palletSearchInput">Search Pallets:</label>
            <input type="text" id="palletSearchInput" placeholder="Filter by Identifier, Warehouse...">
            <label for="palletStatusFilter">Status:</label>
            <select id="palletStatusFilter">
                <option value="">All Statuses</option>
                <option value="In Warehouse">In Warehouse</option>
                <option value="Produced">Produced</option>
            </select>
            <label for="palletWattageFilter">Wattage:</label>
            <select id="palletWattageFilter">
                <option value="">All Wattages</option>
                <?php
                $unique_pallet_wattages = [];
                if (!empty($available_pallets)) {
                    foreach ($available_pallets as $p_avail) { $unique_pallet_wattages[$p_avail['wattage']] = true; }
                    ksort($unique_pallet_wattages);
                    foreach (array_keys($unique_pallet_wattages) as $wattage_avail):
                ?>
                    <option value="<?php echo htmlspecialchars($wattage_avail); ?>"><?php echo htmlspecialchars($wattage_avail); ?>W</option>
                <?php endforeach; 
                }
                ?>
            </select>
        </div>

        <div class="table-responsive">
            <table class="deliveries-table" id="availablePalletsTable">
                <thead>
                    <tr>
                        <th>Pallet Identifier</th>
                        <th>Wattage</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Arrival Date</th>
                        <th>Current Warehouse</th>
                        <th>Assigned Project</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($available_pallets)): ?>
                        <?php foreach ($available_pallets as $pallet_item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pallet_item['pallet_identifier'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['wattage'] ?? 'N/A'); ?>W</td>
                                <td><?php echo htmlspecialchars($pallet_item['quantity'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['pallet_status'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['pallet_status_timestamp'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['current_warehouse_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet_item['assigned_project_name'] ?? 'Unassigned'); ?></td>
                                <td>
                                    <a href="pallet_details.php?pallet_id=<?php echo $pallet_item['pallet_id']; ?>" class="action-button" target="_blank">View Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">No available pallets found matching the criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div> <!-- End Pallets Tab -->

</main>

<script>
    // Show Associated Pallets Modal (copied from manage_deliveries.php, target palletListContainer)
    var associatedPalletsModal = document.getElementById('associatedPalletsModal');
    var palletListContainerDiv = document.getElementById('palletListContainer'); 

    function showAssociatedPalletsModal(buttonElement) {
        var palletsJson = buttonElement.getAttribute('data-pallets');
        try {
            var pallets = JSON.parse(palletsJson);
            palletListContainerDiv.innerHTML = ''; 

            if (pallets.length > 0) {
                var table = document.createElement('table');
                // ... (table creation identical to manage_deliveries.php, targeting palletListContainerDiv) ...
                var thead = table.createTHead();
                var headerRow = thead.insertRow();
                var headers = ['Identifier', 'Wattage', 'Quantity', 'Actions']; // Action could be 'View Details' or 'Unlink'
                headers.forEach(function(headerText) {
                    var th = document.createElement('th');
                    th.textContent = headerText; headerRow.appendChild(th);
                });
                var tbody = table.createTBody();
                pallets.forEach(function(pallet) {
                    var row = tbody.insertRow();
                    row.insertCell().textContent = pallet.pallet_identifier ? pallet.pallet_identifier : `ID: ${pallet.id}`;
                    row.insertCell().textContent = pallet.wattage ? `${pallet.wattage}W` : 'N/A';
                    row.insertCell().textContent = pallet.quantity ? pallet.quantity : 'N/A';
                    var actionCell = row.insertCell();
                    var viewBtn = document.createElement('a');
                    viewBtn.href = `pallet_details.php?pallet_id=${pallet.id}`;
                    viewBtn.textContent = 'View Details';
                    viewBtn.className = 'action-button';
                    // No target='_blank' needed here if manage_deliveries was updated
                    actionCell.appendChild(viewBtn);
                });
                palletListContainerDiv.appendChild(table); 
            } else {
                 palletListContainerDiv.innerHTML = '<p>No pallets currently associated.</p>';
            }
            associatedPalletsModal.style.display = 'block';
        } catch (e) {
            console.error("Error in showAssociatedPalletsModal: ", e);
            palletListContainerDiv.innerHTML = '<p>Error loading pallet data.</p>';
            if (associatedPalletsModal) associatedPalletsModal.style.display = 'block';
        }
    }

    function closeAssociatedPalletsModal() {
         if(associatedPalletsModal) associatedPalletsModal.style.display = 'none';
         if(palletListContainerDiv) palletListContainerDiv.innerHTML = ''; 
    }

    // Placeholder for Manage Pallets Modal (Phase 3)
    function openManagePalletsModal(deliveryId, deliveryWattage) {
        document.getElementById('modalDeliveryId').textContent = deliveryId;
        document.getElementById('modalDeliveryWattage').textContent = deliveryWattage;
        // Here you would typically load available pallets via AJAX based on wattage and deliveryId
        // For now, just display the modal:
        var modal = document.getElementById('managePalletsLinkModal');
        if(modal) modal.style.display = 'block';
    }

    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target == associatedPalletsModal) {
            closeAssociatedPalletsModal();
        }
        var manageModal = document.getElementById('managePalletsLinkModal');
        if (event.target == manageModal) {
            manageModal.style.display = 'none';
        }
    });

    // Table search (from manage_deliveries - simplified for this page's filters)
    // This is a basic client-side search. For large datasets, server-side filtering is better.
    // The current PHP already handles server-side filtering based on the search_term input.
    // This JS search can be a quick local filter on the currently displayed results if desired,
    // but it might conflict or be redundant with the server-side search.
    // For now, relying on server-side search via form submission.

    // --- Tab Switching Logic ---
    document.addEventListener('DOMContentLoaded', function() {
        const tabLinks = document.querySelectorAll('.tabs .tab-link');
        const tabContents = document.querySelectorAll('.tab-content');

        function openTab(targetTabId) {
            tabContents.forEach(content => {
                content.classList.remove('active');
                content.style.display = 'none';
            });
            tabLinks.forEach(link => {
                link.classList.remove('active');
            });

            const activeTabContent = document.getElementById(targetTabId);
            const activeTabLink = document.querySelector(`.tab-link[data-tab="${targetTabId}"]`);

            if (activeTabContent) {
                activeTabContent.classList.add('active');
                activeTabContent.style.display = 'block';
            }
            if (activeTabLink) {
                activeTabLink.classList.add('active');
            }
        }

        tabLinks.forEach(link => {
            link.addEventListener('click', function(event) {
                event.preventDefault();
                const tabId = this.getAttribute('data-tab');
                openTab(tabId);
            });
        });

        // Activate the default tab (Deliveries)
        const initialActiveTab = document.querySelector('.tab-link.active');
        if (initialActiveTab) {
            openTab(initialActiveTab.getAttribute('data-tab'));
        } else if (document.getElementById('deliveriesTab')) {
            openTab('deliveriesTab');
        }
        
        // Initialize table filters if they are now visible
        filterDeliveriesTable(); 
        filterPalletsTable();
    });

    // Filter function for Deliveries Table (adapted from original searchTable)
    function filterDeliveriesTable() {
        var input = document.getElementById("search_term_deliveries"); // This is the search for deliveries
        var filter = input ? input.value.toLowerCase() : "";
        var table = document.getElementById("deliveriesTable");
        if (!table) return;
        var trs = table.getElementsByTagName("tr");

        for (var i = 1; i < trs.length; i++) { // Skip header
            var tds = trs[i].getElementsByTagName("td");
            var show = false;
            if (tds.length > 0) {
                for (var j = 0; j < tds.length; j++) {
                    // Avoid trying to read text from button/input cells if they cause issues
                    if (tds[j].querySelector('button, input, a.action-button, form')) continue; 
                    var txtValue = tds[j].textContent || tds[j].innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        show = true;
                        break;
                    }
                }
            }
            trs[i].style.display = show ? "" : "none";
        }
    }
    // Ensure the original search input for deliveries calls filterDeliveriesTable
    const deliveriesSearchInput = document.getElementById("search_term_deliveries"); 
    if(deliveriesSearchInput) {
        // deliveriesSearchInput.removeEventListener('keyup', searchTable); // If searchTable was globally defined
        deliveriesSearchInput.addEventListener('keyup', filterDeliveriesTable);
    }


    // --- Filter function for Available Pallets table ---
    function filterPalletsTable() {
        var input, statusFilterValue, wattageFilterValue, table, tr, td, i, txtValue, showRow;
        input = document.getElementById("palletSearchInput");
        var filter = input ? input.value.toLowerCase() : "";

        var statusSelect = document.getElementById("palletStatusFilter");
        statusFilterValue = statusSelect ? statusSelect.value.toLowerCase() : "";

        var wattageSelect = document.getElementById("palletWattageFilter");
        wattageFilterValue = wattageSelect ? wattageSelect.value.toLowerCase().replace('w','') : "";

        table = document.getElementById("availablePalletsTable");
        if (!table) return;
        tr = table.getElementsByTagName("tr");

        for (i = 1; i < tr.length; i++) { // Start from 1 to skip header row
            showRow = true;
            var palletIdentifierCell = tr[i].getElementsByTagName("td")[0];
            var wattageCell = tr[i].getElementsByTagName("td")[1];
            var statusCell = tr[i].getElementsByTagName("td")[3];
            var warehouseCell = tr[i].getElementsByTagName("td")[5];
            var projectCell = tr[i].getElementsByTagName("td")[6];

            // Text search (Identifier, Warehouse, Project)
            if (filter) {
                var searchableText = "";
                if (palletIdentifierCell) searchableText += (palletIdentifierCell.textContent || palletIdentifierCell.innerText);
                if (warehouseCell) searchableText += (warehouseCell.textContent || warehouseCell.innerText);
                if (projectCell) searchableText += (projectCell.textContent || projectCell.innerText);
                
                if (searchableText.toLowerCase().indexOf(filter) === -1) {
                    showRow = false;
                }
            }

            // Status filter
            if (showRow && statusFilterValue) {
                if (statusCell) {
                    txtValue = statusCell.textContent || statusCell.innerText;
                    if (txtValue.toLowerCase() !== statusFilterValue) {
                        showRow = false;
                    }
                } else { showRow = false; }
            }

            // Wattage filter
            if (showRow && wattageFilterValue) {
                if (wattageCell) {
                    txtValue = (wattageCell.textContent || wattageCell.innerText).toLowerCase().replace('w','');
                    if (txtValue !== wattageFilterValue) {
                        showRow = false;
                    }
                } else { showRow = false; }
            }
            
            tr[i].style.display = showRow ? "" : "none";
        }
    }

    // Attach event listeners for pallet table filters
    const palletSearchInputEl = document.getElementById("palletSearchInput");
    if (palletSearchInputEl) palletSearchInputEl.addEventListener('keyup', filterPalletsTable);

    const palletStatusFilterEl = document.getElementById("palletStatusFilter");
    if (palletStatusFilterEl) palletStatusFilterEl.addEventListener('change', filterPalletsTable);

    const palletWattageFilterEl = document.getElementById("palletWattageFilter");
    if (palletWattageFilterEl) palletWattageFilterEl.addEventListener('change', filterPalletsTable);

    // Export Deliveries CSV
    document.getElementById('exportDeliveriesCsvBtn').addEventListener('click', function() {
        var currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('export_deliveries_csv', '1');
        window.location.href = currentUrl.toString();
    });

    // Export Available Pallets CSV
    document.getElementById('exportAvailablePalletsCsvBtn').addEventListener('click', function() {
        var currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('export_available_pallets_csv', '1');
        window.location.href = currentUrl.toString();
    });

</script>
</body>
</html>
<?php
if ($conn) {
    $conn->close();
}
?> 