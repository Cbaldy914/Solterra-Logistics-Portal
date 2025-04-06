<?php
session_name("logistics_session");
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}
// Rest of your admin dashboard code

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>

<?php 

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch ALL projects
$sqlProj = "
    SELECT id, project_name, project_address, image_url, estimated_completion_date
    FROM projects
    ORDER BY id ASC
";
$stmt = $conn->prepare($sqlProj);

// Check if prepare() failed
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error); 
}

$stmt->execute();
$result = $stmt->get_result();

// Check if get_result() failed
if ($result === false) {
    die("Error getting result set: " . $stmt->error);
}

$projects = [];
while ($row = $result->fetch_assoc()) {
    $project_id = $row['id'];
    $project = $row;

    // ---- Fetch wattage orders
    $stmt_wattage_orders = $conn->prepare("
        SELECT wattage, total_order
        FROM project_wattage_orders
        WHERE project_id = ?
    ");
    if($stmt_wattage_orders === false) die("Prepare failed: (wattage) " . $conn->error);
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
        $total_mws += ($wattage * $torder) / 1000000; // Convert to MW
    }
    $project['project_size'] = $total_mws;

    // ---- Modules Delivered
    $stmt_delivered = $conn->prepare("
        SELECT SUM(quantity) AS total_delivered
        FROM deliveries
        WHERE project_id = ? AND status_of_delivery = 'Delivered'
    ");
     if($stmt_delivered === false) die("Prepare failed: (delivered) " . $conn->error);
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
     if($stmt_in_storage === false) die("Prepare failed: (storage) " . $conn->error);
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

    // Add computed values
    $project['module_delivery_completion'] = round($module_delivery_completion, 2);
    $project['modules_in_storage']         = round($modules_in_storage, 2);

    $projects[] = $project;
}
$stmt->close();

// Fetch ALL Unassigned Modules
$stmt_unassigned = $conn->prepare("
    SELECT *
    FROM unassigned_modules
"); 
if($stmt_unassigned === false) die("Prepare failed: (unassigned) " . $conn->error);
$stmt_unassigned->execute();
$unassigned_modules = $stmt_unassigned->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_unassigned->close();

// Close DB
$conn->close();
?>
<main>
    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?> (Admin)!</h1>
    <p>This is the global admin dashboard.</p>

    <h2>Active Projects:</h2>
    <div class="projects-container">
        <?php if (!empty($projects)): ?>
             <?php 
            $target_page = 'project_overview.php'; // Make sure this page exists or adjust as needed
            ?>
            <?php foreach ($projects as $project): ?>
                <?php
                // Format completion date if not null
                $estimated_completion_date_display = 'N/A';
                if (!empty($project['estimated_completion_date'])) {
                    try {
                       $dateObj = new DateTime($project['estimated_completion_date']);
                       $estimated_completion_date_display = $dateObj->format('F j, Y');
                    } catch (Exception $e) {
                        // Handle potential date format issues gracefully
                         $estimated_completion_date_display = 'Invalid Date';
                    }
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
                             <img src="<?php echo htmlspecialchars(!empty($project['image_url']) ? $project['image_url'] : 'pictures/default_project_image.png'); ?>" alt="Project Image" style="width:100%; height:auto; max-height: 150px; object-fit: cover;">
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
            <p>No active projects found across all accounts.</p>
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
        <p>No unassigned modules found.</p>
    <?php endif; ?>
</main>

</body>
</html>
