<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['global_admin','admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Check if we have a manufacturer_id (for both GET and POST)
if (!isset($_REQUEST['id'])) {
    die("Manufacturer ID is missing.");
}
$manufacturer_id = intval($_REQUEST['id']);

// If POST, process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Gather form fields
        $name = trim($_POST['name'] ?? '');
        $short_name = trim($_POST['short_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $street_address = trim($_POST['street_address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip_code = trim($_POST['zip_code'] ?? '');
        $country = trim($_POST['country'] ?? 'USA');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if ($name === '') {
            throw new Exception("Manufacturer Name is required.");
        }
        if ($street_address === '' && $city === '' && $state === '' && $zip_code === '') {
            throw new Exception("At least one address field is required.");
        }

        // Fetch existing logo URL to see if we need to replace it
        $stmtOld = $conn->prepare("SELECT logo_url FROM manufacturers WHERE id = ?");
        $stmtOld->bind_param("i", $manufacturer_id);
        $stmtOld->execute();
        $stmtOld->bind_result($existing_logo_url);
        $stmtOld->fetch();
        $stmtOld->close();

        // Handle new logo file if uploaded
        $logo_url = $existing_logo_url; // default to existing
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $allowed_ext = ['jpg','jpeg','png','gif','svg'];
                $file_name   = $_FILES['logo_file']['name'];
                $file_tmp    = $_FILES['logo_file']['tmp_name'];
                $file_size   = $_FILES['logo_file']['size'];
                $file_ext    = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if (!in_array($file_ext, $allowed_ext)) {
                    throw new Exception("Invalid file type. Only JPG, JPEG, PNG, GIF, SVG allowed.");
                }
                if ($file_size > 5*1024*1024) {
                    throw new Exception("File exceeds 5MB limit.");
                }

                // Ensure the uploads directory exists
                $target_dir = "uploads/manufacturer_logos/";
                if (!is_dir($target_dir)) {
                    if (!mkdir($target_dir, 0755, true)) {
                        throw new Exception("Failed to create upload directory.");
                    }
                }

                $unique_name = uniqid('mfg_', true).'.'.$file_ext;
                $target_file = $target_dir . $unique_name;

                if (!move_uploaded_file($file_tmp, $target_file)) {
                    throw new Exception("Error uploading logo file.");
                }
                
                // Delete old logo if it exists and is not empty
                if (!empty($existing_logo_url) && file_exists($existing_logo_url)) {
                    unlink($existing_logo_url);
                }
                
                $logo_url = $target_file;
            } else {
                throw new Exception("File upload error code: " . $_FILES['logo_file']['error']);
            }
        }

        // Update the manufacturer (trigger will populate address field)
        $stmt = $conn->prepare("
            UPDATE manufacturers 
            SET name = ?, 
                short_name = ?, 
                contact_person = ?, 
                phone = ?, 
                email = ?, 
                website = ?, 
                street_address = ?, 
                city = ?, 
                state = ?, 
                zip_code = ?, 
                country = ?, 
                logo_url = ?, 
                is_active = ?, 
                notes = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        if (!$stmt) {
            throw new Exception("Error preparing manufacturer update: " . $conn->error);
        }

        $stmt->bind_param(
            "ssssssssssssdsi",
            $name,
            $short_name,
            $contact_person,
            $phone,
            $email,
            $website,
            $street_address,
            $city,
            $state,
            $zip_code,
            $country,
            $logo_url,
            $is_active,
            $notes,
            $manufacturer_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Error updating manufacturer: " . $stmt->error);
        }
        $stmt->close();

        $conn->close();
        // Redirect to manufacturers page with success
        header("Location: manufacturers.php");
        exit();

    } catch (Exception $ex) {
        $errorMessage = $ex->getMessage();
    }
}

// GET request => show the form
// Fetch manufacturer data
$stmt = $conn->prepare("SELECT * FROM manufacturers WHERE id = ?");
$stmt->bind_param("i", $manufacturer_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if ($res->num_rows < 1) {
    $conn->close();
    die("Manufacturer not found.");
}
$manufacturer = $res->fetch_assoc();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Manufacturer</title>
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
        /* Contact grid layout */
        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 5px;
        }
        .contact-grid input {
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
        .current-logo {
            margin-top: 10px;
            max-width: 200px;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 5px;
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
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Edit Manufacturer',
            'extra' => [ ['label' => 'Manage Manufacturers', 'url' => 'manufacturers.php'] ]
        ]);
    ?>

    <h1>Edit Manufacturer</h1>

    <!-- Display error message if any -->
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- The manufacturer form -->
    <form action="" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?php echo $manufacturer_id; ?>">
        
        <div class="section-title">Basic Information</div>
        
        <label for="name">Manufacturer Name: <span style="color: red;">*</span></label>
        <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($manufacturer['name']); ?>">

        <label for="short_name">Short Name/Abbreviation:</label>
        <input type="text" id="short_name" name="short_name" value="<?php echo htmlspecialchars($manufacturer['short_name'] ?? ''); ?>">
        <div class="form-note">Optional short name or common abbreviation</div>

        <div class="section-title">Contact Information</div>
        
        <label for="contact_person">Contact Person:</label>
        <input type="text" id="contact_person" name="contact_person" value="<?php echo htmlspecialchars($manufacturer['contact_person'] ?? ''); ?>">

        <div class="contact-grid">
            <div>
                <label for="phone" style="margin-top: 0; font-size: 0.9em; color: #666;">Phone:</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($manufacturer['phone'] ?? ''); ?>">
            </div>
            <div>
                <label for="email" style="margin-top: 0; font-size: 0.9em; color: #666;">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($manufacturer['email'] ?? ''); ?>">
            </div>
        </div>

        <label for="website">Website:</label>
        <input type="url" id="website" name="website" value="<?php echo htmlspecialchars($manufacturer['website'] ?? ''); ?>">

        <div class="section-title">Address <span style="color: red;">*</span></div>
        <div class="form-note">At least one address field is required</div>
        
        <div class="address-grid">
            <div>
                <label for="street_address" style="margin-top: 0; font-size: 0.9em; color: #666;">Street Address:</label>
                <input type="text" id="street_address" name="street_address" value="<?php echo htmlspecialchars($manufacturer['street_address'] ?? ''); ?>">
            </div>
            <div>
                <label for="city" style="margin-top: 0; font-size: 0.9em; color: #666;">City:</label>
                <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($manufacturer['city'] ?? ''); ?>">
            </div>
            <div>
                <label for="state" style="margin-top: 0; font-size: 0.9em; color: #666;">State/Province:</label>
                <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($manufacturer['state'] ?? ''); ?>">
            </div>
            <div>
                <label for="zip_code" style="margin-top: 0; font-size: 0.9em; color: #666;">Zip/Postal Code:</label>
                <input type="text" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars($manufacturer['zip_code'] ?? ''); ?>">
            </div>
        </div>

        <label for="country">Country:</label>
        <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($manufacturer['country'] ?? 'USA'); ?>">

        <div class="section-title">Additional Information</div>
        
        <label for="logo_file">Company Logo:</label>
        <?php if (!empty($manufacturer['logo_url'])): ?>
            <div>
                <img src="<?php echo htmlspecialchars($manufacturer['logo_url']); ?>" alt="Current Logo" class="current-logo">
                <div class="form-note">Current logo (upload a new file to replace)</div>
            </div>
        <?php endif; ?>
        <input type="file" id="logo_file" name="logo_file" accept="image/*">
        <div class="form-note">Optional company logo (JPG, PNG, GIF, SVG - Max 5MB)</div>

        <div class="checkbox-container">
            <input type="checkbox" id="is_active" name="is_active" <?php echo $manufacturer['is_active'] ? 'checked' : ''; ?>>
            <label for="is_active" style="margin-top: 0;">Active Manufacturer</label>
        </div>
        <div class="form-note">Uncheck to mark as inactive</div>

        <label for="notes">Notes:</label>
        <textarea id="notes" name="notes"><?php echo htmlspecialchars($manufacturer['notes'] ?? ''); ?></textarea>

        <div class="btn-submit-container">
            <input type="submit" value="Update Manufacturer" class="btn-submit">
        </div>
    </form>

</main>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=REDACTED_GOOGLE_MAPS_KEY&libraries=places"></script>

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
