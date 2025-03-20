<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: login");
    exit();
}

if (!isset($_GET['id'])) {
    die("Estimate ID not specified.");
}

$estimate_id = intval($_GET['id']);

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
$stmt = $conn->prepare("SELECT estimate_data, name, user_id FROM warehouse_quotes WHERE id = ?");
$stmt->bind_param("i", $estimate_id);
$stmt->execute();
$stmt->bind_result($estimate_data_json, $name, $user_id);
if ($stmt->fetch()) {
    $estimate_data = json_decode($estimate_data_json, true);
} else {
    die("Estimate not found.");
}
$stmt->close();

// Handle form submission for adding quotes
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_quote'])) {
        $warehouse_location = trim($_POST['warehouse_location']);
        $in_fee = floatval($_POST['in_fee']);
        $out_fee = floatval($_POST['out_fee']);
        $monthly_storage_fee = floatval($_POST['monthly_storage_fee']);

        if (empty($warehouse_location) || $in_fee < 0 || $out_fee < 0 || $monthly_storage_fee < 0) {
            $error_message = "Please fill in all required fields with valid values.";
        } else {
            // Add the new quote to the estimate_data
            $new_quote = [
                'warehouse_location' => $warehouse_location,
                'in_fee_per_pallet' => $in_fee,
                'out_fee_per_pallet' => $out_fee,
                'monthly_storage_cost_per_pallet' => $monthly_storage_fee
            ];

            // Initialize quotes array if not set
            if (!isset($estimate_data['quotes'])) {
                $estimate_data['quotes'] = [];
            }

            $estimate_data['quotes'][] = $new_quote;

            // Save updated estimate_data back to the database
            $updated_estimate_data_json = json_encode($estimate_data);

            $stmt = $conn->prepare("UPDATE warehouse_quotes SET estimate_data = ? WHERE id = ?");
            $stmt->bind_param("si", $updated_estimate_data_json, $estimate_id);

            if ($stmt->execute()) {
                $success_message = "Quote added successfully!";
            } else {
                $error_message = "Error updating estimate: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif (isset($_POST['delete_quote'])) {
        $quote_index = intval($_POST['quote_index']);

        if (isset($estimate_data['quotes'][$quote_index])) {
            // Remove the quote from the array
            array_splice($estimate_data['quotes'], $quote_index, 1);

            // Save updated estimate_data back to the database
            $updated_estimate_data_json = json_encode($estimate_data);

            $stmt = $conn->prepare("UPDATE warehouse_quotes SET estimate_data = ? WHERE id = ?");
            $stmt->bind_param("si", $updated_estimate_data_json, $estimate_id);

            if ($stmt->execute()) {
                $success_message = "Quote deleted successfully!";
            } else {
                $error_message = "Error updating estimate: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Quote not found.";
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ... existing head content ... -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Warehouse Estimate View</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">

    <!-- Include Google Maps JavaScript API with Places Library -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCYF3qz_6niMzpTd0yklUX9YNpk73KviBM&libraries=places"></script>

    <style>
        /* Add any custom styles here */
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
        button {
            background-color: #488C9A; /* Secondary blue color */
            color: white;
            padding: 10px 20px;
            margin: 10px 0;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-weight: bold;
        }
        button:hover {
            background-color: #293E4C; /* Darker shade on hover */
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
            width: 100%;
            margin-top: 20px;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        form.delete-form {
            display: inline;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Warehouse Estimate: <?php echo htmlspecialchars($name); ?></h1>

    <?php
    // Display success or error messages
    if (!empty($success_message)) {
        echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
    }
    if (!empty($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    ?>

    <!-- Estimate Details -->
    <h2>Estimate Details</h2>
    <ul>
        <li><strong>Project Location:</strong> <?php echo htmlspecialchars($estimate_data['project_location']); ?></li>
        <li><strong>Estimated Storage Start:</strong> <?php echo htmlspecialchars($estimate_data['estimated_storage_start']); ?></li>
        <li><strong>Estimated Number of Pallets:</strong> <?php echo htmlspecialchars($estimate_data['estimated_number_of_pallets']); ?></li>
        <li><strong>Estimated Pallet Dimensions (L x W x H in inches):</strong> <?php echo htmlspecialchars($estimate_data['pallet_length']) . ' x ' . htmlspecialchars($estimate_data['pallet_width']) . ' x ' . htmlspecialchars($estimate_data['pallet_height']); ?></li>
        <li><strong>Stackable:</strong> <?php echo $estimate_data['stackable'] ? 'Yes' : 'No'; ?></li>
        <li><strong>Calculated Square Feet:</strong> <?php echo number_format($estimate_data['square_feet'], 2); ?> sq ft</li>
    </ul>

    <!-- Existing Quotes -->
    <h2>Existing Quotes</h2>
    <?php if (!empty($estimate_data['quotes'])): ?>
        <table>
            <tr>
                <th>Warehouse Location</th>
                <th>In Fee (per pallet)</th>
                <th>Out Fee (per pallet)</th>
                <th>Monthly Storage Fee (per pallet)</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($estimate_data['quotes'] as $index => $quote): ?>
                <tr>
                    <td><?php echo htmlspecialchars($quote['warehouse_location']); ?></td>
                    <td>$<?php echo number_format($quote['in_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['out_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['monthly_storage_cost_per_pallet'], 2); ?></td>
                    <td>
                        <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this quote?');">
                            <input type="hidden" name="quote_index" value="<?php echo $index; ?>">
                            <input type="hidden" name="delete_quote" value="1">
                            <button type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No quotes have been added yet.</p>
    <?php endif; ?>

    <!-- Form to Add a New Quote -->
    <h2>Add a New Quote</h2>
    <form method="POST" action="">
        <label for="warehouse_location">Warehouse Location:</label>
        <!-- Updated Input Field for Autocomplete -->
        <input type="text" id="autocomplete" name="warehouse_location" required onFocus="geolocate()">

        <label for="in_fee">In Fee (per pallet):</label>
        <input type="number" step="0.01" id="in_fee" name="in_fee" required>

        <label for="out_fee">Out Fee (per pallet):</label>
        <input type="number" step="0.01" id="out_fee" name="out_fee" required>

        <label for="monthly_storage_fee">Monthly Storage Fee (per pallet):</label>
        <input type="number" step="0.01" id="monthly_storage_fee" name="monthly_storage_fee" required>

        <input type="hidden" name="add_quote" value="1">
        <button type="submit">Add Quote</button>
    </form>
</main>

<!-- Google Maps Autocomplete Script -->
<script>
    // Google Maps Autocomplete
    let autocomplete;

    function initAutocomplete() {
        autocomplete = new google.maps.places.Autocomplete(
            document.getElementById('autocomplete'),
            { types: ['(cities)'] } // You can change this to ['geocode'] for full addresses or ['(regions)'] for postal codes
        );

        // Set fields to return
        autocomplete.setFields(['address_components', 'geometry']);

        // Add listener for place changed
        autocomplete.addListener('place_changed', fillInAddress);
    }

    function fillInAddress() {
        // Get the place details from the autocomplete object
        const place = autocomplete.getPlace();

        // You can extract components if needed
        // For example, to get the city and postal code:
        // let addressComponents = place.address_components;
        // Extract the necessary components here
    }

    function geolocate() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                const geolocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                const circle = new google.maps.Circle(
                    { center: geolocation, radius: position.coords.accuracy }
                );
                autocomplete.setBounds(circle.getBounds());
            });
        }
    }

    // Initialize the autocomplete when the window loads
    google.maps.event.addDomListener(window, 'load', initAutocomplete);
</script>
</body>
</html>
