<?php
/***********************
 * Combined add_project.php
 * (No user_id insertion)
 ***********************/

session_name("logistics_session");
session_start();


// 2) Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized");
     exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// We'll find the admin's single account or list of accounts for a dropdown
$account_id_for_admin = null;
$accounts             = [];

// If global_admin, load all accounts for a dropdown
if ($role === 'global_admin') {
    $sqlAll = "SELECT id, name FROM customer_accounts ORDER BY name ASC";
    $resAll = $conn->query($sqlAll);
    if ($resAll && $resAll->num_rows > 0) {
        while ($row = $resAll->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
} else {
    // If admin, fetch exactly one account_id from bridging table
    $sqlOne = "
        SELECT account_id
        FROM customer_account_users
        WHERE user_id = ?
          AND role = 'admin'
        LIMIT 1
    ";
    $stmtOne = $conn->prepare($sqlOne);
    if (!$stmtOne) {
        die("Error preparing account lookup: " . $conn->error);
    }
    $stmtOne->bind_param("i", $user_id);
    $stmtOne->execute();
    $stmtOne->bind_result($acctID);
    if ($stmtOne->fetch()) {
        $account_id_for_admin = $acctID;
    }
    $stmtOne->close();

    if (!$account_id_for_admin) {
        die("No valid account found for this admin user.");
    }
}

// Fetch manufacturers for dropdown (for initial module batch)
$manufacturers = [];
$sqlManufacturers = "SELECT id, name, short_name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC";
$resManufacturers = $conn->query($sqlManufacturers);
if ($resManufacturers && $resManufacturers->num_rows > 0) {
    while ($row = $resManufacturers->fetch_assoc()) {
        $manufacturers[] = $row;
    }
}

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";

// If POST, handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Determine correct account_id
        if ($role === 'global_admin') {
            $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
            if ($account_id <= 0) {
                throw new Exception("Please select a valid Account.");
            }
        } else {
            // Admin => single known account
            $account_id = $account_id_for_admin;
        }

        // Gather fields
        $project_name              = trim($_POST['project_name'] ?? '');
        $street_address            = trim($_POST['street_address'] ?? '');
        $city                      = trim($_POST['city'] ?? '');
        $state                     = trim($_POST['state'] ?? '');
        $zip_code                  = trim($_POST['zip_code'] ?? '');
        $estimated_completion_date = trim($_POST['estimated_completion_date'] ?? '');
        $solterra_fee              = isset($_POST['solterra_fee']) ? floatval($_POST['solterra_fee']) : 0.0000;

        if ($project_name === '') {
            throw new Exception("Project Name is required.");
        }
        if ($street_address === '' && $city === '' && $state === '' && $zip_code === '') {
            throw new Exception("At least one address field is required.");
        }

        // Optional image (default to pictures/test.png if none uploaded)
        $image_url = "pictures/test.png"; // default
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $allowed_ext = ['jpg','jpeg','png','gif'];
                $file_name   = $_FILES['image_file']['name'];
                $file_tmp    = $_FILES['image_file']['tmp_name'];
                $file_size   = $_FILES['image_file']['size'];
                $file_ext    = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if (!in_array($file_ext, $allowed_ext)) {
                    throw new Exception("Invalid file type. Only JPG, JPEG, PNG, GIF allowed.");
                }
                if ($file_size > 5*1024*1024) {
                    throw new Exception("File exceeds 5MB limit.");
                }
                $unique_name = uniqid('project_', true).'.'.$file_ext;
                $upload_dir  = 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                if (!move_uploaded_file($file_tmp, $upload_dir.$unique_name)) {
                    throw new Exception("Error uploading the image file.");
                }
                $image_url = $upload_dir.$unique_name;
            } else {
                throw new Exception("File upload error code: " . $_FILES['image_file']['error']);
            }
        }

        // Insert into projects with separate address fields (trigger will populate project_address)
        $stmt = $conn->prepare("
            INSERT INTO projects (
                account_id,
                project_name,
                street_address,
                city,
                state,
                zip_code,
                estimated_completion_date,
                image_url,
                solterra_fee
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception("Error preparing project insert: " . $conn->error);
        }
        $stmt->bind_param(
            "isssssssd",
            $account_id,
            $project_name,
            $street_address,
            $city,
            $state,
            $zip_code,
            $estimated_completion_date,
            $image_url,
            $solterra_fee
        );
        if (!$stmt->execute()) {
            throw new Exception("Error inserting project: " . $stmt->error);
        }
        $project_id = $stmt->insert_id;
        $stmt->close();

        // If wattage and quantities are provided, create a module batch for them
        if (isset($_POST['wattages'], $_POST['quantities'])) {
            $wattages   = $_POST['wattages'];
            $quantities = $_POST['quantities'];
            $manufacturer_id_for_batch = isset($_POST['manufacturer_id']) ? intval($_POST['manufacturer_id']) : null;

            if (count($wattages) !== count($quantities)) {
                throw new Exception("Mismatch between wattage[] and quantities[] arrays.");
            }

            // First, create entries in project_wattage_orders table for project size calculation
            $stmt_wattage = $conn->prepare("
                INSERT INTO project_wattage_orders (project_id, wattage, total_order) 
                VALUES (?, ?, ?)
            ");
            if (!$stmt_wattage) {
                throw new Exception("Error preparing wattage orders insert: " . $conn->error);
            }

            for ($i = 0; $i < count($wattages); $i++) {
                $w_val = trim($wattages[$i]);
                $q_val = trim($quantities[$i]);

                // Validate and convert to integers
                if ($w_val === '' || $q_val === '') {
                    throw new Exception("Wattage and Quantity values cannot be empty for an entry.");
                }

                $w_int = filter_var($w_val, FILTER_VALIDATE_INT);
                $q_int = filter_var($q_val, FILTER_VALIDATE_INT);

                if ($w_int === false || $q_int === false) {
                    throw new Exception("Wattage and Quantity must be valid integers.");
                }
                if ($w_int <= 0 || $q_int <= 0) {
                    throw new Exception("Wattage and Quantity must be positive integers.");
                }
                
                // Insert into project_wattage_orders for project size calculation
                $stmt_wattage->bind_param("iii", $project_id, $w_int, $q_int);
                if (!$stmt_wattage->execute()) {
                    throw new Exception("Error inserting wattage order (Wattage: {$w_int}W, Quantity: {$q_int}): " . $stmt_wattage->error);
                }
            }
            $stmt_wattage->close();

            // Define vendor_name and initial_location for the new module batch
            $manufacturer_name = "Unknown Manufacturer";
            $manufacturer_address = "";
            
            if ($manufacturer_id_for_batch) {
                // Get manufacturer details for vendor name and initial location
                $stmt_mfg = $conn->prepare("SELECT name, street_address, city, state, zip_code FROM manufacturers WHERE id = ?");
                if ($stmt_mfg) {
                    $stmt_mfg->bind_param("i", $manufacturer_id_for_batch);
                    $stmt_mfg->execute();
                    $stmt_mfg->bind_result($mfg_name, $mfg_street, $mfg_city, $mfg_state, $mfg_zip);
                    if ($stmt_mfg->fetch()) {
                        $manufacturer_name = $mfg_name;
                        $address_parts = array_filter([$mfg_street, $mfg_city, $mfg_state, $mfg_zip]);
                        $manufacturer_address = implode(', ', $address_parts);
                    }
                    $stmt_mfg->close();
                }
            }
            
            $default_vendor_name = $manufacturer_name;
            
            // Use manufacturer address as initial location, fallback to project address
            if (!empty($manufacturer_address)) {
                $default_initial_location = $manufacturer_address;
            } else {
                $address_parts = array_filter([$street_address, $city, $state, $zip_code]);
                $default_initial_location = implode(', ', $address_parts);
            }

            // Insert into modules table for the initial batch
            $stmt_module = $conn->prepare("
                INSERT INTO modules (account_id, vendor_name, initial_location, project_id) 
                VALUES (?, ?, ?, ?)
            ");
            if (!$stmt_module) {
                throw new Exception("Error preparing module batch insert: " . $conn->error);
            }
            $stmt_module->bind_param("issi", $account_id, $default_vendor_name, $default_initial_location, $project_id);
            
            if (!$stmt_module->execute()) {
                throw new Exception("Error inserting module batch for project: " . $stmt_module->error);
            }
            $module_batch_id = $stmt_module->insert_id;
            $stmt_module->close();

            // Insert items into unassigned_module_items
            $stmt_item = $conn->prepare("
                INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) 
                VALUES (?, ?, ?)
            ");
            if (!$stmt_item) {
                throw new Exception("Error preparing module item insert: " . $conn->error);
            }

            for ($i = 0; $i < count($wattages); $i++) {
                $w_val = trim($wattages[$i]);
                $q_val = trim($quantities[$i]);

                // We already validated these above, so we can use the same validation
                $w_int = filter_var($w_val, FILTER_VALIDATE_INT);
                $q_int = filter_var($q_val, FILTER_VALIDATE_INT);
                
                $stmt_item->bind_param("iii", $module_batch_id, $w_int, $q_int);
                if (!$stmt_item->execute()) {
                    throw new Exception("Error inserting module item (Wattage: {$w_int}W, Quantity: {$q_int}): " . $stmt_item->error);
                }
            }
            $stmt_item->close();
        }

        // Set a success message to be displayed with the form below
        $successMessage = "Project added successfully!";
        
        // If modules were created, enhance the success message
        if (isset($_POST['wattages'], $_POST['quantities'])) {
            $totalModulesCreated = 0;
            for ($i = 0; $i < count($quantities); $i++) {
                $totalModulesCreated += filter_var($quantities[$i], FILTER_VALIDATE_INT);
            }
            $successMessage = "Project added successfully! " . number_format($totalModulesCreated) . " modules created for this project. <a href='modules.php' style='color: #488C9A; text-decoration: underline;'>Modules can be viewed here</a>.";
        }
    } catch (Exception $ex) {
        // Set the error message to be displayed with the form below
        $errorMessage = $ex->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Project</title>
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
        form input[type="date"],
        form input[type="file"],
        form select {
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
        /* Keep specific wattage entry styles */
        .wattage-entry {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            border: 1px dashed #ccc;
            border-radius: 4px;
        }
        .wattage-entry label {
            margin-top: 0; /* Override general label margin */
            flex-basis: 100px; /* Adjust label width as needed */
        }
        .wattage-entry input[type="number"] {
            flex-grow: 1; /* Allow inputs to take available space */
            width: auto; /* Override 100% width */
        }
        .wattage-entry button {
            background: #dc3545; /* Red for remove */
            color: #fff;
            border: none;
            padding: 8px 14px;
            cursor: pointer;
            border-radius: 4px;
            margin-top: 5px; /* Align with inputs */
            align-self: flex-end; /* Align button bottom */
        }
        .wattage-entry button:hover {
            background: #c82333;
        }
        /* Styling for the 'Add Wattage' button */
         .btn-add-wattage {
            background: #488C9A;
            color: #fff;
            border: none;
            padding: 8px 14px;
            cursor: pointer;
            border-radius: 4px;
            margin-top: 10px;
            margin-bottom: 20px; /* Space before submit */
            display: inline-block;
        }
        .btn-add-wattage:hover {
            background: #293E4C;
        }
        /* General submit button style */
        .btn-submit { 
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

        .section-title {
            margin-top: 30px;
            margin-bottom: 15px; /* Increased bottom margin */
            font-size: 1.2rem; /* Slightly larger title */
            font-weight: 600;
            color: #333;
            border-bottom: 1px solid #eee; /* Add a light separator */
            padding-bottom: 5px;
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
    <script>
        function addWattageField() {
            var container = document.getElementById('wattage-container');
            var index = container.children.length;

            var div = document.createElement('div');
            div.className = 'wattage-entry';

            var wattageLabel = document.createElement('label');
            wattageLabel.textContent = 'Wattage:';
            var wattageInput = document.createElement('input');
            wattageInput.type = 'number';
            wattageInput.step = '1';
            wattageInput.name = 'wattages[' + index + ']';
            wattageInput.required = true;

            var totalOrderLabel = document.createElement('label');
            totalOrderLabel.textContent = 'Quantity:';
            var totalOrderInput = document.createElement('input');
            totalOrderInput.type = 'number';
            totalOrderInput.step = '1';
            totalOrderInput.name = 'quantities[' + index + ']';
            totalOrderInput.required = true;

            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Remove';
            removeButton.onclick = function() {
                container.removeChild(div);
            };
            removeButton.style.marginTop = '0';

            div.appendChild(wattageLabel);
            div.appendChild(wattageInput);
            div.appendChild(totalOrderLabel);
            div.appendChild(totalOrderInput);
            div.appendChild(removeButton);

            container.appendChild(div);
        }
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Breadcrumb Navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="admin_dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manage_projects.php" style="color: #488C9A; text-decoration: none;">Manage Projects</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Add Project</span>
    </div>

    <h1>Add Project</h1>

    <!-- Display success or error messages if any -->
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><strong><?php 
            // Check if the message contains HTML (specifically a link) and display accordingly
            if (strpos($successMessage, '<a href=') !== false) {
                echo $successMessage; // Don't escape if it contains HTML links
            } else {
                echo htmlspecialchars($successMessage); // Escape for safety if no HTML
            }
        ?></strong></div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- The project form -->
    <form action="" method="POST" enctype="multipart/form-data">
        <?php if ($role === 'global_admin'): ?>
            <label for="account_id">Account Name:</label>
            <select name="account_id" id="account_id" required>
                <option value="">--Select Account--</option>
                <?php foreach ($accounts as $acc): ?>
                    <option value="<?php echo $acc['id']; ?>">
                        <?php echo htmlspecialchars($acc['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input type="hidden" name="account_id" value="<?php echo $account_id_for_admin; ?>">
        <?php endif; ?>

        <label for="project_name">Project Name:</label>
        <input type="text" id="project_name" name="project_name" required>

        <label>Project Address:</label>
        <div class="address-grid">
            <div>
                <label for="street_address" style="margin-top: 0; font-size: 0.9em; color: #666;">Street Address:</label>
                <input type="text" id="street_address" name="street_address" placeholder="1107 W Manresa Way">
            </div>
            <div>
                <label for="city" style="margin-top: 0; font-size: 0.9em; color: #666;">City:</label>
                <input type="text" id="city" name="city" placeholder="Huachuca City">
            </div>
            <div>
                <label for="state" style="margin-top: 0; font-size: 0.9em; color: #666;">State:</label>
                <input type="text" id="state" name="state" placeholder="AZ">
            </div>
            <div>
                <label for="zip_code" style="margin-top: 0; font-size: 0.9em; color: #666;">Zip Code:</label>
                <input type="text" id="zip_code" name="zip_code" placeholder="85616">
            </div>
        </div>

        <label for="image_file">Project Image:</label>
        <input type="file" id="image_file" name="image_file" accept="image/*">

        <label for="estimated_completion_date">Estimated Completion Date:</label>
        <input type="date" id="estimated_completion_date" name="estimated_completion_date">

        <?php if ($role === 'global_admin'): ?>
            <label for="solterra_fee">Solterra Fee (per watt):</label>
            <input type="number" id="solterra_fee" step="0.0001" name="solterra_fee" value="0.0000" required>
        <?php else: ?>
            <!-- Hidden input for admin users - defaults to 0.0000 -->
            <input type="hidden" name="solterra_fee" value="0.0000">
        <?php endif; ?>

        <div class="section-title">Initial Module Batch (Optional)</div>
        <p style="color: #666; font-size: 0.9em; margin-bottom: 15px;">
            Create an initial module batch for this project. You can add more batches from different manufacturers later via "Manage Modules".
        </p>
        
        <label for="manufacturer_id">Manufacturer (for initial batch):</label>
        <select name="manufacturer_id" id="manufacturer_id">
            <option value="">--Select Manufacturer (Optional)--</option>
            <?php foreach ($manufacturers as $mfg): ?>
                <option value="<?php echo $mfg['id']; ?>">
                    <?php echo htmlspecialchars($mfg['name']); ?>
                    <?php if (!empty($mfg['short_name'])): ?>
                        (<?php echo htmlspecialchars($mfg['short_name']); ?>)
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="section-title">Wattage and Total Order Quantities</div>
        <div id="wattage-container">
            <!-- Wattage fields will be added here by JS -->
        </div>
        <button type="button" class="btn-add-wattage" onclick="addWattageField()">Add Wattage</button>

        <div class="btn-submit-container">
            <input type="submit" value="Add Project" class="btn-submit">
        </div>
    </form>
</main>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCYF3qz_6niMzpTd0yklUX9YNpk73KviBM&libraries=places"></script>

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
