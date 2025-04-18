<?php
session_name("logistics_session");
session_start();

// Ensure the user is either an admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

// --- Fetch Projects --- 
$sqlProjects        = "";
$paramTypesProjects = "";
$paramsProjects     = [];
$account_id_for_admin = null; // Define it here for potential reuse

// If the user is global_admin, they can see all projects
if ($role === 'global_admin') {
    $sqlProjects = "
        SELECT p.id, p.project_name, c.name AS account_name,
               SUM(pwo.wattage * pwo.total_order) AS project_size
          FROM projects p
          JOIN customer_accounts c ON p.account_id = c.id
          LEFT JOIN project_wattage_orders pwo ON p.id = pwo.project_id
         GROUP BY p.id, p.project_name, c.name
         ORDER BY c.name ASC, p.project_name ASC
    ";
} elseif ($role === 'admin') {
    // Look up the admin's single account_id
    $sqlOne = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtOne = $conn->prepare($sqlOne);
    if (!$stmtOne) die("Error preparing account lookup: " . $conn->error);
    $stmtOne->bind_param("i", $user_id);
    $stmtOne->execute();
    $stmtOne->bind_result($account_id_for_admin);
    $stmtOne->fetch();
    $stmtOne->close();

    if (empty($account_id_for_admin)) {
        // Handle case where admin has no assigned account - maybe show message or redirect
        // For now, we'll let the project query return empty results.
         $sqlProjects = "SELECT NULL LIMIT 0"; // No projects if no account
    } else {
        $sqlProjects = "
            SELECT p.id, p.project_name, c.name AS account_name,
                   SUM(pwo.wattage * pwo.total_order) AS project_size
              FROM projects p
              JOIN customer_accounts c ON p.account_id = c.id
              LEFT JOIN project_wattage_orders pwo ON p.id = pwo.project_id
             WHERE p.account_id = ?
             GROUP BY p.id, p.project_name, c.name
             ORDER BY p.project_name ASC
        ";
        $paramTypesProjects = "i";
        $paramsProjects     = [$account_id_for_admin];
    }
} else {
    header("Location: unauthorized");
    exit();
}

// Prepare and execute the projects query
$stmtProjects = $conn->prepare($sqlProjects);
if (!$stmtProjects) die("Error preparing projects query: " . $conn->error);
if (!empty($paramTypesProjects)) {
    $stmtProjects->bind_param($paramTypesProjects, ...$paramsProjects);
}
$stmtProjects->execute();
$resultProjects = $stmtProjects->get_result();
$stmtProjects->close();


// --- Fetch Unassigned Modules --- 
$sqlUnassigned        = "";
$paramTypesUnassigned = "";
$paramsUnassigned     = [];

if ($role === 'global_admin') {
    $sqlUnassigned = "
        SELECT um.id, um.vendor_name, um.initial_location, c.name AS account_name
        FROM unassigned_modules um
        JOIN customer_accounts c ON um.account_id = c.id
        ORDER BY c.name ASC, um.vendor_name ASC
    ";
} elseif ($role === 'admin' && !empty($account_id_for_admin)) {
     $sqlUnassigned = "
        SELECT um.id, um.vendor_name, um.initial_location, c.name AS account_name
        FROM unassigned_modules um
        JOIN customer_accounts c ON um.account_id = c.id
        WHERE um.account_id = ?
        ORDER BY um.vendor_name ASC
    ";
    $paramTypesUnassigned = "i";
    $paramsUnassigned     = [$account_id_for_admin];
} else {
    // Admin with no account or other roles see no unassigned modules
     $sqlUnassigned = "SELECT NULL LIMIT 0";
}

$stmtUnassigned = $conn->prepare($sqlUnassigned);
if (!$stmtUnassigned) die("Error preparing unassigned modules query: " . $conn->error);
if (!empty($paramTypesUnassigned)) {
    $stmtUnassigned->bind_param($paramTypesUnassigned, ...$paramsUnassigned);
}
$stmtUnassigned->execute();
$resultUnassigned = $stmtUnassigned->get_result();
$unassignedModulesData = [];

// Fetch items for each unassigned module batch
$stmtItems = $conn->prepare("SELECT wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ?");
if (!$stmtItems) die("Error preparing item query: " . $conn->error);

while ($batch = $resultUnassigned->fetch_assoc()) {
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
    $unassignedModulesData[] = $batch;
}
$stmtItems->close();
$stmtUnassigned->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Projects & Unassigned Modules</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 8px 12px;
        }
        th {
            background: #f9f9f9;
        }

        /* Highlight selected row */
        tr.selected {
            background-color: #d9edf7; /* highlight color */
        }

        /* Action buttons */
        .action-button {
            background-color: #488C9A;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            padding: 7px 15px;
            margin: 5px 2px;
            font-weight: bold;
            cursor: pointer;
            border: none;
        }
        .action-button:hover {
            background-color: #293E4C;
        }

        /* Buttons when disabled: lighter color, no hover effect, not-allowed cursor */
        .action-button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        .action-button:disabled:hover {
            background-color: #ccc;
        }

        /* Align the top action bar to the right */
        .top-actions {
            text-align: right;
            margin: 20px 0;
        }

        /* For the deliveries/warehouse forms in the table */
        .action-forms {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
        }
        .action-forms form {
            margin: 0;
            padding: 0;
            display: inline-block;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Manage Projects & Unassigned Modules</h1>

    <!-- 
       A top bar (aligned right) containing Add/Edit/Delete actions.
    -->
    <div class="top-actions">
        <button class="action-button" onclick="window.location.href='add_project.php'">Add Project</button>
        <button class="action-button" onclick="window.location.href='add_unassigned_module.php'">Add Unassigned Modules</button>
        <button id="btnEdit" class="action-button" disabled onclick="handleEdit()">Edit Project</button>
        <button id="btnDelete" class="action-button" disabled onclick="handleDelete()">Delete Project</button>
    </div>

    <h2>Active Projects</h2>
    <table id="projectsTable">
        <thead>
            <tr>
                <th>Customer Account</th>
                <th>Project Name</th>
                <th>Project Size (MW)</th>
                <th>Project Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($resultProjects && $resultProjects->num_rows > 0): ?>
            <?php while ($project = $resultProjects->fetch_assoc()): ?>
                <tr onclick="selectRow(this, '<?php echo $project['id']; ?>')">
                    <td><?php echo htmlspecialchars($project['account_name']); ?></td>
                    <td><?php echo htmlspecialchars($project['project_name']); ?></td>
                    <td>
                        <?php
                            $project_size    = (float)($project['project_size'] ?? 0);
                            $project_size_mw = $project_size / 1_000_000; // convert watts to MW
                            echo number_format($project_size_mw, 2) . ' MW';
                        ?>
                    </td>
                    <td>
                        <div class="action-forms">
                            <form action="manage_deliveries" method="GET">
                                <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                <button type="submit" class="action-button">Deliveries</button>
                            </form>
                            <form action="warehouse_info" method="GET">
                                <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                <button type="submit" class="action-button">Warehouse</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="4">No projects found.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <hr style="margin: 40px 0;">

    <h2>Unassigned Modules</h2>
     <table id="unassignedModulesTable">
        <thead>
            <tr>
                <th>Customer Account</th>
                <th>Vendor Name</th>
                <th>Initial Location</th>
                <th>Total Quantity</th>
                <th>Module Details</th>
                <th>Batch Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($unassignedModulesData)): ?>
            <?php foreach ($unassignedModulesData as $batch): ?>
                <tr>
                    <td><?php echo htmlspecialchars($batch['account_name']); ?></td>
                    <td><?php echo htmlspecialchars($batch['vendor_name']); ?></td>
                    <td><?php echo htmlspecialchars($batch['initial_location']); ?></td>
                    <td><?php echo number_format($batch['total_quantity']); ?></td>
                    <td>
                        <?php
                            $details = [];
                            foreach ($batch['items'] as $item) {
                                $details[] = htmlspecialchars((int)$item['wattage']) . 'W: ' . number_format($item['quantity']);
                            }
                            echo implode(', ', $details);
                        ?>
                    </td>
                    <td>
                        <div class="action-forms">
                            <button class="action-button" onclick="window.location.href='unassigned_module_overview.php?batch_id=<?php echo $batch['id']; ?>'">View Details</button>
                            <button class="action-button" onclick="window.location.href='edit_unassigned_module.php?batch_id=<?php echo $batch['id']; ?>'">Edit Batch</button>
                            <button class="action-button" onclick="alert('Movement tracking page not yet implemented')" title="View detailed movement history (Not Implemented)">View Movements</button>
                            <button class="action-button" onclick="if(confirm('Delete this batch?')) { alert('Implement delete_unassigned_batch.php?id=<?php echo $batch['id']; ?>') }">Delete Batch</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="6">No unassigned module batches found.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

</main>

<script>
    let selectedRow       = null;
    let selectedProjectId = null;

    // Called when a row is clicked
    function selectRow(row, projectId) {
        // If there's an already-selected row and it's different from this row, unselect it
        if (selectedRow && selectedRow !== row) {
            selectedRow.classList.remove('selected');
        }
        // Select the new row
        row.classList.add('selected');

        // Update the global reference
        selectedRow       = row;
        selectedProjectId = projectId;

        // Enable the top Edit/Delete buttons
        document.getElementById('btnEdit').disabled   = false;
        document.getElementById('btnDelete').disabled = false;
    }

    function handleEdit() {
        if (!selectedProjectId) return;
        // Navigate to edit_project?project_id=...
        window.location.href = 'edit_project?project_id=' + selectedProjectId;
    }

    function handleDelete() {
        if (!selectedProjectId) return;
        if (!confirm('Are you sure you want to delete this project?')) {
            return;
        }
        // Navigate to delete_project?id=...
        window.location.href = 'delete_project?id=' + selectedProjectId;
    }
</script>
</body>
</html>
