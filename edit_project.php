<?php
session_name("logistics_session");
session_start();

// Ensure the user is a global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

// Check if we have a project_id (for both GET and POST)
if (!isset($_REQUEST['project_id'])) {
    die("Project ID is missing.");
}
$project_id = intval($_REQUEST['project_id']);

// If POST, process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gather form fields
    $project_name             = trim($_POST['project_name'] ?? '');
    $street_address           = trim($_POST['street_address'] ?? '');
    $city                     = trim($_POST['city'] ?? '');
    $state                    = trim($_POST['state'] ?? '');
    $zip_code                 = trim($_POST['zip_code'] ?? '');
    $estimated_completion_date= trim($_POST['estimated_completion_date'] ?? '');
    $solterra_fee             = isset($_POST['solterra_fee']) ? floatval($_POST['solterra_fee']) : 0.0000;

    // Fetch existing image URL to see if we need to replace it
    $stmtOld = $conn->prepare("SELECT image_url FROM projects WHERE id = ?");
    $stmtOld->bind_param("i", $project_id);
    $stmtOld->execute();
    $stmtOld->bind_result($existing_image_url);
    $stmtOld->fetch();
    $stmtOld->close();

    // Handle new image file if uploaded
    $image_url = $existing_image_url; // default to existing
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Check for upload errors
        if ($_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext = ['jpg','jpeg','png','gif'];
            $file_name   = $_FILES['image_file']['name'];
            $file_tmp    = $_FILES['image_file']['tmp_name'];
            $file_size   = $_FILES['image_file']['size'];
            $file_ext    = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (in_array($file_ext, $allowed_ext)) {
                if ($file_size <= 5*1024*1024) {
                    $unique_name = uniqid('project_', true).'.'.$file_ext;
                    $upload_dir  = 'uploads/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    if (move_uploaded_file($file_tmp, $upload_dir.$unique_name)) {
                        $image_url = $upload_dir.$unique_name;
                        // Delete old image if it exists
                        if (!empty($existing_image_url) && file_exists($existing_image_url)) {
                            unlink($existing_image_url);
                        }
                    } else {
                        die("Error uploading the image file.");
                    }
                } else {
                    die("File size exceeds the maximum limit of 5MB.");
                }
            } else {
                die("Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed.");
            }
        } else {
            die("Error uploading the file. Error code: ".$_FILES['image_file']['error']);
        }
    }

    // Update the project with separate address fields (trigger will populate project_address)
    $stmtUp = $conn->prepare("
        UPDATE projects
           SET project_name = ?,
               street_address = ?,
               city = ?,
               state = ?,
               zip_code = ?,
               estimated_completion_date = ?,
               image_url = ?,
               solterra_fee = ?
         WHERE id = ?
    ");
    $stmtUp->bind_param("sssssssdi",
        $project_name,
        $street_address,
        $city,
        $state,
        $zip_code,
        $estimated_completion_date,
        $image_url,
        $solterra_fee,
        $project_id
    );
    if (!$stmtUp->execute()) {
        die("Error updating project: " . $stmtUp->error);
    }
    $stmtUp->close();

    // Note: Module quantities are now managed through module batches assigned to this project
    // The wattage/quantity display below is automatically calculated from assigned batches

    $conn->close();
    // Redirect or show success
    header("Location: dashboard");
    exit();

} else {
    // GET request => show the form
    // 1) Fetch project
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id=?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows<1) {
        $conn->close();
        die("Project not found.");
    }
    $project = $res->fetch_assoc();

    // 2) Fetch actual module batches assigned to this project (replaces manual wattage orders)
    $stmtModules = $conn->prepare("
        SELECT 
            umi.wattage,
            SUM(umi.quantity) as total_quantity
        FROM modules m
        JOIN unassigned_module_items umi ON m.id = umi.unassigned_module_id
        WHERE m.project_id = ?
        GROUP BY umi.wattage
        ORDER BY umi.wattage ASC
    ");
    $stmtModules->bind_param("i", $project_id);
    $stmtModules->execute();
    $moduleRes = $stmtModules->get_result();
    $stmtModules->close();

    $actual_modules = [];
    while ($row = $moduleRes->fetch_assoc()) {
        $actual_modules[] = $row;
    }

    // Also get batch information for reference
    $stmtBatches = $conn->prepare("
        SELECT 
            m.id as batch_id,
            m.vendor_name,
            umi.wattage,
            umi.quantity
        FROM modules m
        JOIN unassigned_module_items umi ON m.id = umi.unassigned_module_id
        WHERE m.project_id = ?
        ORDER BY m.id ASC, umi.wattage ASC
    ");
    $stmtBatches->bind_param("i", $project_id);
    $stmtBatches->execute();
    $batchRes = $stmtBatches->get_result();
    $stmtBatches->close();

    $assigned_batches = [];
    while ($row = $batchRes->fetch_assoc()) {
        $assigned_batches[] = $row;
    }
    $conn->close();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Project</title>
        <link rel="stylesheet" href="portal.css">
        <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
        <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
        <style>
            /* Address grid layout */
            .address-grid {
                display: grid;
                grid-template-columns: 2fr 1fr 1fr;
                gap: 15px;
                margin-top: 5px;
            }
            .address-grid input {
                margin-top: 0;
                width: 100%;
                padding: 10px;
                border-radius: 4px;
                border: 1px solid #ccc;
                box-sizing: border-box;
            }
            .wattage-entry {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin-bottom: 10px;
                align-items: center;
            }
            .wattage-entry label {
                margin-top: 0;
            }
            .btn-add-wattage, .wattage-entry button {
                background: #488C9A;
                color: #fff;
                border: none;
                padding: 8px 14px;
                cursor: pointer;
                border-radius: 4px;
                margin-top: 10px;
            }
            .btn-add-wattage:hover, .wattage-entry button:hover {
                background: #293E4C;
            }
            .btn-submit {
                background: #293E4C;
                color: #fff;
                border: none;
                padding: 12px 20px;
                cursor: pointer;
                border-radius: 4px;
                font-size: 1rem;
                margin-top: 20px;
            }
            .btn-submit:hover {
                background: #488C9A;
            }
            /* Form styling */
            form label {
                display: block;
                margin-top: 15px;
                font-weight: 600;
                color: #333;
            }
            form input[type="text"],
            form input[type="number"],
            form input[type="date"],
            form input[type="file"] {
                width: 100%;
                padding: 10px;
                margin-top: 5px;
                border-radius: 4px;
                border: 1px solid #ccc;
                box-sizing: border-box;
            }
        </style>
        <script>
            // Module quantities are now managed through assigned module batches
            // No JavaScript needed for wattage management
        </script>
    </head>
    <body>
    <?php include 'header.php'; ?>
    <main>
        <h1>Edit Project</h1>
        <form id="edit-project-form" action="edit_project.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">

            <label for="project_name">Project Name:</label>
            <input type="text" name="project_name" value="<?php echo htmlspecialchars($project['project_name']); ?>" required>

            <label>Project Address:</label>
            <div class="address-grid">
                <div>
                    <label style="margin-top: 0; font-size: 0.9em; color: #666;">Street Address:</label>
                    <input type="text" name="street_address" value="<?php echo htmlspecialchars($project['street_address'] ?? ''); ?>" placeholder="1107 W Manresa Way">
                </div>
                <div>
                    <label style="margin-top: 0; font-size: 0.9em; color: #666;">City:</label>
                    <input type="text" name="city" value="<?php echo htmlspecialchars($project['city'] ?? ''); ?>" placeholder="Huachuca City">
                </div>
                <div>
                    <label style="margin-top: 0; font-size: 0.9em; color: #666;">State:</label>
                    <input type="text" name="state" value="<?php echo htmlspecialchars($project['state'] ?? ''); ?>" placeholder="AZ">
                </div>
                <div>
                    <label style="margin-top: 0; font-size: 0.9em; color: #666;">Zip Code:</label>
                    <input type="text" name="zip_code" value="<?php echo htmlspecialchars($project['zip_code'] ?? ''); ?>" placeholder="85616">
                </div>
            </div>

            <label for="image_file">Project Image:</label>
            <?php if (!empty($project['image_url'])): ?>
                <div>
                    <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="Project Image" style="max-width: 200px;">
                </div>
            <?php endif; ?>
            <input type="file" name="image_file" accept="image/*">

            <label for="estimated_completion_date">Estimated Completion Date:</label>
            <input type="date" name="estimated_completion_date" value="<?php echo htmlspecialchars($project['estimated_completion_date']); ?>">

            <label for="solterra_fee">Solterra Fee (per watt):</label>
            <input type="number" step="0.0001" name="solterra_fee"
                   value="<?php echo isset($project['solterra_fee']) ? htmlspecialchars($project['solterra_fee']) : '0.0000'; ?>"
                   required
            >

            <h2>Project Module Summary</h2>
            <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #dee2e6;">
                <?php if (!empty($actual_modules)): ?>
                    <div style="margin-bottom: 20px;">
                        <h3 style="margin-top: 0; color: #293E4C;">Total Modules by Wattage</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <?php foreach ($actual_modules as $module): ?>
                                <div style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #488C9A;">
                                    <div style="font-size: 1.2em; font-weight: bold; color: #293E4C;">
                                        <?php echo number_format($module['wattage']); ?>W
                                    </div>
                                    <div style="font-size: 1.1em; color: #488C9A; font-weight: 600;">
                                        <?php echo number_format($module['total_quantity']); ?> modules
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 15px; padding: 10px; background-color: #e8f4f8; border-radius: 4px;">
                            <strong>Total Project Modules: 
                                <?php 
                                $total_all = array_sum(array_column($actual_modules, 'total_quantity'));
                                echo number_format($total_all); 
                                ?>
                            </strong>
                        </div>
                    </div>

                    <?php if (!empty($assigned_batches)): ?>
                        <div>
                            <h4 style="color: #293E4C; margin-bottom: 10px;">Assigned Module Batches</h4>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                                    <thead>
                                        <tr style="background-color: #293E4C; color: white;">
                                            <th style="padding: 8px; border: 1px solid #ddd;">Batch</th>
                                                                                         <th style="padding: 8px; border: 1px solid #ddd;">Manufacturer</th>
                                            <th style="padding: 8px; border: 1px solid #ddd;">Wattage</th>
                                            <th style="padding: 8px; border: 1px solid #ddd;">Quantity</th>
                                            <th style="padding: 8px; border: 1px solid #ddd;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assigned_batches as $batch): ?>
                                            <tr style="border-bottom: 1px solid #eee;">
                                                <td style="padding: 8px; border: 1px solid #ddd;">#<?php echo $batch['batch_id']; ?></td>
                                                <td style="padding: 8px; border: 1px solid #ddd;">
                                                    <?php 
                                                    // Extract just the manufacturer name (before the " - " if it exists)
                                                    $manufacturer_name = explode(' - ', $batch['vendor_name'])[0];
                                                    echo htmlspecialchars($manufacturer_name); 
                                                    ?>
                                                </td>
                                                <td style="padding: 8px; border: 1px solid #ddd;"><?php echo number_format($batch['wattage']); ?>W</td>
                                                <td style="padding: 8px; border: 1px solid #ddd;"><?php echo number_format($batch['quantity']); ?></td>
                                                <td style="padding: 8px; text-align: center; border: 1px solid #ddd;">
                                                    <a href="module_overview.php?batch_id=<?php echo $batch['batch_id']; ?>" 
                                                       style="color: #488C9A; text-decoration: none; font-size: 0.85em;">View Batch</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <h3 style="margin-top: 0;">No Module Batches Assigned</h3>
                        <p>This project doesn't have any module batches assigned to it yet.</p>
                        <p>
                            <a href="modules.php" style="color: #488C9A; text-decoration: none; font-weight: 600;">
                                → Go to Manage Modules to assign batches to this project
                            </a>
                        </p>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 15px; padding: 10px; background-color: #fff3cd; border-radius: 4px; font-size: 0.9em;">
                    <strong>Note:</strong> Module quantities are automatically calculated from batches assigned to this project. 
                    To modify module quantities, edit the individual module batches in 
                    <a href="modules.php" style="color: #856404;">Manage Modules</a>.
                </div>
            </div>

            <button type="submit" class="btn-submit">Update Project</button>
        </form>
        <br>
        <a href="dashboard">Back to Dashboard</a>
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
        
        // Only initialize if the street address input exists
        if (!streetAddressInput) return;
        
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
    <?php
}
