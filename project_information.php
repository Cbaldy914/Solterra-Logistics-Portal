<?php
session_name("logistics_session");
session_start();

// Ensure the user is logged in (customer/user role access)
if (!isset($_SESSION['user_id'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Check if we have a project_id
if (!isset($_GET['project_id'])) {
    die("Project ID is missing.");
}
$project_id = intval($_GET['project_id']);

// Verify user has access to this project
if ($role === 'user') {
    // For regular users, check if they have access to this project through their account
    $stmtAccess = $conn->prepare("
        SELECT p.*, ca.name as account_name
        FROM projects p 
        JOIN customer_accounts ca ON p.account_id = ca.id
        JOIN customer_account_users cau ON ca.id = cau.account_id 
        WHERE p.id = ? AND cau.user_id = ? AND cau.role = 'user'
    ");
    $stmtAccess->bind_param("ii", $project_id, $user_id);
    $stmtAccess->execute();
    $result = $stmtAccess->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: unauthorized.php");
        exit();
    }
    
    $project = $result->fetch_assoc();
    $stmtAccess->close();
} else {
    // For admin/global_admin, get project data with account verification
    if ($role === 'admin') {
        $stmtAccess = $conn->prepare("
            SELECT p.*, ca.name as account_name
            FROM projects p 
            JOIN customer_accounts ca ON p.account_id = ca.id
            JOIN customer_account_users cau ON ca.id = cau.account_id 
            WHERE p.id = ? AND cau.user_id = ? AND cau.role = 'admin'
        ");
        $stmtAccess->bind_param("ii", $project_id, $user_id);
    } else {
        // Global admin has access to all projects
        $stmtAccess = $conn->prepare("
            SELECT p.*, ca.name as account_name
            FROM projects p 
            JOIN customer_accounts ca ON p.account_id = ca.id
            WHERE p.id = ?
        ");
        $stmtAccess->bind_param("i", $project_id);
    }
    
    $stmtAccess->execute();
    $result = $stmtAccess->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: unauthorized.php");
        exit();
    }
    
    $project = $result->fetch_assoc();
    $stmtAccess->close();
}

// Get actual module quantities by wattage (from assigned modules)
$actual_modules = [];
$stmtActual = $conn->prepare("
    SELECT u.wattage, SUM(u.quantity) as total_quantity
    FROM unassigned_module_items u
    JOIN modules m ON u.unassigned_module_id = m.id 
    WHERE m.project_id = ?
    GROUP BY u.wattage
    ORDER BY u.wattage ASC
");
$stmtActual->bind_param("i", $project_id);
$stmtActual->execute();
$resultActual = $stmtActual->get_result();
while ($row = $resultActual->fetch_assoc()) {
    $actual_modules[] = $row;
}
$stmtActual->close();

// Get module batches assigned to this project
$assigned_batches = [];
$stmtBatches = $conn->prepare("
    SELECT m.id as batch_id, m.vendor_name, u.wattage, SUM(u.quantity) as quantity
    FROM modules m
    JOIN unassigned_module_items u ON m.id = u.unassigned_module_id 
    WHERE m.project_id = ?
    GROUP BY m.id, u.wattage
    ORDER BY m.id ASC, u.wattage ASC
");
$stmtBatches->bind_param("i", $project_id);
$stmtBatches->execute();
$resultBatches = $stmtBatches->get_result();
while ($row = $resultBatches->fetch_assoc()) {
    $assigned_batches[] = $row;
}
$stmtBatches->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details - <?php echo htmlspecialchars($project['project_name']); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>

        /* Page header */
        .page-header {
            text-align: center;
            margin-bottom: 20px;
            color: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(72, 140, 154, 0.2);
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 600;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .page-header p {
            font-size: 1.1rem;
            font-weight: 300;
            opacity: 0.9;
        }

        .account-info {
            background: rgba(255,255,255,0.1);
            padding: 12px 20px;
            border-radius: 8px;
            margin-top: 16px;
            font-size: 0.95rem;
        }

        /* Breadcrumb styling */
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
            padding: 12px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #488C9A;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }

        /* Form layout */
        .info-container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 400px;
        }

        .info-section {
            padding: 40px;
            border-right: 1px solid #f0f0f0;
        }

        .info-section:last-child {
            border-right: none;
        }

        .info-section h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 2px solid #488C9A;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-section h2::before {
            content: "📋";
            font-size: 1.2em;
        }

        .info-section:nth-child(2) h2::before {
            content: "📍";
        }

        /* Info item styling */
        .info-item {
            margin-bottom: 24px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #488C9A;
        }

        .info-item label {
            display: block;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 8px;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-item .value {
            font-size: 1.1rem;
            color: #1a1a1a;
            line-height: 1.4;
            word-break: break-word;
        }

        .info-item .value.empty {
            color: #999;
            font-style: italic;
        }

        /* Address grid */
        .address-display {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 16px;
        }

        .address-display .info-item {
            margin-bottom: 0;
        }

        /* Phone grid */
        .phone-display {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        .phone-display .info-item {
            margin-bottom: 0;
        }

        /* Module summary section */
        .module-summary {
            grid-column: 1 / -1;
            border-top: 1px solid #f0f0f0;
            margin-top: 20px;
            padding: 40px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        .module-summary h2 {
            color: #293E4C;
            border-bottom: 2px solid #488C9A;
            padding-bottom: 12px;
            margin-bottom: 24px;
        }

        .module-summary h2::before {
            content: "⚡";
        }

        .module-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .module-stat {
            background: white;
            padding: 24px;
            border-radius: 12px;
            border-left: 4px solid #488C9A;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .module-stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }

        .module-stat .wattage {
            font-size: 2em;
            font-weight: bold;
            color: #293E4C;
            margin-bottom: 8px;
        }

        .module-stat .quantity {
            font-size: 1.2em;
            color: #488C9A;
            font-weight: 600;
        }

        .total-summary {
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            font-weight: 700;
            color: white;
            margin-bottom: 30px;
            font-size: 1.2em;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .batch-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }

        .batch-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .batch-table th {
            background: #293E4C;
            color: white;
            padding: 16px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }

        .batch-table td {
            padding: 16px;
            border-bottom: 1px solid #f0f0f0;
        }

        .batch-table tr:last-child td {
            border-bottom: none;
        }

        .batch-table tr:hover {
            background: #f8f9fa;
        }

        .no-modules {
            text-align: center;
            padding: 60px 40px;
            background: white;
            border-radius: 12px;
            color: #666;
        }

        .no-modules h3 {
            color: #293E4C;
            margin-bottom: 16px;
            font-size: 1.5em;
        }

        .no-modules p {
            margin-bottom: 12px;
            line-height: 1.6;
        }

        .info-note {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: 1px solid #90caf9;
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
            font-size: 0.95em;
            color: #1565c0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .info-note::before {
            content: "💡";
            font-size: 1.2em;
            flex-shrink: 0;
        }

        /* Current file displays */
        .current-file {
            background: white;
            padding: 16px;
            border-radius: 8px;
            margin-top: 8px;
            border: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .current-file img {
            max-width: 120px;
            max-height: 80px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .current-file a {
            color: #488C9A;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .current-file a:hover {
            text-decoration: underline;
        }

        .current-file a::before {
            content: "📄";
        }

        /* Navigation section */
        .navigation-section {
            text-align: center;
            padding: 40px;
            background: #f8f9fa;
            border-top: 1px solid #f0f0f0;
        }

        .nav-buttons {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #488C9A;
            color: white;
            text-decoration: none;
            border-radius: 25px;
            transition: all 0.3s ease;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.3);
        }

        .nav-link:hover {
            background: #293E4C;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(72, 140, 154, 0.4);
        }

        .nav-link.secondary {
            background: #6c757d;
            box-shadow: 0 2px 8px rgba(108, 117, 125, 0.3);
        }

        .nav-link.secondary:hover {
            background: #5a6268;
            box-shadow: 0 4px 16px rgba(108, 117, 125, 0.4);
        }

        /* Responsive design */
        @media (max-width: 1024px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            .info-section {
                border-right: none;
                border-bottom: 1px solid #f0f0f0;
            }

            .info-section:last-child {
                border-bottom: none;
            }

            .address-display {
                grid-template-columns: 1fr 1fr;
            }

            .module-stats {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            main {
                width: 95%;
                padding: 10px;
            }

            .info-section,
            .module-summary {
                padding: 24px;
            }

            .address-display,
            .phone-display,
            .module-stats {
                grid-template-columns: 1fr;
            }

            .page-header h1 {
                font-size: 2rem;
            }

            .nav-buttons {
                flex-direction: column;
                align-items: center;
            }

            .nav-link {
                width: 100%;
                max-width: 280px;
                justify-content: center;
            }
        }

        /* Subtle animations */
        .info-item {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }

        .info-item:nth-child(1) { animation-delay: 0.1s; }
        .info-item:nth-child(2) { animation-delay: 0.2s; }
        .info-item:nth-child(3) { animation-delay: 0.3s; }
        .info-item:nth-child(4) { animation-delay: 0.4s; }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Project Details',
            'extra' => [ ['label' => 'Project Overview', 'url' => 'project_overview.php?project_id='.(int)$project_id] ]
        ]);
    ?>

    <div class="page-header">
        <h1><?php echo htmlspecialchars($project['project_name']); ?></h1>
        <p>Complete project information and details</p>
    </div>

    <div class="info-container">
        <div class="info-grid">
            <!-- Left Column: Basic Project Information -->
            <div class="info-section">
                <h2>Project Details</h2>
                
                <div class="info-item">
                    <label>Project Name</label>
                    <div class="value"><?php echo htmlspecialchars($project['project_name']); ?></div>
                </div>

                <div class="info-item">
                    <label>Project Address</label>
                    <div class="address-display">
                        <div class="info-item">
                            <label>Street Address</label>
                            <div class="value"><?php echo !empty($project['street_address']) ? htmlspecialchars($project['street_address']) : '<span class="empty">Not specified</span>'; ?></div>
                        </div>
                        <div class="info-item">
                            <label>City</label>
                            <div class="value"><?php echo !empty($project['city']) ? htmlspecialchars($project['city']) : '<span class="empty">Not specified</span>'; ?></div>
                        </div>
                        <div class="info-item">
                            <label>State</label>
                            <div class="value"><?php echo !empty($project['state']) ? htmlspecialchars($project['state']) : '<span class="empty">Not specified</span>'; ?></div>
                        </div>
                        <div class="info-item">
                            <label>Zip Code</label>
                            <div class="value"><?php echo !empty($project['zip_code']) ? htmlspecialchars($project['zip_code']) : '<span class="empty">Not specified</span>'; ?></div>
                        </div>
                    </div>
                </div>

                <div class="info-item">
                    <label>Estimated Completion Date</label>
                    <div class="value">
                        <?php 
                        if (!empty($project['estimated_completion_date']) && $project['estimated_completion_date'] !== '0000-00-00') {
                            echo date('F j, Y', strtotime($project['estimated_completion_date']));
                        } else {
                            echo '<span class="empty">Not set</span>';
                        }
                        ?>
                    </div>
                </div>

                <?php if (!empty($project['image_url'])): ?>
                <div class="info-item">
                    <label>Project Image</label>
                    <div class="current-file">
                        <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="Project Image">
                        <div>
                            <strong>Project Photo</strong><br>
                            <small style="color: #666;">Click to view full size</small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="info-item">
                    <label>Project Fee Structure</label>
                    <div class="value">
                        <?php 
                        if (isset($project['solterra_fee']) && $project['solterra_fee'] > 0) {
                            echo '$' . number_format($project['solterra_fee'], 4) . ' per watt';
                        } else {
                            echo '<span class="empty">Not specified</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Site Information -->
            <div class="info-section">
                <h2>Site Information</h2>
                
                <div class="info-item">
                    <label>Contact Information</label>
                    <div class="phone-display">
                        <div class="info-item">
                            <label>Primary Phone</label>
                            <div class="value"><?php echo !empty($project['phone1']) ? htmlspecialchars($project['phone1']) : '<span class="empty">Not provided</span>'; ?></div>
                        </div>
                        <div class="info-item">
                            <label>Secondary Phone</label>
                            <div class="value"><?php echo !empty($project['phone2']) ? htmlspecialchars($project['phone2']) : '<span class="empty">Not provided</span>'; ?></div>
                        </div>
                        <div class="info-item">
                            <label>Timezone</label>
                            <div class="value">
                                <?php 
                                $timezones = [
                                    'America/New_York' => 'Eastern',
                                    'America/Chicago' => 'Central', 
                                    'America/Denver' => 'Mountain',
                                    'America/Los_Angeles' => 'Pacific',
                                    'UTC' => 'UTC'
                                ];
                                $current_timezone = $project['timezone'] ?? 'America/New_York';
                                echo $timezones[$current_timezone] ?? $current_timezone;
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="info-item">
                    <label>Reference Numbers</label>
                    <div class="value"><?php echo !empty($project['reference_numbers']) ? htmlspecialchars($project['reference_numbers']) : '<span class="empty">None provided</span>'; ?></div>
                </div>

                <div class="info-item">
                    <label>Special Instructions</label>
                    <div class="value"><?php echo !empty($project['instructions']) ? nl2br(htmlspecialchars($project['instructions'])) : '<span class="empty">No special instructions</span>'; ?></div>
                </div>

                <div class="info-item">
                    <label>Additional Notes</label>
                    <div class="value"><?php echo !empty($project['additional_notes']) ? nl2br(htmlspecialchars($project['additional_notes'])) : '<span class="empty">No additional notes</span>'; ?></div>
                </div>

                <?php if (!empty($project['driver_handout_url'])): ?>
                <div class="info-item">
                    <label>Driver Handout</label>
                    <div class="current-file">
                        <a href="<?php echo htmlspecialchars($project['driver_handout_url']); ?>" target="_blank">
                            <?php echo htmlspecialchars(basename($project['driver_handout_url'])); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Module Summary Section (Full Width) -->
            <div class="module-summary">
                <h2>Project Module Summary</h2>
                
                <?php if (!empty($actual_modules)): ?>
                    <div class="module-stats">
                        <?php foreach ($actual_modules as $module): ?>
                            <div class="module-stat">
                                <div class="wattage"><?php echo number_format($module['wattage']); ?>W</div>
                                <div class="quantity"><?php echo number_format($module['total_quantity']); ?> modules</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="total-summary">
                        Total Project Modules: 
                        <?php 
                        $total_all = array_sum(array_column($actual_modules, 'total_quantity'));
                        echo number_format($total_all); 
                        ?>
                    </div>

                    <?php if (!empty($assigned_batches)): ?>
                        <div class="batch-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Batch ID</th>
                                        <th>Manufacturer</th>
                                        <th>Wattage</th>
                                        <th>Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assigned_batches as $batch): ?>
                                        <tr>
                                            <td><strong>#<?php echo $batch['batch_id']; ?></strong></td>
                                            <td>
                                                <?php 
                                                $manufacturer_name = explode(' - ', $batch['vendor_name'])[0];
                                                echo htmlspecialchars($manufacturer_name); 
                                                ?>
                                            </td>
                                            <td><?php echo number_format($batch['wattage']); ?>W</td>
                                            <td><?php echo number_format($batch['quantity']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-modules">
                        <h3>No Module Batches Assigned</h3>
                        <p>This project doesn't have any module batches assigned to it yet.</p>
                        <p>Module assignments will appear here once they are configured by the project administrators.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Navigation Section -->
            <div class="navigation-section">
                <div class="nav-buttons">
                    <a href="project_overview.php?project_id=<?php echo $project_id; ?>" class="nav-link">
                        
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
