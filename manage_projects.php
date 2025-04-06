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

// We will set up different queries based on user role
$sql        = "";
$paramTypes = "";
$params     = [];

// If the user is global_admin, they can see all projects
if ($role === 'global_admin') {
    $sql = "
        SELECT p.id,
               p.project_name,
               c.name AS account_name,
               SUM(pwo.wattage * pwo.total_order) AS project_size
          FROM projects p
          JOIN customer_accounts c
               ON p.account_id = c.id
          LEFT JOIN project_wattage_orders pwo
               ON p.id = pwo.project_id
         GROUP BY p.id, p.project_name, c.name
         ORDER BY p.id ASC
    ";
// If the user is admin, they can only see projects for their specific account_id
} elseif ($role === 'admin') {
    // Look up the admin's single account_id from the customer_account_users table
    $sqlOne = "
        SELECT account_id
        FROM customer_account_users
        WHERE user_id = ?
          AND role = 'admin'
        LIMIT 1
    ";
    $stmtOne = $conn->prepare($sqlOne);
    if (!$stmtOne) {
        die("Error preparing statement: " . $conn->error);
    }
    $stmtOne->bind_param("i", $user_id);
    $stmtOne->execute();
    $stmtOne->bind_result($account_id_for_admin);
    $stmtOne->fetch();
    $stmtOne->close();

    if (empty($account_id_for_admin)) {
        // If we cannot find an account for this admin, treat it as unauthorized or no results
        header("Location: unauthorized");
        exit();
    }

    // Now build the query filtering by that account_id
    $sql = "
        SELECT p.id,
               p.project_name,
               c.name AS account_name,
               SUM(pwo.wattage * pwo.total_order) AS project_size
          FROM projects p
          JOIN customer_accounts c
               ON p.account_id = c.id
          LEFT JOIN project_wattage_orders pwo
               ON p.id = pwo.project_id
         WHERE p.account_id = ?
         GROUP BY p.id, p.project_name, c.name
         ORDER BY p.id ASC
    ";

    // We'll bind the admin's account_id
    $paramTypes = "i";
    $params     = [$account_id_for_admin];

} else {
    // Fallback if somehow user is neither admin nor global_admin
    header("Location: unauthorized");
    exit();
}

// Prepare and execute the final query
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error preparing query: " . $conn->error);
}

if (!empty($paramTypes)) {
    $stmt->bind_param($paramTypes, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Projects</title>
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
            margin-top: 20px;
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
    <h1>Manage Projects</h1>

    <!-- 
       A top bar (aligned right) containing Edit & Delete.
       Initially disabled until the user selects exactly one row.
    -->
    <div class="top-actions">
        <button id="btnEdit" class="action-button" disabled onclick="handleEdit()">Edit</button>
        <button id="btnDelete" class="action-button" disabled onclick="handleDelete()">Delete</button>
    </div>

    <table id="projectsTable">
        <thead>
            <tr>
                <th>Customer Account</th>
                <th>Project Name</th>
                <th>Project Size (MW)</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($project = $result->fetch_assoc()): ?>
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
                            <!-- Keep "Deliveries" and "Warehouse" here, as requested -->
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
