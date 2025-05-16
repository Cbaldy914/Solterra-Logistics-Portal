<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'user';

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

/**
 * We want to fetch:
 *  - Distinct projects that have modules in storage
 *  - Total modules in storage
 *  - Total monthly storage cost
 *  - Total # projects with modules in storage
 *
 * If role=admin/global_admin => all
 * Else => must join with customer_account_users => only projects in the user's account
 */

// 1) Projects that have modules in storage
if ($role === 'admin' || $role === 'global_admin') {
    $sql_projects_in_storage = "
        SELECT DISTINCT p.id, p.project_name, p.project_address, p.image_url,
                        p.project_size, p.estimated_completion_date
        FROM projects p
        JOIN deliveries d ON p.id = d.project_id
        WHERE d.warehouse_arrival_date IS NOT NULL
          AND d.left_warehouse_date IS NULL
    ";
    $params    = [];
    $paramTypes= "";
} else {
    // Normal user => account-based filter
    $sql_projects_in_storage = "
        SELECT DISTINCT p.id, p.project_name, p.project_address, p.image_url,
                        p.project_size, p.estimated_completion_date
        FROM projects p
        JOIN deliveries d ON p.id = d.project_id
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE d.warehouse_arrival_date IS NOT NULL
          AND d.left_warehouse_date IS NULL
          AND cau.user_id = ?
    ";
    $params    = [$user_id];
    $paramTypes= "i";
}
$stmt = $conn->prepare($sql_projects_in_storage);
if (!empty($paramTypes)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$resProjects = $stmt->get_result();
$stmt->close();

$projects_in_storage = [];
while ($row = $resProjects->fetch_assoc()) {
    $projects_in_storage[] = $row;
}

// 2) Calculate total modules in storage (summing d.quantity)
if ($role === 'admin' || $role === 'global_admin') {
    $sql_total_modules = "
        SELECT SUM(d.quantity) AS total_modules
        FROM deliveries d
        JOIN projects p ON d.project_id=p.id
        WHERE d.warehouse_arrival_date IS NOT NULL
          AND d.left_warehouse_date IS NULL
    ";
    $params2     = [];
    $paramTypes2 = "";
} else {
    $sql_total_modules = "
        SELECT SUM(d.quantity) AS total_modules
        FROM deliveries d
        JOIN projects p ON d.project_id=p.id
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE d.warehouse_arrival_date IS NOT NULL
          AND d.left_warehouse_date IS NULL
          AND cau.user_id=?
    ";
    $params2     = [$user_id];
    $paramTypes2 = "i";
}
$stmt2 = $conn->prepare($sql_total_modules);
if (!empty($paramTypes2)) {
    $stmt2->bind_param($paramTypes2, ...$params2);
}
$stmt2->execute();
$stmt2->bind_result($total_modules_in_storage);
$stmt2->fetch();
$stmt2->close();

$total_modules_in_storage = $total_modules_in_storage ?: 0;

// 3) Calculate total monthly storage cost
if ($role === 'admin' || $role === 'global_admin') {
    $sql_total_monthly = "
        SELECT SUM(w.monthly_storage_fee) AS total_monthly_storage_cost
        FROM deliveries d
        JOIN projects p ON d.project_id=p.id
        JOIN warehouses w ON p.warehouse_id=w.id
        WHERE d.warehouse_arrival_date IS NOT NULL
          AND d.left_warehouse_date IS NULL
    ";
    $params3     = [];
    $paramTypes3 = "";
} else {
    $sql_total_monthly = "
        SELECT SUM(w.monthly_storage_fee) AS total_monthly_storage_cost
        FROM deliveries d
        JOIN projects p ON d.project_id=p.id
        JOIN warehouses w ON p.warehouse_id=w.id
        JOIN customer_account_users cau ON p.account_id=cau.account_id
        WHERE d.warehouse_arrival_date IS NOT NULL
          AND d.left_warehouse_date IS NULL
          AND cau.user_id=?
    ";
    $params3     = [$user_id];
    $paramTypes3 = "i";
}
$stmt3 = $conn->prepare($sql_total_monthly);
if (!empty($paramTypes3)) {
    $stmt3->bind_param($paramTypes3, ...$params3);
}
$stmt3->execute();
$stmt3->bind_result($total_monthly_storage_cost);
$stmt3->fetch();
$stmt3->close();

$total_monthly_storage_cost = $total_monthly_storage_cost ?: 0.0;

// 4) Total # distinct projects with modules in storage
if ($role === 'admin' || $role === 'global_admin') {
    $sql_proj_count = "
        SELECT COUNT(DISTINCT p.id) AS total_projects_with_storage
        FROM projects p
        JOIN deliveries d ON p.id=d.project_id
        WHERE d.warehouse_arrival_date IS NOT NULL
          AND d.left_warehouse_date IS NULL
    ";
    $params4     = [];
    $paramTypes4 = "";
} else {
    $sql_proj_count = "
        SELECT COUNT(DISTINCT p.id) AS total_projects_with_storage
        FROM projects p
        JOIN deliveries d ON p.id=d.project_id
        JOIN customer_account_users cau ON p.account_id=cau.account_id
        WHERE d.warehouse_arrival_date IS NOT NULL
          AND d.left_warehouse_date IS NULL
          AND cau.user_id=?
    ";
    $params4     = [$user_id];
    $paramTypes4 = "i";
}
$stmt4 = $conn->prepare($sql_proj_count);
if (!empty($paramTypes4)) {
    $stmt4->bind_param($paramTypes4, ...$params4);
}
$stmt4->execute();
$stmt4->bind_result($total_projects_with_storage);
$stmt4->fetch();
$stmt4->close();

$total_projects_with_storage = $total_projects_with_storage ?: 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Warehousing Overview</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
            margin-top: 10px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
        .key-figures {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        .figure {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 15px 20px;
            text-align: center;
            min-width: 200px;
        }
        .figure h2 {
            margin: 0 0 10px 0;
        }
        .projects-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .project-item {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 15px;
            width: 250px;
        }
        .project-item h3 {
            margin: 0 0 10px 0;
        }
        .project-image img {
            max-width: 100%;
            border-radius: 4px;
        }
        .project-details p {
            margin: 4px 0;
        }
        .project-details a.button {
            display: inline-block;
            margin-top: 10px;
            background: #488C9A;
            color: #fff;
            padding: 8px 12px;
            border-radius: 4px;
            text-decoration: none;
        }
        .project-details a.button:hover {
            background: #293E4C;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb">
        <a href="<?php echo ($role === 'admin' || $role === 'global_admin') ? 'admin_dashboard.php' : 'dashboard.php'; ?>">Dashboard</a>
        <span class="separator">&raquo;</span>
        <span>Warehousing Overview</span>
    </div>

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
            <?php foreach ($projects_in_storage as $proj): ?>
                <div class="project-item">
                    <h3>
                        <a href="project_overview?id=<?php echo $proj['id']; ?>">
                            <?php echo htmlspecialchars($proj['project_name']); ?>
                        </a>
                    </h3>
                    <div class="project-image">
                        <img src="<?php echo htmlspecialchars($proj['image_url']); ?>" alt="Project Image">
                    </div>
                    <div class="project-details">
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($proj['project_address']); ?></p>
                        <p><strong>Project Size:</strong> <?php echo htmlspecialchars($proj['project_size']); ?> MW</p>
                        <p><strong>Estimated Completion Date:</strong> <?php echo htmlspecialchars($proj['estimated_completion_date']); ?></p>
                        <a href="warehouse_info?project_id=<?php echo $proj['id']; ?>" class="button">
                            View Warehouse Information
                        </a>
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
