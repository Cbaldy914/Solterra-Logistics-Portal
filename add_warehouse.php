<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
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
        $country = trim($_POST['country'] ?? 'USA');
        $is_port = isset($_POST['is_port']) ? 1 : 0;
        
        // Cost structure - parse JSON from dynamic fee manager
        $warehouse_fees_json = $_POST['warehouse_fees'] ?? '[]';
        $warehouse_fees = json_decode($warehouse_fees_json, true);
        if (!is_array($warehouse_fees)) {
            $warehouse_fees = [];
        }

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
        $stmt = $conn->prepare("INSERT INTO warehouses (name, street_address, city, state, zip_code, country, image_url, is_port) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
         if (!$stmt) {
            throw new Exception("Error preparing warehouse insert: " . $conn->error);
        }
        $stmt->bind_param("sssssssi", $name, $street_address, $city, $state, $zip_code, $country, $image_url, $is_port);
        if ($stmt->execute()) {
            $warehouse_id = $conn->insert_id;
            
            // Insert cost items from dynamic fee manager
            if (!empty($warehouse_fees)) {
                $stmt_cost = $conn->prepare("INSERT INTO warehouse_cost_items
                    (warehouse_id, label, trigger_event, amount, unit_type, pallets_per_truck, sqft_per_pallet, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt_cost) {
                    throw new Exception("Error preparing cost items insert: " . $conn->error);
                }

                $display_order = 0;
                foreach ($warehouse_fees as $fee) {
                    $label = trim($fee['name'] ?? 'Unnamed Fee');
                    $trigger = $fee['trigger'] ?? 'other';
                    $amount = floatval($fee['amount'] ?? 0);
                    $unit_type = $fee['unit'] ?? 'per_pallet';
                    $pallets_per_truck = intval($fee['palletsPerTruck'] ?? 26);
                    $sqft_per_pallet = floatval($fee['sqftPerPallet'] ?? 13.33);

                    // Skip customs clearance fee if not a port
                    if ($trigger === 'customs_clearance' && !$is_port) {
                        continue;
                    }

                    $stmt_cost->bind_param("issdsidi",
                        $warehouse_id,
                        $label,
                        $trigger,
                        $amount,
                        $unit_type,
                        $pallets_per_truck,
                        $sqft_per_pallet,
                        $display_order
                    );
                    if (!$stmt_cost->execute()) {
                        throw new Exception("Error adding cost item '{$label}': " . $stmt_cost->error);
                    }
                    $display_order++;
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
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
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

        /* Fee Table Styles */
        .fee-table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            margin-bottom: 12px;
        }
        .fee-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
        }
        .fee-table th {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .fee-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .fee-table tr:last-child td {
            border-bottom: none;
        }
        .fee-table input[type="text"],
        .fee-table input[type="number"],
        .fee-table select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 0.9rem;
            box-sizing: border-box;
        }
        .fee-table .amount-input {
            max-width: 100px;
        }
        .fee-table select {
            max-width: 140px;
        }
        .unit-param {
            margin-top: 6px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .unit-param label {
            color: #6c757d;
            margin: 0;
            font-size: 0.75rem;
            white-space: nowrap;
        }
        .unit-param input {
            width: 70px !important;
            padding: 4px 6px !important;
            font-size: 0.85rem !important;
        }
        .fee-row-remove {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 1.3rem;
            padding: 4px 8px;
            opacity: 0.6;
            transition: opacity 0.2s;
        }
        .fee-row-remove:hover {
            opacity: 1;
        }
        .btn-add-fee {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            background: #f8f9fa;
            color: #488C9A;
            border: 1px dashed #488C9A;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-add-fee:hover {
            background: #488C9A;
            color: #fff;
            border-style: solid;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Breadcrumb Navigation -->
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Add Warehouse',
            'extra' => [ ['label' => 'Manage Warehouses', 'url' => 'manage_warehouses.php'] ]
        ]);
    ?>
    
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
                <label for="state" style="margin-top: 0; font-size: 0.9em; color: #666;">State/Province:</label>
                <input type="text" id="state" name="state" placeholder="NC">
            </div>
            <div>
                <label for="zip_code" style="margin-top: 0; font-size: 0.9em; color: #666;">Zip/Postal Code:</label>
                <input type="text" id="zip_code" name="zip_code" placeholder="28217">
            </div>
            <div>
                <label for="country" style="margin-top: 0; font-size: 0.9em; color: #666;">Country:</label>
                <input type="text" id="country" name="country" placeholder="USA" value="USA">
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
            <p style="color: #0c5460; font-size: 0.9em; margin-bottom: 15px;">
                Configure fees for this warehouse. You can add multiple fee types with different billing units.
            </p>

            <!-- Dynamic Fee Manager Container -->
            <div id="feeManagerContainer"></div>

            <!-- Hidden input for form submission -->
            <input type="hidden" name="warehouse_fees" id="warehouseFeesInput" value="[]">

            <small style="color: #0c5460; font-size: 0.85em; display: block; margin-top: 10px;">
                💡 You can modify these costs anytime after creation. Leave amounts at $0.00 if not applicable.
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
<!-- Load the Warehouse Fee Manager component -->
<script src="components/warehouse-fee-manager.js"></script>

<script>
// Initialize the fee manager when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize fee manager with default fees
    feeManager = new WarehouseFeeManager({
        containerId: 'feeManagerContainer',
        inputId: 'warehouseFeesInput',
        initialFees: [],
        isPort: document.getElementById('is_port').checked
    });

    // Listen for port checkbox changes
    document.getElementById('is_port').addEventListener('change', function() {
        feeManager.setIsPort(this.checked);
    });
});

function initializeAddressAutocomplete() {
    // Get the street address input element
    const streetAddressInput = document.getElementById('street_address');
    const cityInput = document.getElementById('city');
    const stateInput = document.getElementById('state');
    const zipInput = document.getElementById('zip_code');
    const countryInput = document.getElementById('country');
    
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
        countryInput.value = '';
        
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
                    // For international addresses, use long name; for US use short name
                    const shortName = place.address_components[i].short_name;
                    stateInput.value = shortName && shortName.length <= 3 ? shortName : val;
                    break;
                case 'postal_code':
                    zipInput.value = val;
                    break;
                case 'country':
                    countryInput.value = val;
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
