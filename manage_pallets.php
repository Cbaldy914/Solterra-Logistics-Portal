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
$errorMessage = '';

try {
    // Comprehensive query to fetch pallet details and their current location name
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
                CASE
                    WHEN ip.status = 'At Manufacturer' THEN 'At Manufacturer'
                    WHEN ip.status = 'In Warehouse' AND w.name IS NOT NULL THEN CONCAT('Warehouse: ', w.name)
                    WHEN ip.status = 'In Transit to Warehouse' AND w.name IS NOT NULL THEN CONCAT('In Transit to Warehouse: ', w.name) /* Assuming pallets move FROM a location TO a warehouse */
                    WHEN ip.status = 'Delivered to Project' AND p.project_name IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                    WHEN ip.status = 'In Transit to Project' AND p.project_name IS NOT NULL THEN CONCAT('In Transit to Project: ', p.project_name)
                    ELSE ip.status /* Fallback to status if location name isn't available or status is different */
                END AS current_location_display
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
            LEFT JOIN projects p ON ip.current_project_id = p.id
            ORDER BY ip.id DESC"; // Order by most recent pallets first
            
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pallets[] = $row;
        }
    } else {
        throw new Exception("Error fetching pallets: " . $conn->error);
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

    <!-- Add Filters/Search Here -->
    <div class="filter-container">
        <label for="filterInput">Search:</label>
        <input type="text" id="filterInput" onkeyup="filterTable()" placeholder="Filter by ID, Vendor, Project, Wattage, Status, Location...">
        <!-- Add more specific filters if needed (e.g., dropdown for status) -->
    </div>

    <div class="table-responsive">
        <table id="palletsTable">
            <thead>
                <tr>
                    <th>Pallet ID</th>
                    <th>Identifier</th>
                    <th>Origin Vendor</th>
                    <th>Wattage</th>
                    <th>Quantity</th>
                    <th>Status</th>
                    <th>Current Location</th>
                    <th>Project</th>
                    <th>Arrival Date</th>
                    <!-- Add Actions column if needed (e.g., View History) -->
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($pallets)): ?>
                    <?php foreach ($pallets as $pallet): ?>
                        <tr>
                            <td><?php echo $pallet['pallet_id']; ?></td>
                            <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($pallet['origin_vendor'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                            <td><?php echo number_format($pallet['quantity']); ?></td>
                            <td><?php echo htmlspecialchars($pallet['status']); ?></td>
                            <td><?php echo htmlspecialchars($pallet['current_location_display']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($pallet['current_project_name'] ?? 'Unassigned'); ?>
                            </td>
                            <td><?php echo htmlspecialchars($pallet['arrival_date'] ?? 'N/A'); ?></td>
                            <!-- Actions Placeholder -->
                            <!-- <td><a href="pallet_history.php?pallet_id=<?php echo $pallet['pallet_id']; ?>">View History</a></td> -->
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9">No pallets found in the system.</td>
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
// Simple table text filter
function filterTable() {
    var input, filter, table, tr, td, i, j, txtValue;
    input = document.getElementById("filterInput");
    filter = input.value.toUpperCase();
    table = document.getElementById("palletsTable");
    tr = table.getElementsByTagName("tr");

    // Loop through all table rows (start from 1 to skip header)
    for (i = 1; i < tr.length; i++) {
        tr[i].style.display = "none"; // Hide row initially
        td = tr[i].getElementsByTagName("td");
        // Loop through all columns in the current row
        for (j = 0; j < td.length; j++) { 
            if (td[j]) {
                txtValue = td[j].textContent || td[j].innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = ""; // Show row if match found
                    break; // No need to check other columns in this row
                }
            }
        }
    }
}
</script>

</body>
</html> 