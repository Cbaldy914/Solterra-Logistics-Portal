<?php
/**
 * Project Planning / Module Allocation
 *
 * This page allows customers to:
 * - Create and manage planning scenarios
 * - Define framework contracts with constraints
 * - Plan projects and delivery windows
 * - Model inventory pools
 * - Allocate MW across projects
 * - Request activation when ready
 */

// Initialize session
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Get user's display name
$displayName = $_SESSION['username'];
$stmtUser = $conn->prepare("SELECT first_name FROM users WHERE id = ?");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$stmtUser->bind_result($firstName);
$stmtUser->fetch();
$stmtUser->close();

if (!empty($firstName)) {
    $displayName = $firstName;
}

// Determine which account(s) the user belongs to
$accountIds = [];
$primaryAccountId = null;

if ($role === 'global_admin') {
    $resultAccts = $conn->query("SELECT id FROM customer_accounts");
    if ($resultAccts) {
        while ($row = $resultAccts->fetch_assoc()) {
            $accountIds[] = (int)$row['id'];
        }
    }
    $primaryAccountId = !empty($accountIds) ? $accountIds[0] : null;
} else {
    $sqlAccts = "SELECT account_id FROM customer_account_users WHERE user_id = ?";
    $stmtAccts = $conn->prepare($sqlAccts);
    $stmtAccts->bind_param("i", $user_id);
    $stmtAccts->execute();
    $resultAccts = $stmtAccts->get_result();
    while ($row = $resultAccts->fetch_assoc()) {
        $accountIds[] = (int)$row['account_id'];
    }
    $stmtAccts->close();
    $primaryAccountId = !empty($accountIds) ? $accountIds[0] : null;
}

// Handle scenario creation
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_scenario' && $primaryAccountId) {
        $scenarioName = trim($_POST['scenario_name'] ?? '');
        $scenarioDesc = trim($_POST['scenario_description'] ?? '');

        if (!empty($scenarioName)) {
            $stmt = $conn->prepare("INSERT INTO planning_scenarios (account_id, name, description, created_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issi", $primaryAccountId, $scenarioName, $scenarioDesc, $user_id);
            if ($stmt->execute()) {
                $newScenarioId = $conn->insert_id;
                $stmt->close();
                $conn->close();
                // Redirect to the new scenario detail page
                header("Location: scenario_detail.php?id=" . $newScenarioId);
                exit();
            } else {
                $message = "Error creating scenario: " . $conn->error;
                $messageType = 'error';
            }
            $stmt->close();
        } else {
            $message = "Please provide a scenario name.";
            $messageType = 'error';
        }
    }
}

// Fetch scenarios for user's accounts
$scenarios = [];
if (!empty($accountIds)) {
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $sql = "SELECT ps.*, u.first_name, u.last_name, u.username,
                   (SELECT COUNT(*) FROM planning_projects pp WHERE pp.scenario_id = ps.id) as project_count,
                   (SELECT COUNT(*) FROM planning_framework_contracts pfc WHERE pfc.scenario_id = ps.id) as contract_count
            FROM planning_scenarios ps
            LEFT JOIN users u ON ps.created_by = u.id
            WHERE ps.account_id IN ($placeholders)
            ORDER BY ps.updated_at DESC";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($accountIds));
    $stmt->bind_param($types, ...$accountIds);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $scenarios[] = $row;
    }
    $stmt->close();
}

// Fetch active projects for display (read-only in planning context)
$activeProjects = [];
if (!empty($accountIds)) {
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $sql = "SELECT p.id, p.project_name, p.project_size, p.estimated_completion_date, p.project_address
            FROM projects p
            WHERE p.account_id IN ($placeholders)
            ORDER BY p.project_name ASC";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($accountIds));
    $stmt->bind_param($types, ...$accountIds);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $activeProjects[] = $row;
    }
    $stmt->close();
}

// Get summary stats
$totalPlannedMW = 0;
$totalContractedMW = 0;
$pendingActivations = 0;

if (!empty($accountIds)) {
    // Total planned MW from planning_projects
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));

    $sql = "SELECT COALESCE(SUM(required_mw_dc), 0) FROM planning_projects WHERE account_id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($accountIds));
    $stmt->bind_param($types, ...$accountIds);
    $stmt->execute();
    $stmt->bind_result($totalPlannedMW);
    $stmt->fetch();
    $stmt->close();

    // Total contracted MW
    $sql = "SELECT COALESCE(SUM(total_mw_dc), 0) FROM planning_framework_contracts WHERE account_id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$accountIds);
    $stmt->execute();
    $stmt->bind_result($totalContractedMW);
    $stmt->fetch();
    $stmt->close();

    // Pending activations
    $sql = "SELECT COUNT(*) FROM planning_activation_requests WHERE account_id IN ($placeholders) AND status = 'pending'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$accountIds);
    $stmt->execute();
    $stmt->bind_result($pendingActivations);
    $stmt->fetch();
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Planning - Solterra</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* View Toggle Styles */
        .view-toggle-container {
            display: flex;
            justify-content: center;
            margin: 20px 0;
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
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95em;
            color: #6c757d;
            transition: all 0.3s ease;
        }

        .view-toggle a:hover {
            color: #293E4C;
            background: rgba(255,255,255,0.5);
        }

        .view-toggle a.active {
            background: #ffffff;
            color: #488C9A;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        /* Dashboard Header */
        .dashboard-header {
            background: #ffffff;
            color: #293E4C;
            padding: 30px 20px;
            border-radius: 16px;
            margin: 0 0 30px 0;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            border-left: 6px solid #fbb040;
        }

        .dashboard-header h1 {
            margin: 0 0 8px 0;
            font-size: 2.2em;
            font-weight: 600;
            color: #293E4C;
        }

        .dashboard-header p {
            margin: 0;
            font-size: 1.1em;
            color: #6c757d;
        }

        .dashboard-header .beta-badge {
            display: inline-block;
            background: linear-gradient(135deg, #fbb040, #f7931e);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7em;
            font-weight: 600;
            margin-left: 10px;
            vertical-align: middle;
        }

        /* Stats Cards */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-number {
            font-size: 2.2em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
        }

        .stat-label {
            font-size: 0.95em;
            color: #6c757d;
            margin: 8px 0 0 0;
            font-weight: 500;
        }

        .stat-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
            opacity: 0.4;
        }

        /* Section Headers */
        .section-header {
            color: #293E4C;
            font-size: 1.5em;
            font-weight: 600;
            margin: 30px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 3px solid #fbb040;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Scenarios Grid */
        .scenarios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .scenario-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .scenario-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.15);
        }

        .scenario-card-header {
            padding: 20px;
            border-bottom: 1px solid #f1f3f4;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .scenario-card-header h3 {
            margin: 0;
            font-size: 1.2em;
            color: #293E4C;
            font-weight: 600;
        }

        .scenario-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
            text-transform: uppercase;
        }

        .scenario-status.active {
            background: #d4edda;
            color: #155724;
        }

        .scenario-status.draft {
            background: #f1f3f4;
            color: #6c757d;
        }

        .scenario-card-body {
            padding: 20px;
        }

        .scenario-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .scenario-meta-item {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .scenario-meta-item .value {
            font-size: 1.4em;
            font-weight: 700;
            color: #488C9A;
        }

        .scenario-meta-item .label {
            font-size: 0.8em;
            color: #6c757d;
            margin-top: 4px;
        }

        .scenario-description {
            color: #6c757d;
            font-size: 0.9em;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .scenario-footer {
            font-size: 0.8em;
            color: #adb5bd;
            display: flex;
            justify-content: space-between;
        }

        .scenario-card-actions {
            padding: 15px 20px;
            background: #f8f9fa;
            border-top: 1px solid #f1f3f4;
            display: flex;
            gap: 10px;
        }

        .scenario-card-actions a {
            flex: 1;
            padding: 10px 15px;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            font-weight: 600;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4);
        }

        .btn-secondary {
            background: #ffffff;
            color: #488C9A;
            border: 1px solid #488C9A;
        }

        .btn-secondary:hover {
            background: #488C9A;
            color: white;
        }

        /* Add Scenario Card */
        .scenario-card--add {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 280px;
            border: 2px dashed #d0d0d0;
            background: #f9f9f9;
            cursor: pointer;
        }

        .scenario-card--add:hover {
            border-color: #fbb040;
            background: #fffbf5;
        }

        .add-icon {
            font-size: 3em;
            color: #d0d0d0;
            margin-bottom: 15px;
        }

        .scenario-card--add:hover .add-icon {
            color: #fbb040;
        }

        .add-text {
            font-size: 1.1em;
            font-weight: 600;
            color: #6c757d;
        }

        .scenario-card--add:hover .add-text {
            color: #f7931e;
        }

        /* Active Projects List (Read-only) */
        .active-projects-list {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .active-projects-list table {
            width: 100%;
            border-collapse: collapse;
        }

        .active-projects-list th {
            background: #f8f9fa;
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: #293E4C;
            font-size: 0.9em;
            border-bottom: 2px solid #e9ecef;
        }

        .active-projects-list td {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f3f4;
            color: #495057;
        }

        .active-projects-list tr:hover {
            background: #f8f9fa;
        }

        .active-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #cce7ff;
            color: #004085;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
        }

        /* Planning Modal Styles - scoped to avoid portal.css conflicts */
        .planning-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .planning-modal-overlay.active {
            display: flex;
        }

        .planning-modal {
            background: #ffffff !important;
            border-radius: 16px !important;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.2) !important;
            max-width: 500px !important;
            width: 90% !important;
            max-height: 90vh !important;
            z-index: 2001 !important;
            display: block !important;
            position: relative !important;
            margin: 0 !important;
            padding: 0 !important;
            left: auto !important;
            top: auto !important;
            height: auto !important;
            overflow: visible !important;
        }

        .planning-modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .planning-modal-header h2 {
            margin: 0;
            font-size: 1.3em;
            color: #293E4C;
        }

        .planning-modal-close {
            background: none;
            border: none;
            font-size: 1.5em;
            color: #6c757d;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }

        .planning-modal-close:hover {
            color: #293E4C;
        }

        .planning-modal-body {
            padding: 25px;
        }

        .planning-form-group {
            margin-bottom: 20px;
        }

        .planning-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #293E4C;
        }

        .planning-form-group input,
        .planning-form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            font-size: 1em;
            font-family: inherit;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }

        .planning-form-group input:focus,
        .planning-form-group textarea:focus {
            outline: none;
            border-color: #488C9A;
        }

        .planning-form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .planning-modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .planning-modal-footer button {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .planning-modal-footer .btn-cancel {
            background: #f1f3f4;
            border: none;
            color: #6c757d;
        }

        .planning-modal-footer .btn-cancel:hover {
            background: #e9ecef;
        }

        .planning-modal-footer .btn-submit {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border: none;
            color: white;
        }

        .planning-modal-footer .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4);
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state-icon {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            margin: 0 0 10px 0;
            color: #293E4C;
        }

        .empty-state p {
            margin: 0 0 20px 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-header h1 {
                font-size: 1.8em;
            }

            .view-toggle a {
                padding: 10px 16px;
                font-size: 0.85em;
            }

            .scenarios-grid {
                grid-template-columns: 1fr;
            }

            .scenario-meta {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- View Toggle -->
    <div class="view-toggle-container">
        <div class="view-toggle">
            <a href="dashboard">Active Projects</a>
            <a href="project_planning" class="active">Project Planning</a>
        </div>
    </div>

    <!-- Header -->
    <div class="dashboard-header">
        <h1>Project Planning <span class="beta-badge">BETA</span></h1>
        <p>Plan your module allocations, manage framework contracts, and optimize your project pipeline</p>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="dashboard-stats">
        <div class="stat-card">
            <div class="stat-icon">📋</div>
            <h3 class="stat-number"><?php echo count($scenarios); ?></h3>
            <p class="stat-label">Planning Scenarios</p>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⚡</div>
            <h3 class="stat-number"><?php echo number_format($totalPlannedMW, 1); ?></h3>
            <p class="stat-label">Planned MW</p>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📝</div>
            <h3 class="stat-number"><?php echo number_format($totalContractedMW, 1); ?></h3>
            <p class="stat-label">Contracted MW</p>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🔔</div>
            <h3 class="stat-number"><?php echo $pendingActivations; ?></h3>
            <p class="stat-label">Pending Activations</p>
        </div>
    </div>

    <!-- Scenarios Section -->
    <h2 class="section-header">
        Your Scenarios
        <button class="btn-primary" onclick="openCreateScenarioModal()" style="padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 0.9em;">
            + New Scenario
        </button>
    </h2>

    <?php if (empty($scenarios)): ?>
        <div class="scenarios-grid">
            <div class="scenario-card scenario-card--add" onclick="openCreateScenarioModal()">
                <div class="add-icon">+</div>
                <div class="add-text">Create Your First Scenario</div>
            </div>
        </div>
    <?php else: ?>
        <div class="scenarios-grid">
            <?php foreach ($scenarios as $scenario): ?>
                <div class="scenario-card">
                    <div class="scenario-card-header">
                        <h3><?php echo htmlspecialchars($scenario['name']); ?></h3>
                        <span class="scenario-status <?php echo $scenario['is_active'] ? 'active' : 'draft'; ?>">
                            <?php echo $scenario['is_active'] ? 'Active' : 'Draft'; ?>
                        </span>
                    </div>
                    <div class="scenario-card-body">
                        <?php if (!empty($scenario['description'])): ?>
                            <p class="scenario-description"><?php echo htmlspecialchars($scenario['description']); ?></p>
                        <?php endif; ?>
                        <div class="scenario-meta">
                            <div class="scenario-meta-item">
                                <div class="value"><?php echo (int)$scenario['contract_count']; ?></div>
                                <div class="label">Contracts</div>
                            </div>
                            <div class="scenario-meta-item">
                                <div class="value"><?php echo (int)$scenario['project_count']; ?></div>
                                <div class="label">Projects</div>
                            </div>
                        </div>
                        <div class="scenario-footer">
                            <span>Created <?php echo date('M j, Y', strtotime($scenario['created_at'])); ?></span>
                            <span>by <?php echo htmlspecialchars($scenario['first_name'] ?? $scenario['username']); ?></span>
                        </div>
                    </div>
                    <div class="scenario-card-actions">
                        <a href="scenario_detail.php?id=<?php echo $scenario['id']; ?>" class="btn-primary">Open</a>
                        <a href="scenario_detail.php?id=<?php echo $scenario['id']; ?>&tab=settings" class="btn-secondary">Settings</a>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Add New Scenario Card -->
            <div class="scenario-card scenario-card--add" onclick="openCreateScenarioModal()">
                <div class="add-icon">+</div>
                <div class="add-text">New Scenario</div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Active Projects Reference -->
    <?php if (!empty($activeProjects)): ?>
        <h2 class="section-header">Active Projects (Reference)</h2>
        <p style="color: #6c757d; margin-bottom: 20px;">
            These are your currently active projects. They will be shown in planning views for reference but cannot be modified here.
        </p>
        <div class="active-projects-list">
            <table>
                <thead>
                    <tr>
                        <th>Project Name</th>
                        <th>Size (MW)</th>
                        <th>Location</th>
                        <th>Est. Completion</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeProjects as $project): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($project['project_name']); ?></strong></td>
                            <td><?php echo number_format($project['project_size'], 2); ?> MW</td>
                            <td><?php echo htmlspecialchars($project['project_address'] ?? 'N/A'); ?></td>
                            <td><?php echo $project['estimated_completion_date'] ? date('M j, Y', strtotime($project['estimated_completion_date'])) : 'TBD'; ?></td>
                            <td><span class="active-badge">Active</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<!-- Create Scenario Modal -->
<div class="planning-modal-overlay" id="createScenarioModal">
    <div class="planning-modal">
        <div class="planning-modal-header">
            <h2>Create New Scenario</h2>
            <button class="planning-modal-close" onclick="closeCreateScenarioModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_scenario">
            <div class="planning-modal-body">
                <div class="planning-form-group">
                    <label for="scenario_name">Scenario Name *</label>
                    <input type="text" id="scenario_name" name="scenario_name" required placeholder="e.g., Q2 2027 Allocation Plan">
                </div>
                <div class="planning-form-group">
                    <label for="scenario_description">Description (Optional)</label>
                    <textarea id="scenario_description" name="scenario_description" placeholder="Describe the purpose of this scenario..."></textarea>
                </div>
            </div>
            <div class="planning-modal-footer">
                <button type="button" class="btn-cancel" onclick="closeCreateScenarioModal()">Cancel</button>
                <button type="submit" class="btn-submit">Create Scenario</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateScenarioModal() {
    var modal = document.getElementById('createScenarioModal');
    if (modal) {
        modal.classList.add('active');
        console.log('Modal opened');
    } else {
        console.error('Modal not found');
    }
}

function closeCreateScenarioModal() {
    var modal = document.getElementById('createScenarioModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('createScenarioModal');
    if (modal) {
        // Close modal when clicking outside
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeCreateScenarioModal();
            }
        });
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeCreateScenarioModal();
        }
    });
});
</script>
</body>
</html>
