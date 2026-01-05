<?php
// Initialize session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

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


// 1) Determine which account(s) the user belongs to
$user_id = $_SESSION['user_id'];
$accountIds = [];

// Get user's display name (first name if available, otherwise username)
$displayName = $_SESSION['username']; // default to username
$stmtUser = $conn->prepare("SELECT first_name FROM users WHERE id = ?");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$stmtUser->bind_result($firstName);
$stmtUser->fetch();
$stmtUser->close();

if (!empty($firstName)) {
    $displayName = $firstName;
}

if ($role === 'global_admin') {
    // Global admins can view all accounts
    $resultAccts = $conn->query("SELECT id FROM customer_accounts");
    if ($resultAccts) {
        while ($row = $resultAccts->fetch_assoc()) {
            $accountIds[] = (int)$row['id'];
        }
    }
} else {
    $sqlAccts = "
        SELECT account_id
        FROM customer_account_users
        WHERE user_id = ?
    ";
    $stmtAccts = $conn->prepare($sqlAccts);
    $stmtAccts->bind_param("i", $user_id);
    $stmtAccts->execute();
    $resultAccts = $stmtAccts->get_result();
    while ($row = $resultAccts->fetch_assoc()) {
        $accountIds[] = (int)$row['account_id'];
    }
    $stmtAccts->close();
}

// 2) Calculate unassigned modules
$unassigned_modules_count = 0;
if (count($accountIds) > 0) {
    $placeholders_unassigned = implode(',', array_fill(0, count($accountIds), '?'));
    $sqlUnassigned = "
        SELECT COUNT(DISTINCT umi.id) as unassigned_count
        FROM unassigned_module_items umi
        JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN projects p ON p.account_id IN ($placeholders_unassigned)
        WHERE umi.id NOT IN (
            SELECT ip.unassigned_module_item_id 
            FROM inventory_pallets ip 
            WHERE ip.assigned_project_id IS NOT NULL 
            AND ip.unassigned_module_item_id IS NOT NULL
        )
    ";
    $stmtUnassigned = $conn->prepare($sqlUnassigned);
    $types_unassigned = str_repeat('i', count($accountIds));
    $stmtUnassigned->bind_param($types_unassigned, ...$accountIds);
    $stmtUnassigned->execute();
    $stmtUnassigned->bind_result($unassigned_modules_count);
    $stmtUnassigned->fetch();
    $stmtUnassigned->close();
    $unassigned_modules_count = $unassigned_modules_count ?: 0;
}

// 3) If user has accounts, fetch projects for those accounts
$projects = [];
if (count($accountIds) > 0) {
    // Build an IN() clause with placeholders
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $sqlProj = "
        SELECT id, project_name, project_address, image_url, estimated_completion_date, project_size
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

        $total_order_quantity = 0;
        while ($wattage_row = $wattage_orders_result->fetch_assoc()) {
            $torder  = (int)$wattage_row['total_order'];
            $total_order_quantity += $torder;
        }
        // Use project_size from database, default to 0 if not set
        $project['project_size'] = isset($project['project_size']) ? (float)$project['project_size'] : 0;

        // ---- Modules Delivered
        $stmt_delivered = $conn->prepare("
            SELECT SUM(quantity) AS total_delivered
            FROM deliveries
            WHERE project_id = ? AND status_of_delivery = 'Delivered to Project'
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

        // ---- In Warehouse (current status, not historical)
        $stmt_in_storage = $conn->prepare("
            SELECT SUM(ip.quantity) AS total_in_storage
            FROM inventory_pallets ip
            WHERE ip.assigned_project_id = ? AND ip.status = 'In Warehouse'
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
    <style>
        /* Modern Dashboard Styling */
        .dashboard-header {
            background: #ffffff;
            color: #293E4C;
            padding: 30px 20px;
            border-radius: 16px;
            margin: 20px 0 30px 0;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            border-left: 6px solid #488C9A;
        }

        .dashboard-header h1 {
            margin: 0 0 8px 0;
            font-size: 2.5em;
            font-weight: 600;
            color: #293E4C;
        }

        .dashboard-header p {
            margin: 0;
            font-size: 1.1em;
            color: #6c757d;
        }

        /* Dashboard Stats Cards */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #ffffff;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
            text-decoration: none;
            color: inherit;
        }

        .stat-card.clickable {
            cursor: pointer;
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
        }

        .stat-label {
            font-size: 1em;
            color: #6c757d;
            margin: 8px 0 0 0;
            font-weight: 500;
        }

        .stat-icon {
            font-size: 3em;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .project-item {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            min-width: 300px;
        }

        .project-item:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.15);
        }

        .project-image {
            width: 100%;
            height: 200px;
            overflow: hidden;
            position: relative;
        }

        .project-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .project-item:hover .project-image img {
            transform: scale(1.05);
        }

        .project-title {
            padding: 20px 20px 15px 20px;
            background: #ffffff;
            border-bottom: 1px solid #f1f3f4;
        }

        .project-title h3 {
            margin: 0;
            font-size: 1.4em;
            color: #293E4C;
            font-weight: 600;
            text-align: center;
        }

        .project-title h3 a {
            text-decoration: none;
            color: inherit;
        }

        .project-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(72, 140, 154, 0.9), rgba(58, 110, 127, 0.9));
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 3;
        }

        .project-item:hover .project-overlay {
            opacity: 1;
        }

        .project-overlay-text {
            color: white;
            font-size: 1.2em;
            font-weight: 600;
            text-align: center;
        }

        .project-content {
            padding: 15px;
            background: #fafbfc;
            border-top: 1px solid #f1f3f4;
        }

        .project-details {
            background: #ffffff;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #f1f3f4;
        }

        .project-details p {
            margin: 8px 0;
            color: #495057;
            font-size: 0.95em;
            line-height: 1.5;
        }

        .project-details strong {
            color: #293E4C;
            font-weight: 600;
        }

        /* Progress Bars */
        .progress-container {
            margin: 15px 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9em;
            font-weight: 500;
            color: #495057;
        }

        .progress-bar {
            background: #e9ecef;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.8s ease;
        }

        .progress-delivery {
            background: linear-gradient(90deg, #488C9A, #5AA8B7);
        }

        .progress-storage {
            background: linear-gradient(90deg, #fbb040, #FFC857);
        }

        /* Project Status Badge */
        .project-status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 15px;
        }

        .status-on-track {
            background: #d4edda;
            color: #155724;
        }

        .status-behind {
            background: #f8d7da;
            color: #721c24;
        }

        .status-completed {
            background: #cce7ff;
            color: #004085;
        }

        /* Quick Actions Section */
        .quick-actions {
            background: #ffffff;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            margin-bottom: 30px;
        }

        .quick-actions h2 {
            margin: 0 0 20px 0;
            color: #293E4C;
            font-size: 1.3em;
            font-weight: 600;
        }

        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .action-btn {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 15px 20px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(72, 140, 154, 0.4);
        }

        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 20px 15px;
                margin: 15px 0 20px 0;
            }

            .dashboard-header h1 {
                font-size: 2em;
            }

            .user-info {
                flex-direction: column;
                align-items: flex-start;
            }


            .dashboard-stats {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }

            .project-content {
                padding: 20px;
            }
        }

        /* Section Headers */
        .section-header {
            color: #293E4C;
            font-size: 1.8em;
            font-weight: 600;
            margin: 30px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 3px solid #488C9A;
            position: relative;
        }

        .section-header::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            width: 60px;
            height: 3px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 2px;
        }

        /* Add Project Card Style */
        .project-item--add {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            border: 2px dashed #d0d0d0;
            background-color: #f9f9f9;
            color: #6c757d;
            min-height: 480px; /* Match height of other cards */
            text-align: center;
        }
        .project-item--add:hover {
            border-color: #488C9A;
            background-color: #f0f8fa;
            color: #488C9A;
            transform: translateY(-8px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.15);
        }
        .add-project-icon {
            font-size: 4em;
            line-height: 1;
            margin-bottom: 15px;
        }
        .add-project-text {
            font-size: 1.2em;
            font-weight: 600;
        }

        /* View Toggle Styles */
        .view-toggle-container {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        .view-toggle {
            display: inline-flex;
            background: #f1f3f4;
            border-radius: 12px;
            padding: 4px;
            gap: 4px;
        }

        .view-toggle a {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95em;
            text-decoration: none;
            color: #6c757d;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .view-toggle a:hover {
            color: #293E4C;
            background: rgba(255, 255, 255, 0.5);
        }

        .view-toggle a.active {
            background: #ffffff;
            color: #293E4C;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .view-toggle-icon {
            font-size: 1.1em;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Enhanced Welcome Header -->
    <div class="dashboard-header">
        <h1>Welcome back, <?php echo htmlspecialchars($displayName); ?>!</h1>
        <p>Here's an overview of your projects and recent activity</p>
    </div>

    <!-- View Toggle -->
    <div class="view-toggle-container">
        <div class="view-toggle">
            <a href="dashboard.php" class="active">
                <span class="view-toggle-icon">📊</span>
                Active Projects
            </a>
            <a href="project_planning.php">
                <span class="view-toggle-icon">📋</span>
                Project Planning
            </a>
        </div>
    </div>

    <!-- Dashboard Statistics -->
    <div class="dashboard-stats">
        <div class="stat-card clickable" onclick="document.getElementById('projects-section').scrollIntoView({behavior: 'smooth'});">
            <div class="stat-icon">🏗️</div>
            <h3 class="stat-number"><?php echo count($projects); ?></h3>
            <p class="stat-label">Active Projects</p>
        </div>
        <a href="modules.php" class="stat-card clickable">
            <div class="stat-icon">📋</div>
            <h3 class="stat-number"><?php echo number_format($unassigned_modules_count); ?></h3>
            <p class="stat-label">Unassigned Modules</p>
        </a>
        <a href="warehousing_overview.php" class="stat-card clickable">
            <div class="stat-icon">🏭</div>
            <h3 class="stat-number">
                <?php 
                $total_storage = 0;
                foreach ($projects as $project) {
                    $total_storage += $project['modules_in_storage'];
                }
                echo !empty($projects) ? round($total_storage / count($projects), 1) : 0;
                ?>%
            </h3>
            <p class="stat-label">Modules in Storage</p>
        </a>
        <div class="stat-card">
            <div class="stat-icon">⚡</div>
            <h3 class="stat-number">
                <?php 
                $total_mw = 0;
                foreach ($projects as $project) {
                    $total_mw += $project['project_size'];
                }
                echo number_format($total_mw, 1);
                ?>
            </h3>
            <p class="stat-label">Total MW</p>
        </div>
    </div>

    <!-- Enhanced Projects Section -->
    <?php if (!empty($projects) || in_array($role, ['admin', 'global_admin'])): ?>
        <h2 class="section-header" id="projects-section">Your Active Projects</h2>
        <div class="projects-container">
            <?php 
            // Use role-based page link
            $target_page = ($role === 'DDPm') ? 'DDPm_overview' : 'project_overview';
            
            foreach ($projects as $project): 
                // Format completion date
                $estimated_completion_date_display = 'N/A';
                if (!empty($project['estimated_completion_date'])) {
                    $dateObj = new DateTime($project['estimated_completion_date']);
                    $estimated_completion_date_display = $dateObj->format('F j, Y');
                }

                // Determine project status
                $status_class = 'status-on-track';
                $status_text = 'Active';
                if ($total_order_quantity > 0) {
                    if ($total_delivered > 0 && $total_delivered < $total_order_quantity) {
                        $status_class = 'status-behind';
                        $status_text = 'In Progress';
                    } elseif ($total_delivered >= $total_order_quantity) {
                        $status_class = 'status-completed';
                        $status_text = 'Completed';
                    }
                }
            ?>
                <div class="project-item" onclick="window.location.href='<?php echo $target_page; ?>.php?project_id=<?php echo $project['id']; ?>'">
                    <div class="project-title">
                        <h3>
                            <a href="<?php echo $target_page; ?>.php?project_id=<?php echo $project['id']; ?>">
                                <?php echo htmlspecialchars($project['project_name']); ?>
                            </a>
                        </h3>
                    </div>
                    <div class="project-image">
                        <?php $projectImage = !empty($project['image_url']) ? $project['image_url'] : 'pictures/project_default.png'; ?>
                        <img src="<?php echo htmlspecialchars($projectImage); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>" onerror="this.src='pictures/project_default.png'">
                        <div class="project-overlay">
                            <div class="project-overlay-text">View Project Details</div>
                        </div>
                    </div>
                    <div class="project-content">
                        
                        <div class="project-details">
                            <p><strong>📍 Address:</strong> <?php echo htmlspecialchars($project['project_address']); ?></p>
                            <p><strong>⚡ Project Size:</strong> <?php echo number_format($project['project_size'], 2); ?> MW</p>
                            <p><strong>📅 Est. Completion:</strong> <?php echo $estimated_completion_date_display; ?></p>
                        </div>

                        <!-- Delivery Progress Bar -->
                        <div class="progress-container">
                            <div class="progress-label">
                                <span>Delivery Progress</span>
                                <span><?php echo $project['module_delivery_completion']; ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill progress-delivery" style="width: <?php echo $project['module_delivery_completion']; ?>%"></div>
                            </div>
                        </div>

                        <!-- Storage Progress Bar -->
                        <div class="progress-container">
                            <div class="progress-label">
                                <span>Modules in Storage</span>
                                <span><?php echo $project['modules_in_storage']; ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill progress-storage" style="width: <?php echo $project['modules_in_storage']; ?>%"></div>
                            </div>
                        </div>

                        <!-- Project Status Badge -->
                        <div class="project-status <?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
                <div class="project-item project-item--add" onclick="window.location.href='add_project.php'">
                    <div class="add-project-icon">＋</div>
                    <div class="add-project-text">Add New Project</div>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="quick-actions">
            <h2>🏗️ No Active Projects</h2>
            <p style="text-align: center; color: #6c757d; margin-bottom: 20px;">
                You don't have any active projects yet. Contact your administrator to get started.
            </p>
        </div>
    <?php endif; ?>
</main>
</body>
</html>

