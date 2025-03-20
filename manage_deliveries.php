<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Validate the project ID
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}
$project_id = intval($_GET['project_id']);

// Set up time filter variables (if not passed, use defaults)
$time_filter   = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date      = isset($_GET['ref_date']) ? $_GET['ref_date'] : date('Y-m-d');
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';

// Database connection
$servername   = "localhost";
$db_username  = "SolterraSolutions";
$db_password  = "CompanyAdmin!";
$dbname       = "solterra_portal";
$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize messages
if (!isset($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

// Fetch the project name and default freight cost (still used for display logic)
$stmt = $conn->prepare("SELECT project_name, default_freight_cost FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($project_name, $default_freight_cost);
$stmt->fetch();
$stmt->close();

/*
  -----------------------------------------------------------------------------
  1) Handle Bulk Edit
  -----------------------------------------------------------------------------
*/
if (isset($_POST['bulk_edit_submit'])) {
    if (isset($_POST['selected_deliveries']) && !empty($_POST['selected_deliveries'])) {
        $selected_ids = array_map('intval', $_POST['selected_deliveries']);

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
    header("Location: manage_deliveries?project_id=$project_id&time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter));
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
        $ids_placeholder = implode(',', array_fill(0, count($selected_ids), '?'));
        $stmt = $conn->prepare("DELETE FROM deliveries WHERE id IN ($ids_placeholder) AND project_id = ?");
        $types  = str_repeat('i', count($selected_ids)) . 'i';
        $params = array_merge($selected_ids, [$project_id]);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            $_SESSION['messages'][] = "<p>Selected deliveries have been deleted.</p>";
        } else {
            $_SESSION['messages'][] = "<p>Error deleting deliveries: " . $stmt->error . "</p>";
        }
        $stmt->close();
    } else {
        $_SESSION['messages'][] = "<p>No deliveries selected for deletion.</p>";
    }

    header("Location: manage_deliveries?project_id=$project_id&time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter));
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

                // Check if this delivery (by BOL number) already exists
                $stmt = $conn->prepare("SELECT id FROM deliveries WHERE project_id = ? AND bol_number = ?");
                $stmt->bind_param("is", $project_id, $bol_number);
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
                    $insert_stmt->bind_param(
                        "isssissssddd",
                        $project_id,
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
            header("Location: manage_deliveries?project_id=$project_id&time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter));
            exit();

        } else {
            $_SESSION['messages'][] = "<p>Invalid file type or file too large. Please upload a valid CSV file (max 2MB).</p>";
            header("Location: manage_deliveries?project_id=$project_id&time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter));
            exit();
        }
    } else {
        $_SESSION['messages'][] = "<p>Error uploading the file. Please try again.</p>";
        header("Location: manage_deliveries?project_id=$project_id&time_filter=" . urlencode($time_filter) . "&ref_date=" . urlencode($ref_date) . "&status_filter=" . urlencode($status_filter));
        exit();
    }
}

/*
  -----------------------------------------------------------------------------
  4) Fetch Deliveries for Display
  -----------------------------------------------------------------------------
*/
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";
$dateCondition = "";
$paramTypes    = "di"; // for default_freight_cost (d), project_id (i)
$params        = [$default_freight_cost, $project_id];

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
    SELECT *,
           IFNULL(freight_cost, ?) AS freight_cost_with_default
    FROM deliveries
    WHERE project_id = ?
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
$stmt = $conn->prepare("
    SELECT 
        SUM(IF(status_of_delivery = 'delivered', IFNULL(freight_cost, ?), 0)) AS total_freight_cost,
        SUM(IFNULL(accessorial_costs, 0)) AS total_accessorial_costs
    FROM deliveries
    WHERE project_id = ?
");
$stmt->bind_param("di", $default_freight_cost, $project_id);
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
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Manage Deliveries for <?php echo htmlspecialchars($project_name); ?></h1>

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
            <form action="manage_deliveries?project_id=<?php echo $project_id; ?>" method="post" enctype="multipart/form-data">
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit" name="upload_csv">Upload CSV</button>
            </form>
            <div class="single-entry">
                <h3>Add Single Entry:
                    <button type="button" class="add-single-entry-button"
                            onclick="window.location.href='add_delivery?project_id=<?php echo $project_id; ?>';">+</button>
                </h3>
            </div>
        </div>

        <!-- Bulk Actions Section -->
        <div class="middle-section">
            <h2>Bulk Edit / Bulk Delete</h2>
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
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=all"
               class="<?php echo ($time_filter === 'all') ? 'active' : ''; ?>">All</a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=day&ref_date=<?php echo $ref_date; ?>"
               class="<?php echo ($time_filter === 'day') ? 'active' : ''; ?>">Day</a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=week&ref_date=<?php echo $ref_date; ?>"
               class="<?php echo ($time_filter === 'week') ? 'active' : ''; ?>">Week</a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=month&ref_date=<?php echo $ref_date; ?>"
               class="<?php echo ($time_filter === 'month') ? 'active' : ''; ?>">Month</a>
        </div>
        <!-- Center: Date Navigation -->
        <div class="date-navigation">
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>'">
                    &larr;
                </button>
            <?php endif; ?>
            <span class="date-label"><?php echo $dateLabel; ?></span>
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>'">
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
            <form method="get" action="" style="display: flex; gap: 10px;">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
                <input type="hidden" name="ref_date" value="<?php echo $ref_date; ?>">
                <label for="status_filter" style="align-self: center;">Filter by Status:</label>
                <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="Pending" <?php if($status_filter === 'Pending') echo 'selected'; ?>>Pending</option>
                    <option value="In Transit" <?php if($status_filter === 'In Transit') echo 'selected'; ?>>In Transit</option>
                    <option value="Delivered" <?php if($status_filter === 'Delivered') echo 'selected'; ?>>Delivered</option>
                    <option value="Complete" <?php if($status_filter === 'Complete') echo 'selected'; ?>>Complete</option>
                </select>
            </form>
        </div>
    </div>

    <!-- Deliveries Form + Table -->
    <form action="manage_deliveries?project_id=<?php echo $project_id; ?>" method="post" id="deliveriesForm">
        <!-- Preserve filter parameters -->
        <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter); ?>">
        <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">

        <div class="table-responsive">
            <table class="deliveries-table" id="deliveriesTable">
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
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
                    <th>Actions</th>
                </tr>
                <?php if ($deliveries_result->num_rows > 0): ?>
                    <?php while($delivery = $deliveries_result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="selected_deliveries[]" value="<?php echo $delivery['id']; ?>" onclick="updateBulkActionButtons()">
                            </td>
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
                                <a href="edit_delivery?delivery_id=<?php echo $delivery['id']; ?>&project_id=<?php echo $project_id; ?>">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="14">No delivery entries found.</td>
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
            
            <form method="post" action="manage_deliveries?project_id=<?php echo $project_id; ?>">
                <!-- Keep time/status filters in case you want to preserve them after submit -->
                <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter); ?>">
                <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">

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
        container.innerHTML = ''; // clear old data

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
</script>
</body>
</html>
<?php
$conn->close();
?>
