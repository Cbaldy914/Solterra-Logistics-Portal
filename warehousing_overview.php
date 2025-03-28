<?php
session_name("logistics_session");
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Get user role
$user_id = $_SESSION['user_id'];

if (isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
} else {
    // Fetch role from database
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($role);
    $stmt->fetch();
    $stmt->close();

    // Store role in session
    $_SESSION['role'] = $role;
}

// Fetch Projects with Modules in Storage
if ($role == 'admin') {
    // Admins can see all projects
    $stmt = $conn->prepare("
        SELECT DISTINCT p.id, p.project_name, p.project_address, p.image_url, p.project_size, p.estimated_completion_date
        FROM projects p
        JOIN deliveries d ON p.id = d.project_id
        WHERE d.warehouse_arrival_date IS NOT NULL AND d.left_warehouse_date IS NULL
    ");
} else {
    // Regular users can only see their own projects
    $stmt = $conn->prepare("
        SELECT DISTINCT p.id, p.project_name, p.project_address, p.image_url, p.project_size, p.estimated_completion_date
        FROM projects p
        JOIN deliveries d ON p.id = d.project_id
        WHERE d.warehouse_arrival_date IS NOT NULL AND d.left_warehouse_date IS NULL
          AND p.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$projects_result = $stmt->get_result();
$projects_in_storage = [];

while ($row = $projects_result->fetch_assoc()) {
    $projects_in_storage[] = $row;
}

$stmt->close();

// Calculate Total Modules in Storage Across All Projects
if ($role == 'admin') {
    // Admins can see all modules
    $stmt = $conn->prepare("
        SELECT SUM(d.quantity) AS total_modules
        FROM deliveries d
        JOIN projects p ON d.project_id = p.id
        WHERE d.warehouse_arrival_date IS NOT NULL AND d.left_warehouse_date IS NULL
    ");
} else {
    // Regular users can only see their own modules
    $stmt = $conn->prepare("
        SELECT SUM(d.quantity) AS total_modules
        FROM deliveries d
        JOIN projects p ON d.project_id = p.id
        WHERE d.warehouse_arrival_date IS NOT NULL AND d.left_warehouse_date IS NULL
          AND p.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$stmt->bind_result($total_modules_in_storage);
$stmt->fetch();
$stmt->close();

$total_modules_in_storage = $total_modules_in_storage ? $total_modules_in_storage : 0;

// Calculate Total Monthly Storage Cost Across All Projects
if ($role == 'admin') {
    // Admins can see all storage costs
    $stmt = $conn->prepare("
        SELECT SUM(w.monthly_storage_fee) AS total_monthly_storage_cost
        FROM deliveries d
        JOIN projects p ON d.project_id = p.id
        JOIN warehouses w ON p.warehouse_id = w.id
        WHERE d.warehouse_arrival_date IS NOT NULL AND d.left_warehouse_date IS NULL
    ");
} else {
    // Regular users can only see their own storage costs
    $stmt = $conn->prepare("
        SELECT SUM(w.monthly_storage_fee) AS total_monthly_storage_cost
        FROM deliveries d
        JOIN projects p ON d.project_id = p.id
        JOIN warehouses w ON p.warehouse_id = w.id
        WHERE d.warehouse_arrival_date IS NOT NULL AND d.left_warehouse_date IS NULL
          AND p.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
}
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->execute();
$stmt->bind_result($total_monthly_storage_cost);
$stmt->fetch();
$stmt->close();

$total_monthly_storage_cost = $total_monthly_storage_cost ? $total_monthly_storage_cost : 0.0;

// Calculate Total Number of Projects with Modules in Storage
if ($role == 'admin') {
    // Admins can see all projects
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT p.id) AS total_projects_with_storage
        FROM projects p
        JOIN deliveries d ON p.id = d.project_id
        WHERE d.warehouse_arrival_date IS NOT NULL AND d.left_warehouse_date IS NULL
    ");
} else {
    // Regular users can only see their own projects
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT p.id) AS total_projects_with_storage
        FROM projects p
        JOIN deliveries d ON p.id = d.project_id
        WHERE d.warehouse_arrival_date IS NOT NULL AND d.left_warehouse_date IS NULL
          AND p.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$stmt->bind_result($total_projects_with_storage);
$stmt->fetch();
$stmt->close();

$total_projects_with_storage = $total_projects_with_storage ? $total_projects_with_storage : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehousing Overview</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Warehousing Overview</h1>
    <div class="key-figures">
        <div class="figure">
            <h2>Total Modules in Storage</h2>
            <p><?php echo number_format($total_modules_in_storage); ?></p>
        </div>
        <div class="figure">
            <h2>Total Monthly Storage Cost</h2>
            <p>$<?php echo number_format($total_monthly_storage_cost, 2); ?></p>
        </div>
        <div class="figure">
            <h2>Total Projects with Modules in Storage</h2>
            <p><?php echo number_format($total_projects_with_storage); ?></p>
        </div>
    </div>
    <h2>Projects with Modules in Storage:</h2>
        <div class="projects-container">
            <?php if (!empty($projects_in_storage)): ?>
                <?php foreach ($projects_in_storage as $project): ?>
                    <div class="project-item">
                        <h3><a href="project_overview?id=<?php echo $project['id']; ?>">
                            <?php echo htmlspecialchars($project['project_name']); ?>
                        </a></h3>
                        <div class="project-image">
                            <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="Project Image">
                        </div>
                        <div class="project-details">
                            <p><strong>Address:</strong> <?php echo htmlspecialchars($project['project_address']); ?></p>
                            <p><strong>Project Size:</strong> <?php echo htmlspecialchars($project['project_size']); ?> MW</p>
                            <p><strong>Estimated Completion Date:</strong> <?php echo htmlspecialchars($project['estimated_completion_date']); ?></p>
                            <!-- Include other details from the home dashboard page -->
                            <a href="warehouse_info?project_id=<?php echo $project['id']; ?>" class="button">View Warehouse Information</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No active projects with modules in storage.</p>
            <?php endif; ?>
        </div>

</main>
</body>
</html>
<?php
$conn->close();
?>
