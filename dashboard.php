<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// We'll need the user's role to decide which page to link to
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user'; // default to 'user' if not set

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Determine which account(s) the user belongs to
$user_id = $_SESSION['user_id'];
$sqlAccts = "
    SELECT account_id
    FROM customer_account_users
    WHERE user_id = ?
";
$stmtAccts = $conn->prepare($sqlAccts);
$stmtAccts->bind_param("i", $user_id);
$stmtAccts->execute();
$resultAccts = $stmtAccts->get_result();

$accountIds = [];
while ($row = $resultAccts->fetch_assoc()) {
    $accountIds[] = (int)$row['account_id'];
}
$stmtAccts->close();

// 2) If user has accounts, fetch projects for those accounts
$projects = [];
if (count($accountIds) > 0) {
    // Build an IN() clause with placeholders
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $sqlProj = "
        SELECT id, project_name, project_address, image_url, estimated_completion_date
        FROM projects
        WHERE account_id IN ($placeholders)
        ORDER BY id ASC
    ";
    $stmt = $conn->prepare($sqlProj);

    // Bind each account_id (all integers)
    $types = str_repeat('i', count($accountIds)); 
    $stmt->bind_param($types, ...$accountIds);

    $stmt->execute();
    $result = $stmt->get_result();

    // 3) For each project row, gather additional data
    while ($row = $result->fetch_assoc()) {
        $project_id = $row['id'];
        $project = $row;

        // ---- Fetch wattage orders
        $stmt_wattage_orders = $conn->prepare("
            SELECT wattage, total_order
            FROM project_wattage_orders
            WHERE project_id = ?
        ");
        $stmt_wattage_orders->bind_param("i", $project_id);
        $stmt_wattage_orders->execute();
        $wattage_orders_result = $stmt_wattage_orders->get_result();
        $stmt_wattage_orders->close();

        $total_mws = 0;
        $total_order_quantity = 0;
        while ($wattage_row = $wattage_orders_result->fetch_assoc()) {
            $wattage = (float)$wattage_row['wattage'];
            $torder  = (int)$wattage_row['total_order'];

            $total_order_quantity += $torder;
            $total_mws += ($wattage * $torder) / 1_000_000; // Convert to MW
        }
        $project['project_size'] = $total_mws;

        // ---- Modules Delivered
        $stmt_delivered = $conn->prepare("
            SELECT SUM(quantity) AS total_delivered
            FROM deliveries
            WHERE project_id = ? AND status_of_delivery = 'Delivered'
        ");
        $stmt_delivered->bind_param("i", $project_id);
        $stmt_delivered->execute();
        $stmt_delivered->bind_result($total_delivered);
        $stmt_delivered->fetch();
        $stmt_delivered->close();
        $total_delivered = $total_delivered ? $total_delivered : 0;

        // ---- Delivery Completion
        $module_delivery_completion = 0;
        if ($total_order_quantity > 0) {
            $module_delivery_completion = ($total_delivered / $total_order_quantity) * 100;
        }

        // ---- In Warehouse
        $stmt_in_storage = $conn->prepare("
            SELECT SUM(quantity) AS total_in_storage
            FROM deliveries
            WHERE project_id = ? AND status_of_delivery = 'In Warehouse'
        ");
        $stmt_in_storage->bind_param("i", $project_id);
        $stmt_in_storage->execute();
        $stmt_in_storage->bind_result($total_in_storage);
        $stmt_in_storage->fetch();
        $stmt_in_storage->close();
        $total_in_storage = $total_in_storage ? $total_in_storage : 0;

        $modules_in_storage = 0;
        if ($total_order_quantity > 0) {
            $modules_in_storage = ($total_in_storage / $total_order_quantity) * 100;
        }

        // Add these computed values
        $project['module_delivery_completion'] = round($module_delivery_completion, 2);
        $project['modules_in_storage']         = round($modules_in_storage, 2);

        $projects[] = $project;
    }
    $stmt->close();
} 
// else, no accounts => no projects

// 4) "Unassigned Modules" still based on user_id
$stmt = $conn->prepare("
    SELECT *
    FROM unassigned_modules
    WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unassigned_modules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// close DB
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logistics Dashboard</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>
    <p>This is your dashboard.</p>

    <h2>Active Projects:</h2>
    <!-- Projects will be displayed here -->
    <div class="projects-container">
        <?php if (!empty($projects)): ?>
            <?php 
            // CHANGED: Use role-based page link
            $target_page = ($role === 'DDPm') ? 'DDPm_overview' : 'project_overview';
            ?>
            <?php foreach ($projects as $project): ?>
                <?php
                // Format completion date if not null
                $estimated_completion_date_display = 'N/A';
                if (!empty($project['estimated_completion_date'])) {
                    $dateObj = new DateTime($project['estimated_completion_date']);
                    $estimated_completion_date_display = $dateObj->format('F j, Y');
                }
                ?>
                <div class="project-item">
                    <h3>
                        <a href="<?php echo $target_page; ?>?id=<?php echo $project['id']; ?>">
                            <?php echo htmlspecialchars($project['project_name']); ?>
                        </a>
                    </h3>
                    <div class="project-image">
                        <a href="<?php echo $target_page; ?>?id=<?php echo $project['id']; ?>">
                            <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="Project Image">
                        </a>
                    </div>
                    <div class="project-details">
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($project['project_address']); ?></p>
                        <p><strong>Project Size:</strong> <?php echo htmlspecialchars(number_format($project['project_size'], 2)); ?> MW</p>
                        <p><strong>Module Delivery Completion:</strong> <?php echo htmlspecialchars($project['module_delivery_completion']); ?>%</p>
                        <p><strong>Estimated Completion Date:</strong> <?php echo htmlspecialchars($estimated_completion_date_display); ?></p>
                        <p><strong>Percent of Modules in Storage:</strong> <?php echo htmlspecialchars($project['modules_in_storage']); ?>%</p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No active projects.</p>
        <?php endif; ?>
    </div>

    <!-- Unassigned Modules Section -->
    <h2>Unassigned Modules:</h2>
    <?php if (!empty($unassigned_modules)): ?>
        <table class="styled-table">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Wattage</th>
                    <th>Quantity</th>
                    <th>Current Location</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unassigned_modules as $module): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($module['vendor']); ?></td>
                        <td><?php echo htmlspecialchars(number_format($module['wattage'])); ?> W</td>
                        <td><?php echo htmlspecialchars(number_format($module['quantity'])); ?></td>
                        <td><?php echo htmlspecialchars($module['current_location']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No unassigned modules.</p>
    <?php endif; ?>
</main>
</body>
</html>
