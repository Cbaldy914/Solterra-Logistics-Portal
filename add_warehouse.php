<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header("Location: unauthorized");
    exit();
}
// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit'])) {
    try {
        // Retrieve form data and sanitize
        $name = trim($_POST['name']);
        $street_address = trim($_POST['street_address']);
        $city = trim($_POST['city']);
        $state = trim($_POST['state']);
        $zip_code = trim($_POST['zip_code']);
        $is_port = isset($_POST['is_port']) ? 1 : 0;
        
        // Cost structure fields
        $entry_fee = floatval($_POST['entry_fee'] ?? 0);
        $exit_fee = floatval($_POST['exit_fee'] ?? 0);
        $monthly_storage_fee = floatval($_POST['monthly_storage_fee'] ?? 0);
        $customs_clearance_fee = floatval($_POST['customs_clearance_fee'] ?? 0);

        if (empty($name)) {
            throw new Exception("Warehouse Name is required.");
        }
        if ($street_address === '' && $city === '' && $state === '' && $zip_code === '') {
            throw new Exception("At least one address field is required.");
        }

        // Handle image upload if provided
        $image_url = "pictures/test.png"; // Default image or handle no upload case appropriately
        if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
            $allowed_ext = ['jpg','jpeg','png','gif'];
            $file_name   = $_FILES['image']['name'];
            $file_tmp    = $_FILES['image']['tmp_name'];
            $file_size   = $_FILES['image']['size'];
            $file_ext    = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (!in_array($file_ext, $allowed_ext)) {
                throw new Exception("Invalid file type. Only JPG, JPEG, PNG, GIF allowed.");
            }
            if ($file_size > 5*1024*1024) { // Example: 5MB limit
                throw new Exception("File exceeds 5MB limit.");
            }

            // Ensure the uploads directory exists
            $target_dir = "uploads/warehouse_images/";
            if (!is_dir($target_dir)) {
                if (!mkdir($target_dir, 0755, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }

            // Sanitize the file name and create unique name
            $safe_base_name = preg_replace("/[^a-zA-Z0-9\.\-_]/", "", basename($file_name));
            $unique_name = uniqid('wh_', true).'.'.$file_ext;
            $target_file = $target_dir . $unique_name;

            if (!move_uploaded_file($file_tmp, $target_file)) {
                 throw new Exception("Error uploading image file.");
            }
            $image_url = $target_file; // Adjust based on your URL structure if needed
        } elseif (isset($_FILES['image']) && $_FILES['image']['error'] != UPLOAD_ERR_NO_FILE) {
            // Handle other upload errors
             throw new Exception("File upload error code: " . $_FILES['image']['error']);
        }

        // Insert into the database with separate address fields (trigger will populate address field)
        $stmt = $conn->prepare("INSERT INTO warehouses (name, street_address, city, state, zip_code, image_url, is_port) VALUES (?, ?, ?, ?, ?, ?, ?)");
         if (!$stmt) {
            throw new Exception("Error preparing warehouse insert: " . $conn->error);
        }
        $stmt->bind_param("ssssssi", $name, $street_address, $city, $state, $zip_code, $image_url, $is_port);
        if ($stmt->execute()) {
            $warehouse_id = $conn->insert_id;
            
            // Add cost structure items for any non-zero fees
            $cost_items = [];
            if ($entry_fee > 0) {
                $cost_items[] = ['Entry Fee', 'entry', $entry_fee];
            }
            if ($exit_fee > 0) {
                $cost_items[] = ['Exit Fee', 'exit', $exit_fee];
            }
            if ($monthly_storage_fee > 0) {
                $cost_items[] = ['Monthly Storage', 'monthly', $monthly_storage_fee];
            }
            if ($customs_clearance_fee > 0 && $is_port) {
                $cost_items[] = ['Customs Clearance Fee', 'customs_clearance', $customs_clearance_fee];
            }
            
            // Insert cost items
            if (!empty($cost_items)) {
                $stmt_cost = $conn->prepare("INSERT INTO warehouse_cost_items (warehouse_id, label, trigger_event, amount) VALUES (?, ?, ?, ?)");
                if (!$stmt_cost) {
                    throw new Exception("Error preparing cost items insert: " . $conn->error);
                }
                
                foreach ($cost_items as $cost_item) {
                    $stmt_cost->bind_param("issd", $warehouse_id, $cost_item[0], $cost_item[1], $cost_item[2]);
                    if (!$stmt_cost->execute()) {
                        throw new Exception("Error adding cost item '{$cost_item[0]}': " . $stmt_cost->error);
                    }
                }
                $stmt_cost->close();
            }
            
            $successMessage = "Warehouse and cost structure added successfully.";
        } else {
            throw new Exception("Error adding warehouse: " . htmlspecialchars($stmt->error));
        }
        $stmt->close();

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
    <title>Add Warehouse</title>
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
            color: #333; /* Darker label text */
        }
        form input[type="text"],
        form input[type="number"],
        form input[type="file"] {
            width: 100%;
            padding: 10px; /* Slightly larger padding */
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
            box-sizing: border-box; /* Include padding and border in the element's total width and height */
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
        .btn-submit { /* Class for the submit button */
            background: #293E4C; /* Dark blue */
            color: #fff;
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600; /* Make button text bolder */
            margin-top: 20px;
            display: inline-block; /* Or block if you want it full width */
            transition: background-color 0.3s ease; /* Smooth transition on hover */
        }
        .btn-submit:hover {
            background: #488C9A; /* Lighter blue on hover */
        }
        /* Message styling */
        .success-message {
            color: #155724; /* Dark green */
            background-color: #d4edda; /* Light green background */
            border: 1px solid #c3e6cb; /* Green border */
            padding: 15px;
            margin: 20px 0; /* Add margin */
            text-align: center;
            border-radius: 4px;
        }
        .error-message {
            color: #721c24; /* Dark red */
            background-color: #f8d7da; /* Light red background */
            border: 1px solid #f5c6cb; /* Red border */
            padding: 15px;
            margin: 20px 0; /* Add margin */
            text-align: center;
            border-radius: 4px;
        }
        /* Remove default br spacing if labels are block */
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
        <a href="dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manage_warehouses.php" style="color: #488C9A; text-decoration: none;">Manage Warehouses</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Add Warehouse</span>
    </div>
    
    <h1>Add Warehouse</h1>

    <!-- Display success or error messages -->
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><strong><?php echo htmlspecialchars($successMessage); ?></strong></div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <form action="add_warehouse" method="post" enctype="multipart/form-data">
        <label for="name">Warehouse Name:</label>
        <input type="text" id="name" name="name" required>

        <label>Address:</label>
        <div class="address-grid">
            <div>
                <label for="street_address" style="margin-top: 0; font-size: 0.9em; color: #666;">Street Address:</label>
                <input type="text" id="street_address" name="street_address" placeholder="5430 Franklin Springs Circle">
            </div>
            <div>
                <label for="city" style="margin-top: 0; font-size: 0.9em; color: #666;">City:</label>
                <input type="text" id="city" name="city" placeholder="Charlotte">
            </div>
            <div>
                <label for="state" style="margin-top: 0; font-size: 0.9em; color: #666;">State:</label>
                <input type="text" id="state" name="state" placeholder="NC">
            </div>
            <div>
                <label for="zip_code" style="margin-top: 0; font-size: 0.9em; color: #666;">Zip Code:</label>
                <input type="text" id="zip_code" name="zip_code" placeholder="28217">
            </div>
        </div>

        <label for="image">Warehouse Image:</label>
        <input type="file" id="image" name="image" accept="image/*"> <!-- Accept only image files -->

        <div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6;">
            <label style="display: flex; align-items: center; margin: 0; font-weight: 600; cursor: pointer;">
                <input type="checkbox" id="is_port" name="is_port" style="margin-right: 8px; transform: scale(1.2);">
                <span>🚢 This warehouse functions as a port of entry for overseas shipments</span>
            </label>
            <small style="color: #6c757d; margin-left: 24px; display: block; margin-top: 5px;">
                Check this if this warehouse will receive overseas shipments and handle customs clearance
            </small>
        </div>

        <div style="margin-top: 20px; padding: 15px; background-color: #e8f4f8; border-radius: 4px; border: 1px solid #bee5eb;">
            <h4 style="margin-top: 0; color: #0c5460;">💰 Cost Structure</h4>
            
            <div class="form-row">
                <div>
                    <label for="entry_fee" style="margin-top: 0; font-size: 0.9em; color: #0c5460;">Entry Fee (per pallet):</label>
                    <input type="number" id="entry_fee" name="entry_fee" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
                <div>
                    <label for="exit_fee" style="margin-top: 0; font-size: 0.9em; color: #0c5460;">Exit Fee (per pallet):</label>
                    <input type="number" id="exit_fee" name="exit_fee" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
            </div>
            
            <div class="form-row">
                <div>
                    <label for="monthly_storage_fee" style="margin-top: 0; font-size: 0.9em; color: #0c5460;">Monthly Storage (per pallet):</label>
                    <input type="number" id="monthly_storage_fee" name="monthly_storage_fee" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
                <div>
                    <label for="customs_clearance_fee" style="margin-top: 0; font-size: 0.9em; color: #0c5460;">Customs Clearance Fee (if port):</label>
                    <input type="number" id="customs_clearance_fee" name="customs_clearance_fee" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
            </div>
            
            <small style="color: #0c5460; font-size: 0.85em;">
                💡 You can modify these costs anytime after creation. Leave at $0.00 if not applicable.
            </small>
        </div>

        <input type="submit" name="submit" value="Add Warehouse" class="btn-submit"> <!-- Added class -->
    </form>

    <?php
    // Removed the PHP block that was previously here, as processing is now done at the top
    ?>
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
    
    // Create the autocomplete object, restricting the search to addresses
    const autocomplete = new google.maps.places.Autocomplete(streetAddressInput, {
        types: ['address'],
        componentRestrictions: { country: 'US' } // Restrict to US addresses
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
                    cityInput.value = val;
                    break;
                case 'administrative_area_level_1':
                    stateInput.value = place.address_components[i].short_name; // Use short name for state (e.g., "CA" instead of "California")
                    break;
                case 'postal_code':
                    zipInput.value = val;
                    break;
            }
        }
        
        // Combine street number and route for full street address
        streetAddressInput.value = (streetNumber + ' ' + route).trim();
    });
}

// Initialize the autocomplete when the page loads
google.maps.event.addDomListener(window, 'load', initializeAddressAutocomplete);
</script>

</body>
</html>
