<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
// $conn = getDBConnection(); // Connection will be established before CSV or main data fetch

$pallets = [];
$projects = [];
$errorMessage = '';
$successMessage = $_SESSION['manage_pallets_message'] ?? ''; // Check for messages from edit_pallet redirect
$warningDetails = $_SESSION['manage_pallets_warning'] ?? null; // Check for warning message
if (isset($_SESSION['manage_pallets_message'])) unset($_SESSION['manage_pallets_message']);
if (isset($_SESSION['manage_pallets_warning'])) unset($_SESSION['manage_pallets_warning']);

// --- Handle CSV Upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileSize = $_FILES['csv_file']['size'];
        $fileType = $_FILES['csv_file']['type'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));

        $allowedfileExtensions = array('csv');
        if (in_array($fileExtension, $allowedfileExtensions)) {
            $csvFile = fopen($fileTmpPath, 'r');
            if ($csvFile === false) {
                $errorMessage = "Error: Could not open the CSV file.";
            } else {
                fgetcsv($csvFile); // Skip header row

                $conn_csv = getDBConnection();
                if (!$conn_csv) {
                    $errorMessage = "Error: Could not connect to the database for CSV processing.";
                } else {
                    $conn_csv->begin_transaction();
                    $updated_count = 0;
                    $skipped_count = 0;
                    $error_rows = [];
                    $wattage_changed_flag = false;

                    // Prepare statement for updating pallets by id
                    // Fields updatable via CSV: pallet_identifier, wattage, flash_test_data
                    $stmt_update = $conn_csv->prepare("UPDATE inventory_pallets SET pallet_identifier = ?, wattage = ?, flash_test_data = ? WHERE id = ?");

                    if (!$stmt_update) {
                        $errorMessage = "Error preparing update statement: " . $conn_csv->error;
                    } else {
                        $rowNumber = 1;
                        while (($row_data = fgetcsv($csvFile)) !== FALSE) {
                            $rowNumber++;
                            if (empty(array_filter($row_data))) {
                                continue;
                            }
                            // Expected columns: id, pallet_identifier, wattage, (quantity - informational), (status - informational), (damaged_quantity - informational), flash_test_data
                            // Actual columns used for update: id, pallet_identifier, wattage, flash_test_data
                            if (count($row_data) >= 7) { // Ensure enough columns for core + informational
                                $id              = trim($row_data[0]);
                                $identifier      = trim($row_data[1]);
                                $wattage_csv     = trim($row_data[2]);
                                // quantity (index 3) - informational
                                // status (index 4) - informational
                                // damaged_quantity (index 5) - informational (if we add it later)
                                $flash_data_ref  = trim($row_data[6]); // Assuming flash_test_data is the 7th column (index 6)

                                if (empty($id) || !is_numeric($id)) {
                                    $error_rows[] = "Row {$rowNumber}: id is missing or invalid.";
                                    $skipped_count++;
                                    continue;
                                }
                                if (empty($identifier)) {
                                    $error_rows[] = "Row {$rowNumber}: pallet_identifier for id '{$id}' is missing.";
                                    $skipped_count++;
                                    continue;
                                }
                                if (empty($wattage_csv)) {
                                    $error_rows[] = "Row {$rowNumber}: Wattage for id '{$id}' cannot be empty.";
                                    $skipped_count++;
                                    continue;
                                }

                                // Check if wattage is being changed
                                $stmt_check_wattage = $conn_csv->prepare("SELECT wattage FROM inventory_pallets WHERE id = ?");
                                $stmt_check_wattage->bind_param("i", $id);
                                $stmt_check_wattage->execute();
                                $stmt_check_wattage->bind_result($current_wattage_db);
                                if ($stmt_check_wattage->fetch()) {
                                    if ($current_wattage_db != $wattage_csv) {
                                        $wattage_changed_flag = true;
                                    }
                                }
                                $stmt_check_wattage->close();

                                $stmt_update->bind_param("sssi", $identifier, $wattage_csv, $flash_data_ref, $id);
                                if ($stmt_update->execute()) {
                                    if ($stmt_update->affected_rows > 0) {
                                        $updated_count++;
                                    } else {
                                        $error_rows[] = "Row {$rowNumber}: Pallet with id '{$id}' not found or data unchanged.";
                                        $skipped_count++;
                                    }
                                } else {
                                    $error_rows[] = "Row {$rowNumber}: DB error updating pallet with id '{$id}': " . $stmt_update->error;
                                    $skipped_count++;
                                }
                            } else {
                                $error_rows[] = "Row {$rowNumber}: Insufficient columns. Expected at least 7 (id, pallet_identifier, wattage, quantity, status, damaged_quantity, flash_test_data). Row skipped.";
                                $skipped_count++;
                            }
                        }
                        $stmt_update->close();
                    }
                    fclose($csvFile);

                    if (empty($error_rows) || $updated_count > 0) {
                        $conn_csv->commit();
                        $successMessage = "CSV Processed. Successfully updated {$updated_count} pallets.";
                        if ($wattage_changed_flag) {
                            $successMessage .= " <br><b>Warning:</b> Wattage was changed for one or more pallets. Please review the original module batch details in 'Manage Modules' to ensure consistency.";
                        }
                        if ($skipped_count > 0) {
                            $errorMessage .= " Skipped {$skipped_count} rows due to errors or missing ids. Details: " . implode("; ", $error_rows);
                        }
                    } else {
                        $conn_csv->rollback();
                        $errorMessage .= " CSV import failed. No pallets were updated. Errors: " . implode("; ", $error_rows);
                    }
                    $conn_csv->close();
                }
            }
        } else {
            $errorMessage = "Upload failed. Allowed file types: " . implode(',', $allowedfileExtensions);
        }
    } else {
        $errorMessage = "Error uploading file. Code: " . ($_FILES['csv_file']['error'] ?? 'Unknown error');
    }
}


try {
    $conn = getDBConnection(); // Ensure connection is (re)established
    // Comprehensive query to fetch pallet details, their current location name, and delivery association count
    $sql = "SELECT 
                ip.id AS pallet_id,
                ip.pallet_identifier,
                ip.wattage,
                ip.quantity,
                ip.status,
                ip.arrival_date,
                ip.unassigned_module_item_id, /* For tracing origin if needed */
                ip.current_warehouse_id,
                ip.current_project_id,
                ip.flash_test_data, /* <<< Added flash_test_data */
                m.vendor_name AS origin_vendor,
                w.name AS current_warehouse_name,
                p.project_name AS current_project_name,
                (SELECT COUNT(*) FROM delivery_pallets dp WHERE dp.inventory_pallet_id = ip.id) AS delivery_association_count,
                CASE
                    WHEN ip.status = 'At Manufacturer' THEN 'At Manufacturer'
                    WHEN ip.status = 'In Warehouse' AND w.name IS NOT NULL THEN CONCAT('Warehouse: ', w.name)
                    WHEN ip.status = 'In Transit to Warehouse' AND w.name IS NOT NULL THEN CONCAT('In Transit to Warehouse: ', w.name)
                    WHEN ip.status = 'Delivered to Project' AND p.project_name IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                    WHEN ip.status = 'In Transit to Project' AND p.project_name IS NOT NULL THEN CONCAT('In Transit to Project: ', p.project_name)
                    ELSE ip.status
                END AS current_location_display
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
            LEFT JOIN projects p ON ip.current_project_id = p.id
            ORDER BY ip.id DESC";
            
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pallets[] = $row;
        }
    } else {
        throw new Exception("Error fetching pallets: " . $conn->error);
    }

    // Fetch all projects for filter dropdown
    $sqlProjects = "SELECT id, project_name FROM projects ORDER BY project_name ASC";
    $resultProjects = $conn->query($sqlProjects);
    if ($resultProjects) {
        while ($row = $resultProjects->fetch_assoc()) {
            $projects[] = $row;
        }
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

if ($conn) { // Close connection if it was opened
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Pallets</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .filter-container {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            display: flex; /* Use flexbox for alignment */
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
            gap: 15px; /* Space between items */
            align-items: center; /* Align items vertically */
        }
        .filter-container label {
            margin-right: 5px; /* Reduced margin */
            font-weight: 500;
            white-space: nowrap; /* Prevent label wrapping */
        }
        .filter-container input[type="text"],
        .filter-container select {
            padding: 8px;
            /* margin-right: 15px; Removed, using gap */
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .filter-container input[type="text"] {
            flex-grow: 1; /* Allow search input to take more space */
            min-width: 200px; /* Minimum width for search */
        }
        .filter-container .export-button-container {
            margin-left: auto; /* Push export button to the right */
        }

        .success-message {
            color: green;
            background-color: #e6ffed;
            padding: 10px;
            border: 1px solid green;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .error-message {
            color: red;
            background-color: #ffe6e6;
            padding: 10px;
            border: 1px solid red;
            border-radius: 5px;
            margin-bottom: 20px;
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
            display: inline-block;
            white-space: nowrap; /* Prevent button text wrapping */
        }
        .action-button:hover {
            background-color: #3A6E7F;
        }
        /* Modal Basic Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1001; /* Ensure it's above other content */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 5px;
            position: relative;
        }
        .close-button {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Manage All Pallets</h1>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message">
            <?php echo htmlspecialchars($successMessage); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($warningDetails) && is_array($warningDetails)): ?>
        <div class="warning-message" style="background-color: #fff3cd; border-color: #ffeeba; color: #856404; padding: 10px; margin-bottom: 15px; border: 1px solid; border-radius: 4px;">
            <?php echo htmlspecialchars($warningDetails['message']); ?>
            <?php if (!empty($warningDetails['batch_id'])): ?>
                <a href="edit_module.php?batch_id=<?php echo htmlspecialchars($warningDetails['batch_id']); ?>" style="margin-left: 10px; font-weight: bold;">Update Batch Details</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <!-- CSV Upload Section -->
    <div class="csv-upload-section" style="margin-bottom: 20px; padding: 15px; background-color: #f0f0f0; border: 1px solid #ddd; border-radius: 5px;">
        <h3>
            Bulk Update Pallets via CSV
            <button type="button" id="openCsvInstructions" class="action-button" style="font-size: 0.8em; padding: 3px 8px; background-color: #6c757d;">(View Instructions)</button>
        </h3>
        <form action="manage_pallets.php" method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <button type="submit" name="upload_csv" class="action-button" style="background-color: #28a745;">Upload and Process CSV</button>
        </form>
    </div>

    <!-- Filter Container -->
    <div class="filter-container">
        <label for="filterInput">Search:</label>
        <input type="text" id="filterInput" onkeyup="filterTable()" placeholder="Filter table...">
        <label for="projectFilter">Project:</label>
        <select id="projectFilter" onchange="filterTable()">
            <option value="">All Projects</option>
            <option value="Unassigned">Unassigned</option>
            <?php foreach ($projects as $proj): ?>
                <option value="<?php echo htmlspecialchars($proj['project_name']); ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
            <?php endforeach; ?>
        </select>
         <div class="export-button-container">
             <button id="exportCsvBtn" class="action-button" style="background-color: #17a2b8;">Export Visible Data to CSV</button>
        </div>
    </div>

    <div class="table-responsive">
        <table id="palletsTable">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Identifier</th>
                    <th>Origin Vendor</th>
                    <th>Wattage</th>
                    <th>Quantity</th>
                    <th>Status</th>
                    <th>Current Location</th>
                    <th>Associated Deliveries</th>
                    <th>Arrival Date</th>
                    <th>Flash Test Data</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($pallets)): ?>
                    <?php foreach ($pallets as $pallet): ?>
                        <tr data-id="<?php echo htmlspecialchars($pallet['pallet_id']); ?>">
                            <td><?php echo htmlspecialchars($pallet['current_project_name'] ?? 'Unassigned'); ?></td>
                            <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($pallet['wattage']); ?></td>
                            <td><?php echo number_format($pallet['quantity']); ?></td>
                            <td><?php echo htmlspecialchars($pallet['status']); ?></td>
                            <td><?php echo htmlspecialchars($pallet['current_location_display']); ?></td>
                            <td><?php echo $pallet['delivery_association_count']; ?></td>
                            <td><?php echo htmlspecialchars($pallet['arrival_date'] ?? 'N/A'); ?></td>
                            <td>
                                <?php if (!empty($pallet['flash_test_data'])): ?>
                                    <a href="view_flash_test.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" target="_blank" class="action-button" style="background-color: #5bc0de;">View</a>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="pallet_details.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" class="action-button">Pallet Details</a>
                                <button class="action-button" onclick="window.location.href='edit_pallet.php?pallet_id=<?php echo $pallet['pallet_id']; ?>'" style="background-color:#f0ad4e;">Edit Pallet</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11">No pallets found in the system.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="back-link" style="margin-top: 20px;">
        <a href="admin_dashboard.php" class="action-button">&larr; Back to Admin Dashboard</a>
    </div>
</main>

<!-- CSV Instructions Modal -->
<div id="csvInstructionsModal" class="modal">
    <div class="modal-content">
        <span class="close-button" onclick="document.getElementById('csvInstructionsModal').style.display='none'">&times;</span>
        <h2>CSV File Format Instructions</h2>
        <p><strong>Important:</strong> Only <code>pallet_identifier</code>, <code>wattage</code>, and <code>flash_test_data</code> can be updated via this CSV. Other fields like <code>quantity</code> and <code>status</code> are informational if included in your export and will not be changed by this import.</p>
        <ul>
            <li>The first row of the CSV file will be ignored (assumed to be a header row).</li>
            <li><strong>Columns in order:</strong>
                <ol>
                    <li><code>id</code> (Number, <b>Required</b>): The unique database ID. This is used to match records. <b>Do not change this value.</b></li>
                    <li><code>pallet_identifier</code> (Text, Updatable): The user-defined identifier (barcode, label, etc.).</li>
                    <li><code>wattage</code> (Text, Updatable): Wattage of the modules (e.g., 450W). If changed, you may need to update the original module batch.</li>
                    <li><code>quantity</code> (Number, Informational Only): Number of modules. <em>Cannot be updated via this CSV.</em></li>
                    <li><code>status</code> (Text, Informational Only): Current condition status. <em>Cannot be updated via this CSV.</em></li>
                    <li><code>damaged_quantity</code> (Number, Informational Only, if present): Number of damaged modules. <em>Cannot be updated via this CSV.</em></li>
                    <li><code>flash_test_data</code> (Text, Updatable): Filename or reference for flash test data.</li>
                </ol>
            </li>
            <li>The <code>id</code> column is used to match the correct pallet. Rows with missing/invalid <code>id</code> will be skipped.</li>
            <li>If <code>wattage</code> is updated, please manually review and update the corresponding unassigned module batch in "Manage Modules" if necessary.</li>
        </ul>
    </div>
</div>

<script>
// Combined filter for project and general search
function filterTable() {
    var input = document.getElementById("filterInput").value.toUpperCase();
    var projectFilterValue = document.getElementById("projectFilter").value;
    var table = document.getElementById("palletsTable");
    var tr = table.getElementsByTagName("tr");

    for (var i = 1; i < tr.length; i++) { // Start from 1 to skip header row
        tr[i].style.display = "none"; // Hide row initially
        var td = tr[i].getElementsByTagName("td");
        var projectCell = td[0]; // Project is the first column
        var actionCell = td[td.length - 1]; // Actions is the last column

        // Check Project Filter
        var matchesProject = false;
        if (projectFilterValue === "") { // "All Projects"
            matchesProject = true;
        } else if (projectFilterValue === "Unassigned") {
            matchesProject = projectCell && (projectCell.textContent || projectCell.innerText) === "Unassigned";
        } else {
            matchesProject = projectCell && (projectCell.textContent || projectCell.innerText) === projectFilterValue;
        }

        // Check Search Filter (iterate through relevant cells)
        var matchesSearch = false;
        if (input === "") {
            matchesSearch = true; // If search is empty, it's a match
        } else {
            // Iterate through all cells *except* the last one (Actions)
            for (var j = 0; j < td.length - 1; j++) {
                if (td[j]) {
                    var txtValue = td[j].textContent || td[j].innerText;
                    if (txtValue.toUpperCase().indexOf(input) > -1) {
                        matchesSearch = true;
                        break;
                    }
                }
            }
        }

        // Show row only if both filters match
        if (matchesProject && matchesSearch) {
            tr[i].style.display = "";
        }
    }
}

// CSV Instructions Modal
document.getElementById('openCsvInstructions').addEventListener('click', function() {
    document.getElementById('csvInstructionsModal').style.display = 'block';
});
// Close modal if clicked outside content
window.addEventListener('click', function(event) {
    var modal = document.getElementById('csvInstructionsModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
});

// --- START: Updated Export to CSV ---
document.getElementById('exportCsvBtn').addEventListener('click', function() {
    var table = document.getElementById('palletsTable');
    var rows = table.querySelectorAll('tbody tr');
    var csvData = [];

    var headers = [
        "id", // Key for import
        "pallet_identifier", // Updatable
        "wattage", // Updatable
        "quantity", // Informational
        "status", // Informational (current condition)
        // "damaged_quantity", // Add if this column exists in your HTML table and you want to export it
        "flash_test_data", // Updatable
        "Project", "Origin Vendor", "Current Location", "Associated Deliveries", "Arrival Date" // Contextual
    ];
    var escapedHeaders = headers.map(header => '"' + header.replace(/"/g, '""') + '"');
    csvData.push(escapedHeaders.join(','));

    rows.forEach(function(row) {
        if (row.style.display !== 'none') {
            var cells = row.querySelectorAll('td');
            var rowData = [];

            // Ensure columnMapping matches the actual HTML table structure and desired export order
            const columnMapping = {
                id: 'data-id', // From <tr data-id="...">
                pallet_identifier: 1, // Index in HTML table
                wattage: 3,
                quantity: 4,
                status: 5, 
                // damaged_quantity: X, // if you add it to the table
                flash_test_data: 9,
                Project: 0,
                Origin_Vendor: 2,
                Current_Location: 6,
                Associated_Deliveries: 7,
                Arrival_Date: 8
            };

            headers.forEach(function(headerKey) {
                let mapKey = headerKey.replace(/[\s-]+/g, '_').toLowerCase(); // Standardize key for mapping
                let cellRef = columnMapping[mapKey];
                var cellText = 'N/A';

                if (mapKey === 'id') {
                    cellText = row.getAttribute('data-id') || 'N/A';
                } else if (typeof cellRef === 'number' && cells[cellRef] !== undefined) {
                    if (mapKey === 'flash_test_data') {
                        var link = cells[cellRef].querySelector('a');
                        if (link) {
                            cellText = cells[cellRef].innerHTML.replace(/<a[^>]*>.*?<\/a>/i, '').trim();
                            if (!cellText || cellText.toUpperCase() === 'N/A') {
                                cellText = cells[cellRef].textContent.replace('View', '').trim();
                            }
                            if (!cellText || cellText.toUpperCase() === 'N/A') {
                                // Try to get filename from href if it looks like a path
                                let href = link.getAttribute('href');
                                if (href && href.includes('view_flash_test.php')) { // Or your actual view script
                                   // Attempt to extract from a potential data attribute or fallback
                                   cellText = link.getAttribute('data-filename') || 'File Present'; 
                                } else {
                                   cellText = 'File Present';
                                }
                            }
                        } else {
                            cellText = cells[cellRef].textContent || cells[cellRef].innerText;
                        }
                    } else {
                        cellText = cells[cellRef].textContent || cells[cellRef].innerText;
                    }
                } // else if (mapKey === 'damaged_quantity') { /* handle if added */ }
                 var escapedText = cellText.trim().replace(/"/g, '""');
                 if (escapedText.includes(",")) {
                     escapedText = '"' + escapedText + '"';
                 }
                 rowData.push(escapedText);
            });
            csvData.push(rowData.join(','));
        }
    });

    // Create and trigger download with \r\n newlines
    var csvContent = csvData.join("\r\n"); // Use \r\n for better compatibility
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement("a");
    var filename = "pallets_export_" + new Date().toISOString().slice(0,10) + ".csv";

    if (navigator.msSaveBlob) { // IE 10+
        navigator.msSaveBlob(blob, filename);
    } else if (link.download !== undefined) { // Modern browsers
        var url = URL.createObjectURL(blob);
        link.setAttribute("href", url);
        link.setAttribute("download", filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url); // Free up memory
    } else {
         // Fallback or error message
         alert("CSV export is not directly supported in this browser. Please try copying the data.");
    }
});
// --- END: Updated Export to CSV ---

</script>

</body>
</html> 