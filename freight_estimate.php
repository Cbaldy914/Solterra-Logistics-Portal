<?php
// freight_estimate

session_name("logistics_session");
session_start();

// Replace with your administrator's email address
$to_email = 'cbaldy@solterrasol.com';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // redirect to login page
    header("Location: login");
    exit();
}

// Get user role
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

// Get user ID
$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Initialize variables
$origin = $destination = $distance = $project_size = $estimated_start_date = '';
$estimated_number_of_trucks = $estimated_modules_per_truck = '';
$estimate_name = '';
$cost_per_truck = $total_accessorial_cost = '';
$total_freight_cost = $grand_total = 0;

// Handle estimate deletion
if (isset($_GET['delete_estimate'])) {
    $estimate_id_to_delete = intval($_GET['delete_estimate']);

    // Verify that the estimate belongs to the current user
    $stmt = $conn->prepare("DELETE FROM freight_estimates WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $estimate_id_to_delete, $user_id);

    if ($stmt->execute()) {
        $success_message = "Estimate deleted successfully!";
    } else {
        $error_message = "Error deleting estimate: " . $stmt->error;
    }
    $stmt->close();

    // Refresh the page to update the list
    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit();
}

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form variables
    $origin = trim($_POST['origin']);
    $destination = trim($_POST['destination']);
    $distance = isset($_POST['distance']) ? $_POST['distance'] : 'N/A';
    $project_size = isset($_POST['project_size']) ? trim($_POST['project_size']) : '';
    $estimated_start_date = isset($_POST['estimated_start_date']) ? $_POST['estimated_start_date'] : '';
    $estimated_number_of_trucks = isset($_POST['estimated_number_of_trucks']) ? trim($_POST['estimated_number_of_trucks']) : '';
    $estimated_modules_per_truck = isset($_POST['estimated_modules_per_truck']) ? trim($_POST['estimated_modules_per_truck']) : '';
    $estimate_name = isset($_POST['estimate_name']) ? trim($_POST['estimate_name']) : '';

    // Admin-only fields
    if ($user_role == 'admin') {
        $cost_per_truck = isset($_POST['cost_per_truck']) ? trim($_POST['cost_per_truck']) : '';
        $total_accessorial_cost = isset($_POST['total_accessorial_cost']) ? trim($_POST['total_accessorial_cost']) : '';
    }

    // Validate required input fields
    if (empty($origin) || empty($destination) || empty($estimate_name)) {
        $error_message = "Please fill in all required fields.";
    } else {
        // Collect all estimate data
        $estimate_data = [
            'origin' => $origin,
            'destination' => $destination,
            'distance' => $distance,
            'project_size' => $project_size,
            'estimated_start_date' => $estimated_start_date,
            'estimated_number_of_trucks' => $estimated_number_of_trucks,
            'estimated_modules_per_truck' => $estimated_modules_per_truck,
            // Initialize cost fields (admin will fill these later)
            'cost_per_truck' => null,
            'total_accessorial_cost' => null,
            'total_freight_cost' => null,
            'grand_total' => null
        ];

        // Save estimate to database
        $estimate_data_json = json_encode($estimate_data);

        $stmt = $conn->prepare("INSERT INTO freight_estimates (user_id, name, estimate_data, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $estimate_name, $estimate_data_json);

        if ($stmt->execute()) {
            $success_message = "Your estimate has been saved successfully!";
            // Send email notification to admin

            // Prepare the email content
            $subject = 'New Freight Cost Estimate Request';
            $message = "You have received a new freight cost estimate request:\n\n";
            $message .= "Estimate Name: $estimate_name\n";
            $message .= "Origin: $origin\n";
            $message .= "Destination: $destination\n";
            $message .= "Distance: $distance miles\n";
            $message .= "Project Size: $project_size MW\n";
            $message .= "Estimated Start Date: $estimated_start_date\n";
            $message .= "Estimated Number of Trucks: $estimated_number_of_trucks\n";
            $message .= "Estimated Modules Per Truck: $estimated_modules_per_truck\n";

            // Additional headers
            $headers = "From: noreply@example.com\r\n";
            $headers .= "Reply-To: noreply@example.com\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            // Send the email
            if (mail($to_email, $subject, $message, $headers)) {
                $success_message .= " An email notification has been sent to the administrator.";
            } else {
                $error_message = "There was a problem sending your request. Please try again later.";
            }
        } else {
            $error_message = "Error saving estimate: " . $stmt->error;
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ... existing head content ... -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Freight Cost Estimator</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Basic styling for layout */
        .container {
            display: flex;
            flex-wrap: wrap;
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
        #distance {
            font-weight: bold;
            margin-top: 10px;
        }
        #map {
            width: 100%;
            height: 500px; /* Adjusted height for better visibility */
            background-color: #ccc;
            margin-top: 20px;
        }
        .success-message {
            color: green;
            margin-top: 15px;
        }
        .error-message {
            color: red;
            margin-top: 15px;
        }
        table {
            width: 40%;
            margin-top: 20px;
        }
        .disclaimer {
            margin-top: 20px;
            font-size: 0.9em;
            color: #666;
        }

        #submit-quote-button{
            margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            .left-side, .right-side {
                flex: 1 1 100%;
            }
            table {
            width: 100%;
        }
        }
        .required {
            color: red;
        }
    </style>
    <script>
        (function() {
            var referrer = document.referrer;
            if (!referrer) {
                return; // No referrer, nothing to do
            }

            // Create anchor elements to parse URLs
            var referrerAnchor = document.createElement('a');
            referrerAnchor.href = referrer;

            var currentAnchor = document.createElement('a');
            currentAnchor.href = window.location.href;

            // Compare the protocol, host, and pathname (excluding search and hash)
            var referrerPath = referrerAnchor.protocol + '//' + referrerAnchor.host + referrerAnchor.pathname;
            var currentPath = currentAnchor.protocol + '//' + currentAnchor.host + currentAnchor.pathname;

            if (referrerPath !== currentPath) {
                // Different page, update backButtonURL
                sessionStorage.setItem('backButtonURL', referrer);
            }
        })();
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <a href="#" onclick="goBack()" class="back-icon">
        <!-- SVG for Back Arrow -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>
<h1>Freight Cost Estimator</h1>
<!-- Saved Estimates Section -->
<div id="saved-estimates">
    <button id="saved-estimates-button" class="submit-button">Saved Estimates</button>
    <div id="saved-estimates-list" style="display: none;">
        <?php
        // Fetch user's saved estimates
        $stmt = $conn->prepare("SELECT id, name, created_at FROM freight_estimates WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $saved_estimates = [];
        while ($row = $result->fetch_assoc()) {
            $saved_estimates[] = $row;
        }
        $stmt->close();
        ?>

        <?php if (!empty($saved_estimates)): ?>
            <table>
                <tr>
                    <th>Name</th>
                    <th>Created Date</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($saved_estimates as $estimate): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($estimate['name']); ?></td>
                        <td><?php echo htmlspecialchars($estimate['created_at']); ?></td>
                        <td>
                            <a href="view_freight_estimate?id=<?php echo $estimate['id']; ?>">View</a>
                            |
                            <a href="#" class="delete-estimate" data-id="<?php echo $estimate['id']; ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>You have no saved estimates.</p>
        <?php endif; ?>
    </div>
</div>
<?php
// Display success or error messages
if (isset($success_message)) {
    echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
}
if (isset($error_message)) {
    echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
}
?>
    <?php if ($user_role == 'admin'): ?>
            <!-- Admin-only form -->
            <!-- ... admin content ... -->
        <?php else: ?>
            <!-- Display totals if they have been calculated -->
            <?php if ($total_freight_cost > 0 || $grand_total > 0): ?>
                <h2>Estimated Costs</h2>
                <table>
                    <tr>
                        <th>Total Freight Cost</th>
                        <td><?php echo '$' . number_format($total_freight_cost, 2); ?></td>
                    </tr>
                    <tr>
                        <th>Total Accessorial Cost</th>
                        <td><?php echo '$' . number_format($total_accessorial_cost, 2); ?></td>
                    </tr>
                    <tr>
                        <th>Grand Total</th>
                        <td><?php echo '$' . number_format($grand_total, 2); ?></td>
                    </tr>
                </table>
            <?php else: ?>
                <p>Costs will be provided by Solterra Solutions and can be viewed by clicking the "Saved Estimates" button.</p>
            <?php endif; ?>
        <?php endif; ?>
        <p class="disclaimer">This number is an estimate and is for budgeting purposes only.</p>
    </div>
<div class="container">
    <div class="left-side">
        <p><span class="required">*</span> indicates a required field.</p>
        <form id="freight-form" method="POST" action="">
            <!-- Form Inputs -->
            <label for="estimate_name">Estimate Name:<span class="required">*</span></label>
            <input type="text" id="estimate_name" name="estimate_name" required value="<?php echo htmlspecialchars($estimate_name); ?>">

            <label for="origin">Origin (City or ZIP Code):<span class="required">*</span></label>
            <input type="text" id="origin" name="origin" required value="<?php echo htmlspecialchars($origin); ?>">

            <label for="destination">Destination (City or ZIP Code):<span class="required">*</span></label>
            <input type="text" id="destination" name="destination" required value="<?php echo htmlspecialchars($destination); ?>">

            <label for="project_size">Project Size (in MW):</label>
            <input type="number" id="project_size" name="project_size" step="0.01" value="<?php echo htmlspecialchars($project_size); ?>">

            <label for="estimated_start_date">Estimated Start Date:</label>
            <input type="date" id="estimated_start_date" name="estimated_start_date" value="<?php echo htmlspecialchars($estimated_start_date); ?>">

            <label for="estimated_number_of_trucks">Estimated Number of Trucks:
                <span class="info-tooltip">?
                <span class="tooltip-text">This information can be provided by the manufacturer's logistic information sheet (may go by other names)</span>
            </span>
            </label>
            <input type="number" id="estimated_number_of_trucks" name="estimated_number_of_trucks" value="<?php echo htmlspecialchars($estimated_number_of_trucks); ?>">

            <label for="estimated_modules_per_truck">Estimated Modules Per Truck:
                <span class="info-tooltip">?
                <span class="tooltip-text">This information can be provided by the manufacturer's logistic information sheet (may go by other names)</span>
            </span>
            </label>
            <input type="number" id="estimated_modules_per_truck" name="estimated_modules_per_truck" value="<?php echo htmlspecialchars($estimated_modules_per_truck); ?>">

            <div id="distance"><?php if ($distance !== 'N/A') echo 'Distance: ' . htmlspecialchars($distance) . ' miles'; ?></div>

            <!-- Add distance hidden input -->
            <input type="hidden" id="distanceInput" name="distance" value="<?php echo htmlspecialchars($distance); ?>">

            <button id="submit-quote-button" class="submit-button">Submit</button>
        </form>
    </div>

    <!-- Right Side Content (Admin fields or Estimated Costs) -->
    <div class="right-side">
        <!-- Display the map -->
        <div id="map"></div>
    </div>
</div>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCYF3qz_6niMzpTd0yklUX9YNpk73KviBM&libraries=places"></script>

<script>
    // JavaScript code for map and distance calculation

    let map;
    let directionsService;
    let directionsRenderer;
    let distanceElement = document.getElementById('distance');

    function initMap() {
        directionsService = new google.maps.DirectionsService();
        directionsRenderer = new google.maps.DirectionsRenderer();

        map = new google.maps.Map(document.getElementById('map'), {
            zoom: 5,
            center: { lat: 39.5, lng: -98.35 } // Center of the US
        });

        directionsRenderer.setMap(map);

        initAutocomplete();

        // If form was submitted, calculate the route
        <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($error_message)): ?>
        calculateRoute();
        <?php endif; ?>
    }

    function initAutocomplete() {
        const originInput = document.getElementById('origin');
        const destinationInput = document.getElementById('destination');

        const options = {
            // Restrict predictions to geographical location types
            types: ['geocode'],
            componentRestrictions: { country: 'us' }
        };

        const originAutocomplete = new google.maps.places.Autocomplete(originInput, options);
        const destinationAutocomplete = new google.maps.places.Autocomplete(destinationInput, options);

        originAutocomplete.addListener('place_changed', calculateRoute);
        destinationAutocomplete.addListener('place_changed', calculateRoute);

        // Also handle manual input and changes
        originInput.addEventListener('change', calculateRoute);
        destinationInput.addEventListener('change', calculateRoute);
    }

    function calculateRoute() {
        const origin = document.getElementById('origin').value;
        const destination = document.getElementById('destination').value;

        if (origin && destination) {
            const request = {
                origin: origin,
                destination: destination,
                travelMode: 'DRIVING'
            };

            directionsService.route(request, function (result, status) {
                if (status == 'OK') {
                    directionsRenderer.setDirections(result);

                    // Get the distance in miles
                    const distanceInMeters = result.routes[0].legs[0].distance.value;
                    const distanceInMiles = (distanceInMeters / 1609.34).toFixed(2);

                    // Display the distance
                    distanceElement.textContent = `Distance: ${distanceInMiles} miles`;

                    // Update the distance hidden input
                    document.getElementById('distanceInput').value = distanceInMiles;
                } else {
                    distanceElement.textContent = 'Could not calculate distance.';
                }
            });
        } else {
            // Clear the map and distance if inputs are incomplete
            directionsRenderer.set('directions', null);
            distanceElement.textContent = '';
        }
    }

    // Initialize the map when the window loads
    window.onload = initMap;
</script>
<script>
    // Toggle saved estimates list
    document.getElementById('saved-estimates-button').addEventListener('click', function() {
        var list = document.getElementById('saved-estimates-list');
        if (list.style.display === 'none' || list.style.display === '') {
            list.style.display = 'block';
        } else {
            list.style.display = 'none';
        }
    });

    // Handle deletion
    var deleteButtons = document.querySelectorAll('.delete-estimate');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            var estimateId = this.getAttribute('data-id');
            if (confirm('Are you sure you want to delete this estimate?')) {
                window.location.href = window.location.href.split('?')[0] + '?delete_estimate=' + estimateId;
            }
        });
    });
        // Script for the goBack() function
        function goBack() {
        var backURL = sessionStorage.getItem('backButtonURL');
        if (backURL && backURL !== window.location.href) {
            window.location.href = backURL;
        } else {
            window.history.back();
        }
    }
</script>
</main>
</body>
</html>