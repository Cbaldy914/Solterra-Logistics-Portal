<?php

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
    die("Connection failed");
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

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
        
        // New site information fields (optional)
        $phone1                    = trim($_POST['phone1'] ?? '');
        $phone2                    = trim($_POST['phone2'] ?? '');
        $timezone                  = trim($_POST['timezone'] ?? 'America/New_York');
        $reference_numbers         = trim($_POST['reference_numbers'] ?? '');
        $instructions              = trim($_POST['instructions'] ?? '');
        $additional_notes          = trim($_POST['additional_notes'] ?? '');
        $driver_handout_url        = null; // Will be set if file is uploaded
        
        // Module information fields (optional) - use 0 as default for integer fields to avoid NULL binding issues
        $modules_per_pallet        = isset($_POST['modules_per_pallet']) && $_POST['modules_per_pallet'] !== '' ? (int)$_POST['modules_per_pallet'] : 0;
        $pallets_per_truck         = isset($_POST['pallets_per_truck']) && $_POST['pallets_per_truck'] !== '' ? (int)$_POST['pallets_per_truck'] : 0;
        $modules_per_truck         = isset($_POST['modules_per_truck']) && $_POST['modules_per_truck'] !== '' ? (int)$_POST['modules_per_truck'] : 0;
        $pallet_length_mm          = isset($_POST['pallet_length_mm']) && $_POST['pallet_length_mm'] !== '' ? (int)$_POST['pallet_length_mm'] : 0;
        $pallet_depth_mm           = isset($_POST['pallet_depth_mm']) && $_POST['pallet_depth_mm'] !== '' ? (int)$_POST['pallet_depth_mm'] : 0;
        $pallet_double_stacked_height_mm = isset($_POST['pallet_double_stacked_height_mm']) && $_POST['pallet_double_stacked_height_mm'] !== '' ? (int)$_POST['pallet_double_stacked_height_mm'] : 0;
        $pallet_total_weight_kg    = isset($_POST['pallet_total_weight_kg']) && $_POST['pallet_total_weight_kg'] !== '' ? (int)$_POST['pallet_total_weight_kg'] : 0;
        $stacking_in_warehouse     = trim($_POST['stacking_in_warehouse'] ?? '');
        $stacking_during_transport = trim($_POST['stacking_during_transport'] ?? '');
        $forklift_truck_long_side_mm = isset($_POST['forklift_truck_long_side_mm']) && $_POST['forklift_truck_long_side_mm'] !== '' ? (int)$_POST['forklift_truck_long_side_mm'] : 0;
        $forklift_truck_short_side_mm = isset($_POST['forklift_truck_short_side_mm']) && $_POST['forklift_truck_short_side_mm'] !== '' ? (int)$_POST['forklift_truck_short_side_mm'] : 0;
        $pallet_jack_long_side_mm  = isset($_POST['pallet_jack_long_side_mm']) && $_POST['pallet_jack_long_side_mm'] !== '' ? (int)$_POST['pallet_jack_long_side_mm'] : 0;
        $pallet_jack_short_side_mm = isset($_POST['pallet_jack_short_side_mm']) && $_POST['pallet_jack_short_side_mm'] !== '' ? (int)$_POST['pallet_jack_short_side_mm'] : 0;
        $module_notes              = trim($_POST['module_notes'] ?? '');
        $module_docs_url           = null; // Will be set if file is uploaded

        if ($project_name === '') {
            throw new Exception("Project Name is required.");
        }
        if ($street_address === '') {
            throw new Exception("Street Address is required.");
        }
        if ($city === '') {
            throw new Exception("City is required.");
        }
        if ($state === '') {
            throw new Exception("State is required.");
        }
        if ($zip_code === '') {
            throw new Exception("Zip Code is required.");
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

        // Handle driver handout upload
        if (isset($_FILES['driver_handout']) && $_FILES['driver_handout']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['driver_handout']['error'] === UPLOAD_ERR_OK) {
                $allowed_ext = ['pdf','doc','docx','jpg','jpeg','png'];
                $handout_name = $_FILES['driver_handout']['name'];
                $handout_tmp = $_FILES['driver_handout']['tmp_name'];
                $handout_size = $_FILES['driver_handout']['size'];
                $handout_ext = strtolower(pathinfo($handout_name, PATHINFO_EXTENSION));

                if (!in_array($handout_ext, $allowed_ext)) {
                    throw new Exception("Invalid driver handout file type. Only PDF, DOC, DOCX, JPG, JPEG, PNG allowed.");
                }
                if ($handout_size > 5*1024*1024) {
                    throw new Exception("Driver handout file exceeds 5MB limit.");
                }
                $handout_unique = uniqid('driver_handout_', true).'.'.$handout_ext;
                $handout_dir = 'uploads/driver_handouts/';
                if (!is_dir($handout_dir)) {
                    mkdir($handout_dir, 0755, true);
                }
                if (!move_uploaded_file($handout_tmp, $handout_dir.$handout_unique)) {
                    throw new Exception("Error uploading the driver handout file.");
                }
                $driver_handout_url = $handout_dir.$handout_unique;
            } else {
                throw new Exception("Driver handout upload error code: " . $_FILES['driver_handout']['error']);
            }
        }

        // Handle module docs upload
        if (isset($_FILES['module_docs']) && $_FILES['module_docs']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['module_docs']['error'] === UPLOAD_ERR_OK) {
                $allowed_ext = ['pdf','doc','docx','jpg','jpeg','png'];
                $module_docs_name = $_FILES['module_docs']['name'];
                $module_docs_tmp = $_FILES['module_docs']['tmp_name'];
                $module_docs_size = $_FILES['module_docs']['size'];
                $module_docs_ext = strtolower(pathinfo($module_docs_name, PATHINFO_EXTENSION));

                if (!in_array($module_docs_ext, $allowed_ext)) {
                    throw new Exception("Invalid module docs file type. Only PDF, DOC, DOCX, JPG, JPEG, PNG allowed.");
                }
                if ($module_docs_size > 5*1024*1024) {
                    throw new Exception("Module docs file exceeds 5MB limit.");
                }
                $module_docs_unique = uniqid('module_docs_', true).'.'.$module_docs_ext;
                $module_docs_dir = 'uploads/module_docs/';
                if (!is_dir($module_docs_dir)) {
                    mkdir($module_docs_dir, 0755, true);
                }
                if (!move_uploaded_file($module_docs_tmp, $module_docs_dir.$module_docs_unique)) {
                    throw new Exception("Error uploading the module docs file.");
                }
                $module_docs_url = $module_docs_dir.$module_docs_unique;
            } else {
                throw new Exception("Module docs upload error code: " . $_FILES['module_docs']['error']);
            }
        }

        // Insert into projects with separate address fields and new site information
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
                solterra_fee,
                phone1,
                phone2,
                timezone,
                reference_numbers,
                instructions,
                additional_notes,
                driver_handout_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception("Error preparing project insert: " . $conn->error);
        }
        $stmt->bind_param(
            "isssssssdsssssss",
            $account_id,
            $project_name,
            $street_address,
            $city,
            $state,
            $zip_code,
            $estimated_completion_date,
            $image_url,
            $solterra_fee,
            $phone1,
            $phone2,
            $timezone,
            $reference_numbers,
            $instructions,
            $additional_notes,
            $driver_handout_url
        );
        if (!$stmt->execute()) {
            throw new Exception("Error inserting project: " . $stmt->error);
        }
        $project_id = $stmt->insert_id;
        $stmt->close();

        // Default values are now set in the main INSERT statement

        // Set default operating hours (Monday-Friday 8AM-5PM)
        $hours_stmt = $conn->prepare("
            INSERT INTO site_operating_hours (project_id, day_of_week, start_time, end_time) 
            VALUES (?, ?, ?, ?)
        ");
        if (!$hours_stmt) {
            throw new Exception("Error preparing operating hours insert: " . $conn->error);
        }
        
        // Add Mon-Fri 8AM-5PM
        $start_time = "08:00:00";
        $end_time = "17:00:00";
        for ($day = 1; $day <= 5; $day++) {
            $hours_stmt->bind_param("iiss", $project_id, $day, $start_time, $end_time);
            if (!$hours_stmt->execute()) {
                throw new Exception("Error setting operating hours for day {$day}: " . $hours_stmt->error);
            }
        }
        $hours_stmt->close();

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

            // Insert into modules table for the initial batch with module information
            $stmt_module = $conn->prepare("
                INSERT INTO modules (
                    account_id, vendor_name, initial_location, project_id,
                    modules_per_pallet, pallets_per_truck, modules_per_truck,
                    pallet_length_mm, pallet_depth_mm, pallet_double_stacked_height_mm, pallet_total_weight_kg,
                    stacking_in_warehouse, stacking_during_transport,
                    forklift_truck_long_side_mm, forklift_truck_short_side_mm,
                    pallet_jack_long_side_mm, pallet_jack_short_side_mm,
                    module_notes, module_docs_url
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt_module) {
                throw new Exception("Error preparing module batch insert: " . $conn->error);
            }
            $stmt_module->bind_param(
                "issiiiiiiiissiiiiis", 
                $account_id, 
                $default_vendor_name, 
                $default_initial_location, 
                $project_id,
                $modules_per_pallet,
                $pallets_per_truck,
                $modules_per_truck,
                $pallet_length_mm,
                $pallet_depth_mm,
                $pallet_double_stacked_height_mm,
                $pallet_total_weight_kg,
                $stacking_in_warehouse,
                $stacking_during_transport,
                $forklift_truck_long_side_mm,
                $forklift_truck_short_side_mm,
                $pallet_jack_long_side_mm,
                $pallet_jack_short_side_mm,
                $module_notes,
                $module_docs_url
            );
            
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
            $successMessage = "Project added successfully! " . number_format($totalModulesCreated) . " modules created for this project. <a href='project_overview?project_id=" . $project_id . "' style='color: #488C9A; text-decoration: underline;'>View Project</a>.";
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

        /* Form layout */
        .form-container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 600px;
        }

        .form-section {
            padding: 40px;
            border-right: 1px solid #f0f0f0;
        }

        .form-section:last-child {
            border-right: none;
        }

        .form-section h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 2px solid #488C9A;
        }

        /* Input styling */
        .input-group {
            margin-bottom: 24px;
        }

        .input-group label {
            display: block;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .input-group.required label::after {
            content: " *";
            color: #dc3545;
            font-weight: 600;
        }

        .input-group.optional label {
            color: #666;
        }

        .input-group.optional label::after {
            content: " (optional)";
            color: #999;
            font-weight: 400;
            font-size: 0.85rem;
        }

        .input-group input,
        .input-group select,
        .input-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e8e8e8;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s ease;
            box-sizing: border-box;
            background: #fafafa;
        }

        .input-group.required input,
        .input-group.required select,
        .input-group.required textarea {
            border-left: 4px solid #dc3545;
        }

        .input-group input:focus,
        .input-group select:focus,
        .input-group textarea:focus {
            outline: none;
            border-color: #488C9A;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }

        .input-group textarea {
            resize: vertical;
            min-height: 80px;
        }



        /* Address grid */
        .address-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 16px;
        }

        .address-grid .input-group {
            margin-bottom: 0;
        }

        /* Phone grid */
        .phone-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        .phone-grid .input-group {
            margin-bottom: 0;
        }

        /* Module section */
        .module-section {
            grid-column: 1 / -1;
            border-top: 1px solid #f0f0f0;
            margin-top: 20px;
            padding-top: 40px;
        }

        .module-intro {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 32px;
            text-align: center;
        }

        .module-intro h3 {
            color: #293E4C;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .module-intro p {
            color: #666;
            margin-bottom: 0;
        }

        /* Wattage entries */
        .wattage-container {
            margin: 24px 0;
        }

        .wattage-entry {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 16px;
            align-items: end;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            margin-bottom: 16px;
            border: 1px solid #e9ecef;
        }

        .wattage-entry .input-group {
            margin-bottom: 0;
        }

        .remove-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 12px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.2s ease;
        }

        .remove-btn:hover {
            background: #c82333;
        }

        .add-wattage-btn {
            background: #488C9A;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            margin-bottom: 24px;
            transition: background 0.2s ease;
        }

        .add-wattage-btn:hover {
            background: #3a7086;
        }

        /* Logistics specs grid */
        .specs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 24px 0;
        }

        .specs-grid .input-group {
            margin-bottom: 0;
        }

        /* Submit section */
        .submit-section {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px;
            background: #f8f9fa;
            border-top: 1px solid #f0f0f0;
        }

        .submit-btn {
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            color: white;
            border: none;
            padding: 16px 48px;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(40, 62, 76, 0.2);
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(40, 62, 76, 0.3);
        }

        /* Messages */
        .message {
            padding: 16px 24px;
            border-radius: 12px;
            margin: 24px 0;
            text-align: center;
            font-weight: 500;
        }

        .success-message {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error-message {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Responsive design */
        @media (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-section {
                border-right: none;
                border-bottom: 1px solid #f0f0f0;
            }

            .form-section:last-child {
                border-bottom: none;
            }

            .address-grid {
                grid-template-columns: 1fr 1fr;
            }

            .specs-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            main {
                width: 95%;
                padding: 10px;
            }

            .form-section {
                padding: 24px;
            }

            .address-grid,
            .phone-grid,
            .specs-grid {
                grid-template-columns: 1fr;
            }

            .wattage-entry {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .page-header h1 {
                font-size: 2rem;
            }
        }

        /* Hide default br spacing */
        form br {
            display: none;
        }

        /* Subtle animations */
        .input-group {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }

        .input-group:nth-child(1) { animation-delay: 0.1s; }
        .input-group:nth-child(2) { animation-delay: 0.2s; }
        .input-group:nth-child(3) { animation-delay: 0.3s; }
        .input-group:nth-child(4) { animation-delay: 0.4s; }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Loading modal styles */
        .loading-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        .loading-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #488C9A;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 2s linear infinite;
            margin: 0 auto 15px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <script>
        function addWattageField() {
            var container = document.getElementById('wattage-container');
            var index = container.children.length;

            var div = document.createElement('div');
            div.className = 'wattage-entry';

            var wattageGroup = document.createElement('div');
            wattageGroup.className = 'input-group';
            var wattageLabel = document.createElement('label');
            wattageLabel.textContent = 'Wattage (W)';
            var wattageInput = document.createElement('input');
            wattageInput.type = 'number';
            wattageInput.step = '1';
            wattageInput.name = 'wattages[' + index + ']';
            wattageInput.required = true;
            wattageInput.placeholder = 'e.g. 555';
            wattageGroup.appendChild(wattageLabel);
            wattageGroup.appendChild(wattageInput);

            var quantityGroup = document.createElement('div');
            quantityGroup.className = 'input-group';
            var quantityLabel = document.createElement('label');
            quantityLabel.textContent = 'Quantity';
            var quantityInput = document.createElement('input');
            quantityInput.type = 'number';
            quantityInput.step = '1';
            quantityInput.name = 'quantities[' + index + ']';
            quantityInput.required = true;
            quantityInput.placeholder = 'e.g. 1000';
            quantityGroup.appendChild(quantityLabel);
            quantityGroup.appendChild(quantityInput);

            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Remove';
            removeButton.className = 'remove-btn';
            removeButton.onclick = function() {
                container.removeChild(div);
            };

            div.appendChild(wattageGroup);
            div.appendChild(quantityGroup);
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
        <a href="dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manage_projects.php" style="color: #488C9A; text-decoration: none;">Manage Projects</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Add Project</span>
    </div>

    <div class="page-header">
        <h1>Add Project</h1>
    </div>

    <!-- Display success or error messages if any -->
    <?php if (!empty($successMessage)): ?>
        <div class="message success-message"><?php 
            // Check if the message contains HTML (specifically a link) and display accordingly
            if (strpos($successMessage, '<a href=') !== false) {
                echo $successMessage; // Don't escape if it contains HTML links
            } else {
                echo htmlspecialchars($successMessage); // Escape for safety if no HTML
            }
        ?></div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="message error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- The project form -->
    <form action="" method="POST" enctype="multipart/form-data">
        <div class="form-container">
            <div class="form-grid">
                <!-- Left Column: Basic Project Information -->
                <div class="form-section">
                    <h2>Project Details</h2>
                    
                    <?php if ($role === 'global_admin'): ?>
                        <div class="input-group required">
                            <label for="account_id">Account Name</label>
                            <select name="account_id" id="account_id" required>
                                <option value="">Select Account</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo htmlspecialchars($acc['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="account_id" value="<?php echo $account_id_for_admin; ?>">
                    <?php endif; ?>

                    <div class="input-group required">
                        <label for="project_name">Project Name</label>
                        <input type="text" id="project_name" name="project_name" required placeholder="e.g. Solar Farm Project Alpha">
                    </div>

                    <div class="input-group required">
                        <label>Project Address</label>
                        <div class="address-grid">
                            <div class="input-group required">
                                <label for="street_address">Street Address</label>
                                <input type="text" id="street_address" name="street_address" placeholder="e.g. 123 Main Street" required>
                            </div>
                            <div class="input-group required">
                                <label for="city">City</label>
                                <input type="text" id="city" name="city" placeholder="e.g. Springfield" required>
                            </div>
                            <div class="input-group required">
                                <label for="state">State</label>
                                <input type="text" id="state" name="state" placeholder="e.g. CA" required>
                            </div>
                            <div class="input-group required">
                                <label for="zip_code">Zip Code</label>
                                <input type="text" id="zip_code" name="zip_code" placeholder="e.g. 12345" required>
                            </div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="estimated_completion_date">Estimated Completion Date</label>
                        <input type="date" id="estimated_completion_date" name="estimated_completion_date">
                    </div>

                    <div class="input-group">
                        <label for="image_file">Project Image</label>
                        <input type="file" id="image_file" name="image_file" accept="image/*">
                    </div>

                    <?php if ($role === 'global_admin'): ?>
                        <div class="input-group required">
                            <label for="solterra_fee">Solterra Fee (per watt)</label>
                            <input type="number" id="solterra_fee" step="0.0001" name="solterra_fee" value="0.0000" required>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="solterra_fee" value="0.0000">
                    <?php endif; ?>
                </div>

                <!-- Right Column: Site Information -->
                <div class="form-section">
                    <h2>Site Information <span style="color: #999; font-weight: 400; font-size: 0.85rem;">(optional)</span></h2>
                    
                    <div class="input-group">
                        <label>Contact Information</label>
                        <div class="phone-grid">
                            <div class="input-group">
                                <label for="phone1">Primary Phone</label>
                                <input type="tel" id="phone1" name="phone1" placeholder="e.g. 555-555-5555">
                            </div>
                            <div class="input-group">
                                <label for="phone2">Secondary Phone</label>
                                <input type="tel" id="phone2" name="phone2" placeholder="e.g. 555-555-5555">
                            </div>
                            <div class="input-group">
                                <label for="timezone">Timezone</label>
                                <select name="timezone" id="timezone">
                                    <option value="America/New_York" selected>Eastern</option>
                                    <option value="America/Chicago">Central</option>
                                    <option value="America/Denver">Mountain</option>
                                    <option value="America/Los_Angeles">Pacific</option>
                                    <option value="UTC">UTC</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="reference_numbers">Reference Numbers</label>
                        <input type="text" id="reference_numbers" name="reference_numbers" placeholder="e.g. PO #12345, Job #ABC-2024">
                    </div>

                    <div class="input-group">
                        <label for="instructions">Special Instructions</label>
                        <textarea id="instructions" name="instructions" placeholder="e.g. Delivery instructions, site access requirements, etc."></textarea>
                    </div>

                    <div class="input-group">
                        <label for="additional_notes">Additional Notes</label>
                        <textarea id="additional_notes" name="additional_notes" placeholder="e.g. Any other relevant information for this project..."></textarea>
                    </div>

                    <div class="input-group">
                        <label for="driver_handout">Driver Handout</label>
                        <input type="file" id="driver_handout" name="driver_handout" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        <small style="color: #666; font-size: 0.85em;">Upload driver instructions, site maps, or other handout materials (PDF, DOC, JPG, PNG - max 5MB)</small>
                    </div>
                </div>

                <!-- Module Information Section (Full Width) -->
                <div class="module-section">
                    <div class="module-intro">
                        <h3>Initial Module Batch</h3>
                        <p>Create an initial module batch for this project. You can add more batches from different manufacturers later.</p>
                    </div>

                    <div class="form-grid">
                        <div class="form-section">
                            <h2>Manufacturer & Modules <span style="color: #999; font-weight: 400; font-size: 0.85rem;">(optional)</span></h2>
                            
                            <div class="input-group">
                                <label for="manufacturer_id">Manufacturer</label>
                                <select name="manufacturer_id" id="manufacturer_id">
                                    <option value="">Select Manufacturer</option>
                                    <?php foreach ($manufacturers as $mfg): ?>
                                        <option value="<?php echo $mfg['id']; ?>">
                                            <?php echo htmlspecialchars($mfg['name']); ?>
                                            <?php if (!empty($mfg['short_name'])): ?>
                                                (<?php echo htmlspecialchars($mfg['short_name']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="input-group">
                                <label>Wattage & Quantities</label>
                                <div id="wattage-container" class="wattage-container">
                                    <!-- Wattage fields will be added here by JS -->
                                </div>
                                <button type="button" class="add-wattage-btn" onclick="addWattageField()">+ Add Wattage</button>
                            </div>
                        </div>

                        <div class="form-section">
                            <h2>Logistics Specifications <span style="color: #999; font-weight: 400; font-size: 0.85rem;">(optional)</span></h2> 
                            
                            <div class="specs-grid">
                                <div class="input-group">
                                    <label for="modules_per_pallet">Modules per Pallet</label>
                                    <input type="number" id="modules_per_pallet" name="modules_per_pallet" min="1" placeholder="e.g. 30">
                                </div>
                                <div class="input-group">
                                    <label for="pallets_per_truck">Pallets per Truck</label>
                                    <input type="number" id="pallets_per_truck" name="pallets_per_truck" min="1" placeholder="e.g. 22">
                                </div>
                                <div class="input-group">
                                    <label for="modules_per_truck">Modules per Truck</label>
                                    <input type="number" id="modules_per_truck" name="modules_per_truck" min="1" placeholder="Auto-calculated" readonly style="background-color: #f8f9fa; color: #6c757d;">
                                </div>
                                <div class="input-group">
                                    <label for="pallet_length_mm">Length (mm)</label>
                                    <input type="number" id="pallet_length_mm" name="pallet_length_mm" min="1" placeholder="e.g. 2384">
                                </div>
                                <div class="input-group">
                                    <label for="pallet_depth_mm">Depth (mm)</label>
                                    <input type="number" id="pallet_depth_mm" name="pallet_depth_mm" min="1" placeholder="e.g. 1303">
                                </div>
                                <div class="input-group">
                                    <label for="pallet_double_stacked_height_mm">Stack Height (mm)</label>
                                    <input type="number" id="pallet_double_stacked_height_mm" name="pallet_double_stacked_height_mm" min="1" placeholder="e.g. 2200">
                                </div>
                                <div class="input-group">
                                    <label for="pallet_total_weight_kg">Weight (kg)</label>
                                    <input type="number" id="pallet_total_weight_kg" name="pallet_total_weight_kg" min="1" placeholder="e.g. 1200">
                                </div>
                                <div class="input-group">
                                    <label for="forklift_truck_long_side_mm">Forklift Long (mm)</label>
                                    <input type="number" id="forklift_truck_long_side_mm" name="forklift_truck_long_side_mm" min="1" placeholder="e.g. 1200">
                                </div>
                                <div class="input-group">
                                    <label for="forklift_truck_short_side_mm">Forklift Short (mm)</label>
                                    <input type="number" id="forklift_truck_short_side_mm" name="forklift_truck_short_side_mm" min="1" placeholder="e.g. 1000">
                                </div>
                                <div class="input-group">
                                    <label for="pallet_jack_long_side_mm">Pallet Jack Long (mm)</label>
                                    <input type="number" id="pallet_jack_long_side_mm" name="pallet_jack_long_side_mm" min="1" placeholder="e.g. 1150">
                                </div>
                                <div class="input-group">
                                    <label for="pallet_jack_short_side_mm">Pallet Jack Short (mm)</label>
                                    <input type="number" id="pallet_jack_short_side_mm" name="pallet_jack_short_side_mm" min="1" placeholder="e.g. 800">
                                </div>
                            </div>

                            <div class="input-group">
                                <label for="stacking_in_warehouse">Warehouse Stacking</label>
                                <textarea id="stacking_in_warehouse" name="stacking_in_warehouse" placeholder="e.g. Instructions for warehouse stacking..."></textarea>
                            </div>

                            <div class="input-group">
                                <label for="stacking_during_transport">Transport Stacking</label>
                                <textarea id="stacking_during_transport" name="stacking_during_transport" placeholder="e.g. Instructions for transport stacking..."></textarea>
                            </div>

                            <div class="input-group">
                                <label for="module_notes">Module Notes</label>
                                <textarea id="module_notes" name="module_notes" placeholder="e.g. General notes about the modules..."></textarea>
                            </div>

                            <div class="input-group">
                                <label for="module_docs">Module Documentation</label>
                                <input type="file" id="module_docs" name="module_docs" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                <small style="color: #666; font-size: 0.85em;">Module specifications, datasheets, or other documentation (PDF, DOC, JPG, PNG - max 5MB)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Section -->
                <div class="submit-section">
                    <button type="submit" class="submit-btn">Create Project</button>
                </div>
            </div>
        </div>
    </form>
</main>

<!-- Loading Modal for Project Creation -->
<div id="loadingModal" class="loading-modal">
    <div class="loading-content">
        <div class="spinner"></div>
        <h3>Creating Project...</h3>
        <p>Please wait while we create your project. This may take a few moments.</p>
    </div>
</div>

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

// Loading modal functions for project creation
function showLoadingModal() {
    const modal = document.getElementById('loadingModal');
    if (modal) modal.style.display = 'block';
}

function hideLoadingModal() {
    const modal = document.getElementById('loadingModal');
    if (modal) modal.style.display = 'none';
}

// Add loading spinner to project creation form
document.addEventListener('DOMContentLoaded', function() {
    const projectForm = document.querySelector('form[method="POST"]');
    if (projectForm) {
        projectForm.addEventListener('submit', function(e) {
            // Basic form validation before showing loading
            const requiredFields = projectForm.querySelectorAll('input[required], select[required]');
            let allValid = true;
            
            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    allValid = false;
                }
            });
            
            // Only show loading modal if basic validation passes
            if (allValid) {
                showLoadingModal();
            }
        });
    }
    
    // Hide loading modal on page load (in case of refresh/back button)
    hideLoadingModal();
});

        // Auto-calculate modules per truck
        function calculateModulesPerTruck() {
            var modulesPerPallet = parseFloat(document.getElementById('modules_per_pallet').value) || 0;
            var palletsPerTruck = parseFloat(document.getElementById('pallets_per_truck').value) || 0;
            var modulesPerTruckField = document.getElementById('modules_per_truck');
            
            if (modulesPerPallet > 0 && palletsPerTruck > 0) {
                var result = modulesPerPallet * palletsPerTruck;
                modulesPerTruckField.value = result;
                modulesPerTruckField.style.color = '#333'; // Normal text color when calculated
            } else {
                modulesPerTruckField.value = '';
                modulesPerTruckField.style.color = '#6c757d'; // Muted color when empty
            }
        }

        // Add event listeners for auto-calculation
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('modules_per_pallet').addEventListener('input', calculateModulesPerTruck);
            document.getElementById('pallets_per_truck').addEventListener('input', calculateModulesPerTruck);
        });
</script>

</body>
</html>
