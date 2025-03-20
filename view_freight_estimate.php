<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if (!isset($_GET['id'])) {
    die("Estimate ID not specified.");
}

$estimate_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch estimate data
$stmt = $conn->prepare("SELECT name, estimate_data, created_at FROM freight_estimates WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $estimate_id, $user_id);
$stmt->execute();
$stmt->bind_result($name, $estimate_data_json, $created_at);
if ($stmt->fetch()) {
    $estimate_data = json_decode($estimate_data_json, true);
} else {
    die("Estimate not found or you do not have permission to view it.");
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ... existing head content ... -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Freight Estimate</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Add any custom styles here */
        .container {
            display: flex;
            flex-wrap: wrap;
            margin: 0 auto;
        }
        .left-side {
            flex: 1 1 50%;
            padding: 20px;
            box-sizing: border-box;
        }
        .right-side {
            flex: 1 1 50%;
            padding: 20px;
            box-sizing: border-box;
        }
        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }
        input, select {
            width: 95%;
            padding: 8px;
            margin-top: 5px;
        }
        input[readonly] {
            background-color: #f9f9f9;
        }
        #distance {
            font-weight: bold;
            margin-top: 10px;
        }
        #map {
            width: 100%;
            height: 500px;
            background-color: #ccc;
            margin-top: 20px;
        }
        .disclaimer {
            margin-top: 20px;
            font-size: 0.9em;
            color: #666;
        }
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            .left-side, .right-side {
                flex: 1 1 100%;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <a href="javascript:history.back()" class="back-icon">
        <!-- SVG for Back Arrow -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>Back
    </a>
    <h1><?php echo htmlspecialchars($name); ?></h1>
    <p><strong>Created At:</strong> <?php echo htmlspecialchars($created_at); ?></p>
        <!-- Display costs if they have been added by admin -->
        <?php if ($estimate_data['grand_total'] !== null): ?>
        <h2>Costs</h2>
        <table>
            <thead>
                <tr>
                    <th>Cost per Truck</th>
                    <th>Total Freight Cost</th>
                    <th>Total Accessorial Cost</th>
                    <th>Grand Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>$<?php echo number_format($estimate_data['cost_per_truck'], 2); ?></td>
                    <td>$<?php echo number_format($estimate_data['total_freight_cost'], 2); ?></td>
                    <td>$<?php echo number_format($estimate_data['total_accessorial_cost'], 2); ?></td>
                    <td><strong>$<?php echo number_format($estimate_data['grand_total'], 2); ?></strong></td>
                </tr>
            </tbody>
        </table>
    <?php else: ?>
        <p>A cost estimate will be provided by Solterra Solutions shortly.</p>
    <?php endif; ?>

    <p class="disclaimer">This number is an estimate and is for budgeting purposes only.</p>
    </div>

    <div class="container">
        <div class="left-side">
            <!-- Display estimate details in a non-editable form -->
            <form>
                <label for="estimate_name">Estimate Name:</label>
                <input type="text" id="estimate_name" name="estimate_name" value="<?php echo htmlspecialchars($name); ?>" readonly>

                <label for="origin">Origin (City or ZIP Code):</label>
                <input type="text" id="origin" name="origin" value="<?php echo htmlspecialchars($estimate_data['origin']); ?>" readonly>

                <label for="destination">Destination (City or ZIP Code):</label>
                <input type="text" id="destination" name="destination" value="<?php echo htmlspecialchars($estimate_data['destination']); ?>" readonly>

                <label for="project_size">Project Size (in MW):</label>
                <input type="number" id="project_size" name="project_size" value="<?php echo htmlspecialchars($estimate_data['project_size']); ?>" readonly>

                <label for="estimated_start_date">Estimated Start Date:</label>
                <input type="date" id="estimated_start_date" name="estimated_start_date" value="<?php echo htmlspecialchars($estimate_data['estimated_start_date']); ?>" readonly>

                <label for="estimated_number_of_trucks">Estimated Number of Trucks:</label>
                <input type="number" id="estimated_number_of_trucks" name="estimated_number_of_trucks" value="<?php echo htmlspecialchars($estimate_data['estimated_number_of_trucks']); ?>" readonly>

                <label for="estimated_modules_per_truck">Estimated Modules Per Truck:</label>
                <input type="number" id="estimated_modules_per_truck" name="estimated_modules_per_truck" value="<?php echo htmlspecialchars($estimate_data['estimated_modules_per_truck']); ?>" readonly>

                <div id="distance">Distance: <?php echo htmlspecialchars($estimate_data['distance']); ?> miles</div>
            </form>
        </div>

        <div class="right-side">
            <!-- Display the map -->
            <div id="map"></div>
    </div>
</main>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCYF3qz_6niMzpTd0yklUX9YNpk73KviBM&libraries=places"></script>

<script>
    // JavaScript code for map and route display

    let map;
    let directionsService;
    let directionsRenderer;

    function initMap() {
        directionsService = new google.maps.DirectionsService();
        directionsRenderer = new google.maps.DirectionsRenderer();

        map = new google.maps.Map(document.getElementById('map'), {
            zoom: 5,
            center: { lat: 39.5, lng: -98.35 } // Center of the US
        });

        directionsRenderer.setMap(map);

        calculateRoute();
    }

    function calculateRoute() {
        const origin = "<?php echo addslashes($estimate_data['origin']); ?>";
        const destination = "<?php echo addslashes($estimate_data['destination']); ?>";

        if (origin && destination) {
            const request = {
                origin: origin,
                destination: destination,
                travelMode: 'DRIVING'
            };

            directionsService.route(request, function (result, status) {
                if (status === 'OK') {
                    directionsRenderer.setDirections(result);
                } else {
                    console.error('Could not display route: ' + status);
                }
            });
        }
    }

    // Initialize the map when the window loads
    window.onload = initMap;
</script>
</body>
</html>
