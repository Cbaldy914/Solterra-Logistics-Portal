<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

$pallets = [];
$projects = [];
$errorMessage = '';

try {
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

$conn->close();
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
        }
        .filter-container label {
            margin-right: 10px;
            font-weight: 500;
        }
        .filter-container input[type="text"],
        .filter-container select {
            padding: 8px;
            margin-right: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .error-message {
            color: red;
            background-color: #ffe6e6;
            padding: 10px;
            border: 1px solid red;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .action-button { /* Ensure action-button style is available if not in portal.css */
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
        }
        .action-button:hover {
            background-color: #3A6E7F;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Manage All Pallets</h1>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <div class="filter-container">
        <label for="filterInput">Search:</label>
        <input type="text" id="filterInput" onkeyup="filterTable()" placeholder="Filter by ID, Vendor, Wattage, Status, Location...">
        <label for="projectFilter">Project:</label>
        <select id="projectFilter" onchange="filterTable()">
            <option value="">All Projects</option>
            <option value="Unassigned">Unassigned</option>
            <?php foreach ($projects as $proj): ?>
                <option value="<?php echo htmlspecialchars($proj['project_name']); ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="table-responsive">
        <table id="palletsTable">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Pallet ID</th>
                    <th>Identifier</th>
                    <th>Origin Vendor</th>
                    <th>Wattage</th>
                    <th>Quantity</th>
                    <th>Status</th>
                    <th>Current Location</th>
                    <th>Associated Deliveries</th>
                    <th>Arrival Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($pallets)): ?>
                    <?php foreach ($pallets as $pallet): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pallet['current_project_name'] ?? 'Unassigned'); ?></td>
                            <td><?php echo $pallet['pallet_id']; ?></td>
                            <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                            <td><?php echo number_format($pallet['quantity']); ?></td>
                            <td><?php echo htmlspecialchars($pallet['status']); ?></td>
                            <td><?php echo htmlspecialchars($pallet['current_location_display']); ?></td>
                            <td><?php echo $pallet['delivery_association_count']; ?></td>
                            <td><?php echo htmlspecialchars($pallet['arrival_date'] ?? 'N/A'); ?></td>
                            <td><a href="pallet_details.php?pallet_id=<?php echo $pallet['pallet_id']; ?>" class="action-button">View Details</a></td>
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
        var projectCellText = td[0] ? td[0].textContent || td[0].innerText : "";

        var matchesProject = false;
        if (projectFilterValue === "") { // "All Projects"
            matchesProject = true;
        } else if (projectFilterValue === "Unassigned") {
            matchesProject = projectCellText === "Unassigned";
        } else {
            matchesProject = projectCellText === projectFilterValue;
        }

        var matchesSearch = false;
        if (input === "") {
            matchesSearch = true; // If search is empty, it's a match for the search part
        } else {
            for (var j = 0; j < td.length; j++) { // Iterate over all cells for general search
                if (td[j]) {
                    var txtValue = td[j].textContent || td[j].innerText;
                    if (txtValue.toUpperCase().indexOf(input) > -1) {
                        matchesSearch = true;
                        break; 
                    }
                }
            }
        }

        if (matchesProject && matchesSearch) {
            tr[i].style.display = "";
        }
    }
}
</script>

</body>
</html> 