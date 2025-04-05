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
    // Regular user => must join with customer_account_users to ensure user_id belongs to the project’s account
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
        if (($delivery['status_of_delivery'] ?? '') === 'Delivered') {
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
            margin-bottom: 0px;
        }
        .cost-overview {
            display: flex;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .cost-overview .cost-metric {
            margin-right: 20px;
            margin-bottom: 20px;
        }
        .cost-metric h3 {
            margin-bottom: 8px;
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

        }
        .project-item h3 {
            margin-top: 0;
        }
        .project-image img {
            max-width: 100%;
            border-radius: 4px;
        }
        .project-details p {
            margin: 4px 0;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
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
    <form method="GET" id="filter-form">
        <label>
            <input type="radio" name="filter" value="total" onchange="this.form.submit();"
                   <?php if ($filter==='total') echo 'checked'; ?>>
            Total Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="ytd" onchange="this.form.submit();"
                   <?php if ($filter==='ytd') echo 'checked'; ?>>
            Year-to-Date Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="per_project" onchange="this.form.submit();"
                   <?php if ($filter==='per_project') echo 'checked'; ?>>
            Average per Project
        </label>
    </form>

    <!-- Key metrics -->
    <div class="cost-overview">
        <div class="cost-metric">
            <h3>
                <?php echo ($filter==='per_project') ? 'Avg. Emissions / Project' : 'Total Emissions'; ?>
            </h3>
            <p><?php echo number_format($display_total_emissions, 2); ?> kg CO₂</p>
        </div>
        <div class="cost-metric">
            <h3>
                <?php echo ($filter==='per_project') ? 'Avg. Truckloads / Project' : 'Total Truckloads'; ?>
            </h3>
            <p><?php echo number_format($display_total_truckloads, 0); ?></p>
        </div>
        <div class="cost-metric">
            <h3>
                <?php echo ($filter==='per_project') ? 'Avg. Miles / Project' : 'Miles Driven'; ?>
            </h3>
            <p><?php echo number_format($display_miles_driven, 2); ?> miles</p>
        </div>
        <div class="cost-metric">
            <h3>
                <?php echo ($filter==='per_project') ? 'Avg. Fuel / Project' : 'Fuel Consumption'; ?>
            </h3>
            <p><?php echo number_format($display_fuel_consumption, 2); ?> gallons</p>
        </div>
    </div>

    <h2>Sustainability by Project:</h2>
    <div class="projects-container">
        <?php if (!empty($projects)): ?>
            <?php foreach ($projects as $proj): ?>
                <div class="project-item">
                    <h3>
                        <a href="project_sustainability_details?project_id=<?php echo $proj['id']; ?>">
                            <?php echo htmlspecialchars($proj['project_name']); ?>
                        </a>
                    </h3>
                    <div class="project-image">
                        <a href="project_sustainability_details?project_id=<?php echo $proj['id']; ?>">
                            <img src="<?php echo htmlspecialchars($proj['image_url']); ?>" alt="Project Image">
                        </a>
                    </div>
                    <div class="project-details">
                        <p>
                            <strong><?php echo ($filter==='ytd')?'YTD ':''; ?>Emissions:</strong>
                            <?php echo number_format($proj['total_emissions'], 2); ?> kg CO₂
                        </p>
                        <p>
                            <strong><?php echo ($filter==='ytd')?'YTD ':''; ?>Truckloads:</strong>
                            <?php echo number_format($proj['total_truckloads'], 0); ?>
                        </p>
                        <p>
                            <strong><?php echo ($filter==='ytd')?'YTD ':''; ?>Miles Driven:</strong>
                            <?php echo number_format($proj['miles_driven'], 2); ?>
                        </p>
                        <p>
                            <strong><?php echo ($filter==='ytd')?'YTD ':''; ?>Fuel:</strong>
                            <?php echo number_format($proj['fuel_consumption'], 2); ?> gal
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No projects found.</p>
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
