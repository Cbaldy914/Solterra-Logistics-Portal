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
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch estimate data
$stmt = $conn->prepare("SELECT estimate_data, name, created_at FROM warehouse_quotes WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $estimate_id, $user_id);
$stmt->execute();
$stmt->bind_result($estimate_data_json, $name, $created_at);
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
    <title>View Warehouse Estimate</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <!-- Google Fonts -->
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
        input {
            width: 95%;
            padding: 8px;
            margin-top: 5px;
        }
        input[readonly] {
            background-color: #f9f9f9;
        }
        #map {
            width: 100%;
            height: 500px;
            margin-top: 20px;
        }
        .disclaimer {
            margin-top: 20px;
            font-size: 0.9em;
            color: #666;
        }
        table {
            width: 100%;
            margin-top: 20px;
        }
        th, td {
            padding: 8px;
            text-align: left;
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

    <!-- Display Quotes at the Top -->
    <h2>Available Quotes</h2>
    <?php if (!empty($estimate_data['quotes'])): ?>
        <table>
            <tr>
                <th>Warehouse Location</th>
                <th>In Fee (per pallet)</th>
                <th>Out Fee (per pallet)</th>
                <th>Monthly Storage Fee (per pallet)</th>
            </tr>
            <?php foreach ($estimate_data['quotes'] as $quote): ?>
                <tr>
                    <td><?php echo htmlspecialchars($quote['warehouse_location']); ?></td>
                    <td>$<?php echo number_format($quote['in_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['out_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['monthly_storage_cost_per_pallet'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>A quote will be provided by Solterra Solutions shortly.</p>
    <?php endif; ?>

    <p class="disclaimer">This number is an estimate and is for budgeting purposes only.</p>

    <div class="container">
        <div class="left-side">
            <!-- Display estimate details in a non-editable form -->
            <form>
                <label for="estimate_name">Estimate Name:</label>
                <input type="text" id="estimate_name" name="estimate_name" value="<?php echo htmlspecialchars($name); ?>" readonly>

                <label for="project_location">Project Location:</label>
                <input type="text" id="project_location" name="project_location" value="<?php echo htmlspecialchars($estimate_data['project_location']); ?>" readonly>

                <label for="estimated_storage_start">Estimated Storage Start:</label>
                <input type="date" id="estimated_storage_start" name="estimated_storage_start" value="<?php echo htmlspecialchars($estimate_data['estimated_storage_start']); ?>" readonly>

                <label for="estimated_number_of_pallets">Estimated Number of Pallets:</label>
                <input type="number" id="estimated_number_of_pallets" name="estimated_number_of_pallets" value="<?php echo htmlspecialchars($estimate_data['estimated_number_of_pallets']); ?>" readonly>

                <label>Estimated Pallet Dimensions (L x W x H in inches):</label>
                <input type="text" value="<?php echo htmlspecialchars($estimate_data['pallet_length'] . ' x ' . $estimate_data['pallet_width'] . ' x ' . $estimate_data['pallet_height']); ?>" readonly>

                <label for="stackable">Stackable:</label>
                <input type="text" id="stackable" name="stackable" value="<?php echo $estimate_data['stackable'] ? 'Yes' : 'No'; ?>" readonly>

                <label for="square_feet">Calculated Square Feet:</label>
                <input type="text" id="square_feet" name="square_feet" value="<?php echo number_format($estimate_data['square_feet'], 2); ?> sq ft" readonly>
            </form>
        </div>

        <div class="right-side">
            <!-- Display the map -->
            <div id="map"></div>
        </div>
    </div>
</main>

<!-- Load the Google Maps JavaScript API -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCYF3qz_6niMzpTd0yklUX9YNpk73KviBM"></script>

<script>
    // JavaScript code to display the map and warehouse locations
    function initMap() {
        var map = new google.maps.Map(document.getElementById('map'), {
            zoom: 6,
            center: {lat: 34.0489, lng: -111.0937} // Center over Arizona
        });

        var geocoder = new google.maps.Geocoder();

        // Get warehouse locations from PHP
        var warehouseQuotes = <?php echo json_encode($estimate_data['quotes']); ?>;
        var projectLocation = "<?php echo addslashes($estimate_data['project_location']); ?>";

        // Geocode and place the project location marker
        geocoder.geocode({'address': projectLocation}, function(results, status) {
            if (status === 'OK') {
                var projectMarker = new google.maps.Marker({
                    map: map,
                    position: results[0].geometry.location,
                    title: 'Project Location',
                    icon: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png' // Different color icon
                });

                var projectInfoWindow = new google.maps.InfoWindow({
                    content: '<strong>Project Location</strong><br>' + projectLocation
                });

                projectMarker.addListener('click', function() {
                    projectInfoWindow.open(map, projectMarker);
                });

                // Adjust map center to fit all markers
                var bounds = new google.maps.LatLngBounds();
                bounds.extend(results[0].geometry.location);

                // Geocode and place warehouse markers
                warehouseQuotes.forEach(function(quote) {
                    var location = quote.warehouse_location;
                    geocodeAddress(geocoder, map, location, quote, bounds);
                });
            } else {
                console.error('Geocode was not successful for the following reason: ' + status);
            }
        });
    }

    function geocodeAddress(geocoder, map, address, quote, bounds) {
        geocoder.geocode({'address': address}, function(results, status) {
            if (status === 'OK') {
                var marker = new google.maps.Marker({
                    map: map,
                    position: results[0].geometry.location,
                    title: address
                });

                var infoWindow = new google.maps.InfoWindow({
                    content: '<strong>' + address + '</strong><br>' +
                             'In Fee: $' + quote.in_fee_per_pallet.toFixed(2) + ' per pallet<br>' +
                             'Out Fee: $' + quote.out_fee_per_pallet.toFixed(2) + ' per pallet<br>' +
                             'Monthly Storage Fee: $' + quote.monthly_storage_cost_per_pallet.toFixed(2) + ' per pallet'
                });

                marker.addListener('click', function() {
                    infoWindow.open(map, marker);
                });

                bounds.extend(results[0].geometry.location);
                map.fitBounds(bounds);
            } else {
                console.error('Geocode was not successful for the following reason: ' + status);
            }
        });
    }

    // Initialize the map when the page loads
    window.onload = initMap;
</script>
</body>
</html>
