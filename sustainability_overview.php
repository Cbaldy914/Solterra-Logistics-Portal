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

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Handle filter selection
$filter = $_GET['filter'] ?? 'total';
$current_year = date('Y');

// Prepare to fetch projects differently depending on role
if ($role === 'admin' || $role === 'global_admin') {
    // Admin or global_admin => can see all projects
    $sql_projects = "
        SELECT p.id, p.project_name, p.image_url
        FROM projects p
    ";
    $params = [];
    $paramTypes = "";
} else {
    // Regular user => must join with customer_account_users to ensure user_id belongs to the project's account
    $sql_projects = "
        SELECT p.id, p.project_name, p.image_url
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE cau.user_id = ?
    ";
    $params = [$user_id];
    $paramTypes = "i";
}

// Now fetch the projects
$stmt_projects = $conn->prepare($sql_projects);
if (!empty($paramTypes)) {
    $stmt_projects->bind_param($paramTypes, ...$params);
}
$stmt_projects->execute();
$projects_result = $stmt_projects->get_result();
$stmt_projects->close();

$project_count = $projects_result->num_rows;

// Initialize sums
$total_emissions       = 0;
$total_truckloads      = 0;
$total_miles_driven    = 0;
$total_fuel_consumption= 0;

$projects = [];

// For each project, sum up the sustainability metrics from the deliveries
while ($project = $projects_result->fetch_assoc()) {
    $project_id              = $project['id'];
    $project_total_emissions = 0;
    $project_total_truckloads= 0;
    $project_miles_driven    = 0;
    $project_fuel_consumption= 0;

    // Build base deliveries query
    $sql_deliveries = "SELECT * FROM deliveries WHERE project_id = ?";
    if ($filter === 'ytd') {
        $sql_deliveries .= " AND YEAR(created_at) = ?";
    }

    $stmt_deliveries = $conn->prepare($sql_deliveries);
    if ($filter === 'ytd') {
        $stmt_deliveries->bind_param("ii", $project_id, $current_year);
    } else {
        $stmt_deliveries->bind_param("i", $project_id);
    }
    $stmt_deliveries->execute();
    $deliveries_result = $stmt_deliveries->get_result();
    $stmt_deliveries->close();

    while ($delivery = $deliveries_result->fetch_assoc()) {
        // If "Delivered", increment truckloads
        if (in_array($delivery['status_of_delivery'] ?? '', ['Delivered to Project', 'Delivered to Warehouse'])) {
            $project_total_truckloads += 1;
        }

        // Miles from deliveries
        $miles_driven = (float)($delivery['miles'] ?? 0);
        $project_miles_driven += $miles_driven;

        // Fuel consumption (gallons)
        $fuel_consumption = $miles_driven * 0.1667;
        $project_fuel_consumption += $fuel_consumption;

        // Emissions (kg CO₂)
        $emissions = $fuel_consumption * 10.21; 
        $project_total_emissions += $emissions;
    }

    // Accumulate global totals
    $total_emissions        += $project_total_emissions;
    $total_truckloads       += $project_total_truckloads;
    $total_miles_driven     += $project_miles_driven;
    $total_fuel_consumption += $project_fuel_consumption;

    // Add to project array
    $project['total_emissions']     = $project_total_emissions;
    $project['total_truckloads']    = $project_total_truckloads;
    $project['miles_driven']        = $project_miles_driven;
    $project['fuel_consumption']    = $project_fuel_consumption;

    $projects[] = $project;
}

// Depending on filter, either show global total or average per project
if ($filter === 'per_project' && $project_count > 0) {
    $display_total_emissions   = $total_emissions / $project_count;
    $display_total_truckloads  = $total_truckloads / $project_count;
    $display_miles_driven      = $total_miles_driven / $project_count;
    $display_fuel_consumption  = $total_fuel_consumption / $project_count;
} else {
    $display_total_emissions   = $total_emissions;
    $display_total_truckloads  = $total_truckloads;
    $display_miles_driven      = $total_miles_driven;
    $display_fuel_consumption  = $total_fuel_consumption;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sustainability Overview</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .info-tooltip {
            display: inline-block;
            width: 18px;
            height: 18px;
            line-height: 18px;
            text-align: center;
            background-color: #488C9A;
            color: white;
            border-radius: 50%;
            font-weight: bold;
            cursor: pointer;
            margin-left: 5px;
            position: relative;
            vertical-align: middle;
            top: -3px;
            font-size: 0.5em;
        }
        .info-tooltip:hover {
            background-color: #293E4C;
        }
        .info-tooltip .tooltip-text {
            display: none;
            width: 400px;
            background-color: #fff;
            color: #333;
            text-align: left;
            border-radius: 4px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            top: 25px;
            left: -200px;
            box-shadow: 0 0 5px rgba(0,0,0,0.3);
            font-weight: normal;
        }
        .info-tooltip:hover .tooltip-text,
        .info-tooltip.active .tooltip-text {
            display: block;
        }
        h2 {
            margin-top: 50px;
            margin-bottom: 20px;
            color: #293E4C;
            font-size: 1.8em;
            font-weight: 600;
            padding-bottom: 10px;
            border-bottom: 3px solid #488C9A;
            position: relative;
        }
        h2::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            width: 60px;
            height: 3px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 2px;
        }
        .filter-form {
            margin: 15px 0 25px 0;
            padding: 15px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 1px solid #dee2e6;
            width: auto !important;
            max-width: fit-content;
            display: inline-block;
        }
        .filter-form label {
            margin-right: 15px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 6px;
            transition: background-color 0.2s ease;
            font-size: 0.9em;
        }
        .filter-form label:hover {
            background-color: #f8f9fa;
        }
        .filter-form input[type="radio"] {
            margin: 0;
        }
        .cost-overview {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            margin-bottom: 50px;
        }
        .cost-row {
            display: flex;
            width: 100%;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .cost-metric {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            margin: 8px;
            border-radius: 12px;
            text-align: center;
            min-width: 200px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            border: 1px solid #dee2e6;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .cost-metric:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }
        .cost-metric h3 {
            margin: 0 0 10px 0;
            font-weight: 600;
            color: #293E4C;
            font-size: 1rem;
        }
        .cost-metric p {
            margin: 0;
            font-size: 1.4rem;
            font-weight: bold;
            color: #488C9A;
        }
        .projects-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
            padding: 0;
        }
        .project-item {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
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
            padding: 25px;
            background: #fafbfc;
            width: 100% !important;
            box-sizing: border-box;
            text-align: left !important;
            position: relative;
        }
        .project-details {
            background: #ffffff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid #f1f3f4;
            width: 100% !important;
            box-sizing: border-box;
            text-align: left !important;
            float: none !important;
            position: relative;
        }
        .project-details p {
            margin: 12px 0;
            color: #495057;
            font-size: 0.95em;
            line-height: 1.6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 24px;
        }
        .project-details strong {
            color: #293E4C;
            font-weight: 600;
        }
        .sustainability-value {
            font-weight: 700;
            font-size: 1.1em;
        }
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
        @media (max-width: 768px) {
            .projects-container {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            .cost-row {
                flex-direction: column;
                align-items: center;
            }
            .cost-metric {
                min-width: 250px;
                margin: 5px 0;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb">
        <a href="<?php echo isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'global_admin') ? 'admin_dashboard.php' : 'dashboard.php'; ?>">Dashboard</a>
        <span class="separator">&raquo;</span>
        <span>Sustainability Overview</span>
    </div>
    <h1>Sustainability Overview
        <span class="info-tooltip">?
            <span class="tooltip-text">
                <p>Calculations and assumptions:</p>
                <ul>
                    <li>6 miles per gallon for heavy-duty freight trucks in the US (US DOE).</li>
                    <li>Fuel consumption ~ 0.1667 gallons/mile.</li>
                    <li>Diesel emits ~10.21 kg CO₂/gallon (EPA).</li>
                </ul>
            </span>
        </span>
    </h1>

    <!-- Filter form -->
    <form method="GET" id="filter-form" class="filter-form">
        <label>
            <input type="radio" name="filter" value="total" onchange="this.form.submit();"
                   <?php if ($filter==='total') echo 'checked'; ?>>
            📊 Total Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="ytd" onchange="this.form.submit();"
                   <?php if ($filter==='ytd') echo 'checked'; ?>>
            📅 Year-to-Date Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="per_project" onchange="this.form.submit();"
                   <?php if ($filter==='per_project') echo 'checked'; ?>>
            📈 Average per Project
        </label>
    </form>

    <!-- Key metrics -->
    <div class="cost-overview">
        <div class="cost-row">
            <div class="cost-metric">
                <h3>
                    🌱 <?php echo ($filter==='per_project') ? 'Avg. Emissions / Project' : 'Total Emissions'; ?>
                </h3>
                <p><?php echo number_format($display_total_emissions, 2); ?> kg CO₂</p>
            </div>
            <div class="cost-metric">
                <h3>
                    🚛 <?php echo ($filter==='per_project') ? 'Avg. Truckloads / Project' : 'Total Truckloads'; ?>
                </h3>
                <p><?php echo number_format($display_total_truckloads, 0); ?></p>
            </div>
            <div class="cost-metric">
                <h3>
                    🛣️ <?php echo ($filter==='per_project') ? 'Avg. Miles / Project' : 'Miles Driven'; ?>
                </h3>
                <p><?php echo number_format($display_miles_driven, 2); ?> miles</p>
            </div>
            <div class="cost-metric">
                <h3>
                    ⛽ <?php echo ($filter==='per_project') ? 'Avg. Fuel / Project' : 'Fuel Consumption'; ?>
                </h3>
                <p><?php echo number_format($display_fuel_consumption, 2); ?> gallons</p>
            </div>
        </div>
    </div>

    <h2>🌍 Sustainability by Project</h2>
    <div class="projects-container">
        <?php if (!empty($projects)): ?>
            <?php foreach ($projects as $proj): ?>
                <div class="project-item" onclick="window.location.href='project_sustainability_details?project_id=<?php echo $proj['id']; ?>'">
                    <div class="project-title">
                        <h3>
                            <a href="project_sustainability_details?project_id=<?php echo $proj['id']; ?>">
                                <?php echo htmlspecialchars($proj['project_name']); ?>
                            </a>
                        </h3>
                    </div>
                    <div class="project-image">
                        <img src="<?php echo htmlspecialchars($proj['image_url']); ?>" alt="<?php echo htmlspecialchars($proj['project_name']); ?>">
                        <div class="project-overlay">
                            <div class="project-overlay-text">View Sustainability Details</div>
                        </div>
                    </div>
                    <div class="project-content">
                        <div class="project-details">
                            <p>
                                <strong>🌱 <?php echo ($filter==='ytd')?'YTD ':''; ?>Emissions</strong>
                                <span class="sustainability-value"><?php echo number_format($proj['total_emissions'], 2); ?> kg CO₂</span>
                            </p>
                            <p>
                                <strong>🚛 <?php echo ($filter==='ytd')?'YTD ':''; ?>Truckloads</strong>
                                <span class="sustainability-value"><?php echo number_format($proj['total_truckloads'], 0); ?></span>
                            </p>
                            <p>
                                <strong>🛣️ <?php echo ($filter==='ytd')?'YTD ':''; ?>Miles Driven</strong>
                                <span class="sustainability-value"><?php echo number_format($proj['miles_driven'], 2); ?> mi</span>
                            </p>
                            <p>
                                <strong>⛽ <?php echo ($filter==='ytd')?'YTD ':''; ?>Fuel</strong>
                                <span class="sustainability-value"><?php echo number_format($proj['fuel_consumption'], 2); ?> gal</span>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #6c757d; grid-column: 1/-1;">
                <h3>🌱 No Projects Found</h3>
                <p>No projects with sustainability data are available for the selected filter.</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
// Mobile-friendly tooltip toggling
document.addEventListener('DOMContentLoaded', function() {
    var tooltips = document.querySelectorAll('.info-tooltip');
    tooltips.forEach(function(tooltip) {
        tooltip.addEventListener('click', function(e) {
            e.stopPropagation();
            this.classList.toggle('active');
        });
    });
    document.addEventListener('click', function(e) {
        tooltips.forEach(function(tt) {
            if (!tt.contains(e.target)) {
                tt.classList.remove('active');
            }
        });
    });
});
</script>
</body>
</html>
