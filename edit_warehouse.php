<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

$errorMessage = '';
$successMessage = '';
$warehouse = null;

// Get warehouse ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage_warehouses.php");
    exit();
}

$warehouse_id = intval($_GET['id']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $name = trim($_POST['name'] ?? '');
        $street_address = trim($_POST['street_address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip_code = trim($_POST['zip_code'] ?? '');
        $country = trim($_POST['country'] ?? 'USA');
        $is_port = isset($_POST['is_port']) ? 1 : 0;
        
        // Cost structure fields
        $entry_fee = floatval($_POST['entry_fee'] ?? 0);
        $exit_fee = floatval($_POST['exit_fee'] ?? 0);
        $monthly_storage_fee = floatval($_POST['monthly_storage_fee'] ?? 0);
        $customs_clearance_fee = floatval($_POST['customs_clearance_fee'] ?? 0);

        if (empty($name)) {
            throw new Exception("Warehouse name is required.");
        }

        // Check if at least one address field is provided
        if (empty($street_address) && empty($city) && empty($state) && empty($zip_code)) {
            throw new Exception("At least one address field is required.");
        }

        // Combine address fields for the main address field (for backward compatibility)
        $address_parts = array_filter([$street_address, $city, $state, $zip_code]);
        $combined_address = implode(', ', $address_parts);

        // Update warehouse basic information
        $stmt = $conn->prepare("UPDATE warehouses SET name = ?, address = ?, street_address = ?, city = ?, state = ?, zip_code = ?, country = ?, is_port = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing update statement: " . $conn->error);
        }

        $stmt->bind_param("sssssssii", $name, $combined_address, $street_address, $city, $state, $zip_code, $country, $is_port, $warehouse_id);
        
        if ($stmt->execute()) {
            // Update cost structure - first delete existing cost items, then insert new ones
            $stmt_delete = $conn->prepare("DELETE FROM warehouse_cost_items WHERE warehouse_id = ?");
            if ($stmt_delete) {
                $stmt_delete->bind_param("i", $warehouse_id);
                $stmt_delete->execute();
                $stmt_delete->close();
            }
            
            // Insert new cost items for any non-zero fees
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
            
            if (!empty($cost_items)) {
                $stmt_cost = $conn->prepare("INSERT INTO warehouse_cost_items (warehouse_id, label, trigger_event, amount) VALUES (?, ?, ?, ?)");
                if ($stmt_cost) {
                    foreach ($cost_items as $cost_item) {
                        $stmt_cost->bind_param("issd", $warehouse_id, $cost_item[0], $cost_item[1], $cost_item[2]);
                        $stmt_cost->execute();
                    }
                    $stmt_cost->close();
                }
            }
            
            $successMessage = "Warehouse and cost structure updated successfully!";
            // Refresh warehouse data
            $warehouse = null; // Will be fetched again below
        } else {
            throw new Exception("Error updating warehouse: " . $stmt->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Fetch warehouse data and cost items
if (!$warehouse) {
    try {
        $stmt = $conn->prepare("SELECT id, name, address, street_address, city, state, zip_code, country, is_port FROM warehouses WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing select statement: " . $conn->error);
        }

        $stmt->bind_param("i", $warehouse_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            throw new Exception("Warehouse not found.");
        }

        $warehouse = $result->fetch_assoc();
        $stmt->close();
        
        // Fetch existing cost items
        $cost_items = [];
        $stmt_costs = $conn->prepare("SELECT trigger_event, amount FROM warehouse_cost_items WHERE warehouse_id = ? AND is_active = 1");
        if ($stmt_costs) {
            $stmt_costs->bind_param("i", $warehouse_id);
            $stmt_costs->execute();
            $result_costs = $stmt_costs->get_result();
            while ($cost = $result_costs->fetch_assoc()) {
                $cost_items[$cost['trigger_event']] = $cost['amount'];
            }
            $stmt_costs->close();
        }
        
        // Set default values for form fields based on existing cost items
        $warehouse['entry_fee'] = $cost_items['entry'] ?? 0;
        $warehouse['exit_fee'] = $cost_items['exit'] ?? 0;
        $warehouse['monthly_storage_fee'] = $cost_items['monthly'] ?? 0;
        $warehouse['customs_clearance_fee'] = $cost_items['customs_clearance'] ?? 0;

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$conn->close();

// If we couldn't fetch the warehouse, redirect back
if (!$warehouse && empty($successMessage)) {
    header("Location: manage_warehouses.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Warehouse</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .form-container {
            max-width: 800px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 2px rgba(72, 140, 154, 0.1);
        }
        .required {
            color: red;
        }
        .address-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .fee-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background-color: #488C9A;
            color: white;
        }
        .btn-primary:hover {
            background-color: #293E4C;
        }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
        }
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success-message {
            color: #155724;
            background-color: #d4edda;
            padding: 15px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .address-grid {
                grid-template-columns: 1fr;
            }
            .fee-grid {
                grid-template-columns: 1fr;
            }
            .button-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Breadcrumb navigation -->
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Edit Warehouse',
            'extra' => [ ['label' => 'Manage Warehouses', 'url' => 'manage_warehouses.php'] ]
        ]);
    ?>

    <div class="form-container">
        <h1 style="margin-bottom: 30px; color: #333;">Edit Warehouse</h1>

        <?php if (!empty($errorMessage)): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
            <div class="success-message">
                <strong><?php echo htmlspecialchars($successMessage); ?></strong>
            </div>
        <?php endif; ?>

        <?php if ($warehouse): ?>
        <form method="POST" action="">
            <div class="form-group">
                <label for="name">Warehouse Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($warehouse['name']); ?>" required>
            </div>

            <h3 style="margin: 30px 0 15px 0; color: #333;">Address Information</h3>
            <div class="address-grid">
                <div class="form-group">
                    <label for="street_address">Street Address</label>
                    <input type="text" id="street_address" name="street_address" value="<?php echo htmlspecialchars($warehouse['street_address'] ?? ''); ?>" placeholder="123 Main Street">
                </div>
                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($warehouse['city'] ?? ''); ?>" placeholder="Phoenix">
                </div>
                <div class="form-group">
                    <label for="state">State/Province</label>
                    <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($warehouse['state'] ?? ''); ?>" placeholder="AZ">
                </div>
                <div class="form-group">
                    <label for="zip_code">ZIP/Postal Code</label>
                    <input type="text" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars($warehouse['zip_code'] ?? ''); ?>" placeholder="85001">
                </div>
                <div class="form-group">
                    <label for="country">Country</label>
                    <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($warehouse['country'] ?? 'USA'); ?>" placeholder="USA">
                </div>
            </div>
            <small style="color: #666; font-style: italic;">At least one address field is required</small>

            <div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6;">
                <label style="display: flex; align-items: center; margin: 0; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" id="is_port" name="is_port" style="margin-right: 8px; transform: scale(1.2);" <?php echo !empty($warehouse['is_port']) && $warehouse['is_port'] == 1 ? 'checked' : ''; ?>>
                    <span>🚢 This warehouse functions as a port of entry for overseas shipments</span>
                </label>
                <small style="color: #6c757d; margin-left: 24px; display: block; margin-top: 5px;">
                    Check this if this warehouse will receive overseas shipments and handle customs clearance
                </small>
            </div>

            <h3 style="margin: 30px 0 15px 0; color: #333;">💰 Cost Structure</h3>
            <div class="fee-grid" style="grid-template-columns: 1fr 1fr;">
                <div class="form-group">
                    <label for="entry_fee">Entry Fee (per pallet) ($)</label>
                    <input type="number" id="entry_fee" name="entry_fee" step="0.01" min="0" value="<?php echo number_format($warehouse['entry_fee'], 2, '.', ''); ?>" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label for="exit_fee">Exit Fee (per pallet) ($)</label>
                    <input type="number" id="exit_fee" name="exit_fee" step="0.01" min="0" value="<?php echo number_format($warehouse['exit_fee'], 2, '.', ''); ?>" placeholder="0.00">
                </div>
            </div>
            <div class="fee-grid" style="grid-template-columns: 1fr 1fr;">
                <div class="form-group">
                    <label for="monthly_storage_fee">Monthly Storage (per pallet) ($)</label>
                    <input type="number" id="monthly_storage_fee" name="monthly_storage_fee" step="0.01" min="0" value="<?php echo number_format($warehouse['monthly_storage_fee'], 2, '.', ''); ?>" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label for="customs_clearance_fee">Customs Clearance Fee (if port) ($)</label>
                    <input type="number" id="customs_clearance_fee" name="customs_clearance_fee" step="0.01" min="0" value="<?php echo number_format($warehouse['customs_clearance_fee'], 2, '.', ''); ?>" placeholder="0.00">
                </div>
            </div>
            <small style="color: #666; font-style: italic;">💡 Leave fees at $0.00 if not applicable. You can modify these anytime.</small>

            <div class="button-group">
                <button type="submit" class="btn btn-primary">Update Warehouse</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
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
