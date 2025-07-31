<?php
session_name("logistics_session");
session_start();

// Ensure user has role global_admin or admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

// Get manufacturer ID
if (!isset($_GET['manufacturer_id'])) {
    header("Location: manufacturers.php");
    exit();
}
$manufacturer_id = intval($_GET['manufacturer_id']);

// Unified dashboard link
$dashboard_link = 'dashboard.php';

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";
$manufacturer = null;

// Fetch manufacturer info
try {
    $stmt = $conn->prepare("SELECT name, short_name FROM manufacturers WHERE id = ?");
    $stmt->bind_param("i", $manufacturer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Manufacturer not found.");
    }
    
    $manufacturer = $result->fetch_assoc();
    $stmt->close();
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Gather form fields
        $location_name = trim($_POST['location_name'] ?? '');
        $street_address = trim($_POST['street_address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip_code = trim($_POST['zip_code'] ?? '');
        $country = trim($_POST['country'] ?? 'USA');
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if ($location_name === '') {
            throw new Exception("Location Name is required.");
        }
        if ($street_address === '' && $city === '' && $state === '' && $zip_code === '') {
            throw new Exception("At least one address field is required.");
        }

        // If this is set as primary, we need to handle existing primary location
        if ($is_primary) {
            // Set all other locations for this manufacturer to non-primary
            $update_stmt = $conn->prepare("UPDATE manufacturer_locations SET is_primary = FALSE WHERE manufacturer_id = ?");
            if ($update_stmt) {
                $update_stmt->bind_param("i", $manufacturer_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
        }

        // Insert into manufacturer_locations table
        $stmt = $conn->prepare("
            INSERT INTO manufacturer_locations (
                manufacturer_id,
                location_name, 
                street_address, 
                city, 
                state, 
                zip_code, 
                country, 
                is_primary,
                is_active, 
                notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception("Error preparing location insert: " . $conn->error);
        }

        $stmt->bind_param(
            "issssssdds",
            $manufacturer_id,
            $location_name,
            $street_address,
            $city,
            $state,
            $zip_code,
            $country,
            $is_primary,
            $is_active,
            $notes
        );

        if (!$stmt->execute()) {
            throw new Exception("Error inserting location: " . $stmt->error);
        }
        $stmt->close();

        $successMessage = "Location added successfully!";

    } catch (Exception $ex) {
        $errorMessage = $ex->getMessage();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Location - <?php echo htmlspecialchars($manufacturer['name'] ?? 'Manufacturer'); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        main {
            max-width: 800px;
        }
        form label {
            display: block;
            margin-top: 15px;
            font-weight: 600;
            color: #333;
        }
        form input[type="text"],
        form input[type="email"],
        form input[type="tel"],
        form input[type="url"],
        form input[type="file"],
        form select,
        form textarea {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
            box-sizing: border-box;
        }
        form textarea {
            height: 80px;
            resize: vertical;
        }
        /* Address grid layout */
        .address-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
            margin-top: 5px;
        }
        .address-grid input {
            margin-top: 0;
        }
        /* Checkbox styling */
        .checkbox-container {
            display: flex;
            align-items: center;
            margin-top: 10px;
        }
        .checkbox-container input[type="checkbox"] {
            width: auto;
            margin-right: 8px;
        }
        .btn-submit {
            background: #293E4C;
            color: #fff;
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            margin-top: 20px;
            display: inline-block;
            transition: background-color 0.3s ease;
        }
        .btn-submit:hover {
            background: #488C9A;
        }
        .section-title {
            margin-top: 30px;
            margin-bottom: 15px;
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        .success-message {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            border-radius: 4px;
        }
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            border-radius: 4px;
        }
        .form-note {
            font-size: 0.9em;
            color: #666;
            font-style: italic;
            margin-top: 3px;
        }
        .manufacturer-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        form br {
            display: none;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Breadcrumb Navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="<?php echo htmlspecialchars($dashboard_link); ?>" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manufacturers.php" style="color: #488C9A; text-decoration: none;">Manage Manufacturers</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manufacturer_locations.php?manufacturer_id=<?php echo $manufacturer_id; ?>" style="color: #488C9A; text-decoration: none;">Manage Locations</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Add Location</span>
    </div>

    <!-- Manufacturer Info -->
    <?php if ($manufacturer): ?>
    <div class="manufacturer-info">
        <h2 style="margin: 0 0 10px 0;">
            <?php echo htmlspecialchars($manufacturer['name']); ?>
            <?php if (!empty($manufacturer['short_name'])): ?>
                <small style="color: #666;">(<?php echo htmlspecialchars($manufacturer['short_name']); ?>)</small>
            <?php endif; ?>
        </h2>
        <p style="margin: 0; color: #666;">Adding a new location for this manufacturer</p>
    </div>
    <?php endif; ?>

    <h1>Add New Location</h1>

    <!-- Display success or error messages if any -->
    <?php if (!empty($successMessage)): ?>
        <div class="success-message">
            <strong><?php echo htmlspecialchars($successMessage); ?></strong>
            <br><a href="manufacturer_locations.php?manufacturer_id=<?php echo $manufacturer_id; ?>">Back to Locations</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- The location form -->
    <form action="" method="POST">
        <div class="section-title">Location Information</div>
        
        <label for="location_name">Location Name: <span style="color: red;">*</span></label>
        <input type="text" id="location_name" name="location_name" required placeholder="e.g., Phoenix Warehouse, Main Office, Distribution Center">
        <div class="form-note">Descriptive name to identify this location</div>

        <div class="section-title">Address <span style="color: red;">*</span></div>
        <div class="form-note">At least one address field is required</div>
        
        <div class="address-grid">
            <div>
                <label for="street_address" style="margin-top: 0; font-size: 0.9em; color: #666;">Street Address:</label>
                <input type="text" id="street_address" name="street_address" placeholder="123 Industrial Blvd">
            </div>
            <div>
                <label for="city" style="margin-top: 0; font-size: 0.9em; color: #666;">City:</label>
                <input type="text" id="city" name="city" placeholder="Phoenix">
            </div>
            <div>
                <label for="state" style="margin-top: 0; font-size: 0.9em; color: #666;">State/Province:</label>
                <input type="text" id="state" name="state" placeholder="AZ">
            </div>
            <div>
                <label for="zip_code" style="margin-top: 0; font-size: 0.9em; color: #666;">Zip/Postal Code:</label>
                <input type="text" id="zip_code" name="zip_code" placeholder="85281">
            </div>
        </div>

        <label for="country">Country:</label>
        <input type="text" id="country" name="country" value="USA" placeholder="USA">

        <div class="section-title">Location Settings</div>
        
        <div class="checkbox-container">
            <input type="checkbox" id="is_primary" name="is_primary">
            <label for="is_primary" style="margin-top: 0;">Set as Primary Location</label>
        </div>
        <div class="form-note">Primary location will be used as the default for this manufacturer</div>

        <div class="checkbox-container">
            <input type="checkbox" id="is_active" name="is_active" checked>
            <label for="is_active" style="margin-top: 0;">Active Location</label>
        </div>
        <div class="form-note">Uncheck to mark as inactive</div>

        <label for="notes">Notes:</label>
        <textarea id="notes" name="notes" placeholder="Additional notes about this location (warehouse type, operating hours, special instructions, etc.)"></textarea>

        <div class="btn-submit-container">
            <input type="submit" value="Add Location" class="btn-submit">
        </div>
    </form>
</main>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places"></script>

<script>
function initializeAddressAutocomplete() {
    // Get the street address input element
    const streetAddressInput = document.getElementById('street_address');
    const cityInput = document.getElementById('city');
    const stateInput = document.getElementById('state');
    const zipInput = document.getElementById('zip_code');
    
    // Create the autocomplete object for international addresses
    const autocomplete = new google.maps.places.Autocomplete(streetAddressInput, {
        types: ['address']
        // No country restrictions - allow international addresses
    });
    
    // When the user selects an address from the dropdown, populate the address fields
    autocomplete.addListener('place_changed', function() {
        const place = autocomplete.getPlace();
        
        // Clear all fields first
        streetAddressInput.value = '';
        cityInput.value = '';
        stateInput.value = '';
        zipInput.value = '';
        
        if (!place.geometry) {
            // User entered the name of a Place that was not suggested and pressed Enter
            console.log("No details available for input: '" + place.name + "'");
            return;
        }
        
        // Get the address components and populate the form fields
        let streetNumber = '';
        let route = '';
        const countryInput = document.getElementById('country');
        
        for (let i = 0; i < place.address_components.length; i++) {
            const addressType = place.address_components[i].types[0];
            const val = place.address_components[i].long_name;
            
            switch (addressType) {
                case 'street_number':
                    streetNumber = val;
                    break;
                case 'route':
                    route = val;
                    break;
                case 'locality':
                case 'administrative_area_level_3':
                case 'sublocality_level_1': // For international addresses
                    if (!cityInput.value) cityInput.value = val;
                    break;
                case 'administrative_area_level_1':
                    // For US: use short name (CA), for international: use long name (Gujarat)
                    const isUS = place.address_components.some(comp => 
                        comp.types.includes('country') && comp.short_name === 'US'
                    );
                    stateInput.value = isUS ? place.address_components[i].short_name : val;
                    break;
                case 'postal_code':
                    zipInput.value = val;
                    break;
                case 'country':
                    if (countryInput) {
                        countryInput.value = val; // Full country name (e.g., "India", "United States")
                    }
                    break;
            }
        }
        
        // Combine street number and route for full street address
        if (streetNumber && route) {
            streetAddressInput.value = (streetNumber + ' ' + route).trim();
        } else if (route) {
            streetAddressInput.value = route; // For addresses where only route is available
        }
        // If autocomplete doesn't provide street info, keep what user typed
    });
}

// Initialize the autocomplete when the page loads
google.maps.event.addDomListener(window, 'load', initializeAddressAutocomplete);
</script>

</body>
</html> 