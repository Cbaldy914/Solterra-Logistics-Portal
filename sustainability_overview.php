<?php
session_name("logistics_session");
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch user role and ID
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Handle filter selection
$filter = $_GET['filter'] ?? 'total';

$current_year = date('Y');

// Initialize variables
$display_total_emissions = 0;
$display_total_truckloads = 0;
$display_miles_driven = 0;
$display_fuel_consumption = 0;

// Fetch projects for the logged-in user
$sql_projects = "
    SELECT p.id, p.project_name, p.image_url
    FROM projects p
    WHERE p.user_id = ?
";
$stmt_projects = $conn->prepare($sql_projects);
$stmt_projects->bind_param("i", $user_id);
$stmt_projects->execute();
$projects_result = $stmt_projects->get_result();
$stmt_projects->close();

$project_count = $projects_result->num_rows;

$total_emissions = 0;
$total_truckloads = 0;
$total_miles_driven = 0;
$total_fuel_consumption = 0;

$projects = [];

while ($project = $projects_result->fetch_assoc()) {
    $project_id = $project['id'];

    $project_total_emissions = 0;
    $project_total_truckloads = 0;
    $project_miles_driven = 0;
    $project_fuel_consumption = 0;

    $sql_deliveries = "SELECT * FROM deliveries WHERE project_id = ?";
    if ($filter == 'ytd') {
        $sql_deliveries .= " AND YEAR(created_at) = ?";
    }

    $stmt_deliveries = $conn->prepare($sql_deliveries);
    if ($filter == 'ytd') {
        $stmt_deliveries->bind_param("ii", $project_id, $current_year);
    } else {
        $stmt_deliveries->bind_param("i", $project_id);
    }
    $stmt_deliveries->execute();
    $deliveries_result = $stmt_deliveries->get_result();
    $stmt_deliveries->close();

    while ($delivery = $deliveries_result->fetch_assoc()) {
        $quantity = $delivery['quantity'];
        $wattage = $delivery['wattage'];

        // Count truckloads only if delivered
        if ($delivery['status_of_delivery'] == 'Delivered') {
            $project_total_truckloads += 1;
        }

        // Miles from deliveries table
        $miles_driven = isset($delivery['miles']) ? $delivery['miles'] : 0;
        $project_miles_driven += $miles_driven;

        // Fuel consumption (gallons)
        $fuel_consumption = $miles_driven * 0.1667; 
        $project_fuel_consumption += $fuel_consumption;

        // Emissions
        $emissions = $fuel_consumption * 10.21; 
        $project_total_emissions += $emissions;
    }

    $total_emissions += $project_total_emissions;
    $total_truckloads += $project_total_truckloads;
    $total_miles_driven += $project_miles_driven;
    $total_fuel_consumption += $project_fuel_consumption;

    $project['total_emissions'] = $project_total_emissions;
    $project['total_truckloads'] = $project_total_truckloads;
    $project['miles_driven'] = $project_miles_driven;
    $project['fuel_consumption'] = $project_fuel_consumption;

    $projects[] = $project;
}

if ($filter == 'per_project' && $project_count > 0) {
    $display_total_emissions = $total_emissions / $project_count;
    $display_total_truckloads = $total_truckloads / $project_count;
    $display_miles_driven = $total_miles_driven / $project_count;
    $display_fuel_consumption = $total_fuel_consumption / $project_count;
} else {
    $display_total_emissions = $total_emissions;
    $display_total_truckloads = $total_truckloads;
    $display_miles_driven = $total_miles_driven;
    $display_fuel_consumption = $total_fuel_consumption;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            font-size: .5em;
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

        /* Adjust as needed */
        .cost-overview {
            display: flex;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .cost-overview .cost-metric {
            margin-right: 20px;
            margin-bottom: 20px;
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
                    <li>6 miles per gallon for heavy-duty freight trucks in the US - U.S Department of Energy.</li>
                    <li>Fuel consumption per mile = 1 mpg = 0.1667 gallons per mile.</li>
                    <li>Diesel fuel emits approximately 10.21 kg CO₂ per gallon - U.S. Environmental Protection Agency.</li>
                    <li>The EPA's Greenhouse Gas Emission Factors Hub provides 10.21 kg CO₂/gallon for diesel combustion.</li>
                </ul>
            </span>
        </span>
    </h1>

    <form method="GET" id="filter-form">
        <label>
            <input type="radio" name="filter" value="total" onchange="this.form.submit();" <?php if ($filter == 'total') echo 'checked'; ?>>
            Total Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="ytd" onchange="this.form.submit();" <?php if ($filter == 'ytd') echo 'checked'; ?>>
            Year-to-Date Amounts
        </label>
        <label>
            <input type="radio" name="filter" value="per_project" onchange="this.form.submit();" <?php if ($filter == 'per_project') echo 'checked'; ?>>
            Average per Project
        </label>
    </form>

    <!-- Display the key metrics -->
    <div class="cost-overview">
        <div class="cost-metric">
            <h3><?php echo ($filter == 'per_project') ? 'Average Total Emissions per Project' : 'Total Emissions'; ?></h3>
            <p><?php echo number_format($display_total_emissions, 2); ?> kg CO₂</p>
        </div>
        <div class="cost-metric">
            <h3><?php echo ($filter == 'per_project') ? 'Average Truckloads per Project' : 'Total Truckloads'; ?></h3>
            <p><?php echo number_format($display_total_truckloads); ?></p>
        </div>
        <div class="cost-metric">
            <h3><?php echo ($filter == 'per_project') ? 'Average Miles Driven per Project' : 'Miles Driven'; ?></h3>
            <p><?php echo number_format($display_miles_driven, 2); ?> miles</p>
        </div>
        <div class="cost-metric">
            <h3><?php echo ($filter == 'per_project') ? 'Average Fuel Consumption per Project' : 'Fuel Consumption'; ?></h3>
            <p><?php echo number_format($display_fuel_consumption, 2); ?> gallons</p>
        </div>
    </div>

    <h2>Sustainability by Project:</h2>
    <div class="projects-container">
        <?php if (count($projects) > 0): ?>
            <?php foreach ($projects as $project): ?>
                <div class="project-item">
                    <h3><a href="project_sustainability_details?project_id=<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['project_name']); ?></a></h3>
                    <div class="project-image">
                        <a href="project_sustainability_details?project_id=<?php echo $project['id']; ?>">
                            <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="Project Image">
                        </a>
                    </div>
                    <div class="project-details">
                        <p><strong>Total Emissions<?php echo ($filter == 'ytd') ? ' (YTD)' : ''; ?>:</strong> <?php echo number_format($project['total_emissions'], 2); ?> kg CO₂</p>
                        <p><strong>Total Truckloads<?php echo ($filter == 'ytd') ? ' (YTD)' : ''; ?>:</strong> <?php echo number_format($project['total_truckloads']); ?></p>
                        <p><strong>Miles Driven<?php echo ($filter == 'ytd') ? ' (YTD)' : ''; ?>:</strong> <?php echo number_format($project['miles_driven'], 2); ?> miles</p>
                        <p><strong>Fuel Consumption<?php echo ($filter == 'ytd') ? ' (YTD)' : ''; ?>:</strong> <?php echo number_format($project['fuel_consumption'], 2); ?> gallons</p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No projects found.</p>
        <?php endif; ?>
    </div>
</main>

<script>
// Add a click event on info-tooltip to handle mobile tap
document.addEventListener('DOMContentLoaded', function() {
    var tooltips = document.querySelectorAll('.info-tooltip');
    tooltips.forEach(function(tooltip) {
        tooltip.addEventListener('click', function(e) {
            e.stopPropagation();
            // Toggle the active class
            this.classList.toggle('active');
        });
    });

    // Close tooltip if clicking outside
    document.addEventListener('click', function(e) {
        tooltips.forEach(function(tooltip) {
            if (!tooltip.contains(e.target)) {
                tooltip.classList.remove('active');
            }
        });
    });
});
</script>
</body>
</html>
