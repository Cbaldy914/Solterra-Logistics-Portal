<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['global_admin','admin','customer_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$google_maps_api_key = getGoogleMapsApiKey();

if (!isset($_REQUEST['id'])) {
    die("Carrier ID is missing.");
}
$carrier_id = intval($_REQUEST['id']);

$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

function slp_normalize_country($country) {
    $trimmed = trim((string)$country);
    $upper = strtoupper($trimmed);
    $mapToUsa = ['US', 'U.S.', 'U.S.A.', 'UNITED STATES', 'UNITED STATES OF AMERICA', 'AMERICA'];
    if ($upper === '' || $upper === 'USA') {
        return 'USA';
    }
    if (in_array($upper, $mapToUsa, true)) {
        return 'USA';
    }
    return $trimmed;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = trim($_POST['name'] ?? '');
        $short_name = trim($_POST['short_name'] ?? '');
        $mc_number = trim($_POST['mc_number'] ?? '');
        $dot_number = trim($_POST['dot_number'] ?? '');
        $carrier_type = $_POST['carrier_type'] ?? 'ftl';
        $is_solterra_managed = ($role === 'global_admin' && isset($_POST['is_solterra_managed'])) ? 1 : 0;
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $street_address = trim($_POST['street_address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip_code = trim($_POST['zip_code'] ?? '');
        $country = slp_normalize_country($_POST['country'] ?? 'USA');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '') {
            throw new Exception("Carrier Name is required.");
        }
        $valid_types = ['ftl','ltl','drayage','intermodal','ocean','other'];
        if (!in_array($carrier_type, $valid_types, true)) {
            $carrier_type = 'ftl';
        }

        // Fetch existing logo
        $stmtOld = $conn->prepare("SELECT logo_url, is_solterra_managed FROM carriers WHERE id = ?");
        $stmtOld->bind_param("i", $carrier_id);
        $stmtOld->execute();
        $oldRow = $stmtOld->get_result()->fetch_assoc();
        $stmtOld->close();
        $existing_logo_url = $oldRow['logo_url'] ?? null;

        // Preserve is_solterra_managed if not global_admin
        if ($role !== 'global_admin') {
            $is_solterra_managed = (int)($oldRow['is_solterra_managed'] ?? 0);
        }

        // Handle logo upload
        $logo_url = $existing_logo_url;
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

                $target_dir = "uploads/carrier_logos/";
                if (!is_dir($target_dir)) {
                    if (!mkdir($target_dir, 0755, true)) {
                        throw new Exception("Failed to create upload directory.");
                    }
                }

                $unique_name = uniqid('carrier_', true).'.'.$file_ext;
                $target_file = $target_dir . $unique_name;

                if (!move_uploaded_file($file_tmp, $target_file)) {
                    throw new Exception("Error uploading logo file.");
                }

                if (!empty($existing_logo_url) && file_exists($existing_logo_url)) {
                    unlink($existing_logo_url);
                }

                $logo_url = $target_file;
            } else {
                throw new Exception("File upload error code: " . $_FILES['logo_file']['error']);
            }
        }

        $stmt = $conn->prepare("
            UPDATE carriers
            SET name = ?, short_name = ?, mc_number = ?, dot_number = ?, carrier_type = ?,
                is_solterra_managed = ?,
                contact_person = ?, phone = ?, email = ?, website = ?,
                street_address = ?, city = ?, state = ?, zip_code = ?, country = ?,
                logo_url = ?, is_active = ?, notes = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        if (!$stmt) {
            throw new Exception("Error preparing carrier update: " . $conn->error);
        }

        $stmt->bind_param(
            "sssssissssssssssissi",
            $name, $short_name, $mc_number, $dot_number, $carrier_type,
            $is_solterra_managed,
            $contact_person, $phone, $email, $website,
            $street_address, $city, $state, $zip_code, $country,
            $logo_url, $is_active, $notes,
            $carrier_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Error updating carrier: " . $stmt->error);
        }
        $stmt->close();

        $conn->close();
        header("Location: manage_carriers.php");
        exit();

    } catch (Exception $ex) {
        $errorMessage = $ex->getMessage();
    }
}

// GET: Fetch carrier data
$stmt = $conn->prepare("SELECT * FROM carriers WHERE id = ?");
$stmt->bind_param("i", $carrier_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if ($res->num_rows < 1) {
    $conn->close();
    die("Carrier not found.");
}
$carrier = $res->fetch_assoc();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Carrier</title>
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
        .address-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
            margin-top: 5px;
        }
        .address-grid input {
            margin-top: 0;
        }
        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 5px;
        }
        .contact-grid input {
            margin-top: 0;
        }
        .carrier-id-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-top: 5px;
        }
        .carrier-id-grid input,
        .carrier-id-grid select {
            margin-top: 0;
        }
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
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Edit Carrier',
            'extra' => [ ['label' => 'Manage Carriers', 'url' => 'manage_carriers.php'] ]
        ]);
    ?>

    <h1>Edit Carrier</h1>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?php echo $carrier_id; ?>">

        <div class="section-title">Basic Information</div>

        <label for="name">Carrier Name: <span style="color: red;">*</span></label>
        <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($carrier['name']); ?>">

        <label for="short_name">Short Name/Abbreviation:</label>
        <input type="text" id="short_name" name="short_name" value="<?php echo htmlspecialchars($carrier['short_name'] ?? ''); ?>">
        <div class="form-note">Optional short name or common abbreviation</div>

        <div class="section-title">Carrier Identification</div>

        <div class="carrier-id-grid">
            <div>
                <label for="mc_number" style="margin-top: 0; font-size: 0.9em; color: #666;">MC Number:</label>
                <input type="text" id="mc_number" name="mc_number" value="<?php echo htmlspecialchars($carrier['mc_number'] ?? ''); ?>">
            </div>
            <div>
                <label for="dot_number" style="margin-top: 0; font-size: 0.9em; color: #666;">DOT Number:</label>
                <input type="text" id="dot_number" name="dot_number" value="<?php echo htmlspecialchars($carrier['dot_number'] ?? ''); ?>">
            </div>
            <div>
                <label for="carrier_type" style="margin-top: 0; font-size: 0.9em; color: #666;">Carrier Type:</label>
                <?php $ct = $carrier['carrier_type'] ?? 'ftl'; ?>
                <select id="carrier_type" name="carrier_type">
                    <option value="ftl" <?php echo $ct === 'ftl' ? 'selected' : ''; ?>>FTL (Full Truckload)</option>
                    <option value="ltl" <?php echo $ct === 'ltl' ? 'selected' : ''; ?>>LTL (Less Than Truckload)</option>
                    <option value="drayage" <?php echo $ct === 'drayage' ? 'selected' : ''; ?>>Drayage</option>
                    <option value="intermodal" <?php echo $ct === 'intermodal' ? 'selected' : ''; ?>>Intermodal</option>
                    <option value="ocean" <?php echo $ct === 'ocean' ? 'selected' : ''; ?>>Ocean Freight</option>
                    <option value="other" <?php echo $ct === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
        </div>
        <div class="form-note">FMCSA Motor Carrier (MC) number and USDOT number</div>

        <div class="section-title">Contact Information</div>

        <label for="contact_person">Contact Person:</label>
        <input type="text" id="contact_person" name="contact_person" value="<?php echo htmlspecialchars($carrier['contact_person'] ?? ''); ?>">

        <div class="contact-grid">
            <div>
                <label for="phone" style="margin-top: 0; font-size: 0.9em; color: #666;">Phone:</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($carrier['phone'] ?? ''); ?>">
            </div>
            <div>
                <label for="email" style="margin-top: 0; font-size: 0.9em; color: #666;">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($carrier['email'] ?? ''); ?>">
            </div>
        </div>

        <label for="website">Website:</label>
        <input type="url" id="website" name="website" value="<?php echo htmlspecialchars($carrier['website'] ?? ''); ?>">

        <div class="section-title">Address</div>
        <div class="form-note">Carrier address (optional)</div>

        <div class="address-grid">
            <div>
                <label for="street_address" style="margin-top: 0; font-size: 0.9em; color: #666;">Street Address:</label>
                <input type="text" id="street_address" name="street_address" value="<?php echo htmlspecialchars($carrier['street_address'] ?? ''); ?>">
            </div>
            <div>
                <label for="city" style="margin-top: 0; font-size: 0.9em; color: #666;">City:</label>
                <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($carrier['city'] ?? ''); ?>">
            </div>
            <div>
                <label for="state" style="margin-top: 0; font-size: 0.9em; color: #666;">State/Province:</label>
                <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($carrier['state'] ?? ''); ?>">
            </div>
            <div>
                <label for="zip_code" style="margin-top: 0; font-size: 0.9em; color: #666;">Zip/Postal Code:</label>
                <input type="text" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars($carrier['zip_code'] ?? ''); ?>">
            </div>
        </div>

        <label for="country">Country:</label>
        <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($carrier['country'] ?? 'USA'); ?>">

        <div class="section-title">Additional Information</div>

        <label for="logo_file">Company Logo:</label>
        <?php if (!empty($carrier['logo_url'])): ?>
            <div>
                <img src="<?php echo htmlspecialchars($carrier['logo_url']); ?>" alt="Current Logo" class="current-logo">
                <div class="form-note">Current logo (upload a new file to replace)</div>
            </div>
        <?php endif; ?>
        <input type="file" id="logo_file" name="logo_file" accept="image/*">
        <div class="form-note">Optional company logo (JPG, PNG, GIF, SVG - Max 5MB)</div>

        <?php if ($role === 'global_admin'): ?>
            <div class="checkbox-container">
                <input type="checkbox" id="is_solterra_managed" name="is_solterra_managed" <?php echo $carrier['is_solterra_managed'] ? 'checked' : ''; ?>>
                <label for="is_solterra_managed" style="margin-top: 0;">Solterra-Managed Carrier</label>
            </div>
            <div class="form-note">Check if this carrier is managed by Solterra Solutions</div>
        <?php endif; ?>

        <div class="checkbox-container">
            <input type="checkbox" id="is_active" name="is_active" <?php echo $carrier['is_active'] ? 'checked' : ''; ?>>
            <label for="is_active" style="margin-top: 0;">Active Carrier</label>
        </div>
        <div class="form-note">Uncheck to mark as inactive</div>

        <label for="notes">Notes:</label>
        <textarea id="notes" name="notes"><?php echo htmlspecialchars($carrier['notes'] ?? ''); ?></textarea>

        <div class="btn-submit-container">
            <input type="submit" value="Update Carrier" class="btn-submit">
        </div>
    </form>

</main>

<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places"></script>

<script>
function initializeAddressAutocomplete() {
    const streetAddressInput = document.getElementById('street_address');
    const cityInput = document.getElementById('city');
    const stateInput = document.getElementById('state');
    const zipInput = document.getElementById('zip_code');
    const countryInput = document.getElementById('country');

    const autocomplete = new google.maps.places.Autocomplete(streetAddressInput, {
        types: ['address']
    });

    autocomplete.addListener('place_changed', function() {
        const place = autocomplete.getPlace();

        streetAddressInput.value = '';
        cityInput.value = '';
        stateInput.value = '';
        zipInput.value = '';
        if (countryInput) countryInput.value = '';

        if (!place.geometry) {
            return;
        }

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
                case 'sublocality_level_1':
                    if (!cityInput.value) cityInput.value = val;
                    break;
                case 'administrative_area_level_1':
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
                        const shortName = place.address_components[i].short_name;
                        countryInput.value = (shortName === 'US') ? 'USA' : val;
                    }
                    break;
            }
        }

        if (streetNumber && route) {
            streetAddressInput.value = (streetNumber + ' ' + route).trim();
        } else if (route) {
            streetAddressInput.value = route;
        }
    });
}

google.maps.event.addDomListener(window, 'load', initializeAddressAutocomplete);
</script>

</body>
</html>
