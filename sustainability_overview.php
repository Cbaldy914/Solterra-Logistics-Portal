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
if ($role === 'global_admin') {
    // Global admin => can see all projects
    $sql_projects = "
        SELECT p.id, p.project_name, p.image_url
        FROM projects p
    ";
    $params = [];
    $paramTypes = "";
} else {
    // Admins and regular users => only projects tied to their account(s)
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
        /* Header Section - Matching Manage Projects Style */
        .sustainability-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            /* Allow tooltips and overlays to render outside the header */
            overflow: visible;
        }

        .sustainability-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        /* Ensure tooltip text is visible inside gradient header */
        .header-info h1 .info-tooltip {
            -webkit-text-fill-color: #ffffff !important;
            -webkit-background-clip: initial !important;
            background-clip: initial !important;
            color: #ffffff !important;
            /* prevent inheriting giant font-size from h1 */
            font-size: 12px;
        }
        .header-info h1 .info-tooltip *,
        .info-tooltip .tooltip-text,
        .info-tooltip .tooltip-text * {
            -webkit-text-fill-color: initial !important;
            color: #333 !important;
        }
        .header-info h1 .info-tooltip .tooltip-text {
            font-size: 0.9rem;
            line-height: 1.4;
            width: 320px;
            padding: 12px 14px;
            left: -160px; /* center better under the icon */
        }
        .header-info h1 .info-tooltip .tooltip-text p {
            margin: 0 0 6px 0;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .header-info h1 .info-tooltip .tooltip-text ul {
            margin: 0;
            padding-left: 18px;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        /* Remove header stats - they're redundant */
        
        .info-tooltip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            line-height: 20px;
            text-align: center;
            background-color: #488C9A;
            color: white;
            border-radius: 50%;
            font-weight: bold;
            cursor: pointer;
            margin-left: 8px;
            position: relative;
            vertical-align: middle;
            top: -2px;
            font-size: 0.8em;
            z-index: 2;
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
            z-index: 3000;
            top: 26px;
            left: -190px;
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
        /* Remove old project styles - replaced with sustainability-project-* classes */
        /* New beautiful sustainability cards - no portal.css conflicts */
        .sustainability-projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 25px;
            padding: 0;
            margin-top: 30px;
        }
        
        .sustainability-project-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .sustainability-project-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.15);
        }
        .sustainability-project-header {
            padding: 25px 25px 20px 25px;
            background: #ffffff;
            border-bottom: 1px solid #f1f3f4;
            text-align: center;
        }
        
        .sustainability-project-title {
            margin: 0;
            font-size: 1.4em;
            color: #293E4C;
            font-weight: 600;
        }
        
        .sustainability-project-title a {
            text-decoration: none;
            color: inherit;
            transition: color 0.3s ease;
        }
        
        .sustainability-project-title a:hover {
            color: #488C9A;
        }
        
        .sustainability-project-image {
            width: 100%;
            height: 200px;
            overflow: hidden;
            position: relative;
        }
        
        .sustainability-project-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .sustainability-project-card:hover .sustainability-project-image img {
            transform: scale(1.05);
        }
        
        .sustainability-project-overlay {
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
        
        .sustainability-project-card:hover .sustainability-project-overlay {
            opacity: 1;
        }
        
        .sustainability-project-overlay-text {
            color: white;
            font-size: 1.2em;
            font-weight: 600;
            text-align: center;
        }
        
        .sustainability-project-body {
            padding: 25px;
            background: #fafbfc;
            display: flex;
            flex-direction: column;
        }
        
        .sustainability-metrics-container {
            background: #ffffff;
            border-radius: 12px;
            padding: 25px;
            border: 1px solid #f1f3f4;
            margin-bottom: 20px;
            flex: 1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        
        .sustainability-metric-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f8f9fa;
        }
        
        .sustainability-metric-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .sustainability-metric-row:first-child {
            padding-top: 0;
        }
        
        .sustainability-metric-label {
            color: #293E4C;
            font-weight: 600;
            font-size: 0.95em;
        }
        
        .sustainability-metric-value {
            font-weight: 700;
            color: #488C9A;
            font-size: 1.1em;
        }
        
        .sustainability-project-footer {
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid #f1f3f4;
            text-align: center;
        }
        
        .view-sustainability-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .view-sustainability-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        /* Removed old project-details styles - using new sustainability-metric-* classes */
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
        /* Responsive Header */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            /* Removed header stats styles */
            
            .header-info h1 {
                font-size: 2rem;
            }
            
            .header-subtitle {
                font-size: 1rem;
            }
            
            .sustainability-projects-grid {
                grid-template-columns: 1fr;
                gap: 20px;
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
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Sustainability Overview']); ?>
    
    <div class="sustainability-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-info">
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
                    <p class="header-subtitle">Environmental impact tracking and carbon footprint analysis</p>
                </div>
            </div>
        </div>
    </div>

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
    <div class="sustainability-projects-grid">
        <?php if (!empty($projects)): ?>
            <?php foreach ($projects as $proj): ?>
                <div class="sustainability-project-card" onclick="window.location.href='project_sustainability_details?project_id=<?php echo $proj['id']; ?>'">
                    <div class="sustainability-project-header">
                        <h3 class="sustainability-project-title">
                            <a href="project_sustainability_details?project_id=<?php echo $proj['id']; ?>">
                                <?php echo htmlspecialchars($proj['project_name']); ?>
                            </a>
                        </h3>
                    </div>
                    <div class="sustainability-project-image">
                        <img src="<?php echo htmlspecialchars($proj['image_url']); ?>" alt="<?php echo htmlspecialchars($proj['project_name']); ?>">
                        <div class="sustainability-project-overlay">
                            <div class="sustainability-project-overlay-text">View Sustainability Details</div>
                        </div>
                    </div>
                    <div class="sustainability-project-body">
                        <div class="sustainability-metrics-container">
                            <div class="sustainability-metric-row">
                                <span class="sustainability-metric-label">🌱 <?php echo ($filter==='ytd')?'YTD ':''; ?>Emissions</span>
                                <span class="sustainability-metric-value"><?php echo number_format($proj['total_emissions'], 2); ?> kg CO₂</span>
                            </div>
                            <div class="sustainability-metric-row">
                                <span class="sustainability-metric-label">🚛 <?php echo ($filter==='ytd')?'YTD ':''; ?>Truckloads</span>
                                <span class="sustainability-metric-value"><?php echo number_format($proj['total_truckloads'], 0); ?></span>
                            </div>
                            <div class="sustainability-metric-row">
                                <span class="sustainability-metric-label">🛣️ <?php echo ($filter==='ytd')?'YTD ':''; ?>Miles Driven</span>
                                <span class="sustainability-metric-value"><?php echo number_format($proj['miles_driven'], 2); ?> mi</span>
                            </div>
                            <div class="sustainability-metric-row">
                                <span class="sustainability-metric-label">⛽ <?php echo ($filter==='ytd')?'YTD ':''; ?>Fuel</span>
                                <span class="sustainability-metric-value"><?php echo number_format($proj['fuel_consumption'], 2); ?> gal</span>
                            </div>
                        </div>
                        <div class="sustainability-project-footer">
                            <div class="view-sustainability-btn">
                                <span>🌱</span>
                                <span>View Sustainability Details</span>
                            </div>
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
